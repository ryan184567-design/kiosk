// 訪客機 API（目前為模擬資料，之後串接後端時替換各函式內容即可）
//
// 模擬規則：
//   - 速通碼結尾為 0000 → 查無預約
//   - 其他速通碼 → 驗證成功
//   - 離場的模擬規則見 checkOutImpl
//
// 所有 API 皆經 withDebugLog 包裝，debug 模式下會記錄參數、結果與耗時（見檔案最下方 export）

import { withDebugLog } from '@/utils/logger'

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

// ── 有預約 ─────────────────────────────────────────

/** 驗證預約：以速通碼（QR Code 內容號碼）查詢 */
async function verifyAppointmentImpl({ passCode }) {
  await delay(1500)
  if (!passCode || passCode.endsWith('0000')) {
    const err = new Error('Appointment not found')
    err.code = 'NOT_FOUND'
    throw err
  }
  return {
    id: `R${Date.now()}`,
    passCode,
    visitorName: '王小明',
    phone: '0912345678',
    company: '範例股份有限公司',
    hostName: '陳經理',
    hostDept: '業務部',
    purpose: 'meeting',
    visitTime: new Date().toISOString(),
  }
}

/** 人臉辨識 */
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

/**
 * 取得證件存放格位設定（由後端提供）
 * @returns {{ columns: number, slots: Array<{ id, label, status: 'available'|'occupied'|'disabled' }> }}
 *   columns：畫面每列顯示幾格；slots：依顯示順序排列的格位
 */
async function getLockersImpl() {
  await delay(400)
  const columns = 6
  const rows = 4
  const occupied = new Set([1, 4, 7, 8, 13, 17, 22])
  const disabled = new Set([11, 20])
  const slots = Array.from({ length: columns * rows }, (_, i) => {
    const label = `${String.fromCharCode(65 + Math.floor(i / columns))}${String((i % columns) + 1).padStart(2, '0')}`
    return {
      id: label,
      label,
      status: disabled.has(i) ? 'disabled' : occupied.has(i) ? 'occupied' : 'available',
    }
  })
  return { columns, slots }
}

/** 指派證件存放格位（後端可於此開啟格門；格位已被使用時應回傳錯誤） */
async function assignLockerImpl(slotId) {
  await delay(800)
  return { id: slotId, label: slotId }
}

/**
 * 發行訪客證：送出訪客資料給後端，取回卡號（QR Code 內容）與訪客證號
 * @returns {{ cardNo: string, badgeNo: string }}
 */
async function issueBadgeImpl(/* visit */) {
  await delay(800)
  return {
    cardNo: String(Math.floor(100000000 + Math.random() * 900000000)), // 例：123456789
    badgeNo: `V${String(Date.now()).slice(-6)}`,
  }
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

/** 通知被拜訪員工 */
async function notifyHostImpl() {
  await delay(800)
  return { ok: true }
}

// ── 無預約 ─────────────────────────────────────────

const FLOORS = ['B1', ...Array.from({ length: 15 }, (_, i) => `${i + 1}F`)]

const MOCK_COMPANIES = {
  '5F': [
    '新保科技股份有限公司',
    '宏達國際有限公司',
    '全球數位科技股份有限公司',
    '鑫鴻設計顧問有限公司',
    '博安物業管理有限公司',
  ],
}

/** 取得可拜訪的樓層 */
async function getFloorsImpl() {
  await delay(200)
  return FLOORS.map((f) => ({ id: f, label: f }))
}

/** 取得樓層內的公司 */
async function getCompaniesImpl(floorId) {
  await delay(300)
  const names = MOCK_COMPANIES[floorId] || [
    `範例企業 ${floorId} 股份有限公司`,
    `示範科技 ${floorId} 有限公司`,
    `測試顧問 ${floorId} 有限公司`,
  ]
  return names.map((name, i) => ({ id: `${floorId}-${i + 1}`, name }))
}

/**
 * 無預約訪客送出登記（後端應寄送通知 Email 給受訪公司，待其同意）
 * @param {{ floor, company, visitor: { name, phone, email, company, purpose } }} data
 */
async function registerWalkInImpl(data) {
  await delay(1000)
  return { id: `W${Date.now()}`, status: 'pending', ...data }
}

// ── 離場 ───────────────────────────────────────────

/**
 * 訪客離場：送出掃描到的門禁卡號（訪客證）或速通碼（預約 QR Code），由後端判斷類型並完成離場
 * 後端應記錄離場時間（check_out_at）、將預約單狀態改為已離場，並回傳證件存放格位以提醒訪客取回證件
 *
 * 模擬規則：
 *   - 結尾為 0000 → 查無訪客紀錄（NOT_FOUND）
 *   - 結尾為 1111 → 已完成離場（ALREADY_CHECKED_OUT）
 *   - 結尾為 2222 → 尚未報到（NOT_CHECKED_IN）
 *   - 其他 → 離場成功
 *
 * @param {{ code: string }} data
 * @returns {{ visitorName: string, company: string, checkOutAt: string, locker: { id, label } | null }}
 * @throws {Error} err.code 為 NOT_FOUND | ALREADY_CHECKED_OUT | NOT_CHECKED_IN
 */
async function checkOutImpl({ code }) {
  await delay(1200)
  const fail = (errCode, msg) => {
    const err = new Error(msg)
    err.code = errCode
    throw err
  }
  if (!code || code.endsWith('0000')) fail('NOT_FOUND', 'Visitor record not found')
  if (code.endsWith('1111')) fail('ALREADY_CHECKED_OUT', 'Visitor already checked out')
  if (code.endsWith('2222')) fail('NOT_CHECKED_IN', 'Visitor has not checked in')
  return {
    visitorName: '王小明',
    company: '範例股份有限公司',
    checkOutAt: new Date().toISOString(),
    locker: { id: 'A03', label: 'A03' },
  }
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
export const registerWalkIn = withDebugLog('registerWalkIn', registerWalkInImpl)
export const checkOut = withDebugLog('checkOut', checkOutImpl)
