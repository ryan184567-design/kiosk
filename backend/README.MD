# base｜Laravel 多站開發框架（開發者指南）

基礎開發框架，不含業務，內含基礎管理 API（Auth／Role／User／Menu／Portal／SystemConfig／Dictionary／Plugin／Site）。
架構：**主站（HQ）＋子站（site）**，每個站各自獨立資料庫；程式依模塊放置（`app/Api/{module}`），可在多站環境開發，再打包成單站部署。

| 文件 | 用途 |
| --- | --- |
| **README.MD（本檔）** | 照著做：環境、命令、開發流程、約定 |
| **DEVELOP.MD** | 為什麼這樣設計、完整細節、踩坑紀錄、功能現況與已知落差（22 章） |

> 想知道「為什麼」「完整規則」「某功能的細節」→ 查 DEVELOP.MD；本檔各節末尾會標註對應章節。

---

## 1. 環境與首次建置（新環境做一次）

**版本基線（勿任意變更）**：PHP 8.2.29、Laravel 12.x、MySQL 8.4（`utf8mb4_unicode_ci`）、Redis 6.2、Node 與 `package-lock.json` 相符。

```powershell
# 每次開新終端機：UTF-8 與 PHP 8.2
[Console]::InputEncoding  = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
chcp 65001 > $null
$env:Path = "D:\wamp64\bin\php\php8.2.29;" + $env:Path

# 1) 依賴與前端建置（編譯產物不進版控，新環境必做；沒建置 /hq、/admin 會 404）
composer install
npm install
npm run hq:build; npm run admin:build; npm run vue:build

# 2) .env：複製 .env.example 後填入（密碼沒填 seeder 會中斷）
#    DB_*、HQ_ADMIN_PASSWORD、SITE_SUPER_ADMIN_PASSWORD、SITE_DELEGATION_ROOT_PASSWORD
#    MULTI_SITE_ENABLED=true（開發一律 true）、SITE_DB_PREFIX=base_site_

# 3) 密鑰（JWT 兩把密鑰沒設，登入後每支 API 都會報 "Key material must not be empty"）
php artisan key:generate --ansi
php artisan jwt:secret                    # 只補空值；--force 全部重產（既有 token 失效）

# 4) 資料庫（順序不可顛倒：先主站，再開子站）
php artisan migrate:fresh --seed        # 多站模式下自動只建「主站」庫（見下方「migrate／db:seed 防呆」）
php artisan site:create default 預設開發站 --type=standard    # 自動建子站庫、跑子站 migration 與 seed

# 5) 啟動
php artisan serve --host=127.0.0.1 --port=8000      # 或 composer run dev（serve+queue+log+vite）
```

驗證：`php artisan route:list`（80+ 筆）、`/api/ping` 回 `pong`、`public/hq-assets/index.html` 與 `public/admin-assets/index.html` 存在。

**`migrate`／`db:seed` 防呆（可以放心裸跑）**

本專案有兩套資料庫結構：主站 `database/hq/migrations`、子站 `database/migrations`。為避免把子站表建進主站庫，標準指令已改成「依目前模式做對的事」：

| 指令 | 多站模式（`MULTI_SITE_ENABLED=true`，開發／SaaS） | 單站模式（部署包） |
| --- | --- | --- |
| `php artisan migrate`（含 `:status`、`:rollback`、`:reset`、`:fresh`、`:refresh`） | 自動使用主站路徑，只動主站庫 | 照 Laravel 預設（`database/migrations`） |
| `php artisan db:seed`（含 `migrate:fresh --seed`） | 自動跑主站 `HqDatabaseSeeder` | 跑 `SiteBaselineSeeder` |
| 明確帶 `--path` 或 `--database`、`--class` | **完全照你指定的執行**（`site:migrate`、建站流程就是這樣用） | 同左 |

要動**子站**庫，仍用 `site:migrate`（見 4.2），不要自己拼 `--database`。
另一個要避免的：執行期呼叫 `env('DB_...')`（`config:cache` 後回 `null`，登入就 500）；連線走 `App\Support\Site\SiteDatabaseConfig`。

---

## 2. 目錄速查

