<template>
  <div class="detail">
    <p><router-link :to="{ name: 'sites' }">&larr; 回子站列表</router-link></p>

    <p v-if="loadError" class="detail__error">{{ loadError }}</p>

    <template v-if="site">
      <section class="detail__card">
        <h2>{{ site.name }}（{{ site.code }}）</h2>
        <dl>
          <dt>類型</dt>
          <dd>{{ site.site_type === 'customized' ? '客製化子站' : '公版子站' }}</dd>
          <dt>建置狀態</dt>
          <dd>{{ statusLabel(site.provision_status) }}<span v-if="site.provision_failed_reason"> — {{ site.provision_failed_reason }}</span></dd>
          <dt>資料庫</dt>
          <dd>{{ site.db_name }}</dd>
          <dt>網址路徑前綴</dt>
          <dd>{{ site.path_prefix || '（未設定）' }}</dd>
          <dt>綁定網域</dt>
          <dd>
            <form class="domain-form" @submit.prevent="handleSaveDomain">
              <input
                v-model="domainInput"
                type="text"
                placeholder="例如 vendor-a.example.com，留空代表清除"
                :disabled="site.provision_status !== 'ready' || savingDomain"
              />
              <button type="submit" :disabled="site.provision_status !== 'ready' || savingDomain">
                {{ savingDomain ? '儲存中...' : '儲存' }}
              </button>
            </form>
            <p v-if="site.provision_status !== 'ready'" class="detail__hint">子站尚未建置完成，無法設定網域。</p>
            <p v-if="domainError" class="detail__error">{{ domainError }}</p>
          </dd>
          <dt>啟用狀態</dt>
          <dd>{{ site.is_enabled ? '啟用中' : '已停用' }}</dd>
        </dl>
      </section>

      <section class="detail__card">
        <h2>模塊開通狀態</h2>
        <table v-if="modules.length">
          <thead>
            <tr>
              <th>模塊</th>
              <th>核心模塊</th>
              <th>是否開通</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="module in modules" :key="module.code">
              <td>{{ module.name }}（{{ module.code }}）</td>
              <td>{{ module.is_core ? '是（恆為開通）' : '否' }}</td>
              <td>
                <label class="switch">
                  <input
                    type="checkbox"
                    :checked="module.enabled"
                    :disabled="module.is_core || toggling === module.code"
                    @change="toggleModule(module)"
                  />
                  {{ module.enabled ? '已開通' : '未開通' }}
                </label>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="moduleError" class="detail__error">{{ moduleError }}</p>
        <div v-if="moduleResult" class="detail__result">
          <p>已連動開通：{{ moduleResult.join('、') }}（依 module.json 的 depends 宣告，見 README 6.9）</p>
        </div>
      </section>

      <section class="detail__card">
        <h2>單站部署輸出</h2>
        <p class="detail__hint">
          把此子站輸出成可獨立部署到客戶機房的「單站部署包」（見 README 6.8），
          等同 CLI <code>php artisan site:package {{ site.code }}</code>，兩者共用同一份打包邏輯：
          只複製共用程式碼與本站自己的客製化目錄，其他子站的客製化內容不會進入部署包，
          主站專屬的 <code>app/Hq</code> 也不會被打包。
        </p>
        <form class="detail__package" @submit.prevent="handlePackage">
          <label>
            輸出資料夾（留空＝使用站點代碼）
            <input
              v-model="packageFolder"
              type="text"
              :placeholder="`storage/app/packages/${site.code}`"
              :disabled="packaging || site.provision_status !== 'ready'"
            />
          </label>
          <button type="submit" :disabled="packaging || site.provision_status !== 'ready'">
            {{ packaging ? '輸出中...' : '輸出單站部署包' }}
          </button>
        </form>
        <p v-if="site.provision_status !== 'ready'" class="detail__hint">
          子站尚未建置完成，無法輸出部署包。
        </p>
        <p v-if="packageError" class="detail__error">{{ packageError }}</p>
        <div v-if="packageResult" class="detail__result">
          <p>輸出目錄：<code>{{ packageResult.output_path }}</code></p>
          <p>
            內容：{{ packageResult.file_count }} 個檔案／{{ formatSize(packageResult.size_bytes) }}
            （前台 Vue 建置模式：{{ packageResult.vue_build_mode === 'dedicated' ? '獨立建置' : '共用建置' }}）
          </p>
          <ul class="detail__notes">
            <li v-for="note in packageNotes" :key="note">{{ note }}</li>
          </ul>
        </div>
      </section>

      <section class="detail__card">
        <h2>跳轉登入</h2>
        <p class="detail__hint">
          以目標子站的超級管理員身份直接進入該站後台，用於現場除錯／技術支援（見 DEVELOP.MD 6.11）。
          按下按鈕會核發一次性交換碼（60 秒內有效、僅能兌換一次），並導向子站落地頁
          <code>/hq-impersonate/callback</code> 完成登入；若該站已綁定網域會導向該網域，
          只有路徑前綴的站則導向「目前網域 + /站點代碼」。
        </p>
        <form class="detail__impersonate" @submit.prevent="handleImpersonate">
          <label>
            端口
            <input v-model="portalKey" type="text" placeholder="admin" />
          </label>
          <button type="submit" :disabled="impersonating">{{ impersonating ? '核發中...' : '跳轉登入子站' }}</button>
        </form>
        <p v-if="impersonateError" class="detail__error">{{ impersonateError }}</p>
        <div v-if="impersonateResult" class="detail__result">
          <p>
            正在導向子站（{{ impersonateResult.site_code }}）…
            若沒有自動跳轉，請<a :href="impersonateResult.redirect_url">點此進入子站後台</a>。
          </p>
          <p>交換碼：<code>{{ impersonateResult.code }}</code>（{{ impersonateResult.expires_in }} 秒內有效，僅能兌換一次）</p>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
