<template>
  <div class="role-list">
    <div class="role-list__toolbar">
      <el-button type="primary" @click="openCreate">新增角色</el-button>
      <el-button @click="loadRoles">重新整理</el-button>
    </div>

    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" style="margin-bottom: 16px" />

    <el-table :data="roles" v-loading="loading" border>
      <el-table-column prop="id" label="ID" width="80" />
      <el-table-column prop="name" label="角色名稱" />
      <el-table-column label="權限碼數量" width="120">
        <template #default="{ row }">{{ row.permission_codes.length }}</template>
      </el-table-column>
      <el-table-column label="操作" width="160">
        <template #default="{ row }">
          <el-button size="small" @click="openEdit(row)">編輯</el-button>
          <el-button size="small" type="danger" @click="handleDelete(row)">刪除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-dialog v-model="dialogVisible" :title="editing ? '編輯角色' : '新增角色'" width="720px">
      <el-form label-width="100px">
        <el-form-item label="角色名稱">
          <el-input v-model="form.name" />
        </el-form-item>
        <el-form-item label="權限">
          <div class="perm-picker">
            <div class="perm-picker__toolbar">
              <el-input v-model="permFilterText" placeholder="搜尋權限（模塊、說明、權限碼）" clearable size="small" style="width: 280px" />
              <span class="perm-picker__count">已選 {{ form.permission_codes.length }} 筆</span>
            </div>
            <!-- 三層勾選樹：端口（根節點）－模塊－接口，勾選父節點會連帶勾選整個
                 分支（el-tree 預設 check-strictly=false 的行為），對應「按端口
                 全給／按模塊全給／單個接口給」三種顆粒度，不用另外寫全選邏輯。 -->
            <el-tree
              ref="permTreeRef"
              :data="permissionTree"
              node-key="id"
              show-checkbox
              default-expand-all
              :filter-node-method="filterPermNode"
              class="perm-picker__tree"
              @check="syncCheckedCodes"
            >
              <template #default="{ data }">
                <span class="perm-node">
                  <el-tag v-if="data.verbType" :type="data.verbType" size="small" disable-transitions>{{ data.verbLabel }}</el-tag>
                  <span class="perm-node__label">{{ data.label }}</span>
                  <code v-if="data.code" class="perm-node__code">{{ data.code }}</code>
                </span>
              </template>
            </el-tree>
          </div>
        </el-form-item>
      </el-form>
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
File: RoleList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 角色管理頁面（列表、新增、編輯、刪除），見 README 7.2、7.4。
權限挑選改成「端口－模塊－接口」三層勾選樹（見 GET /api/roles/permission-catalog
的說明），取代舊版扁平的權限碼多選下拉——那種列法要在幾十筆權限碼裡逐一
找碼很不直覺，也沒辦法一次「整個模塊全給」。
***************************************** */

import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { authFetch } from '../../stores/auth';

// 模塊代碼目前沒有站點層級的「模塊名稱」API 可查（見 MenuList.vue 同一個
// 限制），先用跟後台側邊欄一致的中文名稱對照；沒對照到的模塊代碼原樣顯示。
const MODULE_LABELS = {
  auth: '登入／個人資料',
  dictionary: '資料字典',
  menu: '選單管理',
  role: '角色管理',
  site: '站點設定',
  system_config: '系統環境配置',
  user: '使用者管理',
};

const roles = ref([]);
const permissions = ref([]);
const catalogPortal = ref(null);
const loading = ref(false);
const loadError = ref('');
const dialogVisible = ref(false);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const form = reactive({ name: '', permission_codes: [] });
const permTreeRef = ref(null);
const permFilterText = ref('');

watch(permFilterText, (val) => {
  permTreeRef.value?.filter(val);
});

// 依權限碼最後一段推斷增/刪/改/查，讓同一個模塊底下的接口能依 CRUD
// 意義排在一起、貼上顏色標籤，而不是照代碼字母序雜亂排列。
function classifyVerb(code) {
  const last = code.split('.').pop().toLowerCase();

  if (/destroy|delete|revoke/.test(last)) return { label: '刪除', order: 3, type: 'danger' };
  if (/store|create|grant/.test(last)) return { label: '新增', order: 1, type: 'success' };
  if (/update|edit|password|reset/.test(last)) return { label: '修改', order: 2, type: 'warning' };
  if (/index|list|show|item|search|option|entry|view|profile|catalog/.test(last)) return { label: '查詢', order: 0, type: 'info' };

  return { label: '其他', order: 4, type: 'info' };
}

