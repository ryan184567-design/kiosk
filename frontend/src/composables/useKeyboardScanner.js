// 鍵盤模擬掃描器（QR Code 掃描器、門禁卡讀卡機）共用邏輯
//
// 這類裝置一般為「鍵盤模擬」輸入：短時間內連續送出字元並以 Enter 結尾
// 以字元間隔判斷是否為掃描器：間隔超過 SCAN_GAP_MS 視為人工按鍵，暫存會被清空
//
// 掃描器輸入需要瀏覽器頁面擁有鍵盤焦點；若焦點在網址列、DevTools 或其他視窗，網頁收不到按鍵。
// 進入頁面時主動把焦點移到頁面上，並提供掃描器是否可接收的狀態（scanReady）。
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { debug } from '@/utils/logger'

const SCAN_GAP_MS = 50

/**
 * @param {object} options
 * @param {(code: string) => void} options.onScan 收到完整掃描內容（已去除前後空白）
 * @param {string} [options.tag] debug 記錄前綴，例如「②」
 * @param {number} [options.minLength] 少於此長度不視為掃描內容
 * @returns {{ rootRef, scanReady, grabFocus }} rootRef 綁定到可聚焦（tabindex="-1"）的根元素
 */
export function useKeyboardScanner({ onScan, tag = '', minLength = 6 }) {
  const log = (msg) => debug('Flow', tag ? `${tag} ${msg}` : msg)

  let buffer = ''
  let lastTime = 0
  // 以下僅供 debug 診斷：自上次 Enter 起收到的按鍵數與最大按鍵間隔
  let rawCount = 0
  let maxGap = 0

  const onKeydown = (e) => {
    const now = Date.now()
    const gap = lastTime ? now - lastTime : 0
    if (gap > SCAN_GAP_MS) buffer = ''
    lastTime = now

    if (e.key === 'Enter') {
      e.preventDefault() // 避免同時觸發目前聚焦中的按鈕
      log(
        `收到 Enter：有效暫存 ${buffer.length} 字元（共收到 ${rawCount} 個按鍵，最大間隔 ${maxGap}ms，判斷門檻 ${SCAN_GAP_MS}ms）`,
      )
      if (buffer.length >= minLength) onScan(buffer.trim())
      else log(`不視為掃描內容：有效暫存少於 ${minLength} 字元`)
      buffer = ''
      rawCount = 0
      maxGap = 0
      return
    }
    if (e.key.length === 1) {
      if (rawCount > 0) maxGap = Math.max(maxGap, gap)
      rawCount += 1
      buffer += e.key
    }
  }

  // ── 鍵盤焦點 ──
  const rootRef = ref()
  const scanReady = ref(document.hasFocus())

  const grabFocus = () => {
    window.focus()
    rootRef.value?.focus({ preventScroll: true })
    scanReady.value = document.hasFocus()
  }
  const onWindowFocus = () => {
    scanReady.value = true
    log('頁面取得焦點，掃描器可接收')
  }
  const onWindowBlur = () => {
    scanReady.value = false
    log('頁面失去焦點，掃描器輸入將無法接收')
  }

  onMounted(() => {
    window.addEventListener('keydown', onKeydown)
    window.addEventListener('focus', onWindowFocus)
    window.addEventListener('blur', onWindowBlur)
    grabFocus()
    log(`進入掃描頁，頁面焦點：${scanReady.value ? '有' : '無（需點擊畫面）'}`)
  })
  onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown)
    window.removeEventListener('focus', onWindowFocus)
    window.removeEventListener('blur', onWindowBlur)
  })

  return { rootRef, scanReady, grabFocus }
}
