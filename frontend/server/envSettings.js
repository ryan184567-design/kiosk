// 系統設定（.env）的讀取、驗證與寫入，供櫃台螢幕（/staff）的「系統設定」介面使用
//
// - 直接修改專案根目錄的 .env（保留註解與順序；新欄位加在檔案最後），寫入前備份為 .env.bak
// - 寫入後同步更新 process.env：每次使用時才讀取的設定（印表機、記錄、PIN、前端設定）立即生效
// - apply：live = 立即生效 ｜ reload = 畫面重新整理後生效 ｜ restart = 需重新啟動服務 ｜ kiosk = 下次開啟 Kiosk 瀏覽器生效
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
export const ENV_FILE = path.resolve(__dirname, '../.env')

const LOG_LEVEL_OPTIONS = [
  { value: 'debug', label: 'debug（全部）' },
  { value: 'info', label: 'info（一般）' },
  { value: 'warning', label: 'warning（警告以上）' },
  { value: 'error', label: 'error（只記錯誤）' },
]

/**
 * 欄位定義
 * type：text | number | select | switch | password
 * when：{ KEY: [值…] } 符合時才顯示（介面用）
 * source：printers | devices 下拉選單由伺服器偵測的清單提供
 */
export const SCHEMA = [
  {
    id: 'printer',
    title: '印表機',
    fields: [
      {
        key: 'PRINTER_TYPE',
        label: '列印方式',
        type: 'select',
        default: 'mock',
        apply: 'live',
        options: [
          { value: 'usb', label: 'Windows 印表機 / USB', platforms: ['win32', 'linux'] },
          { value: 'device', label: '直接寫入 COM 埠 / 裝置' },
          { value: 'network', label: '網路印表機' },
          { value: 'cups', label: 'CUPS（Linux）', platforms: ['linux'] },
          { value: 'mock', label: '不列印（測試，存到 logs/print-last.*）' },
        ],
      },
      {
        key: 'PRINTER_NAME',
        label: '印表機名稱',
        type: 'text',
        default: '',
        apply: 'live',
        source: 'printers',
        when: { PRINTER_TYPE: ['usb', 'windows', 'cups'] },
        help: '透過系統列印佇列送出；Windows 留空 = 預設印表機（開機服務的預設印表機可能不同，建議指定）',
      },
      {
        key: 'PRINTER_DEVICE',
        label: 'COM 埠 / 裝置',
        type: 'text',
        default: '',
        apply: 'live',
        source: 'devices',
        when: { PRINTER_TYPE: ['device'] },
        help: 'Windows 例：COM9（直接寫入，不經列印佇列，印表機未接上會立即顯示錯誤）；Linux 例：/dev/usb/lp0',
      },
      {
        key: 'PRINTER_HOST',
        label: '印表機 IP',
        type: 'text',
        default: '',
        apply: 'live',
        placeholder: '192.168.0.200',
        when: { PRINTER_TYPE: ['network'] },
      },
      {
        key: 'PRINTER_PORT',
        label: '印表機埠號',
        type: 'number',
        min: 1,
        max: 65535,
        default: 9100,
        apply: 'live',
        when: { PRINTER_TYPE: ['network'] },
      },
      { key: 'PRINTER_TITLE', label: '訪客證標題', type: 'text', max: 30, default: '訪客證', apply: 'live' },
      {
        key: 'PRINTER_PAPER_WIDTH',
        label: '紙寬',
        type: 'select',
        default: '80',
        apply: 'live',
        options: [
          { value: '80', label: '80 mm' },
          { value: '58', label: '58 mm' },
        ],
      },
      {
        key: 'PRINTER_QR_MODE',
        label: 'QR Code 列印方式',
        type: 'select',
        default: 'image',
        apply: 'live',
        options: [
          { value: 'image', label: '點陣圖（所有機型可用）' },
          { value: 'native', label: '印表機內建指令（部分機型印不出來）' },
        ],
      },
      {
        key: 'PRINTER_QR_SIZE',
        label: 'QR Code 大小',
        type: 'number',
        min: 1,
        max: 16,
        default: 8,
        apply: 'live',
        help: '1~16，8 約 2.5 公分見方',
      },
      {
        key: 'PRINTER_ENCODING',
        label: '文字編碼',
        type: 'select',
        default: 'big5',
        apply: 'live',
        options: [
          { value: 'big5', label: 'Big5（繁體中文）' },
          { value: 'gb18030', label: 'GB18030（簡體中文機型）' },
          { value: 'shift_jis', label: 'Shift_JIS（日文）' },
          { value: 'ascii', label: 'ASCII' },
        ],
      },
      {
        key: 'PRINTER_CHINESE_MODE',
        label: '送出中文模式指令',
        type: 'switch',
        default: 'true',
        apply: 'live',
        help: '中文字變亂碼時可試著關閉',
      },
    ],
  },
  {
    id: 'scanner',
    title: '證件掃描器',
    fields: [
      {
        key: 'SCANNER_TYPE',
        label: '掃描器',
        type: 'select',
        default: 'mock',
        apply: 'restart',
        options: [
          { value: 'plustek', label: 'Plustek（WebFXScan 服務）' },
          { value: 'mock', label: '模擬（不使用掃描器，回傳範例資料）' },
        ],
        help: '使用 Plustek 前需安裝 PlustekSDK_LDSetup 與掃描器驅動程式；可用 npm run idcard:test 測試',
      },
      {
        key: 'SCANNER_HOST',
        label: 'WebFXScan 服務位址',
        type: 'text',
        default: '127.0.0.1',
        apply: 'restart',
        pattern: '^[0-9A-Za-z.:-]+$',
        when: { SCANNER_TYPE: ['plustek'] },
      },
      {
        key: 'SCANNER_PORT',
        label: 'WebFXScan 服務埠號',
        type: 'number',
        min: 1,
        max: 65535,
        default: 17778,
        apply: 'restart',
        when: { SCANNER_TYPE: ['plustek'] },
      },
      {
        key: 'SCANNER_RECOGNIZE_TYPE',
        label: '辨識類型',
        type: 'select',
        default: 'twid',
        apply: 'restart',
        options: [
          { value: 'twid', label: '台灣身分證（twid）' },
          { value: 'passport', label: '護照（passport）' },
          { value: 'auto', label: '自動判斷（auto）' },
        ],
        when: { SCANNER_TYPE: ['plustek'] },
      },
      {
        key: 'SCANNER_DEVICE',
        label: '裝置名稱',
        type: 'text',
        default: '',
        apply: 'restart',
        when: { SCANNER_TYPE: ['plustek'] },
        help: '留空 = 使用偵測到的第一台掃描器',
      },
      {
        key: 'SCANNER_SOURCE',
        label: '掃描來源',
        type: 'text',
        default: '',
        apply: 'restart',
        placeholder: '自動',
        when: { SCANNER_TYPE: ['plustek'] },
        help: '留空 = 依裝置能力自動選擇（建議）。例：Camera（A61~A64 攝影機式）、Sheetfed-Front、ADF-Front、Flatbed',
      },
      {
        key: 'SCANNER_POLL_INTERVAL',
        label: '證件偵測間隔（毫秒）',
        type: 'number',
        min: 500,
        max: 10000,
        default: 1500,
        apply: 'restart',
        when: { SCANNER_TYPE: ['plustek'] },
        help: '只用於攝影機式掃描器（來源 Camera，例 A62）：等待證件時每隔多久試掃一次；數值越小反應越快，但掃描服務負擔越大',
      },
      {
        key: 'SCANNER_TIMEOUT',
        label: '掃描逾時（秒）',
        type: 'number',
        min: 5,
        max: 120,
        default: 30,
        apply: 'restart',
        when: { SCANNER_TYPE: ['plustek'] },
      },
    ],
  },
  {
    id: 'kiosk',
    title: '訪客機',
    fields: [
      {
        key: 'VITE_IDLE_SECONDS',
        label: '無操作自動回首頁（秒）',
        type: 'number',
        min: 10,
        max: 3600,
        default: 120,
        apply: 'reload',
      },
      {
        key: 'VITE_PASS_CODE_LENGTH',
        label: '速通碼長度',
        type: 'number',
        min: 4,
        max: 32,
        default: 10,
        apply: 'reload',
        help: '有預約訪客的速通碼（QR Code 內容）位數',
      },
      {
        key: 'VITE_DEBUG',
        label: 'Debug 模式',
        type: 'switch',
        default: 'false',
        apply: 'reload',
        help: '將流程資訊輸出到瀏覽器 console；資料可能含個人資料，正式上線請關閉',
      },
    ],
  },
  {
    id: 'factory',
    title: '後端 API',
    fields: [
      {
        key: 'FACTORY_API_BASE',
        label: '後端網址',
        type: 'text',
        default: '',
        apply: 'live',
        pattern: '^(https?://\\S+)?$',
        placeholder: 'http://kiosk.test:8080',
        help: '訪客機 API（/api/factory/*）所在的後端網址',
      },
      {
        key: 'FACTORY_APP_ID',
        label: 'App ID',
        type: 'text',
        default: '',
        apply: 'live',
        pattern: '^[0-9A-Za-z_-]*$',
        help: '後台建立 App 時取得的 app_id',
      },
      {
        key: 'FACTORY_APP_SECRET',
        label: 'App Secret',
        type: 'password',
        default: '',
        apply: 'live',
        help: '只在本機用來簽名換取 token，不會送到網路或瀏覽器',
      },
      {
        key: 'FACTORY_TIMEOUT',
        label: '請求逾時（秒）',
        type: 'number',
        min: 1,
        max: 120,
        default: 10,
        apply: 'live',
      },
    ],
  },
  {
    id: 'log',
    title: '記錄檔',
    fields: [
      {
        key: 'LOG_LEVEL',
        label: '伺服器寫檔等級',
        type: 'select',
        default: 'info',
        apply: 'live',
        options: LOG_LEVEL_OPTIONS,
        help: 'logs/visitor-YYYY-MM-DD.log 寫入的最低等級（含前端送來的記錄）',
      },
      {
        key: 'VITE_LOG_LEVEL',
        label: '前端送出等級',
        type: 'select',
        default: 'info',
        apply: 'reload',
        options: [...LOG_LEVEL_OPTIONS, { value: 'off', label: 'off（不送）' }],
      },
      { key: 'LOG_DAYS', label: '保留天數', type: 'number', min: 1, max: 365, default: 14, apply: 'live' },
    ],
  },
  {
    id: 'server',
    title: '服務',
    fields: [
      {
        key: 'HOST',
        label: '監聽位址',
        type: 'text',
        default: '0.0.0.0',
        apply: 'restart',
        pattern: '^[0-9A-Za-z.:-]+$',
        help: '0.0.0.0 = 所有網卡；127.0.0.1 = 只允許本機（其他電腦將無法連線）',
      },
      {
        key: 'PORT',
        label: '埠號',
        type: 'number',
        min: 1,
        max: 65535,
        default: 3000,
        apply: 'restart',
        help: '修改後需同步調整防火牆規則與 Kiosk 網址',
      },
      {
        key: 'PUBLIC_HOST',
        label: '對外顯示 IP / 網域',
        type: 'text',
        default: '',
        apply: 'restart',
        pattern: '^[0-9A-Za-z.:-]*$',
        help: '僅用於啟動訊息顯示的網址，留空自動列出本機 IP',
      },
    ],
  },
  {
    id: 'browser',
    title: 'Kiosk 瀏覽器',
    fields: [
      {
        key: 'KIOSK_URL',
        label: '開啟網址',
        type: 'text',
        default: '',
        apply: 'kiosk',
        pattern: '^(https?://\\S+)?$',
        placeholder: 'http://localhost:3000',
        help: '留空 = http://localhost:<埠號>',
      },
      {
        key: 'KIOSK_BROWSER',
        label: '瀏覽器',
        type: 'text',
        default: 'auto',
        apply: 'kiosk',
        help: 'auto（Chrome 優先）| chrome | edge | chromium | 瀏覽器執行檔完整路徑',
      },
      {
        key: 'KIOSK_MODE',
        label: '螢幕模式',
        type: 'select',
        default: 'dual',
        apply: 'kiosk',
        options: [
          { value: 'dual', label: '雙螢幕（訪客 + 櫃台）' },
          { value: 'single', label: '單螢幕（只開訪客畫面）' },
        ],
      },
      {
        key: 'KIOSK_VISITOR_DISPLAY',
        label: '訪客畫面螢幕編號',
        type: 'number',
        min: 1,
        max: 9,
        default: 1,
        apply: 'kiosk',
        help: '由左到右 1、2…',
      },
      {
        key: 'KIOSK_STAFF_DISPLAY',
        label: '櫃台畫面螢幕編號',
        type: 'number',
        min: 1,
        max: 9,
        default: 2,
        apply: 'kiosk',
        when: { KIOSK_MODE: ['dual'] },
      },
    ],
  },
  {
    id: 'security',
    title: '安全',
    fields: [
      {
        key: 'SETTINGS_PIN',
        label: '系統設定 PIN',
        type: 'password',
        default: '',
        apply: 'live',
        pattern: '^[0-9A-Za-z]{4,32}$',
        help: '設定後任何電腦輸入 PIN 即可修改設定；未設定則只允許本機修改。4~32 碼英數字',
      },
    ],
  },
]

