<script setup>
// ⑤ 身份驗證：人臉辨識 / 證件掃描 擇一即可
// 證件掃描：進入此頁後插入證件即自動掃描辨識（server/idscanner，Plustek WebFXScan）；
//           「開始掃描」為重試，亦可手動輸入
// ※ 人臉辨識目前不使用，相關程式先註解保留；要啟用時取消下方標示「人臉辨識」的註解即可
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { CheckCircleFilled, IdcardOutlined } from '@ant-design/icons-vue'
// 人臉辨識：import { UserOutlined } from '@ant-design/icons-vue'
import KioskLayout from '@/components/KioskLayout.vue'
import { scanIdCard } from '@/api/visitor'
// 人臉辨識：import { verifyFace } from '@/api/visitor'
import { clearStepsFrom, isIdentityComplete, needsLocker, visit } from '@/stores/visit'
import { debug, logWarning } from '@/utils/logger'
import { emit, on, realtime } from '@/realtime/socket'

const router = useRouter()
const { t } = useI18n()

// 每次進入（含從後面步驟返回）都清空證件資料與之後的格位、同意條款，需重新掃描 / 輸入證件
clearStepsFrom('identity')

// idle | processing | done | failed
const idState = ref('idle')

const backPath = '/appointment'
const complete = computed(() => isIdentityComplete())

/* 人臉辨識
const faceState = ref(visit.identity.face ? 'done' : 'idle')

const runFace = async () => {
  faceState.value = 'processing'
  try {
    await verifyFace()
    visit.identity.face = true
    faceState.value = 'done'
  } catch {
    faceState.value = 'failed'
  }
}
*/

const applyCard = (card, source) => {
  if (idState.value === 'done') return
  visit.identity.idCard = { ...card, manual: false }
  idState.value = 'done'
  manualOpen.value = false
  debug('Flow', `⑤ 證件掃描完成（${source === 'auto' ? '插入證件自動掃描' : '手動掃描'}）`, visit.identity.idCard)
}

// 掃描失敗的提示：無法辨識 → 請重新放置；掃描器離線 → 請改用手動輸入
const failReason = ref('')
const markFailed = (err) => {
  failReason.value = err?.code === 'OFFLINE' || err?.code === 'NO_DEVICE' ? 'offline' : ''
  idState.value = 'failed'
}

// 手動掃描（自動掃描失敗或證件已放入時的重試）
const runIdScan = async () => {
  idState.value = 'processing'
  try {
    applyCard(await scanIdCard(), 'manual')
  } catch (err) {
    logWarning('Flow', '⑤ 證件掃描失敗', err)
    markFailed(err)
  }
}

// ── 插入證件自動掃描：在此頁時通知伺服器等待證件，結果經 socket 送達 ──
const scanner = computed(() => realtime.scanner)
const scannerOffline = computed(
  () => scanner.value?.type === 'plustek' && !(scanner.value.connected && scanner.value.ready),
)
const offs = []
const arm = () => emit('idcard:arm')
onMounted(() => {
  arm()
  offs.push(
    on('connect', arm), // 斷線重連（例如服務重新啟動）後重新等待證件
    on('idcard:scanning', () => {
      if (idState.value !== 'done') idState.value = 'processing'
    }),
    on('idcard:result', ({ card }) => applyCard(card, 'auto')),
    on('idcard:error', (err) => {
      if (idState.value === 'done') return
      logWarning('Flow', `⑤ 自動掃描失敗：${err.message}`, err)
      markFailed(err)
    }),
  )
})
onBeforeUnmount(() => {
  emit('idcard:disarm')
  offs.forEach((off) => off())
})

// 手動輸入證件資料
const manualOpen = ref(false)
const manualFormRef = ref()
const manualForm = reactive({ name: '', idNumber: '' })
const manualRules = computed(() => ({
  name: [{ required: true, message: t('identity.required') }],
  idNumber: [{ required: true, message: t('identity.required') }],
}))

