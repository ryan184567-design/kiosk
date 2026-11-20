# 廠商模式（App Token）：App（機器）認證與授權開發指南

> 適用對象：
> - **前端／訪客機開發者**：想知道怎麼用 app_id／app_secret 取得 token 並呼叫接口（第 2～4 節）。
> - **後端開發者**：想新增一支「給機器呼叫」的接口（第 5～6 節）。
> - **維運**：建立 App、授權、排查問題（第 7～8 節）。
>
> 本文件是廠商模式（App Token）的**開發指南**，以實際程式碼為準。專案整體規範見 `README.MD`（5.8 節是必守規則摘要）與 `DEVELOP.MD` 12.8、12.9。

## 1. 這是什麼

訪客機等「機器」**沒有用戶賬號**，不能走一般的用戶登入（`jwt.auth`）。這套機制讓每台機器擁有自己的身份（**App**），並可以**各自設定可操作的接口權限**。

- 與用戶登入**並存、互不影響**：用戶接口照舊用 `jwt.auth` + `permission.api`，機器接口用 `factory.auth` + `factory.scope`。
- **認證與管理全局共用，業務接口屬於各自模塊**：換 token、App、授權、記錄由核心模塊 `factory` 統一管理；訪客、門禁、停車等各模塊把**自己的**機器接口寫在**自己的模塊**裡，再由後台決定哪個 `app_id` 能呼叫哪支接口。
- 每個 App（app_id）可單獨授權：可授單個接口、整個模塊（`模塊.*`）或全部（`*`）。

> **尚未實作**：DEVELOP.MD 18.3 規劃的第 2 步「app token 換 user token」（`POST /api/factory/auth/login`）。目前只有「App 身份」這一層，適用於訪客機這類沒有用戶的機器。

### 1.1 程式碼位置

| 位置 | 內容 |
| --- | --- |
| `app/Support/Factory/` | 認證與授權核心：`FactorySignatureService`（簽名驗證）、`FactoryJwtService`（JWT）、`FactoryScopeMatcher`（scope 比對） |
| `app/Support/Http/Middleware/EnsureFactoryAuthenticated.php`、`EnsureFactoryScope.php` | middleware `factory.auth`、`factory.scope`（在 `bootstrap/app.php` 註冊） |
| `config/factory.php` | 設定（JWT 密鑰、有效期、時間窗、限流） |
| `app/Api/Factory/`（核心模塊 `factory`） | **只放管理功能**：換 token（`POST /api/factory/token`）、App／授權／記錄的後台管理（`/api/system/factory/*`）。**不放任何業務模塊的接口** |
| `app/Api/{Module}/Controllers/Factory{Xxx}Controller.php` | 各業務模塊自己的機器接口，範例：[FactoryGuestApplyController](../app/Api/Guest/Controllers/FactoryGuestApplyController.php)（`app/Api/Guest`） |
| `resources/vue_src_admin/src/pages/factory/` | 後台管理頁面（選單：系統設定 → 廠商 App 管理／廠商 App 記錄） |
| `tests/Feature/Factory/FactoryAuthTest.php` | 測試與測試範本 |

### 1.2 兩套認證對照

| | 後台用戶接口 | 機器（App）接口 |
| --- | --- | --- |
| 身份 | 用戶＋端口＋角色 | App（app_id） |
| 認證 middleware | `jwt.auth` | `factory.auth` |
| 權限 middleware | `permission.api:api.模塊.方法名` | `factory.scope:模塊.資源.動作` |
| 權限來源 | 角色的 `permission_codes` | 每個 App 各自的授權（`factory_app_scopes`） |
| 權限登記指令 | `permission:sync-api` | `factory:sync-scopes` |
| Swagger 標註 | `@api-portal admin` 等 | 加 `@api-auth app` ＋ `@api-scope xxx` |

## 2. 驗證流程（前端必讀）

