# base｜Laravel 多站開發框架（開發者手冊）

本專案是「基礎開發框架」，不承載具體業務，內含基礎管理 API（Auth／Role／User／Menu／Portal／SystemConfig／Dictionary／Plugin／Site）。
架構為 **主站（HQ）+ 子站（site）** 的多租戶模型，每個站點各自擁有獨立資料庫。

## 文件分工（先看這裡）

| 文件 | 定位 | 內容 |
| --- | --- | --- |
| **README.MD（本檔）** | 開發者手冊 | 只放「馬上要能跑」的事：環境、命令、範例、速查表 |
| **DEVELOP.MD** | 設計與決策全文 | 完整設計理由、架構決策、實測紀錄、踩坑與修正、待辦事項 |

> 需要「為什麼這樣設計」「當初踩過什麼坑」「某欄位完整定義」→ 查 DEVELOP.MD（本檔各節會標註對應章節編號）。
> 只想「把環境跑起來」「某個指令怎麼下」→ 看本檔即可。

---

## 1. 目前狀態

### 1.1 已完成且可運作

| 功能 | 入口／位置 | DEVELOP.MD |
| --- | --- | --- |
| 多站架構（動態 DB 連線、網域／路徑前綴進站） | `EnsureSiteContext`、`SiteResolver`、`storage/app/sites/{id}.php` | 6.1、6.4 |
| 自動建站／單站部署包匯出 | `site:create`、`site:package`、`site:cleanup-failed` | 6.3、6.8 |
| 身份別／端口／角色三層權限（可設定，非寫死） | `identity_types`／`portals`／`roles`／`user_portal_access` | 7.1、7.2 |
| 角色權限指派（端口→模塊→接口三層勾選樹） | 後台 `RoleList.vue`、`GET /api/roles/permission-catalog` | 7.5 |
| 選單管理（取代寫死 config）+ 模塊開通連動 | `MenuService`、`menus.module_code` | 7.3、6.9 |
| 模塊授權（依模塊購買、子站個別開通） | `module.json` 掃描、`site_modules` | 6.9 |
| 插件系統（AD／SSO／設備對接） | `PluginManager`、`app/Plugins`、後台插件管理頁 | 6.10 |
| SSO 對接（driver 可替換 + Blade 落地頁） | `GET /sso/callback`、`config/sso.php` | 8.6 |
| 主站跳轉登入子站（一次性交換碼 + Blade 落地頁） | `POST /api/hq/sites/{id}/impersonate-token`、`GET /hq-impersonate/callback` | 6.11 |
| 前台 Vue3 三層客製化（Tier 1/2/3 可升降級） | `resources/vue_src`、`vue:build` | 8.1～8.4 |
| 三套獨立後台／前台 SPA | 子站後台 `admin`、主站後台 `hq` | 6.12、9.1 |
| System Config（17 種型別 + 圖片上傳裁切） | `GET/PUT /api/system-configs`、`/image` 端點 | 17.2、17.5 |
| 資料字典模組 | `GET/POST/PUT/DELETE /api/dictionaries` | 17.3 |
| JWT 單帳號單 Token（限制範疇為單一端口）+ 會話清理排程 | `user_jwt_sessions`、`jwt:sessions:prune` | 11.1 |
| 統一錯誤碼與 Exception → JSON | `ErrorCode`／`ErrorCatalog`／`bootstrap/app.php` | 11.2 |
| 審計日誌（登入、權限變更、資料刪改） | `audit_logs`（各站自己一份） | 15 |
| Swagger 文件（內部／廠商／主站） | `/docs`、`/docs/partner`、`/docs/hq` | 12 |
| 嚴格 CSP／安全標頭（開發與正式一致） | `SecurityHeaders` middleware | 4.2 |

### 1.2 尚未完成或刻意暫緩

