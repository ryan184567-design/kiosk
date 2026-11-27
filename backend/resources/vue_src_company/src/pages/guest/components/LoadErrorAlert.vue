<template>
  <el-alert class="lea" type="error" show-icon :closable="false">
    <template #title>
      <span>{{ message }}</span>
      <el-button class="lea__retry" link type="primary" @click="emit('retry')">重試</el-button>
    </template>
  </el-alert>
</template>

<script setup>
/* *****************************************
File: LoadErrorAlert.vue
Author: Callum
Date: 2026-11-25
Description: 資料載入失敗的紅色提示列，附「重試」按鈕；訪客管理各頁共用（class 會套用到最外層，頁面可自己調整間距）。
***************************************** */
defineProps({
  message: { type: String, required: true },
});

// 按「重試」時通知頁面重新載入，實際要重載哪些資料由頁面決定。
const emit = defineEmits(['retry']);
</script>

<style scoped>
.lea__retry {
  margin-left: 12px;
}
</style>
