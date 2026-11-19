// 證件掃描器測試：依 .env 的 SCANNER_* 設定連線 Plustek WebFXScan，等待插入一張證件，
// 在畫面上列出「原始辨識資料」與轉換後的結果，用於確認欄位對應（見 parse.js）。
// 用法：npm run idcard:test           （等待插入證件，自動掃描）
//       npm run idcard:test -- --now  （立即掃描已放入的證件）
// ※ 原始資料含個資，只輸出到畫面，不寫入記錄檔；請勿截圖外傳。
import dotenv from 'dotenv'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
dotenv.config({ path: path.resolve(__dirname, '../../.env'), quiet: true })

const { ScannerService, readScannerConfig } = await import('./index.js')

const config = { ...readScannerConfig(), type: 'plustek' }
const scanNowFlag = process.argv.includes('--now')
const WAIT_SECONDS = 120

console.log(`連線 Plustek WebFXScan：ws://${config.host}:${config.port}/webscan2`)
console.log(`辨識類型：${config.recognizeType}；裝置：${config.device || '（自動）'}；來源：${config.source || '（自動）'}\n`)

let pageNo = 0
let started = false
const done = (code) => {
  service.stop()
  setTimeout(() => process.exit(code), 200)
}

const service = new ScannerService(config, {
  onStatus: (s) => {
    if (s.ready && !started) {
      started = true
      console.log(`✔ 掃描器就緒：${s.device}（${s.source}）`)
      if (scanNowFlag) {
        service.scanNow().catch(() => {})
      } else {
        console.log(`請在 ${WAIT_SECONDS} 秒內插入證件…`)
        service.arm()
      }
    }
  },
  onScanning: () => console.log('… 掃描中'),
  onPage: (data) => {
    pageNo += 1
    console.log(`\n── 第 ${pageNo} 頁原始辨識資料（recognizedata）──`)
    console.dir(data, { depth: 10 })
  },
  onResult: (card) => {
    console.log('\n── 轉換結果（存入系統的資料）──')
    console.log(card)
    done(0)
  },
  onError: (err) => {
    console.error(`\n✕ 掃描失敗：${err.message}（${err.code}）`)
    if (err.code === 'NO_DATA') console.error('  請將上方原始資料的欄位名稱提供給開發人員調整 server/idscanner/parse.js')
    done(1)
  },
}).start()

setTimeout(() => {
  const s = service.getStatus()
  console.error(
    `\n✕ ${WAIT_SECONDS} 秒內未完成：${
      !s.connected ? '無法連線掃描服務（請確認已安裝 Plustek SDK、服務已啟動、埠號正確）' : s.error || '未偵測到證件'
    }`,
  )
  done(1)
}, WAIT_SECONDS * 1000)
