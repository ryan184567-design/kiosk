<template>
  <div class="ups">
    <!-- 左側：資料上傳設定的子選單(設計稿) -->
    <aside class="ups__side">
      <h2 class="ups__side-title">資料上傳設定</h2>
      <button v-for="item in NAV" :key="item.key" type="button" class="ups__nav" :class="{ 'ups__nav--active': activeNav === item.key }" :aria-current="activeNav === item.key ? 'page' : undefined" @click="activeNav = item.key">
        <i :class="item.icon" aria-hidden="true"></i>{{ item.label }}
      </button>
    </aside>
    <div class="ups__main">
      <header class="ups__banner">
        <i class="fa-solid fa-cloud-arrow-up ups__banner-icon" aria-hidden="true"></i>
        <div>
          <h2 class="ups__banner-title">訪客記錄上傳設定</h2>
          <p class="ups__banner-text">設定將訪客記錄資料自動上傳至指定雲端 URL，供異地備份、資料彙整或第三方系統整合使用。</p>
        </div>      </header>
      <section v-if="activeNav === 'logs'" class="ups__card">
        <UploadLogs />
      </section>
      <!-- 用 v-show：切到上傳紀錄再回來時，還沒儲存的內容要保留 -->
      <section v-show="activeNav !== 'logs'" v-loading="loading" class="ups__card">
        <LoadErrorAlert v-if="loadError" class="ups__notice" :message="loadError" @retry="load" />
        <el-form ref="formRef" :model="form" :rules="rules" :disabled="!loaded" label-width="130px" label-position="left" @submit.prevent="handleSave">
          <el-form-item label="啟用資料上傳" class="ups__enable">
            <el-switch v-model="form.is_enabled" />
            <span class="ups__enable-text">{{ form.is_enabled ? '已啟用' : '未啟用' }}</span>
          </el-form-item>
          <el-form-item label="上傳目標 URL" prop="url" :required="form.is_enabled">
            <div class="ups__field">
              <el-input v-model="form.url" maxlength="500" placeholder="https://">
                <template #suffix>
                  <button type="button" class="ups__copy" aria-label="複製網址" title="複製網址" :disabled="!form.url" @click="copyUrl">
                    <i class="fa-regular fa-copy" aria-hidden="true"></i>
                  </button>
                </template>
              </el-input>
              <p class="ups__hint"><i class="fa-solid fa-circle-info ups__icon" aria-hidden="true"></i>請輸入接收訪客記錄的雲端 API 網址（http 或 https）</p>
            </div>
          </el-form-item>
          <el-form-item label="上傳方式">
            <el-radio-group model-value="api">
              <el-radio value="api">API 傳送（建議）</el-radio>
              <el-radio value="file" disabled>檔案上傳（尚未支援）</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="上傳格式">
            <el-select model-value="json" class="ups__format">
              <el-option label="JSON（系統預設）" value="json" />
            </el-select>
          </el-form-item>
          <el-form-item label="上傳內容" prop="contents">
            <el-checkbox-group v-model="form.contents" class="ups__contents">
              <el-checkbox v-for="c in CONTENTS" :key="c.value" :value="c.value" border>{{ c.label }}</el-checkbox>
            </el-checkbox-group>
          </el-form-item>
          <el-form-item label="上傳排程">
            <div class="ups__schedule">
              <el-radio model-value="realtime" value="realtime">即時上傳（每筆資料產生後立即上傳）</el-radio>
              <div class="ups__timed">
                <el-radio model-value="realtime" value="timed" disabled>定時上傳（尚未支援）</el-radio>
                <el-select model-value="每日" disabled class="ups__time" aria-label="週期"><el-option label="每日" value="每日" /></el-select>
                <el-select model-value="23:00" disabled class="ups__time" aria-label="時間"><el-option label="23:00" value="23:00" /></el-select>
              </div>
            </div>
          </el-form-item>
          <el-form-item label="簽名與固定資料">
            <div class="ups__field ups__field--wide">
              <p class="ups__hint">每次上傳都會帶上。放在標頭常用於 API Key；放在 Body 是 POST 內容的固定欄位。儲存後不會再顯示完整的值。</p>
              <div v-for="(item, index) in form.auth_items" :key="item.key" class="ups__auth">
                <el-input v-model="item.name" maxlength="100" placeholder="欄位名稱，例如 X-API-Key" class="ups__auth-name" aria-label="欄位名稱" />
                <el-select v-model="item.location" class="ups__auth-location" aria-label="放的位置">
                  <el-option label="標頭 Header" value="header" />
                  <el-option label="內容 Body" value="body" />
                </el-select>
                <el-input v-model="item.value" type="password" show-password maxlength="500" autocomplete="new-password" class="ups__auth-value" :placeholder="keepsValue(item) ? `已設定 ${item.saved.masked}，留空表示不變` : '請輸入值'" aria-label="值" />
                <el-checkbox v-model="item.bearer" :disabled="item.location !== 'header'">加 Bearer</el-checkbox>
                <el-button link type="danger" aria-label="移除這一組" @click="removeAuth(index)"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></el-button>
              </div>
              <el-button :disabled="!loaded || form.auth_items.length >= AUTH_MAX" @click="addAuth">
                <i class="fa-solid fa-plus ups__icon" aria-hidden="true"></i>新增一組
              </el-button>
              <p v-if="authError" class="ups__error" role="alert">{{ authError }}</p>
            </div>
          </el-form-item>
          <el-form-item label="連線測試">
            <div class="ups__field ups__field--wide">
              <div class="ups__test" :class="testResult ? (testResult.success ? 'ups__test--ok' : 'ups__test--fail') : ''">
                <i v-if="testResult" class="ups__test-icon" :class="testResult.success ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark'" aria-hidden="true"></i>
                <div class="ups__test-text" role="status">
                  <template v-if="testResult">
                    <strong>{{ testResult.success ? '連線測試成功' : '連線測試失敗' }}</strong>
                    <span>狀態碼：{{ testResult.status_code ?? '—' }}｜回應時間：{{ testResult.duration_ms ?? '—' }} ms｜測試時間：{{ testResult.tested_at }}</span>
                    <span v-if="testResult.error">原因：{{ testResult.error }}</span>
                    <span v-if="testResult.response_body" class="ups__test-body" :title="testResult.response_body">對方回應：{{ testResult.response_body }}</span>
                  </template>
                  <span v-else>送一次心跳到上面的網址，確認網址與簽名正確（不需要先啟用）。</span>
                </div>
                <el-button :loading="testing" plain type="primary" @click="handleTest">
                  <i v-if="!testing" class="fa-solid fa-wifi ups__icon" aria-hidden="true"></i>測試連線
                </el-button>
              </div>
              <p v-if="authDirty" class="ups__hint">簽名有尚未儲存的修改：測試用的是「已儲存」的簽名，請先儲存。</p>
            </div>
          </el-form-item>
        </el-form>
      </section>
      <footer v-show="activeNav !== 'logs'" class="ups__footer">
        <span v-if="savedAt" class="ups__saved">最後儲存：{{ savedAt }}</span>
        <el-button :disabled="!dirty || saving" @click="reset">取消</el-button>
        <el-button type="primary" :loading="saving" :disabled="!loaded" @click="handleSave">
          <i v-if="!saving" class="fa-regular fa-floppy-disk ups__icon" aria-hidden="true"></i>儲存設定
        </el-button>
      </footer>
    </div>
  </div>
