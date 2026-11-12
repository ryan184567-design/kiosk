<?php

/* *****************************************
File: SiteController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 站點管理：這支 Controller 底下每一支 API 都要求呼叫者是主站超級管理員 （`hq_users.is_super_admin=true`，路由掛 hq.jwt.auth + hq.super_admin）， 一般主站帳號呼叫會收到 403，因為開站/查站點資訊屬於高風險操作。
***************************************** */

namespace App\Hq\Site\Controllers;

use App\Hq\Site\Models\Site;
use App\Hq\Site\Services\SitePackageService;
use App\Hq\Site\Services\SiteProvisioningService;
use App\Jobs\ProvisionSiteJob;
use App\Support\Api\ErrorCode;
use App\Support\Audit\Models\HqAuditLog;
use App\Support\Http\Controllers\BaseHqController;
use Illuminate\Http\Request;
use InvalidArgumentException;

// 站點管理：這支 Controller 底下每一支 API 都要求呼叫者是主站超級管理員
// （`hq_users.is_super_admin=true`，路由掛 hq.jwt.auth + hq.super_admin），
// 一般主站帳號呼叫會收到 403，因為開站/查站點資訊屬於高風險操作。
class SiteController extends BaseHqController
{
    /**
     * @api-method GET
     * @api-path /api/hq/sites
     * @api-tag Site
     * @api-summary 列出所有子站
     * @api-description
     * 回傳目前系統裡所有子站的紀錄，依 `site_id` 由大到小排序（最新建立的
     * 排最前面），不分頁一次回傳全部——子站數量預期是幾十到頂多幾百筆，
     * 還不到需要分頁的量級。清單包含所有狀態的站點，含還在建置中
     * （`pending`/`creating`）或建置失敗（`failed`）的，不會因為還沒建好
     * 就從清單消失，方便管理員追蹤建站進度或失敗原因。
     * @api-response-field data.items[].site_id int 站點ID
     * @api-response-field data.items[].code string 站點代碼（小寫英數底線，唯一），用於資料庫名（見下方 db_name）、URL 路徑前綴、`app/Sites/{StudlyCode}` 客製化目錄
     * @api-response-field data.items[].name string 站點顯示名稱
     * @api-response-field data.items[].primary_domain string 主要網域，未設定時為 null（見 path_prefix 作為替代進站方式）
     * @api-response-field data.items[].path_prefix string 網址路徑前綴（不含網域時用這個進站，例如 code=vendora 對應 http://host/vendora/...）
     * @api-response-field data.items[].db_connection string 固定為 site_current，是 Laravel 連線名稱不是實際資料庫名
     * @api-response-field data.items[].db_name string 實際資料庫名，固定為 SITE_DB_PREFIX 設定值 + code（例如 base_site_vendora）
     * @api-response-field data.items[].site_type dict:site_type 站點類型
     * @api-response-field data.items[].template_namespace string 客製化程式碼命名空間，預設等於 code，轉成 StudlyCase 後對應 app/Sites/{StudlyCode}
     * @api-response-field data.items[].clone_from_site_id int 若此站是從另一個站點複製建立，這裡是來源站點ID，否則為 null
     * @api-response-field data.items[].provision_status dict:provision_status 建站進度
     * @api-response-field data.items[].provision_failed_reason string 建站失敗原因（provision_status=failed 時才有值，其他狀態為 null）
     * @api-response-field data.items[].vue_build_mode dict:vue_build_mode 前台 Vue 建置模式
     * @api-response-field data.items[].last_migrated_at datetime 最後一次成功跑完子站 migration 的時間，null 代表尚未成功建置過
     * @api-response-field data.items[].last_migration_batch string 最後一次 migration 的批次號，用於判斷是否落後公版最新 schema
     * @api-response-field data.items[].is_enabled bool 是否啟用（停用後該站前後台一律拒絕存取）
     * @api-dict site_type standard=公版子站（無客製化程式碼）,customized=客製化子站（有 app/Sites/{StudlyCode} 覆寫）
     * @api-dict provision_status pending=已建立紀錄但尚未開始建置,creating=建置中（建資料庫/跑migration/seed）,ready=建置完成可正常使用,failed=建置失敗（見 provision_failed_reason）
     * @api-dict vue_build_mode shared=與其他公版/輕度客製站點共用同一份編譯產物,dedicated=此站獨立建置，有自己專屬的編譯產物
     */
    public function index(Request $request)
    {
        return $this->success([
            'items' => Site::query()->orderByDesc('site_id')->get(),
        ]);
    }

