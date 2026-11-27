<?php

/* *****************************************
File: SiteBaselineSeeder.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站 baseline seed（必要初始資料，見 README 13 節），由 site:create（6.3） 或本機 migrate:fresh --seed（18.1）觸發。
***************************************** */

namespace Database\Seeders;

use App\Api\Dictionary\Models\SystemDictionary;
use App\Api\Guest\Models\GuestCompanyMember;
use App\Api\Guest\Services\GuestCompanyService;
use App\Api\Menu\Models\Menu;
use App\Api\Role\Models\Role;
use App\Api\SystemConfig\Models\SystemConfig;
use App\Api\User\Models\User;
use App\Hq\Site\Models\Site;
use App\Support\Hq\HqPresence;
use App\Support\Identity\Models\IdentityType;
use App\Support\Menu\MenuCacheVersion;
use App\Support\Module\ModuleRegistryService;
use App\Support\Portal\Models\Portal;
use App\Support\Site\SiteDatabaseConfig;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// 子站 baseline seed（必要初始資料，見 README 13 節），由 site:create（6.3）
// 或本機 migrate:fresh --seed（18.1）觸發。建立：
//   - 三種預設身份別（見 7.1）
//   - admin 端口（見 9.1）
//   - 超級管理員帳號（is_system，供 6.11 跳轉登入與開發除錯使用）
//   - 初始系統管理員帳號（is_delegation_root，交付客戶用來建立自己整套權限體系）
// 帳密統一在 config/initial_accounts.php 定義，同一組帳密套用到每個新建的子站
// （超級管理員是我方內部帳號，本來就該固定、不用每站各記一組），不是每次隨機產生。
class SiteBaselineSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // 單站模式沒有 EnsureSiteContext 中介層（CLI 不經過 HTTP），site_current 仍是
        // 佔位假主機（site-context-not-resolved.invalid），必須先套上 .env 的實際連線，
        // 與 SiteResolver／其他 CLI 指令一致（SiteDatabaseConfig::singleSiteConnection）。
        if (! config('multisite.enabled')) {
            Config::set('database.connections.site_current', SiteDatabaseConfig::singleSiteConnection());
            DB::purge('site_current');
        }

        // 模組登記清單改由掃描 app/Api/*/module.json 產生，見 README 6.9、
        // App\Support\Module\ModuleRegistryService——不再靠這裡寫死一份
        // 清單，新增模組只需要補一份 module.json，不用回頭改這個 seeder。
        // 單站部署包沒有 app/Hq／modules 主表（見 HqPresence），只在含主站時同步。
        if (HqPresence::available()) {
            app(ModuleRegistryService::class)->syncToDatabase();
        }

        $superAdminType = IdentityType::query()->updateOrCreate(
            ['code' => 'super_admin'],
            ['name' => '超級管理員', 'is_system' => true, 'is_delegation_root' => false, 'sort' => 0]
        );

        $delegationRootType = IdentityType::query()->updateOrCreate(
            ['code' => 'system_admin'],
            ['name' => '系統管理員', 'is_system' => false, 'is_delegation_root' => true, 'sort' => 1]
        );

        IdentityType::query()->updateOrCreate(
            ['code' => 'staff'],
            ['name' => '一般使用者', 'is_system' => false, 'is_delegation_root' => false, 'sort' => 2]
        );

        $adminPortal = Portal::query()->updateOrCreate(
            ['portal_key' => 'admin'],
            ['name' => '管理後台', 'admin_only' => false, 'is_enabled' => true, 'requires_login' => true]
        );

        $superAdmin = $this->seedAccount('site_super_admin', '超級管理員', $superAdminType->id);
        $delegationRoot = $this->seedAccount('site_delegation_root', '系統管理員', $delegationRootType->id);

        // 系統管理員預設先自我指派 admin 端口的端口管理員身份，
        // 否則交付當下連 admin 後台都進不去（見 7.1「授權上限」範例）。
        $delegationRoot->portalAccess()->updateOrCreate(
            ['portal_id' => $adminPortal->id],
            ['is_portal_admin' => true]
        );

        $this->seedGuestCompanyPortal();
        $this->seedConfigTypeDictionary();
        $this->seedSiteStatusDictionary();
        $this->seedGuestVisitReasonDictionary();
        $this->seedDashboardReportDictionaries();
        $this->seedSiteBasicConfig();
        $this->seedAdminMenu();
        $this->bumpMenuCache();

        $this->command?->info("超級管理員帳號已就緒：{$superAdmin->username}");
        $this->command?->info("系統管理員帳號已就緒：{$delegationRoot->username}");
    }

    // 見 README 7.3：後台自己的側邊欄選單改成也從選單管理（menus 表）讀出
    // 來，不再是 AdminLayout.vue 寫死的固定清單。功能選單節點（使用者管理、
    // 角色管理…）不在這裡手寫，改由 ModuleRegistryService::provisionMenusForSite()
    // 依各模組 module.json 的 menus 宣告自動註冊（見 6.9）——這裡只保留
    // 兩種「不屬於任何模組」的節點：分組（純展開用，見下方）與儀表板
    // （login_only，見下）。
    // 「儀表板」是頂層節點（不掛在任何分組底下，parent_id=null）、sort=0
    // 排在其餘頂層節點最前面，且 login_only=true——只要登入這個端口就
    // 看得到，不對應任何 module_code/permission_code（見
    // MenuService::buildVisible 的 login_only 分支），符合它是每個人的
    // 首頁、不該被角色權限篩掉的定位。
    // 分組節點（module_code／permission_code 皆留空，見 MenuList.vue 的
    // isGroupNode）把功能選單分成兩類，不是扁平的一整排——分組本身不能
    // 點擊，可見性由底下第二層決定；sort 從 1 開始（0 留給儀表板，sort
    // 是 unsignedSmallInteger，見 2024_01_02_000005_create_menus_table，
    // 不能用負數把儀表板排到最前面）。
    // 訪客模組公司端口（company）：每家公司（門牌）的帳號登入這個端口，只看得到自己公司的資料
    // （見 App\Api\Guest\Middleware\EnsureGuestCompanyScope）。三個角色對應公司內身分
    // （GuestCompanyMember::ROLE_NAMES），帳號由系統依身分自動指派，名稱不可在後台改；
    // 權限碼每次 seed 都覆寫成 GuestCompanyMember::rolePermissionCodes() 的版本（與建立端口的
    // migration 共用同一份），要調整請改那裡再重跑 seed。
    // 一般情況下 site:migrate 已經由 migration 建好端口與角色，這裡負責把權限碼同步成最新。
    // 多站開發環境不論訪客模組是否開通都會建立（沒開通時 API 會被 module:guest 擋下、選單不顯示）；
    // 單站部署包沒帶訪客模組原始碼時（未購買，見 SitePackageService::copyModules）直接略過，
    // 否則這裡引用的類別不存在會讓整支 seeder 中斷。
    private function seedGuestCompanyPortal(): void
    {
        if (! class_exists(GuestCompanyMember::class)) {
            return;
        }

        $portal = Portal::query()->updateOrCreate(
            ['portal_key' => GuestCompanyService::PORTAL_KEY],
            ['name' => '公司端口', 'admin_only' => false, 'is_enabled' => true, 'requires_login' => true]
        );

        foreach (GuestCompanyMember::rolePermissionCodes() as $type => $codes) {
            $role = Role::withTrashed()->firstOrNew([
                'portal_id' => $portal->id,
                'name' => GuestCompanyMember::ROLE_NAMES[$type],
            ]);
            $role->permission_codes = $codes;
            $role->deleted_at = null;
            $role->save();
        }
    }

    private function seedAdminMenu(): void
    {
        Menu::query()->updateOrCreate(
            ['portal_key' => 'admin', 'key' => 'dashboard'],
            [
                'parent_id' => null,
                'module_code' => null,
                'permission_code' => null,
                'login_only' => true,
                'title' => '儀表板',
                'subtitle' => '常用功能捷徑',
                'icon' => 'Odometer',
                'path' => '/dashboard',
                'sort' => 0,
                'is_enabled' => true,
            ]
        );

        $groups = [
            ['key' => 'group-account', 'title' => '帳號與權限', 'subtitle' => '使用者、角色與權限指派', 'icon' => 'UserFilled', 'sort' => 1],
            ['key' => 'group-system', 'title' => '系統設定', 'subtitle' => '選單、字典與環境參數', 'icon' => 'Setting', 'sort' => 2],
            // 訪客管理模塊（app/Api/Guest/module.json）的選單分組；未開通該模塊時底下沒有子節點，不會顯示。
            ['key' => 'group-guest', 'title' => '訪客管理', 'subtitle' => '預約、登記、審核與報表', 'icon' => 'Avatar', 'sort' => 3],
            ['key' => 'group-setting', 'title' => '基本資料設定', 'subtitle' => '樓別、樓層、門牌設定', 'icon' => 'Avatar', 'sort' => 4],
            ['key' => 'group-access', 'title' => '門禁設定', 'subtitle' => '門禁設定', 'icon' => 'Avatar', 'sort' => 5],
        ];

        foreach ($groups as $group) {
            Menu::query()->updateOrCreate(
                ['portal_key' => 'admin', 'key' => $group['key']],
                [
                    'parent_id' => null,
                    'module_code' => null,
                    'permission_code' => null,
                    'title' => $group['title'],
                    'subtitle' => $group['subtitle'],
                    'icon' => $group['icon'],
                    'path' => null,
                    'sort' => $group['sort'],
                    'is_enabled' => true,
                ]
            );
        }

        // 新站剛建立時還沒有任何非核心模組被開通（見 6.9），這裡算出來的
        // 啟用集合此時等於全部核心模組——跟改版前手寫 $items 的 9 筆選單
        // 效果一致，只是資料來源換成模組宣告。之後 HQ 開通新模組給這個站
        // 時，選單由 SiteModuleController::enable() 即時補註冊，不需要
        // 重跑這支 seeder。
        $registry = app(ModuleRegistryService::class);

        if (! HqPresence::available()) {
            // 單站部署包：已開通模塊清單讀匯出時固化的 storage/app/site_modules.php。
            $path = storage_path('app/site_modules.php');

            if (! is_file($path)) {
                throw new RuntimeException("找不到單站模塊開通清單：{$path}");
            }

            $registry->provisionMenusForSite($registry->dependencyClosure((array) require $path));

            return;
        }

        $site = Site::query()->where('code', $this->currentSiteCode())->first();

        if ($site) {
            $registry->provisionMenusForSite($registry->enabledCodesForSite($site));
        }
    }

    // GET /api/site/config（8.2）讀取的顯示設定，見 17 節。cfg_value 留空時
    // 該端點會 fallback 預設值，這裡先建立可在後台編輯的設定項目本身。
    // cfg_type 依 17.1 的完整類型清單標註，值本身與擴充前相容（color 的
    // '#1677ff'、textarea 的空字串都是合法值），站點讀值邏輯（SiteConfig
    // 的 pluck('cfg_value','cfg_name')）不看 cfg_type，不受影響。
    private function seedSiteBasicConfig(): void
    {
        $items = [
            ['cfg_name' => 'site_name', 'cfg_title' => '站名', 'cfg_type' => 'string', 'cfg_option' => 100, 'cfg_value' => config('app.name'), 'sort' => 0],
            ['cfg_name' => 'theme_color', 'cfg_title' => '主題色', 'cfg_type' => 'color', 'cfg_value' => '#1677ff', 'sort' => 1],
            // image 類型示範：cfg_option 存圖片規格（見 17.5），上傳後依這裡
            // 定義的尺寸／模式／輸出格式自動產生圖片，不用另外寫死在程式碼。
            // 網站 Logo 預設輸出 300×200 的透明 PNG（網站左上角與 favicon 都用
            // 這張，見 17.4）；縮圖模式預設「填滿輸出」，實際裁切時的框選範圍、
            // 要不要補底色（透明／純色）都在裁切工具裡決定（見 17.5）。
            [
                'cfg_name' => 'logo',
                'cfg_title' => '網站Logo',
                'cfg_type' => 'image',
                'cfg_option' => [
                    'width' => 300,
                    'height' => 200,
                    'mode' => 'cover',
                    'format' => 'png',
                    'transparent' => true,
                ],
                'cfg_style' => ['preview_width' => 120],
                'cfg_value' => null,
                'sort' => 2,
            ],
            ['cfg_name' => 'footer_text', 'cfg_title' => '頁尾文案', 'cfg_type' => 'textarea', 'cfg_value' => '', 'sort' => 3],
            // dic 類型的 baseline 示範：cfg_option 存字典代碼 site_status，
            // 後台會由該字典的啟用中項目自動帶出下拉（見 17.2/17.3）。
            ['cfg_name' => 'site_status', 'cfg_title' => '站台狀態', 'cfg_type' => 'dic', 'cfg_option' => 'site_status', 'cfg_value' => '1', 'sort' => 4],
        ];

        foreach ($items as $item) {
            SystemConfig::query()->updateOrCreate(
                ['cfg_module' => 'site_basic', 'cfg_name' => $item['cfg_name']],
                [
                    'cfg_title' => $item['cfg_title'],
                    'cfg_type' => $item['cfg_type'],
                    'cfg_option' => $item['cfg_option'] ?? null,
                    'cfg_style' => $item['cfg_style'] ?? null,
                    'cfg_value' => $item['cfg_value'],
                    'sort' => $item['sort'],
                ]
            );
        }
    }

    // 見 README 17 開頭：「並附帶 system_cfg_type 字典供前台使用」——類型
    // 清單本身也走字典，任何需要「選一個 cfg_type」的介面（或客製化表單）
    // 都用同一支字典 API 取得選項，項目與 SystemConfig::TYPES/TYPE_TITLES
    // 同步（updateOrCreate，重跑不重複）。
    private function seedConfigTypeDictionary(): void
    {
        $dictionary = SystemDictionary::query()->updateOrCreate(
            ['code' => 'system_cfg_type'],
            [
                'name' => '系統設定類型',
                'description' => 'system_configs.cfg_type 的類型清單（README 17.1），供前台／後台下拉使用',
                'is_enabled' => true,
                'sort' => 0,
            ]
        );

        foreach (SystemConfig::TYPES as $sort => $type) {
            $dictionary->items()->updateOrCreate(
                ['value' => $type],
                [
                    'label' => SystemConfig::TYPE_TITLES[$type] ?? $type,
                    'is_enabled' => true,
                    'sort' => $sort,
                ]
            );
        }
    }

    // dic 類型的示範字典（見 seedSiteBasicConfig 的 site_status 設定）。
    private function seedSiteStatusDictionary(): void
    {
        $dictionary = SystemDictionary::query()->updateOrCreate(
            ['code' => 'site_status'],
            [
                'name' => '站台狀態',
                'description' => 'site_basic.site_status 設定項的下拉選項來源（README 17.3）',
                'is_enabled' => true,
                'sort' => 1,
            ]
        );

        $items = [
            ['value' => '1', 'label' => '正常營運'],
            ['value' => '0', 'label' => '維護中'],
        ];

        foreach ($items as $sort => $item) {
            $dictionary->items()->updateOrCreate(
                ['value' => $item['value']],
                ['label' => $item['label'], 'is_enabled' => true, 'sort' => $sort]
            );
        }
    }

    // 訪客預約單 guest_applies.visit_reason 存的是這個字典的項目值（整數），統計報表與訪客記錄用它顯示文字；
    // 後台「資料字典」可再新增項目。值一旦被預約單使用就不要改，要換說法請改 label。
    private function seedGuestVisitReasonDictionary(): void
    {
        $dictionary = SystemDictionary::query()->updateOrCreate(
            ['code' => 'guest_visit_reason'],
            [
                'name' => '訪客來訪事由',
                'description' => 'guest_applies.visit_reason 的選項來源，供訪客登記／預約、訪客記錄與統計報表使用',
                'is_enabled' => true,
                'sort' => 2,
            ]
        );

        $items = [
            ['value' => '1', 'label' => '業務洽談'],
            ['value' => '2', 'label' => '工程維護'],
            ['value' => '3', 'label' => '會議'],
            ['value' => '4', 'label' => '送貨/收發'],
            ['value' => '5', 'label' => '面試'],
            ['value' => '6', 'label' => '參觀訪問'],
            ['value' => '7', 'label' => '設備檢測'],
            ['value' => '99', 'label' => '其他'],
        ];

        foreach ($items as $sort => $item) {
            $dictionary->items()->updateOrCreate(
                ['value' => $item['value']],
                ['label' => $item['label'], 'is_enabled' => true, 'sort' => $sort]
            );
        }
    }

    // 儀錶板上報設定（GET／PUT /api/guest/dashboard-settings）各下拉／勾選項目的選項來源。
    // 目前上傳方式、格式、排程各只支援一個值，但仍建成字典，之後新增選項（例如檔案上傳、定時上傳）只要加字典項目與後端支援。
    private function seedDashboardReportDictionaries(): void
    {
        $dictionaries = [
            ['code' => 'dashboard_upload_method', 'name' => '儀錶板上傳方式', 'sort' => 3, 'items' => [
                ['value' => 'api', 'label' => 'API 傳送'],
            ]],
            ['code' => 'dashboard_format', 'name' => '儀錶板上傳格式', 'sort' => 4, 'items' => [
                ['value' => 'json', 'label' => 'JSON'],
            ]],
            ['code' => 'dashboard_content', 'name' => '儀錶板上傳內容', 'sort' => 5, 'items' => [
                ['value' => 'heartbeat', 'label' => '心跳（含訪客機故障）'],
                ['value' => 'approved', 'label' => '核准'],
                ['value' => 'modified', 'label' => '修改'],
                ['value' => 'cancelled', 'label' => '取消'],
                ['value' => 'checkin', 'label' => '報到'],
                ['value' => 'checkout', 'label' => '離場'],
            ]],
            ['code' => 'dashboard_schedule', 'name' => '儀錶板上傳排程', 'sort' => 6, 'items' => [
                ['value' => 'realtime', 'label' => '即時上傳'],
            ]],
        ];

        foreach ($dictionaries as $def) {
            $dictionary = SystemDictionary::query()->updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'description' => 'dashboard_report_settings 的選項來源，供「儀錶板上報設定」頁使用',
                    'is_enabled' => true,
                    'sort' => $def['sort'],
                ]
            );

            foreach ($def['items'] as $sort => $item) {
                $dictionary->items()->updateOrCreate(
                    ['value' => $item['value']],
                    ['label' => $item['label'], 'is_enabled' => true, 'sort' => $sort]
                );
            }
        }
    }

    private function seedAccount(string $configKey, string $displayName, int $identityTypeId): User
    {
        $account = config("initial_accounts.{$configKey}");
        // 見 config/initial_accounts.php 說明：site_delegation_root 沒設
        // env 覆寫時，預設用該子站自己的代號（好識別是哪一站），其餘帳號
        // （我方內部固定帳號）預設值直接來自 config，不用另外計算。
        $username = $account['username'] ?: $this->defaultUsername($configKey);
        $existing = User::query()->where('username', $username)->first();

        // 見 HqDatabaseSeeder 同一段說明：密碼清空後重跑 db:seed 不重置密碼，
        // 只有帳號還不存在（第一次建置）才需要密碼、沒設就中斷。
        if (! $account['password']) {
            if (! $existing) {
                $envHint = strtoupper($configKey).'_PASSWORD';

                throw new RuntimeException(
                    "未設定 {$envHint}（見 config/initial_accounts.php），無法建立{$displayName}帳號。"
                );
            }

            $existing->forceFill([
                'name' => $displayName,
                'identity_type_id' => $identityTypeId,
            ])->save();

            return $existing;
        }

        return User::query()->updateOrCreate(
            ['username' => $username],
            [
                'name' => $displayName,
                'identity_type_id' => $identityTypeId,
                'password' => bcrypt($account['password']),
            ]
        );
    }

    // 見 config/initial_accounts.php 說明：site_delegation_root 的預設帳號
    // 綁定「目前是哪個子站」這個執行期資訊，config 檔案本身拿不到，只能
    // 在真正 seed 的當下（這裡）才算得出來。其餘帳號固定用 admin。
    private function defaultUsername(string $configKey): string
    {
        return $configKey === 'site_delegation_root' ? $this->currentSiteCode() : 'admin';
    }

    // 見 6.3：子站資料庫名固定為 {prefix}{code}（例如 base_site_vendora），
    // 從目前 site_current 連線的資料庫名反推站點代碼，不需要呼叫端（CLI／
    // Job／HTTP 建站流程）額外把 code 傳進 seeder，這裡自己算得出來。
    // 單站模式（MULTI_SITE_ENABLED=false）沒有這個命名慣例，直接沿用
    // SiteResolver 的同一個 fallback：SITE_TEMPLATE_NAMESPACE。
    private function currentSiteCode(): string
    {
        if (! config('multisite.enabled')) {
            return (string) env('SITE_TEMPLATE_NAMESPACE', 'default');
        }

        $dbName = (string) config('database.connections.site_current.database');
        $prefix = (string) config('multisite.site_db_prefix');

        return str_starts_with($dbName, $prefix) ? substr($dbName, strlen($prefix)) : $dbName;
    }

    // 見 MenuAdminService 的同一個機制：選單改變後要 bump 快取版本，使用者
    // 選單才會即時反映，不用等最多 1 小時的快取自然過期。seedAdminMenu()
    // 是直接用 Eloquent 寫 menus 表（seeder 的慣例，繞過 Controller/Service
    // 那層驗證），不會經過 MenuAdminService::create/update，所以這裡才需要
    // 手動補呼叫一次——已實測踩過這個坑：重跑 seeder 改了選單標題／新增
    // 節點，前台快取沒被清掉，後台看起來像是完全沒改到。Site 模型固定走
    // 預設 mysql 連線（主站資料庫），跟目前作用中的 site_current（子站
    // 資料庫）是兩條獨立連線，可以同時查、不受目前連線切換影響。
    private function bumpMenuCache(): void
    {
        $siteId = HqPresence::available()
            ? Site::query()->where('code', $this->currentSiteCode())->value('site_id')
            : config('multisite.single_site.site_id');

        if ($siteId) {
            (new MenuCacheVersion)->bump((int) $siteId);
        }
    }
}
