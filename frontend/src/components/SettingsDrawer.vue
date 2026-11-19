<script setup>
// 櫃台螢幕：系統設定（直接修改伺服器的 .env；欄位定義由伺服器提供，見 server/envSettings.js）
import { computed, reactive, ref, watch } from 'vue'
import { Modal, message } from 'ant-design-vue'
import { LockOutlined, PoweroffOutlined, PrinterOutlined, ReloadOutlined } from '@ant-design/icons-vue'
import {
  getSettings,
  isServiceUp,
  listPrinterDevices,
  restartService,
  saveSettings,
  testPrint,
  verifySettingsPin,
} from '@/api/settings'
import { debug, logError } from '@/utils/logger'

const open = defineModel('open', { type: Boolean, default: false })

const APPLY = {
  live: { text: '立即生效', color: 'green' },
  reload: { text: '畫面重新整理後生效', color: 'blue' },
  restart: { text: '需重新啟動服務', color: 'orange' },
  kiosk: { text: '下次開啟 Kiosk 瀏覽器生效', color: 'purple' },
}

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const restarting = ref(false)
const loadingDevices = ref(false)
const loadError = ref('')
const activeTab = ref('printer')

const meta = reactive({ platform: '', pinRequired: false, editable: false, restartPending: [], canRestart: false })
const schema = ref([])
const form = reactive({})
const original = ref({})
const secrets = ref({}) // KEY → 是否已設定
const secretInput = reactive({}) // 機密欄位輸入的新值
const secretClear = reactive({}) // 機密欄位是否要清除
const devices = reactive({ printers: [], devices: [] })
const testResult = ref(null)

// PIN（只保留在記憶體，關閉抽屜即清除）
const pin = ref('')
const pinInput = ref('')
const unlocked = ref(false)
const verifying = ref(false)

const canEdit = computed(() => meta.editable && (!meta.pinRequired || unlocked.value))

const fieldsByKey = computed(() => new Map(schema.value.flatMap((g) => g.fields).map((f) => [f.key, f])))

/** 有修改的欄位：{ KEY: 新值 } */
const changes = computed(() => {
  const out = {}
  for (const f of fieldsByKey.value.values()) {
    if (f.type === 'password') {
      if (secretClear[f.key]) out[f.key] = ''
      else if (secretInput[f.key]) out[f.key] = secretInput[f.key]
    } else if (String(form[f.key] ?? '') !== String(original.value[f.key] ?? '')) {
      out[f.key] = String(form[f.key] ?? '')
    }
  }
  return out
})
const dirty = computed(() => Object.keys(changes.value).length > 0)
const groupDirty = (g) => g.fields.some((f) => f.key in changes.value)

const isVisible = (f) => !f.when || Object.entries(f.when).every(([k, list]) => list.includes(String(form[k])))
const isChanged = (f) => f.key in changes.value

const sourceOptions = (f) =>
  f.source === 'printers'
    ? devices.printers.map((p) => ({
        value: p.name,
        label: `${p.name}${p.port ? `（${p.port.length > 24 ? `${p.port.slice(0, 22)}…` : p.port}）` : ''}${p.isDefault ? '　預設' : ''}`,
      }))
    : devices.devices.map((d) => ({ value: d.value, label: `${d.label}${d.ok ? '' : '　（未連線）'}` }))

function applyPayload(data) {
  Object.assign(meta, {
    platform: data.platform,
    pinRequired: data.pinRequired,
    editable: data.editable,
    restartPending: data.restartPending || [],
    canRestart: data.canRestart,
  })
  schema.value = data.schema
  secrets.value = data.secrets || {}
  for (const k of Object.keys(secretInput)) delete secretInput[k]
  for (const k of Object.keys(secretClear)) delete secretClear[k]
  for (const k of Object.keys(form)) delete form[k]
  Object.assign(form, data.values)
  original.value = { ...data.values }
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    applyPayload(await getSettings())
    if (canEdit.value) loadDevices()
  } catch (err) {
    loadError.value = `無法讀取設定：${err.message}`
  } finally {
    loading.value = false
  }
}

async function loadDevices() {
  loadingDevices.value = true
  try {
    const r = await listPrinterDevices(pin.value)
    devices.printers = r.printers || []
    devices.devices = r.devices || []
  } catch (err) {
    handleAuthError(err) || message.error(`無法偵測印表機：${err.message}`)
  } finally {
    loadingDevices.value = false
  }
}

async function unlock() {
  if (!pinInput.value) return
  verifying.value = true
  try {
    await verifySettingsPin(pinInput.value)
    pin.value = pinInput.value
    unlocked.value = true
    pinInput.value = ''
    loadDevices()
  } catch (err) {
    message.error(err.status === 401 ? 'PIN 錯誤' : err.message)
  } finally {
    verifying.value = false
  }
}

// PIN 失效（例如 PIN 已被變更）時重新要求輸入
function handleAuthError(err) {
  if (err.status !== 401) return false
  unlocked.value = false
  pin.value = ''
  meta.pinRequired = true
  message.error('PIN 錯誤或已變更，請重新輸入')
  return true
}