/* *****************************************
File: SiteDetail.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站詳細資料、模塊開通、跳轉登入頁面，見 README 6.9、6.11。
***************************************** */

import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { authFetch } from '../stores/auth';

const props = defineProps({ id: { type: [String, Number], required: true } });
const router = useRouter();

const site = ref(null);
const modules = ref([]);
const loadError = ref('');
const toggling = ref(null);
const moduleError = ref('');
const moduleResult = ref(null);
const portalKey = ref('admin');
const impersonating = ref(false);
const impersonateError = ref('');
const impersonateResult = ref(null);
const domainInput = ref('');
const savingDomain = ref(false);
const domainError = ref('');
const packageFolder = ref('');
const packaging = ref(false);
const packageError = ref('');
const packageResult = ref(null);
const packageNotes = ref([]);

const STATUS_LABELS = {
  pending: '待建置',
  creating: '建置中',
  ready: '已完成',
  failed: '建置失敗',
};

function statusLabel(status) {
  return STATUS_LABELS[status] ?? status;
}

async function guardedFetch(path, options) {
  const res = await authFetch(path, options);

  if (res.status === 401) {
    router.push({ name: 'login' });

    return null;
  }

  return res;
}

async function loadSite() {
  loadError.value = '';

  const res = await guardedFetch(`/api/hq/sites/${props.id}`);
  if (!res) return;

  const body = await res.json();

  if (!body.status) {
    loadError.value = body.error_message || '找不到這個子站';

    return;
  }

  site.value = body.data.item;
  domainInput.value = body.data.item.primary_domain || '';
}

async function handleSaveDomain() {
  domainError.value = '';
  savingDomain.value = true;

  try {
    const res = await guardedFetch(`/api/hq/sites/${props.id}/domain`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ domain: domainInput.value }),
    });
    if (!res) return;

    const body = await res.json();

    if (!body.status) {
      domainError.value = body.error_message || '設定網域失敗';

      return;
    }

    site.value.primary_domain = body.data.item.primary_domain;
    domainInput.value = body.data.item.primary_domain || '';
  } finally {
    savingDomain.value = false;
  }
}

async function loadModules() {
  const res = await guardedFetch(`/api/hq/sites/${props.id}/modules`);
  if (!res) return;

  const body = await res.json();

  if (body.status) {
    modules.value = body.data.modules;
  }
}

// 單站部署輸出，見 README 6.8。輸出資料夾留空＝由後端套用預設值
// （storage/app/packages/{code}），填值時後端只接受該目錄底下的相對路徑。
async function handlePackage() {
  packageError.value = '';
  packageResult.value = null;
  packageNotes.value = [];
  packaging.value = true;

  try {
    const res = await guardedFetch(`/api/hq/sites/${props.id}/package`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ output: packageFolder.value }),
    });
    if (!res) return;

    const body = await res.json();

    if (!body.status) {
      packageError.value = body.error_message || '輸出部署包失敗';

      return;
    }

    packageResult.value = body.data.item;
    packageNotes.value = body.data.notes || [];
  } finally {
    packaging.value = false;
  }
}

function formatSize(bytes) {
  if (!bytes) return '0 B';

  const units = ['B', 'KB', 'MB', 'GB'];
  let value = bytes;
  let unit = 0;

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  return `${value.toFixed(unit === 0 ? 0 : 1)} ${units[unit]}`;
}

