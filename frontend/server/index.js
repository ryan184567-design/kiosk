// 正式環境服務：提供 vite build 後的 dist 靜態檔案，並支援 Vue Router history 模式
// 同時提供櫃台 / 訪客螢幕即時同步（socket.io，見 server/realtime.js）
import dotenv from 'dotenv'
import express from 'express'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { attachRealtime } from './realtime.js'
import { printBadge } from './printer/index.js'
import { createSettingsRouter } from './settingsRoutes.js'
import { getScanner, startScanner } from './idscanner/index.js'
import { getClientConfig } from './envSettings.js'
import { logger, writeLog } from './logger.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
// 以專案根目錄的 .env 為準（不受啟動時所在目錄影響）
dotenv.config({ path: path.resolve(__dirname, '../.env'), quiet: true })
const distDir = path.resolve(__dirname, '../dist')
const indexHtml = path.join(distDir, 'index.html')

const HOST = process.env.HOST || '0.0.0.0'
const PORT = Number(process.env.PORT) || 3000
// 對外顯示的主機 IP / 網域（僅影響啟動訊息顯示的網址，不影響監聽）
const PUBLIC_HOST = (process.env.PUBLIC_HOST || '').trim()

// 沒有 dist 時（例如開發模式搭配 npm run dev）只提供 socket 與 /health
const hasDist = fs.existsSync(indexHtml)
if (!hasDist) {
  console.warn(`[visitor-front] ${indexHtml} not found`)
  console.warn('[visitor-front] Static files disabled. Run "npm run build" for production.')
}

const app = express()
app.disable('x-powered-by')

app.get('/health', (req, res) => {
  res.json({ status: 'ok', time: new Date().toISOString() })
})

// 列印訪客證到 POS 印表機（設定見 server/printer/index.js）
app.post('/api/print/badge', express.json(), async (req, res) => {
  const { qrContent, badgeNo, locker, printedAt } = req.body || {}
  try {
    const r = await printBadge({ qrContent, badgeNo, locker, printedAt })
    res.json({ ok: true, type: r.type })
  } catch (err) {
    logger.error('Printer', `列印失敗：${err.message}`, { qrContent, badgeNo, locker })
    res.status(err.status || 500).json({ ok: false, message: err.message })
  }
})

// 證件掃描（手動 / 重試；自動掃描結果經 socket 送到訪客螢幕，見 server/idscanner）
const SCAN_ERROR_STATUS = {
  OFFLINE: 503,
  NO_DEVICE: 503,
  NO_SOURCE: 503,
  OCCUPIED: 503,
  BAD_STATE: 503,
  BUSY: 409,
  NOT_READY: 409,
  TIMEOUT: 504,
  NO_DATA: 422,
}
app.post('/api/idcard/scan', async (req, res) => {
  try {
    const card = await getScanner().scanNow()
    res.json({ ok: true, card })
  } catch (err) {
    res
      .status(SCAN_ERROR_STATUS[err.code] || 500)
      .json({ ok: false, code: err.code || 'SCANNER_ERROR', message: err.message })
  }
})
app.get('/api/idcard/status', (req, res) => res.json({ ok: true, ...getScanner().getStatus() }))

// 系統設定（櫃台螢幕 /staff 的設定介面，直接修改 .env；見 server/settingsRoutes.js）
let io
app.use('/api/settings', createSettingsRouter({ getIo: () => io }))

// 前端記錄寫入檔案（logs/visitor-YYYY-MM-DD.log，見 server/logger.js）
const MAX_LOG_ENTRIES = 100
app.post('/api/log', express.json({ limit: '512kb' }), (req, res) => {
  const { source, entries } = req.body || {}
  if (!Array.isArray(entries)) return res.status(400).json({ ok: false })
  const src = ['visitor', 'staff'].includes(source) ? source : 'client'
  for (const e of entries.slice(0, MAX_LOG_ENTRIES)) {
    writeLog({
      level: e?.level,
      source: src,
      channel: String(e?.channel || 'App').slice(0, 30),
      message: String(e?.message ?? '').slice(0, 2000),
      context: e?.context,
      time: e?.time,
    })
  }
  res.status(204).end()
})

