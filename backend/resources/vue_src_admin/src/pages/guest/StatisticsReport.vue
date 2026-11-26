<template>
  <div class="rpt">
    <!-- 報表頁籤(設計稿只有「訪客統計總覽」；趨勢、事由分析 2026-11-26 依現有 API 自行規劃) -->
    <div class="rpt__tabs" role="group" aria-label="報表類型">
      <button v-for="tab in tabs" :key="tab.key" type="button" class="rpt__tab" :class="{ 'rpt__tab--active': activeTab === tab.key }" :aria-pressed="activeTab === tab.key" @click="activeTab = tab.key">
        <i :class="tab.icon" aria-hidden="true"></i>{{ tab.label }}
      </button>
    </div>
    <el-empty v-if="currentTab.pending" class="rpt__empty" :description="currentTab.pending" />
    <template v-else>
      <!-- 篩選（已完成的報表共用同一組條件） -->
      <section class="rpt__filters">
        <label class="rpt__field">
          <span class="rpt__label">統計期間</span>
          <el-date-picker v-model="filters.range" type="daterange" single-panel :clearable="false" value-format="YYYY-MM-DD" format="YYYY/MM/DD" range-separator="–" start-placeholder="開始日期" end-placeholder="結束日期" class="rpt__range" />
        </label>
        <label class="rpt__field">
          <span class="rpt__label">樓層</span>
          <el-select v-model="filters.floor" placeholder="全部樓層" clearable class="rpt__select">
            <el-option v-for="f in floorOptions" :key="f" :label="`${f}F`" :value="f" />
          </el-select>
        </label>
        <label class="rpt__field">
          <span class="rpt__label">公司</span>
          <el-select v-model="filters.doorplate_id" placeholder="全部公司" clearable filterable class="rpt__company">
            <el-option v-for="c in companyOptions" :key="c.id" :label="c.label" :value="c.id" />
          </el-select>
        </label>
        <!-- 「來訪事由分析」本身就在比較各事由，不篩事由。 -->
        <label v-if="activeTab !== 'reason'" class="rpt__field">
          <span class="rpt__label">來訪事由</span>
          <el-select v-model="filters.visit_reason" placeholder="全部" clearable class="rpt__select">
            <el-option v-for="r in reasonOptions" :key="r.value" :label="r.label" :value="r.value" />
          </el-select>
        </label>
        <el-button type="primary" @click="handleSearch">
          <i class="fa-solid fa-magnifying-glass rpt__icon" aria-hidden="true"></i>查詢
        </el-button>
      </section>
      <p class="rpt__source">
        <i class="fa-solid fa-circle-info rpt__icon" aria-hidden="true"></i>統計資料每天凌晨彙整到前一天，不含今天；只計算「已到」與「已離場」的預約。<template v-if="activeTab !== 'overview'">「較上期同期」是跟上期相同天數的比較（本期只到昨天，就只比上期的前幾天）。</template>
      </p>
      <LoadErrorAlert v-if="optionsError" class="rpt__notice" :message="optionsError" @retry="loadFilterOptions" />
      <TrendAnalysis v-if="activeTab === 'trend'" :params="reportParams" :version="queryVersion" />
      <ReasonAnalysis v-else-if="activeTab === 'reason'" :params="reportParams" :version="queryVersion" />
      <template v-else>
        <LoadErrorAlert v-if="loadError" class="rpt__notice" :message="loadError" @retry="reload" />
        <!-- 統計卡片 -->
        <div class="rpt__stats">
          <StatCard icon="fa-solid fa-users" label="來訪總人次" :value="fmt(summary.total)" :trend="summary.total_trend" note="較上期" tone="blue" />
          <StatCard icon="fa-regular fa-calendar-days" label="預約來訪" :value="fmt(summary.reserved)" :trend="summary.reserved_trend" :note="`占 ${share(summary.reserved)}%`" tone="blue" />
          <StatCard icon="fa-solid fa-person-walking" label="臨時來訪" :value="fmt(summary.walk_in)" :trend="summary.walk_in_trend" :note="`占 ${share(summary.walk_in)}%`" tone="blue" />
          <StatCard icon="fa-regular fa-building" label="來訪公司數" :value="fmt(summary.companies)" :trend="summary.companies_trend" note="較上期" tone="blue" />
          <StatCard icon="fa-regular fa-id-card" label="發證數(臨時卡)" :value="fmt(summary.badges)" :trend="summary.badges_trend" note="較上期" tone="blue" />
        </div>
        <!-- 圖表 -->
        <div v-loading="loadingOverview" class="rpt__charts">
          <section class="rpt__card rpt__card--trend">
            <h3 class="rpt__title">每日來訪人次趨勢</h3>
            <TrendChart :points="trend" />
          </section>
          <section class="rpt__card">
            <h3 class="rpt__title">來訪事由分布</h3>
            <ReasonDonut :items="reasons" />
          </section>
          <section class="rpt__card">
            <h3 class="rpt__title">樓層來訪分布 (Top 5)</h3>
            <FloorBars :items="floors" :total="summary.total" />
          </section>
        </div>
        <!-- 明細 -->
        <section class="rpt__card rpt__detail">
          <div class="rpt__detail-head">
            <h3 class="rpt__title">訪客統計明細</h3>
            <div class="rpt__actions">
              <el-button :loading="exporting === 'excel'" @click="handleExport('excel')">
                <i class="fa-regular fa-file-excel rpt__icon rpt__icon--green" aria-hidden="true"></i>匯出 Excel
              </el-button>
              <el-button :loading="exporting === 'pdf'" @click="handleExport('pdf')">
                <i class="fa-regular fa-file-pdf rpt__icon rpt__icon--red" aria-hidden="true"></i>匯出 PDF
              </el-button>            </div>
          </div>
          <el-table v-loading="loadingDaily" :data="daily" empty-text="沒有資料">
            <el-table-column label="日期" min-width="150">
              <template #default="{ row }">{{ formatDateWithWeekday(row.date) }}</template>
            </el-table-column>
            <el-table-column prop="total" label="來訪總人次" min-width="110" align="center" />
            <el-table-column prop="reserved" label="預約人次" min-width="100" align="center" />
            <el-table-column prop="walk_in" label="臨時人次" min-width="100" align="center" />
            <el-table-column prop="companies" label="來訪公司數" min-width="110" align="center" />
            <el-table-column prop="top_reason" label="最主要來訪事由" min-width="140" />
            <el-table-column label="備註" min-width="90" align="center">
              <template #default="{ row }">{{ row.remark || '—' }}</template>
            </el-table-column>
          </el-table>
          <div class="rpt__pager">
            <span class="rpt__pager-info">共 {{ dailyTotal }} 筆資料</span>
            <div class="rpt__pager-right">
              <span>每頁顯示</span>
              <el-select v-model="perPage" class="rpt__size" @change="handlePerPageChange">
                <el-option v-for="n in [10, 20, 50]" :key="n" :label="n" :value="n" />
              </el-select>
              <el-pagination v-model:current-page="page" :page-size="perPage" :total="dailyTotal" layout="prev, pager, next" background @current-change="loadDaily" />
            </div>
          </div>
        </section>
      </template>
    </template>
  </div>
