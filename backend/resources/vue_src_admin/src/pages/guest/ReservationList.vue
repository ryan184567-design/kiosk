<template>
  <div class="rsv-page">
    <div class="rsv-page__toolbar">
      <el-button type="primary" @click="dialogVisible = true">
        <i class="fa-solid fa-plus rsv-page__icon" aria-hidden="true"></i>新增預約
      </el-button>
      <el-button @click="loadRows">
        <i class="fa-solid fa-rotate rsv-page__icon" aria-hidden="true"></i>重新整理
      </el-button>
    </div>

    <el-alert
      v-if="listIsMock"
      class="rsv-page__notice"
      type="info"
      show-icon
      :closable="false"
      title="下方列表目前是示意資料：後端的預約列表 API 還沒完成，完成後切換即可。「新增預約」已經接上真正的 API。"
    />

    <el-alert v-if="loadError" class="rsv-page__notice" type="error" show-icon :closable="false" :title="loadError" />

    <el-table v-loading="loading" :data="rows" border empty-text="沒有預約資料">
      <el-table-column prop="reservation_id" label="預約單號" min-width="160" />
      <el-table-column label="預約日期" width="120">
        <template #default="{ row }">{{ formatDate(row.visit_date) }}</template>
      </el-table-column>
      <el-table-column label="時段" width="130">
        <template #default="{ row }">{{ row.visit_start_time }} – {{ row.visit_end_time }}</template>
      </el-table-column>
      <el-table-column prop="company_name" label="受訪公司" min-width="170" />
      <el-table-column prop="host_name" label="受訪人" width="100" />
      <el-table-column label="來訪者" min-width="120">
        <template #default="{ row }">{{ visitorSummary(row) }}</template>
      </el-table-column>
      <el-table-column label="人數" width="80" align="center">
        <template #default="{ row }">{{ row.guest_count }} 人</template>
      </el-table-column>
      <el-table-column label="事由" min-width="110">
        <template #default="{ row }">{{ row.visit_reason || '—' }}</template>
      </el-table-column>
      <el-table-column label="狀態" width="110" align="center">
        <template #default="{ row }">
          <el-tag :type="statusOf(row).type">{{ statusOf(row).label }}</el-tag>
        </template>
      </el-table-column>
    </el-table>

    <ReservationDialog v-model="dialogVisible" @saved="loadRows" />
  </div>
</template>

<script setup>
/* *****************************************
File: ReservationList.vue
Author: Callum
Date: 2026-11-19
Description: 預約管理頁。新增預約接真 API；列表資料來自 api/guest.js（後端列表完成前為假資料）。
***************************************** */

import { onMounted, ref } from 'vue';
import ReservationDialog from './components/ReservationDialog.vue';
import { fetchReservations, isMock } from '../../api/guest';

const dialogVisible = ref(false);
const listIsMock = isMock('reservationList');
const rows = ref([]);
const loading = ref(false);
const loadError = ref('');

// 預約狀態字典，對應 API 文件 guest_apply_reservation_status（reservation_status）。
const statusMap = {
  0: { label: '待審核', type: 'warning' },
  1: { label: '修改待審核', type: 'warning' },
  10: { label: '已核准', type: 'success' },
  11: { label: '修改已核准', type: 'success' },
  20: { label: '已通知', type: 'primary' },
  30: { label: '未到', type: 'info' },
  40: { label: '已到', type: 'success' },
  50: { label: '已離場', type: 'info' },
  60: { label: '已取消', type: 'danger' },
};

// 後端若新增了這裡沒有的狀態碼，不要讓整個表格因為讀不到而壞掉。
function statusOf(row) {
  return statusMap[row.reservation_status] || { label: `狀態 ${row.reservation_status}`, type: 'info' };
}

function formatDate(value) {
  return value ? value.replaceAll('-', '/') : '—';
}

// 來訪者欄：只有一位顯示姓名，多位顯示「第一位姓名 等 N 人」。
function visitorSummary(row) {
  const guests = row.guests || [];

  if (guests.length === 0) return '—';
  if (guests.length === 1) return guests[0].name;

  return `${guests[0].name} 等 ${guests.length} 人`;
}

async function loadRows() {
  loading.value = true;
  loadError.value = '';

  const result = await fetchReservations();

  loading.value = false;

  if (!result.ok) {
    loadError.value = `預約列表載入失敗：${result.message}`;

    return;
  }

  rows.value = result.data.items || [];
}

onMounted(loadRows);
</script>

<style scoped>
.rsv-page__toolbar {
  margin-bottom: 16px;
}

.rsv-page__notice {
  margin-bottom: 16px;
}

.rsv-page__icon {
  margin-right: 6px;
}
</style>
