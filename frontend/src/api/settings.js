// 系統設定 API（本機服務 server/settingsRoutes.js，直接修改 .env）

async function request(method, url, { pin, body } = {}) {
  const headers = {}
  if (pin) headers['X-Settings-Pin'] = pin
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  let res
  try {
    res = await fetch(url, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) })
  } catch {
    throw new Error('無法連線到本機服務')
  }
  // 舊版服務沒有此 API 時會回傳網頁（index.html），提示重新啟動服務
  if (!(res.headers.get('content-type') || '').includes('application/json')) {
    throw new Error('本機服務版本較舊或尚未重新啟動，請重新啟動服務後再試')
  }
  const data = await res.json().catch(() => ({}))
  if (!res.ok || data.ok === false) {
    const err = new Error(data.message || `HTTP ${res.status}`)
    err.status = res.status
    err.code = data.code
    throw err
  }
  return data
}

/**
 * 取得設定：{ schema, values, secrets, platform, pinRequired, editable, restartPending, canRestart }
 */
export const getSettings = () => request('GET', '/api/settings')

/** 驗證 PIN */
export const verifySettingsPin = (pin) => request('POST', '/api/settings/verify', { pin })

/** 儲存有修改的欄位：values = { KEY: 值 }；回傳同 getSettings，另含 changed */
export const saveSettings = (values, pin) => request('PUT', '/api/settings', { pin, body: { values } })

/** 偵測印表機與連接埠：{ printers: [{ name, port, isDefault }], devices: [{ value, label, ok }] } */
export const listPrinterDevices = (pin) => request('GET', '/api/settings/devices', { pin })

/** 以目前（已儲存的）設定測試列印 */
export const testPrint = (pin) => request('POST', '/api/settings/printer/test', { pin })

/** 重新啟動本機服務 */
export const restartService = (pin) => request('POST', '/api/settings/restart', { pin })

/** 服務是否已可連線（重新啟動後輪詢用） */
export async function isServiceUp() {
  try {
    const res = await fetch('/health', { cache: 'no-store' })
    return res.ok
  } catch {
    return false
  }
}
