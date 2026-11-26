<template>
  <div class="rdn">
    <div class="rdn__chart">
      <canvas ref="canvasRef" role="img" :aria-label="`來訪事由分布，共 ${total} 人次`"></canvas>
    </div>
    <ul class="rdn__legend">
      <li v-for="item in slices" :key="item.value" class="rdn__item">
        <span class="rdn__dot" :class="`rdn__dot--${item.slot + 1}`"></span>
        <span class="rdn__name">{{ item.reason }}</span>
        <span class="rdn__pct">{{ percent(item.count) }}%</span>
        <span class="rdn__count">({{ item.count.toLocaleString() }})</span>
      </li>
    </ul>
  </div>
</template>
<script setup>
/* *****************************************
File: ReasonDonut.vue
Author: Callum
Date: 2026-11-19
Description: 來訪事由分布圓環圖（Chart.js），中間顯示總人次，右側為圖例清單。
***************************************** */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { CHART_PALETTE_SIZE, Chart, chartPalette } from '../../../charts/chartSetup';
import { useVisitReasons } from '../useVisitReasons';
const props = defineProps({
  // [{ reason: '業務洽談', value: 1, count: 532 }]（value 是字典數值）
  items: { type: Array, default: () => [] },
});
const { reasonOrder } = useVisitReasons();
const canvasRef = ref(null);
let chart = null;
const total = computed(() => props.items.reduce((sum, item) => sum + item.count, 0));
// 圖上的每一塊：顏色（slot）由事由在字典裡的順序決定，不是筆數排名，
// 所以同一個事由在任何期間、任何篩選下都是同一個顏色。順序仍照後端（筆數多到少）。
// 色盤只有 CHART_PALETTE_SIZE 色，不循環重用（循環會出現兩塊同色）：字典超過這個數量、
// 或有字典裡找不到的數值時，最後一色留給「其他事由（合計）」，排在後面的事由全部併進去。
const REST_LABEL = '其他事由（合計）';
const slices = computed(() => {
  const withSlot = props.items.map((item) => ({ ...item, slot: reasonOrder(item.value) }));
  const overflow = withSlot.some((s) => s.slot < 0 || s.slot >= CHART_PALETTE_SIZE);
  const limit = overflow ? CHART_PALETTE_SIZE - 1 : CHART_PALETTE_SIZE;
  const fits = (s) => s.slot >= 0 && s.slot < limit;
  const own = withSlot.filter(fits);
  const rest = withSlot.filter((s) => !fits(s));
  if (rest.length === 0) return own;
  return [...own, { reason: REST_LABEL, value: 'rest', count: rest.reduce((sum, s) => sum + s.count, 0), slot: CHART_PALETTE_SIZE - 1 }];
});
function percent(count) {
  return total.value === 0 ? '0.0' : ((count / total.value) * 100).toFixed(1);
}
// 圓環中間的文字：總人次數字與「總人次」。畫在 canvas 上，隨圖表一起縮放。
const centerText = {
  id: 'centerText',
  afterDraw(c) {
    const { ctx, chartArea } = c;
    const x = (chartArea.left + chartArea.right) / 2;
    const y = (chartArea.top + chartArea.bottom) / 2;
    ctx.save();
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = '#111827';
    ctx.font = `700 22px ${Chart.defaults.font.family}`;
    ctx.fillText(total.value.toLocaleString(), x, y - 8);
    ctx.fillStyle = '#6b7280';
    ctx.font = `400 13px ${Chart.defaults.font.family}`;
    ctx.fillText('總人次', x, y + 14);
    ctx.restore();
  },
};
function buildData(){
  const palette = chartPalette();
  return {
    labels: slices.value.map((s) => s.reason),
    datasets: [{ data: slices.value.map((s) => s.count), backgroundColor: slices.value.map((s) => palette[s.slot]), borderWidth: 2, borderColor: '#ffffff' }],
  };
}
onMounted(() => {
  chart = new Chart(canvasRef.value, {
    type: 'doughnut',
    data: buildData(),
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '68%',
      plugins: {
        tooltip: { callbacks: { label: (item) => `${item.label}：${item.parsed.toLocaleString()} 人（${percent(item.parsed)}%）` } },
      },
    },
    plugins: [centerText],
  });
});
// 資料或字典（決定顏色）任一個變了都要重畫。
watch(
  slices,
  () => {
    if (!chart) return;

    chart.data = buildData();
    chart.update();
  },
);
onBeforeUnmount(() => {
  chart?.destroy();
  chart = null;
});
</script>
<style scoped>
.rdn{
  display: flex;
  gap: 18px;
  align-items: center;
}
.rdn__chart{
  position: relative;
  flex-shrink: 0;
  width: 170px;
  height: 170px;
}
.rdn__legend{
  flex: 1;
  min-width: 0;
  padding: 0;
  margin: 0;
  list-style: none;
}
.rdn__item{
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 5px 0;
  font-size: 13px;
  color: #374151;
}
.rdn__dot{
  flex-shrink: 0;
  width: 10px;
  height: 10px;
  border-radius: 50%;
}
/* 顏色與圖表同源：都來自 base.css 的 --chart-1 ～ --chart-8（數量＝chartSetup.js 的 CHART_PALETTE_SIZE）。 */
.rdn__dot--1 { background: var(--chart-1); }
.rdn__dot--2 { background: var(--chart-2); }
.rdn__dot--3 { background: var(--chart-3); }
.rdn__dot--4 { background: var(--chart-4); }
.rdn__dot--5 { background: var(--chart-5); }
.rdn__dot--6 { background: var(--chart-6); }
.rdn__dot--7 { background: var(--chart-7); }
.rdn__dot--8 { background: var(--chart-8); }
.rdn__name{
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rdn__pct{
  color: #6b7280;
}
.rdn__count{
  min-width: 52px;
  color: #6b7280;
  text-align: right;
}
</style>
