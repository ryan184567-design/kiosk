// 系統設定 API（櫃台螢幕 /staff 的「系統設定」介面，直接修改 .env）
//
// 權限：.env 有設 SETTINGS_PIN → 需在請求標頭 X-Settings-Pin 帶入正確 PIN（任何電腦皆可）
//       未設定               → 只允許本機（localhost）修改，其他電腦只能檢視
//
//   GET  /api/settings              欄位定義與目前值（機密欄位不回傳值）
//   POST /api/settings/verify       驗證 PIN
//   PUT  /api/settings              儲存 { values: { KEY: 值 } }（只需傳有修改的欄位）
//   GET  /api/settings/devices      偵測印表機與 COM 埠
//   POST /api/settings/printer/test 以目前設定測試列印
//   POST /api/settings/restart      重新啟動服務（需由 start.bat / systemd 啟動，結束後會自動重啟）
import { execFile } from 'node:child_process'
import crypto from 'node:crypto'
import express from 'express'
import { fieldApply, getRestartPending, getSchema, getSettingsValues, saveSettings } from './envSettings.js'
import { printBadge } from './printer/index.js'
import { listPrinterDevices } from './printer/settings.js'
import { logger } from './logger.js'

const LOCAL_ADDRS = new Set(['127.0.0.1', '::1', '::ffff:127.0.0.1'])
const isLocalRequest = (req) => LOCAL_ADDRS.has(req.socket.remoteAddress)
const settingsPin = () => String(process.env.SETTINGS_PIN || '').trim()

function pinMatches(input) {
  const a = Buffer.from(String(input || ''))
  const b = Buffer.from(settingsPin())
  return a.length === b.length && crypto.timingSafeEqual(a, b)
}

function requireAuth(req, res, next) {
  if (settingsPin()) {
    if (pinMatches(req.get('X-Settings-Pin'))) return next()
    logger.warning('Settings', `系統設定 PIN 錯誤（${req.socket.remoteAddress}）`)
    return res.status(401).json({ ok: false, code: 'PIN_REQUIRED', message: 'PIN 錯誤' })
  }
  if (isLocalRequest(req)) return next()
  res.status(403).json({ ok: false, code: 'LOCAL_ONLY', message: '只能在本機修改設定（或設定 SETTINGS_PIN）' })
}

// 是否由會自動重啟的程式啟動：systemd（INVOCATION_ID）或 Windows 的 scripts/windows/start.bat
let supervisedCache = null
function isSupervised() {
  if (supervisedCache !== null) return Promise.resolve(supervisedCache)
  if (process.env.INVOCATION_ID) return Promise.resolve((supervisedCache = true))
  if (process.platform !== 'win32') return Promise.resolve((supervisedCache = false))
  return new Promise((resolve) => {
    execFile(
      'powershell.exe',
      ['-NoProfile', '-Command', `(Get-CimInstance Win32_Process -Filter "ProcessId=${process.ppid}").CommandLine`],
      { timeout: 10000, windowsHide: true },
      (err, stdout) => resolve((supervisedCache = !err && /start\.bat/i.test(stdout))),
    )
  })
}

/**
 * @param {{ getIo: () => import('socket.io').Server | undefined }} opts
 */
export function createSettingsRouter({ getIo }) {
  const router = express.Router()

  const payload = async (req) => ({
    ok: true,
    schema: getSchema(),
    ...getSettingsValues(),
    platform: process.platform,
    pinRequired: !!settingsPin(),
    editable: !!settingsPin() || isLocalRequest(req),
    restartPending: getRestartPending(),
    canRestart: await isSupervised(),
  })

  router.get('/', async (req, res) => res.json(await payload(req)))

  router.post('/verify', requireAuth, (req, res) => res.json({ ok: true }))

  router.put('/', requireAuth, express.json(), async (req, res) => {
    try {
      const { changed } = saveSettings(req.body?.values)
      if (changed.length) {
        logger.info('Settings', `更新系統設定：${changed.join(', ')}`, { from: req.socket.remoteAddress })
        // 前端設定（VITE_*）變更：通知各畫面，訪客螢幕會在回到首頁時重新載入
        if (changed.some((k) => fieldApply(k) === 'reload')) getIo()?.emit('config:changed')
      }
      res.json({ ...(await payload(req)), changed })
    } catch (err) {
      res.status(err.status || 500).json({ ok: false, message: err.message })
    }
  })

  router.get('/devices', requireAuth, async (req, res) => {
    res.json({ ok: true, ...(await listPrinterDevices()) })
  })

  router.post('/printer/test', requireAuth, async (req, res) => {
    try {
      const r = await printBadge({
        qrContent: '123456789',
        badgeNo: 'TEST01',
        locker: 'A01',
        printedAt: new Date().toISOString(),
      })
      res.json({ ok: true, type: r.type, bytes: r.bytes, preview: r.preview })
    } catch (err) {
      logger.error('Printer', `測試列印失敗：${err.message}`)
      res.status(err.status || 500).json({ ok: false, message: err.message })
    }
  })

  router.post('/restart', requireAuth, async (req, res) => {
    if (!(await isSupervised())) {
      return res.status(409).json({
        ok: false,
        message: '目前服務不是由 start.bat / systemd 啟動，結束後不會自動重啟，請手動重新啟動',
      })
    }
    logger.info('Settings', '由系統設定介面重新啟動服務', { from: req.socket.remoteAddress })
    res.json({ ok: true })
    // 回應送出後再結束，由 start.bat / systemd 重新啟動
    setTimeout(() => process.kill(process.pid, 'SIGTERM'), 500)
  })

  return router
}
