// POS 印表機（熱感應出單機）列印：組出訪客證 ESC/POS 內容並送到印表機
//
// 設定來源：.env（每次列印時讀取；櫃台螢幕 /staff 的「系統設定」修改後立即生效）
//
// .env 設定：
//   PRINTER_TYPE          mock（預設，不實際列印，存成檔案）
//                         usb     USB 印表機，依作業系統自動選擇：Windows → windows、Linux → device
//                         windows Windows 列印佇列（RAW），不需共用印表機，使用 PRINTER_NAME
//                         device  直接寫入裝置檔，使用 PRINTER_DEVICE（Linux 預設 /dev/usb/lp0）
//                         cups    Linux CUPS（lp -o raw），使用 PRINTER_NAME
//                         network 網路印表機，使用 PRINTER_HOST / PRINTER_PORT
//   PRINTER_NAME          windows / cups：印表機名稱（npm run print:list 查詢；Windows 留空 = 預設印表機）
//   PRINTER_DEVICE        device：Linux 例 /dev/usb/lp0；Windows 填 COM 埠，例 COM9（直接寫入，不經列印佇列）
//   PRINTER_HOST          network：印表機 IP
//   PRINTER_PORT          network：埠號，預設 9100
//   PRINTER_ENCODING      文字編碼，預設 big5（繁體中文）
//   PRINTER_CHINESE_MODE  是否送出中文模式指令，預設 true
//   PRINTER_PAPER_WIDTH   紙寬 mm：80（預設，每行 48 字元）| 58（每行 32 字元）
//   PRINTER_QR_SIZE       QR Code 模組大小 1~16，預設 8
//   PRINTER_QR_MODE       image（預設，點陣圖列印，所有機型可用）| native（印表機內建 QR 指令，部分機型不支援）
//   PRINTER_TITLE         訪客證標題，預設「訪客證」
import { execFile } from 'node:child_process'
import fs from 'node:fs/promises'
import net from 'node:net'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { EscPos } from './escpos.js'
import { logger } from '../logger.js'
import { getPrinterSettings } from './settings.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const LOG_DIR = path.resolve(__dirname, '../../logs')
const RAW_PRINT_PS1 = path.resolve(__dirname, '../../scripts/windows/raw-print.ps1')

// 每次列印時讀取（見 ./settings.js）
function getConfig() {
  const s = getPrinterSettings()
  let type = s.type
  if (type === 'usb') type = process.platform === 'win32' ? 'windows' : 'device'
  return {
    ...s,
    type,
    device: s.device || (process.platform === 'win32' ? '' : '/dev/usb/lp0'),
    title: s.title || '訪客證',
  }
}

const pad = (n) => String(n).padStart(2, '0')
function formatTime(date) {
  const d = date ? new Date(date) : new Date()
  const t = Number.isNaN(d.getTime()) ? new Date() : d
  return `${t.getFullYear()}/${pad(t.getMonth() + 1)}/${pad(t.getDate())} ${pad(t.getHours())}:${pad(
    t.getMinutes(),
  )}:${pad(t.getSeconds())}`
}

/**
 * 組出訪客證列印內容
 * @param {{ qrContent: string, badgeNo: string, locker?: string, printedAt?: string }} data
 *   locker：證件存放格位，有值時以大字列印「證件存放：A01」
 * @returns {{ buffer: Buffer, preview: string }}
 */
export function buildBadge(data, config = getConfig()) {
  const cols = config.paperWidth <= 58 ? 32 : 48
  const sep = '-'.repeat(cols)
  const time = formatTime(data.printedAt)
  const locker = String(data.locker ?? '').trim().slice(0, 20)
  // 3 倍字寬時 80mm 每行約 16 字、58mm 約 10 字；較長的格位名稱改用 2 倍
  const lockerSize = locker.length <= (config.paperWidth <= 58 ? 8 : 12) ? 3 : 2

  const p = new EscPos({ encoding: config.encoding, chineseMode: config.chineseMode })
  p.init()
    .align('center')
    .bold(true)
    .size(2, 2)
    .line(config.title)
    .size(1, 1)
    .bold(false)
    .feed(1)
    .qr(data.qrContent, {
      size: config.qrSize,
      mode: config.qrMode,
      width: config.paperWidth <= 58 ? 384 : 576,
    })
    .feed(1)
    .line(sep)
    .align('left')
    .bold(true)
    .line(`訪客證號：${data.badgeNo}`)
    .bold(false)
    .line(`列印時間：${time}`)
    .line(sep)

  // 證件存放格位：放大置中，方便訪客離開時對照取回證件
  if (locker) {
    p.align('center')
      .line('證件存放櫃位')
      .bold(true)
      .size(lockerSize, lockerSize)
      .line(locker)
      .size(1, 1)
      .bold(false)
      .line('離開時請憑此證取回證件')
      .line(sep)
  }

  p.feed(4).cut()

  // 文字預覽（mock 模式與記錄用）
  const preview = [
    config.title,
    `[QR] ${data.qrContent}`,
    sep,
    `訪客證號：${data.badgeNo}`,
    `列印時間：${time}`,
    sep,
    ...(locker ? ['證件存放櫃位', `【${locker}】`, '離開時請憑此證取回證件', sep] : []),
  ].join('\n')

  return { buffer: p.build(), preview }
}

