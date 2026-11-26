<template>
  <div v-loading="loading" class="tra">
    <LoadErrorAlert v-if="loadError" class="tra__notice" :message="loadError" @retry="load" />
    <div class="tra__stats">
      <StatCard icon="fa-solid fa-users" label="來訪總人次" :value="fmt(stats.total)" :trend="stats.growth" :note="stats.totalNote" />
      <StatCard icon="fa-solid fa-calculator" label="日平均人次" :value="stats.dailyAvg" :note="stats.dailyNote" />
      <StatCard icon="fa-solid fa-arrow-trend-up" label="尖峰日" :value="stats.peakLabel" :note="stats.peakNote" tone="purple" />
      <StatCard icon="fa-solid fa-calendar-week" label="最忙的星期" :value="stats.busiestLabel" :note="stats.busiestNote" tone="green" />
    </div>
    <div class="tra__toolbar">
      <span class="tra__toolbar-label">時間單位</span>
      <el-radio-group v-model="granularity" size="small" aria-label="時間單位">
        <el-radio-button v-for="g in GRANULARITIES" :key="g.key" :value="g.key">{{ g.label }}</el-radio-button>
      </el-radio-group>
      <span class="tra__toolbar-note">趨勢圖、預約與臨時來訪、趨勢明細都依這個單位加總</span>
    </div>
    <ReportCard class="tra__block" title="來訪人次趨勢" hint="上期＝統計期間之前、相同天數的期間">
      <template #actions>
        <el-checkbox v-model="showPrevious">顯示上期</el-checkbox>
      </template>
      <ChartCanvas type="line" :data="trendData" :options="trendOptions" :aria-label="`來訪人次趨勢，共 ${buckets.length} 個${unitLabel}`" />
    </ReportCard>
    <div class="tra__row">
      <ReportCard title="預約與臨時來訪" :hint="`每${unitLabel}人次`">
        <ChartCanvas type="bar" size="sm" :data="mixData" :options="mixOptions" aria-label="預約與臨時來訪人次" />
      </ReportCard>
      <ReportCard title="星期分布" hint="平均每天人次">
        <ChartCanvas type="bar" size="sm" :data="weekdayData" :options="weekdayOptions" :aria-label="weekdayAria" />
      </ReportCard>
    </div>
    <ReportCard title="趨勢明細" :hint="`每${unitLabel}一列；還沒有資料的期間顯示「—」`">
      <el-table :data="tableRows" max-height="420" empty-text="沒有資料">
        <el-table-column label="期間" prop="period" min-width="220" />
        <el-table-column label="來訪人次" min-width="100" align="center">
          <template #default="{ row }">{{ fmtCell(row.total) }}</template>
        </el-table-column>
        <el-table-column label="預約人次" min-width="100" align="center">
          <template #default="{ row }">{{ fmtCell(row.reserved) }}</template>
        </el-table-column>
        <el-table-column label="臨時人次" min-width="100" align="center">
          <template #default="{ row }">{{ fmtCell(row.walk_in) }}</template>
        </el-table-column>
        <el-table-column label="上期同期" min-width="100" align="center">
          <template #default="{ row }">{{ fmtCell(row.previous) }}</template>
        </el-table-column>
        <el-table-column label="較上期同期" min-width="140" align="center">
          <template #default="{ row }"><span :class="`tra__change--${row.tone}`">{{ row.change }}</span></template>
        </el-table-column>
      </el-table>
    </ReportCard>
  </div>
