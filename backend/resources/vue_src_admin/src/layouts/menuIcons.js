/* *****************************************
File: menuIcons.js
Author: Callum
Date: 2026-11-19
Description: 側邊欄選單圖示對照表：依選單 key 對應 Font Awesome 圖示。
***************************************** */
// 後端選單資料（menus 表／各模塊 module.json）存的圖示是 Element Plus 的圖示名稱，
// 而且訪客模塊每一項都是同一個 'Menu'，設計稿要每項不同的 Font Awesome 實心圖示，
// 所以在前端用「選單 key → 圖示」對照，不用動後端。
// 規則：側邊欄一律用實心（fa-solid）；沒有對照到的項目不顯示圖示。
// 之後後端若直接存 Font Awesome class（以 fa- 開頭），會優先採用後端的值。
const ICON_BY_KEY = {
  dashboard: 'fa-solid fa-house',
  // 分類（設計稿：訪客管理用人像圖示）
  'group-guest': 'fa-solid fa-user',
  'group-account': 'fa-solid fa-user-lock',
  'group-setting': 'fa-solid fa-building',
  'group-system': 'fa-solid fa-gear',
  // 帳號與權限
  users: 'fa-solid fa-user-gear',
  roles: 'fa-solid fa-user-shield',
  // 基本資料設定
  building: 'fa-solid fa-building',
  floor: 'fa-solid fa-layer-group',
  doorplate: 'fa-solid fa-door-open',
  // 訪客管理
  approvals: 'fa-solid fa-clipboard-check',
  records: 'fa-solid fa-clock-rotate-left',
  applies: 'fa-solid fa-calendar-check',
  reports: 'fa-solid fa-chart-column',
  // 系統設定
  menus: 'fa-solid fa-bars',
  dictionaries: 'fa-solid fa-book',
  'system-config': 'fa-solid fa-sliders',
  plugins: 'fa-solid fa-plug',
  portals: 'fa-solid fa-network-wired',
};
export function menuIconClass(item){
  if(typeof item.icon === 'string' && item.icon.startsWith('fa-')){
    return item.icon;
  }
  return ICON_BY_KEY[item.key] || '';
}
