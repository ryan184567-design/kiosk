/* *****************************************
File: index.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站管理後台路由設定，含登入守衛，見 README 7.2。
***************************************** */
import { createRouter, createWebHistory } from 'vue-router';
import { isLoggedIn, portalConfig } from '../stores/auth';
// 見 main.js 開頭說明：路徑前綴（例如 /vendora/admin/...）跟一般找不到的
// 頁面沒有語法上的區別，Vue Router 沒辦法只靠路徑樣式猜出「第一段是站點
// 代碼還是打錯字」——已實測踩過坑：改用 `/:sitePrefix?/admin` 這種選填
// 動態參數會導致單一路徑片段的網址一律被猜成有前綴，真正找不到的頁面永遠
// 到不了 404 路由。正確作法是先問後端這次請求真正落在哪個站點，比對相符
// 才把前綴放進 Vue Router 的 base，路由定義本身完全不用處理前綴。
const routes = [
  { path: '/login', name: 'login', component: () => import('../pages/Login.vue') },
  {
    path: '/',
    component: () => import('../layouts/AdminLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '', redirect: { name: 'dashboard' } },
      { path: 'dashboard', name: 'dashboard', meta: { title: '儀表板' }, component: () => import('../pages/Dashboard.vue') },
      { path: 'roles', name: 'roles', meta: { title: '角色管理' }, component: () => import('../pages/roles/RoleList.vue') },
      { path: 'users', name: 'users', meta: { title: '使用者管理' }, component: () => import('../pages/user/UserList.vue') },
      { path: 'menus', name: 'menus', meta: { title: '選單管理' }, component: () => import('../pages/menu/MenuList.vue') },
      { path: 'system-config', name: 'system-config', meta: { title: '系統環境配置' }, component: () => import('../pages/system-config/SystemConfigList.vue') },
      { path: 'dictionaries', name: 'dictionaries', meta: { title: '資料字典' }, component: () => import('../pages/dictionary/DictionaryList.vue') },
      { path: 'plugins', name: 'plugins', meta: { title: '插件管理' }, component: () => import('../pages/plugin/PluginList.vue') },
      { path: 'portals', name: 'portals', meta: { title: '端口管理' }, component: () => import('../pages/portal/PortalList.vue') },
      { path: 'profile', name: 'profile', meta: { title: '個人資料' }, component: () => import('../pages/Profile.vue') },
      // 訪客管理模塊：路徑對應後端 app/Api/Guest/module.json 的選單 path。
      { path: 'guest/registration', name: 'guest-registration', meta: { title: '訪客登記' }, component: () => import('../pages/guest/VisitorRegistration.vue') },
      { path: 'guest/approvals', name: 'guest-approvals', meta: { title: '訪客審核' }, component: () => import('../pages/guest/ApprovalList.vue') },
      { path: 'guest/records', name: 'guest-records', meta: { title: '訪客記錄' }, component: () => import('../pages/guest/VisitRecords.vue') },
      { path: 'guest/reports', name: 'guest-reports', meta: { title: '統計報表' }, component: () => import('../pages/guest/StatisticsReport.vue') },
      { path: 'guest/applies', name: 'guest-applies', meta: { title: '預約管理' }, component: () => import('../pages/guest/ReservationList.vue') },
      { path: 'guest/slots', name: 'guest-slots', meta: { title: '證件櫃' }, component: () => import('../pages/guest/SlotList.vue') },
      { path: 'guest/upload', name: 'guest-upload', meta: { title: '上傳設定' }, component: () => import('../pages/guest/UploadSettings.vue') },
      { path: 'guest/companies', name: 'guest-companies', meta: { title: '公司帳號管理' }, component: () => import('../pages/guest/CompanyList.vue') },
      // 基本資料設定模塊：路徑對應後端 app/Api/DataSetting/module.json 的選單 path。
      { path: 'data-setting/building', name: 'data-setting-building', meta: { title: '棟別設定' }, component: () => import('../pages/data-setting/BuildingList.vue') },
      { path: 'data-setting/floor', name: 'data-setting-floor', meta: { title: '樓別設定' }, component: () => import('../pages/data-setting/FloorList.vue') },
      { path: 'data-setting/doorplate', name: 'data-setting-doorplate', meta: { title: '門牌設定' }, component: () => import('../pages/data-setting/DoorplateList.vue') },
      // 廠商模式（App Token）的 App 管理：全局共用，路徑對應後端 app/Api/Factory/module.json 的選單 path。
      { path: 'factory-apps', name: 'factory-apps', meta: { title: '廠商 App 管理' }, component: () => import('../pages/factory/FactoryAppList.vue') },
      { path: 'factory-logs', name: 'factory-logs', meta: { title: '廠商 App 記錄' }, component: () => import('../pages/factory/FactoryLogList.vue') },
      // 404 放最後，見 resources/vue_src/src/router/index.js 同一段說明：
      // 找不到頁面要顯示明確的 404，不能讓 <router-view> 悄悄渲染空白。
      { path: ':pathMatch(.*)*', name: 'admin-not-found', meta: { title: '找不到頁面' }, component: () => import('../pages/NotFound.vue') },
    ],
  },
];
export function createAppRouter(base){
  const router = createRouter({
    history: createWebHistory(base),
    routes,
  });
  // 見 6.12（主站後台）同一套守衛邏輯：未登入一律導回登入頁；已登入卻還想
  // 進登入頁則導去預設頁面，不多此一舉再登入一次。
  // 見 README 9.1：端口可以設定 requires_login=false（訪客不登入直接
  // 瀏覽），portalConfig 是 main.js 掛載 App 前就先抓好的值（見
  // stores/auth.js::fetchPortalConfig），這裡只是讀取，不會在 guard 內
  // 才臨時發請求。requires_login=false 時完全不擋未登入訪問，頁面內部
  // 若呼叫到需要登入的 API，會照舊由 authFetch 收到 401 清空 session。
  router.beforeEach((to) => {
    if(to.meta.requiresAuth && !isLoggedIn() && portalConfig.requiresLogin){
      return { name: 'login' };
    }
    if(to.name === 'login' && isLoggedIn()){
      return { name: 'dashboard' };
    }
    return true;
  });
  return router;
}
