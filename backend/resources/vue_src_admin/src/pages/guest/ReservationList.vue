<template>
  <div class="rsv-page">
    <!-- 頁籤列：預約列表、待審核、今日預約、行事曆檢視是切換檢視；「新增預約」是開對話框。 -->
    <nav class="rsv-tabs" aria-label="預約管理檢視">
      <button v-for="tab in TABS" :key="tab.key" type="button" class="rsv-tab" :class="{ 'rsv-tab--active': view === tab.key }" @click="selectTab(tab.key)">
        <i :class="tab.icon" aria-hidden="true"></i>{{ tab.label }}
        <span v-if="tab.key === 'pending' && pendingCount > 0" class="rsv-tab__badge" :aria-label="`${pendingCount} 筆待審核`">{{ pendingCount }}</span>
      </button>
    </nav>
    <ReservationCalendar v-if="view === 'calendar'" ref="calendarRef" @create="openCreate" @view="openView" @edit="openEdit" @delete="handleDelete" />
    <template v-else>
    <section class="rsv-card">
      <!-- 篩選 -->
      <div class="rsv-filters">
        <label class="rsv-field">
          <span class="rsv-label">關鍵字</span>
          <el-input v-model="filters.keyword" placeholder="預約單號 / 受訪人 / 來訪者姓名" clearable class="rsv-keyword" @keyup.enter="handleSearch">
            <template #prefix><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></template>
          </el-input>
        </label>
        <!-- 「待審核」頁籤的狀態固定是待審核、「今日預約」頁籤的日期固定是今天，所以隱藏對應的篩選。 -->
        <label v-if="view === 'list'" class="rsv-field">
          <span class="rsv-label">預約狀態</span>
          <el-select v-model="filters.status" placeholder="全部" clearable class="rsv-status">
            <el-option v-for="s in statusOptions" :key="s.code" :label="s.label" :value="s.code" />
          </el-select>
        </label>
        <label v-if="view !== 'today'" class="rsv-field">
          <span class="rsv-label">預約日期</span>
          <el-date-picker v-model="filters.range" type="daterange" single-panel value-format="YYYY-MM-DD" format="YYYY/MM/DD" range-separator="–" start-placeholder="開始日期" end-placeholder="結束日期" class="rsv-range" />
        </label>
        <div class="rsv-buttons">
          <el-button type="primary" @click="handleSearch">
            <i class="fa-solid fa-magnifying-glass rsv-page__icon" aria-hidden="true"></i>查詢
          </el-button>
          <el-button @click="handleClear">
            <i class="fa-solid fa-rotate rsv-page__icon" aria-hidden="true"></i>清除
          </el-button>
        </div>
      </div>
    </section>
    <section class="rsv-card">
      <div class="rsv-head">
        <h3 class="rsv-title">{{ listTitle }} <span class="rsv-count">（共 {{ total }} 筆）</span></h3>
        <el-button type="primary" @click="openCreate()">
          <i class="fa-solid fa-plus rsv-page__icon" aria-hidden="true"></i>新增預約
        </el-button>
      </div>
      <LoadErrorAlert v-if="loadError" class="rsv-page__notice" :message="loadError" @retry="loadRows" />
      <el-table v-loading="loading" :data="rows" border class="rsv-table" empty-text="沒有符合條件的預約" :default-sort="tableSort" @row-click="openView" @sort-change="handleSortChange">
        <!-- 預約單號、預約日期可排序(sortable="custom"，實際排序見 loadRows)。一次只有一欄在排序，兩欄都只在新到舊／舊到新之間切換；
             預設是「預約日期 新到舊」＝後端本來的順序，想回到預設就點預約日期。 -->
        <el-table-column prop="reservation_id" label="預約單號" min-width="160" sortable="custom" :sort-orders="['descending', 'ascending']" />
        <el-table-column label="預約日期" prop="visit_date" width="130" sortable="custom" :sort-orders="['descending', 'ascending']">
          <template #default="{ row }">{{ formatDate(row.visit_date) }}</template>
        </el-table-column>
        <el-table-column label="時段" width="130">
          <template #default="{ row }">{{ row.visit_start_time }} – {{ row.visit_end_time }}</template>
        </el-table-column>
        <el-table-column label="受訪公司" min-width="170">
          <template #default="{ row }">{{ row.company_name || '—' }}</template>
        </el-table-column>
        <el-table-column prop="host_name" label="受訪人" width="100" />
        <el-table-column label="來訪者" min-width="120">
          <template #default="{ row }">{{ visitorSummary(row) }}</template>
        </el-table-column>
        <el-table-column label="人數" width="80" align="center">
          <template #default="{ row }">{{ row.guest_count }} 人</template>
        </el-table-column>
        <el-table-column label="事由" min-width="110">
          <template #default="{ row }">{{ reasonLabel(row.visit_reason) }}</template>
        </el-table-column>
        <el-table-column label="狀態" width="110" align="center">
          <template #default="{ row }">
            <el-tag :type="statusOf(row).type">{{ statusOf(row).label }}</el-tag>
          </template>
        </el-table-column>
      </el-table>
      <div class="rsv-pager">
        <div class="rsv-pager-size">
          <span>每頁顯示</span>
          <el-select v-model="perPage" class="rsv-size" @change="handlePerPageChange">
            <el-option v-for="n in [10, 20, 50]" :key="n" :label="`${n} 筆`" :value="n" />
          </el-select>
        </div>
        <el-pagination v-model:current-page="page" :page-size="perPage" :total="total" layout="prev, pager, next" background @current-change="loadRows" />
      </div>
    </section>
    </template>
    <ReservationDialog v-model="dialogVisible" :reservation="editing" :default-date="createDate" @saved="refreshAfterChange" />
    <ReservationViewDialog v-model="viewVisible" :reservation-id="viewingId" />
  </div>
