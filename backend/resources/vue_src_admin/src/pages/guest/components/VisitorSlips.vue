<template>
  <!-- 掛在 body 底下：列印時只要把 body 的其他子元素藏起來，不受後臺版面(固定高度、捲動區)影響。畫面上永遠看不到。 -->
  <Teleport to="body">
    <div v-if="record" class="visitor-slips">
      <section v-for="(guest, index) in record.guests" :key="guest.id ?? index" class="visitor-slip">
        <header class="visitor-slip__head">
          <div v-if="siteBranding.siteName" class="visitor-slip__site">{{ siteBranding.siteName }}</div>
          <h1 class="visitor-slip__title">訪客單</h1>
          <div v-if="record.guests.length > 1" class="visitor-slip__count">第 {{ index + 1 }} 位，共 {{ record.guests.length }} 位</div>
        </header>
        <div class="visitor-slip__name">{{ guest.name || '—' }}</div>
        <div class="visitor-slip__company">{{ guest.company_name || '—' }}</div>
        <dl class="visitor-slip__list">
          <div><dt>預約單號</dt><dd>{{ record.reservation_id || '—' }}</dd></div>
          <div><dt>受訪公司</dt><dd>{{ record.company_name || '—' }}</dd></div>
          <div><dt>受訪樓層</dt><dd>{{ floorText }}</dd></div>
          <div><dt>受訪人</dt><dd>{{ record.host_name || '—' }}</dd></div>
          <div><dt>來訪事由</dt><dd>{{ reasonLabel(record.visit_reason) }}</dd></div>
          <div><dt>進場時間</dt><dd>{{ dateTime(record.check_in_at) }}</dd></div>
          <div><dt>車牌號碼</dt><dd>{{ guest.plate_number || '—' }}</dd></div>
        </dl>
        <footer class="visitor-slip__foot">列印時間 {{ printedAt }}</footer>
      </section>
    </div>
  </Teleport>
</template>
<script setup>
/* *****************************************
File: VisitorSlips.vue
Author: Callum
Date: 2026-11-26
Description: 訪客單列印版面(A4 直式、每位訪客一張)。給定一筆訪客記錄詳情就開啟瀏覽器的列印視窗，關閉後通知父層。
***************************************** */
// 用瀏覽器列印，不需要後端 API。依個資考量，訪客單不印手機與 Email。
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { siteBranding } from '../../../stores/auth';
import { formatClock, formatDate, toDateString } from '../format';
import { useVisitReasons } from '../useVisitReasons';
const props = defineProps({
  // 要列印的訪客記錄詳情(fetchVisitRecordDetail 的結果)；null＝不列印。
  record: { type: Object, default: null },
});
const emit = defineEmits(['done']);
const BODY_CLASS = 'printing-visitor-slip';
const { reasonLabel } = useVisitReasons();
const printedAt = ref('');
const floorText = computed(() => {
  const floor = props.record?.floor;
  if(!floor) return '—';
  return floor < 0 ? `B${Math.abs(floor)}` : `${floor}F`;
});
const dateTime = (value) => (value ? `${formatDate(value)} ${formatClock(value)}` : '—');
function now(){
  const date = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  return `${formatDate(toDateString(date))} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
function cleanup(){
  document.body.classList.remove(BODY_CLASS);
  window.removeEventListener('afterprint', finish);
}
function finish(){
  cleanup();
  emit('done');
}
watch(() => props.record, async (record) => {
  if(!record) return;
  printedAt.value = now();
  document.body.classList.add(BODY_CLASS);
  // 有的瀏覽器 print() 不會等視窗關閉才往下執行，所以收尾放在 afterprint 事件。
  window.addEventListener('afterprint', finish, { once: true });
  await nextTick();
  window.print();
});
onBeforeUnmount(cleanup);
</script>
<style>
/* 全域樣式(非 scoped)：要控制 body 底下的其他元素。只有列印訪客單期間(body 有 printing-visitor-slip)才影響列印。
   版面固定用黑白，不跟後臺主題色。 */
.visitor-slips{
  display: none;
}
/* 具名頁面：只有訪客單用 A4 直式、無邊界(不印瀏覽器預設的網址與頁首頁尾)，不影響其他頁面的列印。 */
@page visitor-slip{
  size: A4 portrait;
  margin: 0;
}
@media print{
  body.printing-visitor-slip > *:not(.visitor-slips){
    display: none !important;
  }
  body.printing-visitor-slip .visitor-slips{
    display: block;
    page: visitor-slip;
  }
  .visitor-slip{
    display: flex;
    flex-direction: column;
    box-sizing: border-box;
    height: 296mm;
    padding: 20mm;
    overflow: hidden;
    color: #000;
    break-after: page;
  }
  .visitor-slip:last-child{
    break-after: auto;
  }
  .visitor-slip__head{
    padding-bottom: 6mm;
    text-align: center;
    border-bottom: 2px solid #000;
  }
  .visitor-slip__site{
    font-size: 14pt;
  }
  .visitor-slip__title{
    margin: 2mm 0 0;
    font-size: 28pt;
    letter-spacing: 0.3em;
  }
  .visitor-slip__count{
    margin-top: 2mm;
    font-size: 11pt;
  }
  .visitor-slip__name{
    margin-top: 14mm;
    font-size: 40pt;
    font-weight: 700;
    text-align: center;
    overflow-wrap: anywhere;
  }
  .visitor-slip__company{
    margin: 3mm 0 12mm;
    font-size: 18pt;
    text-align: center;
    overflow-wrap: anywhere;
  }
  .visitor-slip__list{
    margin: 0;
    border-top: 1px solid #000;
  }
  .visitor-slip__list div{
    display: flex;
    gap: 6mm;
    padding: 4mm 2mm;
    font-size: 15pt;
    border-bottom: 1px solid #999;
  }
  .visitor-slip__list dt{
    flex: none;
    width: 32mm;
    font-weight: 700;
  }
  .visitor-slip__list dd{
    min-width: 0;
    margin: 0;
    overflow-wrap: anywhere;
  }
  .visitor-slip__foot{
    margin-top: auto;
    font-size: 9pt;
    text-align: right;
  }
}
</style>