</template>
<script setup>
/* *****************************************
File: UploadSettings.vue
Author: Callum
Date: 2026-11-26
Description: 訪客管理「上傳設定」：啟用與否、目標網址、上傳內容、簽名與固定資料、連線測試、上傳紀錄(依設計稿)。
***************************************** */
// 後端 GuestDashboardReportController。跟設計稿不同的地方：
// - 設計稿上方的「基本設定、權限角色…」是系統設定的頁籤，這頁在選單裡屬於訪客管理，沒有放。
// - 上傳方式只支援 API、格式只支援 JSON、排程只支援即時，其他選項照設計稿顯示但不能選。
// - 左側子選單只放有自己內容的兩項(設計稿的「上傳排程設定」「連線測試」就是表單裡的欄位)；橫幅右側的插圖不放。
// - 上傳內容的項目以後端的六種事件為準；後端另外支援簽名與固定資料(設計稿沒有)，實際串接需要，所以加上。
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { fetchUploadSettings, saveUploadSettings, testUploadConnection } from '../../api/guest';
import LoadErrorAlert from './components/LoadErrorAlert.vue';
import UploadLogs from './components/UploadLogs.vue';
const NAV = [
  { key: 'settings', label: '訪客記錄上傳', icon: 'fa-solid fa-cloud-arrow-up' },
  { key: 'logs', label: '上傳紀錄', icon: 'fa-solid fa-list-check' },
];
// 後端字典 dashboard_content(值固定，後端只接受這六個)。
const CONTENTS = [
  { value: 'heartbeat', label: '心跳及故障' },
  { value: 'approved', label: '核准' },
  { value: 'modified', label: '修改' },
  { value: 'cancelled', label: '取消' },
  { value: 'checkin', label: '報到' },
  { value: 'checkout', label: '離場' },
];
const AUTH_MAX = 20;
const RESERVED_BODY_KEYS = ['notify_type', 'message_id', 'sent_at']; // 通知本身的欄位，不能當固定資料名稱
const URL_PATTERN = /^https?:\/\/[^\s]+$/i;
const activeNav = ref('settings');
const formRef = ref(null);
const loading = ref(false);
const loaded = ref(false); // 沒讀到目前設定前不能編輯，避免用預設值蓋掉原本的設定
const loadError = ref('');
const saving = ref(false);
const testing = ref(false);
const testResult = ref(null);
const authError = ref('');
const savedAt = ref('');
const form = reactive({ is_enabled: false, url: '', contents: [], auth_items: [] });
let saved = null; // 後端目前的設定(取消時還原用)
const snapshot = ref('');
const authSnapshot = ref('');
let authKey = 0;
const serialize = () => JSON.stringify([form.is_enabled, form.url, [...form.contents].sort(), serializeAuth()]);
const serializeAuth = () => JSON.stringify(form.auth_items.map((a) => [a.name, a.location, a.location === 'header' && a.bearer, a.value]));
const dirty = computed(() => loaded.value && serialize() !== snapshot.value);
const authDirty = computed(() => loaded.value && serializeAuth() !== authSnapshot.value);
const rules = {
  url: [{
    validator: (_r, value, done) => {
      const url = (value || '').trim();
      if(!url) return done(form.is_enabled ? new Error('啟用上傳時必須填寫網址') : undefined);
      return done(URL_PATTERN.test(url) ? undefined : new Error('網址要以 http:// 或 https:// 開頭，且不能有空白'));
    },
    trigger: 'blur',
  }],
  contents: [{ validator: (_r, value, done) => done(value.length ? undefined : new Error('至少勾選一項；全部都不上傳請關閉「啟用資料上傳」')), trigger: 'change' }],
};
// 值留空時能不能沿用原值：名稱(不分大小寫)與位置都沒變的既有項目。
const keepsValue = (item) => Boolean(item.saved) && item.saved.location === item.location && item.saved.name.toLowerCase() === item.name.trim().toLowerCase();
function apply(data){
  saved = data;
  form.is_enabled = Boolean(data.is_enabled);
  form.url = data.url || '';
  form.contents = [...(data.contents || [])];
  form.auth_items = (data.auth_items || []).map((a) => ({
    key: ++authKey, name: a.name, location: a.location, bearer: Boolean(a.bearer), value: '',
    saved: { name: a.name, location: a.location, masked: a.value_masked },
  }));
  savedAt.value = data.updated_at || '';
  authError.value = '';
  snapshot.value = serialize();
  authSnapshot.value = serializeAuth();
  nextTick(() => formRef.value?.clearValidate());
}
async function load(){
  loading.value = true;
  loadError.value = '';
  const result = await fetchUploadSettings();
  loading.value = false;
  if(!result.ok){
    loadError.value = `上傳設定載入失敗：${result.message}`;
    return;
  }
  loaded.value = true;
  apply(result.data);
}
function reset(){
  if(saved) apply(saved);
}
function addAuth(){
  form.auth_items.push({ key: ++authKey, name: '', location: 'header', bearer: false, value: '', saved: null });
}
function removeAuth(index){
  form.auth_items.splice(index, 1);
  authError.value = '';
}
// 簽名清單的檢查(與後端 mergeAuthItems 的規則一致)，回傳錯誤訊息；沒問題回傳空字串。
function checkAuth(){
  const seen = new Set();
  for(const [i, item] of form.auth_items.entries()){
    const name = item.name.trim();
    const row = `第 ${i + 1} 組`;
    if(!name) return `${row}：請輸入欄位名稱`;
    if(!/^[A-Za-z0-9_-]+$/.test(name)) return `${row}：欄位名稱只能用英文、數字、底線、連字號`;
    const key = `${item.location}|${name.toLowerCase()}`;
    if(seen.has(key)) return `${row}：同一個位置的欄位名稱不能重複`;
    seen.add(key);
    if(item.location === 'body' && RESERVED_BODY_KEYS.includes(name)) return `${row}：${name} 是通知本身的欄位，請換一個名稱`;
    if(!item.value && !keepsValue(item)) return `${row}：請輸入值（新增，或改過名稱、位置的項目要重新輸入）`;
  }
  return '';
}
function payload(){
  return{
    is_enabled: form.is_enabled,
    url: form.url.trim() || null,
    upload_method: 'api',
    format: 'json',
    schedule: 'realtime',
    contents: CONTENTS.map((c) => c.value).filter((v) => form.contents.includes(v)),
    // 值留空的不帶 value，後端沿用原本的。
    auth_items: form.auth_items.map((a) => ({ name: a.name.trim(), location: a.location, bearer: a.location === 'header' && a.bearer, ...(a.value ? { value: a.value } : {}) })),
  };
}
async function handleSave(){
  if(saving.value || !loaded.value) return;
  const valid = await formRef.value.validate().catch(() => false);
  authError.value = checkAuth();
  if(!valid || authError.value) return;
  saving.value = true;
  const result = await saveUploadSettings(payload());
  saving.value = false;
  if(!result.ok){
    ElMessage.error(result.message);
    return;
  }
  apply(result.data);
  ElMessage.success('已儲存上傳設定');
}
async function handleTest(){
  const url = form.url.trim();
  if(!URL_PATTERN.test(url)){
    ElMessage.warning('請先填寫正確的上傳目標 URL');
    return;
  }
  testing.value = true;
  testResult.value = null;
  const result = await testUploadConnection(url);
  testing.value = false;
  if(!result.ok){
    ElMessage.error(`無法執行連線測試：${result.message}`);
    return;
  }
  testResult.value = result.data;
}
async function copyUrl(){
  try{
    await navigator.clipboard.writeText(form.url);
    ElMessage.success('已複製網址');
  }catch{
    ElMessage.warning('瀏覽器不允許複製，請手動選取網址複製');
  }
}
onBeforeRouteLeave(async () => {
  if(!dirty.value) return true;
  return ElMessageBox.confirm('上傳設定有尚未儲存的修改，確定要離開嗎？', '尚未儲存', { confirmButtonText: '離開', cancelButtonText: '留在這頁', type: 'warning' })
    .then(() => true, () => false);
});
onMounted(load);
</script>
<style scoped>
.ups{
  display: grid;
  grid-template-columns: 220px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}
