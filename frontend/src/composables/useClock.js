import { computed, onBeforeUnmount, ref } from 'vue'
import { currentLocale } from '@/i18n'

const pad = (n) => String(n).padStart(2, '0')

/** 回傳格式：2025/07/10 (四) 10:24（星期依目前語系顯示） */
export function useClock() {
  const now = ref(new Date())
  const timer = setInterval(() => (now.value = new Date()), 1000)
  onBeforeUnmount(() => clearInterval(timer))

  return computed(() => {
    const d = now.value
    const weekday = new Intl.DateTimeFormat(currentLocale.value, {
      weekday: currentLocale.value === 'en' ? 'short' : 'narrow',
    }).format(d)
    return `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())} (${weekday}) ${pad(
      d.getHours(),
    )}:${pad(d.getMinutes())}`
  })
}
