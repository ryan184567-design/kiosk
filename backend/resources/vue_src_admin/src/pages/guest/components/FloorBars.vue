<template>
  <div class="flb">
    <div class="flb__chart" :class="`flb__chart--rows-${rowCount}`">
      <canvas ref="canvasRef" role="img" :aria-label="ariaLabel"></canvas>
    </div>
    <p v-if="items.length === 0" class="flb__none">沒有資料</p>
  </div>
</template>

<script setup>
/* *****************************************
File: FloorBars.vue
Author: Callum
Date: 2026-11-25
Description: 樓層來訪分布橫向長條圖（Chart.js）：左邊樓層名稱、右邊「人數（占比）」，長條進場動畫與折線圖、圓環圖一致。
***************************************** */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Chart, primaryColor } from '../../../charts/chartSetup';

const props = defineProps({
  // [{ floor: 2, floor_label: '2F', count: 286 }]
  items: { type: Array, default: () => [] },
  // 占比的分母（統計期間的總人次）。
  total: { type: Number, default: 0 },
});

const canvasRef = ref(null);

let chart = null;

// 每一列的高度要固定，容器高度才算得出來；容器高度用 class（flb__chart--rows-N）決定，不用 inline style（嚴格 CSP）。
// 後端最多回傳 Top 5，class 準備到 10 列，更多就以 10 列的高度為準。
const MAX_ROWS = 10;
// 樓層名稱與長條之間的間距（px）。
const LABEL_GAP = 10;
const rowCount = computed(() => Math.min(props.items.length, MAX_ROWS));
const ariaLabel = computed(() => `樓層來訪分布：${props.items.map((i) => `${i.floor_label} ${i.count} 人`).join('、') || '沒有資料'}`);

// 占總人次的百分比（一位小數）。
function percent(count) {
  return props.total === 0 ? '0.0' : ((count / props.total) * 100).toFixed(1);
}

const valueText = (item) => `${Number(item.count).toLocaleString()} 人 (${percent(item.count)}%)`;

// 每條長條後面的淺灰底軌（長條長度＝占最大值的比例，底軌讓長短一眼看得出來）。
const barTrack = {
  id: 'barTrack',
  beforeDatasetsDraw(c) {
    const { ctx, chartArea } = c;

    ctx.save();
    ctx.fillStyle = '#eef0f3';

    for (const bar of c.getDatasetMeta(0).data) {
      const height = bar.height;
      const top = bar.y - height / 2;

      ctx.beginPath();

      if (ctx.roundRect) {
        ctx.roundRect(chartArea.left, top, chartArea.right - chartArea.left, height, height / 2);
      } else {
        ctx.rect(chartArea.left, top, chartArea.right - chartArea.left, height);
      }

      ctx.fill();
    }

    ctx.restore();
  },
};

// 長條右邊的「人數 (占比%)」，靠右對齊圖表右緣（右邊留給它的空間由 layout.padding.right 保留）。
const valueLabels = {
  id: 'valueLabels',
  afterDatasetsDraw(c) {
    const { ctx } = c;

    ctx.save();
    ctx.textAlign = 'right';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = '#6b7280';
    ctx.font = `400 13px ${Chart.defaults.font.family}`;

    c.getDatasetMeta(0).data.forEach((bar, i) => {
      const item = props.items[i];

      if (item) ctx.fillText(valueText(item), c.width, bar.y);
    });

    ctx.restore();
  },
};

function buildData() {
  return {
    labels: props.items.map((i) => i.floor_label),
    datasets: [
      {
        data: props.items.map((i) => i.count),
        backgroundColor: primaryColor(),
        barThickness: 12,
        borderRadius: 6,
        borderSkipped: false,
      },
    ],
  };
}

onMounted(() => {
  chart = new Chart(canvasRef.value, {
    type: 'bar',
    data: buildData(),
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      layout: { padding: { right: 112 } },
      scales: {
        // 最大值＝最長的那條填滿底軌（與原本的長條圖一致）。
        x: { display: false, beginAtZero: true, grace: 0 },
        y: {
          grid: { display: false },
          border: { display: false },
          ticks: { color: '#374151', font: { size: 13, weight: 600 }, padding: 0 },
          // 標籤與長條的間距：ticks.padding 會同時在標籤上下各多留一份空間（只有 1 列時整張圖被壓成 0 高度、
          // 長條畫不出來），所以間距改成把標籤欄加寬，並把上下保留的空間歸零，每一列剛好 32px。
          afterFit(scale) {
            scale.width += LABEL_GAP;
            scale.paddingTop = 0;
            scale.paddingBottom = 0;
          },
        },
      },
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (item) => `${item.label}：${valueText(props.items[item.dataIndex])}` } },
      },
    },
    plugins: [barTrack, valueLabels],
  });
});

watch(
  () => [props.items, props.total],
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
/* 每列 32px；容器高度跟著列數變（Chart.js 會跟著容器大小重畫）。 */
.flb__chart {
  position: relative;
  height: 0;
}

.flb__chart--rows-1 { height: 32px; }
.flb__chart--rows-2 { height: 64px; }
.flb__chart--rows-3 { height: 96px; }
.flb__chart--rows-4 { height: 128px; }
.flb__chart--rows-5 { height: 160px; }
.flb__chart--rows-6 { height: 192px; }
.flb__chart--rows-7 { height: 224px; }
.flb__chart--rows-8 { height: 256px; }
.flb__chart--rows-9 { height: 288px; }
.flb__chart--rows-10 { height: 320px; }

.flb__none {
  padding: 24px 0;
  margin: 0;
  color: #9ca3af;
  text-align: center;
}
</style>
