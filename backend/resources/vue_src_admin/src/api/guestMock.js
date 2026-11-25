/* *****************************************
File: guestMock.js
Author: Callum
Date: 2026-11-19
Description: 訪客管理仍缺後端 API 的假資料（目前只剩現場訪客登記），後端好了整支刪除即可。
***************************************** */
import { todayString } from '../pages/guest/format';
// 這支檔案只被 api/guest.js 引用，頁面不要直接 import 它。
const delay = (ms = 200) => new Promise((resolve) => setTimeout(resolve, ms));
// 現場訪客登記：只回傳一組假單號。
export async function registerMock(){
  await delay();
  return{ ok: true, data: { reservation_id: `GA${todayString().replaceAll('-', '')}${String(Math.floor(Math.random() * 9000) + 1000)}` }, message: '' };
}
