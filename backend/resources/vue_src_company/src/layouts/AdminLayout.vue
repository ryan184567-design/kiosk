<template>
  <div class="layout">
    <aside class="layout__sidebar" :class="{ 'layout__sidebar--collapsed': collapsed }">
      <div class="layout__logo">
        <img v-if="siteBranding.logo" :src="siteBranding.logo" class="layout__logo-img" alt="" />
        <span v-else class="layout__logo-mark"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
        <span v-show="!collapsed" class="layout__logo-text">{{ brandName }}</span>
      </div>
      <el-menu
        v-if="sidebarMenu.length"
        ref="menuRef"
        class="layout__menu"
        :default-active="activeMenu"
        :default-openeds="defaultOpeneds"
        :collapse="collapsed"
        :collapse-transition="false"
        @select="handleMenuSelect"
      >
        <template v-for="item in sidebarMenu" :key="item.key">
          <el-sub-menu v-if="item.children && item.children.length" :index="item.key">
            <template #title>
              <i v-if="menuIconClass(item)" :class="['layout__menu-icon', menuIconClass(item)]" aria-hidden="true"></i>
              <el-tooltip v-if="item.subtitle" :content="item.subtitle" placement="right" :show-after="300">
                <span>{{ item.title }}</span>
              </el-tooltip>
              <span v-else>{{ item.title }}</span>
              <!-- 分類收合時，裡面的「訪客審核」有待審核就在分類標題旁放個紅點，提醒展開來看；展開後改由子項目的數字顯示。 -->
              <i v-if="pendingApprovals > 0 && hasApprovalsChild(item)" class="layout__group-dot" aria-hidden="true"></i>
            </template>
            <el-tooltip v-for="child in item.children" :key="child.key" :content="child.subtitle" :disabled="!child.subtitle" placement="right" :show-after="300">
              <el-menu-item :index="child.path">
                <span>{{ child.title }}</span>
                <span v-if="child.path === APPROVALS_PATH && pendingApprovals > 0" class="layout__menu-badge" :aria-label="`${pendingApprovals} 筆待審核`">{{ pendingBadge }}</span>
              </el-menu-item>
            </el-tooltip>
          </el-sub-menu>
          <el-tooltip v-else :content="item.subtitle" :disabled="!item.subtitle" placement="right" :show-after="300">
            <el-menu-item :index="item.path">
              <i v-if="menuIconClass(item)" :class="['layout__menu-icon', menuIconClass(item)]" aria-hidden="true"></i>
              <span>{{ item.title }}</span>
              <!-- 公司端口的選單沒有分組，「訪客審核」在第一層，待審核數字直接掛在這裡。 -->
              <span v-if="item.path === APPROVALS_PATH && pendingApprovals > 0" class="layout__menu-badge" :aria-label="`${pendingApprovals} 筆待審核`">{{ pendingBadge }}</span>
            </el-menu-item>
          </el-tooltip>
        </template>
      </el-menu>
    </aside>
    <div class="layout__body">
      <!-- 見 README 6.11 第 4 點：透過主站跳轉登入時顯示提示列，避免操作人員
           忘記自己正以「該站超級管理員」的最高權限在動作。issued_via 由
           GET /api/user/profile 取得(見 stores/auth.js)。 -->
      <el-alert
        v-if="auth.issuedVia === 'hq_impersonation'"
        class="layout__impersonation"
        type="warning"
        :closable="false"
        show-icon
        title="目前透過主站跳轉，以本機的超級管理員身份操作，請留意操作影響範圍。"
      />
      <header class="layout__header">
        <button type="button" class="layout__icon-btn" aria-label="收合或展開側邊欄" :aria-expanded="!collapsed" @click="toggleCollapsed">
          <i class="fa-solid fa-bars" aria-hidden="true"></i>
        </button>
        <div class="layout__header-right">
          <!-- 目前登入的公司與身分（資料範圍只限這家公司）。 -->
          <span v-if="company.companyName" class="layout__company">
            <i class="fa-solid fa-building" aria-hidden="true"></i>
            {{ companyLabel() }}
            <el-tag v-if="company.memberType" size="small" effect="plain">{{ MEMBER_TYPE_LABEL[company.memberType] }}</el-tag>
          </span>
          <!-- 通知鈴鐺：紅色數字是「待審核預約」的筆數，點了展開最新幾筆待審核（見 PendingApprovalsBell.vue）。 -->
          <PendingApprovalsBell :enabled="hasApprovals" :approvals-path="APPROVALS_PATH" />
          <el-dropdown trigger="click">
            <button type="button" class="layout__user">
              <span class="layout__avatar"><i class="fa-regular fa-user" aria-hidden="true"></i></span>
              <span class="layout__user-name">{{ auth.name || auth.username }}</span>
              <i class="fa-solid fa-chevron-down layout__user-arrow" aria-hidden="true"></i>
            </button>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item @click="router.push({ name: 'profile' })">個人資料</el-dropdown-item>
                <el-dropdown-item @click="handleLogout">登出</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </div>
      </header>
      <main class="layout__main">
        <div class="layout__heading">
          <h1 class="layout__title">{{ pageTitle }}</h1>
          <nav v-if="activeGroup" class="layout__crumbs" aria-label="麵包屑">
            <span>{{ activeGroup.title }}</span>
            <i class="fa-solid fa-chevron-right layout__crumb-sep" aria-hidden="true"></i>
            <span>{{ pageTitle }}</span>
          </nav>
        </div>
        <router-view />
      </main>
      <!-- 見 README 17.4：頁尾文案（設定項 footer_text）在管理後臺也顯示，
           跟前臺用同一份設定，避免兩邊文案不一致。 -->
      <footer v-if="siteBranding.footerText" class="layout__footer">{{ siteBranding.footerText }}</footer>
    </div>
  </div>
