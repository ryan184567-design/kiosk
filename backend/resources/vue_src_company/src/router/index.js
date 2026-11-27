/* *****************************************
File: index.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 公司端口（company）路由設定，含登入守衛；由管理後台 router/index.js 複製精簡。
***************************************** */
import { createRouter, createWebHistory } from 'vue-router';
import { isLoggedIn, portalConfig } from '../stores/auth';
// 路徑前綴（/{site}/company/...）的判斷方式與管理後台相同，見 main.js 的 resolveBase。
// 頁面路徑對應後端 app/Api/Guest/module.json 裡 portal=company 的選單 path；
// 側邊欄由後端依角色權限過濾（一般員工看不到審核、員工管理、審核設定）。
const routes = [
  { path: '/login', name: 'login', component: () => import('../pages/Login.vue') },
  {
    path: '/',
    component: () => import('../layouts/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      // 公司端口沒有儀表板，首頁就是預約管理（每個身分都有權限）。
      { path: '', name: 'home', redirect: { name: 'guest-applies' } },
      { path: 'guest/applies', name: 'guest-applies', meta: { title: '預約管理' }, component: () => import('../pages/guest/ReservationList.vue') },
      { path: 'guest/approvals', name: 'guest-approvals', meta: { title: '訪客審核' }, component: () => import('../pages/guest/ApprovalList.vue') },
      { path: 'guest/records', name: 'guest-records', meta: { title: '訪客紀錄' }, component: () => import('../pages/guest/VisitRecords.vue') },
      { path: 'company/employees', name: 'company-employees', meta: { title: '員工管理' }, component: () => import('../pages/company/EmployeeList.vue') },
      { path: 'company/setting', name: 'company-setting', meta: { title: '審核設定' }, component: () => import('../pages/company/CompanySetting.vue') },
      { path: 'profile', name: 'profile', meta: { title: '個人資料' }, component: () => import('../pages/Profile.vue') },
      // 404 放最後：找不到頁面要顯示明確的 404，不能讓 <router-view> 悄悄渲染空白。
      { path: ':pathMatch(.*)*', name: 'company-not-found', meta: { title: '找不到頁面' }, component: () => import('../pages/NotFound.vue') },
    ],
  },
];
export function createAppRouter(base){
  const router = createRouter({
    history: createWebHistory(base),
    routes,
  });
  // 未登入一律導回登入頁；已登入卻還想進登入頁則導去首頁。
  router.beforeEach((to) => {
    if(to.meta.requiresAuth && !isLoggedIn() && portalConfig.requiresLogin){
      return { name: 'login' };
    }
    if(to.name === 'login' && isLoggedIn()){
      return { name: 'home' };
    }
    return true;
  });
  return router;
}
