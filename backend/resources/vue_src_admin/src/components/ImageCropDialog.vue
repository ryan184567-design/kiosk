<script setup>
/* *****************************************
File: ImageCropDialog.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: system_configs image 類型的「編輯圖片」裁切對話框，見 README 17.5、10.3。
功能參考 D:\www\nckuh_ems 的 ImageCropperDialog（該專案已上線驗證過），改寫成本專案的
Element Plus 版本：
  - 縮放：以倍率縮放原圖顯示尺寸，框選與輸出預覽都跟著即時更新。
  - 輸出預覽：在 canvas 上畫出「這次裁切會產生什麼」，所見即所得，避免傳了才發現
    效果不對（例如把 logo 裁掉一角、或底色不是預期的透明）。
  - 輸出模式：填滿輸出（cover，框選比例鎖定成輸出比例）／完整保留 + 補背景
    （contain，框選可超出原圖，超出處補純色或透明）。
  - 擴圖：contain 模式下可以把框選範圍拉出原圖邊界，超出的部分用背景色／透明補齊
    （後端 PhotoClass::expandCrop 實作，見 17.5）。
  - 輸出格式與尺寸：預設依設定項規格（cfg_option／image_spec），也可只針對這次
    輸出調整（例如這次要一張白底 JPG）。
  - 框選比例記憶：同一張來源圖下次開啟時沿用上次框選的相對位置與大小。
純手刻拖曳（不引入第三方裁切套件，維持本專案一貫慣例）。
***************************************** */

import { computed, reactive, ref, watch } from 'vue';
import { ElMessage } from 'element-plus';
import { authFetch } from '../stores/auth';

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  configId: { type: [Number, String], default: null },
  // 設定項目前的圖片規格（GET /api/system-configs 的 image_spec，見 README 17.5）。
  // 對話框的預設值全部從這裡來，不在前端另外兜一套預設值，避免兩邊不一致。
  spec: {
    type: Object,
    default: () => ({ width: 300, height: 200, mode: 'contain', format: 'png', transparent: true, background: null, max_kb: 5120 }),
  },
});

const emit = defineEmits(['update:modelValue', 'cropped']);

const visible = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val),
});

const FORMAT_OPTIONS = [
  { value: 'png', label: 'PNG（支援透明）' },
  { value: 'jpeg', label: 'JPG（不支援透明，自動白底）' },
  { value: 'webp', label: 'WEBP（支援透明）' },
  { value: 'gif', label: 'GIF（不支援透明）' },
];

// 支援透明背景的格式（與後端 SystemConfig::TRANSPARENT_FORMATS 同步）。
const TRANSPARENT_FORMATS = ['png', 'webp'];

const loading = ref(false);
const saving = ref(false);
const imageUrl = ref('');
const previewDataUrl = ref('');
const previewError = ref('');
const stageRef = ref(null);
const imgRef = ref(null);

// 縮放（只影響畫面顯示比例，不影響輸出；放大後框選可以更精準）。
const zoom = ref(1);
const minZoom = 0.3;
const maxZoom = 3;

// natural* 是原圖實際像素，display* 是目前畫面上的顯示尺寸；兩者比例相同，
// 換算時用同一個 scale（見 handleConfirm）。
const naturalWidth = ref(0);
const naturalHeight = ref(0);
const displayWidth = ref(0);
const displayHeight = ref(0);
// 原圖在 stage 裡的偏移量；contain 模式會留一圈擴圖空間（框選可超出原圖）。
const imageOffsetX = ref(0);
const imageOffsetY = ref(0);
const stageWidth = ref(0);
const stageHeight = ref(0);

// 這次裁切的輸出設定，初始值來自設定項規格，可在右側面板調整。
const previewMode = ref('contain');
const backgroundMode = ref('transparent');
const backgroundColor = ref('#ffffff');
const format = ref('png');
const outputWidthInput = ref(300);
const outputHeightInput = ref(200);

// 框選範圍（畫面座標，含 imageOffset 位移；contain 模式可超出原圖＝擴圖）。
const selection = reactive({ x: 0, y: 0, width: 0, height: 0 });

let dragMode = null;
let dragAnchor = { x: 0, y: 0 };
let dragStart = { x: 0, y: 0, width: 0, height: 0 };
let lastSelectionRatio = null;

