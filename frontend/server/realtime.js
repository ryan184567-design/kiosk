// 櫃台螢幕（staff）與訪客螢幕（visitor）的即時同步（socket.io）
// 目前只有一組訪客機，所有連線共用同一個頻道；以 room 區分角色。
//
// 事件：
//   visitor → staff   visitor:state    訪客每次換頁 / 狀態變更的快照 { route, routeName, visit }
//                                      （含訪客自行選擇的證件存放格位 visit.locker）
//   staff → visitor   session:reset    櫃台取消 / 重置本次報到
//   server → all      presence         { visitor: boolean, staff: boolean }
//   server → staff    session:update   目前訪客狀態（連線時立即補發）
//   server → all      config:changed   系統設定的前端設定（VITE_*）已變更，訪客螢幕回到首頁時重新載入
//                                      （由 server/settingsRoutes.js 送出）
//
//   證件掃描（server/idscanner）：
//   visitor → server  idcard:arm       進入身份驗證頁：插入證件即自動掃描
//                     idcard:disarm    離開身份驗證頁（訪客螢幕離線時也會自動解除）
//   server → visitor  idcard:scanning  開始掃描
//                     idcard:result    { card: { name, idNumber（遮罩） } }
//                     idcard:error     { code, message }
//   server → all      scanner:status   掃描器狀態 { type, connected, ready, device, armed, scanning, error }（連線時立即補發）
import { Server } from 'socket.io'
import { logger } from './logger.js'
import { getScanner } from './idscanner/index.js'

const ROLES = ['visitor', 'staff']

const log = (message) => logger.info('Socket', message)

export function attachRealtime(httpServer) {
  const io = new Server(httpServer, {
    // 同一台機器 / 同網域使用，不需跨網域設定
    serveClient: false,
  })

  // 伺服器保存的最新狀態：櫃台重新整理或重新連線時可立即取得
  let session = null // { route, routeName, visit, updatedAt }

  const presence = () => ({
    visitor: (io.sockets.adapter.rooms.get('visitor')?.size || 0) > 0,
    staff: (io.sockets.adapter.rooms.get('staff')?.size || 0) > 0,
  })
  const broadcastPresence = () => io.emit('presence', presence())

  io.on('connection', (socket) => {
    const role = ROLES.includes(socket.handshake.query.role) ? socket.handshake.query.role : null
    if (!role) {
      log(`拒絕未知角色連線 ${socket.id}`)
      socket.disconnect(true)
      return
    }
    socket.join(role)
    log(`${role} 連線 ${socket.id}（${socket.handshake.address}）`)
    broadcastPresence()

    if (role === 'staff') socket.emit('session:update', session)
    const scannerStatus = getScanner()?.getStatus()
    if (scannerStatus) socket.emit('scanner:status', scannerStatus)

    // ── 證件掃描：訪客在身份驗證頁時才自動掃描 ──
    socket.on('idcard:arm', () => {
      if (role === 'visitor') getScanner()?.arm()
    })
    socket.on('idcard:disarm', () => {
      if (role === 'visitor') getScanner()?.disarm()
    })

    // ── 訪客 → 櫃台 ──
    socket.on('visitor:state', (state) => {
      if (role !== 'visitor') return
      const prevLocker = session?.visit?.locker?.label
      session = { ...state, updatedAt: new Date().toISOString() }
      const locker = state?.visit?.locker?.label
      if (locker && locker !== prevLocker) log(`訪客選擇證件存放格位 ${locker}`)
      io.to('staff').emit('session:update', session)
    })

    // ── 櫃台 → 訪客 ──
    socket.on('session:reset', () => {
      if (role !== 'staff') return
      session = null
      log('櫃台重置本次報到')
      io.to('visitor').emit('session:reset')
      io.to('staff').emit('session:update', null)
    })

    socket.on('disconnect', (reason) => {
      log(`${role} 離線 ${socket.id}（${reason}）`)
      // 已沒有訪客螢幕連線時解除自動掃描
      if (role === 'visitor' && !io.sockets.adapter.rooms.get('visitor')?.size) getScanner()?.disarm()
      broadcastPresence()
    })
  })

  return io
}
