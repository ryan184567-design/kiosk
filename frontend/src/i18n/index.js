import { computed } from 'vue'
import { createI18n } from 'vue-i18n'
import { debug } from '@/utils/logger'
import zhTW from './locales/zh-TW'
import en from './locales/en'
import ja from './locales/ja'

export const DEFAULT_LOCALE = 'zh-TW'

export const LOCALES = [
  { value: 'zh-TW', label: '中文' },
  { value: 'en', label: 'English' },
  { value: 'ja', label: '日本語' },
]

export const i18n = createI18n({
  legacy: false,
  locale: DEFAULT_LOCALE,
  fallbackLocale: DEFAULT_LOCALE,
  messages: { 'zh-TW': zhTW, en, ja },
})

export const currentLocale = computed(() => i18n.global.locale.value)

export function setLocale(locale) {
  if (i18n.global.locale.value !== locale) debug('App', `切換語系：${i18n.global.locale.value} → ${locale}`)
  i18n.global.locale.value = locale
  document.documentElement.lang = locale
}
