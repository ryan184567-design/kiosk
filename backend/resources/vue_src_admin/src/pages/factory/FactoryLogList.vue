<template>
  <div class="factory-logs">
    <div class="factory-logs__toolbar">
      <el-input v-model="filters.app_id" placeholder="app_id" clearable style="width: 190px" @keyup.enter="search" @clear="search" />
      <el-input v-model="filters.module" placeholder="業務模塊，如 guest" clearable style="width: 160px" @keyup.enter="search" @clear="search" />
      <el-input v-model="filters.action" placeholder="具體方法，如 FactoryGuestApplyController@index" clearable style="width: 320px" @keyup.enter="search" @clear="search" />
      <el-select v-model="filters.type" placeholder="類型" clearable style="width: 130px" @change="search">
        <el-option v-for="(label, value) in typeLabels" :key="value" :label="label" :value="value" />
      </el-select>
      <el-select v-model="filters.success" placeholder="結果" clearable style="width: 100px" @change="search">
        <el-option label="成功" :value="1" />
        <el-option label="失敗" :value="0" />
      </el-select>
      <el-date-picker
        v-model="range"
        type="datetimerange"
        value-format="YYYY-MM-DD HH:mm:ss"
        :default-time="[new Date(2000, 0, 1, 0, 0, 0), new Date(2000, 0, 1, 23, 59, 59)]"
        start-placeholder="開始時間"
        end-placeholder="結束時間"
        style="width: 360px"
        @change="search"
      />
      <el-button type="primary" @click="search">查詢</el-button>
      <el-button @click="reset">清除條件</el-button>
    </div>

    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" style="margin-bottom: 16px" />

    <el-table :data="logs" v-loading="loading" border>
      <el-table-column prop="created_at" label="時間" width="170" />
      <el-table-column prop="app_id" label="app_id" min-width="170" />
      <el-table-column label="類型" width="100">
        <template #default="{ row }">{{ typeLabels[row.type] || row.type }}</template>
      </el-table-column>
      <el-table-column prop="module" label="業務模塊" width="110">
        <template #default="{ row }">{{ row.module || '—' }}</template>
      </el-table-column>
      <el-table-column prop="action" label="具體方法" min-width="240">
        <template #default="{ row }">{{ row.action || '—' }}</template>
      </el-table-column>
      <el-table-column label="請求" min-width="220">
        <template #default="{ row }">
          <span v-if="row.method" class="factory-logs__method">{{ row.method }}</span>{{ row.path || '' }}
        </template>
      </el-table-column>
      <el-table-column label="結果" width="110">
        <template #default="{ row }">
          <el-tag :type="row.success ? 'success' : 'danger'">{{ row.success ? '成功' : '失敗' }}</el-tag>
          <span v-if="row.status_code" class="factory-logs__code">{{ row.status_code }}</span>
        </template>
      </el-table-column>
      <el-table-column label="耗時" width="90">
        <template #default="{ row }">{{ row.duration_ms === null || row.duration_ms === undefined ? '—' : `${row.duration_ms} ms` }}</template>
      </el-table-column>
      <el-table-column prop="ip" label="來源 IP" width="130" />
      <el-table-column prop="reason" label="原因／操作" min-width="170" />
      <el-table-column label="詳情" width="80" fixed="right">
        <template #default="{ row }">
          <el-button v-if="row.type === 'call'" size="small" @click="openDetail(row)">詳情</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      class="factory-logs__pager"
      layout="total, prev, pager, next"
      :page-size="perPage"
      :total="total"
      @current-change="load"
    />

    <el-dialog v-model="detailVisible" title="API 呼叫詳情" width="900px" top="4vh">
      <div v-loading="detailLoading">
        <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" />
        <template v-if="detail">
          <el-descriptions :column="2" border size="small">
            <el-descriptions-item label="app_id">{{ detail.app_id }}</el-descriptions-item>
            <el-descriptions-item label="結果">
              <el-tag :type="detail.success ? 'success' : 'danger'" size="small">{{ detail.success ? '成功' : '失敗' }}</el-tag>
              狀態碼 {{ detail.status_code }}
            </el-descriptions-item>
            <el-descriptions-item label="請求起始時間">{{ detail.started_at || '—' }}</el-descriptions-item>
            <el-descriptions-item label="處理耗時">{{ detail.duration_ms ?? '—' }} ms</el-descriptions-item>
            <el-descriptions-item label="記錄寫入時間">{{ detail.created_at }}</el-descriptions-item>
            <el-descriptions-item label="來源 IP">{{ detail.ip }}</el-descriptions-item>
            <el-descriptions-item label="業務模塊">{{ detail.module || '—' }}</el-descriptions-item>
            <el-descriptions-item label="具體方法">{{ detail.action || '—' }}</el-descriptions-item>
            <el-descriptions-item label="請求">{{ detail.method }} {{ detail.path }}</el-descriptions-item>
            <el-descriptions-item label="路由樣式">{{ detail.route || '—' }}</el-descriptions-item>
            <el-descriptions-item label="所需 scope">{{ detail.scope || '—' }}</el-descriptions-item>
            <el-descriptions-item label="記錄原因">{{ detail.reason === 'debug' ? '除錯模式' : '非 2xx 回應' }}</el-descriptions-item>
          </el-descriptions>

          <h3 class="factory-logs__h2">請求</h3>
          <template v-for="sec in requestSections" :key="sec.title">
            <h4 class="factory-logs__h">{{ sec.title }}</h4>
            <pre class="factory-logs__pre">{{ sec.text }}</pre>
          </template>
          <p v-if="!requestSections.length" class="factory-logs__hint">（無請求資訊）</p>

          <h3 class="factory-logs__h2">回應</h3>
          <h4 class="factory-logs__h">回應 Header</h4>
          <pre class="factory-logs__pre">{{ pretty(detail.response_headers) }}</pre>
          <h4 class="factory-logs__h">回應內容（{{ detail.response_size ?? 0 }} bytes）</h4>
          <pre class="factory-logs__pre factory-logs__pre--tall">{{ pretty(detail.response_data) }}</pre>

          <p class="factory-logs__hint">敏感值（password、secret、token、authorization、cookie 等）已遮罩為 ***；每個區塊過長會被截斷並標註。記錄保留 30 天後自動移除。</p>
        </template>
      </div>
    </el-dialog>
  </div>
