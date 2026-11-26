/* *****************************************
File: guestAdapter.js
Author: Callum
Date: 2026-11-19
Description: 訪客預約列表的轉接層：把後端回傳的欄位轉成頁面使用的格式，並組出列表查詢參數。
***************************************** */
// 純函式、不依賴瀏覽器或 API，所以可以單獨測試。
// 後端欄位來源：GuestApplyController::presentListItem（GET /api/guest/applies）。
// 後端列表每筆 → 頁面欄位。
// - host_company_name／host_floor → company_name／floor（頁面沿用假資料時的名稱）
// - 列表的來訪者明細叫 visitor，且欄位是 guest_name／guest_company_name／guest_phone
//   （詳情 API 的 visitor 則是 name／company_name／phone，兩邊不一樣），這裡統一成
//   guests: [{ name, company_name, phone, title, email, plate_number }]
// - 後端列表現在有 created_at（申請時間），但格式是 UTC 的 ISO 字串，這裡轉成本地時間（見 normalizeDateTime）。
// - 詳情 API（GET /api/guest/applies/{id}）實際回傳的來訪者欄位是 guest_name、guest_company_name、
//   guest_job_title、guest_phone、guest_email、guest_plate_number（與 Swagger 文件寫的 name／
//   company_name／phone 不同，2026-11-20 實測看程式碼），所以兩種命名這裡都接。
//   詳情有 doorplate_id（後端 2026-11-25 `2e9d019` 起），編輯預約時直接用它選回受訪公司（見 ReservationDialog）。
export function adaptListItem(row){
  return {
    ...row,
    company_name: row.host_company_name ?? null,
    floor: row.host_floor ?? null,
    created_at: normalizeDateTime(row.created_at),
    guests: adaptGuests(row.visitor),
  };
}
// 後端列表的 created_at 是直接輸出的 Carbon（'2026-11-24T02:05:54.000000Z'，UTC），詳情才是 'YYYY-MM-DD HH:mm:ss'（台北時間）。
// 頁面用字串切片顯示時間，不轉換的話會少 8 小時（甚至差一天），所以 ISO 格式的一律轉成本地時間的 'YYYY-MM-DD HH:mm:ss'；
// 已經是 'YYYY-MM-DD HH:mm:ss' 的（詳情、或後端日後改掉）原樣不動。
export function normalizeDateTime(value){
  if(typeof value !== 'string' || !/T.*(Z|[+-]\d\d:?\d\d)$/.test(value)){
    return value;
  }
  const date = new Date(value);
  if(Number.isNaN(date.getTime())){
    return value;
  }
  const pad = (n) => String(n).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}
