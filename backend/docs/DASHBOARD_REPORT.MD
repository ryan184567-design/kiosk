# 儀錶板上報接口規格（nsst → 儀錶板）

> 狀態：nsst 端已實作（第 8 節）；儀錶板方的接口網址與認證細節見第 9 節。
> 適用對象：儀錶板（第三方）開發者、nsst 後端與訪客機開發者。

## 1. 整體架構

```
訪客機 ×4 ──(1) 心跳／故障──▶ nsst ──(2) 同一個 API 地址──▶ 儀錶板（第三方）
                                  ▲
                      預約狀態變化（核准／修改／取消／報到／離場）
```

- **(1) 訪客機 → nsst**：訪客機定期報活；偵測到硬體異常時即時回報。走既有的機器（App Token）認證，見 `APP_DEVELOP.MD`。
- **(2) nsst → 儀錶板**：對方只提供**一個 API 地址**，所有訊息都 POST 到這裡，用最上層的 **`notify_type`（通知類型）** 欄位區分結構。沒有外層信封，欄位全部在同一層。

## 2. 通知類型（`notify_type`）

| `notify_type` | 觸發時機 | 結構 | 對應預約狀態 |
| --- | --- | --- | --- |
| `heartbeat` | nsst 定時（建議每 60 秒） | 第 5.1 節 | — |
| `device_fault` | 訪客機回報異常／恢復，即時 | 第 5.2 節 | — |
| `reservation_approved` | 預約審核通過 | 第 6 節預約欄位 | 10 已核准 |
| `reservation_modified` | 修改後**重新審核通過**時（不是修改當下） | 第 6 節預約欄位 | 11 修改已核准 |
| `reservation_cancelled` | 訪客取消預約 | 第 6 節預約欄位 | 60 已取消 |
| `reservation_checkin` | 訪客到場完成報到 | 第 6 節預約欄位 | 40 已到 |
| `reservation_checkout` | 訪客離場 | 第 6 節預約欄位 | 50 已離場 |

預約類五種的欄位完全相同（同一份完整預約資料），儀錶板用 `reservation_id` 做 upsert 即可，只靠 `notify_type` 知道發生了什麼事。

## 3. 傳輸約定

- `POST {對方提供的 API 地址}`，`Content-Type: application/json; charset=utf-8`。
- **自訂簽名／固定資料**：後台「儀錶板上報設定」可設定多組簽名／固定資料（`auth_items`，每組有欄位名稱、值、位置）：放在 HTTP 標頭（可選擇值前加 `Bearer `），或放進 POST 內容最上層當固定資料值，例如標頭 `X-API-Key` ＋ POST 內容 `site_code`。
- 時間一律 `Asia/Taipei`，格式 `YYYY-MM-DD HH:mm:ss`；日期 `YYYY-MM-DD`；時段 `HH:mm`。
- **冪等**：每則訊息有唯一的 `message_id`，對方可據此去重；重試時 `message_id` 不變。
- **逾時與重試**：單次最多等 **5 秒**；對方回非 2xx、逾時或連不上都算失敗。失敗後 **10 秒**重試，**3 次都失敗就丟棄**（記 log）。重試沿用同一個 `message_id`，對方可據此去重。丟棄後不再補送；心跳每分鐘會再送一次、且帶著各訪客機目前狀態，所以漏掉的心跳與故障狀態會自動補正，預約類事件漏送則需人工對帳。
- **順序**：同一張預約單的訊息可能因重試而亂序，對方以 `updated_at` 較新者為準，較舊的忽略。

## 4. 所有訊息都有的共用欄位

| 欄位 | 型別 | 說明 |
| --- | --- | --- |
| `notify_type` | string | 通知類型，見第 2 節，**決定其餘欄位的結構** |
| `message_id` | string | 訊息唯一碼（UUID），去重用；重試不變 |
| `sent_at` | string | nsst 發送時間（重試時為首次發送時間） |

## 5. 設備類訊息

### 5.1 `notify_type = heartbeat`（nsst 與四台訪客機的存活）

