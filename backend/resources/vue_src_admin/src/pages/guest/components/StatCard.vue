<template>
  <button type="button" class="stat" :class="[`stat--${tone}`, { 'stat--active': active, 'stat--static': !clickable, 'stat--plain': plain }]" @click="handleClick">
    <span class="stat__icon">
      <LineIcon v-if="lineIcon" :name="lineIcon" />
      <i v-else :class="icon" aria-hidden="true"></i>
    </span>
    <span class="stat__body">
      <span class="stat__label">{{ label }}</span>
      <span class="stat__row" :class="{ 'stat__row--compare': compareNote }">
        <span class="stat__value">{{ value }}<small v-if="unit" class="stat__unit">{{ unit }}</small></span>
        <span v-if="trend !== null" class="stat__trend" :class="trendClass">
          <i v-if="trendIcon" :class="trendIcon" aria-hidden="true"></i>{{ trend > 0 ? '+' : '' }}{{ trend }}%
        </span>
        <span v-else-if="compareNote" class="stat__trend stat__trend--flat">—</span>
        <!-- 對照排列：第二行左邊是 note、右邊是 compareNote。 -->
        <template v-if="compareNote">
          <span class="stat__note">{{ note }}</span>
          <span class="stat__note">{{ compareNote }}</span>
        </template>
      </span>
      <span v-if="note && !compareNote" class="stat__note">{{ note }}</span>
    </span>
  </button>
</template>
<script setup>
/* *****************************************
File: StatCard.vue
Author: Callum
Date: 2026-11-19
Description: 統計數字卡片（圖示＋標題＋數值），訪客審核、訪客記錄、統計報表共用。
***************************************** */
import { computed } from 'vue';
import LineIcon from './LineIcon.vue';
const props = defineProps({
  // Font Awesome 的 class，例如 'fa-regular fa-calendar-days'。有給 lineIcon 時可省略。
  icon: { type: String, default: '' },
  // LineIcon 的圖示名稱，用來取代 Font Awesome 圖示。
  lineIcon: { type: String, default: '' },
  // 圖示不加圓形底色、對齊標題（統計報表總覽的樣式）。
  plain: { type: Boolean, default: false },
  label: { type: String, required: true },
  value: { type: [Number, String], default: 0 },
  unit: { type: String, default: '' },
  // 與上一期相比的增減百分比，例如 20 或 -5；null 表示不顯示。
  trend: { type: Number, default: null },
  // 數值下方的小字說明，例如「較上期」或「占 67.5%」。
  note: { type: String, default: '' },
  // 有給就改用「對照排列」：增減百分比在數值右側、這段文字在百分比下方；trend 為 null 時百分比顯示「—」。
  compareNote: { type: String, default: '' },
  // 圖示底色：blue、green、red、purple。
  tone: { type: String, default: 'blue' },
  active: { type: Boolean, default: false },
  // 可點擊（例如點卡片切換頁籤）；不可點擊的卡片不顯示手指游標。
  clickable: { type: Boolean, default: false },
});
const emit = defineEmits(['click']);
// 對照排列用三角形、持平(0%)用灰色不加箭頭；其他頁面維持原本的箭頭樣式。
const flat = computed(() => Boolean(props.compareNote) && props.trend === 0);
const trendClass = computed(() => {
  if(flat.value) return 'stat__trend--flat';
  return props.trend >= 0 ? 'stat__trend--up' : 'stat__trend--down';
});
const trendIcon = computed(() => {
  if(flat.value) return '';
  const shape = props.compareNote ? 'caret' : 'arrow';
  return `fa-solid fa-${shape}-${props.trend >= 0 ? 'up' : 'down'}`;
});
function handleClick(){
  if(props.clickable) emit('click');
}
</script>
<style scoped>
.stat{
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 18px;
  font: inherit;
  text-align: left;
  cursor: pointer;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.stat--static{
  cursor: default;
}
.stat:not(.stat--static):hover{
  border-color: var(--el-color-primary);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.stat--active{
  border-color: var(--el-color-primary);
  box-shadow: 0 0 0 1px var(--el-color-primary);
}
.stat__icon{
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  font-size: 20px;
  border-radius: 50%;
}
.stat--blue .stat__icon{
  color: #1d6bff;
  background: #e8f0ff;
}
.stat--green .stat__icon{
  color: #16a34a;
  background: #dcf5e4;
}
.stat--red .stat__icon{
  color: #e5484d;
  background: #fde8e8;
}
.stat--purple .stat__icon{
  color: #7c5cff;
  background: #eeeaff;
}
.stat--plain{
  align-items: flex-start;
}
.stat--plain .stat__icon{
  width: 36px;
  height: 36px;
  font-size: 24px;
  background: transparent;
}
.stat__body{
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.stat__label{
  font-size: 14px;
  color: #4b5563;
}
.stat__row{
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
  align-items: baseline;
}
.stat__note{
  font-size: 12px;
  color: #9ca3af;
}
.stat__trend{
  font-size: 13px;
  font-weight: 600;
}
.stat__trend i{
  margin-right: 2px;
  font-size: 11px;
}
.stat__trend--up{
  color: #16a34a;
}
.stat__trend--down{
  color: #e5484d;
}
.stat__trend--flat{
  color: #9ca3af;
}
.stat__row--compare{
  display: grid;
  grid-template-columns: auto auto;
  gap: 2px 18px;
  justify-content: start;
  align-items: end;
}
.stat__row--compare .stat__trend{
  padding-bottom: 2px;
  white-space: nowrap;
}
.stat__value{
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
  color: #111827;
}
.stat__unit{
  margin-left: 4px;
  font-size: 14px;
  font-weight: 500;
  color: #4b5563;
}
</style>
