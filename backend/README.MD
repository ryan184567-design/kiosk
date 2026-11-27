# base｜Laravel 多站開發框架（開發者指南）

基礎開發框架，內含基礎管理 API（Auth／Role／User／Menu／Portal／SystemConfig／Dictionary／Plugin／Site）。

- **架構**：主站（HQ）＋子站（site），每個站各自獨立資料庫。
- **程式組織**：依模塊放置（`app/Api/{module}`），在多站環境開發，再打包成單站部署。

| 文件 | 用途 |
| --- | --- |
| **README.MD（本檔）** | 照著做：環境、命令、開發流程、約定 |
| **DEVELOP.MD** | 為什麼這樣設計、完整細節、踩坑紀錄、功能現況與已知落差（22 章） |

> 想知道「為什麼」「完整規則」「某功能的細節」→ 查 DEVELOP.MD；本檔各節末尾會標註對應章節。
> 本檔的指令一律是 PowerShell 語法，在專案的 `backend` 目錄下執行。

---

## 1. 環境與首次建置（新環境做一次）

**版本基線（勿任意變更）**

| 項目 | 版本 |
| --- | --- |
| PHP | 8.2.29 |
| Laravel | 12.x |
| MySQL | 8.4（`utf8mb4_unicode_ci`） |
| Redis | 6.2 |
| Node | 與 `package-lock.json` 相符 |

### 1.1 每次開新終端機

設定 UTF-8 與 PHP 路徑（路徑依自己的安裝位置調整）：

```powershell
[Console]::InputEncoding  = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
chcp 65001 > $null
$env:Path = "D:\wamp64\bin\php\php8.2.29;" + $env:Path
```

### 1.2 首次建置步驟

**步驟 1：安裝依賴**

```powershell
composer install
npm install
```

**步驟 2：建置前端**（編譯產物不進版控，沒建置的話 `/hq`、`/admin`、`/company` 會 404）

```powershell
npm run hq:build
php artisan vue:build
```

> `vue:build` 會一次建置子站前台、子站後台（admin）、公司端口（company），見 6.1。

**步驟 3：設定 `.env`**：複製 `.env.example` 為 `.env`，填入以下項目（密碼沒填，seeder 會中斷）：

| 項目 | 說明 |
| --- | --- |
| `DB_*` | 資料庫連線 |
| `HQ_ADMIN_PASSWORD` | 主站管理員密碼 |
| `SITE_SUPER_ADMIN_PASSWORD` | 子站超級管理員密碼 |
| `SITE_DELEGATION_ROOT_PASSWORD` | 子站系統管理員密碼 |
| `MULTI_SITE_ENABLED=true` | 開發一律 true |
| `SITE_DB_PREFIX=base_site_` | 子站資料庫名稱前綴 |

**步驟 4：產生密鑰**（JWT 密鑰沒設的話，登入後每支 API 都會報 `Key material must not be empty`）

```powershell
php artisan key:generate --ansi
php artisan jwt:secret
```

> `jwt:secret` 只補空值；加 `--force` 會全部重新產生，既有 token 全部失效。

**步驟 5：建立資料庫**（順序不可顛倒：先主站，再開子站）

```powershell
php artisan migrate:fresh --seed
php artisan site:create default 預設開發站 --type=standard
```

- 第一行：多站模式下只會建「主站」資料庫（見 1.3）。
- 第二行：自動建立子站資料庫，並跑子站 migration 與 seed。

**步驟 6：啟動**

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

> 也可以用 `composer run dev`，一次啟動 serve、queue、log、vite。

**驗證**

- `php artisan route:list` 有 80 筆以上。
- 打開 `/api/ping` 回應 `pong`。
- `public/hq-assets/index.html`、`public/admin-assets/index.html`、`public/company-assets/index.html` 都存在。

### 1.3 `migrate`／`db:seed` 防呆（可以放心直接跑）

本專案有兩套資料庫結構：主站 `database/hq/migrations`、子站 `database/migrations`。為了避免把子站的表建進主站資料庫，標準指令會依目前模式自動做對的事：

| 指令 | 多站模式（開發／SaaS） | 單站模式（部署包） |
| --- | --- | --- |
| `php artisan migrate`（含 `:status`、`:rollback`、`:reset`、`:fresh`、`:refresh`） | 自動使用主站路徑，只動主站資料庫 | 照 Laravel 預設（`database/migrations`） |
| `php artisan db:seed`（含 `migrate:fresh --seed`） | 自動跑主站 `HqDatabaseSeeder` | 跑 `SiteBaselineSeeder` |
| 明確帶 `--path`、`--database` 或 `--class` | **完全照你指定的執行**（`site:migrate`、建站流程就是這樣用） | 同左 |

- 要動**子站**資料庫，一律用 `site:migrate`（見 4.2），不要自己拼 `--database`。
- 執行期不要呼叫 `env('DB_...')`：`config:cache` 後會回傳 `null`，登入就 500。連線一律走 `App\Support\Site\SiteDatabaseConfig`。

---

## 2. 目錄速查

