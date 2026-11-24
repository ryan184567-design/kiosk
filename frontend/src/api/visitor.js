// 訪客機 API
//
// 後端訪客機 API（/api/factory/*）一律經本機服務代理：本機保管 App 密鑰、換取 token 並轉送（見 server/factoryApi.js）
// 證件掃描、列印由本機服務直接處理；人臉辨識、通知員工目前仍為模擬
//
// 所有 API 皆經 withDebugLog 包裝，debug 模式下會記錄參數、結果與耗時（見檔案最下方 export）

import { withDebugLog, logWarning } from '@/utils/logger'

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

// ── 後端 API 共用 ──────────────────────────────────

const STATUS_CODES = { 401: 'UNAUTHORIZED', 404: 'NOT_FOUND', 422: 'VALIDATION' }

/**
 * 呼叫後端訪客機 API（經本機 /api/factory 代理）
 * 後端回應格式：成功 { status: true, error_code: 0, data }；失敗 { status: false, error_code, error_message, trace_id }
 * @returns {Promise<any>} 回應的 data
 * @throws {Error} err.code：NOT_FOUND | UNAUTHORIZED | VALIDATION | NETWORK | API_ERROR；另含 status、errorCode、traceId
 */
async function factoryRequest(method, path, { query, body } = {}) {
  const qs = new URLSearchParams()
  for (const [k, v] of Object.entries(query || {})) {
    if (v !== undefined && v !== null && v !== '') qs.append(k, String(v))
  }
  const url = `/api/factory${path}${qs.size ? `?${qs}` : ''}`

  let res
  try {
    res = await fetch(url, {
      method,
      headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    const err = new Error('無法連線到本機服務')
    err.code = 'NETWORK'
    throw err
  }

  const data = await res.json().catch(() => null)
  if (!res.ok || !data || data.status === false) {
    const err = new Error(data?.error_message || `HTTP ${res.status}`)
    err.status = res.status
    // 本機服務連不到後端（502/504）也視為連線問題
    err.code = STATUS_CODES[res.status] || ([502, 504].includes(res.status) ? 'NETWORK' : 'API_ERROR')
    err.errorCode = data?.error_code
    err.traceId = data?.trace_id
    throw err
  }
  return data.data
}

/** 列表 API 的 data 實際為 { items, total, ... }（文件寫陣列），兩種都相容 */
const listOf = (data) => (Array.isArray(data) ? data : Array.isArray(data?.items) ? data.items : [])

// ── 有預約 ─────────────────────────────────────────

/**
 * 驗證預約：以速通碼（QR Code 內容號碼）查詢今天、已通知的預約單
 * @returns {{ id, reservationId, hostName, hostCompany, guestCount, visitors: Array<{ id, name, company, phone }>,
 *             visitorName, phone, company, hostDept }}
 *   visitorName / phone / company 取第一位來訪者；hostDept 為受訪公司（沿用畫面既有欄位）
 * @throws {Error} 查無預約時 err.code 為 NOT_FOUND
 */
async function verifyAppointmentImpl({ passCode }) {
  const d = await factoryRequest('GET', '/guest/applies', { query: { fast_pass_code: passCode } })
  const visitors = (d.visitor || []).map((v) => ({
    id: v.id,
    name: v.guest_name,
    company: v.guest_company_name || '',
    phone: v.guest_phone || '',
  }))
  const first = visitors[0] || {}
  return {
    id: d.id,
    reservationId: d.reservation_id,
    passCode,
    hostName: d.host_name || '',
    hostCompany: d.host_company_name || '',
    guestCount: d.guest_count,
    visitors,
    visitorName: first.name || '',
    phone: first.phone || '',
    company: first.company || '',
    hostDept: d.host_company_name || '',
  }
}

/** 人臉辨識（模擬） */
async function verifyFaceImpl() {
  await delay(1500)
  return { ok: true }
}

/**
 * 證件掃描（手動 / 重試）：由本機服務呼叫證件掃描器（server/idscanner）
 * 插入證件時的自動掃描結果則經 socket（idcard:result）送達，見 IdentityView
 * @returns {{ name: string, idNumber: string }} idNumber 為遮罩後的值（例 A123****89）
 */
async function scanIdCardImpl() {
  const res = await fetch('/api/idcard/scan', { method: 'POST' })
  const body = await res.json().catch(() => ({}))
  if (!res.ok || !body.ok) {
    const err = new Error(body.message || `證件掃描失敗（HTTP ${res.status}）`)
    err.code = body.code
    throw err
  }
  return body.card
}

/** 證件櫃畫面每列顯示幾格（後端未提供版面資訊） */
const LOCKER_COLUMNS = 6
/** 後端證件櫃狀態（dict guest_slot_status）：0 = 空閒、1 = 使用中 */
const SLOT_STATUS = { 0: 'available', 1: 'occupied' }

/**
 * 取得證件櫃列表與目前狀態
 * @returns {{ columns: number, slots: Array<{ id, label, status: 'available'|'occupied'|'disabled' }> }}
 */
async function getLockersImpl() {
  const data = await factoryRequest('GET', '/guest/slot', { query: { per_page: -1 } })
  const slots = listOf(data).map((s) => ({
    id: s.id,
    label: s.name,
    status: SLOT_STATUS[s.status] || 'disabled',
  }))
  return { columns: LOCKER_COLUMNS, slots }
}

/**
 * 選擇證件櫃：後端沒有預先鎖定的 API，實際占用檢查於報到（issueBadge → guest/done）時進行
 * @param {{ id, label }} slot
 */
async function assignLockerImpl(slot) {
  return { id: slot.id, label: slot.label || String(slot.id) }
}

/**
 * 完成報到並取得門禁卡號（guest/done）：寫入證件櫃與同意書，回傳門禁卡號（QR Code 內容）
 * @returns {{ cardNo: string|null, qrContent: string, badgeNo: string, guestCount: number }}
 *   cardNo：後端回傳的門禁卡號（access_card_no），未回傳時為 null
 *   qrContent：訪客證 QR Code 內容，為門禁卡號；未回傳時暫以預約單號代替
 * @throws {Error} 證件櫃已被占用、預約狀態不符時 err.code 為 NOT_FOUND
 */
async function issueBadgeImpl(visit) {
  const d = await factoryRequest('POST', '/guest/done', {
    body: { id: visit.reservation?.id, slot: visit.locker?.id, consent_form: visit.agreed ? 1 : 0 },
  })
  const cardNo = d.access_card_no ? String(d.access_card_no) : null
  if (!cardNo) {
    // 後端尚未寫入門禁卡號：QR Code 先以預約單號暫代，避免無法列印
    logWarning('API', `後端未回傳門禁卡號（access_card_no），QR Code 暫以預約單號 ${d.reservation_id} 列印`)
  }
  return { cardNo, qrContent: cardNo || d.reservation_id, badgeNo: d.reservation_id, guestCount: d.guest_count }
}

/**
 * 列印訪客證：由本機服務送到 POS 印表機（server/printer）
 * @param {{ qrContent: string, badgeNo: string, locker?: string, printedAt: string }} data
 *   locker：證件存放格位（例 A01），空值則不列印該行
 */
async function printBadgeImpl(data) {
  const res = await fetch('/api/print/badge', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data),
  })
  const body = await res.json().catch(() => ({}))
  if (!res.ok || !body.ok) throw new Error(body.message || `列印失敗（HTTP ${res.status}）`)
  return body
}