async function save({ silent = false } = {}) {
  const values = { ...changes.value }
  saving.value = true
  try {
    const data = await saveSettings(values, pin.value)
    // 自己修改了 PIN：之後的請求改用新的 PIN
    if ('SETTINGS_PIN' in values) {
      pin.value = values.SETTINGS_PIN
      unlocked.value = !!values.SETTINGS_PIN
    }
    applyPayload(data)
    debug('Settings', '儲存系統設定', { changed: data.changed })
    if (!silent) notifySaved(data.changed)
    return true
  } catch (err) {
    handleAuthError(err) || message.error(err.message)
    return false
  } finally {
    saving.value = false
  }
}

function notifySaved(changed) {
  if (!changed?.length) return message.info('沒有需要儲存的修改')
  const applies = new Set(changed.map((k) => fieldsByKey.value.get(k)?.apply))
  const notes = []
  if (applies.has('reload')) notes.push('訪客螢幕會在回到首頁時自動重新載入')
  if (applies.has('restart')) notes.push('部分設定需重新啟動服務')
  if (applies.has('kiosk')) notes.push('Kiosk 瀏覽器設定於下次開啟時生效')
  message.success(`已儲存 ${changed.length} 項設定${notes.length ? `；${notes.join('，')}` : ''}`, 5)
}

