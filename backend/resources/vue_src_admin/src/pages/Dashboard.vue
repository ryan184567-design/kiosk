<template>
  <div class="dashboard">
    <div class="dashboard__welcome">
      <h2>哈囉，{{ auth.name || auth.username }}</h2>
      <p>這是管理後台首頁，下面是常用功能的捷徑，實際看得到哪些項目依你目前的權限而定。</p>
    </div>

    <div class="dashboard__grid">
      <el-card v-for="item in shortcuts" :key="item.name" shadow="hover" class="dashboard__card" @click="router.push({ name: item.name })">
        <div class="dashboard__card-body">
          <el-icon class="dashboard__card-icon"><component :is="item.icon" /></el-icon>
          <div>
            <div class="dashboard__card-title">{{ item.title }}</div>
            <div class="dashboard__card-subtitle">{{ item.subtitle }}</div>
          </div>
        </div>
      </el-card>
    </div>
  </div>
</template>

<script setup>
/* *****************************************
File: Dashboard.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 登入後首頁，見 README 7.3、9.1。取代原本「登入後直接落在
角色管理頁」的寫死行為，也取代早期示範用、跟 Vue SPA 完全無關的
app/Web/Admin/Dashboard 示範模組（已移除）。只做導覽用途的捷徑卡片，
不放假的統計數字——這裡沒有哪個模塊天生就該被統計，硬塞數字只是造假
資料。哪些捷徑實際看得到，交給側邊欄同一套權限邏輯決定（這裡不重複
判斷一次，卡片本身沒有權限檢查，但點進去的目標頁面本來就會照樣受
MenuService／各自 API 的權限檢查保護，不會因為捷徑列出來就代表點得
進去）。
***************************************** */

import { useRouter } from 'vue-router';
import {
  Connection, List, Lock, Menu, Switch, Tools, User,
} from '@element-plus/icons-vue';
import { auth } from '../stores/auth';

const router = useRouter();

const shortcuts = [
  { name: 'users', title: '使用者管理', subtitle: '帳號建立與端口授權', icon: User },
  { name: 'roles', title: '角色管理', subtitle: '角色與權限碼指派', icon: Lock },
  { name: 'menus', title: '選單管理', subtitle: '側邊欄選單維護', icon: Menu },
  { name: 'dictionaries', title: '資料字典', subtitle: '可管理的下拉選項', icon: List },
  { name: 'system-config', title: '系統環境配置', subtitle: '頁面設置等站台參數', icon: Tools },
  { name: 'plugins', title: '插件管理', subtitle: 'AD／SSO 等對接插件', icon: Connection },
  { name: 'portals', title: '端口管理', subtitle: '登入端口與訪客瀏覽設定', icon: Switch },
];
</script>

<style scoped>
.dashboard__welcome {
  margin-bottom: 24px;
}

.dashboard__welcome h2 {
  margin: 0 0 8px;
}

.dashboard__welcome p {
  margin: 0;
  color: var(--el-text-color-secondary);
}

.dashboard__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 16px;
}

.dashboard__card {
  cursor: pointer;
}

.dashboard__card-body {
  display: flex;
  align-items: center;
  gap: 12px;
}

.dashboard__card-icon {
  font-size: 28px;
  color: var(--el-color-primary);
  flex-shrink: 0;
}

.dashboard__card-title {
  font-weight: 600;
}

.dashboard__card-subtitle {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
</style>
