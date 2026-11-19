// 證件掃描器服務（Plustek WebFXScan）
//
// .env 設定（櫃台「系統設定」→ 證件掃描器，修改後需重新啟動服務）：
//   SCANNER_TYPE            mock（預設，模擬資料）| plustek（Plustek WebFXScan 服務）
//   SCANNER_HOST / PORT     WebFXScan 服務位址，預設 127.0.0.1:17778
//   SCANNER_DEVICE          裝置名稱，留空 = 第一台
//   SCANNER_SOURCE          掃描來源，留空 = 依裝置能力自動選擇（例 Sheetfed-Front；A62 等攝影機式為 Camera）
//   SCANNER_RECOGNIZE_TYPE  辨識類型：twid（台灣身分證，預設）| passport | auto
//   SCANNER_TIMEOUT         單次掃描逾時秒數，預設 30
//   SCANNER_POLL_INTERVAL   攝影機式裝置偵測證件的試掃間隔（毫秒），預設 1500
//
// 流程（對照 SDK DemoCode/WebFXScan）：連線 → LibWFX_Init → 取得裝置 → 取得來源 → LibWFX_SetProperty
//   自動掃描：訪客在身份驗證頁時 arm()
//     ‧ 進紙式（Sheetfed / ADF）：設定 autoscan，插入證件時服務送出 event_code 7 → 送出 LibWFX_AsynchronizeScan
//     ‧ 攝影機式（Camera，例 A62）：沒有放入事件，改為定時送出 LibWFX_AsynchronizeScan 輪詢；
//       未放證件時回覆 LIBWFX_ERRCODE_PAPER_NOT_READY（16），靜默略過；完成一次掃描後暫停，直到再次 arm()
//     → LIBWFX_NOTIFY_IMAGE_DONE（辨識資料）→ LIBWFX_NOTIFY_END → 解析結果
//   手動掃描：scanNow()（重試按鈕）
// 個資：完整證號只在 parse.js 內使用，結果與記錄皆為遮罩後的值。
import { logger } from '../logger.js'
import {
  ERR_PAPER_NOT_READY,
  WebFxScanClient,
  WebFxScanError,
  fallbackSources,
  sourcesFromCapability,
} from './webfxscan.js'
import { describeKeys, normalizeIdCard } from './parse.js'

const EVENT_PAPER_READY = 7 // 偵測到紙張 / 證件，應開始掃描
const ERR_SERVER_OCCUPIED = 1014 // LIBWFX_ERRCODE_SERVER_OCCUPIED：掃描服務已有其他用戶端在使用

