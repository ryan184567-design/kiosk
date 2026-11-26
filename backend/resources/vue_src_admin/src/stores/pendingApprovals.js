/* *****************************************
File: pendingApprovals.js
Author: Callum
Date: 2026-11-25
Description: 「待審核預約」的筆數（待審核＋修改待審核），給右上角鈴鐺與側邊欄「訪客審核」的紅色數字共用；定時更新。
***************************************** */
import { ref } from 'vue';
import { fetchPendingCount } from '../api/guest';
// 目前待審核的總筆數；查詢失敗（沒有權限、連不上）時維持上一次的值，一開始是 0（不顯示）。
export const pendingApprovals = ref(0);
// 多久更新一次。分頁在背景（切到別的分頁）時不更新，切回來才立刻補一次。
const POLL_INTERVAL_MS = 60 * 1000;
let timer = null;
let refreshing = false;
// 重新取得筆數（正在取得時不重複送）。審核頁、預約管理在自己的資料有變動時也會呼叫，讓數字立刻跟上。
export async function refreshPendingApprovals(){
  if(refreshing)return;
  refreshing = true;
  try{
    const result = await fetchPendingCount();
    if(result.ok) pendingApprovals.value = result.data;
  }finally{
    refreshing = false;
  }
}
// 別處剛查到最新的筆數（例如打開鈴鐺面板時），直接更新，數字才不會和面板上的「共 N 筆」對不起來。
export function setPendingApprovals(count){
  pendingApprovals.value = count;
}
function handleVisibilityChange(){
  if (!document.hidden) refreshPendingApprovals();
}
// 開始定時更新（先查一次）。重複呼叫不會開出多個計時器。
export function startPendingApprovalsPolling(){
  stopPendingApprovalsPolling();
  refreshPendingApprovals();
  timer = setInterval(() => {
    if (!document.hidden) refreshPendingApprovals();
  }, POLL_INTERVAL_MS);
  document.addEventListener('visibilitychange', handleVisibilityChange);
}
export function stopPendingApprovalsPolling(){
  if(timer !== null)clearInterval(timer);
  timer = null;
  document.removeEventListener('visibilitychange', handleVisibilityChange);
}
