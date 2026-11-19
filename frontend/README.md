# visitor-front

訪客機（Kiosk）報到導覽介面。Vue 3 + Ant Design Vue 4 + Vite 前端專案，正式環境由 Node.js (Express) 提供網頁服務，支援 Windows / Linux，並可設定開機自動啟動。不使用 Docker。

## 報到流程

首頁 `/`（`src/views/HomeView.vue`）：選擇有預約 / 無預約、切換語系（中文 / English / 日本語）。

### 有預約訪客

報到後取得訪客證，即可進入大樓。

| 步驟 | 路徑 | 畫面 | 檔案 |
| --- | --- | --- | --- |
| ② | `/appointment` | 掃描 QR Code 或以數字鍵盤輸入速通碼（QR Code 內容即為速通碼，預設 10 碼數字） | `src/views/AppointmentScanView.vue` |
| ③ | `/appointment/verifying` | 系統驗證中（失敗顯示查無預約） | `src/views/VerifyingView.vue` |
| ④ | `/appointment/verified` | 驗證通過，2 秒後自動下一步 | `src/views/VerifiedView.vue` |
| ⑤ | `/identity` | 身份驗證：插入證件自動掃描辨識（Plustek，見〈證件掃描器〉；可手動輸入）；人臉辨識目前註解停用，兩者擇一即可 | `src/views/IdentityView.vue` |
| ⑤-1 | `/locker` | 證件存放：**訪客自行點選可使用的格位**，放入證件後按「確認存放」，顯示格號後自動進入下一步 | `src/views/LockerView.vue` |
| ⑥ | `/agreement` | 訪客須知與同意條款 | `src/views/AgreementView.vue` |
| ⑦ | `/printing` | 列印訪客證，完成後通知員工 | `src/views/PrintingView.vue` |
| ⑧ | `/notified` | 已通知被拜訪員工，10 秒後回首頁 | `src/views/NotifiedView.vue` |

### 無預約訪客

自助登記後系統通知受訪公司，待受訪公司同意後訪客始可進入大樓。

| 步驟 | 路徑 | 畫面 | 檔案 |
| --- | --- | --- | --- |
| ② | `/walk-in` | 選擇到訪樓層 | `src/views/walkin/FloorView.vue` |
| ③ | `/walk-in/company` | 選擇受訪公司（可搜尋） | `src/views/walkin/CompanyView.vue` |
| ④ | `/walk-in/form` | 填寫姓名、手機、Email、訪客公司、拜訪事由 | `src/views/walkin/FormView.vue` |
| ⑤ | `/walk-in/done` | 已通知受訪公司，15 秒後回首頁 | `src/views/walkin/DoneView.vue` |

- 路由守衛：未完成前一步驟時直接輸入網址會被導回首頁（`src/router/index.js`）。
- 閒置重置：無操作 `VITE_IDLE_SECONDS` 秒（預設 120）自動清除資料、恢復中文並回首頁。
- QR Code 掃描器：支援「鍵盤模擬」型掃描器（快速輸入 + Enter），在步驟 ② 畫面直接掃描即可。
- 多語系文字：`src/i18n/locales/`。

### 雙螢幕：訪客螢幕（前方）＋ 櫃台螢幕（後方）

同一台電腦接兩個螢幕，各開一個全螢幕視窗：

| 畫面 | 網址 | 操作者 | 功能 |
| --- | --- | --- | --- |
| 訪客螢幕 | `/` | 訪客 | 上述所有報到步驟 |
| 櫃台螢幕 | `/staff` | 櫃台人員 | 即時顯示訪客進度與資料（含訪客選擇的證件存放格位）、取消 / 重置本次報到 |

兩個畫面透過 **socket.io** 即時同步（伺服器端 `server/realtime.js`，前端 `src/realtime/`）：

| 方向 | 事件 | 說明 |
| --- | --- | --- |
| 訪客 → 櫃台 | `visitor:state` | 每次換頁 / 資料變更的訪客狀態快照（伺服器保存，櫃台重新整理後立即補發） |
| 櫃台 → 訪客 | `session:reset` | 櫃台取消本次報到，訪客畫面清除資料回首頁 |
| 伺服器 → 全部 | `presence` | 兩端是否在線 |

- 證件存放格位由訪客在訪客螢幕自行點選（格位 API `getLockers` / `assignLocker` 由訪客螢幕呼叫），結果隨 `visitor:state` 同步到櫃台螢幕。

