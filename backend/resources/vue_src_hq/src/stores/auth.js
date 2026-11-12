/* *****************************************
File: auth.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站後台登入狀態管理與帶身份驗證的 API 呼叫封裝，見 README 6.12。
***************************************** */

import { reactive } from 'vue';

// 主站 JWT 存在 localStorage，不是 cookie——這是一支獨立的 Vue3 SPA，
// 每次 API 呼叫都主動帶 Authorization: Bearer header，跟子站前台的
// 執行期設定注入（見 stores/site.js）是同一種「不用 Pinia，reactive()
// 就夠」的極簡寫法，沒有多站/多端口概念，狀態單純很多。
const STORAGE_KEY = 'hq_access_token';

export const auth = reactive({
  token: localStorage.getItem(STORAGE_KEY) || null,
  username: null,
  name: null,
  isSuperAdmin: false,
});

export function isLoggedIn() {
  return !!auth.token;
}

export async function login(username, password) {
  const res = await fetch('/api/hq/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
  });
  const body = await res.json();

  if (!body.status) {
    throw new Error(body.error_message || '登入失敗');
  }

  auth.token = body.data.access_token;
  localStorage.setItem(STORAGE_KEY, auth.token);

  return body.data;
}

export async function loadProfile() {
  const res = await authFetch('/api/hq/user/profile');
  const body = await res.json();

  if (!body.status) {
    throw new Error(body.error_message || '無法取得個人資料');
  }

  auth.username = body.data.username;
  auth.name = body.data.name;
  auth.isSuperAdmin = body.data.is_super_admin;

  return body.data;
}

export async function logout() {
  try {
    await authFetch('/api/hq/auth/logout', { method: 'POST' });
  } finally {
    clearSession();
  }
}

export function clearSession() {
  auth.token = null;
  auth.username = null;
  auth.name = null;
  auth.isSuperAdmin = false;
  localStorage.removeItem(STORAGE_KEY);
}

// 見上方說明：統一在這裡帶 Authorization header，401（token 失效/被新登入
// 取代）一律清空本地 session，交由呼叫端（頁面層）決定要不要導去登入頁，
// 這裡不直接操作路由，避免跟 router 互相 import 造成循環依賴。
export async function authFetch(path, options = {}) {
  const headers = { ...(options.headers || {}), Authorization: `Bearer ${auth.token}` };
  const res = await fetch(path, { ...options, headers });

  if (res.status === 401) {
    clearSession();
  }

  return res;
}