```
[訪客機]                                              [後端]
 timestamp, nonce
 sign = HMAC-SHA256(app_secret, "app_id|timestamp|nonce")
 POST /api/factory/token {app_id,timestamp,nonce,sign} ─▶ 1. App 存在、啟用、未過期
                                                          2. 來源 IP 在白名單（若有設定）
                                                          3. timestamp 在 ±300 秒內
                                                          4. 用同一 app_secret 重算 HMAC，hash_equals 比對
                                                          5. nonce 未使用過（防重放）
 ◀─ { access_token, token_type: "Bearer", expires_in } ── 6. 簽發 JWT（HS256）
 之後每次請求 Authorization: Bearer <access_token> ─────▶ factory.auth：驗 JWT、App 仍啟用／未過期／IP
                                                          factory.scope：App 已被授權該 scope
```

**app_secret 絕不經網路傳送**，只傳簽名結果。

### 2.1 簽名規則

- 演算法：**HMAC-SHA256**，輸出**小寫 hex（64 字元）**。
- 密鑰：`app_secret`（後台建立 App 時顯示一次的那串）。
- 簽名原文：`app_id + "|" + timestamp + "|" + nonce`
  - `timestamp`：Unix **秒**級時間戳（整數），與伺服器相差需在 300 秒內。
  - `nonce`：客戶端產生的隨機**英數字**字串，16～64 字元；同一個 nonce 在有效時間內不可重複使用。

### 2.2 換取 token

`POST /api/factory/token`，`Content-Type: application/json`

| 欄位 | 型別 | 說明 |
| --- | --- | --- |
| `app_id` | string | 對外 app_id，例如 `app_153pa938yg7bdiuc` |
| `timestamp` | int | Unix 秒 |
| `nonce` | string | 16～64 位英數字 |
| `sign` | string | 64 字元小寫 hex |

成功回應：

```json
{
  "status": true,
  "error_code": 0,
  "data": { "access_token": "eyJ...", "token_type": "Bearer", "expires_in": 3600 }
}
```

### 2.3 呼叫接口

```
GET /api/factory/guest/applies?fast_pass_code=12345678
Authorization: Bearer <access_token>
Accept: application/json
```

## 3. 前端範例（Vue 3）

```js
async function hmacSha256Hex(key, message) {
  const enc = new TextEncoder();
  const cryptoKey = await crypto.subtle.importKey(
    'raw', enc.encode(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']
  );
  const sig = await crypto.subtle.sign('HMAC', cryptoKey, enc.encode(message));
  return [...new Uint8Array(sig)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function fetchToken(app_id, app_secret, clockOffsetSec = 0) {
  const timestamp = Math.floor(Date.now() / 1000) + clockOffsetSec;
  const nonce = crypto.randomUUID().replace(/-/g, '');           // 32 位英數字
  const sign = await hmacSha256Hex(app_secret, `${app_id}|${timestamp}|${nonce}`);

  const res = await fetch('/api/factory/token', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ app_id, timestamp, nonce, sign }),
  });

  return res.json();
}
```

### 3.1 前端必須處理的事

1. **token 續期**：記下 `expires_in`，快到期或任何請求收到 401 時，重新簽名換 token。**沒有 refresh token**。
2. **時鐘校準**：訪客機本地時間不準會導致簽發失敗。時間窗失敗時回應會多帶 `server_time`（Unix 秒），前端據此算出 `clockOffsetSec = server_time - 本地時間` 後重試**一次**：

   ```json
   { "status": false, "error_code": 20012, "error_message": "認證失敗", "trace_id": "...", "server_time": 1795093599 }
   ```
3. **HTTPS 限制**：`crypto.subtle` 只在 **HTTPS 或 localhost** 可用。若訪客機走內網 http，需改用純 JS 的 HMAC-SHA256 實作（如 `crypto-js`、`@noble/hashes`），並**務必設定 IP 白名單**。
4. **不要重複使用 nonce**：每次換 token 都要重新產生。

### 3.2 命令列手動測試（curl）