    /**
     * @api-method GET
     * @api-path /api/hq/sites/{siteId}
     * @api-tag Site
     * @api-summary 查詢單一子站詳細資料
     * @api-description
     * 依站點ID查詢單一站點紀錄，欄位定義與 index（列表）完全相同。
     * 找不到對應站點時回傳 404。
     * @api-no-request-body
     * @api-response-field data.item.site_id int 站點ID
     * @api-response-field data.item.code string 站點代碼
     * @api-response-field data.item.name string 站點顯示名稱
     * @api-response-field data.item.primary_domain string 主要網域，未設定為 null
     * @api-response-field data.item.path_prefix string 網址路徑前綴
     * @api-response-field data.item.db_name string 實際資料庫名
     * @api-response-field data.item.site_type dict:site_type_show 站點類型
     * @api-response-field data.item.provision_status dict:provision_status_show 建站進度
     * @api-response-field data.item.provision_failed_reason string 建站失敗原因，非 failed 狀態為 null
     * @api-response-field data.item.is_enabled bool 是否啟用
     * @api-dict site_type_show standard=公版子站,customized=客製化子站
     * @api-dict provision_status_show pending=待建置,creating=建置中,ready=已完成,failed=建置失敗
     */
    public function show(int $siteId)
    {
        $site = Site::query()->find($siteId);

        if (! $site) {
            return $this->fail(ErrorCode::CREDENTIALS_MISSING, __('hq.site.not_found'), 404);
        }

        return $this->success(['item' => $site]);
    }