1. **前台目前是骨架頁**：裸網域根目錄（`/`、`/{code}`）會 302 導向管理後台（DEVELOP.MD 8.5）。
2. **檔案管理／通用圖片模組暫緩**：`app/Api/File`、`managed_files` 已從程式庫移除，設計保留在 DEVELOP.MD 10 節，需要時照設計重建。
3. **初始資料極簡**：`portals` 目前只有 `admin`；`identity_types` 只有 `super_admin`／`system_admin`／`staff`（DEVELOP.MD 19）。
4. **跳轉登入只有 `admin` 端口有落地頁**：其他 `portal_key` 尚無對應 SPA，目前導向 `/{portal_key}`，待該端口前端建置後接手（DEVELOP.MD 6.11、9.1）。

### 1.3 ⚠ 文件與程式現況落差（照 DEVELOP.MD 做卻失敗時，先看這張表）

| DEVELOP.MD 寫法 | 實際現況 | 正確做法 |
| --- | --- | --- |
| `composer run swagger:generate`（12.7／18.2-6） | `composer.json` **沒有**這個 script | 用 `php artisan swagger:generate --pretty` |
| `POST /api/site/sites`（6.3） | 實際路由是 `POST /api/hq/sites` | 見 `php artisan route:list`（`app/Hq/Site`） |
| `site:create ... --clone-from=`（6.3） | 指令**沒有**這個選項，只有 `--type` | 想複製既有站客製化內容，目前需人工複製目錄後再建站 |
| 廠商模式 `/api/factory/*`（8.6、12.8、18.3） | **路由不存在**，屬規劃未實作 | 目前不要照該範例測試 |
| 初始帳號 `hq_admin`／`super_admin`（18.3） | 預設是 `admin`；子站系統管理員預設為**站代碼** | 見本檔第 8 節 |

---

## 2. 版本基線（凍結，勿任意變更）

| 元件 | 基線 | 說明 |
| --- | --- | --- |
| PHP | 8.2.29 | 本機路徑 `D:\wamp64\bin\php\php8.2.29`（禁 7.x／8.0／8.1） |
| Laravel | 12.x | 以 `composer.lock` 鎖定（本機實測 12.68.0） |
| MySQL | 8.4.7 | 語法與行為以 8.4 驗證；字元集 `utf8mb4`／`utf8mb4_unicode_ci` |
| Redis | 6.2.x | 快取／佇列相容基線 |
| Node | 與 `package-lock.json` 相符 | 前端建置（Vite 7 + Vue 3.5 + Element Plus） |

基線變更時必須同步：本表、`composer.json` 相依條件、CI／部署腳本版本（DEVELOP.MD 2.4）。

---

## 3. 首次建置（新環境只做一次）

### 3.1 設定 PowerShell（每次開新終端機建議都做）

```powershell
[Console]::InputEncoding  = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
chcp 65001 > $null

# 指定 PHP 8.2（避免誤用系統其他版本）
$env:Path = "D:\wamp64\bin\php\php8.2.29;" + $env:Path
php -v
```

預期輸出（實測）：

```text
PHP 8.2.29 (cli) (built: Jul  1 2025 20:21:12) (ZTS Visual C++ 2019 x64)
Zend Engine v4.2.29, Copyright (c) Zend Technologies
    with Zend OPcache v8.2.29, Copyright (c), by Zend Technologies
    with Xdebug v3.4.7, Copyright (c) 2002-2025, by Derick Rethans
```

### 3.2 安裝依賴

```powershell
composer install
npm install
```

### 3.3 建立 `.env` 並填入必填值

複製 `.env.example` 為 `.env` 後，確認下列項目（**密碼沒填會讓 seeder 直接拋例外中斷**）：

```dotenv
APP_URL=http://127.0.0.1:8000
APP_LOCALE=zh_TW

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=base_hq            # 這個資料庫是「主站」自己的
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

MULTI_SITE_ENABLED=true        # 開發／SaaS 一律 true；false 只給單站匯出包用
SITE_DB_PREFIX=base_site_      # 子站資料庫 = 前綴 + 站代碼

HQ_ADMIN_PASSWORD=             # 主站管理員密碼（必填）
SITE_SUPER_ADMIN_PASSWORD=     # 子站超級管理員密碼（必填）
SITE_DELEGATION_ROOT_PASSWORD= # 子站系統管理員密碼（必填）
```

