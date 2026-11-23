// 後端訪客機 API（Partner API：/api/factory/*）的 token 管理與代理
//
// 後端以 App（機器）身份認證：用 app_id + HMAC-SHA256 簽名換取 access token。
// app_secret 不可經網路傳送，也不能放在瀏覽器，因此由本機服務保管密鑰、換 token，
// 前端只呼叫本機的 /api/factory/*，由這裡帶上 Bearer token 轉送到後端。
//
// 設定（.env，每次請求時讀取，系統設定修改後立即生效）：
//   FACTORY_API_BASE    後端網址，例 http://kiosk.test:8080
//   FACTORY_APP_ID      後台建立 App 時取得的 app_id
//   FACTORY_APP_SECRET  App 的密鑰（只用於本機簽名）
//   FACTORY_TIMEOUT     單次請求逾時秒數（預設 10）
import crypto from 'node:crypto'
import { Router } from 'express'
import { logger } from './logger.js'

const TOKEN_PATH = '/api/factory/token'
// token 提前多久視為過期（秒），避免送出途中剛好過期
const TOKEN_EARLY_EXPIRE = 10

// 允許前端呼叫的後端 API（token 端點不對外）
const ALLOWED = new Set([
  'GET /data_setting/index',
  'GET /guest/applies',
  'GET /guest/slot',
  'POST /guest/create',
  'POST /guest/done',
  'POST /guest/leave',
])

function getConfig() {
  const timeout = Number(process.env.FACTORY_TIMEOUT)
  return {
    base: String(process.env.FACTORY_API_BASE || '').trim().replace(/\/+$/, ''),
    appId: String(process.env.FACTORY_APP_ID || '').trim(),
    secret: String(process.env.FACTORY_APP_SECRET || '').trim(),
    timeoutMs: (Number.isFinite(timeout) && timeout > 0 ? timeout : 10) * 1000,
  }
}

/** 本機服務自己產生的錯誤（非後端回應），附上要回給前端的狀態碼與錯誤代碼 */
class FactoryError extends Error {
  constructor(message, { status = 500, errorCode = -1, cause } = {}) {
    super(message, { cause })
    this.status = status
    this.errorCode = errorCode
  }
}

// ── token ─────────────────────────────────────────────

let cached = null // { token, expiresAt（毫秒）, configKey }
let pending = null // 換取中的 Promise（同時多個請求共用）
let clockOffset = 0 // 伺服器時間 - 本機時間（秒），時間窗失敗時校準

const configKey = (c) => `${c.base}|${c.appId}|${c.secret}`

async function fetchJson(url, { method = 'GET', headers = {}, body, timeoutMs }) {
  let res
  try {
    res = await fetch(url, {
      method,
      headers: { Accept: 'application/json', ...headers, ...(body !== undefined && { 'Content-Type': 'application/json' }) },
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(timeoutMs),
    })
  } catch (err) {
    const timedOut = err?.name === 'TimeoutError'
    throw new FactoryError(timedOut ? '連線後端逾時' : `無法連線到後端：${err?.cause?.code || err.message}`, {
      status: timedOut ? 504 : 502,
      cause: err,
    })
  }
  const text = await res.text()
  let data = null
  try {
    data = text ? JSON.parse(text) : {}
  } catch {
    throw new FactoryError(`後端回應不是 JSON（HTTP ${res.status}）`, { status: 502 })
  }
  return { status: res.status, ok: res.ok, data }
}

