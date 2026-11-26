<template>
  <div v-loading="loading" class="rsa">
    <LoadErrorAlert v-if="loadError" class="rsa__notice" :message="loadError" @retry="load" />
    <div class="rsa__stats">
      <StatCard icon="fa-solid fa-users" label="來訪總人次" :value="fmt(total)" :trend="totalGrowth" :note="totalNote" />
      <StatCard icon="fa-solid fa-crown" label="最主要事由" :value="topReason.label" :note="topReason.note" tone="purple" />
      <StatCard icon="fa-solid fa-tags" label="有來訪的事由" :value="activeCount" unit="種" :note="`目前啟用的事由共 ${reasonOptions.length} 種`" tone="green" />
      <StatCard icon="fa-solid fa-arrow-trend-up" label="成長最多的事由" :value="mostGrown.label" :note="mostGrown.note" />
    </div>
    <div class="rsa__row">
      <ReportCard title="事由占比">
        <ReasonDonut :items="donutItems" />
      </ReportCard>
      <ReportCard title="事由排行" hint="增減＝與上期同期比較">
        <el-table :data="rankRows" empty-text="沒有資料">
          <el-table-column label="事由" min-width="140">
            <template #default="{ row }">
              <span class="rsa__reason"><span class="rsa__dot" :class="`rsa__dot--${row.slot + 1}`" aria-hidden="true"></span>{{ row.label }}</span>
            </template>
          </el-table-column>
          <el-table-column label="人次" min-width="70" align="center">
            <template #default="{ row }">{{ fmt(row.count) }}</template>
          </el-table-column>
          <el-table-column label="占比" min-width="150">
            <template #default="{ row }">
              <div class="rsa__share">
                <el-progress class="rsa__bar" :percentage="row.percent" :show-text="false" :stroke-width="8" />
                <span class="rsa__pct">{{ row.percent.toFixed(1) }}%</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="上期同期" min-width="80" align="center">
            <template #default="{ row }">{{ row.prevCount === null ? '—' : fmt(row.prevCount) }}</template>
          </el-table-column>
          <el-table-column label="增減" min-width="120" align="center">
            <template #default="{ row }"><span :class="`rsa__change--${row.tone}`">{{ row.change }}</span></template>
          </el-table-column>
          <el-table-column label="主要樓層" min-width="110" align="center">
            <template #default="{ row }">{{ row.topFloor }}</template>
          </el-table-column>
        </el-table>
      </ReportCard>
    </div>
    <ReportCard title="各事由人次趨勢" :hint="`每${unitLabel}人次，依事由堆疊`">
      <template #actions>
        <el-radio-group v-model="granularity" size="small" aria-label="時間單位">
          <el-radio-button v-for="g in GRANULARITIES" :key="g.key" :value="g.key">{{ g.label }}</el-radio-button>
        </el-radio-group>
      </template>
      <ChartCanvas type="bar" :data="trendData" :options="trendOptions" :aria-label="`各事由人次趨勢，共 ${buckets.length} 個${unitLabel}`" />
    </ReportCard>
  </div>
