<template>
  <div class="menu-list">
    <el-card shadow="never" class="menu-list__card">
      <template #header>
        <div class="menu-list__toolbar">
          <div class="menu-list__toolbar-group">
            <span class="menu-list__portal-label">端口</span>
            <el-select v-model="selectedPortalId" style="width: 200px" @change="loadMenus">
              <el-option v-for="p in portals" :key="p.id" :label="`${p.name}（${p.portal_key}）`" :value="p.id" />
            </el-select>
            <el-input
              v-model="keyword"
              placeholder="搜尋標題／識別碼／路徑"
              clearable
              style="width: 220px"
              :prefix-icon="Search"
            />
          </div>
          <div class="menu-list__toolbar-group">
            <el-button-group>
              <el-button @click="toggleExpand(true)">全部展開</el-button>
              <el-button @click="toggleExpand(false)">全部收合</el-button>
            </el-button-group>
            <el-button @click="loadMenus">
              <el-icon><Refresh /></el-icon>
              <span>重新整理</span>
            </el-button>
            <el-button @click="openCreate('group')">
              <el-icon><FolderAdd /></el-icon>
              <span>新增分組選單</span>
            </el-button>
            <el-button type="primary" @click="openCreate('function')">
              <el-icon><Plus /></el-icon>
              <span>新增功能選單</span>
            </el-button>
          </div>
        </div>
      </template>

      <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon style="margin-bottom: 16px" />
      <el-alert type="info" :closable="false" show-icon class="menu-list__hint">
        <template #title>
          選單固定兩層：第一層可以是<strong>功能選單</strong>（直接點擊開頁）或<strong>分組選單</strong>（無功能、只用來展開第二層，側邊欄可見性由子選單決定）；拖曳節點可調整排序／改掛父節點，已有子節點的項目不能再掛到別的節點底下，子節點也不能再掛子節點。
        </template>
      </el-alert>

      <el-tree
        v-if="visibleTree.length"
        :key="treeKey"
        ref="treeRef"
        :data="visibleTree"
        :props="{ children: '_children' }"
        v-loading="loading"
        node-key="id"
        draggable
        class="menu-tree"
        :default-expand-all="allExpanded"
        :allow-drop="allowDrop"
        :allow-drag="allowDrag"
        @node-drop="handleNodeDrop"
      >
        <template #default="{ data }">
          <div class="menu-node" :class="{ 'menu-node--group': isGroupNode(data) }">
            <div class="menu-node__icon-wrap" :class="{ 'menu-node__icon-wrap--group': isGroupNode(data) }">
              <el-icon v-if="iconComponent(data.icon)" class="menu-node__icon"><component :is="iconComponent(data.icon)" /></el-icon>
              <el-icon v-else class="menu-node__icon menu-node__icon--placeholder"><Menu /></el-icon>
            </div>

            <div class="menu-node__text">
              <div class="menu-node__title-row">
                <span class="menu-node__title">{{ data.title }}</span>
                <code class="menu-node__key">{{ data.key }}</code>
                <el-icon v-if="data.target === '_blank'" class="menu-node__target" title="新視窗開啟"><Position /></el-icon>
              </div>
              <div v-if="data.subtitle || data.path" class="menu-node__subtitle-row">
                <span v-if="data.subtitle" class="menu-node__subtitle">{{ data.subtitle }}</span>
                <span v-if="data.subtitle && data.path" class="menu-node__dot">·</span>
                <span v-if="data.path" class="menu-node__path">{{ data.path }}</span>
              </div>
            </div>

            <div class="menu-node__tags">
              <el-tag v-if="isGroupNode(data)" size="small" round :type="childCount(data) ? 'info' : 'warning'">分組</el-tag>
              <el-tag v-if="data.module_code" size="small" round type="primary" effect="plain">{{ data.module_code }}</el-tag>
              <el-tag v-if="data.login_only" size="small" round type="warning" effect="plain">僅需登入</el-tag>
              <el-tooltip
                v-if="isHiddenLeaf(data)"
                content="此節點沒有模塊代碼也沒有權限碼，又不是分組父節點，依權限過濾規則（7.3）側邊欄一律不顯示；請改成分組選單（掛上子選單）或補上功能權限來源。"
                placement="top"
              >
                <el-tag size="small" round type="danger">側邊欄不顯示</el-tag>
              </el-tooltip>
              <el-tag v-if="childCount(data)" size="small" round type="success" effect="plain">子選單 {{ childCount(data) }}</el-tag>
              <el-tag size="small" round :type="data.is_enabled ? 'success' : 'info'" effect="plain">{{ data.is_enabled ? '啟用' : '停用' }}</el-tag>
            </div>

            <div class="menu-node__actions">
              <el-tooltip content="新增子選單" placement="top">
                <el-button size="small" circle :disabled="data.parent_id !== null" @click.stop="openCreateChild(data)">
                  <el-icon><Plus /></el-icon>
                </el-button>
              </el-tooltip>
              <el-tooltip content="編輯" placement="top">
                <el-button size="small" circle @click.stop="openEdit(data)">
                  <el-icon><EditPen /></el-icon>
                </el-button>
              </el-tooltip>
              <el-tooltip content="刪除" placement="top">
                <el-button size="small" circle type="danger" plain @click.stop="handleDelete(data)">
                  <el-icon><Delete /></el-icon>
                </el-button>
              </el-tooltip>
            </div>
          </div>
        </template>
      </el-tree>
      <el-empty
        v-else-if="!loading"
        description="目前端口尚無任何選單——第一層可放「功能選單」（綁模塊、有實際頁面），或「分組選單」（無功能，只用來展開第二層）"
      />
    </el-card>

    <el-dialog v-model="dialogVisible" :title="editing ? '編輯選單' : '新增選單'" width="640px">
      <el-form label-width="110px">
        <el-form-item label="節點類型">
          <el-radio-group v-model="nodeKind" :disabled="!!editing && editing.parent_id !== null">
            <el-radio value="function">功能選單（有實際頁面）</el-radio>
            <el-radio value="group">分組選單（僅展開第二層）</el-radio>
          </el-radio-group>
          <div v-if="nodeKind === 'group'" class="menu-form__hint">
            分組選單不綁定任何功能，module_code／permission_code／路徑／開啟方式一律留空；側邊欄上它會以可展開的子選單呈現，可見性由底下第二層子選單決定（子選單有人看得到，它就會出現）。
          </div>
        </el-form-item>
        <el-form-item label="識別碼">
          <el-input v-model="form.key" placeholder="同端口內不可重複，例如 system_group" />
        </el-form-item>
        <el-form-item label="標題">
          <el-input v-model="form.title" placeholder="側邊欄顯示的文字" />
        </el-form-item>
        <el-form-item label="副標題">
          <el-input v-model="form.subtitle" placeholder="可省略" />
        </el-form-item>
        <el-form-item label="父節點">
          <el-select
            v-model="form.parent_id"
            clearable
            style="width: 100%"
            placeholder="無（頂層節點）"
            :disabled="!!editing && editingHasChildren"
          >
            <el-option v-for="m in parentOptions" :key="m.id" :label="m.title" :value="m.id" />
          </el-select>
          <div v-if="editing && editingHasChildren" class="menu-form__hint">此節點底下已有子選單，只能維持頂層。</div>
        </el-form-item>
        <template v-if="nodeKind === 'function'">
          <el-form-item label="模塊代碼">
            <el-select
              v-model="form.module_code"
              clearable
              filterable
              style="width: 100%"
              placeholder="請選擇已登記的模塊（未登記會被拒絕）"
            >
              <el-option v-for="m in modules" :key="m.code" :label="`${m.name}（${m.code}）`" :value="m.code" />
            </el-select>
          </el-form-item>
          <el-form-item label="額外權限碼">
            <el-input v-model="form.permission_code" :disabled="form.login_only" placeholder="可省略；module_code 與權限碼擇一填寫，都不填此節點不會顯示" />
          </el-form-item>
          <el-form-item label="僅需登入即可見">
            <el-switch v-model="form.login_only" />
            <span class="menu-form__hint">開啟後跳過模塊代碼／權限碼判斷，只要登入這個端口就看得到（例如儀表板）</span>
          </el-form-item>
          <el-form-item label="前端路徑">
            <el-input v-model="form.path" placeholder="例如 /admin/users" />
          </el-form-item>
          <el-form-item label="開啟方式">
            <el-radio-group v-model="form.target">
              <el-radio value="_self">目前視窗</el-radio>
              <el-radio value="_blank">新視窗</el-radio>
            </el-radio-group>
          </el-form-item>
        </template>
        <el-form-item label="圖示">
          <el-select v-model="form.icon" filterable allow-create clearable default-first-option style="width: 100%" placeholder="可省略；可直接輸入 Element Plus 圖示名稱">
            <el-option v-for="name in COMMON_ICONS" :key="name" :label="name" :value="name" />
          </el-select>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" />
        </el-form-item>
        <el-form-item label="啟用">
          <el-switch v-model="form.is_enabled" />
        </el-form-item>
      </el-form>
      <el-alert
        title="可見性判斷：module_code 對應頁面被 permission:sync-web 掃到的權限碼，加上額外權限碼（若有填），使用者持有其中任一個即可看到此節點；分組選單的可見性則由其子選單決定。"
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
File: MenuList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 選單管理頁面（列表、新增、編輯、刪除、拖曳排序），見 README 7.3。
支援切換端口管理各端口自己的選單（含後台自己的 admin 端口，見
AdminLayout.vue 已改成從這裡讀出的資料動態渲染側邊欄），選單固定兩層、
可拖曳調整排序與父子關係、可設定開啟方式（目前視窗／新視窗）。
節點分「功能選單」（綁 module_code／permission_code，有實際頁面）與
「分組選單」（無功能、只用來展開第二層；側邊欄可見性由子選單決定），
列表上會標示每個節點的側邊欄可見性狀態，避免「建了第一層卻看不到」的困惑。
***************************************** */

