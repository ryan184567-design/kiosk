// 證件辨識結果（WebFXScan recognizedata）轉換為訪客資料 { name, idNumber（遮罩） }
//
// SDK 文件未列出 twid（台灣身分證）辨識結果的欄位名稱，此處以寬鬆規則比對：
//   姓名：欄位名稱含 name / 姓名（優先取含中文的值）
//   證號：欄位名稱含 idno / idnumber / 身分證，或任何符合台灣身分證格式（A123456789）的值
// 現場接上掃描器後可用 npm run idcard:test 查看實際欄位，必要時調整下方候選規則。
// ※ 完整證號只在此函式內使用，回傳與記錄皆為遮罩後的值。

const TW_ID_RE = /^[A-Z][12A-D89]\d{8}$/ // 含新式居留證（第二碼 A-D / 8 / 9）
const NAME_KEY_RE = /name$|姓名/i
// 排除：英文名、檔名 / 裝置名，以及身分證背面的父母 / 配偶姓名、出生地等
const NAME_EXCLUDE_RE =
  /eng|english|en_?name|first|last|sur|given|file|device|image|father|mother|spouse|mate|parent|company|place|父|母|配偶/i
const ID_KEY_RE =
  /^(id|identity_?(no|number)|personal_?id|document_?(no|number)|doc_?no)$|id_?(no|num(ber)?)$|id_?card(_?no)?$|身分證|統一證號/i
const HAS_CJK = /[㐀-鿿]/

/** 將巢狀物件攤平成 [鍵路徑, 字串值] */
function flatten(value, prefix = '', out = []) {
  if (value === null || value === undefined) return out
  if (typeof value === 'string' || typeof value === 'number') {
    out.push([prefix, String(value).trim()])
  } else if (Array.isArray(value)) {
    value.forEach((v, i) => flatten(v, `${prefix}[${i}]`, out))
  } else if (typeof value === 'object') {
    for (const [k, v] of Object.entries(value)) flatten(v, prefix ? `${prefix}.${k}` : k, out)
  }
  return out
}

const lastKey = (path) => path.split(/[.[\]]/).filter(Boolean).pop() || ''

/** 身分證號遮罩：A123456789 → A123****89（與既有格式一致） */
export function maskId(id) {
  const s = String(id || '').trim()
  if (s.length <= 6) return s.replace(/./g, '*')
  return `${s.slice(0, 4)}${'*'.repeat(s.length - 6)}${s.slice(-2)}`
}

/** 辨識結果的欄位名稱（記錄用，不含值） */
export function describeKeys(recognizedata) {
  return flatten(typeof recognizedata === 'string' ? tryJson(recognizedata) : recognizedata).map(([k]) => k)
}

function tryJson(s) {
  try {
    return JSON.parse(s)
  } catch {
    return s
  }
}

/**
 * @param {unknown} recognizedata WebFXScan 回傳的辨識資料（物件或 JSON 字串；多頁時為陣列）
 * @returns {{ name: string, idNumber: string }} idNumber 已遮罩
 * @throws {Error & { code: 'NO_DATA' }}
 */
export function normalizeIdCard(recognizedata) {
  const data = typeof recognizedata === 'string' ? tryJson(recognizedata) : recognizedata
  const entries = flatten(data).filter(([, v]) => v !== '')

  // 姓名：欄位名稱符合，排除英文名欄位；優先取含中文的值
  const nameCandidates = entries.filter(([k]) => NAME_KEY_RE.test(lastKey(k)) && !NAME_EXCLUDE_RE.test(lastKey(k)))
  const name =
    (nameCandidates.find(([, v]) => HAS_CJK.test(v)) || nameCandidates[0] || [])[1]?.replace(/\s+/g, '') || ''

  // 證號：欄位名稱符合且格式正確 → 任一符合格式的值 → 欄位名稱符合的值
  const norm = (v) => v.replace(/[\s-]/g, '').toUpperCase()
  const idByKey = entries.filter(([k]) => ID_KEY_RE.test(lastKey(k))).map(([, v]) => norm(v))
  const idNumber =
    idByKey.find((v) => TW_ID_RE.test(v)) ||
    entries.map(([, v]) => norm(v)).find((v) => TW_ID_RE.test(v)) ||
    idByKey.find((v) => /^[A-Z0-9]{6,20}$/.test(v)) ||
    ''

  if (!name && !idNumber) {
    const err = new Error('無法辨識證件資料')
    err.code = 'NO_DATA'
    throw err
  }
  return { name, idNumber: idNumber ? maskId(idNumber) : '' }
}
