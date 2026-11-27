<template>
  <aside class="rcd">
    <div class="rcd__head">
      <h3 class="rcd__title">訪客詳細資料</h3>
      <button type="button" class="rcd__close" aria-label="關閉詳細資料" @click="emit('close')">
        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
      </button>
    </div>
    <div v-if="!row" class="rcd__empty">請從左側列表選擇一筆訪客記錄，這裡會顯示詳細資料。</div>
    <template v-else>
      <div class="rcd__profile">
        <div class="rcd__profile-body">
          <div class="rcd__name">
            {{ visitor.name || '—' }}
            <el-tag :type="status.type" size="small">{{ status.label }}</el-tag>
          </div>
          <div class="rcd__line"><i class="fa-regular fa-building" aria-hidden="true"></i>{{ visitor.company_name || '—' }}</div>
          <div class="rcd__line"><i class="fa-solid fa-phone" aria-hidden="true"></i>{{ visitor.phone || '—' }}</div>
          <div class="rcd__line"><i class="fa-regular fa-user" aria-hidden="true"></i>{{ reasonLabel(row.visit_reason) }}</div>
        </div>
      </div>
      <dl class="rcd__list">
        <div class="rcd__item"><dt><i class="fa-regular fa-calendar-days" aria-hidden="true"></i>登記時間</dt><dd>{{ formatDateTime(row.created_at) }}</dd></div>
        <div class="rcd__item"><dt><i class="fa-regular fa-building" aria-hidden="true"></i>接待樓層</dt><dd>{{ row.floor ? `${row.floor}F` : '—' }}</dd></div>
        <div class="rcd__item"><dt><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>進場時間</dt><dd>{{ formatDateTime(row.check_in_at) }}</dd></div>
        <div class="rcd__item"><dt><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>離場時間</dt><dd>{{ formatDateTime(row.check_out_at) }}</dd></div>
        <div class="rcd__item"><dt><i class="fa-solid fa-car" aria-hidden="true"></i>車牌號碼</dt><dd>{{ visitor.plate_number || '—' }}</dd></div>
        <div class="rcd__item"><dt><i class="fa-regular fa-clipboard" aria-hidden="true"></i>備註</dt><dd>{{ row.remark || '—' }}</dd></div>
        <div v-if="row.health_declared !== undefined" class="rcd__item">
          <dt><i class="fa-regular fa-file-lines" aria-hidden="true"></i>健康聲明</dt>
          <dd>
            <span v-if="row.health_declared" class="rcd__agree"><i class="fa-regular fa-circle-check" aria-hidden="true"></i>已同意</span>
            <span v-else>未同意</span>
          </dd>
        </div>
      </dl>
    </template>
    <div class="rcd__footer">
      <el-button :disabled="!row" @click="emit('print')">
        <i class="fa-solid fa-print rcd__icon" aria-hidden="true"></i>列印訪客單
      </el-button>
      <el-button type="primary" :disabled="!row" @click="emit('edit')">
        <i class="fa-regular fa-pen-to-square rcd__icon" aria-hidden="true"></i>編輯
      </el-button>
    </div>
  </aside>
</template>
<script setup>
/* *****************************************
File: RecordDetail.vue
Author: Callum
Date: 2026-11-19
Description: 訪客記錄頁右側的「訪客詳細資料」面板，列印與編輯由父層處理。
***************************************** */
import { computed } from 'vue';
import { RECORD_STATUS } from '../constants';
import { formatClock, formatDate } from '../format';
import { useVisitReasons } from '../useVisitReasons';

const { reasonLabel } = useVisitReasons();
const props = defineProps({
  // 目前選取的訪客記錄（列表單筆資料），沒有選取時為 null。
  row: { type: Object, default: null },
});
const emit = defineEmits(['close', 'print', 'edit']);
// 詳情顯示第一位來訪者。
const visitor = computed(() => props.row?.guests?.[0] || {});
const status = computed(() => RECORD_STATUS[props.row?.reservation_status] || { label: '—', type: 'info' });
// '2026-11-08 15:32:00' → '2026/11/08 15:32'；沒有值（例如還沒離場）顯示 —。
function formatDateTime(value) {
  return value ? `${formatDate(value)} ${formatClock(value)}` : '—';
}
</script>
<style scoped>
.rcd{
  display: flex;
  flex-direction: column;
  padding: 18px 20px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.rcd__head{
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.rcd__title{
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: #1f2937;
}
/* 標題左側的藍色直條，對應設計稿。 */
.rcd__title::before{
  width: 4px;
  height: 16px;
  content: '';
  background: var(--el-color-primary);
  border-radius: 2px;
}
.rcd__close{
  padding: 4px 6px;
  font-size: 18px;
  color: #6b7280;
  cursor: pointer;
  background: transparent;
  border: 0;
}
.rcd__close:hover{
  color: #1f2937;
}
.rcd__empty{
  padding: 32px 8px;
  font-size: 14px;
  line-height: 1.7;
  color: #6b7280;
  text-align: center;
}
.rcd__profile{
  display: flex;
  gap: 14px;
  padding-bottom: 14px;
  border-bottom: 1px solid #f0f1f3;
}
.rcd__profile-body{
  min-width: 0;
}
.rcd__name{
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 6px;
  font-size: 18px;
  font-weight: 700;
  color: #111827;
}
.rcd__line{
  display: flex;
  gap: 8px;
  align-items: center;
  padding: 2px 0;
  font-size: 14px;
  color: #374151;
}
.rcd__line i{
  width: 16px;
  color: #6b7280;
  text-align: center;
}
.rcd__list{
  margin: 0;
}
.rcd__item{
  display: flex;
  gap: 12px;
  padding: 9px 0;
  font-size: 14px;
  border-bottom: 1px solid #f0f1f3;
}
.rcd__item dt{
  display: flex;
  flex-shrink: 0;
  gap: 8px;
  align-items: center;
  width: 104px;
  color: #6b7280;
}
.rcd__item dt i{
  width: 16px;
  text-align: center;
}
.rcd__item dd{
  min-width: 0;
  margin: 0;
  color: #1f2937;
  word-break: break-all;
}
.rcd__agree{
  color: #16a34a;
}
.rcd__agree i{
  margin-right: 6px;
}
.rcd__footer{
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  padding-top: 14px;
  margin-top: auto;
}
.rcd__icon{
  margin-right: 6px;
}
</style>
