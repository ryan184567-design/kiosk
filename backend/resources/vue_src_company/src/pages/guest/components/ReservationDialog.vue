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
        <i :class="['rsv-dialog__header-icon', isEdit ? 'fa-regular fa-pen-to-square' : 'fa-regular fa-calendar-plus']" aria-hidden="true"></i>
        <span class="rsv-dialog__title">{{ isEdit ? '編輯預約' : '新增預約' }}</span>
        <button type="button" class="rsv-dialog__close" aria-label="關閉" @click="close">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
      </div>
    </template>
    <!-- 本公司資訊載入失敗：顯示不出受訪公司，但送出不受影響（後端一律用登入者所屬公司），提供重試。 -->
    <LoadErrorAlert v-if="companyError" class="rsv-dialog__notice" :message="companyError" @retry="loadCompanyInfo" />
    <!-- 預約基本資訊 -->
    <section class="rsv-card">
      <h3 class="rsv-card__title">
        <i class="fa-regular fa-calendar-check" aria-hidden="true"></i>
        預約基本資訊
      </h3>
      <el-form ref="baseFormRef" :model="form" :rules="baseRules" label-width="96px" class="rsv-grid">
        <el-form-item label="預約單號">
          <el-input :model-value="isEdit ? reservation.reservation_id : '系統自動產生'" disabled />
        </el-form-item>
        <el-form-item label="預約事由" prop="visit_reason">
          <el-select v-model="form.visit_reason" placeholder="請選擇預約事由" class="rsv-full">
            <el-option v-for="r in reasonOptions" :key="r.value" :label="r.label" :value="r.value" />
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
        <!-- 公司端口：受訪公司固定是登入者所屬公司，不能選（後端也會忽略前端傳的門牌）。 -->
        <el-form-item label="受訪公司">
          <el-input :model-value="companyLabel() || '本公司'" disabled />
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
        <el-form-item label="訪客公司" prop="company_name">
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
        <el-table-column prop="company_name" label="訪客公司" min-width="170" />
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
          <i class="fa-regular fa-calendar-check rsv-icon" aria-hidden="true"></i>{{ isEdit ? '儲存修改' : '儲存預約' }}
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
Description: 預約管理「新增／編輯預約」對話框：新增走 POST /api/guest/applies，編輯走 PUT /api/guest/applies/{id}。
2026-11-27 公司端口版本：受訪公司固定為登入者所屬公司（不選門牌、不送 doorplate_id），
送出後的狀態依本公司審核設定（需要審核 → 待審核；免審核 → 自動核准）。
***************************************** */
import { computed, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { createReservation, updateReservation } from '../../../api/guest';
import { company, companyLabel, loadCompany } from '../../../stores/company';
import { MAX_GUESTS, PHONE_MESSAGE, PHONE_PATTERN } from '../constants';
import { useVisitReasons } from '../useVisitReasons';
import LoadErrorAlert from './LoadErrorAlert.vue';
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // 有帶值＝編輯模式（傳入單筆預約詳情，欄位格式同 fetchReservationDetail 的回傳）；沒帶＝新增。
  reservation: { type: Object, default: null },
  // 新增時預設的預約日期（YYYY-MM-DD），從行事曆按「新增預約」時帶入；過去的日期會被忽略。
  defaultDate: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'saved']);
