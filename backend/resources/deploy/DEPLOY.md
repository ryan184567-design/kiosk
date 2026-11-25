# {{site_name}}（{{site_code}}）現場單站部署說明

本目錄是由主站 `site:package`（見 README 6.8）產生的**單站部署包**：程式碼結構與 SaaS 開發庫完全一致，
只保留本客戶自己的客製化目錄（其他客戶的客製化程式碼與主站專屬的 `app/Hq` 都不在包內）。

| 項目 | 值 |
| --- | --- |
| 站點代碼 | `{{site_code}}` |
| 站點編號（site_id） | `{{site_id}}` |
| 站點類型 | `{{site_type}}` |
| 前台 Vue 建置模式 | `{{vue_build_mode}}` |
| 資料庫名稱 | `{{db_name}}` |

## 1. 環境需求

- PHP 8.2（與開發環境同一條基線，見 README 2.1）
- MySQL 8.4（`utf8mb4` / `utf8mb4_unicode_ci`）
- Web 伺服器的 **DocumentRoot 指向本目錄的 `public/`**（不是專案根目錄）
- 需要可執行 `composer`（本部署包刻意不含 `vendor/`，見第 2 點）

## 2. 安裝相依套件

```bash
composer install --no-interaction --optimize-autoloader --no-dev
```

`vendor/` 不隨部署包附上，原因是「依賴由 composer 依 `composer.lock` 還原」才能保證與開發庫一致，
且可避免不同作業系統（Windows 開發 / Linux 上線）的二進位差異。

## 3. 檢查 `.env`

部署包已附一份**可直接使用的 `.env`**（由 `.env.example` 產生），匯出時已寫入：

- `APP_ENV=production`、`APP_DEBUG=false`（見 README 4.2「嚴格優先」）
- `APP_KEY`、`JWT_SITE_SECRET`、`JWT_HQ_SECRET`：**匯出當下自動產生**，每個部署包都不同。
  這幾個值變更會讓既有登入狀態與已加密資料失效，部署後請勿隨意更換。
- `MULTI_SITE_ENABLED=false`、`SITE_TEMPLATE_NAMESPACE={{site_code}}`、`SITE_ID={{site_id}}`（見 6.8）
- `DB_DATABASE={{db_name}}`

現場只需依實際狀況調整 `DB_HOST`、`DB_PORT`、`DB_USERNAME`、`DB_PASSWORD`、`APP_URL`。
`DB_USERNAME` 請填實際帳號（常見錯誤：把 `root` 打成 `rot`，症狀是 `SQLSTATE[HY000] [1045] Access denied`）。

另外，`storage/app/site_modules.php` 是匯出時產生的「已開通模塊清單」（見 README 6.9 第 6 點），
單站環境靠它判斷哪些模塊可用；**請勿刪除或搬動**，否則所有模塊會被視為未開通，且 log 會出現
`單站模式找不到模塊開通清單` 的警告。

## 4. 目錄權限

`bootstrap/cache/` 需可寫入。部署包已建立好 `storage/` 目錄骨架，Linux 上通常需要：

```bash
chmod -R 775 storage bootstrap/cache
```

Windows（IIS／Apache 服務帳號）請確認 `storage` 與 `bootstrap/cache` 對服務執行身分有「修改」權限
（Laravel 會在**請求期間**寫入 Blade 編譯檔與 package manifest，此目錄不可寫會直接 500，
見第 7 點的實測案例）。

## 5. 資料庫

若現場資料庫是**全新空庫**（第一次部署）：

```bash
php artisan migrate --force
php artisan migrate --path=database/sites/{{site_code}}/migrations --force   # 這個目錄存在時才需要
```

若現場資料庫是**從開發庫匯入的既有資料**（一般情況，客戶資料已存在），跑 `migrate` 只會補上缺少的
遷移，不會清空資料；仍建議先在測試庫驗證。另外，正式部署**不要**執行 `migrate:fresh` 或
`db:seed`（baseline seed 會重建初始帳號／模組授權，需自行確認影響，見 README 6.6）。

## 6. 前端建置（Node.js）

部署包含前端原始碼與 `package.json`／`package-lock.json`，但**不含 `node_modules/`**，也不保證附帶編譯產物
（`public/vue`、`public/admin-assets` 在開發庫的 `.gitignore` 內）。現場需有 Node.js 18+，並執行：

```bash
npm ci
npm run admin:build      # 子站後台 → public/admin-assets（/admin）
npm run vue:build        # 前台共用建置 → public/vue
```

- `{{vue_build_mode}}` 為 `shared`（Tier 1/2）：只需上面兩行。
- `{{vue_build_mode}}` 為 `dedicated`（Tier 3）：前台改用獨立建置，並輸出到 `public/vue`：

```bash
php artisan vue:build --site={{site_code}} --output=public/vue
```

- 單站部署包**沒有主站後台**（`hq`），不要執行 `npm run hq:build`。
- 若 `/admin` 出現 404，通常是 `admin:build` 沒跑（見 7.1）。

## 7. 上線前最後一步