</template>
<script setup>
/* *****************************************
File: StatisticsReport.vue
Author: Callum
Date: 2026-11-19
Description: 統計報表頁(訪客統計總覽：統計卡片、趨勢折線、事由圓環、樓層分布、每日明細；
2026-11-26 起另有「來訪趨勢分析」「來訪事由分析」，見 report/ 資料夾)。
***************************************** */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { exportReportDaily, fetchDoorplates, fetchReportDaily, fetchReportOverview } from '../../api/guest';
import FloorBars from './components/FloorBars.vue';
import LoadErrorAlert from './components/LoadErrorAlert.vue';
import ReasonDonut from './components/ReasonDonut.vue';
import StatCard from './components/StatCard.vue';
import TrendChart from './components/TrendChart.vue';
import { saveBlob } from './download';
import ReasonAnalysis from './report/ReasonAnalysis.vue';
import TrendAnalysis from './report/TrendAnalysis.vue';
import { useVisitReasons } from './useVisitReasons';
import { formatDateWithWeekday } from './format';
const { reasonOptions } = useVisitReasons();
// 設計稿有六種報表，只有第一種有畫面。趨勢、事由分析用現有的統計 API 就能做（2026-11-26 自行規劃畫面）；
// 其餘還沒做的頁籤用 pending 說明原因，點了顯示這段文字。
const tabs = [
  { key: 'overview', label: '訪客統計總覽', icon: 'fa-solid fa-chart-column' },
  { key: 'trend', label: '來訪趨勢分析', icon: 'fa-solid fa-chart-line' },
  { key: 'floor', label: '樓層/公司分析', icon: 'fa-regular fa-building', pending: '樓層/公司分析尚未提供：需要後端提供「全部樓層」與「各公司排行」的統計 API' },
  { key: 'reason', label: '來訪事由分析', icon: 'fa-solid fa-tag' },
  { key: 'booking', label: '預約統計', icon: 'fa-regular fa-calendar-days', pending: '預約統計尚未提供' },
  { key: 'custom', label: '自訂報表', icon: 'fa-solid fa-gear', pending: '自訂報表尚未提供：需要後端支援自選欄位、儲存報表設定與匯出' },
];
const pad = (n) => String(n).padStart(2, '0');
// 預設統計期間：本月 1 日到本月最後一天。
function currentMonthRange(){
  const now = new Date();
  const last = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate();
  const ym = `${now.getFullYear()}-${pad(now.getMonth() + 1)}`;
  return [`${ym}-01`, `${ym}-${pad(last)}`];
}
const activeTab = ref('overview');
const currentTab = computed(() => tabs.find((t) => t.key === activeTab.value) || tabs[0]);
// 篩選列分成兩份(做法同訪客審核頁)：filters 是畫面上看得到的值(草稿)，按「查詢」才會套用；
// applied 是目前卡片、圖表、明細實際套用的條件，明細換頁、改每頁筆數、匯出都用 applied，
// 才不會出現「改了統計期間沒按查詢，換頁或匯出卻用了新期間」，跟上面的卡片、圖表對不上。
function copyFilters(source){
  return { ...source, range: source.range ? [...source.range] : null };
}
const filters = reactive({ range: currentMonthRange(), floor: '', doorplate_id: '', visit_reason: '' });
const applied = reactive(copyFilters(filters));
const floorOptions = ref([]);
const companyOptions = ref([]);
const summary = reactive({ total: 0, reserved: 0, walk_in: 0, companies: 0, badges: 0, total_trend: null, reserved_trend: null, walk_in_trend: null, companies_trend: null, badges_trend: null });
const trend = ref([]);
const reasons = ref([]);
const floors = ref([]);
const daily = ref([]);
const dailyTotal = ref(0);
const page = ref(1);
const perPage = ref(10);
const loadingOverview = ref(false);
const loadingDaily = ref(false);
const loadError = ref('');
// 樓層、公司下拉選項載入失敗的提示(與統計資料的 loadError 分開，重試只重載選項，不會重跑統計)。
const optionsError = ref('');
const fmt = (n) => Number(n || 0).toLocaleString();
// 占總人次的百分比(一位小數)。
function share(count){
  return summary.total === 0 ? '0.0' : ((count / summary.total) * 100).toFixed(1);
}
// 目前套用的條件 → API 參數(總覽、明細、匯出共用)。
function queryParams(){
  return{
    date_from: applied.range?.[0],
    date_to: applied.range?.[1],
    floor: applied.floor,
    doorplate_id: applied.doorplate_id,
    visit_reason: applied.visit_reason,
  };
}
// 給「來訪趨勢分析」「來訪事由分析」的條件：只在按「查詢」套用時才換成新的物件；
// queryVersion 每按一次查詢加 1，條件完全沒變也會重新載入（等同重新整理資料）。
const reportParams = computed(() => queryParams());
const queryVersion = ref(0);
// 在其他頁籤按了查詢，總覽的資料就過期了：切回總覽時再重新載入。
let overviewStale = false;
watch(activeTab, (tab) => {
  if(tab === 'overview' && overviewStale){
    overviewStale = false;
    reload();
  }
});
// 連續查詢、換頁時，比較慢的舊回應不能蓋掉新的：只採用最後一次請求的結果(做法同行事曆)。
let overviewSeq = 0;
let dailySeq = 0;
async function loadOverview(){
  const seq = ++overviewSeq;
  loadingOverview.value = true;
  const result = await fetchReportOverview(queryParams());
  if(seq !== overviewSeq) return;
  loadingOverview.value = false;
  if(!result.ok){
    loadError.value = `統計資料載入失敗：${result.message}`;
    return;
  }
  Object.assign(summary, result.data.summary);
  trend.value = result.data.trend || [];
  reasons.value = result.data.reasons || [];
  floors.value = result.data.floors || [];
}
async function loadDaily(){
  const seq = ++dailySeq;
  loadingDaily.value = true;
  const result = await fetchReportDaily({ ...queryParams(), page: page.value, per_page: perPage.value });
  if(seq !== dailySeq) return;
  loadingDaily.value = false;
  if(!result.ok){
    loadError.value = `統計明細載入失敗：${result.message}`;
    return;
  }
  daily.value = result.data.items || [];
  dailyTotal.value = result.data.total ?? daily.value.length;
}
// 用目前套用的條件重新載入總覽與明細(「重試」用，不會套用還沒按查詢的草稿)。
function reload(){
  loadError.value = '';
  loadOverview();
  loadDaily();
}
// 查詢：套用篩選列目前的條件、明細回到第 1 頁。總覽只在顯示中才載入；趨勢、事由分析由 queryVersion 觸發各自載入。
function handleSearch(){
  Object.assign(applied, copyFilters(filters));
  page.value = 1;
  queryVersion.value += 1;
  if(activeTab.value === 'overview'){
    reload();
  }else{
    overviewStale = true;
  }
}
function handlePerPageChange(){
  page.value = 1;
  loadDaily();
}
// 樓層與公司的下拉選項來自基本資料設定的門牌 API(這支已經是真的)。
async function loadFilterOptions(){
  optionsError.value = '';
  const result = await fetchDoorplates();
  if (!result.ok) {
    optionsError.value = `樓層、公司選項載入失敗：${result.message}`;
    return;
  }
  const items = result.data.items || [];
  floorOptions.value = [...new Set(items.map((d) => d.floor))].sort((a, b) => a - b);
  companyOptions.value = items
    .filter((d) => d.company_name)
    .map((d) => ({ id: d.id, label: d.company_name }));
}
// 匯出符合目前篩選條件的統計明細(不分頁，下載檔案由後端產生)。
const exporting = ref('');
async function handleExport(kind){
  exporting.value = kind;
  const result = await exportReportDaily(kind, queryParams());
  exporting.value = '';
  if(!result.ok){
    ElMessage.error(result.message);
    return;
  }
  saveBlob(result.data.blob, result.data.filename);
}
onMounted(() => {
  loadFilterOptions();
  handleSearch();
});
</script>
<style scoped>
.rpt__notice{
  margin-bottom: 14px;
}
.rpt__tabs{
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.rpt__tab{
  padding: 9px 16px;
  font: inherit;
  font-size: 14px;
  color: #374151;
  cursor: pointer;
  background: #fff;
  border: 1px solid #dcdfe6;
  border-radius: 6px;
}
.rpt__tab i{
  margin-right: 8px;
}
.rpt__tab:hover{
  color: var(--el-color-primary);
  border-color: var(--el-color-primary);
}
.rpt__tab--active,
.rpt__tab--active:hover{
  color: #fff;
  background: var(--el-color-primary);
  border-color: var(--el-color-primary);
}
.rpt__empty{
  padding: 48px 0;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.rpt__filters{
  display: flex;
  flex-wrap: wrap;
  gap: 12px 18px;
  align-items: center;
  margin-bottom: 14px;
}
.rpt__field{
  display: inline-flex;
  gap: 8px;
  align-items: center;
}
.rpt__label{
  font-size: 14px;
  font-weight: 600;
  color: #374151;
}
.rpt__range{
  width: 290px;
}
.rpt__select{
  width: 130px;
}
.rpt__company{
  width: 170px;
}
.rpt__icon{
  margin-right: 6px;
}
.rpt__source{
  margin: 0 0 14px;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
.rpt__icon--green{
  color: #16a34a;
}
.rpt__icon--red{
  color: #e5484d;
}
.rpt__stats{
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.rpt__charts{
  display: grid;
  grid-template-columns: minmax(0, 1.5fr) minmax(0, 1.1fr) minmax(0, 1fr);
  gap: 14px;
  margin-bottom: 16px;
}
.rpt__card{
  min-width: 0;
  padding: 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.rpt__title{
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 0 0 12px;
  font-size: 16px;
  font-weight: 600;
  color: #1f2937;
}
/* 標題左側的藍色直條，對應設計稿。 */
.rpt__title::before{
  width: 4px;
  height: 16px;
  content: '';
  background: var(--el-color-primary);
  border-radius: 2px;
}
.rpt__detail-head{
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.rpt__detail-head .rpt__title{
  margin: 0;
}
.rpt__actions{
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.rpt__pager{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-top: 14px;
  font-size: 14px;
  color: #374151;
}
.rpt__pager-right{
  display: inline-flex;
  gap: 8px;
  align-items: center;
}
.rpt__size{
  width: 80px;
}
@media(max-width: 1400px){
  .rpt__charts{
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }
  .rpt__card--trend{
    grid-column: 1 / -1;
  }
  .rpt__stats{
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