### 列印訪客證（POS 熱感應出單機）

步驟 ⑦ 流程：

1. `issueBadge()`（`src/api/visitor.js`）：送出訪客資料給後端，取回 **卡號**（QR Code 內容，例 `123456789`）與 **訪客證號**。目前為模擬資料，串接後端時替換此函式。
2. `printBadge()` → 本機服務 `POST /api/print/badge` → `server/printer/`：組出 ESC/POS 指令送到印表機。
3. `notifyHost()`：通知被拜訪員工。

列印內容（使用印表機內建 QR Code 指令，Epson 相容）：

```
            訪客證            ← 放大粗體
          [QR Code]           ← 內容 = 卡號
------------------------------------------------
訪客證號：V616958
列印時間：2026/11/10 17:28:44
------------------------------------------------
                              ← 進紙後自動裁切
```

`.env` 設定（修改後重新啟動服務）：

| 設定 | 說明 |
| --- | --- |
| `PRINTER_TYPE` | `mock`（預設，不實際列印，內容存到 `logs/print-last.bin`、`print-last.txt`）／**`usb`**／`device`／`cups`／`network` |
| `PRINTER_NAME` | `usb`（Windows）、`cups`：印表機名稱，用 `npm run print:list` 查詢 |
| `PRINTER_DEVICE` | `usb`（Linux）：裝置檔，預設 `/dev/usb/lp0`；`device`（Windows）：COM 埠，例 `COM9`，直接寫入序列埠、不經列印佇列 |
| `PRINTER_HOST`、`PRINTER_PORT` | `network`：網路印表機 IP 與埠號（通常 9100） |
| `PRINTER_ENCODING` | 中文編碼，繁中機型 `big5`（預設），簡中機型 `gb18030` |
| `PRINTER_CHINESE_MODE` | 是否送出中文模式指令（`FS &`），中文字變亂碼時可試著改為 `false` |
| `PRINTER_PAPER_WIDTH` | 紙寬 `80`（預設）或 `58` mm |
| `PRINTER_QR_SIZE` | QR Code 大小 1～16（預設 8） |
| `PRINTER_QR_MODE` | `image`（預設，點陣圖列印，所有機型可用）／`native`（印表機內建 QR 指令，部分機型印不出來） |
| `PRINTER_TITLE` | 標題文字（預設「訪客證」） |

#### 系統設定介面

櫃台螢幕（`/staff`）右上角「系統設定」可修改 `.env` 的所有設定（印表機、訪客機、記錄檔、服務、Kiosk 瀏覽器、PIN），並可偵測印表機 / COM 埠、測試列印、重新啟動服務：

- **直接寫回 `.env`**（保留註解與順序），寫入前備份為 `.env.bak`。
- 生效時機（介面上每個欄位都有標示）：

  | 設定 | 生效時機 |
  | --- | --- |
  | `PRINTER_*`、`LOG_LEVEL`、`LOG_DAYS`、`SETTINGS_PIN` | 立即 |
  | `VITE_*`（無操作秒數、速通碼長度、Debug、前端記錄等級） | 畫面重新整理後；訪客螢幕會在回到首頁時自動重新載入 |
  | `HOST`、`PORT`、`PUBLIC_HOST` | 重新啟動服務（介面有按鈕；需由 `start.bat` / systemd 啟動才會自動重啟） |
  | `KIOSK_*` | 下次開啟 Kiosk 瀏覽器 |

- `VITE_*` 由伺服器在執行時注入 `index.html`（`window.__APP_CONFIG__`，見 `src/config.js`），**不需重新 build**；開發模式（`npm run dev`）仍使用 build 時的值。
- 修改權限：有設 `SETTINGS_PIN` → 任何電腦輸入 PIN 即可修改；未設定 → 只允許本機（localhost）修改，其他電腦只能檢視。PIN 不會回傳給瀏覽器。

#### USB 印表機設定步驟

1. 安裝印表機廠商提供的 Windows 驅動程式（Linux 一般免驅動，會出現 `/dev/usb/lp0`）。
2. 查詢印表機名稱：`npm run print:list`
3. 設定 `.env`：

   ```ini
   PRINTER_TYPE=usb
   PRINTER_NAME=POS-80C        # Windows：填 print:list 顯示的名稱（Linux 免填，改用 PRINTER_DEVICE）
   ```

4. 測試列印：`npm run print:test`，成功後重新啟動服務。

