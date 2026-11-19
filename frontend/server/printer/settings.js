// 印表機設定：讀取 .env 的 PRINTER_*（每次列印時讀取，櫃台「系統設定」修改後立即生效），
// 以及列出本機印表機 / 連接埠（供設定介面下拉選單）
import { execFile } from 'node:child_process'
import fs from 'node:fs'

const oneOf = (list, def) => (v) => (list.includes(String(v).toLowerCase()) ? String(v).toLowerCase() : def)
const int = (min, max, def) => (v) => {
  const n = Number(v)
  return Number.isInteger(n) && n >= min && n <= max ? n : def
}

/** 目前生效的印表機設定（無效值改用預設） */
export function getPrinterSettings() {
  const env = process.env
  return {
    type: oneOf(['mock', 'usb', 'windows', 'device', 'cups', 'network'], 'mock')(env.PRINTER_TYPE || 'mock'),
    name: (env.PRINTER_NAME || '').trim(),
    device: (env.PRINTER_DEVICE || '').trim(),
    host: (env.PRINTER_HOST || '').trim(),
    port: int(1, 65535, 9100)(env.PRINTER_PORT),
    encoding: oneOf(['big5', 'gb18030', 'shift_jis', 'ascii'], 'big5')(env.PRINTER_ENCODING || 'big5'),
    chineseMode: String(env.PRINTER_CHINESE_MODE ?? 'true').toLowerCase() !== 'false',
    paperWidth: Number(env.PRINTER_PAPER_WIDTH) <= 58 ? 58 : 80,
    qrSize: int(1, 16, 8)(env.PRINTER_QR_SIZE),
    qrMode: oneOf(['image', 'native'], 'image')(env.PRINTER_QR_MODE || 'image'),
    title: (env.PRINTER_TITLE || '').trim() || '訪客證',
  }
}

const run = (cmd, args) =>
  new Promise((resolve) => {
    execFile(cmd, args, { timeout: 15000, windowsHide: true }, (err, stdout) => resolve(err ? '' : stdout.trim()))
  })

const parseJson = (s) => {
  try {
    return s ? [].concat(JSON.parse(s)) : []
  } catch {
    return []
  }
}

/** 列出可選的印表機與連接埠：{ printers: [{ name, port, isDefault }], devices: [{ value, label, ok }] } */
export async function listPrinterDevices() {
  if (process.platform === 'win32') {
    const ps = (script) =>
      run('powershell.exe', ['-NoProfile', '-Command', `[Console]::OutputEncoding=[Text.Encoding]::UTF8; ${script}`])
    const [printers, ports] = await Promise.all([
      ps('Get-CimInstance Win32_Printer | Select-Object Name, PortName, Default | ConvertTo-Json -Compress'),
      ps(
        `Get-CimInstance Win32_PnPEntity -Filter "Name LIKE '%(COM%'" | Select-Object Name, Status | ConvertTo-Json -Compress`,
      ),
    ])
    return {
      printers: parseJson(printers)
        .map((p) => ({ name: p.Name, port: p.PortName, isDefault: !!p.Default }))
        .sort((a, b) => a.name.localeCompare(b.name)),
      devices: parseJson(ports)
        .map((p) => ({ value: /\((COM\d+)\)/i.exec(p.Name)?.[1]?.toUpperCase(), label: p.Name, ok: p.Status === 'OK' }))
        .filter((d) => d.value)
        .sort((a, b) => Number(a.value.slice(3)) - Number(b.value.slice(3))),
    }
  }

  const devices = []
  for (const dir of ['/dev/usb', '/dev']) {
    try {
      for (const f of fs.readdirSync(dir)) {
        if ((dir === '/dev/usb' && f.startsWith('lp')) || (dir === '/dev' && /^tty(USB|ACM)\d+$/.test(f))) {
          devices.push({ value: `${dir}/${f}`, label: `${dir}/${f}`, ok: true })
        }
      }
    } catch {
      /* 目錄不存在 */
    }
  }
  const lpstat = await run('lpstat', ['-p'])
  const printers = [...lpstat.matchAll(/^printer (\S+)/gm)].map((m) => ({ name: m[1], port: '', isDefault: false }))
  return { printers, devices }
}