// 三層樹：根節點＝目前端口（勾選＝端口全給）－模塊（勾選＝模塊全給）－
// 個別接口（單接口給）。el-tree 預設父子勾選會連動，不用自己刻全選邏輯。
const permissionTree = computed(() => {
  const modules = {};

  for (const perm of permissions.value) {
    const verb = classifyVerb(perm.code);

    modules[perm.module_code] ??= { id: `module:${perm.module_code}`, label: MODULE_LABELS[perm.module_code] || perm.module_code, children: [] };
    modules[perm.module_code].children.push({
      id: perm.code,
      code: perm.code,
      label: perm.summary || perm.code,
      verbLabel: verb.label,
      verbType: verb.type,
      order: verb.order,
    });
  }

  const moduleNodes = Object.values(modules).map((m) => {
    m.children.sort((a, b) => a.order - b.order || a.code.localeCompare(b.code));
    m.label = `${m.label}（${m.children.length}）`;

    return m;
  });

  moduleNodes.sort((a, b) => a.label.localeCompare(b.label, 'zh-Hant'));

  const portalName = catalogPortal.value ? `${catalogPortal.value.name}（${catalogPortal.value.portal_key}）` : '目前端口';

  return [{ id: 'portal-root', label: `端口：${portalName}`, children: moduleNodes }];
});

function filterPermNode(value, data) {
  if (!value) return true;

  const keyword = value.toLowerCase();

  return (data.label || '').toLowerCase().includes(keyword) || (data.code || '').toLowerCase().includes(keyword);
}

// el-tree 沒有反向的「checked-keys 變動」事件可以直接雙向綁定 form，
// 改成在使用者勾選時，用 getCheckedKeys(true) 只取葉節點（實際權限碼），
// 忽略 module/portal-root 這種合成節點的 key，同步回 form 供送出使用。
function syncCheckedCodes() {
  form.permission_codes = permTreeRef.value?.getCheckedKeys(true) || [];
}

async function loadRoles() {
  loading.value = true;
  loadError.value = '';

  try {
    const res = await authFetch('/api/roles?per_page=-1');
    const body = await res.json();

    if (!body.status) {
      loadError.value = body.error_message || '載入角色列表失敗';

      return;
    }

    roles.value = body.data.items;
  } finally {
    loading.value = false;
  }
}

async function loadPermissionCatalog() {
  const res = await authFetch('/api/roles/permission-catalog?per_page=-1');
  const body = await res.json();

  if (body.status) {
    permissions.value = body.data.items;
    catalogPortal.value = body.data.portal;
  }
}

// 對話框開啟當下 el-tree 才會掛載（el-dialog 預設延遲渲染 slot 內容），
// 這裡等下一個 tick 才呼叫 setCheckedKeys，不然 permTreeRef 還是 null。
function applyCheckedKeys() {
  permFilterText.value = '';
  nextTick(() => {
    permTreeRef.value?.setCheckedKeys(form.permission_codes);
  });
}

function openCreate() {
  editing.value = null;
  form.name = '';
  form.permission_codes = [];
  formError.value = '';
  dialogVisible.value = true;
  applyCheckedKeys();
}

function openEdit(row) {
  editing.value = row;
  form.name = row.name;
  form.permission_codes = [...row.permission_codes];
  formError.value = '';
  dialogVisible.value = true;
  applyCheckedKeys();
}

async function handleSave() {
  formError.value = '';
  saving.value = true;

  try {
    const path = editing.value ? `/api/roles/${editing.value.id}` : '/api/roles';
    const method = editing.value ? 'PUT' : 'POST';
    const res = await authFetch(path, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: form.name, permission_codes: form.permission_codes }),
    });
    const body = await res.json();

    if (!body.status) {
      formError.value = body.error_message || '儲存失敗';

      return;
    }

    dialogVisible.value = false;
    await loadRoles();
    ElMessage.success('已儲存');
  } finally {
    saving.value = false;
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`確定要刪除角色「${row.name}」嗎？`, '刪除確認', { type: 'warning' });
  } catch {
    return;
  }

  const res = await authFetch(`/api/roles/${row.id}`, { method: 'DELETE' });
  const body = await res.json();

  if (!body.status) {
    ElMessage.error(body.error_message || '刪除失敗');

    return;
  }

  ElMessage.success('已刪除');
  await loadRoles();
}

onMounted(() => {
  loadRoles();
  loadPermissionCatalog();
});
</script>

<style scoped>
.role-list__toolbar {
  margin-bottom: 16px;
  display: flex;
  gap: 8px;
}

.perm-picker {
  width: 100%;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
}

.perm-picker__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px;
  border-bottom: 1px solid #ebeef5;
}

.perm-picker__count {
  color: #909399;
  font-size: 12px;
  white-space: nowrap;
}

.perm-picker__tree {
  max-height: 360px;
  overflow-y: auto;
  padding: 4px 8px;
}

.perm-node {
  display: flex;
  align-items: center;
  gap: 6px;
}

.perm-node__label {
  color: #303133;
}

.perm-node__code {
  color: #b0b3b8;
  font-size: 12px;
}
</style>
