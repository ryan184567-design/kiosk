<script setup>
import { computed } from 'vue'
import zhTW from 'ant-design-vue/es/locale/zh_TW'
import enUS from 'ant-design-vue/es/locale/en_US'
import jaJP from 'ant-design-vue/es/locale/ja_JP'
import { currentLocale } from '@/i18n'
import { useIdleReset } from '@/composables/useIdleReset'
import { appConfig } from '@/config'

const ANTD_LOCALES = { 'zh-TW': zhTW, en: enUS, ja: jaJP }
const antdLocale = computed(() => ANTD_LOCALES[currentLocale.value] || zhTW)

const theme = {
  token: {
    colorPrimary: '#1554c0',
    borderRadius: 10,
    fontSize: 16,
    fontFamily: "'Noto Sans TC', 'Microsoft JhengHei', 'PingFang TC', system-ui, sans-serif",
  },
}

// 無操作自動回首頁秒數（.env VITE_IDLE_SECONDS，見 src/config.js）
useIdleReset(appConfig.idleSeconds)
</script>

<template>
  <a-config-provider :locale="antdLocale" :theme="theme" :auto-insert-space-in-button="false">
    <router-view v-slot="{ Component, route }">
      <!-- 櫃台螢幕不需要換頁動畫 -->
      <transition :name="route.meta.staff ? 'none' : 'page'" mode="out-in">
        <component :is="Component" />
      </transition>
    </router-view>
  </a-config-provider>
</template>
