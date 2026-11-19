// 列出本機印表機，協助設定 .env 的 PRINTER_NAME / PRINTER_DEVICE
// 用法：npm run print:list
import { execFile } from 'node:child_process'
import fs from 'node:fs'

const run = (cmd, args) =>
  new Promise((resolve) => {
    execFile(cmd, args, { windowsHide: true }, (err, stdout, stderr) =>
      resolve(err ? `（無法執行 ${cmd}：${(stderr || err.message).trim()}）` : stdout.trim()),
    )
  })

if (process.platform === 'win32') {
  console.log('Windows 印表機（PRINTER_TYPE=usb，PRINTER_NAME 填「Name」欄位）：\n')
  const json = await run('powershell.exe', [
    '-NoProfile',
    '-Command',
    '[Console]::OutputEncoding=[Text.Encoding]::UTF8; Get-CimInstance Win32_Printer | Select-Object Name, DriverName, PortName, Default | ConvertTo-Json -Compress',
  ])
  let printers = []
  try {
    printers = [].concat(JSON.parse(json))
  } catch {
    console.log(json)
  }
  for (const p of printers.sort((a, b) => a.Name.localeCompare(b.Name))) {
    const port = p.PortName.length > 30 ? `${p.PortName.slice(0, 27)}...` : p.PortName
    console.log(`  ${p.Default ? '*' : ' '} ${p.Name.padEnd(32)} 連接埠 ${port.padEnd(32)} 驅動 ${p.DriverName}`)
  }
  console.log('\n（* 為目前帳號的預設印表機）')
  console.log('POS 印表機的連接埠通常是 USB001 / USB002…，或 USB 轉序列埠的 COM3 / COM5…')
  console.log('開機服務以 SYSTEM 帳號執行，預設印表機與登入帳號不同，請在 PRINTER_NAME 明確指定名稱。')
} else {
  const devices = fs.existsSync('/dev/usb')
    ? fs.readdirSync('/dev/usb').filter((f) => f.startsWith('lp')).map((f) => `/dev/usb/${f}`)
    : []
  console.log('USB 印表機裝置（PRINTER_TYPE=usb，PRINTER_DEVICE）：')
  console.log(devices.length ? devices.map((d) => `  ${d}`).join('\n') : '  （未偵測到 /dev/usb/lp*）')
  console.log('\nCUPS 印表機（PRINTER_TYPE=cups，PRINTER_NAME）：')
  console.log(await run('lpstat', ['-p']))
}