```bash
php artisan config:cache     # 設定快取，正式環境建議開啟
php artisan view:cache       # Blade 編譯快取
php artisan route:cache      # 路由快取（Laravel 12 已支援含閉包的路由，可安心使用）
```

若日後修改 `.env`，記得再執行一次 `php artisan config:clear`（或重跑 `config:cache`）才生效。

### 注意：`optimize:clear` 之後要先重建 package manifest（實測踩過）

`php artisan optimize:clear`（連同 `clear-compiled`）會**刪掉** `bootstrap/cache/packages.php` 與
`bootstrap/cache/services.php`。這兩個檔案會被「第一個進來的 HTTP 請求」即時重建，因此：

- `bootstrap/cache` 目錄必須可寫（見第 4 點），否則請求會直接 500。
- 在 Windows 上，若同時有多個請求一起重建，`rename()` 會因為檔案被另一個行程佔用而失敗，
  症狀是頁面 500、`storage/logs/laravel.log` 出現
  `rename(...bootstrap\cache\pacXXXX.tmp, ...bootstrap\cache/packages.php): 存取被拒 (code: 5)`。
  這是「清完快取後又被併發請求同時重建」造成的一次性問題，不是每次都會發生，但一發生就很難查。

因此**建議順序**是：先 `composer install`（會自動執行 `package:discover` 產生這兩個檔案）→
`php artisan config:cache`／`view:cache`／`route:cache` → 再開放對外存取。
若真的需要 `optimize:clear`，清完請補一道指令把 manifest 重建回來：

```bash
php artisan package:discover
```

## 7.1 常見錯誤排查（實際遇過的症狀）

| 症狀 | 原因 | 處理 |
| --- | --- | --- |
| 頁面 500，log 顯示 `Please provide a valid cache path` 或找不到 `storage/framework/views` | 部署包被解開時漏了 `storage/` 骨架 | 確認 `storage/framework/{cache/data,sessions,views}`、`storage/logs` 存在且可寫（見第 4 點） |
| 頁面 500，log 顯示 `No application encryption key has been specified` | `.env` 的 `APP_KEY` 是空的 | 執行 `php artisan key:generate`（正常情況下匯出包已自動產生，不需手動） |
| 頁面 500，log 顯示 `SQLSTATE[HY000] [1045] Access denied for user 'xxx'@'localhost'` | `.env` 的 `DB_USERNAME`／`DB_PASSWORD` 打錯（實際遇過把 `root` 打成 `rot`） | 修正 `.env` 後執行 `php artisan config:clear` |
| 頁面 500，log 顯示 `rename(...bootstrap\cache\...): 存取被拒 (code: 5)` | 清完快取後多個請求同時重建 package manifest（見第 7 點） | 執行 `php artisan package:discover`，並確認 `bootstrap/cache` 可寫 |
| 頁面顯示「Class \"view\" does not exist」 | 上述 manifest 寫入失敗的連鎖反應（facade 別名沒註冊成功），不是 view 相關設定有問題 | 同上 |
| 頁面 500，log 顯示 `Class "App\Hq\SiteModule\Models\Module" not found` | 共用層依賴主站模塊表，但單站部署包不含 `app/Hq`（見 README 6.8 第 8 點、6.9 第 6 點） | 覆蓋共用目錄為最新版，並確認 `storage/app/site_modules.php` 存在 |
| 頁面 500，log 顯示 `Access denied for user ''@'localhost'`（帳號與資料庫名都是空的） | 執行期 `env()` 在 `config:cache` 後回傳 null（見 README 6.4 第 4 點） | 覆蓋共用目錄為最新版，重新 `config:clear`／`config:cache` |
| `/admin` 404 或空白頁 | 子站後台 SPA 未建置 | 執行 `npm ci && npm run admin:build` |
| 首頁 302 轉到 `/admin` | 正常行為（裸網域導向後台入口，見 `SpaFallbackController`） | 無需處理 |

## 8. 登入帳號

初始帳號沿用匯出前該子站在多站環境下已 seed 的帳密（見 `config/initial_accounts.php`、README 6.3／6.12）。
若客戶資料庫是從開發庫匯入的，帳號即為開發庫上該站的帳號。
**上線後請立即更換初始密碼**，並確認 `.env` 中不再需要保留 `HQ_ADMIN_PASSWORD` 等 seed 用變數。

## 9. 後續維護

- 公版更新：覆蓋共用目錄（`app/Api`、`app/Support`、`resources/views/{module}`、`public/vue` 等），
  **不要**覆蓋 `app/Sites/{{site_code}}` 底下的客製化檔案（見 README 6.8 第 6 點）。
- 追加客製化：直接在 `app/Sites/{{site_code}}`、`resources/views/sites/{{site_code}}`、
  `resources/vue_src/src/site-overrides/{{site_code}}` 加檔案，操作方式與 SaaS 開發模式相同。
- 共用程式碼不可寫死判斷其他 `site_code` 的例外邏輯，否則這個部署包會失去「只服務單一客戶」的前提
  （見 README 6.8 第 7 點）。