// 每次開啟都是空白表單
const openManual = () => {
  manualForm.name = ''
  manualForm.idNumber = ''
  manualFormRef.value?.clearValidate()
  manualOpen.value = true
}

const submitManual = async () => {
  try {
    await manualFormRef.value.validate()
  } catch {
    return
  }
  visit.identity.idCard = { ...manualForm, manual: true }
  idState.value = 'done'
  debug('Flow', '⑤ 手動輸入證件資料', visit.identity.idCard)
  manualOpen.value = false
}

// 有證件資料 → 先選擇證件存放格位；僅人臉辨識 → 直接簽署協議
const next = () => {
  debug('Flow', `⑤ 身份驗證完成，下一步：${needsLocker() ? '選擇證件存放格位' : '簽署協議'}`, visit.identity)
  router.push(needsLocker() ? '/locker' : '/agreement')
}
</script>

<template>
  <KioskLayout :back="backPath" :title="$t('identity.title')">
    <div class="identity">
      <!-- 人臉辨識（目前不使用，先註解）
      <section class="card panel">
        <h2 class="card__title">{{ $t('identity.face') }}</h2>
        <p class="card__hint">{{ $t('identity.faceHint') }}</p>
        <div class="camera" :class="{ 'is-done': faceState === 'done' }">
          <span class="corner tl" /><span class="corner tr" /><span class="corner bl" /><span
            class="corner br"
          />
          <UserOutlined class="camera__icon" />
          <div v-if="faceState === 'processing'" class="camera__scan" />
          <CheckCircleFilled v-if="faceState === 'done'" class="badge-ok" />
        </div>
        <p v-if="faceState === 'failed'" class="card__error">{{ $t('identity.failed') }}</p>
        <a-button
          v-if="faceState !== 'done'"
          type="primary"
          size="large"
          class="kiosk-btn card__btn"
          :loading="faceState === 'processing'"
          @click="runFace"
        >
          {{ faceState === 'processing' ? $t('identity.processing') : $t('identity.startFace') }}
        </a-button>
        <div v-else class="card__done">{{ $t('identity.done') }}</div>
      </section>
      -->

      <!-- 證件掃描 -->
      <section class="card panel">
        <h2 class="card__title">{{ $t('identity.idCard') }}</h2>
        <p class="card__hint">{{ $t('identity.insertCard') }}</p>
        <div class="reader" :class="{ 'is-done': idState === 'done' }">
          <div class="reader__card">
            <IdcardOutlined />
          </div>
          <div v-if="idState === 'processing'" class="camera__scan" />
          <CheckCircleFilled v-if="idState === 'done'" class="badge-ok" />
        </div>
        <p v-if="idState === 'failed'" class="card__error">
          {{ failReason === 'offline' ? $t('identity.scannerOffline') : $t('identity.failed') }}
        </p>
        <p v-else-if="scannerOffline && idState !== 'done'" class="card__warn">
          {{ $t('identity.scannerOffline') }}
        </p>
        <template v-if="idState !== 'done'">
          <a-space :size="12" class="card__btn">
            <a-button
              type="primary"
              size="large"
              class="kiosk-btn btn-half"
              :loading="idState === 'processing'"
              @click="runIdScan"
            >
              {{ idState === 'processing' ? $t('identity.processing') : $t('identity.startScan') }}
            </a-button>
            <a-button
              size="large"
              class="kiosk-btn btn-half"
              :disabled="idState === 'processing'"
              @click="openManual"
            >
              {{ $t('identity.manual') }}
            </a-button>
          </a-space>
        </template>
        <div v-else class="card__done">
          {{ $t('identity.done') }}
          <span v-if="visit.identity.idCard">（{{ visit.identity.idCard.name }}）</span>
        </div>
      </section>
    </div>

    <a-button
      type="primary"
      size="large"
      class="kiosk-btn next"
      :disabled="!complete"
      @click="next"
    >
      {{ $t('common.next') }}
    </a-button>

    <a-modal
      v-model:open="manualOpen"
      :title="$t('identity.manualTitle')"
      :ok-text="$t('common.confirm')"
      :cancel-text="$t('common.cancel')"
      centered
      @ok="submitManual"
    >
      <a-form
        ref="manualFormRef"
        :model="manualForm"
        :rules="manualRules"
        layout="vertical"
        size="large"
      >
        <a-form-item :label="$t('identity.name')" name="name">
          <a-input v-model:value="manualForm.name" :maxlength="30" />
        </a-form-item>
        <a-form-item :label="$t('identity.idNumber')" name="idNumber">
          <a-input v-model:value="manualForm.idNumber" :maxlength="20" />
        </a-form-item>
      </a-form>
    </a-modal>
  </KioskLayout>
