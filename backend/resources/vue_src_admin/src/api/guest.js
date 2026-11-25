/* *****************************************
File: guest.js
Author: Callum
Date: 2026-11-19
Description: 訪客管理模塊的 API 呼叫封裝（預約、審核、門牌選項），頁面不直接寫 fetch。
***************************************** */
import { authFetch } from '../stores/auth';
import { adaptListItem, adaptRecordDetail, adaptReportDay, adaptReportOverview, adaptRecordItem, adaptRecordStats, buildApprovalDateParams, buildListQuery, buildRecordQuery, mergeStatusResults, normalizeStatuses } from './guestAdapter';
import { registerMock } from './guestMock';
import { PENDING_STATUSES, REJECTED_STATUS } from '../pages/guest/constants';
import { todayString, weekRange } from '../pages/guest/format';
// 統一把後端回應整理成 { ok, data, message }，頁面只要看 ok 與 message：
// 後端格式為 { status, error_code, data, error_message }，驗證失敗（422）
// 也是同一種格式，error_message 會帶第一個欄位的中文錯誤，見 bootstrap/app.php。
// 失敗訊息要依實際原因分開說，才不會讓人往錯的方向查：
// - 連請求都送不出去（fetch 丟例外）→「無法連線到伺服器」，由呼叫端處理。
// - 後端有給 error_message（驗證失敗、業務錯誤）→ 直接用它。
// - 回了 JSON 但沒有 error_message、狀態碼 5xx → Laravel 處理時出錯（伺服器連得到，是後端程式的錯誤）。
// - 回應不是 JSON 且不成功 → 通常是後端沒在跑（開發時 Vite 代理回 500、內容是空的）或正式環境的閘道錯誤（502／504 網頁）。
function failureMessage(res, body, action = '操作'){
  if(body?.error_message)return body.error_message;
  if(body && res.status >= 500)return `伺服器處理時發生錯誤（HTTP ${res.status}），請稍後再試；持續發生請聯絡系統管理員`;
  if(!body && !res.ok)return `伺服器沒有正常回應（HTTP ${res.status}），請稍後再試`;
  return `${action}失敗，請稍後再試`;
}
async function request(path, options = {}){
  let res;
  try{
    res = await authFetch(path, options);
  }catch{
    return{ ok: false, data: null, message: '無法連線到伺服器，請稍後再試' };
  }
  const body = await res.json().catch(() => null);
  if(body?.status){
    return{ ok: true, data: body.data, message: '' };
  }
  return{ ok: false, data: null, message: failureMessage(res, body) };
}
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };
function postJson(path, payload){
  return request(path, { method: 'POST', headers: JSON_HEADERS, body: JSON.stringify(payload) });
}
function putJson(path, payload = {}){
  return request(path, { method: 'PUT', headers: JSON_HEADERS, body: JSON.stringify(payload) });
}
// 把查詢參數轉成網址：略過空值，陣列用 key[]=a&key[]=b 的寫法。
function toQuery(params){
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;
    if (Array.isArray(value)) {
      value.forEach((v) => search.append(`${key}[]`, v));
    } else {
      search.append(key, value);
    }
  }
  return search.toString();
}
// ─────────────────────────────────────────────────────────────
// 已完成的後端 API
// ─────────────────────────────────────────────────────────────
// GET /api/data-setting/doorplate：受訪公司下拉用(門牌資料，per_page=-1 不分頁)。
export function fetchDoorplates(){
  return request('/api/data-setting/doorplate?per_page=-1', { headers: { Accept: 'application/json' } });
}
// POST /api/guest/applies：新增訪客預約，成功回傳 { reservation_id }（2026-11-24 起後端不再回傳 qr_code、fast_pass_code，要看速通碼請抓詳情）。
export function createReservation(payload){
  return postJson('/api/guest/applies', payload);
}
// GET /api/guest/applies/{id}：單筆預約詳情（含來訪者、備註、通知設定）。轉成與列表相同的頁面欄位。
export async function fetchReservationDetail(id){
  const result = await request(`/api/guest/applies/${id}`, { headers: { Accept: 'application/json' } });
  if(!result.ok){
    return result;
  }
  return{ ...result, data: adaptListItem(result.data) };
}
// PUT /api/guest/applies/{id}：修改預約（欄位同新增；來訪者整批覆寫；修改後狀態會變成「修改待審核」）。
export function updateReservation(id, payload){
  return putJson(`/api/guest/applies/${id}`, payload);
}
// DELETE /api/guest/applies/{id}：刪除預約單（軟刪除，成功無資料）。後端不限制狀態，已報到的也能刪，
// 所以「哪些狀態可以刪」由頁面把關（見 ReservationCalendar 的 canDelete）。
export function deleteReservation(id){
  return request(`/api/guest/applies/${id}`, { method: 'DELETE', headers: { Accept: 'application/json' } });
}
// GET /api/dictionaries/guest_visit_reason/items：來訪事由字典（預約單的 visit_reason 存項目的 value）。
// 回傳陣列 [{ id, value, label, is_enabled, sort }]；轉換與快取見 pages/guest/useVisitReasons.js。
export function fetchVisitReasonItems(){
  return request('/api/dictionaries/guest_visit_reason/items', { headers: { Accept: 'application/json' } });
}
// 待審核（狀態 0 待審核、1 修改待審核）的總筆數，給預約管理「待審核」頁籤、右上角鈴鐺與側邊欄「訪客審核」的紅色數字用。
// 用列表 API 各查一次（每頁只要 1 筆，看 total），依序查不併發。後端的 GET /api/guest/approvals/data 也有這個數字，但權限碼不同（api.guest.approvalStatus），能看列表的人不一定有它的權限，所以不改用它。
export async function fetchPendingCount(){
  let count = 0;
  for(const status of [0, 1]){
    const result = await request(`/api/guest/applies?${buildListQuery({ page: 1, per_page: 1 }, status)}`, { headers: { Accept: 'application/json' } });
    if(!result.ok)return result;
    count += result.data.total ?? 0;
  }
  return{ ok: true, data: count, message: '' };
}
// ─────────────────────────────────────────────────────────────
// 後端尚未完成的 API：目前用假資料(見 guestMock.js)，後端好了把對應旗標改成 false
// 就切到真 API，全部做完後把 guestMock.js 整支刪掉。
// 頁面只認這裡回傳的欄位格式(照後端資料表命名)，所以切換時頁面不用改，
// 只可能需要微調這支檔案。下面「真 API」那幾行的網址與參數名稱都是暫定，待後端確認。
// ─────────────────────────────────────────────────────────────
const USE_MOCK = {
  // 現場訪客登記：後端沒有對應 API(資料表有 reservation_type=1 現場預約，
  // 但尚未確認登記要走哪支 API、是否需要受訪公司／受訪人)。
  registration: true,
};
// 頁面用來判斷「這支 API 現在是不是假資料」，決定要不要顯示示意資料提示；
// 旗標改成 false 後提示自動消失，不用記得手動拿掉。
export function isMock(name){
  return Boolean(USE_MOCK[name]);
}
// 真 API：GET /api/guest/applies。後端一次只能查一個狀態，且選填參數沒帶就會 500，
// 這些細節都收在 guestAdapter.js；這裡只負責呼叫，並把回傳欄位轉成頁面使用的格式。
// 多個狀態時（例如待審核 0、1）依序分次查詢（不要併發：README 提到本機併發請求偶發 500）再合併。
// 「已拒絕（70）」後端 2026-11-25 `2e9d019` 起可以直接查，和其他狀態一樣。
// 後端列表只能依預約日期由新到舊排序；有指定 sort_by（'created_at' 申請時間、'reservation_id' 預約單號、'visit' 預約日期）時，
// 一律抓全部回來由前端排序、分頁（見 guestAdapter.mergeStatusResults）。後端有排序參數後改成直接送參數。
// 申請日期（params.created_from／created_to）：預約列表 API 沒有這個參數，只有審核 API 有（見 approvalQuery）。
// 有填申請日期時，先向審核 API 查出符合所有條件的預約單 id，再從預約列表的資料裡只留下這些（所以一定是抓全部再由前端分頁）。
async function fetchReservationsReal(params = {}){
  const statuses = normalizeStatuses(params.status);
  const options = { headers: { Accept: 'application/json' } };
  const filterByCreated = Boolean(params.created_from || params.created_to);
  let keepMatching = (items) => items;
  if(filterByCreated){
    const ids = await fetchApprovalIds(params);
    if(!ids.ok)return ids;
    keepMatching = (items) => items.filter((item) => ids.data.has(item.id));
  }
  const sortLocally = Boolean(params.sort_by) || filterByCreated;
  if(!sortLocally && statuses.length <= 1){
    const result = await request(`/api/guest/applies?${buildListQuery(params, statuses[0])}`, options);
    if(!result.ok)return result;
    return{ ...result, data: { ...result.data, items: (result.data.items || []).map(adaptListItem) } };
  }
  const itemLists = [];
  // 沒指定狀態（「全部」）時查一次、不帶狀態。
  for(const status of statuses.length ? statuses : ['']){
    const result = await request(`/api/guest/applies?${buildListQuery({ ...params, page: 1, per_page: -1 }, status)}`, options);
    if(!result.ok)return result;
    itemLists.push(keepMatching((result.data.items || []).map(adaptListItem)));
  }
  return{ ok: true, data: mergeStatusResults(itemLists, params), message: '' };
}
// 預約列表（預約管理頁用）。params：status（數字或陣列）、keyword（單號／受訪人／來訪者姓名）、
// date_from、date_to（預約日期區間）、page、per_page（-1 不分頁）；
// 選填 sort_by（'reservation_id' 預約單號、'visit' 預約日期＋時段）＋ sort_order（'asc'／'desc'），
// 不帶＝後端的預約日期由新到舊（後端分頁）；有帶就抓全部由前端排序。
// 回傳 { items, total, per_page, current_page }，每筆已轉成：company_name、floor、guests 等頁面欄位。
export function fetchReservations(params = {}){
  return fetchReservationsReal(params);
}
// 審核頁的列表（2026-11-23 起走真 API）。params 同預約列表：status、keyword、date_from／date_to（來訪日期）、page、per_page，
// 另可帶 created_from／created_to（申請日期，見 fetchReservationsReal）。後端沒有來訪事由篩選與排序（審核頁已拿掉這些控制項）。
// 依申請時間排序（設計稿）：params.sort_order 'desc'（預設，新到舊）或 'asc'。後端沒有排序參數，由前端排序（見 fetchReservationsReal）。
export function fetchApprovalList(params = {}){
  return fetchReservationsReal({ ...params, sort_by: 'created_at' });
}
// ── 訪客記錄（已報到的預約，狀態 40 在場中、50 已離場）：後端 2026-11-23 完成，全部走真 API ──
// 查詢參數：status（40 或 50，單一值）、date_from、date_to（預約日期）、guest_name、company_name、
// keyword（單號／受訪人／姓名／公司／手機／車牌）、visit_reason、page、per_page（-1 不分頁，最大 200）。
// 排序後端固定「預約日期＋起始時間由新到舊」，沒有排序參數。
// 回傳 { items, total, per_page, current_page }，每筆已轉成頁面欄位（見 guestAdapter.adaptRecordItem）。
// 選填 sort_order：'desc'（預設＝後端順序，來訪時間由新到舊，後端分頁）或 'asc'（舊到新）。
// 後端沒有排序參數，'asc' 時抓全部（per_page=-1）把後端順序整個反過來，再由前端分頁；反轉剛好就是由舊到新，同時間的順序也一致。
export async function fetchVisitRecords(params = {}){
  const ascending = params.sort_order === 'asc';
  const query = ascending ? { ...params, page: undefined, per_page: -1 } : params;
  const result = await request(`/api/guest/records?${buildRecordQuery(query)}`, { headers: { Accept: 'application/json' } });
  if(!result.ok)return result;
  const items = (result.data.items || []).map(adaptRecordItem);
  if(!ascending){
    return{ ...result, data: { ...result.data, items } };
  }
  const perPage = Number(params.per_page ?? -1);
  const page = Number(params.page ?? 1);
  items.reverse();
  return{
    ...result,
    data: {
      items: perPage > 0 ? items.slice((page - 1) * perPage, page * perPage) : items,
      total: items.length,
      per_page: perPage,
      current_page: page,
    },
  };
}
// 單筆訪客記錄詳情（右側面板用）：多了登記時間、備註、車牌、健康聲明。
export async function fetchVisitRecordDetail(id){
  const result = await request(`/api/guest/records/${id}`, { headers: { Accept: 'application/json' } });
  if(!result.ok)return result;
  return{ ...result, data: adaptRecordDetail(result.data) };
}
// 訪客記錄頁統計卡片：{ today, week, month, completed, today_trend, week_trend, month_trend }。
export async function fetchVisitSummary(){
  const result = await request('/api/guest/records/stats', { headers: { Accept: 'application/json' } });
  if(!result.ok)return result;
  return{ ...result, data: adaptRecordStats(result.data) };
}
// 下載後端產生的檔案（匯出 Excel／PDF）。成功回傳 { ok: true, data: { blob, filename } }，由頁面觸發下載；
// 失敗時後端回的是 JSON，取出錯誤訊息（訊息的分法同 failureMessage）。
async function downloadExport(path, fallbackName){
  let res;
  try{
    res = await authFetch(path);
  }catch{
    return{ ok: false, data: null, message: '無法連線到伺服器，請稍後再試' };
  }
  if(!res.ok || (res.headers.get('Content-Type') || '').includes('application/json')){
    const body = await res.json().catch(() => null);
    return{ ok: false, data: null, message: failureMessage(res, body, '匯出') };
  }
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(res.headers.get('Content-Disposition') || '');
  try{
    return{ ok: true, data: { blob: await res.blob(), filename: match ? decodeURIComponent(match[1]) : fallbackName }, message: '' };
  }catch{
    return{ ok: false, data: null, message: '檔案下載中斷，請稍後再試' };
  }
}
// 匯出訪客記錄（符合目前篩選的全部資料，後端上限 5000 筆）。kind：'excel' 或 'pdf'。
export function exportVisitRecords(kind, params = {}){
  const exportCode = kind === 'pdf' ? 2 : 1;
  return downloadExport(`/api/guest/records?${buildRecordQuery({ ...params, export: exportCode, page: undefined, per_page: undefined })}`, `訪客記錄.${kind === 'pdf' ? 'pdf' : 'xlsx'}`);
}
// ── 審核 API（GET /api/guest/approvals）：匯出、申請日期篩選都靠它 ──
// 後端（Michael，2026-11-24 `3547b12`）的 reservation_status 是「群組代碼」，不是狀態碼：
// 傳 0＝待審核＋修改待審核（0、1）、傳 1＝已核准＋修改已核准（10、11）、傳 70＝已拒絕，沒帶＝全部（不限審核狀態）。
// 所以這裡把頁籤的狀態陣列轉成群組代碼：[0,1]→0、[10,11]→1、[70]→70；其他組合後端沒有對應，直接回報，不要默默查出錯的內容。
const APPROVAL_STATUS_GROUPS = { '0,1': 0, '10,11': 1, '70': 70 };
const APPROVAL_GROUP_UNSUPPORTED = '這個狀態組合後端的審核 API 不支援，請改選「全部」';
function approvalStatusGroup(status){
  const statuses = normalizeStatuses(status);
  if(!statuses.length)return{ supported: true, group: undefined };
  const group = APPROVAL_STATUS_GROUPS[[...statuses].sort((a, b) => a - b).join(',')];
  return{ supported: group !== undefined, group };
}
// 審核 API 的查詢字串。params：keyword、date_from／date_to（來訪日期）、created_from／created_to（申請日期）；
// 日期參數的名字與後端 bug 的繞法見 guestAdapter.buildApprovalDateParams。
function approvalQuery(params, group, extra = {}){
  const dates = buildApprovalDateParams({ visit_from: params.date_from, visit_to: params.date_to, created_from: params.created_from, created_to: params.created_to });
  return buildRecordQuery({ ...dates, keyword: params.keyword, status: group, ...extra });
}
// 符合條件的預約單 id（Set）：有填申請日期時，用它從預約列表的資料裡挑出符合的。每頁不限筆數（per_page=-1）。
async function fetchApprovalIds(params){
  const { supported, group } = approvalStatusGroup(params.status);
  if(!supported)return{ ok: false, data: null, message: APPROVAL_GROUP_UNSUPPORTED };
  const result = await request(`/api/guest/approvals?${approvalQuery(params, group, { per_page: -1 })}`, { headers: { Accept: 'application/json' } });
  if(!result.ok)return result;
  return{ ok: true, data: new Set((result.data.items || []).map((item) => item.id)), message: '' };
}
// 匯出審核列表（`?export=1|2`，後端 2026-11-24 `8b4f5e5` 完成）：條件同審核列表（不含分頁），上限 5000 筆。
export async function exportApprovals(kind, params = {}){
  const { supported, group } = approvalStatusGroup(params.status);
  if(!supported){
    return{ ok: false, data: null, message: '這個狀態組合後端的審核匯出不支援，請改選「全部」再匯出' };
  }
  const exportCode = kind === 'pdf' ? 2 : 1;
  return downloadExport(`/api/guest/approvals?${approvalQuery(params, group, { export: exportCode })}`, `訪客審核紀錄.${kind === 'pdf' ? 'pdf' : 'xlsx'}`);
}
// 某些狀態、日期、關鍵字條件下的預約總筆數：後端沒有能套用這些條件的統計 API（/approvals/data 不吃篩選條件），用列表 API 每個狀態各查一次（每頁 1 筆，只看 total）再相加。
// 有填申請日期時，只有審核 API 能篩，改成對它查一次（每頁 1 筆，看 total）。
// 依序查詢、不併發（本機併發請求偶發 500）。
async function countReservations(params){
  if(params.created_from || params.created_to){
    const { supported, group } = approvalStatusGroup(params.status);
    if(!supported)return{ ok: false, data: null, message: APPROVAL_GROUP_UNSUPPORTED };
    const result = await request(`/api/guest/approvals?${approvalQuery(params, group, { page: 1, per_page: 1 })}`, { headers: { Accept: 'application/json' } });
    if(!result.ok)return result;
    return{ ok: true, data: result.data.total ?? 0, message: '' };
  }
  const statuses = normalizeStatuses(params.status);
  let count = 0;
  for(const status of statuses.length ? statuses : ['']){
    const result = await request(`/api/guest/applies?${buildListQuery({ ...params, page: 1, per_page: 1 }, status)}`, { headers: { Accept: 'application/json' } });
    if(!result.ok)return result;
    count += result.data.total ?? 0;
  }
  return{ ok: true, data: count, message: '' };
}
// 審核頁統計卡片：{ pending, approved, rejected, today, week }。
// params 是目前列表的查詢條件（status、keyword、date_from、date_to、created_from、created_to，不含分頁）。
// 每張卡片的數字＝「現在點這張卡片，列表會出現幾筆」：狀態卡片不套狀態、日期卡片不套來訪日期，其餘條件（含申請日期）都要套。
// 共 5 張卡片，查詢次數 = 各狀態各一次，每次都是一支列表 API。
export async function fetchApprovalSummary(params = {}){
  const base = { keyword: params.keyword, created_from: params.created_from, created_to: params.created_to };
  const [weekFrom, weekTo] = weekRange();
  const cards = {
    pending: { ...base, date_from: params.date_from, date_to: params.date_to, status: PENDING_STATUSES },
    approved: { ...base, date_from: params.date_from, date_to: params.date_to, status: [10, 11] },
    rejected: { ...base, date_from: params.date_from, date_to: params.date_to, status: [REJECTED_STATUS] },
    today: { ...base, date_from: todayString(), date_to: todayString(), status: params.status },
    week: { ...base, date_from: weekFrom, date_to: weekTo, status: params.status },
  };
  const summary = {};
  for(const [key, cardParams] of Object.entries(cards)){
    const result = await countReservations(cardParams);
    if(!result.ok)return result;
    summary[key] = result.data;
  }
  return{ ok: true, data: summary, message: '' };
}
// ── 統計報表（cliffdean，2026-11-23）：資料來自每日統計緩存表，只統計已報到（已到、已離場）的預約 ──
// 查詢參數：date_from、date_to（必填，統計期間，最長 731 天）、floor、doorplate_id（公司）、visit_reason（字典數值）。
// 總覽 GET /api/guest/reports → { summary, trend, reasons, floors }（轉換見 guestAdapter.adaptReportOverview）。
export async function fetchReportOverview(params = {}){
  const result = await request(`/api/guest/reports?${toQuery(params)}`, { headers: { Accept: 'application/json' } });
  if(!result.ok)return result;
  return{ ...result, data: adaptReportOverview(result.data) };
}
// 明細 GET /api/guest/reports/details：每天一筆，可分頁（page、per_page，-1 不分頁）。
export async function fetchReportDaily(params = {}){
  const result = await request(`/api/guest/reports/details?${toQuery(params)}`, { headers: { Accept: 'application/json' } });
  if(!result.ok)return result;
  return{ ...result, data: { ...result.data, items: (result.data.items || []).map(adaptReportDay) } };
}
// 匯出統計明細：kind 'excel' 或 'pdf'，條件同明細（不含分頁）。
export function exportReportDaily(kind, params = {}){
  const exportCode = kind === 'pdf' ? 2 : 1;
  return downloadExport(`/api/guest/reports/details?${toQuery({ ...params, export: exportCode })}`, `訪客統計明細.${kind === 'pdf' ? 'pdf' : 'xlsx'}`);
}
// 核准、拒絕單筆預約：PUT /api/guest/applies/{id}/status。
// 核准傳 1，後端依原狀態轉成 10（待審核→已核准）或 11（修改待審核→修改已核准）；拒絕傳 70（會清除 QR code、速通碼、門禁卡）。
// 後端不檢查原狀態，所以呼叫前頁面要先確認是待審核的預約（已核准的再傳 1 會被改成「修改待審核」）。
export function approveReservation(id){
  return putJson(`/api/guest/applies/${id}/status`, { reservation_status: 1 });
}
export function rejectReservation(id){
  return putJson(`/api/guest/applies/${id}/status`, { reservation_status: REJECTED_STATUS });
}
// 現場訪客登記。payload 欄位照後端資料表命名：
// { guest_count, visit_reason, remark, visitor: { name, phone, company_name, title, plate_number } }。
export function registerWalkIn(payload){
  if(USE_MOCK.registration){
    return registerMock(payload);
  }
  return postJson('/api/guest/registration', payload); // 網址暫定
}
