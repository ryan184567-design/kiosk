<script setup>
// 無預約 ⑤ 已通知受訪公司，待受訪公司同意後另行通知訪客
import KioskLayout from '@/components/KioskLayout.vue'
import StatusPanel from '@/components/StatusPanel.vue'
import WalkInSteps from '@/components/WalkInSteps.vue'
import { useCountdownHome } from '@/composables/useCountdownHome'
import { visit } from '@/stores/visit'

const floor = visit.walkIn.floor
const company = visit.walkIn.company
const { remaining, finish } = useCountdownHome(15)
</script>

<template>
  <KioskLayout>
    <WalkInSteps :current="4" />
    <StatusPanel
      status="sent"
      compact
      :title="$t('walkIn.doneTitle')"
      :hint="$t('walkIn.doneHint')"
    >
      <div v-if="company" class="target">
        <span>{{ $t('walkIn.visitTo') }}</span>
        <strong>{{ floor?.label }}・{{ company.name }}</strong>
      </div>
      <a-button size="large" class="kiosk-btn" @click="finish">
        {{ $t('common.backHome') }}
      </a-button>
      <p class="countdown">{{ $t('walkIn.countdown', { n: remaining }) }}</p>
    </StatusPanel>
  </KioskLayout>
</template>

<style scoped>
.target {
  display: flex;
  gap: 16px;
  justify-content: center;
  margin-bottom: 24px;
  padding: 12px 24px;
  font-size: 18px;
  background: var(--brand-light);
  border-radius: 12px;
}
.target span {
  color: var(--muted);
}
.countdown {
  margin: 16px 0 0;
  color: var(--muted);
  font-size: 16px;
}
</style>
