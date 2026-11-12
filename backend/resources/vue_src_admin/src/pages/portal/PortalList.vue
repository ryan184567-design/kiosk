<template>
  <div class="portal-list">
    <div class="portal-list__toolbar">
      <el-button type="primary" @click="openCreate">新增端口</el-button>
      <el-button @click="loadPortals">重新整理</el-button>
    </div>

    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" style="margin-bottom: 16px" />

    <el-table :data="portals" v-loading="loading" border>
      <el-table-column prop="id" label="ID" width="70" />
      <el-table-column prop="portal_key" label="端口代碼" width="140" />
      <el-table-column prop="name" label="端口名稱" width="180" />
      <el-table-column label="僅端口管理員登入" width="150">
        <template #default="{ row }">
          <el-switch :model-value="row.admin_only" @change="(val) => handleToggle(row, 'admin_only', val)" />
        </template>
      </el-table-column>
      <el-table-column label="需要登入才能進入" width="150">
        <template #default="{ row }">
          <el-switch :model-value="row.requires_login" @change="(val) => handleToggle(row, 'requires_login', val)" />
        </template>
      </el-table-column>
      <el-table-column label="啟用" width="90">
        <template #default="{ row }">
          <el-switch :model-value="row.is_enabled" @change="(val) => handleToggle(row, 'is_enabled', val)" />
        </template>
      </el-table-column>
      <el-table-column label="操作" width="100">
        <template #default="{ row }">
          <el-button size="small" @click="openEdit(row)">編輯</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="editing ? '編輯端口' : '新增端口'" width="480px">
      <el-form label-width="140px">
        <el-form-item label="端口代碼">
          <el-input v-model="form.portal_key" :disabled="!!editing" placeholder="小寫英數與底線，例如 vendor" />
        </el-form-item>
        <el-form-item label="端口名稱">
          <el-input v-model="form.name" />
        </el-form-item>
        <el-form-item label="僅端口管理員登入">
          <el-switch v-model="form.admin_only" />
        </el-form-item>
        <el-form-item label="需要登入才能進入">
          <el-switch v-model="form.requires_login" />
        </el-form-item>
        <el-form-item label="啟用">
          <el-switch v-model="form.is_enabled" />
        </el-form-item>
      </el-form>
      <el-alert
        title="端口代碼建立後不可變更；新增端口只會建立資料列，實際要讓這個端口的登入與頁面運作，還需要另外開發對應的前端與後端骨架。"
        type="info"
        :closable="false"
        style="margin-bottom: 16px"
      />
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" style="margin-bottom: 16px" />
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">儲存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
/* *****************************************
File: PortalList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 端口管理頁面，見 README 9.1。
***************************************** */

import { reactive, ref, onMounted } from 'vue';
import { ElMessage } from 'element-plus';
import { authFetch } from '../../stores/auth';

const portals = ref([]);
const loading = ref(false);
const loadError = ref('');

const dialogVisible = ref(false);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const form = reactive({ portal_key: '', name: '', admin_only: false, requires_login: true, is_enabled: true });

async function loadPortals() {
  loading.value = true;
  loadError.value = '';

  try {
    const res = await authFetch('/api/portals');
    const body = await res.json();

    if (!body.status) {
      loadError.value = body.error_message || '載入端口列表失敗';

      return;
    }

    portals.value = body.data.items;
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  editing.value = null;
  Object.assign(form, { portal_key: '', name: '', admin_only: false, requires_login: true, is_enabled: true });
  formError.value = '';
  dialogVisible.value = true;
}

function openEdit(row) {
  editing.value = row;
  Object.assign(form, {
    portal_key: row.portal_key,
    name: row.name,
    admin_only: row.admin_only,
    requires_login: row.requires_login,
    is_enabled: row.is_enabled,
  });
  formError.value = '';
  dialogVisible.value = true;
}

async function handleSave() {
  formError.value = '';
  saving.value = true;

  try {
    const path = editing.value ? `/api/portals/${editing.value.id}` : '/api/portals';
    const method = editing.value ? 'PUT' : 'POST';
    const res = await authFetch(path, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    });
    const body = await res.json();

    if (!body.status) {
      formError.value = body.error_message || '儲存失敗';

      return;
    }

    dialogVisible.value = false;
    await loadPortals();
    ElMessage.success('已儲存');
  } finally {
    saving.value = false;
  }
}

// 表格上的開關直接就地切換，不用另外打開編輯對話框——跟表單儲存共用
// 同一支 PUT 端點，只是這裡只送這個端口目前的完整狀態＋剛切換的那個欄位。
async function handleToggle(row, field, value) {
  const payload = {
    name: row.name,
    admin_only: row.admin_only,
    requires_login: row.requires_login,
    is_enabled: row.is_enabled,
    [field]: value,
  };

  const res = await authFetch(`/api/portals/${row.id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const body = await res.json();

  if (!body.status) {
    ElMessage.error(body.error_message || '更新失敗');

    return;
  }

  row[field] = value;
  ElMessage.success('已更新');
}

onMounted(loadPortals);
</script>

<style scoped>
.portal-list__toolbar {
  margin-bottom: 16px;
  display: flex;
  gap: 8px;
}
</style>