function clamp(value, min, max) {
  return Math.min(max, Math.max(min, value));
}

function specWidth() {
  return Number(props.spec?.width) || 300;
}

function specHeight() {
  return Number(props.spec?.height) || 200;
}

function formatLabel(value) {
  return FORMAT_OPTIONS.find((item) => item.value === value)?.label ?? String(value ?? '');
}

const formatOptions = computed(() => FORMAT_OPTIONS);
const formatSupportsTransparency = computed(() => TRANSPARENT_FORMATS.includes(format.value));
const allowOverflow = computed(() => previewMode.value === 'contain');
const outputWidth = computed(() => Math.max(1, Number(outputWidthInput.value) || specWidth()));
const outputHeight = computed(() => Math.max(1, Number(outputHeightInput.value) || specHeight()));
// 框選比例**一律鎖定成輸出比例**（見 README 17.5，與參考站 nckuh_ems 一致）：
// 兩種模式都鎖，差別只在「完整保留＋補背景」允許把框拉出原圖範圍（＝擴圖補
// 背景）、「填滿輸出」限制在原圖內。先前版本在 contain 模式放開比例，會讓
// 使用者圈出與輸出比例不同的框（事後被等比縮放＋補底），與參考站行為不一致，
// 也讓人誤以為「換了模式框的比例就跑掉」。
const aspectRatio = computed(() => outputWidth.value / outputHeight.value);

const imageStyle = computed(() => ({
  position: 'absolute',
  left: `${imageOffsetX.value}px`,
  top: `${imageOffsetY.value}px`,
  width: `${displayWidth.value}px`,
  height: `${displayHeight.value}px`,
}));

const selectionStyle = computed(() => ({
  left: `${selection.x}px`,
  top: `${selection.y}px`,
  width: `${selection.width}px`,
  height: `${selection.height}px`,
}));

const previewPlaceholder = computed(() => previewError.value || '請先框選區域');

// 框選是否超出原圖（用來提示使用者這次會擴圖補背景）。
const padded = computed(
  () => selection.width > 0
    && (selection.x < imageOffsetX.value - 1
      || selection.y < imageOffsetY.value - 1
      || selection.x + selection.width > imageOffsetX.value + displayWidth.value + 1
      || selection.y + selection.height > imageOffsetY.value + displayHeight.value + 1),
);
// 依設定項規格套用預設值（每次開啟對話框時重設一次，避免沿用上一次的
// 臨時調整；框選比例記憶是唯一刻意保留的狀態，見 rememberSelectionRatio）。
function applySpecDefaults() {
  previewMode.value = props.spec?.mode === 'cover' ? 'cover' : 'contain';
  format.value = props.spec?.format ?? 'png';
  outputWidthInput.value = specWidth();
  outputHeightInput.value = specHeight();
  backgroundMode.value = props.spec?.transparent === false && props.spec?.background ? 'color' : 'transparent';
  backgroundColor.value = props.spec?.background ?? '#ffffff';
  syncBackgroundModeWithFormat();
}

// 切到不支援透明的格式（JPG／GIF）時自動改成純色背景，否則使用者會以為
// 輸出還是透明的（後端在這些格式一律填白底，見 17.5）。
function syncBackgroundModeWithFormat() {
  if (! formatSupportsTransparency.value && backgroundMode.value === 'transparent') {
    backgroundMode.value = 'color';
  }
}

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      applySpecDefaults();
      loadOriginal();
    }
  },
);

watch(format, () => {
  syncBackgroundModeWithFormat();
  renderPreview();
});

watch(zoom, () => {
  syncDisplaySize();
  renderPreview();
});

watch(allowOverflow, () => {
  // 切換「填滿輸出／完整保留＋補背景」時，顯示區會多／少一圈擴圖空間，
  // 這裡讓框選保持原樣（只重新夾擠到合法範圍），不要因為模式切換就跳成
  // 預設大小的框——那會讓人以為「換了模式框就跑掉」。
  syncDisplaySize();
  clampSelection();
  renderPreview();
});

watch([outputWidth, outputHeight], () => {
  // 輸出尺寸改了，框選比例跟著換（一律鎖定，見 aspectRatio 說明）。
  if (selection.width > 0) {
    selection.height = selection.width / aspectRatio.value;
    clampSelection();
  }

  renderPreview();
});

watch([backgroundMode, backgroundColor, previewMode], () => renderPreview());