import { computed, onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { Delete, EditPen, FolderAdd, Menu, Plus, Position, Refresh, Search } from '@element-plus/icons-vue';
import * as Icons from '@element-plus/icons-vue';
import { authFetch } from '../../stores/auth';

const flatMenus = ref([]);
const portals = ref([]);
const selectedPortalId = ref(null);
const loading = ref(false);
const loadError = ref('');
const dialogVisible = ref(false);
const editing = ref(null);
const saving = ref(false);
const formError = ref('');
const keyword = ref('');
// 模塊代碼下拉選項（GET /api/menus/modules），見 MenuController::modules。
const modules = ref([]);
// 樹的展開／收合：el-tree 沒有一鍵收合的公開 API，這裡用動態
// default-expand-all + 遞增 key 強制重建整棵樹來實現（見 toggleExpand）。
const allExpanded = ref(true);
const treeKey = ref(0);
// 節點類型：function=功能選單（綁 module_code／permission_code）、
// group=分組選單（無功能，僅用來展開第二層）。
const nodeKind = ref('function');

// 表單 icon 下拉的常用建議（Element Plus 圖示名稱，allow-create 可自行輸入
// 其他名稱）；清單跟側邊欄的 iconComponent 映射共用同一個套件。
const COMMON_ICONS = [
  'HomeFilled', 'Menu', 'Setting', 'User', 'UserFilled', 'Lock', 'Key', 'List',
  'Grid', 'Document', 'Files', 'DataAnalysis', 'Management', 'Operation', 'Tools',
  'Monitor', 'Bell', 'Link', 'Position', 'OfficeBuilding', 'Shop', 'Coordinate',
];

const form = reactive({
  key: '',
  title: '',
  subtitle: '',
  parent_id: null,
  module_code: '',
  permission_code: '',
  login_only: false,
  icon: '',
  path: '',
  target: '_self',
  sort: 0,
  is_enabled: true,
});

// icon 名稱 → Element Plus 圖示元件（選單管理列表與側邊欄共用同一套映射；
// 名稱不存在或未填回 null，呼叫端不渲染 icon）。
function iconComponent(name) {
  return name && Icons[name] ? Icons[name] : null;
}

// 分組節點＝module_code 與 permission_code 都沒填、也不是 login_only
// （7.3：側邊欄可見性由子選單決定）。login_only=true 的節點（例如儀表板）
// 雖然也沒填 module_code/permission_code，但它是「只要登入即可見」的
// 功能選單，不是分組——分組本身不能點擊，login_only 節點是真的能點進去
// 的頁面，兩者語意不同。
function isGroupNode(row) {
  return !row.module_code && !row.permission_code && !row.login_only;
}

function childCount(row) {
  return flatMenus.value.filter((m) => m.parent_id === row.id).length;
}

// 「側邊欄不顯示」警示：葉節點（沒有子選單）且沒有任何權限來源。
// MenuService::buildVisible 對這種節點一律過濾掉（7.3 的保守設計），
// 這裡明確標出來，避免「剛建好第一層、側邊欄看不到」的困惑。
function isHiddenLeaf(row) {
  return isGroupNode(row) && childCount(row) === 0;
}

// 見 MenuController::index() 說明：後端回傳扁平清單，前端自己依 parent_id
// 組出樹狀結構；選單固定兩層，這裡的樹只會有 1-2 層深，不用處理遞迴多層。
// 搜尋（keyword）過濾後保留符合節點與其父鏈（子節點符合時父節點也要看得到）。
const menuTree = computed(() => {
  const byId = new Map(flatMenus.value.map((m) => [m.id, { ...m, _children: [] }]));
  const roots = [];

  for (const m of byId.values()) {
    if (m.parent_id && byId.has(m.parent_id)) {
      byId.get(m.parent_id)._children.push(m);
    } else {
      roots.push(m);
    }
  }

  roots.sort((a, b) => a.sort - b.sort);
  roots.forEach((r) => r._children.sort((a, b) => a.sort - b.sort));

  return roots;
});

const visibleTree = computed(() => {
  const kw = keyword.value.trim().toLowerCase();

  if (!kw) {
    return menuTree.value;
  }

  const match = (m) => [m.title, m.key, m.path, m.module_code].some((v) => v && v.toLowerCase().includes(kw));

  const filterTree = (nodes) => nodes
    .map((n) => ({ ...n, _children: filterTree(n._children) }))
    .filter((n) => match(n) || n._children.length > 0);

  return filterTree(menuTree.value);
});

function toggleExpand(expand) {
  allExpanded.value = expand;
  treeKey.value += 1;
}

// 新增/編輯時可選的父節點：只能選頂層節點（兩層限制），編輯中不能選自己。
const parentOptions = computed(() =>
  flatMenus.value.filter((m) => m.parent_id === null && (!editing.value || m.id !== editing.value.id))
);

const editingHasChildren = computed(() => {
  if (!editing.value) return false;

  return flatMenus.value.some((m) => m.parent_id === editing.value.id);
});

async function loadPortals() {
  const res = await authFetch('/api/menus/portals');
  const body = await res.json();

  if (body.status) {
    portals.value = body.data;

    if (!selectedPortalId.value && body.data.length) {
      selectedPortalId.value = body.data.find((p) => p.portal_key === 'admin')?.id ?? body.data[0].id;
    }
  }
}

// 模塊代碼下拉選項（見 MenuController::modules：modules 主表啟用中的模塊）。
async function loadModules() {
  const res = await authFetch('/api/menus/modules');
  const body = await res.json();

  if (body.status) {
    modules.value = body.data;
  }
}

async function loadMenus() {
  if (!selectedPortalId.value) return;

  loading.value = true;
  loadError.value = '';

  try {
    const res = await authFetch(`/api/menus?per_page=-1&portal_id=${selectedPortalId.value}`);
    const body = await res.json();

    if (!body.status) {
      loadError.value = body.error_message || '載入選單列表失敗';

      return;
    }

    flatMenus.value = body.data.items;
  } finally {
    loading.value = false;
  }
}

// 拖曳限制（跟後端 MenuAdminService 的兩層限制保持一致，見該檔說明）：
// - 有子節點的節點不能被拖成別人的子節點（inner 一律禁止；prev/next 若
//   目標已經是第二層也禁止，因為結果會跟目標同一層）。
// - inner 拖放的目標本身若已經是第二層，也一律禁止（會變成第三層）。
function allowDrop(draggingNode, dropNode, type) {
  const draggingHasChildren = (draggingNode.data._children || []).length > 0;

  if (type === 'inner') {
    return dropNode.level < 2 && !draggingHasChildren;
  }

  return !(dropNode.level >= 2 && draggingHasChildren);
}

function allowDrag() {
  return true;
}

async function handleNodeDrop(draggingNode, dropNode, dropType) {
  const newParentId = dropType === 'inner' ? dropNode.data.id : (dropNode.data.parent_id ?? null);
  const siblings = dropType === 'inner' ? dropNode.childNodes : dropNode.parent.childNodes;
  const items = siblings.map((node, index) => ({ id: node.data.id, parent_id: newParentId, sort: index }));

  const res = await authFetch('/api/menus/reorder', {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ items, portal_id: selectedPortalId.value }),
  });
  const body = await res.json();

  if (!body.status) {
    ElMessage.error(body.error_message || '排序失敗');
    await loadMenus();

    return;
  }

  ElMessage.success('已更新排序');
  await loadMenus();
}

