/* *****************************************
File: guest.js
Author: Callum
Date: 2026-11-19
Description: 訪客管理模塊的 API 呼叫封裝（預約、門牌選項），頁面不直接寫 fetch。
***************************************** */

import { authFetch } from '../stores/auth';

// 統一把後端回應整理成 { ok, data, message }，頁面只要看 ok 與 message：
// 後端格式為 { status, error_code, data, error_message }，驗證失敗（422）
// 也是同一種格式，error_message 會帶第一個欄位的中文錯誤，見 bootstrap/app.php。
async function request(path, options = {}) {
  try {
    const res = await authFetch(path, options);
    const body = await res.json();

    if (body.status) {
      return { ok: true, data: body.data, message: '' };
    }

    return { ok: false, data: null, message: body.error_message || '操作失敗，請稍後再試' };
  } catch {
    return { ok: false, data: null, message: '無法連線到伺服器，請稍後再試' };
  }
}

function postJson(path, payload) {
  return request(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify(payload),
  });
}

// GET /api/data-setting/doorplate：受訪公司下拉用（門牌資料，per_page=-1 不分頁）。
export function fetchDoorplates() {
  return request('/api/data-setting/doorplate?per_page=-1', { headers: { Accept: 'application/json' } });
}

// POST /api/guest/applies：新增訪客預約，成功回傳 { reservation_id, qr_code, fast_pass_code }。
export function createReservation(payload) {
  return postJson('/api/guest/applies', payload);
}

// ─────────────────────────────────────────────────────────────
// 後端尚未完成的 API：目前用假資料，後端好了把對應旗標改成 false 就切到真 API。
// 假資料的欄位名稱一律照後端資料表（guest_applies／guests）與 Swagger 註解，
// 頁面只認這個欄位格式，所以切換時頁面不用改，只可能需要微調這支檔案。
// ─────────────────────────────────────────────────────────────
const USE_MOCK = {
  // GET /api/guest/applies（列表）：後端 index 目前是空殼。
  reservationList: true,
  // 現場訪客登記：後端沒有對應 API（資料表有 reservation_type=1 現場預約，
  // 但尚未確認登記要走哪支 API、是否需要受訪公司／受訪人）。
  registration: true,
};

// 頁面用來判斷「這支 API 現在是不是假資料」，決定要不要顯示示意資料提示；
// 旗標改成 false 後提示自動消失，不用記得手動拿掉。
export function isMock(name) {
  return Boolean(USE_MOCK[name]);
}

// 假設列表回傳每筆已帶 company_name（由 doorplate_id 帶出）與 guests（來訪者明細，
// 對應 Model 的 guests() 關聯）；這兩個欄位名稱是推測，等後端列表完成後對照調整。
// 時間為 "HH:mm"（Model 註解：Controller 會把 MySQL time 截成前 5 碼）。
const MOCK_RESERVATIONS = [
  { id: 12, reservation_id: 'GA202611080012', reservation_type: 0, doorplate_id: 1, company_name: 'XX科技有限公司', host_name: '陳志明', visit_date: '2026-11-08', visit_start_time: '10:00', visit_end_time: '11:00', guest_count: 1, visit_reason: '業務洽談', remark: null, reservation_status: 10, guests: [{ name: '王小明', company_name: '宏昇工程股份有限公司', phone: '0912-345-678' }] },
  { id: 11, reservation_id: 'GA202611080011', reservation_type: 0, doorplate_id: 2, company_name: '鼎泰顧問有限公司', host_name: '林美玲', visit_date: '2026-11-08', visit_start_time: '14:00', visit_end_time: '15:00', guest_count: 3, visit_reason: '合作討論', remark: '請協助安排會議室 A 室', reservation_status: 0, guests: [{ name: '陳雅婷', company_name: '鼎泰顧問有限公司', phone: '0922-111-333' }] },
  { id: 10, reservation_id: 'GA202611080010', reservation_type: 0, doorplate_id: 3, company_name: '聯合營造有限公司', host_name: '張志豪', visit_date: '2026-11-08', visit_start_time: '15:30', visit_end_time: '16:30', guest_count: 2, visit_reason: '工地進度會議', remark: null, reservation_status: 20, guests: [{ name: '劉建宏', company_name: '聯合營造有限公司', phone: '0933-222-444' }] },
  { id: 9, reservation_id: 'GA202611070009', reservation_type: 0, doorplate_id: 4, company_name: '大智科技股份有限公司', host_name: '黃建達', visit_date: '2026-11-07', visit_start_time: '09:30', visit_end_time: '10:30', guest_count: 1, visit_reason: '技術交流', remark: null, reservation_status: 40, guests: [{ name: '周欣怡', company_name: '大智科技股份有限公司', phone: '0955-666-777' }] },
  { id: 8, reservation_id: 'GA202611060008', reservation_type: 0, doorplate_id: 5, company_name: '宏昇工程股份有限公司', host_name: '李淑華', visit_date: '2026-11-06', visit_start_time: '13:00', visit_end_time: '14:00', guest_count: 2, visit_reason: '設備維護', remark: null, reservation_status: 60, guests: [{ name: '李美玲', company_name: '宏昇工程股份有限公司', phone: '0966-888-999' }] },
];

// 現場訪客登記。payload 欄位照後端資料表命名：
// { guest_count, visit_reason, remark, visitor: { name, phone, company_name, title, plate_number } }。
// 假資料階段只回傳一組假單號；真 API 的網址與回傳格式待後端確認，確認後改下面那一行。
export function registerWalkIn(payload) {
  if (USE_MOCK.registration) {
    const stamp = new Date().toISOString().slice(0, 10).replaceAll('-', '');

    return Promise.resolve({
      ok: true,
      data: { reservation_id: `GA${stamp}${String(Math.floor(Math.random() * 9000) + 1000)}` },
      message: '',
    });
  }

  return postJson('/api/guest/registration', payload); // 網址為暫定，待後端確認
}

// 列表回傳格式比照其他列表 API：{ items, total, per_page, current_page }。
// params 目前只預留（之後會有日期、狀態、關鍵字篩選），假資料階段不處理。
export function fetchReservations(params = {}) {
  if (USE_MOCK.reservationList) {
    return Promise.resolve({
      ok: true,
      data: { items: MOCK_RESERVATIONS, total: MOCK_RESERVATIONS.length, per_page: -1, current_page: 1 },
      message: '',
    });
  }

  const query = new URLSearchParams({ per_page: -1, ...params }).toString();

  return request(`/api/guest/applies?${query}`, { headers: { Accept: 'application/json' } });
}