const FIELDS = new Map(SCHEMA.flatMap((g) => g.fields).map((f) => [f.key, f]))

/** 欄位的值是否為機密（不回傳給瀏覽器） */
const isSecret = (f) => f.type === 'password'

function badRequest(msg) {
  const err = new Error(msg)
  err.status = 400
  return err
}

/** 驗證並轉為寫入 .env 的字串 */
function validate(f, raw) {
  let v = raw === null || raw === undefined ? '' : String(raw).trim()
  if (/[\r\n]/.test(v)) throw badRequest(`${f.label}：不可包含換行`)
  switch (f.type) {
    case 'number': {
      const n = Number(v)
      if (v === '' || !Number.isInteger(n) || n < f.min || n > f.max) {
        throw badRequest(`${f.label}：必須是 ${f.min}~${f.max} 的整數`)
      }
      return String(n)
    }
    case 'switch':
      if (!['true', 'false'].includes(v.toLowerCase())) throw badRequest(`${f.label}：必須是 true / false`)
      return v.toLowerCase()
    case 'select':
      if (!f.options.some((o) => o.value === v)) throw badRequest(`${f.label}：無效的選項「${v}」`)
      return v
    default:
      if (v.length > (f.max || 300)) throw badRequest(`${f.label}：長度不可超過 ${f.max || 300}`)
      // 密碼留空 = 清除，不檢查格式
      if (f.pattern && !(f.type === 'password' && v === '') && !new RegExp(f.pattern).test(v)) {
        throw badRequest(`${f.label}：${v === '' ? '不可留空' : '格式不正確'}`)
      }
      return v
  }
}