// 來訪者明細：列表與詳情的欄位命名不同，兩種都接，統一成頁面用的欄位。
function adaptGuests(visitors){
  return (visitors || []).map((v) => ({
    id: v.id,
    name: v.guest_name ?? v.name ?? '',
    company_name: v.guest_company_name ?? v.company_name ?? '',
    phone: v.guest_phone ?? v.phone ?? '',
    title: v.guest_job_title ?? v.title ?? null,
    email: v.guest_email ?? v.email ?? null,
    plate_number: v.guest_plate_number ?? v.plate_number ?? null,
  }));
}
// status 可能是單一值、陣列、空字串或 undefined，統一整理成數字陣列。
export function normalizeStatuses(status){
  return [].concat(status ?? [])
    .filter((s) => s !== '' && s !== null && s !== undefined)
    .map(Number);
}
// 組出 GET /api/guest/applies 的查詢字串。
// 重要：後端 Controller 直接讀 $data['keyword'] 等欄位，**選填參數沒帶就會 500**
// （Undefined array key），所以六個參數一律都帶，沒有值就帶空字串（後端會轉成 null）。
// 後端的 reservation_status 只收單一值，多個狀態要由呼叫端拆成多次查詢。
export function buildListQuery(params = {}, status = '') {
  return new URLSearchParams({
    keyword: params.keyword ?? '',
    date_from: params.date_from ?? '',
    date_to: params.date_to ?? '',
    reservation_status: status ?? '',
    page: params.page ?? 1,
    per_page: params.per_page ?? -1,
  }).toString();
}
// 多個狀態分次查詢（各自不分頁）後合併：依預約日期＋開始時間排序，再自己分頁。
// 後端一次只能查一個狀態，審核頁的「待審核」（0、1）與訪客記錄（40、50）要靠這個。
// sort_by：'visit'（預設，預約日期＋開始時間）、'created_at'（申請時間，審核頁用）或 'reservation_id'（預約單號，預約列表用）；
// sort_order：'asc'／'desc'（預設）。
// created_at 已經由 adaptListItem 轉成本地時間的 'YYYY-MM-DD HH:mm:ss'、預約單號是「GA＋日期＋流水號」，都可以直接用字串比較；
// 相同的再依 id 排，順序才穩定。
const SORT_KEYS = {
  created_at: (r) => r.created_at || '',
  reservation_id: (r) => r.reservation_id || '',
};
export function mergeStatusResults(itemLists, params = {}){
  const key = SORT_KEYS[params.sort_by] || ((r) => `${r.visit_date} ${r.visit_start_time}`);
  const compare = (a, b) => key(a).localeCompare(key(b)) || (a.id ?? 0) - (b.id ?? 0);
  const items = itemLists.flat().sort((a, b) => (params.sort_order === 'asc' ? compare(a, b) : compare(b, a)));
  const perPage = Number(params.per_page ?? -1);
  const page = Number(params.page ?? 1);
  return{
    items: perPage > 0 ? items.slice((page - 1) * perPage, page * perPage) : items,
    total: items.length,
    per_page: perPage,
    current_page: page,
  };
}
// ─────────────────────────────────────────────────────────────
// 訪客記錄（GET /api/guest/records、/records/stats、/records/{id}，2026-11-23 後端完成）
// 來源：GuestRecordController。與預約列表是不同的 API，回傳欄位也不同：
// - 沒有 visit_date／visit_start_time，只有 visit_at（'YYYY-MM-DD HH:mm'），這裡拆回兩個欄位。
// - 詳情另外有 created_at（登記時間）、remark、access_card_no、health_declaration（是否同意健康聲明）。
// ─────────────────────────────────────────────────────────────
// 記錄列表每筆 → 頁面欄位（沿用預約列表的 company_name、floor、guests 命名）。
export function adaptRecordItem(row){
  const visitAt = row.visit_at ?? '';
  return{
    ...row,
    visit_date: visitAt.slice(0, 10),
    visit_start_time: visitAt.slice(11, 16),
    company_name: row.host_company_name ?? null,
    floor: row.host_floor ?? null,
    guests: adaptGuests(row.visitor),
  };
}
// 記錄詳情：多了健康聲明（頁面欄位叫 health_declared）。
export function adaptRecordDetail(row){
  return{ ...adaptRecordItem(row), health_declared: Boolean(row.health_declaration) };
}
// 統計卡片：後端回 { today: { count, previous_count, growth_rate }, week, month, completed: { count } }，
// 轉成頁面用的 { today, week, month, completed, today_trend, week_trend, month_trend }。
// growth_rate 上一期為 0 時是 null（不顯示增減），其餘四捨五入到小數 1 位。
export function adaptRecordStats(data){
  const rate = (value) => (value === null || value === undefined ? null : Math.round(Number(value) * 10) / 10);
  return{
    today: data?.today?.count ?? 0,
    week: data?.week?.count ?? 0,
    month: data?.month?.count ?? 0,
    completed: data?.completed?.count ?? 0,
    today_trend: rate(data?.today?.growth_rate),
    week_trend: rate(data?.week?.growth_rate),
    month_trend: rate(data?.month?.growth_rate),
  };
}
// 組出 GET /api/guest/records 的查詢字串：後端的選填參數可以省略，所以空值直接略過。
// status 只收單一值（40 或 50）；export：1 Excel、2 PDF（不帶＝JSON 列表）。
// ── 審核 API（GET /api/guest/approvals）的日期參數 ──
// 後端（Michael，`c49086b`）：date_from／date_to＝申請日期（created_at）；apply_date_from／apply_date_to＝來訪日期（visit_date，名字像申請日期，其實是來訪日期）。
// 後端 bug（`GuestApprovalService::list` 第 41、44 行）：來訪日期條件用 `!empty($date_from)`／`!empty($date_to)` 判斷，卻代入 apply_date_*。
// 結果是同一邊（起或迄）的兩個參數只送一個就壞：只送 date_* → 500；只送 apply_date_* → 來訪日期條件被略過。
// 繞法：同一邊的兩個參數一定成對送，缺的那個補上涵蓋所有日期的邊界（對結果沒有影響）。
// 等後端把判斷改成 `!empty($apply_date_*)` 之後，這個函式可以改成直接對應、不用補邊界。
const WIDE_FROM = '2000-01-01';
const WIDE_TO = '2999-12-31';
// 傳入：visit_from／visit_to（來訪日期）、created_from／created_to（申請日期），都可以不給。回傳可以直接丟給 buildRecordQuery 的日期欄位。
export function buildApprovalDateParams({ visit_from, visit_to, created_from, created_to } = {}){
  const side = (visit, created, wide) => (visit || created
    ? { apply: visit || wide, date: created || wide }
    : { apply: undefined, date: undefined });
  const from = side(visit_from, created_from, WIDE_FROM);
  const to = side(visit_to, created_to, WIDE_TO);
  return{ date_from: from.date, date_to: to.date, apply_date_from: from.apply, apply_date_to: to.apply };
}
export function buildRecordQuery(params = {}){
  const search = new URLSearchParams();
  const fields = {
    export: params.export,
    date_from: params.date_from,
    date_to: params.date_to,
    apply_date_from: params.apply_date_from,
    apply_date_to: params.apply_date_to,
    guest_name: params.guest_name,
    company_name: params.company_name,
    visit_reason: params.visit_reason,
    reservation_status: params.status,
    keyword: params.keyword,
    page: params.page,
    per_page: params.per_page,
  };
  for (const [key, value] of Object.entries(fields)) {
    if (value === undefined || value === null || value === '') continue;
    search.append(key, value);
  }
  return search.toString();
}
// ── 統計報表（GET /api/guest/reports、/reports/details）轉成統計報表頁用的格式 ──