function resetForm(parentId = null) {
  Object.assign(form, {
    key: '',
    title: '',
    subtitle: '',
    parent_id: parentId,
    module_code: '',
    permission_code: '',
    login_only: false,
    icon: '',
    path: '',
    target: '_self',
    sort: 0,
    is_enabled: true,
  });
}

function openCreate(kind = 'function') {
  editing.value = null;
  nodeKind.value = kind;
  resetForm();
  formError.value = '';
  dialogVisible.value = true;
}

function openCreateChild(parentRow) {
  editing.value = null;
  // 子選單固定是功能選單（分組選單只會是第一層，兩層限制見 MenuAdminService）。
  nodeKind.value = 'function';
  resetForm(parentRow.id);
  formError.value = '';
  dialogVisible.value = true;
}

function openEdit(row) {
  editing.value = row;
  nodeKind.value = isGroupNode(row) ? 'group' : 'function';
  Object.assign(form, {
    key: row.key,
    title: row.title,
    subtitle: row.subtitle,
    parent_id: row.parent_id,
    module_code: row.module_code,
    permission_code: row.permission_code,
    login_only: row.login_only,
    icon: row.icon,
    path: row.path,
    target: row.target || '_self',
    sort: row.sort,
    is_enabled: row.is_enabled,
  });
  formError.value = '';
  dialogVisible.value = true;
}