```json
{
  "notify_type": "heartbeat",
  "message_id": "6d1f9a52-0c1b-4e0e-8a77-3f2c9d1e5b01",
  "sent_at": "2026-11-13 08:00:00",
  "nsst": { "status": "online" },
  "kiosks": [
    {
      "kiosk_id": "KIOSK-01",
      "status": "online",
      "last_seen_at": "2026-11-13 07:59:41",
      "faults": []
    },
    {
      "kiosk_id": "KIOSK-02",
      "status": "online",
      "last_seen_at": "2026-11-13 07:59:50",
      "faults": [
        { "fault_code": "PRINTER_PAPER_OUT", "occurred_at": "2026-11-13 07:20:11" }
      ]
    },
    {
      "kiosk_id": "KIOSK-03",
      "status": "offline",
      "last_seen_at": "2026-11-13 07:41:03",
      "faults": []
    },
    {
      "kiosk_id": "KIOSK-04",
      "status": "online",
      "last_seen_at": "2026-11-13 07:59:57",
      "faults": []
    }
  ]
}
```

| 欄位 | 型別 | 說明 |
| --- | --- | --- |
| `nsst.status` | string | 固定 `online`（能送出就代表活著；對方收不到心跳即視為 nsst 離線） |
| `kiosks[]` | array | 曾經回報過的訪客機，**每次都完整列出**（從未回報過的訪客機不會出現） |
| `kiosk_id` | string | 訪客機編號，等於該訪客機在 nsst 的 App ID（`app_id`） |
| `status` | string | `online`：最後一次回報在 **1 分鐘內**；`offline`：超過 1 分鐘沒回報（訪客機每 30 秒回報一次），視為故障 |
| `last_seen_at` | string\|null | nsst 最後一次收到該機回報（心跳或故障回報）的時間；從未連線為 `null` |
| `faults[]` | array | **目前仍未恢復**的故障清單，沒有故障為空陣列 |
| `faults[].fault_code` | string | 故障代碼，見 5.3 |
| `faults[].occurred_at` | string | 故障發生時間 |

> 心跳內附帶「目前未恢復的故障」，即使某則 `device_fault` 遺失，下一個心跳就能自我修正。

### 5.2 `notify_type = device_fault`（訪客機異常／恢復，即時）

```json
{
  "notify_type": "device_fault",
  "message_id": "0a4e7c19-5d2b-4b8f-91c6-7e3d2a1f4c22",
  "sent_at": "2026-11-13 07:20:12",
  "kiosk_id": "KIOSK-02",
  "fault_code": "PRINTER_PAPER_OUT",
  "fault_status": "occurred",
  "fault_message": "熱敏出單機缺紙",
  "occurred_at": "2026-11-13 07:20:11"
}
```

| 欄位 | 型別 | 說明 |
| --- | --- | --- |
| `kiosk_id` | string | 哪一台訪客機 |
| `fault_code` | string | 故障代碼，見 5.3 |
| `fault_status` | string | `occurred`：發生；`resolved`：已恢復（`SYSTEM_OK` 固定為 `resolved`） |
| `fault_message` | string | 中文說明，供人看 |
| `occurred_at` | string | 事件發生時間（`resolved` 時為恢復時間） |

### 5.3 故障代碼

| `fault_code` | 說明 |
| --- | --- |
| `PRINTER_PAPER_OUT` | 熱敏出單機缺紙 |
| `PRINTER_DISCONNECTED` | 熱敏出單機連接故障 |
| `ID_SCANNER_DISCONNECTED` | 身份證掃描器連接故障 |
| `SYSTEM_OK` | 系統正常：這台訪客機所有故障都已排除（不是故障，而是「全部恢復」；`fault_status` 為 `resolved`） |

之後新增故障種類只需增加代碼，不改結構。同一種故障重複回報、或沒有故障時回報 `SYSTEM_OK`，狀態沒有變動，**不會**重複上報。

### 5.4 訪客機 → nsst（內部接口，供訪客機開發者）

沿用既有機器認證（`POST /api/factory/token` 換 token，之後帶 `Authorization: Bearer`），路徑在 `/api/factory/guest/` 底下。兩支接口的 scope 分別是 `guest.kiosk.heartbeat`、`guest.kiosk.fault`，需到後台「廠商 App 管理 → 授權」勾選。

