<script setup>
// 離場 ①：感應門禁卡（訪客證）或掃描速通碼 QR Code
// 讀卡機與 QR Code 掃描器皆為鍵盤模擬輸入，內容直接交給後端判斷類型
import { useRouter } from 'vue-router'
import { CreditCardOutlined, QrcodeOutlined } from '@ant-design/icons-vue'
import KioskLayout from '@/components/KioskLayout.vue'
import { useKeyboardScanner } from '@/composables/useKeyboardScanner'
import { visit } from '@/stores/visit'
import { debug } from '@/utils/logger'

const router = useRouter()

// 每次進入（含從結果頁「重新掃描」返回）都清空
visit.checkOut = { code: '', result: null }

const onScan = (code) => {
  debug('Flow', `離場① 收到掃描內容（${code.length} 字元）：${code}`)
  visit.checkOut.code = code
  router.push('/check-out/result')
}

// 門禁卡號可能較短（例如 8 碼），門檻放寬為 4 字元；人工按鍵已由字元間隔排除
const { rootRef, scanReady, grabFocus } = useKeyboardScanner({
  onScan,
  tag: '離場①',
  minLength: 4,
})
</script>

<template>
  <KioskLayout back="/" :title="$t('checkOut.title')" :subtitle="$t('checkOut.subtitle')">
    <div ref="rootRef" class="checkout" tabindex="-1" @click="grabFocus">
      <div class="checkout__panel panel">
        <div class="checkout__methods">
          <div class="method">
            <div class="method__icon"><CreditCardOutlined /></div>
            <span class="method__title">{{ $t('checkOut.card') }}</span>
            <span class="method__hint">{{ $t('checkOut.cardHint') }}</span>
          </div>
          <div class="checkout__or">{{ $t('scan.or') }}</div>
          <div class="method">
            <div class="method__icon"><QrcodeOutlined /></div>
            <span class="method__title">{{ $t('checkOut.qr') }}</span>
            <span class="method__hint">{{ $t('checkOut.qrHint') }}</span>
          </div>
        </div>

        <p class="checkout__status" :class="scanReady ? 'is-ready' : 'is-off'">
          <i class="checkout__dot" />
          {{ scanReady ? $t('scan.scannerReady') : $t('scan.scannerNotReady') }}
        </p>
      </div>
    </div>
  </KioskLayout>
</template>

<style scoped>
.checkout {
  display: flex;
  justify-content: center;
  width: 100%;
  cursor: pointer;
}
.checkout:focus {
  outline: none;
}
.checkout__panel {
  width: min(820px, 100%);
  min-height: 440px;
  padding: 40px 28px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.checkout__methods {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  gap: clamp(20px, 4vw, 48px);
}
.checkout__or {
  font-size: 24px;
  font-weight: 800;
  color: var(--brand-dark);
}
.method {
  width: 240px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  text-align: center;
}
.method__icon {
  width: 180px;
  height: 180px;
  display: grid;
  place-items: center;
  font-size: 110px;
  color: var(--brand);
  background: var(--brand-light);
  border-radius: 28px;
  animation: pulse 2.2s ease-in-out infinite;
}
.method__title {
  margin-top: 8px;
  font-size: 26px;
  font-weight: 800;
  color: var(--text);
}
.method__hint {
  font-size: 17px;
  color: var(--muted);
}

.checkout__status {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin: 36px 0 0;
  padding: 6px 14px;
  font-size: 16px;
  font-weight: 600;
  border-radius: 999px;
}
.checkout__dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: currentColor;
}
.checkout__status.is-ready {
  color: #1f9d55;
  background: #e8f7ee;
}
.checkout__status.is-off {
  color: #d46b08;
  background: #fff4e5;
}
.checkout__status.is-off .checkout__dot {
  animation: blink 1s ease-in-out infinite;
}

@keyframes blink {
  50% {
    opacity: 0.2;
  }
}
@keyframes pulse {
  0%,
  100% {
    box-shadow: 0 0 0 0 rgba(21, 84, 192, 0.25);
  }
  50% {
    box-shadow: 0 0 0 14px rgba(21, 84, 192, 0);
  }
}
</style>
