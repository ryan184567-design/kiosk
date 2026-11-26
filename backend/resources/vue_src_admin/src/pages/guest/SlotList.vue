<template>
  <div class="slt">
    <!-- 三張卡片＝依使用狀態篩選 -->
    <div class="slt__stats">
      <StatCard icon="fa-solid fa-box-archive" label="全部證件櫃" :value="counts.all" tone="blue" clickable :active="filter === 'all'" @click="filter = 'all'" />
      <StatCard icon="fa-regular fa-circle-check" label="未使用" :value="counts.free" tone="green" clickable :active="filter === 'free'" @click="toggleFilter('free')" />
      <StatCard icon="fa-solid fa-id-card" label="使用中" :value="counts.used" tone="red" clickable :active="filter === 'used'" @click="toggleFilter('used')" />
    </div>
    <section class="slt__main">
      <div class="slt__toolbar">
        <el-input v-model="keyword" class="slt__keyword" placeholder="關鍵字：櫃號 / 備註 / 預約單號" clearable>
          <template #prefix><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></template>
        </el-input>
        <div class="slt__actions">
          <el-button :loading="loading" @click="load">
            <i v-if="!loading" class="fa-solid fa-rotate slt__icon" aria-hidden="true"></i>重新整理
          </el-button>
          <el-button type="primary" @click="openDialog(null)">
            <i class="fa-solid fa-plus slt__icon" aria-hidden="true"></i>新增證件櫃
          </el-button>
        </div>
      </div>
      <LoadErrorAlert v-if="loadError" class="slt__notice" :message="loadError" @retry="load" />
      <div v-loading="loading" class="slt__body">
        <el-empty v-if="!loading && !loadError && !visibleSlots.length" :description="slots.length ? '沒有符合條件的證件櫃' : '還沒有證件櫃，請按「新增證件櫃」建立'" />
        <ul v-else class="slt__grid">
          <li v-for="slot in visibleSlots" :key="slot.id" class="slt__card" :class="{ 'slt__card--used': isUsed(slot) }">
            <div class="slt__head">
              <span class="slt__name">{{ slot.name }}</span>
              <el-tag :type="isUsed(slot) ? 'danger' : 'success'">{{ isUsed(slot) ? '使用中' : '未使用' }}</el-tag>
            </div>
            <dl class="slt__info">
              <div><dt>預約單號</dt><dd>{{ slot.reservation_id || '—' }}</dd></div>
              <div><dt>備註</dt><dd class="slt__remark" :title="slot.remark || ''">{{ slot.remark || '—' }}</dd></div>
            </dl>
            <div class="slt__foot">
              <el-button link type="primary" @click="openDialog(slot)">
                <i class="fa-solid fa-pen slt__icon" aria-hidden="true"></i>編輯
              </el-button>
              <el-button v-if="isUsed(slot)" link type="warning" :loading="busyId === slot.id" @click="handleRelease(slot)">
                <i class="fa-solid fa-lock-open slt__icon" aria-hidden="true"></i>釋放
              </el-button>
              <el-button link type="danger" :disabled="isUsed(slot)" :title="isUsed(slot) ? '使用中的證件櫃不能刪除' : ''" :loading="!isUsed(slot) && busyId === slot.id" @click="handleDelete(slot)">
                <i class="fa-regular fa-trash-can slt__icon" aria-hidden="true"></i>刪除
              </el-button>
            </div>
          </li>
        </ul>
      </div>
    </section>
    <el-dialog v-model="dialogVisible" :title="editing ? '編輯證件櫃' : '新增證件櫃'" width="440px" :close-on-click-modal="false">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="handleSave">
        <el-form-item label="櫃號" prop="name">
          <el-input v-model="form.name" :maxlength="NAME_MAX" show-word-limit :disabled="nameLocked" placeholder="例如 A01" />
          <p v-if="nameLocked" class="slt__hint">使用中不能改櫃號：訪客離場時是用櫃號找回這個櫃子。</p>
        </el-form-item>
        <el-form-item label="備註" prop="remark">
          <el-input v-model="form.remark" type="textarea" :rows="3" :maxlength="REMARK_MAX" show-word-limit placeholder="選填，例如放置位置" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">{{ editing ? '儲存' : '新增' }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>
