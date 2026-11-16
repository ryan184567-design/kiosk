# Laravel 開發框架規劃文件

## 1. 背景與目標
本專案定位為「基礎開發框架」，不承載具體業務；但是包含基礎的管理相關API；目標是建立可複用、可擴展、可多人協作的專案模板，並提供必要的基礎能力示範。

核心目標如下：
1. 統一目錄結構與分層規範，降低多人開發衝突。
2. 建立可支持主站/子站的多租戶式開發模型。
3. 制定安全、語系、檔案、日誌、測試等共通規範。
4. 提供可快速落地的腳手架與工具腳本。

## 2. 版本與相容性
本章是「必遵守」的版本基線與落地流程，避免每位工程師使用不同環境導致問題難以重現。

### 2.1 版本基線（凍結）
| 元件 | 基線版本 | 說明 |
| --- | --- | --- |
| PHP | 8.2.29 | 目前專案依賴與語法以 8.2 為準，禁止使用 7.x 或 8.0/8.1。 |
| Laravel | 12.x | 以 composer.lock 鎖定實際小版本，不使用「最新」描述。 |
| MySQL | 8.4.7 | 資料庫語法與行為以 8.4 系列驗證。 |
| Redis | 6.2.x | 快取/佇列以 6.2 相容行為為基線。 |

### 2.2 工程師本機環境要求
1. 必須先確認 CLI 使用的是 PHP 8.2.29，完整目錄為 `D:\wamp64\bin\php\php8.2.29`。
2. VS Code 需指向此 PHP 8.2 執行檔，避免語法提示與實際執行版本不一致。
3. 新環境第一次啟動前，需完成以下命令。

Windows PowerShell 建議流程：

```powershell
[Console]::InputEncoding = [System.Text.UTF8Encoding]::new($false)
[Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
chcp 65001 > $null
$env:Path = "D:\wamp64\bin\php\php8.2.29;" + $env:Path
php -v
composer install
php artisan optimize:clear
php artisan migrate --ansi
php artisan key:generate --ansi
```

### 2.3 相容性驗證（提交前必做）
每次調整依賴、升級套件或切換開發機時，至少完成以下檢查：

1. `php -v` 顯示 8.2.29。
2. `composer check-platform-reqs` 全部通過。
3. `php artisan --version` 可正常輸出 Laravel 版本。
4. `php artisan migrate:status --ansi` 可正常讀取遷移狀態。
5. `.env` 需明確設定：`DB_CHARSET=utf8mb4`、`DB_COLLATION=utf8mb4_unicode_ci`。

### 2.4 升級與變更規範
1. 禁止直接把版本寫成「最新」，必須寫明 major/minor 範圍或固定版號。
2. 任何基線變更（例如 PHP 8.2 -> 8.3）必須同步更新：
   - 本章版本矩陣
   - `composer.json` 相依條件
   - CI/部署腳本使用的執行版本
3. 版本變更需附上回歸驗證結果（至少含 migrate、主要 API、登入流程）。

### 2.5 資料庫讀寫分離
**預設不啟用**：絕大多數站點的流量不需要讀寫分離，只有在單一站點規模成長到主庫無法負荷時，才需要為該站點個別開啟，不做成全站強制規範。

1. 專案已支援 Laravel 原生讀寫分離（read/write + sticky），單站模式與多站模式下的個別子站皆可選擇性開啟。
2. `.env` 可選配置（單站模式，或多站模式下由主站後台界面產生的子站設定，見 6.3、6.4）：
   - `DB_READ_HOST`：讀庫 host，可用逗號分隔多台。
   - `DB_READ_PORT`、`DB_READ_USERNAME`、`DB_READ_PASSWORD`。
   - `DB_WRITE_HOST`：主庫 host（通常單一）。
   - `DB_WRITE_PORT`、`DB_WRITE_USERNAME`、`DB_WRITE_PASSWORD`。
   - `DB_STICKY=true`：同一次 request 寫入後，後續讀取強制走主庫，降低主從延遲造成的讀不到問題。
3. 未配置 read/write 時，系統會回退使用 `DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD`（多站模式下對應子站自己的資料庫連線設定，見 6.4）。
4. 配置變更後需執行：`php artisan optimize:clear`。
5. 遷移、seed、交易內操作仍以主庫為準，不建議在資料一致性敏感流程關閉 sticky。
6. 多站模式下，若某子站需要開啟讀寫分離，由主站後台「子站管理」界面針對該站個別設定讀/寫庫資訊，儲存時一併寫入該子站的連線設定檔（`storage/app/sites/{site_id}.php`，見 6.4），不需要為其餘子站一併規劃這組欄位。


## 3. 基礎架構原則

### 3.1 分層與模組
1. Web 遵循 MVC；API 遵循 MSC（Model-Service-Controller）。
2. 系統按模組開發，每個模組為獨立目錄，至少包含 controller、model、view 或 service、route。
3. 共用能力不得重複散落於模組內，必須放入共用層（如 helper、service、component、library）。

### 3.2 前端策略
1. 保留 Blade 模板作為傳統前台渲染方案,默認使用 laravel 自帶的 vite;但部分情況會使用混合的模式，如對接 SSO 的跳轉頁面會使用 Blade 渲染。
2. 提供 `resources/vue_src` 用於存放前台原始代碼，開發時使用 npm 自帶的開發服務提供即時預覽；原始碼不放在 `public` 下，避免被直接存取（見 9）。
3. Vue 編譯資源部署在 `public/vue`（公版與輕度客製站點共用；深度客製站點另有獨立編譯目錄，見 8.1／8.4），除了實際存在的系統路由（例如 `/api`、`/docs`、`/admin`）外，其餘前台 GET 路徑都會依目前站點回傳對應的 Vue 入口頁，交由前端路由處理。

目錄結構完整規劃見「9. 目錄結構規劃」。

## 4. 請求參數與安全規範
1. 禁止直接使用 $_GET、$_POST、$_REQUEST 等超全域參數。
2. 所有輸入必須經過統一驗證層，僅允許取得已聲明規則的欄位。
3. Controller 只接收經驗證後資料，業務邏輯放在 Service。
4. 寫操作接口需包含 CSRF（Web）或簽章/Token（API）保護。
5. 統一使用 ORM 或 Query Builder 參數綁定。

### 4.1 入參驗證器
繼承laravel原生的驗證器，未來按需求進行擴展

### 4.2 安全設定採「嚴格優先、及早失敗」原則（Secure by Default）
**核心原則**：凡是涉及資安的設定（CSP、CORS、Cookie 屬性、速率限制、輸入驗證白名單…），一律在**專案初始化當下就採用正式環境等級的嚴格設定**，不採取「先求開發方便、上線前再調緊」的做法。放寬過的安全設定，實務上常演變成工程師已經習慣寬鬆寫法（inline script、萬用 CORS…）、程式碼已大量依賴會被擋下的模式，等到上線前才收緊時要大改一輪，時間壓力下往往改不完或帶著已知風險倉促上線。改成一開始就嚴格，違規寫法在本機開發階段就會直接報錯或被擋下，等於強制工程師從第一行程式碼就用合規的方式寫，上線前幾乎不需要再調整安全設定。

**本地開發環境（`APP_ENV=local`）與正式環境的安全設定，只允許差異在「維運相關的細節」**（例如允許的網域清單不同、本機憑證是自簽），**不允許本地環境整體關閉或放寬安全機制本身**（例如本地不可以直接把 CSP 關掉、CORS 不可以在本機設成 `*`、速率限制不可以用 `APP_ENV=local` 條件式跳過）。

**例外範疇**：以上「一開始就嚴格、不允許放寬」針對的是**寫進應用程式碼裡、需要工程師照特定寫法開發**的資安限制（CSP、CORS 白名單邏輯、速率限制邏輯、輸入驗證…）——這類限制一旦一開始放寬，後面全靠工程師回頭改程式碼才能收緊，成本會隨程式碼量一起疊加，難以收拾。若某項資安需求可以透過**基礎設施層的整體限制**一次到位解決、不需要應用程式配合寫特定邏輯（例如 HTTPS／TLS termination 交給 HAProxy 或其他反向代理處理、IP 白名單交給防火牆/負載平衡器、DDoS 防護交給邊界設備），則不受本節限制，應用程式與本機開發環境不需要重複實作——這類限制是在基礎設施層套用一次就對所有環境同時生效，不存在「之後要一個個回頭改程式碼」的問題，本節 4.2.2 的 Cookie／Session 一項即是此類例外的具體案例。

#### 4.2.1 CSP（Content-Security-Policy）
1. 專案初始化當下就加上 `SecurityHeaders` middleware（`app/Support/Http/Middleware/SecurityHeaders.php`），全域套用嚴格 CSP：`script-src 'self' 'nonce-{隨機值}'`、`style-src 'self' 'nonce-{隨機值}'`，**不使用 `unsafe-inline`／`unsafe-eval`**。這套嚴格規則是「編譯後產物」必須滿足的標準，Laravel 應用本地與正式環境一致套用，不因為是本機就放寬。
2. **有兩套完全獨立的 Vite 建置，dev server 對 CSP 的影響也不同，不要混為一談**：
   - **Blade 資產**（`resources/css`/`resources/js`，走 `vite.config.js` + `laravel-vite-plugin`，指令 `npm run dev`/`npm run build`）：頁面透過 `@vite()` 由 Laravel／Blade 渲染回應，會經過 `SecurityHeaders` middleware。開發時 Vite 的熱更新（HMR）需要額外來源才能運作，硬套嚴格 CSP 只會讓開發體驗變差、換不到實質安全效益——dev server 只在開發者自己機器上執行，從未對外服務、也不會被部署。做法：透過 Laravel 的 `Vite::hotFile()` 判斷目前是否為 dev server 模式，是的話才額外放行 dev server 來源（`script-src`／`connect-src`／`style-src` 動態讀取 `hot` 檔案裡實際記錄的 origin，**不寫死 port**——Vite 遇到 port 衝突會自動換一個，寫死會導致換了 port 後 CSP 放行的來源跟實際 dev server 對不上，反而擋住開發，必要時對 dev server 來源開放 `unsafe-eval`）；這個判斷邏輯與正式建置共用同一份 `SecurityHeaders` middleware，**由程式依 `hot` 檔案是否存在自動切換，不是靠工程師手動註解／取消 CSP**，因此不會有「忘記改回來」的風險，正式環境也因為不會有 `hot` 檔案而自動維持嚴格。
   - **Vue3 前台 SPA**（`resources/vue_src`，走獨立的 `vite.vue.config.js`，指令 `npm run vue:dev`/`vue:build`，見 6.10 命名慣例對應的 9 節目錄、18.2 第 12 項）：開發時瀏覽器直接連 Vite 自己的 dev server（例如 `http://localhost:5173/vue/`），**完全不經過 Laravel、也不會碰到 `SecurityHeaders`**，因為這個 dev server 本身就是獨立的 HTTP 伺服器，不是由 Laravel 代理或渲染的。這不是安全漏洞——一樣是「只在開發者自己機器上執行、不會被部署」的情境——但代表 CSP 的即時回饋在這個 dev server 下不會出現，真正的把關點是下方第 8 點的「編譯後驗證」，開發時若要在瀏覽器主控台提早看到 CSP 違規，可以先跑一次 `vue:build` 後用 Laravel 的路由（見 3.2 第 3 點的 SPA fallback）預覽。
3. Blade／Vite 整合：`AppServiceProvider::boot()` 註冊 Laravel 內建的 `Vite::useCspNonce()`，讓 `@vite` 產出的 `<script>`／`<link>` 自動帶上本次請求的 nonce；頁面上若還需要其他 inline `<script>`，一律透過 `Vite::cspNonce()` 取得同一個 nonce 附加，不允許無 nonce 的 inline script 存在。
4. Vue3 前台建置（8 節）**禁止使用 runtime-compiler 版本**（避免執行期以 `new Function()` 編譯樣板而需要開放 `unsafe-eval`），一律使用 Vite 預設的 SFC 預編譯建置產物。
5. **CSS／inline style 的因應策略（常見卡點，需一開始就定案，不要等到大量元件都寫了 `:style` 才發現擋住）**：Vue3 的 `:style` 動態綁定、許多 UI 套件的動態定位/尺寸，預設會編譯成 inline `style="..."` **屬性**，嚴格 CSP 的 `style-src`（不含 `unsafe-inline`）預設也會擋掉這類 inline 屬性，不是只擋 `<style>` 標籤，開發時務必留意，不能只顧 `script-src` 卻漏了這塊：
   - 優先方案：能用 `:class` 切換樣式類別就不要用 `:style` 動態內嵌樣式，把「有限的幾種樣式狀態」預先寫成 CSS class；真正需要連續數值（例如拖曳座標、進度條百分比）時，改用 CSS 自訂屬性（CSS variables）搭配共用元件內、帶 nonce 的 `<style>` 區塊寫 `var(--x)`，而不是每個元件各自 inline style。
   - 若第三方 UI 套件內部本來就是用 inline style 實作、上述方案無法完全涵蓋：可利用 CSP Level 3 把 `style-src-attr`（inline `style="..."` 屬性）與 `style-src-elem`（`<style>`/`<link>` 標籤）分開設定——`style-src-elem` 維持嚴格（只允許 nonce），只在 `style-src-attr` 開放 `unsafe-inline`。這是業界常見的折衷做法，因為 inline style 屬性本身無法執行 JavaScript，風險遠低於整段允許 `<style>`／`<script>` 注入；但這仍是一種放寬，一樣要比照下方第 6 點的規則明確登記原因與範圍，**不可以圖方便直接把整個 `style-src` 開 `unsafe-inline`**。
6. 已知例外需在文件明確登記，不可默默放寬：例如 Swagger UI（12 節 `/docs`）若所用版本需要 inline style，僅能針對 `/docs` 路徑單獨放寬（獨立的 middleware 分組），不可讓整站 CSP 因此變寬鬆；日後新增任何 CSP 例外（含上方第 5 點的 `style-src-attr`），都要在本文件登記原因與適用範圍。
7. 一律使用會真正阻擋的 `Content-Security-Policy` 標頭，不把 `Content-Security-Policy-Report-Only`（僅回報不阻擋）當作正式運作模式——`Report-Only` 只能作為調整規則時的暫時輔助工具，長期停留在這個模式等於沒有真正防護。
8. **編譯後產物需驗證符合嚴格 CSP，作為正式把關點**：`npm run build` 後的靜態產物不應該再需要任何 `unsafe-inline`／`unsafe-eval`／dev server 例外；上線前（或 CI）需以「正式模式」（無 `hot` 檔案）實際跑一次編譯後的 `public/vue`／`public/build`，確認瀏覽器主控台沒有任何 CSP violation 才算通過。開發過程用 `npm run dev` 圖方便沒關係，但這個編譯後驗證步驟不可省略——這正是「編譯後必須符合嚴格 CSP、但開發過程不必因此綁手綁腳」的具體落地方式。

#### 4.2.2 比照辦理的其他項目
- **CORS**：`config/cors.php` 從一開始就使用明確網域白名單（`.env` 的 `CORS_ALLOWED_ORIGINS`，逗號分隔），不使用 `*`；預設空陣列，代表「沒設定就不允許任何跨來源請求」，本機開發網域也需要明確列在白名單才會放行，不因為是本機就整組放行。實務上 Vue3 SPA／Blade 頁面預設與 API 同源（同一個 Laravel 應用提供，見 3.2、8 節），瀏覽器對 `/api/*` 的呼叫本來就不會觸發 CORS 檢查，這份白名單只保留給「前台真的架在跟 API 不同網域」的少數情境（非本階段預設架構，見 9.1）。本機用 `npm run vue:dev` 時 Vue3 SPA 由 Vite 自己的 dev server 提供、與 API 不同 origin，但這個情境不透過 CORS 解決——改用 `vite.vue.config.js` 的 dev server proxy（把 `/api` 轉發到 `VUE_DEV_API_PROXY` 指定的後端），讓瀏覽器完全感受不到跨來源，比開 CORS 更乾淨；這與 Blade 資產（`vite.config.js`）的 dev server 是完全獨立的兩套設定，後者透過 `<script src>` 載入 JS/CSS，頁面本身仍是同源，從未需要 CORS。已知第三方套件行為：底層 `fruitcake/php-cors` 在白名單剛好只有一個網域時，不會真的比對請求的 Origin，一律固定回傳該網域（白名單有兩個以上才會正確比對，已實測驗證）；這不是可利用的漏洞（瀏覽器端仍會用回應值比對頁面自身來源），但正式環境若曾經只設定一個網域，稽核時容易誤解成「有做來源比對」，已在 `config/cors.php` 註解中明確記錄。
- **Cookie／Session**：HTTPS 本身不需要在 Apache／Laravel 這層處理——正式/測試環境由前端（如 10.5 規劃的 HAProxy，或其他反向代理／負載平衡器）做 SSL termination，Apache／Laravel 收到的是內部 HTTP。真正「從一開始就要設對」的是 `TrustProxies`：透過 `.env` 的 `TRUSTED_PROXIES`（信任的反向代理來源 IP/CIDR，依實際部署環境設定，本機開發留空即可）設定信任的反向代理來源，讀取 `X-Forwarded-Proto`／`X-Forwarded-For`，讓 `$request->secure()`、`Secure` cookie 標記、`url()`/`route()` 產生的網址都能正確反映「瀏覽器端是 HTTPS」。本機開發沒有反向代理，直接用 HTTP、`SESSION_SECURE_COOKIE=false` 即可，這不算安全性放寬（本機不存在需要保護的真實網路邊界）；`HttpOnly`、`SameSite` 兩項與 TLS 無關，本機與正式環境都應一致設定為嚴格值。
- **速率限制**：本節與 6.3 的建站限流等既有規則，一律在本機開發環境就啟用，不得用環境條件式關閉，避免正式環境才第一次真正測到限流行為。
- **依賴套件安全掃描**：`composer audit`／`npm audit` 從專案第一天就納入 16 節「PR 合併前需通過」的檢查項目，不是上線前才臨時補做一次。
- **檔案上傳驗證**（10.1 第 3 點）、**輸入驗證白名單**（本節第 2 點）等既有規則，同樣適用本節原則：一開始就用正式等級的嚴格規則實作，不允許先做寬鬆版本「之後再補」。

## 5. 國際化（i18n）規範
1. 程式碼中禁止硬編碼文案（範圍與例外見開發約定第 11 點：CLI 終端機輸出、Swagger 標註驗證錯誤不受此限）。
2. **實際採用 Laravel 標準機制**，不另外自訂命名空間：語言包放在專案根目錄 `lang/{locale}/{file}.php`（`base_path('lang')`），呼叫直接用 `__('api.auth.invalid_credentials')`，不帶 `app::` 之類的前綴——標準路徑不需要 `useLangPath()`／自訂 `ServiceProvider` 註冊命名空間，減少一層不必要的間接。
3. 語言 key 命名為: `{domain}.{scene}.{key}`（例如 `api.auth.invalid_credentials`）。
4. API／Middleware／共用工具（`app/Support` 底下會被請求路徑呼叫到的部分）訊息一律透過語言包讀取，禁止直接寫死字串。

### 5.1 語言包目錄策略（依領域分檔，不與資料庫隔離規則混淆）
1. 不要全部塞在單一檔案，依「領域」分檔，檔名對應呼叫時的第一層 key：
   - `lang/zh_TW/api.php`：`app/Api`、`app/Support`（`ErrorCatalog`、`PluginManager`、`SsoManager` 等）、`app/Web/Sso`、`app/Web/Impersonation` 共用。
   - `lang/zh_TW/hq.php`：`app/Hq` 專用——因為程式碼與資料庫跟子站完全獨立（見 6.5），文字也分開維護，不與 `api.php` 混用。
   - 未來若驗證訊息需要客製化，比照標準 Laravel 慣例另外維護 `lang/zh_TW/validation.php`，目前沿用框架內建預設訊息，尚未客製化。
2. 規模變大後再依模組細分（例如 `api_auth.php`、`api_user.php`），比一開始過度切分更實用；目前規模不需要拆到這麼細。
3. key 命名需具語意與層次，例如：
   - `api.auth.invalid_credentials`
   - `api.role.exceeds_delegation_ceiling`
   - `hq.provisioning.code_exists`

### 5.2 placeholder 用法（重點）
Laravel 會自動替換語言字串中的 placeholder，常見如下：
1. `:attribute`
   - 來源：`validation.php` 的 `attributes` 陣列
   - 範例：
     - `'required' => ':attribute 為必填欄位。'`
     - `'attributes' => ['email' => '電子郵件']`
   - 當 `email` 驗證失敗時會輸出：`電子郵件 為必填欄位。`
2. `:min`、`:max`、`:value`、`:date` 等
   - 由對應驗證規則自動注入
   - 範例：`'min.string' => ':attribute 長度不可少於 :min 個字元。'`
3. 自訂替換
   - 可用 `__('api.file.extension_not_allowed', ['ext' => $ext])`
   - 語言字串：`'extension_not_allowed' => '不允許的副檔名：:ext'`

### 5.3 實務建議
1. 「同一語言根目錄（`lang/`）＋依領域分檔」是目前最平衡做法，不會因為檔案太大難找，也不會造成過度分散。
2. 優先把「API 回傳錯誤訊息」集中管理，避免跨模組重複定義；目前尚未客製化 Laravel 內建的 `validation.php` 訊息（沿用框架預設英文/中文包），有需要時再補上。
3. 目前階段僅維護 `zh_TW`（`.env` 的 `APP_LOCALE`/`APP_FALLBACK_LOCALE` 已設定）；`en` 暫不維護，待後續需要時再整體翻譯。

## 6. 多租戶（多站）架構設計
多數基礎工具（`PermissionService`、`FileManager`、`PhotoClass`、`SiteResolver` 的網域/路徑判斷邏輯、Swagger 標註方式）沿用 `D:\www\nckuh_ems` 已驗證可行的設計，本次重新設計要解決的問題：身份別從寫死 enum 改為可設定、端口存取從依附身份別改為獨立可設定（一人可跨多端口、各端口權限獨立，見 7 節）、選單從寫死 config 改為可管理、多站從「共用資料庫＋site_id 欄位」改為「每站獨立資料庫」。

**多站架構的用途**：(1) SaaS 平台正式多租戶營運；(2) 公司提供測試/展示平台給客戶試用（demo／POC，同一套系統快速開一個子站給客戶看，不用另外架環境）；(3) 若客戶決定採購並需要獨立部署到自己機房，透過 6.8 節的單站打包機制，把該客戶在多站環境下已經客製化的部分原封不動帶到現場部署，不需要重做。

### 6.1 兩種運作模式：多站（開發／SaaS）與單站（現場部署匯出）
`.env` 以 `MULTI_SITE_ENABLED=true|false` 決定運作模式。這兩者**不是同一套部署互相切換的開關**，而是對應兩種不同情境：開發與正式 SaaS 永遠使用多站模式，單站模式只是把某個客製化子站固化匯出後的部署形態（見 6.8）。

1. **`true`（多站模式，開發／SaaS 預設）**：
   - 所有開發都在這個模式下的同一份 git 倉庫完成，公版功能與各子站的客製化都在此開發、測試。
   - 啟用**主站**（`site_id = 0`）：主站是獨立站點，擁有自己的資料庫（使用一般 `.env` 的 `DB_HOST/DB_DATABASE/...` 預設連線）與獨立程式碼目錄 `app/Hq`（見 9），負責「站點管理」——開設子站、管理子站、停用子站、模塊開通（6.9）等；主站與子站的程式碼（`app/Api`、`app/Web`）、資料庫、權限體系**完全獨立、互不共用**。目前**不**提供跨子站資料彙總功能（例如彙整各子站商品成一份總表），未來若有此需求另行設計（見 19 節）。
   - 子站 `site_id` 從 1 開始：**1 號子站是公版的預設開發／測試站**，直接繼承共用代碼（`app/Api`、`app/Web`），沒有任何客製化覆寫，工程師平時開發即是在這個站點上調試；子站透過網域或路徑前綴判斷進站站點（`SiteResolver`：網域優先 → 路徑前綴 `{code}` → 預設 1 號站）。路徑前綴直接用 `code` 本身、不加任何符號區隔（例如 `code=vendora` 對應 `http://host/vendora/...`），代價是若前台頁面路徑第一段剛好跟某個子站代碼同名會被誤判成該子站，屬已知取捨，命名子站代碼時應避開常見前台路由名稱；實際資料庫連線見 6.4。**網域綁定（已實作）**：開站當下不會自動設定網域（`primary_domain` 預設 null，先用路徑前綴進站），要另外呼叫 `PUT /api/hq/sites/{siteId}/domain` 設定或清除，主站後台頁面（見 6.12）的子站詳細頁已有對應表單；設定成功會同步寫入 `sites` 表的 `primary_domain` 欄位與 `storage/app/sites/sites.json`（`SiteResolver` 實際查詢的來源），兩種進站方式（網域／路徑前綴）可以同時存在、互不影響，只能對已建置完成（`ready`）的子站設定。
   - SaaS 正式營運、以及提供客戶測試/展示（demo／POC）的測試機，皆使用此模式。
2. **`false`（單站模式，現場部署匯出專用，非開發模式）**：
   - 僅用於「透過 `php artisan site:package` 將多站環境下某個子站固化匯出」之後的部署包（見 6.8），不會有工程師直接在單站模式下開發新功能，也不包含 `app/Hq`（單站部署不需要站點管理功能）。
   - 匯出時，主站依照 6.3 記錄的該子站資訊（購買的模塊、客製化程度、資料庫設定等）產生固化設定檔（`.env`／`storage/app/sites` 對應檔案），`SiteResolver` 不再判斷網域/路徑前綴，`site_current` 連線也不再動態切換，直接固定使用匯出時寫入的資料庫設定（即該客戶自己的資料庫，不是 `site_id=1`，實際值依原本在多站環境下的 site_id 而定）。
   - 仍保留 `migrate`、`db:seed` 等 `php artisan` 指令支援，供現場工程師操作資料庫（見 6.8 第 5 點）。

### 6.2 站點類型（standard／customized）
`sites` 資料表存在於**主站自己的資料庫**（見 6.1），用來記錄所有子站的開站資訊；子站各自的資料庫內不會有這張表。在 nckuh_ems 既有欄位（name、code、primary_domain、path_prefix、db_connection、is_enabled）基礎上新增：

| 欄位 | 說明 |
| --- | --- |
| `site_type` | `standard`（公版）／`customized`（客製化），決定是否納入批次 migrate、是否有專屬覆寫目錄 |
| `db_name` | 實際資料庫名稱（= `SITE_DB_PREFIX` 設定值 + `code`，見 `config/multisite.php`），建站時落地存一份，避免設定值改動後對應錯亂；**刻意不用 site_id 編號**，改用子站代碼是為了能直接從資料庫名對照是哪個子站，不用另外查表，前綴可設定是為了跟同一台 MySQL 上其他不相關的資料庫明確區隔，避免裸用 `code` 當資料庫名互相撞名 |
| `template_namespace` | 客製化覆寫的識別碼，預設等於 `code`，用於 9.2 的容器綁定與 View fallback |
| `clone_from_site_id` | 若由既有子站複製開站，記錄來源站點，供追溯（複製範圍見 6.3） |
| `provision_status` | 建站狀態：`pending`／`creating`／`ready`／`failed`，供 6.3 非同步建站流程追蹤與後台顯示 |
| `last_migrated_at` / `last_migration_batch` | 供後台顯示「哪些子站尚未同步到最新公版 schema」 |

### 6.3 自動建站流程（`site:create`）
已確認走全自動路線：App DB 帳號具備 `CREATE DATABASE` 權限。此流程屬於 6.1 所述的主站獨立程式碼 `app/Hq`（非子站 `app/Api`），連線的是主站自己的資料庫。流程：
1. 主站後台呼叫 `POST /api/site/sites`（或 CLI `php artisan site:create {code} {name} --type=standard|customized [--clone-from=]`）。
2. 服務層依序：
   - 驗證 `code` 唯一、命名規則（小寫英數底線）。
   - 建立 `sites` 記錄取得 `site_id`，回填 `db_name = {SITE_DB_PREFIX}{code}`（例如 `code=vendora` → `base_site_vendora`，前綴見 `.env` 的 `SITE_DB_PREFIX`）。
   - `CREATE DATABASE IF NOT EXISTS {db_name} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`。
   - 動態註冊連線設定（見 6.4）。
   - 產生站點設定檔 `storage/app/sites/{site_id}.php`，並更新 `storage/app/sites/sites.json` 對照表。
   - 在新站點自己的資料庫執行公版 migration 與 baseline seed（見 13）；baseline seed 固定會建立該站的**超級管理員**帳號（7.1 的 `is_system=true`，供 6.11 的主站跳轉登入機制與一般開發除錯使用）**與一組初始「系統管理員」帳號**（7.1 的 `is_delegation_root=true`，密碼隨機產生，交付客戶時另外提供並要求首次登入強制改密）——這組帳號是交付客戶後，客戶唯一能從零開始建立自己整套權限體系（開通端口存取、指派端口管理員）的起點，沒有這一步就沒有任何帳號能對新站點做「授權他人」這件事，見 7.1「授權上限」。
   - 寫入操作日誌（誰、何時、建了哪個站、來源複製站點）。
3. **「從既有子站複製」的複製範圍（已依需求確認）**：
   - 只複製**程式碼檔案**（該來源站點在 `app/Sites/{site_code}`、`resources/views/sites/{site_code}`、`resources/vue_src/src/site-overrides/{site_code}` 下的客製化覆寫檔案）與**資料庫遷移檔案**（`database/sites/{site_code}/migrations/`、`database/sites/{site_code}/seeders/`），複製到新站點對應的目錄，並把來源站點的公版 migration 一併視為新站點的起點。
   - **不**複製來源站點資料庫裡的實際執行期資料（業務資料、使用者資料）。
   - 新站點建立後，對「複製過來的 migration 檔案」（含其中的 seeders）在新站點自己的資料庫執行一次遷移，藉此重現來源站點客製化後的 schema 與 baseline／demo seed 資料，效果等同「套用來源站點已客製化的部分」，但資料本身是全新產生，不是搬移舊資料。

**安全性提醒（重要）**：即使決定全自動，也建議把「具 `CREATE DATABASE` 權限的帳號」與「一般查詢/API 用的帳號」分開——`.env` 除了現有的 `DB_USERNAME/DB_PASSWORD`（一般查詢用，低權限）外，另外新增 `DB_PROVISION_USERNAME/DB_PROVISION_PASSWORD`（僅建站流程使用，具建庫權限），只在 `site:create` 這條受保護、需主站管理員權限的流程中連線，降低一般請求路徑意外具備建/刪資料庫能力的風險。

**資源耗盡與失敗補償（重要）**：
1. `POST /api/site/sites` 僅限主站管理員角色呼叫，並掛上速率限制（例如 `throttle:site-create`），避免誤用或惡意呼叫短時間內建立大量資料庫，耗盡 MySQL 資源。
2. 建站流程改為非同步：API/CLI 先寫入 `sites` 記錄並將 `provision_status`（6.2）設為 `pending`，實際建庫／複製檔案／跑 migration 交給 Queue Job 執行，避免同步阻塞請求、也方便失敗重試。
3. `code` 欄位需有資料庫唯一索引，以唯一鍵約束防止同名並發重複建站，而不是只靠應用層「先查詢再寫入」（避免競態條件）。
4. 任一步驟失敗時，`provision_status` 設為 `failed` 並記錄失敗原因，**不自動靜默重試**；提供 `php artisan site:cleanup-failed` 指令供人工確認後清理（`DROP DATABASE` 未完成的站點、移除產生到一半的設定檔），避免留下孤兒資料庫。
5. 後台「子站管理」列表需顯示 `provision_status`，讓工程師能看到正在建站中／建站失敗的站點。

### 6.4 動態資料庫連線註冊（不需重啟／不需 config:cache）
沿用 nckuh_ems `SiteResolver` 的判斷邏輯，但改為真正動態注入連線設定（Laravel 官方模式，`stancl/tenancy` 等多租戶套件也採此法）。

**執行期不會查詢主站資料庫**：每站的連線設定檔是主站在上方建站流程「當下」就產生好、寫入同一份多站程式碼的 `storage/app/sites/{site_id}.php`（靜態 PHP 檔案）；子站請求進來時，`EnsureSiteContext` middleware 只是讀取這個已經落地的檔案，**不會**在請求過程中連線主站資料庫查詢子站設定。因此各子站資料庫之間、以及子站與主站之間，執行期完全互不相依，不存在「子站跨庫讀取主站」的情況。
1. 每站設定檔 `storage/app/sites/{site_id}.php` 回傳陣列（含 `site_id`、`code`、`site_type`、`domains`、`path_prefix`、`db.*`、`template_namespace`）。
2. 新增 `EnsureSiteContext` middleware，排在所有會存取資料庫的 middleware 之前：
   - 透過 `SiteResolver` 判斷目前站點。
   - 多站模式且非主站時，讀取對應 `storage/app/sites/{site_id}.php`。
   - `Config::set("database.connections.site_current", $mergedConfig)`；`DB::purge('site_current')`，讓應用層一律用固定連線名稱 `site_current`，不需要在 Model 裡處理實際 site_id。
   - 將 `SiteContext`（`siteId`、`siteType`、`templateNamespace`）綁定為容器單例，供全域使用。
