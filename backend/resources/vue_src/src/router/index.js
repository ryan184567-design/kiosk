/* *****************************************
File: index.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: Vue Router 設定，含 Tier 2 動態掛載客製化路由，見 README 8.3。
***************************************** */

import { createRouter, createWebHistory } from 'vue-router';

// 見 README 6.1、8.2：子站用網址路徑前綴進站時（例如 code=vendora 對應
// /vendora/...），Vue Router 要知道自己「掛在哪個 base 底下」才能正確比對
// 路徑，不能直接拿網址片段當路由參數猜——之前用 `/:sitePrefix?` 這種選填
// 動態參數的寫法，已實測踩過坑：`/:sitePrefix?` 對單一路徑片段（例如打錯字
// 的 `/foo`）一樣會比對成功（把 foo 當成 sitePrefix、落地頁仍是首頁），
// 導致真正找不到的頁面完全不會落到 404 路由，網址打錯字也只會看到首頁。
// 正確作法：先呼叫 stores/site.js 的 loadSiteConfig() 問後端這次請求真正
// 解析到哪個站點（site_code），跟網址第一段比對相符才算是路徑前綴進站，
// 用這個結果決定 createWebHistory 的 base，路由本身完全不用處理前綴。
const routes = [
  { path: '/', name: 'home', component: () => import('../pages/Home.vue') },
  // 404 一律放最後：Vue Router 4 對「靜態片段」的比對優先權本來就高於
  // 這種萬用字元路徑，不因為註冊順序在前面就搶先比對到，日後 Tier 2
  // 用 registerCustomRoutes() 動態掛載的頁面一樣會正確優先於這條。
  { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../pages/NotFound.vue') },
];

export function createAppRouter(base) {
  return createRouter({
    history: createWebHistory(base),
    routes,
  });
}

// 見 README 8.3：Tier 2 共用建置＋動態掛載。依 site.config 回傳的
// custom_routes 清單，於啟動時 router.addRoute() 動態掛載，每筆對應一個
// import() 動態載入的 chunk，未被路由到的站點不會載入這些 chunk。路徑相對
// 於 router 自己的 base，不用再處理前綴。
export function registerCustomRoutes(router, customRoutes = []) {
  for (const route of customRoutes) {
    router.addRoute({
      path: route.path,
      name: route.name ?? route.path,
      component: () => import(/* @vite-ignore */ route.component),
    });
  }
}