```bash
APPID=app_xxx
APP_SECRET=your-app_secret
TS=$(date +%s)
NONCE=$(openssl rand -hex 16)
SIGN=$(printf '%s' "$APPID|$TS|$NONCE" | openssl dgst -sha256 -hmac "$APP_SECRET" | awk '{print $NF}')

curl -s -X POST http://127.0.0.1:8000/api/factory/token \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"app_id\":\"$APPID\",\"timestamp\":$TS,\"nonce\":\"$NONCE\",\"sign\":\"$SIGN\"}"
```

## 4. 錯誤回應

| 狀況 | HTTP | error_code | 說明 |
| --- | --- | --- | --- |
| 簽名錯誤、App 不存在／停用／過期、IP 不在白名單、nonce 重複 | 401 | 20012 | **一律同一則「認證失敗」**，不透露是哪一步失敗；原因只寫入後台「廠商 App 記錄」 |
| 時間窗外 | 401 | 20012 | 同上，但額外回 `server_time` |
| 缺少／無效／過期的 token、App 已被停用或過期、IP 不符 | 401 | 20001 | 呼叫接口時 |
| 拿 app token 呼叫一般（用戶）接口 | 403 | 20002 | App Token 只能訪問 `/api/factory/*` |
| App 沒有該接口的 scope | 403 | 20002 | 後台「廠商 App 記錄」會記一筆「權限被拒」 |
| 參數驗證失敗 | 422 | 10001 | `error_message` 帶第一個欄位的中文錯誤 |
| 查無資料 | 404 | 40004 | |
| 換 token 太頻繁 | 429 | — | 限流，見第 8 節 |

回應格式統一為 `{ status, error_code, data | error_message, trace_id }`，見 `ApiResponder`。

## 5. 後端：新增一支機器接口

**原則：業務接口寫在擁有該業務的模塊裡，不要放進 `Factory` 模塊。** 以「速通碼查詢」為樣本：[FactoryGuestApplyController](../app/Api/Guest/Controllers/FactoryGuestApplyController.php)、[Guest routes](../app/Api/Guest/routes/api.php)（檔案最下方的 `factory/guest` 群組）。

### 命名與放置規則（定死，有測試把關）

> 同一份規則的摘要在 `README.MD` 5.8，這裡是完整版。違反時 `tests/Feature/Factory/FactoryAuthTest.php` 會失敗。

| 項目 | 規則 |
| --- | --- |
| 位置 | `app/Api/{模塊}/Controllers/` **根層**，不可放子資料夾（`permission:sync-api`、`swagger:generate` 只掃根層，放子資料夾會被漏掉） |
| 類名／檔名 | **`Factory{Xxx}Controller`**（`Xxx` 大駝峰，描述業務，如 `FactoryGuestApplyController`） |
| `Factory` 前綴保留 | 只用於路徑為 `/api/factory/*` 的 Controller，且該 Controller 只放 `/api/factory/*` 的接口。後台管理（`/api/system/factory/*`）與一般接口**不可**用此前綴（`factory` 模塊的後台管理 Controller 因此叫 `AppController`、`AppLogController`）；端口（portal）也不要取名 `factory`（會與 DEVELOP.MD 9.1.1 的 `{PortalKey}{Feature}Controller` 混淆） |
| 路徑 | `/api/factory/{模塊代碼}/...` |
| 路由 middleware | `['module:{code}', 'factory.auth']`，單支再掛 `factory.scope:{模塊}.{資源}.{動作}`；**不可**掛 `jwt.auth`／`permission.api` |
| 註解 | `@api-portal *`、`@api-auth app`、`@api-scope`；不需認證的（換 token）標 `@api-auth public` |
| 不要放進 `Factory` 模塊 | `app/Api/Factory` 只放 App 管理（換 token、App、授權、記錄），**不放任何業務模塊的機器接口** |

### 步驟

