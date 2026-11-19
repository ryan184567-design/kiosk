// 前端執行時設定
//   正式服務：由伺服器注入 index.html（window.__APP_CONFIG__，來源為 .env，見 server/envSettings.js getClientConfig），
//             櫃台「系統設定」修改後重新整理即生效，不需重新 build
//   開發模式（npm run dev）：沒有注入，改用 build 時的 import.meta.env.VITE_*
const injected = (typeof window !== 'undefined' && window.__APP_CONFIG__) || {}
const env = import.meta.env

const bool = (v) => String(v).trim().toLowerCase() === 'true'

export const appConfig = Object.freeze({
  /** 無操作自動回首頁秒數 */
  idleSeconds: Number(injected.idleSeconds ?? env.VITE_IDLE_SECONDS) || 120,
  /** 速通碼長度 */
  passCodeLength: Number(injected.passCodeLength ?? env.VITE_PASS_CODE_LENGTH) || 10,
  /** Debug 模式（輸出到瀏覽器 console） */
  debug: injected.debug ?? bool(env.VITE_DEBUG),
  /** 前端送到伺服器寫檔的最低等級 */
  logLevel: String(injected.logLevel ?? env.VITE_LOG_LEVEL ?? 'info')
    .trim()
    .toLowerCase(),
})
