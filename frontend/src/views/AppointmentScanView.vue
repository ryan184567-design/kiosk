<script setup>
// ② 有預約：掃描 QR Code 或輸入速通碼（QR Code 的內容即為速通碼）
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import { QrcodeOutlined } from '@ant-design/icons-vue'
import KioskLayout from '@/components/KioskLayout.vue'
import NumericKeypad from '@/components/NumericKeypad.vue'
import { PASS_CODE_LENGTH, clearStepsFrom, isValidPassCode, visit } from '@/stores/visit'
import { useKeyboardScanner } from '@/composables/useKeyboardScanner'
import { debug } from '@/utils/logger'

const router = useRouter()
const { t } = useI18n()

// 每次進入（含從後面步驟返回）都清空，重新掃描或輸入速通碼
clearStepsFrom('passCode')
const passCode = ref('')

/** 送出速通碼（掃描與鍵盤輸入共用同一驗證流程） */
const submitPassCode = (code, source) => {
  visit.passCode = code
  visit.passCodeSource = source
  router.push('/appointment/verifying')
}

const submitKeypad = () => {
  if (!isValidPassCode(passCode.value)) {
    debug('Flow', `② 速通碼格式錯誤（需 ${PASS_CODE_LENGTH} 碼數字）：${passCode.value}`)
    message.warning(t('scan.invalidCode', { n: PASS_CODE_LENGTH }))
    return
  }
  debug('Flow', `② 輸入速通碼：${passCode.value}`)
  submitPassCode(passCode.value, 'keypad')
}

const submitQr = (code) => {
  if (!isValidPassCode(code)) {
    debug('Flow', `② QR Code 內容不是有效的速通碼（${code.length} 字元）：${code}`)
    message.warning(t('scan.invalidQr'))
    return
  }
  debug('Flow', `② 掃描 QR Code 取得速通碼：${code}`)
  submitPassCode(code, 'scan')
}

// QR Code 掃描器輸入（鍵盤輸入只用來接收掃描器資料，手動輸入僅能使用畫面數字鍵盤）
const { rootRef, scanReady, grabFocus } = useKeyboardScanner({
  onScan: submitQr,
  tag: '②',
})
</script>

<template>
  <KioskLayout back="/" :title="$t('scan.title')" :subtitle="$t('scan.subtitle')">
    <div ref="rootRef" class="scan" tabindex="-1">
      <div class="scan__qr panel" @click="grabFocus">
        <div class="scanner">
          <span class="corner tl" /><span class="corner tr" /><span class="corner bl" /><span
            class="corner br"
          />
          <QrcodeOutlined class="scanner__icon" />
          <div class="scanner__line" />
        </div>
        <p class="scan__hint">{{ $t('scan.qrHint') }}</p>
        <p class="scan__status" :class="scanReady ? 'is-ready' : 'is-off'">
          <i class="scan__dot" />
          {{ scanReady ? $t('scan.scannerReady') : $t('scan.scannerNotReady') }}
        </p>
      </div>

      <div class="scan__or">{{ $t('scan.or') }}</div>

      <div class="scan__code panel">
        <NumericKeypad
          v-model="passCode"
          :max-length="PASS_CODE_LENGTH"
          :label="$t('scan.codeLabel')"
          :placeholder="$t('scan.codePlaceholder', { n: PASS_CODE_LENGTH })"
          @confirm="submitKeypad"
        />
      </div>
    </div>
  </KioskLayout>
</template>

<style scoped>
.scan {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  gap: 28px;
  width: 100%;
}
.scan__qr,
.scan__code {
  width: min(400px, 100%);
  min-height: 480px;
  padding: 28px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}
.scan:focus {
  outline: none;
}
.scan__qr {
  cursor: pointer;
}
.scan__status {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  margin: 12px 0 0;
  padding: 6px 14px;
  font-size: 16px;
  font-weight: 600;
  border-radius: 999px;
}
.scan__dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: currentColor;
}
.scan__status.is-ready {
  color: #1f9d55;
  background: #e8f7ee;
}
.scan__status.is-off {
  color: #d46b08;
  background: #fff4e5;
}
.scan__status.is-off .scan__dot {
  animation: blink 1s ease-in-out infinite;
}
@keyframes blink {
  50% {
    opacity: 0.2;
  }
}
.scan__or {
  font-size: 24px;
  font-weight: 800;
  color: var(--brand-dark);
}
.scan__hint {
  margin: 24px 0 0;
  font-size: 18px;
  color: var(--muted);
}

.scanner {
  position: relative;
  width: 260px;
  height: 260px;
  display: grid;
  place-items: center;
  overflow: hidden;
  border-radius: 12px;
}
.scanner__icon {
  font-size: 170px;
  color: var(--text);
}
.scanner__line {
  position: absolute;
  left: 8%;
  right: 8%;
  height: 3px;
  background: linear-gradient(90deg, transparent, #3a8bf5, transparent);
  box-shadow: 0 0 12px #3a8bf5;
  animation: scan 2.2s ease-in-out infinite;
}
.corner {
  position: absolute;
  width: 40px;
  height: 40px;
  border: 5px solid var(--brand);
}
.tl {
  top: 0;
  left: 0;
  border-right: 0;
  border-bottom: 0;
  border-top-left-radius: 12px;
}
.tr {
  top: 0;
  right: 0;
  border-left: 0;
  border-bottom: 0;
  border-top-right-radius: 12px;
}
.bl {
  bottom: 0;
  left: 0;
  border-right: 0;
  border-top: 0;
  border-bottom-left-radius: 12px;
}
.br {
  bottom: 0;
  right: 0;
  border-left: 0;
  border-top: 0;
  border-bottom-right-radius: 12px;
}

@keyframes scan {
  0%,
  100% {
    top: 10%;
  }
  50% {
    top: 88%;
  }
}
</style>
