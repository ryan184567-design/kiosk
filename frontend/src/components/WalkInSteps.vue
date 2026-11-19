<script setup>
// 無預約流程步驟指示（選擇樓層 → 選擇公司 → 填寫資料 → 完成通知）
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

defineProps({
  /** 目前步驟（從 0 開始） */
  current: { type: Number, default: 0 },
})

const { t } = useI18n()
const items = computed(() =>
  ['floor', 'company', 'info', 'done'].map((k) => ({ title: t(`walkIn.steps.${k}`) })),
)
</script>

<template>
  <a-steps :current="current" :items="items" label-placement="vertical" class="walkin-steps" />
</template>

<style scoped>
.walkin-steps {
  width: min(720px, 100%);
  margin: 0 auto 24px;
}
.walkin-steps :deep(.ant-steps-item-title) {
  font-size: 16px;
}
</style>