```text
app/Api/{Module}/         子站共用 API：Controllers／Models／Services／routes／module.json
app/Hq/{Module}/          主站專屬（站點管理），與子站完全獨立
app/Web/{Portal}/{Mod}/   各端口 Blade 頁骨架與路由（業務邏輯仍在 Api）
app/Support/{Concept}/    共用層（Auth、Site、Api、Audit、Plugin、Sso、Factory…）；共用層不得依賴 app/Hq
app/Sites/{SiteCode}/     子站客製化：覆寫 Service，或公版沒有的全新 API
app/Plugins/{Func}/{Brand}/{Ver}/   案場對接插件（AD／SSO／設備）
resources/vue_src*        前台／子站後台（_admin）／主站後台（_hq）三套 SPA 原始碼
database/migrations       子站公版 migration；hq/migrations 主站；sites/{code}/ 客製站專屬
storage/app/sites/{id}.php  每站連線設定檔（開站時產生，執行期只讀它）
```

Model 放「擁有它的模塊」的 `Models/`（模塊要能整包移除）；框架核心 Model 才放 `app/Support/{Concept}/Models/`。

---

## 3. 開發約定（必守）

1. 動工前先讀本檔與 DEVELOP.MD 相關章節；做完把「後人要遵循的內容」寫回文件。
2. API 放 `app/Api/{module}`（Model-Service-Controller）；Controller 只驗證與轉發，邏輯在 Service。
3. 輸入只經 `$this->validateInput()`；禁用 `$_GET`／`$_POST`／`$_REQUEST`；一律走 ORM／參數綁定。
4. 可能被子站覆寫的 Service，在 Controller 用**方法參數注入**，不要建構子注入，也不要 `new XxxService()`。
5. 新模塊動工前確認：登記進 `modules`（`module.json`）、給哪些端口、路由掛 `module:{code}`、是否開放 App Token（廠商模式，見 5.8）。
6. 文案一律走語言包：`lang/zh_TW/api.php`（`app/Api`、`app/Support`）、`lang/zh_TW/hq.php`（`app/Hq`）。
7. Swagger 標註要自成一體（入參、回傳欄位、字典選項都寫清楚），不可寫「見 README」。
8. 分頁用 `PaginationHelper`，註明 `per_page=-1` 不分頁；刪除一律軟刪除。
9. 安全設定從第一天就用嚴格等級（CSP、CORS 白名單、速率限制），本機也一樣，不可為了方便放寬（DEVELOP.MD 4.2）。
10. 每個檔案開頭加 Header 註解；保留既有邏輯註解；臨時測試腳本用完刪除。
    ```php
    /* *****************************************
    File: DashboardController.php
    Author: cliffdean yangtinghui@gmail.com
    Date: 2026-06-18
    Description: Dashboard 統計數據控制器
    ***************************************** */
    ```
11. 方案不明確先提問，不要自行做出偏離設計的決策。指令範例一律 PowerShell 語法。

---

## 4. 命令速查

### 4.1 服務與前端

| 用途 | 指令 | 位址 |
| --- | --- | --- |
| 後端（API／Blade） | `php artisan serve` | `http://127.0.0.1:8000` |
| 子站前台 dev | `npm run vue:dev` | `http://localhost:5173/vue/` |
| 主站後台 dev | `npm run hq:dev` | `http://localhost:5175/` |
| 子站後台 dev | `npm run admin:dev` | `http://localhost:5176/` |
| Blade 資產 | `npm run dev`／`npm run build` | — |

SPA dev server 已用 proxy 轉發 `/api`（可用 `VUE_DEV_API_PROXY` 覆寫），**不需要開 CORS**。

### 4.2 資料庫與多站

```powershell
php artisan migrate --ansi                                        # 主站 migration（多站模式自動使用主站路徑）
php artisan site:create vendor_a "A 客戶站" --type=customized      # 開新子站（自動建庫＋migration＋seed）
php artisan site:migrate 2                                        # 單站同步（自動疊加 database/sites/{code}/）
php artisan site:migrate --all                                    # 所有啟用站
php artisan site:migrate --all --seed=Database\Seeders\SiteBaselineSeeder   # 重跑 baseline seed
php artisan site:package vendor_a --output=D:\deploy\vendor_a     # 匯出單站部署包
php artisan site:cleanup-failed                                   # 清理建站失敗的孤兒庫
```

### 4.3 腳手架（產生骨架，之後手動補邏輯）

