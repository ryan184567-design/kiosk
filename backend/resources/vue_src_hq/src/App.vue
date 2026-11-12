<template>
  <div class="hq-app">
    <header v-if="auth.token" class="hq-header">
      <span class="hq-header__title">WebBase 主站管理</span>
      <span class="hq-header__user">{{ auth.name }}（{{ auth.username }}）</span>
      <button class="hq-header__logout" @click="handleLogout">登出</button>
    </header>
    <main class="hq-main">
      <router-view />
    </main>
  </div>
</template>

<script setup>
/* *****************************************
File: App.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站後台 SPA 根元件，見 README 6.12。
***************************************** */

import { useRouter } from 'vue-router';
import { auth, logout } from './stores/auth';

const router = useRouter();

async function handleLogout() {
  await logout();
  router.push({ name: 'login' });
}
</script>

<style>
body {
  margin: 0;
  font-family: system-ui, -apple-system, 'PingFang TC', 'Microsoft JhengHei', sans-serif;
  background: #f5f6f8;
}
</style>

<style scoped>
.hq-header {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 12px 24px;
  background: #1f2937;
  color: #fff;
}

.hq-header__title {
  font-weight: 600;
}

.hq-header__user {
  margin-left: auto;
  opacity: 0.85;
}

.hq-header__logout {
  border: 1px solid rgba(255, 255, 255, 0.4);
  background: transparent;
  color: #fff;
  padding: 4px 12px;
  border-radius: 4px;
  cursor: pointer;
}

.hq-main {
  padding: 24px;
  max-width: 960px;
  margin: 0 auto;
}
</style>