</template>
<script setup>
/* *****************************************
File: AdminLayout.vue
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 公司端口版面（側邊欄＋頂欄）；2026-11-27 由管理後臺 AdminLayout.vue 複製，頂欄改顯示目前公司與身分。
側邊欄改成從選單管理(menus 表，portal_key=company)動態讀出，不再是這裡
寫死的固定清單——跟前臺 SPA 共用同一支 GET /api/site/config、同一套
MenuService 權限過濾邏輯(見 App\Support\Menu\MenuService)，使用者實際
持有哪些權限就看到哪些節點，不會出現「側邊欄有、點進去卻 403」的落差。
2026-11-19 Callum：外觀改為依設計稿(深色側邊欄、頂欄、標題與麵包屑)，
功能邏輯(選單讀取、權限、登出)維持不變。
***************************************** */
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { auth, authFetch, loadProfile, logout, siteBranding } from '../stores/auth';
import { pendingApprovals, startPendingApprovalsPolling, stopPendingApprovalsPolling } from '../stores/pendingApprovals';
import { menuIconClass } from './menuIcons';
import PendingApprovalsBell from './PendingApprovalsBell.vue';
import { MEMBER_TYPE_LABEL, company, companyLabel, loadCompany } from '../stores/company';
const route = useRoute();
const router = useRouter();
const sidebarMenu = ref([]);
// 待審核預約的提醒（2026-11-25 起）：鈴鐺的紅色數字與側邊欄「訪客審核」旁的數字，都是真實的待審核筆數
// （待審核＋修改待審核，見 stores/pendingApprovals.js，每分鐘更新）。選單裡沒有「訪客審核」的人（沒有權限或模塊未開通）
// 不會去查，也不顯示。路徑對應後端 app/Api/Guest/module.json 的選單 path。
const APPROVALS_PATH = '/guest/approvals';
const hasApprovals = computed(() => sidebarMenu.value.some((item) => item.path === APPROVALS_PATH || item.children?.some((child) => child.path === APPROVALS_PATH)));
function hasApprovalsChild(item){
  return Boolean(item.children?.some((child) => child.path === APPROVALS_PATH));
}
const pendingBadge = computed(() => (pendingApprovals.value > 99 ? '99+' : String(pendingApprovals.value)));
// 側邊欄收合狀態記在 localStorage，重新整理後維持；讀寫失敗(私密視窗等)就當作展開。
const COLLAPSE_KEY = 'company_sidebar_collapsed';
function readCollapsed(){
  try{
    return localStorage.getItem(COLLAPSE_KEY) === '1';
  }catch{
    return false;
  }
}
const collapsed = ref(readCollapsed());
function toggleCollapsed(){
  collapsed.value = !collapsed.value;
  try{
    localStorage.setItem(COLLAPSE_KEY, collapsed.value ? '1' : '0');
  }catch{
    // 無法寫入就算了，只是下次重新整理不會記住。
  }
}
// 站名來自「頁面設置」；沒設定時用設計稿的品牌文字。
const brandName = computed(() => siteBranding.siteName || 'NSST 新保科技');
// 見 stores/auth.js 的 authFetch：401 時只會清空本地 token（clearSession），
// 不會主動導頁——頁面各自呼叫 API 的時機不同，交給這裡統一監看 token
// 狀態，一旦變成 null 就導回登入頁，不用每個頁面各自處理一次重複邏輯。
watch(
  () => auth.token,
  (token) => {
    if(!token){
      router.push({ name: 'login' });
    }
  },
);
const activeMenu = computed(() => route.path);
const pageTitle = computed(() => (route.meta.title ? route.meta.title : String(route.name || '')));
// 目前頁面所屬的分類(用在麵包屑「訪客管理 › 訪客記錄」)；最上層項目沒有分類就不顯示。
const activeGroup = computed(() => sidebarMenu.value.find((item) => item.children?.some((child) => child.path === route.path)) || null);
// 分組選單預設只展開「目前頁面所在的分類」，其他收合（2026-11-25 起；原本是全部展開，
// 後臺項目變多後要捲動才看得到，所以改掉）。el-menu 的 default-openeds 只在載入時生效，
// 之後換頁（例如點首頁的捷徑卡片跳到別的分類）要靠下面的 watch 幫忙展開，不然目前頁面的選項會藏在收合的分類裡。
const defaultOpeneds = computed(() => (activeGroup.value ? [activeGroup.value.key] : []));
const menuRef = ref(null);
watch(activeGroup, (group) => {
  if (group) menuRef.value?.open(group.key);
});
// key -> target 的對照表(含子節點)，el-menu 拿到的 @select 只給 index
// （這裡固定用葉節點的 path 當 index），不知道這個節點的開啟方式，要另外
// 查表決定是 router.push 還是開新視窗。
const targetByPath = computed(() => {
  const map = {};
  for(const item of sidebarMenu.value){
    if(item.path) map[item.path] = item.target;
    for(const child of item.children || []){
      if(child.path) map[child.path] = child.target;
    }
  }
  return map;
});
function handleMenuSelect(index){
  if(targetByPath.value[index] === '_blank'){
    // el-menu 沒有掛 router 屬性，改成這裡自己決定要 router.push 還是開
    // 新視窗；新視窗要補上 Vue Router 的 base（路徑前綴進站時的
    // /{site}/admin），不然開出來的分頁網址會少一截。
    window.open(router.options.history.base + index, '_blank');
    return;
  }
  router.push(index);
}
async function loadSidebarMenu(){
  const res = await authFetch('/api/site/config');
  const body = await res.json();
  if(body.status){
    sidebarMenu.value = body.data.menu || [];
  }
}
async function handleLogout(){
  await logout();
  router.push({ name: 'login' });
}
// 見已知環境限制說明(README)：兩支呼叫刻意改成依序 await，不要同時併發
// 打——已實測觀察到本機環境偶發 config('jwt.site_secret') 被讀成空字串
// 導致 500(Key material must not be empty)，併發請求會提高觸發機率，
// 依序呼叫可以降低機率(非本次功能修正範圍，屬於既有環境限制)。
onMounted(async () => {
  if(!auth.username){
    await loadProfile();
  }
  // 重新整理頁面時補讀本公司資訊（登入頁已讀過就直接沿用）。
  await loadCompany();
  await loadSidebarMenu();
  // 選單載入後才知道有沒有「訪客審核」，有才開始查待審核筆數。
  if(hasApprovals.value){
    startPendingApprovalsPolling();
  }
});
onUnmounted(stopPendingApprovalsPolling);
</script>
<style>
/* 這份是全域樣式(非 scoped)：el-menu 的內部元素屬於子元件，scoped 管不到，
   所以全部用 .layout 前綴限縮範圍。顏色一律走 Element Plus 變數或 base.css 的變數；
   嚴格 CSP 不允許 inline style，這裡完全不用 :style 與 style 屬性；
   過渡一律用 var(--app-transition-duration)／var(--app-transition-timing)。 */