3. 新增站點只需要在 `storage/app/sites/` 多一個檔案、`sites.json` 多一筆對照即可生效，不需重新部署或 `config:cache`。
4. **連線設定一律由 config 取得，禁止在執行期呼叫 `env()`（重要，實際踩過）**：`php artisan config:cache` 之後 Laravel 不會載入 `.env`，執行期的 `env()` 一律回傳 `null`。早期版本在 `SiteResolver`（單站模式）、`SiteProvisioningService`（寫站點設定檔）與各 CLI 指令裡直接用 `env('DB_HOST')`／`env('DB_DATABASE')`／`env('DB_USERNAME')` 組連線，正式環境（一定會開 `config:cache`）就會變成「空帳號、空資料庫」，症狀是 `SQLSTATE[HY000] [1045] Access denied for user ''@'localhost'`，而且是「首頁打得開、一登入就 500」這種最難查的形態（站台首頁不需要資料庫，登入才第一次碰 DB）。現在統一走 `App\Support\Site\SiteDatabaseConfig`（讀 `config('database.connections.mysql')`，開不開 `config:cache` 都拿得到正確值），要調整連線設定請改那裡，不要在別處新增 `env()`。

**部署模型限定（重要，非「跨站資料外洩」風險，而是 PHP 執行模型限制）**：上述 `Config::set` 屬於**單一 PHP 進程內的全域狀態**。在傳統 PHP-FPM（每個請求各自獨立進程，處理完即銷毀）下沒有問題，`site_current` 只在該次請求的進程生命週期內有效，各請求互不影響。但如果日後改用 Laravel Octane、Swoole 等**常駐進程／協程**模型，同一進程會依序或併發處理來自不同站點的多個請求，若沒有在每次請求結束後重置這個全域狀態，理論上可能讓後一個請求誤用前一個請求殘留的 `site_current` 設定。**目前設計限定在傳統 PHP-FPM（request-per-process）部署模型下**；若未來要導入 Octane，需要額外在每次請求結束時（`Octane::app()` 或對應的 after-request hook）明確重置 `site_current` 與 `SiteContext`，此為導入 Octane 前的必要前置工作，非本階段範圍。

### 6.5 路由層
- 主站（`site_id=0`）擁有獨立程式碼目錄 `app/Hq/{module}`（見 9），路由、Controller、Service、資料庫連線皆與子站完全分開，目前只提供站點管理（開站／停用／模塊開通）功能，無跨站聚合報表（見 6.1）。
- **主站與子站權限完全物理隔離**：由於 `app/Hq` 是獨立程式碼、獨立資料庫，其路由、Controller、`permissions`／`roles` 資料表都與子站完全分開，子站角色（即使勾選萬用字元 `api.*.*`）在資料庫層面就查不到、也呼叫不到任何主站 API，不需要靠「保留權限命名空間＋ seed 時不勾選」這種命名慣例去防呆——從架構上就不可能越權（詳見 7 節）。
- 子站路由沿用既有 `app/Api/{module}`、`app/Web/{portal}/{module}` 結構（見 9），差異只在於「用哪個資料庫連線」，不需要為每個站點複製一份路由檔（此為「覆寫既有邏輯」情境，見 9.2 第一點）。
- 若客製化子站需要「公版完全沒有」的全新 API 端點，路由與 controller/service 需獨立放置，見 9.2 第二點。

### 6.6 子站 Schema 同步策略（依決策：公版固定套用、客製化專屬路徑自動偵測）
1. `php artisan site:migrate {site_id}`：對單一站點固定執行公版路徑（`database/migrations`）的 `migrate --ansi`；**同時自動偵測**該站是否存在 `database/sites/{site_code}/migrations`（見 9 命名慣例），存在就一併套用，**不需要手動帶 `--path`**——客製化站點的資料庫結構是「公版 + 自己專屬」疊加而成，兩層本來就該一起同步，不用每次手動拼路徑、也不用記哪個站有沒有客製化 migration。只有在專屬 migration 不遵循這個慣例路徑的特殊情境，才需要明確帶 `--path=` 覆寫成單一路徑（此時不會再自動加上公版或慣例路徑）。
2. `php artisan site:migrate --all`：對**所有已啟用站點**（`is_enabled=true`，不分 `standard`／`customized`）逐一套用上一點的邏輯（可加 `--seed=` 一併跑 baseline seed）——公版部分不會因為某個站點有客製化就被排除在批次之外，該站的專屬 migration 存在的話也會一併自動套用，不會悄悄落後公版 schema、也不用另外為客製化站點手動下第二道指令。
3. 後台「子站管理」列表顯示 `last_migrated_at` / 是否落後於主框架最新 migration，方便追蹤哪些站點尚未同步。

### 6.7 客製化子站的程式碼組織
見 9.2；重點是客製化以「覆寫/擴展」疊加在共用代碼之上，不是各自維護一份完整複本。

### 6.8 單一客戶現場部署（Single-Tenant On-Premise 打包）
本節即 6.1 所述「單站模式」的產生方式：開發永遠在多站環境完成，多站環境下的某個客製化子站，可以透過本節流程固化匯出成獨立部署包，不存在「單站升級為多站」的反向路徑。情境：某客製化子站需要整套獨立部署到客戶自己的機房/伺服器。透過 `php artisan site:package {site_code}` 產生「單站部署包」（輸出到獨立目錄，不影響原開發庫）。

**兩種觸發方式（共用同一份實作）**：
- CLI：`php artisan site:package {site_code}`，輸出到獨立目錄（預設 `storage/app/packages/{site_code}`，可用 `--output=` 指定到任意路徑，例如 `D:\deploy\vendor_a`），不影響原開發庫。
- 主站後台（見 6.12）：「子站列表」每一列有「輸出部署包」按鈕（以預設資料夾輸出），「子站詳細」頁另有「單站部署輸出」區塊，可自訂輸出資料夾並顯示打包結果與注意事項。對應 API 為 `POST /api/hq/sites/{siteId}/package`（`hq.super_admin` + `throttle:site-package`，與開站限流同一套原則，見 4.2.2、6.3），request 欄位 `output` 為相對 `storage/app/packages` 的資料夾（可留空＝用站點代碼），回應附上 `output_path`、`file_count`、`size_bytes` 與 `notes`（依站點類型／Vue 建置模式產生的後續提醒）。

**兩條路徑共用 `App\Hq\Site\Services\SitePackageService`，不是各寫一套**：打包是一連串「哪些目錄要複製、哪些要排除」的規則，兩份實作一旦分岔，最糟的後果是某天從後台輸出的部署包悄悄夾帶了其他客戶的客製化程式碼，而 CLI 輸出的是乾淨的，卻沒有地方看得出來。也因為共用，後台點按鈕與下指令得到的部署包內容保證一致。

**輸出目錄已存在時一律擋下，不覆蓋既有部署包**（CLI 與後台行為相同）；要重新產生請先自行清掉舊目錄，或改用其他資料夾名稱（後台常見做法是帶日期，例如 `vendor_a_20260913`）。另外，只有 `provision_status=ready` 的子站可以輸出——建置中／失敗的子站連自己的資料庫都還沒好，打包出來的東西在現場也跑不起來。

**核心原則：保留客製化目錄結構，只做「篩選」不做「合併攤平」**——部署包的執行期程式碼結構與 SaaS 開發庫完全一致，只是把「多站點動態判斷」換成「固定寫死成這一個客戶」，這樣日後推公版更新只需覆蓋共用目錄，不會動到客戶的客製化檔案；現場工程師要繼續追加客製化，操作方式也跟 SaaS 開發模式完全一樣。

1. 打包邏輯（篩選，不合併）：
   - 複製全部共用程式碼：`app/Support`、`resources/views/{module}`、`resources/vue_src` 主體、`database/migrations`、`database/seeders` 等，原封不動。
   - **`app/Api`、`app/Web` 依模組購買狀態逐一篩選，不是整包複製**（`SitePackageService::copyModules()`，見 6.9 第 7-9 點）：核心模組 OR 這個站已購買（含 `depends` 遞移展開）才複製對應的 `app/Api/{目錄}`；模組宣告的 `menus[].portal` 推導出它在哪些端口還有 `app/Web/{Portal}/{目錄}` 衛星頁面，一併篩選——沒買的模組原始碼不會出現在部署包裡，基礎包一定在。`app/Web/Sso`、`app/Web/Front` 這類不對應任何模組登記、純骨架用途的既有目錄不受篩選影響，一律複製。**`app/Sites/{site_code}` 完全不受這套篩選影響**（見下一點）：客製化本來就等於客戶已經買了，不跟模組購買狀態掛鉤。
   - **不**複製主站專屬程式碼 `app/Hq` 與主站專屬 migration（`database/hq/`，見 6.1、9）：單站部署包只服務單一客戶，不需要站點管理功能。
   - 只複製**指定站點自己的**客製化目錄：`app/Sites/{site_code}/`、`resources/views/sites/{site_code}/`、`resources/vue_src/src/site-overrides/{site_code}/`、`database/sites/{site_code}/`。
   - **排除**其他客戶的客製化目錄：`app/Sites/{other_code}`、`resources/views/sites/{other_code}`、`resources/vue_src/src/site-overrides/{other_code}`、`database/sites/{other_code}` 等，一律不進入此客戶的部署包，避免不同客戶的客製化程式碼互相外流。
   - Controller/Service 覆寫解析（9.2 第一點）、全新 API 路由掛載（9.2 第二點）、View/Blade fallback（8.5）等執行期機制**原封不動保留**在部署包裡，跟 SaaS 模式邏輯完全相同，程式碼層級不需要任何特殊處理。
2. 「單機模式」的意思：`MULTI_SITE_ENABLED=false`，不需要 `SiteResolver` 判斷網域/路徑前綴、不需要 `site_current` 連線動態切換（見 6.1）——但**不等於拿掉客製化覆寫機制**。`SiteContext->templateNamespace` 改由設定檔直接寫死該客戶的 `site_code`（不再依網域/路徑動態判斷），override resolver 仍照常依這個固定值決定 resolve 共用類別還是 `App\Sites\{site_code}\...` 覆寫類別。
3. `.env`：匯出時**自動產生一份可直接使用的 `.env`**（以 `.env.example` 為底改寫，不是只給五行提示），已寫入 `MULTI_SITE_ENABLED=false`、`SITE_TEMPLATE_NAMESPACE={site_code}`、`SITE_ID`（原本在多站環境下的 `site_id`，不是固定 1，用來對應資料庫內以 `site_id` 標記的既有資料）、`DB_DATABASE`（該站資料庫名），並自動產生 `APP_KEY`、`JWT_SITE_SECRET`、`JWT_HQ_SECRET`（每個部署包各自一組，不沿用開發機密鑰），`APP_ENV=production`、`APP_DEBUG=false`。現場只需調整 `DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD`、`APP_URL`。
   - 早期版本只輸出一個 `.env.package` 提示檔（內容僅 5 行），現場得自己拼 `.env`，結果是最容易漏掉 `APP_KEY`／`JWT_SITE_SECRET` 這類「不填就 500、又不在提示檔裡」的設定，實際踩過（現場把 `root` 打成 `rot`，加上缺 `APP_KEY`、沒有 `storage/` 目錄，頁面直接掛掉）。改為直接產生完整 `.env` 後，這一類問題不會再發生在匯出流程這一段。
4. Vue 前台建置：沿用該站點在 SaaS 模式下原本所屬的層級（8.1）建置，不需要為了「打包」另外設計一套建置方式：
   - 原本是 Tier 2（共用建置＋動態掛載，8.3）：部署包一樣跑共用 `php artisan vue:build --clean`，客製頁面仍靠 `custom_routes` 動態掛載。
   - 原本是 Tier 3（獨立建置，8.4）：部署包跑 `php artisan vue:build --site={site_code} --clean --output=public/vue`（現場只服務一個客戶，直接輸出到 `public/vue` 即可，不需要 `public/vue-sites/{site_code}` 這層區分）。
5. Migration 執行（保留「共用 + 站點專屬」兩層分開執行，與開發庫策略一致，見 6.6）：
   ```powershell
   php artisan migrate --ansi
   php artisan migrate --path=database/sites/{site_code}/migrations --ansi
   ```
   若該站點有專屬 baseline/demo seed，比照 6.6 用 `db:seed --class=` 個別執行。
6. 好處：
   - 日後主框架公版更新，只需要覆蓋共用目錄部分的檔案，客戶自己 `app/Sites/{site_code}`／`site-overrides/{site_code}` 底下的客製化檔案不會被誤蓋掉。
   - 現場工程師若要繼續對該客戶追加客製化，直接在對應客製化目錄加檔案即可，跟 SaaS 開發模式操作方式一致，不需要另外學一套「攤平後」的維護方式。
7. 限制與提醒：
   - `site:package` 是「篩選必要目錄＋固定站點識別碼」，不做程式碼合併，執行速度快、邏輯單純，但仍需注意共用程式碼裡不能有寫死判斷其他 `site_code` 的例外邏輯（若有應改用 override resolver 處理）。
   - 打包前建議先在開發庫用 `site:migrate {site_id}` 驗證過該站點兩層 migration 皆可正常執行，避免到現場才發現遷移檔缺漏。
8. 匯出包除了程式碼，還會一併產生「讓它當場可跑」的配套（早期版本只複製程式碼，匯出物其實只是原始碼備份，現場必定撞牆）：
   - **`storage/` 目錄骨架**：`storage/app`、`storage/app/private`、`storage/app/public`、`storage/framework/{cache,cache/data,sessions,testing,views}`、`storage/logs`（含 `.gitignore`）。Laravel 沒有這些目錄無法運作（Blade 編譯、log、session、cache 全都要落地），而開發庫的 `storage/` 內容（編譯過的 Blade、舊 log、上傳檔案）本來就不該帶到客戶機房，所以只建空目錄。
   - **清空的 `bootstrap/cache`**：開發機的 `packages.php`／`services.php`／`config.php`／`routes-*.php` 是「當時那台機器 vendor 內容」的快照，現場 `composer install` 後不一定相同，帶著跑會載入不存在的 provider，一律清掉（目錄與 `.gitignore` 保留）。
   - **`DEPLOY.md`**：現場部署步驟說明書（環境需求、`composer install`、目錄權限、migrate、Tier 3 的 `vue:build`、快取指令、上線後換密碼），另附一節「常見錯誤排查」記錄實際遇過的症狀與處理（缺 `storage/`、空 `APP_KEY`、`DB_USERNAME` 打錯、`bootstrap/cache` 不可寫導致 `rename() 存取被拒` 而 500、首頁 302 到 `/admin` 是正常行為）。內容維護在 `resources/deploy/DEPLOY.md` 範本，匯出時置換站點資訊，維運要改說明直接改範本即可，不必動程式。
   - **一個實測踩到的坑**：`php artisan optimize:clear` 會連帶刪掉 `bootstrap/cache/packages.php`／`services.php`，這兩個檔案由「第一個進來的 HTTP 請求」即時重建——目錄不可寫就直接 500；Windows 上若同時多個請求一起重建，`rename()` 會被佔用而失敗（症狀是 500 加上 `存取被拒 (code: 5)`，緊接著的連鎖反應是 `Class "view" does not exist`，很容易誤判成 view 設定問題）。因此部署建議順序是 `composer install`（自動產生 manifest）→ 快取指令 → 才開放存取；若確實需要 `optimize:clear`，清完補一道 `php artisan package:discover`。已寫入 `DEPLOY.md` 第 7、7.1 節。
   - **`.env.example`**：一併複製，現場可對照有哪些可調整的設定。
   - **模塊開通清單 `storage/app/site_modules.php`**：這個部署包已開通的模塊代碼（核心模塊 + 已購買未停用者），由匯出時依主站資料庫產生，現場不需修改，內容見 6.9 第 6 點。
   - **共用層不得依賴 `app/Hq`**：單站部署包沒有主站程式碼，共用層若有 `App\Hq\...` 的執行期相依就會在現場直接 `Class not found`（實際踩過：登入成功後載入選單時 500）。目前以 `App\Support\Hq\HqPresence` 統一判斷並優雅降級（模塊清單改讀上述檔案、JWT 會話清理改清單一連線），新增共用程式碼時請比照，不要直接 `use App\Hq\...`。
   - **刻意不含 `vendor/` 與 `node_modules/`**：依賴由現場 `composer install`／`npm ci` 依 lock 檔還原，才能保證與開發庫一致，也避免跨作業系統的二進位差異。

### 6.9 模塊授權（依模塊購買、子站需個別開通）
本專案採模塊式開發（3.1），客戶依模塊購買，因此子站能使用哪些功能，除了角色權限（7）之外，還需要先經過「模塊是否已開通」這一層授權。

1. **`modules` 主表**（模塊登記清單，供主站後台勾選開通）：
   | 欄位 | 說明 |
   | --- | --- |
   | `code` | 模塊代碼，對應目錄名稱（`app/Api/{module}`），唯一 |
   | `name` | 顯示名稱（供後台勾選介面與商務溝通用） |
   | `description` | 模塊說明 |
   | `is_core` | 核心模塊（如系統管理、身份/角色/選單、System Config 本身）恆為開通，不進入可勾選清單，也不會被取消 |
   | `sort` / `is_enabled` | `is_enabled=false` 時該模塊對所有子站全域下架（例如尚未上線或已下架的模塊） |

   **這張表的資料來源是掃描，不是手寫**：每個公版共用模組在自己的目錄下放一份 `module.json`（`app/Api/{ModuleStudly}/module.json`），內容就是這張表＋依賴＋選單宣告的單一事實來源，見第 7-9 點。
2. **`site_modules` 中介表**（`site_id`、`module_code`、`enabled_at`、`disabled_at` nullable，`(site_id, module_code)` 唯一）：記錄哪個子站開通了哪些模塊；主站後台「模塊管理」頁面對照 `modules` 清單勾選，變更需寫入 15 節的審計日誌（誰、何時、對哪個站開通/關閉了哪個模塊）。
3. **防堵範圍（雙重保險，API 與選單都要擋）**：
   - **API 層**：新增 `EnsureModuleEnabled` middleware，模塊路由檔統一在最外層掛載（例如 `Route::middleware('module:order')->group(...)`），比對目前站點的 `site_modules`（或該模塊 `is_core=true`）；未開通直接回傳 403，統一錯誤碼（11.2 的 `2xxxx` 認證/授權分層新增，例如 `20016` 表示「模塊未開通」）。
   - **選單層**：`menus` 表新增 `module_code` 欄位（不完全依賴解析 `permission_code` 字串猜測所屬模塊，避免萬用字元或系統選單解析出錯）；`MenuService::getMenuForUser`（7.3）遞迴過濾時，除了原本的權限判斷，同時檢查該選單 `module_code` 是否已對目前站點開通，未開通則整支（含子選單）不顯示，即使使用者原本有權限也不例外。
4. **`permission:sync-api` 掃描時的核對**：掃描到的 API 若所屬 `module_code` 不存在於 `modules` 主表，需明確報錯中斷（比照 12.6 的錯誤處理原則，不可靜默略過），避免模塊寫好程式碼卻忘記登記，導致永遠無法被開通。
5. **模塊關閉不影響資料與 Schema**：`site_modules` 只控制 API／選單是否可被存取（見上方第 3 點），該模塊相關資料表仍隨公版 migration 正常建立與更新（見 13），已產生的業務資料也不會被清除或搬移；模塊關閉純粹是功能鎖定，重新開通後資料原封不動可繼續使用，不需要為此額外設計資料隔離機制。
6. **單站部署包（6.8）的模塊開通**：`modules`／`site_modules` 只存在主站資料庫，而部署包刻意不含 `app/Hq` 與主站資料庫；若共用層直接查這兩個表，單站環境會在「登入成功後第一次載入選單」時 `Class "App\Hq\SiteModule\Models\Module" not found`（實際踩過）。處理方式：
   - 匯出時把該站已開通的模塊代碼固化到部署包的 `storage/app/site_modules.php`（核心模塊 + 已購買且未停用的模塊，見 `SitePackageService::writeSiteModuleList`）。
   - `ModuleAccessService` 在偵測不到主站（`App\Support\Hq\HqPresence`）時改讀這份清單，因此 6.9 的「依模塊購買、子站需個別開通」在現場仍然成立，不會因為換成單站就整組解鎖。
   - `EnsureModuleEnabled`、`MenuService` 的判斷都走同一個服務，不需另外處理。
   - 選單管理的「模塊代碼」下拉在單站模式回傳空清單（該欄位本來就是選填），`module_code` 的登記核對一併跳過，避免選單管理在現場完全不能用。
7. **`module.json` 規格與掃描**（`App\Support\Module\ModuleRegistryService`）：只掃描 `app/Api/*/module.json`——**子站客製化模組（`app/Sites/{site_code}/Api/{module}`）刻意不在掃描範圍內**：客製化程式碼本來就等於客戶已經買了，不受這套購買篩選機制管轄，`module:make --site=` 產生的客製化模組也不會寫 `module.json`、不登記進這張表（見 18 第 2 點）。掃描（`modules` 主表怎麼來）跟購買（`site_modules` 哪個站買了什麼）是兩件事，不要混在一起：掃描只回答「產品線總共有哪些模組」。
   ```json
   {
       "code": "user",
       "name": "使用者管理",
       "description": "帳號建立與端口授權",
       "is_core": true,
       "sort": 3,
       "depends": [],
       "menus": [
           {"portal": "admin", "key": "users", "title": "使用者管理", "subtitle": "帳號建立與端口授權",
            "icon": "User", "path": "/users", "permission_code": "api.user.index", "group": "group-account", "sort": 0}
       ]
   }
   ```
   `code` 需與 `Str::snake(目錄名)` 一致，不一致時 `ModuleRegistryService::scanManifests()` 直接報錯中斷（比照第 4 點的錯誤處理原則）；`depends`／`menus` 見下方第 8、9 點。`php artisan module:sync` 掃描並同步進 `modules` 表——`SiteBaselineSeeder` 已經會在每次子站種子時自動呼叫同一套邏輯，日常開發不用手動下這支指令，只有「新增了模組但沒有重跑任何子站 seeder」時才需要。
8. **模組依賴（`depends`）**：一個模組可以宣告依賴哪些其他模組代碼，`ModuleRegistryService::dependencyClosure()` 負責遞移展開。`SiteModuleController`：
   - **開通（enable）**：自動把依賴模組一併開通（cascade），回應的 `cascaded_modules` 列出因此連動開通的模塊代碼，不靜默動作。
   - **關閉（disable）**：若這個站目前還有其他已開通、非核心的模組依賴它，直接拒絕並在錯誤訊息列出是哪些模組擋住，要求先關閉那些模組——不做連動關閉，避免把管理員沒有主動要求關閉的功能一起關掉。
9. **選單自動註冊（`menus` 宣告 + 端口聯動）**：一個模組可以宣告在哪些端口（`portal`）要有哪些選單項目（欄位對應 `menus` 表：`key`／`title`／`subtitle`／`icon`／`path`／`permission_code`／`group`／`sort`），對應「模組可能在多個端口都有頁面」——同一個模組的 `menus` 可以有多筆、分屬不同 `portal`。`group` 指向的分組節點本身仍是少數固定的手動種子資料（見 `SiteBaselineSeeder::seedAdminMenu()` 的 `group-account`／`group-system`），不因為模組而動態新增分組。`ModuleRegistryService::provisionMenusForSite()` 依「這個站目前完整的啟用模組集合」把宣告的選單項目 upsert 進**該站自己**的 `menus` 表，兩個時機會呼叫：
   - 子站種子（`SiteBaselineSeeder`）：新站建立當下只有核心模組，等同種進核心模組宣告的選單。
   - `SiteModuleController::enable()`：切到目標站的資料庫（`App\Support\Site\SiteDatabaseConfig::connection()`，config:cache 安全的寫法）後即時補註冊，新開通模塊（含連動開通的依賴）的選單立即生效，不用重跑 seeder。
   - `disable()` 不刪除選單列（比照第 5 點「模塊關閉不影響資料」），可見性本來就已經由 `MenuService` 的模塊開通檢查即時擋下。
   - **選單可見性本身不需要額外處理多端口**：`MenuService::buildVisible()` 呼叫的 `moduleAccess->isEnabledForSite()` 不分端口，同一個 `module_code` 不管掛在哪個 `portal_key` 底下的選單，開通/關閉都會一起生效，這件事在改版前就已經成立。

### 6.10 插件系統（案場設備／AD／SSO 整合）
本框架定位為軟體集成，同一套程式碼要在不同案場（子站）對接不同廠商、不同版本的設備或介面（門禁、考勤設備等），也可能遇到各案場不同的 AD／SSO 接入方式。採用插件（Plugin）架構：以「功能」為統一介面契約，「品牌／版本」為具體實作，子站透過設定選用要啟用哪一個，呼叫端永遠面對同一份輸入輸出格式，不需要因為換了廠商或案場而修改業務程式碼。

#### 6.10.1 三層命名：功能／品牌／版本
1. **功能（Function）**：這支插件要解決的整合類型，例如 `ad`（AD 對接）、`sso`（SSO 對接）、`access_control`（門禁設備）、`attendance`（考勤設備）。每個功能對應一份**契約介面**（Contract Interface）與一組標準輸入／輸出 DTO，放在 `app/Plugins/{Function}/Contracts/`，所有品牌／版本的實作都必須實作這個介面——呼叫端永遠依賴介面與 DTO，不依賴任何品牌專屬類別，換插件不必改業務程式碼。
2. **品牌（Brand）**：實際對接的廠商／系統，例如 `Microsoft`、`Ldap`、`Okta`、`Cas`、`Hikvision`。
3. **版本（Version）**：同一品牌不同 API／協議版本可能存在差異（例如客戶現場設備是舊版韌體），各自獨立一個實作類別，不與其他版本共用邏輯以免互相牽連。

目錄結構：`app/Plugins/{Function}/{Brand}/{Version}/{Function}Connector.php`，例如：
```
app/Plugins/
  Ad/
    Contracts/AdConnectorInterface.php    # 統一介面 + DTO（AdCredentials、AdAuthResult…）
    Microsoft/V2019/AdConnector.php
    Ldap/V3/AdConnector.php
  Sso/
    Contracts/SsoConnectorInterface.php
    Okta/V1/SsoConnector.php
    Cas/V2/SsoConnector.php
  AccessControl/
    Contracts/AccessControlConnectorInterface.php
    Hikvision/V1/AccessControlConnector.php
```

#### 6.10.2 插件註冊與子站選用
1. `config/plugins.php` 登記目前程式碼庫中所有可用插件（`功能 => [品牌_版本 => 實作類別]`），新增插件時透過腳手架指令（見 18）自動寫入；此清單是「程式碼庫有哪些插件」的唯一事實來源，不重複維護在資料庫。
2. 新增 `site_plugin_configs` 資料表（**存在子站自己的資料庫**，因為每案場的連線設定彼此獨立、可能含機敏資訊，見 6.4）：`function_code`、`slot`（預設 `default`，同一功能允許多組設定，例如同一站要接兩個不同 AD 來源）、`brand_version_key`（命名規則為 `{brand的snake_case}_{version}`，例如品牌 `Microsoft`、版本 `V2019` 對應 `microsoft_v2019`，需與 `config/plugins.php` 的 key 完全一致）、`config`（json，**需以 Eloquent `encrypted:array` cast 加密儲存**，比照 4 節安全規範）、`is_enabled`，`(function_code, slot)` 唯一。
3. `PluginManager::resolve(string $function, string $slot = 'default')`：讀取目前 `SiteContext` 對應站點的 `site_plugin_configs`，找出 `brand_version_key` 對應的實作類別，從容器解析並注入該筆設定，回傳的物件保證實作該功能的 Contract Interface。呼叫端統一寫法：`PluginManager::resolve('ad')->authenticate($credentials)`，不需要知道目前案場實際用的是哪個品牌/版本。若該站設定的 `brand_version_key` 已不存在於目前 `config/plugins.php`（例如插件被移除或改名），需明確拋出例外中斷（比照 12.6 的錯誤處理原則，不可靜默回傳 null 或跳過）。
4. 後台「插件管理」頁面：實際端點是 `GET /api/plugins`（已實作，見 6.10.7），不是本節原始設計草稿寫的 `GET /api/system/plugins?function=ad`。

#### 6.10.3 統一錯誤處理
各品牌實作內部若拋出廠商專屬例外（SDK 例外、HTTP 錯誤等），需在 Connector 內攔截並轉換為統一的 `PluginConnectorException`（區分 `CONNECTION_FAILED`／`AUTH_REJECTED`／`INVALID_RESPONSE` 等子類型），呼叫端只需要處理這組統一例外，不需要認識各廠商 SDK 的例外類別。

#### 6.10.4 config_schema 與動態表單（已實作）
每個 Connector 類別需提供靜態方法 `configSchema(): array`，描述該品牌/版本需要哪些設定欄位，鍵是欄位名（對應 `cfg_name`），值為 `{type, required, description, option?, default?}`（`type` 為 17.1 `SystemConfig::TYPES` 其中之一、`option`／`default` 規則同 17.1，`required` 目前僅供文件說明用途，尚未接到驗證規則，見 6.10.7 已知限制）。後台「插件設定」表單**不是自己動態產生一份表單元件**，而是啟用插件當下把 `configSchema()` 種進 `system_configs`（`cfg_module` 見 6.10.7 命名規則），直接複用 17.4 的 `SystemConfigEditor.vue`——插件設定跟核心系統設定共用同一套型別驗證／編輯 UI，不用為插件另外刻表單邏輯，這正是 17.4 開頭「可複用結構」設計意圖的實際落地案例。

#### 6.10.5 單站部署與插件
插件屬於共用程式碼（隨程式碼庫部署，不是站點客製化），`site:package`（6.8）打包時原封不動整包複製 `app/Plugins`，不因單一客戶只用得到其中一兩個插件就篩選精簡；該客戶實際使用哪個插件，仍由匯出時一併帶走的 `site_plugin_configs` 資料決定，與 6.8 既有「篩選不合併」的打包原則一致，不需要新增例外規則。

#### 6.10.6 驗證範例：`ad`/`ldap`/`v1`（已實測打通整條鏈路，含後台管理）
**這是一個驗證插件系統可行性的示範，不是真實案場需求**——真正要對接哪個品牌/版本的 AD、SSO、設備，待各案場需求明確後再實作（見 19 節第 4 點）。用 `php artisan plugin:make ad ldap v1` 產生骨架後補完：
- `app/Plugins/Ad/Contracts/AdConnectorInterface.php`：定義 `authenticate(array $credentials): array` 標準方法，`configSchema()` 回傳格式見 6.10.4。
- `app/Plugins/Ad/Ldap/V1/AdConnector.php`：用 PHP 內建 `ldap` 擴充套件實作（不需要額外 composer 套件），`ldap_bind` 失敗或連線例外一律攔截轉換成 `PluginConnectorException`（6.10.3），不讓呼叫端接到擴充套件原生的錯誤格式；`configSchema()` 回傳 `host`/`port`/`base_dn`/`bind_dn_template` 四個欄位定義。
- 已登記進 `config/plugins.php`：`'ad' => ['ldap_v1' => ...]`。
- **已可透過後台「插件管理」頁面完整操作**（見 6.10.7），不再只是 tinker/curl 層級的驗證：啟用 → 自動種出 4 筆 `system_configs`（`cfg_module=plugin:ad:ldap_v1`）→ 用 `SystemConfigEditor.vue` 填寫 `host` 等欄位 → 停用再重新啟用，已填的值原樣保留 → `PluginManager::resolve('ad')` 正確組出對應 `AdConnector` 實例並帶入解密後的設定值（已用 tinker 反射驗證建構參數內容）。`site_plugin_configs` 未設定時 `PluginManager::resolve()` 正確拋出「尚未設定插件」；對不可達的 LDAP 位址呼叫 `authenticate()` 會正確拋出 `PluginConnectorException`（而不是讓 ldap 擴充套件的原生錯誤外洩）。程式碼與後台操作路徑都保留作為往後新插件的參考範例。

