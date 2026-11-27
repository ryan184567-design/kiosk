/* *****************************************
File: company.js
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-27
Description: 公司端口：登入者所屬公司與公司內身分（GET /api/guest/company），各頁共用、登入後讀一次。
***************************************** */
import { reactive } from 'vue';
import { request } from '../api/http';

// 公司內身分（後端 GuestCompanyMember::TYPE_*）
export const MEMBER_TYPE = {
  ADMIN: 1,
  APPROVER: 2,
  EMPLOYEE: 3,
};
export const MEMBER_TYPE_LABEL = {
  1: '公司管理員',
  2: '審核者',
  3: '一般員工',
};

export const company = reactive({
  loaded: false,
  doorplateId: null,
  companyName: '',
  floor: null,
  doorplate: null,
  // 員工新增預約是否需要審核（false＝新增即自動核准）
  approvalRequired: true,
  memberType: null,
});

let loading = null;

// 讀取本公司資訊；重複呼叫共用同一個請求。force=true 時重新讀（例如審核設定剛改完）。
export function loadCompany(force = false){
  if(force) loading = null;
  if(!loading){
    loading = request('/api/guest/company', { headers: { Accept: 'application/json' } }).then((result) => {
      if(!result.ok){
        loading = null;
        return result;
      }
      applyCompany(result.data);
      return result;
    });
  }
  return loading;
}

export function applyCompany(data){
  company.loaded = true;
  company.doorplateId = data.doorplate_id;
  company.companyName = data.company_name || '';
  company.floor = data.floor;
  company.doorplate = data.doorplate;
  company.approvalRequired = data.approval_required !== false;
  if(data.member_type !== undefined) company.memberType = data.member_type;
}

export function clearCompany(){
  loading = null;
  Object.assign(company, { loaded: false, doorplateId: null, companyName: '', floor: null, doorplate: null, approvalRequired: true, memberType: null });
}

// 受訪公司顯示文字，例如「宏昇工程（3F）」
export function companyLabel(){
  if(!company.companyName) return '';
  return company.floor ? `${company.companyName}（${company.floor}F）` : company.companyName;
}
