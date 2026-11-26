<template>
  <section class="rcard">
    <header class="rcard__head">
      <div class="rcard__heading">
        <h3 class="rcard__title">{{ title }}</h3>
        <span v-if="hint" class="rcard__hint">{{ hint }}</span>
      </div>
      <div v-if="$slots.actions" class="rcard__actions"><slot name="actions" /></div>
    </header>
    <slot />
  </section>
</template>
<script setup>
/* *****************************************
File: ReportCard.vue
Author: Callum
Date: 2026-11-26
Description: 統計報表的白色區塊：左側藍色直條的標題、標題旁的灰色說明、右側操作區（插槽）。外觀同「訪客統計總覽」的區塊。
***************************************** */
defineProps({
  title: { type: String, required: true },
  // 標題旁的小字說明，例如「平均每日人次」。
  hint: { type: String, default: '' },
});
</script>
<style scoped>
.rcard{
  min-width: 0;
  padding: 16px;
  background: #fff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.rcard__head{
  display: flex;
  flex-wrap: wrap;
  gap: 8px 12px;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.rcard__heading{
  display: flex;
  flex-wrap: wrap;
  gap: 4px 10px;
  align-items: baseline;
  min-width: 0;
}
.rcard__title{
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: #1f2937;
}
/* 標題左側的藍色直條，與「訪客統計總覽」一致。 */
.rcard__title::before{
  width: 4px;
  height: 16px;
  content: '';
  background: var(--el-color-primary);
  border-radius: 2px;
}
.rcard__hint{
  font-size: 13px;
  color: #6b7280;
}
.rcard__actions{
  display: flex;
  flex-wrap: wrap;
  gap: 8px 14px;
  align-items: center;
}
</style>
