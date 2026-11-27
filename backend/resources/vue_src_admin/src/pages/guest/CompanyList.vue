<template>
  <section class="co-card">
    <div class="co-toolbar">
      <el-input v-model="keyword" placeholder="公司名稱" clearable class="co-keyword" @keyup.enter="search" @clear="search">
        <template #prefix><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></template>
      </el-input>
      <el-button @click="search">查詢</el-button>
    </div>
    <p class="co-hint">
      一個門牌＝一家公司（公司名稱在「門牌設定」維護）。替公司建立「公司管理員」帳號後，對方可登入公司端口（/company）
      自行管理員工、指定審核者與審核設定，且只看得到自己公司的資料。審核開關只影響員工從公司端口新增的預約，其他來源的訪客申請一律需要審核。
    </p>
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon class="co-alert" />
    <el-table v-loading="loading" :data="items" empty-text="沒有符合條件的公司">
      <el-table-column prop="company_name" label="公司名稱" min-width="180" />
      <el-table-column label="樓層／門牌" width="120">
        <template #default="{ row }">{{ row.floor }}F／{{ row.doorplate }}</template>
      </el-table-column>
      <el-table-column label="員工預約需審核" width="150">
        <template #default="{ row }">
          <el-switch
            :model-value="row.approval_required"
            :loading="savingId === row.doorplate_id"
            inline-prompt
            active-text="需要"
            inactive-text="免審"
            :before-change="() => toggleApproval(row)"
          />
        </template>
      </el-table-column>
      <el-table-column label="帳號數" width="100">
        <template #default="{ row }">{{ row.member_count }}</template>
      </el-table-column>
      <el-table-column label="公司管理員" width="130">
        <template #default="{ row }">
          <el-tag v-if="row.admin_count > 0" type="success" effect="light">{{ row.admin_count }} 位</el-tag>
          <el-tag v-else type="warning" effect="light">尚未建立</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="120" fixed="right">
        <template #default="{ row }">
          <el-button link type="primary" @click="openMembers(row)">帳號管理</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-if="total > pageSize"
      class="co-pager"
      layout="total, prev, pager, next"
      :total="total"
      :page-size="pageSize"
      :current-page="page"
      @current-change="changePage"
    />

    <!-- 某家公司的帳號 -->
    <el-drawer v-model="drawer.visible" :title="`帳號管理：${drawer.company?.company_name || ''}`" size="760px" @closed="load">
      <div class="co-toolbar">
        <span class="co-spacer"></span>
        <el-button type="primary" @click="openCreate"><i class="fa-solid fa-user-plus co-btn-icon" aria-hidden="true"></i>新增帳號</el-button>
      </div>
      <el-alert v-if="drawer.error" :title="drawer.error" type="error" :closable="false" show-icon class="co-alert" />
      <el-table v-loading="drawer.loading" :data="drawer.members" empty-text="這家公司還沒有帳號，請先新增一位公司管理員">
        <el-table-column prop="username" label="帳號" min-width="130" />
        <el-table-column prop="name" label="姓名" min-width="110" />
        <el-table-column label="身分" width="110">
          <template #default="{ row }">
            <el-tag :type="TYPE_TAG[row.member_type]" effect="light">{{ MEMBER_TYPE_LABEL[row.member_type] }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">編輯</el-button>
            <el-button link type="primary" @click="openPassword(row)">重設密碼</el-button>
            <el-button link type="danger" @click="handleRemove(row)">移除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-drawer>

    <!-- 新增／編輯帳號 -->
    <el-dialog v-model="dialog.visible" :title="dialog.id ? '編輯帳號' : '新增帳號'" width="480px" :close-on-click-modal="false" append-to-body>
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
            <el-radio :value="MEMBER_TYPE.ADMIN">公司管理員</el-radio>
            <el-radio :value="MEMBER_TYPE.APPROVER">審核者</el-radio>
            <el-radio :value="MEMBER_TYPE.EMPLOYEE">一般員工</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <el-alert v-if="dialog.error" :title="dialog.error" type="error" :closable="false" show-icon />
      <template #footer>
        <el-button @click="dialog.visible = false">取消</el-button>
        <el-button type="primary" :loading="dialog.saving" @click="submit">儲存</el-button>
      </template>
    </el-dialog>

    <!-- 重設密碼 -->
    <el-dialog v-model="pwd.visible" :title="`重設密碼：${pwd.name}`" width="420px" :close-on-click-modal="false" append-to-body>
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
File: CompanyList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 後台「公司帳號管理」：替各公司（門牌）建立公司管理員帳號、管理成員，以及設定員工預約是否需要審核。
***************************************** */
import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import {
  MEMBER_TYPE,
  MEMBER_TYPE_LABEL,
  createCompanyMember,
  fetchCompanies,
  fetchCompanyMembers,
  removeCompanyMember,
  resetCompanyMemberPassword,
  saveCompanyApproval,
  updateCompanyMember,
} from '../../api/guestCompany';

const TYPE_TAG = { 1: 'danger', 2: 'warning', 3: 'info' };
const pageSize = 10;

const keyword = ref('');
const items = ref([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);
const loadError = ref('');
const savingId = ref(null);

async function load(){
  loading.value = true;
  loadError.value = '';
  const result = await fetchCompanies({ keyword: keyword.value, page: page.value, per_page: pageSize });
  loading.value = false;
  if(!result.ok){
    loadError.value = `公司清單載入失敗：${result.message}`;
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

// el-switch 的 before-change：送出成功才切換。
async function toggleApproval(row){
  const next = !row.approval_required;
  savingId.value = row.doorplate_id;
  const result = await saveCompanyApproval(row.doorplate_id, next);
  savingId.value = null;
  if(!result.ok){
    ElMessage.error(`儲存失敗：${result.message}`);
    return false;
  }
  row.approval_required = result.data.approval_required;
  ElMessage.success(`${row.company_name}：員工預約${next ? '需要審核' : '改為免審核'}`);
  return true;
}

// ── 帳號管理抽屜 ──
const drawer = reactive({ visible: false, company: null, members: [], loading: false, error: '' });
async function loadMembers(){
  drawer.loading = true;
  drawer.error = '';
  const result = await fetchCompanyMembers(drawer.company.doorplate_id, { per_page: -1 });
  drawer.loading = false;
  if(!result.ok){
    drawer.error = `帳號清單載入失敗：${result.message}`;
    return;
  }
  drawer.members = result.data.items || [];
}
function openMembers(row){
  Object.assign(drawer, { visible: true, company: row, members: [], error: '' });
  loadMembers();
}

// ── 新增／編輯 ──
const formRef = ref(null);
const dialog = reactive({ visible: false, id: null, saving: false, error: '' });
const form = reactive({ username: '', password: '', name: '', email: '', member_type: MEMBER_TYPE.ADMIN });
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
  // 還沒有公司管理員時預設選公司管理員，有了就預設一般員工。
  const hasAdmin = drawer.members.some((m) => m.member_type === MEMBER_TYPE.ADMIN);
  Object.assign(form, { username: '', password: '', name: '', email: '', member_type: hasAdmin ? MEMBER_TYPE.EMPLOYEE : MEMBER_TYPE.ADMIN });
  Object.assign(dialog, { visible: true, id: null, saving: false, error: '' });
  formRef.value?.clearValidate();
}
function openEdit(row){
  Object.assign(form, { username: row.username, password: '', name: row.name, email: row.email || '', member_type: row.member_type });
  Object.assign(dialog, { visible: true, id: row.id, saving: false, error: '' });
  formRef.value?.clearValidate();
}
async function submit(){
  dialog.error = '';
  const valid = await formRef.value.validate().catch(() => false);
  if(!valid) return;
  dialog.saving = true;
  const doorplateId = drawer.company.doorplate_id;
  const email = form.email.trim() || null;
  const result = dialog.id
    ? await updateCompanyMember(doorplateId, dialog.id, { name: form.name.trim(), email, member_type: form.member_type })
    : await createCompanyMember(doorplateId, { username: form.username.trim(), password: form.password, name: form.name.trim(), email, member_type: form.member_type });
  dialog.saving = false;
  if(!result.ok){
    dialog.error = result.message;
    return;
  }
  ElMessage.success(dialog.id ? '帳號已更新' : '帳號已建立，可以登入公司端口');
  dialog.visible = false;
  loadMembers();
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
  const result = await resetCompanyMemberPassword(drawer.company.doorplate_id, pwd.id, pwd.password);
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
    await ElMessageBox.confirm(`確定要移除「${row.name}（${row.username}）」嗎？移除後將無法登入公司端口，已建立的預約會保留。`, '移除確認', { type: 'warning', confirmButtonText: '移除', cancelButtonText: '取消' });
  }catch{
    return;
  }
  const result = await removeCompanyMember(drawer.company.doorplate_id, row.id);
  if(!result.ok){
    ElMessage.error(`移除失敗：${result.message}`);
    return;
  }
  ElMessage.success('帳號已移除');
  loadMembers();
}

onMounted(load);
</script>

<style scoped>
.co-card{
  padding: 20px;
  background: #fff;
  border-radius: 8px;
  box-shadow: 0 1px 2px rgba(15, 35, 72, 0.06);
}
.co-toolbar{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin-bottom: 12px;
}
.co-keyword{
  width: 260px;
}
.co-spacer{
  flex: 1;
}
.co-btn-icon{
  margin-right: 6px;
}
.co-hint{
  margin: 0 0 12px;
  color: #6b7280;
  font-size: 13px;
  line-height: 1.7;
}
.co-alert{
  margin-bottom: 12px;
}
.co-pager{
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