| 目錄 | 放什麼 |
| --- | --- |
| `app/Api/{Module}/` | 子站共用 API：Controllers／Models／Services／routes／module.json |
| `app/Hq/{Module}/` | 主站專屬（站點管理），與子站完全獨立 |
| `app/Web/{Portal}/{Mod}/` | 各端口 Blade 頁骨架與路由（業務邏輯仍在 Api） |
| `app/Support/{Concept}/` | 共用層（Auth、Site、Api、Audit、Plugin、Sso、Factory…）；**共用層不得依賴 `app/Hq`** |
| `app/Sites/{SiteCode}/` | 子站客製化：覆寫 Service，或公版沒有的全新 API |
| `app/Plugins/{Func}/{Brand}/{Ver}/` | 案場對接插件（AD／SSO／設備） |
| `resources/vue_src*` | 各套 SPA 原始碼（見 6.1） |
| `database/migrations` | 子站公版 migration |
| `database/hq/migrations` | 主站 migration |
| `database/sites/{code}/` | 客製站專屬 migration |
| `storage/app/sites/{id}.php` | 每站連線設定檔（開站時產生，執行期只讀它） |

Model 放在「擁有它的模塊」的 `Models/`（模塊要能整包移除）；只有框架核心 Model 才放 `app/Support/{Concept}/Models/`。

---

## 3. 開發約定（必守）

1. 動工前先讀本檔與 DEVELOP.MD 相關章節；做完把「後人要遵循的內容」寫回文件。
2. API 放 `app/Api/{module}`（Model-Service-Controller）；Controller 只負責驗證與轉發，邏輯放在 Service。
3. 輸入只經 `$this->validateInput()`；禁用 `$_GET`／`$_POST`／`$_REQUEST`；一律走 ORM／參數綁定。
4. 可能被子站覆寫的 Service，在 Controller 用**方法參數注入**，不要用建構子注入，也不要 `new XxxService()`。
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
11. 方案不明確先提問，不要自行做出偏離設計的決策。指令範例一律用 PowerShell 語法。

---

## 4. 命令速查

> 下表的 `--site=1` 代表 1 號站（預設開發站）；多站時每個站都要各跑一次。

### 4.1 服務與前端 dev server

| 用途 | 指令 | 網址 |
| --- | --- | --- |
| 後端（API／Blade） | `php artisan serve` | `http://127.0.0.1:8000` |
| 子站前台 | `npm run vue:dev` | `http://localhost:5173/vue/` |
| 主站後台（hq） | `npm run hq:dev` | `http://localhost:5175/` |
| 子站後台（admin） | `npm run admin:dev` | `http://localhost:5176/` |
| 公司端口（company） | `npm run company:dev` | `http://localhost:5177/` |
| Blade 資產 | `npm run dev` | — |

SPA dev server 已用 proxy 轉發 `/api`（可用 `VUE_DEV_API_PROXY` 覆寫），**不需要開 CORS**。

### 4.2 資料庫與多站

| 用途 | 指令 |
| --- | --- |
| 主站 migration | `php artisan migrate --ansi` |
| 開新子站（自動建庫＋migration＋seed） | `php artisan site:create vendor_a "A 客戶站" --type=customized` |
| 同步單一子站的 migration | `php artisan site:migrate 2` |
| 同步所有啟用子站的 migration | `php artisan site:migrate --all` |
| 重跑 baseline seed | `php artisan site:migrate --all --seed=Database\Seeders\SiteBaselineSeeder` |
| 匯出單站部署包 | `php artisan site:package vendor_a --output=D:\deploy\vendor_a` |
| 清理建站失敗留下的資料庫 | `php artisan site:cleanup-failed` |

- `site:migrate` 會自動疊加該站的 `database/sites/{code}/` 專屬 migration。
- seeder **不會自動套用到既有子站**，要手動重跑；重跑會覆蓋後台手改過的同一列資料（例如選單標題）。

### 4.3 腳手架（產生骨架，之後手動補邏輯）

| 用途 | 指令 |
| --- | --- |
| 模塊骨架（公版會互動產生 module.json） | `php artisan module:make order` |
| 掃描 module.json，同步 modules 表與各子站的模組選單 | `php artisan module:sync` |
| 子站客製化 Vue 頁骨架 | `php artisan vue:page:make vendor_a order-dashboard` |
| 插件骨架（要手動登記 `config/plugins.php`） | `php artisan plugin:make ad microsoft v2019` |
| 依現有資料表產生 migration／seed | `php artisan db:table:scaffold users` |

- `module:make` 可加 `--portal=vendor`（指定端口）、`--site=vendor_a`（做成子站客製模塊）。
- `module:sync` 會把 `module.json` 的選單補進每個子站（只補該站已開通模組的選單），並以 `module.json` 為準覆寫模組選單的標題、圖示、排序；後台手動新增的選單不受影響。只想同步主站 modules 表時加 `--no-menus`。
- `db:table:scaffold` 可加 `--only=migration` 或 `--only=seed`。

### 4.4 權限與 API 文件

| 用途 | 指令 |
| --- | --- |
| 同步 API 權限 | `php artisan permission:sync-api --site=1 --assign-admin=admin` |
| 同步 Web 頁面權限 | `php artisan permission:sync-web --site=1 --assign-admin=admin` |
| 稽核越權／跨端口洩漏 | `php artisan permission:audit-ceiling --site=1` |
| 產生 API 文件 | `php artisan swagger:generate --pretty` |

