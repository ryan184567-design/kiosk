// 櫃台 / 訪客螢幕即時同步：socket.io 用戶端（單例）
// 事件說明見 server/realtime.js
import { reactive } from 'vue'
import { io } from 'socket.io-client'
import { debug } from '@/utils/logger'

export const realtime = reactive({
  role: null, // 'visitor' | 'staff'
  connected: false, // 是否已連上伺服器
  presence: { visitor: false, staff: false }, // 兩端螢幕是否在線
  scanner: null, // 證件掃描器狀態 { type, connected, ready, device, armed, scanning, error }
})

let socket = null

/** 建立連線（同一頁面只會建立一次） */
export function connectRealtime(role) {
  if (socket) return socket
  realtime.role = role
  socket = io({ query: { role }, transports: ['websocket', 'polling'] })

  socket.on('connect', () => {
    realtime.connected = true
    debug('Socket', `已連線伺服器（角色：${role}）`)
  })
  socket.on('disconnect', (reason) => {
    realtime.connected = false
    realtime.presence = { visitor: false, staff: false }
    realtime.scanner = null
    debug('Socket', `與伺服器中斷連線：${reason}`)
  })
  socket.on('presence', (p) => {
    realtime.presence = p
    debug('Socket', `在線狀態：訪客螢幕 ${p.visitor ? '在線' : '離線'}、櫃台螢幕 ${p.staff ? '在線' : '離線'}`)
  })
  socket.on('scanner:status', (s) => {
    realtime.scanner = s
  })
  socket.onAny((event, ...args) => {
    if (event !== 'presence' && event !== 'scanner:status') debug('Socket', `← ${event}`, ...args)
  })
  return socket
}

/** 送出事件 */
export function emit(event, data) {
  if (!socket) return
  debug('Socket', `→ ${event}`, data)
  socket.emit(event, data)
}

/** 監聽事件，回傳取消監聽的函式 */
export function on(event, handler) {
  socket?.on(event, handler)
  return () => socket?.off(event, handler)
}
