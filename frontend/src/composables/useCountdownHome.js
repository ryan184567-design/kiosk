// 倒數結束（或手動呼叫 finish）後清除本次資料並回到首頁
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { resetVisit } from '@/stores/visit'
import { DEFAULT_LOCALE, setLocale } from '@/i18n'
import { debug } from '@/utils/logger'

/**
 * @param {number} seconds 倒數秒數
 * @param {{ autoStart?: boolean }} [options] autoStart=false 時不在掛載時倒數，需自行呼叫 start()
 */
export function useCountdownHome(seconds = 10, { autoStart = true } = {}) {
  const router = useRouter()
  const remaining = ref(seconds)
  let timer

  const finish = () => {
    debug('Flow', remaining.value <= 0 ? '倒數結束，返回首頁' : '訪客按下完成，返回首頁')
    clearInterval(timer)
    resetVisit()
    setLocale(DEFAULT_LOCALE)
    router.replace('/')
  }

  /** 開始（或重新開始）倒數 */
  const start = () => {
    clearInterval(timer)
    remaining.value = seconds
    timer = setInterval(() => {
      remaining.value -= 1
      if (remaining.value <= 0) finish()
    }, 1000)
  }

  onMounted(() => {
    if (autoStart) start()
  })
  onBeforeUnmount(() => clearInterval(timer))

  return { remaining, finish, start }
}
