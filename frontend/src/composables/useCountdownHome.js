// 倒數結束（或手動呼叫 finish）後清除本次資料並回到首頁
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { resetVisit } from '@/stores/visit'
import { DEFAULT_LOCALE, setLocale } from '@/i18n'
import { debug } from '@/utils/logger'

export function useCountdownHome(seconds = 10) {
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

  onMounted(() => {
    timer = setInterval(() => {
      remaining.value -= 1
      if (remaining.value <= 0) finish()
    }, 1000)
  })
  onBeforeUnmount(() => clearInterval(timer))

  return { remaining, finish }
}