async function loadOriginal() {
  loading.value = true;
  clearSelection();

  try {
    const res = await authFetch(`/api/system-configs/${props.configId}/image/original`);

    if (! res.ok) {
      ElMessage.error('尚未上傳過原圖，無法編輯裁切');
      visible.value = false;

      return;
    }

    const blob = await res.blob();
    imageUrl.value = URL.createObjectURL(blob);
  } finally {
    loading.value = false;
  }
}

function onImageLoad() {
  naturalWidth.value = imgRef.value?.naturalWidth || 0;
  naturalHeight.value = imgRef.value?.naturalHeight || 0;
  syncDisplaySize();
  restoreSelectionOrDefault();
  renderPreview();
}

// 依容器大小算出「原圖 fit 進畫面」的顯示尺寸；contain 模式額外留一圈擴圖
// 空間（框選可以拉出原圖之外），所以 stage 比圖片大一圈。
function syncDisplaySize() {
  if (! naturalWidth.value || ! naturalHeight.value) {
    return;
  }

  const shell = stageRef.value?.parentElement;
  const maxWidth = Math.max(260, (shell?.clientWidth || 700) - 20);
  const maxHeight = Math.max(240, window.innerHeight * 0.58);
  const fitScale = Math.min(maxWidth / naturalWidth.value, maxHeight / naturalHeight.value);
  const scale = Math.max(0.05, fitScale) * zoom.value;

  displayWidth.value = Math.max(1, Math.round(naturalWidth.value * scale));
  displayHeight.value = Math.max(1, Math.round(naturalHeight.value * scale));

  if (allowOverflow.value) {
    imageOffsetX.value = Math.max(60, Math.round(displayWidth.value * 0.4));
    imageOffsetY.value = Math.max(60, Math.round(displayHeight.value * 0.4));
  } else {
    imageOffsetX.value = 0;
    imageOffsetY.value = 0;
  }

  stageWidth.value = displayWidth.value + imageOffsetX.value * 2;
  stageHeight.value = displayHeight.value + imageOffsetY.value * 2;

  if (selection.width > 0) {
    clampSelection();
  }
}

function changeZoom(delta) {
  zoom.value = Math.min(maxZoom, Math.max(minZoom, +(zoom.value + delta).toFixed(2)));
}

function initCenteredSelection() {
  if (! displayWidth.value || ! displayHeight.value) {
    return;
  }

  const ratio = aspectRatio.value;
  let width = displayWidth.value * 0.7;
  let height = width / ratio;

  if (height > displayHeight.value * 0.9) {
    height = displayHeight.value * 0.9;
    width = height * ratio;
  }

  selection.x = imageOffsetX.value + (displayWidth.value - width) / 2;
  selection.y = imageOffsetY.value + (displayHeight.value - height) / 2;
  selection.width = width;
  selection.height = height;
}

// 見檔頭說明：記住上次的框選「相對於原圖」的位置與大小，同一張來源圖下次
// 開啟時直接沿用，減少重複調整。
function restoreSelectionOrDefault() {
  if (lastSelectionRatio) {
    selection.x = imageOffsetX.value + displayWidth.value * lastSelectionRatio.xRatio;
    selection.y = imageOffsetY.value + displayHeight.value * lastSelectionRatio.yRatio;
    selection.width = displayWidth.value * lastSelectionRatio.widthRatio;
    selection.height = displayHeight.value * lastSelectionRatio.heightRatio;
    clampSelection();

    return;
  }

  initCenteredSelection();
}

function rememberSelectionRatio() {
  if (! selection.width || ! displayWidth.value || ! displayHeight.value) {
    return;
  }

  lastSelectionRatio = {
    xRatio: clamp((selection.x - imageOffsetX.value) / displayWidth.value, 0, 1),
    yRatio: clamp((selection.y - imageOffsetY.value) / displayHeight.value, 0, 1),
    widthRatio: clamp(selection.width / displayWidth.value, 0.05, 3),
    heightRatio: clamp(selection.height / displayHeight.value, 0.05, 3),
  };
}

function resetSelection() {
  lastSelectionRatio = null;
  initCenteredSelection();
  renderPreview();
}

function clearSelection() {
  selection.width = 0;
  selection.height = 0;
}

