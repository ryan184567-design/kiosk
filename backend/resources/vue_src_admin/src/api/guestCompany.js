/* *****************************************
File: guestCompany.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 後台「公司帳號管理」API 封裝：各公司（門牌）審核設定、公司管理員／員工帳號。
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

// 公司內身分（後端 GuestCompanyMember::TYPE_*）
export const MEMBER_TYPE = { ADMIN: 1, APPROVER: 2, EMPLOYEE: 3 };
export const MEMBER_TYPE_LABEL = { 1: '公司管理員', 2: '審核者', 3: '一般員工' };

// GET /api/guest/companies：有公司名稱的門牌。params keyword、page、per_page（-1 不分頁）。
// 每筆 { doorplate_id, company_name, floor, doorplate, approval_required, member_count, admin_count }。
export function fetchCompanies(params = {}){
  return request(`/api/guest/companies?${toQuery(params)}`, JSON_ACCEPT);
}

// PUT /api/guest/companies/{doorplateId}/setting：員工新增預約是否需要審核（其他來源的訪客申請一律需要審核）。
export function saveCompanyApproval(doorplateId, approvalRequired){
  return putJson(`/api/guest/companies/${doorplateId}/setting`, { approval_required: approvalRequired });
}

// GET /api/guest/companies/{doorplateId}/members：params keyword、member_type、page、per_page。
export function fetchCompanyMembers(doorplateId, params = {}){
  return request(`/api/guest/companies/${doorplateId}/members?${toQuery(params)}`, JSON_ACCEPT);
}

// POST：payload { username, password, name, email, member_type }（1 公司管理員、2 審核者、3 一般員工）。
export function createCompanyMember(doorplateId, payload){
  return postJson(`/api/guest/companies/${doorplateId}/members`, payload);
}

// PUT：payload { name, email, member_type }。最後一位公司管理員不能改成其他身分。
export function updateCompanyMember(doorplateId, id, payload){
  return putJson(`/api/guest/companies/${doorplateId}/members/${id}`, payload);
}

export function resetCompanyMemberPassword(doorplateId, id, password){
  return putJson(`/api/guest/companies/${doorplateId}/members/${id}/password`, { password });
}

// DELETE：移除（收回公司端口登入權限）。最後一位公司管理員不能移除。
export function removeCompanyMember(doorplateId, id){
  return request(`/api/guest/companies/${doorplateId}/members/${id}`, { method: 'DELETE', ...JSON_ACCEPT });
}