async function saveAndTest() {
  if (dirty.value && !(await save({ silent: true }))) return
  testing.value = true
  testResult.value = null
  try {
    const r = await testPrint(pin.value)
    testResult.value = {
      ok: true,
      text:
        r.type === 'mock'
          ? '目前為「不列印」模式，內容已存到 logs/print-last.txt'
          : `已送出到印表機（${r.bytes} bytes），請確認是否出紙並可掃描 QR Code`,
      preview: r.type === 'mock' ? r.preview : '',
    }
  } catch (err) {
    if (handleAuthError(err)) return
    testResult.value = { ok: false, text: `列印失敗：${err.message}` }
    logError('Settings', `測試列印失敗：${err.message}`, err)
  } finally {
    testing.value = false
  }
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

function restart() {
  const newPort = meta.restartPending.includes('PORT') ? form.PORT : ''
  const target = newPort ? `${location.protocol}//${location.hostname}:${newPort}` : location.origin
  Modal.confirm({
    title: '重新啟動服務？',
    content: `服務會中斷約 10 秒，進行中的訪客報到將被中斷。${newPort ? `重新啟動後改用埠號 ${newPort}。` : ''}`,
    okText: '重新啟動',
    okType: 'danger',
    cancelText: '取消',
    centered: true,
    onOk: async () => {
      restarting.value = true
      try {
        await restartService(pin.value)
      } catch (err) {
        restarting.value = false
        handleAuthError(err) || message.error(err.message)
        return
      }
      const hide = message.loading('服務重新啟動中…', 0)
      await sleep(3000)
      for (let i = 0; i < 30; i++) {
        const up = newPort
          ? await fetch(`${target}/health`, { mode: 'no-cors', cache: 'no-store' }).then(
              () => true,
              () => false,
            )
          : await isServiceUp()
        if (up) {
          hide()
          location.href = `${target}/staff`
          return
        }
        await sleep(1000)
      }
      hide()
      restarting.value = false
      message.error('服務未在 30 秒內恢復，請檢查 logs/service.log', 8)
    },
  })
}

function close() {
  if (!dirty.value) return (open.value = false)
  Modal.confirm({
    title: '尚有未儲存的修改',
    content: '確定要放棄修改並關閉？',
    okText: '放棄修改',
    cancelText: '繼續編輯',
    centered: true,
    onOk: () => (open.value = false),
  })
}

function discard() {
  Object.assign(form, original.value)
  for (const k of Object.keys(secretInput)) delete secretInput[k]
  for (const k of Object.keys(secretClear)) delete secretClear[k]
}

watch(open, (v) => {
  if (v) return load()
  pin.value = ''
  pinInput.value = ''
  unlocked.value = false
  testResult.value = null
})
</script>

<template>
  <a-drawer :open="open" title="系統設定" placement="right" :width="640" :mask-closable="false" @close="close">
    <a-spin :spinning="loading">
      <a-alert v-if="loadError" type="error" show-icon :message="loadError" class="block" />

      <template v-else-if="schema.length">
        <a-alert
          v-if="!meta.editable"
          type="warning"
          show-icon
          message="只能在本機（訪客機這台電腦）修改設定"
          description="如需從其他電腦修改，請在本機「安全」分頁設定 PIN。"
          class="block"
        />
        <div v-else-if="meta.pinRequired && !unlocked" class="pin block">
          <p><LockOutlined /> 請輸入 PIN 以修改設定</p>
          <div class="pin__row">
            <a-input-password v-model:value="pinInput" placeholder="PIN" autocomplete="off" @press-enter="unlock" />
            <a-button type="primary" :loading="verifying" @click="unlock">解鎖</a-button>
          </div>
        </div>

        <a-alert v-if="meta.restartPending.length" type="warning" show-icon class="block">
          <template #message>
            {{ meta.restartPending.join('、') }} 已修改，需重新啟動服務才會生效
          </template>
          <template v-if="!meta.canRestart" #description>
            目前服務不是由 start.bat / systemd 啟動，請手動重新啟動。
          </template>
        </a-alert>

        <a-tabs v-model:active-key="activeTab">
          <a-tab-pane v-for="g in schema" :key="g.id">
            <template #tab>
              <a-badge :dot="groupDirty(g)" :offset="[6, 0]">{{ g.title }}</a-badge>
            </template>

            <a-form layout="vertical" :disabled="!canEdit">
              <template v-for="f in g.fields" :key="f.key">
                <a-form-item v-if="isVisible(f)" :extra="f.help" :class="{ 'is-changed': isChanged(f) }">
                  <template #label>
                    <span class="label">
                      {{ f.label }}
                      <code class="label__key">{{ f.key }}</code>
                      <a-tag :color="APPLY[f.apply]?.color" class="label__tag">{{ APPLY[f.apply]?.text }}</a-tag>
                    </span>
                  </template>

                  <a-select v-if="f.type === 'select'" v-model:value="form[f.key]" :options="f.options" />

                  <a-input-number
                    v-else-if="f.type === 'number'"
                    v-model:value="form[f.key]"
                    :min="f.min"
                    :max="f.max"
                    :precision="0"
                    :string-mode="true"
                    class="full"
                  />

                  <a-switch
                    v-else-if="f.type === 'switch'"
                    :checked="form[f.key] === 'true'"
                    @update:checked="(v) => (form[f.key] = v ? 'true' : 'false')"
                  />

                  <div v-else-if="f.type === 'password'" class="secret">
                    <a-input-password
                      v-model:value="secretInput[f.key]"
                      :disabled="!canEdit || secretClear[f.key]"
                      :placeholder="secrets[f.key] ? '已設定（留空表示不變更）' : '未設定'"
                      autocomplete="new-password"
                    />
                    <a-checkbox v-if="secrets[f.key]" v-model:checked="secretClear[f.key]">清除</a-checkbox>
                  </div>

                  <div v-else-if="f.source" class="with-refresh">
                    <a-auto-complete
                      v-model:value="form[f.key]"
                      :options="sourceOptions(f)"
                      :placeholder="f.placeholder"
                      allow-clear
                    />
                    <a-button :loading="loadingDevices" title="重新偵測" @click="loadDevices">
                      <ReloadOutlined />
                    </a-button>
                  </div>

                  <a-input v-else v-model:value="form[f.key]" :placeholder="f.placeholder" :maxlength="f.max || 300" />
                </a-form-item>
              </template>
            </a-form>

            <template v-if="g.id === 'printer'">
              <a-button :disabled="!canEdit" :loading="testing" @click="saveAndTest">
                <PrinterOutlined /> {{ groupDirty(g) ? '儲存並測試列印' : '測試列印' }}
              </a-button>
              <a-alert
                v-if="testResult"
                :type="testResult.ok ? 'success' : 'error'"
                show-icon
                :message="testResult.text"
                class="test-result"
              >
                <template v-if="testResult.preview" #description>
                  <pre class="preview">{{ testResult.preview }}</pre>
                </template>
              </a-alert>
            </template>
          </a-tab-pane>
        </a-tabs>
      </template>
    </a-spin>

    <template #footer>
      <div class="footer">
        <a-button
          v-if="meta.restartPending.length"
          danger
          :disabled="!canEdit || !meta.canRestart || dirty"
          :loading="restarting"
          @click="restart"
        >
          <PoweroffOutlined /> 重新啟動服務
        </a-button>
        <span v-else />
        <div class="footer__right">
          <a-button :disabled="!dirty" @click="discard">復原修改</a-button>
          <a-button type="primary" :disabled="!canEdit || !dirty" :loading="saving" @click="save()">
            儲存{{ dirty ? `（${Object.keys(changes).length}）` : '' }}
          </a-button>
        </div>
      </div>
    </template>
  </a-drawer>
</template>

<style scoped>
.block {
  margin-bottom: 16px;
}
.pin p {
  margin: 0 0 8px;
  font-weight: 600;
}
.pin__row,
.with-refresh,
.secret {
  display: flex;
  align-items: center;
  gap: 8px;
}
.pin__row > :first-child,
.with-refresh > :first-child,
.secret > :first-child {
  flex: 1;
}
.full {
  width: 100%;
}
.label {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.label__key {
  font-size: 12px;
  color: var(--muted, #8a97ab);
}
.label__tag {
  margin: 0;
  font-size: 12px;
  line-height: 18px;
}
.is-changed :deep(.ant-form-item-label > label)::before {
  content: '●';
  margin-right: 4px;
  color: #fa8c16;
  font-size: 10px;
}
.test-result {
  margin-top: 12px;
}
.preview {
  margin: 8px 0 0;
  font-size: 12px;
  white-space: pre-wrap;
}
.footer {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}
.footer__right {
  display: flex;
  gap: 8px;
}
</style>
