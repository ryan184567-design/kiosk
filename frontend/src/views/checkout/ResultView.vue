<script setup>
// 離場 ②：送出掃描內容給後端進行離場作業，並顯示結果
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import KioskLayout from '@/components/KioskLayout.vue'
import StatusPanel from '@/components/StatusPanel.vue'
import { checkOut } from '@/api/visitor'
import { useCountdownHome } from '@/composables/useCountdownHome'
import { visit } from '@/stores/visit'
import { debug, logWarning } from '@/utils/logger'

const router = useRouter()
const { t, locale } = useI18n()

/** loading | success | error */
const state = ref('loading')
const errorCode = ref('')
const result = computed(() => visit.checkOut.result)

const { remaining, finish, start } = useCountdownHome(10, { autoStart: false })

// 依後端錯誤代碼顯示對應訊息，未知錯誤顯示一般失敗訊息
const ERROR_KEYS = {
  NOT_FOUND: 'notFound',
  ALREADY_CHECKED_OUT: 'alreadyCheckedOut',
  NOT_CHECKED_IN: 'notCheckedIn',
}
const errorKey = computed(() => ERROR_KEYS[errorCode.value] || 'failed')

const checkOutTime = computed(() => {
  if (!result.value?.checkOutAt) return ''
  return new Date(result.value.checkOutAt).toLocaleTimeString(locale.value, {
    hour: '2-digit',
    minute: '2-digit',
  })
})

onMounted(async () => {
  if (!visit.checkOut.code) return router.replace('/check-out')
  try {
    visit.checkOut.result = await checkOut({ code: visit.checkOut.code })
    debug('Flow', '離場② 離場成功', visit.checkOut.result)
    state.value = 'success'
  } catch (err) {
    errorCode.value = err?.code || ''
    logWarning('Flow', `離場② 離場失敗：${err?.code || err?.message}`)
    state.value = 'error'
  }
  // 成功或失敗都倒數回首頁，避免畫面停留在上一位訪客的結果
  start()
})
</script>

<template>
  <KioskLayout>
    <StatusPanel
      v-if="state === 'loading'"
      status="loading"
      :title="$t('checkOut.processing')"
      :hint="$t('verify.wait')"
    />

    <StatusPanel
      v-else-if="state === 'success'"
      status="success"
      :title="$t('checkOut.successTitle')"
      :hint="$t('checkOut.successHint')"
    >
      <div class="info">
        <div v-if="result?.visitorName" class="info__row">
          <span>{{ $t('checkOut.visitor') }}</span>
          <strong>{{ result.visitorName }}</strong>
        </div>
        <div v-if="checkOutTime" class="info__row">
          <span>{{ $t('checkOut.time') }}</span>
          <strong>{{ checkOutTime }}</strong>
        </div>
      </div>
      <div v-if="result?.locker" class="locker">
        {{ $t('checkOut.lockerReminder', { slot: result.locker.label }) }}
      </div>
      <a-button type="primary" size="large" class="kiosk-btn" @click="finish">
        {{ $t('notified.finish') }}
      </a-button>
      <p class="countdown">{{ $t('notified.countdown', { n: remaining }) }}</p>
    </StatusPanel>

    <StatusPanel
      v-else
      status="error"
      :title="$t(`checkOut.errors.${errorKey}.title`)"
      :hint="$t(`checkOut.errors.${errorKey}.hint`)"
    >
      <a-space :size="16">
        <a-button size="large" class="kiosk-btn" @click="finish">
          {{ $t('common.backHome') }}
        </a-button>
        <a-button
          type="primary"
          size="large"
          class="kiosk-btn"
          @click="router.replace('/check-out')"
        >
          {{ $t('checkOut.rescan') }}
        </a-button>
      </a-space>
      <p class="countdown">{{ $t('notified.countdown', { n: remaining }) }}</p>
    </StatusPanel>
  </KioskLayout>
</template>

<style scoped>
.info {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: min(420px, 100%);
  margin-bottom: 20px;
  padding: 16px 24px;
  font-size: 18px;
  background: var(--brand-light);
  border-radius: 12px;
}
.info__row {
  display: flex;
  justify-content: space-between;
  gap: 24px;
}
.info__row span {
  color: var(--muted);
}
.locker {
  margin-bottom: 24px;
  padding: 14px 24px;
  font-size: 20px;
  font-weight: 700;
  color: #ad4e00;
  background: #fff4e5;
  border: 1px solid #ffd591;
  border-radius: 12px;
}
.countdown {
  margin: 16px 0 0;
  color: var(--muted);
  font-size: 16px;
}
</style>
