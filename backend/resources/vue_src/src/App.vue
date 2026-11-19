<template>
  <div id="site-app">
    <div v-if="siteConfig.loaded && siteConfig.siteStatus === '0'" class="maintenance">
      <img v-if="siteConfig.logo" :src="siteConfig.logo" alt="" class="site-brand__logo" />
      <h1>{{ siteConfig.siteName || 'WebBase' }}</h1>
      <p>站臺維護中，請稍後再訪問。</p>
    </div>

    <template v-else>
      <!-- 見 README 17.4：頁首左上角固定顯示網站 Logo（設定項 logo，後台
           「網站Logo」欄位上傳，規格 300×200 透明 PNG）與站名。未上傳 Logo
           時只顯示站名文字，不會出現破圖。 -->
      <header class="site-header">
        <router-link :to="{ path: '/' }" class="site-brand">
          <img v-if="siteConfig.logo" :src="siteConfig.logo" alt="" class="site-brand__logo" />
          <span class="site-brand__name">{{ siteConfig.siteName || 'WebBase' }}</span>
        </router-link>

        <nav v-if="topMenu.length" class="site-header__nav">
          <router-link v-for="item in topMenu" :key="item.key" :to="item.path">{{ item.title }}</router-link>
        </nav>
      </header>

      <router-view />
    </template>

    <!-- 見 README 17.4：頁尾文案（設定項 footer_text）固定顯示在頁面下方。 -->
    <footer v-if="siteConfig.footerText" class="site-footer">{{ siteConfig.footerText }}</footer>
  </div>
</template>

<script setup>
/* *****************************************
File: App.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 前台 SPA 根元件，客製化子站的 site-overrides 不覆寫這一層，見 README 8.3/8.4。
套用 site_status（17.3 字典示範）：維護中時前台一律顯示維護頁，不再渲染
實際路由頁面；管理後台（另一套獨立 SPA）不受影響，管理員仍可正常登入
把狀態改回來，不會因為維護中反而連自己都進不去。
網站 Logo（17.4、17.5）放頁首左上角、頁尾文案放頁面下方，兩者都來自
GET /api/site/config，未設定時整個區塊不渲染。
***************************************** */

import { computed } from 'vue';
import { siteConfig } from './stores/site';

// 頁首導覽只放第一層（有子選單的節點交給各頁面自己呈現，這裡純粹是
// 品牌區旁的簡易導覽，不重做一套選單樹）。
const topMenu = computed(() => (siteConfig.menu || []).filter((item) => item.path));
</script>

<style scoped>
#site-app {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.site-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 10px 20px;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
}

.site-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
  color: inherit;
}

/* 設定項規格是 300×200，這裡固定高度、寬度讓它依比例自適應，維持左上角
   版面穩定（不同 Logo 不會把頁首撐高或壓扁）。 */
.site-brand__logo {
  height: 36px;
  width: auto;
  max-width: 160px;
  object-fit: contain;
  display: block;
}

.site-brand__name {
  font-size: 17px;
  font-weight: 600;
  color: #1f2937;
}

.site-header__nav {
  display: flex;
  gap: 16px;
  font-size: 14px;
}

.site-header__nav a {
  color: #4b5563;
  text-decoration: none;
}

.site-header__nav a:hover {
  color: var(--theme-color, #1677ff);
}

.maintenance {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 60vh;
  text-align: center;
  color: #606266;
}

.site-footer {
  margin-top: auto;
  text-align: center;
  color: #909399;
  font-size: 13px;
  padding: 16px;
  border-top: 1px solid #f0f0f0;
}

/* 見 17.4：主色（theme_color）套用為 --theme-color CSS 變數，這裡只負責
   用它上色；未設定時 fallback 本專案預設色。 */
</style>

