<template>
  <div class="dict-list">
    <div class="dict-list__toolbar">
      <el-button type="primary" @click="openCreate">新增字典</el-button>
      <el-button @click="loadDictionaries">重新整理</el-button>
    </div>

    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" style="margin-bottom: 16px" />

    <el-table :data="dictionaries" v-loading="loading" border>
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column prop="code" label="代碼" width="180" />
      <el-table-column prop="name" label="名稱" width="180" />
      <el-table-column prop="description" label="說明" />
      <el-table-column label="項目數" width="90">
        <template #default="{ row }">{{ row.items_count }}</template>
      </el-table-column>
      <el-table-column label="狀態" width="90">
        <template #default="{ row }">
          <el-tag :type="row.is_enabled ? 'success' : 'info'">{{ row.is_enabled ? '啟用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="220">
        <template #default="{ row }">
          <el-button size="small" @click="openItems(row)">項目管理</el-button>
          <el-button size="small" @click="openEdit(row)">編輯</el-button>
          <el-button size="small" type="danger" @click="handleDelete(row)">刪除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="editing ? '編輯字典' : '新增字典'" width="500px">
      <el-form label-width="90px">
        <el-form-item label="代碼">
          <el-input v-model="form.code" :disabled="!!editing" placeholder="例如 user_status" />
        </el-form-item>
        <el-form-item label="名稱">
          <el-input v-model="form.name" />
        </el-form-item>
        <el-form-item label="說明">
          <el-input v-model="form.description" />
        </el-form-item>
        <el-form-item label="啟用">
          <el-switch v-model="form.is_enabled" />
        </el-form-item>
      </el-form>
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" style="margin-bottom: 16px" />
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">儲存</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="itemsDialogVisible" :title="`項目管理：${activeDictionary?.name}`" width="700px">
      <div class="dict-list__toolbar">
        <el-button type="primary" size="small" @click="openItemCreate">新增項目</el-button>
      </div>
      <el-table :data="items" v-loading="itemsLoading" border>
        <el-table-column prop="value" label="值" width="160" />
        <el-table-column prop="label" label="顯示文字" width="200" />
        <el-table-column label="狀態" width="90">
          <template #default="{ row }">
            <el-tag :type="row.is_enabled ? 'success' : 'info'">{{ row.is_enabled ? '啟用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作">
          <template #default="{ row }">
            <el-button size="small" @click="openItemEdit(row)">編輯</el-button>
            <el-button size="small" type="danger" @click="handleItemDelete(row)">刪除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <el-dialog v-model="itemFormVisible" :title="editingItem ? '編輯項目' : '新增項目'" width="450px" append-to-body>
      <el-form label-width="90px">
        <el-form-item label="值">
          <el-input v-model="itemForm.value" />
        </el-form-item>
        <el-form-item label="顯示文字">
          <el-input v-model="itemForm.label" />
        </el-form-item>
        <el-form-item label="啟用">
          <el-switch v-model="itemForm.is_enabled" />
        </el-form-item>
      </el-form>
      <el-alert v-if="itemFormError" :title="itemFormError" type="error" :closable="false" style="margin-bottom: 16px" />
      <template #footer>
        <el-button @click="itemFormVisible = false">取消</el-button>
        <el-button type="primary" :loading="itemSaving" @click="handleItemSave">儲存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
/* *****************************************
File: DictionaryList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 資料字典管理頁面（類型 CRUD ＋ 項目 CRUD），見 README 17.3。
***************************************** */

import { reactive, ref, onMounted } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { authFetch } from '../../stores/auth';

const dictionaries = ref([]);
const loading = ref(false);
const loadError = ref('');
const dialogVisible = ref(false);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const form = reactive({ code: '', name: '', description: '', is_enabled: true });

const activeDictionary = ref(null);
const items = ref([]);
const itemsLoading = ref(false);
const itemsDialogVisible = ref(false);

const itemFormVisible = ref(false);
const editingItem = ref(null);
const itemSaving = ref(false);
const itemFormError = ref('');
const itemForm = reactive({ value: '', label: '', is_enabled: true });

async function loadDictionaries() {
  loading.value = true;
  loadError.value = '';

  try {
    const res = await authFetch('/api/dictionaries');
    const body = await res.json();

    if (!body.status) {
      loadError.value = body.error_message || '載入字典列表失敗';

      return;
    }

    dictionaries.value = body.data.items;
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  editing.value = null;
  Object.assign(form, { code: '', name: '', description: '', is_enabled: true });
  formError.value = '';
  dialogVisible.value = true;
}

function openEdit(row) {
  editing.value = row;
  Object.assign(form, { code: row.code, name: row.name, description: row.description, is_enabled: row.is_enabled });
  formError.value = '';
  dialogVisible.value = true;
}

async function handleSave() {
  formError.value = '';
  saving.value = true;

  try {
    const path = editing.value ? `/api/dictionaries/${editing.value.id}` : '/api/dictionaries';
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
    await loadDictionaries();
    ElMessage.success('已儲存');
  } finally {
    saving.value = false;
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`確定要刪除字典「${row.name}」嗎？`, '刪除確認', { type: 'warning' });
  } catch {
    return;
  }

  const res = await authFetch(`/api/dictionaries/${row.id}`, { method: 'DELETE' });
  const body = await res.json();

  if (!body.status) {
    ElMessage.error(body.error_message || '刪除失敗');

    return;
  }

  ElMessage.success('已刪除');
  await loadDictionaries();
}

async function openItems(row) {
  activeDictionary.value = row;
  itemsDialogVisible.value = true;
  await loadItems();
}

async function loadItems() {
  itemsLoading.value = true;

  try {
    const res = await authFetch(`/api/dictionaries/${activeDictionary.value.id}/items`);
    const body = await res.json();

    items.value = body.status ? body.data : [];
  } finally {
    itemsLoading.value = false;
  }
}

function openItemCreate() {
  editingItem.value = null;
  Object.assign(itemForm, { value: '', label: '', is_enabled: true });
  itemFormError.value = '';
  itemFormVisible.value = true;
}

function openItemEdit(row) {
  editingItem.value = row;
  Object.assign(itemForm, { value: row.value, label: row.label, is_enabled: row.is_enabled });
  itemFormError.value = '';
  itemFormVisible.value = true;
}

async function handleItemSave() {
  itemFormError.value = '';
  itemSaving.value = true;

  try {
    const base = `/api/dictionaries/${activeDictionary.value.id}/items`;
    const path = editingItem.value ? `${base}/${editingItem.value.id}` : base;
    const method = editingItem.value ? 'PUT' : 'POST';
    const res = await authFetch(path, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(itemForm),
    });
    const body = await res.json();

    if (!body.status) {
      itemFormError.value = body.error_message || '儲存失敗';

      return;
    }

    itemFormVisible.value = false;
    await loadItems();
    await loadDictionaries();
    ElMessage.success('已儲存');
  } finally {
    itemSaving.value = false;
  }
}

async function handleItemDelete(row) {
  try {
    await ElMessageBox.confirm(`確定要刪除項目「${row.label}」嗎？`, '刪除確認', { type: 'warning' });
  } catch {
    return;
  }

  const res = await authFetch(`/api/dictionaries/${activeDictionary.value.id}/items/${row.id}`, { method: 'DELETE' });
  const body = await res.json();

  if (!body.status) {
    ElMessage.error(body.error_message || '刪除失敗');

    return;
  }

  ElMessage.success('已刪除');
  await loadItems();
  await loadDictionaries();
}

onMounted(loadDictionaries);
</script>

<style scoped>
.dict-list__toolbar {
  margin-bottom: 16px;
  display: flex;
  gap: 8px;
}
</style>
