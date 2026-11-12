<template>
  <div class="config-editor">
    <div class="config-editor__toolbar">
      <el-button type="primary" size="small" :loading="saving" @click="handleSaveAll">儲存</el-button>
      <el-button size="small" @click="loadConfigs">重新整理</el-button>
    </div>

    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" style="margin-bottom: 16px" />
    <el-empty v-if="!loading && items.length === 0" description="此模組尚無設定項" />

    <el-table v-else :data="items" v-loading="loading" border>
      <el-table-column prop="cfg_title" label="說明" width="200" />
      <el-table-column prop="cfg_name" label="代碼" width="200" />
      <el-table-column prop="cfg_type" label="類型" width="120">
        <template #default="{ row }">{{ typeLabel(row.cfg_type) }}</template>
      </el-table-column>
      <el-table-column label="值">
        <template #default="{ row }">
          <!-- 見 17.2：單選下拉（enum 由 cfg_option、dic 由字典）的選項已隨
               GET /api/system-configs 的 options 欄位自動帶出，不用另外
               呼叫字典 API。字典無可用選項時顯示提示。 -->
          <el-select
            v-if="isSelectType(row, false)"
            v-model="row._value"
            style="width: 100%"
            :placeholder="row.options.length ? '請選擇' : '（選項來源不存在或為空）'"
          >
            <el-option v-for="opt in row.options" :key="opt.value" :label="optionLabel(opt)" :value="opt.value" />
          </el-select>
          <el-select
            v-else-if="isSelectType(row, true)"
            v-model="row._value"
            style="width: 100%"
            multiple
            :placeholder="row.options.length ? '請選擇（可多選）' : '（選項來源不存在或為空）'"
          >
            <el-option v-for="opt in row.options" :key="opt.value" :label="optionLabel(opt)" :value="opt.value" />
          </el-select>
          <el-switch v-else-if="row.cfg_type === 'bool'" v-model="row._value" active-value="1" inactive-value="0" />
          <el-input-number
            v-else-if="row.cfg_type === 'int'"
            v-model="row._value"
            style="width: 100%"
            :precision="0"
            :value-on-null="null"
          />
          <el-input-number
            v-else-if="row.cfg_type === 'float'"
            v-model="row._value"
            style="width: 100%"
            :precision="numericOption(row.cfg_option)"
            :value-on-null="null"
          />
          <el-date-picker
            v-else-if="row.cfg_type === 'date' || row.cfg_type === 'datetime'"
            v-model="row._value"
            :type="row.cfg_type === 'datetime' ? 'datetime' : 'date'"
            :format="pickerFormat(row)"
            :value-format="pickerFormat(row)"
            style="width: 100%"
          />
          <el-time-picker
            v-else-if="row.cfg_type === 'time'"
            v-model="row._value"
            :format="pickerFormat(row)"
            :value-format="pickerFormat(row)"
            style="width: 100%"
          />
          <el-color-picker v-else-if="row.cfg_type === 'color'" v-model="row._value" />
          <el-input
            v-else-if="row.cfg_type === 'password'"
            v-model="row._value"
            type="password"
            show-password
            autocomplete="new-password"
            :placeholder="row.has_value ? '（已設定，留白＝不更改）' : ''"
          />
          <el-input
            v-else-if="row.cfg_type === 'textarea' || row.cfg_type === 'json'"
            v-model="row._value"
            type="textarea"
            :rows="row.cfg_style?.rows || 3"
          />
          <!-- image 類型不走這裡的文字輸入/整批儲存，改由上傳/裁切兩支
               專用端點各自即時生效（見 README 17.5），只在這裡顯示縮圖
               預覽、這個欄位的規格摘要（讀 image_spec）跟兩個操作按鈕。 -->
          <div v-else-if="row.cfg_type === 'image'" class="config-editor__image-cell">
            <el-image
              v-if="row.cfg_value"
              :src="row.cfg_value"
              fit="contain"
              class="config-editor__image-preview"
            />
            <div v-else class="config-editor__image-placeholder">尚未上傳</div>
            <div class="config-editor__image-meta">
              <span class="config-editor__image-spec">{{ imageSpecText(row) }}</span>
              <div class="config-editor__image-actions">
                <el-button size="small" :loading="row._uploading" @click="triggerUpload(row)">上傳圖片</el-button>
                <el-button size="small" :disabled="!row.has_value" @click="openCropDialog(row)">編輯圖片</el-button>
              </div>
            </div>
          </div>
          <el-input v-else v-model="row._value" :maxlength="numericOption(row.cfg_option) ?? undefined" />
        </template>
      </el-table-column>
    </el-table>

    <input ref="uploadInputRef" type="file" accept="image/*" style="display: none" @change="onFileSelected" />
    <ImageCropDialog
      v-model="cropDialogVisible"
      :config-id="cropRow?.id"
      :spec="cropRow?.image_spec"
      @cropped="handleCropped"
    />
  </div>
