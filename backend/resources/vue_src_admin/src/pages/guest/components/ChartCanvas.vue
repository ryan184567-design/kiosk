<template>
  <div class="chc" :class="`chc--${size}`">
    <canvas ref="canvasRef" role="img" :aria-label="ariaLabel"></canvas>
  </div>
</template>
<script setup>
/* *****************************************
File: ChartCanvas.vue
Author: Callum
Date: 2026-11-26
Description: 通用的 Chart.js 圖表外框：依傳入的 type、data、options 建立圖表，資料變了就更新，離開頁面時銷毀。
***************************************** */
// data、options 由頁面用 computed 組好再傳進來(每次都是新的物件、陣列用 map 產生)，這裡只負責建立與更新。
// 不要傳 Vue 的 reactive 陣列進來：Chart.js 會改寫資料陣列的 push 等方法來監聽變動，跟 Vue 的 Proxy 會互相干擾。
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Chart } from '../../../charts/chartSetup';
const props = defineProps({
  type: { type: String, required: true },
  // { labels, datasets }
  data: { type: Object, required: true },
  options: { type: Object, default: () => ({}) },
  ariaLabel: { type: String, default: '' },
  // 高度：md 280px、sm 240px(用 class 決定，嚴格 CSP 不能用 inline style)。
  size: { type: String, default: 'md' },
});
const canvasRef = ref(null);
let chart = null;
const fullOptions = () => ({ responsive: true, maintainAspectRatio: false, ...props.options });
onMounted(() => {
  chart = new Chart(canvasRef.value, { type: props.type, data: props.data, options: fullOptions() });
});
watch(
  () => [props.data, props.options],
  () => {
    if(!chart) return;
    chart.data = props.data;
    chart.options = fullOptions();
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
.chc{
  position: relative;
}
.chc--md{
  height: 280px;
}
.chc--sm{
  height: 240px;
}
</style>