1. **Controller**
   - 放在**該業務模塊**自己的 `Controllers/` 根層，命名 `Factory{Xxx}Controller`（`permission:sync-api`、`swagger:generate` 只掃 `Controllers/` 這一層，放子資料夾會被漏掉）。
   - 業務邏輯放 Service，**沿用既有 Service，不要複製**。
   - 只回傳機器需要的欄位，**不要直接回傳 Model**。
   - Service 拋的業務 `RuntimeException` 用 `use MapsServiceExceptions;` 的 `failFromService($e)` 轉換（原因見第 6 節）。
2. **路由**：掛 `factory.auth` ＋ `factory.scope:{scope}`，業務模塊再加 `module:{code}`。HTTP 方法遵循 RESTful（查詢＝GET，條件用 query string）。

   ```php
   // 放在 app/Api/Guest/routes/api.php；路徑一律 /api/factory/{模塊}/...
   Route::prefix('factory/guest')->middleware(['module:guest', 'factory.auth'])->group(function () {
       Route::get('applies', [FactoryGuestApplyController::class, 'index'])
           ->middleware('factory.scope:guest.apply.read');
   });
   ```
3. **Swagger 標註**：docblock 加

   ```
   @api-portal *
   @api-auth app
   @api-scope guest.apply.read
   ```
   - `@api-scope` 必須與路由上的 `factory.scope:xxx` **完全一致**（測試會檢查）。
   - 不需認證的接口（如換 token）標 `@api-auth public`。
   - 標了 `@api-auth` 的接口，`permission:sync-api` 會**略過**（不會被登記成後台用戶權限），Swagger 則顯示 Bearer 認證與所需 scope（`x-scope`）。
   - 其餘標註（`@api-method`、`@api-path`、`@api-tag`、`@api-summary`、`@api-response-field` 等）規則同一般接口，見 `DEVELOP.MD` 12 節。
4. **登記 scope**：`php artisan factory:sync-scopes [--site=]`。
5. **授權**：後台「系統設定 → 廠商 App 管理 → 授權」勾選。
6. **更新文件**：`php artisan swagger:generate --pretty`（`/api/factory/*` 進第三方文件 `/docs/partner`，不在內部文件 `/docs`；後台管理 `/api/system/factory/*` 在內部文件）。
7. **補測試**：照 [FactoryAuthTest](../tests/Feature/Factory/FactoryAuthTest.php)，至少涵蓋：未帶 token＝401、缺 scope＝403、查無資料＝404、驗證失敗＝422、回傳欄位不外洩。

### Scope 命名

- 格式 `模塊.資源.動作`，動作建議 `read`／`create`／`update`／`delete` 或具體動作名，例如 `guest.apply.read`。
- 只允許小寫英數與底線，以點分隔。
- 授權判斷：**精確命中**，或命中 `模塊.*`，或命中 `*`。
- `*`（全部權限）僅限特殊場景，後台勾選時會二次確認。

### 寫入類接口（報到、離場、存放證件位置…）

訪客機需要把操作寫回系統，這是**正常且支援**的用法，沒有任何額外開關。照一般機器接口的步驟做，再注意：

- **HTTP 方法與路徑**：遵循 RESTful。把「狀態變化」當成資源動作，例如 `PUT /api/factory/guest/applies/{id}/check-in`（報到）、`PUT /api/factory/guest/applies/{id}/check-out`（離場）、`PUT /api/factory/guest/slots/{id}`（證件櫃存放）。
- **scope 動作名**：用具體動作，例如 `guest.apply.check_in`、`guest.apply.check_out`、`guest.slot.assign`，**一個動作一個 scope**，讓後台能分別授權；不要為了方便讓所有機器共用 `guest.*`。
- **最小授權**：只授權該機器需要的 scope。例如大廳報到機授權 `guest.apply.read`＋`guest.apply.check_in`＋`guest.slot.assign`，不需要離場的就不給 `check_out`。
- **業務規則在 Service**：狀態檢查（例如只有「已通知」才能報到、已離場不可再報到）、證件櫃是否被占用等，一律寫在 Service，並用 `MapsServiceExceptions::failFromService()` 回 404／422；不要依賴「機器不會亂送」。
- **可重複送出**：機器網路不穩會重試，寫入接口要能安全地被重複呼叫（已完成就回成功或明確的狀態，不要產生重複紀錄）。
- **要知道是哪台機器做的**：Controller 內可用 `$request->attributes->get('factory_app')->app_id` 取得呼叫的 App，業務紀錄需要時自行寫入（`factory_logs` 只記簽發、權限被拒與後台對 App 的操作，不記每次業務寫入）。
- **回傳只給機器需要的欄位**；寫入成功回 200／201 與必要的結果，不回完整 Model。
- **測試**：除了 401／403／404／422，寫入接口要測「狀態不符」與「重複送出」。

