import { createApp } from 'vue'
import 'ant-design-vue/dist/reset.css'
import './styles/global.css'
import App from './App.vue'
import router from './router'
import { i18n } from './i18n'
import { debug, installErrorLogging, setLogSource } from './utils/logger'
import { appConfig } from './config'
import { connectRealtime } from './realtime/socket'
import { setupVisitorSync } from './realtime/visitorSync'

// 同一個前端提供兩種畫面：/staff 為櫃台螢幕，其餘為訪客螢幕
const role = location.pathname.startsWith('/staff') ? 'staff' : 'visitor'
setLogSource(role)

debug('App', `${role === 'staff' ? '櫃台螢幕' : '訪客螢幕'}啟動`, {
  mode: import.meta.env.MODE,
  config: appConfig,
  userAgent: navigator.userAgent,
})

connectRealtime(role)
if (role === 'visitor') setupVisitorSync(router)

const app = createApp(App)
installErrorLogging(app)
app.use(i18n).use(router).mount('#app')