#### 6.10.7 插件管理後台（已實作）
`app/Support/Plugin/PluginRegistry`：插件目錄掃描，供插件管理頁面列出「目前有哪些插件」，不是執行期解析（那是 `PluginManager` 的職責）。分兩條掃描路徑，**目前並存兩種機制，尚未強行合併成一套**（見下方「已知限制」）：
1. **`connector` 機制**（6.10.1-6.10.5 描述的標準插件骨架，例如 `ad`）：直接掃 `app/Plugins/{Function}/{Brand}/{Version}/{Function}Connector.php` 目錄取得「程式碼庫裡實際存在哪些插件實作」，不只看 `config/plugins.php`——`plugin:make` 目前不會自動寫入 config（見 18.2 該指令說明，只會提示要手動登記的那一行），只掃 config 會漏掉「已經寫好但還沒登記」的插件。掃到的每個品牌/版本額外標記 `is_registered`（是否也在 `config/plugins.php` 裡），未登記的在後台會顯示但無法啟用——磁碟上找得到不代表能用，登記本身是刻意保留的部署期人工確認步驟，不讓後台單靠掃到檔案就能執行任意找到的類別。啟用狀態存 `site_plugin_configs`（`function_code`+`slot='default'`，同時間只有一個品牌/版本生效）。
2. **`sso_driver` 機制**（既有的 SSO 對接，見 8.6）：SSO 的設定原本用 `system_configs`（`cfg_module='sso'`）獨立運作，沒有搬動；插件管理頁面把它也攤平進同一份目錄清單方便管理員瀏覽與編輯，但不強行套用 `connector` 機制那套 `site_plugin_configs`——啟用即等同把既有的 `sso_driver` 設定切到這個鍵值、`sso_enabled` 設為 `1`，停用則設為 `0`。`JwtExchangeSsoProvider` 額外補上 `configSchema()`（見 6.10.4 shape），插件管理頁面啟用時據此把 12 個 `sso_*` 欄位種進 `system_configs`（沿用既有的 `cfg_module='sso'`，不是 `plugin:sso:xxx`——刻意不搬動既有資料，見下方已知限制）。

**設定值存放**：`connector` 機制的模組代碼為 `plugin:{function}:{brand_version_key}`（例如 `plugin:ad:ldap_v1`），`sso_driver` 機制固定沿用既有的 `sso` 模組（`PluginRegistry::moduleCodeFor`）。`PluginManager::resolve()` 組裝 connector 建構參數時呼叫 `SystemConfigService::valuesForModule()` 讀這個模組底下的值（密碼類型自動解密），不再讀 `site_plugin_configs.config` 這個舊欄位（該欄位保留給尚未走這條新流程的舊資料相容，實際不再寫入）。

**API**：`GET /api/plugins`（目錄＋目前站點的啟用狀態，見 `PluginAdminService::catalog`）、`POST /api/plugins/{function}/{key}/enable`、`PUT /api/plugins/{function}/{key}/disable`。啟用時呼叫 `PluginAdminService::ensureConfigSeeded()`，只在「這個設定項還不存在」時才寫入 `configSchema()` 定義的預設值，已存在的設定項只同步 `cfg_title`/`cfg_type`/`cfg_option` 這些描述性欄位、`cfg_value` 一律保留原樣——啟用/停用可以重複操作，不會把使用者填過的值洗回預設值（已用真實 HTTP 請求測試：啟用 → 填值 → 停用 → 重新啟用，值正確保留）。

**前端**：`resources/vue_src_admin/src/pages/plugin/PluginList.vue`，依功能分類（`ad`/`sso`）列卡片，每張卡片列出底下的品牌/版本，一個開關切換啟用狀態、一個「設定」按鈕開對話框掛 `SystemConfigEditor`（見 17.4）指到該插件的 `config_module`。

**已知限制（刻意不在這次一併解決，見 19 節「有具體需求才擴展」原則）**：
- `connector`／`sso_driver` 兩套啟用機制目前並存，沒有強行合併成一套——SSO 的 `SsoManager`／`SsoLoginService`／`config/sso.php` 完全沒有改動，只是設定值的**展示位置**移到插件管理頁面，底層驅動解析邏輯仍是原本那一套，不經過 `PluginManager`。若日後真的需要讓 SSO 也支援多品牌同時並存、或走 `site_plugin_configs` 那套 slot 機制，屆時再依實際需求決定怎麼收斂。
- `configSchema()` 的 `required` 欄位目前只是文件說明，`SystemConfigService` 的驗證規則不會因為 `required=true` 就要求非空——這一格未來若有實際需求（例如某插件的某欄位真的不能留空）再擴充。
- 插件管理頁面固定假設每個功能只用 `slot='default'`，尚未提供多 slot（同一功能同時接兩組來源）的後台介面，`PluginManager::resolve()` 本身已支援帶 `$slot` 參數，只是後台操作介面還沒做。

### 6.11 主站管理員跳轉登入子站（Impersonation）
主站管理員（6 節）在「子站管理」列表可直接以目標子站的**超級管理員**身份（7.1 的 `is_system=true`）跳轉登入該子站後台，不需要另外持有或記住每個子站的帳密，用於現場除錯／技術支援。因主站與子站雖是同一份部署（同一個 Laravel 應用、多個資料庫連線，見 6.4），但使用者體系與資料庫彼此獨立，仍需要一套安全的跳轉機制，不能直接共用 session；此設計取代 7.2、19 節原先列為待明確的「跨站身份信任機制」。

1. **核發一次性交換碼**：主站管理員在「子站管理」列表按「跳轉登入子站」，可選擇要以哪個 `portal_key` 進入（預設 `admin`，方便的話也可以直接跳進 `vendor` 等其他端口除錯），呼叫 `POST /api/hq/sites/{site_id}/impersonate-token`（`app/Hq`，僅主站管理員角色可呼叫，body 含 `portal_key`）。服務層在同一個 Laravel 進程內，產生一組隨機交換碼存入 **共用（非站點專屬）cache**（key 比照 14 節命名規範，`hq:impersonation:{code}`），內容含 `site_id`、`portal_key`、`requested_by`（主站操作者 `hq_users.id`）、`issued_at`，**TTL 60 秒**；並**回傳子站落地頁的完整網址 `redirect_url`**（見下一點的網址組法），主站前端不需要自己拼網址。子站端的超級管理員帳號**不在核發階段查詢**——核發階段不連子站資料庫，由子站端兌換時查（見第 3 點）。
2. **導向子站落地頁**：主站前端用 `window.location.assign(redirect_url)` 導向 **`{子站網址}/hq-impersonate/callback?code={交換碼}`**（`app/Web/Impersonation/Callback`，Blade 落地頁，做法比照 8.6 的 `/sso/callback`）。`redirect_url` 的組法依該站的進站方式（見 6.1）：已綁定 `primary_domain` 的站用該網域（`{scheme}://{primary_domain}`），只有路徑前綴的站用「主站這次請求的網域 + `/{code}`」（兩者本來就是同一個 Laravel 應用，主機名稱相同）。路徑前綴站的落地頁路由是 `/{sitePrefix}/hq-impersonate/callback`，與 `/admin` 一樣兩種網址形態都有註冊，兩種進站方式都到得了。
   - 同一組交換碼另有一個**純 JSON 的兌換端點** `GET /api/hq-impersonate/exchange?code=`（`app/Api/Auth`），保留給指令碼／自動化測試使用；瀏覽器導頁**不要**用這一支（只會看到一頁 JSON，而且管理後台 SPA 的 JWT 存在 `localStorage`，光有 cookie 進不了後台）。兩個入口共用同一份兌換邏輯（`App\Support\Impersonation\HqImpersonationService`），避免驗證規則分岔。
3. **子站端驗證並核發正式登入**：`HqImpersonationService::redeem()` 驗證交換碼存在、未過期、**未被使用過**（讀取後立即從 cache 刪除，防止重放；即使後續因為站點不符而失敗，這組碼也一併失效，不讓攻擊者用同一組碼反覆試不同站點）、且交換碼內的 `site_id` 等於目前站點（防止拿著 A 站的碼跑到 B 站兌換）；驗證通過後依交換碼內的 `portal_key`，比照 7.2 登入規則第 1 步（超級管理員短路，不查 `user_portal_access`、不受 `admin_only` 限制）核發正式 JWT（寫入 `user_jwt_sessions`，並額外標記 `issued_via=hq_impersonation`），最後導向該端口後台首頁完成登入。失敗一律渲染 `resources/views/impersonation/error.blade.php`，訊息走 `lang/zh_TW/api.php`（`api.auth.*`），不洩漏內部細節。
   - **落地頁怎麼把登入交給子站後台 SPA（重要）**：子站管理後台是純 SPA，JWT 存在 `localStorage`（key `admin_access_token`，見 `resources/vue_src_admin/src/stores/auth.js`），落地頁只設 cookie 的話 SPA 一載入仍會被導去登入頁。因此落地頁（`resources/views/impersonation/landing.blade.php`）除了由 Controller 設定 **`HttpOnly` cookie**（`config('jwt.cookie_name')`，供 Blade 頁面與同來源 API 使用，見 8.6）之外，另用**帶 nonce 的 inline script** 把同一個 token 寫進 SPA 讀的 `localStorage` key，再 `location.replace()` 到後台（用 `replace` 而非 `assign`，避免使用者按上一頁又觸發已失效的兌換）。token 不經過網址參數，不會留在瀏覽器歷史或伺服器 access log 裡。`portal_key` 目前只有 `admin` 有對應 SPA，其他端口一律導向 `/{portal_key}`，等該端口自己的 SPA 建置好（見 9.1）會在同一條路徑接手。
   - 落地頁的 inline script／style 都帶 `Vite::cspNonce()`，符合 4.2.1 的嚴格 CSP（已實測：回應標頭無 `unsafe-inline`、頁面 nonce 與標頭一致）。
4. **審計與安全限制**：
   - 每一次核發交換碼與兌換登入都需寫入 15 節審計日誌（誰、何時、透過此機制登入了哪個子站）：核發寫主站 `audit_logs`（`impersonation.issue`），兌換寫**子站自己的** `audit_logs`（`hq_impersonation.login`，`context.requested_by_hq_user_id` 記主站操作者）。
   - 交換碼短效（60 秒）、一次性、綁定單一 `site_id`，過期或已用一律拒絕。
   - 僅主站管理員可觸發核發；子站端不對外公開任何「以子站身份直接產生交換碼」的入口，交換碼永遠是主站單向核發。
   - 子站後台介面對這種登入方式顯示明顯提示（已實作）：`GET /api/user/profile` 一併回傳本次 session 的 `issued_via`，子站管理後台（`AdminLayout.vue`）在 `issued_via=hq_impersonation` 時於版面頂部顯示警告列，避免操作人員忘記自己是用最高權限帳號在動作。

**已實作檔案對照（2026-09-14）**：
| 角色 | 檔案 |
| --- | --- |
| 主站核發交換碼＋組落地頁網址 | `App\Hq\Impersonation\Services\ImpersonationService`（`issue()`） |
| 主站 API | `App\Hq\Impersonation\Controllers\ImpersonationController`（`POST /api/hq/sites/{siteId}/impersonate-token`） |
| 主站前端 | `resources/vue_src_hq/src/pages/SiteDetail.vue`（核發後 `window.location.assign(redirect_url)`，並保留手動連結與交換碼供排查） |
| 子站共用兌換邏輯 | `App\Support\Impersonation\HqImpersonationService`（`redeem()`，API 與落地頁共用） |
| 子站落地頁 | `App\Web\Impersonation\Callback\Controllers\HqImpersonationCallbackController`、`app/Web/Impersonation/Callback/routes/web.php`、`resources/views/impersonation/{landing,error}.blade.php` |
| 子站 JSON 兌換 API | `App\Api\Auth\Controllers\HqImpersonationController`（`GET /api/hq-impersonate/exchange`） |
| 後台提示列 | `AuthController::profile` 回傳 `issued_via`、`resources/vue_src_admin/src/layouts/AdminLayout.vue` |

**實作踩到並修掉的坑（務必知道，見 8.6／11.1）**：cookie 承載 JWT 的 fallback 機制有個不明顯的前提——Laravel 送出的 cookie 一律經 `EncryptCookies` 加密，而 **`api` 群組預設沒有這個 middleware**，少了它，`/api/*` 請求讀到的會是密文、JWT 驗證永遠失敗（症狀：帶著落地頁剛設定好的 cookie 呼叫 `GET /api/user/profile` 固定回 401，但 Blade 頁面因為走 `web` 群組反而正常，很容易誤判成「cookie 沒設成功」）。已在 `bootstrap/app.php` 把 `EncryptCookies` 補進 api 群組前段（Sanctum 的 `statefulApi` 也是同一種做法），這同時修好了 8.6 SSO 落地頁原本就存在的同一個問題。

**已實測（本機，實際 HTTP 請求 + cookie session）**：主站登入 → 核發交換碼（`redirect_url` 帶 `/default/` 路徑前綴）→ 落地頁 200 且設定 `site_jwt` cookie、把 token 交付 `localStorage`、導向 `/default/admin`（該入口頁 200）→ 以 cookie 與以 bearer 呼叫 `GET /api/user/profile` 皆 200 且 `issued_via=hq_impersonation` → 同一組碼重複兌換 403 → 缺碼／無效碼皆有對應訊息（403）→ 拿 A 站的碼到 B 站兌換 403 → 未帶主站 JWT 呼叫核發端點 401；`redirect_url` 兩種組法（已綁定網域／只有路徑前綴）亦分別驗證；落地頁在嚴格 CSP 下無 `unsafe-inline`、nonce 一致。

### 6.12 主站自身的使用者與權限（精簡設計）
前面各節反覆提到「主站管理員」，但尚未定義主站自己怎麼登入、帳號存在哪——這裡補上。主站的功能範疇很窄（開站/管理/停用子站、模塊開通、跳轉登入子站），使用對象僅限我方維運/工程團隊，不對客戶開放，因此**不套用 7 節那套身份別／端口／角色的完整模型**，改用精簡設計：

1. 新增 `hq_users` 資料表（**存在主站自己的資料庫**，見 6.1）：`id`、`username`（登入用，唯一）、`name`、`email`（選填聯絡資訊，**不用於登入**，見下方登入規範）、`password`、`is_super_admin`（`true` 表示可執行高風險操作，如 `site:create`、跳轉登入任一子站、模塊全域下架；`false` 為一般主站操作人員，僅能查看子站列表/報表，不能執行高風險操作）、`is_enabled`、`last_login_at`。
2. 沒有跨端口概念，登入即進入主站後台唯一入口；`hq_users` 也走 JWT（比照 11.1 的機制，但獨立的 `hq_user_jwt_sessions` 表，不與子站的 `user_jwt_sessions` 混用，因為根本是不同資料庫）。
3. 6.3（`site:create`）、6.8（單站部署包輸出）、6.9（模塊開通/下架）、6.11（跳轉登入）等「僅主站管理員可呼叫」的動作，一律要求 `hq_users.is_super_admin=true`；未來若主站功能變多、需要更細的分工，再擴充角色顆粒度（列入 19 節），現階段不做過度設計。
4. 主站資料庫首次建置需 seed 至少一個 `is_super_admin=true` 的帳號，否則沒有人能執行第一次 `site:create`；帳密來源見下方「登入規範與初始帳號」，不寫入版本控制。

#### 主站後台頁面（已實作）
主站後台是一套**獨立建置的 Vue3 SPA**（跟子站前台的 SPA 完全分開的第三套建置，因為主站沒有多租戶概念，不需要子站那套依站點切換輸出路徑的機制）：
- 原始碼：`resources/vue_src_hq/`；建置設定：`vite.hq.config.js`；指令 `npm run hq:dev`（開發）／`npm run hq:build`（建置，輸出到 `public/hq-assets`，`base` 固定為 `/hq-assets/`，避免跟下方頁面路由 `/hq` 撞路徑，見 12.1 `/docs` 那個 PHP 內建伺服器目錄撞名坑的同一個教訓）。
- 頁面路由：`GET /hq`、`GET /hq/{any}` 由 `App\Support\Http\Controllers\HqSpaController` 回傳同一份編譯產物首頁，瀏覽器內的 Vue Router（history 模式）接手處理 `/hq/login`、`/hq`（子站列表＋開站表單＋每列的「輸出部署包」捷徑）、`/hq/sites/{id}`（子站詳細資料、綁定網域設定、模塊開通開關、單站部署包輸出、跳轉登入交換碼核發）這幾條子路徑。
- 身份驗證：JWT 存在瀏覽器 `localStorage`（不是 cookie，因為是純 SPA、每次 API 呼叫都主動帶 `Authorization: Bearer` header），路由層有登入守衛，未登入一律導回登入頁；401（token 失效/被新登入取代）會清空本地憑證。
- **跳轉登入已可完整走完**：核發交換碼後前端直接導向子站落地頁（`{子站網址}/hq-impersonate/callback?code=`），子站端兌換、交付 SPA、導向該端口後台首頁，詳見 6.11（含檔案對照與實測紀錄）。
- 已用 Playwright 實測完整流程（登入 → 子站列表 → 子站詳細/模塊開關 → 核發交換碼 → 登出）、確認編譯後產物在嚴格 CSP 下零主控台錯誤，符合 4.2.1 第 8 點的編譯後驗證要求。

#### 登入規範與初始帳號
- **全站（主站與所有子站）登入一律用帳號（`username`）+ 密碼，不使用 email。** `email` 欄位（`hq_users`、子站 `users` 皆同）保留作為選填聯絡資訊，不參與登入判斷、不要求必填。
- 超級管理員（7.1）、主站初始管理員（本節）這類**我方內部帳號**，帳密統一在 `config/initial_accounts.php` 定義（實際值來自 `.env` 的 `HQ_ADMIN_USERNAME`/`HQ_ADMIN_PASSWORD`、`SITE_SUPER_ADMIN_USERNAME`/`PASSWORD`），**每次建站都套用同一組帳密**，不是每站各自隨機產生一組——原因是這些帳號本來就是我方自己要用的固定憑證，隨機產生只會變成要到 console 輸出裡翻密碼、還不能重來（見下一點）。
- **三組帳號的預設帳號名稱與覆寫方式**（沒在 `.env` 設對應的 `_USERNAME` 就套用預設值，設了就一律以設定值為準）：
  - `hq_admin`（主站管理員）與 `site_super_admin`（子站超級管理員，我方內部帳號，每個子站資料庫都有一個）預設都是 **`admin`**——同一個慣例名稱，不用因為是主站還是哪個子站的資料庫就記不同帳號。
  - `site_delegation_root`（子站系統管理員，交付客戶用來建立自己整套權限體系的帳號）預設用**該子站自己的代號**（例如 `code=vendora` 對應 `username=vendora`），讓帳號本身就能識別是哪一站，不會每個子站資料庫裡都是同一個 `admin` 帳號、只能另外對照資料庫名稱才知道是哪一個；實際計算見 `database/seeders/SiteBaselineSeeder.php` 的 `currentSiteCode()`（從 `site_current` 連線的資料庫名反推站代碼，單站模式則回退 `SITE_TEMPLATE_NAMESPACE`，跟 `SiteResolver` 的判斷邏輯一致）。
  - 注意：`site_delegation_root` 的**密碼**目前仍是 `.env` 單一固定值、每個子站共用同一組（跟前兩者一樣），只有帳號名稱改成依站點區分，這點是既有設計、非本次調整範圍。
- 密碼**沒有預設值**：`.env` 沒設定、且帳號**尚未建立**時，seeder（`HqDatabaseSeeder`、`SiteBaselineSeeder`）直接拋例外中斷，不會用隨機密碼糊弄過去，呼應 4.2 節「嚴格優先、及早失敗」的原則——本機開發前務必先在 `.env` 設好這三組密碼。
- **帳號建好之後，建議把 `.env` 裡的明文密碼清空**，避免這組固定憑證留在檔案裡外流。清空後重跑 `php artisan db:seed --ansi` 時，seeder 偵測到密碼是空字串、但帳號**已經存在**，只會沿用現有密碼（不重置、不報錯），其餘欄位（是否啟用、姓名、身份別）照常同步——只有帳號還不存在的第一次建置情境才會要求密碼、沒設就中斷。

---

## 7. 身份別／端口／角色／權限／選單系統設計

身份別（Identity）與端口（Portal）是兩個獨立的維度：**身份別是「這個人是誰」的分類，一人僅一種**；**端口是「這個人能進入哪些系統入口」，一人可以同時進入多個端口，且每個端口各自獨立設置權限（角色）**。舉例：同一人身份別是「正職員工」，但可能同時被開通了「工務室端」與「財務系統端」兩個端口，在這兩個端口各自被指派不同角色、擁有不同權限；財務系統可設定為僅開放端口管理員登入（見 7.2 的 `admin_only`），其他一般使用者即使有帳號也無法進入（此處僅說明機制，不代表本框架要設計財務端口）。

**名詞辨析（四種容易混淆的「管理員」，務必分清楚）**：

| 角色 | 所在資料庫 | 是否隱藏 | 實際存取範圍 | 授權他人的能力 |
| --- | --- | --- | --- | --- |
| **超級管理員**（`identity_types.is_system=true`，見 7.1） | 子站自己的資料庫（每個子站各有一份） | 是，對其他身份別完全隱藏 | 該子站所有端口、所有功能，不受任何限制 | 不受授權上限限制（7.1） |
| **系統管理員**（`identity_types.is_delegation_root=true`，見 7.1） | 子站自己的資料庫 | 否 | 依一般規則判斷（`user_portal_access`），**不會**因為這個身份就自動擁有任何端口的存取權 | 不受授權上限限制（7.1），可指派任何端口的角色／端口管理員身份給任何使用者 |
| **端口管理員**（`user_portal_access.is_portal_admin=true`，見 7.2） | 子站自己的資料庫 | 否 | 僅所屬端口，該端口內全部權限 | 受授權上限限制，但因已持有端口全部權限，等同可自由授權端口內任何角色（含指派其他端口管理員） |
| **主站管理員**（見 6 節） | 主站自己的資料庫（`app/Hq`，與子站資料庫完全獨立） | 否 | 主站的站點管理功能（開站／停用／模塊開通），與子站的權限體系無關 | 不適用，屬於不同的權限體系 |

### 7.1 身份別（Identity）：由寫死 enum 改為可設定
新增 `identity_types` 資料表（取代 nckuh_ems `IdentityType` 常數類）。每個子站預設有三種身份別（seed baseline，之後可依業務需求再擴充分類，例如 `vendor`、`volunteer`）：

| 欄位 | 說明 |
| --- | --- |
| `code` | 身份分類代碼 |
| `name` | 顯示名稱 |
| `is_system` | **超級管理員（我方隱藏身份）專用旗標**：每個子站唯一一種身份把此欄位設為 `true`，供我方工程師登入使用；`PermissionService::can()` 對此身份直接短路回傳允許、不查角色權限、不受任何端口存取限制（見 7.4），且此身份對其他身份別**完全隱藏**（見下方「身份分層」第 3 點），一般客製化開發不應該再新增第二個 `is_system=true` 的身份別 |
| `is_delegation_root` | **系統管理員專用旗標**：交付給客戶的最高管理帳號。此身份在**授權他人**（指派 `user_portal_access` 的角色或端口管理員身份，見 7.2）時，不受下方「授權上限」規則限制，可以指派任何端口的任何角色／端口管理員身份給任何使用者——這是它存在的核心用途：作為交付後客戶自己建立起整套權限體系（端口管理員、一般使用者角色）的起點帳號，不需要我方在資料庫手動塞資料。**但這個旗標只放寬授權他人的動作，不放寬它自己的實際存取權**：系統管理員要自己使用某個端口，一樣得先透過這個授權超能力把自己指派進該端口的 `user_portal_access`（見 7.2 範例）。一般客製化開發不應該再新增第二個 `is_delegation_root=true` 的身份別。 |
| `sort` / `is_enabled` | |

`users` 表新增 `identity_type_id`（單一外鍵，非多對多）：**一個帳號僅屬於一種身份別**。

#### 身份分層（超級管理員／系統管理員／一般使用者）
1. **超級管理員**（`is_system=true`，我方保留、隱藏身份）：預設擁有所有權限、可進入所有端口（`PermissionService` 短路，不受角色權限表與端口存取限制，也不受下方 `admin_only` 限制），供我方維運使用，不開放給客戶。
2. **系統管理員**（`is_delegation_root=true`，交付給客戶）：不隱藏、一般使用者管理介面可見；本身的端口存取依 7.2 一般規則判斷（預設什麼端口都進不去），差異只在於它可以無視「授權上限」，把任意端口的角色或端口管理員身份指派給任何使用者（含指派給自己）。例如財務系統設定 `admin_only=true`（僅端口管理員可登入）時，系統管理員一樣被擋在外面，除非先用自己的授權超能力，把自己指派為該端口的 `is_portal_admin`。
3. **一般使用者**：無特殊旗標，身份別僅作分類用途，實際能進入哪些端口、在各端口的權限，完全由 7.2 的 `user_portal_access` 決定，與身份別無關（不會因為身份別是「員工」就自動能進某端口）。
4. **超級管理員的隱藏範圍（連身份別本身也隱藏，不只隱藏帳號）**：
   - 所有回傳 `identity_types` 清單的地方（身份別下拉選單、新增使用者頁面的身份別選項等 API），一律過濾掉 `is_system=true` 的紀錄，除非目前登入者自己就是超級管理員。
   - 使用者管理／查詢 API（列表、搜尋、詳情）需一併過濾掉 `identity_type_id` 屬於 `is_system=true` 身份別的帳號紀錄，非超級管理員無法看到、也查不到這些帳號存在。
   - 上述過濾邏輯集中在共用的 `IdentityTypeService`（例如提供 `visibleTo(User $viewer): Collection` 方法）與對應的 Eloquent Scope，各 Controller／Service 統一呼叫，不各自寫 `if` 判斷，避免遺漏造成隱藏身份外洩。

#### 授權上限（Delegation Ceiling）
- 一般規則：任何使用者要把角色／權限／端口存取授權給別人，只能授予自己目前已持有的權限子集，不可無中生有授予超過自己權限範圍的內容（避免權限提升）；`permission:audit-ceiling`（7.4）用於稽核既有資料是否曾出現逾越上限的情況。
- 例外：`is_system=true`（超級管理員）與 `is_delegation_root=true`（系統管理員）這兩種身份在**授權他人**時完全不受此上限限制。
- 此例外**只適用於「授權他人」的動作**，不影響這兩種身份「自己實際能不能存取某端口」的判斷——超級管理員因為 `PermissionService` 短路而永遠能存取所有端口；系統管理員沒有這層短路，一樣要走 7.2 一般的 `user_portal_access` 判斷（差別只在於它可以自己動手把自己加進去）。

**API 層的實際落地（角色／使用者端口存取管理，`/api/roles`、`/api/users/*/portal-access`）**：不是所有端口都會有自己的端口管理員（例如純前台端口），這類端口的權限勢必得靠後台統一管理才給得出去，因此「跨端口管理」不是只開放給超級管理員／系統管理員，而是三層情境並存：
1. **一般情況（不帶 `portal_id`）**：只作用在目前登入 session 所屬的端口，行為與過去相同。
2. **端口管理員跨到自己管理的其他端口（帶 `portal_id`）**：呼叫者本身是該目標端口的端口管理員即可（不需要目前 session 剛好登入在那個端口——一人可能同時是好幾個端口的管理員，見 7.2），但範圍就到此為止，不能再跨到自己不是管理員的端口。
3. **`is_system`／`is_delegation_root` 跨任意端口**：不受第 2 點限制，可以直接指定任何端口操作，不需要自己在該端口持有任何 `user_portal_access` 紀錄——這正是「授權他人不受上限」的具體實現。

判斷「能不能操作目標端口」與「在目標端口能不能做這個動作」是兩層分開的檢查（`BaseApiController::resolveTargetPortalId`／`authorizeOnPortal`），且**這兩支 Controller 的路由刻意不掛 `permission.api:{code}` middleware**——該 middleware 只認得「目前登入 session 的端口」，沒辦法處理「目標端口可能跟登入端口不同」的情境，改成在 Controller 內部先解析出目標端口、才用該端口判斷權限碼，找不到目標端口的權限就直接 403，`is_system`／`is_delegation_root` 略過這層逐碼檢查（否則系統管理員對自己毫無 `user_portal_access` 的端口，永遠會被擋下來，跟本節例外矛盾）。實際被授權的權限碼／角色，一律經 `PermissionService::canAll()`／`isPortalAdmin()` 檢查是否落在建立者/授權者自己持有的範圍內（一般規則），`is_system`／`is_delegation_root` 才整段跳過。

### 7.2 端口（Portal）與端口存取：一人可跨多端口，各端口權限獨立
新增 `portals` 資料表（取代原本掛在身份別上的 `portal_key`）：

| 欄位 | 說明 |
| --- | --- |
| `portal_key` | 對應前端入口/端口（見 9.1），決定登入 API、選單樹、Vue Router base |
| `name` | 顯示名稱 |
| `admin_only` | **端口的基礎配置**：`true` 表示此端口僅允許 `is_portal_admin=true` 的使用者登入，一般已指派角色但非端口管理員的使用者即使 `user_portal_access` 紀錄存在也無法登入（例如財務系統只給財務主管、端口管理員使用）；`false`（預設）為一般模式，任何有 `user_portal_access` 紀錄的使用者皆可依角色登入。此開關建議僅開放主站管理員或系統管理員調整，避免端口管理員誤將自己鎖在門外 |
| `is_enabled` | |

`roles` 表新增 `portal_id`：角色必須屬於某一端口，不可跨端口共用，對應「端口 -> 角色 -> 權限」的層級設計（取代原本角色掛在身份別上的設計）。

新增 `user_portal_access` 中介表（`user_id`、`portal_id`、`role_id` nullable、`is_portal_admin`，`(user_id, portal_id)` 唯一）：
- 一筆代表「此帳號被開通了此端口」；**一個帳號可以同時在多個端口各有一筆紀錄**（例如同一人同時在工務室端與財務系統端各有一筆，角色各自獨立）。
- `is_portal_admin=true`：此帳號在**此端口內**擁有該端口全部權限（`PermissionService` 對此端口短路，`role_id` 可為 `null`），可以把角色或端口管理員身份授權給該端口的其他使用者（受上方「授權上限」規則限制，但因已持有端口全部權限，等同可自由授權端口內任何角色）；範疇僅限該端口本身，不能跨端口操作（與 7.1 的「超級管理員」`is_system=true`、「系統管理員」`is_delegation_root=true` 是完全不同層級，也與 6 節的「主站管理員」不同——參見本節開頭的名詞辨析表）。
- `is_portal_admin=false` 的一般使用者，`role_id` 為必填。

**登入規則**：使用者依「進入哪個端口（`portal_key`）」登入，即使是超級管理員也必須指定要進入哪個端口（沒有『不選端口直接登入』這回事，因為 JWT 必須帶有效的 `portal_id`，見下方）：
1. **先判斷身份別是否為 `is_system=true`（超級管理員）**：是的話直接短路核發該端口的 JWT，**不查 `user_portal_access`**（超級管理員本來就不會有這張表的紀錄），不受下方任何一步限制。
2. 非超級管理員，依帳號 + 該 `portal_key` 對應的 `portal_id` 查出 `user_portal_access` 紀錄：沒有紀錄一律拒絕登入（回傳明確錯誤碼，不可 fallback 到其他端口；系統管理員 `is_delegation_root=true` 若尚未指派自己該端口的存取權，也會在這一步被拒絕，見 7.1「授權上限」範例）。
3. 該端口 `admin_only=false`（一般模式）：有紀錄即可登入，依 `role_id` 或 `is_portal_admin` 判斷後續權限。
4. 該端口 `admin_only=true`（僅端口管理員）：只有 `is_portal_admin=true` 才能登入；`is_portal_admin=false` 即使有 `role_id` 也一樣拒絕登入（回傳錯誤碼，例如 `20017` 表示「此端口僅開放端口管理員登入」）。

JWT payload 需帶入本次登入使用的 `portal_id` 與 `role_id`（`identity_type_id` 可選擇性帶入供顯示用途，不參與權限判斷），同一 session 內的權限判斷（7.4 `PermissionService`）與選單（7.3）皆以此為準；同一帳號要切換使用另一個端口，需另外登入該端口取得新的 JWT，不在同一個 session 內混用多端口權限。

**主站管理員的跨站進入方式**：主站管理員（6 節，主站 `app/Hq` 自己的使用者）需要進入任一子站（例如上方財務系統的例子）時，走 6.11 的「跳轉登入」機制、以該站**超級管理員**身份進入，超級管理員不受 `admin_only` 限制（見上方第 4 點），不需要另外為主站管理員設計一套跨站權限對應規則。

### 7.3 選單管理（取代 nckuh_ems 寫死的 `config/menus.php`）
新增 `menus` 資料表：`id`、`portal_key`、`module_code`（nullable，供 6.9 模塊開通判斷用；系統層選單可留空）、`parent_id`、`key`、`title`、`subtitle`、`icon`、`path`、`permission_code`（nullable）、`sort`、`is_enabled`。後台提供「選單管理」頁面可調整順序、顯示/隱藏、綁定權限碼；`admin_only` 端口（7.2）的選單管理僅開放主站管理員、系統管理員或該端口自己的 `is_portal_admin` 操作。