- API 文件產生後在 `/docs`（內部）、`/docs/partner`（第三方）、`/docs/hq`（主站）。
- `@api-*` 標註有缺漏時，`swagger:generate` 與 `permission:sync-api` 會**直接報錯中斷**，訊息會指出是哪支 Controller。
- `@api-portal` 標了資料庫裡沒有的端口，也會報錯中斷（例如「@api-portal 標註了不存在的端口：company」，處理方式見 4.5）。

### 4.5 拉取更新後（git pull 之後）

同事更新了程式碼，你 `git pull` 之後，依序執行下面的步驟。不確定對方改了什麼時，就全部跑一次，重複執行不會有副作用。

**步驟 1：安裝新的套件**（`composer.lock`／`package-lock.json` 有變動時）

```powershell
composer install
npm install
```

**步驟 2：更新資料庫**

```powershell
php artisan migrate
php artisan site:migrate --all
```

**步驟 3：同步模塊、選單與權限**（一定要在步驟 2 之後，否則可能遇到「不存在的端口」錯誤；漏了 `module:sync`，新選單不會出現）

```powershell
php artisan module:sync
php artisan permission:sync-api --site=1 --assign-admin=admin
php artisan permission:sync-web --site=1 --assign-admin=admin
php artisan swagger:generate --pretty
```

**步驟 4：重建前端**

```powershell
php artisan vue:build
npm run hq:build
```

> `vue:build` 已包含子站前台、子站後台（admin）、公司端口（company）；主站後台（hq）要另外建。

**步驟 5：清除快取**

```powershell
php artisan optimize:clear
```

> 對方有改 `SiteBaselineSeeder`（選單分組、設定項、字典、端口角色的權限碼）時，步驟 2 之後再跑：
> ```powershell
> php artisan site:migrate --all --seed=Database\Seeders\SiteBaselineSeeder
> ```

### 4.6 維護、排程與測試

| 用途 | 指令 | 排程 |
| --- | --- | --- |
| 清除設定快取（改 `.env`／config 之後） | `php artisan optimize:clear` | — |
| 清除過期的 JWT 登入紀錄 | `php artisan jwt:sessions:prune` | 每日 03:20 |
| 彙整訪客每日統計 | `php artisan guest:stats-aggregate` | 每日 00:05 |
| 上報儀錶板心跳 | `php artisan dashboard:report-heartbeat` | 每分鐘 |
| 執行測試 | `php artisan test` | — |
| 程式碼格式化 | `vendor/bin/pint` | — |
| 依賴漏洞掃描 | `composer audit`、`npm audit` | — |

- `optimize:clear` 會刪掉 `bootstrap/cache` 的 manifest，清完補跑 `php artisan package:discover`。
- `jwt:sessions:prune` 加 `--dry-run` 只列出、不刪除。
- `guest:stats-aggregate`：把前一日的訪客統計彙整到快取表 `guest_daily_stats`。補歷史資料用 `--from=2026-01-01 --to=2026-01-31`，單日用 `--date=`，可以重跑。
- `dashboard:report-heartbeat`：各子站依「儀錶板上報設定」決定是否上報，可加 `--site=` 只跑一個站。上報走佇列（5 秒逾時、失敗 10 秒後重試、共 3 次失敗就丟棄），**需要 `queue:work` 常駐**，詳見 `docs/DASHBOARD_REPORT.MD`。
- `pint` 加 `--test` 只檢查、不修改。
- `php artisan test` 目前的 ExampleTest 預期會失敗（`/` 會 302 轉到 `/admin`）。
- 生產環境要每分鐘觸發一次 `php artisan schedule:run`，上表的排程才會執行（DEVELOP.MD 18.4）。

---

## 5. 常見開發任務

> 「端口」= `portal`（登入入口，各自有角色與選單，例如 `admin`、`company`），不是 TCP port。
> 以下每項的完整說明在 DEVELOP.MD 20 章。

### 5.1 改完要跑什麼

| 你改了… | 要跑 |
| --- | --- |
| 新增模塊／改 `module.json`（含新增選單） | `php artisan module:sync` |
| 新增或改名 API、改 `@api-*` 註解 | ① `permission:sync-api --site=1 --assign-admin=admin`<br>② `swagger:generate --pretty` |
| 新增或改名機器接口（`factory.scope`，見 5.8） | ① `factory:sync-scopes --site=1`<br>② 到後台授權<br>③ `swagger:generate --pretty` |
| 新增 Web 頁路由（`permission.web`） | `permission:sync-web --site=1 --assign-admin=admin` |
| 公版 migration | `site:migrate --all` |
| `SiteBaselineSeeder`（端口、選單分組、設定項、字典） | `site:migrate --all --seed=Database\Seeders\SiteBaselineSeeder` |
| `.env`／`config/*.php` | `optimize:clear` |
| 子站後台（`vue_src_admin`） | `npm run admin:build`（只建這一套，比較快） |
| 公司端口（`vue_src_company`） | `npm run company:build`（只建這一套，比較快） |
| 子站前台（`vue_src`） | `php artisan vue:build --clean`（會連同子站後台、公司端口一起建） |
| composer／npm 套件 | `composer install`／`npm install`，lock 檔一併提交 |