async function requestToken(config, retried = false) {
  const timestamp = Math.floor(Date.now() / 1000) + clockOffset
  const nonce = crypto.randomBytes(16).toString('hex')
  const sign = crypto.createHmac('sha256', config.secret).update(`${config.appId}|${timestamp}|${nonce}`).digest('hex')

  const res = await fetchJson(`${config.base}${TOKEN_PATH}`, {
    method: 'POST',
    body: { app_id: config.appId, timestamp, nonce, sign },
    timeoutMs: config.timeoutMs,
  })

  if (res.ok && res.data?.status !== false && res.data?.data?.access_token) {
    const { access_token: token, expires_in: expiresIn } = res.data.data
    const ttl = Math.max(Number(expiresIn) - TOKEN_EARLY_EXPIRE, 1)
    logger.info('Factory', `已取得後端 access token（有效 ${expiresIn} 秒）`)
    return { token, expiresAt: Date.now() + ttl * 1000, configKey: configKey(config) }
  }

  // 時間窗失敗：後端回傳 server_time，校準時鐘後重試一次
  const serverTime = Number(res.data?.server_time ?? res.data?.data?.server_time)
  if (res.status === 401 && Number.isFinite(serverTime) && serverTime > 0 && !retried) {
    clockOffset = serverTime - Math.floor(Date.now() / 1000)
    logger.warning('Factory', `本機與後端時間相差 ${clockOffset} 秒，校準後重新換取 token`)
    return requestToken(config, true)
  }

  const message = res.data?.error_message || `HTTP ${res.status}`
  logger.error('Factory', `換取後端 access token 失敗：${message}`, { status: res.status, appId: config.appId })
  throw new FactoryError(`後端認證失敗：${message}`, { status: res.status === 429 ? 429 : 401, errorCode: res.data?.error_code ?? -3 })
}

/** 取得有效的 token（快取到快過期為止） */
async function getToken(config, { force = false } = {}) {
  const key = configKey(config)
  if (!force && cached && cached.configKey === key && cached.expiresAt > Date.now()) return cached.token
  if (!pending) {
    pending = requestToken(config)
      .then((t) => {
        cached = t
        return t.token
      })
      .finally(() => {
        pending = null
      })
  }
  return pending
}

// ── 呼叫後端 ─────────────────────────────────────────

/**
 * 以 App 身份呼叫後端 API；收到 401 時重新換取 token 並重試一次
 * @param {string} method
 * @param {string} path  /api/factory 之後的路徑，例 /guest/slot
 * @returns {Promise<{ status: number, data: any }>}
 */
export async function callFactory(method, path, { query, body } = {}) {
  const config = getConfig()
  if (!config.base || !config.appId || !config.secret) {
    throw new FactoryError('尚未設定後端 API（FACTORY_API_BASE / FACTORY_APP_ID / FACTORY_APP_SECRET）', {
      status: 503,
      errorCode: -2,
    })
  }

  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(query || {})) {
    if (v !== undefined && v !== null && v !== '') qs.append(k, String(v))
  }
  const url = `${config.base}/api/factory${path}${qs.size ? `?${qs}` : ''}`

  const send = async (force) => {
    const token = await getToken(config, { force })
    return fetchJson(url, { method, headers: { Authorization: `Bearer ${token}` }, body, timeoutMs: config.timeoutMs })
  }

  let res = await send(false)
  if (res.status === 401) {
    logger.info('Factory', `後端回應 401，重新換取 token 後重試：${method} ${path}`)
    cached = null
    res = await send(true)
  }
  return { status: res.status, data: res.data }
}

/** 代理路由：掛在 /api/factory，只轉送白名單內的 API，原樣回傳後端狀態碼與 JSON */
export function createFactoryRouter() {
  const router = Router()

  router.use(async (req, res) => {
    if (!ALLOWED.has(`${req.method} ${req.path}`)) {
      return res.status(404).json({ status: false, error_code: 404, error_message: `API 不存在：${req.method} ${req.originalUrl}` })
    }
    const started = Date.now()
    try {
      const r = await callFactory(req.method, req.path, {
        query: req.query,
        body: req.method === 'GET' ? undefined : req.body || {},
      })
      const level = r.status >= 500 ? 'error' : r.status >= 400 ? 'warning' : 'info'
      logger[level]('Factory', `${req.method} ${req.path} → ${r.status}（${Date.now() - started} ms）`, {
        ...(r.status >= 400 && { error_code: r.data?.error_code, error_message: r.data?.error_message, trace_id: r.data?.trace_id }),
      })
      res.status(r.status).json(r.data)
    } catch (err) {
      const status = err instanceof FactoryError ? err.status : 500
      logger.error('Factory', `${req.method} ${req.path} 失敗：${err.message}（${Date.now() - started} ms）`)
      res.status(status).json({ status: false, error_code: err.errorCode ?? -1, error_message: err.message })
    }
  })

  return router
}
