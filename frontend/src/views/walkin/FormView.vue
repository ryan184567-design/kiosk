<script setup>
// 無預約 ④ 填寫訪客基本資料 → 送出登記（系統通知受訪公司）
import { computed, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { message } from 'ant-design-vue'
import KioskLayout from '@/components/KioskLayout.vue'
import WalkInSteps from '@/components/WalkInSteps.vue'
import { registerWalkIn } from '@/api/visitor'
import { visit } from '@/stores/visit'
import { debug, logWarning } from '@/utils/logger'

const router = useRouter()
const { t } = useI18n()
const formRef = ref()
const submitting = ref(false)

const PURPOSES = ['business', 'meeting', 'interview', 'delivery', 'maintenance', 'other']

// 每次進入（含返回後重新進入）都是空白表單
visit.walkIn.form = null
const form = reactive({
  name: '',
  phone: '',
  email: '',
  company: '',
  purpose: undefined,
})

const purposeOptions = computed(() =>
  PURPOSES.map((p) => ({ value: p, label: t(`walkIn.purposes.${p}`) })),
)

const rules = computed(() => {
  const required = { required: true, message: t('walkIn.required'), trigger: 'blur' }
  return {
    name: [required],
    phone: [required, { pattern: /^09\d{8}$/, message: t('walkIn.invalidPhone'), trigger: 'blur' }],
    email: [required, { type: 'email', message: t('walkIn.invalidEmail'), trigger: 'blur' }],
    company: [required],
    purpose: [{ ...required, trigger: 'change' }],
  }
})

const submit = async () => {
  try {
    await formRef.value.validate()
  } catch (err) {
    debug('Flow', '④ 無預約：表單驗證未通過', err?.errorFields?.map((f) => f.name.join('.')))
    return
  }
  submitting.value = true
  try {
    visit.walkIn.form = { ...form }
    debug('Flow', '④ 無預約：送出登記', visit.walkIn)
    visit.registration = await registerWalkIn({
      floor: visit.walkIn.floor,
      company: visit.walkIn.company,
      visitor: { ...form },
    })
    debug('Flow', `④ 無預約：登記成功，登記編號 ${visit.registration.id}`)
    router.replace('/walk-in/done')
  } catch (err) {
    logWarning('Flow', '④ 無預約：登記失敗', err)
    message.error(t('walkIn.submitFailed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <KioskLayout back="/walk-in/company" :title="$t('walkIn.formTitle')">
    <WalkInSteps :current="2" />
    <div class="form panel">
      <div class="form__target">
        <span>{{ $t('walkIn.visitTo') }}</span>
        <strong>{{ visit.walkIn.floor?.label }}・{{ visit.walkIn.company?.name }}</strong>
      </div>

      <a-form
        ref="formRef"
        :model="form"
        :rules="rules"
        :label-col="{ flex: '130px' }"
        :wrapper-col="{ flex: 'auto' }"
        label-align="left"
        :colon="false"
        size="large"
        class="form__body"
      >
        <a-form-item :label="$t('walkIn.name')" name="name">
          <a-input v-model:value="form.name" :maxlength="30" />
        </a-form-item>
        <a-form-item :label="$t('walkIn.phone')" name="phone">
          <a-input
            v-model:value="form.phone"
            inputmode="numeric"
            :maxlength="10"
            placeholder="0912345678"
          />
        </a-form-item>
        <a-form-item :label="$t('walkIn.email')" name="email">
          <a-input
            v-model:value="form.email"
            inputmode="email"
            :maxlength="100"
            placeholder="name@example.com"
          />
        </a-form-item>
        <a-form-item :label="$t('walkIn.company')" name="company">
          <a-input
            v-model:value="form.company"
            :maxlength="50"
            :placeholder="$t('walkIn.companyPlaceholder')"
          />
        </a-form-item>
        <a-form-item :label="$t('walkIn.purpose')" name="purpose">
          <a-select
            v-model:value="form.purpose"
            :options="purposeOptions"
            :placeholder="$t('walkIn.purposePlaceholder')"
          />
        </a-form-item>
      </a-form>

      <a-button
        type="primary"
        size="large"
        block
        class="kiosk-btn"
        :loading="submitting"
        @click="submit"
      >
        {{ $t('walkIn.submit') }}
      </a-button>
    </div>
  </KioskLayout>
</template>

<style scoped>
.form {
  width: min(720px, 100%);
  padding: 24px 40px 28px;
  box-sizing: border-box;
}
.form__body :deep(.ant-form-item) {
  margin-bottom: 18px;
}
.form__target {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 20px;
  padding: 14px 20px;
  font-size: 18px;
  background: var(--brand-light);
  border-radius: 12px;
}
.form__target span {
  color: var(--muted);
  white-space: nowrap;
}
.form__body :deep(.ant-form-item-label > label) {
  font-size: 18px;
  font-weight: 700;
}
.form__body :deep(.ant-input),
.form__body :deep(.ant-select-selector) {
  font-size: 20px !important;
}
.form__body :deep(.ant-input),
.form__body :deep(.ant-select-single.ant-select-lg .ant-select-selector) {
  height: 50px !important;
}
.form__body :deep(.ant-select-single.ant-select-lg .ant-select-selector .ant-select-selection-item),
.form__body
  :deep(.ant-select-single.ant-select-lg .ant-select-selector .ant-select-selection-placeholder) {
  line-height: 48px;
}
@media (max-width: 600px) {
  .form {
    padding: 20px;
  }
}
</style>