```powershell
php artisan module:make order [--portal=vendor] [--site=vendor_a]   # 模塊骨架（公版會互動產生 module.json）
php artisan module:sync                                             # 掃 module.json → modules 表
php artisan vue:page:make vendor_a order-dashboard                  # 子站客製化 Vue 頁骨架
php artisan plugin:make ad microsoft v2019                          # 插件骨架（要手動登記 config/plugins.php）
php artisan db:table:scaffold users [--only=migration|seed]         # 依現有表產生 migration／seed
```

### 4.4 權限、文件、維護、測試

```powershell
php artisan permission:sync-api --site=1 --assign-admin=admin    # 同步 API 權限
php artisan permission:sync-web --site=1 --assign-admin=admin    # 同步 Web 頁面權限
php artisan permission:audit-ceiling --site=1                    # 稽核越權／跨端口洩漏
php artisan swagger:generate --pretty                            # 產生 API 文件（/docs、/docs/partner、/docs/hq）
php artisan optimize:clear                                       # 改 .env／config 後；之後可補 package:discover
php artisan jwt:sessions:prune [--dry-run]                       # 清過期 JWT 會話（排程每日 03:20）
php artisan guest:stats-aggregate [--date=|--from= --to=]        # 彙整訪客每日統計到緩存表 guest_daily_stats（排程每日 00:05 彙整前一日；補歷史資料用 --from/--to，可重跑）
php artisan dashboard:report-heartbeat [--site=]                # 給儀錶板上報一次心跳（排程每分鐘執行，各子站依「儀錶板上報設定」決定是否上報；上報走佇列：5 秒逾時、失敗 10 秒後重試、共 3 次失敗丟棄，需 queue:work 常駐，見 docs/DASHBOARD_REPORT.MD）
php artisan test                                                 # 目前 ExampleTest 預期失敗（/ 會 302 到 /admin）
vendor/bin/pint                                                  # 格式化；--test 只檢查
composer audit; npm audit                                        # 依賴漏洞掃描
```

標註缺漏時 `swagger:generate` 與 `permission:sync-api` 會**直接報錯中斷**，訊息會指出哪支 Controller。

---

## 5. 常見開發任務

> 端口 = `portal`（登入入口與角色／選單分組，如 `admin`），不是 TCP port。
> 以下每項的完整說明在 DEVELOP.MD 20 章。

### 5.1 改完要跑什麼

| 你改了… | 要跑 |
| --- | --- |
| 新增模塊／改 `module.json` | `module:sync` |
| 新增／改名 API、`@api-*` 註解 | `permission:sync-api --site=1 --assign-admin=admin` → `swagger:generate --pretty` |
| 新增／改名機器接口（`factory.scope`，5.8） | `factory:sync-scopes --site=1` → 後台授權 → `swagger:generate --pretty` |
| 新增 Web 頁路由（`permission.web`） | `permission:sync-web --site=1 --assign-admin=admin` |
| 公版 migration | `site:migrate --all` |
| `SiteBaselineSeeder`（端口、選單分組、設定項、字典） | `site:migrate --all --seed=Database\Seeders\SiteBaselineSeeder` |
| `.env`／`config/*.php` | `optimize:clear` |
| 子站後台／子站前台（`vue_src_admin`／`vue_src`） | `php artisan vue:build --clean`（兩套一起建）；只改後台可單跑 `npm run admin:build` |
| composer／npm 套件 | `composer install`／`npm install`，lock 檔一併提交 |

> ⚠ seeder **不會自動套用到既有子站**，重跑會覆蓋後台手改過的同一列（如選單標題）。

### 5.2 新增模塊

```powershell
php artisan module:make order
```

骨架的 `routes/api.php` **只有 `module:order`，沒有登入與權限檢查**，必須補成（順序：module → jwt.auth → permission.api）：

```php
Route::middleware(['module:order', 'jwt.auth'])->prefix('orders')->group(function () {
    Route::middleware('permission.api:api.order.index')->get('/', [OrderController::class, 'index']);
    Route::middleware('permission.api:api.order.store')->post('/', [OrderController::class, 'store']);
});
```

