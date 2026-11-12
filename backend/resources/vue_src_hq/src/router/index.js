/* *****************************************
File: index.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站後台路由設定，含登入守衛，見 README 6.12。
***************************************** */

import { createRouter, createWebHistory } from 'vue-router';
import { isLoggedIn } from '../stores/auth';

const routes = [
  { path: '/hq/login', name: 'login', component: () => import('../pages/Login.vue') },
  { path: '/hq', name: 'sites', component: () => import('../pages/Sites.vue'), meta: { requiresAuth: true } },
  { path: '/hq/sites/:id', name: 'site-detail', component: () => import('../pages/SiteDetail.vue'), meta: { requiresAuth: true }, props: true },
  // 404 放最後，見 resources/vue_src/src/router/index.js 同一段說明：
  // 找不到頁面要顯示明確的 404，不能讓 <router-view> 悄悄渲染空白。
  { path: '/hq/:pathMatch(.*)*', name: 'not-found', component: () => import('../pages/NotFound.vue') },
];

export const router = createRouter({
  history: createWebHistory(),
  routes,
});

// 見 6.12：所有站點管理功能都要求先登入，未登入一律導回登入頁；已登入
// 卻還想進登入頁（例如手動打網址）則導去站點列表，不多此一舉再登入一次。
router.beforeEach((to) => {
  if (to.meta.requiresAuth && !isLoggedIn()) {
    return { name: 'login' };
  }

  if (to.name === 'login' && isLoggedIn()) {
    return { name: 'sites' };
  }

  return true;
});
