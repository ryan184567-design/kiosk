/* *****************************************
File: site.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 執行期站點設定注入（主題色／選單／文案），啟動時 fetch 一次，見 README 8.2、17.4。
***************************************** */

import { reactive } from 'vue';

// 見 README 8.2：執行期站點設定注入，三層皆適用。啟動時 fetch 一次，
// 套用為 CSS variables（主題色）與 favicon/logo、document.title、頁尾
// 文案，不因建置層級而不同。刻意不引入 Pinia，這裡的需求（單一份全域
// 設定、啟動時載入一次）用 reactive() 就夠，避免多一個依賴。
export const siteConfig = reactive({
  loaded: false,
  siteName: '',
  logo: null,
  themeColor: null,
  menu: [],
  footerText: '',
  customRoutes: [],
  // '1'=正常營運、'0'=維護中（見 17.4 dic 示範字典），前台依此顯示維護頁。
  siteStatus: '1',
  // 這次請求實際解析到的站點代碼，見 router/index.js 說明：main.js 靠這個
  // 決定 Vue Router 的 base，不是自己憑網址片段用猜的。
  code: '',
});

// 見 App\Support\Site\SiteResolver 開頭「已實測踩過的坑」說明：fetch()
// 打的是絕對路徑 /api/site/config，不會自動帶著瀏覽器網址列上的站點
// 前綴（例如 /vendora/...），後端單靠網址路徑判斷會把這支呼叫誤判成
// 預設站——用同一顆 X-Site-Path-Prefix header 修正，跟管理後台的
// stores/auth.js 是同一套解法。前台的路徑結構是 /{sitePrefix} 直接到底
// （不像管理後台還有固定的 /admin 分段），只要有第一段就是候選前綴，
// 交給後端跟站點代碼清單比對，比對不到就當作沒有這個 header 一樣處理。
function sitePrefixHeaders() {
  const first = window.location.pathname.split('/').filter(Boolean)[0];

  return first ? { 'X-Site-Path-Prefix': first } : {};
}

// 見已實測踩過的坑：API 回傳一律 snake_case（site_name/theme_color/
// footer_text/custom_routes/site_status），這裡的 reactive store 用
// camelCase 給模板用起來自然一點——早期版本直接 Object.assign(siteConfig,
// body.data) 兩邊命名對不上，套用永遠失敗（siteConfig.themeColor 永遠是
// 初始值 null），已改成逐欄位明確對應，不能再用整包 assign 的偷懶寫法。
export async function loadSiteConfig() {
  const res = await fetch('/api/site/config', { headers: sitePrefixHeaders() });
  const body = await res.json();

  if (!body.status) {
    throw new Error(body.error_message || 'site config load failed');
  }

  const data = body.data;

  siteConfig.siteName = data.site_name || '';
  siteConfig.logo = data.logo || null;
  siteConfig.themeColor = data.theme_color || null;
  siteConfig.menu = data.menu || [];
  siteConfig.footerText = data.footer_text || '';
  siteConfig.customRoutes = data.custom_routes || [];
  siteConfig.siteStatus = data.site_status ?? '1';
  siteConfig.code = data.site_code || '';
  siteConfig.loaded = true;

  applyRuntimeBranding(siteConfig);

  return siteConfig;
}

// 實際套用到瀏覽器層級的效果（document.title／favicon／CSS 變數），
// 三套 SPA（前台／子站管理後台／主站管理後台）各自的進入點都要呼叫，
// 集中寫在這裡避免三邊各自兜一份重複邏輯。
export function applyRuntimeBranding(config) {
  document.title = config.siteName || document.title;

  if (config.themeColor) {
    document.documentElement.style.setProperty('--theme-color', config.themeColor);
    document.documentElement.style.setProperty('--el-color-primary', config.themeColor);
  }

  if (config.logo) {
    let link = document.querySelector('link[rel="icon"]');

    if (!link) {
      link = document.createElement('link');
      link.rel = 'icon';
      document.head.appendChild(link);
    }

    link.href = config.logo;
  }
}