</template>

<script setup>
/* *****************************************
File: FactoryLogList.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: App 記錄搜尋（依 app_id、業務模塊、具體方法、結果、時間）與 API 呼叫詳情（傳入參數、完整回應）。
***************************************** */

import { computed, onMounted, reactive, ref } from 'vue';
import { fetchLogDetail, fetchLogs } from '../../api/factory';

const typeLabels = { token: '簽發 token', scope_denied: '權限被拒', call: 'API 呼叫', admin: '後台操作' };

const logs = ref([]);
const loading = ref(false);
const loadError = ref('');
const page = ref(1);
const perPage = 20;
const total = ref(0);
const range = ref(null);
const filters = reactive({ app_id: '', module: '', action: '', type: '', success: '' });

function search() {
  page.value = 1;
  load();
}

function reset() {
  Object.assign(filters, { app_id: '', module: '', action: '', type: '', success: '' });
  range.value = null;
  search();
}

async function load() {
  loading.value = true;
  loadError.value = '';

  const r = await fetchLogs({
    ...filters,
    date_from: range.value?.[0] ?? '',
    date_to: range.value?.[1] ?? '',
    page: page.value,
    per_page: perPage,
  });

  loading.value = false;

  if (!r.ok) {
    loadError.value = r.message;

    return;
  }

  logs.value = r.data.items;
  total.value = r.data.total;
}

// ── 詳情：傳入參數與完整回應 ─────────────────
const detailVisible = ref(false);
const detailLoading = ref(false);
const detailError = ref('');
const detail = ref(null);

async function openDetail(row) {
  detail.value = null;
  detailError.value = '';
  detailVisible.value = true;
  detailLoading.value = true;

  const r = await fetchLogDetail(row.id);

  detailLoading.value = false;

  if (!r.ok) {
    detailError.value = r.message;

    return;
  }

  detail.value = r.data;
}

// 請求資訊（JSON 字串）依區塊分段顯示，空的區塊不顯示
const requestSections = computed(() => {
  if (!detail.value?.request_data) {
    return [];
  }

  let req;

  try {
    req = JSON.parse(detail.value.request_data);
  } catch {
    return [{ title: '請求資訊（無法解析，顯示原文）', text: detail.value.request_data }];
  }

  const blocks = [
    ['完整 URL', req.url],
    ['客戶端（IP、User-Agent、Referer…）', req.client],
    ['路由參數', req.route_params],
    ['GET 參數（query）', req.query],
    ['POST 表單（form）', req.form],
    ['JSON body', req.json],
    ['上傳檔案（只記檔名／大小／類型）', req.files],
    ['原始 body', req.raw_body],
    ['請求 Header', req.headers],
  ];

  return blocks
    .filter(([, v]) => v !== null && v !== undefined && v !== '' && !(typeof v === 'object' && Object.keys(v).length === 0))
    .map(([title, v]) => ({ title, text: typeof v === 'string' ? v : JSON.stringify(v, null, 2) }));
});

// 內容是 JSON 字串就排版；被截斷而不是合法 JSON 時照原樣顯示
function pretty(text) {
  if (!text) {
    return '（無）';
  }

  try {
    return JSON.stringify(JSON.parse(text), null, 2);
  } catch {
    return text;
  }
}

onMounted(load);
</script>

<style scoped>
.factory-logs__method {
  display: inline-block;
  min-width: 48px;
  margin-right: 6px;
  font-weight: 600;
  color: var(--el-color-primary);
}

.factory-logs__code {
  margin-left: 6px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.factory-logs__h2 {
  margin: 22px 0 4px;
  padding-bottom: 4px;
  border-bottom: 1px solid var(--el-border-color);
}

.factory-logs__pre--tall {
  max-height: 420px;
}

.factory-logs__h {
  margin: 16px 0 6px;
}

.factory-logs__pre {
  max-height: 260px;
  overflow: auto;
  padding: 10px 12px;
  background: var(--el-fill-color-light);
  border-radius: 4px;
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-all;
}

.factory-logs__hint {
  margin-top: 8px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}

.factory-logs__toolbar {
  margin-bottom: 16px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.factory-logs__pager {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