// 見 README 6.9：開通可能因 module.json 的 depends 宣告而連動開通其他
// 模塊，關閉則可能因為還有其他已開通模塊依賴它而被拒絕——兩種情況都
// 不能只翻轉點下去那一個模塊的本地狀態，要整份重新載入才能反映實際
// 結果（連動開通的模塊、被拒絕時维持原狀）。
async function toggleModule(module) {
  toggling.value = module.code;
  moduleError.value = '';
  moduleResult.value = null;
  const action = module.enabled ? 'disable' : 'enable';

  try {
    const res = await guardedFetch(`/api/hq/sites/${props.id}/modules/${module.code}/${action}`, { method: 'POST' });
    if (!res) return;

    const body = await res.json();

    if (!body.status) {
      moduleError.value = body.error_message || '操作失敗';

      return;
    }

    if (action === 'enable' && body.data?.cascaded_modules?.length) {
      moduleResult.value = body.data.cascaded_modules;
    }

    await loadModules();
  } finally {
    toggling.value = null;
  }
}

async function handleImpersonate() {
  impersonateError.value = '';
  impersonateResult.value = null;
  impersonating.value = true;

  try {
    const res = await guardedFetch(`/api/hq/sites/${props.id}/impersonate-token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ portal_key: portalKey.value || 'admin' }),
    });
    if (!res) return;

    const body = await res.json();

    if (!body.status) {
      impersonateError.value = body.error_message || '核發失敗';

      return;
    }

    impersonateResult.value = body.data;

    // 見 DEVELOP.MD 6.11 第 2 點：交換碼本身不能直接用來登入子站，要帶著它
    // 導頁到子站落地頁，由子站端換成正式 JWT 並完成登入。落地頁網址由後端
    // 組好（網域已綁定／只有路徑前綴的站規則不同，見 6.1），前端不自己拼。
    // 用 assign 而非 window.open：非使用者手勢直接觸發的 window.open 容易被
    // 瀏覽器彈窗攔截；主站 JWT 存在 localStorage，同分頁導走後再回首頁仍保持登入。
    window.location.assign(body.data.redirect_url);
  } finally {
    impersonating.value = false;
  }
}

onMounted(() => {
  loadSite();
  loadModules();
});
</script>

<style scoped>
.detail {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.detail__card {
  background: #fff;
  padding: 24px;
  border-radius: 8px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

.detail__card h2 {
  margin-top: 0;
}

dl {
  display: grid;
  grid-template-columns: 140px 1fr;
  row-gap: 8px;
}

dt {
  color: #6b7280;
}

.domain-form {
  display: flex;
  gap: 8px;
}

.domain-form input {
  flex: 1;
  padding: 6px 10px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
}

.domain-form button {
  padding: 6px 14px;
  border: none;
  border-radius: 4px;
  background: #1f2937;
  color: #fff;
  cursor: pointer;
}

.domain-form button:disabled,
.domain-form input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

dd {
  margin: 0;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  text-align: left;
  padding: 8px 12px;
  border-bottom: 1px solid #e5e7eb;
}

.switch {
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
}

.detail__hint {
  color: #6b7280;
  font-size: 0.875rem;
}

.detail__impersonate {
  display: flex;
  gap: 12px;
  align-items: end;
}

.detail__impersonate label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 0.875rem;
  color: #374151;
}

.detail__impersonate input {
  padding: 8px 10px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
}

.detail__impersonate button {
  padding: 8px 16px;
  border: none;
  border-radius: 4px;
  background: #1f2937;
  color: #fff;
  cursor: pointer;
}

.detail__package {
  display: flex;
  gap: 12px;
  align-items: end;
  margin-top: 12px;
}

.detail__package label {
  display: flex;
  flex-direction: column;
  gap: 4px;
  font-size: 0.875rem;
  color: #374151;
  flex: 1;
}

.detail__package input {
  padding: 8px 10px;
  border: 1px solid #d1d5db;
  border-radius: 4px;
}

.detail__package button {
  padding: 8px 16px;
  border: none;
  border-radius: 4px;
  background: #1f2937;
  color: #fff;
  cursor: pointer;
}

.detail__package button:disabled,
.detail__package input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.detail__notes {
  margin: 8px 0 0;
  padding-left: 20px;
  color: #4b5563;
}

.detail__notes li {
  margin-bottom: 4px;
}

.detail__error {
  color: #dc2626;
  font-size: 0.875rem;
}

.detail__result {
  margin-top: 12px;
  padding: 12px;
  background: #f3f4f6;
  border-radius: 4px;
  font-size: 0.875rem;
}
</style>