| 接口 | 時機 | 請求 body |
| --- | --- | --- |
| `POST /api/factory/guest/kiosk/heartbeat` | 每 30 秒 | 選填 `{"faults": [{"fault_code": "PRINTER_PAPER_OUT", "occurred_at": "2026-11-13 07:20:11"}]}`：目前仍未恢復的故障。有帶以它為準整批覆蓋（沒有故障傳 `[]`）；沒帶則不動 |
| `POST /api/factory/guest/kiosk/fault` | 偵測到異常、恢復、或系統正常時立即 | `{"fault_code": "PRINTER_PAPER_OUT", "fault_status": "occurred", "occurred_at": "2026-11-13 07:20:11"}`；`fault_status` 選填（預設 `occurred`，可為 `resolved`）；`fault_code` 為 `SYSTEM_OK` 時清除全部故障 |

- `kiosk_id` 由 token 對應的 App 決定，訪客機不必自己傳。
- 兩支接口都會更新「最後回報時間」（`guest_kiosk_statuses`），並寫一筆日誌（`guest_kiosk_logs`）。
- 故障狀態有變動時，nsst 立即組成 5.2 的 `device_fault` 上報；心跳由排程每分鐘統一組成 5.1 的 `heartbeat` 上報。

## 6. 預約類訊息（雙方已協商欄位，原樣沿用，只在最上層加共用欄位）

以 `reservation_approved` 為例：

```json
{
  "notify_type": "reservation_approved",
  "message_id": "b7a1c3e0-4f6a-4c55-9d38-0b1c2f6a7e11",
  "sent_at": "2026-11-13 08:15:02",

  "reservation_id": "20261113",
  "reservation_type": "0",
  "visit_start_time": "09:00",
  "visit_end_time": "11:00",
  "host_company_name": "XXX股份有限公司",
  "host_floor": "3",
  "host_name": "X先生",
  "visit_date": "2026-11-13",
  "visit_reason": "客戶訪談",
  "guest_company_name": "AAA股份有限公司",
  "visitor": [
    { "guest_job_title": "業務", "guest_name": "A先生", "guest_phone": "0952526236" },
    { "guest_job_title": "助理", "guest_name": "A小姐", "guest_phone": "0952526251" },
    { "guest_job_title": "技術主管", "guest_name": "B先生", "guest_phone": "0952526223" }
  ],
  "id_card_slot_no": "A12",
  "guest_count": 3,
  "remark": "大廳等候，會有專人來協助引導上樓",
  "is_email_notify": true,
  "is_sms_notify": false,
  "is_auto_remind": true,
  "reservation_status": 10,
  "fast_pass_code": "2562321512",
  "access_card_no": "5214221253",
  "created_at": "2026-11-13 08:00:00",
  "updated_at": "2026-11-13 08:15:00",
  "check_in_at": null,
  "check_out_at": null
}
```

（空行只是方便閱讀，實際傳送是同一個 JSON 物件。）

| 欄位 | 型別 | 說明 |
| --- | --- | --- |
| `reservation_id` | string | 預約單號 |
| `reservation_type` | string | 預約類型：`"0"` 預先預約、`"1"` 現場預約 |
| `visit_start_time` / `visit_end_time` | string | 來訪時段起／迄，`HH:mm` |
| `host_company_name` | string | 受訪公司 |
| `host_floor` | string | 樓層，例如 `"3"`、`"B1"` |
| `host_name` | string | 受訪者 |
| `visit_date` | string | 受訪日期 |
| `visit_reason` | string | 預約事由（文字，不是代碼） |
| `guest_company_name` | string | 拜訪者公司名稱 |
| `visitor[]` | array | 拜訪者資料，`guest_job_title`、`guest_name`、`guest_phone` |
| `id_card_slot_no` | string\|null | 證件存放格位 |
| `guest_count` | int | 人數 |
| `remark` | string\|null | 備註 |
| `is_email_notify` / `is_sms_notify` / `is_auto_remind` | bool | 通知設定：email／簡訊／到訪前自動提醒 |
| `reservation_status` | int | 0 待審核、1 修改待審核、10 已核准、11 修改已核准、20 已通知、30 未到、40 已到、50 已離場、60 已取消 |
| `fast_pass_code` | string\|null | 速通碼 |
| `access_card_no` | string\|null | 門禁卡號 |
| `created_at` / `updated_at` | string | 新增／最後更新時間 |
| `check_in_at` | string\|null | **新增**：報到時間（`reservation_checkin`、`reservation_checkout` 才有值） |
| `check_out_at` | string\|null | **新增**：離場時間（`reservation_checkout` 才有值） |

