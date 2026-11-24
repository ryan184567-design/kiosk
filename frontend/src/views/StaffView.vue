<script setup>
// 櫃台螢幕（/staff）：即時顯示訪客進度與證件存放格位、取消 / 重置本次報到
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Modal, message } from 'ant-design-vue'
import {
  CheckCircleFilled,
  CheckOutlined,
  ClockCircleOutlined,
  DesktopOutlined,
  IdcardOutlined,
  InboxOutlined,
  SettingOutlined,
  StopOutlined,
  WifiOutlined,
} from '@ant-design/icons-vue'
import BrandLogo from '@/components/BrandLogo.vue'
import SettingsDrawer from '@/components/SettingsDrawer.vue'
import { emit, on, realtime } from '@/realtime/socket'
import { useClock } from '@/composables/useClock'
import { debug } from '@/utils/logger'
import { formatPassCode } from '@/stores/visit'

const clock = useClock()
const settingsOpen = ref(false)

// 證件掃描器狀態（伺服器 scanner:status）
const scannerTag = computed(() => {
  const s = realtime.scanner
  if (!s) return { color: 'default', text: '—' }
  if (s.type === 'mock') return { color: 'blue', text: '模擬' }
  if (s.scanning) return { color: 'processing', text: '掃描中' }
  if (s.connected && s.ready) return { color: 'green', text: s.armed ? '等待證件' : '就緒' }
  if (!s.connected) return { color: 'red', text: '未連線' }
  const reason = { OCCUPIED: '被其他程式佔用', NO_DEVICE: '未偵測到掃描器', NO_SOURCE: '無法判斷掃描來源' }[s.errorCode]
  return { color: 'red', text: reason || '未就緒' }
})

// ── 訪客狀態（由訪客螢幕同步） ──
const session = ref(null) // { route, routeName, visit, updatedAt }
const visit = computed(() => session.value?.visit || null)
const routeName = computed(() => session.value?.routeName || 'home')

const APPOINTMENT_STEPS = [
  { name: 'appointment-scan', title: '掃描 / 輸入' },
  { name: 'appointment-verifying', title: '驗證預約' },
  { name: 'appointment-verified', title: '驗證成功' },
  { name: 'identity', title: '身份驗證' },
  { name: 'locker', title: '證件存放' },
  { name: 'agreement', title: '簽署協議' },
  { name: 'printing', title: '列印訪客證' },
  { name: 'notified', title: '完成' },
]
const WALKIN_STEPS = [
  { name: 'walk-in-floor', title: '選擇樓層' },
  { name: 'walk-in-company', title: '選擇公司' },
  { name: 'walk-in-form', title: '填寫資料' },
  { name: 'walk-in-done', title: '已通知受訪公司' },
]
const PURPOSES = {
  business: '業務洽談',
  meeting: '會議',
  interview: '面試',
  delivery: '送貨 / 收件',
  maintenance: '維修 / 施工',
  other: '其他',
}

const steps = computed(() => (visit.value?.mode === 'walkIn' ? WALKIN_STEPS : APPOINTMENT_STEPS))
const currentStep = computed(() => {
  const i = steps.value.findIndex((s) => s.name === routeName.value)
  // 最後一步視為已完成
  return i === steps.value.length - 1 ? steps.value.length : i
})
const isActive = computed(() => !!visit.value?.mode && routeName.value !== 'home')
const modeText = computed(() =>
  visit.value?.mode === 'appointment' ? '有預約' : visit.value?.mode === 'walkIn' ? '無預約' : '—',
)
const updatedAt = computed(() =>
  session.value?.updatedAt
    ? new Date(session.value.updatedAt).toLocaleTimeString('zh-TW', { hour12: false })
    : '',
)

// ── 證件存放格位（由訪客在訪客螢幕自行選擇，櫃台僅顯示） ──
const choosingLocker = computed(() => routeName.value === 'locker' && !visit.value?.locker)

// ── 取消 / 重置 ──
const resetSession = () => {
  Modal.confirm({
    title: '確定要取消本次報到？',
    content: '訪客螢幕將清除所有資料並返回首頁。',
    okText: '取消報到',
    okType: 'danger',
    cancelText: '返回',
    centered: true,
    onOk: () => {
      debug('Flow', '櫃台：取消 / 重置本次報到')
      emit('session:reset')
      message.info('已重置訪客螢幕')
    },
  })
}

// ── socket ──
const offs = []
onMounted(() => {
  offs.push(
    on('session:update', (s) => {
      session.value = s
    }),
  )
})
onBeforeUnmount(() => offs.forEach((off) => off()))
</script>