> 帳號名稱可選填覆寫：`HQ_ADMIN_USERNAME`、`SITE_SUPER_ADMIN_USERNAME`、`SITE_DELEGATION_ROOT_USERNAME`。

### 3.4 產生 APP_KEY 與 JWT 密鑰

```powershell
php artisan key:generate --ansi
```

### 3.5 建置資料庫（順序不可顛倒）

主站與子站是**兩層各自獨立的資料庫**：

```powershell
# 第一步：主站（HQ）資料庫結構 + 初始帳號 + 模組清單
php artisan migrate:fresh --path=database/hq/migrations --seed --seeder=Database\Hq\Seeders\HqDatabaseSeeder --ansi

# 第二步：開 1 號子站（公版預設開發站），會自動建庫、跑子站 migration 與 baseline seed
php artisan site:create default 預設開發站 --type=standard
```

### 3.6 驗證安裝

```powershell
php artisan --version           # 預期：Laravel Framework 12.68.0
composer check-platform-reqs    # 預期：全部 pass
php artisan route:list --ansi   # 預期：列出 80+ 筆路由（含 api/ping、api/auth/login）
php artisan migrate:status --path=database/hq/migrations --ansi
```

也可用 `php artisan list` 確認自訂指令都在（`site:`、`module:`、`plugin:`、`vue:`、`permission:`、`swagger:`、`jwt:sessions:prune`）。

### 3.7 絕對不要這樣下（常見誤用）

| ❌ 不要 | 原因 | ✅ 改用 |
| --- | --- | --- |
| `php artisan migrate`（不帶 `--path`） | 預設路徑 `database/migrations` 是**子站**表結構，會把子站表建到主站資料庫 | 主站加 `--path=database/hq/migrations`；子站用 `site:migrate` |
| `php artisan db:seed`（裸執行） | `SiteBaselineSeeder` 依賴的 `site_current` 連線只有 `site:migrate`／`site:create` 會設好 | `php artisan site:migrate 1 --seed=Database\Seeders\SiteBaselineSeeder --ansi` |
| 執行期呼叫 `env('DB_...')` | `config:cache` 後 `env()` 一律回 `null` → `Access denied for user ''@'localhost'` | 走 `App\Support\Site\SiteDatabaseConfig`（讀 config） |

---
## 4. 日常開發常用命令（本節是重點）

以下皆為 **Windows PowerShell** 語法。凡指令說明提到「預期」，皆為實際執行過的結果或明確判定條件。

### 4.1 啟動本機服務

```powershell
# 後端（Laravel + API + Blade 頁面）
php artisan serve --host=127.0.0.1 --port=8000
# 預期：INFO  Server running on [http://127.0.0.1:8000]

# 一鍵同時開 serve + queue:listen + pail(log) + vite(Blade 資產)
composer run dev
```

| 服務 | 指令 | 預設網址 |
| --- | --- | --- |
| 後端 API／Blade | `php artisan serve` | `http://127.0.0.1:8000` |
| 前台 SPA（子站） | `npm run vue:dev` | `http://localhost:5173/vue/` |
| 主站後台 SPA | `npm run hq:dev` | `http://localhost:5175/` |
| 子站後台 SPA | `npm run admin:dev` | `http://localhost:5176/` |
| Blade 資產（HMR） | `npm run dev` | 由 Laravel 頁面自動帶入 |

> 三個 SPA 的 dev server 都設了 `/api` proxy（預設轉發到 `http://127.0.0.1:8000`，可用 `VUE_DEV_API_PROXY` 覆寫），因此**不需要為了前端開發開 CORS**。開發 SPA 時瀏覽器直接開 Vite 印出的網址，不經過 `php artisan serve`。

### 4.2 前端建置對照表（共四套設定檔，別搞混）