function resetOutputSize() {
  outputWidthInput.value = specWidth();
  outputHeightInput.value = specHeight();
}
// 框選邊界：cover 模式限制在原圖內（輸出就是原圖的一部分）；contain 模式
// 允許整個 stage（框選可超出原圖＝擴圖）。
function selectionBounds() {
  if (allowOverflow.value) {
    return { width: stageWidth.value, height: stageHeight.value };
  }

  return { width: displayWidth.value, height: displayHeight.value };
}

function clampSelection() {
  const bounds = selectionBounds();

  selection.width = Math.max(10, Math.min(selection.width, bounds.width));
  selection.height = Math.max(10, Math.min(selection.height, bounds.height));

  // 比例一律鎖定（見 aspectRatio 的說明），框高一律由框寬推得，不會出現
  // 與輸出比例不同的框。
  selection.height = selection.width / aspectRatio.value;

  if (selection.y + selection.height > bounds.height) {
    selection.height = bounds.height - selection.y;
    selection.width = selection.height * aspectRatio.value;
  }

  selection.x = clamp(selection.x, 0, Math.max(0, bounds.width - selection.width));
  selection.y = clamp(selection.y, 0, Math.max(0, bounds.height - selection.height));
}

function localPoint(event) {
  const rect = stageRef.value?.getBoundingClientRect();

  if (! rect) {
    return null;
  }

  const bounds = selectionBounds();

  return {
    x: clamp(event.clientX - rect.left, 0, bounds.width),
    y: clamp(event.clientY - rect.top, 0, bounds.height),
  };
}

function onStageMouseDown(event) {
  const point = localPoint(event);

  if (! point) {
    return;
  }

  dragMode = 'create';
  dragAnchor = point;
  selection.x = point.x;
  selection.y = point.y;
  selection.width = 1;
  selection.height = 1;
  bindDragListeners();
}

function onSelectionMouseDown(event) {
  const point = localPoint(event);

  if (! point) {
    return;
  }

  dragMode = 'move';
  dragAnchor = point;
  dragStart = { ...selection };
  bindDragListeners();
}

function onResizeHandleMouseDown() {
  dragMode = 'resize';
  dragStart = { ...selection };
  bindDragListeners();
}

function bindDragListeners() {
  window.addEventListener('mousemove', onDragMove);
  window.addEventListener('mouseup', onDragEnd);
}

function onDragMove(event) {
  const point = localPoint(event);

  if (! point || ! dragMode) {
    return;
  }

  const bounds = selectionBounds();

  if (dragMode === 'create') {
    const dx = point.x - dragAnchor.x;
    const dy = point.y - dragAnchor.y;

    if (Math.abs(dx) < 2 && Math.abs(dy) < 2) {
      return;
    }

    let width = Math.abs(dx);
    let height = Math.abs(dy);

    // 比例鎖定：以框寬為準推框高；若框高超過實際拖曳距離，改以框高為準反推
    // 框寬（＝往拖曳方向的另一個軸對齊），避免比例跑掉。
    height = width / aspectRatio.value;

    if (height > Math.abs(dy)) {
      height = Math.abs(dy);
      width = height * aspectRatio.value;
    }

    selection.x = dx < 0 ? dragAnchor.x - width : dragAnchor.x;
    selection.y = dy < 0 ? dragAnchor.y - height : dragAnchor.y;
    selection.width = width;
    selection.height = height;
    clampSelection();
  } else if (dragMode === 'move') {
    selection.x = clamp(dragStart.x + (point.x - dragAnchor.x), 0, Math.max(0, bounds.width - selection.width));
    selection.y = clamp(dragStart.y + (point.y - dragAnchor.y), 0, Math.max(0, bounds.height - selection.height));
  } else if (dragMode === 'resize') {
    let width = clamp(point.x - dragStart.x, 20, Math.max(20, bounds.width - dragStart.x));
    let height = width / aspectRatio.value;

    if (dragStart.y + height > bounds.height) {
      height = Math.max(20, bounds.height - dragStart.y);
      width = height * aspectRatio.value;
    }

    selection.x = dragStart.x;
    selection.y = dragStart.y;
    selection.width = width;
    selection.height = height;
  }

  renderPreview();
}

