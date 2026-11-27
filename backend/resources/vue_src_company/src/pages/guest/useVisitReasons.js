/* *****************************************
File: useVisitReasons.js
Author: Callum
Date: 2026-11-23
Description: 來訪事由字典(guest_visit_reason)：下拉選項與「數值 → 顯示文字」，各頁共用、只向後端讀一次。
***************************************** */
import { computed, ref } from 'vue';
import { fetchVisitReasonItems } from '../../api/guest';
import { FALLBACK_VISIT_REASONS } from './constants';
// [{ value, label, enabled }]：先放備用清單，字典讀回來後換成真的。
const items = ref(FALLBACK_VISIT_REASONS.map((r) => ({ ...r, enabled: true })));
let loading = null;
// 只會真的呼叫一次；失敗時保留備用清單（下次重新整理頁面會再試）。
export function loadVisitReasons(){
  if(!loading){
    loading = fetchVisitReasonItems().then((result) => {
      if(!result.ok){
        loading = null;
        return;
      }
      const list = (result.data || [])
        .map((i) => ({ value: Number(i.value), label: i.label, enabled: Boolean(i.is_enabled) }))
        .filter((i) => Number.isFinite(i.value));
      if (list.length) items.value = list;
    });
  }
  return loading;
}
// 下拉選單用：只列出啟用中的項目(停用的歷史資料仍能顯示文字，但不能再被選)。
export const reasonOptions = computed(() => items.value.filter((i) => i.enabled));
// 數值 → 顯示文字。沒有對應項目時顯示「其他」，與後端的備用文字一致；舊資料若還是文字就原樣顯示。
export function reasonLabel(value){
  if (value === null || value === undefined || value === '') return '—';
  const found = items.value.find((i) => i.value === Number(value));
  if (found) return found.label;
  return Number.isNaN(Number(value)) ? String(value) : '其他';
}
// 數值 → 在字典裡的順序（0 起算，含停用的項目；後端依 sort 排序）。找不到回傳 -1。
// 圖表用它決定顏色：同一個事由永遠同一個顏色，不會因為筆數排名或篩選而換色。
export function reasonOrder(value){
  return items.value.findIndex((i) => i.value === Number(value));
}
export function useVisitReasons(){
  loadVisitReasons();
  return { reasonOptions, reasonLabel, reasonOrder };
}
