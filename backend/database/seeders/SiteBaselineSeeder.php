<?php

/* *****************************************
File: SiteBaselineSeeder.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 子站 baseline seed（必要初始資料，見 README 13 節），由 site:create（6.3） 或本機 migrate:fresh --seed（18.1）觸發。
***************************************** */

namespace Database\Seeders;

use App\Api\Dictionary\Models\SystemDictionary;
use App\Api\Menu\Models\Menu;
use App\Api\SystemConfig\Models\SystemConfig;
use App\Api\User\Models\User;
use App\Hq\Site\Models\Site;
use App\Support\Identity\Models\IdentityType;
use App\Support\Menu\MenuCacheVersion;
use App\Support\Module\ModuleRegistryService;
use App\Support\Portal\Models\Portal;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
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
        // 模組登記清單改由掃描 app/Api/*/module.json 產生，見 README 6.9、
        // App\Support\Module\ModuleRegistryService——不再靠這裡寫死一份
        // 清單，新增模組只需要補一份 module.json，不用回頭改這個 seeder。
        app(ModuleRegistryService::class)->syncToDatabase();

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

        $this->seedConfigTypeDictionary();
        $this->seedSiteStatusDictionary();
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
        $siteId = Site::query()->where('code', $this->currentSiteCode())->value('site_id');

        if ($siteId) {
            (new MenuCacheVersion)->bump((int) $siteId);
        }
    }
}