`MenuService::getMenuForUser(User $user)`：
1. 依當前 session 的 `portal_id` 取出對應選單樹（見 7.2，一人跨多端口時以本次登入所屬端口為準，不會混合多個端口的選單）。
2. **可見性以「該頁面涉及的 API」判斷，不是靠人工在選單上手動綁一個權限碼**：葉節點若綁了 `module_code`，取出 `permission:sync-web` 已掃到的該模塊全部 `web.*` 權限碼（該頁面的入口/檢視/新增/編輯/刪除等任一支 API），只要 `PermissionService::canAny()` 對其中任一碼回傳可通過，該選單就顯示——不需要頁面上每個動作都個別有權限才能看到入口，只要有其中一種操作權限即可進入頁面（`canAny()` 內部已含 `is_system`／該端口 `is_portal_admin` 短路，不需要 `MenuService` 自己再判斷一次）；若額外綁了 `permission_code`，併入同一組候選碼一起判斷。葉節點完全沒有 `module_code` 也沒有 `permission_code`（沒有任何可判斷的權限來源）一律不顯示，避免忘記綁定造成越權外洩。**例外：`login_only=true` 的葉節點完全跳過這個判斷，只要登入這個端口就一定顯示**（`module_code` 開通檢查仍然照跑，若有填的話）——給像「儀表板」這種每個人都該看到、不對應特定業務權限的節點使用，`SiteBaselineSeeder::seedAdminMenu()` 已用這個欄位把儀表板設成頂層、`sort=0`、任何角色都看得到（見下方實測紀錄）。父節點（選單分類）可見性 = 是否至少有一個子節點可見（沿用 nckuh_ems `MenuService::filterMenuByPermissions` 邏輯，子選單只要有任一項權限，父選單就能看到）；父節點若自己也綁了 `module_code`／`permission_code`，一樣要通過 `canAny()` 才顯示，即使子節點可見也一樣；`login_only` 對父節點無意義（分組本身不是頁面）。`module_code` 另外檢查該模塊是否已對目前站點開通（6.9），未開通則整支（含子選單）不顯示，權限判斷通過或 `login_only=true` 也不例外。
3. 依 `user_id + portal_id + role_id` 做快取 key，避免每次登入都重新計算整棵樹（同一人不同端口的選單需分開快取，不可共用同一個 key）；子站開通/關閉模塊時需清除該站相關快取，避免選單顯示與最新模塊狀態不一致（實作見 `MenuCacheVersion`：因為預設 cache driver 不支援 tags，改用版本號做法，模塊切換時 bump 版本、快取 key 帶版本號，舊 key 靠自然過期回收）。

### 7.4 直接沿用 nckuh_ems 已驗證可行的部分
- `permission.code` 命名規則 `api.{module}.{action}` / `web.{module}.{action}`，含萬用字元 `api.{module}.*`。
- `permission:sync-api`、`permission:sync-web` 指令模式；`permission:audit-ceiling` 稽核既有角色／授權紀錄是否曾逾越 7.1「授權上限」規則（`is_system`／`is_delegation_root` 兩種身份的授權紀錄不受此稽核限制）。
- `PermissionService::canAny()` 短路優先序：`is_system`（全域短路）→ 該端口 `is_portal_admin`（端口內短路）→ 一般角色精確匹配 + 萬用字元兩段式判斷；查詢 `role_id` 的來源為當前 session 的端口角色（7.2），不再是 `users.role_id` 單一欄位。

### 7.5 角色權限指派介面：端口－模塊－接口三層勾選樹（已實作）
早期版本 `GET /api/roles/permission-catalog` 回傳全站不分端口的扁平權限碼清單，後台角色編輯頁面只能用一個超長的多選下拉框逐條勾選——權限碼一多（幾十筆）就很難找、也無法一次「整個模塊全給」，體驗上很不科學。改版重點：

- **`GET /api/roles/permission-catalog` 依「目前登入 session 所屬端口」過濾**：`domain=api` 的權限碼只保留標註 `@api-portal *` 或明確包含此端口的項目（跟 `permission:audit-ceiling` 判斷「跨端口洩漏」用同一套規則，見 7.4）；`domain=web` 的權限碼目前沒有可靠的端口關聯資料（`permission:sync-web` 一律存 `portal_keys=[]`，見該指令說明），一律不過濾、全部保留，避免誤判整批消失。回應同時多帶一個 `data.portal`（`id`/`portal_key`/`name`），供前端標示樹的根節點。
- **`RoleService::assertCodesExist()` 同時把這個過濾規則提前擋在寫入當下**：以前只檢查權限碼是否存在於目錄（抓打字錯誤），現在額外檢查該權限碼標註的允許端口是否包含這個角色所屬的端口，不符合直接擋下（`api.role.permission_code_wrong_portal`）——這是把 `permission:audit-ceiling` 原本只能事後抓的「跨端口權限洩漏」提前變成寫入時的硬性驗證，不用等離線稽核才發現。
- **前端（`RoleList.vue`）改成 `el-tree` 三層勾選樹**：根節點＝目前端口（勾選＝整個端口全給）－模塊節點（依 `module_code` 分組，勾選＝該模塊全給，並用一份中文對照表顯示模塊名稱）－葉節點＝個別接口（單一接口給）。三種顆粒度直接靠 el-tree 內建的父子勾選連動達成，不必另外刻「全選」邏輯。葉節點文字依 `code` 最後一段推斷增/刪/改/查/其他並上色標籤、同一模塊底下依此排序，讓同一資源的 CRUD 操作排在一起；另提供關鍵字篩選（模塊名／說明／權限碼）。送出時用 `getCheckedKeys(true)` 只取葉節點的實際權限碼，合成的 module/portal-root 節點 key 不會混入 `permission_codes`。
- 這套三層樹只在角色編輯情境下有意義（一個角色永遠只屬於一個端口，見 7.2），因此不提供「切換端口」UI——如果一個權限碼同時標註多個具體端口（目前全部 33+1 筆權限碼都只用 `@api-portal *`，尚未出現這種情況），會分別出現在各端口自己的樹裡，需要的話得個別勾選，非阻塞性的已知限制。

### 7.6 登入後首頁 Dashboard（已實作）

早期版本登入後直接寫死導向「角色管理」頁（`router/index.js` 的根路徑重導向），沒有真正的首頁；同時 `app/Web/Admin/Dashboard` 是框架初期示範 MVC／`permission:sync-web` 掃描機制留下的骨架（原始碼註解明寫「範例 Web 模組」，對應一個純文字 Blade 頁面），跟後來整個改用 Vue SPA 打造的實際管理後台完全無關，導致角色權限指派介面上看得到 `web.dashboard.entry` 這個權限碼，Vue 後台卻永遠點不到對應頁面——這是遺留示範程式碼沒清掉，不是功能缺失，但也順便暴露了「沒有真正首頁」這件事。

**處理方式**：直接把兩個問題一起解決，把 Dashboard 的概念搬進 Vue SPA，變成真正的登入後首頁：
- 刪除 `app/Web/Admin/Dashboard`（Controller＋`routes/web.php`）與 `resources/views/dashboard/`；`permission:sync-web` 重新掃描後自然不再產生 `web.dashboard.entry`，舊資料庫裡的殘留權限碼與角色引用需手動清一次（一次性，非常態機制）。
- `resources/vue_src_admin/src/pages/Dashboard.vue`：只做導覽用途的捷徑卡片（連到使用者管理／角色管理／選單管理／資料字典／系統環境配置／插件管理／端口管理），不放假的統計數字。
- 選單 `dashboard`（`SiteBaselineSeeder::seedAdminMenu()`）是頂層節點、`sort=0` 排最前面、`login_only=true`（見 7.3 第 2 點的例外規則）——不對應任何 `module_code`／`permission_code`，任何登入這個端口的人都看得到，已用一個 `permission_codes=[]` 的角色實測驗證（該角色登入後 `GET /api/site/config` 的 `menu` 只剩「儀表板」一項，其餘全部正確隱藏）。
- `router/index.js`：新增 `dashboard` 路由，根路徑重導向與「已登入卻訪問 `/login`」的重導向都改指向它；`Login.vue` 登入成功後的 `router.push` 與 `NotFound.vue` 的返回按鈕一併同步更新（這兩處是登入首頁邏輯散落在別處、容易漏改的地方，已實測踩過——只改 `router/index.js` 的預設值不會生效，因為這兩處各自寫死了 `{ name: 'roles' }`）。

---

## 8. 前台 Vue3 多站客製化策略（依決策：分層漸進，共用為預設、依客製化程度逐步升級）

考量到客製化需求深淺不一（從「幾乎沒差異」到「整套前台都不同」都可能發生），採用三層模式、依需求漸進升級，**同一份 `resources/vue_src/src/site-overrides/{site_code}/` 原始碼在三層之間共用**，升降級只差在「build 指令怎麼下、輸出到哪裡」，不需要因為客製化程度變深就重寫目錄結構或推翻既有站點的程式碼。

### 8.1 三層客製化模式與升降級路徑
| 層級 | 適用情境 | 編譯輸出 | 升降級成本 |
| --- | --- | --- | --- |
| Tier 1：純公版 | 前台完全沒有客製化差異（即使後端 `site_type=customized`，只要前台沒差異就不用升級） | 共用 `public/vue`，不含此站的 site-overrides | 無 site-overrides 目錄，天然就是這層 |
| Tier 2：共用建置＋動態掛載 | 只有少數幾個全新頁面／局部覆寫，不值得整套獨立建置 | 仍輸出共用 `public/vue`，客製頁面以 code-splitting chunk 動態載入（見 8.3） | 新增/刪除 `site-overrides/{site_code}` 內容即可，不影響其他站 bundle |
| Tier 3：獨立建置 | 客製化範圍大（整組頁面、路由結構都不同），或該站未來會走 6.8 現場單站部署 | 獨立輸出 `public/vue-sites/{site_code}`（見 8.4） | 同一份 `site-overrides/{site_code}` 原始碼改用 `vue:build --site={site_code}` 建置即可，不需搬移或重寫原始碼 |

- 每站目前屬於哪一層，記錄在 `storage/app/sites/{site_id}.php`（6.4）的 `vue_build_mode: shared|dedicated` 欄位（Tier 1/2 皆為 `shared`，差異只在有沒有 site-overrides 內容；Tier 3 為 `dedicated`）。SPA fallback 依此欄位決定回傳 `public/vue/index.html` 還是 `public/vue-sites/{site_code}/index.html`（對應 3.2 節「其餘前台 GET 路徑回傳 Vue 入口頁」的規則）。
- 判斷／切換層級是人為決策（工程師依客製化範圍決定），不做成前端自動判斷；`vue_build_mode` 只是紀錄目前建置方式，不影響 site-overrides 原始碼怎麼寫。
- 升降級都只是「換一種 build 方式 + 改一個設定值」，避免了「一開始選錯層級，後面就要整個重構」的風險。

### 8.2 執行期站點設定注入（三層皆適用）
- 子站主題／文案差異一律透過「執行期站點設定 API」注入，不因建置層級而不同：
  - `GET /api/site/config`（依目前 `SiteContext` 回傳）：logo、主題色、站名、選單（7.3，後端已動態產生）、頁尾文案、少量文案覆寫字典、`site_code`（見下方）。此端點本身不需要登入（SPA 啟動當下多半還沒登入）；若請求帶有效的子站 JWT，才一併帶入依當前 `portal_id`/`role_id` 算出的選單，沒帶或 token 無效則選單回空陣列，不因此讓整支端點失敗。
  - 前端啟動（`main.js`）時先 fetch 此設定寫入 store，套用為 CSS variables（主題色）與 favicon/logo。
- **Vue Router 的路徑前綴 base 由 `site_code` 決定，不是猜網址片段**：早期實作用 `/:sitePrefix?` 這種選填動態參數處理路徑前綴（見 6.1），已實測踩過坑——`/:sitePrefix?` 對任何單一路徑片段（包含打錯字的網址）都會比對成功，導致真正找不到的頁面永遠到不了 404 路由、網址打錯字只會看到首頁。正確作法：`main.js` 掛載 app 前先呼叫 `GET /api/site/config`（帶上 `X-Site-Path-Prefix` 候選值，見 6.1），比對回傳的 `site_code` 是否等於網址第一段，相符才代表「真的在用路徑前綴進站」，這時才把該片段設為 `createWebHistory(base)` 的 `base`；不相符（網域已綁定、或前綴打錯字回退預設站）則 `base` 固定為 `/`（或管理後台的 `/admin/`），路由定義本身完全不用處理前綴、也不用猜。子站前台（`resources/vue_src`）與子站管理後台（`resources/vue_src_admin`）都採用這套機制；主站後台（`resources/vue_src_hq`）沒有站點前綴概念，不受影響。
- Logo 等圖片資源走 System Config 的 `cfg_type=image` 上傳／裁切工具（17.5，非 10 節那套已暫緩上線的通用檔案管理模組），子站自行上傳，記錄存在各自站點資料庫的 `system_configs` 表對應欄位（`cfg_value` 存公開網址）。

### 8.3 Tier 2：共用建置＋動態掛載客製頁面
- Vue Router 保留「依 `site.config` 回傳的 `custom_routes` 清單，於啟動時 `router.addRoute()` 動態掛載」的機制，每筆對應一個 `import()` 動態載入的 chunk。
- 客製頁面原始碼集中放在 `resources/vue_src/src/site-overrides/{site_code}/`；因為是 code-splitting 的動態載入，未被路由到的站點不會載入這些 chunk，不影響其他站的 bundle 大小，仍是「一次 build」。
- 適用「客製化範圍很小」的站點；範圍變大、頁面/路由變多到難以維護時，升級為 Tier 3（8.4），原始碼位置不變。

### 8.4 Tier 3：獨立建置站點
- 適用客製化範圍大、或該站點未來會走 6.8 現場單站部署的站點；輸出目錄改為 `public/vue-sites/{site_code}/`，與共用 `public/vue` 完全分開，兩者互不影響、互不共用快取檔名。
- 建置指令：`php artisan vue:build --site={site_code} --clean`，邏輯為「共用 `resources/vue_src` 主體原始碼 + 該站 `site-overrides/{site_code}` 疊加合併」後單獨建置，輸出 base path 設為 `/vue-sites/{site_code}/`，確保資源相對路徑正確。
- 此「主體＋覆寫合併後建置」的邏輯與 6.8 節「現場單站部署打包」共用同一套合併實作，差別只在輸出目的地：SaaS 多站模式輸出到 `public/vue-sites/{site_code}`，現場單站部署則輸出到該客戶專屬部署包自己的 `public/vue`（見 6.8）。兩種情境用同一套合併邏輯，維護成本不會因為多一種部署形態而倍增。
- `php artisan vue:build --all-sites`：遍歷所有 `vue_build_mode=dedicated` 的站點逐一重新建置（CI/CD 用），不影響共用 `public/vue` 與其他站點的獨立建置。
- 靜態資源服務：Web Server／SPA fallback controller 依 `SiteContext` 解析出的站點讀取 `vue_build_mode`，決定回傳哪個目錄下的 `index.html` 與掛載哪個 base path 的靜態資源。
- 降級：若該站客製化需求減少，恢復用 Tier 2（甚至 Tier 1，直接清空 site-overrides）即可，共用 `public/vue` 不受影響。

### 8.5 Blade 樣板 fallback（對應 3.2 節的 Blade 混合模式）
- 若需要 Blade 渲染（如 SSO 跳轉頁），`EnsureSiteContext` middleware 依 `SiteContext->templateNamespace` 動態偵測 `resources/views/sites/{site_code}` 是否存在，存在才透過 Laravel View Finder 的 `setPaths()` 把它插入查找路徑最前面（`View::addNamespace` 建立的是另一個獨立命名空間，需要 view 呼叫端自己加 `sites::` 前綴才會用到，無法達到「同一個 view 名稱、依站點自動 fallback」的效果，故不採用）；該目錄不存在時完全不影響查找路徑，`view('sso.error')` 之類的呼叫照舊只查公版 `resources/views/{module}`，**不需要每個子站都先建一份空的繼承檔案才能跑**——這正是 8.1 Tier 1「無 site-overrides 目錄，天然就是這層」在 Blade 層的對應實作，新增一個公版頁面只需要寫共用程式碼本身，不用回頭幫每個現有子站補一份檔案。
- **裸網域根目錄導向管理後台**：目前前台（8.1）只是骨架頁面、沒有真正的業務內容，`SpaFallbackController`（`Route::fallback`，見 3.2 第 3 點）判斷這次請求路徑是不是「裸網域根目錄」——`/` 本身，或路徑前綴子站的 `/{code}` 本身、後面完全沒有其他路徑片段——是的話直接 302 導向管理後台（`/admin` 或 `/{code}/admin`），不回傳前台 SPA 的殼；其他任何路徑（包含前台自己的子頁面、或真正找不到的頁面）一律照舊回傳前台 `index.html`，交由前端路由決定要渲染實際頁面還是 404（8.2）。日後前台補上真正的業務頁面後，拿掉這段判斷即可還原成單純的 SPA 出口。

### 8.6 SSO 對接（Blade 落地頁，補足 3.2 第 1 點的實際登入機制）

**前提**：本專案預設前後分離、前台走 Vue3 SPA（8 節），JWT 走 `Authorization: Bearer` header。但對接 SSO 屬於會實際遇到的情境（見 3.2 第 1 點），SSO 系統通常是導頁而不是 AJAX 呼叫，瀏覽器直接導頁帶不了自訂 header，需要 Blade 落地頁 + cookie 承載身份，這是專屬於 SSO 情境的例外路徑，不影響 Vue3 SPA 的一般作法。

**每個案場的對接方式不同（協定、端點、憑證、欄位命名都可能不一樣），因此設計為「driver 可替換 + 設定按子站定義」，不是寫死一種串接邏輯**：

1. **驅動註冊表（程式碼層級，`config/sso.php`）**：`drivers` 陣列登記 `driver key => 實作 App\Support\Sso\Contracts\SsoProvider 的類別`，框架內建一種 `jwt_exchange`（`App\Support\Sso\Drivers\JwtExchangeSsoProvider`）——瀏覽器帶一次性 `code` 導頁過來，用該站設定的端點把 `code` 換成身份資料（回應可以是明碼 JSON，或需要驗簽的 JWT，見下）。案場若是完全不同的協定（SAML、純 Cookie 轉發…），另外寫一個實作 `SsoProvider` 介面的類別、在這裡註冊一個 key 即可，不需要改動呼叫端（`SsoLoginService`／`SsoManager`）任何程式碼。
2. **每站實際設定（案場資料，存在 `system_configs`，`cfg_module=sso`，見 17 節）**：`sso_enabled`（預設 `0`，關閉）、`sso_driver`（要用哪個已註冊的 key）、`sso_endpoint`（該案場的端點網址）、`sso_client_id`／`sso_client_secret`、`sso_response_format`（`json`＝端點直接回明碼身份資料／`jwt`＝端點回一個需要用 `sso_verify_key` 驗簽的 JWT）、`sso_field_mapping`（JSON，外部欄位名稱對應到 `external_id`／`username`／`name`，支援點記法）、`sso_portal_key`（登入後要進入哪個端口）、`sso_auto_create_user`、`sso_default_identity_type`、`sso_success_redirect`。全部後台可編輯、不需要改程式碼重新部署，`SiteBaselineSeeder` 只建立這些設定項本身（`sso_enabled=0`），實際端點/憑證由管理員照案場情況填寫——不能替客戶預先猜一組值。
3. **落地頁流程**（`GET /sso/callback`，`app/Web/Sso/Callback`，掛 `site.context`，不掛 `jwt.auth`——這支本身就是登入入口）：`SsoLoginService` 讀該站 SSO 設定 → 用 `SsoManager` 解析出對應 driver → 呼叫 `resolveIdentity()` 拿到身份 → 依 `username` 比對本地 `users`（找不到且 `sso_auto_create_user` 開啟才建立新帳號，**新建帳號不會自動取得任何端口權限**，一樣要走既有的 `user_portal_access` 才能登入——SSO 只負責「證明這是誰」，不是繞過既有權限體系的後門）→ 呼叫 `AuthService::resolvePortalAccess()`（從 `login()` 抽出的共用邏輯，見 7.2 規則，密碼登入／SSO 登入共用同一套端口存取判斷）→ `issueSession()` 核發內部 JWT（與一般登入完全相同的 JWT／session 機制，一樣寫入 `user_jwt_sessions`）→ 寫入 `audit_logs`（`action=sso.login`）→ 把 JWT 寫進 `HttpOnly` cookie（見下）並導頁到 `sso_success_redirect`（或白名單過的 `?redirect=` 站內相對路徑，**拒絕絕對網址／`//host`，避免被當 open redirect 跳板**）。任何一步失敗都導向 `resources/views/sso/error.blade.php`，依錯誤原因顯示對應訊息，不洩漏內部細節。
4. **Blade 頁面怎麼吃到這個身份**：`EnsureJwtAuthenticated` 原本只認 `Authorization: Bearer`，現在改成「先讀 bearer header，沒有才 fallback 讀 `config('jwt.cookie_name')`（預設 `site_jwt`）這個 cookie」，兩種來源驗證邏輯完全共用（都是同一個 `JwtService::decode()` + `user_jwt_sessions` 查詢），互不影響——Vue3 SPA／一般 API 呼叫本來就會帶 bearer header，優先判斷不會受影響；只有純瀏覽器導頁（沒有 bearer header）才會用到 cookie。Cookie 屬性依 4.2.2 一律嚴格：`HttpOnly`（不可被 JS 讀取）、`SameSite=Lax`、`Secure` 依當次請求是否為 HTTPS 動態決定（本機開發沒有反向代理時為 `false`，不算放寬，見 4.2.2 說明）。
   - **前提：`api` 群組必須有 `EncryptCookies`（2026-09-14 實測補上）**。Laravel 送出的 cookie 一律先經 `EncryptCookies` 加密，而 `api` 群組預設**沒有**這個 middleware——少了它，`/api/*` 讀到的 `jwt.cookie_name` 是密文、驗證永遠失敗（症狀是「帶著落地頁剛設定好的 cookie 呼叫 API 固定 401，但 Blade 頁面正常」，見 6.11 同一段說明）。已在 `bootstrap/app.php` 把 `EncryptCookies` 補進 api 群組前段；這個 fallback 機制（含本節的 SSO 落地頁）才真的可用。寫新的「用 cookie 帶身份」流程時，不要繞過這個前提。
5. **這不是要建一套完整 IdP 對接框架**：`jwt_exchange` 是「先能動」的一種預設實作，涵蓋最常見的「code 換身份」模式；真正遇到的案場如果協定更複雜（例如標準 OAuth2 with state 防重放、SAML assertion），照第 1 點的方式另外實作一個 driver，此設計的重點是「換對接方式不需要改動框架其他部分」，不是「每種協定都已經內建」。

---

## 9. 目錄結構規劃（含多站與多身份端口）

```
app/
  Hq/{module}/              # 主站專屬程式碼（站點管理：開站/停用/模塊開通等，見 6.1、6.5），與子站完全獨立、不共用
    Controllers/
    Models/                 # 這個模塊自己的 Eloquent Model（例如 Hq/Site/Models/Site.php）
    Services/
    routes/
  Api/{module}/            # 子站共用 API；端口以權限控制，不用目錄區分（多端口模塊的 controller 命名規則見 9.1）
    Controllers/
    Models/                 # 這個模塊自己的 Eloquent Model（例如 Api/Role/Models/Role.php）
    Services/
    routes/
  Web/
    Admin/{module}/        # 子站管理後台（對應 admin 這個 portal，實際權限依 user_portal_access 判斷，見 7.2）
      Controllers/
      routes/
    {Portal}/{module}/     # 依 portals.portal_key 對應的其他端口（如 workshop、vendor、declare），結構同 Admin
    Front/{module}/        # 對外一般前台（如有），結構同 Admin
    Sso/Callback/          # 不綁端口的登入類落地頁（見 8.6），結構同 Admin
    Impersonation/Callback/ # 主站跳轉登入的落地頁（見 6.11），結構同 Admin
  Support/                  # 共用工具層：Auth、Site、File、Cache…（沿用 nckuh_ems 慣例）
    Impersonation/          # 子站端兌換主站跳轉交換碼（JSON API 與 Blade 落地頁共用，見 6.11）
    {Concept}/Models/        # 框架核心、跨模塊共用、不屬於任何單一可購買模塊的 Model
                              # （AuditLog、Portal、Permission、IdentityType、SitePluginConfig），
                              # 見下方「Model 歸屬原則」說明
  Plugins/{Function}/{Brand}/{Version}/  # 案場設備/AD/SSO 對接插件，統一介面、按站點設定選用（見 6.10）
  Console/Commands/
  Sites/{SiteCode}/        # 客製化子站的 Controller/Service 覆寫類別，以及子站專屬（公版沒有）的全新 API（見 9.2）
    Api/{module}/            # 子站專屬全新 API，結構同 app/Api/{module}（Controllers/Models/Services/routes）
```

**Model 歸屬原則（依模塊購買/移除的邊界劃分，不可與 6.9 模塊授權機制混淆但精神一致）**：每個模塊（`app/Api/{module}`、`app/Hq/{module}`）必須是自我完整的一個單位——因為客戶是按模塊購買服務、模塊要能整包移除或替換，該模塊自己的核心資料表（Model）一律放在自己的 `Models/` 目錄下，不可以為了「反正大家都要用」就集中放到一個頂層共用目錄，那樣會讓「這個模塊到底包含哪些檔案」變得模糊、也違背模塊化的初衷。
- 每個 Model 指定唯一一個「擁有模塊」：判斷依據是哪個模塊的 Controller/Service 實際**建立**這筆資料（例如 `Role` 由 Role 模塊的 `RoleService::create()` 建立，即使 User/Menu 模塊也會讀取 `Role`，`Role` 仍歸屬 Role 模塊）。其他模塊需要用到時，直接用完整命名空間 `use App\Api\Role\Models\Role;` 引用，不需要複製一份。
- 少數 Model **不屬於任何單一可購買模塊**，而是 7 節權限架構、15 節審計日誌這類框架本身運作就需要的核心設施（`AuditLog`、`Portal`、`Permission`、`IdentityType`、`SitePluginConfig`）：這些放在 `app/Support/{Concept}/Models/`（例如 `app/Support/Portal/Models/Portal.php`、`app/Support/Audit/Models/AuditLog.php`），跟 `app/Support/{Concept}/` 底下既有的 Service 放在同一個概念目錄下。主站（`app/Hq`）的審計日誌是另一張獨立的表（不同資料庫/連線），類別故意命名為 `HqAuditLog`（同樣放在 `app/Support/Audit/Models/`）避免跟子站的 `AuditLog` 撞名。
- Model 內部互相參照（例如 `belongsTo`/`hasMany`）一律用完整的 `use` 匯入語句，不可以省略匯入依賴同一層目錄關係——這在 Model 分散到各自模塊之後尤其重要，省略匯入在原本全部擠在同一個扁平目錄時偶然能生效（PHP 同命名空間可以省略 `use`），現在分散到不同命名空間後一定會在執行期才炸掉 `Class not found`，已實際踩過這個坑並全面排查修正過。
resources/
  vue_src/                  # Vue 原始碼（從 public 移出，不可被直接存取，見下方備註）
    src/site-overrides/{site_code}/
  views/
    sites/{site_code}/{module}/...   # 客製化子站 Blade 覆寫
    {module}/...                     # 共用 Blade 樣板，用模塊小寫代碼當目錄名，直接放在 resources/views 底下
                                      # （不放進 app/Web/{Portal}/{module}/views/——Laravel 預設只認
                                      # resources/views，硬要放進 app/ 底下需要另外寫 ServiceProvider 註冊
                                      # 自訂 view 命名空間，徒增一層不必要的間接，故未採用；上方目錄樹的
                                      # views/ 曾經這樣規劃過但實際上未照此實作，特此更正）
                                      # 跨端口、不屬於任何 portal 業務模塊的基礎設施頁面（例如 Swagger UI，
                                      # 見 12.1）比照辦理，一樣放 resources/views/{name}/，Controller 則放在
                                      # app/Support/Http/Controllers（同 SpaFallbackController 的慣例）
  （原 Laravel 骨架附帶的 welcome.blade.php 已刪除：前台一律走 Vue3 SPA，見 3.2、8 節，不需要這個預設頁面，留著容易誤用）
**主站（app/Hq）目前沒有 Blade／View 層**：6.12 明訂主站功能範疇很窄（開站/管理/停用子站、模塊開通、跳轉登入），現階段只提供 API，沒有對應的網頁後台，因此 `app/Hq/{module}/` 底下不會有 `views/` 子目錄；若日後主站真的需要自己的管理網頁，比照子站 `app/Web` 的慣例另外規劃 `app/Hq/{module}/routes/web.php` + `resources/views/hq/{module}/...`，不是本階段範圍。
database/
  migrations/                # 子站共用 migration，公版子站與單站模式都跑這份
  seeders/
  hq/
    migrations/               # 主站專屬 migration（sites、modules、site_modules 等主站自己的 schema，見 6.2、6.9）
    seeders/
  sites/{site_code}/migrations/   # 客製化子站專屬 migration（不進 --all，見 6.6）
  sites/{site_code}/seeders/
storage/
  app/sites/
    sites.json               # 站點對照表快取（domain/path_prefix -> site_id）
    {site_id}.php             # 各站點連線與設定檔（6.4）
  uploads/{site_id}/{resourceType}/{Y-m}/{filename}
public/
  vue/                       # 共用編譯產物（Tier 1/2 公版與輕度客製站點共用入口，見 8.1）
  vue-sites/{site_code}/     # Tier 3 獨立建置站點各自的編譯產物（見 8.4），只有真正獨立建置的站點才有
  uploads/{site_id}/{resourceType}/{Y-m}/{filename}
