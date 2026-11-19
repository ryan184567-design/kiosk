import { createRouter, createWebHistory } from 'vue-router'
// 訪客機會長時間開著同一頁面：所有頁面一次載入，避免伺服器更新檔案後，切換頁面時找不到舊版檔案而卡住
import HomeView from '@/views/HomeView.vue'
import AppointmentScanView from '@/views/AppointmentScanView.vue'
import VerifyingView from '@/views/VerifyingView.vue'
import VerifiedView from '@/views/VerifiedView.vue'
import IdentityView from '@/views/IdentityView.vue'
import LockerView from '@/views/LockerView.vue'
import AgreementView from '@/views/AgreementView.vue'
import PrintingView from '@/views/PrintingView.vue'
import NotifiedView from '@/views/NotifiedView.vue'
import WalkInFloorView from '@/views/walkin/FloorView.vue'
import WalkInCompanyView from '@/views/walkin/CompanyView.vue'
import WalkInFormView from '@/views/walkin/FormView.vue'
import WalkInDoneView from '@/views/walkin/DoneView.vue'
import NotFoundView from '@/views/NotFoundView.vue'
import StaffView from '@/views/StaffView.vue'
import { isLockerComplete, needsLocker, visit } from '@/stores/visit'
import { debug, logError, logWarning } from '@/utils/logger'

// meta.mode：此頁限定的到訪方式
// meta.requires：進入此頁前必須完成的步驟，未完成則導回首頁（避免直接輸入網址跳步驟）
const routes = [
  { path: '/', name: 'home', component: HomeView },
  {
    path: '/appointment',
    name: 'appointment-scan',
    component: AppointmentScanView,
    meta: { mode: 'appointment' },
  },
  {
    path: '/appointment/verifying',
    name: 'appointment-verifying',
    component: VerifyingView,
    meta: { mode: 'appointment' },
  },
  {
    path: '/appointment/verified',
    name: 'appointment-verified',
    component: VerifiedView,
    meta: { mode: 'appointment', requires: 'visitor' },
  },
  {
    path: '/identity',
    name: 'identity',
    component: IdentityView,
    meta: { mode: 'appointment', requires: 'visitor' },
  },
  {
    path: '/locker',
    name: 'locker',
    component: LockerView,
    // 訪客自行點選證件存放格位
    meta: { mode: 'appointment', requires: 'idCard' },
  },
  {
    path: '/agreement',
    name: 'agreement',
    component: AgreementView,
    meta: { mode: 'appointment', requires: 'locker' },
  },
  {
    path: '/printing',
    name: 'printing',
    component: PrintingView,
    meta: { mode: 'appointment', requires: 'agreed' },
  },
  {
    path: '/notified',
    name: 'notified',
    component: NotifiedView,
    meta: { mode: 'appointment', requires: 'badge' },
  },

  // ── 無預約 ──
  {
    path: '/walk-in',
    name: 'walk-in-floor',
    component: WalkInFloorView,
    meta: { mode: 'walkIn' },
  },
  {
    path: '/walk-in/company',
    name: 'walk-in-company',
    component: WalkInCompanyView,
    meta: { mode: 'walkIn', requires: 'floor' },
  },
  {
    path: '/walk-in/form',
    name: 'walk-in-form',
    component: WalkInFormView,
    meta: { mode: 'walkIn', requires: 'company' },
  },
  {
    path: '/walk-in/done',
    name: 'walk-in-done',
    component: WalkInDoneView,
    meta: { mode: 'walkIn', requires: 'registered' },
  },

  // ── 櫃台螢幕 ──
  {
    path: '/staff',
    name: 'staff',
    component: StaffView,
    meta: { staff: true, noIdle: true },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: NotFoundView,
  },
]

const STEP_CHECKS = {
  visitor: () => !!visit.visitor,
  idCard: () => !!visit.visitor && needsLocker(),
  locker: () => !!visit.visitor && isLockerComplete(),
  agreed: () => !!visit.visitor && isLockerComplete() && visit.agreed,
  badge: () => !!visit.badge,
  floor: () => !!visit.walkIn.floor,
  company: () => !!visit.walkIn.floor && !!visit.walkIn.company,
  registered: () => !!visit.registration,
}

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
})

router.beforeEach((to) => {
  const { mode, requires } = to.meta
  if (mode && visit.mode !== mode) {
    logWarning('Route', `拒絕進入 ${to.fullPath}：到訪方式不符（需 ${mode}，目前 ${visit.mode}），導回首頁`)
    return { path: '/', replace: true }
  }
  if (requires && !STEP_CHECKS[requires]()) {
    logWarning('Route', `拒絕進入 ${to.fullPath}：未完成前置步驟「${requires}」，導回首頁`)
    return { path: '/', replace: true }
  }
})

router.afterEach((to, from, failure) => {
  if (failure) return
  debug('Route', `${from.fullPath} → ${to.fullPath}`, { visit })
})

// 換頁發生錯誤（例如載入失敗）時記錄，並一律輸出到 console 方便排查
router.onError((err, to) => {
  logError('Route', `換頁失敗：${to?.fullPath}：${err?.message}`, err)
  console.error('[visitor] 換頁失敗', to?.fullPath, err)
})

export default router
