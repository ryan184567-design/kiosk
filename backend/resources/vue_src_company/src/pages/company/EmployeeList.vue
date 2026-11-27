<template>
  <section class="emp-card">
    <div class="emp-toolbar">
      <el-input v-model="filters.keyword" placeholder="帳號 / 姓名" clearable class="emp-keyword" @keyup.enter="search" @clear="search">
        <template #prefix><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></template>
      </el-input>
      <el-select v-model="filters.member_type" placeholder="全部身分" clearable class="emp-type" @change="search">
        <el-option v-for="(label, value) in MEMBER_TYPE_LABEL" :key="value" :label="label" :value="Number(value)" />
      </el-select>
      <el-button @click="search">查詢</el-button>
      <span class="emp-spacer"></span>
      <el-button type="primary" @click="openCreate"><i class="fa-solid fa-user-plus emp-btn-icon" aria-hidden="true"></i>新增員工</el-button>
    </div>
    <p class="emp-hint">
      審核者可以審核本公司的訪客預約；一般員工只能登記與管理預約。公司管理員帳號由大樓管理後台建立。
    </p>
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon class="emp-alert" />
    <el-table v-loading="loading" :data="items" empty-text="沒有符合條件的員工">
      <el-table-column prop="username" label="帳號" min-width="140" />
      <el-table-column prop="name" label="姓名" min-width="120" />
      <el-table-column prop="email" label="Email" min-width="180">
        <template #default="{ row }">{{ row.email || '—' }}</template>
      </el-table-column>
      <el-table-column label="身分" width="120">
        <template #default="{ row }">
          <el-tag :type="TYPE_TAG[row.member_type]" effect="light">{{ MEMBER_TYPE_LABEL[row.member_type] }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="created_at" label="建立時間" width="170" />
      <el-table-column label="操作" width="230" fixed="right">
        <template #default="{ row }">
          <template v-if="row.member_type !== MEMBER_TYPE.ADMIN">
            <el-button link type="primary" @click="openEdit(row)">編輯</el-button>
            <el-button link type="primary" @click="openPassword(row)">重設密碼</el-button>
            <el-button link type="danger" @click="handleRemove(row)">移除</el-button>
          </template>
          <span v-else class="emp-muted">由後台管理</span>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-if="total > pageSize"
      class="emp-pager"
      layout="total, prev, pager, next"
      :total="total"
      :page-size="pageSize"
      :current-page="page"
      @current-change="changePage"
    />

    <!-- 新增／編輯員工 -->
    <el-dialog v-model="dialog.visible" :title="dialog.id ? '編輯員工' : '新增員工'" width="480px" :close-on-click-modal="false">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="88px">
        <el-form-item label="帳號" prop="username">
          <el-input v-model="form.username" :disabled="Boolean(dialog.id)" maxlength="50" autocomplete="off" placeholder="英數、底線、減號、點" />
        </el-form-item>
        <el-form-item v-if="!dialog.id" label="密碼" prop="password">
          <el-input v-model="form.password" type="password" show-password maxlength="100" autocomplete="new-password" placeholder="至少 8 個字元" />
        </el-form-item>
        <el-form-item label="姓名" prop="name">
          <el-input v-model="form.name" maxlength="100" />
        </el-form-item>
        <el-form-item label="Email" prop="email">
          <el-input v-model="form.email" maxlength="150" />
        </el-form-item>
        <el-form-item label="身分" prop="member_type">
          <el-radio-group v-model="form.member_type">
            <el-radio :value="MEMBER_TYPE.EMPLOYEE">一般員工</el-radio>
            <el-radio :value="MEMBER_TYPE.APPROVER">審核者</el-radio>
          </el-radio-group>
        </el-form-item>
        <p v-if="dialog.id && form.member_type !== dialog.originalType" class="emp-hint">身分變更後，對方需要重新登入才會生效。</p>
      </el-form>
      <el-alert v-if="dialog.error" :title="dialog.error" type="error" :closable="false" show-icon />
      <template #footer>
        <el-button @click="dialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="dialog.saving" @click="submit">儲存</el-button>
      </template>
    </el-dialog>

    <!-- 重設密碼 -->
    <el-dialog v-model="pwd.visible" :title="`重設密碼：${pwd.name}`" width="420px" :close-on-click-modal="false">
      <el-form ref="pwdFormRef" :model="pwd" :rules="pwdRules" label-width="88px" @submit.prevent>
        <el-form-item label="新密碼" prop="password">
          <el-input v-model="pwd.password" type="password" show-password maxlength="100" autocomplete="new-password" placeholder="至少 8 個字元" />
        </el-form-item>
      </el-form>
      <el-alert v-if="pwd.error" :title="pwd.error" type="error" :closable="false" show-icon />
      <template #footer>
        <el-button @click="pwd.visible = false">取消</el-button>
        <el-button type="primary" :loading="pwd.saving" @click="submitPassword">重設</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<script setup>
/* *****************************************
File: EmployeeList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 公司端口「員工管理」：公司管理員新增、編輯、移除本公司員工，並設定誰是審核者。
***************************************** */
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { createEmployee, fetchEmployees, removeEmployee, resetEmployeePassword, updateEmployee } from '../../api/company';
import { MEMBER_TYPE, MEMBER_TYPE_LABEL } from '../../stores/company';

const TYPE_TAG = { 1: 'danger', 2: 'warning', 3: 'info' };
const pageSize = 10;

const filters = reactive({ keyword: '', member_type: null });
const items = ref([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);
const loadError = ref('');

async function load(){
  loading.value = true;
  loadError.value = '';
  const result = await fetchEmployees({ keyword: filters.keyword, member_type: filters.member_type, page: page.value, per_page: pageSize });
  loading.value = false;
  if(!result.ok){
    loadError.value = `員工清單載入失敗：${result.message}`;
    return;
  }
  items.value = result.data.items || [];
  total.value = result.data.total ?? 0;
}
function search(){
  page.value = 1;
  load();
}
function changePage(p){
  page.value = p;
  load();
}

// ── 新增／編輯 ──
const formRef = ref(null);
const dialog = reactive({ visible: false, id: null, originalType: null, saving: false, error: '' });
const form = reactive({ username: '', password: '', name: '', email: '', member_type: MEMBER_TYPE.EMPLOYEE });
const rules = {
  username: [
    { required: true, message: '請輸入帳號', trigger: 'blur' },
    { pattern: /^[A-Za-z0-9_.-]{3,50}$/, message: '帳號為 3～50 個英數字、底線、減號或點', trigger: 'blur' },
  ],
  password: [
    { required: true, message: '請輸入密碼', trigger: 'blur' },
    { min: 8, message: '密碼至少 8 個字元', trigger: 'blur' },
  ],
  name: [{ required: true, message: '請輸入姓名', trigger: 'blur' }],
  email: [{ type: 'email', message: 'Email 格式不正確', trigger: 'blur' }],
  member_type: [{ required: true, message: '請選擇身分', trigger: 'change' }],
};
function openCreate(){
  Object.assign(form, { username: '', password: '', name: '', email: '', member_type: MEMBER_TYPE.EMPLOYEE });
  Object.assign(dialog, { visible: true, id: null, originalType: null, saving: false, error: '' });
  formRef.value?.clearValidate();
}
function openEdit(row){
  Object.assign(form, { username: row.username, password: '', name: row.name, email: row.email || '', member_type: row.member_type });
  Object.assign(dialog, { visible: true, id: row.id, originalType: row.member_type, saving: false, error: '' });
  formRef.value?.clearValidate();
}
async function submit(){
  dialog.error = '';
  const valid = await formRef.value.validate().catch(() => false);
  if(!valid) return;
  dialog.saving = true;
  const email = form.email.trim() || null;
  const result = dialog.id
    ? await updateEmployee(dialog.id, { name: form.name.trim(), email, member_type: form.member_type })
    : await createEmployee({ username: form.username.trim(), password: form.password, name: form.name.trim(), email, member_type: form.member_type });
  dialog.saving = false;
  if(!result.ok){
    dialog.error = result.message;
    return;
  }
  ElMessage.success(dialog.id ? '員工資料已更新' : '員工已新增，可以用這組帳號登入公司端口');
  dialog.visible = false;
  load();
}

// ── 重設密碼 ──
const pwdFormRef = ref(null);
const pwd = reactive({ visible: false, id: null, name: '', password: '', saving: false, error: '' });
const pwdRules = {
  password: [
    { required: true, message: '請輸入新密碼', trigger: 'blur' },
    { min: 8, message: '密碼至少 8 個字元', trigger: 'blur' },
  ],
};
function openPassword(row){
  Object.assign(pwd, { visible: true, id: row.id, name: row.name, password: '', saving: false, error: '' });
  pwdFormRef.value?.clearValidate();
}
async function submitPassword(){
  pwd.error = '';
  const valid = await pwdFormRef.value.validate().catch(() => false);
  if(!valid) return;
  pwd.saving = true;
  const result = await resetEmployeePassword(pwd.id, pwd.password);
  pwd.saving = false;
  if(!result.ok){
    pwd.error = result.message;
    return;
  }
  ElMessage.success('密碼已重設');
  pwd.visible = false;
}

// ── 移除 ──
async function handleRemove(row){
  try{
    await ElMessageBox.confirm(`確定要移除員工「${row.name}（${row.username}）」嗎？移除後將無法登入公司端口，已建立的預約會保留。`, '移除確認', { type: 'warning', confirmButtonText: '移除', cancelButtonText: '取消' });
  }catch{
    return;
  }
  const result = await removeEmployee(row.id);
  if(!result.ok){
    ElMessage.error(`移除失敗：${result.message}`);
    return;
  }
  ElMessage.success('員工已移除');
  load();
}

onMounted(load);
</script>

<style scoped>
.emp-card{
  padding: 20px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 1px 2px rgba(15, 35, 72, 0.06);
}
.emp-toolbar{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
}
.emp-keyword{
  width: 240px;
}
.emp-type{
  width: 150px;
}
.emp-spacer{
  flex: 1;
}
.emp-btn-icon{
  margin-right: 6px;
}
.emp-hint{
  margin: 12px 0;
  color: #6b7280;
  font-size: 13px;
}
.emp-alert{
  margin-bottom: 12px;
}
.emp-muted{
  color: #9ca3af;
  font-size: 13px;
}
.emp-pager{
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