</template>

<script setup>
/* *****************************************
File: SystemConfigEditor.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 系統環境配置的可複用編輯區塊，見 README 17.4。只吃一個
`module`（cfg_module）prop，自己抓資料、自己存檔，不依賴外層怎麼安排
版面——核心系統設定頁（SystemConfigList.vue）用它拼出橫向分頁，插件
設定畫面（見 README 6.10）也直接掛一個實例、換一個 module 就能用同一套
型別渲染／驗證邏輯，不用為插件另外刻一份表單。
***************************************** */

import { onMounted, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { authFetch } from '../stores/auth';
import ImageCropDialog from './ImageCropDialog.vue';

const props = defineProps({
  module: { type: String, required: true },
});

const items = ref([]);
const loading = ref(false);
const loadError = ref('');
const saving = ref(false);

// 類型中文名（顯示欄位）。清單與後端 SystemConfig::TYPE_TITLES／
// system_cfg_type 字典同步；介面固定渲染這 17 種（見 17.1），未知類型
// 一律 fallback 成文字輸入框，不會白屏。
const TYPE_LABELS = {
  string: '單行文字',
  textarea: '多行文字',
  int: '整數',
  float: '小數',
  bool: '布林開關',
  enum: '下拉選項',
  multi: '多選',
  dic: '字典下拉',
  dic_multi: '字典多選',
  date: '日期',
  datetime: '日期時間',
  time: '時間',
  email: '信箱',
  url: '網址',
  color: '色碼',
  password: '密碼／金鑰',
  json: 'JSON 結構',
  image: '圖片上傳',
};

function typeLabel(type) {
  return TYPE_LABELS[type] ?? type;
}

function optionLabel(opt) {
  // enum 的 label 就是值本身，dic 的 label 是字典的中文名稱；兩者相同時
  // 只顯示一次，避免「啟用中（啟用中）」這種重複文字。
  return opt.value === opt.label ? opt.label : `${opt.label}（${opt.value}）`;
}

// cfg_option 在 string/textarea/int 是最大長度、float 是小數位數，
// 沒設或不合法回 null（呼叫端自行決定 fallback）。
function numericOption(option) {
  const num = Number(option);

  return option !== null && option !== '' && Number.isFinite(num) ? num : null;
}

// 見 17.2：enum/multi/dic/dic_multi 的選項都由後端自動帶出，唯一的差別
// 只在單選／多選。multi/dic_multi 的 cfg_value 是 JSON 陣列字串，v-model
// 用陣列，儲存時再轉回 JSON 字串（見 parseMultiValue／serializeValue）。
function isSelectType(row, multiple) {
  const singleTypes = ['enum', 'dic'];
  const multiTypes = ['multi', 'dic_multi'];

  return multiple ? multiTypes.includes(row.cfg_type) : singleTypes.includes(row.cfg_type);
}

function parseMultiValue(rawValue) {
  if (typeof rawValue !== 'string' || rawValue === '') {
    return [];
  }

  try {
    const parsed = JSON.parse(rawValue);

    return Array.isArray(parsed) ? parsed : [];
  } catch (e) {
    return [];
  }
}

// 見 17.1：cfg_option 的格式字串慣例是 PHP 風格 token（YYYY/MM/dd、
// HH:ii:ss），Element Plus 的 format/value-format 走 Day.js token，這裡做
// 對應轉換（dd→DD、ii→mm，其餘常見 token 兩邊寫法相同）；沒設格式時依
// 類型回預設格式。
function pickerFormat(row) {
  const defaults = { date: 'YYYY/MM/DD', datetime: 'YYYY/MM/DD HH:mm:ss', time: 'HH:mm:ss' };

  return (
    String(row.cfg_option ?? '').replace(/ii/g, 'mm').replace(/dd/g, 'DD')
    || defaults[row.cfg_type]
    || 'YYYY/MM/DD HH:mm:ss'
  );
}

async function loadConfigs() {
  loading.value = true;
  loadError.value = '';

  try {
    const res = await authFetch(`/api/system-configs?module=${encodeURIComponent(props.module)}`);
    const body = await res.json();

    if (!body.status) {
      loadError.value = body.error_message || '載入設定失敗';

      return;
    }

    // password 類型後端一律回空字串＋has_value 旗標（見
    // SystemConfigController::present），不把已存的密碼／金鑰明文吐回
    // 前端；has_value 讓輸入框顯示「已設定」提示，使用者留白送出＝不更動
    // 既有值，不是把它清空（見 handleSaveAll 過濾邏輯）。
    items.value = body.data.items.map((item) => ({
      ...item,
      _value: isSelectType(item, true) ? parseMultiValue(item.cfg_value) : (item.cfg_value ?? ''),
    }));
  } finally {
    loading.value = false;
  }
}

function serializeValue(row) {
  return isSelectType(row, true) ? JSON.stringify(row._value ?? []) : String(row._value ?? '');
}

// image 類型的上傳與裁切各自即時生效（見下方 triggerUpload/openCropDialog），
// 不透過這裡的整批儲存，也沒有可以送出的 _value 文字，一律排除。
const uploadInputRef = ref(null);
let uploadTargetRow = null;

function triggerUpload(row) {
  uploadTargetRow = row;
  uploadInputRef.value.value = '';
  uploadInputRef.value.click();
}

async function onFileSelected(event) {
  const file = event.target.files[0];
  const row = uploadTargetRow;

  if (!file || !row) {
    return;
  }

  row._uploading = true;

  try {
    const formData = new FormData();
    formData.append('image', file);

    // 不手動帶 Content-Type：交給瀏覽器自己補上 multipart 的 boundary
    // 參數，手動設定反而會漏掉 boundary 導致後端解析不到檔案。
    const res = await authFetch(`/api/system-configs/${row.id}/image`, {
      method: 'POST',
      body: formData,
    });
    const body = await res.json();

    if (!body.status) {
      ElMessage.error(body.error_message || '上傳失敗');

      return;
    }

    row.cfg_value = body.data.cfg_value;
    row.has_value = true;

    if (body.data.image_spec) {
      row.image_spec = body.data.image_spec;
    }

    ElMessage.success('已上傳並依設定規格自動產生圖片');
  } finally {
    row._uploading = false;
  }
}

const cropDialogVisible = ref(false);
const cropRow = ref(null);

// 見 17.5：image 類型的規格由後端正規化後放在 image_spec，這裡只負責顯示成
// 一行摘要（例如「300×200　PNG　透明背景　填滿輸出」），讓管理員上傳前就知道
// 要準備什麼尺寸／格式的圖；實際縮圖參數一律以後端設定項為準。
function imageSpecText(row) {
  const spec = row.image_spec;

  if (! spec) {
    return '';
  }

  const format = spec.format === 'jpeg' ? 'JPG' : String(spec.format).toUpperCase();
  const background = spec.transparent ? '透明背景' : `背景 ${spec.background}`;
  const mode = spec.mode === 'cover' ? '填滿輸出' : '完整保留＋補背景';

  return `${spec.width}×${spec.height}　${format}　${background}　${mode}`;
}

function openCropDialog(row) {
  cropRow.value = row;
  cropDialogVisible.value = true;
}

function handleCropped(newValue, newSpec) {
  if (cropRow.value) {
    cropRow.value.cfg_value = newValue;
    cropRow.value.has_value = true;

    if (newSpec) {
      cropRow.value.image_spec = newSpec;
    }
  }
}

// 這個模組自己的整批保存，不影響其他分頁／其他插件的設定模組，見上方
// 說明——每個 SystemConfigEditor 實例都是獨立、自給自足的儲存單位。
async function handleSaveAll() {
  saving.value = true;

  try {
    // password 類型留白＝不更改（見 SystemConfigService::updateValue），
    // 直接不送出這幾筆，避免後端收到空字串又要判斷「是不是真的要改」；
    // image 類型完全不透過這裡送出，見上方 triggerUpload/openCropDialog。
    const toSave = items.value
      .filter((row) => row.cfg_type !== 'image')
      .filter((row) => row.cfg_type !== 'password' || row._value !== '');
    const requestItems = toSave.map((row) => ({ id: row.id, value: serializeValue(row) }));

    if (requestItems.length === 0) {
      ElMessage.info('沒有變更需要儲存');

      return;
    }

    const res = await authFetch('/api/system-configs/batch', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ items: requestItems }),
    });
    const body = await res.json();

    if (!body.status) {
      ElMessage.error(body.error_message || '儲存失敗');

      return;
    }

    const byId = new Map(body.data.items.map((item) => [item.id, item]));

    for (const row of items.value) {
      if (byId.has(row.id)) {
        const updated = byId.get(row.id);

        row.cfg_value = updated.cfg_value;
        row.has_value = updated.has_value;
      }
    }

    ElMessage.success('已儲存');
  } finally {
    saving.value = false;
  }
}

watch(() => props.module, loadConfigs);
onMounted(loadConfigs);
</script>

<style scoped>
.config-editor__toolbar {
  margin-bottom: 16px;
  display: flex;
  gap: 8px;
}

.config-editor__image-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.config-editor__image-meta {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.config-editor__image-spec {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}

.config-editor__image-actions {
  display: flex;
  gap: 8px;
}

.config-editor__image-preview {
  width: 72px;
  height: 48px;
  border-radius: 4px;
  border: 1px solid var(--el-border-color);
  flex-shrink: 0;
  background: repeating-conic-gradient(#f8fafc 0% 25%, #e2e8f0 0% 50%) 50% / 12px 12px;
}

.config-editor__image-placeholder {
  width: 48px;
  height: 48px;
  border-radius: 4px;
  border: 1px dashed var(--el-border-color);
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  color: var(--el-text-color-placeholder);
  text-align: center;
}
</style>