> 上表的 artisan 指令都要加 `php artisan` 前綴。

### 5.2 新增模塊

**步驟 1：產生骨架**

```powershell
php artisan module:make order
```

**步驟 2：補上登入與權限檢查**。骨架的 `routes/api.php` **只有 `module:order`，沒有登入與權限檢查**，必須補成下面這樣（順序：module → jwt.auth → permission.api）：

```php
Route::middleware(['module:order', 'jwt.auth'])->prefix('orders')->group(function () {
    Route::middleware('permission.api:api.order.index')->get('/', [OrderController::class, 'index']);
    Route::middleware('permission.api:api.order.store')->post('/', [OrderController::class, 'store']);
});
```

**步驟 3：完成其餘設定**

1. 每個 Controller 方法寫 `@api-*` 註解。
2. Model 放模塊的 `Models/`，migration 放 `database/migrations/`，然後跑 `site:migrate --all`。
3. 確認 `module.json`（`is_core`、`depends`、`menus`），然後跑 `module:sync` 與 `permission:sync-api`。
4. 非核心模塊：到主站後台「模塊管理」對要用的子站**開通**（沒開通時 API 回 `20016`、選單不顯示）。
5. 要有畫面的話：加選單與前端頁（見 5.3）。

> ⚠ 權限碼固定是 **`api.{模塊}.{Controller 方法名}`**，路由寫的碼必須與方法名一致，否則一律 403。

### 5.3 新增左側選單

三件事缺一不可。

**① 選單資料**：寫在 `app/Api/{Module}/module.json` 的 `menus`（建議這樣做；在後台「選單管理」手動新增只影響單一站）：

```json
"menus": [{
  "portal": "admin", "key": "orders", "title": "訂單管理",
  "icon": "Tickets",                 // Element Plus 圖示名稱，或 Font Awesome class（fa- 開頭）
  "path": "/orders",                 // 必須等於 ② 的前端路由（含開頭 /）
  "permission_code": "api.order.index",
  "group": "group-system",           // 或 group-account；null = 頂層
  "sort": 10
}]
```

**② 前端路由與頁面**：新增 `resources/vue_src_admin/src/pages/order/OrderList.vue`，並在 `router/index.js` 的 `children`（404 路由之前）加上：

```js
{ path: 'orders', name: 'orders', meta: { title: '訂單管理' }, component: () => import('../pages/order/OrderList.vue') },
```

**③ 套用**：跑 `php artisan module:sync`，選單會補進所有子站（只補該站已開通的模組）。

- 新站：建站時自動帶入。
- 既有站的非核心模塊：主站「開通」時也會自動補上。

**選單沒出現時，依序檢查：**

1. 模塊開通了嗎？
2. 角色有這個模塊的權限嗎？
3. `permission_code` 和權限碼一致嗎？（`path` 與 `permission_code` 都空的葉節點不會顯示）
4. 以上都對，跑 `optimize:clear`。

要新增「分組」節點：改 `SiteBaselineSeeder::seedAdminMenu()` 的 `$groups`（目前只有 admin 端口有分組）。

### 5.4 新增端口（portal）

1. **建立端口資料**：在後台「端口管理」新增（只影響單一站），或寫一支 migration 建立（參考 `2026_11_27_180000_create_guest_company_portal`）。欄位：`portal_key`、`admin_only`、`requires_login`、`is_enabled`（端口不可刪除）。
   > 不要只寫在 seeder：seeder 不會自動套用到既有子站，別人拉了更新後跑 `permission:sync-api` 會報「不存在的端口」。
2. **角色與使用者**：在後台「角色管理」建立該端口的角色；在「使用者管理」把使用者加入該端口，並指派角色或設為端口管理員。
3. **API 與選單**：API 註解的 `@api-portal` 填新端口（或 `*`），再跑 `permission:sync-api`；`module.json` 的 `menus[].portal` 填新端口。
4. **前端（需要畫面才做）**：
   1. 複製 `resources/vue_src_admin` 為 `vue_src_{portal}`。
   2. 改 `stores/auth.js` 的 **`PORTAL_KEY`** 與 token 儲存鍵。
   3. 新增 `vite.{portal}.config.js`（改 `base`、`outDir`、`port`）。
   4. `package.json` 加 `{portal}:dev`、`{portal}:build`。
   5. 新增 SPA 入口控制器，並在 `routes/web.php` 加路由（放在 `Route::fallback` 之前）。
   6. 在各 SPA `stores/auth.js` 的 `SWITCHABLE_PORTALS` 加上新端口，帳號走錯登入入口時才會自動切換（見 5.9）。
   7. `.gitignore` 加上建置輸出目錄。

### 5.5 權限

**權限鏈路**（任一段漏掉都是 403）：

1. Controller 方法寫 `@api-*` 註解。
2. 跑 `permission:sync-api`，寫入 `permissions` 表。
3. 到後台「角色管理」勾選。
4. 路由的 `permission.api:{code}` 比對通過。

**補充：**

- 端口管理員、超級管理員本來就擁有全部權限；其他角色在「角色管理」勾選，支援萬用字元（例如 `api.order.*`）。
- 權限存在各子站資料庫，多站要各跑一次（`--site=N`）。
- 只需要登入、不綁權限的 API：路由只掛 `jwt.auth`。
- 只能授予自己持有的權限（超級管理員、系統管理員除外）。

