<template>
  <div class="plugin-list">
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon style="margin-bottom: 16px" />
    <el-alert
      title="每個功能分類同時只會有一個品牌/版本生效——啟用某一個會自動換掉同分類下原本啟用的其他項目。第一次啟用時會自動建立這個插件的設定項，填過的值往後停用/重新啟用都不會被清掉。"
      type="info"
      :closable="false"
      show-icon
      style="margin-bottom: 16px"
    />

    <el-card v-for="group in catalog" :key="group.function" shadow="never" class="plugin-group">
      <template #header>
        <span class="plugin-group__title">{{ group.function_name }}</span>
        <code class="plugin-group__code">{{ group.function }}</code>
      </template>

      <div v-for="entry in group.entries" :key="entry.key" class="plugin-entry">
        <div class="plugin-entry__info">
          <div class="plugin-entry__title-row">
            <span class="plugin-entry__name">{{ entry.brand ? `${entry.brand} ${entry.version}` : entry.key }}</span>
            <code class="plugin-entry__key">{{ entry.key }}</code>
            <el-tag v-if="!entry.is_registered" size="small" type="warning">尚未登記</el-tag>
            <el-tag size="small" :type="entry.is_enabled ? 'success' : 'info'">{{ entry.is_enabled ? '啟用中' : '未啟用' }}</el-tag>
          </div>
          <div class="plugin-entry__class">{{ entry.class }}</div>
        </div>

        <div class="plugin-entry__actions">
          <el-tooltip v-if="!entry.is_registered" content="尚未登記在 config/plugins.php／config/sso.php，需先完成登記才能啟用" placement="top">
            <span>
              <el-switch disabled />
            </span>
          </el-tooltip>
          <el-switch
            v-else
            :model-value="entry.is_enabled"
            :loading="togglingKey === `${group.function}:${entry.key}`"
            @change="(val) => handleToggle(group.function, entry, val)"
          />
          <el-button size="small" :disabled="!entry.is_registered" @click="openSettings(group, entry)">設定</el-button>
        </div>
      </div>
    </el-card>

    <el-empty v-if="!loading && catalog.length === 0" description="尚未發現任何插件（見 app/Plugins 目錄與 config/sso.php）" />

    <el-dialog v-model="settingsVisible" :title="settingsTitle" width="720px">
      <SystemConfigEditor v-if="settingsModule" :module="settingsModule" />
    </el-dialog>
  </div>
</template>

<script setup>
/* *****************************************
File: PluginList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 插件管理頁面，見 README 6.10、17.4。列出從 app/Plugins 目錄
與 config/sso.php 掃出來的插件目錄（見 App\Support\Plugin\PluginRegistry），
可以啟用/停用、並在對話框裡掛一個 SystemConfigEditor 編輯該插件自己的
設定——跟核心系統環境設定（SystemConfigList.vue）共用同一個元件，只是
scope 到不同的 cfg_module，不用為插件另外刻一份表單。
***************************************** */

import { onMounted, ref } from 'vue';
import { ElMessage } from 'element-plus';
import SystemConfigEditor from '../../components/SystemConfigEditor.vue';
import { authFetch } from '../../stores/auth';

const catalog = ref([]);
const loading = ref(false);
const loadError = ref('');
const togglingKey = ref('');
const settingsVisible = ref(false);
const settingsModule = ref('');
const settingsTitle = ref('');

async function loadCatalog() {
  loading.value = true;
  loadError.value = '';

  try {
    const res = await authFetch('/api/plugins');
    const body = await res.json();

    if (!body.status) {
      loadError.value = body.error_message || '載入插件目錄失敗';

      return;
    }

    catalog.value = body.data;
  } finally {
    loading.value = false;
  }
}

async function handleToggle(functionCode, entry, enable) {
  const stateKey = `${functionCode}:${entry.key}`;
  togglingKey.value = stateKey;

  try {
    const path = `/api/plugins/${functionCode}/${entry.key}/${enable ? 'enable' : 'disable'}`;
    const res = await authFetch(path, { method: enable ? 'POST' : 'PUT' });
    const body = await res.json();

    if (!body.status) {
      ElMessage.error(body.error_message || '操作失敗');

      return;
    }

    ElMessage.success(enable ? '已啟用' : '已停用');
    await loadCatalog();
  } finally {
    togglingKey.value = '';
  }
}

function openSettings(group, entry) {
  settingsModule.value = entry.config_module;
  settingsTitle.value = `${group.function_name} － ${entry.brand ? `${entry.brand} ${entry.version}` : entry.key} 設定`;
  settingsVisible.value = true;
}

onMounted(loadCatalog);
</script>

<style scoped>
.plugin-group {
  margin-bottom: 16px;
}

.plugin-group__title {
  font-weight: 600;
}

.plugin-group__code {
  margin-left: 8px;
  color: #b0b3b8;
  font-size: 12px;
}

.plugin-entry {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 0;
  border-bottom: 1px solid #f0f2f5;
}

.plugin-entry:last-child {
  border-bottom: none;
  padding-bottom: 0;
}

.plugin-entry__title-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.plugin-entry__name {
  font-weight: 500;
}

.plugin-entry__key {
  color: #b0b3b8;
  font-size: 12px;
}

.plugin-entry__class {
  color: #909399;
  font-size: 12px;
  font-family: monospace;
  margin-top: 2px;
}

.plugin-entry__actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}
</style>