### 一支接口要求多個 scope 之一

`factory.scope:a.read,b.read` 表示 App 擁有**任一**即可通過。

## 6. 不要踩的坑

- **不要直接 `catch (RuntimeException)`**：`Illuminate\Database\QueryException` 繼承自 `PDOException`，而 `PDOException` 是 `RuntimeException` 的子類，會把資料庫連線／SQL 錯誤誤報成 404／422。請用 `MapsServiceExceptions::failFromService()`，它會讓資料庫類例外原樣拋出（回 500）。
- **機器接口不要掛 `permission.api`**，也不要漏掉 `@api-auth`，否則會被當成後台用戶權限登記。
- **不要在 token 裡寫權限**：權限每次請求即時查（App 狀態）＋短暫快取（scope），這樣停用 App 或改授權才能快速生效。
- **不要用 `APP_KEY` 以外的方式保存 app_secret**：app_secret 是 `encrypted` cast 存放（需可還原以重算 HMAC）。`APP_KEY` 變更會導致已存 app_secret 無法解密，需對所有 App 重置 app_secret。
- **`$hidden`／`present()` 不輸出 `app_secret`**：任何查詢接口都不得回傳 app_secret。

## 7. 維運

### 7.1 後台管理（系統設定 → 廠商 App 管理）

- 新增／編輯（名稱、IP 白名單、過期時間、備註、**除錯模式**）／停用／刪除（軟刪除）。
- **重置 app_secret**：舊 key 立即失效。新 key 只在這一次顯示。已簽發的 access token 在到期前仍有效；需要立即中斷請同時停用 App。
- **授權**：以模塊分組勾選，可勾整個模塊（`模塊.*`）或單項；改授權最多 60 秒內生效。
- **app_secret 只在建立或重置時顯示一次**，之後任何地方都查不到明文，遺失只能重置。
- 後台 API：`/api/system/factory/apps`、`/api/system/factory/scopes`、`/api/system/factory/logs`（搜尋）、`/api/system/factory/logs/{id}`（詳情），權限碼 `api.factory.*`。
- 「廠商 App 記錄」可查：簽發成功／失敗（含原因）、權限被拒、API 呼叫（完整請求與回應，見 7.5）、後台對 App 的操作；**保留 30 天，超過自動移除**。
- **除錯模式**（每個 App 各自一個開關）：決定這個 App 的 API 呼叫要記多少，見 7.5。新開啟時會跳出確認提示。

### 7.2 首次部署／初始化

```powershell
# 1. .env 設定 JWT 密鑰（至少 32 字元，獨立於 APP_KEY、JWT_SITE_SECRET、JWT_HQ_SECRET）
FACTORY_JWT_SECRET=<隨機長字串>

# 2. 建表、同步模塊／選單／權限
php artisan site:migrate --all            # 多站模式（單站直接 php artisan migrate）
php artisan module:sync
php artisan factory:sync-scopes --site=1
php artisan permission:sync-api --site=1

# 3. 重新建置後台前端
npm run admin:build
```

選單在模塊開通流程中寫入；若直接寫入資料庫，記得更新該站的選單快取版本（`MenuCacheVersion::bump($siteId)`），否則側邊欄最多 1 小時才會出現。把 `api.factory.*` 權限指派給需要管理的後台角色（超級管理員本來就有）。

### 7.3 沒有後台帳號時用指令建立