```

**需要指出的一處待修正**：目前 nckuh_ems 把 Vue 原始碼放在 `public/vue_src`，但本次需求明確要求「源碼中包含 vue 的原始碼目錄，不應該直接讓用戶直接訪問」——`public` 目錄預設是可被 Web Server 直接存取的，兩者矛盾。本次框架建議把原始碼搬到 `resources/vue_src`（Laravel 慣例：原始碼不放 `public`），`public` 只保留編譯後的 `vue/` 目錄；`vue:build` 指令的來源/輸出路徑需同步調整。

### 9.1 端口（portal）架構
- 每個 `portal_key`（見 7.2 `portals` 表）對應一組獨立登入 API、獨立 Vue Router base、獨立選單樹（7.3），一人可同時被開通多個端口，各端口權限彼此獨立（見 7.2）。
- `app/Web/{portal}` 只放頁面骨架與路由掛載點，業務邏輯仍在 `app/Api/{module}/services`，維持 3.1 節「共用能力不得重複散落」原則。
- `portals` 表欄位：`portal_key`（唯一）、`name`、`admin_only`（true＝僅端口管理員可登入，見 7.2 登入規則）、`is_enabled`、`requires_login`（見 9.1.2）。

#### 9.1.2 端口管理頁面與 requires_login（已實作）
`admin_only`／`is_enabled` 這兩個欄位原本就存在且登入邏輯（`AuthService::login`）也真的在用，但一直沒有對應的後台管理頁面，只能手動改資料庫；同時使用者也提出「端口可以設置是否需要登入才能進入」的需求，這是全新概念——以前 Vue Router 對所有頁面一律強制 `requiresAuth`，沒有「訪客可看」這種端口層級設定。這次一併補上：

- **`app/Api/Portal`**：新增 `requires_login` 欄位（migration `2024_01_08_000000_add_requires_login_to_portals_table`，預設 `true`，向下相容）。`PortalController` 提供 `GET /api/portals`（列出全部，供端口管理頁面用，權限 `api.portal.index`）、`POST /api/portals`（建立，`api.portal.store`）、`PUT /api/portals/{id}`（更新 `name`／`admin_only`／`is_enabled`／`requires_login`，`api.portal.update`）——都是普通 `permission.api:{code}` 檢查，不套用 Role/User/Menu 那套跨端口 ceiling 機制（端口本身是基礎設施表，跟 SystemConfig／Dictionary 性質相同，不是「某個端口底下」的業務資料）。**刻意不提供刪除**：端口一旦有 Role／Menu／UserPortalAccess 掛著就很難乾淨刪除，之後有具體需求（例如確認某端口從未被使用過）再擴充。新增端口只會建立資料列，實際要讓它的登入與頁面運作，還需要另外開發對應的 `app/Web/{Portal}` 骨架與前端建置（見 9.1.1），這支 API 不含這部分。
- **`GET /api/portals/public/{portal_key}`**：唯一不掛 `jwt.auth` 的端點，比照 `GET /api/site/config`（8.2）的寫法，子站 SPA 啟動、登入頁渲染之前就要呼叫。只回傳 `{portal_key, name, requires_login}`；端口不存在或已停用一律回 404，不區分兩種情況。
- **前端串接（`resources/vue_src_admin`）**：`stores/auth.js` 新增 `fetchPortalConfig()`，`main.js` 掛載 App 前與 `fetchSiteCode()` 一起呼叫（`Promise.all`），結果存進 `portalConfig` reactive store；`router/index.js` 的 `beforeEach` 改成 `to.meta.requiresAuth && !isLoggedIn() && portalConfig.requiresLogin` 才導去登入頁，`requires_login=false` 時允許未登入直接進入頁面（頁面內部呼叫到需要登入的 API 一樣會被 `authFetch` 收到 401 清空 session，不需要另外處理）。呼叫 `fetchPortalConfig()` 失敗或端口被停用時保持預設 `true`（安全預設，不會因為請求失敗就意外開放匿名進入）。`admin` 端口本身維持 `requires_login=true`，這版改動對它沒有任何行為差異，機制只在之後有人把某個端口切成 `false` 時才會實際生效——已用一個臨時測試端口驗證過整條鏈路（切換設定 → `GET /api/portals/public/{key}` 正確反映 → 前端讀得到），測試完已清除。
- **前端頁面**：`resources/vue_src_admin/src/pages/portal/PortalList.vue`，表格＋新增/編輯對話框，四個欄位（`admin_only`／`is_enabled`／`requires_login`／`name`）都可直接編輯。
- **使用者管理支援多端口勾選端口管理員**：`UserList.vue` 新增使用者／編輯權限對話框，選擇「端口管理員」模式時改成勾選清單（來源 `GET /api/users/grantable-portals`，精準對應 `UserService::assertGrantWithinCeiling` 的判斷式：`is_system`／`is_delegation_root` 看得到全部端口，一般使用者只看得到自己本身已經是端口管理員的端口——不是 `GET /api/menus/portals` 那種「有某個權限碼也算」的寬鬆清單，避免使用者以為勾了就能授權，送出後才在後端被 `exceeds_delegation_ceiling` 擋下），可以一次勾選多個端口，對每個勾選的 `portal_id` 各呼叫一次既有的 `POST /api/users/{id}/portal-access`。**已知限制**：這是新增/覆蓋授權，不會自動撤銷先前已經是管理員但這次沒勾選的端口；要撤銷某個端口的管理員身份，還是要在該端口底下用既有的「撤銷存取」處理，v1 不做跨端口的一次性撤銷。

#### 9.1.1 多端口模塊的 API 命名規則（不分目錄，靠命名區分）
`app/Api/{module}` 不依 portal 拆子目錄（維持共用目錄、避免重複散落），但一個模塊若橫跨多個端口，動工前需先確認：**這個模塊要提供給哪些 `portal_key`**，並依下列規則命名，讓人一眼從檔名看出這支 API 服務哪個端口、也方便找入口：
1. Controller 依端口拆分、加上 portal 前綴：`{PortalKey}{Feature}Controller`（大駝峰，`PortalKey` 對應 `portals.portal_key`，如 `WorkshopOrderController`、`VendorOrderController`、`DeclareOrderController`）；同一模塊下不同端口輸入輸出格式、可見欄位、驗證規則往往不同，因此在 Controller 層拆開。
2. 邏輯全端口共用、沒有差異的 Controller，不加 portal 前綴（如 `OrderController`），代表所有端口共用同一支。
3. Model／Service **一律不依端口拆分**，維持共用（業務邏輯與資料存取本身不因「誰在呼叫」而不同，差異只在 Controller 層的輸入驗證與輸出組裝），避免同一份業務邏輯依端口各寫一份造成重複。
4. 路由檔預設仍是模塊下單一 `routes/api.php`；若模塊夠複雜，可依端口拆成 `routes/{portal_key}.php` 由 `routes/api.php` 統一 `require`，非強制規則、依模塊規模自行判斷，但不論拆不拆檔，都要搭配 6.9 的 `module:{code}` middleware 與 12.4 的 `@api-portal` 標註，明確標示每支 API 所屬模塊與允許呼叫的端口。

### 9.2 站點程式碼繼承關係
**命名慣例**：`sites.code`（6.2）是資料值，規則是小寫英數底線（如 `vendor_a`）；對應到 PHP 命名空間／目錄時（`app/Sites/{SiteCode}/...`）一律轉成 StudlyCase（`Str::studly($code)`，如 `VendorA`），因為這是 PSR-4 會拿去比對目錄大小寫的路徑片段（Windows 檔案系統不分大小寫容易掩蓋這個問題，Linux 正式環境會直接找不到類別，見 9 節目錄樹的大小寫慣例）。本文其餘地方寫 `{site_code}` 的路徑（`resources/views/sites/{site_code}`、`resources/vue_src/src/site-overrides/{site_code}`、`database/sites/{site_code}`、`public/vue-sites/{site_code}` 等）都不是 PHP 命名空間，直接用原始 `code` 小寫值即可，不需要轉換。

- **公版子站**：執行期完全使用共用目錄（`app/*`、`resources/views/{module}`、共用 Vue chunk），無任何 per-site 檔案。
- **客製化子站**：
  - **Controller/Service 覆寫（約定優於配置，自動偵測，已實作於 `App\Support\Site\SiteOverrideResolver`，見下方驗證範例）**：resolve 類別時，依目前 `SiteContext->templateNamespace` 檢查 `App\Sites\{SiteCode}\...` 下是否存在與共用類別相同相對路徑/名稱、且確實 `extends` 該共用類別的覆寫類別（例如共用 `App\Api\Order\Services\OrderService` 對應覆寫 `App\Sites\VendorA\Api\Order\Services\OrderService`）；存在則動態 `bind()` 成覆寫類別，不存在則完全不受影響、直接 resolve 共用類別（公版子站沒有 `app/Sites/{SiteCode}` 目錄，`is_dir()` 直接短路，零成本，同 8.1/8.5 的設計語言）。工程師只需新增覆寫檔案即可生效，**不需要**在子站另外手動註冊 binding；邏輯與共用一致時，完全不用建立任何檔案，自然沿用共用類別。判斷邏輯集中在 `EnsureSiteContext` middleware 判斷出目前站點後呼叫 `SiteOverrideResolver::register()`，只影響當次請求的容器生命週期。
    **重要的編碼規範（已實測修正，跟原始設計不同，務必注意）**：這個機制只對「透過容器解析、且解析時機晚於 `EnsureSiteContext` 執行」的依賴生效。原本預期「Controller 建構子注入即可」，但實測發現 **Laravel 11 的 Router 在 `gatherMiddleware()` 階段（早於任何 route middleware 執行，包括 `site.context`）就會為了檢查 Controller 有沒有宣告自己的 middleware，呼叫 `$route->getController()` 提前完整建構一次 Controller 實例**——由於所有 Controller 都繼承自 `Illuminate\Routing\Controller`、都有 `getMiddleware()` 方法，這個提前建構**必定會發生**，若可能被覆寫的 Service 是 Controller 的建構子依賴，會在 `site.context` 綁定覆寫類別之前就被提前解析、永遠鎖死成公版類別，覆寫形同虛設，且不會有任何錯誤或警告提示，是實測才發現的深層框架陷阱。
    正確做法：可能被子站覆寫的 Service，**在 Controller 裡一律用「方法參數注入」，不要用建構子注入**（例如 `public function store(Request $request, RoleService $roles)`，不要 `public function __construct(private RoleService $roles) {}`）——方法參數是在 Controller 方法真正被呼叫時（`site.context` 早已執行完畢）才由 Laravel 重新解析，能正確吃到覆寫綁定。**建構子注入仍然適用於「Service 依賴另一個 Service」的情況**（不受此限，因為 Service 本身不會被 Router 提前建構），只有「Controller 的建構子」這個特定位置有這個陷阱。全程**禁止在程式碼中 `new OrderService()` 直接建立實例**，理由不變（繞過容器解析，覆寫永遠拿不到）。
    **已驗證範例**（可作為往後新覆寫的參考依據，非測試殘留）：`app/Sites/Vendora/Api/Role/Services/RoleService.php` extends 公版 `App\Api\Role\Services\RoleService`，`create()` 一律替角色名稱加上 `[VendorA] ` 前綴；`RoleController` 已改為方法參數注入。實測：對 `vendora` 站點（`storage/app/sites/3.php` 的 `template_namespace=vendora`）建立角色會得到加了前綴的名稱，對其他站點（如 1 號站）呼叫同一支 API 完全不受影響，證明機制正確運作。
  - 全新 API 端點（公版完全沒有，非覆寫既有邏輯）：放在 `app/Sites/{SiteCode}/Api/{module}/`，結構比照 `app/Api/{module}`（見 9），同樣分 `Controllers`、`Models`、`Services`、`routes`。路由檔由 `EnsureSiteContext` middleware 判斷出目前站點後，僅在該站點載入對應的 `app/Sites/{SiteCode}/Api/{module}/routes`，不掛進共用 `app/Api` 路由，避免公版站點誤以為此 API 可用。
  - View/Blade 覆寫：見 8.5。
  - Vue 頁面覆寫：分三層漸進，見 8.1（層級與升降級路徑）、8.3（Tier 2 動態掛載）、8.4（Tier 3 獨立建置）。
  - 開站時的複製規則（僅程式碼與 migration/seed，不含執行期資料）：見 6.3 第 3 點。

---

## 10. 檔案管理與圖片工具（設計已定案並實作驗證過，暫緩上線，見下方說明）

**目前狀態**：`app/Api/File`（模塊）、`app/Support/File`（`FileManager`/`PhotoClass` 共用工具）、對應的 `managed_files` 表與前端 `ImageCropper.vue` 元件都已經實作並實測驗證過，但已從程式碼庫移除——優先順序上，字典/系統設定/選單這類更基礎的後台管理功能還沒做完，檔案上傳/圖片處理不是目前階段的重點，先移除避免半吊子的功能留在程式庫裡造成混淆。下面記錄已經驗證可行的設計，之後真的需要時可以直接照這個設計重建，不用重新踩一次坑。

### 10.1 基本規範（設計，尚未實作）
1. 檔案操作必須經過統一文件管理器（`FileManager`），不允許業務模組自行直寫檔案系統。
2. 檔案分為長期存儲（`storage` 私有或 `public/uploads` 公開，注意：公開資源要用實體目錄，不能用 `storage:link` 這種 symlink 做法對外開放——`storage/` 底下同時也放 log、cache 等內部運作資料，不應該讓任何一段路徑被 symlink 整個曝露）與短期存儲（需有過期時間與排程清理機制，且排程頻率要跟 TTL 的時間量級匹配，TTL 是「小時」等級的話排程不能一天才跑一次，否則到期資料會多留將近一天才被真的清掉）。
3. 上傳需限制副檔名、MIME、大小，並保留審計資訊（上傳者、時間、來源）。
4. 考慮分佈式部署需求，預留 FastDFS／其他 OSS 存儲支援，目前版本僅保留類型與字典，未實作。
5. 裁切／縮圖／浮水印這類「從既有檔案產生衍生圖片」的操作要抽成 `FileManager` 上任何模塊都能直接呼叫的共用方法（例如 `thumbnail()`/`crop()`/`watermarkText()`/`watermarkImage()`，各自的尺寸/模式/儲存類型/是否短期存儲都由呼叫端決定），不要讓每個用到圖片處理的模塊各自重新兜一次上傳/處理/登記的流程；底層實際的 GD 像素操作（`PhotoClass`）至少要提供 `makeThumbnail`/`resize`/`pad`/`crop`/`cropWithPadding`/`addTextWatermark`/`addImageWatermark` 這幾種常用操作。

### 10.2 多站隔離（設計，尚未實作）
- 儲存路徑增加 `site_id` 層：`public/uploads/{site_id}/{resourceType}/{Y-m}/{filename}`、`storage/{...}/uploads/{site_id}/{resourceType}/{Y-m}/{filename}`。
- 檔案紀錄表隨各站點獨立資料庫走；單站部署包（見 6.1、6.8）內固定使用該站點原本在多站環境下的 `site_id`（由匯出流程寫入設定檔，非固定為 1），同一實體檔案不跨站共用。
- 清理排程需比照 6.6 的邏輯，支援 `--site=` 或遍歷所有站點逐一切換連線清理。
- `FileManager` 服務需感知目前 `SiteContext`：資料庫連線沿用 request 生命週期內已切換好的 `site_current`（不需額外處理），實體路徑多一層 `{site_id}`（如上）。
- 刪除檔案時要同步刪除所有縮圖前綴的衍生檔，不可只刪原圖留下孤兒縮圖。

### 10.3 前台圖片裁切規範與組件（設計，尚未實作）
1. 前台圖片裁切統一使用可複用元件，避免在各頁重複實作滑鼠框選邏輯；props 至少要有 `aspectRatio`（必填，鎖定框選比例，不提供自由拉伸的縮放控點）、`resourceType`、`outputWidth`/`outputHeight`（省略則輸出框選區域本身尺寸）、`token`（元件本身不管理登入狀態，由外部登入後的 store／頁面傳入）；元件本身純手刻滑鼠/觸控事件，不引入任何第三方裁切函式庫。三套 SPA（前台／子站管理後台／主站管理後台）各自獨立建置，這類共用元件的原始碼要各自維護一份，不跨專案 import（見 8 節設計）。
2. 先上傳原圖再裁切輸出，裁切結果另存成一筆新的檔案紀錄，不覆寫原圖，兩者各自獨立生命週期；裁切座標與尺寸一律以來源圖片的原始像素為準，前端自己負責把顯示座標換算回原圖像素座標，實際像素運算在後端用 GD 完成。
3. **已實測驗證過的細節（重建時可以直接沿用，不用重新測一次）**：
   - Playwright 真實瀏覽器測試：拖曳畫出正方形裁切框（`aspectRatio=1`）確認寬高永遠相等、比例鎖定生效；裁切輸出確實是指定尺寸、顏色跟框選區域一致，全程 CSP 無違規。
   - **X-Site-Path-Prefix header 陷阱**：任何用 `fetch()` 打絕對路徑 API 的共用元件（不只裁切工具），都要記得帶上這個 header（見 6.1 開頭 `SiteResolver` 的說明），否則路徑前綴進站的子站，元件的 API 呼叫會被誤判成預設站，回傳 401 或寫錯資料庫，且這個錯誤不會在頁面載入時出現，只有實際觸發該元件的操作時才會發生，很容易漏測。
   - **檔案網址不能用 `Storage::disk('public')->url()` 的預設行為直接回傳**：那是組好的絕對網址（寫死 `APP_URL`），多站/多網域環境下實際瀏覽的網域常常跟 `APP_URL` 不同，會被 CSP 的 `img-src 'self'` 擋下；`FileManager::url()` 要自己只取路徑部分回傳相對網址。
   - **GD 直接寫入實體路徑不會自動建目錄**：不像 `UploadedFile::storeAs()` 那樣 Flysystem 會自動建立缺少的目錄，衍生檔案要換一個 disk（例如 storage_type 覆寫）時，目標目錄可能從來沒建立過，寫入前要自己確保目錄存在。

### 10.4 未來高可用與儲存擴展規劃（方向性，非本階段實作範圍）
1. 隨站點數與流量成長，規劃導入 `HAProxy -> Apache -> MySQL（主從）+ FastDFS` 架構：HAProxy 負責負載平衡與備援、MySQL 主從搭配 2.5 節讀寫分離因應資料庫壓力、檔案儲存改用 FastDFS 取代單機 `public/uploads`。
2. 儲存空間監控：清理排程搭配磁碟／儲存用量監控告警（門檻待與維運確認），避免暫存或過期檔案累積到耗盡儲存空間才發現。
3. 本節僅記錄未來擴展方向，實際導入時機依站點規模與維運需求另行評估，非本階段開發範圍。

---

## 11. API 設計與錯誤處理規範
1. API 回傳格式統一為：
   - 成功: {status: true, error_code: 0, data: {}}
   - 失敗: {status: false, error_code: 10001, error_message: "error message"}
2. 錯誤碼需分層：
   - 1xxxx: 參數/驗證
   - 2xxxx: 權限/認證
   - 3xxxx: 業務邏輯
   - 5xxxx: 系統異常
3. 需要有一個錯誤代碼表，記錄錯誤代碼具體的意義及檢查提醒。
4. Exception 必須轉換為統一 JSON 結構，不可直接回傳堆疊資訊。
5. 除錯模式記錄請求與回應時，需遮罩密碼、token、身分證號、手機等敏感欄位。
6. API 文件（Swagger／OpenAPI）規範獨立於「12. Swagger／OpenAPI 文件規範」，所有接口皆須依該章節標註。

### 11.1 JWT 登入狀態與單帳號單 Token（限制範疇為「單一端口」）
**限制範疇說明**：7.2 允許一人同時開通多個端口、各自獨立登入 session，因此單帳號單 Token 的限制範疇是「同一帳號在同一端口」，不是整個帳號——例如同一人在工務室端與財務系統端可以同時各自保有一個有效 Token，但同一人在同一端口重複登入時，該端口的舊 Token 會失效。

1. 新增 `user_jwt_sessions`：記錄每個 JWT 的 `jti`、`user_id`、`portal_id`、`role_id`、`last_access_at`、`last_access_ip`、`last_access_user_agent`、`is_active`；「目前有效 Token」由查詢 `user_jwt_sessions` 中 `(user_id, portal_id)` 對應 `is_active=true` 的紀錄取得，不使用 `users` 表單一欄位（避免與一人多端口同時登入的設計衝突）。
2. 新增設定 `JWT_SINGLE_USER_SESSION`：
   - `true`：同帳號在**同一端口**新登入會使該端口的舊 JWT 失效（僅保留 1 個有效 JWT／端口），不影響該帳號在其他端口的既有 session。
   - `false`：不限制同帳號同端口的 JWT 數量。
3. 當開啟單帳號限制且舊 Token 被新 Token 取代時，接口將回傳 `20015`。
4. 系統支援 JWT 會話自動清理：
   - 排程命令：`jwt:sessions:prune`（預設每日執行）。
   - `JWT_SESSION_TOUCH_INTERVAL_SECONDS=60`：最後訪問時間落庫節流秒數，避免每次請求都更新資料表。
   - `JWT_SESSION_PRUNE_ENABLED=true`：是否啟用排程清理。
   - `JWT_SESSION_PRUNE_RETENTION_DAYS=30`：保留天數（超過天數的過期/失效會話將被清理）。
   - `JWT_SESSION_PRUNE_TIME=03:20`：每日清理時間（24小時制）。

### 11.2 統一錯誤碼管理
1. 錯誤碼需集中管理，禁止在 Controller/Middleware 直接寫 magic number。
2. 建議建立 `App\Support\Api\ErrorCode`（常數）與 `App\Support\Api\ErrorCatalog`（代碼對應訊息 key/HTTP status）。
3. 分層建議：
   - `1xxxx`：參數/驗證（例：10001 驗證失敗）
   - `2xxxx`：認證/授權（例：20001 缺少憑證、20011 APP 已停用、20012 簽章錯誤、20015 單帳號單 Token 被新登入取代（11.1）、20016 模塊未開通（6.9）、20017 端口僅開放端口管理員登入（7.2））
   - `3xxxx`：業務邏輯
   - `4xxxx`：保留（暫未使用）
   - `5xxxx`：系統異常
4. `ApiResponder::fail()` 應支援僅傳錯誤碼，由 catalog 反查預設訊息與 HTTP status。
5. Exception Handler 應統一轉換為：`{status:false,error_code,error_message}`，並保留 `trace_id` 供排查。**已實作**（`bootstrap/app.php` 的 `withExceptions()`）：
   - `shouldRenderJsonWhen`：`/api/*` 路徑一律視為要回 JSON，不管請求有沒有帶 `Accept: application/json`。**修正一個已實測踩過的坑**：Laravel 預設只在請求帶 `Accept: application/json`（或 `X-Requested-With: XMLHttpRequest`）時才判斷「這個請求想要 JSON」，但前端一律用瀏覽器原生 `fetch()`（`authFetch`，見 8.6）呼叫 API，預設不會帶這兩個 header——這導致 `$request->validate()` 驗證失敗時，Laravel 誤判成瀏覽器表單請求，改成 302 redirect back（`/api/*` 路由通常沒有 session，這支更糟：直接 redirect 到 SPA 首頁的 HTML），前端 `res.json()` 對著一頁 HTML 解析直接拋例外、又沒有對應的 catch 處理，使用者只會看到「按下儲存鍵完全沒反應」（loading 圈轉一下就消失，沒有任何錯誤訊息），新增使用者、選單管理的新增/編輯表單都曾中招。
   - `render(ValidationException ...)`：把 `$request->validate()` 丟出的例外轉成跟 Controller 手動 `$this->fail(ErrorCode::VALIDATION_FAILED, ...)` 完全相同的回應格式，前端不用分兩套邏輯判斷「這是自動驗證失敗還是我自己丟的 RuntimeException」。訊息帶 `$e->validator->errors()->first()`（第一個欄位層級的錯誤），讓使用者看得出來是哪個欄位、什麼原因不合法，不能只顯示一句模糊的「驗證失敗」——這份訊息由新增的 `lang/zh_TW/validation.php` 產生（Laravel 內建規則訊息＋`attributes` 對照表，把 `identity_type_id` 這類英文欄位名轉成「身份別」等中文說法；只覆蓋目前實際用到的欄位，之後新增欄位若訊息顯示成英文鍵名，補上對照即可）；`lang/en/*`（`lang:publish` 預設會產生）沒有實際用途已刪除，這個專案的 API 文字一律走 `lang/zh_TW/*.php` 自己維護（開發約定第 11 點），不依賴 Laravel 內建的英文語言檔。需要跨欄位或業務規則層級訊息的情境，還是走既有慣例：`RuntimeException` + `api.php` 自訂訊息。

---

## 12. Swagger／OpenAPI 文件規範
本章彙整所有 Swagger／OpenAPI 相關規則（標註風格、端口、多行說明、回應欄位、CLI 指令、文件網址、受眾隔離），為唯一權威來源，其他章節一律以連結引用，不重複列出細節。

### 12.1 基本規則與必填欄位
1. API 接口統一使用 Swagger 定義介面，並用 Swagger UI 展示；註解直接寫在各模組入口 Controller 方法上（例如 login、profile、ping），使用 `@api-xxxx` 風格。
2. **Swagger UI（已實作）**：`/docs`（內部）、`/docs/partner`（廠商，見 12.8）兩支路由由 `App\Support\Http\Controllers\ApiDocsController` 渲染 `resources/views/docs/index.blade.php`，自行 host `swagger-ui-dist`（npm 套件，不吃 CDN，比照 4 節安全原則）——靜態資源與 OpenAPI JSON 皆由 `swagger:generate` 一併產生／複製：
   - `swagger-ui-dist` 的 CSS/JS 複製到 `public/vendor/swagger-ui`（`SwaggerGenerateCommand::publishSwaggerUiAssets`，需先 `npm install` 才會有 `node_modules/swagger-ui-dist` 可複製；找不到時只跳過複製並提示，不影響 JSON 產出）。
   - OpenAPI JSON **刻意不放在 `public/docs`**，改存在 `storage/app/private/docs/openapi.json`／`openapi.partner.json`，透過 `/docs/openapi.json`、`/docs/openapi.partner.json` 兩支路由讀檔回傳——因為 `php artisan serve`（PHP 內建開發伺服器）遇到請求路徑剛好對應一個真實存在的目錄（若沿用舊設計把 JSON 放在 `public/docs/openapi.json`，`public/docs/` 這個目錄就會真實存在）時，會直接回自己的 404、完全不會進到 Laravel 路由，導致 `/docs` 這個網址本身永遠打不到，已實測確認（Nginx/Apache 正式環境的標準設定不會有這個問題，但本機開發天天要用 `php artisan serve`，不能將就）；移除 `public/docs/` 這個實體目錄、整棵 `/docs/*` 都交給 Laravel 路由處理後才解決。
   - `/docs`、`/docs/partner` 這兩條路由的 CSP 用獨立的 `App\Support\Http\Middleware\DocsSecurityHeaders`（`->withoutMiddleware(SecurityHeaders::class)` 移除全域套用後改掛這支），不影響全站其他頁面，見 4.2.1 第 6 點。已用 Playwright 實測載入頁面、檢查主控台：`swagger-ui-bundle.js` 執行期會固定注入一段內容不變的 `<style>` 標籤，用瀏覽器違規訊息直接給的 CSP hash source（`sha256-...`）精確允許這一份內容即可，不需要 `unsafe-inline`／`unsafe-eval`，比 4.2.1 第 5 點講的 `style-src-attr` 折衷做法更嚴格（hash 只認这份固定內容，套件版本升級後內容變了要重新計算）。
3. 最小必填標註，`swagger:generate`／`permission:sync-api` 共用的 `ApiAnnotationValidator` 會逐項檢查，任一項不合格直接中斷（見 12.6）：`method`、`path`、`tag`、`summary`；`portal`（12.4，不限端口要明確標 `@api-portal *`，不可留空）；POST/PUT/PATCH 若有 body 入參要有 `request-field`（沒有 body 要明確標 `@api-no-request-body`），有 `request-field` 就要有 `request-example`（12.5）；`response-field`（12.3，沒有回傳資料要明確標 `@api-response-empty`）；每一筆 `request-field`／`response-field` 都要有非空的說明文字。回傳值 `example` 為可選填，非強制。

### 12.2 多行 Description
1. `@api-description` 之後的內容，只要還在同一段 `/** ... */` docblock 內、且尚未遇到下一個 `@api-xxx` 標籤，都視為該欄位的延續行；掃描腳本需逐行去除開頭的 `*` 與空白後以換行接續組合。
2. OpenAPI 的 `description` 欄位原生支援 CommonMark（Markdown），可放清單、粗體、換行段落，Swagger UI 會渲染排版後的說明，不再只是一行純文字。
3. 範例：
   ```
   /**
    * @api-method POST
    * @api-path /api/work-orders
    * @api-summary 建立工單
    * @api-description
    * 建立一筆新的維修工單。
    *
    * 注意事項：
    * - `category_id` 需為啟用中的常見問題分類
    * - `priority` 未填時預設為一般
    * @api-response 200 ...
    */
   ```

### 12.3 Response 欄位定義（`@api-response-field`）
1. 過去 Swagger 只能靠 `@api-response` 貼一段 example JSON，UI 的 Schema 分頁看不到欄位型別與說明，本次需補上。
2. 新增標註 `@api-response-field {欄位路徑} {型別} {說明}`，每個欄位一行，巢狀欄位用點記法、陣列用 `[]`：
   ```
   * @api-dict work_order_status 1=待處理,2=已完成,3=已取消
   * @api-response-field data.id int 工單ID
   * @api-response-field data.status dict:work_order_status 工單狀態
   * @api-response-field data.items[].id int 明細ID
   * @api-response-field data.items[].qty int 數量
   ```
3. 掃描腳本需把這些欄位組成 OpenAPI 的 `responses.200.content.application/json.schema.properties`（巢狀路徑轉成巢狀 `properties`/`items`），連同既有的 example 一起輸出；Swagger UI 的「Schema」分頁可看到完整欄位結構、型別、說明，「Example Value」分頁仍看 example。
4. 型別限定允許清單：`int/string/bool/float/array/object/datetime/file`（`file` 用於 multipart 上傳欄位，例如 `@api-request-field image file 圖片檔案（multipart/form-data）`），或 `dict:{字典名稱}`（見 12.5 第 2 點，欄位型別是字典時強制用這個前綴，不可只在說明文字裡口頭帶過）。所有有 response 的接口都必須標註完整欄位定義（沒有回傳資料要明確標 `@api-response-empty`，見 12.1），不可只提供 example；陣列型欄位一律標 `array`（不支援 `string[]` 這類語法糖，型別不在允許清單即報錯，見 12.6）。

### 12.4 端口標註（`@api-portal`）
沿用 nckuh_ems `@api-identity` 的標註精神，改為對照 7.2 的 `portals` 資料表——實際決定「誰能呼叫這支 API」的是端口，不是身份別（見 7 節說明）：
1. Controller 標註 `@api-portal workshop,vendor`（逗號分隔 `portals.portal_key`，總站管理員 `is_system=true` 不用標，永遠不受限；`app/Hq` 底下的 Controller 因程式碼與資料庫完全獨立，不使用此標註）。此標註**必填**，不可留空——刻意不限端口（登入本身、`GET /api/site/config` 這類公開端點）要明確標 `@api-portal *`，讓「這支接口就是不限端口」變成看得到的紀錄，而不是漏標。
2. `permission:sync-api` 掃描時對照 `portals.portal_key` 解析成 `portal_id`，寫入 `permission_portals`；標註 `*` 時不查表，直接視為不限端口。
3. OpenAPI 產出時，把 `@api-portal` 反映在 `x-portals` extension 欄位，Swagger UI 可依端口分類/篩選 API 列表，也讓「哪個端口能呼叫哪支 API」有唯一事實來源（不需要在後台權限頁面手動重複維護一份）。

### 12.5 request-example 與字典標註慣例
1. 所有有 body 入參的接口都必須補上可直接調用的 `@api-request-example`，不可使用 `"string"` 這類佔位示例；示例需盡可能貼近真實業務資料（可用的代碼、日期格式、路徑、狀態值）。更新接口也必須提供 body 範例，確保調用者只看文檔即可正確填寫。POST/PUT/PATCH 若本來就沒有 body 入參（例如登出），標 `@api-no-request-body` 明確聲明，不是留白讓人猜是漏寫還是真的沒有。
2. **字典欄位一律用結構化標註，不要把選項清單寫在自由文字的 `@api-description` 裡**（舊版做法無法被機器驗證，容易寫了字典名稱卻忘記列出可選值，或列出的值跟後端實際邏輯對不上）：
   - 欄位型別標成 `dict:{字典名稱}`（取代 `int`/`string`，見 12.3 第 4 點）。
   - 同一段 docblock 內用 `@api-dict {字典名稱} {值}={說明}[,{值}={說明}...]` 列出實際可選值，例如 `@api-dict work_order_status 1=待處理,2=已完成,3=已取消`。
   - `ApiAnnotationValidator` 會檢查每個 `dict:{name}` 型別都有對應的 `@api-dict {name}` 宣告，缺少或選項格式錯誤（不是「值=說明」逗號分隔）一律中斷（見 12.6）；`swagger:generate` 會把選項清單展開寫進該欄位的 `description`，Swagger UI 不需要額外外掛就能直接看到字典的實際內容，不是只看到「這是個字典」幾個字。
3. 若接口參數有特殊情況（如二者必須傳一種、特殊值有特殊意義），必須在 Swagger 文檔中明確寫清楚。
4. 若需初始化字典等資料，需在 Swagger 文檔用 `@api-description` 備註字典名稱與可選項，初始化語句使用 seeders，執行前需檢查是否已存在重複字典，避免重複寫入。
5. 修改字典值時，應同步修改所有用到該字典的接口的 `@api-dict` 宣告，並執行 `alter table` 更新欄位的 comment 說明，避免未來資料與文件對不上。

### 12.6 標註格式錯誤處理
`swagger:generate` / `permission:sync-api` 共用同一套 `ApiAnnotationValidator`（見 12.1），在遇到以下情況時，須明確報錯中斷，不可靜默略過或忽略錯誤標註：
- 缺少必填標註：`tag`、`summary`、`portal`（12.4）。
- `@api-response-field`／`@api-request-field` 路徑格式不合法、型別不在允許清單也不是 `dict:` 前綴（12.3），或說明文字空白。
- `@api-portal` 標註了 `portals` 資料庫中不存在的 code（`*` 除外，12.4），避免打錯字被靜默忽略。
- POST/PUT/PATCH 沒有 `request-field` 也沒有明確標 `@api-no-request-body`（12.1、12.5）。
- 有 `request-field` 卻沒有 `request-example`（12.5 第 1 點）。
- 沒有 `response-field` 也沒有明確標 `@api-response-empty`（12.1、12.3）。
- 欄位型別標為 `dict:{name}`，但同段 docblock 找不到對應的 `@api-dict {name}` 宣告，或該宣告的選項格式錯誤（12.5 第 2 點）。

### 12.7 CLI 指令與文件網址
文件網址：
- Swagger UI（內部）：`http://127.0.0.1:8000/docs`
- Swagger UI（第三方廠商）：`http://127.0.0.1:8000/docs/partner`
- OpenAPI JSON（內部）：`http://127.0.0.1:8000/docs/openapi.json`
- OpenAPI JSON（第三方廠商）：`http://127.0.0.1:8000/docs/openapi.partner.json`

指令：
1. 產生 OpenAPI JSON（推薦）
   ```powershell
   php artisan swagger:generate --pretty
   ```
   - 掃描範圍: app/Api 下所有 Controller 註解
   - 輸出檔案: `storage/app/private/docs/openapi.json`／`openapi.partner.json`（見 12.1 第 2 點，不放 `public/docs` 的原因），另會一併把 `swagger-ui-dist` 的靜態資源複製到 `public/vendor/swagger-ui`（需先 `npm install`）