/** 通知被拜訪員工（後端尚無對應 API） */
async function notifyHostImpl() {
  return { ok: true }
}

// ── 無預約 ─────────────────────────────────────────

const OPTIONS_CACHE_MS = 5 * 60 * 1000
let optionsCache = null // { at, promise }

/**
 * 訪客機選項（data_setting/index）：依樓層分組的公司（門牌）清單與來訪事由字典
 * 後端回應：{ items: [{ floor: '2', companies: [{ id, company_name }] }], reason: { '1': '業務洽談', … } }
 * 快取一段時間，供選樓層 / 選公司 / 拜訪事由共用
 * @returns {Promise<{ floors: Array<{ floor: string, companies: Array<{ id, name }> }>, reasons: Array<{ id: number, label: string }> }>}
 */
function loadKioskOptions() {
  if (!optionsCache || Date.now() - optionsCache.at > OPTIONS_CACHE_MS) {
    const promise = factoryRequest('GET', '/data_setting/index').then((data) => ({
      floors: listOf(data).map((f) => ({
        floor: String(f.floor ?? ''),
        companies: (f.companies || [])
          .filter((c) => c.company_name)
          .map((c) => ({ id: c.id, name: c.company_name })),
      })),
      // reason 為 { 值: 顯示文字 }；沒有資料時後端回空陣列
      reasons: Object.entries(data?.reason || {})
        .map(([value, label]) => ({ id: Number(value), label: String(label) }))
        .filter((r) => Number.isInteger(r.id) && r.label),
    }))
    optionsCache = { at: Date.now(), promise }
    // 失敗時不快取，下次重新取得
    promise.catch(() => {
      if (optionsCache?.promise === promise) optionsCache = null
    })
  }
  return optionsCache.promise
}

