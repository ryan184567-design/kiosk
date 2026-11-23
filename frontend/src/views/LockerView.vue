<script setup>
// ⑤-1 證件存放（訪客螢幕）：訪客自行點選證件存放格位，將證件放入後按「確認存放」
// 格位資料由 API 提供（getLockers），確認後呼叫 assignLocker 指派，顯示格號並自動進入下一步
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import { InboxOutlined } from '@ant-design/icons-vue'
import KioskLayout from '@/components/KioskLayout.vue'
import LockerGrid from '@/components/LockerGrid.vue'
import { assignLocker, getLockers } from '@/api/visitor'
import { clearStepsFrom, visit } from '@/stores/visit'
import { debug, logWarning } from '@/utils/logger'

const NEXT_DELAY_MS = 2000
const router = useRouter()
const { t } = useI18n()
let timer = null

// 每次進入（含從後面步驟返回）都重新選擇格位
clearStepsFrom('locker')

const columns = ref(6)
const slots = ref([])
const loading = ref(false)
const loadFailed = ref(false)
const selected = ref(null)
const assigning = ref(false)
const selectedSlot = computed(() => slots.value.find((s) => s.id === selected.value))

const loadLockers = async () => {
  loading.value = true
  loadFailed.value = false
  selected.value = null
  try {
    const data = await getLockers()
    columns.value = data.columns || 6
    slots.value = data.slots || []
  } catch (err) {
    logWarning('Flow', '⑤-1 無法取得證件存放格位', err)
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

const confirm = async () => {
  if (!selectedSlot.value) return
  assigning.value = true
  try {
    const locker = await assignLocker(selectedSlot.value)
    visit.locker = { id: locker.id, label: locker.label || locker.id }
    debug('Flow', `⑤-1 訪客將證件存放於 ${visit.locker.label}`, visit.locker)
    timer = setTimeout(() => router.push('/agreement'), NEXT_DELAY_MS)
  } catch (err) {
    logWarning('Flow', `⑤-1 格位指派失敗 ${selectedSlot.value?.label}`, err)
    message.error(t('locker.assignFailed'))
    await loadLockers()
  } finally {
    assigning.value = false
  }
}

onMounted(loadLockers)
onBeforeUnmount(() => clearTimeout(timer))
</script>

<template>
  <KioskLayout back="/identity" :title="visit.locker ? '' : $t('locker.title')" :subtitle="visit.locker ? '' : $t('locker.hint')">
    <!-- 已存放 -->
    <div v-if="visit.locker" class="locker panel">
      <div class="locker__slot">
        <InboxOutlined />
        <span>{{ visit.locker.label }}</span>
      </div>
      <h2 class="locker__title">{{ $t('locker.storedTitle', { slot: visit.locker.label }) }}</h2>
      <p class="locker__hint">{{ $t('locker.storedHint') }}</p>
    </div>

    <!-- 選擇格位 -->
    <div v-else class="picker panel">
      <a-result v-if="loadFailed" status="warning" :title="$t('locker.loadFailed')">
        <template #extra>
          <a-button type="primary" size="large" class="kiosk-btn" @click="loadLockers">
            {{ $t('common.retry') }}
          </a-button>
        </template>
      </a-result>
      <template v-else>
        <a-spin :spinning="loading">
          <LockerGrid v-model:selected="selected" :columns="columns" :slots="slots" />
        </a-spin>
        <a-button
          type="primary"
          size="large"
          block
          class="kiosk-btn picker__confirm"
          :disabled="!selectedSlot"
          :loading="assigning"
          @click="confirm"
        >
          {{ selectedSlot ? $t('locker.confirmSlot', { slot: selectedSlot.label }) : $t('locker.confirm') }}
        </a-button>
      </template>
    </div>
  </KioskLayout>
</template>

<style scoped>
.picker {
  width: min(760px, 100%);
  padding: 28px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  gap: 24px;
}
.picker :deep(.slot) {
  height: 76px;
}
.picker__confirm.ant-btn {
  height: 60px;
  font-size: 20px;
}

.locker {
  width: min(680px, 100%);
  min-height: 440px;
  padding: 48px 32px;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
}
.locker__title {
  margin: 32px 0 0;
  font-size: clamp(26px, 3vw, 34px);
  font-weight: 800;
  color: var(--brand-dark);
}
.locker__hint {
  margin: 12px 0 0;
  font-size: 20px;
  color: var(--muted);
}
.locker__slot {
  width: 190px;
  height: 150px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  color: #fff;
  font-size: 44px;
  background: var(--brand-gradient);
  border-radius: 20px;
  box-shadow: 0 16px 40px rgba(21, 84, 192, 0.35);
  animation: pop 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
}
.locker__slot span {
  font-size: 44px;
  font-weight: 900;
  line-height: 1;
}

@keyframes pop {
  from {
    transform: scale(0.4);
    opacity: 0;
  }
  to {
    transform: scale(1);
    opacity: 1;
  }
}
</style>
