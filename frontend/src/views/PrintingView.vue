<script setup>
// ⑦ 發行並列印訪客證 → 完成後通知被拜訪員工
//   1. issueBadge：向後端完成報到（guest/done），取回門禁卡號（QR Code 內容）與預約單號（訪客證號）
//   2. printBadge：由本機服務送到 POS 印表機（QR Code、訪客證號、證件存放格位、列印時間）
//   3. notifyHost：通知被拜訪員工
// 重試時不重複發卡、已列印成功也不重複列印
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import KioskLayout from '@/components/KioskLayout.vue'
import StatusPanel from '@/components/StatusPanel.vue'
import { issueBadge, notifyHost, printBadge } from '@/api/visitor'
import { visit } from '@/stores/visit'
import { debug, logWarning } from '@/utils/logger'

const router = useRouter()
const failed = ref(false)
// 報到時證件櫃已被占用或預約狀態不符（後端回 404）：讓訪客重新選擇證件櫃
const slotRejected = ref(false)
let issued = null // 已發行的訪客證 { cardNo, badgeNo }
let printedAt = null // 列印成功的時間

const run = async () => {
  failed.value = false
  slotRejected.value = false
  try {
    if (!issued) {
      try {
        issued = await issueBadge(visit)
      } catch (err) {
        if (err?.code === 'NOT_FOUND') slotRejected.value = true
        throw err
      }
      debug('Flow', `⑦ 取得訪客證：卡號 ${issued.cardNo}、證號 ${issued.badgeNo}`)
    }
    if (!printedAt) {
      const time = new Date().toISOString()
      await printBadge({
        qrContent: issued.cardNo,
        badgeNo: issued.badgeNo,
        locker: visit.locker?.label || '', // 證件存放格位（無存放證件時不列印）
        printedAt: time,
      })
      printedAt = time
      debug('Flow', `⑦ 訪客證列印完成（QR：${issued.cardNo}）`)
    }
    await notifyHost(visit)
    visit.badge = { ...issued, printedAt }
    debug('Flow', `⑦ 已通知員工，證號 ${issued.badgeNo}`)
    router.replace('/notified')
  } catch (err) {
    logWarning('Flow', '⑦ 發卡、列印或通知失敗', err)
    failed.value = true
  }
}

onMounted(run)
</script>

<template>
  <KioskLayout>
    <StatusPanel v-if="!failed" :title="$t('printing.title')" :hint="$t('printing.hint')">
      <template #icon>
        <div class="printer">
          <div class="printer__body"><span class="printer__led" /></div>
          <div class="printer__paper">
            <strong>{{ $t('printing.badge') }}</strong>
            <span>NSST</span>
          </div>
        </div>
      </template>
    </StatusPanel>
    <StatusPanel
      v-else
      status="error"
      :title="slotRejected ? $t('printing.checkInFailTitle') : $t('printing.failTitle')"
      :hint="slotRejected ? $t('printing.slotRejectedHint') : $t('printing.failHint')"
    >
      <a-space :size="16">
        <a-button size="large" class="kiosk-btn" @click="router.replace('/')">
          {{ $t('common.backHome') }}
        </a-button>
        <a-button
          v-if="slotRejected"
          type="primary"
          size="large"
          class="kiosk-btn"
          @click="router.replace('/locker')"
        >
          {{ $t('printing.reselectLocker') }}
        </a-button>
        <a-button v-else type="primary" size="large" class="kiosk-btn" @click="run">
          {{ $t('common.retry') }}
        </a-button>
      </a-space>
    </StatusPanel>
  </KioskLayout>
</template>

<style scoped>
.printer {
  position: relative;
  width: 220px;
  height: 190px;
}
.printer__body {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 110px;
  border-radius: 18px;
  background: linear-gradient(180deg, #f4f7fb, #cfd9e8);
  box-shadow: 0 10px 24px rgba(16, 41, 90, 0.18);
  border-top: 16px solid #233a63;
  box-sizing: border-box;
  z-index: 2;
}
.printer__led {
  position: absolute;
  right: 22px;
  top: 18px;
  width: 26px;
  height: 6px;
  border-radius: 3px;
  background: #3a8bf5;
  animation: blink 1s ease-in-out infinite;
}
.printer__paper {
  position: absolute;
  left: 40px;
  right: 40px;
  top: 80px;
  height: 110px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
  padding-bottom: 14px;
  box-sizing: border-box;
  background: #fff;
  border: 1px solid var(--line);
  border-radius: 0 0 8px 8px;
  box-shadow: 0 8px 16px rgba(16, 41, 90, 0.12);
  font-size: 18px;
  color: var(--text);
  z-index: 1;
  animation: feed 2.4s ease-out infinite;
}
.printer__paper span {
  font-weight: 900;
  font-size: 15px;
  color: var(--brand-dark);
}
@keyframes feed {
  0% {
    transform: translateY(-70px);
  }
  70%,
  100% {
    transform: translateY(0);
  }
}
@keyframes blink {
  50% {
    opacity: 0.25;
  }
}
</style>
