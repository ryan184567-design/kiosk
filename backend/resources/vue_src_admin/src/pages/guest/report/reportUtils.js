/* *****************************************
File: reportUtils.js
Author: Callum
Date: 2026-11-26
Description: 統計報表「來訪趨勢分析」「來訪事由分析」共用的日期分組與計算（純函式，不依賴瀏覽器或 API，可單獨測試）。
***************************************** */
import { addDays, formatDate, formatDateWithWeekday, mondayWeekRange, todayString } from '../format';
// ── 資料口徑（與後端統計 API 一致）──
// - 統計資料每天凌晨彙整到前一天，所以「昨天」以後的日子是「還沒有資料」（null），不是 0；圖表在那裡斷開，平均也不算這些天。
// - 上期＝緊接在統計期間之前、相同天數的期間（與後端「較上期」相同）。
// - 「較上期同期」只比「本期已經有資料的天數」：例如本月只過了 10 天，就跟上期的前 10 天比，月中查詢才不會一律顯示大幅下降。
// 'YYYY-MM-DD' 之間（含頭尾）的每一天；格式不對或起日晚於迄日時回傳空陣列。
export function dateList(from, to){
  const pattern = /^\d{4}-\d{2}-\d{2}$/;
  if(!pattern.test(from || '') || !pattern.test(to || '') || from > to) return [];
  const dates = [];
  for(let d = from; d <= to; d = addDays(d, 1)) dates.push(d);
  return dates;
}
// 上期的 [起, 迄]。
export function previousRange(from, to){
  const days = dateList(from, to).length;
  const prevTo = addDays(from, -1);
  return [addDays(prevTo, -(days - 1)), prevTo];
}
// 最後一天有統計資料的日期（昨天）。
export function lastDataDate(today = todayString()){
  return addDays(today, -1);
}
// 期間內已經有資料的天數（dates 由舊到新）。
export function countElapsed(dates, lastDate = lastDataDate()){
  return dates.filter((d) => d <= lastDate).length;
}
// 每天的數值：rowsByDate 是 { 'YYYY-MM-DD': 列 }，沒有那一天的列＝0；lastDate 以後＝null（還沒有資料）。
export function dailySeries(dates, rowsByDate, field, lastDate = lastDataDate()){
  return dates.map((d) => (d <= lastDate ? Number(rowsByDate[d]?.[field] ?? 0) : null));
}
// ── 時間單位（日／週／月）──
export const GRANULARITIES = [
  { key: 'day', label: '日' },
  { key: 'week', label: '週' },
  { key: 'month', label: '月' },
];
// 預設時間單位：2 個月內看每天，7 個月內看每週，再長看每月（點太多折線會擠成一團）。
export function defaultGranularity(dayCount){
  if(dayCount <= 62) return 'day';
  if(dayCount <= 210) return 'week';
  return 'month';
}
// 兩個日期的區間文字：'2026/11/03 – 11/09'（跨年時迄日也顯示年份）；同一天就是 '2026/11/03 (一)'。
export function rangeText(from, to){
  if(from === to) return formatDateWithWeekday(from);
  const end = from.slice(0, 4) === to.slice(0, 4) ? formatDate(to).slice(5) : formatDate(to);
  return `${formatDate(from)} – ${end}`;
}
// 依時間單位把日期分組：回傳 [{ key, start, end, label, title }]，start／end 是 dates 的索引（含頭尾）。
// 週＝週一到週日、月＝日曆月；頭尾不滿一週／一個月的，照統計期間實際的日期切。
// label 是 X 軸上的短字（'11/03'、'2026/11'），title 是提示框與明細表用的完整期間。
export function buildBuckets(dates, granularity){
  const keyOf = {
    day: (d) => d,
    week: (d) => mondayWeekRange(d)[0],
    month: (d) => d.slice(0, 7),
  }[granularity] || ((d) => d);
  const buckets = [];
  dates.forEach((d, i) => {
    const key = keyOf(d);
    const last = buckets[buckets.length - 1];
    if(last && last.key === key){
      last.end = i;
    }else{
      buckets.push({ key, start: i, end: i });
    }
  });
  return buckets.map((b) => {
    const from = dates[b.start];
    const to = dates[b.end];
    if(granularity === 'month'){
      const month = formatDate(from).slice(0, 7);
      const whole = from.endsWith('-01') && addDays(to, 1).endsWith('-01');
      return { ...b, label: month, title: whole ? month : `${month}（${rangeText(from, to)}）` };
    }
    if(granularity === 'week') return { ...b, label: formatDate(from).slice(5), title: rangeText(from, to) };
    return { ...b, label: formatDate(from).slice(5), title: formatDateWithWeekday(from) };
  });
}
// 每組的完整標題：本期只過了一部分的組（例如這週只過了 3 天），註明資料統計到哪一天。elapsed＝本期已有資料的天數。
export function bucketTitles(buckets, dates, elapsed){
  const until = elapsed ? formatDate(dates[elapsed - 1]).slice(5) : '';
  return buckets.map((b) => (b.start < elapsed && b.end >= elapsed ? `${b.title}（本期統計到 ${until}）` : b.title));
}
// 每組的合計；整組都還沒有資料（全是 null）時回傳 null。
export function sumByBucket(values, buckets){
  return buckets.map(({ start, end }) => {
    let sum = null;
    for(let i = start; i <= end; i++){
      if(values[i] !== null && values[i] !== undefined) sum = (sum ?? 0) + values[i];
    }
    return sum;
  });
}
// 前 count 個數值的合計（略過 null）。
export function sumFirst(values, count = values.length){
  return values.slice(0, count).reduce((sum, v) => sum + (v ?? 0), 0);
}
// 成長百分比（一位小數）；上期為 0 時無法計算，回傳 null（與後端相同）。
export function growthRate(current, previous){
  if(!previous) return null;
  return Math.round(((current - previous) / previous) * 1000) / 10;
}
// 增減文字：'+3（+20.0%）'、'-2（-50.0%）'、'0'；上期為 0 時只顯示人數。
export function changeText(current, previous){
  const diff = current - previous;
  const rate = growthRate(current, previous);
  const sign = diff > 0 ? '+' : '';
  if(diff === 0) return '0';
  return rate === null ? `${sign}${diff}` : `${sign}${diff}（${rate > 0 ? '+' : ''}${rate}%）`;
}
// ── 星期分布 ──
export const WEEKDAY_LABELS = ['週一', '週二', '週三', '週四', '週五', '週六', '週日'];
// 週一～週日各自的平均每日人次（只算有資料的日子）：[{ label, days, average }]，期間內沒出現過的星期幾 average 為 null。
export function weekdayAverages(dates, values){
  const sums = Array(7).fill(0);
  const days = Array(7).fill(0);
  dates.forEach((d, i) => {
    if(values[i] === null || values[i] === undefined) return;
    const w = (new Date(`${d}T00:00:00`).getDay() + 6) % 7;
    sums[w] += values[i];
    days[w] += 1;
  });
  return WEEKDAY_LABELS.map((label, w) => ({ label, days: days[w], average: days[w] ? Math.round((sums[w] / days[w]) * 10) / 10 : null }));
}
// 數值最大的索引（略過 null）；全部是 0 或沒有資料時回傳 -1。
export function peakIndex(values){
  let best = -1;
  values.forEach((v, i) => {
    if(v !== null && v !== undefined && v > 0 && (best < 0 || v > values[best])) best = i;
  });
  return best;
}
// ── 事由顏色（規則同 ReasonDonut）──
// 顏色依事由在字典裡的順序決定（同一個事由在每張圖都是同一色）；色盤只有 paletteSize 色，不循環重用：
// 字典超過色盤數量、或有字典裡找不到的數值時，最後一色留給「其他事由（合計）」，放不下的事由都併進去。
export const REST_REASON_LABEL = '其他事由（合計）';
export function assignReasonSlots(values, reasonOrder, paletteSize){
  const withSlot = values.map((value) => ({ value, slot: reasonOrder(value) }));
  const overflow = withSlot.some((s) => s.slot < 0 || s.slot >= paletteSize);
  const limit = overflow ? paletteSize - 1 : paletteSize;
  return withSlot.map((s) => (s.slot >= 0 && s.slot < limit ? { ...s, rest: false } : { value: s.value, slot: paletteSize - 1, rest: true }));
}