1. 補路由的 `jwt.auth`／`permission.api`；每個 Controller 方法寫 `@api-*` 註解。
2. Model 放模塊 `Models/`，migration 放 `database/migrations/`，跑 `site:migrate --all`。
3. 確認 `module.json`（`is_core`、`depends`、`menus`），跑 `module:sync` 與 `permission:sync-api`。
4. 非核心模塊：主站後台「模塊管理」對要用的子站**開通**（未開通：API 回 `20016`、選單不顯示）。
5. 要有畫面：加選單與前端頁（5.3）。

> ⚠ 權限碼固定 **`api.{模塊}.{Controller 方法名}`**，路由寫的碼必須與方法名一致，否則一律 403。

### 5.3 新增左側選單

三件事缺一不可：

**① 選單資料**：寫在 `app/Api/{Module}/module.json` 的 `menus`（建議；後台「選單管理」手加只影響單站）：

```json
"menus": [{
  "portal": "admin", "key": "orders", "title": "訂單管理",
  "icon": "Tickets",                 // Element Plus 圖示名稱
  "path": "/orders",                 // 必須等於 ② 的前端路由（含開頭 /）
  "permission_code": "api.order.index",
  "group": "group-system",           // 或 group-account；null = 頂層
  "sort": 10
}]
```

**② 前端路由與頁面**：新增 `resources/vue_src_admin/src/pages/order/OrderList.vue`，在 `router/index.js` 的 `children`（404 路由之前）加：

```js
{ path: 'orders', name: 'orders', meta: { title: '訂單管理' }, component: () => import('../pages/order/OrderList.vue') },
```

**③ 套用**：新站自動帶入；既有站非核心模塊於「開通」時自動補註冊；**核心模塊或事後改了 `module.json`** 需重跑 baseline seed。

沒出現時檢查：模塊已開通？角色有該模塊權限？`permission_code` 與權限碼一致？（兩者皆空的葉節點不顯示）→ `optimize:clear`。
新增「分組」節點：改 `SiteBaselineSeeder::seedAdminMenu()` 的 `$groups`（目前只有 admin 端口）。

### 5.4 新增端口（portal）

1. 建立資料列：後台「端口管理」（單站），或在 `SiteBaselineSeeder::run()` 仿 `admin` 寫 `Portal::updateOrCreate` 後重跑 seed。欄位：`portal_key`、`admin_only`、`requires_login`、`is_enabled`（不可刪除）。
2. 後台「角色管理」建該端口的角色；「使用者管理」把使用者加入該端口並指派角色／端口管理員。
3. API 註解 `@api-portal` 填新端口（或 `*`），再 `permission:sync-api`；`module.json` 的 `menus[].portal` 填新端口。
4. 需要畫面才建前端：複製 `resources/vue_src_admin` → `vue_src_{portal}`，改 `stores/auth.js` 的 **`PORTAL_KEY`**、`vite.{portal}.config.js`（`base`／`outDir`／`port`）、`package.json` 的 `{portal}:dev`／`{portal}:build`，並新增 SPA 入口控制器與 `routes/web.php` 路由（放在 `Route::fallback` 之前）。

### 5.5 權限

鏈路：Controller 方法＋`@api-*` →（`permission:sync-api`）→ `permissions` 表 → 後台「角色管理」勾選 → 路由 `permission.api:{code}` 比對。**任一段漏掉都是 403**。

- 端口管理員、超級管理員本來就全開；其他角色在「角色管理」勾選；支援 `api.order.*`。
- 權限存在各子站庫，多站要各跑一次（`--site=N`）。只需登入不綁權限：路由只掛 `jwt.auth`。
- 只能授予自己持有的權限（超級管理員／系統管理員除外）。

### 5.6 依賴

- 模塊依賴：`module.json` 的 `"depends": ["user","role"]`。開通會連動；被依賴者要關須先關依賴它的模塊；核心模塊不要依賴非核心模塊；改完 `module:sync`。
- 套件依賴：`composer require`／`npm install`，lock 檔一併提交（單站部署包靠 lock 還原）；提交前 `composer audit`／`npm audit`。

### 5.7 其他常見調整

