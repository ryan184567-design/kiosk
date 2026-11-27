/* *****************************************
File: auth.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 公司端口（company）登入狀態管理與帶身份驗證的 API 呼叫封裝；2026-11-27 由管理後台 stores/auth.js 複製，差別只在端口代碼、token 儲存鍵與網址前綴。
***************************************** */

import { reactive } from 'vue';

// 公司端口固定登入 company 端口（管理後台是 admin）（見 README 7.2），JWT 存 localStorage、
// 每次 API 呼叫主動帶 Authorization: Bearer header，理由與寫法比照
// 主站後台的 stores/auth.js，兩者是完全獨立的 SPA、各自的 token 不通用
// （子站 JWT 用 jwt.site_secret 簽章，主站用 jwt.hq_secret，見 README 11.1）。
const STORAGE_KEY = 'company_access_token';
const PORTAL_KEY = 'company';

// 見 App\Support\Site\SiteResolver 開頭「已實測踩過的坑」說明：這支 SPA
// 用 /{sitePrefix}/admin/... 這種網址結構（見 router/index.js），但
// fetch('/api/xxx') 是絕對路徑，請求本身不會帶著瀏覽器網址列上的站點
// 前綴，害後端 SiteResolver 對每一支 API 呼叫都判斷成預設站——已實測
// 發現這個問題（在 /vendora/admin 建立角色，資料卻寫進 1 號站）。修法：
// 從目前網址路徑自己算出站點前綴，每次 API 呼叫都用 X-Site-Path-Prefix
// header 明確告訴後端；沒有前綴時（網域已綁定的子站，或就是預設站）回傳
// 空物件，不影響原本網域/預設站的判斷。
function sitePrefixHeaders() {
  const first = window.location.pathname.split('/').filter(Boolean)[0];

  return first && first !== 'company' ? { 'X-Site-Path-Prefix': first } : {};
}

// 見 README 17.4：頁面設置（cfg_module=site_basic）套用到管理後台自己
// 的外觀——站名（側邊欄 logo 文字、瀏覽器分頁標題）、主題色（Element Plus
// 主色 CSS 變數）、站徽（favicon）。三套 SPA 各自獨立建置（見 README 8
// 節），這裡跟前台 stores/site.js 是各自維護一份同樣邏輯，不跨專案 import。
export const siteBranding = reactive({
  siteName: '',
  logo: null,
  themeColor: null,
  // 見 README 17.4：頁尾文案在管理後台也顯示（AdminLayout 的 el-footer）。
  footerText: '',
});

function applyRuntimeBranding() {
  if (siteBranding.siteName) {
    document.title = `${siteBranding.siteName} 公司端口`;
  }

  if (siteBranding.themeColor) {
    document.documentElement.style.setProperty('--el-color-primary', siteBranding.themeColor);
  }

  if (siteBranding.logo) {
    let link = document.querySelector('link[rel="icon"]');

    if (!link) {
      link = document.createElement('link');
      link.rel = 'icon';
      document.head.appendChild(link);
    }

    link.href = siteBranding.logo;
  }
}

// 見 main.js：app 掛載前用同一支端點問後端這次請求實際落在哪個站點
// （site_code），藉此決定 Vue Router 的 base；沒有 token 也能呼叫（見
// SiteConfigController 說明：這支端點不需要登入）。順便把站名/主題色/
// 站徽套用起來，不用等登入後才有正確的品牌外觀。
export async function fetchSiteCode() {
  try {
    const res = await fetch('/api/site/config', { headers: sitePrefixHeaders() });
    const body = await res.json();

    if (body?.data) {
      siteBranding.siteName = body.data.site_name || '';
      siteBranding.logo = body.data.logo || null;
      siteBranding.themeColor = body.data.theme_color || null;
      siteBranding.footerText = body.data.footer_text || '';
      applyRuntimeBranding();
    }

    return body?.data?.site_code || '';
  } catch (e) {
    return '';
  }
}

// 見 README 9.1：端口可以設定「是否需要登入才能進入」，main.js 掛載
// App 前呼叫 fetchPortalConfig() 問後端這個端口目前的設定，router 的
// beforeEach 依 requiresLogin 決定要不要強制導去登入頁。呼叫失敗或端口
// 被停用時保持預設 true（安全預設：不會因為請求失敗就意外開放匿名進入）。
export const portalConfig = reactive({
  requiresLogin: true,
});