### 5.6 依賴

**模塊依賴**：寫在 `module.json`，例如 `"depends": ["user", "role"]`。

- 開通模塊時，依賴的模塊會一併開通。
- 要關閉被依賴的模塊，必須先關閉依賴它的模塊。
- 核心模塊不要依賴非核心模塊。
- 改完跑 `module:sync`。
- 匯出單站部署包時會檢查依賴：依賴的模塊沒有開通，就不能匯出。

**套件依賴**：

- 用 `composer require`、`npm install` 安裝，lock 檔一併提交（單站部署包靠 lock 檔還原）。
- 提交前跑 `composer audit`、`npm audit`。

### 5.7 其他常見調整

| 要調整 | 位置 | 備註 |
| --- | --- | --- |
| dev server 埠號 | 各 `vite.*.config.js` 的 `server.port`（見 4.1） | 改 serve 埠時同步 `VUE_DEV_API_PROXY`、`APP_URL`；不用改 CORS／CSP |
| 系統設定項／字典 | `SiteBaselineSeeder` 的 `seedSiteBasicConfig()`、字典 seed | 只開放改值；新增項目要改 seeder 後重跑 |
| 初始帳號 | `config/initial_accounts.php` ＋ `.env` | 密碼沒填 seeder 會中斷 |
| `.env`／config | `.env.example` ＋ `config/*.php`（`env()` 只能寫在 config 內） | 新增必填項，同步更新 `resources/deploy/DEPLOY.md` |
| 錯誤碼 | `ErrorCode.php` ＋ `ErrorCatalog.php`（`STATUS`、`MESSAGE_KEYS`）＋ `lang/zh_TW/api.php` | 漏登記會回 500「未知錯誤」 |
| 插件 | ① `plugin:make`<br>② 登記 `config/plugins.php`（key 為 `{brand}_{version}`）<br>③ 到後台「插件管理」啟用 | — |

### 5.8 開放廠商模式（App Token）給機器／第三方呼叫

機器（訪客機等）沒有使用者帳號，改用 **App 身分**呼叫 `/api/factory/*`，與使用者登入並存、互不影響。完整指南見 `docs/APP_DEVELOP.MD`，設計摘要見 DEVELOP.MD 12.9。

**分工**

| 層 | 放什麼 | 位置 |
| --- | --- | --- |
| 框架核心 | 簽名驗證、JWT、scope 比對、`factory.auth`／`factory.scope` middleware、`config/factory.php` | `app/Support/Factory/` |
| 核心模塊 `factory` | **只放管理功能**：換 token（`POST /api/factory/token`）、App／授權／記錄的後台管理（`/api/system/factory/*`）。核心模塊恆為開通 | `app/Api/Factory/` |
| **各業務模塊** | **自己的**機器接口（具體業務操作） | `app/Api/{Module}/Controllers/Factory{Xxx}Controller.php` |

> ⚠ **不要把任何業務模塊的接口放進 `app/Api/Factory`**。業務接口屬於擁有該業務的模塊（可整包移除、模塊沒開通就被擋）；`Factory` 模塊只管「誰（`app_id`）能不能呼叫」。

**必守規則**（已有測試把關：`tests/Feature/Factory/FactoryAuthTest.php`）

| 項目 | 規則 |
| --- | --- |
| 位置 | `app/Api/{模塊}/Controllers/` **根層**，不可放子資料夾（`permission:sync-api`、`swagger:generate` 只掃根層，放子資料夾會被漏掉） |
| 類別名稱／檔名 | **`Factory{Xxx}Controller`**（`Xxx` 用大駝峰描述業務，例如 `FactoryGuestApplyController`） |
| `Factory` 前綴保留 | 只能用在路徑為 `/api/factory/*` 的 Controller，且該 Controller 只放 `/api/factory/*` 的接口；後台管理（`/api/system/factory/*`）與一般接口**不可**用此前綴；端口也不要取名 `factory`（會與 DEVELOP.MD 9.1.1 的 `{PortalKey}{Feature}Controller` 混淆） |
| 路徑 | `/api/factory/{模塊代碼}/...`（`Route::prefix('factory/{模塊}')`） |
| 路由 middleware | `['module:{code}', 'factory.auth']`，單支再掛 `factory.scope:{模塊}.{資源}.{動作}`；**不可**掛 `jwt.auth`／`permission.api` |
| 註解 | `@api-portal *`、`@api-auth app`、`@api-scope {scope}`（必須與路由的 `factory.scope:` 完全一致）；不需認證的（換 token）標 `@api-auth public` |
| 權限 | scope **不進** `permissions` 表（`permission:sync-api` 會略過標了 `@api-auth` 的接口）；改跑 `factory:sync-scopes` 登記，再到後台「廠商 App 管理 → 授權」勾選 |
| Swagger | `/api/factory/*` 放在第三方文件 `/docs/partner`，不在內部文件 `/docs`（DEVELOP.MD 12.8） |
| 一般接口誤用 | 拿 App Token 呼叫 `/api/factory/*` 以外的接口，一律 403 |

**新增一支機器接口**