// 不存在的 API 回傳 JSON 404（不要落到 SPA fallback 回傳 index.html）
app.use('/api', (req, res) => res.status(404).json({ ok: false, message: `API 不存在：${req.method} ${req.originalUrl}` }))

// 前端執行時設定（VITE_*）注入 index.html：修改 .env 後重新整理即生效，不需重新 build
function renderIndex() {
  const json = JSON.stringify(getClientConfig()).replace(/</g, '\\u003c')
  return fs
    .readFileSync(indexHtml, 'utf8')
    .replace('</head>', `<script>window.__APP_CONFIG__=${json}</script></head>`)
}

if (hasDist) {
  // 帶 hash 的打包資源可長期快取；找不到的檔案直接回 404（不回傳 index.html，避免錯誤原因被掩蓋）
  app.use(
    '/assets',
    express.static(path.join(distDir, 'assets'), {
      immutable: true,
      maxAge: '1y',
      fallthrough: false,
    }),
  )
  app.use(express.static(distDir, { index: false }))

  // SPA fallback：其餘 GET 請求一律回傳 index.html（不快取）
  app.use((req, res, next) => {
    if (req.method !== 'GET' && req.method !== 'HEAD') return next()
    res.set('Cache-Control', 'no-cache')
    res.type('html').send(renderIndex())
  })
}

// 錯誤處理（例如 /assets 找不到檔案）
// eslint-disable-next-line no-unused-vars -- Express 依參數數量（4 個）判斷為錯誤處理中介層
app.use((err, _req, res, next) => {
  if (res.headersSent) return next(err)
  res.status(err.status || err.statusCode || 500).send(err.status === 404 ? 'Not Found' : 'Error')
})

const server = app.listen(PORT, HOST, () => {
  console.log(`[visitor-front] ${new Date().toISOString()} server started (bind ${HOST}:${PORT})`)
  // 0.0.0.0 / :: 只是「監聽所有網卡」，瀏覽器無法直接開啟，改列出可訪問的網址
  const isAnyHost = HOST === '0.0.0.0' || HOST === '::'
  console.log(`  Local:   http://${isAnyHost ? 'localhost' : HOST}:${PORT}`)
  if (PUBLIC_HOST) {
    console.log(`  Network: http://${PUBLIC_HOST}:${PORT}`)
  } else if (isAnyHost) {
    for (const ip of getLanIPs()) console.log(`  Network: http://${ip}:${PORT}`)
  }
  console.log(`  Staff:   http://${isAnyHost ? 'localhost' : HOST}:${PORT}/staff  （櫃台螢幕）`)
  writeLog({ level: 'info', channel: 'App', message: `服務啟動 ${HOST}:${PORT}`, context: { hasDist } })
})

io = attachRealtime(server)

// 證件掃描器：狀態廣播給所有畫面，掃描過程與結果送到訪客螢幕（個資只含遮罩後證號）
startScanner({
  onStatus: (status) => io.emit('scanner:status', status),
  onScanning: () => io.to('visitor').emit('idcard:scanning'),
  onResult: (card) => io.to('visitor').emit('idcard:result', { card }),
  onError: (err) => io.to('visitor').emit('idcard:error', { code: err.code || 'SCANNER_ERROR', message: err.message }),
})

function getLanIPs() {
  return Object.values(os.networkInterfaces())
    .flat()
    .filter((i) => i && i.family === 'IPv4' && !i.internal)
    .map((i) => i.address)
}

server.on('error', (err) => {
  console.error('[visitor-front] failed to start:', err.message)
  writeLog({ level: 'error', channel: 'App', message: `服務啟動失敗：${err.message}` })
  setTimeout(() => process.exit(1), 100)
})

const shutdown = (signal) => {
  console.log(`[visitor-front] received ${signal}, shutting down`)
  writeLog({ level: 'info', channel: 'App', message: `服務停止（${signal}）` })
  server.close(() => process.exit(0))
  setTimeout(() => process.exit(0), 5000).unref()
}
process.on('SIGINT', () => shutdown('SIGINT'))
process.on('SIGTERM', () => shutdown('SIGTERM'))