| 用途 | 原始碼 | 開發 | 建置指令 | 輸出目錄 |
| --- | --- | --- | --- | --- |
| Blade 資產（`@vite`） | `resources/css`、`resources/js` | `npm run dev` | `npm run build` | `public/build` |
| 子站前台 SPA | `resources/vue_src` | `npm run vue:dev` | `npm run vue:build` 或 `php artisan vue:build` | `public/vue` |
| 子站前台（Tier 3 獨立建置） | 同上 + `site-overrides/{code}` | — | `php artisan vue:build --site={code} --clean` | `public/vue-sites/{code}` |
| 主站後台 SPA | `resources/vue_src_hq` | `npm run hq:dev` | `npm run hq:build` | `public/hq-assets` |
| 子站後台 SPA | `resources/vue_src_admin` | `npm run admin:dev` | `npm run admin:build` | `public/admin-assets` |

**`npm run vue:build` 與 `php artisan vue:build` 的差別**：前者只是 Vite 建置，後者會依參數決定輸出路徑與 base：

```powershell
# 共用建置（Tier 1/2：公版站與輕度客製站共用）
php artisan vue:build --clean

# 指定站點獨立建置（Tier 3；輸出到 public/vue-sites/{site_code}）
php artisan vue:build --site=vendor_a --clean

# 重新建置所有 Tier 3 站點（CI/CD 用）
php artisan vue:build --all-sites
```

### 4.3 資料庫與遷移

```powershell
# 主站（HQ）遷移
php artisan migrate --path=database/hq/migrations --ansi

# 重跑主站管理員 seed（沿用 .env 的 HQ_ADMIN_PASSWORD）
php artisan db:seed --class=Database\Hq\Seeders\HqDatabaseSeeder --ansi

# 重跑某子站 baseline seed（不重建表，只重跑模塊／身份別／端口／初始帳號）
php artisan site:migrate 1 --seed=Database\Seeders\SiteBaselineSeeder --ansi

# 子站 Schema 同步（公版路徑固定套用；客製化站點專屬 migration 自動偵測）
php artisan site:migrate 2                 # 單一站點
php artisan site:migrate --all             # 所有 is_enabled=true 站點（可加 --seed=）
php artisan site:migrate 2 --path=database/sites/vendor_a/migrations   # 不照慣例路徑時才需指定
```

### 4.4 權限同步與稽核

```powershell
# 同步 API 權限（模組級 + 接口級）並併入指定帳號角色；--site 必填
php artisan permission:sync-api --site=1 --assign-admin=admin

# 同步 Web 權限模板（entry/view/create/update/delete）
php artisan permission:sync-web --site=1 --assign-admin=admin

# 連未掛 permission.web 的路由也同步（不建議常態使用）
php artisan permission:sync-web --site=1 --include-unprotected --assign-admin=admin

# 稽核角色權限碼是否越權／跨端口洩漏
php artisan permission:audit-ceiling --site=1
```

> `swagger:generate` 與 `permission:sync-api` 共用同一套標註驗證器，標註缺漏會**直接報錯中斷**（不是警告），錯誤訊息會指出是哪支 Controller 的哪個標註（DEVELOP.MD 12.6）。

### 4.5 API 文件（Swagger）

```powershell
php artisan swagger:generate --pretty    # 掃描 app/Api 的 @api-* 註解
```

| 文件 | 網址 |
| --- | --- |
| Swagger UI（內部） | `http://127.0.0.1:8000/docs` |
| Swagger UI（廠商） | `http://127.0.0.1:8000/docs/partner` |
| Swagger UI（主站） | `http://127.0.0.1:8000/docs/hq` |
| OpenAPI JSON | `/docs/openapi.json`、`/docs/openapi.partner.json`、`/docs/openapi.hq.json` |

輸出檔案落在 `storage/app/private/docs/`；`swagger-ui-dist` 靜態資源複製到 `public/vendor/swagger-ui`（需先 `npm install`）。
修改 Controller 註解的標準流程：改註解 → `swagger:generate` → 重新整理文件頁。

### 4.6 腳手架指令（產生骨架，之後手動補邏輯）

