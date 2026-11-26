/* *****************************************
File: chartSetup.js
Author: Callum
Date: 2026-11-19
Description: Chart.js 共用設定：只註冊用到的圖表類型（按需引入）、統一字型與動畫。
***************************************** */
import { ArcElement, BarController, BarElement, CategoryScale, Chart, DoughnutController, Filler, Legend, LineController, LineElement, LinearScale, PointElement, Tooltip } from 'chart.js';
// 只註冊趨勢折線、圓環圖、長條圖用到的元件，其餘不打包進來，檔案才不會膨脹。
Chart.register(ArcElement, BarController, BarElement, CategoryScale, DoughnutController, Filler, Legend, LineController, LineElement, LinearScale, PointElement, Tooltip);
// 圖例（2026-11-26 為了多組資料的圖註冊）預設關閉，需要的圖自己打開（plugins.legend.display: true），
// 原本單一資料的圖（每日趨勢、樓層分布）與自己畫 HTML 圖例的圓環圖都不受影響。
Chart.defaults.plugins.legend.display = false;
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.legend.labels.boxHeight = 8;
const rootStyle = () => getComputedStyle(document.documentElement);
// canvas 讀不到 CSS 的 inherit，字型要取實際值。
Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
Chart.defaults.font.size = 12;
Chart.defaults.color = '#6b7280';
// 動畫比照全站「過渡 300ms」的規則；Chart.js 沒有 CSS 的 ease，用最接近的 easeInOutQuad。
// 注意：只能逐項修改，不能整個換掉 Chart.defaults.animation——Chart.js 會用這個物件的欄位
// 清單（type、fn、delay…）辨識動畫設定，換掉後顏色動畫會找不到計算方式，
// 丟出「this._fn is not a function」（已實測踩過）。
Chart.defaults.animation.duration = 300;
Chart.defaults.animation.easing = 'easeInOutQuad';
Chart.defaults.transitions.active.animation.duration = 300;
// 讀取 CSS 變數的實際色值（圖表畫在 canvas 上，不能直接寫 var()）。
export function cssColor(name, fallback) {
  return rootStyle().getPropertyValue(name).trim() || fallback;
}
export const primaryColor = () => cssColor('--el-color-primary', '#409eff');
// 圓環圖等用的色盤，順序對應 base.css 的 --chart-1 ～ --chart-8（共 CHART_PALETTE_SIZE 色）。
// 顏色不夠時不要循環重用（會有兩塊同色），超出的類別要合併成「其他」，見 ReasonDonut.vue。
export const CHART_PALETTE_SIZE = 8;
export function chartPalette() {
  return Array.from({ length: CHART_PALETTE_SIZE }, (_, i) => cssColor(`--chart-${i + 1}`, '#999999'));
}
// '#1d6bff' → 'rgba(29, 107, 255, 0.2)'，用來做折線下方的漸層底色。
export function withAlpha(hex, alpha) {
  const match = /^#([0-9a-f]{6})$/i.exec(hex);
  if (!match) return hex;
  const n = parseInt(match[1], 16);
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}
export { Chart };
