// 前端記錄工具
//   1. console：VITE_DEBUG=true 時輸出到瀏覽器 console（開發除錯用）
//   2. 檔案：等級 ≥ VITE_LOG_LEVEL 的記錄批次送到伺服器 /api/log，
//      寫入 logs/visitor-YYYY-MM-DD.log（格式仿 Laravel，見 server/logger.js）
// （設定來源見 src/config.js：正式服務由 .env 於執行時注入，重新整理即生效）
import { appConfig } from '@/config'

export const isDebug = appConfig.debug

const LEVELS = { debug: 10, info: 20, warning: 30, error: 40, off: Infinity }
const REMOTE_LEVEL = LEVELS[appConfig.logLevel] ?? LEVELS.info

// debug(tag, …) 依分類對應的記錄等級
const TAG_LEVEL = {
  Flow: 'info', // 報到流程中的重要操作
  App: 'info',
  API: 'debug', // API 參數 / 結果（錯誤另以 error 記錄）
  Route: 'debug',
  Socket: 'debug',
}

const TAG_COLORS = {
  API: '#7c3aed',
  Route: '#0891b2',
  Flow: '#1554c0',
  Socket: '#d97706',
  App: '#64748b',
}

/** 將 reactive 物件轉為當下的快照，避免 console 展開時看到的是之後被修改的值 */
export function snapshot(value) {
  if (value === null || typeof value !== 'object') return value
  try {
    return JSON.parse(JSON.stringify(value))
  } catch {
    return value
  }
}

/** 轉為可寫入記錄的資料（Error 物件保留 name / message / stack） */
function serialize(value) {
  if (value instanceof Error) {
    return { error: value.name, message: value.message, code: value.code, stack: value.stack }
  }
  return snapshot(value)
}

// ── 送到伺服器（批次） ──
const ENDPOINT = '/api/log'
const FLUSH_MS = 2000
const MAX_BATCH = 50
const MAX_QUEUE = 500
let source = 'visitor'
let queue = []
let timer = null

/** 設定記錄來源：visitor（訪客螢幕）| staff（櫃台螢幕） */
export function setLogSource(s) {
  source = s
}

function flush() {
  clearTimeout(timer)
  timer = null
  if (!queue.length) return
  const entries = queue.splice(0, MAX_BATCH)
  fetch(ENDPOINT, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ source, entries }),
    keepalive: true,
  }).catch(() => {
    // 伺服器暫時無法連線：放回佇列稍後重試（不再記錄，避免無限循環）
    queue = entries.concat(queue).slice(-MAX_QUEUE)
    schedule()
  })
  if (queue.length) schedule()
}

function schedule() {
  if (!timer) timer = setTimeout(flush, FLUSH_MS)
}

function enqueue(entry) {
  queue.push(entry)
  if (queue.length > MAX_QUEUE) queue = queue.slice(-MAX_QUEUE)
  if (queue.length >= MAX_BATCH) flush()
  else schedule()
}

// 關閉 / 重新整理頁面前送出剩餘記錄
if (typeof window !== 'undefined') {
  window.addEventListener('pagehide', () => {
    if (!queue.length) return
    const body = JSON.stringify({ source, entries: queue.splice(0, MAX_BATCH) })
    navigator.sendBeacon?.(ENDPOINT, new Blob([body], { type: 'application/json' }))
  })
}

/**
 * 記錄
 * @param {'debug'|'info'|'warning'|'error'} level
 * @param {string} tag 分類：Flow / API / Route / Socket / App …
 * @param {string} message
 * @param {...any} data 附加資料
 */
export function log(level, tag, message, ...data) {
  if (isDebug) {
    const color = TAG_COLORS[tag] || '#334155'
    const time = new Date().toLocaleTimeString('zh-TW', { hour12: false })
    const method = level === 'error' ? 'error' : level === 'warning' ? 'warn' : 'log'
    console[method](
      `%c[${time}] [${tag}]%c ${message}`,
      `color:${color};font-weight:bold`,
      'color:inherit',
      ...data.map(snapshot),
    )
  }
  if (LEVELS[level] >= REMOTE_LEVEL) {
    const context = data.length === 0 ? undefined : data.length === 1 ? serialize(data[0]) : data.map(serialize)
    enqueue({ level, channel: tag, message, context, time: new Date().toISOString() })
  }
}

/** 依分類自動決定等級（Flow / App → info，其餘 → debug） */
export function debug(tag, message, ...data) {
  log(TAG_LEVEL[tag] || 'debug', tag, message, ...data)
}

export const logWarning = (tag, message, ...data) => log('warning', tag, message, ...data)
export const logError = (tag, message, ...data) => log('error', tag, message, ...data)

/** 包裝非同步函式：記錄呼叫參數、回傳結果、耗時與錯誤 */
export function withDebugLog(name, fn) {
  return async (...args) => {
    debug('API', `→ ${name}`, ...args)
    const start = performance.now()
    try {
      const result = await fn(...args)
      debug('API', `← ${name} (${Math.round(performance.now() - start)}ms)`, result)
      return result
    } catch (err) {
      logError('API', `✕ ${name} (${Math.round(performance.now() - start)}ms)：${err?.message}`, err)
      throw err
    }
  }
}

/** 記錄未捕捉的錯誤（Vue 元件錯誤、JavaScript 錯誤、未處理的 Promise 拒絕） */
export function installErrorLogging(app) {
  app.config.errorHandler = (err, _instance, info) => {
    logError('App', `Vue 錯誤（${info}）：${err?.message}`, err)
    if (!isDebug) console.error(err)
  }
  window.addEventListener('error', (e) => {
    logError('App', `JavaScript 錯誤：${e.message}`, { file: e.filename, line: e.lineno, col: e.colno })
  })
  window.addEventListener('unhandledrejection', (e) => {
    logError('App', `未處理的 Promise 錯誤：${e.reason?.message || e.reason}`, e.reason)
  })
}
