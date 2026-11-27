<template>
  <div class="apv">
    <!-- 統計卡片分兩組、互不干擾：狀態組(前三張＝下方頁籤)、日期組(後兩張＝來訪日期)。
         再點一次已選取的卡片＝取消。規則說明見 useApprovalQuery.js。 -->
    <div class="apv__stats">
      <StatCard icon="fa-regular fa-hourglass-half" label="待審核" :value="summary.pending" tone="blue" clickable :active="activeTab === 'pending'" @click="toggleStatus('pending')" />
      <StatCard icon="fa-regular fa-circle-check" label="已核准" :value="summary.approved" tone="green" clickable :active="activeTab === 'approved'" @click="toggleStatus('approved')" />
      <StatCard icon="fa-regular fa-circle-xmark" label="已拒絕" :value="summary.rejected" tone="red" clickable :active="activeTab === 'rejected'" @click="toggleStatus('rejected')" />
      <StatCard icon="fa-regular fa-calendar-days" label="今日訪客" :value="summary.today" tone="purple" clickable :active="quickRange === 'today'" @click="toggleQuickRange('today')" />
      <StatCard icon="fa-solid fa-users" label="本週訪客" :value="summary.week" tone="blue" clickable :active="quickRange === 'week'" @click="toggleQuickRange('week')" />
    </div>
    <div class="apv__layout">
      <section class="apv__main">
        <!-- 頁籤(左)＋批次操作(右)，同一列 -->
        <div class="apv__tabbar">
          <div class="apv__tabs" role="group" aria-label="審核狀態">
            <button v-for="tab in tabs" :key="tab.key" type="button" class="apv__tab" :class="{ 'apv__tab--active': activeTab === tab.key }" :aria-pressed="activeTab === tab.key" @click="selectTab(tab.key)">
              <i :class="[tab.icon, 'apv__tab-icon', `apv__tab-icon--${tab.tone}`]" aria-hidden="true"></i>
              {{ tab.label }}<template v-if="tab.key === 'pending'"> ({{ summary.pending }})</template>
            </button>
          </div>
          <div class="apv__toolbar">
            <el-button :disabled="!selection.length" @click="handleBatch('approve')">
              <i class="fa-solid fa-check apv__icon apv__icon--green" aria-hidden="true"></i>批次核准
            </el-button>
            <el-button :disabled="!selection.length" @click="handleBatch('reject')">
              <i class="fa-solid fa-xmark apv__icon apv__icon--red" aria-hidden="true"></i>批次拒絕
            </el-button>
            <el-button :loading="exporting" @click="handleExport">
              <i class="fa-regular fa-file-excel apv__icon apv__icon--green" aria-hidden="true"></i>匯出 Excel
            </el-button>
          </div>
        </div>
        <!-- 篩選 -->
        <div class="apv__filters">
          <el-input v-model="filters.keyword" class="apv__keyword" placeholder="關鍵字：預約單號 / 受訪人 / 來訪者姓名" clearable @keyup.enter="search">
            <template #prefix><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></template>
          </el-input>
          <label class="apv__field">
            <span>申請日期</span>
            <el-date-picker v-model="filters.created_range" type="daterange" single-panel value-format="YYYY-MM-DD" format="YYYY/MM/DD" range-separator="–" start-placeholder="開始日期" end-placeholder="結束日期" class="apv__range" />
          </label>
          <label class="apv__field">
            <span>來訪日期</span>
            <el-date-picker v-model="filters.visit_range" type="daterange" single-panel value-format="YYYY-MM-DD" format="YYYY/MM/DD" range-separator="–" start-placeholder="開始日期" end-placeholder="結束日期" class="apv__range" />
          </label>
          <el-button type="primary" @click="search">
            <i class="fa-solid fa-magnifying-glass apv__icon" aria-hidden="true"></i>查詢
          </el-button>
        </div>
        <LoadErrorAlert v-if="loadError" class="apv__notice" :message="loadError" @retry="reloadAll" />
        <el-table
          v-loading="loading"
          :data="rows"
          row-key="id"
          highlight-current-row
          :current-row-key="currentId"
          empty-text="沒有符合條件的預約"
          :default-sort="{ prop: 'created_at', order: 'descending' }"
          @selection-change="selection = $event"
          @current-change="handleCurrentChange"
          @sort-change="handleSortChange"
        >
          <el-table-column type="selection" width="44" />
          <!-- 排序由前端處理(sortable="custom"：表格只負責箭頭，實際排序交給 useApprovalQuery)，只在新到舊／舊到新之間切換。 -->
          <el-table-column label="申請時間" prop="created_at" width="120" sortable="custom" :sort-orders="['descending', 'ascending']">
            <template #default="{ row }">
              <div>{{ formatDate(row.created_at) }}</div>
              <div class="apv__sub">{{ formatClock(row.created_at) }}</div>
            </template>
          </el-table-column>
          <el-table-column label="預約單號" min-width="150">
            <template #default="{ row }"><span class="apv__id">{{ row.reservation_id }}</span></template>
          </el-table-column>
          <el-table-column label="訪客姓名" width="100">
            <template #default="{ row }">{{ row.guests?.[0]?.name || '—' }}</template>
          </el-table-column>
          <!-- 公司端口：這裡是「訪客自己所屬的公司」，受訪公司一律是本公司（後端已限定），
               原本標「公司名稱」容易被誤會成別家公司的資料外漏，所以改名並補上受訪人。 -->
          <el-table-column label="訪客公司" min-width="170">
            <template #default="{ row }">{{ row.guests?.[0]?.company_name || '—' }}</template>
          </el-table-column>
          <el-table-column label="受訪人" width="110">
            <template #default="{ row }">{{ row.host_name || '—' }}</template>
          </el-table-column>
          <el-table-column label="來訪時間" min-width="140">
            <template #default="{ row }">{{ formatVisitSlot(row) }}</template>
          </el-table-column>
          <el-table-column label="來訪事由" min-width="110">
            <template #default="{ row }">{{ reasonLabel(row.visit_reason) }}</template>
          </el-table-column>
          <el-table-column label="審核狀態" width="120" align="center">
            <template #default="{ row }">
              <el-tag :type="statusOf(row).type">
                <i v-if="PENDING_STATUSES.includes(row.reservation_status)" class="fa-regular fa-hourglass-half apv__icon" aria-hidden="true"></i>{{ statusOf(row).label }}
              </el-tag>
            </template>
          </el-table-column>
        </el-table>
        <!-- 分頁 -->
        <div class="apv__pager">
          <div class="apv__pager-size">
            <span>每頁顯示</span>
            <el-select v-model="perPage" class="apv__size" @change="changePerPage">
              <el-option v-for="n in [10, 20, 50]" :key="n" :label="`${n} 筆`" :value="n" />
            </el-select>
          </div>
          <span class="apv__pager-info">第 {{ rangeFrom }} – {{ rangeTo }} 筆，共 {{ total }} 筆</span>
          <el-pagination v-model:current-page="page" :page-size="perPage" :total="total" layout="prev, pager, next" background @current-change="changePage" />
        </div>
      </section>
      <ApprovalDetail :row="detailRow" :busy="actionBusy" @back="currentId = null" @approve="handleSingle('approve')" @reject="handleSingle('reject')" />
    </div>
  </div>