與原協商版本的差異（其餘不動）：

1. 最上層新增 `notify_type`、`message_id`、`sent_at`（第 4 節）。
2. 原稿 `visit_start_time` 出現兩次，第二個應為**迄**，已更正為 `visit_end_time`。
3. 新增 `check_in_at`、`check_out_at`，讓報到、離場事件帶得到實際時間（平常為 `null`）。
4. 原稿的 `//` 註解不是合法 JSON，實際傳送不含註解。

各事件的差異只在 `notify_type` 與 `reservation_status`：

| 事件 | `notify_type` | `reservation_status` | 備註 |
| --- | --- | --- | --- |
| 核准 | `reservation_approved` | 10 | |
| 修改 | `reservation_modified` | 11 | 修改後需重新審核，**審核通過才通知**；欄位是修改後的完整資料 |
| 取消 | `reservation_cancelled` | 60 | |
| 報到 | `reservation_checkin` | 40 | `check_in_at` 有值 |
| 離場 | `reservation_checkout` | 50 | `check_in_at`、`check_out_at` 皆有值 |

## 7. 對方回應（建議）

HTTP 2xx 視為成功，其餘（含逾時）nsst 依第 3 節重試。**實際回應格式待對方確認。**

## 8. nsst 端實作

### 8.1 設定（後台「儀錶板上報設定」）

| 接口 | 說明 |
| --- | --- |
| `GET /api/guest/dashboard-settings` | 讀取設定（簽名值只回傳遮罩） |
| `PUT /api/guest/dashboard-settings` | 儲存設定：是否啟用、網址、上傳方式（目前僅 `api`）、格式（目前僅 `json`）、上傳內容、排程（目前僅 `realtime`）、自訂簽名（可多組） |
| `POST /api/guest/dashboard-settings/test` | 連線測試：手動上報一次心跳，回傳 `success`、`status_code`、`duration_ms`、`tested_at`、`error`、`response_body`（儀錶板回應原文）；不看是否啟用，可帶 `url` 測尚未儲存的網址 |
| `GET /api/guest/dashboard-logs` | 上報記錄：每次實際送出（含重試每一次、連線測試）的送出內容與儀錶板回應，可依通知類型、成功與否、是否連線測試、日期篩選 |

「上傳內容」勾選什麼就上報什麼：`heartbeat`（心跳及訪客機故障）、`approved`、`modified`、`cancelled`、`checkin`、`checkout`。選項來源是資料字典 `dashboard_upload_method`、`dashboard_format`、`dashboard_content`、`dashboard_schedule`（`GET /api/dictionaries/{代碼}/items`）。每個子站各有一組設定。

### 8.2 公共方法 `App\Support\Dashboard\DashboardReporter`

在事件發生的接口裡，**動作成功且資料庫交易已提交之後**，呼叫一行即可：

```php
use App\Support\Dashboard\DashboardReporter;

DashboardReporter::approved($apply);    // 核准：審核通過（status 10）
DashboardReporter::modified($apply);    // 修改：修改後「重新審核通過」時（status 11），不是修改當下
DashboardReporter::cancelled($apply);   // 取消（status 60）
DashboardReporter::checkin($apply);     // 報到（status 40）
DashboardReporter::checkout($apply);    // 離場（status 50）
```

