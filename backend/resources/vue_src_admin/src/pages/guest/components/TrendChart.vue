<template>
  <div class="trend">
    <canvas ref="canvasRef" role="img" :aria-label="`每日來訪人次趨勢，共 ${points.length} 天`"></canvas>
  </div>
</template>
<script setup>
/* *****************************************
File: TrendChart.vue
Author: Callum
Date: 2026-11-19
Description: 每日來訪人次趨勢折線圖（Chart.js），折線下方有漸層底色。
***************************************** */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Chart, primaryColor, withAlpha } from '../../../charts/chartSetup';
import { formatDateWithWeekday } from '../format';
const props = defineProps({
  // [{ date: '2026-10-01', total: 52 }]
  points: { type: Array, default: () => [] },
});
const canvasRef = ref(null);
let chart = null;
// '2026-10-22' → '10/22'
const shortDate = (date) => date.slice(5).replace('-', '/');
function buildData(){
  return{
    labels: props.points.map((p) => shortDate(p.date)),
    datasets: [
      {
        data: props.points.map((p) => p.total),
        borderColor: primaryColor(),
        borderWidth: 2,
        pointRadius: 3,
        pointHoverRadius: 5,
        pointBackgroundColor: primaryColor(),
        tension: 0.35,
        fill: true,
        // 漸層要在 canvas 有尺寸之後才建得出來，所以用函式；第一次繪製前 chartArea 還不存在。
        backgroundColor: (context) => {
          const { chart: c } = context;
          if(!c.chartArea) return withAlpha(primaryColor(), 0.15);
          const gradient = c.ctx.createLinearGradient(0, c.chartArea.top, 0, c.chartArea.bottom);
          gradient.addColorStop(0, withAlpha(primaryColor(), 0.28));
          gradient.addColorStop(1, withAlpha(primaryColor(), 0));
          return gradient;
        },
      },
    ],
  };
}
onMounted(() => {
  chart = new Chart(canvasRef.value, {
    type: 'line',
    data: buildData(),
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        tooltip: {
          displayColors: false,
          callbacks: {
            title: (items) => formatDateWithWeekday(props.points[items[0].dataIndex].date).slice(5),
            label: (item) => `來訪：${item.parsed.y} 人`,
          },
        },
      },
      scales: {
        x: { grid: { display: false }, ticks: { maxTicksLimit: 11, maxRotation: 0 } },
        y: { beginAtZero: true, border: { display: false }, grid: { color: '#eef0f3' }, ticks: { precision: 0 } },
      },
    },
  });
});
watch(
  () => props.points,
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
/* Chart.js 用 maintainAspectRatio:false 時，容器必須有明確高度、且 position: relative。 */
.trend {
  position: relative;
  height: 250px;
}
</style>