- **Windows**：透過系統列印佇列以 RAW 模式送出 ESC/POS（`scripts/windows/raw-print.ps1`），**不需要設定共用印表機**；USB 或 USB 轉序列埠（COM）皆可。
  開機服務以 SYSTEM 帳號執行，預設印表機與登入帳號不同，請務必填 `PRINTER_NAME`。
- **Linux**：直接寫入 `/dev/usb/lp0`，執行服務的使用者需有寫入權限（`sudo usermod -aG lp 使用者`，重新登入後生效）；若印表機已加入 CUPS，也可改用 `PRINTER_TYPE=cups` 搭配 `PRINTER_NAME`。

測試列印（依 `.env` 設定印一張範例）：

```bash
npm run print:list                       # 列出印表機
npm run print:test                       # QR=123456789、證號 V000001
npm run print:test -- 987654321 V123456  # 自訂內容
```

- 重試時不會重複發卡；已列印成功只是通知失敗時，也不會重複列印。

### 證件掃描器（Plustek WebFXScan）

身份驗證步驟（`/identity`）進入後，**插入證件即自動掃描辨識**，姓名與證號自動帶入；「開始掃描」為重試，也可改用手動輸入。

- 由本機服務（`server/idscanner/`）以 WebSocket 連線 Plustek WebFXScan 服務 `ws://127.0.0.1:17778/webscan2`（協定移植自 SDK 範例 `DemoCode/WebFXScan/scan.js`），掃描結果經 socket 送到訪客螢幕；櫃台螢幕上方顯示掃描器狀態。
- **個資**：證號只保存與顯示遮罩後的值（`A123****89`），完整證號不會回傳瀏覽器、不會寫入記錄。
- 需 Node.js 22 以上（使用內建 WebSocket）。

安裝步驟：

1. 安裝掃描器驅動程式（例：SecureScan Series WebDriver）並接上掃描器。
2. 安裝 `PlustekSDK_LDSetup_xxxxx_x64.exe`，確認服務已啟動（埠號 17778 有在監聽）。
3. 測試並確認辨識欄位：`npm run idcard:test`，依提示插入證件；畫面會列出原始辨識資料與轉換結果。
   若顯示「無法辨識證件資料」，依原始資料的欄位名稱調整 `server/idscanner/parse.js` 的比對規則。
4. 櫃台「系統設定」→「證件掃描器」將掃描器改為 **Plustek**，並重新啟動服務。

| 設定 | 說明 |
| --- | --- |
| `SCANNER_TYPE` | `mock`（預設，回傳範例資料）／`plustek` |
| `SCANNER_HOST`、`SCANNER_PORT` | WebFXScan 服務位址，預設 `127.0.0.1`、`17778` |
| `SCANNER_RECOGNIZE_TYPE` | `twid`（台灣身分證，預設）／`passport`／`auto` |
| `SCANNER_DEVICE` | 裝置名稱，留空 = 第一台 |
| `SCANNER_SOURCE` | 掃描來源，留空 = 依裝置能力自動選擇（例 `Camera`〔A61~A64〕、`Sheetfed-Front`） |
| `SCANNER_TIMEOUT` | 單次掃描逾時秒數（預設 30） |
| `SCANNER_POLL_INTERVAL` | 攝影機式掃描器（`Camera`，例 A62）等待證件時的試掃間隔，毫秒（預設 1500） |

注意事項：

- 掃描服務同時只能給一個程式使用。開著瀏覽器中的 Plustek SDK 範例頁時，kiosk 會顯示「被其他程式佔用」（錯誤 1014），關閉後約 15 秒自動恢復。
- 進紙式掃描器插入證件時服務會主動通知；攝影機式（A62 等）不會通知，因此在身份驗證頁每隔 `SCANNER_POLL_INTERVAL` 試掃一次（未放證件時服務回覆 16，畫面不顯示）。
- 掃描服務會壓縮傳送的訊息，Node 內建 WebSocket 無法正確解壓，因此程式改用 `ws` 套件並關閉壓縮。

```bash
npm run idcard:test          # 等待插入證件（自動掃描）
npm run idcard:test -- --now # 立即掃描已放入的證件
```

### 模擬 API

目前所有後端呼叫皆為模擬資料，集中於 `src/api/visitor.js`，串接後端時替換其中函式即可：

- 預約驗證 `verifyAppointment({ passCode })`：掃描與手動輸入皆送出速通碼
  - 速通碼結尾為 `0000` → 模擬「查無預約」
  - 其他速通碼 → 驗證成功（回傳範例預約資料）
  - 格式不符（非 `VITE_PASS_CODE_LENGTH` 碼數字）的輸入或 QR Code 會在畫面上直接提示，不會送出