export async function fetchPortalConfig() {
  try {
    const res = await fetch(`/api/portals/public/${PORTAL_KEY}`, { headers: sitePrefixHeaders() });
    const body = await res.json();

    if (body?.status) {
      portalConfig.requiresLogin = body.data.requires_login !== false;
    }
  } catch (e) {
    // 見上方說明：失敗維持預設 true，不做任何事。
  }
}

export const auth = reactive({
  token: localStorage.getItem(STORAGE_KEY) || null,
  username: null,
  name: null,
  portalId: null,
  // 見 README 6.11 第 4 點：本次 session 的建立來源（login／sso／
  // hq_impersonation），由 GET /api/user/profile 回傳；AdminLayout 依此
  // 顯示「目前透過主站跳轉以超級管理員身份操作」的提示列。
  issuedVia: null,
});

export function isLoggedIn() {
  return !!auth.token;
}

// 走錯登入入口時可以自動切換過去的端口：端口代碼 → 該端口 SPA 的網址片段與 token 儲存鍵
// （要與該端口自己的 stores/auth.js 一致，管理後台見 resources/vue_src_admin/src/stores/auth.js）。
const SWITCHABLE_PORTALS = {
  admin: { label: '管理後台', path: 'admin', storageKey: 'admin_access_token' },
};
// 後端 ErrorCode::PORTAL_MISMATCH：帳號密碼正確，但帳號不屬於這個端口，data.portal_keys 是它能登入的端口。
const PORTAL_MISMATCH = 20018;

async function requestLogin(username, password, portalKey) {
  const res = await fetch('/api/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...sitePrefixHeaders() },
    body: JSON.stringify({ username, password, portal_key: portalKey }),
  });

  return res.json();
}

// 改登入另一個端口，把 token 存到該端口 SPA 讀取的位置後整頁跳過去（保留路徑前綴進站的站點代碼）。
async function switchToPortal(portalKey, username, password) {
  const target = SWITCHABLE_PORTALS[portalKey];
  const body = await requestLogin(username, password, portalKey);

  if (!body.status) {
    throw new Error(body.error_message || '登入失敗');
  }

  localStorage.setItem(target.storageKey, body.data.access_token);
  const first = window.location.pathname.split('/').filter(Boolean)[0];
  const prefix = first && first !== 'company' ? `/${first}` : '';
  window.location.assign(`${prefix}/${target.path}/`);

  return { redirected: true, portalLabel: target.label };
}

// 登入成功回傳後端 data；若帳號屬於其他端口（例如後台管理員），會自動改登入該端口並整頁跳轉，
// 此時回傳 { redirected: true }，呼叫端不要再做後續動作。
export async function login(username, password) {
  const body = await requestLogin(username, password, PORTAL_KEY);

  if (!body.status) {
    const switchTo = body.error_code === PORTAL_MISMATCH
      ? (body.data?.portal_keys || []).find((key) => SWITCHABLE_PORTALS[key])
      : null;

    if (switchTo) {
      return switchToPortal(switchTo, username, password);
    }

    throw new Error(body.error_message || '登入失敗');
  }

  auth.token = body.data.access_token;
  localStorage.setItem(STORAGE_KEY, auth.token);

  return body.data;
}

export async function loadProfile() {
  const res = await authFetch('/api/user/profile');
  const body = await res.json();

  if (!body.status) {
    throw new Error(body.error_message || '無法取得個人資料');
  }

  auth.username = body.data.username;
  auth.name = body.data.name;
  auth.portalId = body.data.portal_id;
  // 見 README 6.11 第 4 點：hq_impersonation 表示這次是主站跳轉登入進來的。
  auth.issuedVia = body.data.issued_via || null;

  return body.data;
}

export async function logout() {
  try {
    await authFetch('/api/auth/logout', { method: 'POST' });
  } finally {
    clearSession();
  }
}

export function clearSession() {
  auth.token = null;
  auth.username = null;
  auth.name = null;
  auth.portalId = null;
  auth.issuedVia = null;
  localStorage.removeItem(STORAGE_KEY);
}

// 見上方說明：統一在這裡帶 Authorization header，401（token 失效/被新登入
// 取代）一律清空本地 session，交由呼叫端（頁面層）決定要不要導去登入頁。
export async function authFetch(path, options = {}) {
  const headers = { ...(options.headers || {}), ...sitePrefixHeaders(), Authorization: `Bearer ${auth.token}` };
  const res = await fetch(path, { ...options, headers });

  if (res.status === 401) {
    clearSession();
  }

  return res;
}
