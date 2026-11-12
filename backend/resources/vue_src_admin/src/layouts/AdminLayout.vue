<template>
  <el-container class="layout">
    <el-aside width="220px" class="layout__sidebar">
      <div class="layout__logo">
        <img v-if="siteBranding.logo" :src="siteBranding.logo" class="layout__logo-img" alt="" />
        <span>{{ siteBranding.siteName || 'WebBase' }} 管理後台</span>
      </div>
      <el-menu
        v-if="sidebarMenu.length"
        :default-active="activeMenu"
        :default-openeds="defaultOpeneds"
        background-color="#001529"
        text-color="#c9d1d9"
        active-text-color="#ffffff"
        @select="handleMenuSelect"
      >
        <template v-for="item in sidebarMenu" :key="item.key">
          <el-sub-menu v-if="item.children && item.children.length" :index="item.key">
            <template #title>
              <el-icon v-if="iconComponent(item.icon)"><component :is="iconComponent(item.icon)" /></el-icon>
              <el-tooltip v-if="item.subtitle" :content="item.subtitle" placement="right" :show-after="300">
                <span>{{ item.title }}</span>
              </el-tooltip>
              <span v-else>{{ item.title }}</span>
            </template>
            <el-tooltip v-for="child in item.children" :key="child.key" :content="child.subtitle" :disabled="!child.subtitle" placement="right" :show-after="300">
              <el-menu-item :index="child.path">
                <el-icon v-if="iconComponent(child.icon)"><component :is="iconComponent(child.icon)" /></el-icon>
                <span>{{ child.title }}</span>
              </el-menu-item>
            </el-tooltip>
          </el-sub-menu>
          <el-tooltip v-else :content="item.subtitle" :disabled="!item.subtitle" placement="right" :show-after="300">
            <el-menu-item :index="item.path">
              <el-icon v-if="iconComponent(item.icon)"><component :is="iconComponent(item.icon)" /></el-icon>
              <span>{{ item.title }}</span>
            </el-menu-item>
          </el-tooltip>
        </template>
      </el-menu>
    </el-aside>
    <el-container>
      <!-- 見 README 6.11 第 4 點：透過主站跳轉登入時顯示提示列，避免操作人員
           忘記自己正以「該站超級管理員」的最高權限在動作。issued_via 由
           GET /api/user/profile 取得（見 stores/auth.js）。 -->
      <el-alert
        v-if="auth.issuedVia === 'hq_impersonation'"
        class="layout__impersonation"
        type="warning"
        :closable="false"
        show-icon
        title="目前透過主站跳轉，以本機的超級管理員身份操作，請留意操作影響範圍。"
      />
      <el-header class="layout__header">
        <span class="layout__title">{{ pageTitle }}</span>
        <el-dropdown>
          <span class="layout__user">
            {{ auth.name || auth.username }}
            <el-icon><ArrowDown /></el-icon>
          </span>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item @click="router.push({ name: 'profile' })">個人資料</el-dropdown-item>
              <el-dropdown-item @click="handleLogout">登出</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </el-header>
      <el-main class="layout__main">
        <router-view />
      </el-main>
      <!-- 見 README 17.4：頁尾文案（設定項 footer_text）在管理後台也顯示，
           跟前台用同一份設定，避免兩邊文案不一致。 -->
      <el-footer v-if="siteBranding.footerText" class="layout__footer">{{ siteBranding.footerText }}</el-footer>
    </el-container>
  </el-container>
</template>

<script setup>
/* *****************************************
File: AdminLayout.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 管理後台版面（側邊欄＋頂欄），見 README 7.2、7.3。
側邊欄改成從選單管理（menus 表，portal_key=admin）動態讀出，不再是這裡
寫死的固定清單——跟前台 SPA 共用同一支 GET /api/site/config、同一套
MenuService 權限過濾邏輯（見 App\Support\Menu\MenuService），使用者實際
持有哪些權限就看到哪些節點，不會出現「側邊欄有、點進去卻 403」的落差。
***************************************** */