| 要調整 | 位置 | 備註 |
| --- | --- | --- |
| dev server 埠號 | `vite.vue.config.js`(5173)／`vite.hq.config.js`(5175)／`vite.admin.config.js`(5176) 的 `server.port` | 改 serve 埠時同步 `VUE_DEV_API_PROXY`、`APP_URL`；不用改 CORS／CSP |
| 系統設定項／字典 | `SiteBaselineSeeder` 的 `seedSiteBasicConfig()`、字典 seed | 只開放改值；新增項目要改 seeder 後重跑 |
| 初始帳號 | `config/initial_accounts.php` ＋ `.env` | 密碼沒填 seeder 會中斷 |
| `.env`／config | `.env.example` ＋ `config/*.php`（`env()` 只能在 config 內） | 新增必填項同步 `resources/deploy/DEPLOY.md` |
| 錯誤碼 | `ErrorCode.php` ＋ `ErrorCatalog.php`（`STATUS`、`MESSAGE_KEYS`）＋ `lang/zh_TW/api.php` | 漏登記回 500「未知錯誤」 |
| 插件 | `plugin:make` → 登記 `config/plugins.php`（key `{brand}_{version}`）→ 後台插件管理啟用 | — |

---

### 5.8 開放廠商模式（App Token）給機器／第三方呼叫

機器（訪客機等）沒有用戶賬號，用 **App 身份** 呼叫 `/api/factory/*`（與用戶登入並存、互不影響）。完整指南見 `docs/APP_DEVELOP.MD`，設計摘要見 DEVELOP.MD 12.9。

**分工：**

| 層 | 放什麼 | 位置 |
| --- | --- | --- |
| 框架核心 | 簽名驗證、JWT、scope 比對、`factory.auth`／`factory.scope` middleware、`config/factory.php` | `app/Support/Factory/` |
| 核心模塊 `factory` | **只放管理功能**：換 token（`POST /api/factory/token`）、App／授權／記錄的後台管理（`/api/system/factory/*`）。核心模塊恆為開通 | `app/Api/Factory/` |
| **各業務模塊** | **自己**的機器接口（具體業務操作） | `app/Api/{Module}/Controllers/Factory{Xxx}Controller.php` |

> ⚠ **不要把任何業務模塊的接口放進 `app/Api/Factory`**。業務接口屬於擁有該業務的模塊（整包可移除、模塊沒開通即被擋）；`Factory` 模塊只管「誰（`app_id`）能不能呼叫」。

**必守規則（已有測試把關：`tests/Feature/Factory/FactoryAuthTest.php`）：**

| 項目 | 規則 |
| --- | --- |
| 位置 | `app/Api/{模塊}/Controllers/` **根層**，不可放子資料夾（`permission:sync-api`、`swagger:generate` 只掃根層，放子資料夾會被漏掉） |
| 類名／檔名 | **`Factory{Xxx}Controller`**（`Xxx` 大駝峰，描述業務，如 `FactoryGuestApplyController`） |
| `Factory` 前綴保留 | 只能用在路徑為 `/api/factory/*` 的 Controller，且該 Controller 只放 `/api/factory/*` 的接口；後台管理（`/api/system/factory/*`）與一般接口**不可**用此前綴；端口（portal）也不要取名 `factory`（會與 DEVELOP.MD 9.1.1 的 `{PortalKey}{Feature}Controller` 混淆） |
| 路徑 | `/api/factory/{模塊代碼}/...`（`Route::prefix('factory/{模塊}')`） |
| 路由 middleware | `['module:{code}', 'factory.auth']`，單支再掛 `factory.scope:{模塊}.{資源}.{動作}`；**不可**掛 `jwt.auth`／`permission.api` |
| 註解 | `@api-portal *`、`@api-auth app`、`@api-scope {scope}`（須與路由 `factory.scope:` 完全一致）；不需認證的（換 token）標 `@api-auth public` |
| 權限 | scope **不進** `permissions` 表（`permission:sync-api` 會略過標了 `@api-auth` 的接口）；改跑 `factory:sync-scopes` 登記，再到後台「廠商 App 管理 → 授權」勾選 |
| Swagger | `/api/factory/*` 進第三方文件 `/docs/partner`，不在內部文件 `/docs`（DEVELOP.MD 12.8） |
| 一般接口誤用 | App Token 拿去呼叫 `/api/factory/*` 以外的接口一律 403 |

> 機器接口**不限於查詢**：報到、離場、存放證件位置等寫入類操作同樣支援，沒有「只讀」限制；風險靠逐一授權 scope（最小授權）與 Service 層的業務檢查控制，指引見 `docs/APP_DEVELOP.MD` 5 節「寫入類接口」。

