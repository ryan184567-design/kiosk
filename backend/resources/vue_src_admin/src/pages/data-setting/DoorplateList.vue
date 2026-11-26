<template>
  <div>
    <div class="ds__stats">
      <StatCard icon="fa-solid fa-door-closed" label="全部門牌" :value="counts.all" tone="blue" clickable :active="statusFilter === 'all'" @click="statusFilter = 'all'" />
      <StatCard icon="fa-regular fa-circle-check" label="啟用中" :value="counts.on" tone="green" clickable :active="statusFilter === 'on'" @click="toggleStatus('on')" />
      <StatCard icon="fa-solid fa-ban" label="停用" :value="counts.off" tone="red" clickable :active="statusFilter === 'off'" @click="toggleStatus('off')" />
    </div>
    <section class="ds__card">
      <div class="ds__toolbar">
        <el-input v-model="keyword" class="ds__keyword" placeholder="關鍵字：公司名稱 / 門牌號碼" clearable>
          <template #prefix><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></template>
        </el-input>
        <el-select v-model="floorFilter" placeholder="全部樓層" clearable class="ds__select" aria-label="樓層">
          <el-option v-for="f in floorOptions" :key="f" :label="floorLabel(f)" :value="f" />
        </el-select>
        <div class="ds__actions">
          <el-button :loading="loading" @click="load">
            <i v-if="!loading" class="fa-solid fa-rotate ds__icon" aria-hidden="true"></i>重新整理
          </el-button>
          <el-button type="primary" @click="openDialog(null)">
            <i class="fa-solid fa-plus ds__icon" aria-hidden="true"></i>新增門牌
          </el-button>
        </div>
      </div>
      <p class="ds__note"><i class="fa-solid fa-circle-info ds__icon" aria-hidden="true"></i>公司名稱是訪客預約的「受訪公司」選項；訪客機只列出「啟用中」的公司。</p>
      <LoadErrorAlert v-if="loadError" class="ds__notice" :message="loadError" @retry="load" />
      <el-table v-loading="loading" :data="pageRows" :empty-text="items.length ? '沒有符合條件的門牌' : '還沒有門牌資料'">
        <el-table-column label="公司名稱" min-width="220">
          <template #default="{ row }">{{ row.company_name || '—' }}</template>
        </el-table-column>
        <el-table-column label="樓層" min-width="90" align="center">
          <template #default="{ row }">{{ floorLabel(row.floor) }}</template>
        </el-table-column>
        <el-table-column label="門牌號碼" prop="doorplate" min-width="100" align="center" />
        <el-table-column label="室／戶／之" min-width="110" align="center">
          <template #default="{ row }">{{ row.household || '—' }}</template>
        </el-table-column>
        <el-table-column label="使用狀態" min-width="100" align="center">
          <template #default="{ row }"><el-tag :type="isOn(row) ? 'success' : 'info'">{{ isOn(row) ? '啟用中' : '停用' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="操作" width="150" align="center">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDialog(row)">編輯</el-button>
            <el-button link type="danger" :loading="busyId === row.id" @click="handleDelete(row)">刪除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="ds__pager">
        <span>共 {{ filtered.length }} 筆</span>
        <el-pagination v-model:current-page="page" :page-size="PER_PAGE" :total="filtered.length" layout="prev, pager, next" background />
      </div>
    </section>
    <el-dialog v-model="dialogVisible" :title="editing ? '編輯門牌' : '新增門牌'" width="460px" :close-on-click-modal="false">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="handleSave">
        <el-form-item label="公司名稱" prop="company_name">
          <el-input v-model="form.company_name" maxlength="255" />
        </el-form-item>
        <el-form-item label="樓層" prop="floor">
          <el-input-number v-model="form.floor" :min="-10" :max="100" :precision="0" />
          <p class="ds__hint">地下樓層填負數，例如 -1 代表 B1。</p>
        </el-form-item>
        <el-form-item label="門牌號碼" prop="doorplate">
          <el-input-number v-model="form.doorplate" :min="1" :max="1000" :precision="0" />
        </el-form-item>
        <el-form-item label="室／戶／之" prop="household">
          <el-input-number v-model="form.household" :min="0" :max="10" :precision="0" />
          <p class="ds__hint">沒有分戶填 0。</p>
        </el-form-item>
        <el-form-item v-if="editing" label="使用狀態">
          <el-switch v-model="form.enabled" active-text="啟用中" inactive-text="停用" />
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
File: DoorplateList.vue
Author: Callum
Date: 2026-11-26
Description: 基本資料設定「門牌設定」：門牌（受訪公司）列表、篩選、新增、編輯、刪除。沒有設計稿，依現有 API 規劃。
***************************************** */
// 後端不分頁，篩選與分頁都在前端做。
// 門牌（門牌號碼＋樓層＋室戶）不能重複：後端遇到重複是丟未處理的例外（畫面只會看到 500），所以送出前先在這裡擋。
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { ElMessage, ElMessageBox } from 'element-plus';
import { addDoorplate, deleteDoorplate, fetchDoorplateList, updateDoorplate } from '../../api/dataSetting';
import LoadErrorAlert from '../guest/components/LoadErrorAlert.vue';
import StatCard from '../guest/components/StatCard.vue';
const PER_PAGE = 20;
// 使用狀態：1 啟用中（訪客機只列出這種），其他視為停用。
const STATUS_ON = 1;
const STATUS_OFF = 0;
const items = ref([]);
const loading = ref(false);
const loadError = ref('');
const keyword = ref('');
const floorFilter = ref('');
const statusFilter = ref('all'); // 'all'、'on'、'off'
const page = ref(1);
const busyId = ref(null);
const isOn = (row) => Number(row.status) === STATUS_ON;
const floorLabel = (floor) => (floor < 0 ? `B${Math.abs(floor)}` : `${floor}F`);
const counts = computed(() => {
  const on = items.value.filter(isOn).length;
  return { all: items.value.length, on, off: items.value.length - on };
});
const floorOptions = computed(() => [...new Set(items.value.map((d) => d.floor))].sort((a, b) => a - b));
const filtered = computed(() => {
  const text = keyword.value.trim().toLowerCase();
  return items.value
    .filter((d) => statusFilter.value === 'all' || isOn(d) === (statusFilter.value === 'on'))
    .filter((d) => floorFilter.value === '' || floorFilter.value === undefined || floorFilter.value === null || d.floor === floorFilter.value)
    .filter((d) => !text || (d.company_name || '').toLowerCase().includes(text) || String(d.doorplate).includes(text))
    .sort((a, b) => a.floor - b.floor || a.doorplate - b.doorplate || a.household - b.household);
});
const pageRows = computed(() => filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));
watch([keyword, floorFilter, statusFilter], () => { page.value = 1; });
// 刪除後最後一頁可能空了。
watch(() => filtered.value.length, (n) => { page.value = Math.min(page.value, Math.max(1, Math.ceil(n / PER_PAGE))); });
function toggleStatus(key){
  statusFilter.value = statusFilter.value === key ? 'all' : key;
}
async function load(){
  loading.value = true;
  loadError.value = '';
  const result = await fetchDoorplateList();
  loading.value = false;
  if(result.ok) items.value = result.data.items || [];
  else loadError.value = `門牌資料載入失敗：${result.message}`;
  return result;
}
// ── 新增、編輯 ──
const dialogVisible = ref(false);
const saving = ref(false);
const editing = ref(null); // 正在編輯的門牌；null＝新增
const formRef = ref(null);
const form = reactive({ company_name: '', floor: 1, doorplate: 1, household: 0, enabled: true });
function checkAddress(_rule, _value, done){
  const taken = items.value.some((d) => d.id !== editing.value?.id && d.floor === form.floor && d.doorplate === form.doorplate && d.household === form.household);
  done(taken ? new Error('同樓層的這個門牌號碼與室戶已經存在') : undefined);
}
const rules = {
  company_name: [{ required: true, whitespace: true, message: '請輸入公司名稱', trigger: 'blur' }],
  floor: [{ validator: (_r, v, done) => done(!v ? new Error('請輸入樓層（沒有 0 樓）') : undefined), trigger: 'change' }],
  doorplate: [{ required: true, message: '請輸入門牌號碼', trigger: 'change' }, { validator: checkAddress, trigger: 'change' }],
  household: [{ required: true, message: '請輸入室／戶／之（沒有填 0）', trigger: 'change' }],
};
async function openDialog(row){
  editing.value = row;
  Object.assign(form, row
    ? { company_name: row.company_name || '', floor: row.floor, doorplate: row.doorplate, household: row.household, enabled: isOn(row) }
    : { company_name: '', floor: 1, doorplate: 1, household: 0, enabled: true });
  dialogVisible.value = true;
  await nextTick();
  formRef.value?.clearValidate();
}
async function handleSave(){
  if(saving.value) return;
  saving.value = true;
  try{
    const latest = await load(); // 用最新資料檢查重複、確認要改的那筆還在
    if(!latest.ok){
      ElMessage.error(`無法確認目前的門牌資料：${latest.message}`);
      return;
    }
    if(editing.value && !items.value.some((d) => d.id === editing.value.id)){
      ElMessage.warning('這個門牌已經被刪除，列表已重新整理');
      dialogVisible.value = false;
      return;
    }
    if(!(await formRef.value.validate().catch(() => false))) return;
    const payload = { doorplate: form.doorplate, floor: form.floor, household: form.household, company_name: form.company_name.trim() };
    const result = editing.value
      ? await updateDoorplate({ ...payload, id: editing.value.id, status: form.enabled ? STATUS_ON : STATUS_OFF })
      : await addDoorplate(payload);
    if(!result.ok){
      ElMessage.error(result.message);
      return;
    }
    dialogVisible.value = false;
    ElMessage.success(editing.value ? `已儲存 ${payload.company_name}` : `已新增 ${payload.company_name}`);
    await load();
  }finally{
    saving.value = false;
  }
}
async function handleDelete(row){
  const name = row.company_name || `門牌 ${row.doorplate}`;
  const ok = await ElMessageBox.confirm(`確定要刪除「${name}」（${floorLabel(row.floor)}）嗎？已經指定這個受訪公司的預約，編輯時需要重新選擇。只是暫時不用的話，建議改成「停用」。`, '刪除門牌', { confirmButtonText: '刪除', cancelButtonText: '取消', type: 'warning' })
    .then(() => true, () => false);
  if(!ok) return;
  busyId.value = row.id;
  const result = await deleteDoorplate(row.id);
  busyId.value = null;
  if(result.ok) ElMessage.success(`已刪除 ${name}`);
  else ElMessage.error(result.message);
  await load();
}
onMounted(load);
</script>
<style scoped src="./data-setting.css"></style>
