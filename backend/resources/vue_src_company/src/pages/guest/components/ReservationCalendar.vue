<template>
  <div class="cal">
    <section class="cal__main">
      <!-- 工具列：前後切換、今天、月份選擇、日／週／月 -->
      <div class="cal__toolbar">
        <div class="cal__nav">
          <button type="button" class="cal__btn cal__btn--icon" aria-label="上一個" @click="go(-1)">
            <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
          </button>
          <button type="button" class="cal__btn cal__btn--today" @click="goToday">今天</button>
          <button type="button" class="cal__btn cal__btn--icon" aria-label="下一個" @click="go(1)">
            <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
          </button>
        </div>
        <el-date-picker :model-value="cursor" type="month" value-format="YYYY-MM-DD" format="YYYY年M月" :clearable="false" class="cal__month" @update:model-value="pickMonth" />
        <div class="cal__views" role="group" aria-label="檢視方式">
          <button v-for="v in VIEWS" :key="v.key" type="button" class="cal__view" :class="{ 'cal__view--active': view === v.key }" @click="changeView(v.key)">{{ v.label }}</button>
        </div>
      </div>
      <LoadErrorAlert v-if="loadError" class="cal__alert" :message="loadError" @retry="load" />
      <!-- 月／週：七欄格子；日：只有一欄 -->
      <div v-loading="loading" class="cal__grid" :class="`cal__grid--${view}`">
        <template v-if="view !== 'day'">
          <div v-for="(name, i) in WEEKDAY_NAMES" :key="name" class="cal__weekday" :class="{ 'cal__weekday--weekend': i === 0 || i === 6 }">{{ name }}</div>
        </template>
        <button
          v-for="day in days"
          :key="day"
          type="button"
          class="cal__cell"
          :class="{
            'cal__cell--outside': view === 'month' && !isSameMonth(day),
            'cal__cell--selected': day === selected,
            'cal__cell--weekend': isWeekend(day),
          }"
          @click="selectDay(day)"
        >
          <span class="cal__date" :class="{ 'cal__date--today': day === today }">{{ view === 'day' ? dayTitle(day) : Number(day.slice(8)) }}</span>
          <span v-for="item in visibleItems(day)" :key="item.id" class="cal__chip" :class="`cal__chip--${groupKeyOf(item.reservation_status)}`">
            <span class="cal__chip-dot" aria-hidden="true"></span>
            <span class="cal__chip-text">{{ chipText(item) }}</span>
          </span>
          <span v-if="hiddenCount(day) > 0" class="cal__more">+{{ hiddenCount(day) }}</span>
        </button>
      </div>
    </section>
    <aside class="cal__side">
      <!-- 圖例 -->
      <div class="cal__legend">
        <span v-for="g in CALENDAR_GROUPS" :key="g.key" class="cal__legend-item" :class="`cal__chip--${g.key}`">
          <span class="cal__chip-dot" aria-hidden="true"></span>{{ g.label }}
        </span>
      </div>
      <!-- 選取日期的預約清單 -->
      <div class="cal__day">
        <div class="cal__day-head">
          <i class="fa-regular fa-calendar-days cal__day-icon" aria-hidden="true"></i>
          <div>
            <div class="cal__day-title">{{ selectedTitle }}</div>
            <div class="cal__day-count">共 {{ dayItems.length }} 筆預約</div>
          </div>
        </div>
        <el-button type="primary" class="cal__create" @click="emit('create', selected)">
          <i class="fa-solid fa-plus cal__icon" aria-hidden="true"></i>新增預約
        </el-button>
        <p v-if="!loading && dayItems.length === 0" class="cal__empty">這一天沒有預約</p>
        <ul class="cal__list">
          <li v-for="item in dayItems" :key="item.id" class="cal__card" :class="`cal__card--${groupKeyOf(item.reservation_status)}`">
            <div class="cal__card-top">
              <span class="cal__card-time">{{ item.visit_start_time }} – {{ item.visit_end_time }}</span>
              <span class="cal__tag" :class="`cal__tag--${groupKeyOf(item.reservation_status)}`">
                <i :class="groupOf(item.reservation_status).icon" aria-hidden="true"></i>{{ statusLabel(item.reservation_status) }}
              </span>
            </div>
            <div class="cal__card-line"><i class="fa-regular fa-building" aria-hidden="true"></i>{{ item.company_name || '—' }}</div>
            <div class="cal__card-line"><i class="fa-regular fa-user" aria-hidden="true"></i>{{ item.host_name }}（{{ item.guest_count }} 人）</div>
            <div class="cal__card-line cal__card-bottom">
              <span class="cal__card-reason"><i class="fa-regular fa-calendar-check" aria-hidden="true"></i>{{ reasonLabel(item.visit_reason) }}</span>
              <span class="cal__actions">
                <el-tooltip content="檢視" placement="top">
                  <button type="button" class="cal__action" aria-label="檢視" @click="emit('view', item)"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                </el-tooltip>
                <el-tooltip :content="canEdit(item) ? '編輯' : '此狀態不能編輯'" placement="top">
                  <span>
                    <button type="button" class="cal__action" aria-label="編輯" :disabled="!canEdit(item)" @click="emit('edit', item)"><i class="fa-regular fa-pen-to-square" aria-hidden="true"></i></button>
                  </span>
                </el-tooltip>
                <el-tooltip :content="canCancel(item) ? '取消預約' : '此狀態不能取消'" placement="top">
                  <span>
                    <button type="button" class="cal__action cal__action--danger" aria-label="取消預約" :disabled="!canCancel(item)" @click="emit('cancel', item)"><i class="fa-solid fa-ban" aria-hidden="true"></i></button>
                  </span>
                </el-tooltip>
              </span>
            </div>
          </li>
        </ul>
      </div>
    </aside>
  </div>
