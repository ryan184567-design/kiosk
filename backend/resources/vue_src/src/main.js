/* *****************************************
File: main.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 前台 SPA 進入點：先 fetch 執行期站點設定再掛載 app，見 README 8.2。
***************************************** */

import { createApp } from 'vue';
import App from './App.vue';
import { createAppRouter, registerCustomRoutes } from './router';
import { loadSiteConfig } from './stores/site';

// 見 router/index.js 開頭說明：只有網址第一段真的等於後端解析出的
// site_code，才算是路徑前綴進站，router 的 base 才要帶上這段；網域已綁定
// 的子站、或前綴打錯字（後端回退預設站、site_code 對不上網址片段），
// base 一律是 `/`，讓打錯字的網址落入 not-found 路由，不是被誤判成首頁。
function resolveBase(siteCode) {
  const first = window.location.pathname.split('/').filter(Boolean)[0];

  return first && siteCode && first === siteCode ? `/${first}/` : '/';
}

// 見 README 8.2：前端啟動時先 fetch 執行期站點設定，再掛載 app，
// 確保第一次渲染就有正確的主題色/選單/文案，不會有一瞬間的預設樣式閃爍。
loadSiteConfig()
  .then((config) => {
    const router = createAppRouter(resolveBase(config.code));
    registerCustomRoutes(router, config.customRoutes);
    createApp(App).use(router).mount('#app');
  })
  .catch(() => {
    // 設定載入失敗不阻擋整個 app 啟動，僅維持預設樣式與 base '/'。
    createApp(App).use(createAppRouter('/')).mount('#app');
  });
