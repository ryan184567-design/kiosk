/* *****************************************
File: useApprovalQuery.js
Author: Callum
Date: 2026-11-19
Description: 訪客審核頁的查詢狀態：統計卡片、狀態頁籤、篩選列、分頁之間的互動規則集中在這裡。
***************************************** */
import { computed, reactive, ref } from 'vue';
import { PENDING_STATUSES, REJECTED_STATUS } from './constants';
import { todayString, weekRange } from './format';
// ─────────────────────────────────────────────────────────────
// 互動規則（審核頁上方五張卡片分成兩組，兩組互不干擾、可以組合）：
//
// 1. 狀態組：「待審核、已核准、已拒絕」三張卡片＝下方頁籤(另有「全部」)，兩者是同一個狀態，
//    永遠同步。點卡片＝切到那個狀態；再點一次已選取的卡片＝取消，回到「全部」。
// 2. 日期組：「今日訪客、本週訪客」套用「來訪日期」。點卡片＝填入今天／本週；
//    再點一次已選取的卡片＝取消來訪日期。**不會動到狀態組。**
// 3. 篩選列上的輸入(關鍵字、來訪日期、申請日期)在按「查詢」之前只是草稿。
//    (來訪事由篩選與排序：後端的預約列表沒有，2026-11-23 改接真 API 時拿掉了。
//     申請日期：預約列表沒有，只有審核 API 有，2026-11-25 起靠審核 API 篩，見 api/guest.js。)
//    點卡片、點頁籤、按查詢都算「重新查詢」，會一併套用篩選列上目前看到的條件，
//    所以查完之後，列表一定等於畫面上看到的所有條件。
// 4. 換頁、改每頁筆數、切換「申請時間」排序只是看同一批結果，不會套用還沒按查詢的草稿；排序會回到第 1 頁。
// 5. 卡片是否選取、卡片上的數字，都以「目前列表實際套用的條件」為準，不看草稿；
//    每張卡片的數字＝現在點它，列表會出現幾筆（由統計 API 依同樣條件計算）。
// 6. 從別處指定頁籤進來（右上角鈴鐺）：清空篩選列、切到該頁籤、回到第 1 頁重新查詢（resetToTab）；
//    排序與每頁筆數維持使用者原本的選擇。
// ─────────────────────────────────────────────────────────────
export const STATUS_TABS = [
  { key: 'pending', label: '待審核', icon: 'fa-solid fa-hourglass-half', tone: 'navy', status: PENDING_STATUSES },
  { key: 'approved', label: '已核准', icon: 'fa-regular fa-square-check', tone: 'green', status: [10, 11] },
  { key: 'rejected', label: '已拒絕', icon: 'fa-solid fa-xmark', tone: 'red', status: [REJECTED_STATUS] },
  { key: 'all', label: '全部', icon: 'fa-regular fa-calendar-days', tone: 'navy', status: null },
];
export const QUICK_RANGES = {
  today: () => [todayString(), todayString()],
  week: () => weekRange(),
};
function emptyFilters(){
  return { keyword: '', visit_range: null, created_range: null };
}
// 複製一份篩選條件（日期是陣列，要另外複製，避免草稿與已套用的條件共用同一個陣列）。
function copyFilters(source){
  return{
    keyword: source.keyword,
    visit_range: source.visit_range ? [...source.visit_range] : null,
    created_range: source.created_range ? [...source.created_range] : null,
  };
}
// 來訪日期剛好是今天／本週時，回傳 'today'／'week'，否則 null。
function matchQuickRange(range){
  const [from, to] = range || [];
  return Object.keys(QUICK_RANGES).find((key) => {
    const [quickFrom, quickTo] = QUICK_RANGES[key]();
    return from === quickFrom && to === quickTo;
  }) || null;
}
// reloadAll：重新載入統計卡片與列表；reloadList：只重新載入列表（換頁、排序用）。
export function useApprovalQuery({ reloadAll, reloadList }){
  const filters = reactive(emptyFilters()); // 篩選列上看得到的值（草稿）
  const applied = reactive(emptyFilters()); // 目前列表實際套用的值
  const activeTab = ref('pending');
  const page = ref(1);
  const perPage = ref(10);
  const sortOrder = ref('desc'); // 申請時間：'desc' 新到舊（預設）、'asc' 舊到新
  const quickRange = computed(() => matchQuickRange(applied.visit_range));
  // 重新查詢：套用篩選列目前的條件、回到第 1 頁。
  function query(){
    Object.assign(applied, copyFilters(filters));
    page.value = 1;
    return reloadAll();
  }
  // 頁籤：單純切換狀態（點已選取的頁籤也只是重新查詢）。
  function selectTab(key){
    activeTab.value = key;
    return query();
  }
  // 狀態卡片：再點一次已選取的卡片＝取消，回到「全部」。
  function toggleStatus(key){
    return selectTab(activeTab.value === key ? 'all' : key);
  }
  // 規則 6：清空篩選列再切到指定頁籤（不認得的頁籤當作「待審核」）。
  function resetToTab(key){
    Object.assign(filters, emptyFilters());
    return selectTab(STATUS_TABS.some((t) => t.key === key) ? key : 'pending');
  }
  // 日期卡片：填入或清除來訪日期，不動狀態。
  function toggleQuickRange(key){
    filters.visit_range = quickRange.value === key ? null : QUICK_RANGES[key]();
    return query();
  }
  function changePage(){
    return reloadList();
  }
  function changePerPage(){
    page.value = 1;
    return reloadList();
  }
  // 申請時間排序：order 為 'asc' 或 'desc'。
  function changeSort(order){
    sortOrder.value = order;
    page.value = 1;

    return reloadList();
  }
  // 目前列表實際套用的查詢條件（列表與統計卡片共用）。
  function conditions(){
    const tab = STATUS_TABS.find((t) => t.key === activeTab.value);
    return{
      status: tab.status,
      keyword: applied.keyword.trim(),
      // 來訪日期（後端的預約日期）。
      date_from: applied.visit_range?.[0],
      date_to: applied.visit_range?.[1],
      // 申請日期（預約單建立的日期）。
      created_from: applied.created_range?.[0],
      created_to: applied.created_range?.[1],
    };
  }
  function listParams(){
    return{ ...conditions(), page: page.value, per_page: perPage.value, sort_order: sortOrder.value };
  }
  return{
    filters,
    applied,
    activeTab,
    page,
    perPage,
    quickRange,
    search: query,
    selectTab,
    toggleStatus,
    resetToTab,
    toggleQuickRange,
    changePage,
    changePerPage,
    changeSort,
    listParams,
    summaryParams: conditions,
  };
}