<template>
  <div class="staff">
    <header class="staff__header">
      <div class="staff__brand">
        <BrandLogo />
        <span class="staff__title">櫃台作業</span>
      </div>
      <div class="staff__status">
        <a-tag :color="realtime.connected ? 'green' : 'red'">
          <WifiOutlined /> 伺服器：{{ realtime.connected ? '已連線' : '未連線' }}
        </a-tag>
        <a-tag :color="realtime.presence.visitor ? 'green' : 'orange'">
          <DesktopOutlined /> 訪客螢幕：{{ realtime.presence.visitor ? '在線' : '離線' }}
        </a-tag>
        <a-tag :color="scannerTag.color" :title="realtime.scanner?.error || realtime.scanner?.device || ''">
          <IdcardOutlined /> 掃描器：{{ scannerTag.text }}
        </a-tag>
        <a-button class="staff__settings" @click="settingsOpen = true"><SettingOutlined /> 系統設定</a-button>
        <time class="staff__clock">{{ clock }}</time>
      </div>
    </header>

    <SettingsDrawer v-model:open="settingsOpen" />

    <main class="staff__body">
      <!-- 訪客進度 -->
      <section class="card panel">
        <div class="card__head">
          <h2>訪客進度</h2>
          <span v-if="updatedAt" class="card__time"><ClockCircleOutlined /> 更新於 {{ updatedAt }}</span>
        </div>

        <a-empty v-if="!isActive" description="等待訪客操作" class="card__empty" />

        <template v-else>
          <ol class="progress">
            <li
              v-for="(s, i) in steps"
              :key="s.name"
              class="progress__item"
              :class="{ 'is-done': i < currentStep, 'is-current': i === currentStep }"
            >
              <span class="progress__no">
                <CheckOutlined v-if="i < currentStep" />
                <template v-else>{{ i + 1 }}</template>
              </span>
              {{ s.title }}
            </li>
          </ol>

          <a-descriptions bordered size="middle" :column="2" class="info">
            <a-descriptions-item label="到訪方式">{{ modeText }}</a-descriptions-item>
            <a-descriptions-item label="目前步驟">
              {{ steps.find((s) => s.name === routeName)?.title || routeName }}
            </a-descriptions-item>

            <!-- 有預約 -->
            <template v-if="visit.mode === 'appointment'">
              <a-descriptions-item label="訪客姓名">{{ visit.visitor?.name || '—' }}</a-descriptions-item>
              <a-descriptions-item label="手機號碼">
                {{ visit.visitor?.phone || '—' }}
              </a-descriptions-item>
              <a-descriptions-item label="訪客公司">{{ visit.visitor?.company || '—' }}</a-descriptions-item>
              <a-descriptions-item label="受訪人">
                {{ visit.visitor ? `${visit.visitor.hostDept || ''} ${visit.visitor.hostName || ''}` : '—' }}
              </a-descriptions-item>
              <a-descriptions-item label="速通碼">
                <template v-if="visit.passCode">
                  {{ formatPassCode(visit.passCode, visit.passCode.length) }}
                  <a-tag :color="visit.passCodeSource === 'scan' ? 'green' : 'blue'">
                    {{ visit.passCodeSource === 'scan' ? '掃描 QR Code' : '手動輸入' }}
                  </a-tag>
                </template>
                <template v-else>—</template>
              </a-descriptions-item>
              <a-descriptions-item label="證件資料">
                <template v-if="visit.identity?.idCard">
                  {{ visit.identity.idCard.name }}／{{ visit.identity.idCard.idNumber }}
                  <a-tag v-if="visit.identity.idCard.manual" color="blue">手動輸入</a-tag>
                </template>
                <template v-else>—</template>
              </a-descriptions-item>
              <a-descriptions-item label="存放格位">
                <strong v-if="visit.locker" class="info__locker">{{ visit.locker.label }}</strong>
                <a-tag v-else-if="choosingLocker" color="orange">訪客選擇中</a-tag>
                <template v-else>—</template>
              </a-descriptions-item>
              <a-descriptions-item label="同意條款">
                <CheckCircleFilled v-if="visit.agreed" class="info__ok" />
                <template v-else>—</template>
              </a-descriptions-item>
              <a-descriptions-item label="訪客證號">{{ visit.badge?.badgeNo || '—' }}</a-descriptions-item>
              <a-descriptions-item label="卡號">{{ visit.badge?.cardNo || '—' }}</a-descriptions-item>
            </template>

            <!-- 無預約 -->
            <template v-else>
              <a-descriptions-item label="樓層">{{ visit.walkIn?.floor?.label || '—' }}</a-descriptions-item>
              <a-descriptions-item label="受訪公司">{{ visit.walkIn?.company?.name || '—' }}</a-descriptions-item>
              <a-descriptions-item label="訪客姓名">{{ visit.walkIn?.form?.name || '—' }}</a-descriptions-item>
              <a-descriptions-item label="手機號碼">{{ visit.walkIn?.form?.phone || '—' }}</a-descriptions-item>
              <a-descriptions-item label="電子郵件">{{ visit.walkIn?.form?.email || '—' }}</a-descriptions-item>
              <a-descriptions-item label="訪客公司">{{ visit.walkIn?.form?.company || '—' }}</a-descriptions-item>
              <a-descriptions-item label="拜訪事由">
                {{ PURPOSES[visit.walkIn?.form?.purposeKey] || visit.walkIn?.form?.purposeLabel || '—' }}
              </a-descriptions-item>
              <a-descriptions-item label="登記編號">{{ visit.registration?.id || '—' }}</a-descriptions-item>
            </template>
          </a-descriptions>
        </template>

        <div class="card__actions">
          <a-button danger size="large" :disabled="!isActive" @click="resetSession">
            <StopOutlined /> 取消 / 重置本次報到
          </a-button>
        </div>
      </section>

      <!-- 證件存放 -->
      <section class="card panel">
        <div class="card__head">
          <h2><InboxOutlined /> 證件存放</h2>
        </div>

        <div class="locker__idle">
          <template v-if="visit?.locker">
            <div class="locker__done">{{ visit.locker.label }}</div>
            <p>本次訪客證件已存放於 {{ visit.locker.label }}</p>
          </template>
          <template v-else-if="choosingLocker">
            <a-spin size="large" />
            <p>訪客正在訪客螢幕選擇證件存放格位</p>
          </template>
          <p v-else>訪客完成身份驗證後，會在訪客螢幕自行選擇證件存放格位，結果將顯示於此</p>
        </div>
      </section>
    </main>
  </div>
