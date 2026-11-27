/* *****************************************
File: http.js
Author: Callum
Date: 2026-11-26
Description: API 呼叫的共用處理：把後端回應整理成 { ok, data, message }（原本寫在 guest.js，2026-11-26 抽出來給各 API 檔共用）。
***************************************** */
import { authFetch } from '../stores/auth';
// 統一把後端回應整理成 { ok, data, message }，頁面只要看 ok 與 message：
// 後端格式為 { status, error_code, data, error_message }，驗證失敗（422）
// 也是同一種格式，error_message 會帶第一個欄位的中文錯誤，見 bootstrap/app.php。
// 失敗訊息要依實際原因分開說，才不會讓人往錯的方向查：
// - 連請求都送不出去（fetch 丟例外）→「無法連線到伺服器」，由呼叫端處理。
// - 後端有給 error_message（驗證失敗、業務錯誤）→ 直接用它。
// - 回了 JSON 但沒有 error_message、狀態碼 5xx → Laravel 處理時出錯（伺服器連得到，是後端程式的錯誤）。
// - 回應不是 JSON 且不成功 → 通常是後端沒在跑（開發時 Vite 代理回 500、內容是空的）或正式環境的閘道錯誤（502／504 網頁）。
export function failureMessage(res, body, action = '操作'){
  if(body?.error_message)return body.error_message;
  if(body && res.status >= 500)return `伺服器處理時發生錯誤（HTTP ${res.status}），請稍後再試；持續發生請聯絡系統管理員`;
  if(!body && !res.ok)return `伺服器沒有正常回應（HTTP ${res.status}），請稍後再試`;
  return `${action}失敗，請稍後再試`;
}
export async function request(path, options = {}){
  let res;
  try{
    res = await authFetch(path, options);
  }catch{
    return{ ok: false, data: null, message: '無法連線到伺服器，請稍後再試' };
  }
  const body = await res.json().catch(() => null);
  if(body?.status){
    return{ ok: true, data: body.data, message: '' };
  }
  return{ ok: false, data: null, message: failureMessage(res, body) };
}
const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };
export function postJson(path, payload){
  return request(path, { method: 'POST', headers: JSON_HEADERS, body: JSON.stringify(payload) });
}
export function putJson(path, payload = {}){
  return request(path, { method: 'PUT', headers: JSON_HEADERS, body: JSON.stringify(payload) });
}