> 排查機器問題：後台「廠商 App 管理」每個 App 有「**除錯模式**」開關。關閉時只記錄非 2xx 的呼叫；開啟時成功失敗都記錄（含完整請求與回應，敏感值遮罩）。到「廠商 App 記錄」可依 **App ID、業務模塊、具體方法、結果、時間** 搜尋，詳情含 IP、時間、接口、所有 header、GET／POST／JSON 參數、狀態碼與完整回應。**記錄保留 30 天，超過由每日排程 `factory:logs-prune` 自動移除（需 cron 每分鐘跑 `schedule:run`）**。新增機器接口不需額外處理；除錯資料量大，查完請關閉。詳見 `docs/APP_DEVELOP.MD` 7.5。

**新增一支機器接口：**
1. 在**自己的模塊**寫 `Factory{Xxx}Controller`（業務邏輯沿用既有 Service，不要複製），只回傳機器需要的欄位。
2. 路由放自己的 `routes/api.php`，照上表掛 middleware；每個方法寫 `@api-*` 註解。
3. `factory:sync-scopes --site=1` → 後台授權 → `swagger:generate --pretty`。
4. 補測試（照 `FactoryAuthTest`：缺 token＝401、缺 scope＝403、查無資料＝404）。

範例：`app/Api/Guest/Controllers/FactoryGuestApplyController.php` 與 `app/Api/Guest/routes/api.php` 最下方的 `factory/guest` 群組。

### 5.9 訪客模組公司端口（company）

一個門牌＝一家公司。後台「訪客管理 › 公司帳號管理」替公司建立**公司管理員**帳號，對方登入 `/company`（前端 `resources/vue_src_company`）自行管理員工、指定審核者、設定審核開關。

| 身分（`guest_company_members.member_type`） | company 端口角色 | 可做的事 |
| --- | --- | --- |
| 1 公司管理員 | 公司管理員 | 員工管理、審核設定、審核、預約 |
| 2 審核者 | 審核者 | 審核、預約 |
| 3 一般員工 | 一般員工 | 登記與管理預約、訪客紀錄 |

- **資料隔離**：同一組 `/api/guest/applies|approvals|records` API 兩個端口共用。登入 company 端口時，`EnsureGuestCompanyScope` 依成員紀錄套上公司範圍，`GuestApply` 的全域範圍自動加 `doorplate_id` 條件；admin 端口看全部。**新增訪客 API 時不用自己加公司條件，但不能用 `DB::table('guest_applies')` 或 `withoutGlobalScope` 繞過 Model**。
- **審核規則**：公司的審核開關（`doorplates.approval_required`）**只影響員工從 company 端口新增／修改的預約**（免審核→10／11）。其他來源的訪客申請（後台建立等）一律待審核，由受訪公司的公司管理員／審核者（或後台）審核。company 端口只能核准（1）或拒絕（70）待審核的單。
- **角色**：三個角色由 `SiteBaselineSeeder::seedGuestCompanyPortal()` 建立並覆寫權限碼，**不要在後台改名或改權限**（帳號依名稱指派角色）；要調整就改 seeder 後重跑 baseline seed。
- 前端：`npm run company:build`（開發 `company:dev`，埠號 5177），輸出 `public/company-assets`。
- **登入入口相容**：帳號密碼正確但走錯入口時，`/api/auth/login` 回 `20018`（PORTAL_MISMATCH）並附 `data.portal_keys`；後台登入頁遇到公司帳號會自動改登入 company 端口並跳到 `/company`，反之亦然。新增有前端的端口時，記得在各 SPA `stores/auth.js` 的 `SWITCHABLE_PORTALS` 補上。

## 6. 前端（Vue3）：共用、客製化與部署

### 6.1 四套前端，各自獨立建置

| 套 | 原始碼 | 開發 | 建置 | 輸出（不進版控） |
| --- | --- | --- | --- | --- |
| 子站前台（可客製化） | `resources/vue_src` | `npm run vue:dev` | `php artisan vue:build`（預設連同子站後台） | `public/vue`、`public/vue-sites/{code}` |
| 子站後台（admin，全站共用） | `resources/vue_src_admin` | `npm run admin:dev` | `npm run admin:build`，或隨 `php artisan vue:build` 一併建置 | `public/admin-assets` |
| 主站後台（hq） | `resources/vue_src_hq` | `npm run hq:dev` | `npm run hq:build` | `public/hq-assets` |
| Blade 資產 | `resources/css`、`resources/js` | `npm run dev` | `npm run build` | `public/build` |