```powershell
php artisan factory:app-create "自助訪客機 1 號" --scope=guest.apply.read --site=1
```

app_id／app_secret 只會顯示這一次。

### 7.4 排查

| 症狀 | 檢查 |
| --- | --- |
| 想知道某台機器實際送了什麼、收到什麼 | 把該 App 的「除錯模式」打開，重現後到「廠商 App 記錄」搜尋該 `app_id`，點「詳情」看完整請求（IP、header、GET／POST／JSON…）與完整回應；查完請關閉 |
| 記錄好像沒有自動清掉 | 確認正式環境有 cron 每分鐘跑 `php artisan schedule:run`（DEVELOP.MD 18.4）；`php artisan schedule:list` 應看到 `factory:logs-prune`；可手動 `factory:logs-prune --dry-run` 預覽 |
| 換 token 一直 401 | 後台「廠商 App 記錄」看失敗原因（`signature_mismatch`、`timestamp_out_of_window`、`ip_not_allowed`、`app_disabled`、`nonce_replayed`…） |
| 時間窗失敗 | 訪客機時鐘是否準確；前端是否處理 `server_time` |
| 簽名一直對不上 | 原文是否為 `app_id\|timestamp\|nonce`、是否小寫 hex、app_secret 是否為最新（重置後舊 key 失效）、`timestamp` 是否為「秒」不是毫秒 |
| 呼叫接口 403 | 該 App 是否已授權對應 scope；改授權後最多等 60 秒 |
| 後台看不到新接口的 scope | 是否執行了 `factory:sync-scopes` |
| 後台選單沒出現 | 是否 `module:sync`、選單是否已寫入、選單快取版本 |
| 換 token 回 500 | `.env` 的 `FACTORY_JWT_SECRET` 是否已設定且長度 ≥ 32 |

### 7.5 除錯模式、API 呼叫記錄與保留期限

#### 記不記：每個 App 的「除錯模式」開關

| 除錯模式 | 記錄哪些呼叫 |
| --- | --- |
| **關閉**（預設） | 只記錄**回應不是 2xx** 的呼叫（4xx、5xx 等失敗）。`201` 等 2xx 都算成功、不記錄 |
| **開啟** | **成功失敗都記錄** |

換 token（`POST /api/factory/token`）也套用同一套規則。500（例外轉成的回應）一樣記得到，因為記錄是在回應確定之後才寫入，不拖慢回應。

#### 記什麼：盡可能完整

| 分類 | 內容 |
| --- | --- |
| **索引欄位**（可搜尋／排序） | `app_id`、來源 IP、業務模塊 `module`、具體方法 `action`（`Controller@method`）、HTTP 方法、實際路徑 `path`、路由樣式 `route`（如 `/api/factory/guest/applies/{id}`）、所需 scope、狀態碼、結果（成功／失敗）、請求起始時間 `started_at`（毫秒）、處理耗時 `duration_ms`、記錄寫入時間 `created_at`、回應大小 `response_size` |
| **請求**（`request_data`，JSON） | `method`、完整 `url`（含 query）、`client`（IP、**X-Forwarded-For IP 鏈**、User-Agent、Referer、協定 http/https、主機、埠、HTTP 版本、伺服器 IP）、`content_type`、`content_length`、`route_params`（路由參數）、`query`（**GET** 參數）、`form`（**POST** 表單欄位）、`json`（**JSON** body）、`files`（上傳檔案：只記檔名／大小／類型，**不記內容**）、`raw_body`（XML、純文字、JSON 格式錯誤等無法解析的 body 原文）、**所有 request header** |
| **回應** | `status_code`、**所有 response header**（`response_headers`）、**完整回應內容**（`response_data`） |
| **為何被記錄** | `reason`：`debug`（除錯模式）或 `non_2xx`（非 2xx 回應） |

#### 搜尋（後台「廠商 App 記錄」，條件之間為「且」）