- 參數是 `App\Api\Guest\Models\GuestApply`；通知內容在呼叫當下從資料庫讀最新資料（含審核後、報到後寫入的欄位），不必自己組，所以請在交易提交後再呼叫。
- **不用判斷設定**：沒啟用、或上傳內容沒勾選該事件，方法什麼都不做。
- **不會丟例外、不拖慢回應**：呼叫只是把通知排進佇列（一筆資料庫寫入），實際 POST 由 queue worker 執行（見 8.3）；排入佇列失敗只記 log。
- `DashboardReporter::deviceFault(...)` 已在 `POST /api/factory/guest/kiosk/fault` 內呼叫，一般不需要自己呼叫。
- 現有的核准、修改審核、取消、報到、離場接口**尚未**掛上這些呼叫，待逐一接上（見下表）。

| 事件 | 接上的位置 | 呼叫 |
| --- | --- | --- |
| 核准 | `GuestApplyService::status()`：0→10 | `approved` |
| 修改審核通過 | `GuestApplyService::status()`：1→11 | `modified` |
| 取消 | `GuestApplyService::cancel()` | `cancelled` |
| 報到 | `GuestApplyService::checkDone()`（訪客機報到） | `checkin` |
| 離場 | `GuestApplyService::leave()`（訪客機離場） | `checkout` |

> 這幾個 Service 方法內部有資料庫交易，請在 **Controller 取得結果之後**呼叫（交易已提交），不要在 Service 的交易閉包裡呼叫。

### 8.3 佇列、重試與心跳排程

**上報走佇列任務 `App\Jobs\SendDashboardNotification`**：`tries = 3`、`backoff = 10` 秒、單次 HTTP 逾時 5 秒；第 3 次仍失敗就結束任務（記 `儀錶板上報失敗，已達重試上限，丟棄`，不進 `failed_jobs`）。任務建立時就帶著網址、標頭（含簽名）與通知內容，執行時不再讀設定，所以多站共用一個 worker 也沒問題；佇列資料表裡整個任務是加密的。

> **部署必要條件**：`QUEUE_CONNECTION=database`（預設）且要有 `php artisan queue:work` 常駐（建議用 Supervisor／服務管理）；沒有 worker 通知不會送出。`QUEUE_CONNECTION=sync` 時通知會當場送出、沒有延遲重試。連線測試（`POST /api/guest/dashboard-settings/test`）是同步送出、不重試。

**心跳排程**：`routes/console.php` 每分鐘執行 `php artisan dashboard:report-heartbeat`（需 cron 每分鐘跑 `schedule:run`），把心跳排進佇列。多站模式逐子站處理：只有「已啟用且上傳內容勾了心跳」的子站才上報；`--site={id}` 可只跑單一子站。

### 8.4 資料表

| 表 | 用途 |
| --- | --- |
| `guest_kiosk_statuses` | 每台訪客機一列：`last_reported_at`（最後回報時間）、`faults`（目前未恢復的故障）。心跳依它判斷離線：超過 1 分鐘沒回報即 `offline` |
| `guest_kiosk_logs` | 訪客機每次回報（心跳、故障）的日誌，只新增；每台每天約 2,880 筆心跳，目前沒有自動清理 |
| `dashboard_report_logs` | 上報記錄：每次送出一列（通知類型、message_id、第幾次嘗試、是否連線測試、網址、成功與否、狀態碼、耗時、送出內容、對方回應內容、錯誤）。不記標頭；放在 POST 內容的簽名值遮罩；目前沒有自動清理 |
| `dashboard_report_settings` | 上報設定，每個子站一列；簽名值（`auth_items`）整串加密儲存 |

## 9. 待與對方確認

1. API 網址與認證方式（用哪個欄位名稱、放標頭還是 POST 內容、是否要 `Bearer`），以及對方的回應格式；nsst 目前只看 HTTP 狀態碼是否為 2xx。
2. 對方是否能用 `message_id` 去重、以 `updated_at` 判斷新舊。
3. `reservation_type`、`host_floor` 目前沿用協商的字串型別（`"0"`、`"3"`），`reservation_status`、`guest_count` 為數字，是否要統一？
4. `notify_type` 的值用英文字串（如上）還是數字代碼？
5. 是否需要上報 `reservation_status` 20（已通知）、30（未到）。目前只定義了你們列出的六種情境。