改哪套就重建哪套；新環境與每次部署都要重建。

> `php artisan vue:build`（含 `--site=`、`--all-sites`）**預設會一併建置子站後台**（`admin:build`），不需要時加 `--no-admin`。只跑 `npm run vue:build` 不會建後台，`resources/vue_src_admin` 的改動（例如訪客管理頁）不會生效。

### 6.2 子站前台：共用還是獨立

每個子站的前台只有兩種情況，由該站的 `vue_build_mode` 決定：

| 情況 | 什麼時候用 | `vue_build_mode` | 建置指令 | 網站讀哪一份 |
| --- | --- | --- | --- | --- |
| **共用前台**（預設） | 跟公版一樣，或只多幾個自己的頁面 | `shared` | `vue:build` | `public/vue`（所有共用站同一份） |
| **獨立前台** | 整組頁面都不一樣，或要拿去客戶現場單獨部署 | `dedicated` | `vue:build --site={code}` | `public/vue-sites/{code}`（這個站自己一份） |

絕大多數站都是共用前台。改了共用前台，所有共用站會同時生效；獨立前台的站則要另外重建才會吃到。

```powershell
php artisan vue:page:make vendor_a order-dashboard   # 幫 vendor_a 新增專屬頁面 → site-overrides/vendor_a/pages/OrderDashboard.vue
php artisan vue:build --clean                        # 共用前台＋子站後台
php artisan vue:build --site=vendor_a --clean        # vendor_a 的獨立前台 → public/vue-sites/vendor_a＋子站後台（若該站是共用前台，自動改建 public/vue）
php artisan vue:build --all-sites                    # 共用前台＋所有獨立前台站＋子站後台（CI）
```

- 客製化只放 `resources/vue_src/src/site-overrides/{site_code}/`；**不要在共用頁面寫 `if (siteCode === ...)`**。
- 要把某站改成獨立前台：該站 `vue_build_mode` 改為 `dedicated`（主站 `sites` 表＋`storage/app/sites/{id}.php` 兩處都要改），否則網站仍讀共用的 `public/vue`。
- 只改樣式／文案／Logo／主題色 → 用後台「系統設定」，不需客製化。
- 後台（admin）全站共用：客戶差異靠**模塊開通、選單、角色權限、系統設定**；要讓某客戶多一個後台頁面，做成模塊只對他開通。
- Blade 覆寫：同名檔放 `resources/views/sites/{code}/{module}/`；後端客製：`app/Sites/{SiteCode}/`；專屬 migration：`database/sites/{code}/migrations`（`site:migrate` 自動疊加）。

> ⚠ **尚未實作**：共用前台站的「專屬頁面動態掛載」（`custom_routes` 目前固定回空陣列，頁面做了也掛不上去）、獨立前台的「公版＋該站覆寫檔合併後建置」（`--site` 目前只換輸出目錄，內容其實還是公版）。（`vue:build --output=` 已實作，單站部署包用。）做某站前台客製化前先補實作並寫回文件，詳見 DEVELOP.MD 21.2、21.3。

### 6.3 部署

```powershell
# 多站／SaaS
composer install --no-dev --optimize-autoloader
php artisan migrate --force                  # 多站模式：主站庫
php artisan site:migrate --all
npm ci
npm run hq:build
php artisan vue:build --all-sites            # 子站前台（共用＋所有獨立前台站）＋子站後台 admin
npm run build                                # 有用 @vite 的 Blade 頁才需要
php artisan config:cache; php artisan route:cache; php artisan view:cache
```

- 只對外暴露 `public/`。共用前台改動會讓**所有 shared 站同時生效**；dedicated 站要重新 `vue:build --site=` 才吃得到。
- 單站部署包（`site:package`）只含該站客製化、不含 `vendor/`、`node_modules/`：現場 `composer install`、`npm ci` 並重建前端（DEVELOP.MD 6.8、21.5）。
- 驗收：`/hq`、`/admin`、`/{code}/admin`、前台皆非 404；主控台無 CSP violation；以路徑前綴站登入一次確認 API 打到正確站台。獨立 dev server 看不到 CSP 違規，**上線前必須建置後走 Laravel 路由驗證**（DEVELOP.MD 4.2.1）。
- 排程：生產環境每分鐘觸發一次 `php artisan schedule:run`（DEVELOP.MD 18.4）。`optimize:clear` 會刪 `bootstrap/cache` 的 manifest，清完補 `php artisan package:discover`。

