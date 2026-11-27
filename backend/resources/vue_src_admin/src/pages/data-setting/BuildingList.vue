<template>
  <section class="ds__card">
    <div class="ds__toolbar">
      <el-input v-model="keyword" class="ds__keyword" placeholder="關鍵字：棟別名稱" clearable>
        <template #prefix><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></template>
      </el-input>
      <div class="ds__actions">
        <el-button :loading="loading" @click="load">
          <i v-if="!loading" class="fa-solid fa-rotate ds__icon" aria-hidden="true"></i>重新整理
        </el-button>
        <el-button type="primary" @click="openDialog">
          <i class="fa-solid fa-plus ds__icon" aria-hidden="true"></i>新增棟別
        </el-button>
      </div>
    </div>
    <p class="ds__note"><i class="fa-solid fa-circle-info ds__icon" aria-hidden="true"></i>目前系統只提供新增，建立後無法修改或刪除，請確認內容再送出。新增棟別會自動建立它的樓層資料。點選棟別可查看它的樓層。</p>
    <LoadErrorAlert v-if="loadError" class="ds__notice" :message="loadError" @retry="load" />
    <!-- 點一列＝查看該棟別的樓層 -->
    <el-table v-loading="loading" :data="visible" class="ds__table--clickable" :empty-text="items.length ? '沒有符合條件的棟別' : '還沒有棟別資料'" @row-click="openFloors">
      <el-table-column label="棟別名稱" prop="name" min-width="200" />
      <el-table-column label="地上樓層" prop="on_floor" min-width="110" align="center" />
      <el-table-column label="地下樓層" prop="under_floor" min-width="110" align="center" />
      <el-table-column label="樓層合計" min-width="110" align="center">
        <template #default="{ row }">{{ row.on_floor + row.under_floor }}</template>
      </el-table-column>
    </el-table>
    <el-dialog v-model="dialogVisible" title="新增棟別" width="440px" :close-on-click-modal="false">
      <el-form ref="formRef" :model="form" :rules="rules" label-position="top" @submit.prevent="handleSave">
        <el-form-item label="棟別名稱" prop="name">
          <el-input v-model="form.name" maxlength="100" placeholder="例如 A 棟" />
        </el-form-item>
        <el-form-item label="地上樓層數" prop="on_floor">
          <el-input-number v-model="form.on_floor" :min="1" :max="100" :precision="0" />
        </el-form-item>
        <el-form-item label="地下樓層數" prop="under_floor">
          <el-input-number v-model="form.under_floor" :min="0" :max="10" :precision="0" />
          <p class="ds__hint">沒有地下室填 0。</p>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">新增</el-button>
      </template>
    </el-dialog>
  </section>
</template>
<script setup>
/* *****************************************
File: BuildingList.vue
Author: Callum
Date: 2026-11-26
Description: 基本資料設定「棟別設定」：棟別列表與新增。沒有設計稿，依現有 API 規劃（後端沒有修改、刪除）。
***************************************** */
import { computed, nextTick, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ElMessage, ElMessageBox } from 'element-plus';
import { addBuilding, fetchBuildings } from '../../api/dataSetting';
import LoadErrorAlert from '../guest/components/LoadErrorAlert.vue';
const router = useRouter();
const items = ref([]);
const loading = ref(false);
const loadError = ref('');
const keyword = ref('');
const visible = computed(() => {
  const text = keyword.value.trim().toLowerCase();
  return text ? items.value.filter((b) => b.name.toLowerCase().includes(text)) : items.value;
});
async function load(){
  loading.value = true;
  loadError.value = '';
  const result = await fetchBuildings();
  loading.value = false;
  if(result.ok) items.value = result.data.items || [];
  else loadError.value = `棟別資料載入失敗：${result.message}`;
  return result;
}
function openFloors(row){
  router.push({ name: 'data-setting-floor', query: { bid: row.id } });
}
const dialogVisible = ref(false);
const saving = ref(false);
const formRef = ref(null);
const form = reactive({ name: '', on_floor: 1, under_floor: 0 });
const sameName = (a, b) => a.trim().toLowerCase() === b.trim().toLowerCase();
const rules = {
  name: [
    { required: true, whitespace: true, message: '請輸入棟別名稱', trigger: 'blur' },
    // 後端不檢查重複，而新增後不能改也不能刪，所以在這裡擋。
    { validator: (_r, v, done) => done(items.value.some((b) => sameName(b.name, v || '')) ? new Error('這個棟別名稱已經存在') : undefined), trigger: 'blur' },
  ],
  on_floor: [{ required: true, message: '請輸入地上樓層數', trigger: 'change' }],
  under_floor: [{ required: true, message: '請輸入地下樓層數（沒有填 0）', trigger: 'change' }],
};
async function openDialog(){
  Object.assign(form, { name: '', on_floor: 1, under_floor: 0 });
  dialogVisible.value = true;
  await nextTick();
  formRef.value?.clearValidate();
}
async function handleSave(){
  if(saving.value) return;
  saving.value = true;
  try{
    const latest = await load(); // 用最新資料檢查名稱重複
    if(!latest.ok){
      ElMessage.error(`無法確認目前的棟別資料：${latest.message}`);
      return;
    }
    if(!(await formRef.value.validate().catch(() => false))) return;
    const name = form.name.trim();
    const confirmed = await ElMessageBox.confirm(`確定要新增「${name}」（地上 ${form.on_floor} 層、地下 ${form.under_floor} 層）嗎？建立後無法修改或刪除。`, '新增棟別', { confirmButtonText: '新增', cancelButtonText: '返回修改', type: 'warning' })
      .then(() => true, () => false);
    if(!confirmed) return;
    const result = await addBuilding({ name, on_floor: form.on_floor, under_floor: form.under_floor });
    if(!result.ok){
      ElMessage.error(result.message);
      return;
    }
    dialogVisible.value = false;
    ElMessage.success(`已新增棟別 ${name}`);
    await load();
  }finally{
    saving.value = false;
  }
}
onMounted(load);
</script>
<style scoped src="./data-setting.css"></style>
