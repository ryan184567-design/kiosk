<template>
  <div class="home">
    <img v-if="siteConfig.logo" :src="siteConfig.logo" alt="" class="home__logo" />
    <h1>{{ siteConfig.siteName || 'WebBase' }}</h1>
    <p>前台 Vue3 SPA 骨架，見 README 3.2、8 節。</p>
  </div>
</template>

<script setup>
/* *****************************************
File: Home.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 前台 SPA 首頁骨架頁面，見 README 3.2、8 節。
Logo（17.4、17.5）規格是 300×200 透明 PNG，這裡不寫死尺寸、只限制最大寬度，
讓不同比例的圖都不會破版。
***************************************** */

import { siteConfig } from '../stores/site';
</script>

<style scoped>
.home {
  text-align: center;
  padding: 40px 20px;
}

.home__logo {
  max-width: 300px;
  width: 100%;
  height: auto;
  margin-bottom: 16px;
}
</style>