function onDragEnd() {
  dragMode = null;
  rememberSelectionRatio();
  window.removeEventListener('mousemove', onDragMove);
  window.removeEventListener('mouseup', onDragEnd);
}
// 即時輸出預覽：在 canvas 上依「這次的參數」畫出結果，跟後端裁切邏輯一致
// （先取框選範圍、超出原圖處補背景，再依模式縮放）。
// 這是避免「傳了才發現效果不對」的主要防線（見檔頭說明）。
function renderPreview() {
  const image = imgRef.value;

  if (! selection.width || ! displayWidth.value || ! image) {
    previewDataUrl.value = '';
    previewError.value = '';

    return;
  }

  try {
    const scaleX = naturalWidth.value / displayWidth.value;
    const scaleY = naturalHeight.value / displayHeight.value;
    const selX = selection.x - imageOffsetX.value;
    const selY = selection.y - imageOffsetY.value;
    const canvas = document.createElement('canvas');

    canvas.width = outputWidth.value;
    canvas.height = outputHeight.value;

    const ctx = canvas.getContext('2d');
    // 透明只可能在 contain + 支援透明的格式下成立；其餘情況（含 JPG／GIF）
    // 一律先鋪底色，跟後端 encode() 的白底處理一致（見 17.5）。
    const transparent = previewMode.value === 'contain' && backgroundMode.value === 'transparent';

    if (! transparent) {
      ctx.fillStyle = previewMode.value === 'contain' ? backgroundColor.value : '#ffffff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
    }

    // 框選範圍換算回原圖像素；只有與原圖的交集需要畫圖片本體，其餘是補背景。
    const sx = Math.max(0, selX) * scaleX;
    const sy = Math.max(0, selY) * scaleY;
    const ex = Math.min(displayWidth.value, selX + selection.width) * scaleX;
    const ey = Math.min(displayHeight.value, selY + selection.height) * scaleY;

    if (ex > sx && ey > sy) {
      if (previewMode.value === 'cover') {
        ctx.drawImage(image, sx, sy, ex - sx, ey - sy, 0, 0, canvas.width, canvas.height);
      } else {
        // 交集在框選範圍中的相對位置，再換算成輸出畫布座標（其餘留白＝背景）。
        const targetX = (((sx / scaleX) - selX) / selection.width) * canvas.width;
        const targetY = (((sy / scaleY) - selY) / selection.height) * canvas.height;
        const targetW = (((ex - sx) / scaleX) / selection.width) * canvas.width;
        const targetH = (((ey - sy) / scaleY) / selection.height) * canvas.height;

        ctx.drawImage(image, sx, sy, ex - sx, ey - sy, targetX, targetY, targetW, targetH);
      }
    }

    previewDataUrl.value = canvas.toDataURL(transparent ? 'image/png' : 'image/jpeg', 0.92);
    previewError.value = '';
  } catch (error) {
    previewDataUrl.value = '';
    previewError.value = error?.name === 'SecurityError'
      ? '此圖片來源限制，無法產生即時預覽（仍可正常裁切）'
      : '預覽產生失敗，請稍後再試';
  }
}

async function handleConfirm() {
  if (! selection.width || ! naturalWidth.value) {
    return;
  }

  rememberSelectionRatio();

  const scaleX = naturalWidth.value / displayWidth.value;
  const scaleY = naturalHeight.value / displayHeight.value;

  // 送給後端的是原圖像素座標，允許負數與超出原圖（＝擴圖，見 17.5）。
  const payload = {
    x: Math.round((selection.x - imageOffsetX.value) * scaleX),
    y: Math.round((selection.y - imageOffsetY.value) * scaleY),
    width: Math.max(1, Math.round(selection.width * scaleX)),
    height: Math.max(1, Math.round(selection.height * scaleY)),
    output_width: outputWidth.value,
    output_height: outputHeight.value,
    mode: previewMode.value,
    format: format.value,
    background: previewMode.value === 'contain' && backgroundMode.value === 'color' ? backgroundColor.value : 'transparent',
  };

  saving.value = true;

  try {
    const res = await authFetch(`/api/system-configs/${props.configId}/image/crop`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    });
    const body = await res.json();

    if (! body.status) {
      ElMessage.error(body.error_message || '裁切失敗');

      return;
    }

    ElMessage.success('已套用裁切');
    emit('cropped', body.data.cfg_value, body.data.image_spec);
    visible.value = false;
  } finally {
    saving.value = false;
  }
}

