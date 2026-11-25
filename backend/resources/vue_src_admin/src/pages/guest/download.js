/* *****************************************
File: download.js
Author: Callum
Date: 2026-11-23
Description: 把 API 回傳的檔案（Blob）存成使用者的下載檔案，供訪客記錄匯出等功能使用。
***************************************** */

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