1. 在**自己的模塊**寫 `Factory{Xxx}Controller`（業務邏輯沿用既有 Service，不要複製），只回傳機器需要的欄位。
2. 路由放在自己模塊的 `routes/api.php`，照上表掛 middleware；每個方法寫 `@api-*` 註解。
3. 跑 `php artisan factory:sync-scopes --site=1`。
4. 到後台授權。
5. 跑 `php artisan swagger:generate --pretty`。
6. 補測試（照 `FactoryAuthTest`：缺 token＝401、缺 scope＝403、查無資料＝404）。

範例：`app/Api/Guest/Controllers/FactoryGuestApplyController.php`，以及 `app/Api/Guest/routes/api.php` 最下方的 `factory/guest` 群組。

**補充**

- 機器接口**不限於查詢**：報到、離場、存放證件位置等寫入操作同樣支援。風險靠逐一授權 scope（最小授權）與 Service 層的業務檢查控制，見 `docs/APP_DEVELOP.MD` 5 節「寫入類接口」。
- **排查機器問題**：後台「廠商 App 管理」每個 App 都有「除錯模式」開關。
  - 關閉時：只記錄非 2xx 的呼叫。
  - 開啟時：成功、失敗都記錄（含完整請求與回應，敏感值遮罩）。資料量大，查完請關閉。
  - 到「廠商 App 記錄」可依 App ID、業務模塊、方法、結果、時間搜尋。
  - 記錄保留 30 天，由每日排程 `factory:logs-prune` 自動移除（需要 `schedule:run` 每分鐘執行）。詳見 `docs/APP_DEVELOP.MD` 7.5。

### 5.9 訪客模組：公司端口（company）

**概念**

- 一個門牌＝一家公司（公司名稱在後台「門牌設定」維護）。
- 後台「訪客管理 › 公司帳號管理」替公司建立**公司管理員**帳號。
- 公司管理員登入 `/company`，自行管理員工、指定審核者、設定審核開關。
- 前端原始碼：`resources/vue_src_company`。

**公司內身分**

| 身分（`member_type`） | 對應的 company 端口角色 | 可以做的事 |
| --- | --- | --- |
| 1 公司管理員 | 公司管理員 | 員工管理、審核設定、審核、預約 |
| 2 審核者 | 審核者 | 審核、預約 |
| 3 一般員工 | 一般員工 | 登記與管理預約、查看訪客紀錄 |

**審核規則**

- 公司的審核開關（`doorplates.approval_required`）**只影響員工從公司端口新增或修改的預約**：
  - 需要審核：新增後為「待審核」（0），修改後為「修改待審核」（1）。
  - 免審核：新增後直接「已核准」（10），修改後直接「修改已核准」（11）。
- 其他來源的訪客申請（後台建立等）**一律待審核**，不受開關影響，由受訪公司的公司管理員、審核者或後台審核。
- 公司端口只能核准（1）或拒絕（70）待審核的預約。

**資料隔離**

- 預約、審核、紀錄 API（`/api/guest/applies`、`/approvals`、`/records`）由兩個端口共用。
- 登入 company 端口時，`EnsureGuestCompanyScope` 依成員紀錄套上公司範圍，`GuestApply` 的全域範圍會自動加上 `doorplate_id` 條件；admin 端口看全部。
- ⚠ 新增訪客 API 時不用自己加公司條件，但**不能用 `DB::table('guest_applies')` 或 `withoutGlobalScope` 繞過 Model**，否則公司端口會看到別家公司的資料。

**端口與角色**

- `company` 端口與三個角色由 migration `2026_11_27_180000_create_guest_company_portal` 建立，跑 `site:migrate` 就會補上，已存在的不會被覆寫。
- 角色的權限碼定義在 `GuestCompanyMember::rolePermissionCodes()`；要調整就改這裡，再重跑 baseline seed 覆寫既有角色。
- ⚠ **不要在後台改這三個角色的名稱或權限**：帳號是依角色名稱指派的。

**登入入口相容**

- 帳號密碼正確、但走錯登入入口時，`/api/auth/login` 回傳錯誤碼 `20018`（PORTAL_MISMATCH），並在 `data.portal_keys` 附上這個帳號可以登入的端口。
- 後台登入頁遇到公司帳號，會自動改登入公司端口並跳到 `/company`；反過來也一樣。
- 帳號或密碼錯誤時，訊息維持「帳號、密碼或端口錯誤」，不透露帳號屬於哪個端口。

---

## 6. 前端（Vue3）：共用、客製化與部署

### 6.1 五套前端，各自獨立建置

| 前端 | 原始碼 | 開發 | 建置 | 輸出（不進版控） |
| --- | --- | --- | --- | --- |
| 子站前台（可客製化） | `resources/vue_src` | `npm run vue:dev` | `php artisan vue:build` | `public/vue`、`public/vue-sites/{code}` |
| 子站後台（admin，全站共用） | `resources/vue_src_admin` | `npm run admin:dev` | `npm run admin:build` | `public/admin-assets` |
| 公司端口（company） | `resources/vue_src_company` | `npm run company:dev` | `npm run company:build` | `public/company-assets` |
| 主站後台（hq） | `resources/vue_src_hq` | `npm run hq:dev` | `npm run hq:build` | `public/hq-assets` |
| Blade 資產 | `resources/css`、`resources/js` | `npm run dev` | `npm run build` | `public/build` |