</template>

<style scoped>
.staff {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}
.staff__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 14px 28px;
  background: #fff;
  border-bottom: 1px solid var(--line);
  box-shadow: 0 4px 12px rgba(21, 84, 192, 0.06);
}
.staff__brand {
  display: flex;
  align-items: center;
  gap: 16px;
}
.staff__title {
  padding-left: 16px;
  border-left: 2px solid var(--line);
  font-size: 22px;
  font-weight: 800;
  color: var(--brand-dark);
}
.staff__status {
  display: flex;
  align-items: center;
  gap: 8px;
}
.staff__status :deep(.ant-tag) {
  font-size: 14px;
  padding: 4px 10px;
}
.staff__settings {
  margin-left: 4px;
}
.staff__clock {
  margin-left: 8px;
  font-size: 17px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.staff__body {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
  gap: 20px;
  padding: 20px 28px 28px;
}
.card {
  display: flex;
  flex-direction: column;
  gap: 18px;
  padding: 22px 24px;
  min-width: 0;
}
.card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.card__head h2 {
  margin: 0;
  font-size: 20px;
  font-weight: 800;
  color: var(--brand-dark);
}
.card__time {
  font-size: 13px;
  color: var(--muted);
}
.card__empty {
  margin: 60px 0;
}
.card__actions {
  margin-top: auto;
  display: flex;
  justify-content: flex-end;
}
.progress {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: 0;
  padding: 0;
  list-style: none;
}
.progress__item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px 5px 6px;
  font-size: 14px;
  color: #8a97ab;
  background: #f3f6fb;
  border-radius: 999px;
}
.progress__no {
  width: 22px;
  height: 22px;
  display: grid;
  place-items: center;
  font-size: 12px;
  font-weight: 700;
  color: #8a97ab;
  background: #e3e9f3;
  border-radius: 50%;
}
.progress__item.is-done {
  color: var(--brand-dark);
  background: var(--brand-light);
}
.progress__item.is-done .progress__no {
  color: var(--brand);
  background: #d3e3fb;
}
.progress__item.is-current {
  color: #fff;
  font-weight: 700;
  background: var(--brand);
  box-shadow: 0 4px 12px rgba(21, 84, 192, 0.3);
}
.progress__item.is-current .progress__no {
  color: var(--brand);
  background: #fff;
}
.info :deep(.ant-descriptions-item-label) {
  width: 96px;
  white-space: nowrap;
}
.info__locker {
  font-size: 18px;
  color: var(--brand);
}
.info__ok {
  color: #52c41a;
  font-size: 18px;
}
.locker__idle {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  min-height: 240px;
  color: var(--muted);
  font-size: 16px;
  text-align: center;
}
.locker__done {
  padding: 18px 34px;
  font-size: 40px;
  font-weight: 900;
  color: #fff;
  background: var(--brand-gradient);
  border-radius: 16px;
}
@media (max-width: 1000px) {
  .staff__body {
    grid-template-columns: 1fr;
  }
}
</style>
