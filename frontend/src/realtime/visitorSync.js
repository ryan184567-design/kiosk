// 訪客螢幕：將目前步驟與訪客資料同步給櫃台，並接收櫃台的重置指令
import { watch } from 'vue'
import { emit, on } from './socket'
import { resetVisit, visit } from '@/stores/visit'
import { DEFAULT_LOCALE, setLocale } from '@/i18n'
import { debug, snapshot } from '@/utils/logger'

export function setupVisitorSync(router) {
  let timer = null

  const sendState = () => {
    const route = router.currentRoute.value
    emit('visitor:state', {
      route: route.fullPath,
      routeName: route.name,
      visit: snapshot(visit),
    })
  }
  // 資料可能連續變動，稍微延遲合併成一次送出
  const scheduleSend = () => {
    clearTimeout(timer)
    timer = setTimeout(sendState, 150)
  }

  watch(() => router.currentRoute.value.fullPath, scheduleSend)
  watch(visit, scheduleSend, { deep: true })
  // 連線（含斷線重連）後補送目前狀態
  on('connect', sendState)

  // 系統設定（.env 前端設定）已變更：在首頁時立即重新載入；報到進行中則等回到首頁再載入，避免中斷訪客
  let reloadPending = false
  const reloadIfIdle = () => {
    if (reloadPending && router.currentRoute.value.path === '/') location.reload()
  }
  on('config:changed', () => {
    debug('App', '系統設定已變更，回到首頁時重新載入')
    reloadPending = true
    reloadIfIdle()
  })
  watch(() => router.currentRoute.value.path, reloadIfIdle)

  // 櫃台取消 / 重置本次報到
  on('session:reset', () => {
    debug('Flow', '櫃台已重置本次報到，返回首頁')
    resetVisit()
    setLocale(DEFAULT_LOCALE)
    router.replace('/')
  })
}
