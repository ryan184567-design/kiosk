/* *****************************************
File: dataSetting.js
Author: Callum
Date: 2026-11-26
Description: 基本資料設定模塊（棟別、樓層、門牌）的 API 呼叫封裝。
***************************************** */
// 後端 DataSettingController。目前的能力：棟別只能查詢與新增、樓層只能查詢、門牌可查詢／新增／修改／刪除。
import { postJson, request } from './http';
const GET = { headers: { Accept: 'application/json' } };
// 全部棟別（預設不分頁）：{ items: [{ id, name, on_floor, under_floor }], total }。
export function fetchBuildings(){
  return request('/api/data-setting/building', GET);
}
// 新增棟別：{ name, on_floor, under_floor }。後端會同時建立對應的樓層資料。三個欄位都要帶（沒帶 under_floor 後端會出錯）。
export function addBuilding(payload){
  return postJson('/api/data-setting/building/add', payload);
}
// 全部樓層：{ floor: [{ id, bid, floor, location, image, status, bname }], building: [{ id, name }] }。
// 後端雖然收 bid，但驗證是 max:10（棟別 id 超過 10 就會被拒絕），所以一律抓全部、由頁面篩選。
export function fetchFloors(){
  return request('/api/data-setting/floor', GET);
}
// 全部門牌（預設不分頁）：{ items: [{ id, doorplate, floor, household, company_name, status }], total }。
export function fetchDoorplateList(){
  return request('/api/data-setting/doorplate', GET);
}
// 新增門牌：{ doorplate, floor, household, company_name }，新增後狀態固定是啟用。
export function addDoorplate(payload){
  return postJson('/api/data-setting/doorplate/create', payload);
}
// 修改門牌：{ id, doorplate, floor, household, company_name, status }，全部必填。
export function updateDoorplate(payload){
  return postJson('/api/data-setting/doorplate/update', payload);
}
export function deleteDoorplate(id){
  return postJson('/api/data-setting/doorplate/delete', { id });
}