2. 透過 composer 腳本產生（等價命令）
   ```powershell
   composer run swagger:generate
   ```
3. 驗證 API 路由是否已註冊
   ```powershell
   php artisan route:list --ansi
   ```
   - 需確認至少包含: /api/ping、/api/auth/login、/api/user/profile、/api/system/factory/apps
4. 建議執行順序：修改 Controller 註解 → 執行 `swagger:generate` → 重新整理對應文件網址確認 UI 已更新。

### 12.8 文件受眾隔離（內部／廠商）
- 內部文件（`/docs`，`openapi.json`）不含 `/api/factory/*`。
- 第三方文件（`/docs/partner`，`openapi.partner.json`）僅含廠商模式 API（`/api/factory/*`）。
- App 模式 token 僅允許訪問 `/api/factory/*`，訪問一般路徑會回傳 403。

---

## 13. 資料庫與遷移規範
1. Schema 變更必須透過 migration，禁止手改線上結構。
2. migration 命名需可辨識模組與用途。
3. seed 分為兩類：
   - baseline seed（必要初始資料）
   - demo seed（開發示範資料，生產不執行）
4. 索引、唯一鍵、外鍵策略需在設計文件中說明。
5. MySQL/MariaDB 資料表儲存引擎統一使用 `InnoDB`，禁止使用 `MyISAM`。
6. `.env` 必須設定 `DB_ENGINE=InnoDB`，新建表須支援交易、行級鎖與外鍵。
7. 初始化數據請建立 seed 檔案，不允許直接在 migration 中直接寫入，必須考慮重複執行是不可重複插入
8. **標準指令防呆（裸跑 `migrate`／`db:seed` 不會弄錯庫）**：專案有兩套結構——主站 `database/hq/migrations`、子站 `database/migrations`——但 Laravel 預設只認 `database/migrations`。為避免開發者（或 `migrate:fresh --seed`）把子站表建進主站庫，標準指令改為依模式選擇目標：
   - **migrate 系列**：`App\Support\Database\Console\Hq*Command`（`migrate`、`migrate:status`、`migrate:rollback`、`migrate:reset` 的子類別，共用 `UsesHqMigrationPath`，於 `AppServiceProvider::register()` 以 `extend()` 取代）。條件「多站模式（`multisite.enabled`）且沒帶 `--path` 且沒帶 `--database`」時，`getMigrationPaths()` 回傳 `database/hq/migrations`。`migrate:fresh`、`migrate:refresh` 內部呼叫上述指令，自動一併生效。
   - **db:seed**：`DatabaseSeeder::run()` 依「多站模式且沒帶 `--database`」選擇 `HqDatabaseSeeder`，否則 `SiteBaselineSeeder`。
   - **明確指定一律照辦**：帶 `--path`／`--database`／`--class` 時完全不介入，因此 `site:migrate`、`SiteProvisioningService` 內部呼叫（都帶 `--database=site_current`＋明確路徑）行為不變；舊的完整寫法（`migrate --path=database/hq/migrations`）仍可用。單站模式（`MULTI_SITE_ENABLED=false`，部署包）沒有主站，維持 Laravel 預設，部署文件的 `php artisan migrate` 照舊。
   - 使用 `extend()` 而非重新 `singleton()`：這些指令由延遲載入的 `MigrationServiceProvider` 註冊，直接覆寫綁定會在其載入時被蓋回去。
   - 驗證方式：路徑解析以反射驗證過（多站裸跑／帶 `--path`／帶 `--database`／單站四種情境，四個指令皆符合預期；`DatabaseSeeder` 三種情境皆選到預期的 seeder），**尚未在實際資料庫上完整跑過 `migrate:fresh --seed`**，需要時請在測試環境驗證一次。

## 14. 快取、佇列、排程規範
1. Redis key 命名需帶前綴：project:module:scene:id。
2. 快取需定義 TTL 與失效策略，避免永不過期。
3. 重任務使用 Queue，不可在 Web 請求中同步執行。
4. 排程任務需具備互斥鎖與失敗告警。

## 15. 日誌與監控規範
1. 日誌分級：debug、info、warning、error。
2. 禁止記錄密碼、token、完整個資。
3. 關鍵操作需審計日誌，寫入日誌表（登入、權限變更、資料刪改）。
4. 生產環境至少具備: error log、slow log、queue fail log。
5. 新增 `audit_logs` 資料表，記錄什麼時間、誰、做了什麼操作（對應第 3 點的審計日誌需求，欄位至少含 `user_id`、`action`、`target`、`ip`、`created_at`）。**此表跟著各自的資料庫走，不是單一集中表**：子站層級操作（登入、權限/選單變更、資料刪改）寫入該子站自己的 `audit_logs`；主站層級操作（6.3 開站、6.9 模塊開通/下架、6.11 核發跳轉交換碼）寫入主站自己的 `audit_logs`。像 6.9「主站操作但影響子站」這類跨庫動作，兩邊資料庫各自記一筆（主站記「對哪個子站做了什麼」，子站記「發生了什麼」），不需要、也不能跨庫 join 查詢。
6. **已實作落地範圍**：子站一般帳密登入（`AuthService::login`，action=`auth.login`）、SSO 登入（`SsoLoginService`，action=`sso.login`）、主站跳轉登入兌換（`App\Support\Impersonation\HqImpersonationService::redeem`，action=`hq_impersonation.login`；JSON API 與 Blade 落地頁共用，見 6.11）皆已寫入登入審計；Role／User／Menu 三個管理模組的建立/更新/刪除、端口授權/撤銷、密碼重設也都已在各自 Service 層寫入審計（action 命名慣例為 `{模組}.{動作}`，如 `role.create`、`user.grant_access`、`menu.delete`），已用真實 HTTP 請求逐一測試並確認 `audit_logs` 正確落地，寫入邏輯統一放在 Service 層、Controller 只負責把 `$request->ip()` 傳入（同 4 節第 3 點「Controller 只做轉發」原則）。密碼重設的 `context` 刻意不記任何密碼片段，對應第 2 點的禁止事項。

## 16. 程式碼規範與品質門檻
1. 統一 PHP CS Fixer 或 Pint 規範。
2.  PR 合併前需通過：
   - 測試
   - 靜態檢查
   - 格式化檢查
   - 依賴套件安全掃描（`composer audit`／`npm audit`，見 4.2）
3. 禁止在 Controller 撰寫複雜業務流程。
4. 禁止不經驗證直接使用用戶傳遞的參數。
5. 未按要求撰寫 swagger 註解（見 12），或是注釋不全、未將該注意的邏輯寫明，視為未完成。

## 17. 系統環境配置模組（System Config）

系統提供共用環境配置功能，可供各模組（如系統設定、郵件設定、金流設定…）複用，
不需要各自實作設定儲存邏輯。核心資料表為 `system_configs`，並附帶 `system_cfg_type` 字典供前台使用。

**本表存在於各站點自己的資料庫**（子站各自獨立、主站也有自己一份，見 6.1、6.4），因此 `site_id` 欄位在此**不用於跨站點區分**（同一份資料庫本來就只服務單一站點），只作為「此設定是否套用到該站所有模組」的識別旗標沿用，不代表可被其他站點查詢到。

### 17.1 資料表欄位

| 欄位 | 類型 | 說明 |
|------|------|------|
| id | bigint | 主鍵 |
| site_id | int | 固定為目前所屬站點的識別碼，預設 0（代表套用到該站所有模組的通用設定，非跨站全域） |
| cfg_name | varchar(100) | 英文名稱，同模組下唯一（小寫英數底線） |
| cfg_title | varchar(255) | 中文說明 |
| cfg_type | varchar(20) | 類型：`string`/`textarea`/`int`/`float`/`bool`/`enum`/`multi`/`dic`/`dic_multi`/`date`/`datetime`/`time`/`email`/`url`/`color`/`password`/`json`/`image`（欄位刻意放寬為 varchar：新增類型只需登記 `SystemConfig::TYPES` + 驗證分支 + 重灌 `system_cfg_type` 字典，不用改表，原始清單為 string/int/enum/dic/date/datetime） |
| cfg_option | json | 選項規則：enum/multi=[陣列]、dic/dic_multi=字典代碼、string/textarea/int=最大長度、float=小數位數、date/datetime/time=格式字串、image=縮圖規格物件，其餘類型不需要 |
| cfg_style | json | 樣式設定（如 `{"maxlength":100,"width":"300px","rows":3}`） |
| cfg_value | text | 當前設定值（multi/dic_multi 一律存 JSON 陣列字串，例如 `["a","b"]`） |
| cfg_default | text | 預設值 |
| cfg_module | varchar(100) | 所屬模組（如 `system`/`mail`/`payment`） |
| sort | tinyint | 排序 |

`cfg_type` 對應的 `cfg_option` 規則：
- `enum`/`multi`：`["選項A","選項B"]`
- `dic`/`dic_multi`：字典名稱字串，如 `"user_status"`（從 `system_dictionaries` 讀取選項）
- `string`/`textarea`/`int`：最大長度數字，如 `255`
- `float`：小數位數上限，如 `2`
- `date`：格式字串，預設 `YYYY/MM/dd`；`datetime`：預設 `YYYY/MM/dd HH:ii:ss`；`time`：預設 `HH:ii:ss`（只是顯示格式，不是驗證規則）
- `bool`：不需要 cfg_option，值固定為 `"0"`/`"1"` 字串（與既有 enum `["0","1"]` 的值格式完全相同，讀值端如 `SsoSiteConfig::load` 的 `$value === '1'` 不受影響）
- `email`/`url`/`color`/`password`/`json`：不需要 cfg_option；`color` 為 `#RRGGBB`；`password` 用 `Crypt::encryptString` 加密後才寫入 `cfg_value`（見 17.2），不是明文存放——早期版本只在後台呈現時遮罩、資料庫本身沒有保護，已修正
- `image`：完整圖片規格物件，見 17.5。可用鍵（全部可省略，省略時用 `SystemConfig::defaultImageSpec()` 的預設值）：
  - `width`／`height`：輸出尺寸（像素），例如網站 Logo 是 `300`×`200`。
  - `mode`：`cover`（填滿輸出，可能裁切）／`contain`（完整保留＋補背景）。早期還有一個 `stretch`（不維持長寬比直接縮放），因為會把 logo 壓扁、實務上沒人會選，已從規格移除。
  - `format`：輸出格式 `png`／`jpeg`／`webp`／`gif`（預設 `png`）。`jpeg`／`gif` 沒有 alpha 通道，輸出時會自動壓成白底。
  - `transparent`：是否透明背景（預設 `true`）；也可以直接設 `background`（色碼 `#rrggbb`）代表要補底色、不透明，兩者同時存在時以 `background` 為準。
  - `background`：補背景色碼，例如 `"#ffffff"`；不設＝透明。
  - `max_kb`：允許上傳的來源檔大小上限（KB，預設 5120）。
  - `allowed_formats`：允許上傳的來源格式清單，例如 `["jpg","png","gif","webp"]`（預設全開）。

類型清單本身依本章開頭的原始設計灌成 `system_cfg_type` 字典（見 17.3），需要「選一個 cfg_type」的介面直接用同一支字典 API 取得選項。

### 17.2 管理 API（已實作）

`app/Api/SystemConfig`：`GET /api/system-configs`（可帶 `module` 查詢參數依 `cfg_module` 過濾，供後台依模組分組渲染設定頁面）、`PUT /api/system-configs/{id}` 更新單筆設定。權限判斷直接用路由層 `permission.api:{code}`（見 `EnsurePermission`），不透過 `BaseApiController::resolveTargetPortalId`／`authorizeOnPortal` 那套跨端口機制——因為 `system_configs` 不像 Role/User/Menu 綁定 `portal_id`，是整站層級設定，沒有「目標端口」這個概念，直接檢查目前登入端口是否持有權限碼即可。

**刻意的範圍限制**：管理 API 只能改 `cfg_value`，不開放新增／刪除設定項或修改 `cfg_type`/`cfg_option`/`cfg_module` 等結構欄位——新增一筆設定項代表程式碼要多讀一個 `cfg_name`，本來就得由工程師寫程式配合（透過 `SiteBaselineSeeder` 或各模組自己的 seeder，見 13 節「初始化數據建立 seed 檔案」），不是後台純資料操作能獨立完成的事，跟 Role/User/Menu 那種「純資料、後台可以完全自主管理」的情境不同，故意不做成看似對稱、實際上會被結構完整性問題絆住的通用 CRUD。圖片類型的處理參數（輸出尺寸、格式…）同樣屬於結構定義，寫在 seeder 的 `cfg_option` 裡（見 17.5），要調整請改 seeder 後重跑 baseline seed，不從後台改。

更新時依 `cfg_type` 驗證 `cfg_value`（見 `SystemConfigService::normalizeValue`）：`enum` 必須是 `cfg_option` 允許的選項之一、`multi` 送 JSON 陣列字串且每個成員都在 `cfg_option` 內、`bool` 只接受 `0`/`1`、`int` 必須是整數、`float` 必須是數字、`json` 必須是合法 JSON、`email`/`url`/`color` 依對應格式檢查、`string`/`textarea` 依 `cfg_option` 檢查最大長度；`date`/`datetime`/`time` 的 `cfg_option` 只是顯示格式字串不強制驗證內容格式；`dic` 送的值必須是 `cfg_option` 指定字典（見 17.3）底下啟用中的項目值之一、`dic_multi` 送 JSON 陣列字串且每個成員都必須是該字典啟用中的項目值之一（字典代碼本身不存在或已停用會直接拒絕）；未知類型一律拒絕（見 `SystemConfig::TYPES`）。`value` 可傳空字串清空設定值（`enum`/`bool`/`dic` 等必選類型不接受空值）；`multi`/`dic_multi` 回傳的 `cfg_value` 是正規化後的 JSON 陣列字串。（單筆 `PUT` 這支已修正一個踩過的坑：Laravel 內建的 `ConvertEmptyStringsToNull` 中介層會把請求裡的 `""` 轉成 `null`，`SystemConfigController::update()` 原本直接把驗證後的值傳給型別宣告為非 nullable `string` 的 `updateValue()`，導致清空操作丟出 `TypeError`（500）；`PUT /api/system-configs/batch` 因為原本就用 `$item['value'] ?? ''` 沒有這個問題，只有單筆 `PUT` 需要補上同樣的 `?? ''`。）

`GET /api/system-configs` 每筆回傳附 `options` 欄位（下拉選項自動帶出，前端不需要再另外呼叫字典 API）：`enum`/`multi` 由 `cfg_option` 展開、`dic`/`dic_multi` 由該字典啟用中項目解析（`DictionaryService::resolveEnabledItemsByCodes`，整頁用到的字典代碼去重後單一查詢），格式為 `[{value,label},...]`，其餘類型為空陣列。後台頁面（`SystemConfigList.vue`）依 `cfg_type` 自動渲染對應控制項：下拉／多選／開關（bool）／整數／小數／日期／日期時間／時間／色碼（color）／密碼遮罩（password）／多行文字（textarea/json）／單行文字（string/email/url）。

更新動作會寫入該子站自己的 `audit_logs`（見 15 節第 6 點），`context` 只記 `cfg_module`/`cfg_name`，不記實際值——郵件/金流這類模組的設定值可能含密碼或金鑰，審計日誌本身不能變成另一個外洩管道。已用真實 HTTP 請求測試：`module` 過濾、合法 enum 值更新、不合法 enum 值正確拒絕、`string` 更新、不存在的 id 回 404、未帶 token 回 401，皆驗證通過。類型擴充後另已驗證：所有新類型的合法值更新與不合法值拒絕（`color`/`url`/`json`/`bool`/`multi`/`dic_multi`/`password`/空值清空等）、`dic`/`enum` 的 `options` 自動帶出內容正確（tinker service 層 + 真實 HTTP 請求各測一輪）、未知類型正確拒絕。

**批量保存**：後台改成整頁（實際上是整個分頁／整個插件設定區塊，見 17.4）一次送出，取代逐列各自 `PUT` 的舊版做法——`PUT /api/system-configs/batch`（`{"items":[{"id":1,"value":"..."},...]}`）包在同一個 DB 交易內，任一筆驗證失敗（規則與單筆 `PUT` 完全相同）整批回滾、不寫入任何一筆。

**`password` 類型的特殊規則**：
- 寫入前用 `Crypt::encryptString` 加密（`SystemConfigService::updateValue`），資料庫裡看到的是密文，不是明文，也不是先前版本的「明文存、只在後台遮罩」。
- `GET /api/system-configs` 與所有寫入端點的回應，`cfg_value` 對 `password` 類型一律回空字串（見 `SystemConfigService::maskedValue`），不把明文或密文吐回前端；另外附一個 `has_value`（bool）欄位，讓後台能顯示「已設定」提示而不用回顯實際值。
- 空字串在 `password` 類型有特殊語意——代表「不更改」，不是「清空」：後台密碼輸入框從不回顯明文，使用者留白送出時沒辦法區分「故意要清空」跟「本來就沒填、原樣送出」，統一解讀成不更改（`SystemConfigEditor.vue` 也會把留白的密碼欄位直接排除在批量送出的清單之外，雙重保險）。
- 真正需要明文的消費端（例如插件連接器讀取自己的 bind password，見 6.10）呼叫 `SystemConfigService::decryptPassword()`／`valuesForModule()`，解密失敗（舊資料格式或本來就是空字串）一律回空字串，不讓例外往外炸。

### 17.3 資料字典模組（Dictionary，已實作）

`app/Api/Dictionary`：跟 Role/User/Menu 一樣屬於「純資料，後台可以完全自主管理」的情境（對比 17.2 的 SystemConfig 只能改值），提供給 `system_configs.cfg_type=dic` 這類需要「一組可管理選項」的場景使用，也可以單獨被其他業務模組引用。

**資料表**（皆存在各站點自己的資料庫，`system_dictionaries.site_id` 比照 `system_configs` 的既有慣例，不用於跨站區分）：
- `system_dictionaries`（類型）：`id`/`site_id`/`code`（唯一，供 `system_configs.cfg_option` 查詢）/`name`/`description`/`is_enabled`/`sort`。
- `system_dictionary_items`（項目）：`id`/`dictionary_id`（外鍵，cascade delete）/`value`（實際被引用的值，同一字典底下唯一）/`label`/`is_enabled`/`sort`。

**管理 API**：`GET/POST/PUT/DELETE /api/dictionaries`（類型 CRUD）、`GET/POST/PUT/DELETE /api/dictionaries/{id}/items`（項目 CRUD）。不分端口，權限判斷方式比照 17.2 的 SystemConfig（直接用路由層 `permission.api:{code}` 檢查）。刪除字典類型前會先檢查有沒有 `system_configs` 的 `cfg_type=dic` 設定項還在引用這個代碼，有的話拒絕刪除，避免那些設定項的 `dic` 驗證悄悄失效；項目隨字典一併刪除（資料庫層 cascade）沒有這層檢查，因為項目被移除頂多讓某個既有 `cfg_value` 變成不再合法，下次更新時才會被擋下，不像刪掉整個字典代碼會讓驗證邏輯直接找不到對象。

`DictionaryService::resolveEnabledValues(string $code): array` 是提供給 `SystemConfigService::normalizeValue()`（`cfg_type=dic`/`dic_multi` 分支）呼叫的共用方法，只回傳啟用中字典底下啟用中項目的值；字典停用或代碼不存在回傳空陣列，`SystemConfigService` 據此判斷「字典不存在或已停用」並拒絕更新。另有 `resolveEnabledItemsByCodes(array $codes): array`（回傳 `code => [{value,label},...]`，含 label），供 17.2 的 `GET /api/system-configs` 批次自動帶出下拉選項用。已用真實 HTTP 請求測試完整流程：建立字典、新增項目、`system_configs` 設一筆 `cfg_type=dic` 指向該字典、合法值更新成功、不合法值正確拒絕、字典使用中時刪除被拒絕、停用字典後驗證正確失效，皆驗證通過。

baseline seed（`SiteBaselineSeeder`）會灌兩個字典：`system_cfg_type`（`SystemConfig::TYPES` 的 17 種類型清單，項目與 `TYPE_TITLES` 同步，updateOrCreate 重跑不重複）與 `site_status`（`site_basic.site_status` 這筆 `cfg_type=dic` 設定項的示範選項來源）。

### 17.4 橫向分頁與可複用編輯元件（已實作）

`SystemConfigList.vue` 改成橫向分頁（`el-tabs`，一個 `cfg_module` 一個分頁）取代舊版的垂直手風琴，每個分頁實際的表格／存檔邏輯抽成獨立元件 `resources/vue_src_admin/src/components/SystemConfigEditor.vue`——只吃一個 `module` prop，自己抓資料（`GET /api/system-configs?module=`）、自己批量存檔（`PUT /api/system-configs/batch`），不依賴外層怎麼安排版面。這支元件是本節開頭「可供各模組複用」設計意圖真正落地的地方：核心系統設定頁掛一個實例、插件設定畫面（見 6.10）也掛一個實例，只是 `module` 參數不同，不用為插件另外刻一份表單或驗證邏輯。

**分頁清單刻意寫死，不是動態查 `system_configs` 有哪些 distinct `cfg_module` 就全部列出來**：這張表跟插件設定共用，若動態列出所有出現過的模組，插件一旦被啟用過（哪怕後來又停用），它的 `cfg_module`（`plugin:{function}:{key}`，見 6.10）就會一起被列成系統設定頁的分頁，兩種完全不同性質的設定混在同一份清單裡——已實測踩過這個坑，現在 `SystemConfigList.vue` 只固定列出核心模組（目前只有 `site_basic`），跟「有哪些設定項存在」一樣是開發階段決定的結構，不是後台資料自己長出來的。

**`site_basic` 更名為「頁面設置」（僅顯示名稱，`cfg_module` 資料庫值不變）**，並把原本「只是存著、沒有實際效果」的 5 個設定項全部接上真正的用途（早期版本前端有 snake_case／camelCase 命名對不上的 bug，`Object.assign(siteConfig, body.data)` 永遠套用失敗，已修正為逐欄位明確對應，見 `resources/vue_src/src/stores/site.js`）：
- `site_name`：套用為 `document.title`（前台、管理後台皆是；管理後台額外顯示在側邊欄 logo 文字旁）。
- `theme_color`：套用為 CSS 變數 `--theme-color`（前台）與 Element Plus 主色 `--el-color-primary`（管理後台），兩邊各自維護一份 `applyRuntimeBranding()`（不跨專案 import，見 8 節慣例）。
- `logo`（後台顯示名稱「網站Logo」，見 17.5）：套用為 favicon（動態插入/更新 `<link rel="icon">`）、**前台頁首左上角**的 Logo 圖片（`resources/vue_src/src/App.vue`，緊鄰站名，點擊回首頁）與管理後台側邊欄 Logo；未設定時前台/後台都 fallback 純文字，不會出現破圖。規格為 300×200 透明 PNG（見 17.5）。
- `footer_text`：前台 `App.vue` 底部渲染成 `<footer>`，管理後台 `AdminLayout.vue` 底部也顯示同一份文案（`el-footer`），未設定時不渲染（不留空白區塊）。前台版面是 `min-height:100vh` 的 flex 直向排列，頁尾用 `margin-top:auto` 固定貼齊頁面下方，內容不足一整頁時也不會浮在半空中。
- `site_status`（`dic` 類型示範字典，見上）：`GET /api/site/config` 一併回傳，前台 `App.vue` 依此判斷——`0`＝維護中，整個 `<router-view>` 換成維護頁；管理後台**不受影響**（另一套獨立 SPA，管理員仍可正常登入把狀態改回來，不會維護模式一開連自己都進不去）。

**SSO 相關設定已移出這個核心分頁**，改到插件管理頁面管理，見 6.10.7。

### 17.5 image 類型：圖片上傳與裁切（已實作）

`cfg_type=image` 讓後台管理員直接上傳圖片（例如網站 Logo `logo`），取代原本「貼一段圖片網址」的文字輸入方式，並依這個設定項自己的 `cfg_option`（見 17.1）自動產生圖片，不用另外寫死在程式碼裡。刻意只服務 `system_configs` 自己的 image 欄位，不是重建 10.1 設計但已暫緩的完整通用檔案管理模組（那個範圍更大，目前沒有第二個具體需求，見 6.10.7「有具體需求才擴展」同一個原則）——但底層純像素運算抽成獨立、與 `system_configs` 無關的可複用類別 `App\Support\File\PhotoClass`（只操作記憶體中的 `GdImage`／二進位字串，不碰檔案系統路徑，磁碟讀寫一律交給呼叫端透過 `Illuminate\Support\Facades\Storage` 處理——這樣才能吃到 Flysystem 自動建立目錄的能力，不會踩到「GD 直接寫入實體路徑不會自動建目錄」這個已知陷阱），之後若有其他模組需要上傳／縮圖，直接複用 `PhotoClass`，不用複製這支跟 `system_configs` 綁定的 Service。

**`PhotoClass` 的公開方法**：`createFromString`（解析二進位）、`makeThumbnail`（依 `cover`／`contain` 產生目標尺寸）、`resize`、`crop`、`expandCrop`（框選可超出原圖，超出處補背景色或透明，見下方「擴圖」）、`padToAspectRatio`（等比縮放後置中並補背景）、`createFilledCanvas`／`allocateHexColor`、`onOpaqueBackground`、`encode`（依格式輸出 `png`／`jpeg`／`webp`／`gif`，JPEG／GIF 自動壓白底）、`toPng`、`width`／`height`。

**設定項規格正規化**：`SystemConfig::imageSpec()` 把 `cfg_option` 正規化成一份「含預設值的完整規格」（缺漏或打錯的鍵一律回退預設，不讓一個手誤把上傳流程整組弄掛），並透過 `GET /api/system-configs` 的 `image_spec` 欄位與上傳／裁切端點的回應一起回給前端；前端不自己重複一套預設值邏輯，避免兩邊不一致。`logo` 的 baseline 規格是 **`{width:300, height:200, mode:'contain', format:'png', transparent:true}`**（300×200 透明 PNG，見 `SiteBaselineSeeder::seedSiteBasicConfig`）。

**原圖／成品分開存放**：上傳的原圖存在私有磁碟（`local`，落在 `storage/app/private/uploads/system-config/{id}-original.{ext}`），只給「編輯圖片」重新裁切用，不對外公開；裁切／縮圖後的成品存在公開磁碟（`public`，落在 `public/uploads/system-config/{id}-{timestamp}.{ext}`，見 6.3 已移除 `storage:link` 的實體目錄設計），`cfg_value` 存的就是這個公開網址，副檔名依輸出格式決定。公開檔名帶時間戳記，每次重新上傳／裁切都是新檔名並主動清掉舊檔（`deleteOldPublicFile`，只刪「看起來是自己管理的路徑」，避免 `cfg_value` 被手動改成別的網址時誤刪不相干的檔案），瀏覽器不會因為快取到舊檔名而看到舊圖；同一個設定項只保留一份原圖，重新上傳時副檔名即使改變也會先清掉舊的原圖再存新的，不會累積孤兒檔案。

**API**（`app/Api/SystemConfig/Services/SystemConfigImageService.php`、`SystemConfigController`）：
- `POST /api/system-configs/{id}/image`（multipart/form-data，欄位 `image`）：上傳原圖，依設定項規格自動產生圖片並更新 `cfg_value`；回應附最新的 `image_spec`。
- `PUT /api/system-configs/{id}/image/crop`：「編輯圖片」流程，從已存的原圖重新產生圖片。**`x`／`y` 可為負、`width`／`height` 可大於原圖**（＝擴圖，超出處依 `background` 補色／透明），另可帶 `output_width`／`output_height`（這次的輸出尺寸，省略＝設定項規格）、`mode`、`background`（色碼或 `transparent`）、`format`。尚未上傳過原圖時拒絕。
- `GET /api/system-configs/{id}/image/original`：回傳原圖二進位內容（非 JSON 包裝），供「編輯圖片」對話框載入預覽；原圖在私有磁碟，前端需帶身份驗證 header 呼叫（`authFetch` 讀成 blob 轉 `URL.createObjectURL`），不能直接當 `<img src>`。

**兩個實測踩到的坑（已修）**：
1. 輸出尺寸一度誤用「框選範圍的 `width`／`height`」當成輸出尺寸，導致裁切結果變成框選大小（實際測到 800×533 而不是規格的 300×200）。現在輸出尺寸只認 `output_width`／`output_height`，沒帶才回退設定項規格。
2. `optimize:clear`／`clear-compiled` 會刪掉 `bootstrap/cache` 的 package manifest，若同時有多個請求重建會失敗（與本節無關但同期踩到，見 6.8 第 8 點）。

**前端**：`SystemConfigEditor.vue` 的 `image` 類型分支顯示圖片預覽（透明區用棋盤格底紋，讓透明與白色底分辨得出來）、這個欄位的規格摘要（讀 `image_spec`）＋「上傳圖片」／「編輯圖片」兩個按鈕，兩者都各自呼叫上面的專用端點立即生效，不透過整批儲存（`PUT /api/system-configs/batch`）送出，因為圖片沒有可以打字送出的 `value`。「編輯圖片」開啟 `ImageCropDialog.vue`（純手刻拖曳框選，不引入第三方裁切套件，維持 10.3 一貫慣例；功能規格參考另一個已上線專案 `D:\www\nckuh_ems` 的 `ImageCropperDialog`，改寫成本專案的 Element Plus 版本）：

- **縮放**：0.3×～3× 倍率滑桿（含 ±／100% 按鈕），放大後可精準圈選小細節；縮放只影響顯示比例，不影響輸出。
- **即時輸出預覽**：右側用 canvas 依「這次的參數」畫出實際會產生的圖（先取框選範圍、超出原圖處補背景，再依模式縮放），與後端邏輯一致——避免「按了確認才發現裁掉 logo 一角／底色不是預期的透明」。透明區在預覽框也是棋盤格底紋。
- **輸出模式**：`cover`（填滿輸出；框選比例鎖定成輸出比例，不會變形）／`contain`（完整保留＋補背景；框選比例自由）。
- **擴圖添加背景**：`contain` 模式下 stage 會在原圖四周留一圈空間（原圖邊界以虛線標示），框選可以拉出原圖之外，超出處依選定的背景補純色或透明；框選超出時資訊列會提示「已擴圖」。後端對應 `PhotoClass::expandCrop`。
- **補背景**：透明／純色二選一（含色票挑色）；切到不支援透明的格式（JPG／GIF）時，「透明」選項會自動停用並改用純色，避免使用者以為輸出還是透明的（後端也強制壓白底）。
- **輸出格式與尺寸**：預設依設定項規格（網站Logo＝PNG 300×200），也可只針對這次輸出調整（例如這次要一張白底 JPG），並可一鍵「還原」回規格值。
- **框選比例記憶**：同一張來源圖下次開啟時沿用上次框選的相對位置與大小，減少重複調整。

CSP 的 `img-src` 已加入 `blob:`（見 `SecurityHeaders`），否則對話框載入原圖預覽時會被嚴格 CSP 擋下（已實測踩過）。

**已驗證**（真實 HTTP 請求 + `curl` multipart 上傳 + GD 逐像素檢查，環境：本機 Apache、`base_site_default` 站）：`GET /api/system-configs` 回傳 `logo` 的 `image_spec` 為 `300×200 / cover / png / transparent`；上傳 600×400 帶透明背景的 PNG 後，成品為 **300×200 PNG 且四角 alpha=127（透明保留）**；以 `x=-100, y=-50, width=800, height=533`＋`background:"#ff0000"`＋`mode:"contain"` 擴圖裁切，成品為 **300×200、角落為純紅 `rgba(255,0,0,0)`、中央為原圖藍色**（擴圖補背景生效）；以 `format:"jpeg"`＋`mode:"cover"` 裁切，成品為 **300×200 JPG、透明處已壓成白底**；帶 `output_width:150, output_height:100` 時輸出為 **150×100**（尺寸覆寫生效）；以 `mode:"contain"`＋`background:"transparent"` 裁切，成品透明區確實保留（alpha=127）。

## 18. 工具腳本與常用指令

**以下第 1-4 點為「腳手架」類指令（自動產生程式碼/設定骨架，供後續手動填充邏輯），刻意集中列在一起；第 5 點文件匯出、18.2 常用指令另外分類**：