```powershell
# 依現有資料表產生 migration + seed（表名固定加 _scaffold、enum 需人工補選項）
php artisan db:table:scaffold users
php artisan db:table:scaffold users --only=migration
php artisan db:table:scaffold users --only=seed --chunk=500 --force
# 掃主站表（sites／modules）要帶：--connection=mysql --output-path=database/hq/migrations

# 模組骨架（公版會互動問答建立 module.json；--site 為子站客製化，不問答、不登記 modules 表）
php artisan module:make order
php artisan module:make order --portal=vendor
php artisan module:make order --site=vendor_a

# 同步模組清單（掃 app/Api/*/module.json → 主站 modules 表）
php artisan module:sync

# 子站客製化 Vue 頁面骨架
php artisan vue:page:make vendor_a order-dashboard

# 插件骨架（功能／品牌／版本）
php artisan plugin:make ad microsoft v2019
php artisan plugin:make sso okta v1
```

> 產生／修改公版 migration 後，記得跑 `php artisan site:migrate --all`，否則只停在檔案系統，任何站點的資料庫都不會變更。

### 4.7 多站維運

```powershell
# 開新子站（目前只有 --type 一個選項；--type=customized 表示該站有客製化覆寫）
php artisan site:create vendor_a "A 客戶站" --type=customized
php artisan site:create default "預設開發站" --type=standard

# 匯出單站部署包（給客戶機房獨立部署；含可用 .env、storage 骨架與 DEPLOY.md）
php artisan site:package vendor_a --output=D:\deploy\vendor_a

# 清理建站失敗留下的孤兒資料庫與設定檔（人工確認後才執行）
php artisan site:cleanup-failed
php artisan site:cleanup-failed 5
```

> 匯出包刻意**不含** `vendor/`、`node_modules/`、`bootstrap/cache/*`；現場依 lock 檔 `composer install`／`npm ci` 還原（DEVELOP.MD 6.8 有實測踩坑紀錄）。

### 4.8 維護與排程

```powershell
php artisan optimize:clear            # 設定／路由／檢視快取全清（改 .env 或 config 後執行）
php artisan jwt:sessions:prune        # 清理過期 JWT 會話（預設每日 03:20 由排程執行）
php artisan jwt:sessions:prune --dry-run   # 只預覽筆數
php artisan schedule:run              # 手動觸發一次排程
```

生產環境只需讓系統每分鐘觸發一次 `schedule:run`（Linux crontab／Windows 工作排程器設定見 DEVELOP.MD 18.4）。

> ⚠ `optimize:clear` 會刪掉 `bootstrap/cache/packages.php`／`services.php`，這兩檔由「第一個進來的 HTTP 請求」重建——目錄不可寫會直接 500。若清完要繼續服務，補一道 `php artisan package:discover`。

### 4.9 測試與品質檢查

```powershell
php artisan test          # 或 composer test（先 config:clear 再 test）
vendor/bin/pint           # 格式化（Laravel Pint）
vendor/bin/pint --test    # 只檢查不修改
composer audit            # PHP 依賴漏洞掃描
npm audit                 # 前端依賴漏洞掃描
```

`php artisan test` 目前的實測結果：

```text
Tests:    1 failed, 1 passed (2 assertions)
```

失敗的是 `tests/Feature/ExampleTest`（預期 `GET /` 回 200，但目前 `/` 會 302 導向 `/admin`，見 DEVELOP.MD 8.5），屬**已知現況**，不是環境問題。

---

## 5. 目錄速查

```text
app/
  Api/{module}/            子站共用 API（MSC 分層：Controllers / Models / Services / routes）
  Hq/{module}/             主站專屬（站點管理），程式碼與資料庫都與子站完全獨立
  Web/{Portal}/{module}/   各端口頁面骨架與路由掛載點（業務邏輯仍在 Api）
  Support/{Concept}/       共用工具層（Auth、Site、Api、Audit、Plugin、Sso、File…）
  Sites/{SiteCode}/        客製化子站的覆寫類別與「公版沒有」的全新 API
  Plugins/{Function}/{Brand}/{Version}/   案場對接插件（統一套 Contract 介面）
  Console/Commands/        自訂 CLI 指令

resources/
  vue_src/                 前台 SPA 原始碼（site-overrides/{code}/ 放客製頁面）
  vue_src_admin/           子站後台 SPA
  vue_src_hq/              主站後台 SPA
  views/{module}/          共用 Blade；views/sites/{code}/ 為客製覆寫

database/
  migrations/              子站共用 migration（公版）
  hq/migrations/           主站 migration
  sites/{code}/migrations/ 客製化子站專屬 migration
  seeders/                 SiteBaselineSeeder 等

storage/app/sites/{id}.php 每站設定檔（DB 連線、template_namespace、vue_build_mode…）
config/initial_accounts.php 初始帳號定義（實際值來自 .env）
```