.ups__side,
.ups__card,
.ups__footer{
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.ups__side{
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 14px 12px;
}
.ups__side-title{
  margin: 0 8px 8px;
  font-size: 16px;
  color: #111827;
}
.ups__nav{
  display: flex;
  gap: 12px;
  align-items: center;
  padding: 12px;
  font: inherit;
  font-size: 14px;
  color: #1f2937;
  text-align: left;
  cursor: pointer;
  background: transparent;
  border: 0;
  border-radius: 6px;
  transition: background-color var(--app-transition-duration) var(--app-transition-timing);
}
.ups__nav i{
  width: 18px;
  text-align: center;
}
.ups__nav:hover{
  background: #f3f6fb;
}
.ups__nav--active,
.ups__nav--active:hover{
  font-weight: 700;
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}
.ups__main{
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
}
.ups__banner{
  display: flex;
  gap: 18px;
  align-items: center;
  padding: 22px 28px;
  background: linear-gradient(90deg, var(--el-color-primary-light-9), var(--el-color-primary-light-8));
  border-radius: 8px;
}
.ups__banner-icon{
  font-size: 44px;
  color: var(--el-color-primary);
}
.ups__banner-title{
  margin: 0 0 4px;
  font-size: 18px;
  color: #111827;
}
.ups__banner-text{
  margin: 0;
  font-size: 13px;
  color: #4b5563;
}
.ups__card{
  padding: 20px 24px;
}
.ups__notice{
  margin-bottom: 14px;
}
.ups__icon{
  margin-right: 6px;
}
.ups__enable{
  padding-bottom: 16px;
  border-bottom: 1px solid #eef0f4;
}
.ups__enable-text{
  margin-left: 12px;
}
.ups__field{
  width: 100%;
  max-width: 720px;
}
.ups__field--wide{
  max-width: 900px;
}
.ups__hint{
  margin: 4px 0;
  font-size: 13px;
  line-height: 1.6;
  color: #6b7280;
}
.ups__error{
  margin: 6px 0 0;
  font-size: 13px;
  line-height: 1.5;
  color: var(--el-color-danger);
}
.ups__copy{
  padding: 0 2px;
  color: #6b7280;
  cursor: pointer;
  background: transparent;
  border: 0;
}
.ups__format{
  width: 260px;
}
.ups__contents{
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.ups__contents :deep(.el-checkbox){
  margin-right: 0;
}
.ups__schedule{
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}
.ups__timed{
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}
.ups__time{
  width: 110px;
}
.ups__auth{
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin: 8px 0;
}
.ups__auth-name{
  width: 220px;
}
.ups__auth-location{
  width: 140px;
}
.ups__auth-value{
  flex: 1;
  min-width: 200px;
}
.ups__test{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  padding: 12px 16px;
  background: #f5f7fb;
  border-radius: 8px;
}
.ups__test--ok{
  background: #ecfdf3;
}
.ups__test--fail{
  background: #fef3f2;
}
.ups__test-icon{
  font-size: 24px;
}
.ups__test--ok .ups__test-icon,
.ups__test--ok strong{
  color: #15803d;
}
.ups__test--fail .ups__test-icon,
.ups__test--fail strong{
  color: #b42318;
}
.ups__test-text{
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 220px;
  font-size: 13px;
  line-height: 1.7;
  color: #4b5563;
}
.ups__test-body{
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ups__footer{
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  justify-content: flex-end;
  padding: 12px 20px;
}
.ups__saved{
  margin-right: auto;
  font-size: 13px;
  color: #6b7280;
}
@media(max-width: 1100px){
  .ups{
    grid-template-columns: minmax(0, 1fr);
  }
  .ups__side{
    flex-flow: row wrap;
  }
  .ups__side-title{
    width: 100%;
  }
}
</style>