</template>
<script setup>
/* *****************************************
File: ReasonAnalysis.vue
Author: Callum
Date: 2026-11-26
Description: 統計報表「來訪事由分析」：最主要事由、成長最多的事由、事由占比、事由排行（含上期同期比較與主要樓層）、
各事由人次趨勢（可切換日／週／月）。
***************************************** */
// 資料來源：統計總覽 API（GET /api/guest/reports），依序查：
// 1. 本期（不篩事由，group_by=day）→ 總人次、各事由人次。
// 2. 上期同期（上期開頭、與本期已有資料相同的天數）→ 各事由的比較基準。本期還沒有任何資料時不查。
// 3. 本期有來訪的事由各查一次（visit_reason=該事由）→ 該事由每天的人次（趨勢圖）與人次最多的樓層。字典目前 8 種，最多 8 次。
// 這一頁本身就是在比較事由，所以篩選列的「來訪事由」不顯示、也不套用（樓層、公司照常套用）。
import { computed, ref, shallowRef, watch } from 'vue';
import { fetchReportOverview } from '../../../api/guest';
import { CHART_PALETTE_SIZE, chartPalette } from '../../../charts/chartSetup';
import ChartCanvas from '../components/ChartCanvas.vue';
import LoadErrorAlert from '../components/LoadErrorAlert.vue';
import ReasonDonut from '../components/ReasonDonut.vue';
import ReportCard from '../components/ReportCard.vue';
import StatCard from '../components/StatCard.vue';
import { addDays } from '../format';
import { useVisitReasons } from '../useVisitReasons';
import { GRANULARITIES, REST_REASON_LABEL, assignReasonSlots, bucketTitles, buildBuckets, changeText, countElapsed, dailySeries, dateList, defaultGranularity, growthRate, lastDataDate, previousRange, sumByBucket } from './reportUtils';
const props = defineProps({
  // 目前套用的查詢條件：date_from、date_to、floor、doorplate_id（visit_reason 在這頁不使用）。
  params: { type: Object, required: true },
  // 每按一次「查詢」加 1：條件沒變也要重新載入。
  version: { type: Number, default: 0 },
});
const { reasonOptions, reasonOrder } = useVisitReasons();
const loading = ref(false);
const loadError = ref('');
const dates = shallowRef([]);
const elapsed = ref(0);
const total = ref(0);
const prevTotal = ref(null); // 上期同期的總人次；沒有比較基準時是 null
// 各事由：[{ value, label, count, prevCount, daily, topFloor }]，依本期人次由多到少（後端順序）；上期有、本期沒有的排在後面。
const reasons = shallowRef([]);
const donutItems = shallowRef([]);
const granularity = ref('day');
const fmt = (n) => Number(n || 0).toLocaleString();
const unitLabel = computed(() => GRANULARITIES.find((g) => g.key === granularity.value)?.label || '日');
// 每個事由的顏色位置（規則同 ReasonDonut：依字典順序，放不下的併成「其他事由（合計）」）。字典讀回來前後順序可能不同，所以是 computed。
const slotted = computed(() => {
  const slots = assignReasonSlots(reasons.value.map((r) => r.value), reasonOrder, CHART_PALETTE_SIZE);
  return reasons.value.map((r, i) => ({ ...r, slot: slots[i].slot, rest: slots[i].rest }));
});
const activeCount = computed(() => reasons.value.filter((r) => r.count > 0).length);
const totalGrowth = computed(() => (prevTotal.value === null ? null : growthRate(total.value, prevTotal.value)));
const totalNote = computed(() => {
  if(elapsed.value === 0) return '統計期間還沒有資料';
  if(totalGrowth.value === null) return '上期同期沒有來訪，無法比較';
  return '較上期同期';
});
const percentOf = (count) => (total.value ? Math.round((count / total.value) * 1000) / 10 : 0);
const topReason = computed(() => {
  const top = reasons.value[0];
  if(!top || top.count === 0) return { label: '—', note: '沒有來訪' };
  return { label: top.label, note: `占 ${percentOf(top.count).toFixed(1)}%（${top.count.toLocaleString()} 人次）` };
});
// 人次增加最多的事由（看人數，不看百分比：上期 1 人變 3 人的 +200% 不該蓋過 50 人變 80 人）。
const mostGrown = computed(() => {
  if(prevTotal.value === null) return { label: '—', note: '沒有上期同期可以比較' };
  const best = reasons.value.reduce((acc, r) => {
    const diff = r.count - (r.prevCount ?? 0);
    return diff > 0 && (!acc || diff > acc.diff) ? { r, diff } : acc;
  }, null);
  if(!best) return { label: '—', note: '沒有事由比上期同期增加' };
  const rate = growthRate(best.r.count, best.r.prevCount ?? 0);
  return { label: best.r.label, note: `較上期同期 +${best.diff.toLocaleString()} 人次${rate === null ? '' : `（+${rate}%）`}` };
});
const rankRows = computed(() => slotted.value.map((r) => {
  const comparable = r.prevCount !== null;
  const diff = comparable ? r.count - r.prevCount : 0;
  return{
    ...r,
    percent: percentOf(r.count),
    change: comparable ? changeText(r.count, r.prevCount) : '—',
    tone: diff > 0 ? 'up' : diff < 0 ? 'down' : 'flat',
  };
}));
// ── 各事由人次趨勢：堆疊長條，事由依字典順序由下往上疊，顏色同圓環圖 ──
const buckets = computed(() => buildBuckets(dates.value, granularity.value));
const trendData = computed(() => {
  const palette = chartPalette();
  // 只畫本期有來訪的事由；放不下色盤的併成一組。
  const groups = new Map();
  for(const r of slotted.value.filter((x) => x.count > 0)){
    const key = r.rest ? 'rest' : r.value;
    const group = groups.get(key) || { label: r.rest ? REST_REASON_LABEL : r.label, slot: r.slot, daily: null };
    group.daily = group.daily ? group.daily.map((v, i) => (v === null ? null : v + r.daily[i])) : [...r.daily];
    groups.set(key, group);
  }
  const datasets = [...groups.values()]
    .sort((a, b) => a.slot - b.slot)
    .map((g) => ({
      label: g.label,
      data: sumByBucket(g.daily, buckets.value),
      backgroundColor: palette[g.slot],
      borderColor: '#ffffff',
      borderWidth: { top: 2 },
      borderSkipped: 'start',
      maxBarThickness: 32,
    }));
  return { labels: buckets.value.map((b) => b.label), datasets };
});
const trendOptions = computed(() => {
  const names = bucketTitles(buckets.value, dates.value, elapsed.value);
  return{
    interaction: { mode: 'index', intersect: false },
    plugins: {
      // 兩種以上的事由才需要圖例（只有一種時標題就說明了）。
      legend: { display: trendData.value.datasets.length > 1, position: 'top', align: 'end' },
      tooltip: {
        // 只列出那段期間有來訪的事由；整段都沒有來訪（或還沒有資料）時全部列出，提示框才不會是空的。
        filter: (item, _index, all) => (item.parsed.y ?? 0) > 0 || all.every((i) => !((i.parsed.y ?? 0) > 0)),
        callbacks: {
          title: (items) => names[items[0]?.dataIndex] ?? '',
          label: (item) => `${item.dataset.label}：${item.parsed.y === null ? '尚無資料' : `${item.parsed.y.toLocaleString()} 人`}`,
          footer: (items) => (items.some((i) => i.parsed.y !== null) ? `合計：${items.reduce((sum, i) => sum + (i.parsed.y ?? 0), 0).toLocaleString()} 人` : ''),
        },
      },
    },
    scales: {
      x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0, autoSkip: true } },
      y: { stacked: true, beginAtZero: true, border: { display: false }, grid: { color: '#eef0f3' }, ticks: { precision: 0 } },
    },
  };
});
// 樓層：人次最多的樓層與人次，例如「2F（3 人次）」；受訪公司已被刪除（沒有樓層）時顯示「—」。
const floorText = (floor) => (floor ? `${floor.floor_label}（${floor.count.toLocaleString()} 人次）` : '—');
// 依序查詢（不併發：README 提到本機併發請求偶發 500）；條件換得很快時，只採用最後一次的結果。
let loadSeq = 0;
async function load(){
  const { date_from: from, date_to: to } = props.params;
  const list = dateList(from, to);
  if(!list.length) return;
  const seq = ++loadSeq;
  const base = { ...props.params, visit_reason: undefined };
  const lastDate = lastDataDate();
  const days = countElapsed(list, lastDate);
  loading.value = true;
  loadError.value = '';
  const fail = (result) => {
    loading.value = false;
    loadError.value = `事由資料載入失敗：${result.message}`;
  };
  const current = await fetchReportOverview({ ...base, group_by: 'day' });
  if(seq !== loadSeq) return;
  if(!current.ok) return fail(current);
  let prevCounts = null;
  let prevSum = null;
  if(days > 0){
    const prevFrom = previousRange(from, to)[0];
    const previous = await fetchReportOverview({ ...base, date_from: prevFrom, date_to: addDays(prevFrom, days - 1) });
    if(seq !== loadSeq) return;
    if(!previous.ok) return fail(previous);
    prevCounts = new Map((previous.data.reasons || []).map((r) => [r.value, r]));
    prevSum = previous.data.summary.total;
  }
  const items = [];
  for(const r of current.data.reasons || []){
    const one = await fetchReportOverview({ ...base, visit_reason: r.value, group_by: 'day' });
    if(seq !== loadSeq) return;
    if(!one.ok) return fail(one);
    const byDate = Object.fromEntries((one.data.trend || []).map((t) => [t.date, t]));
    items.push({
      value: r.value,
      label: r.reason,
      count: r.count,
      prevCount: prevCounts ? (prevCounts.get(r.value)?.count ?? 0) : null,
      daily: dailySeries(list, byDate, 'total', lastDate),
      topFloor: floorText(one.data.floors?.[0]),
    });
  }
  // 上期有、本期沒有的事由也列出來（人次 0），才看得出「哪個事由不見了」。
  for(const [value, r] of prevCounts || []){
    if(items.some((i) => i.value === value)) continue;
    items.push({ value, label: r.reason, count: 0, prevCount: r.count, daily: list.map((d) => (d <= lastDate ? 0 : null)), topFloor: '—' });
  }
  loading.value = false;
  dates.value = list;
  elapsed.value = days;
  total.value = current.data.summary.total;
  prevTotal.value = prevSum;
  reasons.value = items;
  donutItems.value = current.data.reasons || [];
  granularity.value = defaultGranularity(list.length);
}
watch(() => [props.params, props.version], load, { immediate: true });
</script>
<style scoped>
.rsa__notice{
  margin-bottom: 14px;
}
.rsa__stats{
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.rsa__row{
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.6fr);
  gap: 14px;
  margin-bottom: 16px;
}
.rsa__reason{
  display: inline-flex;
  gap: 8px;
  align-items: center;
}
.rsa__dot{
  flex-shrink: 0;
  width: 10px;
  height: 10px;
  border-radius: 50%;
}
/* 顏色與圖表同源：都來自 base.css 的 --chart-1 ～ --chart-8（數量＝chartSetup.js 的 CHART_PALETTE_SIZE）。 */
.rsa__dot--1 { background: var(--chart-1); }
.rsa__dot--2 { background: var(--chart-2); }
.rsa__dot--3 { background: var(--chart-3); }
.rsa__dot--4 { background: var(--chart-4); }
.rsa__dot--5 { background: var(--chart-5); }
.rsa__dot--6 { background: var(--chart-6); }
.rsa__dot--7 { background: var(--chart-7); }
.rsa__dot--8 { background: var(--chart-8); }
.rsa__share{
  display: flex;
  gap: 8px;
  align-items: center;
}
.rsa__bar{
  flex: 1;
  min-width: 60px;
}
.rsa__pct{
  min-width: 46px;
  font-size: 13px;
  color: #6b7280;
  text-align: right;
}
/* 增減：多了綠、少了紅（與統計卡片的成長率同色）。 */
.rsa__change--up{
  color: #16a34a;
}
.rsa__change--down{
  color: #e5484d;
}
@media(max-width: 1400px){
  .rsa__row{
    grid-template-columns: minmax(0, 1fr);
  }
}
@media(max-width: 1280px){
  .rsa__stats{
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
