/* *****************************************
File: download.js
Author: Callum
Date: 2026-11-23
Description: 把 API 回傳的檔案（Blob）存成使用者的下載檔案，供訪客記錄匯出等功能使用。
***************************************** */
import { ElMessageBox } from 'element-plus';

// 後端匯出（審核列表、訪客記錄）的筆數上限，超過的部分會直接截掉、不會回報。
export const EXPORT_LIMIT = 5000;

// 超過上限時先問使用者要不要繼續；回傳 true 才匯出。後端依時間新到舊排序，所以拿到的是最新的那些。
export async function confirmExportLimit(total) {
  if (total <= EXPORT_LIMIT) return true;
  try {
    await ElMessageBox.confirm(
      `目前條件共有 ${total} 筆，超過匯出上限 ${EXPORT_LIMIT} 筆，檔案只會包含最新的 ${EXPORT_LIMIT} 筆。要匯出全部資料，請縮小日期範圍分批匯出。`,
      '匯出筆數超過上限',
      { confirmButtonText: '仍要匯出', cancelButtonText: '取消', type: 'warning' },
    );
    return true;
  } catch {
    return false;
  }
}

// 建立暫時的下載連結並自動點擊；用完立刻釋放，避免佔用記憶體。
export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
