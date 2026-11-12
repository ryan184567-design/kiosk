<template>
  <div class="user-list">
    <div class="user-list__toolbar">
      <el-button type="primary" @click="openGrant">新增使用者</el-button>
      <el-button @click="loadUsers">重新整理</el-button>
    </div>

    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" style="margin-bottom: 16px" />

    <el-table :data="users" v-loading="loading" border>
      <el-table-column prop="user_id" label="ID" width="80" />
      <el-table-column prop="username" label="帳號" width="160" />
      <el-table-column prop="name" label="姓名" width="160" />
      <el-table-column label="端口角色">
        <template #default="{ row }">
          <el-tag v-if="row.is_portal_admin" type="warning">端口管理員</el-tag>
          <span v-else>{{ row.role_name || '—' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="280">
        <template #default="{ row }">
          <el-button size="small" @click="openEditAccess(row)">編輯權限</el-button>
          <el-button size="small" @click="openResetPassword(row)">重設密碼</el-button>
          <el-button size="small" type="danger" @click="handleRevoke(row)">撤銷存取</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="grantDialogVisible" title="新增使用者" width="560px">
      <el-form label-width="110px">
        <el-form-item label="帳號來源">
          <el-radio-group v-model="grantMode">
            <el-radio value="new">建立新帳號</el-radio>
            <el-radio value="existing">加入既有帳號</el-radio>
          </el-radio-group>
        </el-form-item>

        <template v-if="grantMode === 'new'">
          <el-form-item label="帳號">
            <el-input v-model="newUserForm.username" />
          </el-form-item>
          <el-form-item label="密碼">
            <el-input v-model="newUserForm.password" type="password" show-password />
          </el-form-item>
          <el-form-item label="姓名">
            <el-input v-model="newUserForm.name" />
          </el-form-item>
          <el-form-item label="身份別">
            <el-select v-model="newUserForm.identity_type_id" style="width: 100%">
              <el-option v-for="t in identityTypes" :key="t.id" :label="t.name" :value="t.id" />
            </el-select>
          </el-form-item>
        </template>

        <template v-else>
          <el-form-item label="帳號">
            <div style="display: flex; gap: 8px; width: 100%">
              <el-input v-model="searchUsername" placeholder="輸入帳號後搜尋" />
              <el-button @click="handleSearch">搜尋</el-button>
            </div>
          </el-form-item>
          <el-alert v-if="searchResult" :title="`找到：${searchResult.name}（${searchResult.username}）`" type="success" :closable="false" style="margin-bottom: 16px" />
          <el-alert v-else-if="searchAttempted" title="查無此帳號" type="warning" :closable="false" style="margin-bottom: 16px" />
        </template>

        <el-divider />

        <el-form-item label="端口角色">
          <el-radio-group v-model="accessMode">
            <el-radio value="role">指派角色</el-radio>
            <el-radio value="admin">端口管理員</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="accessMode === 'role'" label="角色">
          <el-select v-model="accessForm.role_id" style="width: 100%">
            <el-option v-for="r in roles" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item v-else label="管理的端口">
          <el-checkbox-group v-model="accessForm.portal_ids">
            <el-checkbox v-for="p in grantablePortals" :key="p.id" :value="p.id">{{ p.name }}（{{ p.portal_key }}）</el-checkbox>
          </el-checkbox-group>
          <div v-if="grantablePortals.length === 0" class="user-list__hint">沒有可授權的端口——你自己還不是任何端口的端口管理員。</div>
        </el-form-item>
      </el-form>
      <el-alert v-if="grantError" :title="grantError" type="error" :closable="false" style="margin-bottom: 16px" />
      <template #footer>
        <el-button @click="grantDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="granting" @click="handleGrantSubmit">儲存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="editAccessVisible" title="編輯權限" width="480px">
      <el-form label-width="110px">
        <el-form-item label="端口角色">
          <el-radio-group v-model="accessMode">
            <el-radio value="role">指派角色</el-radio>
            <el-radio value="admin">端口管理員</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="accessMode === 'role'" label="角色">
          <el-select v-model="accessForm.role_id" style="width: 100%">
            <el-option v-for="r in roles" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
        <el-form-item v-else label="管理的端口">
          <el-checkbox-group v-model="accessForm.portal_ids">
            <el-checkbox v-for="p in grantablePortals" :key="p.id" :value="p.id">{{ p.name }}（{{ p.portal_key }}）</el-checkbox>
          </el-checkbox-group>
          <div v-if="grantablePortals.length === 0" class="user-list__hint">沒有可授權的端口——你自己還不是任何端口的端口管理員。</div>
        </el-form-item>
      </el-form>
      <el-alert v-if="grantError" :title="grantError" type="error" :closable="false" style="margin-bottom: 16px" />
      <template #footer>
        <el-button @click="editAccessVisible = false">取消</el-button>
        <el-button type="primary" :loading="granting" @click="handleEditAccessSubmit">儲存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="passwordDialogVisible" title="重設密碼" width="420px">
      <el-form label-width="90px">
        <el-form-item label="新密碼">
          <el-input v-model="newPassword" type="password" show-password />
        </el-form-item>
      </el-form>
      <el-alert v-if="passwordError" :title="passwordError" type="error" :closable="false" style="margin-bottom: 16px" />
      <template #footer>
        <el-button @click="passwordDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="resettingPassword" @click="handleResetPassword">儲存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
/* *****************************************
File: UserList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 使用者管理頁面（目前端口的存取名單，見 README 7.1、7.2）。
***************************************** */

import { onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { authFetch } from '../../stores/auth';

const users = ref([]);
const roles = ref([]);
const identityTypes = ref([]);
const loading = ref(false);
const loadError = ref('');

const grantDialogVisible = ref(false);
const grantMode = ref('new');
const accessMode = ref('role');
const newUserForm = reactive({ username: '', password: '', name: '', identity_type_id: null });
const accessForm = reactive({ role_id: null, portal_ids: [] });
const grantablePortals = ref([]);
const searchUsername = ref('');
const searchResult = ref(null);
const searchAttempted = ref(false);
const granting = ref(false);
const grantError = ref('');

const editAccessVisible = ref(false);
const editingRow = ref(null);

const passwordDialogVisible = ref(false);
const passwordTargetRow = ref(null);
const newPassword = ref('');
const passwordError = ref('');
const resettingPassword = ref(false);

async function loadUsers() {
  loading.value = true;
  loadError.value = '';

  try {
    const res = await authFetch('/api/users?per_page=-1');
    const body = await res.json();

    if (!body.status) {
      loadError.value = body.error_message || '載入使用者列表失敗';

      return;
    }

    users.value = body.data.items;
  } finally {
    loading.value = false;
  }
}

async function loadRoles() {
  const res = await authFetch('/api/roles?per_page=-1');
  const body = await res.json();

  if (body.status) roles.value = body.data.items;
}

async function loadIdentityTypes() {
  const res = await authFetch('/api/users/identity-type-options');
  const body = await res.json();

  if (body.status) identityTypes.value = body.data.items;
}

// 見 README 9.1：只回傳呼叫者實際能授予端口管理員身份的端口子集，跟
// PortalList.vue 用的 GET /api/portals（完整清單）刻意分開。
async function loadGrantablePortals() {
  const res = await authFetch('/api/users/grantable-portals');
  const body = await res.json();

  if (body.status) grantablePortals.value = body.data.items;
}

function openGrant() {
  grantMode.value = 'new';
  accessMode.value = 'role';
  Object.assign(newUserForm, { username: '', password: '', name: '', identity_type_id: null });
  Object.assign(accessForm, { role_id: null, portal_ids: [] });
  searchUsername.value = '';
  searchResult.value = null;
  searchAttempted.value = false;
  grantError.value = '';
  grantDialogVisible.value = true;
  loadGrantablePortals();
}

async function handleSearch() {
  searchAttempted.value = false;
  searchResult.value = null;

  const res = await authFetch(`/api/users/search?username=${encodeURIComponent(searchUsername.value)}`);
  const body = await res.json();

  searchAttempted.value = true;

  if (body.status && body.data.found) {
    searchResult.value = body.data.user;
  }
}

async function handleGrantSubmit() {
  grantError.value = '';
  granting.value = true;

  try {
    let userId;

    if (grantMode.value === 'new') {
      const res = await authFetch('/api/users', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(newUserForm),
      });
      const body = await res.json();

      if (!body.status) {
        grantError.value = body.error_message || '建立帳號失敗';

        return;
      }

      userId = body.data.id;
    } else {
      if (!searchResult.value) {
        grantError.value = '請先搜尋並找到既有帳號';

        return;
      }

      userId = searchResult.value.id;
    }

    const granted = await grantAccess(userId);

    if (!granted) return;

    grantDialogVisible.value = false;
    await loadUsers();
    ElMessage.success('已儲存');
  } finally {
    granting.value = false;
  }
}

// 見 README 9.1：「端口管理員」模式可以一次勾選多個端口，逐一呼叫既有的
// 授權端點（每個 portal_id 各一次）；「指派角色」模式維持只作用在目前
// 端口不變（角色本身就是綁定單一端口的資源，多選沒有意義）。這裡是
// 新增/覆蓋授權，不會自動撤銷先前已經是管理員但這次沒勾選的端口——要
// 撤銷要在該端口底下用既有的「撤銷存取」處理，見 README 已知限制說明。
async function grantAccess(userId) {
  if (accessMode.value === 'role') {
    return grantOnePortal(userId, { is_portal_admin: false, role_id: accessForm.role_id });
  }

  if (accessForm.portal_ids.length === 0) {
    grantError.value = '請至少勾選一個要管理的端口';

    return false;
  }

  for (const portalId of accessForm.portal_ids) {
    // eslint-disable-next-line no-await-in-loop -- 逐一呼叫既有的單端口授權端點，數量最多就是端口總數，不需要並發。
    const ok = await grantOnePortal(userId, { is_portal_admin: true, role_id: null, portal_id: portalId });

    if (!ok) return false;
  }

  return true;
}

async function grantOnePortal(userId, payload) {
  const res = await authFetch(`/api/users/${userId}/portal-access`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const body = await res.json();

  if (!body.status) {
    grantError.value = body.error_message || '授權失敗';

    return false;
  }

  return true;
}

function openEditAccess(row) {
  editingRow.value = row;
  accessMode.value = row.is_portal_admin ? 'admin' : 'role';
  Object.assign(accessForm, { role_id: row.role_id, portal_ids: [] });
  grantError.value = '';
  editAccessVisible.value = true;
  loadGrantablePortals();
}

async function handleEditAccessSubmit() {
  grantError.value = '';
  granting.value = true;

  try {
    const granted = await grantAccess(editingRow.value.user_id);

    if (!granted) return;

    editAccessVisible.value = false;
    await loadUsers();
    ElMessage.success('已儲存');
  } finally {
    granting.value = false;
  }
}

function openResetPassword(row) {
  passwordTargetRow.value = row;
  newPassword.value = '';
  passwordError.value = '';
  passwordDialogVisible.value = true;
}

async function handleResetPassword() {
  passwordError.value = '';
  resettingPassword.value = true;

  try {
    const res = await authFetch(`/api/users/${passwordTargetRow.value.user_id}/password`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: newPassword.value }),
    });
    const body = await res.json();

    if (!body.status) {
      passwordError.value = body.error_message || '重設密碼失敗';

      return;
    }

    passwordDialogVisible.value = false;
    ElMessage.success('已重設密碼');
  } finally {
    resettingPassword.value = false;
  }
}

async function handleRevoke(row) {
  try {
    await ElMessageBox.confirm(`確定要撤銷「${row.username}」在此端口的存取權嗎？`, '撤銷確認', { type: 'warning' });
  } catch {
    return;
  }

  const res = await authFetch(`/api/users/${row.user_id}/portal-access`, { method: 'DELETE' });
  const body = await res.json();

  if (!body.status) {
    ElMessage.error(body.error_message || '撤銷失敗');

    return;
  }

  ElMessage.success('已撤銷');
  await loadUsers();
}

onMounted(() => {
  loadUsers();
  loadRoles();
  loadIdentityTypes();
});
</script>

<style scoped>
.user-list__toolbar {
  margin-bottom: 16px;
  display: flex;
  gap: 8px;
}

.user-list__hint {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