</template>
<script setup>
/* *****************************************
File: TrendAnalysis.vue
Author: Callum
Date: 2026-11-26
Description: 統計報表「來訪趨勢分析」：總人次與較上期同期、日平均、尖峰日、最忙的星期，
本期與上期趨勢、預約與臨時來訪、星期分布、趨勢明細（可切換日／週／月）。
***************************************** */
// 資料來源：統計明細 API（GET /api/guest/reports/details，per_page=-1 一次取整段期間，每天一列），本期、上期各查一次。
// 日期口徑（還沒有資料的日子、上期、較上期同期）見 reportUtils.js 開頭的說明。
import { computed, ref, shallowRef, watch } from 'vue';
import { fetchReportDaily } from '../../../api/guest';
import { chartPalette, primaryColor, withAlpha } from '../../../charts/chartSetup';
import ChartCanvas from '../components/ChartCanvas.vue';
import LoadErrorAlert from '../components/LoadErrorAlert.vue';
import ReportCard from '../components/ReportCard.vue';
import StatCard from '../components/StatCard.vue';
import { formatDateWithWeekday } from '../format';
import { GRANULARITIES, bucketTitles, buildBuckets, changeText, countElapsed, dailySeries, dateList, defaultGranularity, growthRate, lastDataDate, peakIndex, previousRange, rangeText, sumByBucket, sumFirst, weekdayAverages } from './reportUtils';
const props = defineProps({
  // 目前套用的查詢條件：date_from、date_to、floor、doorplate_id、visit_reason。
  params: { type: Object, required: true },
  // 每按一次「查詢」加 1：條件沒變也要重新載入。
  version: { type: Number, default: 0 },
});
const PREVIOUS_COLOR = '#9ca3af'; // 上期：中性灰虛線，當作背景參考，不搶本期的主色
const loading = ref(false);
const loadError = ref('');
const dates = shallowRef([]); // 本期每一天
const prevDates = shallowRef([]); // 上期每一天（與本期逐日對齊：第 i 天對第 i 天）
const daily = shallowRef({ total: [], reserved: [], walk_in: [], previous: [] }); // 每天的人次，還沒有資料的日子是 null
const elapsed = ref(0); // 本期已經有資料的天數
const granularity = ref('day');
const showPrevious = ref(true);
const fmt = (n) => Number(n || 0).toLocaleString();
const fmtCell = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString());
const unitLabel = computed(() => GRANULARITIES.find((g) => g.key === granularity.value)?.label || '日');
const buckets = computed(() => buildBuckets(dates.value, granularity.value));
// 依時間單位加總後的各組數值（上期用同樣的天數位置加總，才是同期比較）。
// 交給圖表時一律複製一份（[...陣列]）：Chart.js 會改寫資料陣列的方法來監聽變動，不要讓它碰到明細表也在用的同一個陣列。
// previous：上期整組的人次（趨勢圖的灰線，當作參考）；previousSame：上期只算「本期已有資料的那幾天」（明細表的同期比較）。
// 兩者只在「本期進行到一半的那一組」不同，例如這週只過了 3 天，就只跟上期那週的前 3 天比，不然一定顯示大幅下降。
const series = computed(() => {
  const n = elapsed.value;
  const previousSame = daily.value.previous.map((v, i) => (i < n ? v : null));
  return{
    total: sumByBucket(daily.value.total, buckets.value),
    reserved: sumByBucket(daily.value.reserved, buckets.value),
    walk_in: sumByBucket(daily.value.walk_in, buckets.value),
    previous: sumByBucket(daily.value.previous, buckets.value),
    previousSame: sumByBucket(previousSame, buckets.value),
  };
});
// 每組的完整標題（本期只過了一部分的組會註明統計到哪一天）。
const titles = computed(() => bucketTitles(buckets.value, dates.value, elapsed.value));
const stats = computed(() => {
  const n = elapsed.value;
  const totals = daily.value.total;
  const total = sumFirst(totals, n);
  const growth = n ? growthRate(total, sumFirst(daily.value.previous, n)) : null;
  const peak = peakIndex(totals);
  const weekdays = weekdayAverages(dates.value, totals);
  const busiest = peakIndex(weekdays.map((w) => w.average));
  let totalNote = '較上期同期';
  if(n === 0) totalNote = '統計期間還沒有資料';
  else if(growth === null) totalNote = '上期同期沒有來訪，無法比較';
  return{
    total,
    growth,
    totalNote,
    dailyAvg: n ? (Math.round((total / n) * 10) / 10).toLocaleString() : '—',
    dailyNote: n ? `統計 ${n} 天` : '統計期間還沒有資料',
    peakLabel: peak >= 0 ? formatDateWithWeekday(dates.value[peak]).slice(5) : '—',
    peakNote: peak >= 0 ? `${totals[peak].toLocaleString()} 人次` : '沒有來訪',
    busiestLabel: busiest >= 0 ? weekdays[busiest].label : '—',
    busiestNote: busiest >= 0 ? `平均每天 ${weekdays[busiest].average} 人次` : '沒有來訪',
    weekdays,
  };
});
// 提示框的人次文字；null＝那段期間還沒有資料。
const countText = (v) => (v === null || v === undefined ? '尚無資料' : `${v.toLocaleString()} 人`);
// 共用的 Y 軸：從 0 開始、只有整數刻度、淡灰格線。
const countAxis = (extra = {}) => ({ beginAtZero: true, border: { display: false }, grid: { color: '#eef0f3' }, ticks: { precision: 0 }, ...extra });
const categoryAxis = (extra = {}) => ({ grid: { display: false }, ticks: { maxTicksLimit: 12, maxRotation: 0, autoSkip: true }, ...extra });
// ── 來訪人次趨勢：本期（主色實線＋漸層底）、上期（灰色虛線）──
const trendData = computed(() => {
  const primary = primaryColor();
  const datasets = [{
    label: '本期',
    data: [...series.value.total],
    borderColor: primary,
    borderWidth: 2,
    pointRadius: buckets.value.length > 62 ? 0 : 3,
    pointHoverRadius: 5,
    pointBackgroundColor: primary,
    tension: 0.3,
    fill: true,
    backgroundColor: (context) => {
      const { chart: c } = context;
      if(!c.chartArea) return withAlpha(primary, 0.15);
      const gradient = c.ctx.createLinearGradient(0, c.chartArea.top, 0, c.chartArea.bottom);
      gradient.addColorStop(0, withAlpha(primary, 0.24));
      gradient.addColorStop(1, withAlpha(primary, 0));
      return gradient;
    },
  }];
  if(showPrevious.value){
    datasets.push({
      label: '上期',
      data: [...series.value.previous],
      borderColor: PREVIOUS_COLOR,
      backgroundColor: PREVIOUS_COLOR,
      borderDash: [6, 4],
      borderWidth: 2,
      pointRadius: 0,
      pointHoverRadius: 4,
      tension: 0.3,
      fill: false,
    });
  }
  return { labels: buckets.value.map((b) => b.label), datasets };
});
const trendOptions = computed(() => {
  const list = buckets.value;
  const names = titles.value;
  const prev = prevDates.value;
  return{
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: showPrevious.value, position: 'top', align: 'end' },
      tooltip: {
        callbacks: {
          title: (items) => names[items[0].dataIndex] ?? '',
          label: (item) => {
            if(item.dataset.label === '本期') return `本期：${countText(item.parsed.y)}`;
            const b = list[item.dataIndex];
            // 本期只過了一部分的組：灰線是上期整組，另外附上同期（與明細表的比較基準相同），兩邊才對得起來。
            const same = series.value.previousSame[item.dataIndex];
            const sameText = same !== null && same !== item.parsed.y ? `；同期 ${same.toLocaleString()} 人` : '';
            return `上期（${rangeText(prev[b.start], prev[b.end])}）：${countText(item.parsed.y)}${sameText}`;
          },
        },
      },
    },
    scales: { x: categoryAxis(), y: countAxis() },
  };
});
// ── 預約與臨時來訪：堆疊長條（每段上緣留 2px 白邊，段與段之間才分得開）──
const mixData = computed(() => {
  const palette = chartPalette();
  const bar = (label, data, color) => ({ label, data, backgroundColor: color, borderColor: '#ffffff', borderWidth: { top: 2 }, borderSkipped: 'start', maxBarThickness: 32 });
  return{
    labels: buckets.value.map((b) => b.label),
    datasets: [bar('預約', [...series.value.reserved], palette[0]), bar('臨時', [...series.value.walk_in], palette[1])],
  };
});
const mixOptions = computed(() => {
  const names = titles.value;
  return{
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { display: true, position: 'top', align: 'end' },
      tooltip: {
        callbacks: {
          title: (items) => names[items[0].dataIndex] ?? '',
          label: (item) => `${item.dataset.label}：${countText(item.parsed.y)}`,
          footer: (items) => (items.some((i) => i.parsed.y !== null) ? `合計：${items.reduce((sum, i) => sum + (i.parsed.y ?? 0), 0).toLocaleString()} 人` : ''),
        },
      },
    },
    scales: { x: categoryAxis({ stacked: true }), y: countAxis({ stacked: true }) },
  };
});
// ── 星期分布：週一～週日的平均每日人次 ──
const weekdayData = computed(() => ({
  labels: stats.value.weekdays.map((w) => w.label),
  datasets: [{ data: stats.value.weekdays.map((w) => w.average), backgroundColor: primaryColor(), borderRadius: 4, maxBarThickness: 36 }],
}));
const weekdayOptions = computed(() => {
  const weekdays = stats.value.weekdays;
  return{
    plugins: {
      tooltip: {
        displayColors: false,
        callbacks: { label: (item) => `平均每天 ${item.parsed.y} 人次（共 ${weekdays[item.dataIndex].days} 天）` },
      },
    },
    // 平均值會有小數，這張圖的刻度不限整數。
    scales: { x: { grid: { display: false } }, y: countAxis({ ticks: {} }) },
  };
});
const weekdayAria = computed(() => `星期分布：${stats.value.weekdays.map((w) => `${w.label} ${w.average ?? '無資料'}`).join('、')}`);
// ── 趨勢明細：每組一列，由舊到新（與圖表相同順序）；上期一律用同期（previousSame）──
const tableRows = computed(() => buckets.value.map((b, i) => {
  const s = series.value;
  const comparable = s.total[i] !== null && s.previousSame[i] !== null;
  const diff = comparable ? s.total[i] - s.previousSame[i] : 0;
  return{
    key: b.key,
    period: titles.value[i],
    total: s.total[i],
    reserved: s.reserved[i],
    walk_in: s.walk_in[i],
    previous: s.previousSame[i],
    change: comparable ? changeText(s.total[i], s.previousSame[i]) : '—',
    tone: diff > 0 ? 'up' : diff < 0 ? 'down' : 'flat',
  };
}));
// 本期、上期依序查詢（不併發：README 提到本機併發請求偶發 500）；條件換得很快時，只採用最後一次的結果。
let loadSeq = 0;
async function load(){
  const { date_from: from, date_to: to } = props.params;
  const list = dateList(from, to);
  if(!list.length) return;
  const seq = ++loadSeq;
  loading.value = true;
  loadError.value = '';
  const [prevFrom, prevTo] = previousRange(from, to);
  const current = await fetchReportDaily({ ...props.params, per_page: -1 });
  if(seq !== loadSeq) return;
  const previous = current.ok ? await fetchReportDaily({ ...props.params, date_from: prevFrom, date_to: prevTo, per_page: -1 }) : current;
  if(seq !== loadSeq) return;
  loading.value = false;
  if(!previous.ok){
    loadError.value = `趨勢資料載入失敗：${previous.message}`;
    return;
  }
  const lastDate = lastDataDate();
  const byDate = (items) => Object.fromEntries((items || []).map((r) => [r.date, r]));
  const currentRows = byDate(current.data.items);
  const prevList = dateList(prevFrom, prevTo);
  dates.value = list;
  prevDates.value = prevList;
  daily.value = {
    total: dailySeries(list, currentRows, 'total', lastDate),
    reserved: dailySeries(list, currentRows, 'reserved', lastDate),
    walk_in: dailySeries(list, currentRows, 'walk_in', lastDate),
    previous: dailySeries(prevList, byDate(previous.data.items), 'total', lastDate),
  };
  elapsed.value = countElapsed(list, lastDate);
  granularity.value = defaultGranularity(list.length);
}
watch(() => [props.params, props.version], load, { immediate: true });
</script>
<style scoped>
.tra__notice{
  margin-bottom: 14px;
}
.tra__stats{
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.tra__toolbar{
  display: flex;
  flex-wrap: wrap;
  gap: 8px 12px;
  align-items: center;
  margin-bottom: 14px;
}
.tra__toolbar-label{
  font-size: 14px;
  font-weight: 600;
  color: #374151;
}
.tra__toolbar-note{
  font-size: 13px;
  color: #6b7280;
}
.tra__block{
  margin-bottom: 16px;
}
.tra__row{
  display: grid;
  grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
  gap: 14px;
  margin-bottom: 16px;
}
/* 增減：多了綠、少了紅（與統計卡片的成長率同色）。 */
.tra__change--up{
  color: #16a34a;
}
.tra__change--down{
  color: #e5484d;
}
@media(max-width: 1280px){
  .tra__stats{
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .tra__row{
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