</template>

<style scoped>
.identity {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 28px;
  width: 100%;
}
.card {
  width: min(440px, 100%);
  padding: 28px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
}
.card__title {
  margin: 0;
  font-size: 26px;
  font-weight: 800;
}
.card__hint {
  margin: 6px 0 20px;
  font-size: 17px;
  color: var(--muted);
}
.card__error {
  margin: 12px 0 0;
  color: #d9363e;
  font-size: 16px;
}
.card__warn {
  margin: 12px 0 0;
  color: #d97706;
  font-size: 16px;
}
.card__btn {
  margin-top: 20px;
}
.card__done {
  margin-top: 20px;
  height: 60px;
  display: flex;
  align-items: center;
  font-size: 20px;
  font-weight: 700;
  color: #1f9d55;
}
.btn-half.ant-btn {
  min-width: 170px;
}

.camera,
.reader {
  position: relative;
  width: 100%;
  height: 260px;
  border-radius: 16px;
  display: grid;
  place-items: center;
  overflow: hidden;
  transition: box-shadow 0.3s;
}
.camera {
  background: linear-gradient(180deg, #f2f6fc, #dfe8f5);
}
.camera__icon {
  font-size: 150px;
  color: #9fb3d1;
}
.reader {
  background: linear-gradient(180deg, #2c3442, #171c25);
}
.reader__card {
  width: 62%;
  aspect-ratio: 1.58;
  display: grid;
  place-items: center;
  font-size: 80px;
  color: var(--brand);
  background: linear-gradient(135deg, #f7faff, #d9e6f9);
  border-radius: 12px;
  box-shadow: 0 6px 18px rgba(0, 0, 0, 0.35);
}
.is-done {
  box-shadow: 0 0 0 4px #52c41a inset;
}
.is-done .corner {
  display: none;
}
.camera__scan {
  position: absolute;
  left: 6%;
  right: 6%;
  height: 4px;
  background: linear-gradient(90deg, transparent, #3a8bf5, transparent);
  box-shadow: 0 0 14px #3a8bf5;
  animation: sweep 1.4s ease-in-out infinite;
}
.badge-ok {
  position: absolute;
  right: 14px;
  bottom: 14px;
  font-size: 48px;
  color: #52c41a;
  background: #fff;
  border-radius: 50%;
}
.corner {
  position: absolute;
  width: 34px;
  height: 34px;
  border: 4px solid var(--brand);
}
.tl {
  top: 14px;
  left: 14px;
  border-right: 0;
  border-bottom: 0;
}
.tr {
  top: 14px;
  right: 14px;
  border-left: 0;
  border-bottom: 0;
}
.bl {
  bottom: 14px;
  left: 14px;
  border-right: 0;
  border-top: 0;
}
.br {
  bottom: 14px;
  right: 14px;
  border-left: 0;
  border-top: 0;
}
.next {
  margin-top: 32px;
}

@keyframes sweep {
  0%,
  100% {
    top: 8%;
  }
  50% {
    top: 90%;
  }
}
</style>