1. 資料表腳手架腳本 `php artisan db:table:scaffold {table} [--only=migration|seed] [--connection=] [--output-path=database/migrations] [--chunk=500] [--force]`：依現有資料表的實際欄位型別產生對應的 migration 骨架（表名固定加 `_scaffold` 後綴，型別為盡量比對結果，`enum` 需人工補選項），`--only=seed` 則依現有資料每 `--chunk` 筆分批產生 `DB::table()->insert()` 的 seeder；不帶 `--only` 兩者都產生。掃描主站（HQ）的表（如 `sites`、`modules`）需帶 `--connection=mysql --output-path=database/hq/migrations`，否則 migration 會被誤放進子站的 `database/migrations`。僅供起手參考，正式使用前務必人工核對索引/外鍵/預設值，語法範例見 18.2 第 14-16 項。
1.1. 產生的公版 migration 別忘了跑一次 `php artisan site:migrate --all`（見 6.6、18.2 第 20 項）套用到所有已啟用站點，否則只停留在檔案系統、任何站點的資料庫都不會真的變更。
2. 模組初始化腳本 `php artisan module:make {module_code} [--portal={portal_key}] [--site={site_code}]`：自動建立模組目錄（`Controllers`/`Models`/`Services`/`routes`）、基底類、路由檔骨架；不帶 `--site` 建立在公版 `app/Api/{module}`，並以**互動問答**方式建立 `module.json`（顯示名稱、描述、是否為基礎包 `is_core`、排序、依賴哪些既有模組——選項直接取自現有模組掃描結果、要不要登記選單項目，可重複新增多筆，見 6.9 第 7 點），完成後執行 `module:sync` 自動登記到 `modules` 主表（需另外在主站後台開通給要用的子站）；`--no-interaction` 模式下所有問題一律採用預設值（等同快速建立最小骨架）。帶 `--site` 則建立在該子站客製化目錄 `app/Sites/{SiteCode}/Api/{module}`（見 9.2、命名慣例見 9.2 開頭），**不會**進入互動問答、也不寫 `module.json`、不登記進 `modules` 主表（客製化模組不受模組購買機制管轄，見 6.9 第 7 點）。此腳本只建立程式碼骨架，不含資料表；若模組需要新表，另外用第 1 點的 `db:table:scaffold` 或手寫 migration。
2.1. 模組清單同步腳本 `php artisan module:sync`：掃描所有 `app/Api/*/module.json` 並同步主站 `modules` 表（見 6.9 第 7 點）。`SiteBaselineSeeder` 已經會在每次子站種子時自動呼叫同一套邏輯，日常開發不用手動下這支指令，只有「新增了模組但沒有重跑任何子站 seeder」時才需要。
3. Vue 客製頁面骨架腳本 `php artisan vue:page:make {site_code} {page_name}`：在 `resources/vue_src/src/site-overrides/{site_code}/` 下建立客製化頁面的元件與路由片段骨架，供 Tier 2／Tier 3 客製化頁面（見 8.3、8.4）起手使用。
4. 插件骨架腳本 `php artisan plugin:make {function} {brand} {version}`：在 `app/Plugins/{Function}/{Brand}/{Version}/` 建立 Connector 類別骨架（自動實作對應功能的 Contract Interface，若該功能的 Contract 尚不存在則一併建立於 `app/Plugins/{Function}/Contracts/`），並提示登記進 `config/plugins.php`（見 6.10）。
5. API 文件匯出腳本 `php artisan swagger:generate [--pretty]`：掃描所有 Controller 上的 `@api-*` 註解，產生 `storage/app/private/docs/openapi.json`／`openapi.partner.json`／`openapi.hq.json`，規則見 12；等價的 composer 腳本、內部文件頁網址見 18.2 第 5-6 項、12.7。

### 18.1 環境設置（新環境／首次啟動才需要）
1. 設定 PowerShell UTF-8（避免中文顯示為 ??）
   ```powershell
   [Console]::InputEncoding = [System.Text.UTF8Encoding]::new($false)
   [Console]::OutputEncoding = [System.Text.UTF8Encoding]::new($false)
   chcp 65001 > $null
   ```
2. 指定使用 PHP 8.2 執行命令（避免誤用系統其他版本）
   ```powershell
   $env:Path = "D:\wamp64\bin\php\php8.2.29;" + $env:Path
   php -v
   ```
3. 安裝依賴
   ```powershell
   composer install
   ```
4. 建立 `.env`、產生金鑰
   ```powershell
   php artisan key:generate --ansi
   ```
5. 首次建立資料庫結構並灌入初始資料——多站模式下有**主站**與**子站**兩層各自獨立的資料庫（見 6.1、6.12），順序不可顛倒。**開始前先在 `.env` 設好 `HQ_ADMIN_PASSWORD`、`SITE_SUPER_ADMIN_PASSWORD`、`SITE_DELEGATION_ROOT_PASSWORD`**（見 `config/initial_accounts.php`、6.12「登入規範與初始帳號」），沒設定 seed 會直接報錯中斷：
   ```powershell
   # 第一步：建立主站資料庫結構與初始帳號（.env 的 DB_DATABASE 對應主站）
   php artisan migrate:fresh --path=database/hq/migrations --seed --seeder=Database\Hq\Seeders\HqDatabaseSeeder --ansi
   ```
   上一步會建立 `is_super_admin=true` 的 `hq_users` 帳號，帳號（`username`）為 `HQ_ADMIN_USERNAME`（預設 `hq_admin`），密碼即 `.env` 設定的值。取得帳號後：
   ```powershell
   # 第二步：用主站帳號登入後，透過 site:create 開設 1 號子站（公版預設開發/測試站，見 6.1）
   php artisan site:create default 預設開發站 --type=standard
   ```
   `site:create` 會自動建立子站資料庫、跑公版 migration 與 baseline seed（見 6.3），產生該站的超級管理員與初始系統管理員帳號，之後日常開發即以這個站點為主。

> 上列僅新環境／首次啟動需要；日常開發請改用 18.2 常用命令清單。

### 18.2 常用命令清單（Windows PowerShell，日常開發）
只列「開發過程中可能需要手動執行」的指令；Swagger 文件網址與 CLI 見 12.7，API 測試範例見 18.3。

1. 執行主站（HQ）資料庫遷移——**不要**直接執行不帶 `--path` 的 `php artisan migrate`：預設連線（`mysql`）就是主站資料庫，但預設 migration 路徑 `database/migrations` 放的是**子站**的表結構，兩者不可混用，否則會把子站專用的表建到主站資料庫裡；子站遷移見第 22 項 `site:migrate`
   ```powershell
   php artisan migrate --path=database/hq/migrations --ansi
   ```
2. 重新執行主站管理員 seed（沿用 `HQ_ADMIN_PASSWORD` 等 `.env` 設定，見 6.12）
   ```powershell
   php artisan db:seed --class=Database\Hq\Seeders\HqDatabaseSeeder --ansi
   ```
3. 重新執行某個子站的 baseline seed（不重建資料表，只重跑模塊/身份別/端口/初始帳號等必要初始資料）——**不要**用不帶 `--database` 的裸 `php artisan db:seed`：`SiteBaselineSeeder` 依賴的 `site_current` 連線只有在下列指令（或 `site:create`）幫你設定好時才是有效連線，裸執行會直接連到一個刻意設計成無法解析的假主機、報 `getaddrinfo` 連線錯誤中斷
   ```powershell
   php artisan site:migrate {site_id} --seed=Database\Seeders\SiteBaselineSeeder --ansi
   ```
4. 啟動本機服務
   ```powershell
   php artisan serve --host=127.0.0.1 --port=8000
   ```
5. 生成 Swagger/OpenAPI 文件（掃描 Controller 註解）
   ```powershell
   php artisan swagger:generate --pretty
   ```
6. 透過 composer 腳本生成 Swagger 文件（等價於第 5 項）
   ```powershell
   composer run swagger:generate
   ```
7. 同步內部 API 權限（模組級 + 接口級），`--site=` 為必填，指定要同步到哪個子站
   ```powershell
   php artisan permission:sync-api --site=1 --assign-admin=admin
   ```
8. 同步 Web 權限模板（*/entry/view/create/update/delete，可排除無需授權頁面）
   ```powershell
   php artisan permission:sync-web --site=1 --assign-admin=admin
   ```
9. 同步 Web 權限並包含未掛 `permission.web` 的路由（不建議常態使用）
   ```powershell
   php artisan permission:sync-web --site=1 --include-unprotected --assign-admin=admin
   ```
9.1. 稽核角色權限碼是否對應有效目錄、有無跨端口洩漏（見 7.1「授權上限」）
   ```powershell
   php artisan permission:audit-ceiling --site=1
   ```
10. 手動清理過期 JWT 會話資料
    ```powershell
    php artisan jwt:sessions:prune
    ```
11. 預覽 JWT 清理筆數（不實際刪除）
    ```powershell
    php artisan jwt:sessions:prune --dry-run
    ```
12. 編譯 VUE 頁面（清除 vue 舊檔案後重新建置，設計見 8.1/8.4）
    ```powershell
    # 共用建置（Tier 1/2 公版與輕度客製站點）
    php artisan vue:build --clean

    # 指定站點獨立建置（Tier 3，輸出到 public/vue-sites/{site_code}）
    php artisan vue:build --site={site_code} --clean

    # 重新建置所有 Tier 3 獨立建置站點（CI/CD 用）
    php artisan vue:build --all-sites
    ```
    - `resources/vue_src` 為前台 Vue 頁面原始碼（不可被直接存取，見 9）。
    - `public/vue` 為共用編譯後的代碼；`public/vue-sites/{site_code}` 為個別 Tier 3 站點的獨立編譯產物。
    - 日常開發前台頁面用 `npm run vue:dev`（獨立於 Blade 資產的 `npm run dev`，見 4.2.1 第 2 點），瀏覽器直接開 Vite 印出的網址（例如 `http://localhost:5173/vue/`），不經過 `php artisan serve`。
12.1. 主站後台頁面（見 6.12）：獨立於前台的第三套建置，沒有 `--site`／Tier 概念
    ```powershell
    # 開發，瀏覽器直接開 Vite 印出的網址（預設 http://localhost:5175/），不經過 php artisan serve
    npm run hq:dev

    # 建置，輸出到 public/hq-assets（固定路徑，不像前台依 Tier 分岔）
    npm run hq:build
    ```
    - `resources/vue_src_hq` 為主站後台頁面原始碼；建置後透過 `GET /hq`、`GET /hq/{any}`（`App\Support\Http\Controllers\HqSpaController`）存取，瀏覽器內 Vue Router 處理子路徑。
13. 打包單一子站為現場部署包（只篩選保留該站點客製化目錄、排除其他站點目錄，不合併攤平；設計見 6.8）
    ```powershell
    # 輸出單站部署包（現場單站部署用，見 6.8；只篩選該站客製化目錄，不做合併攤平）
    php artisan site:package vendor_a --output=D:\deploy\vendor_a
    ```
    - 同一件事也可以在主站後台做（不用打指令）：「子站列表」每列按「輸出部署包」，或在「子站詳細」頁的「單站部署輸出」區塊自訂輸出資料夾，兩者都呼叫 `POST /api/hq/sites/{siteId}/package`（見 6.8、6.12）。
    - 匯出包已含可直接使用的 `.env`（`APP_KEY`／JWT secret 自動產生）、`storage/` 目錄骨架與現場部署說明 `DEPLOY.md`；現場只需調整資料庫連線資訊並執行 `composer install`（見 6.8 第 3、8 點）。

    **以下第 14-19 項為「腳手架」類指令（自動產生程式碼/設定骨架），集中列在一起，設計總覽見 18 第 1-4 點：**
14. 依資料表產生 migration + seed 腳本（預設同時產生）
    ```powershell
    php artisan db:table:scaffold users
    ```
15. 只產生 migration 快照
    ```powershell
    php artisan db:table:scaffold users --only=migration
    ```
16. 只產生 seed 腳本（每批 500 筆，若存在則覆蓋）
    ```powershell
    php artisan db:table:scaffold users --only=seed --chunk=500 --force
    ```
    - 預設用當前預設連線（子站 `site_current` 或本機直連的 `mysql`）掃描表結構；掃描主站資料庫的表（如 `sites`、`modules`）需帶 `--connection=mysql --output-path=database/hq/migrations`，否則 migration 會被誤放進子站的 `database/migrations`。
    - 產生的 migration 資料表名稱固定加 `_scaffold` 後綴、型別為盡量比對結果（`enum` 需要人工補選項），僅供起手參考，正式使用前務必人工核對索引/外鍵/預設值。
17. 建立新模組骨架（公版或子站客製化，設計見 18 第 2 點）
    ```powershell
    # 公版模組（互動問答建立 module.json，見 18 第 2 點）
    php artisan module:make order

    # 指定端口
    php artisan module:make order --portal=vendor

    # 子站客製化全新模組（放在 app/Sites/VendorA/Api/order，見 9.2 命名慣例）
    php artisan module:make order --site=vendor_a
    ```
17.1. 同步模組清單（掃描 `app/Api/*/module.json` 並同步主站 `modules` 表，設計見 6.9 第 7 點、18 第 2.1 點；日常不需要手動下，只有新增模組但沒有重跑任何子站 seeder 時才需要）
    ```powershell
    php artisan module:sync
    ```
18. 建立子站客製化 Vue 頁面骨架（設計見 18 第 3 點）
    ```powershell
    php artisan vue:page:make vendor_a order-dashboard
    ```
19. 建立新插件骨架（AD／SSO／設備對接，設計見 6.10、18 第 4 點）
    ```powershell
    php artisan plugin:make ad microsoft v2019
    php artisan plugin:make sso okta v1
    ```
20. 同步子站 Schema（見 6.6；公版路徑固定套用，客製化站點專屬 migration 依慣例路徑自動偵測套用，不需要手動帶 --path）
    ```powershell
    # 對所有已啟用站點（含客製化）批次執行；每個站點都會自動套用公版
    # migration，若該站在 database/sites/{code}/migrations 下有專屬 migration
    # 也會一併自動套用
    php artisan site:migrate --all

    # 對單一站點執行，邏輯同上（自動判斷公版／該站專屬）
    php artisan site:migrate 2

    # 專屬 migration 不遵循慣例路徑時，才需要明確帶 --path 覆寫成單一路徑
    php artisan site:migrate 2 --path=database/sites/vendor_a/migrations
    ```
21. 清理建站失敗留下的孤兒資料庫與設定檔（見 6.3「資源耗盡與失敗補償」，不自動靜默重試，需人工確認）
    ```powershell
    php artisan site:cleanup-failed
    php artisan site:cleanup-failed 5
    ```

#### 18.2.1 權限清單顯示與過濾規則（設定參考，非指令）
1. Web 權限同步排除規則：設定位置 `config/webbase.php -> permission.web_sync`。
   - `include_unprotected_routes`：預設 `false`，只同步掛載 `permission.web` 的路由。
   - `exclude_paths`：路徑排除清單，支援萬用字元（例如 `/docs/*`）。
   - `exclude_route_names`：route name 排除清單（例如 `ignition.*`）。
2. 授權清單顯示格式配置（RoleAdmin）：設定位置 `config/webbase.php -> permission.catalog_display`。
   - `domain_prefixes`：前綴（例如 `api => [API]`、`web => [WEB]`）。
   - `modules`：模組名稱（例如 `user => 使用者`）。
   - `actions`：動作名稱（例如 `write => 新增/編輯`）。
   - `templates`：完整控制顯示字串（支援 `{prefix} {module} {action} {method} {path} {code}`），例如 `module` 模板為 `{prefix}{module} {action}` 時輸出 `[API]使用者 新增/編輯`。
   - 回退規則：模板需要的映射不存在時（module/action 未配置），回退到資料庫原始 `name`。
3. 授權清單自動過濾規則（RoleAdmin）：
   - API endpoint 權限：僅顯示路由同時掛載 `jwt.auth` + `permission.api` 的接口。
   - API module 權限：僅顯示有對應受保護 endpoint 的 action（避免出現無意義的 `write/delete`）。
   - Web 權限：僅顯示路由掛載 `permission.web` 的模組權限。
   - 目的：Web/API 中「無需授權」的部分不進入授權清單。
   - 粒度說明：`module` 同時回傳 API 模組級（`api.{module}.view/write/delete/*`）與 Web 模組級（`web.{module}.*`）；`web_action` 只回傳 Web 細粒度（`entry/view/create/update/delete`），不含 `*`。

### 18.3 初始測試帳號與 API 測試範例
1. 子站系統管理員帳號：`config/initial_accounts.php` 的 `site_delegation_root.username`（預設 `admin`），密碼為 `.env` 的 `SITE_DELEGATION_ROOT_PASSWORD`。
2. 子站超級管理員帳號：`site_super_admin.username`（預設 `super_admin`），密碼為 `SITE_SUPER_ADMIN_PASSWORD`。
3. 主站初始管理員帳號：`hq_admin.username`（預設 `hq_admin`），密碼為 `HQ_ADMIN_PASSWORD`。

登入一律用帳號（`username`）+ 密碼，不使用 email（見 6.12）；登入子站時需指定要進入哪個 `portal_key`（見 7.2）。

一般登入測試：
```powershell
$loginBody = @{ username = 'admin'; password = '請填入 SITE_DELEGATION_ROOT_PASSWORD'; portal_key = 'admin' } | ConvertTo-Json
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/auth/login" -Method Post -ContentType "application/json" -Body $loginBody

# 測試第一個 API（ping）
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/ping"

# 測試受保護 API（需先登入取得 token）
$token = "請填入登入返回的 access_token"
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/user/profile" -Headers @{ Authorization = "Bearer $token" }
```

廠商模式（App Token）測試流程：
```powershell
# 1. 申請 app token（APPID + APPSECRET）
$appBody = @{ app_id = '請填入APPID'; app_secret = '請填入APPSECRET' } | ConvertTo-Json
$appTokenResp = Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/factory/token" -Method Post -ContentType "application/json" -Body $appBody
$appToken = $appTokenResp.data.access_token

# 2. app token 換 user token（廠商模式登入）
$loginBody = @{ username = 'admin'; password = '請填入 SITE_DELEGATION_ROOT_PASSWORD'; portal_key = 'vendor' } | ConvertTo-Json
$userTokenResp = Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/factory/auth/login" -Method Post -ContentType "application/json" -Headers @{ Authorization = "Bearer $appToken" } -Body $loginBody
$userToken = $userTokenResp.data.access_token

# 3. 使用 user token 呼叫廠商模式 profile
Invoke-RestMethod -Uri "http://127.0.0.1:8000/api/factory/user/profile" -Headers @{ Authorization = "Bearer $userToken" }
```

### 18.4 Laravel Scheduler 部署（生產環境）
1. Scheduler 設計原則
   - Laravel 只需要「每分鐘觸發一次」`php artisan schedule:run`。
   - 實際要跑的任務由程式內排程規則決定（例如 `dailyAt('03:20')`）。
2. Linux（crontab）部署方式
   - 進入專案目錄後，設定 crontab 每分鐘執行一次：

```bash
* * * * * cd /path/to/base && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

   - 若主機有多個 PHP 版本，請改用 PHP 8.2 的完整路徑。
   - 設定後可用 `crontab -l` 確認是否已生效。
3. Windows（工作排程器）部署方式
   - 建立一個「每 1 分鐘執行」的工作。
   - 程式/指令碼填入：`D:\wamp64\bin\php\php8.2.29\php.exe`
   - 新增引數填入：`artisan schedule:run`
   - 起始於（Start in）填入：`D:\www\base`
   - 勾選「不論使用者是否登入都執行」與「使用最高權限執行」（視環境權限政策調整）。
4. 部署後驗證
   - 可先手動執行一次：

```powershell
php artisan schedule:run
```

   - 再手動驗證 JWT 清理命令：

```powershell
php artisan jwt:sessions:prune --dry-run
```

   - 若排程已生效，應可在日誌或資料表觀察到定時執行結果。
5. 與本專案相關的 Scheduler 設定
   - `JWT_SESSION_PRUNE_ENABLED=true`：啟用 JWT 會話清理排程。
   - `JWT_SESSION_PRUNE_TIME=03:20`：每日清理觸發時間。
   - `JWT_SESSION_PRUNE_RETENTION_DAYS=30`：保留天數。
   - 設定變更後請執行：`php artisan optimize:clear`

## 19. 尚待後續明確的事項
1. `portals` 的初始清單（例如 admin / workshop / vendor / declare 各代表什麼端口）與 `identity_types` 身份分類清單，待業務需求明確後再定案 seed 資料。
2. `modules` 主表已改由掃描 `module.json` 自動產生（見 6.9 第 7 點），既有 9 個模組的代碼／顯示名稱已定案；但供客戶購買方案對照用的**商業命名與方案分組**（例如同一批模組要包裝成哪個銷售方案的名稱）仍待業務需求明確後再另行規劃，目前 `name`／`description` 只是工程側的顯示名稱。
3. 未來若需要跨子站資料彙總（例如彙整各子站商品成一份總表），主站的角色與程式碼需另行擴充設計（見 6.1）。
4. `config/plugins.php` 的初始插件清單（實際要對接哪些品牌/版本的 AD、SSO、設備），待各案場需求明確後陸續開發登記（見 6.10）。
5. 主站（`hq_users`，見 6.12）目前僅有 `is_super_admin` 單一顆粒度；若日後主站操作人員變多、需要區分「僅能查看報表」與「可執行開站/模塊管理」等更細的分工，再擴充角色設計，現階段不做過度設計。

---

## 開發約定
- 每次實作之前應該閱讀本 README.MD 文件，確認是否符合要求及規範
- 實作完成後，需要工程師後續開發是遵循的或是工程師需要知道的內容寫回本文檔
- 有新的 Cli 腳本或是共用的內容，應簡潔的解釋用法。
- 代碼中應該加上註解，避免後續維護時，無法理解

- API開發約定
1. API接口應該放在app\Api目錄下，並且建立模塊目錄，按照MSC分層，分別放在 Controllers、Models、Services目錄下，控制器需要使用 $this->validateInput 來過濾用戶的入參，主要邏輯應在 Services 下完成。路由也是在模塊下的 routes 目錄下進行註冊即可。**Model 一律放在該模塊自己的 `Models/` 目錄下，不集中放頂層共用目錄**——客戶按模塊購買服務，每個模塊必須是能整包移除的自我完整單位；只有真正不屬於任何單一模塊的框架核心 Model（`AuditLog`、`Portal`、`Permission`、`IdentityType` 等）才放 `app/Support/{Concept}/Models/`，歸屬原則與範例見 9 節目錄樹。
2. 所有 Swagger／API 文件相關標註規範（`@api-xxxx` 風格、字典標註、多行 Description、Response 欄位定義）已整合於「12. Swagger／OpenAPI 文件規範」，撰寫接口時務必依該章節規則標註。
3. 共用工具請放在 app\Support 目錄下，按照工具類型建立目錄，如需要使用工具也應該先查看是否有現成的工具
4. 關於本項目的文檔在 README.MD 描述本項目的一些邏輯，如果需要也可以對文檔補充，盡可能的簡單、簡潔
5. 一般情況下，工作完成不需要總結，如果比較重要的情況，需要總結也是盡可能簡單說明，簡潔清晰的說明即可
6. 新建的測試腳本記得刪除、盡量不要建立一大堆腳本
7. 寫命令時使用powershell語法，不然不支持
8. 如果不是特別明確說明，所有的刪除API接口都是軟刪除
9. 所有接口遇到分頁是共用的，應該使用統一的 app\Support\Api\Concerns\PaginationHelper.php 方法實現，都需要備註per_page參數-1代表不分頁
10. 開發過程中，應該標註邏輯注釋，迭代文件不可輕易去掉原來的已有的注釋、函數的入參說明...等信息
11. API代碼中不允許直接使用文字，需要使用語言包。實際採用 Laravel 標準的 `lang/{locale}/{file}.php`（`base_path('lang')`，不是 `app/lang`，這樣不需要額外呼叫 `useLangPath()` 覆寫框架預設路徑）：`app/Api`、`app/Support`（含 `ErrorCatalog`、`PluginManager`、`SsoManager` 等會被 API 請求路徑呼叫到的共用工具）、`app/Web/Sso`（SSO 落地頁，見 8.6）、`app/Web/Impersonation`（跳轉登入落地頁，見 6.11）統一用 `lang/zh_TW/api.php`；`app/Hq` 因程式碼與資料庫完全獨立（見 6.5），文字另外放 `lang/zh_TW/hq.php`，不與 api.php 混用。`.env` 的 `APP_LOCALE`/`APP_FALLBACK_LOCALE` 已設為 `zh_TW`。此規則不含 CLI 指令（`app/Console/Commands`）的終端機輸出訊息與 `permission:sync-api`／`swagger:generate` 的標註驗證錯誤（`ApiAnnotationValidator`）——這些是開發期間才看得到的建置期診斷文字，不是執行期回應真實使用者的 API 內容，比照專案裡 migration/seeder 的 `$this->command?->info()` 輸出，不受此規則限制。
12. 請以專業的角度，在有疑問或是方案不夠明確的時候，請一定要提出疑問，而不是隨意自己決策，導致完成的代碼與我的設計有偏差
13. 如果需要了解資料庫結構或是數據情況，可以使用 dbcode MCP 查詢；後端 API 資料庫、APP 端 sqlite 資料庫各自的連線名稱請依實際建置環境設定為準。
14. 新模塊開發前，需先確認：(1) 是否已在 `modules` 主表登記（見 6.9），未登記 `permission:sync-api` 會報錯中斷；(2) 這個模塊要提供給哪些端口（`portal_key`），若跨多端口，Controller 需依 9.1.1 的命名規則加上 portal 前綴，Model／Service 維持共用不拆分；(3) 模塊路由需掛載 `module:{code}` middleware（6.9），確保未開通該模塊的子站會被擋下；(4) 若模塊需開放給廠商 App Token（`/api/factory/*`）呼叫，需確認路由掛載在 `factory` 命名空間下並比照 12.8 的受眾隔離規則，避免一般路徑被 App Token 誤訪問。
15. **禁止對 `site_current` 連線的 Model 使用 Laravel 隱式路由模型綁定**（Controller 方法參數直接型別提示成 Model，例如 `function update(Role $role)`）。Laravel 的 `SubstituteBindings` 會在請求進到 Controller 方法前先查一次資料庫，這個時間點在本專案的中介層順序下拿到的是 `config/database.php` 裡刻意設成無效主機的 `site_current` 預留值（見 6.4），查詢一律失敗且會噴出不易辨識的錯誤（曾表現為 `Malformed UTF-8 characters` 這種文不對題的訊息，因為 SQL 例外訊息本身混進了系統語系的錯誤字串，JSON 編碼失敗才蓋掉了真正原因）。正確做法：路由參數收 `int`（例如 `function update(int $role)`），進入方法本體後（`site.context` 已執行完畢）自己手動 `Role::query()->findOrFail($role)`，見 `RoleController`／`UserController` 的寫法。
16. **每個檔案開頭需加上文件 Header 註解**，格式固定如下（PHP／JS／Vue 一律比照辦理，Vue 檔案放在 `<script>` 區塊最上方）：
    ```php
    /* *****************************************
    File: DashboardController.php
    Author: cliffdean yangtinghui@gmail.com
    Date: 2026-06-18
    Description: Dashboard 統計數據控制器
    ***************************************** */
    ```
    `File` 填實際檔名（不含路徑）；`Author` 固定填實際撰寫者；`Date` 為建立該檔案的日期（`YYYY-MM-DD`），既有檔案回補時填實際回補當下日期，不用回頭猜原始建立時間；`Description` 一句話說明這個檔案的用途，不是重複檔名。這段 Header 與檔案內原有的功能/邏輯註解（例如 Controller 開頭說明某段設計考量的 `//` 註解）是兩件事，**不互相取代**——Header 只負責「這是誰在什麼時候建的、做什麼用」，原有的邏輯性註解照舊保留在 Header 之後。
17. **Swagger 標註（`@api-description` 等）必須自成一體，禁止用「見 README X.X」這種引用式寫法把讀者導去別的文件才能看懂**——Swagger 文件本身就是開發時唯一要參照的依據，工程師不該還要跳去翻 README 才知道一支 API 在幹嘛。撰寫時直接把該接口的實際邏輯、行為、需注意事項寫清楚：用到的字典要把字典名稱與每個選項的意義都列出來（`@api-dict`）、入參的驗證規則與限制要講明白、回傳值每個欄位的意義與可能情況（例如某欄位在什麼狀況下會是 null）都要交代。README 仍然是系統設計的權威文件，但那是給理解整體架構用的，不是給「這支 API 到底怎麼用」這種開發當下要查的問題用——這兩者服務不同的讀者情境，不能互相取代，見 12 節既有規則的實際延伸。

---

## 20. 開發中手動調整配置詳解（對應 README 第 9 節）

> 本章是 README 第 9、10 節的完整版（README 只留精簡指引）。本章內「20.x／21.x」指本章小節；未特別標明的「第 N 節」「N.N」（如 3.2、4.4、第 7 節）是 README **精簡前的舊版節次**（開發約定＝現 README 第 3 節、命令＝第 4 節、常見任務＝第 5 節、前端＝第 6 節；舊版全文已移入本文件 22 章或併入上述章節），其餘編號為本文件章節。

`module:make`、`plugin:make`、`vue:page:make` 只產生**骨架**，下列事項都得人工補。本節依「想做什麼」查表，每項都寫明：**改哪個檔 → 改完要跑什麼 → 常見踩坑**。

> 名詞：本專案的「**端口**」= `portal`（登入入口＋角色／選單的分組，如 `admin`），**不是** TCP port；TCP port（dev server 埠號）見 20.6。

### 20.0 速查：想做什麼 → 改哪裡

| 想做什麼 | 要改的檔案／位置 | 改完要跑 | 小節 |
| --- | --- | --- | --- |
| 新增一個功能模塊 | `module:make` 後補 Controller／`routes/api.php`／`module.json` | `permission:sync-api`、`swagger:generate`，主站開通模塊 | 20.1 |
| 左側選單加一項 | 模塊的 `module.json` 的 `menus`，**加** 前端 `router/index.js` ＋頁面 `.vue` | 見 20.2 重新套用選單 | 20.2 |
| 左側選單加「分組」 | `SiteBaselineSeeder::seedAdminMenu()` 的 `$groups` | 重跑 baseline seed | 20.2 |
| 新增端口（portal） | `portals` 資料列＋該端口的角色／選單／前端 SPA | 見 20.3 | 20.3 |
| 權限（誰能呼叫哪支 API） | Controller 的 `@api-*` 註解＋路由 `permission.api:{code}` | `permission:sync-api` → 後台「角色管理」勾選 | 20.4 |
| 模塊依賴（A 模塊要先有 B） | `module.json` 的 `depends` | 主站重新開通 | 20.5 |
| 套件依賴（composer／npm） | `composer.json`／`package.json` | `composer install`／`npm install` | 20.5 |
| 改 dev server／serve 的 TCP port | `vite.*.config.js`、`.env` 的 `VUE_DEV_API_PROXY`、`--port=` | 重啟 dev server | 20.6 |
| 新增系統設定項／字典 | `SiteBaselineSeeder` 的 `seedSiteBasicConfig()` 等 | 對既有站重跑 seed | 20.7 |
| 新增 `.env`／config 設定 | `.env.example`＋`config/*.php` | `optimize:clear` | 20.8 |
| 新增錯誤碼／文案 | `ErrorCode`、`ErrorCatalog`、`lang/zh_TW/*.php` | — | 20.9 |
| 啟用／登記插件 | `config/plugins.php` | 後台插件管理頁啟用 | 20.10 |

> **共通陷阱**：`SiteBaselineSeeder` 只在「建新站」或「手動重跑」時執行。你改了 seeder，**既有子站不會自動套用**：
> ```powershell
> php artisan site:migrate --all --seed=Database\Seeders\SiteBaselineSeeder --ansi   # 全部站
> php artisan site:migrate 1 --seed=Database\Seeders\SiteBaselineSeeder --ansi       # 只重跑 1 號站
> ```
> seeder 內多是 `updateOrCreate`，重跑不會重複建立，但會**覆蓋**你在後台手改過的同一列（例如選單標題、圖示），見 20.2。

### 20.1 新增一個功能模塊（完整檢查清單）

```powershell
php artisan module:make order        # 互動問答會產生 module.json；加 --portal=vendor 則 Controller 名稱帶端口前綴
```

產生的檔案在 `app/Api/Order/`（`Controllers`、`Services`、`Models`、`routes/api.php`、`module.json`）。**骨架刻意只給最小內容，以下要手動補：**

| # | 要做的事 | 說明 |
| --- | --- | --- |
| 1 | **補路由的認證與權限 middleware** | 骨架 `routes/api.php` 只有 `module:order`，**沒有登入驗證也沒有權限檢查**，等於任何人可呼叫。比照 `app/Api/Dictionary/routes/api.php` 補成下面寫法 |
| 2 | **每個 Controller 方法加 `@api-*` 註解** | 沒有註解就不會被 `permission:sync-api` 掃到，也沒有 Swagger；缺少必填標註會**直接中斷**而非警告（見 4.4） |
| 3 | **Model 放進自己的模塊** | `app/Api/Order/Models/`；migration 放 `database/migrations/`（子站公版），之後 `site:migrate --all` |
| 4 | **檢查 `module.json`** | `is_core`、`depends`、`menus` 是否正確（見 20.2、20.5） |
| 5 | **同步模塊清單** | `module:make` 會自動同步一次；之後改 `module.json` 要手動 `php artisan module:sync` |
| 6 | **同步權限碼** | `php artisan permission:sync-api --site=1 --assign-admin=admin` |
| 7 | **（非核心模塊）主站開通** | 主站後台「模塊管理」對要用的子站勾選；`is_core=false` 預設未開通，未開通時 API 回 `20016`、選單也不顯示 |
| 8 | **前端頁面** | 見 20.2（路由＋頁面） |
| 9 | **文案走語言包** | `lang/zh_TW/api.php`，不得硬編碼，見第 7 節 |

