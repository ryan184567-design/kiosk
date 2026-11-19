/* *****************************************
File: main.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站管理後台 SPA 進入點，見 README 7.2。
***************************************** */

import { createApp } from 'vue';
import ElementPlus from 'element-plus';
import zhTw from 'element-plus/es/locale/lang/zh-tw.mjs';
import 'element-plus/dist/index.css';
// 全站圖示統一用 Font Awesome Free（font-family 字型檔版）：CSS 是外部檔案、
// 字型走同源檔案，符合嚴格 CSP 的 style-src／font-src 'self'；不要改成
// SVG 核心套件，它會在執行期動態注入 <style>，會被 CSP 擋下。
import '@fortawesome/fontawesome-free/css/all.min.css';
// 全域基礎樣式（統一 hover／過渡為 ease 300ms），要放在 element-plus 樣式之後，
// 才能覆蓋它的過渡變數。
import './styles/base.css';
import App from './App.vue';
import { createAppRouter } from './router';
import { fetchPortalConfig, fetchSiteCode } from './stores/auth';

// 見 router/index.js 開頭說明：只有網址第一段真的等於後端解析出的
// site_code，才算是路徑前綴進站，Vue Router 的 base 才要帶上這段前綴＋
// 固定的 /admin；網域已綁定的子站、或前綴打錯字，base 一律是 `/admin/`，
// 讓打錯字的網址落入 404 路由，不是被誤判成登入頁或站點首頁。
function resolveBase(siteCode) {
  const first = window.location.pathname.split('/').filter(Boolean)[0];

  return first && first !== 'admin' && first === siteCode ? `/${first}/admin/` : '/admin/';
}

// 見 README 4.2.1：已實測 Element Plus 的浮層定位（下拉選單/tooltip/
// 日期選擇器等，底層用 popper.js）是用 CSSOM 逐一屬性賦值（el.style.x = y），
// 不是整段 style 屬性字串替換，嚴格 CSP 的 style-src（無 unsafe-inline）
// 不會擋下這種寫法，Playwright 實測零主控台錯誤，可以放心使用不需要
// 額外的 CSP 例外規則。
// 見開發約定第 11 點：介面文字不能寫死英文，Element Plus 元件內建文字
// （確認對話框的「OK/Cancel」、表格的「No Data」等）預設是英文，要指定
// 繁中語系包才會變成中文，不是我們自己刻的字串所以不受 lang/ 語言包規則
// 管，但一樣不能讓使用者看到英文介面。
// 見 stores/auth.js：requires_login 的設定要在 router guard 第一次判斷
// 之前就準備好，否則 beforeEach 讀到還沒抓回來的預設值會先誤導一次。
Promise.all([fetchSiteCode(), fetchPortalConfig()]).then(([siteCode]) => {
  const router = createAppRouter(resolveBase(siteCode));

  createApp(App).use(router).use(ElementPlus, { locale: zhTw }).mount('#app');
});
