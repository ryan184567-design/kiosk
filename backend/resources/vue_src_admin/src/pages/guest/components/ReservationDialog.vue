<template>
  <el-dialog
    :model-value="modelValue"
    class="rsv-dialog"
    width="1080px"
    top="4vh"
    :show-close="false"
    :close-on-click-modal="false"
    @update:model-value="emit('update:modelValue', $event)"
    @open="handleOpen"
  >
    <template #header>
      <div class="rsv-dialog__header">
        <i class="fa-regular fa-calendar-plus rsv-dialog__header-icon" aria-hidden="true"></i>
        <span class="rsv-dialog__title">新增預約</span>
        <button type="button" class="rsv-dialog__close" aria-label="關閉" @click="close">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
      </div>
    </template>

    <!-- 預約基本資訊 -->
    <section class="rsv-card">
      <h3 class="rsv-card__title">
        <i class="fa-regular fa-calendar-check" aria-hidden="true"></i>
        預約基本資訊
      </h3>
      <el-form ref="baseFormRef" :model="form" :rules="baseRules" label-width="96px" class="rsv-grid">
        <el-form-item label="預約單號">
          <el-input model-value="系統自動產生" disabled />
        </el-form-item>
        <el-form-item label="預約事由" prop="visit_reason">
          <el-select v-model="form.visit_reason" placeholder="請選擇預約事由" class="rsv-full">
            <el-option v-for="r in visitReasons" :key="r" :label="r" :value="r" />
          </el-select>
        </el-form-item>

        <el-form-item label="預約日期" prop="visit_date">
          <el-date-picker
            v-model="form.visit_date"
            type="date"
            value-format="YYYY-MM-DD"
            format="YYYY/MM/DD"
            placeholder="請選擇日期"
            :disabled-date="isBeforeToday"
            :clearable="false"
            class="rsv-full"
          />
        </el-form-item>
        <el-form-item label="預約時段" prop="visit_end_time">
          <div class="rsv-time">
            <el-select v-model="form.visit_start_time" class="rsv-time__select">
              <el-option v-for="t in timeOptions" :key="t" :label="t" :value="t" />
            </el-select>
            <span class="rsv-time__sep">~</span>
            <el-select v-model="form.visit_end_time" class="rsv-time__select">
              <el-option v-for="t in timeOptions" :key="t" :label="t" :value="t" :disabled="t <= form.visit_start_time" />
            </el-select>
          </div>
        </el-form-item>

        <el-form-item label="受訪公司" prop="doorplate_id">
          <el-select
            v-model="form.doorplate_id"
            filterable
            placeholder="請選擇受訪公司"
            :loading="doorplateLoading"
            no-data-text="沒有可選的公司（請先到基本資料設定新增門牌）"
            class="rsv-full"
          >
            <el-option v-for="d in doorplateOptions" :key="d.id" :label="d.label" :value="d.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="受訪人" prop="host_name">
          <el-input v-model="form.host_name" maxlength="100" placeholder="請輸入受訪人姓名" />
        </el-form-item>

        <el-form-item label="來訪人數" prop="guest_count">
          <div class="rsv-count">
            <el-input-number v-model="form.guest_count" :min="1" :max="MAX_GUESTS" />
            <span>人</span>
          </div>
        </el-form-item>
      </el-form>
    </section>

    <!-- 來訪者資料 -->
    <section class="rsv-card">
      <div class="rsv-card__head">
        <h3 class="rsv-card__title">
          <i class="fa-regular fa-user" aria-hidden="true"></i>
          來訪者資料
        </h3>
        <el-button type="primary" plain size="small" @click="handleSaveVisitor">
          <i class="fa-solid fa-plus rsv-icon" aria-hidden="true"></i>{{ editingIndex === null ? '新增來訪者' : '更新來訪者' }}
        </el-button>
      </div>

      <el-form ref="visitorFormRef" :model="draft" :rules="visitorRules" label-width="96px" class="rsv-grid rsv-grid--column">
        <el-form-item label="來訪者姓名" prop="name">
          <el-input v-model="draft.name" maxlength="100" />
        </el-form-item>
        <el-form-item label="公司名稱" prop="company_name">
          <el-input v-model="draft.company_name" maxlength="150" />
        </el-form-item>
        <el-form-item label="職稱" prop="title">
          <el-input v-model="draft.title" maxlength="100" />
        </el-form-item>
        <el-form-item label="手機號碼" prop="phone">
          <el-input v-model="draft.phone" maxlength="30" placeholder="例如 0912-345-678" />
        </el-form-item>
        <el-form-item label="Email" prop="email">
          <el-input v-model="draft.email" maxlength="150" />
        </el-form-item>
        <el-form-item label="車牌號碼" prop="plate_number">
          <el-input v-model="draft.plate_number" maxlength="20" placeholder="選填" />
        </el-form-item>
      </el-form>

      <el-table :data="visitors" size="small" class="rsv-visitors" empty-text="尚未新增來訪者，請填寫上方資料後按「新增來訪者」">
        <el-table-column type="index" label="#" width="56" />
        <el-table-column prop="name" label="來訪者姓名" min-width="110" />
        <el-table-column prop="company_name" label="公司名稱" min-width="170" />
        <el-table-column label="職稱" min-width="110">
          <template #default="{ row }">{{ row.title || '—' }}</template>
        </el-table-column>
        <el-table-column prop="phone" label="手機號碼" min-width="130" />
        <el-table-column label="車牌號碼" min-width="110">
          <template #default="{ row }">{{ row.plate_number || '—' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="96" align="center">
          <template #default="{ $index }">
            <el-button link type="primary" aria-label="編輯" @click="handleEditVisitor($index)">
              <i class="fa-regular fa-pen-to-square" aria-hidden="true"></i>
            </el-button>
            <el-button link type="danger" aria-label="刪除" @click="handleRemoveVisitor($index)">
              <i class="fa-regular fa-trash-can" aria-hidden="true"></i>
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <div class="rsv-row">
      <!-- 備註 -->
      <section class="rsv-card rsv-card--half">
        <h3 class="rsv-card__title">
          <i class="fa-regular fa-clipboard" aria-hidden="true"></i>
          備註
        </h3>
        <el-input v-model="form.remark" type="textarea" :rows="4" maxlength="200" show-word-limit resize="none" placeholder="請輸入備註（選填）" />
      </section>

      <!-- 通知設定 -->
      <section class="rsv-card rsv-card--half">
        <h3 class="rsv-card__title">
          <i class="fa-regular fa-bell" aria-hidden="true"></i>
          通知設定
        </h3>
        <div class="rsv-notify">
          <el-checkbox v-model="form.is_email_notify">Email 通知受訪人</el-checkbox>
          <el-checkbox v-model="form.is_sms_notify">簡訊通知來訪者（預約成功／變更／取消）</el-checkbox>
          <div class="rsv-notify__remind">
            <el-checkbox v-model="form.is_auto_remind">到訪前自動提醒</el-checkbox>
            <el-tooltip content="目前只會儲存通知設定，系統尚未實際發送信件、簡訊或提醒" placement="top">
              <i class="fa-solid fa-circle-info rsv-notify__hint" aria-hidden="true"></i>
            </el-tooltip>
            <el-select v-model="form.remind_time" :disabled="!form.is_auto_remind" class="rsv-notify__select">
              <el-option v-for="o in remindOptions" :key="o.value" :label="o.label" :value="o.value" />
            </el-select>
          </div>
        </div>
      </section>
    </div>

    <el-alert v-if="submitError" :title="submitError" type="error" show-icon :closable="false" class="rsv-error" />

    <template #footer>
      <div class="rsv-footer">
        <el-button @click="close">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          <i class="fa-regular fa-calendar-check rsv-icon" aria-hidden="true"></i>儲存預約
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>

