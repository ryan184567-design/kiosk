/* *****************************************
File: company.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 公司端口「本公司」API 封裝：審核設定、員工帳號管理（只有公司管理員有權限）。
***************************************** */
import { postJson, putJson, request } from './http';

const JSON_ACCEPT = { headers: { Accept: 'application/json' } };

function toQuery(params){
  const search = new URLSearchParams();
  for(const [key, value] of Object.entries(params)){
    if(value === undefined || value === null || value === '') continue;
    search.append(key, value);
  }
  return search.toString();
}

// PUT /api/guest/company/setting：員工新增預約是否需要審核。回傳本公司資訊（同 GET /api/guest/company，少了 member_type）。
export function saveApprovalSetting(approvalRequired){
  return putJson('/api/guest/company/setting', { approval_required: approvalRequired });
}

// GET /api/guest/company/employees：params keyword、member_type、page、per_page（-1 不分頁）。
// 回傳 { items: [{ id, user_id, username, name, email, member_type, created_at }], total, per_page, current_page }。
export function fetchEmployees(params = {}){
  return request(`/api/guest/company/employees?${toQuery(params)}`, JSON_ACCEPT);
}

// POST：payload { username, password, name, email, member_type }，member_type 只能 2（審核者）或 3（一般員工）。
export function createEmployee(payload){
  return postJson('/api/guest/company/employees', payload);
}

// PUT：payload { name, email, member_type }。不能修改公司管理員。
export function updateEmployee(id, payload){
  return putJson(`/api/guest/company/employees/${id}`, payload);
}

// PUT：重設密碼，不需要舊密碼。
export function resetEmployeePassword(id, password){
  return putJson(`/api/guest/company/employees/${id}/password`, { password });
}

// DELETE：移除員工（收回登入權限，帳號與建立過的預約保留）。
export function removeEmployee(id){
  return request(`/api/guest/company/employees/${id}`, { method: 'DELETE', ...JSON_ACCEPT });
}