</template>
<script setup>
/* *****************************************
File: ReservationList.vue
Author: Callum
Date: 2026-11-19
Description: 預約管理頁：頁籤(預約列表、待審核、今日預約、行事曆檢視)、篩選、列表、新增／檢視／編輯預約。皆串接真 API(/api/guest/applies)。
***************************************** */
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import LoadErrorAlert from './components/LoadErrorAlert.vue';
import ReservationCalendar from './components/ReservationCalendar.vue';
import ReservationDialog from './components/ReservationDialog.vue';
import ReservationViewDialog from './components/ReservationViewDialog.vue';
import { deleteReservation, fetchPendingCount, fetchReservationDetail, fetchReservations } from '../../api/guest';
import { ElMessage, ElMessageBox } from 'element-plus';
import { PENDING_STATUSES, RESERVATION_STATUS } from './constants';
import { formatDate, todayString } from './format';
import { pendingApprovals } from '../../stores/pendingApprovals';
import { useVisitReasons } from './useVisitReasons';
const { reasonLabel } = useVisitReasons();
// 頁籤順序與圖示照設計稿：預約列表、新增預約、待審核、今日預約、行事曆檢視。
// key 是檢視名稱。list／pending／today 共用下面的表格，只是預設條件不同；calendar 是行事曆；
// create 不是檢視，點了是開新增預約對話框（見 selectTab）。
const TABS = [
  { key: 'list', label: '預約列表', icon: 'fa-regular fa-calendar-days' },
  { key: 'create', label: '新增預約', icon: 'fa-regular fa-calendar-plus' },
  { key: 'pending', label: '待審核', icon: 'fa-regular fa-calendar-check' },
  { key: 'today', label: '今日預約', icon: 'fa-regular fa-calendar' },
  { key: 'calendar', label: '行事曆檢視', icon: 'fa-regular fa-calendar-days' },
];
const LIST_TITLES = { list: '預約列表', pending: '待審核預約', today: '今日預約' };
const view = ref('list');
const listTitle = computed(() => LIST_TITLES[view.value] || LIST_TITLES.list);
const calendarRef = ref(null);
// 新增／編輯共用同一個對話框：editing 有值＝編輯該筆，null＝新增。
const dialogVisible = ref(false);
const editing = ref(null);
const createDate = ref('');
const viewVisible = ref(false);
const viewingId = ref(null);
// 篩選列分成兩份(做法同訪客審核頁)：filters 是畫面上看得到的值(草稿)，按「查詢」「清除」或切換頁籤才會套用；
// applied 是目前列表實際套用的條件，換頁、改每頁筆數、排序都用 applied，
// 才不會出現「改了篩選沒按查詢，換頁卻用了新條件」，跟畫面上的筆數對不上。
const filters = reactive({ keyword: '', status: '', range: null });
const applied = reactive({ keyword: '', status: '', range: null });
function applyFilters(){
  Object.assign(applied, { ...filters, range: filters.range ? [...filters.range] : null });
}
const page = ref(1);
const perPage = ref(10);
// 排序：sortBy 'visit'(預約日期＋時段，預設)或 'reservation_id'(預約單號)；sortOrder 'desc' 新到舊(預設)或 'asc' 舊到新。
const sortBy = ref('visit');
const sortOrder = ref('desc');
// 表頭的排序箭頭。切到「行事曆檢視」時表格會被移除，切回來是重新建立的表格，
// Element Plus 只在建立時讀一次 default-sort；寫死成「預約日期 新到舊」的話，
// 先前選過別的排序，箭頭就會跟實際資料的順序對不上，所以跟著目前的排序走。
const tableSort = computed(() => ({
  prop: sortBy.value === 'reservation_id' ? 'reservation_id' : 'visit_date',
  order: sortOrder.value === 'asc' ? 'ascending' : 'descending',
}));
// 「預約日期 新到舊」就是後端本來的順序：不帶排序參數，照舊由後端分頁(不用把全部抓回來)；其他排序才交給前端。
function sortParams(){
  if(sortBy.value === 'visit' && sortOrder.value === 'desc'){
    return {};
  }
  return{ sort_by: sortBy.value, sort_order: sortOrder.value };
}
const total = ref(0);
const rows = ref([]);
const loading = ref(false);
const loadError = ref('');
// 「待審核」頁籤旁的紅色數字；載入失敗或 0 筆時不顯示。
const pendingCount = ref(0);
// 狀態下拉：列出所有狀態（「已拒絕」後端 2026-11-25 `2e9d019` 起可以查，加回來了）。
const statusOptions = computed(() =>
  Object.entries(RESERVATION_STATUS).map(([code, s]) => ({ code: Number(code), label: s.label })),
);
// 後端若新增了字典沒有的狀態碼，不要讓整個表格因為讀不到而壞掉。
function statusOf(row){
  return RESERVATION_STATUS[row.reservation_status] || { label: `狀態 ${row.reservation_status}`, type: 'info' };
}
// 來訪者欄：只有一位顯示姓名，多位顯示「第一位姓名 等 N 人」。
function visitorSummary(row){
  const guests = row.guests || [];
  if (guests.length === 0) return '—';
  if (guests.length === 1) return guests[0].name;

  return `${guests[0].name} 等 ${guests.length} 人`;
}
// 連續切換（頁籤、換頁、排序）時，比較慢的舊回應不能蓋掉新的：只採用最後一次請求的結果（做法同行事曆）。
let rowsSeq = 0;
async function loadRows(){
  const seq = ++rowsSeq;
  loading.value = true;
  loadError.value = '';
  // 「待審核」頁籤固定查待審核（0、1），「今日預約」頁籤固定查今天；其餘條件照篩選列。
  const today = todayString();
  const result = await fetchReservations({
    keyword: applied.keyword.trim(),
    status: view.value === 'pending' ? PENDING_STATUSES : applied.status,
    date_from: view.value === 'today' ? today : applied.range?.[0],
    date_to: view.value === 'today' ? today : applied.range?.[1],
    page: page.value,
    per_page: perPage.value,
    ...sortParams(),
  });
  if(seq !== rowsSeq)return;
  loading.value = false;
  if(!result.ok){
    loadError.value = `預約列表載入失敗：${result.message}`;
    return;
  }
  rows.value = result.data.items || [];
  total.value = result.data.total ?? rows.value.length;
}
// 查詢：套用篩選列目前的條件、回到第 1 頁。
function handleSearch(){
  applyFilters();
  page.value = 1;
  loadRows();
}
function handleClear(){
  Object.assign(filters, { keyword: '', status: '', range: null });
  handleSearch();
}
function handlePerPageChange(){
  page.value = 1;
  loadRows();
}
// 點「預約單號」或「預約日期」表頭的排序箭頭：換排序回到第 1 頁。
function handleSortChange({ prop, order }){
  sortBy.value = prop === 'reservation_id' ? 'reservation_id' : 'visit';
  sortOrder.value = order === 'ascending' ? 'asc' : 'desc';
  page.value = 1;
  loadRows();
}
// 切換頁籤：篩選列的草稿保留(關鍵字、日期)，只是換預設條件；換到列表類頁籤才重新查。
function selectTab(key){
  // 「新增預約」不切換檢視，只開對話框；目前的頁籤維持不變。
  if(key === 'create'){
    openCreate();
    return;
  }
  if(view.value === key)return;
  view.value = key;
  if(key !== 'calendar'){
    handleSearch();
  }
}
// 新增預約(date 是行事曆選取的日期，列表按鈕不帶)。
function openCreate(date = ''){
  editing.value = null;
  createDate.value = typeof date === 'string' ? date : '';
  dialogVisible.value = true;
}
function openView(item){
  viewingId.value = item.id;
  viewVisible.value = true;
}
// 編輯要用詳情資料(列表／行事曆的資料沒有備註與通知設定)，先取詳情再開對話框。
async function openEdit(item){
  const result = await fetchReservationDetail(item.id);
  if (!result.ok){
    ElMessage.error(`預約詳情載入失敗：${result.message}`);
    return;
  }
  editing.value = result.data;
  dialogVisible.value = true;
}
async function loadPendingCount(){
  const result = await fetchPendingCount();
  pendingCount.value = result.ok ? result.data : 0;
  // 順便同步鈴鐺與側邊欄的數字（同一個數字，不用多查一次)。
  if(result.ok){
    pendingApprovals.value = result.data;
  }
}
// 刪除：先確認；成功後重新載入目前這個檢視與待審核數字。
async function handleDelete(item){
  try{
    await ElMessageBox.confirm(`確定要刪除預約單 ${item.reservation_id}（${formatDate(item.visit_date)} ${item.visit_start_time}，${item.company_name || '—'}）嗎？`, '刪除確認', { type: 'warning' });
  }catch{
    return;
  }
  const result = await deleteReservation(item.id);
  if (!result.ok){
    ElMessage.error(`刪除失敗：${result.message}`);
    return;
  }
  ElMessage.success('預約已刪除');
  refreshAfterChange();
}
// 新增、編輯、刪除成功後：重新載入目前這個檢視（沿用已套用的篩選，回到第 1 頁），並更新待審核數字。
// （編輯、刪除只在行事曆裡，行事曆只重新載入自己；列表會走到下面的只有「新增預約」，新增後回第 1 頁。）
async function refreshAfterChange(){
  loadPendingCount();
  if (view.value === 'calendar'){
    await nextTick();
    calendarRef.value?.reload();
    return;
  }
  page.value = 1;
  loadRows();
}
onMounted(() => {
  loadRows();
  loadPendingCount();
});
</script>
<style scoped>
.rsv-page__notice{
  margin-bottom: 14px;
}
/* 點整列就能看詳情，滑過時顯示手指游標。 */
.rsv-table :deep(.el-table__row){
  cursor: pointer;
}
.rsv-page__icon{
  margin-right: 6px;
}
.rsv-tabs{
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 16px;
}
.rsv-tab{
  display: inline-flex;
  gap: 8px;
  align-items: center;
  height: 44px;
  padding: 0 22px;
  font-size: 15px;
  color: #374151;
  cursor: pointer;
  background: #fff;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  transition: all var(--app-transition-duration) var(--app-transition-timing);
}
.rsv-tab:hover{
  color: var(--el-color-primary);
  border-color: var(--el-color-primary);
}
.rsv-tab__badge{
  min-width: 20px;
  height: 20px;
  padding: 0 6px;
  font-size: 12px;
  font-weight: 600;
  line-height: 20px;
  color: #fff;
  text-align: center;
  background: #e5484d;
  border-radius: 10px;
}
.rsv-tab--active,.rsv-tab--active:hover{
  color: #fff;
  background: var(--el-color-primary);
  border-color: var(--el-color-primary);
}
.rsv-card{
  padding: 16px;
  margin-bottom: 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.rsv-filters{
  display: flex;
  flex-wrap: wrap;
  gap: 12px 18px;
  align-items: center;
}
.rsv-field{
  display: inline-flex;
  gap: 8px;
  align-items: center;
}
.rsv-label{
  font-size: 14px;
  font-weight: 600;
  color: #374151;
}
.rsv-keyword{
  width: 260px;
}
.rsv-status{
  width: 130px;
}
.rsv-range{
  width: 280px;
}
.rsv-buttons{
  display: flex;
  gap: 8px;
}
.rsv-head{
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.rsv-title{
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 0;
  font-size: 17px;
  font-weight: 600;
  color: #1f2937;
}
.rsv-title::before{
  width: 4px;
  height: 18px;
  content: '';
  background: var(--el-color-primary);
  border-radius: 2px;
}
.rsv-count{
  font-size: 14px;
  font-weight: 400;
  color: #6b7280;
}
.rsv-pager{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-top: 14px;
  font-size: 14px;
  color: #374151;
}
.rsv-pager-size{
  display: inline-flex;
  gap: 8px;
  align-items: center;
}
.rsv-size{
  width: 100px;
}
</style>