- 證件存放格位 `getLockers()`：回傳 `{ columns, slots: [{ id, label, status }] }`，status 為 `available` / `occupied` / `disabled`；`assignLocker(id)` 指派格位（後端可於此開啟格門）
- 樓層：B1、1F～15F；5F 有 5 家範例公司，其他樓層各 3 家測試公司
- 無預約送出登記 `registerWalkIn()`：後端應寄送通知 Email 給受訪公司

## 環境需求

- Node.js **20.19 以上**（建議 LTS）
- npm

## 專案結構

```
├─ src/                 前端原始碼（Vue 3）
│  ├─ main.js
│  ├─ App.vue           主題、語系、頁面切換動畫、閒置重置
│  ├─ api/              後端 API（目前為模擬資料）
│  ├─ components/       共用元件（版型、狀態面板、數字鍵盤、Logo）
│  ├─ composables/      時鐘、閒置重置
│  ├─ i18n/             多語系（zh-TW / en / ja）
│  ├─ router/           vue-router（history 模式 + 步驟守衛）
│  ├─ stores/visit.js   本次訪客報到狀態
│  ├─ styles/           全域樣式
│  └─ views/            各步驟畫面
├─ public/              靜態資源
├─ server/index.js      正式環境服務（Express：提供 dist + SPA fallback + /health）
├─ scripts/
│  ├─ linux/            systemd 開機自啟安裝 / 移除腳本
│  └─ windows/          工作排程器開機自啟安裝 / 移除腳本
├─ logs/                Windows 服務日誌（logs/service.log）
├─ .env.example         設定範本（HOST / PORT）
└─ vite.config.js
```

Ant Design Vue 元件已透過 `unplugin-vue-components` 自動按需引入，在 `.vue` 中可直接使用 `<a-button>` 等元件，不需手動 import。

## 設定

複製 `.env.example` 為 `.env`（安裝腳本會自動處理）：

```ini
HOST=0.0.0.0        # 監聽位址：0.0.0.0=所有網卡、127.0.0.1=僅本機、或指定某張網卡的 IP
PORT=3000           # 埠號
PUBLIC_HOST=        # 對外訪問的主機 IP / 網域（僅用於啟動訊息顯示），留空自動列出本機 IP
```

建議使用櫃台螢幕的「系統設定」修改（見〈系統設定介面〉，會標示各設定的生效時機）；直接編輯 `.env` 則需重新啟動服務。

前端設定（`VITE_` 開頭；正式服務於執行時讀取，畫面重新整理即生效，不需重新 build；開發模式需重啟 `npm run dev`）：

```ini
VITE_IDLE_SECONDS=120   # 無操作多少秒後自動回首頁
VITE_PASS_CODE_LENGTH=10 # 速通碼長度（QR Code 內容號碼，數字）
VITE_DEBUG=false        # true：將各流程資訊輸出到瀏覽器 console（F12）
```

### Debug 模式

`VITE_DEBUG=true` 時，瀏覽器 console 會依分類輸出：

| 分類 | 內容 |
| --- | --- |
| `[App]` | 啟動資訊、語系切換 |
| `[Route]` | 每次換頁（含當下完整訪客狀態）、被步驟守衛擋下的原因 |
| `[API]` | 每支 API 的呼叫參數 `→`、回傳結果 `←`（含耗時）、錯誤 `✕` |
| `[Flow]` | 各步驟操作：速通碼（掃描或輸入）、證件資料、選擇格位、送出登記、閒置重置等 |

記錄工具在 `src/utils/logger.js`，新增記錄可使用 `debug('Flow', '訊息', 資料)`。
**正式上線請設為 `false`**，記錄內容可能包含訪客個人資料。

### 記錄檔（類似 Laravel log）

前端（訪客螢幕、櫃台螢幕）與伺服器的記錄統一寫入 `logs/visitor-YYYY-MM-DD.log`，每天一個檔案，超過保留天數自動刪除：

```
[2026-11-10 17:43:29] production.INFO: [visitor][Flow] ② 掃描到 QR Code（11 字元）：10826280001
[2026-11-10 17:43:40] production.INFO: [staff][Flow] 櫃台：證件存放於 A03 {"id":"A03","label":"A03"}
[2026-11-10 17:43:46] production.INFO: [server][Printer] 列印訪客證（mock）V826453 / QR=186722610 {"printer":"mock","bytes":244}
[2026-11-10 17:44:57] production.ERROR: [visitor][App] 未處理的 Promise 錯誤：test rejection {"error":"Error","message":"test rejection","stack":"…"}
```