// ── .env 檔案讀寫 ──

function readEnvText() {
  try {
    return fs.readFileSync(ENV_FILE, 'utf8')
  } catch (err) {
    if (err.code === 'ENOENT') return ''
    throw err
  }
}

const LINE_RE = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/

function unquote(v) {
  const s = v.trim()
  const q = s[0]
  if ((q === '"' || q === "'") && s.endsWith(q) && s.length >= 2) {
    const inner = s.slice(1, -1)
    return q === '"' ? inner.replace(/\\"/g, '"').replace(/\\\\/g, '\\') : inner
  }
  // 未加引號：# 之後為註解
  return s.replace(/\s+#.*$/, '')
}

function quote(v) {
  if (v === '' || /^[^\s#"'`\\]+$/.test(v)) return v
  return `"${v.replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`
}

/** 讀取 .env 中的值（key → 字串） */
export function readEnvFile() {
  const out = {}
  for (const line of readEnvText().split(/\r?\n/)) {
    const m = LINE_RE.exec(line)
    if (m && !(m[1] in out)) out[m[1]] = unquote(m[2])
  }
  return out
}

/** 將變更寫回 .env（保留原本的註解、順序與換行格式） */
function writeEnvFile(changes) {
  const text = readEnvText()
  const eol = text.includes('\r\n') ? '\r\n' : '\n'
  const lines = text === '' ? [] : text.split(/\r?\n/)
  const pending = new Map(Object.entries(changes))

  for (let i = 0; i < lines.length; i++) {
    const m = LINE_RE.exec(lines[i])
    if (m && pending.has(m[1])) {
      lines[i] = `${m[1]}=${quote(pending.get(m[1]))}`
      pending.delete(m[1])
    }
  }
  if (pending.size) {
    while (lines.length && lines[lines.length - 1] === '') lines.pop()
    lines.push('', '# ── 由櫃台「系統設定」介面新增 ──')
    for (const [k, v] of pending) lines.push(`${k}=${quote(v)}`)
  }
  if (lines[lines.length - 1] !== '') lines.push('')

  if (text) fs.writeFileSync(`${ENV_FILE}.bak`, text, 'utf8')
  const tmp = `${ENV_FILE}.tmp`
  fs.writeFileSync(tmp, lines.join(eol), 'utf8')
  fs.renameSync(tmp, ENV_FILE)
}

// ── 對外 API ──

// 服務啟動時實際使用的值：比對「需重新啟動」的設定是否已變更
// （ES module 載入早於 dotenv.config()，故直接讀 .env；系統環境變數優先，與 dotenv 不覆寫的行為一致）
const startupValues = (() => {
  const file = readEnvFile()
  return Object.fromEntries([...FIELDS.keys()].map((k) => [k, process.env[k] ?? file[k] ?? '']))
})()

/** 目前 .env 的設定值（機密欄位只回傳是否已設定） */
export function getSettingsValues() {
  const file = readEnvFile()
  const values = {}
  const secrets = {}
  for (const [key, f] of FIELDS) {
    const v = file[key] ?? process.env[key] ?? String(f.default ?? '')
    if (isSecret(f)) {
      secrets[key] = v !== ''
      values[key] = ''
    } else {
      values[key] = v === '' && f.type !== 'text' ? String(f.default ?? '') : v
    }
  }
  // 舊設定 windows 與 usb 相同
  if (values.PRINTER_TYPE === 'windows') values.PRINTER_TYPE = 'usb'
  return { values, secrets }
}

/** 已修改、但需重新啟動服務才會生效的欄位 */
export function getRestartPending() {
  const file = readEnvFile()
  return [...FIELDS.values()]
    .filter((f) => f.apply === 'restart' && (file[f.key] ?? '') !== (startupValues[f.key] ?? ''))
    .map((f) => f.key)
}

/**
 * 儲存設定（只需傳入有修改的欄位）
 * @returns {{ changed: string[] }}
 */
export function saveSettings(input) {
  if (!input || typeof input !== 'object') throw badRequest('設定格式錯誤')
  const current = readEnvFile()
  const changes = {}
  for (const [key, raw] of Object.entries(input)) {
    const f = FIELDS.get(key)
    if (!f) throw badRequest(`未知的設定：${key}`)
    const v = validate(f, raw)
    if ((current[key] ?? '') !== v || !(key in current)) changes[key] = v
  }

  // 組合檢查
  const merged = { ...current, ...changes }
  if (merged.PRINTER_TYPE === 'device' && !merged.PRINTER_DEVICE) throw badRequest('直接寫入時必須填寫 COM 埠 / 裝置')
  if (merged.PRINTER_TYPE === 'network' && !merged.PRINTER_HOST) throw badRequest('網路印表機必須填寫印表機 IP')

  const keys = Object.keys(changes)
  if (keys.length) {
    writeEnvFile(changes)
    for (const k of keys) process.env[k] = changes[k]
  }
  return { changed: keys }
}

/** 前端在執行時讀取的設定（注入 index.html，取代 build 時寫死的 VITE_ 值） */
export function getClientConfig() {
  const env = process.env
  return {
    idleSeconds: Number(env.VITE_IDLE_SECONDS) || 120,
    passCodeLength: Number(env.VITE_PASS_CODE_LENGTH) || 10,
    debug: String(env.VITE_DEBUG).trim().toLowerCase() === 'true',
    logLevel: String(env.VITE_LOG_LEVEL || 'info').trim().toLowerCase(),
  }
}

/** 介面用的欄位定義（依作業系統過濾選項） */
export function getSchema(platform = process.platform) {
  return SCHEMA.map((g) => ({
    ...g,
    fields: g.fields.map((f) => ({
      ...f,
      options: f.options?.filter((o) => !o.platforms || o.platforms.includes(platform)),
    })),
  }))
}

export const fieldApply = (key) => FIELDS.get(key)?.apply
