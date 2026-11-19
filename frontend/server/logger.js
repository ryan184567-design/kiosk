// 檔案記錄（格式仿 Laravel）：logs/visitor-YYYY-MM-DD.log，每天一個檔案
//   [2026-11-10 17:30:00] production.INFO: [visitor][Flow] 訊息 {"key":"value"}
//
// .env 設定：
//   LOG_LEVEL   寫入檔案的最低等級：debug | info（預設）| warning | error
//   LOG_DAYS    保留天數，預設 14（超過自動刪除）
//   APP_ENV     記錄中的環境名稱，預設 production
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
export const LOG_DIR = path.resolve(__dirname, '../logs')
const FILE_PREFIX = 'visitor-'

export const LEVELS = { debug: 10, info: 20, warning: 30, error: 40 }

const pad = (n) => String(n).padStart(2, '0')
const dateOf = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
const timeOf = (d) => `${dateOf(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`

// 於呼叫時讀取（確保 dotenv 已載入 .env）
const config = () => ({
  level: LEVELS[(process.env.LOG_LEVEL || 'info').toLowerCase()] ?? LEVELS.info,
  days: Number(process.env.LOG_DAYS) || 14,
  env: process.env.APP_ENV || 'production',
})

export function normalizeLevel(level) {
  const l = String(level || 'info').toLowerCase()
  if (l === 'warn') return 'warning'
  return LEVELS[l] ? l : 'info'
}

let stream = null
let streamDate = ''

function getStream(date) {
  if (stream && streamDate === date) return stream
  stream?.end()
  fs.mkdirSync(LOG_DIR, { recursive: true })
  stream = fs.createWriteStream(path.join(LOG_DIR, `${FILE_PREFIX}${date}.log`), { flags: 'a' })
  streamDate = date
  cleanup()
  return stream
}

/** 刪除超過保留天數的記錄檔 */
export function cleanup() {
  const { days } = config()
  const limit = dateOf(new Date(Date.now() - days * 86400000))
  try {
    for (const f of fs.readdirSync(LOG_DIR)) {
      const m = f.match(/^visitor-(\d{4}-\d{2}-\d{2})\.log$/)
      if (m && m[1] < limit) fs.unlinkSync(path.join(LOG_DIR, f))
    }
  } catch {
    // 忽略
  }
}

function stringify(context) {
  if (context === undefined || context === null) return ''
  if (Array.isArray(context) && context.length === 0) return ''
  try {
    return ` ${JSON.stringify(context)}`
  } catch {
    return ' [unserializable context]'
  }
}

/**
 * 寫入一筆記錄
 * @param {object} entry
 * @param {string} entry.level    debug | info | warning | error
 * @param {string} entry.source   來源：server | visitor | staff
 * @param {string} entry.channel  分類：Flow / API / Socket / Printer …
 * @param {string} entry.message
 * @param {any}    entry.context  附加資料
 * @param {string} entry.time     發生時間（ISO，前端傳入；預設現在）
 */
export function writeLog({ level, source = 'server', channel = 'App', message = '', context, time }) {
  const lv = normalizeLevel(level)
  const cfg = config()
  if (LEVELS[lv] < cfg.level) return
  const now = new Date()
  const at = time ? new Date(time) : now
  const ts = Number.isNaN(at.getTime()) ? now : at
  const line = `[${timeOf(ts)}] ${cfg.env}.${lv.toUpperCase()}: [${source}][${channel}] ${String(message).replace(/\r?\n/g, ' ')}${stringify(context)}\n`
  getStream(dateOf(now)).write(line)
}

/** 伺服器端使用：同時輸出 console 與寫入檔案 */
export const logger = {
  debug: (channel, message, context) => writeLog({ level: 'debug', channel, message, context }),
  info: (channel, message, context) => {
    console.log(`[${channel}] ${new Date().toISOString()} ${message}`)
    writeLog({ level: 'info', channel, message, context })
  },
  warning: (channel, message, context) => {
    console.warn(`[${channel}] ${new Date().toISOString()} ${message}`)
    writeLog({ level: 'warning', channel, message, context })
  },
  error: (channel, message, context) => {
    console.error(`[${channel}] ${new Date().toISOString()} ${message}`)
    writeLog({ level: 'error', channel, message, context })
  },
}