function handleClosed() {
  if (imageUrl.value) {
    URL.revokeObjectURL(imageUrl.value);
  }

  imageUrl.value = '';
  previewDataUrl.value = '';
  clearSelection();
  zoom.value = 1;
}
</script>

<template>
  <!-- 版面：左＝原圖與框選（含擴圖空間）／右＝輸出預覽與這次裁切參數 -->
  <el-dialog v-model="visible" title="編輯圖片" width="min(96vw, 980px)" :close-on-click-modal="false" @closed="handleClosed">
    <div v-loading="loading" class="cropper">
      <div class="cropper__info">
        <span>輸出尺寸：{{ outputWidth }} × {{ outputHeight }}</span>
        <span>框選比例：鎖定 {{ outputWidth }} : {{ outputHeight }}</span>
        <span v-if="selection.width">框選：{{ Math.round(selection.width) }} × {{ Math.round(selection.height) }}（畫面）</span>
        <span>縮放：{{ Math.round(zoom * 100) }}%</span>
        <span v-if="padded" class="cropper__info-tag">已擴圖：框選超出原圖，超出部分會補背景</span>
      </div>

      <div class="cropper__grid">
        <div class="cropper__left">
          <div class="cropper__tools">
            <el-button size="small" :disabled="zoom <= minZoom" @click="changeZoom(-0.1)">－</el-button>
            <el-slider v-model="zoom" class="cropper__zoom" :min="minZoom" :max="maxZoom" :step="0.05" />
            <el-button size="small" :disabled="zoom >= maxZoom" @click="changeZoom(0.1)">＋</el-button>
            <el-button size="small" :disabled="Math.abs(zoom - 1) < 0.01" @click="zoom = 1">100%</el-button>
          </div>

          <div class="cropper__stage-shell">
            <div
              ref="stageRef"
              class="cropper__stage"
              :style="{ width: `${stageWidth}px`, height: `${stageHeight}px` }"
              @mousedown="onStageMouseDown"
            >
              <!-- 虛線框＝原圖範圍；框選可以拉到這裡之外（擴圖補背景），
                   cover 模式則限制在原圖內，見 selectionBounds()。 -->
              <div v-if="allowOverflow" class="cropper__safe-area" :style="imageStyle"></div>

              <img
                ref="imgRef"
                :src="imageUrl"
                alt="待裁切圖片"
                class="cropper__image"
                crossorigin="anonymous"
                :style="imageStyle"
                @load="onImageLoad"
              />

              <div
                v-if="selection.width > 0"
                class="cropper__selection"
                :style="selectionStyle"
                @mousedown.stop="onSelectionMouseDown"
              >
                <span class="cropper__handle" @mousedown.stop="onResizeHandleMouseDown"></span>
              </div>
            </div>
          </div>
        </div>
        <!-- 右側：輸出預覽與這次裁切的參數（預設值來自設定項規格）。 -->
        <div class="cropper__right">
          <h4>輸出預覽</h4>
          <div class="cropper__preview-frame">
            <img v-if="previewDataUrl" :src="previewDataUrl" alt="輸出預覽" class="cropper__preview-image" />
            <div v-else class="cropper__preview-placeholder">{{ previewPlaceholder }}</div>
          </div>

          <div class="cropper__field">
            輸出模式
            <el-select v-model="previewMode" style="width: 100%">
              <el-option value="cover" label="填滿輸出（可能裁切）" />
              <el-option value="contain" label="完整保留 + 補背景" />
            </el-select>
          </div>

          <template v-if="previewMode === 'contain'">
            <div class="cropper__field">
              補背景類型
              <el-select v-model="backgroundMode" style="width: 100%">
                <el-option value="color" label="純色背景" />
                <el-option value="transparent" label="透明背景" :disabled="!formatSupportsTransparency" />
              </el-select>
            </div>
            <div v-if="backgroundMode === 'color'" class="cropper__field">
              背景顏色
              <input v-model="backgroundColor" type="color" class="cropper__color" />
            </div>
          </template>

          <div class="cropper__field">
            輸出格式
            <el-select v-model="format" style="width: 100%">
              <el-option v-for="item in formatOptions" :key="item.value" :value="item.value" :label="item.label" />
            </el-select>
          </div>

          <div class="cropper__field">
            輸出尺寸（一般不需修改）
            <div class="cropper__size-row">
              <el-input-number v-model="outputWidthInput" :min="1" :max="5000" :controls="false" />
              <span>×</span>
              <el-input-number v-model="outputHeightInput" :min="1" :max="5000" :controls="false" />
              <el-button size="small" @click="resetOutputSize">還原</el-button>
            </div>
          </div>

          <p class="cropper__hint">
            設定項規格：{{ specWidth() }}×{{ specHeight() }} {{ formatLabel(spec.format) }}（{{ spec.transparent ? '透明背景' : `背景 ${spec.background}` }}）。
            <strong>框選比例固定為輸出比例（{{ outputWidth }} : {{ outputHeight }}）</strong>，兩種模式下都一樣、不會變形；
            差別只在「完整保留 + 補背景」可以把框選拉出原圖邊界（擴圖），超出處用上面選的背景填滿，「填滿輸出」則限制在原圖內。
          </p>
        </div>
      </div>
    </div>

    <template #footer>
      <el-button @click="resetSelection">重設框選</el-button>
      <el-button @click="visible = false">取消</el-button>
      <el-button type="primary" :loading="saving" :disabled="!selection.width" @click="handleConfirm">確認裁切</el-button>
    </template>
  </el-dialog>
