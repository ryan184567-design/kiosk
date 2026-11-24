// 本次訪客報到流程的狀態（單一訪客機同時只服務一位訪客，使用簡單的 reactive 即可）
import { reactive } from 'vue'
import { debug } from '@/utils/logger'
import { appConfig } from '@/config'

const initialState = () => ({
  mode: null, // 'appointment' 有預約 | 'walkIn' 無預約 | 'checkOut' 離場

  // ── 有預約 ──
  passCode: '', // 速通碼（即 QR Code 內容號碼）
  passCodeSource: null, // 速通碼來源 'scan' 掃描 QR Code | 'keypad' 數字鍵盤輸入
  reservation: null, // 驗證後取得的預約資料
  visitor: null, // 訪客資料 { name, phone, company, hostName, hostDept（受訪公司）, guestCount }
  identity: {
    face: false, // 人臉辨識是否通過
    idCard: null, // 證件資料 { name, idNumber, manual }
  },
  locker: null, // 證件存放格位 { id, label }（id 為後端證件櫃 ID，label 為證件櫃名稱）
  agreed: false, // 是否同意條款
  badge: null, // 列印完成的訪客證 { badgeNo }

  // ── 無預約 ──
  walkIn: {
    floor: null, // 選擇的樓層 { id, label }
    company: null, // 選擇的受訪公司 { id, name }
    form: null, // 填寫的訪客資料 { name, phone, email, company, purpose（拜訪事由 ID）, purposeKey, purposeLabel }
  },
  registration: null, // 送出登記後的結果 { id }

  // ── 離場 ──
  checkOut: {
    code: '', // 掃描到的內容（門禁卡號或速通碼 QR Code），由後端判斷類型
    result: null, // 離場成功的結果 { visitorName, company, checkOutAt, locker }
  },
})

export const visit = reactive(initialState())

/** 速通碼長度（.env VITE_PASS_CODE_LENGTH，預設 10 碼，見 src/config.js） */
export const PASS_CODE_LENGTH = appConfig.passCodeLength

/** 速通碼格式：固定長度的數字 */
export function isValidPassCode(code) {
  return new RegExp(`^\\d{${PASS_CODE_LENGTH}}$`).test(code || '')
}

/**
 * 速通碼分組顯示（以空白分隔，方便核對），依速通碼長度決定每組碼數：
 *   12 碼以內分成前後兩組（例：10 碼 12345 67890、8 碼 1234 5678、9 碼 12345 6789）
 *   超過 12 碼則每 4 碼一組
 * @param {string} code    要顯示的速通碼（輸入中可未滿長度）
 * @param {number} length  速通碼總長度，預設為設定值；輸入中時依總長度分組，分隔位置才不會跳動
 */
export function formatPassCode(code, length = PASS_CODE_LENGTH) {
  const s = String(code || '')
  const size = length <= 12 ? Math.ceil(length / 2) : 4
  if (size <= 0) return s
  return s.match(new RegExp(`.{1,${size}}`, 'g'))?.join(' ') || ''
}

export function resetVisit() {
  // 只在有進行中的報到時記錄，並只保留重點資料
  if (visit.mode) {
    debug('Flow', '清除本次訪客資料', {
      mode: visit.mode,
      visitor: visit.visitor?.name || visit.walkIn.form?.name || null,
      locker: visit.locker?.label || null,
      badgeNo: visit.badge?.badgeNo || null,
      registrationId: visit.registration?.id || null,
    })
  }
  Object.assign(visit, initialState())
}

// 有預約各步驟依序產生的資料；回到某一步時，該步與之後步驟的資料都要清除重新輸入
const APPOINTMENT_STEPS = {
  passCode: () => {
    visit.passCode = ''
    visit.passCodeSource = null
    visit.reservation = null
    visit.visitor = null
  },
  identity: () => {
    visit.identity = { face: false, idCard: null }
  },
  locker: () => {
    visit.locker = null
  },
  agreement: () => {
    visit.agreed = false
  },
  badge: () => {
    visit.badge = null
  },
}

/**
 * 進入（或返回）有預約的某一步時呼叫：清除該步與之後步驟的資料，讓訪客重新輸入 / 掃描
 * @param {'passCode'|'identity'|'locker'|'agreement'} step
 */
export function clearStepsFrom(step) {
  const names = Object.keys(APPOINTMENT_STEPS)
  const cleared = names.slice(names.indexOf(step))
  cleared.forEach((name) => APPOINTMENT_STEPS[name]())
  debug('Flow', `清除步驟資料：${cleared.join(', ')}`)
}

/** 身份驗證：人臉辨識或證件掃描（含手動輸入）擇一完成即可 */
export function isIdentityComplete() {
  return visit.identity.face || !!visit.identity.idCard
}

/**
 * 是否需要選擇證件存放格位：身份驗證完成後一律需要
 * （後端報到 API guest/done 必須帶證件櫃，人臉辨識通過的訪客也要選擇）
 */
export function needsLocker() {
  return isIdentityComplete()
}

/** 身份驗證與證件存放皆已完成 */
export function isLockerComplete() {
  return isIdentityComplete() && !!visit.locker
}
