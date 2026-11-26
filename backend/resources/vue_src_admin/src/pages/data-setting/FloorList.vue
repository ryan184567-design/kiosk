<template>
  <section class="ds__card">
    <div class="ds__toolbar">
      <el-select v-model="buildingId" placeholder="全部棟別" clearable class="ds__select" aria-label="棟別">
        <el-option v-for="b in buildings" :key="b.id" :label="b.name" :value="b.id" />
      </el-select>
      <el-select v-model="location" placeholder="地上＋地下" clearable class="ds__select" aria-label="地上或地下">
        <el-option label="地上" value="地上" />
        <el-option label="地下" value="地下" />
      </el-select>
      <div class="ds__actions">
        <el-button :loading="loading" @click="load">
          <i v-if="!loading" class="fa-solid fa-rotate ds__icon" aria-hidden="true"></i>重新整理
        </el-button>
      </div>
    </div>
    <p class="ds__note"><i class="fa-solid fa-circle-info ds__icon" aria-hidden="true"></i>樓層資料在新增棟別時自動建立，目前系統只提供查詢。</p>
    <LoadErrorAlert v-if="loadError" class="ds__notice" :message="loadError" @retry="load" />
    <el-empty v-if="!loading && !loadError && !floors.length" description="還沒有樓層資料；新增棟別後，樓層會自動出現在這裡">
      <el-button type="primary" @click="router.push({ name: 'data-setting-building' })">前往棟別設定</el-button>
    </el-empty>
    <template v-else>
    <el-table v-loading="loading" :data="pageRows" empty-text="沒有符合條件的樓層">
      <el-table-column label="棟別" prop="bname" min-width="180" />
      <el-table-column label="樓層" min-width="100" align="center">
        <template #default="{ row }">{{ floorLabel(row) }}</template>
      </el-table-column>
      <el-table-column label="地上／地下" prop="location" min-width="110" align="center" />
      <el-table-column label="使用狀態" min-width="110" align="center">
        <template #default="{ row }"><el-tag :type="Number(row.status) === 1 ? 'success' : 'info'">{{ Number(row.status) === 1 ? '啟用' : '停用' }}</el-tag></template>
      </el-table-column>
      <el-table-column label="平面圖" min-width="110" align="center">
        <template #default="{ row }">{{ row.image ? '已設定' : '—' }}</template>
      </el-table-column>
    </el-table>
    <div class="ds__pager">
      <span>共 {{ filtered.length }} 筆</span>
      <el-pagination v-model:current-page="page" :page-size="PER_PAGE" :total="filtered.length" layout="prev, pager, next" background />
    </div>
    </template>
  </section>
</template>
<script setup>
/* *****************************************
File: FloorList.vue
Author: Callum
Date: 2026-11-26
Description: 基本資料設定「樓別設定」：各棟樓層列表（依棟別、地上／地下篩選）。後端只提供查詢。
***************************************** */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchFloors } from '../../api/dataSetting';
import LoadErrorAlert from '../guest/components/LoadErrorAlert.vue';
const PER_PAGE = 20;
const route = useRoute();
const router = useRouter();
const floors = ref([]);
const buildings = ref([]);
const loading = ref(false);
const loadError = ref('');
// 從棟別設定的「查看樓層」進來時，網址帶 ?bid=棟別 id。
const buildingId = ref(Number(route.query.bid) || '');
const location = ref('');
const page = ref(1);
const floorLabel = (row) => (row.location === '地下' ? `B${row.floor}` : `${row.floor}F`);
// 高樓層在上：地上由高到低，接著 B1、B2…
const height = (row) => (row.location === '地下' ? -row.floor : row.floor);
const filtered = computed(() => floors.value
  .filter((f) => (!buildingId.value || Number(f.bid) === buildingId.value) && (!location.value || f.location === location.value))
  .sort((a, b) => a.bid - b.bid || height(b) - height(a)));
const pageRows = computed(() => filtered.value.slice((page.value - 1) * PER_PAGE, page.value * PER_PAGE));
watch([buildingId, location], () => { page.value = 1; });
async function load(){
  loading.value = true;
  loadError.value = '';
  const result = await fetchFloors();
  loading.value = false;
  if(!result.ok){
    loadError.value = `樓層資料載入失敗：${result.message}`;
    return;
  }
  floors.value = result.data.floor || [];
  buildings.value = result.data.building || [];
}
onMounted(load);
</script>
<style scoped src="./data-setting.css"></style>
