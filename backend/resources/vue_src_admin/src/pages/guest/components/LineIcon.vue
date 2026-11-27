<template>
  <svg class="line-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <template v-if="name === 'users'">
      <circle cx="9" cy="7" r="4" />
      <path d="M2 21v-1a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v1z" />
      <path d="M16 3.5a4 4 0 0 1 0 7" />
      <path d="M18.5 15.5A5 5 0 0 1 22 20.3v.7" />
    </template>
    <template v-else-if="name === 'calendar'">
      <rect x="3" y="4.5" width="18" height="17" rx="2" />
      <path d="M8 2.5v4M16 2.5v4M3 10h18" />
      <path d="M8 14v3h5M16 14.5v.5" />
    </template>
    <template v-else-if="name === 'buildings'">
      <path d="M4 21V3h11v18M15 9.5h5V21M2.5 21h19" />
      <path d="M7.5 7h4M7.5 10.5h4M7.5 14h4M7.5 17.5h4M17.5 13.5v.5M17.5 17v.5" />
    </template>
    <template v-else-if="name === 'id-card'">
      <rect x="2" y="4" width="20" height="16" rx="1.5" />
      <circle cx="8" cy="9.5" r="2" />
      <path d="M5 16a3 2.5 0 0 1 6 0zM14 9h4.5M14 12.5h3.5M14 16h3.5" />
    </template>
  </svg>
</template>
<script setup>
/* *****************************************
File: LineIcon.vue
Author: Callum
Date: 2026-11-27
Description: 統計報表卡片用的線條圖示（依設計稿繪製；Font Awesome Free 沒有對應的線條版）。
***************************************** */
defineProps({
  // users、calendar、buildings、id-card。
  name: { type: String, required: true },
});
</script>
<style scoped>
.line-icon{
  width: 1.5em;
  height: 1.5em;
}
</style>
