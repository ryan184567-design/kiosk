<template>
  <div class="upl">
    <h3 class="upl__title">上傳紀錄</h3>
    <div class="upl__filters">
      <el-select v-model="filters.notify_type" placeholder="全部類型" clearable class="upl__select" aria-label="通知類型">
        <el-option v-for="(label, value) in NOTIFY_LABELS" :key="value" :label="label" :value="value" />
      </el-select>
      <el-select v-model="filters.success" placeholder="成功與失敗" clearable class="upl__select" aria-label="結果">
        <el-option label="成功" value="1" />
        <el-option label="失敗" value="0" />
      </el-select>
      <el-select v-model="filters.is_test" placeholder="含連線測試" clearable class="upl__select" aria-label="是否連線測試">
        <el-option label="只看連線測試" value="1" />
        <el-option label="不含連線測試" value="0" />
      </el-select>
      <el-date-picker v-model="filters.range" type="daterange" single-panel value-format="YYYY-MM-DD" format="YYYY/MM/DD" range-separator="–" start-placeholder="開始日期" end-placeholder="結束日期" class="upl__range" />
      <el-button type="primary" @click="search">
        <i class="fa-solid fa-magnifying-glass upl__icon" aria-hidden="true"></i>查詢
      </el-button>
    </div>
    <LoadErrorAlert v-if="loadError" class="upl__notice" :message="loadError" @retry="load" />
    <el-table v-loading="loading" :data="items" empty-text="沒有上傳紀錄">
      <el-table-column label="送出時間" prop="created_at" min-width="160" />
      <el-table-column label="通知類型" min-width="110">
        <template #default="{ row }">{{ NOTIFY_LABELS[row.notify_type] || row.notify_type }}<el-tag v-if="row.is_test" size="small" type="info" class="upl__tag">測試</el-tag></template>
      </el-table-column>
      <el-table-column label="結果" width="80" align="center">
        <template #default="{ row }"><el-tag :type="row.success ? 'success' : 'danger'">{{ row.success ? '成功' : '失敗' }}</el-tag></template>
      </el-table-column>
      <el-table-column label="狀態碼" width="80" align="center">
        <template #default="{ row }">{{ row.status_code ?? '—' }}</template>
      </el-table-column>
      <el-table-column label="回應時間" width="100" align="center">
        <template #default="{ row }">{{ row.duration_ms ?? '—' }} ms</template>
      </el-table-column>
      <el-table-column label="第幾次" width="70" align="center" prop="attempt" />
      <el-table-column label="操作" width="70" align="center">
        <template #default="{ row }"><el-button link type="primary" @click="detail = row">詳情</el-button></template>
      </el-table-column>
    </el-table>
    <div class="upl__pager">
      <span>共 {{ total }} 筆</span>
      <el-pagination v-model:current-page="page" :page-size="PER_PAGE" :total="total" layout="prev, pager, next" background @current-change="load" />
    </div>
    <el-dialog :model-value="Boolean(detail)" title="上傳詳情" width="640px" @close="detail = null">
      <template v-if="detail">
        <dl class="upl__detail">
          <div><dt>送出時間</dt><dd>{{ detail.created_at }}</dd></div>
          <div><dt>網址</dt><dd>{{ detail.url }}</dd></div>
          <div><dt>通知識別碼</dt><dd>{{ detail.message_id }}</dd></div>
          <div v-if="detail.error"><dt>失敗原因</dt><dd class="upl__error">{{ detail.error }}</dd></div>
        </dl>
        <h4 class="upl__sub">送出的內容</h4>
        <pre class="upl__code">{{ pretty(detail.request_body) }}</pre>
        <h4 class="upl__sub">對方的回應</h4>
        <pre class="upl__code">{{ detail.response_body || '（沒有回應）' }}</pre>
      </template>
    </el-dialog>
  </div>
</template>
<script setup>
/* *****************************************
File: UploadLogs.vue
Author: Callum
Date: 2026-11-26
Description: 上傳設定頁的「上傳紀錄」：依類型、結果、是否測試、日期篩選，可看每一筆送出的內容與對方回應。
***************************************** */
import { onMounted, reactive, ref } from 'vue';
import { fetchUploadLogs } from '../../../api/guest';
import LoadErrorAlert from './LoadErrorAlert.vue';
const PER_PAGE = 10;
// 後端字典 dashboard_notify_type。
const NOTIFY_LABELS = {
  heartbeat: '心跳',
  device_fault: '訪客機故障',
  reservation_approved: '核准',
  reservation_modified: '修改',
  reservation_cancelled: '取消',
  reservation_checkin: '報到',
  reservation_checkout: '離場',
};
const filters = reactive({ notify_type: '', success: '', is_test: '', range: null });
// applied：目前列表實際套用的條件；換頁用它，不吃還沒按查詢的草稿。
let applied = {};
const items = ref([]);
const total = ref(0);
const page = ref(1);
const loading = ref(false);
const loadError = ref('');
const detail = ref(null);
const pretty = (body) => (body ? JSON.stringify(body, null, 2) : '（沒有內容）');
let loadSeq = 0;
async function load(){
  const seq = ++loadSeq;
  loading.value = true;
  loadError.value = '';
  const result = await fetchUploadLogs({ ...applied, page: page.value, per_page: PER_PAGE });
  if(seq !== loadSeq) return;
  loading.value = false;
  if(!result.ok){
    loadError.value = `上傳紀錄載入失敗：${result.message}`;
    return;
  }
  items.value = result.data.items || [];
  total.value = result.data.total ?? items.value.length;
}
function search(){
  applied = { notify_type: filters.notify_type, success: filters.success, is_test: filters.is_test, date_from: filters.range?.[0], date_to: filters.range?.[1] };
  page.value = 1;
  load();
}
onMounted(search);
</script>
<style scoped>
.upl__title{
  margin: 0 0 14px;
  font-size: 16px;
  color: #111827;
}
.upl__filters{
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}
.upl__select{
  width: 150px;
}
.upl__filters :deep(.el-date-editor--daterange){
  --el-date-editor-daterange-width: 240px;
  flex: none;
}
.upl__icon{
  margin-right: 6px;
}
.upl__notice{
  margin-bottom: 14px;
}
.upl__tag{
  margin-left: 6px;
}
.upl__pager{
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-top: 14px;
  font-size: 14px;
  color: #374151;
}
.upl__detail{
  margin: 0;
  font-size: 14px;
}
.upl__detail div{
  display: flex;
  gap: 12px;
  margin-bottom: 6px;
}
.upl__detail dt{
  flex: none;
  width: 84px;
  color: #6b7280;
}
.upl__detail dd{
  min-width: 0;
  margin: 0;
  overflow-wrap: anywhere;
}
.upl__error{
  color: #b42318;
}
.upl__sub{
  margin: 14px 0 6px;
  font-size: 14px;
}
.upl__code{
  max-height: 220px;
  padding: 10px 12px;
  margin: 0;
  overflow: auto;
  font-size: 12px;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  background: #f5f7fb;
  border-radius: 6px;
}
</style>