</template>
<script setup>
/* *****************************************
File: ApprovalList.vue
Author: Callum
Date: 2026-11-19
Description: 訪客審核頁(統計卡片、狀態頁籤、篩選、列表、詳情與核准／拒絕)。
***************************************** */
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { refreshPendingApprovals } from '../../stores/pendingApprovals';
import { approveReservation, exportApprovals, fetchApprovalList, fetchApprovalSummary, fetchReservationDetail, rejectReservation } from '../../api/guest';
import ApprovalDetail from './components/ApprovalDetail.vue';
import LoadErrorAlert from './components/LoadErrorAlert.vue';
import StatCard from './components/StatCard.vue';
import { PENDING_STATUSES, RESERVATION_STATUS } from './constants';
import { confirmExportLimit, saveBlob } from './download';
import { formatClock, formatDate, formatVisitSlot } from './format';
import { STATUS_TABS, useApprovalQuery } from './useApprovalQuery';
import { useVisitReasons } from './useVisitReasons';
const { reasonLabel } = useVisitReasons();
const tabs = STATUS_TABS;
// 卡片、頁籤、篩選列、分頁之間的互動規則都集中在 useApprovalQuery.js(檔案開頭有完整說明)。
// filters：篩選列上的草稿（keyword、visit_range＝來訪日期、created_range＝申請日期），按查詢／點卡片／點頁籤才會套用。
const {
  filters,
  activeTab,
  page,
  perPage,
  quickRange,
  search,
  selectTab,
  toggleStatus,
  resetToTab,
  toggleQuickRange,
  changePage,
  changePerPage,
  changeSort,
  listParams,
  summaryParams,
} = useApprovalQuery({ reloadAll: () => reloadAll(), reloadList: () => loadList() });
const total = ref(0);
const rows = ref([]);
const selection = ref([]);
const currentId = ref(null);
const detail = ref(null);
const loading = ref(false);
const loadError = ref('');
const actionBusy = ref(false);
const exporting = ref(false);
const summary = reactive({ pending: 0, approved: 0, rejected: 0, today: 0, week: 0 });
const currentRow = computed(() => rows.value.find((r) => r.id === currentId.value) || null);
// 右側詳情：列表那一筆沒有職稱、Email、備註，選取後另外抓詳情補上。
// 狀態等「會因審核而變」的欄位以列表為準(核准後重新載入列表，詳情不會再抓)。
const detailRow = computed(() => {
  if(!currentRow.value) return null;
  return detail.value?.id === currentRow.value.id ? { ...detail.value, ...currentRow.value, guests: detail.value.guests } : currentRow.value;
});
const rangeFrom = computed(() => (total.value === 0 ? 0 : (page.value - 1) * perPage.value + 1));
const rangeTo = computed(() => Math.min(page.value * perPage.value, total.value));
// 後端若新增了字典沒有的狀態碼，不要讓整個表格因為讀不到而壞掉。
function statusOf(row){
  return RESERVATION_STATUS[row.reservation_status] || { label: `狀態 ${row.reservation_status}`, type: 'info' };
}
// 連續切換(頁籤、卡片、換頁、排序)時，比較慢的舊回應不能蓋掉新的：只採用最後一次請求的結果(做法同行事曆)。
let summarySeq = 0;
let listSeq = 0;
async function loadSummary(){
  const seq = ++summarySeq;
  const result = await fetchApprovalSummary(summaryParams());
  if(seq !== summarySeq)return;
  if(result.ok)Object.assign(summary, result.data);
}
async function loadList(){
  const seq = ++listSeq;
  loading.value = true;
  loadError.value = '';
  const result = await fetchApprovalList(listParams());
  if(seq !== listSeq)return;
  loading.value = false;
  if(!result.ok){
    loadError.value = `預約列表載入失敗：${result.message}`;
    return;
  }
  rows.value = result.data.items || [];
  total.value = result.data.total ?? rows.value.length;
  // 保留原本選取的那一筆；它不在這一頁了就改選第一筆，右側詳情才不會是空的。
  if(!rows.value.some((r) => r.id === currentId.value)){
    currentId.value = rows.value[0]?.id ?? null;
  }
}
// 載入完再更新鈴鐺與側邊欄的待審核數字(核准、拒絕後數字會立刻跟上；放在後面是避免跟列表的請求同時送出)。
async function reloadAll(){
  await Promise.all([loadSummary(), loadList()]);
  refreshPendingApprovals();
}
function handleCurrentChange(row){
  if (row) currentId.value = row.id;
}
// 點「申請時間」表頭的排序箭頭。
function handleSortChange({ order }){
  changeSort(order === 'ascending' ? 'asc' : 'desc');
}
// 送出審核前先查這一筆「現在」的狀態：後端不檢查原狀態，畫面是舊的（別人已經處理過、或分頁放很久）時直接送出，
// 會把已核准的單改回「修改待審核」，或把已報到的單改成拒絕。查不到目前狀態時寧可不送。
// （查詢和送出之間仍有極短的空檔，要完全避免得由後端檢查。）
async function reviewOne(row, kind){
  const latest = await fetchReservationDetail(row.id);
  if(!latest.ok)return{ ok: false, message: `無法確認目前狀態：${latest.message}` };
  if(!PENDING_STATUSES.includes(latest.data.reservation_status)){
    return{ ok: false, changed: true, status: statusOf(latest.data).label };
  }
  return kind === 'approve' ? approveReservation(row.id) : rejectReservation(row.id);
}
// 核准／拒絕的共用流程：確認 → 逐筆呼叫 → 彙整結果 → 重新載入。
// 只有待審核(0、1)的能審核；勾選裡混了其他狀態的(例如在「全部」頁籤)，確認框與結果都要講明略過幾筆，不能默默跳過。
async function review(targets, kind){
  const isApprove = kind === 'approve';
  const verb = isApprove ? '核准' : '拒絕';
  const pending = targets.filter((r) => PENDING_STATUSES.includes(r.reservation_status));
  const skipped = targets.length - pending.length;
  if(pending.length === 0){
    ElMessage.warning(targets.length > 1 ? `勾選的 ${targets.length} 筆都不是「待審核」，沒有可以${verb}的預約` : '請選擇「待審核」的預約');
    return;
  }
  const skippedNote = skipped > 0 ? `（另有 ${skipped} 筆不是「待審核」，會略過）` : '';
  try{
    await ElMessageBox.confirm(`確定要${verb}這 ${pending.length} 筆預約嗎？${skippedNote}`, `${verb}預約`, {
      confirmButtonText: verb,
      cancelButtonText: '取消',
      type: isApprove ? 'info' : 'warning',
    });
  }catch{
    return;
  }
  actionBusy.value = true;
  // 後端只有單筆 API，批次就逐筆依序呼叫(不併發)。
  const results = [];
  for(const r of pending){
    results.push(await reviewOne(r, kind));
  }
  actionBusy.value = false;
  showReviewResult(results, verb, skipped);
  await reloadAll();
}
function showReviewResult(results, verb, skipped){
  const succeeded = results.filter((r) => r.ok).length;
  const changed = results.filter((r) => r.changed);
  const failed = results.filter((r) => !r.ok && !r.changed);
  const skippedTail = skipped > 0 ? `，略過 ${skipped} 筆非待審核` : '';
  if(results.length === 1 && changed.length === 1){
    ElMessage.warning(`這筆預約的狀態已經變更（目前是「${changed[0].status}」），沒有送出${verb}，列表已重新整理${skippedTail}`);
    return;
  }
  if(succeeded === results.length){
    ElMessage.success(`已${verb} ${succeeded} 筆預約${skippedTail}`);
    return;
  }
  const parts = [`${verb}成功 ${succeeded} 筆`];
  if(changed.length) parts.push(`${changed.length} 筆狀態已經變更而沒有送出`);
  if(failed.length) parts.push(`失敗 ${failed.length} 筆`);
  const reason = failed.length ? `。失敗原因：${failed[0].message}` : '';
  ElMessage[failed.length ? 'error' : 'warning'](`${parts.join('、')}${skippedTail}${reason}`);
}
function handleSingle(kind){
  if (currentRow.value) review([currentRow.value], kind);
}
function handleBatch(kind){
  review(selection.value, kind);
}
// 匯出符合目前列表條件（狀態、關鍵字、來訪日期、申請日期）的全部資料，檔案由後端產生；超過上限會先詢問。
// 後端目前只能匯出「全部」或單一狀態（見 exportApprovals），不能匯出的頁籤會顯示原因。
async function handleExport(){
  if(!(await confirmExportLimit(total.value)))return;
  exporting.value = true;
  const result = await exportApprovals('excel', summaryParams());
  exporting.value = false;
  if(!result.ok){
    ElMessage.warning(result.message);
    return;
  }
  saveBlob(result.data.blob, result.data.filename);
}
// 選到另一筆就抓它的詳情；回應回來時如果又換了一筆，丟掉舊的回應。
watch(currentId, async (id) => {
  detail.value = null;
  if(id === null)return;
  const result = await fetchReservationDetail(id);
  if(result.ok && currentId.value === id)detail.value = result.data;
});
// ── 從右上角鈴鐺進來（見 layouts/PendingApprovalsBell.vue）──
// 網址帶 ?tab=pending（「查看全部待審核」），點某一筆時另帶 &id=預約 id：清空篩選、切到該頁籤（規則見 useApprovalQuery 第 6 點），有 id 就選取那一筆。
// 讀完就把網址參數拿掉：重新整理不會再做一次，已經在這頁時再從鈴鐺點同一筆也會重新觸發。
const route = useRoute();
const router = useRouter();
// 離開這頁時 route 會先變成下一頁的，那時候的網址參數不是給這頁的，不能處理（更不能把它清掉）。
const ownPath = route.path;
function takeRouteRequest(){
  if(route.path !== ownPath)return null;
  const { tab, id } = route.query;
  if(!tab && !id)return null;
  router.replace({ path: route.path, query: {} });
  return{ tab, id: Number(id) || null };
}
async function handleRouteRequest(request){
  await resetToTab(request.tab);
  if(request.id && !loadError.value)await focusReservation(request.id);
}
// 鈴鐺列的是最新的幾筆，切到「待審核」第 1 頁通常就看得到。看不到時（剛好又有新的申請把它擠到下一頁、排序是舊到新、或已經被別人審核掉），
// 先查它現在的狀態：已經不是待審核就說明原因；還是待審核就用預約單號當關鍵字查出來。
async function focusReservation(id){
  if(rows.value.some((r) => r.id === id)){
    currentId.value = id;
    return;
  }
  const latest = await fetchReservationDetail(id);
  if(!latest.ok){
    ElMessage.warning(`無法開啟這筆預約：${latest.message}`);
    return;
  }
  if(!PENDING_STATUSES.includes(latest.data.reservation_status)){
    ElMessage.warning(`預約 ${latest.data.reservation_id} 目前是「${statusOf(latest.data).label}」，已經不需要審核（可能已被其他人處理）`);
    return;
  }
  filters.keyword = latest.data.reservation_id;
  await search();
  if(rows.value.some((r) => r.id === id))currentId.value = id;
}
watch(() => route.query, () => {
  const request = takeRouteRequest();
  if(request)handleRouteRequest(request);
});
onMounted(() => {
  const request = takeRouteRequest();
  return request ? handleRouteRequest(request) : search();
});
</script>
<style scoped>
.apv__notice{
  margin-bottom: 14px;
}
.apv__stats{
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.apv__layout{
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 16px;
  align-items: stretch;
}
.apv__main{
  min-width: 0;
  padding: 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
/* 頁籤與批次操作同一列：頁籤靠左、按鈕靠右；寬度不夠時按鈕自動換到下一行。 */
.apv__tabbar{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.apv__tabs{
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.apv__tab{
  display: inline-flex;
  gap: 8px;
  align-items: center;
  padding: 10px 20px;
  font: inherit;
  font-size: 15px;
  font-weight: 500;
  color: #1f2a44;
  cursor: pointer;
  background: #fff;
  border: 1px solid #dcdfe6;
  border-radius: 8px;
  transition: all 300ms ease;
}
.apv__tab:hover{
  color: var(--el-color-primary);
  border-color: var(--el-color-primary);
}
.apv__tab--active,.apv__tab--active:hover{
  color: #fff;
  background: var(--el-color-primary);
  border-color: var(--el-color-primary);
}
/* 頁籤圖示：核准綠、拒絕紅、其餘跟文字同色；選取時一律白色。 */
.apv__tab-icon{
  font-size: 16px;
  transition: color 300ms ease;
}
.apv__tab-icon--green{
  color: #16a34a;
}
.apv__tab-icon--red{
  color: #e5484d;
}
.apv__tab--active .apv__tab-icon{
  color: #fff;
}
.apv__filters{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin-bottom: 12px;
}
.apv__keyword{
  width: 300px;
}
.apv__field{
  display: inline-flex;
  gap: 8px;
  align-items: center;
  font-size: 14px;
  color: #374151;
}
/* 日期區間(申請日期、來訪日期)的寬度。
   Element Plus 的寬度由 CSS 變數 --el-date-editor-daterange-width(預設 350px)決定，
   直接寫 width 會被它蓋掉，所以要改這個變數。數字想再寬或窄，改這裡的 220px 即可。 */
.apv__field :deep(.el-date-editor--daterange){
  --el-date-editor-daterange-width: 220px;
}
.apv__toolbar{
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: flex-end;
  margin-left: auto;
}
.apv__icon{
  margin-right: 6px;
}
.apv__icon--green{
  color: #16a34a;
}

.apv__icon--red{
  color: #e5484d;
}
.apv__sub{
  font-size: 13px;
  color: #6b7280;
}
.apv__id{
  color: var(--el-color-primary);
}
.apv__pager{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-top: 14px;
  font-size: 14px;
  color: #374151;
}
.apv__pager-size{
  display: inline-flex;
  gap: 8px;
  align-items: center;
}
.apv__size{
  width: 100px;
}
@media(max-width: 1280px){
  .apv__layout{
    grid-template-columns: minmax(0, 1fr);
  }
  .apv__stats{
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