- 改了哪套就重建哪套；新環境與每次部署都要重建。
- `php artisan vue:build`（含 `--site=`、`--all-sites`）**預設會一併建置子站後台（admin）與公司端口（company）**：
  - 不需要建置後台時加 `--no-admin`。
  - 不需要建置公司端口時加 `--no-company`。
  - 所以 `php artisan vue:build --site=default` 可以取代 `npm run admin:build` ＋ `npm run company:build`（另外還會建置前台）。
- 只跑 `npm run vue:build` **只會建置前台**，後台與公司端口的改動不會生效；要一次建齊請用 `php artisan vue:build`。

### 6.2 子站前台：共用還是獨立

每個子站的前台只有兩種情況，由該站的 `vue_build_mode` 決定：

| 情況 | 什麼時候用 | `vue_build_mode` | 建置指令 | 網站讀取哪一份 |
| --- | --- | --- | --- | --- |
| **共用前台**（預設） | 跟公版一樣，或只多幾個自己的頁面 | `shared` | `vue:build` | `public/vue`（所有共用站同一份） |
| **獨立前台** | 整組頁面都不一樣，或要拿去客戶現場單獨部署 | `dedicated` | `vue:build --site={code}` | `public/vue-sites/{code}`（這個站自己一份） |

絕大多數站都是共用前台。改了共用前台，所有共用站會同時生效；獨立前台的站要另外重建才會更新。

| 用途 | 指令 |
| --- | --- |
| 幫 vendor_a 新增專屬頁面 | `php artisan vue:page:make vendor_a order-dashboard` |
| 建置共用前台＋子站後台＋公司端口 | `php artisan vue:build --clean` |
| 建置 vendor_a 的獨立前台＋子站後台＋公司端口 | `php artisan vue:build --site=vendor_a --clean` |
| 建置共用前台＋所有獨立前台＋子站後台＋公司端口（CI 用） | `php artisan vue:build --all-sites` |

- `vue:page:make` 產生的檔案在 `site-overrides/vendor_a/pages/OrderDashboard.vue`。
- `--site=vendor_a` 輸出到 `public/vue-sites/vendor_a`；如果該站其實是共用前台，會自動改建 `public/vue`。

**客製化原則**

- 客製化只放在 `resources/vue_src/src/site-overrides/{site_code}/`；**不要在共用頁面寫 `if (siteCode === ...)`**。
- 要把某站改成獨立前台：該站的 `vue_build_mode` 改為 `dedicated`，主站 `sites` 表和 `storage/app/sites/{id}.php` 兩處都要改，否則網站仍然讀共用的 `public/vue`。
- 只改樣式、文案、Logo、主題色 → 用後台「系統設定」，不需要客製化。
- 後台（admin）全站共用：客戶差異靠**模塊開通、選單、角色權限、系統設定**；要讓某客戶多一個後台頁面，就做成模塊只對他開通。
- Blade 覆寫：同名檔放在 `resources/views/sites/{code}/{module}/`。
- 後端客製：`app/Sites/{SiteCode}/`。
- 專屬 migration：`database/sites/{code}/migrations`（`site:migrate` 會自動疊加）。

> ⚠ **尚未實作**（做某站前台客製化前，先補實作並寫回文件，詳見 DEVELOP.MD 21.2、21.3）：
> - 共用前台站的「專屬頁面動態掛載」：`custom_routes` 目前固定回空陣列，頁面做了也掛不上去。
> - 獨立前台的「公版＋該站覆寫檔合併後建置」：`--site` 目前只換輸出目錄，內容其實還是公版。
> - `vue:build --output=` 已實作（單站部署包用）。

### 6.3 部署（多站／SaaS）

依序執行：

**步驟 1：安裝後端依賴**

```powershell
composer install --no-dev --optimize-autoloader
```

**步驟 2：更新資料庫**

```powershell
php artisan migrate --force
php artisan site:migrate --all
```

**步驟 3：建置前端**

```powershell
npm ci
npm run hq:build
php artisan vue:build --all-sites
```

> `vue:build --all-sites` 會建置共用前台、所有獨立前台站、子站後台（admin）與公司端口（company）。有用到 `@vite` 的 Blade 頁，另外再跑 `npm run build`。

**步驟 4：建立快取**

