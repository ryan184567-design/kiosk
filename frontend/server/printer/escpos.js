// ESC/POS 指令產生器（適用一般熱感應出單機 / 出餐機，Epson 相容指令）
import iconv from 'iconv-lite'
import QRCode from 'qrcode'

const ESC = 0x1b
const GS = 0x1d
const FS = 0x1c

const QR_EC_LEVEL = { L: 48, M: 49, Q: 50, H: 51 }

export class EscPos {
  /**
   * @param {object} opts
   * @param {string} opts.encoding  文字編碼：big5（繁中，預設）| gb18030 | shift_jis | ascii …
   * @param {boolean} opts.chineseMode 是否送出 FS & 進入中文模式（中文韌體印表機需要）
   */
  constructor({ encoding = 'big5', chineseMode = true } = {}) {
    this.encoding = encoding.toLowerCase()
    this.chineseMode = chineseMode
    this.chunks = []
  }

  raw(...bytes) {
    this.chunks.push(Buffer.from(bytes.flat()))
    return this
  }

  /** 初始化印表機 */
  init() {
    this.raw(ESC, 0x40)
    if (this.chineseMode) this.raw(FS, 0x26)
    return this
  }

  text(str) {
    const buf =
      this.encoding === 'ascii' ? Buffer.from(str, 'latin1') : iconv.encode(str, this.encoding)
    this.chunks.push(buf)
    return this
  }

  line(str = '') {
    return this.text(`${str}\n`)
  }

  /** 對齊：left | center | right */
  align(a = 'left') {
    return this.raw(ESC, 0x61, { left: 0, center: 1, right: 2 }[a] ?? 0)
  }

  bold(on = true) {
    return this.raw(ESC, 0x45, on ? 1 : 0)
  }

  /** 字體放大倍率（1~8） */
  size(width = 1, height = 1) {
    return this.raw(GS, 0x21, ((width - 1) << 4) | (height - 1))
  }

  /**
   * 列印 QR Code
   * @param {string} data 內容
   * @param {object} opts size：模組大小 1~16；ec：容錯等級 L/M/Q/H；
   *   mode：image（預設，轉成點陣圖列印，所有機型皆可）| native（印表機內建 QR 指令 GS ( k）；
   *   width：image 模式的可列印寬度（點），80mm = 576、58mm = 384
   */
  qr(data, { size = 8, ec = 'M', mode = 'image', width = 576 } = {}) {
    if (mode === 'native') return this.qrNative(data, { size, ec })
    return this.qrImage(data, { size, ec, width })
  }

  /** QR Code：轉成點陣圖後以 GS v 0 光柵指令列印（置中） */
  qrImage(data, { size = 8, ec = 'M', width = 576 } = {}) {
    const { modules } = QRCode.create(String(data), { errorCorrectionLevel: ec })
    const quiet = 2 // 左右留白（模組數）
    const count = modules.size + quiet * 2
    const scale = Math.max(1, Math.min(16, size, Math.floor(width / count)))
    const bytesPerRow = Math.ceil(width / 8)
    const offset = Math.floor((width - count * scale) / 2) + quiet * scale // 置中
    const totalRows = modules.size * scale

    const bitmap = Buffer.alloc(bytesPerRow * totalRows)
    for (let y = 0; y < totalRows; y++) {
      const r = Math.floor(y / scale)
      for (let c = 0; c < modules.size; c++) {
        if (!modules.get(r, c)) continue
        for (let dx = 0; dx < scale; dx++) {
          const x = offset + c * scale + dx
          bitmap[y * bytesPerRow + (x >> 3)] |= 0x80 >> (x & 7)
        }
      }
    }

    // 分段送出（部分機型單次光柵高度有限制）
    const BAND = 128
    for (let y = 0; y < totalRows; y += BAND) {
      const rows = Math.min(BAND, totalRows - y)
      this.raw(GS, 0x76, 0x30, 0x00, bytesPerRow & 0xff, bytesPerRow >> 8, rows & 0xff, rows >> 8)
      this.chunks.push(bitmap.subarray(y * bytesPerRow, (y + rows) * bytesPerRow))
    }
    return this
  }

  /** QR Code：使用印表機內建 QR 指令（部分機型不支援） */
  qrNative(data, { size = 8, ec = 'M' } = {}) {
    const payload = Buffer.from(String(data), 'utf8')
    const len = payload.length + 3
    this.raw(GS, 0x28, 0x6b, 0x04, 0x00, 0x31, 0x41, 0x32, 0x00) // Model 2
    this.raw(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x43, Math.min(16, Math.max(1, size))) // 模組大小
    this.raw(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x45, QR_EC_LEVEL[ec] ?? QR_EC_LEVEL.M) // 容錯
    this.raw(GS, 0x28, 0x6b, len & 0xff, (len >> 8) & 0xff, 0x31, 0x50, 0x30) // 存入資料
    this.chunks.push(payload)
    this.raw(GS, 0x28, 0x6b, 0x03, 0x00, 0x31, 0x51, 0x30) // 列印
    return this
  }

  /** 進紙 n 行 */
  feed(n = 1) {
    return this.raw(ESC, 0x64, n)
  }

  /** 進紙後部分裁切（無裁刀的機型會忽略） */
  cut() {
    return this.raw(GS, 0x56, 0x42, 0x00)
  }

  build() {
    return Buffer.concat(this.chunks)
  }
}
