// Plustek WebFXScan 服務的 WebSocket 用戶端（精簡移植自 SDK DemoCode/WebFXScan/scan.js）
//
// 協定：ws://<host>:<port>/webscan2，JSON 訊息
//   送出   { type: 'call', func: 'LibWFX_xxx', data: {...} }
//   回覆   { type: 'return', func: 'LibWFX_xxx', data: { err_code, message } }
//   通知   { type: 'callback', func: 'LIBWFX_NOTIFY_IMAGE_DONE' | 'LIBWFX_NOTIFY_END' |
//            'LIBWFX_EVENT_CODE' | 'LIBWFX_NOTIFY_EXCEPTION', data: {...} }
//   回覆中常有「JSON 字串包 JSON」，需逐層解析（parseMessage）
//
// 事件（EventEmitter）：open、close、event(code, msg)、imageDone(message)、end(msg)、exception(msg)、error(err)
//
// ※ 使用 ws 套件並關閉 permessage-deflate：WebScan2（websocket-sharp）每則訊息都壓縮成獨立的 deflate 串流，
//   Node 內建 WebSocket（undici）只能正確解出第一則，之後的回覆都變成空字串（例如 LibWFX_GetDeviesList）。
import { EventEmitter } from 'node:events'
import WebSocket from 'ws'

/** 解析 WebFXScan 訊息：移除跳脫的換行並將看起來像 JSON 的字串逐層 parse（同 SDK 的 sdkV2MessageParser） */
export function parseMessage(text) {
  const obj = JSON.parse(String(text).replace(/\\r\\n/g, ''))
  const walk = (o) => {
    for (const key in o) {
      const v = o[key]
      if (typeof v === 'string') {
        const s = v.trim()
        if ((s.startsWith('{') && s.endsWith('}')) || (s.startsWith('[') && s.endsWith(']'))) {
          try {
            o[key] = JSON.parse(s)
            if (o[key] && typeof o[key] === 'object') walk(o[key])
          } catch {
            /* 非 JSON，保留原字串 */
          }
        }
      } else if (v && typeof v === 'object') {
        walk(v)
      }
    }
    return o
  }
  return walk(obj)
}

export class WebFxScanError extends Error {
  constructor(message, { code = 'SCANNER_ERROR', errCode, func } = {}) {
    super(message)
    this.code = code
    this.errCode = errCode
    this.func = func
  }
}

export class WebFxScanClient extends EventEmitter {
  /**
   * @param {{ host?: string, port?: number, reconnect?: boolean }} opts
   */
  constructor({ host = '127.0.0.1', port = 17778, reconnect = true } = {}) {
    super()
    this.url = `ws://${host}:${port}/webscan2`
    this.reconnect = reconnect
    this.ws = null
    this.connected = false
    this.pending = new Map() // func → { resolve, reject, timer }
    this.retryDelay = 5000
    this.retryTimer = null
    this.closedByUser = false
  }

  /** 建立連線（失敗時依 reconnect 設定自動重試：5 秒起，最長 30 秒） */
  connect() {
    this.closedByUser = false
    clearTimeout(this.retryTimer)
    let ws
    try {
      ws = new WebSocket(this.url, { perMessageDeflate: false })
    } catch (err) {
      this.emit('error', err)
      this.scheduleReconnect()
      return
    }
    this.ws = ws

    ws.addEventListener('open', () => {
      this.connected = true
      this.retryDelay = 5000
      this.emit('open')
    })
    ws.addEventListener('message', (ev) => this.handleMessage(ev.data))
    ws.addEventListener('error', (ev) => {
      this.emit('error', new Error(ev?.message || `無法連線 ${this.url}`))
    })
    ws.addEventListener('close', (ev) => {
      const wasConnected = this.connected
      this.connected = false
      this.ws = null
      for (const [func, p] of this.pending) {
        clearTimeout(p.timer)
        p.reject(new WebFxScanError('與掃描服務的連線中斷', { code: 'OFFLINE', func }))
      }
      this.pending.clear()
      const silent = this.silentClose
      this.silentClose = false
      if (wasConnected && !silent) this.emit('close', ev)
      this.scheduleReconnect()
    })
  }

  scheduleReconnect() {
    if (!this.reconnect || this.closedByUser) return
    clearTimeout(this.retryTimer)
    this.retryTimer = setTimeout(() => this.connect(), this.retryDelay)
    this.retryDelay = Math.min(this.retryDelay * 2, 30000)
  }

  // 結束連線即釋放掃描器（SDK 的 close() 也只是關閉 WebSocket，服務沒有 LibWFX_Close）
  close() {
    this.closedByUser = true
    clearTimeout(this.retryTimer)
    this.ws?.close()
  }

  /** 關閉目前連線並於 delayMs 後重新連線（不觸發 close 事件），用於初始化失敗後取得全新工作階段 */
  restart(delayMs = 15000) {
    if (this.closedByUser) return
    this.silentClose = true
    this.retryDelay = delayMs
    if (this.ws) this.ws.close()
    else this.scheduleReconnect()
  }

