<template>
  <el-dialog
    :model-value="modelValue"
    class="rsv-dialog"
    width="860px"
    top="6vh"
    :show-close="false"
    :close-on-click-modal="false"
    @update:model-value="emit('update:modelValue', $event)"
    @open="loadDetail"
  >
    <!-- 外殼與標題列的樣式沿用 ReservationDialog.vue 的全域 rsv-dialog 樣式。 -->
    <template #header>
      <div class="rsv-dialog__header">
        <i class="fa-regular fa-eye rsv-dialog__header-icon" aria-hidden="true"></i>
        <span class="rsv-dialog__title">預約詳情</span>
        <button type="button" class="rsv-dialog__close" aria-label="關閉" @click="close">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
      </div>
    </template>
    <div v-loading="loading" class="rvd">
      <el-alert v-if="error" type="error" show-icon :closable="false" :title="error" />
      <template v-if="detail">
        <section class="rsv-card">
          <h3 class="rsv-card__title">
            <i class="fa-regular fa-calendar-check" aria-hidden="true"></i>
            預約基本資訊
            <el-tag :type="status.type" class="rvd__status">{{ status.label }}</el-tag>
          </h3>
          <dl class="rvd__list">
            <div><dt>預約單號</dt><dd>{{ detail.reservation_id }}</dd></div>
            <div><dt>預約事由</dt><dd>{{ reasonLabel(detail.visit_reason) }}</dd></div>
            <div><dt>預約日期</dt><dd>{{ formatDateWithWeekday(detail.visit_date) }}</dd></div>
            <div><dt>預約時段</dt><dd>{{ detail.visit_start_time }} – {{ detail.visit_end_time }}</dd></div>
            <div><dt>受訪公司</dt><dd>{{ detail.company_name || '—' }}</dd></div>
            <div><dt>受訪樓層</dt><dd>{{ detail.floor ? `${detail.floor} 樓` : '—' }}</dd></div>
            <div><dt>受訪人</dt><dd>{{ detail.host_name }}</dd></div>
            <div><dt>來訪人數</dt><dd>{{ detail.guest_count }} 人</dd></div>
            <div><dt>預約類型</dt><dd>{{ detail.reservation_type === 1 ? '現場預約' : '事前預約' }}</dd></div>
            <div><dt>速通碼</dt><dd>{{ detail.fast_pass_code || '—' }}</dd></div>
          </dl>
        </section>
        <section class="rsv-card">
          <h3 class="rsv-card__title">
            <i class="fa-regular fa-user" aria-hidden="true"></i>
            來訪者資料
          </h3>
          <el-table :data="detail.guests" size="small" class="rsv-visitors" empty-text="沒有來訪者資料">
            <el-table-column type="index" label="#" width="56" />
            <el-table-column prop="name" label="來訪者姓名" min-width="110" />
            <el-table-column prop="company_name" label="公司名稱" min-width="170" />
            <el-table-column label="職稱" min-width="100">
              <template #default="{ row }">{{ row.title || '—' }}</template>
            </el-table-column>
            <el-table-column prop="phone" label="手機號碼" min-width="130" />
            <el-table-column label="車牌號碼" min-width="100">
              <template #default="{ row }">{{ row.plate_number || '—' }}</template>
            </el-table-column>
          </el-table>
        </section>
        <div class="rsv-row">
          <section class="rsv-card rsv-card--half">
            <h3 class="rsv-card__title">
              <i class="fa-regular fa-clipboard" aria-hidden="true"></i>
              備註
            </h3>
            <p class="rvd__remark">{{ detail.remark || '無' }}</p>
          </section>
          <section class="rsv-card rsv-card--half">
            <h3 class="rsv-card__title">
              <i class="fa-regular fa-bell" aria-hidden="true"></i>
              通知設定
            </h3>
            <ul class="rvd__notify">
              <li>Email 通知受訪人：{{ yesNo(detail.is_email_notify) }}</li>
              <li>簡訊通知來訪者：{{ yesNo(detail.is_sms_notify) }}</li>
              <li>到訪前自動提醒：{{ detail.is_auto_remind ? `提前 ${detail.remind_time} 分鐘` : '否' }}</li>
            </ul>
          </section>
        </div>
      </template>
    </div>
    <template #footer>
      <div class="rvd__footer">
        <el-button type="primary" @click="close">關閉</el-button>
      </div>
    </template>
  </el-dialog>
</template>
<script setup>
/* *****************************************
File: ReservationViewDialog.vue
Author: Callum
Date: 2026-11-20
Description: 預約「檢視」對話框（唯讀），開啟時用 GET /api/guest/applies/{id} 取得單筆詳情。
***************************************** */
import { computed, ref } from 'vue';
import { fetchReservationDetail } from '../../../api/guest';
import { RESERVATION_STATUS } from '../constants';
import { formatDateWithWeekday } from '../format';
import { useVisitReasons } from '../useVisitReasons';
const { reasonLabel } = useVisitReasons();
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // 要檢視的預約單 id。
  reservationId: { type: Number, default: null },
});
const emit = defineEmits(['update:modelValue']);
const detail = ref(null);
const loading = ref(false);
const error = ref('');
const status = computed(() => RESERVATION_STATUS[detail.value?.reservation_status] || { label: '—', type: 'info' });
const yesNo = (value) => (value ? '是' : '否');
async function loadDetail(){
  detail.value = null;
  error.value = '';
  loading.value = true;
  const result = await fetchReservationDetail(props.reservationId);
  loading.value = false;
  if (!result.ok) {
    error.value = `預約詳情載入失敗：${result.message}`;
    return;
  }
  detail.value = result.data;
}
function close(){
  emit('update:modelValue', false);
}
</script>
<style scoped>
.rvd{
  min-height: 120px;
}
.rvd__status{
  margin-left: 6px;
}
.rvd__list{
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 32px;
  margin: 0 0 10px;
}
.rvd__list > div{
  display: flex;
  gap: 12px;
  padding: 8px 0;
  font-size: 14px;
  border-bottom: 1px dashed #eef0f4;
}
.rvd__list dt{
  flex-shrink: 0;
  width: 76px;
  color: #6b7280;
}
.rvd__list dd{
  margin: 0;
  color: #1f2937;
  word-break: break-all;
}
.rvd__remark{
  margin: 0;
  font-size: 14px;
  line-height: 1.7;
  color: #374151;
  white-space: pre-wrap;
}
.rvd__notify{
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 0;
  margin: 0;
  font-size: 14px;
  color: #374151;
  list-style: none;
}
.rvd__footer{
  display: flex;
  justify-content: flex-end;
}
</style>
