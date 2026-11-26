<template>
  <aside class="apd">
    <h3 class="apd__title">
      <i class="fa-regular fa-file-lines" aria-hidden="true"></i>
      預約申請詳情
    </h3>
    <div v-if="!row" class="apd__empty">請從左側列表選擇一筆預約，這裡會顯示詳細資料。</div>
    <template v-else>
      <section class="apd__section">
        <h4 class="apd__heading">
          <i class="fa-regular fa-user" aria-hidden="true"></i>
          訪客資料
        </h4>
        <dl class="apd__list">
          <div class="apd__item"><dt>訪客姓名</dt><dd>{{ visitor.name || '—' }}</dd></div>
          <div class="apd__item"><dt>公司名稱</dt><dd>{{ visitor.company_name || '—' }}</dd></div>
          <div class="apd__item"><dt>職稱</dt><dd>{{ visitor.title || '—' }}</dd></div>
          <div class="apd__item"><dt>手機號碼</dt><dd>{{ visitor.phone || '—' }}</dd></div>
          <div class="apd__item"><dt>Email</dt><dd>{{ visitor.email || '—' }}</dd></div>
        </dl>
        <p v-if="extraCount > 0" class="apd__more">另有 {{ extraCount }} 位同行來訪者</p>
      </section>
      <section class="apd__section">
        <h4 class="apd__heading">
          <i class="fa-regular fa-calendar-days" aria-hidden="true"></i>
          來訪資訊
        </h4>
        <dl class="apd__list">
          <div class="apd__item"><dt>預約單號</dt><dd class="apd__link">{{ row.reservation_id }}</dd></div>
          <div class="apd__item"><dt>來訪日期</dt><dd>{{ formatDateWithWeekday(row.visit_date) }}</dd></div>
          <div class="apd__item"><dt>來訪時間</dt><dd>{{ row.visit_start_time }} – {{ row.visit_end_time }}</dd></div>
          <div class="apd__item"><dt>來訪事由</dt><dd>{{ reasonLabel(row.visit_reason) }}</dd></div>
          <div class="apd__item"><dt>來訪人數</dt><dd>{{ row.guest_count }} 人</dd></div>
          <div class="apd__item"><dt>受訪人</dt><dd>{{ row.host_name }}</dd></div>
          <div class="apd__item"><dt>受訪公司</dt><dd>{{ row.company_name }}</dd></div>
          <div class="apd__item"><dt>受訪樓層</dt><dd>{{ row.floor ? `${row.floor} 樓` : '—' }}</dd></div>
        </dl>
      </section>
      <section class="apd__section">
        <h4 class="apd__heading">
          <i class="fa-regular fa-comment-dots" aria-hidden="true"></i>
          備註
        </h4>
        <div class="apd__remark">{{ row.remark || '無' }}</div>
      </section>
    </template>
    <div class="apd__footer">
      <el-button @click="emit('back')">返回列表</el-button>
      <el-button type="danger" plain :disabled="!canReview" :loading="busy" @click="emit('reject')">
        <i class="fa-solid fa-xmark apd__icon" aria-hidden="true"></i>拒絕
      </el-button>
      <el-button type="primary" :disabled="!canReview" :loading="busy" @click="emit('approve')">
        <i class="fa-solid fa-check apd__icon" aria-hidden="true"></i>核准
      </el-button>
    </div>
  </aside>
</template>
<script setup>
/* *****************************************
File: ApprovalDetail.vue
Author: Callum
Date: 2026-11-19
Description: 訪客審核頁右側的「預約申請詳情」面板，核准／拒絕由父層處理。
***************************************** */
import { computed } from 'vue';
import { PENDING_STATUSES } from '../constants';
import { formatDateWithWeekday } from '../format';
import { useVisitReasons } from '../useVisitReasons';

const { reasonLabel } = useVisitReasons();
const props = defineProps({
  // 目前選取的預約（列表單筆資料），沒有選取時為 null。
  row: { type: Object, default: null },
  busy: { type: Boolean, default: false },
});
const emit = defineEmits(['back', 'approve', 'reject']);
// 詳情只顯示第一位來訪者，同行的其他人以「另有 N 位」提示。
const visitor = computed(() => props.row?.guests?.[0] || {});
const extraCount = computed(() => Math.max((props.row?.guests?.length || 0) - 1, 0));
// 只有待審核的預約可以核准或拒絕。
const canReview = computed(() => Boolean(props.row) && PENDING_STATUSES.includes(props.row.reservation_status));
</script>
<style scoped>
.apd{
  display: flex;
  flex-direction: column;
  padding: 18px 20px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.apd__title{
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 14px;
  font-size: 16px;
  font-weight: 600;
  color: #1f2937;
}
.apd__title i,.apd__heading i{
  color: var(--el-color-primary);
}
.apd__empty{
  padding: 32px 8px;
  font-size: 14px;
  line-height: 1.7;
  color: #6b7280;
  text-align: center;
}
.apd__section{
  padding: 14px 0;
  border-top: 1px solid #f0f1f3;
}
.apd__heading{
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 10px;
  font-size: 15px;
  font-weight: 600;
  color: #1f2937;
}
.apd__list{
  flex: 1;
  min-width: 0;
  margin: 0;
}
.apd__item{
  display: flex;
  gap: 12px;
  padding: 4px 0;
  font-size: 14px;
}
.apd__item dt{
  flex-shrink: 0;
  width: 72px;
  color: #6b7280;
}
.apd__item dd{
  min-width: 0;
  margin: 0;
  color: #1f2937;
  word-break: break-all;
}
.apd__link{
  color: var(--el-color-primary);
}
.apd__more{
  margin: 6px 0 0;
  font-size: 13px;
  color: #6b7280;
}
.apd__remark{
  min-height: 56px;
  padding: 10px 12px;
  font-size: 14px;
  line-height: 1.6;
  color: #374151;
  background: #f5f7fb;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
}
.apd__footer{
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  padding-top: 14px;
  margin-top: auto;
  border-top: 1px solid #f0f1f3;
}
.apd__icon{
  margin-right: 6px;
}
</style>