```powershell
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

**部署注意事項**

- 只對外開放 `public/`。
- 共用前台的改動會讓**所有 shared 站同時生效**；dedicated 站要重新 `vue:build --site=` 才會更新。
- 單站部署包（`site:package`）只含該站客製化，不含 `vendor/`、`node_modules/`：現場要 `composer install`、`npm ci` 並重建前端（DEVELOP.MD 6.8、21.5）。
- 排程：生產環境每分鐘觸發一次 `php artisan schedule:run`（DEVELOP.MD 18.4）。

**驗收**

- `/hq`、`/admin`、`/company`、`/{code}/admin`、前台都不是 404。
- 瀏覽器主控台沒有 CSP violation。
- 用路徑前綴進站登入一次，確認 API 打到正確的站。
- 獨立的 dev server 看不到 CSP 違規，**上線前必須建置後走 Laravel 路由驗證**（DEVELOP.MD 4.2.1）。

---

## 7. 初始帳號與 API 測試

登入一律用**帳號＋密碼＋端口（`portal_key`）**，不用 email。

| 端口 | 網址 | 誰登入 |
| --- | --- | --- |
| `admin` | `/admin` | 子站後台管理人員 |
| `company` | `/company` | 訪客模組各公司的帳號（見 5.9） |

**初始帳號**

| 角色 | 帳號（預設） | 密碼來源 | 所在資料庫 |
| --- | --- | --- | --- |
| 主站管理員 | `admin`（`HQ_ADMIN_USERNAME`） | `.env` 的 `HQ_ADMIN_PASSWORD` | 主站 |
| 子站超級管理員（我方） | `admin`（`SITE_SUPER_ADMIN_USERNAME`） | `.env` 的 `SITE_SUPER_ADMIN_PASSWORD` | 各子站 |
| 子站系統管理員（交付客戶） | **站代碼**（`SITE_DELEGATION_ROOT_USERNAME`） | `.env` 的 `SITE_DELEGATION_ROOT_PASSWORD` | 各子站 |

> 同一個帳號在同一個端口只能有一個登入；在別的地方再登入一次，前一個登入會失效（錯誤碼 `20015`）。

**用 PowerShell 測試 API**

```powershell
$body = @{ username = 'admin'; password = '密碼'; portal_key = 'admin' } | ConvertTo-Json
$resp = Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/auth/login" -Method Post -ContentType "application/json" -Body $body
$token = $resp.data.access_token
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/user/profile" -Headers @{ Authorization = "Bearer $token" }
```

**回應格式**

- 成功：`{"status":true,"error_code":0,"data":{}}`
- 失敗：`{"status":false,"error_code":10001,"error_message":"..."}`

**錯誤碼**

| 範圍／錯誤碼 | 意思 |
| --- | --- |
| `1xxxx` | 參數／驗證 |
| `2xxxx` | 認證／授權 |
| `20015` | Token 被新登入取代 |
| `20016` | 模塊未開通 |
| `20017` | 端口僅限端口管理員登入 |
| `20018` | 帳號不屬於這個端口（附 `data.portal_keys`，見 5.9） |
| `3xxxx` | 業務 |
| `5xxxx` | 系統 |

---

## 8. 常見問題

| 症狀 | 處理 |
| --- | --- |
| 指令失敗 | 確認 `php -v` 是 8.2.29、`.env` 的 `DB_*` 正確、MySQL 已啟動 |
| `/hq`、`/admin`、`/company` 404 | SPA 沒建置：`/hq` 跑 `npm run hq:build`；`/admin`、`/company` 跑 `php artisan vue:build`（或單獨 `npm run admin:build`／`company:build`） |
| 登入後每支 API 都失敗、`Key material must not be empty` | JWT 密鑰沒設：跑 `php artisan jwt:secret`（機器接口另需 `FACTORY_JWT_SECRET`）；log 在 `storage/logs/laravel.log` |
| 首頁正常、一登入就 500 | 多半是 `site_current` 連線問題，或誤用 `env()`（DEVELOP.MD 6.4） |
| 畫面空白、主控台有 CSP violation | 嚴格 CSP 的預期行為；常見原因是 `:style` 動態綁定（DEVELOP.MD 4.2.1） |
| 改設定沒生效 | `php artisan optimize:clear` |
| API 一律 403 | 權限碼與方法名不一致、沒跑 `permission:sync-api`、角色沒勾選，或模塊未開通（見 5.5） |
| 選單沒出現 | 先跑 `php artisan module:sync`（同步選單到子站），再見 5.3「選單沒出現時，依序檢查」 |
| `permission:sync-api` 報「@api-portal 標註了不存在的端口」 | 資料庫還沒有該端口：先跑 `site:migrate --all`，再重跑同步（見 4.5） |
| 一直被登出、提示「登入已失效」 | 同一個帳號在別處登入了同一個端口（見第 7 節），換個帳號測試 |
| 機器接口換 token 一直 401／500 | 到後台「廠商 App 記錄」看失敗原因；500 多半是 `.env` 沒設 `FACTORY_JWT_SECRET`（至少 32 字元），見 `docs/APP_DEVELOP.MD` 7.4 |
| 機器接口回 403 | 該 `app_id` 沒被授權對應的 scope（到後台授權）；或拿 App Token 呼叫了 `/api/factory/*` 以外的接口 |

**文件與程式的已知落差**（照 DEVELOP.MD 做卻失敗時先看；完整表見 DEVELOP.MD 22.1）

- `composer run swagger:generate` 不存在 → 用 `php artisan swagger:generate --pretty`。
- 建站 API 實際是 `POST /api/hq/sites`；`site:create` 沒有 `--clone-from`（只有 `--type`）。
- 廠商模式（App Token）已實作「換 token ＋ scope 授權」；**尚未實作**「app token 換 user token」（`/api/factory/auth/login`，DEVELOP.MD 18.3 第 2 步）；初始帳號以第 7 節為準。
- 其餘目前狀態與未完成項目（前台是骨架頁、檔案管理暫緩、只有 `admin` 端口有跳轉登入落地頁）見 DEVELOP.MD 22.1。