const isEdit = computed(() => Boolean(props.reservation));
// 預約事由選項來自資料字典（值是數字，見 ../useVisitReasons.js）；人數上限、手機格式與其他訪客頁共用，見 ../constants.js。
const { reasonOptions } = useVisitReasons();
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
function todayString(){
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function isBeforeToday(date){
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  return date.getTime() < start.getTime();
}
function emptyForm(){
  return {
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
function emptyDraft(){
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
// 本公司資訊的載入錯誤（與送出的錯誤 submitError 分開，送出時不會被清掉）。
const companyError = ref('');
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
        if(form.visit_end_time <= form.visit_start_time){
          callback(new Error('結束時間必須晚於開始時間'));
        }else{
          callback();
        }
      },
      trigger: 'change',
    },
  ],
  host_name: [{ required: true, message: '請輸入受訪人', trigger: 'blur' }],
};
const visitorRules = {
  name: [{ required: true, message: '請輸入來訪者姓名', trigger: 'blur' }],
  company_name: [{ required: true, message: '請輸入訪客公司', trigger: 'blur' }],
  phone: [
    { required: true, message: '請輸入手機號碼', trigger: 'blur' },
    { pattern: PHONE_PATTERN, message: PHONE_MESSAGE, trigger: 'blur' },
  ],
  email: [{ type: 'email', message: 'Email 格式不正確', trigger: 'blur' }],
};
// 讀取本公司資訊（受訪公司顯示與送出後的提示要用）。重新讀取，確保審核設定是最新的。
async function loadCompanyInfo(){
  companyError.value = '';
  const result = await loadCompany(true);
  if(!result.ok){
    companyError.value = `本公司資訊載入失敗：${result.message}`;
  }
}
function resetAll(){
  Object.assign(form, emptyForm());
  Object.assign(draft, emptyDraft());
  visitors.value = [];
  editingIndex.value = null;
  submitError.value = '';
  companyError.value = '';
  baseFormRef.value?.clearValidate();
  visitorFormRef.value?.clearValidate();
}
// 編輯：把既有預約帶進表單。
function fillFromReservation(r){
  Object.assign(form, {
    visit_reason: r.visit_reason || '',
    visit_date: r.visit_date,
    visit_start_time: r.visit_start_time,
    visit_end_time: r.visit_end_time,
    host_name: r.host_name || '',
    guest_count: r.guest_count,
    remark: r.remark || '',
    is_email_notify: Boolean(r.is_email_notify),
    is_sms_notify: Boolean(r.is_sms_notify),
    is_auto_remind: Boolean(r.is_auto_remind),
    remind_time: r.remind_time || emptyForm().remind_time,
  });
  visitors.value = (r.guests || []).map((g) => ({
    name: g.name,
    company_name: g.company_name,
    title: g.title || '',
    phone: g.phone,
    email: g.email || '',
    plate_number: g.plate_number || '',
  }));
}
async function handleOpen(){
  resetAll();
  if (!isEdit.value && props.defaultDate && props.defaultDate >= todayString()) {
    form.visit_date = props.defaultDate;
  }
  if(isEdit.value){
    fillFromReservation(props.reservation);
  }
  await loadCompanyInfo();
}
function close(){
  emit('update:modelValue', false);
}
async function handleSaveVisitor(){
  submitError.value = '';
  const valid = await visitorFormRef.value.validate().catch(() => false);
  if(!valid) return;
  if(editingIndex.value === null && visitors.value.length >= MAX_GUESTS){
    submitError.value = `來訪者名單最多 ${MAX_GUESTS} 位`;
    return;
  }
  if(editingIndex.value === null){
    visitors.value.push({ ...draft });
  }else{
    visitors.value.splice(editingIndex.value, 1, { ...draft });
    editingIndex.value = null;
  }
  // 名單人數超過來訪人數時，自動把來訪人數補到名單人數，省得使用者再回去調整。
  if(form.guest_count < visitors.value.length){
    form.guest_count = visitors.value.length;
  }
  Object.assign(draft, emptyDraft());
  visitorFormRef.value.clearValidate();
}
function handleEditVisitor(index){
  Object.assign(draft, emptyDraft(), visitors.value[index]);
  editingIndex.value = index;
}
// 刪除名單中的一位。正在編輯的那位被刪掉，就清空編輯區；刪掉的是排在它前面的人，
// 編輯中的那位在名單裡的位置會往前一格，editingIndex 要跟著減 1，
// 不然按「更新來訪者」會改到別人（或因為位置超出名單而多出一筆）。
function handleRemoveVisitor(index){
  visitors.value.splice(index, 1);
  if(editingIndex.value === null) return;
  if(editingIndex.value === index){
    editingIndex.value = null;
    Object.assign(draft, emptyDraft());
    visitorFormRef.value?.clearValidate();
  }else if(index < editingIndex.value){
    editingIndex.value -= 1;
  }
}
// 空字串一律轉成 null 再送，避免選填欄位被存成空字串。
function blankToNull(value){
  return value === '' || value === undefined ? null : value;
}
function buildPayload(){
  // 不送 doorplate_id：公司端口後端一律用登入者所屬公司。
  return{
    visit_date: form.visit_date,
    visit_start_time: form.visit_start_time,
    visit_end_time: form.visit_end_time,
    guest_count: form.guest_count,
    host_name: form.host_name.trim(),
    // 後端的 visit_reason 是字典項目的數值（整數，必填）。
    visit_reason: Number(form.visit_reason),
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
async function handleSubmit(){
  submitError.value = '';
  const valid = await baseFormRef.value.validate().catch(() => false);
  if(!valid) return;
  if(visitors.value.length === 0) {
    submitError.value = '請至少新增一位來訪者：填寫來訪者資料後，按「新增來訪者」加入名單';
    return;
  }
  if (visitors.value.length > form.guest_count) {
    submitError.value = '來訪者名單人數不可超過來訪人數';
    return;
  }
  submitting.value = true;
  const result = isEdit.value ? await updateReservation(props.reservation.id, buildPayload()) : await createReservation(buildPayload());
  submitting.value = false;
  if (!result.ok) {
    submitError.value = result.message;
    return;
  }
  // 送出後的狀態依本公司審核設定：需要審核 → 待審核／修改待審核；免審核 → 自動核准。
  const autoApproved = !company.approvalRequired;
  if(isEdit.value){
    ElMessage.success(autoApproved ? '已儲存修改（本公司免審核，已自動核准）' : '已儲存修改，預約狀態變更為「修改待審核」，待公司管理員或審核者審核');
  }else{
    ElMessage.success(autoApproved ? `預約成功（已自動核准），預約單號：${result.data.reservation_id}` : `預約已送出，待公司管理員或審核者審核，預約單號：${result.data.reservation_id}`);
  }
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
.rsv-dialog__notice {
  margin-bottom: 14px;
}
.rsv-footer{
  display: flex;
  justify-content: space-between;
}
.rsv-icon{
  margin-right: 6px;
}
</style>