.layout{
  --layout-sidebar-bg: #0f2348;
  --layout-sidebar-width: 240px;
  --layout-sidebar-collapsed-width: 72px;
  display: flex;
  height: 100vh;
  background: #f5f7fb;
}
/* ───── 側邊欄 ───── */
.layout__sidebar{
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  width: var(--layout-sidebar-width);
  overflow: hidden;
  background: var(--layout-sidebar-bg);
  transition: width var(--app-transition-duration) var(--app-transition-timing);
}
.layout__sidebar--collapsed{
  width: var(--layout-sidebar-collapsed-width);
}
.layout__logo{
  display: flex;
  gap: 10px;
  align-items: center;
  height: 68px;
  padding: 0 20px;
  overflow: hidden;
  color: #fff;
  white-space: nowrap;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}
.layout__logo-img{
  flex-shrink: 0;
  width: 32px;
  height: 32px;
  object-fit: contain;
}
.layout__logo-mark{
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  font-size: 16px;
  background: var(--el-color-primary);
  border-radius: 8px;
}
.layout__logo-text{
  overflow: hidden;
  font-size: 18px;
  font-weight: 700;
  text-overflow: ellipsis;
}
.layout__menu.el-menu{
  --el-menu-bg-color: transparent;
  --el-menu-text-color: rgba(255, 255, 255, 0.88);
  --el-menu-hover-bg-color: rgba(255, 255, 255, 0.08);
  --el-menu-active-color: #fff;
  --el-menu-item-height: 46px;
  --el-menu-sub-item-height: 42px;
  flex: 1;
  padding: 10px;
  overflow-x: hidden;
  overflow-y: auto;
  border-right: 0;
  scrollbar-width: none;
}
.layout__menu.el-menu::-webkit-scrollbar{
  display: none;
}