// 總覽：後端 cards 的 count／growth_rate 攤平成 summary 與 *_trend（上一期為 0 時成長率是 null，維持 null）。
export function adaptReportOverview(data = {}) {
  const cards = data.cards || {};
  const count = (card) => card?.count ?? 0;
  const rate = (card) => card?.growth_rate ?? null;

  return {
    summary: {
      total: count(cards.total),
      total_trend: rate(cards.total),
      reserved: count(cards.reserved),
      reserved_trend: rate(cards.reserved),
      walk_in: count(cards.onsite),
      walk_in_trend: rate(cards.onsite),
      companies: count(cards.companies),
      companies_trend: rate(cards.companies),
      badges: count(cards.cards),
      badges_trend: rate(cards.cards),
    },
    trend: (data.trend || []).map((t) => ({ date: t.date, total: t.count })),
    reasons: (data.reasons || []).map((r) => ({ reason: r.label, value: r.visit_reason, count: r.count })),
    floors: (data.floors || []).map((f) => ({ floor: f.floor, floor_label: f.floor_label, count: f.count })),
  };
}

// 明細的一天。後端沒有「備註」欄位，頁面的備註欄一律顯示「—」。
export function adaptReportDay(row) {
  return {
    date: row.date,
    weekday: row.weekday,
    total: row.total,
    reserved: row.reserved,
    walk_in: row.onsite,
    companies: row.companies,
    top_reason: row.top_visit_reason_label || '—',
    remark: '',
  };
}
