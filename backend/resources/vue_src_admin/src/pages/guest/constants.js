/* *****************************************
File: constants.js
Author: Callum
Date: 2026-11-19
Description: 訪客管理各頁共用的常數與驗證規則（預約事由選項、手機格式、人數上限）。
***************************************** */

// 來訪事由：後端 2026-11-23 起改成資料字典 guest_visit_reason（預約單存的是項目的「數值」），
// 選項與顯示文字都從 GET /api/dictionaries/guest_visit_reason/items 取，見 useVisitReasons.js。
// 下面是字典讀不到時的備用清單，內容與後端種子資料一致（SiteBaselineSeeder）。
export const FALLBACK_VISIT_REASONS = [
  { value: 1, label: '業務洽談' },
  { value: 2, label: '工程維護' },
  { value: 3, label: '會議' },
  { value: 4, label: '送貨/收發' },
  { value: 5, label: '面試' },
  { value: 6, label: '參觀訪問' },
  { value: 7, label: '設備檢測' },
  { value: 99, label: '其他' },
];
// 來訪人數與名單上限 1～10 人（後端 2026-11-24 `03e694a` 起驗證也是 max:10，與文件一致）。
export const MAX_GUESTS = 10;
// 預約狀態字典，對應 API 文件 guest_apply_reservation_status（reservation_status）。
// 70 已拒絕：後端目前的狀態字典沒有「拒絕」（設計稿的訪客審核有），暫定用 70，
// 這是前端假定的碼，待後端確認實際的狀態碼後修改這一個地方。
export const REJECTED_STATUS = 70;
export const RESERVATION_STATUS = {
  0: { label: '待審核', type: 'warning' },
  1: { label: '修改待審核', type: 'warning' },
  10: { label: '已核准', type: 'success' },
  11: { label: '修改已核准', type: 'success' },
  20: { label: '已通知', type: 'primary' },
  30: { label: '未到', type: 'info' },
  40: { label: '已到', type: 'success' },
  50: { label: '已離場', type: 'info' },
  60: { label: '已取消', type: 'danger' },
  [REJECTED_STATUS]: { label: '已拒絕', type: 'danger' },
};
// 可修改的狀態：待審核、修改待審核、已核准、修改已核准（後端 GuestApplyService::EDITABLE_STATUSES），
// 而且必須尚未報到（check_in_at 為空）。其他狀態後端會拒絕修改。
export const EDITABLE_STATUSES = [0, 1, 10, 11];
// 已報到的狀態：已到、已離場（有進出記錄，不能刪除）。
export const CHECKED_IN_STATUSES = [40, 50];
// 行事曆的顏色分組（設計稿圖例：已核准、待審核、已通知、已完成、已取消）。
// 設計稿沒有的狀態先歸入最接近的一組：30 未到、40 已到、50 已離場 → 已完成；70 已拒絕 → 已取消。
// 顏色由 ReservationCalendar 的 CSS 依 key 決定（嚴格 CSP 不能用 inline style）。
export const CALENDAR_GROUPS = [
  { key: 'approved', label: '已核准', codes: [10, 11], icon: 'fa-solid fa-check' },
  { key: 'pending', label: '待審核', codes: [0, 1], icon: 'fa-regular fa-hourglass' },
  { key: 'notified', label: '已通知', codes: [20], icon: 'fa-regular fa-bell' },
  { key: 'done', label: '已完成', codes: [30, 40, 50], icon: 'fa-regular fa-circle-check' },
  { key: 'cancelled', label: '已取消', codes: [60, REJECTED_STATUS], icon: 'fa-solid fa-ban' },
];
// 訪客記錄頁只看「已進場」的預約：後端狀態 40 已到＝設計稿的「在場中」、50 已離場。
export const RECORD_STATUS = {
  40: { label: '在場中', type: 'primary' },
  50: { label: '已離場', type: 'success' },
};
// 需要審核的狀態：待審核、修改待審核。
export const PENDING_STATUSES = [0, 1];
// 手機規則與後端一致：數字、加號、連字號，8～30 碼。
export const PHONE_PATTERN = /^[0-9+-]{8,30}$/;
export const PHONE_MESSAGE = '手機號碼只能有數字、加號、連字號，長度 8～30 碼';