</template>

<style scoped>
.cropper {
  min-height: 320px;
}

.cropper__info {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  font-size: 13px;
  color: var(--el-text-color-secondary);
  margin-bottom: 10px;
}

.cropper__info-tag {
  color: var(--el-color-primary);
}

.cropper__grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: 14px;
}

.cropper__tools {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.cropper__zoom {
  flex: 1;
}

.cropper__stage-shell {
  border: 1px solid var(--el-border-color);
  border-radius: 12px;
  background: repeating-conic-gradient(#f8fafc 0% 25%, #e2e8f0 0% 50%) 50% / 20px 20px;
  min-height: 300px;
  max-height: 56vh;
  overflow: auto;
  display: flex;
  justify-content: center;
  align-items: center;
}

.cropper__stage {
  position: relative;
  user-select: none;
  cursor: crosshair;
  flex-shrink: 0;
}

.cropper__image {
  display: block;
  z-index: 1;
}

.cropper__safe-area {
  border: 1px dashed rgba(22, 119, 255, 0.55);
  box-sizing: border-box;
  pointer-events: none;
  z-index: 0;
}

.cropper__selection {
  position: absolute;
  border: 2px solid var(--el-color-primary);
  box-shadow: 0 0 0 9999px rgba(15, 23, 42, 0.35);
  cursor: move;
  z-index: 3;
}

.cropper__handle {
  position: absolute;
  right: -7px;
  bottom: -7px;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: var(--el-color-primary);
  border: 2px solid #fff;
  cursor: nwse-resize;
}

.cropper__right {
  border: 1px solid var(--el-border-color);
  border-radius: 12px;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-height: 0;
  overflow: auto;
}

.cropper__right h4 {
  margin: 0;
  font-size: 14px;
}

.cropper__preview-frame {
  border: 1px solid var(--el-border-color);
  border-radius: 10px;
  min-height: 170px;
  display: grid;
  place-items: center;
  background: repeating-conic-gradient(#f8fafc 0% 25%, #e2e8f0 0% 50%) 50% / 16px 16px;
  padding: 10px;
}

.cropper__preview-image {
  max-width: 100%;
  max-height: 170px;
  border-radius: 8px;
  border: 1px solid var(--el-border-color);
}

.cropper__preview-placeholder {
  color: var(--el-text-color-placeholder);
  font-size: 13px;
  text-align: center;
}

.cropper__field {
  display: grid;
  gap: 6px;
  font-size: 12px;
  color: var(--el-text-color-regular);
}

.cropper__color {
  width: 100%;
  height: 34px;
  border: 1px solid var(--el-border-color);
  border-radius: 8px;
  background: #fff;
  padding: 4px;
}

.cropper__size-row {
  display: flex;
  align-items: center;
  gap: 6px;
}

.cropper__size-row :deep(.el-input-number) {
  width: 84px;
}

.cropper__hint {
  margin: 0;
  font-size: 12px;
  line-height: 1.6;
  color: var(--el-text-color-secondary);
}

@media (max-width: 900px) {
  .cropper__grid {
    grid-template-columns: 1fr;
  }
}
</style>