    /**
     * @api-method POST
     * @api-path /api/hq/sites
     * @api-tag Site
     * @api-summary 開設新子站
     * @api-description
     * 建立一筆新的子站紀錄後，立即以非同步 Queue Job 派送實際建站流程
     * （建資料庫、跑子站公版 migration+seed、寫入 `storage/app/sites/{site_id}.php`
     * 設定檔、更新 `storage/app/sites/sites.json` 站點對照表），不會讓這支
     * API 同步等到整個建站流程跑完才回應（避免請求逾時）。回應的
     * `provision_status` 一定是 `pending`，要另外呼叫
     * `GET /api/hq/sites/{siteId}` 輪詢目前建置進度是否變成 `ready`
     * 或 `failed`。
     * 建站失敗時（例如資料庫連線問題、migration 出錯）不會自動重試，
     * `provision_status` 會停在 `failed`、`provision_failed_reason` 會記錄
     * 失敗原因，需要人工排查後用 `php artisan site:cleanup-failed` 指令
     * 清理再重新嘗試。
     * `code` 一旦送出成功即固定不可改——之後所有識別這個子站的地方
     * （資料庫名、URL 路徑前綴、客製化程式碼目錄）都以這個值為準。
     * @api-request-field code string 子站代碼，只能是小寫英文字母/數字/底線，且不可與現有子站重複；建立後這個值不可修改
     * @api-request-field name string 子站顯示名稱（給人看的中文名，可隨時改）
     * @api-request-field type dict:site_type_create 子站類型，省略時預設為 standard
     * @api-request-example
     * {"code": "vendor_a", "name": "Vendor A 客戶站", "type": "standard"}
     * @api-response-field data.item.site_id int 新建立的站點ID
     * @api-response-field data.item.code string 子站代碼
     * @api-response-field data.item.name string 子站顯示名稱
     * @api-response-field data.item.site_type dict:site_type_create_resp 子站類型
     * @api-response-field data.item.provision_status dict:provision_status_create 建站進度，剛建立必定是 pending
     * @api-dict site_type_create standard=公版子站（無客製化程式碼）,customized=客製化子站（日後可在 app/Sites/{StudlyCode} 放覆寫程式碼）
     * @api-dict site_type_create_resp standard=公版子站,customized=客製化子站
     * @api-dict provision_status_create pending=待建置,creating=建置中,ready=已完成,failed=建置失敗
     */
    public function store(Request $request, SiteProvisioningService $provisioning)
    {
        $data = $this->validateInput($request, [
            'code' => 'required|string',
            'name' => 'required|string',
            'type' => 'nullable|string|in:standard,customized',
        ]);

        try {
            $site = $provisioning->createPending($data['code'], $data['name'], $data['type'] ?? 'standard');
        } catch (InvalidArgumentException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        ProvisionSiteJob::dispatch($site->site_id);

        $this->audit($request, 'site.create.requested', $site->code, ['site_id' => $site->site_id]);

        return $this->success(['item' => $site], 202);
    }

    /**
     * @api-method PUT
     * @api-path /api/hq/sites/{siteId}/domain
     * @api-tag Site
     * @api-summary 設定／清除子站的主要網域
     * @api-description
     * 子站有兩種進站方式：綁定網域（這支設定的 `primary_domain`）或用
     * 網址路徑前綴（`path_prefix`，開站時就自動設定，不需要另外設定）。
     * `SiteResolver` 判斷進站站點時網域優先於路徑前綴，兩種方式可以同時
     * 存在、互不影響——設定網域不會讓路徑前綴失效，兩個網址都能進同一個
     * 子站。
     * `domain` 傳空字串或 null 代表清除已設定的網域，之後只能靠路徑前綴
     * 進站。網域格式需符合一般網域名稱規則（英數、點、連字號組成，不含
     * `http://` 開頭或路徑），且不可跟其他子站重複——同一個網域同時綁定
     * 兩個子站會讓 `SiteResolver` 永遠只解析到其中一個，這裡直接擋掉這種
     * 設定錯誤。只能對已建置完成（`provision_status=ready`）的子站設定，
     * 建置中／失敗的子站連資料庫都還沒有，設網域也無法真的進站。
     * 設定成功後會同步更新 `storage/app/sites/sites.json`（`SiteResolver`
     * 實際查詢網域對照的來源）與該站自己的 `storage/app/sites/{site_id}.php`
     * 設定檔，不需要另外執行任何指令或重啟服務即可生效。
     * @api-request-field domain string 要綁定的網域，例如 vendor-a.example.com；傳空字串或省略代表清除
     * @api-request-example
     * {"domain": "vendor-a.example.com"}
     * @api-response-field data.item.site_id int 站點ID
     * @api-response-field data.item.primary_domain string 更新後的網域，清除後為 null
     */
    public function updateDomain(Request $request, int $siteId, SiteProvisioningService $provisioning)
    {
        $site = Site::query()->find($siteId);

        if (! $site) {
            return $this->fail(ErrorCode::CREDENTIALS_MISSING, __('hq.site.not_found'), 404);
        }

        $data = $this->validateInput($request, ['domain' => 'nullable|string|max:255']);
        $domain = $data['domain'] !== '' ? ($data['domain'] ?? null) : null;

        try {
            $site = $provisioning->updateDomain($site, $domain);
        } catch (InvalidArgumentException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        $this->audit($request, 'site.domain.update', $site->code, ['site_id' => $site->site_id, 'domain' => $domain]);

        return $this->success(['item' => $site->only(['site_id', 'primary_domain'])]);
    }

    /**
     * @api-method POST
     * @api-path /api/hq/sites/{siteId}/package
     * @api-tag Site
     * @api-summary 輸出子站的單站部署包
     * @api-description
     * 把指定子站輸出成可獨立部署到客戶機房的單站部署包（見 README 6.8），
     * 與 CLI `php artisan site:package {site_code}` 共用同一份打包邏輯
     * （`SitePackageService`），兩邊產出的內容完全一致——後台只是多了「用點選
     * 取代打指令」與輸出結果顯示。
     * 打包只做「篩選」不做「合併攤平」：複製共用程式碼與**該站自己**的客製化
     * 目錄（`app/Sites/{StudlyCode}`、`resources/views/sites/{code}`、
     * `resources/vue_src/src/site-overrides/{code}`、`database/sites/{code}`），
     * 並排除其他子站的客製化目錄；主站專屬的 `app/Hq`、`database/hq` 也不會
     * 進入部署包（單站部署不需要站點管理功能）。輸出時會一併產生現場部署必要的配套，
     * 讓匯出包不只是原始碼備份：完整可用的 `.env`（以 `.env.example` 為底，寫入
     * `MULTI_SITE_ENABLED=false`、`SITE_TEMPLATE_NAMESPACE`、`SITE_ID`、`DB_DATABASE`，
     * 並自動產生 APP_KEY 與 JWT secret）、`storage/` 目錄骨架、清空的
     * `bootstrap/cache`、以及現場部署步驟 `DEPLOY.md`（見 6.8 第 3、8 點）。
     * 現場只需調整 `DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD`、`APP_URL` 並執行
     * `composer install`（部署包刻意不含 `vendor/`）即可啟動。
     * 只能對已建置完成（`provision_status=ready`）的子站輸出；`output` 若留空
     * 則輸出到 `storage/app/packages/{code}`，指定時只接受該目錄底下的相對
     * 路徑（避免用一個 HTTP 請求把檔案寫到任意系統路徑）。輸出目錄已存在時
     * 直接擋下（不覆蓋既有部署包），要重新產生請先自行清掉或改用其他資料夾。
     * @api-request-field output string 輸出資料夾（相對 storage/app/packages 的路徑，可留空，預設等於站點代碼）
     * @api-request-example
     * {"output": "vendor_a_20260913"}
     * @api-response-field data.item.site_id int 站點ID
     * @api-response-field data.item.code string 站點代碼
     * @api-response-field data.item.output_path string 部署包實際輸出路徑（絕對路徑）
     * @api-response-field data.item.file_count int 部署包內的檔案總數
     * @api-response-field data.item.size_bytes int 部署包總大小（bytes）
     * @api-response-field data.item.vue_build_mode dict:vue_build_mode 該站前台 Vue 建置模式，供現場部署時判斷要用哪個建置指令（見 6.8 第 4 點）
     * @api-dict vue_build_mode shared=與其他公版/輕度客製站點共用同一份編譯產物,dedicated=此站獨立建置，有自己專屬的編譯產物
     * @api-response-field data.notes array 打包後注意事項（依站點類型／Vue 建置模式動態產生的字串陣列）
     */
    public function package(Request $request, int $siteId, SitePackageService $packager)
    {
        $site = Site::query()->find($siteId);

        if (! $site) {
            return $this->fail(ErrorCode::CREDENTIALS_MISSING, __('hq.site.not_found'), 404);
        }

        $data = $this->validateInput($request, ['output' => 'nullable|string|max:200']);

        try {
            $output = $packager->resolveOutputPath($site, $data['output'] ?? null);
            $result = $packager->package($site, $output);
        } catch (InvalidArgumentException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        $this->audit($request, 'site.package', $site->code, [
            'site_id' => $site->site_id,
            'output_path' => $result['output_path'],
            'file_count' => $result['file_count'],
        ]);

        return $this->success([
            'item' => [
                'site_id' => $site->site_id,
                'code' => $site->code,
                'output_path' => $result['output_path'],
                'file_count' => $result['file_count'],
                'size_bytes' => $result['size_bytes'],
                'vue_build_mode' => $site->vue_build_mode,
            ],
            'notes' => $this->packageNotes($site),
        ]);
    }

    // 打包後要提醒管理員的事，見 6.8 第 3、4、8 點。放在 API 回應裡而不是只寫
    // 在文件上，是因為「打包完就以為可以上線」是最容易出錯的一步（部署包不含
    // vendor、.env 的資料庫欄位要用現場值、Vue 產物要不要另外建置也依站點而異）。
    private function packageNotes(Site $site): array
    {
        $notes = [
            __('hq.packaging.env_hint'),
            __('hq.packaging.deploy_guide_hint'),
            __('hq.packaging.no_merge_hint'),
        ];

        if ($site->site_type === 'standard') {
            $notes[] = __('hq.packaging.standard_site_hint');
        }

        $notes[] = $site->vue_build_mode === 'dedicated'
            ? __('hq.packaging.dedicated_vue_hint', ['code' => $site->code])
            : __('hq.packaging.shared_vue_hint');

        return $notes;
    }

    private function audit(Request $request, string $action, string $target, array $context = []): void
    {
        HqAuditLog::query()->create([
            'user_id' => $this->currentHqUser($request)?->id,
            'action' => $action,
            'target' => $target,
            'context' => $context,
            'ip' => $request->ip(),
            'created_at' => now(),
        ]);
    }
}