.layout__menu .el-menu-item,
.layout__menu .el-sub-menu__title{
  margin-bottom: 4px;
  font-size: 15px;
  border-radius: 8px;
}
.layout__menu .el-menu-item:hover,
.layout__menu .el-sub-menu__title:hover{
  color: #fff;
}
/* 展開中的分類：標題底色比側邊欄稍亮。 */
.layout__menu .el-sub-menu.is-opened > .el-sub-menu__title{
  color: #fff;
  background: rgba(255, 255, 255, 0.1);
}
/* 目前所在頁面：主色圓角底。 */
.layout__menu .el-menu-item.is-active{
  color: #fff;
  background: var(--el-color-primary);
}
/* 分類底下的子項目：沒有圖示，往右縮排。Element Plus 會用 inline 方式算縮排，要蓋過它。 */
.layout__menu .el-sub-menu .el-menu-item{
  padding-left: 52px !important;
  font-size: 14px;
}
/* 「訪客審核」旁的待審核筆數。 */
.layout__menu-badge{
  min-width: 18px;
  height: 18px;
  padding: 0 6px;
  margin-left: 8px;
  font-size: 12px;
  font-weight: 600;
  line-height: 18px;
  color: #fff;
  text-align: center;
  background: #f5222d;
  border-radius: 9px;
}
/* 分類收合時，標題旁的紅點（分類展開後就不顯示，改看子項目的數字）。 */
.layout__menu .el-sub-menu__title{
  position: relative;
}
.layout__group-dot{
  display: inline-block;
  flex: none;
  width: 8px;
  height: 8px;
  margin-left: 8px;
  background: #f5222d;
  border-radius: 50%;
}
.layout__menu .el-sub-menu.is-opened .layout__group-dot{
  display: none;
}
/* 側邊欄收成圖示時，紅點改放在圖示的右上角。 */
.layout__sidebar--collapsed .layout__group-dot{
  position: absolute;
  top: 10px;
  left: calc(50% + 8px);
  margin-left: 0;
}
.layout__menu-icon{
  width: 22px;
  margin-right: 12px;
  font-size: 17px;
  text-align: center;
}
.layout__sidebar--collapsed .layout__menu-icon{
  margin-right: 0;
}
/* ───── 右側主體 ───── */
.layout__body{
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;
}
.layout__impersonation{
  border-radius: 0;
}
.layout__header{
  display: flex;
  flex-shrink: 0;
  gap: 16px;
  align-items: center;
  height: 64px;
  padding: 0 24px;
}
.layout__header-right{
  display: flex;
  gap: 22px;
  align-items: center;
  margin-left: auto;
}
.layout__icon-btn{
  position: relative;
  padding: 6px 8px;
  font-size: 20px;
  color: #1f2937;
  cursor: pointer;
  background: transparent;
  border: 0;
  border-radius: 6px;
}
.layout__icon-btn:hover{
  background: rgba(15, 35, 72, 0.06);
}
.layout__company{
  display: inline-flex;
  gap: 8px;
  align-items: center;
  color: #374151;
  font-size: 14px;
  white-space: nowrap;
}
.layout__badge{
  position: absolute;
  top: -2px;
  right: -2px;
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  font-size: 11px;
  font-weight: 600;
  line-height: 18px;
  color: #fff;
  text-align: center;
  background: #f5222d;
  border-radius: 9px;
}
.layout__user{
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 0;
  font: inherit;
  color: #1f2937;
  cursor: pointer;
  background: transparent;
  border: 0;
  outline: none;
}
.layout__avatar{
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 38px;
  height: 38px;
  font-size: 17px;
  color: #fff;
  background: #c3cad6;
  border-radius: 50%;
}
.layout__user-name{
  font-size: 15px;
  font-weight: 600;
}
.layout__user-arrow{
  font-size: 12px;
}
.layout__main{
  flex: 1;
  padding: 4px 24px 24px;
  overflow-y: auto;
}
.layout__heading{
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
  align-items: center;
  margin: 4px 0 18px;
}
.layout__title{
  margin: 0;
  font-size: 26px;
  font-weight: 700;
  color: #111827;
}
.layout__crumbs{
  display: flex;
  gap: 10px;
  align-items: center;
  padding-left: 16px;
  font-size: 14px;
  color: #6b7280;
  border-left: 1px solid #d1d5db;
}
.layout__crumb-sep{
  font-size: 10px;
}
.layout__footer{
  flex-shrink: 0;
  padding: 12px 16px;
  font-size: 13px;
  color: #6b7280;
  text-align: center;
  background: #fff;
  border-top: 1px solid #e5e7eb;
}
@media(max-width: 900px){
  .layout__company{
    display: none;
  }
}
</style>