</template>
<script setup>
/* *****************************************
File: ReservationCalendar.vue
Author: Callum
Date: 2026-11-20
Description: 預約管理的「行事曆檢視」：月／週／日格子加右側當日預約清單，資料用 GET /api/guest/applies 依日期區間查詢。
***************************************** */
import { computed, onMounted, ref, watch } from 'vue';
import { fetchReservations } from '../../../api/guest';
import { defaultSelected, groupByDate, groupKeyOf, monthStart, shiftCursor, viewDays, viewRange } from '../calendarUtils';
import { CALENDAR_GROUPS, EDITABLE_STATUSES, RESERVATION_STATUS } from '../constants';
import { todayString } from '../format';
import LoadErrorAlert from './LoadErrorAlert.vue';
import { useVisitReasons } from '../useVisitReasons';

const { reasonLabel } = useVisitReasons();
const emit = defineEmits(['create', 'view', 'edit', 'cancel']);
const VIEWS = [
  { key: 'day', label: '日' },
  { key: 'week', label: '週' },
  { key: 'month', label: '月' },
];
const WEEKDAY_NAMES = ['週日', '週一', '週二', '週三', '週四', '週五', '週六'];
// 月檢視每格最多顯示幾筆，其餘以「+N」表示；週、日檢視不限（格子夠高）。
const MONTH_CELL_LIMIT = 2;
const today = todayString();
const view = ref('month');
const cursor = ref(today);
const selected = ref(today);
const items = ref([]);
const loading = ref(false);
const loadError = ref('');
const days = computed(() => viewDays(view.value, cursor.value));
const byDate = computed(() => groupByDate(items.value));
const dayItems = computed(() => byDate.value[selected.value] || []);
const selectedTitle = computed(() => {
  const d = new Date(`${selected.value}T00:00:00`);
  return `${String(d.getMonth() + 1).padStart(2, '0')}月${String(d.getDate()).padStart(2, '0')}日 (${WEEKDAY_NAMES[d.getDay()]})`;
});
const isSameMonth = (day) => day.slice(0, 7) === cursor.value.slice(0, 7);
const isWeekend = (day) => [0, 6].includes(new Date(`${day}T00:00:00`).getDay());
const dayTitle = (day) => `${Number(day.slice(5, 7))}月${Number(day.slice(8))}日（${WEEKDAY_NAMES[new Date(`${day}T00:00:00`).getDay()]}）`;
const groupOf = (code) => CALENDAR_GROUPS.find((g) => g.key === groupKeyOf(code));
const statusLabel = (code) => RESERVATION_STATUS[code]?.label || `狀態 ${code}`;
const chipText = (item) => `${item.visit_start_time} ${item.company_name || item.host_name}`;
// 可編輯：狀態在可修改清單內，且尚未報到。
const canEdit = (item) => EDITABLE_STATUSES.includes(item.reservation_status) && !item.check_in_at;
// 公司端口沒有刪除權限，改成取消：後端只允許可修改的狀態（待審核、核准）且尚未報到的預約取消。
const canCancel = (item) => EDITABLE_STATUSES.includes(item.reservation_status) && !item.check_in_at;
function visibleItems(day){
  const list = byDate.value[day] || [];
  return view.value === 'month' ? list.slice(0, MONTH_CELL_LIMIT) : list;
}
function hiddenCount(day){
  if (view.value !== 'month') return 0;
  return Math.max((byDate.value[day]?.length || 0) - MONTH_CELL_LIMIT, 0);
}
// 連續切換時舊的回應晚到，不能蓋掉新的：只採用最後一次請求的結果。
let requestSeq = 0;
async function load(){
  const seq = ++requestSeq;
  const [date_from, date_to] = viewRange(view.value, cursor.value);
  loading.value = true;
  loadError.value = '';
  const result = await fetchReservations({ date_from, date_to, per_page: -1 });
  if(seq !== requestSeq)return;
  loading.value = false;
  if(!result.ok){
    items.value = [];
    loadError.value = `行事曆載入失敗：${result.message}`;
    return;
  }
  items.value = result.data.items || [];
}
function go(direction){
  cursor.value = shiftCursor(view.value, cursor.value, direction);
  selected.value = defaultSelected(view.value, cursor.value);
}
function goToday(){
  cursor.value = today;
  selected.value = today;
}
function pickMonth(value){
  cursor.value = monthStart(value);
  selected.value = defaultSelected(view.value, cursor.value);
}
function changeView(key){
  view.value = key;
  // 切換檢視不換日期：以目前選取的日期為準。
  cursor.value = selected.value;
}
function selectDay(day){
  selected.value = day;
  // 日檢視點的就是當天；其他檢視保持目前的顯示區間。
  if (view.value === 'day') cursor.value = day;
}
// 讓外部（新增、編輯完成後）重新載入目前區間。
defineExpose({ reload: load, selectedDate: selected });
watch([view, cursor], load);
onMounted(load);
</script>
<style scoped>
.cal{
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 16px;
  align-items: start;
}
.cal__main,.cal__side{
  padding: 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.cal__icon{
  margin-right: 6px;
}
/* ───── 工具列 ───── */
.cal__toolbar{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.cal__nav,.cal__views{
  display: inline-flex;
  gap: 8px;
}
.cal__btn{
  height: 38px;
  padding: 0 14px;
  font-size: 14px;
  color: #374151;
  cursor: pointer;
  background: #fff;
  border: 1px solid #d1d5db;
  border-radius: 6px;
  transition: all var(--app-transition-duration) var(--app-transition-timing);
}
.cal__btn:hover{
  color: var(--el-color-primary);
  border-color: var(--el-color-primary);
}
.cal__btn--icon{
  width: 42px;
  padding: 0;
}
.cal__btn--today{
  min-width: 92px;
}
/* 月份選擇：只留文字與日曆圖示，外框拿掉，貼近設計稿。 */
.cal__month.el-date-editor{
  --el-date-editor-width: 200px;
}
.cal__month :deep(.el-input__wrapper){
  background: transparent;
  box-shadow: none;
}
.cal__month :deep(.el-input__inner){
  font-size: 22px;
  font-weight: 700;
  color: #1f2937;
  text-align: center;
}
.cal__month :deep(.el-input__prefix){
  font-size: 20px;
  color: var(--el-color-primary);
}
.cal__views{
  gap: 0;
  overflow: hidden;
  border: 1px solid #d1d5db;
  border-radius: 6px;
}
.cal__view{
  width: 54px;
  height: 36px;
  font-size: 14px;
  color: #374151;
  cursor: pointer;
  background: #fff;
  border: 0;
  border-right: 1px solid #d1d5db;
  transition: all var(--app-transition-duration) var(--app-transition-timing);
}
.cal__view:last-child{
  border-right: 0;
}
.cal__view--active{
  color: #fff;
  background: var(--el-color-primary);
}
.cal__alert{
  margin-bottom: 12px;
}
/* ───── 格子 ───── */
.cal__grid{
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  overflow: hidden;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
}
.cal__grid--day{
  grid-template-columns: minmax(0, 1fr);
}
.cal__weekday{
  padding: 10px 0;
  font-size: 14px;
  font-weight: 600;
  color: #374151;
  text-align: center;
  background: #f3f5f9;
  border-bottom: 1px solid #e5e7eb;
}
.cal__weekday--weekend{
  color: #e5483d;
}
.cal__cell{
  display: flex;
  flex-direction: column;
  gap: 4px;
  align-items: stretch;
  min-height: 118px;
  padding: 8px 6px;
  font: inherit;
  text-align: left;
  cursor: pointer;
  background: #fff;
  border: 0;
  border-right: 1px solid #eef0f4;
  border-bottom: 1px solid #eef0f4;
  transition: background var(--app-transition-duration) var(--app-transition-timing);
}
.cal__grid--week .cal__cell{
  min-height: 320px;
}
.cal__grid--day .cal__cell{
  min-height: 420px;
}
.cal__cell:hover{
  background: #f7f9fd;
}
.cal__cell--selected{
  position: relative;
  background: color-mix(in srgb, var(--el-color-primary) 6%, white);
  outline: 2px solid var(--el-color-primary);
  outline-offset: -2px;
  border-radius: 6px;
}
.cal__cell--outside .cal__date{
  color: #c4c9d4;
}
.cal__date{
  align-self: flex-start;
  min-width: 22px;
  padding: 0 4px;
  font-size: 15px;
  font-weight: 600;
  color: #1f2937;
}
.cal__cell--weekend .cal__date{
  color: #e5483d;
}
.cal__cell--outside.cal__cell--weekend .cal__date{
  color: #f0b6b1;
}
.cal__grid--day .cal__date{
  font-size: 16px;
}
/* 今天：日期數字加主色底。 */
.cal__date--today{
  color: #fff !important;
  background: var(--el-color-primary);
  border-radius: 11px;
}
/* ───── 預約小標籤（格子裡）與圖例共用的顏色 ───── */
.cal__chip{
  display: flex;
  gap: 5px;
  align-items: center;
  padding: 2px 6px;
  overflow: hidden;
  font-size: 12px;
  font-weight: 500;
  white-space: nowrap;
  border-radius: 5px;
}
.cal__chip-dot{
  flex-shrink: 0;
  width: 7px;
  height: 7px;
  background: currentcolor;
  border-radius: 50%;
}
.cal__chip-text{
  overflow: hidden;
  color: #1f2937;
  text-overflow: ellipsis;
}

.cal__more{
  align-self: flex-end;
  padding-right: 4px;
  font-size: 12px;
  color: #6b7280;
}
.cal__chip--approved{
  color: #1f9d55;
  background: #e6f6ec;
}
.cal__chip--pending{
  color: #f08c00;
  background: #fff1dc;
}
.cal__chip--notified{
  color: #1d6bff;
  background: #e4edff;
}
.cal__chip--done{
  color: #6b7280;
  background: #eceef2;
}
.cal__chip--cancelled{
  color: #e5483d;
  background: #fde8e6;
}
/* ───── 右側 ───── */
.cal__side{
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 14px;
  background: #f8f9fc;
}
.cal__legend{
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 8px 10px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.cal__legend-item{
  display: inline-flex;
  gap: 6px;
  align-items: center;
  padding: 3px 10px;
  font-size: 12px;
  font-weight: 500;
  color: #1f2a44;
  border-radius: 12px;
}
/* 底色沿用 .cal__chip--狀態 的淡色；圓點用各狀態的主色。 */
.cal__legend-item .cal__chip-dot{
  width: 8px;
  height: 8px;
}
.cal__chip--approved .cal__chip-dot{
  color: #1f9d55;
}
.cal__chip--pending .cal__chip-dot{
  color: #f08c00;
}
.cal__chip--notified .cal__chip-dot{
  color: #1d6bff;
}
.cal__chip--done .cal__chip-dot{
  color: #6b7280;
}
.cal__chip--cancelled .cal__chip-dot{
  color: #e5483d;
}
.cal__day{
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 14px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.cal__day-head{
  display: flex;
  gap: 12px;
  align-items: center;
}
.cal__day-icon{
  font-size: 30px;
  color: var(--el-color-primary);
}
.cal__day-title{
  font-size: 18px;
  font-weight: 700;
  color: #1f2937;
}
.cal__day-count{
  margin-top: 2px;
  font-size: 13px;
  color: #6b7280;
}
.cal__create{
  width: 100%;
  height: 42px;
  font-size: 15px;
}
.cal__empty{
  margin: 8px 0;
  font-size: 14px;
  color: #6b7280;
  text-align: center;
}
.cal__list{
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 0;
  margin: 0;
  list-style: none;
}
.cal__card{
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 10px 12px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-left-width: 4px;
  border-radius: 6px;
}
.cal__card--approved{
  border-left-color: #1f9d55;
}
.cal__card--pending{
  border-left-color: #f08c00;
}
.cal__card--notified{
  border-left-color: #1d6bff;
}
.cal__card--done{
  border-left-color: #9ca3af;
}
.cal__card--cancelled{
  border-left-color: #e5483d;
}
.cal__card-top{
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cal__card-time{
  font-size: 16px;
  font-weight: 600;
  color: #1f2a44;
}
.cal__tag{
  display: inline-flex;
  gap: 4px;
  align-items: center;
  padding: 2px 10px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 12px;
}
.cal__tag--approved{
  color: #1f9d55;
  background: #e6f6ec;
}
.cal__tag--pending{
  color: #f08c00;
  background: #fff1dc;
}
.cal__tag--notified{
  color: #1d6bff;
  background: #e4edff;
}
.cal__tag--done{
  color: #6b7280;
  background: #eceef2;
}
.cal__tag--cancelled{
  color: #e5483d;
  background: #fde8e6;
}
.cal__card-line{
  display: flex;
  gap: 8px;
  align-items: center;
  font-size: 14px;
  color: #1f2a44;
}
.cal__card-line i{
  width: 16px;
  color: #3f5183;
  text-align: center;
}
.cal__card-bottom{
  justify-content: space-between;
}
.cal__card-reason{
  display: inline-flex;
  gap: 8px;
  align-items: center;
}
.cal__actions{
  display: inline-flex;
  gap: 6px;
}
.cal__action{
  width: 36px;
  height: 32px;
  color: #3f5183;
  cursor: pointer;
  background: #fff;
  border: 1px solid #dce4f0;
  border-radius: 6px;
  transition: all var(--app-transition-duration) var(--app-transition-timing);
}
.cal__action i{
  width: auto;
  color: inherit;
}
.cal__action:hover:not(:disabled){
  color: var(--el-color-primary);
  border-color: var(--el-color-primary);
}
.cal__action--danger:hover:not(:disabled){
  color: #e5484d;
  border-color: #e5484d;
}
.cal__action:disabled{
  color: #c4c9d4;
  cursor: not-allowed;
  background: #f5f6f8;
}
@media (max-width: 1280px){
  .cal {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