  handleMessage(raw) {
    const text = typeof raw === 'string' ? raw : Buffer.from(raw).toString('utf8')
    // 防呆：若收到空白訊息（例如訊息解壓失敗），直接讓等待中的呼叫失敗，避免空等到逾時
    if (!text.trim()) {
      const [func, p] = this.pending.size === 1 ? [...this.pending][0] : []
      if (p) {
        this.pending.delete(func)
        clearTimeout(p.timer)
        p.reject(
          new WebFxScanError(`${func} 收到空白回應（請確認掃描服務正常，必要時重新啟動 WebScan2.exe）`, { code: 'BAD_STATE', func }),
        )
      }
      return
    }
    let msg
    try {
      msg = parseMessage(text)
    } catch (err) {
      this.emit('error', new Error(`無法解析掃描服務訊息：${err.message}`))
      return
    }
    const { type, func, data = {} } = msg

    if (type === 'return') {
      const p = this.pending.get(func)
      if (!p) return
      this.pending.delete(func)
      clearTimeout(p.timer)
      p.resolve(data)
      return
    }
    if (type !== 'callback') return

    switch (func) {
      case 'LIBWFX_EVENT_CODE':
        this.emit('event', data.event_code, data)
        break
      case 'LIBWFX_NOTIFY_IMAGE_DONE':
        this.emit('imageDone', data)
        break
      case 'LIBWFX_NOTIFY_END':
        this.emit('end', data)
        break
      case 'LIBWFX_NOTIFY_EXCEPTION':
        this.emit('exception', data)
        break
      default:
        this.emit('callback', func, data)
    }
  }

  /** 送出指令（不等待回覆） */
  send(func, data) {
    if (!this.connected || !this.ws) throw new WebFxScanError('掃描服務未連線', { code: 'OFFLINE', func })
    this.ws.send(JSON.stringify({ type: 'call', func, ...(data === undefined ? {} : { data }) }))
  }

  /**
   * 呼叫並等待同名的 return 回覆
   * @param {object} opts okCodes：視為成功的 err_code（預設 [0]）
   * @returns {Promise<any>} 回覆的 data.message
   */
  call(func, data, { timeoutMs = 15000, okCodes = [0] } = {}) {
    if (this.pending.has(func)) {
      return Promise.reject(new WebFxScanError(`${func} 執行中`, { code: 'BUSY', func }))
    }
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(func)
        reject(new WebFxScanError(`${func} 逾時`, { code: 'TIMEOUT', func }))
      }, timeoutMs)
      this.pending.set(func, {
        timer,
        reject,
        resolve: (d) => {
          const errCode = d?.err_code ?? 0
          if (okCodes.includes(errCode)) resolve(d?.message)
          else reject(new WebFxScanError(`${func} 失敗（${errCode}）：${stringify(d?.message)}`, { errCode, func }))
        },
      })
      try {
        this.send(func, data)
      } catch (err) {
        clearTimeout(timer)
        this.pending.delete(func)
        reject(err)
      }
    })
  }
}

const stringify = (v) => (typeof v === 'string' ? v : JSON.stringify(v ?? ''))

// 裝置能力（source / duplex）對應可用的來源（移植自 scan.js sourceMap）
const SOURCE_MAP = {
  1: { true: ['ADF-Front', 'ADF-Back', 'ADF-Duplex'], false: ['ADF-Front'] },
  2: { true: ['Sheetfed-Front', 'Sheetfed-Back', 'Sheetfed-Duplex'], false: ['Sheetfed-Front'] },
  3: {
    true: ['Auto', 'ADF-Front', 'ADF-Back', 'ADF-Duplex', 'Sheetfed-Front', 'Sheetfed-Back', 'Sheetfed-Duplex'],
    false: ['Auto', 'ADF-Front', 'Sheetfed-Front'],
  },
  4: { true: ['Flatbed'], false: ['Flatbed'] },
  5: { true: ['Auto', 'ADF-Front', 'ADF-Back', 'ADF-Duplex', 'Flatbed'], false: ['Auto', 'ADF-Front', 'Flatbed'] },
  6: { true: ['Camera'], false: ['Camera'] }, // 例 A61 / A62 / A63 / A64 證件掃描器（攝影機式）
}

// 讀不到裝置能力時，依型號推定來源（SDK scan.js 的 devices 表中 type4 = Camera）
const CAMERA_MODELS = new Set(['A61', 'A62', 'A63', 'A64'])
export const fallbackSources = (device) => (CAMERA_MODELS.has(String(device).toUpperCase()) ? ['Camera'] : [])

/** 由 LibWFX_GetDeviceCapability 的回覆取得可用來源清單 */
export function sourcesFromCapability(cap) {
  const source = Number(cap?.source)
  const duplex = cap?.duplex === true || cap?.duplex === 1 || cap?.duplex === 'true'
  return SOURCE_MAP[source]?.[duplex] || []
}

/** LIBWFX_ERRCODE_PAPER_NOT_READY：尚未放入證件（攝影機式裝置以輪詢掃描時的正常回覆） */
export const ERR_PAPER_NOT_READY = 16
