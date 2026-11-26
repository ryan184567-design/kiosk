<template>
  <div class="reg-page">
    <el-alert v-if="registrationIsMock" class="reg-page__notice" type="info" show-icon :closable="false" title="送出目前是示意：後端還沒有現場登記的 API，按送出只會顯示成功訊息，資料不會真的儲存。" />
    <section class="reg-card">
      <h3 class="reg-card__title">來訪者資料</h3>
      <el-form ref="formRef" :model="form" :rules="rules" label-width="96px" class="reg-grid">
        <div class="reg-col">
          <el-form-item label="來訪者姓名" prop="name">
            <el-input v-model="form.name" maxlength="100" />
          </el-form-item>
          <el-form-item label="手機號碼" prop="phone">
            <el-input v-model="form.phone" maxlength="30" placeholder="例如 0912-345-678" />
          </el-form-item>
          <el-form-item label="公司名稱" prop="company_name">
            <el-input v-model="form.company_name" maxlength="150" />
          </el-form-item>
          <el-form-item label="職稱" prop="title">
            <el-input v-model="form.title" maxlength="100" />
          </el-form-item>
          <el-form-item label="車牌號碼" prop="plate_number">
            <el-input v-model="form.plate_number" maxlength="20" placeholder="請輸入車牌號碼（選填）" />
          </el-form-item>
        </div>
        <div class="reg-col">
          <el-form-item label="來訪人數" prop="guest_count">
            <div class="reg-count">
              <el-input-number v-model="form.guest_count" :min="1" :max="MAX_GUESTS" />
              <span>人</span>
            </div>
          </el-form-item>
          <el-form-item label="來訪事由" prop="visit_reason">
            <el-select v-model="form.visit_reason" placeholder="請選擇來訪事由" class="reg-full">
              <el-option v-for="r in reasonOptions" :key="r.value" :label="r.label" :value="r.value" />
            </el-select>
          </el-form-item>
          <el-form-item label="備註" prop="remark">
            <el-input v-model="form.remark" type="textarea" :rows="4" maxlength="200" show-word-limit resize="none" placeholder="請輸入備註（選填）" />
          </el-form-item>
        </div>
      </el-form>
    </section>
    <div class="reg-footer">
      <el-button size="large" @click="handleCancel">取消</el-button>
      <el-button type="primary" size="large" :loading="submitting" @click="handleSubmit">
        <i class="fa-regular fa-paper-plane reg-icon" aria-hidden="true"></i>送出
      </el-button>
    </div>
  </div>
</template>
<script setup>
/* *****************************************
File: VisitorRegistration.vue
Author: Callum
Date: 2026-11-19
Description: 訪客登記頁(現場登記)，送出目前走假資料，後端 API 完成後切換。
***************************************** */
import { reactive, ref } from 'vue';
import { ElMessage } from 'element-plus';
import { isMock, registerWalkIn } from '../../api/guest';
import { MAX_GUESTS, PHONE_MESSAGE, PHONE_PATTERN } from './constants';
import { useVisitReasons } from './useVisitReasons';
const { reasonOptions } = useVisitReasons();
const registrationIsMock = isMock('registration');
function emptyForm(){
  return { name: '', phone: '', company_name: '', title: '', plate_number: '', guest_count: 1, visit_reason: '', remark: '' };
}
const form = reactive(emptyForm());
const formRef = ref(null);
const submitting = ref(false);
const rules = {
  name: [{ required: true, message: '請輸入來訪者姓名', trigger: 'blur' }],
  phone: [
    { required: true, message: '請輸入手機號碼', trigger: 'blur' },
    { pattern: PHONE_PATTERN, message: PHONE_MESSAGE, trigger: 'blur' },
  ],
  company_name: [{ required: true, message: '請輸入公司名稱', trigger: 'blur' }],
  visit_reason: [{ required: true, message: '請選擇來訪事由', trigger: 'change' }],
};
// 空字串轉 null，避免選填欄位被存成空字串。
function blankToNull(value){
  const text = typeof value === 'string' ? value.trim() : value;
  return text === '' || text === undefined ? null : text;
}
function resetForm(){
  Object.assign(form, emptyForm());
  formRef.value?.clearValidate();
}
function handleCancel(){
  resetForm();
}
async function handleSubmit(){
  const valid = await formRef.value.validate().catch(() => false);
  if (!valid) return;
  submitting.value = true;
  const result = await registerWalkIn({
    guest_count: form.guest_count,
    visit_reason: form.visit_reason,
    remark: blankToNull(form.remark),
    visitor: {
      name: form.name.trim(),
      phone: form.phone.trim(),
      company_name: form.company_name.trim(),
      title: blankToNull(form.title),
      plate_number: blankToNull(form.plate_number),
    },
  });
  submitting.value = false;
  if(!result.ok){
    ElMessage.error(result.message);
    return;
  }
  ElMessage.success(`登記成功，單號：${result.data.reservation_id}`);
  resetForm();
}
</script>
<style scoped>
.reg-card{
  padding: 20px 24px 8px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.reg-card__title{
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 20px;
  font-size: 17px;
  font-weight: 600;
  color: #1f2937;
}
/* 標題左側的藍色直條，對應設計稿。 */
.reg-card__title::before{
  width: 4px;
  height: 18px;
  content: '';
  background: var(--el-color-primary);
  border-radius: 2px;
}
.reg-page__notice{
  margin-bottom: 16px;
}
.reg-grid{
  display: grid;
  grid-template-columns: 1fr 1fr;
  column-gap: 48px;
}
.reg-full{
  width: 100%;
}
.reg-count{
  display: flex;
  align-items: center;
  gap: 8px;
}
.reg-footer{
  display: flex;
  justify-content: space-between;
  padding: 16px 20px;
  margin-top: 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.reg-icon{
  margin-right: 6px;
}
</style>
