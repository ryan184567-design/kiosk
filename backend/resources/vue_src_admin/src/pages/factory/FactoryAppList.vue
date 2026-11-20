<template>
  <div class="factory-apps">
    <div class="factory-apps__toolbar">
      <el-input v-model="keyword" placeholder="名稱／app_id" clearable style="width: 220px" @keyup.enter="load" @clear="load" />
      <el-select v-model="statusFilter" placeholder="狀態" clearable style="width: 120px" @change="load">
        <el-option label="啟用" :value="1" />
        <el-option label="停用" :value="0" />
      </el-select>
      <el-button @click="load">查詢</el-button>
      <el-button type="primary" @click="openCreate">新增 App</el-button>
    </div>

    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" style="margin-bottom: 16px" />

    <el-table :data="apps" v-loading="loading" border>
      <el-table-column prop="name" label="名稱" min-width="150" />
      <el-table-column prop="app_id" label="app_id" min-width="190" />
      <el-table-column label="狀態" width="80">
        <template #default="{ row }">
          <el-tag :type="row.status ? 'success' : 'info'">{{ row.status ? '啟用' : '停用' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="除錯模式" width="110">
        <template #default="{ row }">
          <el-tag v-if="row.is_debug" type="warning">除錯中</el-tag>
          <span v-else class="factory-apps__muted">僅記錄失敗</span>
        </template>
      </el-table-column>
      <el-table-column label="已授權" min-width="200">
        <template #default="{ row }">
          <el-tag v-for="s in row.scopes" :key="s" :type="s === '*' ? 'danger' : 'primary'" size="small" class="factory-apps__tag">{{ s }}</el-tag>
          <span v-if="!row.scopes.length" class="factory-apps__muted">未授權</span>
        </template>
      </el-table-column>
      <el-table-column prop="expired_at" label="過期時間" width="170">
        <template #default="{ row }">{{ row.expired_at || '不過期' }}</template>
      </el-table-column>
      <el-table-column prop="last_used_at" label="最近簽發" width="170">
        <template #default="{ row }">{{ row.last_used_at || '—' }}</template>
      </el-table-column>
      <el-table-column label="操作" width="400" fixed="right">
        <template #default="{ row }">
          <el-button size="small" @click="openScopes(row)">授權</el-button>
          <el-button size="small" @click="openEdit(row)">編輯</el-button>
          <el-button size="small" @click="handleToggle(row)">{{ row.status ? '停用' : '啟用' }}</el-button>
          <el-button size="small" type="warning" @click="handleResetKey(row)">重置 key</el-button>
          <el-button size="small" type="danger" @click="handleDelete(row)">刪除</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      class="factory-apps__pager"
      layout="total, prev, pager, next"
      :page-size="perPage"
      :total="total"
      @current-change="load"
    />

    <!-- 新增／編輯 -->
    <el-dialog v-model="formVisible" :title="editing ? '編輯 App' : '新增 App'" width="520px">
      <el-form label-width="100px">
        <el-form-item label="名稱">
          <el-input v-model="form.name" placeholder="例如 自助訪客機 1 號" />
        </el-form-item>
        <el-form-item label="IP 白名單">
          <el-input v-model="form.allowed_ips_text" type="textarea" :rows="3" placeholder="每行一個 IP 或 CIDR，留空代表不限制（建議設定）" />
        </el-form-item>
        <el-form-item label="過期時間">
          <el-date-picker v-model="form.expired_at" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" placeholder="留空代表不過期" />
        </el-form-item>
        <el-form-item label="備註">
          <el-input v-model="form.remark" />
        </el-form-item>
        <el-form-item label="除錯模式">
          <el-switch v-model="form.is_debug" />
          <div class="factory-apps__tip">
            關閉：只記錄回應非 2xx（失敗）的呼叫。<br />
            開啟：成功失敗都記錄，包含傳入參數與完整回應；資料量大，除錯完請關閉。
          </div>
        </el-form-item>
      </el-form>
      <el-alert v-if="formError" :title="formError" type="error" :closable="false" />
      <template #footer>
        <el-button @click="formVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">儲存</el-button>
      </template>
    </el-dialog>

    <!-- app_secret 只顯示一次 -->
    <el-dialog v-model="keyVisible" title="請立即保存 app_secret" width="560px" :close-on-click-modal="false" :close-on-press-escape="false" :show-close="false">
      <el-alert type="warning" :closable="false" title="app_secret 只會顯示這一次，關閉後無法再查看，遺失只能重置。" style="margin-bottom: 16px" />
      <el-descriptions :column="1" border>
        <el-descriptions-item label="app_id">{{ issued.app_id }}</el-descriptions-item>
        <el-descriptions-item label="app_secret"><code>{{ issued.app_secret }}</code></el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button @click="copyKey">複製 app_secret</el-button>
        <el-checkbox v-model="keySaved" style="margin: 0 12px">我已保存</el-checkbox>
        <el-button type="primary" :disabled="!keySaved" @click="closeKey">關閉</el-button>
      </template>
    </el-dialog>

    <!-- 授權 -->
    <el-dialog v-model="scopeVisible" :title="`授權：${activeApp?.name || ''}`" width="640px">
      <el-alert type="info" :closable="false" style="margin-bottom: 12px"
        title="勾選模塊代表整個模塊（模塊.*），包含之後新增的接口；也可只勾單個接口。變更最多 60 秒內生效。" />
      <div v-loading="scopeLoading">
        <el-checkbox :model-value="selected.has('*')" @change="toggleAll">
          <strong class="factory-apps__danger">全部權限（*）</strong>
        </el-checkbox>
        <el-empty v-if="!scopeGroups.length && !scopeLoading" description="尚無已登記的 scope，請先執行 php artisan factory:sync-scopes" />
        <div v-for="g in scopeGroups" :key="g.module" class="factory-apps__group">
          <el-checkbox :model-value="selected.has(`${g.module}.*`)" :disabled="selected.has('*')" @change="(v) => toggle(`${g.module}.*`, v)">
            <strong>{{ g.module }}</strong>（整個模塊）
          </el-checkbox>
          <div class="factory-apps__scopes">
            <el-checkbox
              v-for="s in g.scopes"
              :key="s.code"
              :model-value="covered(s.code)"
              :disabled="selected.has('*') || selected.has(`${g.module}.*`)"
              @change="(v) => toggle(s.code, v)"
            >
              {{ s.code }}<span v-if="s.name" class="factory-apps__muted"> {{ s.name }}</span>
            </el-checkbox>
          </div>
        </div>
      </div>
      <el-alert v-if="scopeError" :title="scopeError" type="error" :closable="false" style="margin-top: 12px" />
      <template #footer>
        <el-button @click="scopeVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSaveScopes">儲存授權</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
/* *****************************************
File: FactoryAppList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 廠商 App 管理：列表、新增／編輯、重置 app_secret（只顯示一次）、scope 授權、停用／刪除。
             這頁同時是「後台管理頁」的樣本：呼叫封裝放 api/factory.js，頁面不直接寫 fetch。
***************************************** */

import { computed, onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { createApp, deleteApp, fetchApps, fetchScopes, resetAppKey, setAppScopes, updateApp } from '../../api/factory';

const apps = ref([]);
const loading = ref(false);
const loadError = ref('');
const keyword = ref('');
const statusFilter = ref('');
const page = ref(1);
const perPage = 10;
const total = ref(0);
const saving = ref(false);

async function load() {
  loading.value = true;
  loadError.value = '';

  const r = await fetchApps({ keyword: keyword.value, status: statusFilter.value, page: page.value, per_page: perPage });

  loading.value = false;

  if (!r.ok) {
    loadError.value = r.message;

    return;
  }

  apps.value = r.data.items;
  total.value = r.data.total;
}

// ── 新增／編輯 ───────────────────────────────
const formVisible = ref(false);
const editing = ref(null);
const formError = ref('');
const form = reactive({ name: '', allowed_ips_text: '', expired_at: null, remark: '', is_debug: false });

function openCreate() {
  editing.value = null;
  Object.assign(form, { name: '', allowed_ips_text: '', expired_at: null, remark: '', is_debug: false });
  formError.value = '';
  formVisible.value = true;
}

function openEdit(row) {
  editing.value = row;
  Object.assign(form, { name: row.name, allowed_ips_text: row.allowed_ips.join('\n'), expired_at: row.expired_at, remark: row.remark || '', is_debug: !!row.is_debug });
  formError.value = '';
  formVisible.value = true;
}

async function handleSave() {
  formError.value = '';

  // 新開啟除錯模式時提醒：會存傳入參數與完整回應（已遮罩敏感欄位，但仍可能含業務資料）
  if (form.is_debug && !(editing.value && editing.value.is_debug)) {
    try {
      await ElMessageBox.confirm(
        '除錯模式會記錄這個 App 每次呼叫的傳入參數與完整回應（密碼、token 等敏感欄位會遮罩），資料量大且可能包含業務資料。除錯完請記得關閉。確定開啟？',
        '開啟除錯模式',
        { type: 'warning' },
      );
    } catch {
      return;
    }
  }

  saving.value = true;

  const payload = {
    name: form.name,
    allowed_ips: form.allowed_ips_text.split('\n').map((s) => s.trim()).filter(Boolean),
    expired_at: form.expired_at || null,
    remark: form.remark || null,
    is_debug: form.is_debug,
  };

  const r = editing.value
    ? await updateApp(editing.value.id, { ...payload, status: editing.value.status })
    : await createApp(payload);

  saving.value = false;

  if (!r.ok) {
    formError.value = r.message;

    return;
  }

  formVisible.value = false;

  if (!editing.value) {
    showKey(r.data);
  }

  ElMessage.success('已儲存');
  await load();
}

async function handleToggle(row) {
  const next = !row.status;

  try {
    await ElMessageBox.confirm(`確定要${next ? '啟用' : '停用'}「${row.name}」嗎？${next ? '' : '停用後該 App 立即無法使用。'}`, '確認', { type: 'warning' });
  } catch {
    return;
  }

  const r = await updateApp(row.id, {
    name: row.name, status: next, allowed_ips: row.allowed_ips, expired_at: row.expired_at, remark: row.remark,
  });

  if (!r.ok) {
    ElMessage.error(r.message);

    return;
  }

  await load();
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`確定要刪除「${row.name}」嗎？該 App 會立即失效（歷史記錄保留）。`, '刪除確認', { type: 'warning' });
  } catch {
    return;
  }

  const r = await deleteApp(row.id);

  if (!r.ok) {
    ElMessage.error(r.message);

    return;
  }

  ElMessage.success('已刪除');
  await load();
}

// ── app_secret 只顯示一次 ─────────────────────────
const keyVisible = ref(false);
const keySaved = ref(false);
const issued = reactive({ app_id: '', app_secret: '' });

function showKey(data) {
  Object.assign(issued, { app_id: data.app_id, app_secret: data.app_secret });
  keySaved.value = false;
  keyVisible.value = true;
}

async function copyKey() {
  try {
    await navigator.clipboard.writeText(issued.app_secret);
    ElMessage.success('已複製');
  } catch {
    ElMessage.warning('瀏覽器不允許自動複製，請手動選取');
  }
}

function closeKey() {
  keyVisible.value = false;
  issued.app_secret = '';
}

async function handleResetKey(row) {
  try {
    await ElMessageBox.confirm(`重置後舊 app_secret 立即失效，該機器需更新設定才能繼續使用。確定重置「${row.name}」？`, '重置 app_secret', { type: 'warning' });
  } catch {
    return;
  }

  const r = await resetAppKey(row.id);

  if (!r.ok) {
    ElMessage.error(r.message);

    return;
  }

  showKey(r.data);
}

// ── 授權 ─────────────────────────────────────
const scopeVisible = ref(false);
const scopeLoading = ref(false);
const scopeError = ref('');
const scopeGroups = ref([]);
const activeApp = ref(null);
const selected = ref(new Set());

// 已被 `模塊.*` 或 `*` 涵蓋的單項，畫面上視為已勾選（但不可取消，需先取消上層）
const covered = computed(() => (code) => selected.value.has(code) || selected.value.has('*') || selected.value.has(`${code.split('.')[0]}.*`));

async function openScopes(row) {
  activeApp.value = row;
  selected.value = new Set(row.scopes);
  scopeError.value = '';
  scopeVisible.value = true;
  scopeLoading.value = true;

  const r = await fetchScopes();

  scopeLoading.value = false;

  if (!r.ok) {
    scopeError.value = r.message;

    return;
  }

  scopeGroups.value = r.data.items;
}

function toggle(code, on) {
  const next = new Set(selected.value);

  if (on) {
    next.add(code);

    // 勾整個模塊時，單項授權變成多餘，移除避免混淆
    if (code.endsWith('.*')) {
      [...next].filter((c) => c !== code && c.startsWith(code.slice(0, -1))).forEach((c) => next.delete(c));
    }
  } else {
    next.delete(code);
  }

  selected.value = next;
}

async function toggleAll(on) {
  if (on) {
    try {
      await ElMessageBox.confirm('「全部權限（*）」會讓這個 App 能呼叫所有現有與未來的機器接口，僅限特殊場景。確定要授予？', '高風險授權', { type: 'error' });
    } catch {
      return;
    }

    selected.value = new Set(['*']);

    return;
  }

  selected.value = new Set();
}

async function handleSaveScopes() {
  scopeError.value = '';
  saving.value = true;

  const r = await setAppScopes(activeApp.value.id, [...selected.value]);

  saving.value = false;

  if (!r.ok) {
    scopeError.value = r.message;

    return;
  }

  scopeVisible.value = false;
  ElMessage.success('已儲存授權（最多 60 秒內生效）');
  await load();
}

onMounted(load);
</script>

<style scoped>
.factory-apps__toolbar {
  margin-bottom: 16px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.factory-apps__tag {
  margin: 0 4px 4px 0;
}

.factory-apps__tip {
  margin-top: 4px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  line-height: 1.5;
}

.factory-apps__muted {
  color: var(--el-text-color-secondary);
}

.factory-apps__danger {
  color: var(--el-color-danger);
}

.factory-apps__pager {
  margin-top: 16px;
  justify-content: flex-end;
}

.factory-apps__group {
  margin-top: 16px;
}

.factory-apps__scopes {
  display: flex;
  flex-direction: column;
  padding-left: 24px;
}
</style>
