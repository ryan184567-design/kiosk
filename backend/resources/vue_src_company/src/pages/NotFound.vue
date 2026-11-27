<template>
  <div class="not-found">
    <h1>404</h1>
    <p>找不到這個頁面。</p>
    <el-button type="primary" @click="$router.push({ name: 'home' })">回首頁</el-button>
  </div>
</template>

<script setup>
/* *****************************************
File: NotFound.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 公司端口 404 頁面。
***************************************** */
</script>

<style scoped>
.not-found {
  text-align: center;
  padding-top: 80px;
  color: #6b7280;
}

.not-found h1 {
  font-size: 3rem;
  margin-bottom: 8px;
  color: #1f2937;
}
</style>