<script setup>
/* *****************************************
File: ReservationDialog.vue
Author: Callum
Date: 2026-11-19
Description: 預約管理「新增預約」對話框，送出走 POST /api/guest/applies。
***************************************** */

import { reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { createReservation, fetchDoorplates } from '../../../api/guest';
import { MAX_GUESTS, PHONE_MESSAGE, PHONE_PATTERN, VISIT_REASONS } from '../constants';

defineProps({ modelValue: { type: Boolean, default: false } });
const emit = defineEmits(['update:modelValue', 'saved']);

// 預約事由選項、人數上限、手機格式與其他訪客頁共用，見 ../constants.js。
const visitReasons = VISIT_REASONS;

const remindOptions = [
  { value: 15, label: '提前 15 分鐘' },
  { value: 30, label: '提前 30 分鐘' },
  { value: 60, label: '提前 1 小時' },
  { value: 120, label: '提前 2 小時' },
  { value: 1440, label: '提前 1 天' },
];

// 07:00～22:00，每 30 分鐘一格。
const timeOptions = Array.from({ length: 31 }, (_, i) => {
  const minutes = 7 * 60 + i * 30;

  return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
});

function todayString() {
  const d = new Date();

  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function isBeforeToday(date) {
  const start = new Date();
  start.setHours(0, 0, 0, 0);

  return date.getTime() < start.getTime();
}

function emptyForm() {
  return {
    doorplate_id: null,
    visit_reason: '',
    visit_date: todayString(),
    visit_start_time: '10:00',
    visit_end_time: '11:00',
    host_name: '',
    guest_count: 1,
    remark: '',
    is_email_notify: true,
    is_sms_notify: true,
    is_auto_remind: true,
    remind_time: 30,
  };
}

function emptyDraft() {
  return { name: '', company_name: '', title: '', phone: '', email: '', plate_number: '' };
}

const form = reactive(emptyForm());
const draft = reactive(emptyDraft());
const visitors = ref([]);
const editingIndex = ref(null);

const baseFormRef = ref(null);
const visitorFormRef = ref(null);
const submitting = ref(false);
const submitError = ref('');

const doorplateOptions = ref([]);
const doorplateLoading = ref(false);

// 開始時間改到不早於結束時間時，結束時間自動順延一格，避免送出才被擋。
watch(
  () => form.visit_start_time,
  (start) => {
    if (form.visit_end_time <= start) {
      form.visit_end_time = timeOptions.find((t) => t > start) || start;
    }
  },
);

const baseRules = {
  visit_reason: [{ required: true, message: '請選擇預約事由', trigger: 'change' }],
  visit_date: [{ required: true, message: '請選擇預約日期', trigger: 'change' }],
  visit_end_time: [
    {
      validator: (_rule, _value, callback) => {
        if (form.visit_end_time <= form.visit_start_time) {
          callback(new Error('結束時間必須晚於開始時間'));
        } else {
          callback();
        }
      },
      trigger: 'change',
    },
  ],
  doorplate_id: [{ required: true, message: '請選擇受訪公司', trigger: 'change' }],
  host_name: [{ required: true, message: '請輸入受訪人', trigger: 'blur' }],
};

const visitorRules = {
  name: [{ required: true, message: '請輸入來訪者姓名', trigger: 'blur' }],
  company_name: [{ required: true, message: '請輸入公司名稱', trigger: 'blur' }],
  phone: [
    { required: true, message: '請輸入手機號碼', trigger: 'blur' },
    { pattern: PHONE_PATTERN, message: PHONE_MESSAGE, trigger: 'blur' },
  ],
  email: [{ type: 'email', message: 'Email 格式不正確', trigger: 'blur' }],
};

async function loadDoorplates() {
  doorplateLoading.value = true;

  const result = await fetchDoorplates();

  doorplateLoading.value = false;

  if (!result.ok) {
    submitError.value = `受訪公司載入失敗：${result.message}`;

    return;
  }

  doorplateOptions.value = (result.data.items || []).map((d) => ({
    id: d.id,
    label: d.company_name ? `${d.company_name}（${d.floor}F）` : `門牌 ${d.doorplate}（${d.floor}F）`,
  }));
}

function resetAll() {
  Object.assign(form, emptyForm());
  Object.assign(draft, emptyDraft());
  visitors.value = [];
  editingIndex.value = null;
  submitError.value = '';
  baseFormRef.value?.clearValidate();
  visitorFormRef.value?.clearValidate();
}

function handleOpen() {
  resetAll();
  loadDoorplates();
}

function close() {
  emit('update:modelValue', false);
}

async function handleSaveVisitor() {
  submitError.value = '';

  const valid = await visitorFormRef.value.validate().catch(() => false);

  if (!valid) return;

  if (editingIndex.value === null && visitors.value.length >= MAX_GUESTS) {
    submitError.value = `來訪者名單最多 ${MAX_GUESTS} 位`;

    return;
  }

  if (editingIndex.value === null) {
    visitors.value.push({ ...draft });
  } else {
    visitors.value.splice(editingIndex.value, 1, { ...draft });
    editingIndex.value = null;
  }

  // 名單人數超過來訪人數時，自動把來訪人數補到名單人數，省得使用者再回去調整。
  if (form.guest_count < visitors.value.length) {
    form.guest_count = visitors.value.length;
  }

  Object.assign(draft, emptyDraft());
  visitorFormRef.value.clearValidate();
}

function handleEditVisitor(index) {
  Object.assign(draft, emptyDraft(), visitors.value[index]);
  editingIndex.value = index;
}

function handleRemoveVisitor(index) {
  visitors.value.splice(index, 1);

  if (editingIndex.value === index) {
    editingIndex.value = null;
    Object.assign(draft, emptyDraft());
  }
}

// 空字串一律轉成 null 再送，避免選填欄位被存成空字串。
function blankToNull(value) {
  return value === '' || value === undefined ? null : value;
}

function buildPayload() {
  return {
    doorplate_id: form.doorplate_id,
    visit_date: form.visit_date,
    visit_start_time: form.visit_start_time,
    visit_end_time: form.visit_end_time,
    guest_count: form.guest_count,
    host_name: form.host_name.trim(),
    visit_reason: blankToNull(form.visit_reason),
    remark: blankToNull(form.remark),
    is_email_notify: form.is_email_notify,
    is_sms_notify: form.is_sms_notify,
    is_auto_remind: form.is_auto_remind,
    // 後端 Service 的 reminder_time 參數型別是 int（不接受 null），關閉自動提醒
    // 時也要送數字；Service 會在關閉時自行忽略，不會存進資料庫。
    remind_time: form.remind_time,
    visitor: visitors.value.map((v) => ({
      name: v.name.trim(),
      company_name: v.company_name.trim(),
      title: blankToNull(v.title),
      phone: v.phone.trim(),
      email: blankToNull(v.email),
      plate_number: blankToNull(v.plate_number),
    })),
  };
}

async function handleSubmit() {
  submitError.value = '';

  const valid = await baseFormRef.value.validate().catch(() => false);

  if (!valid) return;

  if (visitors.value.length === 0) {
    submitError.value = '請至少新增一位來訪者：填寫來訪者資料後，按「新增來訪者」加入名單';

    return;
  }

  if (visitors.value.length > form.guest_count) {
    submitError.value = '來訪者名單人數不可超過來訪人數';

    return;
  }

  submitting.value = true;

  const result = await createReservation(buildPayload());

  submitting.value = false;

  if (!result.ok) {
    submitError.value = result.message;

    return;
  }

  ElMessage.success(`預約成功，預約單號：${result.data.reservation_id}`);
  emit('saved', result.data);
  close();
}
</script>

<style>
/* el-dialog 內容會被搬到 body 下，scoped 樣式管不到外殼，所以這份用 rsv- 前綴的
   全域樣式；顏色一律走 Element Plus 變數，之後整體換主題色會一起跟著變。
   嚴格 CSP 不允許 inline style，這裡完全不用 :style 與 style 屬性。 */
.rsv-dialog.el-dialog {
  --rsv-border: #e5e7eb;
  --rsv-bg: #f5f7fb;

  max-width: 96vw;
  padding: 0;
  overflow: hidden;
  border-radius: 10px;
}

.rsv-dialog .el-dialog__header {
  padding: 0;
  margin: 0;
}

.rsv-dialog .el-dialog__body {
  max-height: 72vh;
  padding: 16px;
  overflow-y: auto;
  background: var(--rsv-bg);
}

.rsv-dialog .el-dialog__footer {
  padding: 12px 16px;
  background: #fff;
  border-top: 1px solid var(--rsv-border);
}

.rsv-dialog__header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 20px;
  color: #fff;
  background: var(--el-color-primary);
}

.rsv-dialog__header-icon {
  font-size: 20px;
}

.rsv-dialog__title {
  flex: 1;
  font-size: 18px;
  font-weight: 600;
}

.rsv-dialog__close {
  padding: 4px 8px;
  font-size: 20px;
  color: #fff;
  cursor: pointer;
  background: transparent;
  border: 0;
}

.rsv-card {
  padding: 16px 20px 6px;
  margin-bottom: 14px;
  background: #fff;
  border: 1px solid var(--rsv-border);
  border-radius: 8px;
}

.rsv-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.rsv-card__title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 14px;
  font-size: 16px;
  font-weight: 600;
  color: #1f2937;
}