格式：`[時間] 環境.等級: [來源][分類] 訊息 {附加資料 JSON}`；來源為 `server` / `visitor` / `staff`。

| 設定 | 說明 |
| --- | --- |
| `VITE_LOG_LEVEL` | 前端送到伺服器的最低等級：`debug` / `info`（預設）/ `warning` / `error` / `off` |
| `LOG_LEVEL` | 伺服器寫入檔案的最低等級 |
| `LOG_DAYS` | 保留天數（預設 14） |

等級對應：

- `info`：報到流程各步驟（`[Flow]`）、啟動資訊、socket 連線、列印
- `warning`：驗證 / 列印 / 登記失敗、被步驟守衛擋下
- `error`：API 錯誤、未捕捉的 JavaScript / Vue / Promise 錯誤
- `debug`：API 參數與回傳內容、換頁與 socket 事件細節（量大，排查問題時再開啟）

前端每 2 秒批次送出，關閉 / 重新整理頁面前會以 `sendBeacon` 送出剩餘記錄；伺服器暫時無法連線時會保留在佇列稍後重送。
前端記錄以發生時間為準，因批次送出，檔案中可能與伺服器記錄的順序略有交錯。
程式中可使用 `debug()`（依分類決定等級）、`logWarning()`、`logError()`（`src/utils/logger.js`）；伺服器端使用 `logger.info / warning / error`（`server/logger.js`）。

> 記錄可能包含訪客個人資料（姓名、手機、證件號碼），請注意 `logs/` 目錄的存取權限與保留天數。

## 開發

```bash
npm install
npm run dev:server # 另開一個終端機：socket 同步服務（http://localhost:3000，修改 server/ 會自動重啟）
npm run dev        # http://localhost:5173（訪客螢幕）、http://localhost:5173/staff（櫃台螢幕）
```

開發模式下 `/socket.io` 會由 Vite 轉送到 `dev:server`。

## 正式執行（手動）

```bash
npm install
npm run build      # 產生 dist/
npm start          # http://<本機IP>:3000
# 或一次完成：npm run serve
```

健康檢查：`GET /health` → `{"status":"ok",...}`

> 每次更新程式碼後都需重新 `npm run build`，並重新啟動服務。

---

## 開機自動啟動

### Linux（systemd）

```bash
npm install && npm run build
sudo bash scripts/linux/install-service.sh            # 預設以目前 sudo 的使用者身分執行
# 或指定執行身分：sudo bash scripts/linux/install-service.sh www-data
```

常用指令：

```bash
systemctl status visitor-front
sudo systemctl restart visitor-front     # 更新 build 後重啟
journalctl -u visitor-front -f           # 即時日誌
sudo bash scripts/linux/uninstall-service.sh
```

若有防火牆，記得開放埠號，例如：`sudo ufw allow 3000/tcp` 或
`sudo firewall-cmd --add-port=3000/tcp --permanent && sudo firewall-cmd --reload`。

> 若 Node 透過 nvm 安裝，安裝腳本會嘗試從使用者環境找到 node 路徑；建議改用系統層級安裝的 Node 較穩定。

### Windows（工作排程器）

以 **系統管理員身分** 開啟 PowerShell，於專案目錄執行：

```powershell
npm install; npm run build
powershell -ExecutionPolicy Bypass -File scripts\windows\install-task.ps1
```

腳本會：

- 建立工作排程 `visitor-front`：開機時以 SYSTEM 身分執行（**不需登入**）
- 透過 `scripts\windows\start.bat` 啟動，程序意外結束會 5 秒後自動重啟
- 新增防火牆規則開放 `.env` 中的 PORT
- 立即啟動服務；日誌寫入 `logs\service.log`

常用指令（系統管理員 PowerShell）：

```powershell
Get-ScheduledTask visitor-front | Select-Object TaskName, State
# 更新 build 後重啟：重新執行 install-task.ps1，或重新開機
powershell -ExecutionPolicy Bypass -File scripts\windows\uninstall-task.ps1   # 停止並移除
```

> 注意：Node 需安裝在系統層級（例如 `C:\Program Files\nodejs`），SYSTEM 帳號才能存取。
> 若使用 nvm-windows 等安裝在使用者目錄，請確認 SYSTEM 對該路徑有讀取權限。

