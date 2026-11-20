/* *****************************************
File: factory.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 廠商 App 管理（factory 模塊，全局共用）的 API 呼叫封裝，頁面不直接寫 fetch。
             注意：這裡呼叫的是「後台用戶」接口（/api/system/factory/apps 等，jwt.auth + permission.api），
             不是機器自己呼叫的接口（/api/factory/token、/api/factory/guest/* 等，App 認證）。
***************************************** */

import { authFetch } from '../stores/auth';

// 統一整理成 { ok, data, message }，頁面只看 ok 與 message（後端格式見 bootstrap/app.php）。
async function request(path, options = {}) {
  try {
    const res = await authFetch(path, options);
    const body = await res.json();

    if (body.status) {
      return { ok: true, data: body.data, message: '' };
    }

    return { ok: false, data: null, message: body.error_message || '操作失敗，請稍後再試' };
  } catch {
    return { ok: false, data: null, message: '無法連線到伺服器，請稍後再試' };
  }
}

function sendJson(method, path, payload) {
  return request(path, {
    method,
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: payload === undefined ? undefined : JSON.stringify(payload),
  });
}

function query(params) {
  const q = new URLSearchParams();

  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) q.set(k, v);
  });

  const s = q.toString();

  return s ? `?${s}` : '';
}

const jsonHeaders = { headers: { Accept: 'application/json' } };

export const fetchApps = (params = {}) => request(`/api/system/factory/apps${query(params)}`, jsonHeaders);
// 建立與重置回傳的 app_secret 只出現這一次，頁面要立刻顯示並提醒保存
export const createApp = (payload) => sendJson('POST', '/api/system/factory/apps', payload);
export const updateApp = (id, payload) => sendJson('PUT', `/api/system/factory/apps/${id}`, payload);
export const deleteApp = (id) => sendJson('DELETE', `/api/system/factory/apps/${id}`);
export const resetAppKey = (id) => sendJson('POST', `/api/system/factory/apps/${id}/reset-secret`);
export const setAppScopes = (id, scopes) => sendJson('PUT', `/api/system/factory/apps/${id}/scopes`, { scopes });
export const fetchScopes = () => request('/api/system/factory/scopes', jsonHeaders);
export const fetchLogs = (params = {}) => request(`/api/system/factory/logs${query(params)}`, jsonHeaders);
// 單筆記錄完整內容（含傳入參數與完整回應；列表不含，因為可能很大）
export const fetchLogDetail = (id) => request(`/api/system/factory/logs/${id}`, jsonHeaders);
