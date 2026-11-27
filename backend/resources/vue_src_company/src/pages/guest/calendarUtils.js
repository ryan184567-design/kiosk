/* *****************************************
File: calendarUtils.js
Author: Callum
Date: 2026-11-20
Description: 預約行事曆的日期運算（月／週／日的顯示區間、前後切換、依日期分組），純函式不碰畫面。
***************************************** */
import { CALENDAR_GROUPS } from './constants';
import { addDays, toDateString, todayString, weekRange } from './format';
const parse = (dateString) => new Date(`${dateString}T00:00:00`);
// 該日期所屬月份的第一天：'2026-11-08' → '2026-11-01'
export function monthStart(dateString){
  const d = parse(dateString);
  return toDateString(new Date(d.getFullYear(), d.getMonth(), 1));
}
// 該月最後一天：'2026-11-08' → '2026-11-30'
export function monthEnd(dateString){
  const d = parse(dateString);
  return toDateString(new Date(d.getFullYear(), d.getMonth() + 1, 0));
}
// 月曆要畫的所有日期：從第一天所在那週的週日，到最後一天所在那週的週六（5 或 6 列，每列 7 天）。
export function monthDays(dateString){
  const start = weekRange(monthStart(dateString))[0];
  const end = weekRange(monthEnd(dateString))[1];
  const days = [];
  for (let d = start; d <= end; d = addDays(d, 1)){
    days.push(d);
  }
  return days;
}
// 目前顯示的日期清單：month＝整張月曆、week＝該週 7 天、day＝只有當天。
export function viewDays(view, cursor){
  if(view === 'month') return monthDays(cursor);
  if(view === 'week') return weekDays(cursor);
  return [cursor];
}
function weekDays(cursor){
  const [start] = weekRange(cursor);
  return Array.from({ length: 7 }, (_, i) => addDays(start, i));
}
// 要向後端查的日期區間 [起, 迄]。
export function viewRange(view, cursor){
  const days = viewDays(view, cursor);
  return [days[0], days[days.length - 1]];
}
// 按「上一個／下一個」：月＝前後一個月（回到 1 號）、週＝±7 天、日＝±1 天。
export function shiftCursor(view, cursor, direction){
  if(view === 'month'){
    const d = parse(cursor);
    return toDateString(new Date(d.getFullYear(), d.getMonth() + direction, 1));
  }
  return addDays(cursor, direction * (view === 'week' ? 7 : 1));
}
// 切換期間後右側清單要選哪一天：今天在這段期間內就選今天，否則選期間的第一天
// （月檢視的第一天指「當月 1 號」，不是灰色的上個月日期）。
export function defaultSelected(view, cursor){
  const today = todayString();
  const [from, to] = viewRange(view, cursor);
  if (today >= from && today <= to) return today;
  return view === 'month' ? monthStart(cursor) : from;
}
// 預約狀態碼 → 顏色分組 key（approved、pending、notified、done、cancelled）。
export function groupKeyOf(statusCode){
  return CALENDAR_GROUPS.find((g) => g.codes.includes(Number(statusCode)))?.key || 'done';
}
// 預約清單 → { '2026-11-08': [依開始時間排序的預約…] }
export function groupByDate(items){
  const map = {};
  for(const item of items){
    (map[item.visit_date] ||= []).push(item);
  }
  for(const list of Object.values(map)){
    list.sort((a, b) => a.visit_start_time.localeCompare(b.visit_start_time));
  }
  return map;
}
