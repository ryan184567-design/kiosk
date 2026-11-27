/* *****************************************
File: format.js
Author: Callum
Date: 2026-11-19
Description: 訪客管理各頁共用的日期、時間顯示格式化函式。
***************************************** */
const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];
const pad = (n) => String(n).padStart(2, '0');
// Date → 'YYYY-MM-DD'（用本地時間，不用 toISOString，避免時區差一天）。
export function toDateString(date){
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}
export function todayString(){
  return toDateString(new Date());
}
// '2026-11-19' 加減天數，例如 addDays('2026-11-19', -1) → '2026-11-18'。
export function addDays(dateString, days){
  const date = new Date(`${dateString}T00:00:00`);
  date.setDate(date.getDate() + days);
  return toDateString(date);
}
// 本週的 [週日, 週六]：跟日期選擇器的月曆一致（週日是第一欄）。
export function weekRange(dateString = todayString()){
  const start = addDays(dateString, -new Date(`${dateString}T00:00:00`).getDay());
  return [start, addDays(start, 6)];
}
// 後端「本週」的算法：週一到週日（訪客記錄的統計卡片用這個，點卡片查出來的筆數才對得上）。
export function mondayWeekRange(dateString = todayString()){
  const start = addDays(dateString, -((new Date(`${dateString}T00:00:00`).getDay() + 6) % 7));
  return [start, addDays(start, 6)];
}
// 本月的 [1 號, 月底]。
export function monthRange(dateString = todayString()){
  const [year, month] = dateString.split('-').map(Number);
  return [`${year}-${pad(month)}-01`, toDateString(new Date(year, month, 0))];
}
// '2026-11-08' → '2026/11/08'
export function formatDate(value){
  return value ? String(value).slice(0, 10).replaceAll('-', '/') : '—';
}
// '2026-11-08 09:12:00' → '09:12'
export function formatClock(value){
  return value ? String(value).slice(11, 16) : '';
}
// '2026-11-08' → '2026/11/08 (日)'
export function formatDateWithWeekday(value){
  if(!value) return '—';
  const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
  return `${formatDate(value)} (${WEEKDAYS[date.getDay()]})`;
}
// 來訪時間：'11/08 10:00 – 11:00'
export function formatVisitSlot(row){
  const day = formatDate(row.visit_date).slice(5);
  return `${day} ${row.visit_start_time} – ${row.visit_end_time}`;
}
// 多久以前（通知清單用）：'剛剛'、'5 分鐘前'、'3 小時前'、'2 天前'，7 天以上改顯示日期 '11/08'。
// value 是本地時間的 'YYYY-MM-DD HH:mm:ss'（列表的申請時間已由 guestAdapter.normalizeDateTime 轉好）；讀不懂就回空字串。
export function formatTimeAgo(value, now = new Date()){
  if(!value) return '';
  const time = new Date(String(value).replace(' ', 'T'));
  if(Number.isNaN(time.getTime())) return '';
  const minutes = Math.floor((now - time) / 60000);
  if(minutes < 1) return '剛剛';
  if(minutes < 60) return `${minutes} 分鐘前`;
  const hours = Math.floor(minutes / 60);
  if(hours < 24) return `${hours} 小時前`;
  const days = Math.floor(hours / 24);
  if(days < 7) return `${days} 天前`;
  return formatDate(value).slice(5);
}
