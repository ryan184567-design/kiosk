// 印表機測試：依 .env 的 PRINTER_* 設定列印一張範例訪客證
// 用法：npm run print:test [-- QR內容 訪客證號 證件櫃位]（櫃位傳 - 表示不列印）
import dotenv from 'dotenv'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
dotenv.config({ path: path.resolve(__dirname, '../../.env'), quiet: true })

const { printBadge } = await import('./index.js')

const [qrContent = '123456789', badgeNo = 'V000001', locker = 'A01'] = process.argv.slice(2)
try {
  const r = await printBadge({
    qrContent,
    badgeNo,
    locker: locker === '-' ? '' : locker,
    printedAt: new Date().toISOString(),
  })
  console.log(`列印成功（${r.type}，${r.bytes} bytes）`)
  console.log(r.preview)
  if (r.type === 'mock') console.log('（mock 模式：內容已存到 logs/print-last.bin / print-last.txt）')
} catch (err) {
  console.error('列印失敗：', err.message)
  process.exit(1)
}
