<script setup>
// ⑧ 已通知被拜訪員工，倒數後自動回首頁
import KioskLayout from '@/components/KioskLayout.vue'
import StatusPanel from '@/components/StatusPanel.vue'
import { useCountdownHome } from '@/composables/useCountdownHome'
import { visit } from '@/stores/visit'

const visitor = visit.visitor
const badge = visit.badge
const locker = visit.locker
const { remaining, finish } = useCountdownHome(10)
</script>

<template>
  <KioskLayout>
    <StatusPanel status="sent" :title="$t('notified.title')" :hint="$t('notified.hint')">
      <div class="info">
        <div v-if="visitor?.hostName" class="info__row">
          <span>{{ $t('notified.host') }}</span>
          <strong>{{ visitor.hostDept }} {{ visitor.hostName }}</strong>
        </div>
        <div v-if="badge" class="info__row">
          <span>{{ $t('notified.badgeNo') }}</span>
          <strong>{{ badge.badgeNo }}</strong>
        </div>
        <div v-if="locker" class="info__row">
          <span>{{ $t('locker.slot') }}</span>
          <strong>{{ locker.label }}</strong>
        </div>
      </div>
      <a-button type="primary" size="large" class="kiosk-btn" @click="finish">
        {{ $t('notified.finish') }}
      </a-button>
      <p class="countdown">{{ $t('notified.countdown', { n: remaining }) }}</p>
    </StatusPanel>
  </KioskLayout>
</template>

<style scoped>
.info {
  min-width: 320px;
  margin-bottom: 28px;
  padding: 16px 28px;
  background: var(--brand-light);
  border-radius: 12px;
}
.info__row {
  display: flex;
  justify-content: space-between;
  gap: 32px;
  padding: 6px 0;
  font-size: 19px;
}
.info__row span {
  color: var(--muted);
}
.countdown {
  margin: 16px 0 0;
  color: var(--muted);
  font-size: 16px;
}
</style>