**Model 歸屬原則**：Model 一律放在「擁有它的模塊」的 `Models/`（客戶按模塊購買，模塊要能整包移除）；只有框架核心 Model（`AuditLog`／`Portal`／`Permission`／`IdentityType`／`SitePluginConfig`）放 `app/Support/{Concept}/Models/`（DEVELOP.MD 9）。

---

## 6. 關鍵設計決策（一句話版）

| 決策 | 一句話理由 | 詳見 |
| --- | --- | --- |
| **每站獨立資料庫**（非共用庫 + site_id 欄位） | 站點之間執行期完全互不相依，不會有跨站誤讀 | 6.1、6.4 |
| **連線設定在開站當下落地成靜態檔** | 子站請求進來只讀 `storage/app/sites/{id}.php`，不回頭查主站 DB，開新站不需重啟／`config:cache` | 6.4 |
| **執行期一律用固定連線名 `site_current`** | Model 不需要知道自己在哪個站；且禁止執行期 `env()`（`config:cache` 後會回 null） | 6.4 |
| **安全設定「嚴格優先、及早失敗」** | CSP／CORS／速率限制／驗證白名單一開始就用正式等級，不靠上線前才收緊；dev server 來源由程式依 `hot` 檔自動放行 | 4.2 |
| **身份別／端口／角色三者分離** | 身份別是「你是誰」（一人一種）；端口是「能進哪些入口」（一人多個、權限各自獨立） | 7 |
| **模塊購買 + 角色權限雙層** | 子站要能用某功能，得先「已開通模塊」再「角色有權限」，API 與選單都擋 | 6.9 |
| **插件以「功能」為契約、品牌版本為實作** | 換廠商／換案場不用改業務程式碼；設定放 `system_configs`，後台可編 | 6.10 |
| **前台客製化分三層（Tier 1/2/3）** | 共用為預設，客製變大才升級；升降級只換 build 方式與一個設定值，不用重構 | 8.1 |
| **System Config 只開放改值、不開放改結構** | 新增設定項代表程式碼要多讀一個欄位，必須工程師配合，不做假對稱的 CRUD | 17.2 |
| **統一錯誤碼分層 + Exception 一律轉 JSON** | 前端只需一套判斷邏輯；`/api/*` 一律視為要回 JSON（避免驗證失敗被 302 成 HTML） | 11.2 |
| **審計日誌跟著各自的資料庫走** | 主站操作記主站、子站操作記子站，不跨庫 join；密碼／token 一律不入日誌 | 15 |

---

## 7. 開發約定（必守重點）

1. 動工前先讀文件（本檔 + 對應的 DEVELOP.MD 章節）；實作後把「後人要遵循的內容」寫回文件。
2. **API 放 `app/Api/{module}`**，依 MSC 分層；Controller 只做驗證與轉發，業務邏輯寫在 Service。
3. **Controller 只接收驗證後資料**：使用 `$this->validateInput()` 過濾；禁止直接用 `$_GET`／`$_POST`／`$_REQUEST`。
4. **可能被子站覆寫的 Service，在 Controller 內用「方法參數注入」**，不要用建構子注入（Router 會提前建構 Controller，導致覆寫失效）；且**禁止 `new XxxService()`**。
5. **新模塊動工前確認 4 件事**：是否已登記 `modules` 表、要給哪些端口（`portal_key`）、路由是否掛 `module:{code}` middleware、是否需要開放 App Token 路徑（DEVELOP.MD 開發約定 14）。
6. **文字一律走語言包**：`lang/zh_TW/api.php`（`app/Api`、`app/Support`）、`lang/zh_TW/hq.php`（`app/Hq`）；API 程式碼禁止硬編碼文案。
7. **Swagger 標註必須自成一體**：禁止用「見 README X.X」把讀者導去別的文件；字典要把選項與意義列清楚（`@api-dict`），入參與回傳欄位意義都要寫明白。
8. **分頁一律使用** `app/Support/Api/Concerns/PaginationHelper.php`，並註明 `per_page=-1` 代表不分頁。
9. **刪除 API 一律軟刪除**（除非特別指明）。
10. **每個檔案開頭加上 Header 註解**（`File`／`Author`／`Date`／`Description`）：

