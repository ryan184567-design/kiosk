<script setup>
// ③ 系統驗證預約中
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import KioskLayout from '@/components/KioskLayout.vue'
import StatusPanel from '@/components/StatusPanel.vue'
import { verifyAppointment } from '@/api/visitor'
import { visit } from '@/stores/visit'
import { debug, logWarning } from '@/utils/logger'

const router = useRouter()
const failed = ref(false)

onMounted(async () => {
  if (!visit.passCode) return router.replace('/appointment')
  try {
    const r = await verifyAppointment({ passCode: visit.passCode })
    visit.reservation = r
    visit.visitor = {
      name: r.visitorName,
      phone: r.phone,
      company: r.company,
      hostName: r.hostName,
      hostDept: r.hostDept, // 受訪公司
      guestCount: r.guestCount,
    }
    debug('Flow', '③ 預約驗證成功，取得訪客資料', visit.visitor)
    router.replace('/appointment/verified')
  } catch (err) {
    logWarning('Flow', `③ 預約驗證失敗：${err?.code || err?.message}`)
    failed.value = true
  }
})
</script>

<template>
  <KioskLayout>
    <StatusPanel
      v-if="!failed"
      status="loading"
      :title="$t('verify.verifying')"
      :hint="$t('verify.wait')"
    />
    <StatusPanel v-else status="error" :title="$t('verify.failTitle')" :hint="$t('verify.failHint')">
      <a-space :size="16">
        <a-button size="large" class="kiosk-btn" @click="router.replace('/')">
          {{ $t('common.backHome') }}
        </a-button>
        <a-button
          type="primary"
          size="large"
          class="kiosk-btn"
          @click="router.replace('/appointment')"
        >
          {{ $t('verify.reenter') }}
        </a-button>
      </a-space>
    </StatusPanel>
  </KioskLayout>
</template>
