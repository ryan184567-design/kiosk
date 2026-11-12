<template>
  <div class="sites">
    <section class="sites__create">
      <h2>開設新子站</h2>
      <form @submit.prevent="handleCreate">
        <label>
          子站代碼
          <input v-model="form.code" type="text" placeholder="小寫英數底線，例如 vendor_a" required />
        </label>
        <label>
          顯示名稱
          <input v-model="form.name" type="text" required />
        </label>
        <label>
          類型
          <select v-model="form.type">
            <option value="standard">公版子站</option>
            <option value="customized">客製化子站</option>
          </select>
        </label>
        <p v-if="createError" class="sites__error">{{ createError }}</p>
        <button type="submit" :disabled="creating">{{ creating ? '建立中...' : '開設子站' }}</button>
      </form>
    </section>

    <section class="sites__list">
      <div class="sites__list-header">
        <h2>子站列表</h2>
        <button type="button" @click="loadSites">重新整理</button>
      </div>
      <p v-if="loadError" class="sites__error">{{ loadError }}</p>
      <p v-if="packageError" class="sites__error">{{ packageError }}</p>
      <p v-if="packageNotice" class="sites__notice">{{ packageNotice }}</p>
      <table v-if="sites.length">
        <thead>
          <tr>
            <th>代碼</th>
            <th>名稱</th>
            <th>類型</th>
            <th>建置狀態</th>
            <th>啟用</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="site in sites" :key="site.site_id">
            <td>{{ site.code }}</td>
            <td>{{ site.name }}</td>
            <td>{{ site.site_type === 'customized' ? '客製化' : '公版' }}</td>
            <td>
              <span :class="['status', `status--${site.provision_status}`]">{{ statusLabel(site.provision_status) }}</span>
            </td>
            <td>{{ site.is_enabled ? '是' : '否' }}</td>
            <td class="sites__actions">
              <router-link :to="{ name: 'site-detail', params: { id: site.site_id } }">詳細</router-link>
              <button
                type="button"
                class="sites__package"
                :disabled="site.provision_status !== 'ready' || packagingId === site.site_id"
                @click="handlePackage(site)"
              >
                {{ packagingId === site.site_id ? '輸出中...' : '輸出部署包' }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else-if="!loadError">尚無子站，請在上方建立第一個。</p>
    </section>
  </div>
</template>

<script setup>
/* *****************************************
File: Sites.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站列表與開站頁面，見 README 6.3、6.5。
***************************************** */

import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { authFetch } from '../stores/auth';

const router = useRouter();
const sites = ref([]);
const loadError = ref('');
const createError = ref('');
const creating = ref(false);
const packagingId = ref(null);
const packageNotice = ref('');
const packageError = ref('');
const form = reactive({ code: '', name: '', type: 'standard' });

const STATUS_LABELS = {
  pending: '待建置',
  creating: '建置中',
  ready: '已完成',
  failed: '建置失敗',
};

function statusLabel(status) {
  return STATUS_LABELS[status] ?? status;
}

async function loadSites() {
  loadError.value = '';

  const res = await authFetch('/api/hq/sites');

  if (res.status === 401) {
    router.push({ name: 'login' });

    return;
  }

  const body = await res.json();

  if (!body.status) {
    loadError.value = body.error_message || '載入子站列表失敗';

    return;
  }

  sites.value = body.data.items;
}

async function handleCreate() {
  createError.value = '';
  creating.value = true;

  try {
    const res = await authFetch('/api/hq/sites', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    });

    if (res.status === 401) {
      router.push({ name: 'login' });

      return;
    }

    const body = await res.json();

    if (!body.status) {
      createError.value = body.error_message || '開站失敗';

      return;
    }

    form.code = '';
    form.name = '';
    form.type = 'standard';
    await loadSites();
  } finally {
    creating.value = false;
  }
}

// 單站部署包輸出（見 README 6.8）：列表頁以預設輸出資料夾
// storage/app/packages/{code} 直接輸出，需要自訂資料夾（或看打包後的注意事項）
// 時再到子站詳細頁操作。後端輸出目錄若已存在會直接擋下，不會覆蓋既有部署包。
async function handlePackage(site) {
  packageError.value = '';
  packageNotice.value = '';
  packagingId.value = site.site_id;

  try {
    const res = await authFetch(`/api/hq/sites/${site.site_id}/package`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({}),
    });

    if (res.status === 401) {
      router.push({ name: 'login' });

      return;
    }

    const body = await res.json();

    if (!body.status) {
      packageError.value = body.error_message || '輸出部署包失敗';

      return;
    }

    packageNotice.value = `${site.code} 已輸出部署包：${body.data.item.output_path}（${body.data.item.file_count} 個檔案）`;
  } finally {
    packagingId.value = null;
  }
}

onMounted(loadSites);
</script>

<style scoped>
.sites {
  display: flex;
  flex-direction: column;
  gap: 32px;
}

.sites__create,
.sites__list {
  background: #fff;
  padding: 24px;
  border-radius: 8px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

.sites__create form {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  align-items: end;
}

.sites__create label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 0.875rem;
  color: #374151;
}

.sites__create input,
.sites__create select {
  padding: 8px 10px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
  font-size: 1rem;
}

.sites__create button,
.sites__list-header button {
  padding: 8px 16px;
  border: none;
  border-radius: 4px;
  background: #1f2937;
  color: #fff;
  cursor: pointer;
}

.sites__create button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.sites__list-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.sites__error {
  color: #dc2626;
  font-size: 0.875rem;
  width: 100%;
}

.sites__notice {
  color: #166534;
  font-size: 0.875rem;
  width: 100%;
}

.sites__actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.sites__package {
  padding: 4px 10px;
  border: 1px solid #1f2937;
  border-radius: 4px;
  background: #fff;
  color: #1f2937;
  cursor: pointer;
  font-size: 0.8rem;
}

.sites__package:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 16px;
}

th,
td {
  text-align: left;
  padding: 8px 12px;
  border-bottom: 1px solid #e5e7eb;
}

.status {
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 0.8rem;
}

.status--ready {
  background: #dcfce7;
  color: #166534;
}

.status--pending,
.status--creating {
  background: #fef9c3;
  color: #854d0e;
}

.status--failed {
  background: #fee2e2;
  color: #991b1b;
}
</style>