---

## Kiosk 模式（開機自動全螢幕開啟訪客機畫面）

網頁服務在開機時於背景啟動（不需登入）；瀏覽器則需顯示在使用者桌面，因此 kiosk 設定為「**使用者登入後**」自動開啟。
搭配作業系統的**自動登入**，即可達成「開機 → 自動登入 → 全螢幕訪客機畫面」。

啟動腳本會先等待網頁服務 `/health` 就緒（最多 180 秒），再以獨立瀏覽器設定檔開啟 kiosk（無網址列、無還原分頁提示），網頁保有鍵盤焦點，QR Code 掃描器可直接使用。

`.env` 設定（皆可省略）：

```ini
KIOSK_URL=              # 網站根網址，留空 = http://localhost:<PORT>（服務在其他主機時填該主機網址）
KIOSK_BROWSER=auto      # auto（Chrome 優先）| chrome | edge | chromium | 瀏覽器執行檔完整路徑
KIOSK_MODE=dual         # dual：雙螢幕（訪客 / + 櫃台 /staff）| single：只開訪客畫面
KIOSK_VISITOR_DISPLAY=1 # 訪客畫面所在螢幕（由左到右 1、2…）
KIOSK_STAFF_DISPLAY=2   # 櫃台畫面所在螢幕
```

雙螢幕模式會偵測各螢幕位置（Windows：系統螢幕資訊；Linux：`xrandr`），在指定螢幕各開一個全螢幕視窗（各自使用獨立瀏覽器設定檔）。
螢幕編號依位置由左到右排列，啟動記錄（Windows `logs\kiosk.log`）會列出偵測到的螢幕，若訪客 / 櫃台畫面放反，對調兩個編號即可。
只偵測到一個螢幕時只開訪客畫面，櫃台可手動開啟 `/staff`。

### Windows

```powershell
# 安裝服務時一併設定 kiosk（系統管理員 PowerShell）
powershell -ExecutionPolicy Bypass -File scripts\windows\install-task.ps1 -Kiosk
# 指定 kiosk 帳號：... install-task.ps1 -Kiosk -KioskUser KIOSK-PC\kiosk

# 或只設定 kiosk（例如網頁服務在其他主機）
powershell -ExecutionPolicy Bypass -File scripts\windows\install-kiosk.ps1 [-User 帳號]

# 立即測試 / 移除
Start-ScheduledTask -TaskName visitor-front-kiosk
powershell -ExecutionPolicy Bypass -File scripts\windows\uninstall-kiosk.ps1
```

- 建立工作排程 `visitor-front-kiosk`：指定帳號登入後執行 `scripts\windows\start-kiosk.ps1`，日誌寫入 `logs\kiosk.log`
- 自動登入設定：執行 `netplwiz`，取消勾選「使用者必須輸入使用者名稱和密碼，才能使用這台電腦」
  （Windows 11 若無此選項，需先在「設定 → 帳戶 → 登入選項」關閉「僅允許 Windows Hello 登入」）
- 測試啟動指令但不開啟瀏覽器：`... start-kiosk.ps1 -DryRun`

### Linux（桌面環境 XDG autostart）

```bash
# 安裝服務時一併設定 kiosk
sudo bash scripts/linux/install-service.sh --kiosk [使用者]

# 或只設定 kiosk（以該桌面使用者執行，不需 sudo）
bash scripts/linux/install-kiosk.sh

# 立即測試 / 移除
bash scripts/linux/start-kiosk.sh
bash scripts/linux/uninstall-kiosk.sh
```

- 建立 `~/.config/autostart/visitor-front-kiosk.desktop`：登入桌面後執行 `scripts/linux/start-kiosk.sh`（GNOME、XFCE、LXDE、KDE 等皆支援）
- 啟動時會關閉 X11 螢幕保護與自動關閉螢幕（`xset s off -dpms`）
- 自動登入設定：
  - GNOME（GDM）：`/etc/gdm3/custom.conf` 的 `[daemon]` 加上 `AutomaticLoginEnable=true`、`AutomaticLogin=使用者`
  - LightDM：`/etc/lightdm/lightdm.conf` 的 `[Seat:*]` 加上 `autologin-user=使用者`
- 測試啟動指令但不開啟瀏覽器：`DRY_RUN=1 bash scripts/linux/start-kiosk.sh`

離開 kiosk 畫面：`Alt + F4`。