| 條件 | 說明 |
| --- | --- |
| App ID | `app_id` 精確比對 |
| 業務模塊 | 模塊代碼，如 `guest`（換 token 為 `factory`） |
| 具體方法 | `Controller@method`，模糊比對，可只填 `index` 或 `FactoryGuestApplyController@index` |
| 結果 | 成功（2xx）／失敗；API 另有 `status_code`、`method`（HTTP 方法）、`type` 可進一步篩選 |
| 時間 | 起訖時間；只給日期涵蓋整天，也可給到秒 |

列表不含請求與回應內容（可能很大），點「詳情」才載入（`GET /api/system/factory/logs/{id}`），畫面依區塊分段顯示。

#### 什麼情況會被記錄／不會
- 認得出是哪個 App 才記：token 有效但 App 已停用／過期／IP 不符的 401 會記在該 App 名下；**token 無效或亂填的匿名請求不記**（避免被灌爆日誌）。換 token 時 `app_id` 不存在也不記。
- 權限被拒（403）會有兩筆：一筆「權限被拒」（原有）、一筆「API 呼叫」（含完整請求與回應），兩者都帶業務模塊與具體方法，可搜到。

#### 保留 30 天後自動移除
- **保留期限**：`FACTORY_LOG_RETENTION_DAYS`（預設 **30 天**）。超過的記錄**自動移除**，範圍是 `factory_logs` **全部類型**（簽發、權限被拒、API 呼叫、後台操作），依 `created_at` 判斷。
- **自動執行**：每天 `FACTORY_LOG_PRUNE_TIME`（預設 03:40）由 Laravel Scheduler 執行 `factory:logs-prune`，**多站模式會遍歷所有啟用的子站**（`FACTORY_LOG_PRUNE_ENABLED=false` 可關閉）。**前提是正式環境有 cron 每分鐘跑 `php artisan schedule:run`**（DEVELOP.MD 18.4），沒有的話不會自動清，請務必確認。
- **手動**：`php artisan factory:logs-prune [--site=ID] [--days=N] [--dry-run]`（`--dry-run` 只預覽筆數）。分批刪除，不會一次鎖表。
- 需要長期保存稽核資料的話，請在清理前自行匯出；移除後無法復原。

#### 安全與容量
- **敏感值遮罩**：欄位或 header 名稱含 `password`、`passwd`、`secret`、`token`、`authorization`、`api_key`、`cookie` 的值一律存成 `***`（巢狀也處理，**query、form、json、header 都適用**），包含換 token 回應裡的 `access_token`；`token_type` 這類名稱含 token 但不是機密的欄位不遮罩。`sign`（一次性簽名）保留，方便排查簽名問題。
- 除錯記錄仍可能包含**業務資料**（例如訪客姓名、電話），所以預設關閉，新開啟時會跳確認；**查完請關閉**。
- **區塊上限**：每個區塊（headers、query、form、json、files、raw_body、回應內容）各自超過 `FACTORY_LOG_MAX_BYTES`（預設 64KB）就截斷並標註原始大小；請求區塊截斷時以標註物件取代，整體 JSON 仍合法。
- **時間**：`started_at`（請求起始，毫秒）與 `created_at`（寫入記錄）皆為應用時區（`APP_TIMEZONE`）。

**程式位置**：記不記、收集、遮罩、截斷都在 `app/Support/Factory/FactoryCallLogger.php`；`factory.auth` middleware 在 `terminate()` 呼叫它，換 token 在 `FactoryAuthController`。清理在 `app/Support/Factory/FactoryLogPruner.php` 與 `FactoryLogsPruneCommand`。新增機器接口不需要做任何事，掛了 `factory.auth` 就自動套用。

## 8. 設定（`.env`／`config/factory.php`）