function sendNetwork(buffer, { host, port }) {
  return new Promise((resolve, reject) => {
    if (!host) return reject(new Error('PRINTER_HOST 未設定'))
    const socket = net.createConnection({ host, port })
    socket.setTimeout(5000)
    socket.on('connect', () => socket.end(buffer))
    socket.on('timeout', () => socket.destroy(new Error(`連線印表機逾時 ${host}:${port}`)))
    socket.on('error', reject)
    socket.on('close', (hadError) => {
      if (!hadError) resolve()
    })
  })
}

// Windows 的 COM 埠（例 COM9）轉成裝置路徑 \\.\COM9（COM10 以上必須用此格式）
function resolveDevice(device) {
  if (process.platform === 'win32' && /^COM\d+:?$/i.test(device)) {
    return `\\\\.\\${device.replace(/:$/, '').toUpperCase()}`
  }
  return device
}

async function sendDevice(buffer, { device }) {
  if (!device) throw new Error('PRINTER_DEVICE 未設定')
  const target = resolveDevice(device)
  const write = fs.writeFile(target, buffer).catch((err) => {
    if (err.code === 'ENOENT') throw new Error(`找不到裝置 ${device}（印表機未接上或 COM 號碼不對）`)
    if (err.code === 'EBUSY' || err.code === 'EACCES' || err.code === 'EPERM') {
      throw new Error(`裝置 ${device} 被占用或無權限（若 Windows 印表機也設在此埠，請勿同時使用）`)
    }
    throw err
  })
  let timer
  const timeout = new Promise((_, reject) => {
    timer = setTimeout(() => reject(new Error(`寫入裝置 ${device} 逾時`)), 10000)
  })
  try {
    await Promise.race([write, timeout])
  } finally {
    clearTimeout(timer)
  }
}

// 將資料寫到暫存檔後交給外部程式列印，完成後刪除暫存檔
async function withTempFile(buffer, fn) {
  const file = path.join(os.tmpdir(), `visitor-badge-${Date.now()}-${process.pid}.bin`)
  await fs.writeFile(file, buffer)
  try {
    return await fn(file)
  } finally {
    fs.unlink(file).catch(() => {})
  }
}

function run(cmd, args) {
  return new Promise((resolve, reject) => {
    execFile(cmd, args, { timeout: 30000, windowsHide: true }, (err, stdout, stderr) => {
      if (err) reject(new Error((stderr || stdout || err.message).trim()))
      else resolve(stdout.trim())
    })
  })
}

/** Windows：透過列印佇列以 RAW 模式送出（scripts/windows/raw-print.ps1） */
function sendWindows(buffer, { name }) {
  return withTempFile(buffer, (file) => {
    const args = ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', RAW_PRINT_PS1, '-Path', file]
    if (name) args.push('-Printer', name)
    return run('powershell.exe', args)
  })
}

/** Linux：透過 CUPS 以 raw 模式送出 */
function sendCups(buffer, { name }) {
  return withTempFile(buffer, (file) => {
    const args = ['-o', 'raw']
    if (name) args.push('-d', name)
    args.push(file)
    return run('lp', args)
  })
}

async function saveMock(buffer, preview) {
  await fs.mkdir(LOG_DIR, { recursive: true })
  const file = path.join(LOG_DIR, 'print-last.bin')
  await fs.writeFile(file, buffer)
  await fs.writeFile(path.join(LOG_DIR, 'print-last.txt'), `${preview}\n`, 'utf8')
  return file
}

/** 列印訪客證 */
export async function printBadge(data) {
  if (!data?.qrContent || !data?.badgeNo) {
    const err = new Error('缺少 qrContent 或 badgeNo')
    err.status = 400
    throw err
  }
  const config = getConfig()
  const { buffer, preview } = buildBadge(data, config)

  switch (config.type) {
    case 'network':
      await sendNetwork(buffer, config)
      break
    case 'device':
      await sendDevice(buffer, config)
      break
    case 'windows':
      await sendWindows(buffer, config)
      break
    case 'cups':
      await sendCups(buffer, config)
      break
    case 'mock':
      await saveMock(buffer, preview)
      break
    default:
      throw new Error(`不支援的 PRINTER_TYPE：${config.type}`)
  }

  const lockerText = data.locker ? ` / 櫃位=${data.locker}` : ''
  logger.info('Printer', `列印訪客證（${config.type}）${data.badgeNo} / QR=${data.qrContent}${lockerText}`, {
    printer: config.name || config.device || config.host || config.type,
    bytes: buffer.length,
  })
  return { type: config.type, bytes: buffer.length, preview }
}