<script setup>
/* *****************************************
File: SlotList.vue
Author: Callum
Date: 2026-11-26
Description: 證件櫃管理頁（統計卡片、關鍵字與使用狀態篩選、櫃位卡片、新增／編輯／釋放／刪除）。沒有設計稿，依現有 API 規劃。
***************************************** */
// 使用狀態會被訪客機隨時改掉（報到占用、離場釋放），畫面上的資料可能是舊的，所以修改、釋放、刪除前都先重新抓一次再判斷。
// 後端沒有做、由這裡把關的事：
// - 櫃號不能重複：後端不檢查，而離場時是用櫃號找櫃子（GuestApplyService::leave），重複會釋放到別的櫃子。
// - 櫃號上限 10 字：證件櫃本身可存 50 字，但預約單上的 id_card_slot_no 只有 10 字，超過會讓訪客機報到失敗。
// - 使用中不能刪除、不能改櫃號。
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { createSlot, deleteSlot, fetchSlots, updateSlot } from '../../api/guest';
import LoadErrorAlert from './components/LoadErrorAlert.vue';
import StatCard from './components/StatCard.vue';
const NAME_MAX = 10;
const REMARK_MAX = 200;
const STATUS_FREE = 0;
const STATUS_USED = 1;
const slots = ref([]);
const loading = ref(false);
const loadError = ref('');
const keyword = ref('');
const filter = ref('all'); // 'all'、'free'、'used'
const busyId = ref(null);
const isUsed = (slot) => Number(slot.status) === STATUS_USED;
// 櫃號裡的數字照數值大小排（A2 排在 A10 前面）。
const byName = (a, b) => a.name.localeCompare(b.name, 'zh-Hant', { numeric: true }) || a.id - b.id;
const counts = computed(() => {
  const used = slots.value.filter(isUsed).length;
  return { all: slots.value.length, free: slots.value.length - used, used };
});
const visibleSlots = computed(() => {
  const text = keyword.value.trim().toLowerCase();
  return slots.value.filter((slot) => {
    if(filter.value !== 'all' && isUsed(slot) !== (filter.value === 'used')) return false;
    return !text || [slot.name, slot.remark, slot.reservation_id].some((v) => (v || '').toLowerCase().includes(text));
  });
});
function toggleFilter(key){
  filter.value = filter.value === key ? 'all' : key;
}
// 連續重新整理時，只採用最後一次請求的結果。
let loadSeq = 0;
async function load(){
  const seq = ++loadSeq;
  loading.value = true;
  loadError.value = '';
  const result = await fetchSlots();
  if(seq !== loadSeq) return result;
  loading.value = false;
  if(result.ok){
    slots.value = [...(result.data.items || [])].sort(byName);
  }else{
    loadError.value = `證件櫃載入失敗：${result.message}`;
  }
  return result;
}
// ── 新增、編輯 ──
const dialogVisible = ref(false);
const saving = ref(false);
const editing = ref(null); // 正在編輯的證件櫃；null＝新增
const formRef = ref(null);
const form = reactive({ name: '', remark: '' });
const nameLocked = computed(() => Boolean(editing.value) && isUsed(editing.value));
const sameName = (a, b) => a.trim().toLowerCase() === b.trim().toLowerCase();
const rules = {
  name: [
    { required: true, whitespace: true, message: '請輸入櫃號', trigger: 'blur' },
    {
      validator: (_rule, value, done) => {
        const taken = slots.value.some((s) => s.id !== editing.value?.id && sameName(s.name, value || ''));
        done(taken ? new Error('這個櫃號已經存在') : undefined);
      },
      trigger: 'blur',
    },
  ],
};
async function openDialog(slot){
  editing.value = slot;
  form.name = slot?.name ?? '';
  form.remark = slot?.remark ?? '';
  dialogVisible.value = true;
  await nextTick();
  formRef.value?.clearValidate();
}
async function handleSave(){
  if(saving.value) return;
  saving.value = true;
  try{
    // 先拿最新資料：櫃號是否重複、這個櫃子現在是否使用中，都要看「現在」的狀態。
    const latest = await load();
    if(!latest.ok){
      ElMessage.error(`無法確認目前的證件櫃資料：${latest.message}`);
      return;
    }
    let current = null;
    if(editing.value){
      current = slots.value.find((s) => s.id === editing.value.id);
      if(!current){
        ElMessage.warning('這個證件櫃已經被刪除，列表已重新整理');
        dialogVisible.value = false;
        return;
      }
      editing.value = current;
      // 打開視窗之後才被占用的：櫃號改回原本的。
      if(isUsed(current)) form.name = current.name;
    }
    const valid = await formRef.value.validate().catch(() => false);
    if(!valid) return;
    const name = form.name.trim();
    const remark = form.remark.trim() || null;
    const result = current
      ? await updateSlot(current.id, { name, remark, status: Number(current.status), reservation_id: current.reservation_id || null })
      : await createSlot({ name, remark });
    if(!result.ok){
      ElMessage.error(result.message);
      return;
    }
    dialogVisible.value = false;
    await load();
    if(current){
      ElMessage.success(`已儲存證件櫃 ${name}`);
      return;
    }
    await ensureNewSlotIsFree(name);
  }finally{
    saving.value = false;
  }
}
// 新增 API 不帶使用狀態，靠資料庫預設值。早期的 migration 預設是「使用中」（後來直接改了同一支檔案，已經建好的資料表不會跟著變），
// 所以新增後如果是「使用中但沒有預約單號」，立刻改回未使用。
async function ensureNewSlotIsFree(name){
  const created = slots.value.find((s) => sameName(s.name, name));
  if(created && isUsed(created) && !created.reservation_id){
    const result = await updateSlot(created.id, { name: created.name, remark: created.remark, status: STATUS_FREE, reservation_id: null });
    await load();
    if(!result.ok){
      ElMessage.warning(`已新增證件櫃 ${name}，但狀態是「使用中」，請按「釋放」：${result.message}`);
      return;
    }
  }
  ElMessage.success(`已新增證件櫃 ${name}`);
}
// ── 釋放、刪除 ──
async function confirm(message, title, confirmButtonText){
  try{
    await ElMessageBox.confirm(message, title, { confirmButtonText, cancelButtonText: '取消', type: 'warning' });
    return true;
  }catch{
    return false;
  }
}
// 重新抓一次並找出同一個櫃子；抓不到或已被刪除時回傳 null（並說明原因）。
async function findLatest(slot){
  const latest = await load();
  if(!latest.ok){
    ElMessage.error(`無法確認目前狀態：${latest.message}`);
    return null;
  }
  const current = slots.value.find((s) => s.id === slot.id);
  if(!current) ElMessage.warning(`證件櫃 ${slot.name} 已經被刪除`);
  return current || null;
}
async function handleRelease(slot){
  const holder = slot.reservation_id ? `（目前是預約單 ${slot.reservation_id} 使用中）` : '';
  const ok = await confirm(`確定要把證件櫃 ${slot.name} 改成「未使用」嗎？${holder}請先確認櫃內的證件已經歸還，否則下一位訪客可能被分配到同一個櫃子。`, '釋放證件櫃', '釋放');
  if(!ok) return;
  busyId.value = slot.id;
  try{
    const current = await findLatest(slot);
    if(!current) return;
    // 確認期間換人使用了（或已經離場釋放），不能照舊畫面送出。
    if(!isUsed(current) || (current.reservation_id || '') !== (slot.reservation_id || '')){
      ElMessage.warning(`證件櫃 ${slot.name} 的狀態已經變更，沒有送出，列表已重新整理`);
      return;
    }
    const result = await updateSlot(current.id, { name: current.name, remark: current.remark, status: STATUS_FREE, reservation_id: null });
    if(!result.ok){
      ElMessage.error(result.message);
      return;
    }
    ElMessage.success(`已釋放證件櫃 ${current.name}`);
    await load();
  }finally{
    busyId.value = null;
  }
}
async function handleDelete(slot){
  const ok = await confirm(`確定要刪除證件櫃 ${slot.name} 嗎？`, '刪除證件櫃', '刪除');
  if(!ok) return;
  busyId.value = slot.id;
  try{
    const current = await findLatest(slot);
    if(!current) return;
    if(isUsed(current)){
      ElMessage.warning(`證件櫃 ${current.name} 剛剛被使用了，不能刪除`);
      return;
    }
    const result = await deleteSlot(current.id);
    if(!result.ok){
      ElMessage.error(result.message);
      return;
    }
    ElMessage.success(`已刪除證件櫃 ${current.name}`);
    await load();
  }finally{
    busyId.value = null;
  }
}
onMounted(load);
</script>
<style scoped>
.slt__stats{
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.slt__main{
  padding: 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.slt__toolbar{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.slt__keyword{
  width: 300px;
  max-width: 100%;
}
.slt__actions{
  display: flex;
  gap: 8px;
}
.slt__icon{
  margin-right: 6px;
}
.slt__notice{
  margin-bottom: 14px;
}
.slt__body{
  min-height: 220px;
}
.slt__grid{
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 14px;
  padding: 0;
  margin: 0;
  list-style: none;
}
/* 上緣色條＝使用狀態（綠：未使用、紅：使用中），右上角另有文字標籤，不只靠顏色分辨。 */
.slt__card{
  display: flex;
  flex-direction: column;
  min-width: 0;
  padding: 14px 16px 10px;
  border: 1px solid #e5e7eb;
  border-top: 4px solid #16a34a;
  border-radius: 8px;
  transition: box-shadow var(--app-transition-duration) var(--app-transition-timing);
}
.slt__card:hover{
  box-shadow: 0 4px 12px rgba(15, 35, 72, 0.1);
}
.slt__card--used{
  background: #fffafa;
  border-top-color: #e5484d;
}
.slt__head{
  display: flex;
  gap: 8px;
  align-items: center;
  justify-content: space-between;
}
.slt__name{
  min-width: 0;
  overflow: hidden;
  font-size: 22px;
  font-weight: 700;
  color: #111827;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.slt__info{
  flex: 1;
  margin: 12px 0;
  font-size: 13px;
}
.slt__info div{
  display: flex;
  gap: 10px;
  margin-bottom: 4px;
}
.slt__info dt{
  flex: none;
  width: 56px;
  color: #6b7280;
}
.slt__info dd{
  min-width: 0;
  margin: 0;
  color: #1f2937;
  overflow-wrap: anywhere;
}
/* 備註最多顯示兩行，完整內容放在 title。 */
.slt__remark{
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  line-clamp: 2;
}
.slt__foot{
  display: flex;
  gap: 6px;
  justify-content: flex-end;
  padding-top: 8px;
  border-top: 1px solid #f1f3f6;
}
.slt__hint{
  width: 100%;
  margin: 4px 0 0;
  font-size: 13px;
  line-height: 1.5;
  color: #6b7280;
}
</style>