async function handleSave() {
  formError.value = '';
  saving.value = true;

  try {
    const path = editing.value ? `/api/menus/${editing.value.id}` : '/api/menus';
    const method = editing.value ? 'PUT' : 'POST';
    const payload = { ...form };

    // 分組選單不綁定任何功能：功能欄位一律清空送出（避免切回功能類型的
    // 殘值被存進去）；分組選單也不需要 path/target（點不了）、login_only
    // 對分組也沒有意義（分組本身不是頁面，不會經過 login_only 那個判斷
    // 分支）。
    if (nodeKind.value === 'group') {
      payload.module_code = '';
      payload.permission_code = '';
      payload.login_only = false;
      payload.path = '';
      payload.target = '_self';
    }

    if (!editing.value) {
      payload.portal_id = selectedPortalId.value;
    }

    const res = await authFetch(path, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const body = await res.json();

    if (!body.status) {
      formError.value = body.error_message || '儲存失敗';

      return;
    }

    dialogVisible.value = false;
    await loadMenus();
    ElMessage.success('已儲存');
  } finally {
    saving.value = false;
  }
}

async function handleDelete(row) {
  try {
    await ElMessageBox.confirm(`確定要刪除選單「${row.title}」嗎？`, '刪除確認', { type: 'warning' });
  } catch {
    return;
  }

  const res = await authFetch(`/api/menus/${row.id}`, { method: 'DELETE' });
  const body = await res.json();

  if (!body.status) {
    ElMessage.error(body.error_message || '刪除失敗');

    return;
  }

  ElMessage.success('已刪除');
  await loadMenus();
}

onMounted(async () => {
  await Promise.all([loadPortals(), loadModules()]);
  await loadMenus();
});
</script>

<style scoped>
.menu-list__card :deep(.el-card__header) {
  padding: 16px 20px;
}

.menu-list__card :deep(.el-card__body) {
  padding: 20px;
}

.menu-list__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.menu-list__toolbar-group {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.menu-list__portal-label {
  color: #909399;
  font-size: 13px;
  white-space: nowrap;
}

.menu-list__hint {
  margin-bottom: 16px;
  line-height: 1.6;
}

.menu-form__hint {
  margin-left: 8px;
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.menu-tree {
  --el-tree-node-content-height: auto;
}

.menu-tree :deep(.el-tree-node__content) {
  height: auto;
  padding: 4px 0;
}

/* 分組（第一層）跟功能選單（第二層）之間留一點呼吸空間，視覺上更像
   分類清單，不是一整排密集的列表。 */
.menu-tree :deep(.el-tree-node) {
  margin-bottom: 2px;
}

.menu-node {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1;
  padding: 10px 14px;
  margin-right: 8px;
  border-radius: 8px;
  background: #fff;
  border: 1px solid #ebeef5;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
  transition: all var(--app-transition-duration) var(--app-transition-timing);
}

.menu-node:hover {
  border-color: #c6e2ff;
  box-shadow: 0 2px 8px rgba(64, 158, 255, 0.12);
}

/* 分組節點（第一層分類）用較深的底色＋左側強調邊條，跟第二層的功能
   選單在視覺上明確區分開，一眼就能看出誰是分類、誰是實際頁面。 */
.menu-node--group {
  background: linear-gradient(90deg, #f5f7fa 0%, #fff 60%);
  border-left: 3px solid #606266;
  padding-left: 12px;
}

.menu-node__icon-wrap {
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #ecf5ff;
}

.menu-node__icon-wrap--group {
  background: #f0f1f3;
}

.menu-node__icon {
  color: #409eff;
  font-size: 17px;
}

.menu-node__icon--placeholder {
  color: #c0c4cc;
}

.menu-node--group .menu-node__icon {
  color: #606266;
}

.menu-node__text {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  flex-shrink: 0;
}

.menu-node__title-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.menu-node__title {
  font-weight: 600;
  color: #303133;
}

.menu-node__key {
  color: #b0b3b8;
  font-size: 12px;
}

.menu-node__target {
  color: #909399;
  font-size: 14px;
}

.menu-node__subtitle-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #909399;
}

.menu-node__dot {
  color: #dcdfe6;
}

.menu-node__path {
  font-family: monospace;
}

.menu-node__tags {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.menu-node__actions {
  margin-left: auto;
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}

.menu-form__hint {
  color: #909399;
  font-size: 12px;
  line-height: 1.5;
  margin-top: 4px;
}
</style>