export function readScannerConfig(env = process.env) {
  const type = String(env.SCANNER_TYPE || 'mock').toLowerCase() === 'plustek' ? 'plustek' : 'mock'
  return {
    type,
    host: (env.SCANNER_HOST || '127.0.0.1').trim(),
    port: Number(env.SCANNER_PORT) || 17778,
    device: (env.SCANNER_DEVICE || '').trim(),
    source: (env.SCANNER_SOURCE || '').trim(),
    recognizeType: (env.SCANNER_RECOGNIZE_TYPE || 'twid').trim(),
    timeout: Math.min(120, Math.max(5, Number(env.SCANNER_TIMEOUT) || 30)),
    pollInterval: Math.min(10000, Math.max(500, Number(env.SCANNER_POLL_INTERVAL) || 1500)),
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

export class ScannerService {
  /**
   * @param {ReturnType<typeof readScannerConfig>} config
   * @param {object} hooks
   * @param {(status: object) => void} [hooks.onStatus]   狀態變更
   * @param {() => void} [hooks.onScanning]               開始掃描
   * @param {(card: object) => void} [hooks.onResult]     掃描成功 { name, idNumber（遮罩） }
   * @param {(err: Error) => void} [hooks.onError]        掃描失敗
   * @param {(recognizedata: unknown) => void} [hooks.onPage]  每頁原始辨識資料（僅供 idcard:test 使用，請勿寫入記錄）
   */
  constructor(config, hooks = {}) {
    this.config = config
    this.hooks = hooks
    this.client = null
    this.job = null
    this.setupTimer = null
    this.pollMode = false // 攝影機式裝置：以輪詢掃描偵測證件
    this.pollPaused = false
    this.pollTimer = null
    this.state = {
      type: config.type,
      connected: false,
      ready: config.type === 'mock',
      device: config.type === 'mock' ? '模擬' : '',
      source: '',
      armed: false,
      scanning: false,
      error: '',
      errorCode: '', // 例 OCCUPIED（被其他程式佔用）、NO_DEVICE、OFFLINE，供櫃台畫面顯示原因
    }
  }

  getStatus() {
    return { ...this.state }
  }

  setState(patch) {
    const before = JSON.stringify(this.state)
    Object.assign(this.state, patch)
    if (JSON.stringify(this.state) !== before) this.hooks.onStatus?.(this.getStatus())
  }

  start() {
    if (this.config.type === 'mock') {
      logger.info('Scanner', '證件掃描器：模擬模式（SCANNER_TYPE=mock）')
      return this
    }
    const { host, port } = this.config
    const client = new WebFxScanClient({ host, port })
    this.client = client
    let offlineLogged = false

    client.on('open', () => {
      offlineLogged = false
      logger.info('Scanner', `已連線掃描服務 ${client.url}`)
      this.setState({ connected: true, error: '', errorCode: '' })
      this.setup()
    })
    client.on('close', () => {
      if (client.closedByUser) return
      logger.warning('Scanner', '與掃描服務的連線中斷，將自動重新連線')
      this.failJob(new WebFxScanError('與掃描服務的連線中斷', { code: 'OFFLINE' }))
      this.setState({ connected: false, ready: false, error: '連線中斷', errorCode: 'OFFLINE' })
    })
    client.on('error', (err) => {
      if (!this.state.connected && !offlineLogged) {
        offlineLogged = true
        logger.warning('Scanner', `無法連線掃描服務 ${client.url}（請確認已安裝 Plustek SDK 且服務已啟動）：${err.message}`)
        this.setState({ error: '無法連線掃描服務', errorCode: 'OFFLINE' })
      }
    })
    client.on('event', (code) => {
      if (code !== EVENT_PAPER_READY) return
      if (!this.state.armed) return logger.debug('Scanner', '偵測到證件，但目前不在身份驗證步驟，略過')
      if (!this.job && this.state.ready) this.startJob('auto').catch(() => {})
    })
    client.on('imageDone', (data) => this.onImageDone(data))
    client.on('end', () => this.finishJob())
    client.on('exception', (data) => {
      const message = data?.message || data?.notify_code || '裝置異常'
      logger.warning('Scanner', `掃描器異常：${typeof message === 'string' ? message : JSON.stringify(message)}`)
      this.failJob(new WebFxScanError(`掃描器異常：${message}`, { code: 'DEVICE_ERROR' }))
    })

    client.connect()
    return this
  }

  stop() {
    clearTimeout(this.setupTimer)
    clearTimeout(this.pollTimer)
    this.client?.close()
  }

  /** 連線後初始化：Init → 裝置 → 來源 → 設定掃描參數（自動掃描 + 辨識） */
  async setup() {
    clearTimeout(this.setupTimer)
    const { client, config } = this
    try {
      try {
        await client.call('LibWFX_Init', undefined, { okCodes: [0, 1001, 1002, 1003] })
      } catch (err) {
        if (err.errCode === ERR_SERVER_OCCUPIED) {
          throw new WebFxScanError(
            '掃描服務已被其他程式佔用（1014）：請關閉瀏覽器中的 Plustek 範例頁、其他使用掃描器的程式，或另一個執行中的 kiosk 服務',
            { code: 'OCCUPIED', errCode: err.errCode, func: err.func },
          )
        }
        throw err
      }

      const list = await client.call('LibWFX_GetDeviesList')
      const devices = [].concat(list?.szDevicesList || []).filter(Boolean)
      if (!devices.length) throw new WebFxScanError('未偵測到掃描器（請確認掃描器已接上且已安裝驅動程式）', { code: 'NO_DEVICE' })
      const device = config.device || devices[0]
      if (config.device && !devices.includes(config.device)) {
        throw new WebFxScanError(`找不到裝置 ${config.device}（偵測到：${devices.join(', ')}）`, { code: 'NO_DEVICE' })
      }

      let source = config.source
      if (!source) {
        try {
          const cap = await client.call('LibWFX_GetDeviceCapability', { 'device-name': device })
          source = sourcesFromCapability(cap)[0] || ''
        } catch (err) {
          logger.warning('Scanner', `無法取得裝置能力：${err.message}`)
        }
      }
      if (!source) source = fallbackSources(device)[0] || ''
      if (!source) throw new WebFxScanError('無法判斷掃描來源，請於系統設定填寫 SCANNER_SOURCE', { code: 'NO_SOURCE' })

      const pollMode = source === 'Camera'
      // 參數同 SDK 範例頁預設（resolution 300、color）；攝影機式裝置不支援 autoscan 事件，改用輪詢
      await client.call('LibWFX_SetProperty', {
        'device-name': device,
        source,
        'recognize-type': config.recognizeType,
        resolution: 300,
        mode: 'color',
        ...(pollMode ? {} : { autoscan: true }),
        base64enc: true,
      })
      this.pollMode = pollMode
      logger.info(
        'Scanner',
        `掃描器就緒：${device}（${source}，辨識 ${config.recognizeType}${pollMode ? '，輪詢偵測證件' : ''}）`,
      )
      this.setState({ ready: true, device, source, error: '', errorCode: '' })
      this.schedulePoll(0)
    } catch (err) {
      logger.error('Scanner', `掃描器初始化失敗：${err.message}`)
      this.setState({ ready: false, error: err.message, errorCode: err.code || 'SCANNER_ERROR' })
      // 15 秒後以全新連線重試（例如掃描器稍後才接上、佔用的程式已關閉）。
      // 不在同一連線上重送 LibWFX_Init：服務在回覆錯誤後對同一連線的 Init 不再回應（會逾時）。
      if (client.connected) client.restart(15000)
    }
  }

  arm() {
    if (!this.state.armed) logger.debug('Scanner', '等待證件插入（自動掃描）')
    this.pollPaused = false
    this.setState({ armed: true })
    this.schedulePoll(0)
  }

  disarm() {
    clearTimeout(this.pollTimer)
    this.setState({ armed: false })
  }

  /** 攝影機式裝置：等待中時定時送出掃描（未放證件時服務回覆 16，靜默略過） */
  schedulePoll(delay = this.config.pollInterval) {
    clearTimeout(this.pollTimer)
    if (!this.pollMode || !this.state.armed || this.pollPaused) return
    this.pollTimer = setTimeout(() => {
      if (!this.state.ready || !this.state.armed || this.pollPaused) return
      if (this.job) return this.schedulePoll()
      this.startJob('auto', { probe: true }).catch(() => {})
    }, delay)
  }

  /** 手動掃描（重試按鈕）；掃描進行中時等待同一次結果 */
  async scanNow() {
    if (this.config.type === 'mock') {
      this.hooks.onScanning?.()
      await sleep(1500)
      const card = { name: '王小明', idNumber: 'A123****89' }
      this.hooks.onResult?.(card)
      return card
    }
    if (this.job) {
      // 輪詢中的探測掃描轉為手動：結果（含「未放入證件」）都要回報
      if (this.job.probe) {
        this.job.probe = false
        this.announceJob(this.job)
      }
      return this.job.promise
    }
    if (!this.state.connected) {
      throw new WebFxScanError('掃描服務未連線', { code: 'OFFLINE' })
    }
    if (!this.state.ready) {
      throw new WebFxScanError(this.state.error || '掃描器尚未就緒', { code: 'OFFLINE' })
    }
    return this.startJob('manual')
  }

  /**
   * @param {'auto'|'manual'} origin
   * @param {{ probe?: boolean }} opts probe：輪詢探測，服務確認開始掃描（有證件）後才通知畫面
   */
  startJob(origin, { probe = false } = {}) {
    let resolve, reject
    const promise = new Promise((res, rej) => {
      resolve = res
      reject = rej
    })
    const job = { origin, probe, announced: false, pages: [], resolve, reject, promise, timer: null }
    this.job = job
    this.resetJobTimer()
    if (!probe) this.announceJob(job)

    this.client
      .call('LibWFX_AsynchronizeScan', undefined, { timeoutMs: this.config.timeout * 1000 })
      .then(() => {
        if (this.job === job) this.announceJob(job)
      })
      .catch((err) => {
        if (this.job !== job) return
        if (err.errCode === ERR_PAPER_NOT_READY) {
          if (job.probe) {
            // 尚未放入證件：靜默結束，稍後再試
            this.endJob()
            job.reject(err)
            return this.schedulePoll()
          }
          return this.failJob(
            new WebFxScanError('未偵測到證件，請將證件放入掃描器', { code: 'NOT_READY', errCode: err.errCode }),
          )
        }
        this.failJob(err)
      })
    return promise
  }

  /** 通知畫面開始掃描（每個工作只通知一次） */
  announceJob(job) {
    if (job.announced) return
    job.announced = true
    this.setState({ scanning: true })
    this.hooks.onScanning?.()
    logger.info('Scanner', `開始掃描證件（${job.origin === 'auto' ? '自動' : '手動'}）`)
  }

  resetJobTimer() {
    const job = this.job
    if (!job) return
    clearTimeout(job.timer)
    job.timer = setTimeout(
      () => this.failJob(new WebFxScanError(`掃描逾時（${this.config.timeout} 秒）`, { code: 'TIMEOUT' })),
      this.config.timeout * 1000,
    )
  }

  onImageDone(data) {
    if (!this.job) return
    if (data?.err_code) {
      return this.failJob(new WebFxScanError(`掃描失敗（${data.err_code}）`, { code: 'SCAN_FAILED', errCode: data.err_code }))
    }
    const recognizedata = data?.message?.recognizedata
    this.job.pages.push(recognizedata)
    this.hooks.onPage?.(recognizedata)
    this.resetJobTimer()
  }

  finishJob() {
    const job = this.job
    if (!job) return
    let card = null
    for (const page of job.pages) {
      try {
        card = normalizeIdCard(page)
        if (card.name && card.idNumber) break
      } catch {
        /* 下一頁（例如背面） */
      }
    }
    if (!card) {
      const keys = job.pages.flatMap((p) => describeKeys(p))
      logger.warning('Scanner', `無法辨識證件資料（${job.pages.length} 頁，欄位：${keys.join(', ') || '無'}）`)
      return this.failJob(new WebFxScanError('無法辨識證件資料', { code: 'NO_DATA' }))
    }
    this.endJob()
    this.pollPaused = true // 證件可能仍在掃描器上，避免重複辨識；下次 arm() 再恢復輪詢
    logger.info('Scanner', `證件辨識完成：${card.name || '（無姓名）'}／${card.idNumber || '（無證號）'}`)
    job.resolve(card)
    this.hooks.onResult?.(card)
  }

  failJob(err) {
    const job = this.job
    if (!job) return
    this.endJob()
    if (job.probe && !job.announced) {
      // 輪詢探測在服務確認開始掃描前就失敗（例如逾時）：只記錄，不打擾訪客畫面，稍後重試
      logger.warning('Scanner', `偵測證件失敗：${err.message}`)
      job.reject(err)
      return this.schedulePoll(this.config.pollInterval * 4)
    }
    this.pollPaused = true // 失敗後等訪客按重試（scanNow）或重新進入身份驗證頁
    logger.warning('Scanner', `證件掃描失敗：${err.message}`)
    job.reject(err)
    this.hooks.onError?.(err)
  }

  endJob() {
    clearTimeout(this.job?.timer)
    this.job = null
    this.setState({ scanning: false })
  }
}

// ── 服務單例（server/index.js 使用） ──
let service = null

/** 啟動掃描器服務（依 .env 設定） */
export function startScanner(hooks) {
  service = new ScannerService(readScannerConfig(), hooks).start()
  return service
}

export const getScanner = () => service
