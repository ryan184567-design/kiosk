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
        <span>{{ visit.visitor.name }}</span>
        <span class="summary__arrow">→</span>
        <span>{{ visit.visitor.hostDept }} {{ visit.visitor.hostName }}</span>
      </div>
    </StatusPanel>
  </KioskLayout>
</template>

<style scoped>
.summary {
  display: flex;
  gap: 12px;
  padding: 12px 24px;
  font-size: 20px;
  font-weight: 600;
  background: var(--brand-light);
  border-radius: 12px;
}
.summary__arrow {
  color: var(--brand);
}
</style>
