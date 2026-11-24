<script setup>
// ④ 驗證通過：取得預約資料後自動進入身份驗證
import { onBeforeUnmount, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import KioskLayout from '@/components/KioskLayout.vue'
import StatusPanel from '@/components/StatusPanel.vue'
import { visit } from '@/stores/visit'

const router = useRouter()
let timer

onMounted(() => {
  timer = setTimeout(() => router.replace('/identity'), 2000)
})
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <KioskLayout>
    <StatusPanel status="success" :title="$t('verify.success')" :hint="$t('verify.fetching')">
      <div v-if="visit.visitor" class="summary">
        <div class="summary__party">
          <span class="summary__label">{{ $t('verify.visitor') }}</span>
          <strong>{{ visit.visitor.name }}</strong>
          <span v-if="visit.visitor.company" class="summary__company">{{ visit.visitor.company }}</span>
        </div>
        <span class="summary__arrow">→</span>
        <div class="summary__party">
          <span class="summary__label">{{ $t('verify.host') }}</span>
          <strong>{{ visit.visitor.hostName || '—' }}</strong>
          <span v-if="visit.visitor.hostDept" class="summary__company">{{ visit.visitor.hostDept }}</span>
        </div>
      </div>
    </StatusPanel>
  </KioskLayout>
</template>

<style scoped>
/* 左右兩欄等寬、箭頭置中：兩側公司名稱長短不同也不會跑版 */
.summary {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  align-items: center;
  column-gap: 32px;
  width: min(640px, 100%);
  padding: 18px 32px;
  box-sizing: border-box;
  background: var(--brand-light);
  border-radius: 12px;
}
.summary__party {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-width: 0;
  text-align: center;
}
.summary__label {
  font-size: 15px;
  color: var(--muted);
}
.summary__party strong {
  font-size: 24px;
  font-weight: 700;
  line-height: 1.3;
}
.summary__company {
  font-size: 16px;
  line-height: 1.4;
  color: var(--muted);
  overflow-wrap: anywhere;
}
.summary__arrow {
  font-size: 24px;
  color: var(--brand);
}
</style>
