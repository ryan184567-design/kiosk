<template>
  <el-popover
    ref="popoverRef"
    trigger="click"
    placement="bottom-end"
    width="auto"
    popper-class="bell-pop"
    @before-enter="handleOpen"
    @after-leave="open = false"
  >
    <template #reference>
      <!-- 按鈕外觀沿用 AdminLayout 的 .layout__icon-btn、.layout__badge（那邊是全域樣式）。 -->
      <button type="button" class="layout__icon-btn layout__bell" :aria-label="buttonLabel" :title="buttonLabel" aria-haspopup="dialog" :aria-expanded="open">
        <i class="fa-regular fa-bell" aria-hidden="true"></i>
        <span v-if="enabled && pendingApprovals > 0" class="layout__badge">{{ badge }}</span>
      </button>
    </template>
    <div class="bell" role="dialog" aria-label="待審核預約">
      <div class="bell__head">
        <span class="bell__title">{{ enabled ? '待審核預約' : '通知' }}</span>
        <span v-if="enabled && total > 0" class="bell__count">共 {{ total }} 筆</span>
      </div>
      <div v-loading="loading" class="bell__body">
        <p v-if="!enabled" class="bell__empty">目前沒有通知</p>
        <p v-else-if="error" class="bell__error">
          <span>{{ error }}</span>
          <el-button link type="primary" @click="load">重試</el-button>
        </p>
        <p v-else-if="loaded && !items.length" class="bell__empty">
          <i class="fa-regular fa-circle-check bell__empty-icon" aria-hidden="true"></i>目前沒有待審核的預約
        </p>
        <ul v-else class="bell__list">
          <li v-for="item in items" :key="item.id">
            <button type="button" class="bell__item" @click="openItem(item)">
              <span class="bell__mark" :class="{ 'bell__mark--edit': isEdited(item) }" aria-hidden="true">
                <i :class="isEdited(item) ? 'fa-regular fa-pen-to-square' : 'fa-regular fa-hourglass-half'"></i>
              </span>
              <span class="bell__text">
                <span class="bell__line">
                  <span class="bell__name">{{ guestName(item) }}</span>
                  <span class="bell__host">→ {{ hostName(item) }}</span>
                  <el-tag v-if="isEdited(item)" size="small" type="warning" class="bell__tag">修改待審核</el-tag>
                </span>
                <span class="bell__line bell__meta">
                  <span>來訪 {{ formatVisitSlot(item) }}</span>
                  <span class="bell__ago" :title="`申請時間 ${formatDate(item.created_at)} ${formatClock(item.created_at)}`">{{ formatTimeAgo(item.created_at) }}</span>
                </span>
              </span>
            </button>
          </li>
        </ul>
      </div>
      <button v-if="enabled" type="button" class="bell__foot" @click="openAll">
        查看全部待審核<i class="fa-solid fa-arrow-right bell__foot-icon" aria-hidden="true"></i>
      </button>
    </div>
  </el-popover>
</template>
<script setup>
/* *****************************************
File: PendingApprovalsBell.vue
Author: Callum
Date: 2026-11-26
Description: 右上角的通知鈴鐺：紅色數字是待審核預約的筆數，點了展開最新幾筆待審核，再點一筆或「查看全部」前往訪客審核。
***************************************** */
// 鈴鐺的數字是「還沒處理的筆數」（審核完才會變少），不是「還沒看過的通知」：後端沒有通知資料表，也沒有已讀／未讀的 API。
// 面板每次打開才查（不跟著每分鐘的數字一起查），只顯示申請時間最新的 MAX_ITEMS 筆；面板裡不放核准／拒絕，審核要到審核頁看完整資料。
// 點一筆＝前往審核頁並選取它（網址帶 ?tab=pending&id=…，審核頁的處理見 ApprovalList 的 takeRouteRequest）。
import { computed, ref } from 'vue';
import { useRouter } from 'vue-router';
import { fetchApprovalList } from '../api/guest';
import { PENDING_STATUSES } from '../pages/guest/constants';
import { formatClock, formatDate, formatTimeAgo, formatVisitSlot } from '../pages/guest/format';
import { pendingApprovals, setPendingApprovals } from '../stores/pendingApprovals';
const props = defineProps({
  // 選單裡有沒有「訪客審核」（沒有權限或模塊未開通就沒有）；沒有時面板只顯示「目前沒有通知」。
  enabled: { type: Boolean, default: false },
  // 訪客審核頁的路徑（對應後端 module.json 的選單 path）。
  approvalsPath: { type: String, required: true },
});
const MAX_ITEMS = 5;
const EDITED_STATUS = 1; // 修改待審核
const router = useRouter();
const popoverRef = ref(null);
const open = ref(false);
const items = ref([]);
const total = ref(0);
const loading = ref(false);
const loaded = ref(false);
const error = ref('');
const badge = computed(() => (pendingApprovals.value > 99 ? '99+' : String(pendingApprovals.value)));
const buttonLabel = computed(() => {
  if(!props.enabled) return '通知';
  return pendingApprovals.value > 0 ? `${pendingApprovals.value} 筆待審核預約，點擊查看` : '待審核預約';
});
function isEdited(item){
  return item.reservation_status === EDITED_STATUS;
}
function guestName(item){
  const name = item.guests?.[0]?.name || '（未填姓名）';
  const count = item.guest_count ?? item.guests?.length ?? 1;
  return count > 1 ? `${name} 等 ${count} 人` : name;
}
function hostName(item){
  const company = item.company_name || '（未指定受訪公司）';
  return item.floor ? `${company} ${item.floor}F` : company;
}
// 連續開關面板時，只採用最後一次查詢的結果。
let loadSeq = 0;
async function load(){
  if(!props.enabled) return;
  const seq = ++loadSeq;
  loading.value = true;
  error.value = '';
  const result = await fetchApprovalList({ status: PENDING_STATUSES, page: 1, per_page: MAX_ITEMS, sort_order: 'desc' });
  if(seq !== loadSeq) return;
  loading.value = false;
  if(!result.ok){
    error.value = `待審核預約載入失敗：${result.message}`;
    return;
  }
  items.value = result.data.items || [];
  total.value = result.data.total ?? items.value.length;
  loaded.value = true;
  // 這次查到的總數就是最新的，順便更新鈴鐺與側邊欄的數字。
  setPendingApprovals(total.value);
}
function handleOpen(){
  open.value = true;
  load();
}
function goApprovals(query){
  popoverRef.value?.hide();
  router.push({ path: props.approvalsPath, query });
}
function openItem(item){
  goApprovals({ tab: 'pending', id: String(item.id) });
}
function openAll(){
  goApprovals({ tab: 'pending' });
}
</script>
<style>
/* 全域樣式(非 scoped)：面板外框 .el-popover 是 Element Plus 產生、掛在 body 底下的元素，scoped 管不到，
   所以一律用 .bell-pop／.bell 前綴限縮範圍。嚴格 CSP 不允許 inline style，這裡不用 :style；
   過渡一律用 var(--app-transition-duration)／var(--app-transition-timing)。 */