import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowDown } from '@element-plus/icons-vue';
import * as Icons from '@element-plus/icons-vue';
import { auth, authFetch, loadProfile, logout, siteBranding } from '../stores/auth';

const route = useRoute();
const router = useRouter();
const sidebarMenu = ref([]);

// 選單 icon 欄位存的 Element Plus 圖示名稱（選單管理頁的圖示下拉與這裡
// 共用同一個套件）；名稱不存在或未填回 null，模板不渲染 icon。
function iconComponent(name) {
  return name && Icons[name] ? Icons[name] : null;
}

// 見 stores/auth.js 的 authFetch：401 時只會清空本地 token（clearSession），
// 不會主動導頁——頁面各自呼叫 API 的時機不同，交給這裡統一監看 token
// 狀態，一旦變成 null 就導回登入頁，不用每個頁面各自處理一次重複邏輯。
watch(
  () => auth.token,
  (token) => {
    if (!token) {
      router.push({ name: 'login' });
    }
  },
);

const activeMenu = computed(() => route.path);
const pageTitle = computed(() => (route.meta.title ? route.meta.title : String(route.name || '')));

// 分組選單預設全部展開——後台自己的功能項目不多，收合起來反而要多一次
// 點擊才看得到，不像前台可能選單很深才需要預設收合。
const defaultOpeneds = computed(() => sidebarMenu.value.filter((item) => item.children?.length).map((item) => item.key));

// key -> target 的對照表（含子節點），el-menu 拿到的 @select 只給 index
// （這裡固定用葉節點的 path 當 index），不知道這個節點的開啟方式，要另外
// 查表決定是 router.push 還是開新視窗。
const targetByPath = computed(() => {
  const map = {};

  for (const item of sidebarMenu.value) {
    if (item.path) map[item.path] = item.target;

    for (const child of item.children || []) {
      if (child.path) map[child.path] = child.target;
    }
  }

  return map;
});

function handleMenuSelect(index) {
  if (targetByPath.value[index] === '_blank') {
    // el-menu 沒有掛 router 屬性，改成這裡自己決定要 router.push 還是開
    // 新視窗；新視窗要補上 Vue Router 的 base（路徑前綴進站時的
    // /{site}/admin），不然開出來的分頁網址會少一截。
    window.open(router.options.history.base + index, '_blank');

    return;
  }

  router.push(index);
}

async function loadSidebarMenu() {
  const res = await authFetch('/api/site/config');
  const body = await res.json();

  if (body.status) {
    sidebarMenu.value = body.data.menu || [];
  }
}

async function handleLogout() {
  await logout();
  router.push({ name: 'login' });
}

// 見已知環境限制說明（README）：兩支呼叫刻意改成依序 await，不要同時併發
// 打——已實測觀察到本機環境偶發 config('jwt.site_secret') 被讀成空字串
// 導致 500（Key material must not be empty），併發請求會提高觸發機率，
// 依序呼叫可以降低機率（非本次功能修正範圍，屬於既有環境限制）。
onMounted(async () => {
  if (!auth.username) {
    await loadProfile();
  }

  await loadSidebarMenu();
});
</script>

<style scoped>
.layout {
  height: 100vh;
}

.layout__sidebar {
  background: #001529;
  display: flex;
  flex-direction: column;
}

.layout__logo {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #fff;
  font-size: 16px;
  font-weight: 600;
  padding: 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.layout__logo-img {
  width: 24px;
  height: 24px;
  object-fit: contain;
  flex-shrink: 0;
}

.layout__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
}

.layout__impersonation {
  border-radius: 0;
}

.layout__title {
  font-weight: 600;
  color: #1f2937;
}

.layout__user {
  display: flex;
  align-items: center;
  gap: 4px;
  cursor: pointer;
  color: #374151;
}

.layout__main {
  background: #f5f6f8;
}

.layout__footer {
  height: auto;
  padding: 12px 16px;
  text-align: center;
  font-size: 13px;
  color: #6b7280;
  background: #fff;
  border-top: 1px solid #e5e7eb;
}
</style>
