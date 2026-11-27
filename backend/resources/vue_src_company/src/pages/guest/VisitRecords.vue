<template>
  <div class="vrc">
    <!-- 統計卡片 -->
    <div class="vrc__stats">
      <StatCard icon="fa-solid fa-users" label="今日訪客" :value="summary.today" unit="人" :trend="summary.today_trend" tone="blue" clickable :active="quickRange === 'today'" @click="toggleQuickRange('today')" />
      <StatCard icon="fa-regular fa-calendar-days" label="本週訪客" :value="summary.week" unit="人" :trend="summary.week_trend" tone="blue" clickable :active="quickRange === 'week'" @click="toggleQuickRange('week')" />
      <StatCard icon="fa-regular fa-building" label="本月訪客" :value="summary.month" unit="人" :trend="summary.month_trend" tone="blue" clickable :active="quickRange === 'month'" @click="toggleQuickRange('month')" />
      <StatCard icon="fa-solid fa-clipboard-check" label="完成訪客" :value="summary.completed" unit="人" tone="blue" clickable :active="applied.status === COMPLETED_STATUS" @click="toggleCompleted" />
    </div>
    <!-- 篩選 -->
    <section class="vrc__card vrc__filters">
      <div class="vrc__filter-row">
        <label class="vrc__field">
          <span class="vrc__label">日期區間</span>
          <el-date-picker v-model="filters.range" type="daterange" single-panel value-format="YYYY-MM-DD" format="YYYY/MM/DD" range-separator="–" start-placeholder="開始日期" end-placeholder="結束日期" class="vrc__range" />
        </label>
        <label class="vrc__field">
          <span class="vrc__label">訪客姓名</span>
          <el-input v-model="filters.guest_name" placeholder="請輸入姓名" clearable class="vrc__input" @keyup.enter="handleSearch" />
        </label>
        <label class="vrc__field">
          <span class="vrc__label">公司名稱</span>
          <el-input v-model="filters.company_name" placeholder="請輸入公司名稱" clearable class="vrc__input" @keyup.enter="handleSearch" />
        </label>
        <label class="vrc__field">
          <span class="vrc__label">來訪事由</span>
          <el-select v-model="filters.visit_reason" placeholder="全部" clearable class="vrc__select">
            <el-option v-for="r in reasonOptions" :key="r.value" :label="r.label" :value="r.value" />
          </el-select>
        </label>
        <label class="vrc__field">
          <span class="vrc__label">狀態</span>
          <el-select v-model="filters.status" placeholder="全部" clearable class="vrc__select">
            <el-option v-for="(s, code) in RECORD_STATUS" :key="code" :label="s.label" :value="Number(code)" />
          </el-select>
        </label>
        <div class="vrc__buttons">
          <el-button type="primary" @click="handleSearch">
            <i class="fa-solid fa-magnifying-glass vrc__icon" aria-hidden="true"></i>查詢
          </el-button>
          <el-button @click="handleClear">
            <i class="fa-solid fa-rotate vrc__icon" aria-hidden="true"></i>清除
          </el-button>
        </div>
      </div>
      <div v-if="showMore" class="vrc__filter-row vrc__filter-row--more">
        <label class="vrc__field">
          <span class="vrc__label">手機號碼</span>
          <el-input v-model="filters.phone" placeholder="請輸入手機號碼" clearable class="vrc__input" @keyup.enter="handleSearch" />
        </label>
        <label class="vrc__field">
          <span class="vrc__label">車牌號碼</span>
          <el-input v-model="filters.plate_number" placeholder="請輸入車牌號碼" clearable class="vrc__input" @keyup.enter="handleSearch" />
        </label>
      </div>
      <button type="button" class="vrc__more" :aria-expanded="showMore" @click="showMore = !showMore">
        {{ showMore ? '收合篩選條件' : '更多篩選條件' }}
        <i :class="showMore ? 'fa-solid fa-angle-up' : 'fa-solid fa-angle-down'" aria-hidden="true"></i>
      </button>
    </section>
    <div class="vrc__layout">
      <section class="vrc__card vrc__main">
        <div class="vrc__head">
          <h3 class="vrc__title">訪客記錄清單 <span class="vrc__count">(共 {{ total }} 筆)</span></h3>
          <div class="vrc__actions">
            <el-button :loading="exporting === 'excel'" :disabled="Boolean(exporting)" @click="handleExport('excel')">
              <i class="fa-regular fa-file-excel vrc__icon vrc__icon--green" aria-hidden="true"></i>匯出 Excel
            </el-button>
            <el-button :loading="exporting === 'pdf'" :disabled="Boolean(exporting)" @click="handleExport('pdf')">
              <i class="fa-regular fa-file-pdf vrc__icon vrc__icon--red" aria-hidden="true"></i>匯出 PDF
            </el-button>
            <el-button @click="reloadAll">
              <i class="fa-solid fa-rotate vrc__icon" aria-hidden="true"></i>重新整理
            </el-button>
          </div>
        </div>
        <LoadErrorAlert v-if="loadError" class="vrc__notice" :message="loadError" @retry="reloadAll" />
        <el-table v-loading="loading" :data="rows" row-key="id" highlight-current-row :current-row-key="currentId" empty-text="沒有符合條件的訪客記錄" :default-sort="{ prop: 'visit_at', order: 'descending' }" @current-change="handleCurrentChange" @sort-change="handleSortChange">
          <el-table-column type="index" label="序號" width="64" :index="indexMethod" />
          <!-- 預設新到舊(後端本來的順序)；只在新到舊／舊到新之間切換，排序由 api/guest.js 的 fetchVisitRecords 處理。 -->
          <el-table-column label="來訪時間" prop="visit_at" width="160" sortable="custom" :sort-orders="['descending', 'ascending']">
            <template #default="{ row }">{{ formatDate(row.visit_date) }} {{ row.visit_start_time }}</template>
          </el-table-column>
          <el-table-column label="訪客姓名" width="100">
            <template #default="{ row }">{{ row.guests?.[0]?.name || '—' }}</template>
          </el-table-column>
          <el-table-column label="公司名稱" min-width="170">
            <template #default="{ row }">{{ row.guests?.[0]?.company_name || '—' }}</template>
          </el-table-column>
          <el-table-column label="來訪事由" min-width="110">
            <template #default="{ row }">{{ reasonLabel(row.visit_reason) }}</template>
          </el-table-column>
          <el-table-column label="接待樓層" width="90" align="center">
            <template #default="{ row }">{{ row.floor ? `${row.floor}F` : '—' }}</template>
          </el-table-column>
          <el-table-column label="進場時間" width="90" align="center">
            <template #default="{ row }">{{ formatClock(row.check_in_at) || '—' }}</template>
          </el-table-column>
          <el-table-column label="離場時間" width="90" align="center">
            <template #default="{ row }">{{ formatClock(row.check_out_at) || '–' }}</template>
          </el-table-column>
          <el-table-column label="狀態" width="100" align="center">
            <template #default="{ row }">
              <el-tag :type="statusOf(row).type">{{ statusOf(row).label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="96" align="center">
            <template #default="{ row }">
              <el-button link type="primary" aria-label="查看詳細資料" @click="currentId = row.id">
                <i class="fa-regular fa-eye" aria-hidden="true"></i>
              </el-button>
              <el-button link type="primary" aria-label="列印訪客單" title="列印訪客單" :loading="printingId === row.id" @click.stop="handlePrint(row)">
                <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
              </el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="vrc__pager">
          <div class="vrc__pager-size">
            <span>每頁顯示</span>
            <el-select v-model="perPage" class="vrc__size" @change="handlePerPageChange">
              <el-option v-for="n in [10, 20, 50]" :key="n" :label="`${n} 筆`" :value="n" />
            </el-select>
          </div>
          <span class="vrc__pager-info">第 {{ page }} / {{ pageCount }} 頁，共 {{ total }} 筆</span>
          <el-pagination v-model:current-page="page" :page-size="perPage" :total="total" layout="prev, pager, next" background @current-change="loadList" />
        </div>
      </section>
      <RecordDetail :row="detailRow" @close="currentId = null" @print="handlePrint(currentRow)" @edit="handleEdit" />
    </div>
    <VisitorSlips :record="printRecord" @done="printRecord = null" />
  </div>
</template>
<script setup>
/* *****************************************
File: VisitRecords.vue
Author: Callum
Date: 2026-11-19
Description: 訪客記錄頁(統計卡片、篩選、已進場訪客列表與詳細資料)。
***************************************** */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { exportVisitRecords, fetchVisitRecordDetail, fetchVisitRecords, fetchVisitSummary } from '../../api/guest';
import LoadErrorAlert from './components/LoadErrorAlert.vue';
import RecordDetail from './components/RecordDetail.vue';
import StatCard from './components/StatCard.vue';
import VisitorSlips from './components/VisitorSlips.vue';
import { RECORD_STATUS } from './constants';
import { confirmExportLimit, saveBlob } from './download';
import { formatClock, formatDate, monthRange, mondayWeekRange, todayString } from './format';
import { useVisitReasons } from './useVisitReasons';
const { reasonLabel, reasonOptions } = useVisitReasons();
// 完成訪客＝已離場(50)，後端的累計數也是這樣算。
const COMPLETED_STATUS = 50;
// 統計卡片的日期區間，與後端算法一致(週一到週日)，點卡片查出來的預約才對得上卡片的數字。
const QUICK_RANGES = {
  today: () => [todayString(), todayString()],
  week: () => mondayWeekRange(),
  month: () => monthRange(),
};
function emptyFilters(){
  return { range: null, guest_name: '', company_name: '', visit_reason: '', status: '', phone: '', plate_number: '' };
}
// 篩選列分成兩份(做法同訪客審核頁)：filters 是畫面上看得到的值(草稿)，按「查詢」、點卡片、按「清除」才會套用；
// applied 是目前列表實際套用的條件，換頁、改每頁筆數、排序、匯出都用 applied，
// 才不會出現「改了篩選沒按查詢，換頁或匯出卻用了新條件」，跟畫面上的筆數、內容對不上。
const filters = reactive(emptyFilters());
const applied = reactive(emptyFilters());
function copyFilters(source){
  return { ...source, range: source.range ? [...source.range] : null };
}
const showMore = ref(false);
const page = ref(1);
const perPage = ref(10);
const sortOrder = ref('desc'); // 來訪時間：'desc' 新到舊(預設)、'asc' 舊到新
const total = ref(0);
const rows = ref([]);
const currentId = ref(null);
const detail = ref(null);
const exporting = ref('');
const loading = ref(false);
const loadError = ref('');
const summary = reactive({ today: 0, week: 0, month: 0, completed: 0, today_trend: null, week_trend: null, month_trend: null });
// 目前套用的日期區間剛好是今天／本週／本月時，對應的卡片顯示為選取(看已套用的條件，不看草稿)。
const quickRange = computed(() => {
  const [from, to] = applied.range || [];
  return Object.keys(QUICK_RANGES).find((key) => {
    const [quickFrom, quickTo] = QUICK_RANGES[key]();
    return from === quickFrom && to === quickTo;
  }) || null;
});
const currentRow = computed(() => rows.value.find((r) => r.id === currentId.value) || null);
// 右側面板：先用列表那一筆立刻顯示，詳情(登記時間、備註、車牌、健康聲明)回來後再補上。
const detailRow = computed(() => {
  if (!currentRow.value) return null;
  return detail.value?.id === currentRow.value.id ?{ ...currentRow.value, ...detail.value } : currentRow.value;
});
const pageCount = computed(() => Math.max(Math.ceil(total.value / perPage.value), 1));
// 後端若新增了字典沒有的狀態碼，不要讓整個表格因為讀不到而壞掉。
function statusOf(row){
  return RECORD_STATUS[row.reservation_status] || { label: `狀態 ${row.reservation_status}`, type: 'info' };
}
// 序號要跨頁連續：第 2 頁（每頁 10 筆）從 11 開始。
function indexMethod(index){
  return (page.value - 1) * perPage.value + index + 1;
}
async function loadSummary(){
  const result = await fetchVisitSummary();
  if (result.ok) Object.assign(summary, result.data);
}
// 目前套用的篩選條件 → API 參數(列表與匯出共用)。
// 後端沒有「手機號碼」「車牌號碼」個別篩選，只有 keyword(單號／受訪人／姓名／公司／手機／車牌都會比對)，
// 所以這兩格都送進 keyword；兩格同時填時只能擇一，這裡以手機優先。
function buildFilterParams(){
  return {
    status: applied.status,
    date_from: applied.range?.[0],
    date_to: applied.range?.[1],
    guest_name: applied.guest_name.trim(),
    company_name: applied.company_name.trim(),
    visit_reason: applied.visit_reason,
    keyword: applied.phone.trim() || applied.plate_number.trim(),
  };
}
// 連續換頁、排序時，比較慢的舊回應不能蓋掉新的：只採用最後一次請求的結果(做法同行事曆)。
let listSeq = 0;
async function loadList(){
  const seq = ++listSeq;
  loading.value = true;
  loadError.value = '';
  const result = await fetchVisitRecords({ ...buildFilterParams(), page: page.value, per_page: perPage.value, sort_order: sortOrder.value });
  if (seq !== listSeq) return;
  loading.value = false;
  if(!result.ok){
    loadError.value = `訪客記錄載入失敗：${result.message}`;
    return;
  }
  rows.value = result.data.items || [];        
  total.value = result.data.total ?? rows.value.length;
  // 保留原本選取的那一筆；它不在這一頁了就改選第一筆，右側詳情才不會是空的。
  if (!rows.value.some((r) => r.id === currentId.value)){
    currentId.value = rows.value[0]?.id ?? null;
  }
}
function reloadAll(){
  return Promise.all([loadSummary(), loadList()]);
}
// 查詢：套用篩選列目前的條件、回到第 1 頁。
function handleSearch(){
  if(filters.phone.trim() && filters.plate_number.trim()){
    ElMessage.warning('手機號碼與車牌號碼一次只能擇一查詢，目前以手機號碼為準');
  }
  Object.assign(applied, copyFilters(filters));
  page.value = 1;
  loadList();
}
// 日期卡片：填入或清除日期區間，立即查詢；不動其他條件。
function toggleQuickRange(key){
  filters.range = quickRange.value === key ? null : QUICK_RANGES[key]();
  handleSearch();
}
// 完成訪客：只看已離場；再點一次取消。累計數不分日期，所以選取時一併清掉日期區間。
// 卡片和其他查詢一樣，會一併套用篩選列上目前看到的條件。
function toggleCompleted(){
  const active = applied.status === COMPLETED_STATUS;
  filters.status = active ? '' : COMPLETED_STATUS;
  if (!active) filters.range = null;
  handleSearch();
}
function handleClear(){
  Object.assign(filters, emptyFilters());
  handleSearch();
}
function handlePerPageChange(){
  page.value = 1;
  loadList();
}
// 點「來訪時間」表頭的排序箭頭：換排序回到第 1 頁。
function handleSortChange({ order }){
  sortOrder.value = order === 'ascending' ? 'asc' : 'desc';
  page.value = 1;
  loadList();
}
function handleCurrentChange(row){
  if (row) currentId.value = row.id;
}
// 匯出符合目前篩選條件的全部記錄，下載檔案由後端產生；超過上限會先詢問。
async function handleExport(kind){
  if(!(await confirmExportLimit(total.value))) return;
  exporting.value = kind;
  const result = await exportVisitRecords(kind, buildFilterParams());
  exporting.value = '';
  if(!result.ok){
    ElMessage.error(result.message);
    return;
  }
  saveBlob(result.data.blob, result.data.filename);
}
// 列印訪客單：用瀏覽器列印(版面見 components/VisitorSlips.vue)。列表那一筆沒有車牌，先抓詳情；每次都重抓，印的是目前的資料。
const printRecord = ref(null);
const printingId = ref(null);
async function handlePrint(row){
  if(!row || printingId.value !== null) return;
  printingId.value = row.id;
  const result = await fetchVisitRecordDetail(row.id);
  printingId.value = null;
  if(!result.ok){
    ElMessage.error(`無法列印訪客單：${result.message}`);
    return;
  }
  if(!result.data.guests?.length){
    ElMessage.warning('這筆記錄沒有來訪者資料，無法列印訪客單');
    return;
  }
  printRecord.value = result.data;
}
// 編輯訪客記錄：後端沒有對應 API。
function handleEdit(){
  ElMessage.info('編輯訪客記錄尚未開放：後端還沒有提供修改訪客記錄的 API');
}
// 選到另一筆就抓它的詳情；回應回來時如果又換了一筆，丟掉舊的回應。
watch(currentId, async (id) => {
  detail.value = null;
  if(id === null)return;
  const result = await fetchVisitRecordDetail(id);
  if (result.ok && currentId.value === id) detail.value = result.data;
});
onMounted(reloadAll);
</script>
<style scoped>
.vrc__notice{
  margin-bottom: 14px;
}
.vrc__stats{
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.vrc__card{
  padding: 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.vrc__filters{
  margin-bottom: 16px;
}
.vrc__filter-row{
  display: flex;
  flex-wrap: wrap;
  gap: 12px 18px;
  align-items: flex-end;
}
.vrc__filter-row--more{
  margin-top: 12px;
}
.vrc__field{
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.vrc__label{
  font-size: 14px;
  font-weight: 600;
  color: #374151;
}
.vrc__range{
  width: 270px;
}
.vrc__input{
  width: 170px;
}
.vrc__select{
  width: 120px;
}
.vrc__buttons{
  display: flex;
  gap: 8px;
}
.vrc__more{
  display: block;
  margin: 10px auto 0;
  font: inherit;
  font-size: 13px;
  color: var(--el-color-primary);
  cursor: pointer;
  background: transparent;
  border: 0;
}
.vrc__more i{
  margin-left: 4px;
}
.vrc__layout{
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 16px;
  align-items: stretch;
}
.vrc__main{
  min-width: 0;
}
.vrc__head{
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.vrc__title{
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 0;
  font-size: 17px;
  font-weight: 600;
  color: #1f2937;
}
/* 標題左側的藍色直條，對應設計稿。 */
.vrc__title::before{
  width: 4px;
  height: 18px;
  content: '';
  background: var(--el-color-primary);
  border-radius: 2px;
}
.vrc__count{
  font-size: 14px;
  font-weight: 400;
  color: #6b7280;
}
.vrc__actions{
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.vrc__icon{
  margin-right: 6px;
}
.vrc__icon--green{
  color: #16a34a;
}
.vrc__icon--red{
  color: #e5484d;
}
.vrc__pager{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-top: 14px;
  font-size: 14px;
  color: #374151;
}
.vrc__pager-size{
  display: inline-flex;
  gap: 8px;
  align-items: center;
}
.vrc__size{
  width: 100px;
}
@media(max-width: 1280px){
  .vrc__layout{
    grid-template-columns: minmax(0, 1fr);
  }
  .vrc__stats{
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