| 項目 | 環境變數 | 預設 | 說明 |
| --- | --- | --- | --- |
| JWT 簽名密鑰 | `FACTORY_JWT_SECRET` | — | **必填**，至少 32 字元 |
| JWT issuer | `FACTORY_JWT_ISSUER` | `factory-api` | 驗證 token 時比對 |
| token 有效秒數 | `FACTORY_JWT_TTL` | 3600 | |
| 簽名時間窗 | `FACTORY_TIMESTAMP_WINDOW` | 300 | timestamp 容許偏差秒數 |
| nonce 快取秒數 | `FACTORY_NONCE_TTL` | 600 | 需大於時間窗 |
| scope 快取秒數 | `FACTORY_SCOPE_CACHE_TTL` | 60 | 後台改授權時會主動清除該 App 的快取 |
| 換 token 限流（每 IP／分鐘） | `FACTORY_TOKEN_RATE_PER_IP` | 30 | |
| 換 token 限流（每 app_id／分鐘） | `FACTORY_TOKEN_RATE_PER_APP` | 20 | |
| 記錄區塊上限（bytes） | `FACTORY_LOG_MAX_BYTES` | 65536 | 每個區塊（headers、query、json、回應內容…）各自超過就截斷 |
| 記錄保留天數 | `FACTORY_LOG_RETENTION_DAYS` | 30 | 超過自動移除，`factory_logs` 全部類型 |
| 自動清理開關 | `FACTORY_LOG_PRUNE_ENABLED` | true | 需 cron 每分鐘跑 `schedule:run` |
| 自動清理時間 | `FACTORY_LOG_PRUNE_TIME` | 03:40 | 每天幾點執行 `factory:logs-prune` |

## 9. 資料表（子站庫，`site_current` 連線）

| 表 | 說明 |
| --- | --- |
| `factory_apps` | App：`app_id`（隨機、唯一）、`name`、`app_secret`（加密）、`status`、`is_debug`（除錯模式）、`allowed_ips`（JSON，IP／CIDR）、`expired_at`、`last_used_at`、軟刪除 |
| `factory_scopes` | 已登記的 scope（由 `factory:sync-scopes` 寫入，供後台勾選；路由已不存在的 scope 不會自動刪除，只在指令輸出提示） |
| `factory_app_scopes` | App 的授權。`scope` 存字串而非外鍵，因為授權要支援 `模塊.*` 與 `*`，這兩種不會登記在 `factory_scopes` |
| `factory_logs` | 簽發成功／失敗（`token`）、權限被拒（`scope_denied`）、API 呼叫（`call`）、後台操作（`admin`）；`reason` 只供後台查看，不回給呼叫方。`call` 類另有 `module`（業務模塊）、`action`（`Controller@method`）、`method`、`path`、`route`、`status_code`、`started_at`、`duration_ms`、`request_data`、`response_headers`、`response_data`、`response_size`（內容見 7.5）；`app_id`、`module`、`action`、`status_code`、`created_at` 有索引供搜尋；**保留 30 天後自動移除** |

## 10. 安全注意事項

1. **app_secret 會出現在前端 JS 執行環境**（前端要自己算簽名）。訪客機是受控設備、權限小，這個風險可接受。緩解：只授權該機器實際需要的 scope（不要輕易給 `模塊.*` 或 `*`）、設 IP 白名單、短 token 有效期、可隨時停用與重置、換 token 限流。**若之後有高權限的 App，不要用前端自簽，改由受信任的後端持有 app_secret**。
2. **HTTPS**：JWT 是 bearer 憑證，被截獲即可使用。生產環境建議全站 HTTPS；內網 http 時務必設 IP 白名單。
3. JWT 驗證**固定只接受 HS256**，並驗證 `iss`，防 alg 混淆。
4. 簽發失敗一律同一個 401，不讓攻擊者分辨 app_id 是否存在。
5. 簽名正確後才佔用 nonce，避免攻擊者亂填 nonce 消耗快取。
6. **機器接口不限於查詢，寫入類（報到、離場、存放證件位置等）一樣支援**：框架層面沒有任何「只讀」限制，`factory.scope` 只比對授權的 scope，不看 HTTP 方法。寫入類接口的風險靠「最小授權＋業務層檢查」控制，做法見第 5 節「寫入類接口」。