.bell-pop.el-popover.el-popper{
  padding: 0;
  overflow: hidden;
  border-radius: 10px;
}
.bell{
  display: flex;
  flex-direction: column;
  width: 360px;
  max-width: calc(100vw - 32px);
  color: #1f2937;
}
.bell__head{
  display: flex;
  gap: 8px;
  align-items: baseline;
  justify-content: space-between;
  padding: 14px 16px 10px;
  border-bottom: 1px solid #eef0f4;
}
.bell__title{
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}
.bell__count{
  font-size: 13px;
  color: #6b7280;
}
.bell__body{
  min-height: 88px;
  max-height: 400px;
  overflow-y: auto;
}
.bell__empty,
.bell__error{
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
  align-items: center;
  justify-content: center;
  margin: 0;
  padding: 32px 16px;
  font-size: 14px;
  color: #6b7280;
  text-align: center;
}
.bell__error{
  color: #b42318;
}
.bell__empty-icon{
  font-size: 16px;
  color: #16a34a;
}
.bell__list{
  padding: 0;
  margin: 0;
  list-style: none;
}
.bell__list li + li{
  border-top: 1px solid #f1f3f6;
}
.bell__item{
  display: flex;
  gap: 12px;
  align-items: flex-start;
  width: 100%;
  padding: 12px 16px;
  font: inherit;
  color: inherit;
  text-align: left;
  cursor: pointer;
  background: transparent;
  border: 0;
  transition: background-color var(--app-transition-duration) var(--app-transition-timing);
}
.bell__item:hover,
.bell__item:focus-visible{
  background: #f5f7fb;
  outline: none;
}
/* 左邊的狀態圖示：待審核用沙漏、修改待審核用編輯圖示，顏色都取警示色（跟列表的狀態標籤同一色系）。 */
.bell__mark{
  display: inline-flex;
  flex: none;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  font-size: 14px;
  color: #b45309;
  background: #fef3e2;
  border-radius: 50%;
}
.bell__mark--edit{
  color: #9a3412;
  background: #ffedd5;
}
.bell__text{
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.bell__line{
  display: flex;
  gap: 6px;
  align-items: center;
  min-width: 0;
}
.bell__name{
  flex: none;
  font-size: 14px;
  font-weight: 600;
  color: #111827;
}
.bell__host{
  overflow: hidden;
  font-size: 13px;
  color: #4b5563;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.bell__tag{
  flex: none;
  margin-left: auto;
}
.bell__meta{
  justify-content: space-between;
  font-size: 12px;
  color: #6b7280;
}
.bell__ago{
  flex: none;
}
.bell__foot{
  display: flex;
  gap: 6px;
  align-items: center;
  justify-content: center;
  padding: 12px 16px;
  font: inherit;
  font-size: 14px;
  font-weight: 600;
  color: var(--el-color-primary);
  cursor: pointer;
  background: #fff;
  border: 0;
  border-top: 1px solid #eef0f4;
  transition: background-color var(--app-transition-duration) var(--app-transition-timing);
}
.bell__foot:hover,
.bell__foot:focus-visible{
  background: #f5f7fb;
  outline: none;
}
.bell__foot-icon{
  font-size: 12px;
}
</style>