---

## 7. 初始帳號與 API 測試

登入一律用**帳號＋密碼＋端口（`portal_key`）**，不用 email。目前 seed 的端口只有 `admin`。

| 角色 | 帳號（預設） | 密碼來源 | 所在庫 |
| --- | --- | --- | --- |
| 主站管理員 | `admin`（`HQ_ADMIN_USERNAME`） | `.env` `HQ_ADMIN_PASSWORD` | 主站 |
| 子站超級管理員（我方） | `admin`（`SITE_SUPER_ADMIN_USERNAME`） | `.env` `SITE_SUPER_ADMIN_PASSWORD` | 各子站 |
| 子站系統管理員（交付客戶） | **站代碼**（`SITE_DELEGATION_ROOT_USERNAME`） | `.env` `SITE_DELEGATION_ROOT_PASSWORD` | 各子站 |

```powershell
$body = @{ username = 'admin'; password = '密碼'; portal_key = 'admin' } | ConvertTo-Json
$resp = Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/auth/login" -Method Post -ContentType "application/json" -Body $body
$token = $resp.data.access_token
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/user/profile" -Headers @{ Authorization = "Bearer $token" }
```

回應格式：成功 `{"status":true,"error_code":0,"data":{}}`；失敗 `{"status":false,"error_code":10001,"error_message":"..."}`。
錯誤碼：`1xxxx` 參數／驗證、`2xxxx` 認證授權（`20015` Token 被新登入取代、`20016` 模塊未開通、`20017` 端口僅限端口管理員）、`3xxxx` 業務、`5xxxx` 系統。

---

## 8. 常見問題

| 症狀 | 處理 |
| --- | --- |
| 指令失敗 | 確認 `php -v` 為 8.2.29、`.env` 的 `DB_*` 正確、MySQL 已啟動 |
| `/hq`、`/admin` 404 | SPA 沒建置：`npm run hq:build`／`npm run admin:build` |
| 登入後每支 API 都失敗、`Key material must not be empty` | `JWT_SITE_SECRET`／`JWT_HQ_SECRET` 沒設（機器接口另需 `FACTORY_JWT_SECRET`）：`php artisan jwt:secret`（log：`storage/logs/laravel.log`） |
| 首頁正常、登入就 500 | 多半是 `site_current` 連線或誤用 `env()`（DEVELOP.MD 6.4） |
| 畫面空白、console 有 CSP violation | 嚴格 CSP 的預期行為；`:style` 動態綁定是常見原因（DEVELOP.MD 4.2.1） |
| 改設定沒生效 | `php artisan optimize:clear` |
| API 一律 403 | 權限碼與方法名不一致、沒 `permission:sync-api`、角色沒勾選，或模塊未開通（5.5） |
| 選單沒出現 | 見 5.3「沒出現時檢查」 |
| 機器接口換 token 一直 401／500 | 後台「廠商 App 記錄」看失敗原因；500 多半是 `.env` 沒設 `FACTORY_JWT_SECRET`（至少 32 字元）：見 `docs/APP_DEVELOP.MD` 7.4 |
| 機器接口回 403 | 該 `app_id` 沒被授權對應 scope（後台授權）；或拿 App Token 去呼叫 `/api/factory/*` 以外的接口 |

**文件與程式的已知落差**（照 DEVELOP.MD 做卻失敗時先看；完整表見 DEVELOP.MD 22.1）

- `composer run swagger:generate` 不存在 → 用 `php artisan swagger:generate --pretty`。
- 建站 API 實際是 `POST /api/hq/sites`；`site:create` 沒有 `--clone-from`（只有 `--type`）。
- 廠商模式（App Token）已實作「換 token ＋ scope 授權」；**尚未實作**「app token 換 user token」（`/api/factory/auth/login`，DEVELOP.MD 18.3 第 2 步）；初始帳號以第 7 節為準。

其餘：目前狀態與未完成項目（前台是骨架頁、檔案管理暫緩、只有 `admin` 端口有跳轉登入落地頁）見 DEVELOP.MD 22.1。