/** 樓層顯示文字：數字顯示為 2F，負數顯示為 B1，其他（例如 B1、RF）原樣顯示 */
function floorLabel(floor) {
  const n = Number(floor)
  if (floor === '' || !Number.isInteger(n)) return floor
  return n < 0 ? `B${-n}` : `${n}F`
}

/** 取得可拜訪的樓層（只列出有公司的樓層，順序依後端） */
async function getFloorsImpl() {
  const { floors } = await loadKioskOptions()
  return floors.filter((f) => f.companies.length).map((f) => ({ id: f.floor, label: floorLabel(f.floor) }))
}

/**
 * 取得樓層內的公司
 * @returns {Array<{ id: number, name: string }>} id 為門牌 ID（無預約登記時作為 doorplate_id 送出）
 */
async function getCompaniesImpl(floorId) {
  const { floors } = await loadKioskOptions()
  return floors.find((f) => f.floor === String(floorId))?.companies || []
}

// 後端來訪事由字典沒有資料時的暫代清單（key 對應 i18n walkIn.purposes.*）
const FALLBACK_REASONS = [
  { id: 1, key: 'business' },
  { id: 2, key: 'meeting' },
  { id: 3, key: 'interview' },
  { id: 4, key: 'delivery' },
  { id: 5, key: 'maintenance' },
  { id: 6, key: 'other' },
]

/**
 * 取得拜訪事由（後端來訪事由字典 guest_visit_reason）
 * @returns {Array<{ id: number, label?: string, key?: string }>} id 為送給後端的 visit_reason
 */
async function getVisitReasonsImpl() {
  const { reasons } = await loadKioskOptions()
  if (reasons.length) return reasons
  logWarning('API', '後端來訪事由字典沒有資料，改用暫代清單')
  return FALLBACK_REASONS
}

/**
 * 無預約訪客送出登記（guest/create，建立一筆現場預約）
 * @param {{ company: { id }, visitor: { name, phone, email, company, purpose } }} data
 *   company.id 為門牌 ID；visitor.purpose 為拜訪事由 ID
 */
async function registerWalkInImpl({ company, visitor }) {
  await factoryRequest('POST', '/guest/create', {
    body: {
      doorplate_id: company.id,
      visit_reason: Number(visitor.purpose),
      visitor: [
        {
          name: visitor.name,
          company_name: visitor.company,
          phone: visitor.phone,
          email: visitor.email,
        },
      ],
    },
  })
  // 後端不回傳資料（單號、速通碼由系統產生並通知）
  return { id: null, status: 'pending' }
}

// ── 離場 ───────────────────────────────────────────

/**
 * 訪客離場（guest/leave）：以門禁卡號比對已報到的預約單，清除門禁卡號、速通碼與證件櫃
 * 後端不回傳資料，訪客姓名與證件櫃無法顯示
 * @param {{ code: string }} data  掃描到的門禁卡號
 * @returns {{ visitorName: null, company: null, checkOutAt: string, locker: null }}
 * @throws {Error} 查無已報到的預約單時 err.code 為 NOT_FOUND
 */
async function checkOutImpl({ code }) {
  // 門禁卡號為數字（後端以字串接收，最長 20 字元）：非數字內容（例如其他 QR Code）不可能是門禁卡號
  const cardNo = String(code || '').trim()
  if (!/^\d{1,20}$/.test(cardNo)) {
    const err = new Error('Not an access card number')
    err.code = 'NOT_FOUND'
    throw err
  }
  await factoryRequest('POST', '/guest/leave', { body: { card_no: cardNo } })
  return { visitorName: null, company: null, checkOutAt: new Date().toISOString(), locker: null }
}

// ── 對外提供（含 debug 記錄） ─────────────────────────

export const verifyAppointment = withDebugLog('verifyAppointment', verifyAppointmentImpl)
export const verifyFace = withDebugLog('verifyFace', verifyFaceImpl)
export const scanIdCard = withDebugLog('scanIdCard', scanIdCardImpl)
export const getLockers = withDebugLog('getLockers', getLockersImpl)
export const assignLocker = withDebugLog('assignLocker', assignLockerImpl)
export const issueBadge = withDebugLog('issueBadge', issueBadgeImpl)
export const printBadge = withDebugLog('printBadge', printBadgeImpl)
export const notifyHost = withDebugLog('notifyHost', notifyHostImpl)
export const getFloors = withDebugLog('getFloors', getFloorsImpl)
export const getCompanies = withDebugLog('getCompanies', getCompaniesImpl)
export const getVisitReasons = withDebugLog('getVisitReasons', getVisitReasonsImpl)
export const registerWalkIn = withDebugLog('registerWalkIn', registerWalkInImpl)
export const checkOut = withDebugLog('checkOut', checkOutImpl)