```php
/* *****************************************
File: DashboardController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-06-18
Description: Dashboard 統計數據控制器
***************************************** */
```

11. 邏輯註解要保留、不可在迭代中刪掉既有的說明與參數文件；新建的臨時測試腳本記得刪除。
12. 遇到方案不明確或有疑問，**先提出問題**，不要自行決策做出與設計有偏差的實作。
13. 寫給 Windows 的指令一律用 PowerShell 語法。

---

## 8. 初始帳號與 API 測試範例

### 8.1 初始帳號

全站登入一律用 **帳號（`username`）+ 密碼 + 要進入的端口（`portal_key`）**，不使用 email。

| 角色 | 帳號（預設） | 密碼來源 | 在哪個資料庫 |
| --- | --- | --- | --- |
| 主站管理員 | `HQ_ADMIN_USERNAME`（預設 `admin`） | `.env` `HQ_ADMIN_PASSWORD` | 主站 |
| 子站超級管理員（我方維運） | `SITE_SUPER_ADMIN_USERNAME`（預設 `admin`） | `.env` `SITE_SUPER_ADMIN_PASSWORD` | 各子站 |
| 子站系統管理員（交付客戶） | `SITE_DELEGATION_ROOT_USERNAME`（預設＝**站代碼**，如 `default`、`vendora`） | `.env` `SITE_DELEGATION_ROOT_PASSWORD` | 各子站 |

`portals` 目前 seed 的可用值：`admin`。

### 8.2 登入與呼叫受保護 API

```powershell
# 1) 登入（portal_key 必填）
$body = @{ username = 'admin'; password = '請填入密碼'; portal_key = 'admin' } | ConvertTo-Json
$resp = Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/auth/login" -Method Post -ContentType "application/json" -Body $body
$token = $resp.data.access_token

# 2) 公開端點（不需 token）
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/ping"
# 預期：{"status":true,"error_code":0,"data":{"pong":true}}

# 3) 受保護端點（帶 token）
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/user/profile" -Headers @{ Authorization = "Bearer $token" }
```

### 8.3 回應格式與錯誤碼

```json
// 成功
{ "status": true, "error_code": 0, "data": {} }
// 失敗
{ "status": false, "error_code": 10001, "error_message": "驗證失敗" }
```

| 區間 | 意義 | 已知例子 |
| --- | --- | --- |
| `1xxxx` | 參數／驗證 | `10001` 驗證失敗 |
| `2xxxx` | 認證／授權 | `20015` Token 被新登入取代、`20016` 模塊未開通、`20017` 端口僅開放端口管理員 |
| `3xxxx` | 業務邏輯 | — |
| `5xxxx` | 系統異常 | — |

---

## 9. 遇到問題時

1. **指令失敗**：先確認 `php -v` 是 8.2.29、`.env` 的 `DB_*` 正確、且沒有誤用第 3.7 節的裸指令。
2. **登入後 500 但首頁正常**：典型的 `site_current` 連線或 `env()` 問題（DEVELOP.MD 6.4 第 4 點）。
3. **畫面空白、console 有 CSP violation**：屬嚴格 CSP 的預期行為，先看 DEVELOP.MD 4.2.1（`:style` 動態綁定是常見卡點）。
4. **改設定沒生效**：`php artisan optimize:clear`。
5. **查不到原因**：DEVELOP.MD 內每個章節都有「實際踩過的坑」與修正紀錄，多數錯誤已在其中留下症狀與處理方式。