.rsv-card__title i {
  color: var(--el-color-primary);
}

.rsv-card--half {
  flex: 1;
  min-width: 0;
  padding-bottom: 16px;
}

.rsv-row {
  display: flex;
  gap: 14px;
}

.rsv-row .rsv-card {
  margin-bottom: 0;
}

.rsv-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  column-gap: 32px;
}

/* 來訪者資料：左欄三個、右欄三個（依序往下排，不是左右交錯）。 */
.rsv-grid--column {
  grid-template-rows: repeat(3, auto);
  grid-auto-flow: column;
}

.rsv-full {
  width: 100%;
}

.rsv-time {
  display: flex;
  align-items: center;
  gap: 10px;
}

.rsv-time__select {
  width: 130px;
}

.rsv-count {
  display: flex;
  align-items: center;
  gap: 8px;
}

.rsv-visitors {
  margin-bottom: 12px;
}

.rsv-notify {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.rsv-notify__remind {
  display: flex;
  align-items: center;
  gap: 8px;
}

.rsv-notify__hint {
  color: #6b7280;
  cursor: help;
}

.rsv-notify__select {
  width: 150px;
}

.rsv-error {
  margin-top: 14px;
}

.rsv-footer {
  display: flex;
  justify-content: space-between;
}

.rsv-icon {
  margin-right: 6px;
}
</style>