路由標準寫法（middleware 順序：`module` → `jwt.auth` → `permission.api`，`permission.api` 一定要在 `jwt.auth` 之後）：

```php
Route::middleware(['module:order', 'jwt.auth'])->prefix('orders')->group(function () {
    Route::middleware('permission.api:api.order.index')->get('/', [OrderController::class, 'index']);
    Route::middleware('permission.api:api.order.store')->post('/', [OrderController::class, 'store']);
});
```

> ⚠ **權限碼固定為 `api.{模塊代碼}.{Controller 方法名}`**（`PermissionSyncApiCommand::syncOne`）。路由寫的 `permission.api:api.order.store` 必須與 Controller 的方法名 `store` 完全一致；方法改名就要同步改路由，否則權限碼對不上，所有人（含管理員以外的角色）都會 403。
> ⚠ `module_code` 必須與目錄名的 snake 形式一致（`OrderItem` → `order_item`），不一致 `module:sync` 直接報錯。
> ⚠ Controller 內要被子站覆寫的 Service，用**方法參數注入**，不要用建構子注入（見第 7 節第 4 點）。

### 20.2 左側選單（側邊欄）

側邊欄資料來自 `menus` 資料表（**每個子站各一份**），不是前端寫死。要讓一個頁面出現在選單並能點進去，**三件事缺一不可**：

**① 選單資料（資料庫）** — 有兩種來源，擇一：

| 方式 | 適用 | 做法 |
| --- | --- | --- |
| **A. 寫在 `module.json`（建議）** | 屬於某個模塊的頁面 | 編輯 `app/Api/{Module}/module.json` 的 `menus`；模塊開通時自動 upsert 進該站 `menus` |
| **B. 後台「選單管理」頁手動新增** | 臨時／單站調整 | 只影響當前站，不會進版控，換新站要重做 |

`module.json` 的 `menus` 範例（欄位對應 `menus` 表）：

```json
"menus": [
  {
    "portal": "admin",            // 掛在哪個端口的側邊欄（portal_key，必須已存在於 portals 表）
    "key": "orders",              // 同端口內唯一；upsert 的比對鍵（portal+key）
    "title": "訂單管理",
    "subtitle": "訂單查詢與處理",
    "icon": "Tickets",            // Element Plus Icons 名稱，名稱錯誤則不顯示圖示（不報錯）
    "path": "/orders",            // 必須與 ③ 的前端路由 path 完全一致（含開頭 /）
    "permission_code": "api.order.index",   // 有此權限（或該模塊任一 web.* 權限）才看得到
    "group": "group-system",      // 掛在哪個分組；填 null 則為頂層節點
    "sort": 10                    // 同層排序，數字小在前
  }
]
```

**② 前端路由＋頁面（原始碼）** — 選單只是「連結」，沒有對應路由會進 404：

1. 新增頁面：`resources/vue_src_admin/src/pages/{module}/XxxList.vue`
2. 在 `resources/vue_src_admin/src/router/index.js` 的 `children` 加一行（放在 404 萬用路由**之前**）：
   ```js
   { path: 'orders', name: 'orders', meta: { title: '訂單管理' }, component: () => import('../pages/order/OrderList.vue') },
   ```
3. 子站後台改完要重建：`npm run admin:build`（開發中用 `npm run admin:dev`）。

**③ 讓選單真的套用到子站**

- 新站：建站時自動帶入核心模塊的選單。
- 既有站的**非核心模塊**：主站「開通」該模塊時會即時補註冊選單。
- 既有站的**核心模塊**，或你事後**修改了 `module.json` 的 `menus`**：不會自動更新，需重跑 baseline seed（見 20.0 共通陷阱）。⚠ 這會用 `module.json` 的值**覆蓋**後台選單管理頁手改過的 `title`／`subtitle`／`icon`／`path`／`permission_code`／`sort`。
- 關閉模塊**不會刪除**選單列，只是不顯示。

**可見性規則（選單沒出現時對照檢查）**

1. 該模塊對目前站點**已開通**（核心模塊恆開通）。
2. 登入者對該葉節點有權限：持有該模塊任一 `web.*` 權限碼，或持有節點 `permission_code`；**兩者皆空且非 `login_only` 的葉節點一律不顯示**。
3. 超級管理員、該端口的端口管理員直接通過；一般角色要在「角色管理」勾選對應權限。
4. 選單有快取，後台改選單／開關模塊會自動換版；仍異常可 `php artisan optimize:clear`。

**新增「分組」節點**：分組沒有 `module.json` 機制，是 `SiteBaselineSeeder::seedAdminMenu()` 的 `$groups` 陣列（目前 `group-account`、`group-system`，**僅 `admin` 端口**）。要加分組就在該陣列補一筆（`key` 建議用 `group-` 開頭），再重跑 seed；`module.json` 才能用 `"group": "你的 key"` 引用。新端口要有分組，需自行仿照寫一份 seed（分組以 `portal_key + key` 為準）。

### 20.3 新增端口（portal）

端口 = 一個獨立的登入入口。**後端只要有資料列就能登入**；但要真的有可用的畫面，還得另外建前端。依序：

1. **建立端口資料列**（兩種方式擇一）
   - 後台「端口管理」頁新增（只對當前站）。
   - 寫進 `SiteBaselineSeeder::run()`（仿 `admin` 端口那段 `Portal::updateOrCreate`），之後重跑 seed，新站才會自動帶出。
   欄位：`portal_key`（小寫英數底線，建立後視為不可改）、`admin_only`（`true`＝僅端口管理員可登入）、`requires_login`（`false`＝允許訪客瀏覽）、`is_enabled`。端口**不提供刪除**。
2. **建立該端口的角色**：後台「角色管理」，角色必須屬於某一端口、不可跨端口共用。
3. **開通使用者**：在「使用者管理」把使用者加入該端口（`user_portal_access`）並指派角色或設為端口管理員。超級管理員不需此步驟。
4. **標註 API 適用端口**：Controller 的 `@api-portal` 填新的 `portal_key`（或 `*` 代表不限端口）；標了不存在的端口，`permission:sync-api`／`swagger:generate` 會報錯中斷。之後重跑 `permission:sync-api`。
5. **選單**：`module.json` 的 `menus[].portal` 填新端口（20.2）。
6. **前端 SPA（真的要有畫面才需要）**：目前只有 `admin`（子站後台）、`hq`（主站後台）、`vue`（前台）三套。新端口通常是複製 `admin` 這一套，需要改動的地方：
   | 項目 | 位置 |
   | --- | --- |
   | 前端原始碼目錄 | `resources/vue_src_{portal}/`（複製 `vue_src_admin`） |
   | **登入送出的 `portal_key`** | `stores/auth.js` 的 `PORTAL_KEY` 常數（寫死，忘了改會登進 admin 端口） |
   | 路由 base／側邊欄讀取的端口 | `main.js`（`resolveBase`）、`router/index.js` |
   | Vite 設定 | 複製 `vite.admin.config.js` → `vite.{portal}.config.js`，改 `root`、`base`（`/{portal}-assets/`）、`outDir`、dev `port`（見 20.6） |
   | npm scripts | `package.json` 加 `{portal}:dev`、`{portal}:build` |
   | Laravel 入口 | 仿 `AdminSpaController` 新增控制器，並在 `routes/web.php` 註冊 `/{portal}`、`/{portal}/{any}`，**要放在最後的 `Route::fallback` 之前** |
   | 部署／打包 | 新增的 `public/{portal}-assets` 為編譯產物不進版控，新環境要補建置（見 3.2） |

> 提醒：目前跳轉登入（主站→子站）只有 `admin` 端口有落地頁，其他端口會導向 `/{portal_key}`，見 1.2 第 4 點。
> 嚴格 CSP 的新端口前端：不要用 `:style` 動態綁定，見 4.2.1。

### 20.4 權限（API 權限碼 → 角色）

權限是三段資料串起來的，**任何一段漏了，結果都是 403**：

```text
Controller 方法 + @api-* 註解  ──permission:sync-api──▶  permissions 表（api.{module}.{method}）
路由 permission.api:{code}      ──請求時比對──▶            目前登入端口的角色是否持有該碼
後台「角色管理」勾選            ──────────────▶            roles 的權限碼
```

- **新增／改名 API 後**：`php artisan permission:sync-api --site=1 --assign-admin=admin`。`--assign-admin` 會把新權限碼併進指定帳號的角色；端口管理員、超級管理員本來就全開，不需要。
- **新權限要給其他角色**：後台「角色管理」勾選（端口－模塊－接口三層樹，見 7.5）。
- **權限碼萬用字元**：`api.order.*` 代表該模塊全部。
- **只能授予自己持有的**（授權上限），超級管理員／系統管理員除外；稽核用 `php artisan permission:audit-ceiling --site=1`。
- **Web 頁面權限**（`app/Web/...` 的 Blade 頁面）：路由掛 `permission.web:web.{module}.{action}`（action 限 `entry/view/create/update/delete`），再執行 `php artisan permission:sync-web --site=1 --assign-admin=admin`。
- **某支 API 不想綁權限**：路由只掛 `jwt.auth`（登入即可）；完全公開則不掛，但**需評估風險**，並在註解 `@api-portal *`。
- **權限碼存在子站各自的資料庫**：多個子站要各跑一次 `permission:sync-api --site=N`。
- 角色／使用者這類**跨端口操作**的路由刻意不掛 `permission.api`（Controller 內自行判斷，見 7.1），新增類似功能前先讀該節。

### 20.5 依賴設定

**模塊依賴**（A 模塊需要 B 模塊先存在）— 編輯 A 的 `module.json`：

```json
"depends": ["user", "role"]        // 填對方的 module code（snake_case）
```

- 主站**開通 A 時會自動連動開通 B**（回應 `cascaded_modules` 會列出）。
- **關閉 B** 時若還有已開通的 A 依賴它，會被拒絕並列出擋住的模塊；要先關 A。
- 改完執行 `php artisan module:sync`；已開通的站不需重開。
- 單站部署包（`site:package`）依這份依賴遞移展開，漏寫 `depends` 會造成部署包缺少被依賴模塊的程式碼。
- 核心模塊（`is_core=true`）不可被取消，也不進入可勾選清單；**核心模塊不要依賴非核心模塊**。

**套件依賴**：

```powershell
composer require vendor/package        # 後端；會更新 composer.lock，兩個檔都要提交
npm install some-package               # 前端；同樣提交 package-lock.json
```

- PHP／Laravel 版本基線見第 2 節，不得任意升降。
- 新增套件後 `composer audit`、`npm audit` 要過（第 4.9 節）。
- 單站部署包不含 `vendor/`、`node_modules/`，現場依 lock 檔還原，所以 **lock 檔必須與 composer.json／package.json 一致**。
- 若某套件只有部分模塊用到，在該模塊的說明文件註明，避免移除模塊時漏掉。

### 20.6 TCP port 與本機網址

| 項目 | 位置 | 預設 |
| --- | --- | --- |
| Laravel serve | `php artisan serve --port=8000`（或 `composer run dev` 內的參數） | 8000 |
| 前台 SPA dev | `vite.vue.config.js` → `server.port` | 5173 |
| 主站後台 dev | `vite.hq.config.js` → `server.port` | 5175 |
| 子站後台 dev | `vite.admin.config.js` → `server.port` | 5176 |
| Blade 資產 dev | `vite.config.js` | 5173（預設，與前台 SPA 衝突時 Vite 會自動換號） |
| dev server 轉發 `/api` 的目標 | 環境變數 `VUE_DEV_API_PROXY`（預設 `http://127.0.0.1:8000`） | — |

- 改了 `php artisan serve` 的埠，要同步設 `VUE_DEV_API_PROXY` 與 `.env` 的 `APP_URL`，否則 SPA 的 `/api` 會打到舊埠。
- **不需要為此改 CORS**：SPA 走 proxy，對瀏覽器是同源。`CORS_ALLOWED_ORIGINS` 只給「前台真的架在別的網域」的情境，且不可用 `*`（見 4.2.2）。
- **不需要改 CSP**：Blade 資產的 dev server 來源由程式讀 `hot` 檔自動放行，不寫死埠號（4.2.1）。
- 兩個 dev server 同埠會被 Vite 自動遞增換號，網址以終端機輸出為準。
- 用 `npm run vue:dev` 等獨立 dev server 時，瀏覽器不經過 Laravel，**看不到 CSP 違規**；要驗證需 `build` 後走 Laravel 路由（4.2.1 第 2、8 點）。

### 20.7 系統設定項、字典、初始資料

- **系統設定（`system_configs`）只開放改「值」不開放改「結構」**：新增設定項要在 `SiteBaselineSeeder` 補一筆（仿 `seedSiteBasicConfig()` 的 `$items`，需指定 `cfg_module`／`cfg_name`／`cfg_type`），程式碼再去讀它。17 種 `cfg_type` 與 `cfg_option` 寫法見 17.1。
- **字典**：仿 `seedSiteStatusDictionary()` 寫 seed；`dic` 類型設定項的 `cfg_option` 填字典 `code`。也可在後台「資料字典」手建，但只影響該站。
- **初始身份別／帳號**：身份別在 `SiteBaselineSeeder::run()`；帳號名稱／密碼在 `config/initial_accounts.php`，實際值來自 `.env`（`SITE_SUPER_ADMIN_*`、`SITE_DELEGATION_ROOT_*`、`HQ_ADMIN_*`）。**密碼沒填 seeder 會直接丟例外**。
- 以上都屬 baseline seed，**既有站要手動重跑**（見 20.0 共通陷阱）。
- 主站（HQ）的資料另有 `database/hq/seeders/HqDatabaseSeeder`，指令不同：`php artisan db:seed --class=Database\Hq\Seeders\HqDatabaseSeeder`。

### 20.8 `.env` 與 `config/*.php`

1. 新設定值：先寫進 **`.env.example`**（附註解與安全的預設），再在 `config/*.php` 用 `env('KEY', default)` 讀出。
2. **程式碼一律讀 `config('...')`，禁止在 `config/` 以外呼叫 `env()`**：`config:cache` 後 `env()` 會回 `null`（本專案實際踩過，症狀是 `Access denied for user ''@'localhost'`）。
3. 改完執行 `php artisan optimize:clear`。
4. 子站資料庫連線不要自己組，走 `App\Support\Site\SiteDatabaseConfig`；多站設定在 `config/multisite.php`。
5. 單站部署包會依 `.env.example` 產生 `.env`，新增必填設定記得**同時**更新 `.env.example` 與部署說明範本 `resources/deploy/DEPLOY.md`，否則現場會漏設。

### 20.9 錯誤碼與語言包

1. 新錯誤碼加在 `app/Support/Api/ErrorCode.php`（依分層：`1xxxx` 驗證、`2xxxx` 認證授權、`3xxxx` 業務、`5xxxx` 系統）。
2. 同步登記到 `app/Support/Api/ErrorCatalog.php` 的 **`STATUS`（HTTP 狀態碼）與 `MESSAGE_KEYS`（語言 key）兩張表**，漏登記會回 500＋「未知錯誤」。
3. 在 `lang/zh_TW/api.php`（主站用 `hq.php`）補對應文案，key 命名 `{domain}.{scene}.{key}`。
4. 業務上的失敗訊息走 `RuntimeException` + 語言包 key，不要把中文寫死在程式裡。

### 20.10 插件

```powershell
php artisan plugin:make ad microsoft v2019
```

1. 補完 `configSchema()` 與連線邏輯（實作必須符合該「功能」的 Contract 介面）。
2. **手動登記到 `config/plugins.php`**（key 命名 `{brand_snake}_{version}`，例如 `microsoft_v2019`）；登記後才會出現在後台插件管理頁。
3. 在子站後台「插件管理」啟用並填設定值（設定存於該站資料庫、加密儲存）。
4. 若該 key 之後從 `config/plugins.php` 刪除或改名，已啟用它的站會直接拋例外，改名要連帶處理各站的設定。

---

## 21. 前端 Vue3 共用／客製化／部署詳解（對應 README 第 10 節）

### 21.1 先分清楚：共有 4 套前端，各自獨立建置

| 套 | 原始碼 | 服務誰 | 子站間 | 客製化單位 | 編譯產物（**不進版控**） | 建置指令 |
| --- | --- | --- | --- | --- | --- | --- |
| 子站前台 | `resources/vue_src` | 子站的終端使用者 | 預設共用，可依站升級客製化（21.2） | 每站 `site-overrides/{code}` | `public/vue`、`public/vue-sites/{code}` | `php artisan vue:build [--site=]` |
| 子站後台（`admin` 端口） | `resources/vue_src_admin` | 子站管理員 | **所有子站共用同一份**，無逐站客製化機制 | 靠選單／權限／系統設定差異化 | `public/admin-assets` | `npm run admin:build` |
| 主站後台（`hq`） | `resources/vue_src_hq` | 我方維運 | 只有一份 | — | `public/hq-assets` | `npm run hq:build` |
| Blade 資產 | `resources/css`、`resources/js` | SSO 落地頁等 Blade 頁 | 共用；Blade 檔可逐站覆寫（21.4） | `resources/views/sites/{code}` | `public/build` | `npm run build` |

> 要記住：**每一套都是獨立的 Vite 專案**，改了哪一套就只重建哪一套；`npm run build` 不會順便建置 SPA。編譯產物全部在 `.gitignore`，**新環境、每次部署都要重建**（見 21.5）。
> 新增端口要不要「自己一套前端」見 20.3 第 6 點；多數情況新端口是複製 `vue_src_admin`，之後該端口自己獨立演進，不與 `admin` 共用建置。

### 21.2 子站前台的三層客製化（Tier 1／2／3）

核心原則：**共用為預設，客製化範圍變大才升級；同一份 `site-overrides/{code}` 原始碼在三層之間通用**，升降級不必搬移或重寫程式碼（8.1）。

| 層級 | 什麼時候用 | 做法 | `vue_build_mode` | 輸出 |
| --- | --- | --- | --- | --- |
| **Tier 1** 純公版 | 前台沒有任何差異 | 什麼都不用做 | `shared` | 共用 `public/vue` |
| **Tier 2** 共用建置＋動態掛載 | 只多幾個全新頁面 | 在 `site-overrides/{code}` 加頁面，由共用建置動態載入 | `shared` | 共用 `public/vue` |
| **Tier 3** 獨立建置 | 整組頁面／路由都不同，或該站要現場單站部署 | `vue:build --site={code}` 單獨建置 | `dedicated` | `public/vue-sites/{code}` |

目錄約定（以 `vendor_a` 為例，子站代碼用**原始小寫值**，不轉 StudlyCase，見 9.2）：

```text
resources/vue_src/src/
  pages/ router/ stores/ ...                共用主體（所有站都吃）
  site-overrides/vendor_a/
    pages/OrderDashboard.vue                該站客製頁面
    routes.js                               該站客製路由片段
```

建立客製頁面骨架：

```powershell
php artisan vue:page:make vendor_a order-dashboard
# 產生 site-overrides/vendor_a/pages/OrderDashboard.vue，並提示要在 routes.js 補的路由那一行
```

**升降級（人工決策，系統不自動判斷）**

```powershell
# Tier 1/2：共用建置，所有 shared 站一起用
php artisan vue:build --clean

# 升級 Tier 3：單站獨立建置 → public/vue-sites/vendor_a（base 為 /vue-sites/vendor_a/）
php artisan vue:build --site=vendor_a --clean

# CI/CD：重建所有 dedicated 站
php artisan vue:build --all-sites
```

升級到 Tier 3 還要**把該站的 `vue_build_mode` 改成 `dedicated`**，否則後端依舊回傳共用 `public/vue/index.html`（`SpaFallbackController` 依 `SiteContext->vueBuildMode` 決定出哪個目錄）。要改兩處、缺一不可：

1. 主站 `sites` 表該站的 `vue_build_mode` 欄位（供 `--all-sites`、部署包說明判讀）。
2. 該站的 `storage/app/sites/{site_id}.php` 內的 `'vue_build_mode' => 'dedicated'`（執行期實際讀的是這個檔案，不查主站 DB，見第 6 節）。

降級則反向：改回 `shared`，清掉或保留 `site-overrides` 皆可，共用 `public/vue` 不受影響。

#### ⚠ 目前實作與設計的落差（客製化前先看）

下表是我逐一核對程式碼得到的現況（非實測執行；動手前請自行驗證）：

| 設計（8.3／8.4） | 程式現況 | 實務影響與處理 |
| --- | --- | --- |
| Tier 2：由 `GET /api/site/config` 的 `custom_routes` 清單動態掛載客製頁 | `SiteConfigController` 目前**固定回傳 `custom_routes: []`**，`vue:page:make` 產生的 `routes.js` 也**沒有任何程式讀取** | 只放檔案、不做事，**客製頁不會出現**。要啟用需補兩件事：(a) 讓 `custom_routes` 依站點產出（例如存站台設定）；(b) 前台 router 改用 `import.meta.glob('./site-overrides/**/*.vue')` 對照載入——`router/index.js` 現行的 `import(route.component)` 用**執行期字串**載入原始碼路徑，建置後該路徑不存在，無法運作 |
| Tier 3：共用主體 ＋ `site-overrides/{code}` 疊加後單獨建置 | `vue:build --site=` 目前**只切換輸出目錄與 base path**（`VUE_BUILD_OUTDIR`／`VUE_BUILD_BASE`），`vite.vue.config.js` 沒有把該站 overrides 合進主體 | 現在 `--site` 建出來的內容與共用建置相同，只是放在別的目錄。要真正疊加，需在 `vite.vue.config.js` 加入依 `--site` 設定的 alias／入口覆寫（見下方 21.3 的建議做法） |
| `vue:build --output=public/vue`（`resources/deploy/DEPLOY.md` 第 6 節範例） | `vue:build` **沒有 `--output` 選項** | 現場單站部署的 Tier 3 照 DEPLOY.md 下指令會報錯；改用 `vue:build --site={code}` 後，自行把 `public/vue-sites/{code}` 內容放到 `public/vue`，或補上該選項 |
| 沒有切換 `vue_build_mode` 的指令／介面 | 只有建站時預設寫入 `shared` | 目前只能手改資料（見上方升級步驟） |

> 以上屬「框架尚未做完的部分」。真正開始做某個子站的前台客製化時，**先把對應落差補完並寫回本文件**（開發約定第 1 點），否則會做出一個「檔案有了、畫面沒有」的狀態。

### 21.3 客製化如何「單獨疊加」而不污染共用

要守住的邊界：**共用主體（`resources/vue_src/src/*` 除 `site-overrides` 以外）不為任何單一客戶改動**；客戶差異只放在 `site-overrides/{code}/`。

| 想做的事 | 放哪裡 | 說明 |
| --- | --- | --- |
| 純樣式／文案／Logo／主題色 | **系統設定**（後台 System Config，`site_basic`）→ `GET /api/site/config` | 不需要任何客製化層級，三層通用（8.2、17.5） |
| 一兩個全新頁面 | `site-overrides/{code}/pages` ＋ `routes.js`（Tier 2，需先補完 21.2 落差） | 不影響其他站的 bundle（動態載入 chunk） |
| 整套頁面／路由結構不同 | Tier 3 | 與共用完全隔離，互不影響快取檔名 |
| 後端 API 邏輯不同 | `app/Sites/{SiteCode}/...` 覆寫 Service（見第 7 節第 4 點） | 前端客製與後端客製是兩條線，可以只做其中一邊 |
| Blade 頁面不同（SSO 落地頁等） | `resources/views/sites/{code}/...` | 同名 view 自動優先，沒有該目錄則走公版（21.4） |
| 該站專屬資料表／全新 API | `database/sites/{code}/migrations`、`app/Sites/{SiteCode}/Api/{module}` | `site:migrate` 會自動偵測並疊加 |

Tier 3 疊加的建議做法（需要自行補實作，供開發者參考，非現有功能）：

1. 在 `vite.vue.config.js` 讀 `VUE_BUILD_SITE`，設定 `resolve.alias`：把 `@overrides` 指到 `site-overrides/{code}`（共用建置則指到一個空目錄）。
2. 共用 `router/index.js` 以 `import.meta.glob('@overrides/routes.js')` 取該站路由片段併入。
3. `VueBuildCommand::buildOne()` 多傳一個 `VUE_BUILD_SITE` 環境變數。
4. 想「覆寫」共用元件時，不要改共用檔，用 alias 把同名元件導向 overrides 內的版本。

**絕不要**：為了某客戶在共用頁面加 `if (siteCode === 'vendor_a')`。這會讓部署包（只篩選目錄、不合併邏輯）把客戶專屬判斷帶給別的站，與「共用層不寫死站點代碼」的原則衝突（6.8 第 7 點）。

### 21.4 多端口與 Blade 的對應

- **各端口的前端互相獨立**：`admin` 與你日後新增的 `workshop`、`vendor` 等端口，建置、輸出目錄、路由 base 各自一套（20.3 第 6 點）。同一站的不同端口可同時存在，使用者在各端口各自登入（JWT 綁單一端口）。
- **子站後台（`admin`）全站共用同一份建置**：不同客戶的後台差異靠 **選單（`module.json`／選單管理）、角色權限、模塊開通、系統設定** 達成，不是靠改前端程式。要讓某客戶多一個後台頁面 → 做成一個**模塊**（20.1）並只對該客戶開通，而不是改 `vue_src_admin` 加條件。
- **路徑前綴進站**：沒綁網域的子站用 `/{site_code}/admin`、`/{site_code}/…`；前端靠 `GET /api/site/config` 回傳的 `site_code` 決定 router base，**不要在前端自己猜網址片段**（8.2）。前端 `fetch('/api/...')` 要帶 `X-Site-Path-Prefix`（見 10.3 的陷阱說明），否則路徑前綴站會被誤判成預設站。
- **Blade 覆寫**：把同名檔放到 `resources/views/sites/{code}/{module}/…` 即可，`EnsureSiteContext` 偵測到該目錄才插入查找路徑；不需要為每個站先建空檔（8.5）。
- **裸網域根目錄 `/` 目前會 302 到 `/admin`**（前台只是骨架）；前台補上真正頁面後，要拿掉 `SpaFallbackController` 的這段判斷，見 1.2 第 1 點。

### 21.5 建置與部署流程

**開發期（本機）**：見 4.1、4.2；dev server 不經 Laravel，看不到 CSP 違規。要驗 CSP，**必須建置後走 Laravel 路由**（`http://127.0.0.1:8000/admin`），這是上線前的必做關卡（4.2.1 第 8 點）。

**多站／SaaS 部署（單一程式碼庫、多個子站）**

```powershell
# 1) 後端依賴與資料庫
composer install --no-dev --optimize-autoloader
php artisan migrate --path=database/hq/migrations --force     # 主站
php artisan site:migrate --all                                # 所有子站（自動疊加客製化站的專屬 migration）

# 2) 前端：逐套建置（缺哪套，該入口就 404）
npm ci
npm run hq:build         # /hq
npm run admin:build      # /admin 以及 /{code}/admin
npm run vue:build        # 或 php artisan vue:build --clean  → public/vue
php artisan vue:build --all-sites     # 有 dedicated（Tier 3）站才需要
npm run build            # Blade 資產（有用到 @vite 的頁面才需要）

# 3) 快取（改 .env／config 後；順序見 4.8 的警告）
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

- 伺服器**只對外暴露 `public/`**，`resources/vue_src*` 原始碼不可被直接存取。
- 不是每次都要全建：只改了 `vue_src_admin` 就只跑 `admin:build`；只改某 Tier 3 站的 overrides 就只跑 `vue:build --site={code}`。
- 共用前台（`public/vue`）一改，**所有 `shared` 站同時生效**，上線前確認不會破壞任一站；`dedicated` 站不受影響但**也不會自動吃到共用的修正**，需重新 `vue:build --site=`。
- 子站新增／切換 Tier 後，不需重啟或 `config:cache`（站點設定是 `storage/app/sites/{id}.php`，見第 6 節）。
- CI/CD 建議：每次發版固定跑 `hq:build`、`admin:build`、`vue:build`、`vue:build --all-sites`，不要依賴開發機上的舊產物。

**單站部署包（現場獨立部署，`site:package`）**

- 部署包只含**該站**的客製化目錄，其他客戶的 `site-overrides`、`app/Sites`、`views/sites`、`database/sites` 一律排除（6.8）。
- **不含 `vendor/`、`node_modules/`、`bootstrap/cache`**；現場 `composer install`、`npm ci`，並**重新建置前端**（`hq` 不在部署包內，只需 `admin:build` 與前台）。
- Tier 1/2（`shared`）：用共用 `vue:build`；Tier 3（`dedicated`）：用 `vue:build --site={code}`。⚠ 注意 21.2 落差表中 `DEPLOY.md` 的 `--output` 問題。
- 現場後續追加客製化：照 21.3 在 `site-overrides/{code}` 加檔案即可，操作與 SaaS 開發相同；公版更新時只覆蓋共用目錄，不會蓋掉客製化。

**驗收清單（每次前端相關部署後）**

1. 各入口 200：`/hq`、`/admin`、`/{code}/admin`、前台頁面（不是 404／空白）。
2. 瀏覽器主控台**沒有 CSP violation**、沒有 `Key material must not be empty`（見 3.4）。
3. 以非預設站（路徑前綴站）登入一次，確認 API 打到正確的站。
4. Tier 3 站：開啟後網址資源路徑為 `/vue-sites/{code}/…`。

---

## 22. 功能現況、文件落差與設計決策速查（自 README 移入）

> README 精簡後，下列內容移到本章保存。本章內「README x.x」指 README 舊版節次的內容，僅供查閱。

### 22.1 功能現況與文件落差（原 README 第 1 節）

#### 1.1 已完成且可運作

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

#### 1.2 尚未完成或刻意暫緩

1. **前台目前是骨架頁**：裸網域根目錄（`/`、`/{code}`）會 302 導向管理後台（DEVELOP.MD 8.5）。
2. **檔案管理／通用圖片模組暫緩**：`app/Api/File`、`managed_files` 已從程式庫移除，設計保留在 DEVELOP.MD 10 節，需要時照設計重建。
3. **初始資料極簡**：`portals` 目前只有 `admin`；`identity_types` 只有 `super_admin`／`system_admin`／`staff`（DEVELOP.MD 19）。
4. **跳轉登入只有 `admin` 端口有落地頁**：其他 `portal_key` 尚無對應 SPA，目前導向 `/{portal_key}`，待該端口前端建置後接手（DEVELOP.MD 6.11、9.1）。

#### 1.3 ⚠ 文件與程式現況落差（照 DEVELOP.MD 做卻失敗時，先看這張表）

| DEVELOP.MD 寫法 | 實際現況 | 正確做法 |
| --- | --- | --- |
| `composer run swagger:generate`（12.7／18.2-6） | `composer.json` **沒有**這個 script | 用 `php artisan swagger:generate --pretty` |
| `POST /api/site/sites`（6.3） | 實際路由是 `POST /api/hq/sites` | 見 `php artisan route:list`（`app/Hq/Site`） |
| `site:create ... --clone-from=`（6.3） | 指令**沒有**這個選項，只有 `--type` | 想複製既有站客製化內容，目前需人工複製目錄後再建站 |
| 廠商模式 `/api/factory/*`（8.6、12.8、18.3） | **路由不存在**，屬規劃未實作 | 目前不要照該範例測試 |
| 初始帳號 `hq_admin`／`super_admin`（18.3） | 預設是 `admin`；子站系統管理員預設為**站代碼** | 見本檔第 8 節 |
| Tier 2 動態掛載客製頁（8.3）、Tier 3 主體＋overrides 疊加建置（8.4） | `custom_routes` 固定回空陣列；`vue:build --site=` 只換輸出目錄 | 見本檔 10.2 落差表 |
| `vue:build --output=`（`DEPLOY.md`、6.8） | 指令**沒有**此選項 | 見本檔 10.2 落差表 |

### 22.2 關鍵設計決策一句話版（原 README 第 6 節）

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

### 22.3 指令防呆與測試現況（原 README 3.7、4.9）

早期版本裸跑 `php artisan migrate`／`db:seed` 會把子站表建進主站庫，或因缺 `site_current` 連線而失敗，README 曾以「絕不要這樣下」警告。現已改為指令本身防呆，設計見 13 章第 8 點；仍須避免的只剩：執行期呼叫 `env('DB_...')`（`config:cache` 後回 `null` → `Access denied for user ''@'localhost'`），連線一律走 `App\Support\Site\SiteDatabaseConfig`。

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
