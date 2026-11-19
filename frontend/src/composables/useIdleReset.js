// 無操作一段時間後，自動清除本次資料並回到首頁（避免下一位訪客看到上一位的資料）
import { onBeforeUnmount, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { resetVisit } from '@/stores/visit'
import { DEFAULT_LOCALE, currentLocale, setLocale } from '@/i18n'
import { debug } from '@/utils/logger'

const EVENTS = ['pointerdown', 'keydown', 'touchstart']

export function useIdleReset(seconds = 120) {
  const router = useRouter()
  const route = useRoute()
  let timer = null

  const onIdle = () => {
    debug('Flow', `閒置 ${seconds} 秒無操作，自動重置並回首頁（目前頁面 ${route.path}）`)
    resetVisit()
    if (currentLocale.value !== DEFAULT_LOCALE) setLocale(DEFAULT_LOCALE)
    if (route.path !== '/') router.replace('/')
  }

  const restart = () => {
    clearTimeout(timer)
    // 櫃台螢幕、等待櫃台處理的頁面（meta.noIdle）不倒數
    if (route.meta.noIdle) return
    // 已在首頁且為預設語系時不需倒數
    if (route.path === '/' && currentLocale.value === DEFAULT_LOCALE) return
    timer = setTimeout(onIdle, seconds * 1000)
  }

  onMounted(() => {
    EVENTS.forEach((e) => window.addEventListener(e, restart, { passive: true }))
    restart()
  })
  onBeforeUnmount(() => {
    clearTimeout(timer)
    EVENTS.forEach((e) => window.removeEventListener(e, restart))
  })
  watch(() => [route.path, currentLocale.value], restart)
}
