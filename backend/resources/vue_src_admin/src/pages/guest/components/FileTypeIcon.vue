<template>
  <svg class="file-icon" viewBox="0 0 24 24" aria-hidden="true">
    <template v-if="type === 'excel'">
      <rect x="9" y="3.5" width="14" height="17" rx="1.5" fill="#22a83a" />
      <path d="M15.5 6.5h2.5v2h-2.5zM19 6.5h2.5v2H19zM15.5 11h2.5v2h-2.5zM19 11h2.5v2H19zM15.5 15.5h2.5v2h-2.5zM19 15.5h2.5v2H19z" fill="#fff" />
      <rect x="1" y="2" width="13.5" height="20" rx="1.5" fill="#0b7d22" />
      <path d="M4.8 8l6 8M10.8 8l-6 8" stroke="#fff" stroke-width="2.4" />
    </template>
    <template v-else>
      <path d="M4.5 2H15l6 6v12a2 2 0 0 1-2 2H4.5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z" fill="#ec2a2a" />
      <path d="M15 2l6 6h-4.5A1.5 1.5 0 0 1 15 6.500z" fill="#fff" fill-opacity="0.6" />
      <path d="M6.500 18c2.300-1.300 4.300-5 5-8.300.400-1.900.100-3.200-.700-3.200s-1 1.600 0 3.900c1.200 2.700 3.600 4.800 5.600 5 1.700.200 1.700-1.500-.300-1.700-2.800-.300-7 1-9.600 4.300z" fill="none" stroke="#fff" stroke-width="1.4" stroke-linejoin="round" />
    </template>
  </svg>
</template>
<script setup>
/* *****************************************
File: FileTypeIcon.vue
Author: Callum
Date: 2026-11-27
Description: 匯出按鈕用的彩色檔案類型圖示（依設計稿繪製）。
***************************************** */
defineProps({
  // excel 或 pdf。
  type: { type: String, required: true },
});
</script>
<style scoped>
.file-icon{
  width: 18px;
  height: 18px;
}
</style>
