<?php

/* *****************************************
File: SiteConfigController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 前台 SPA 啟動時查詢的站點執行期設定，見 README 8.2。
***************************************** */

namespace App\Api\Site\Controllers;

use App\Api\Auth\Models\UserJwtSession;
use App\Api\SystemConfig\Models\SystemConfig;
use App\Api\User\Models\User;
use App\Support\Auth\JwtService;
use App\Support\Http\Controllers\BaseApiController;
use App\Support\Menu\MenuService;
use App\Support\Portal\Models\Portal;
use App\Support\Site\SiteContext;
use Illuminate\Http\Request;

class SiteConfigController extends BaseApiController
{
    /**
     * @api-method GET
     * @api-path /api/site/config
     * @api-tag Site
     * @api-summary 取得執行期站點設定
     * @api-description
     * 前台 Vue3 SPA 啟動、掛載畫面之前一定會先呼叫這支，確保第一次渲染
     * 就有正確的站名/主題色/選單，不會有先顯示預設樣式再跳成正確樣式的
     * 閃爍。**不需要登入**（未帶 token 或 token 失效都不會讓整支請求失敗，
     * 只是回傳的 `menu` 會是空陣列而已）——因為前台頁面骨架本身在使用者
     * 登入前就要能正常顯示（例如登入頁本身）。
     * 不論子站是完全用公版前端、還是有輕度客製化（用 `custom_routes` 動態
     * 掛載額外頁面元件、不用整包重新編譯）、還是整站獨立編譯，都呼叫同一支
     * 取得執行期設定，差別只在於前端後續怎麼使用這些欄位。
     * 若請求有帶 `Authorization: Bearer` 且是有效未過期的子站 JWT
     * （驗簽通過、對應的 session 仍是啟用狀態），`menu` 會依這個 token
     * 對應的使用者、端口（`portal_id`）、角色（`role_id`）即時算出這個人
     * 實際看得到哪些選單節點（依權限碼過濾，沒有任何可見子節點的父節點
     * 也會被拿掉）；token 無效、缺少、或找不到對應使用者/端口時，`menu`
     * 一律回空陣列，不會讓整支端點失敗。
     * @api-portal *
     * @api-response-field data.site_name string 站名
     * @api-response-field data.logo string 站徽網址，未設定時為 null
     * @api-response-field data.theme_color string 主題色（hex 色碼）
     * @api-response-field data.footer_text string 頁尾文案
     * @api-response-field data.site_status string 站台狀態（見 17.3 字典 site_status），1=正常營運、0=維護中，前台依此顯示維護頁
     * @api-response-field data.custom_routes[].path string 動態掛載頁面的路由路徑（前端輕度客製化用，不需要整包重新編譯即可新增頁面）
     * @api-response-field data.custom_routes[].component string 動態掛載頁面對應的元件檔案路徑
     * @api-response-field data.menu[].key string 選單節點識別碼
     * @api-response-field data.menu[].title string 選單標題
     * @api-response-field data.menu[].subtitle string 選單副標題
     * @api-response-field data.menu[].icon string 選單圖示
     * @api-response-field data.menu[].path string 對應的前端路徑
     * @api-response-field data.menu[].target string 開啟方式，_self=目前視窗、_blank=新視窗
     * @api-response-field data.menu[].children[] array 子選單節點，結構遞迴同上（無子節點時為空陣列）
     * @api-response-field data.site_code string 這次請求實際解析出的站點代碼；前端用來比對網址第一段是否真的是路徑前綴，藉此決定 Vue Router 的 base
     */
    public function show(Request $request, SiteContext $siteContext, JwtService $jwt, MenuService $menuService)
    {
        $configs = SystemConfig::query()
            ->where('cfg_module', 'site_basic')
            ->pluck('cfg_value', 'cfg_name');

        return $this->success([
            'site_name' => $configs['site_name'] ?? config('app.name'),
            'logo' => $configs['logo'] ?? null,
            'theme_color' => $configs['theme_color'] ?? null,
            'footer_text' => $configs['footer_text'] ?? '',
            'site_status' => $configs['site_status'] ?? '1',
            'menu' => $this->resolveMenu($request, $siteContext, $jwt, $menuService),
            'custom_routes' => [],
            // 見各 SPA 的 router/index.js：前端要靠這個欄位判斷網址上的第一段
            // 是不是「真的在用路徑前綴進站」，藉此決定 Vue Router 的 base，
            // 不能只憑網址片段自己猜（猜錯會把一般找不到的頁面誤判成站點首頁）。
            'site_code' => $siteContext->code,
        ]);
    }

    // 見 8.2：此端點不需要登入（前台 SPA 啟動就要呼叫），但若帶了有效的子站
    // JWT，就依當前登入的 portal_id/role_id 一併回傳選單（7.3）；沒帶或無效
    // 一律回空陣列，不可因此讓整支端點失敗（未登入時前台本來就不需要選單）。
    private function resolveMenu(Request $request, SiteContext $siteContext, JwtService $jwt, MenuService $menuService): array
    {
        $token = $request->bearerToken();

        if (! $token) {
            return [];
        }

        $claims = $jwt->decode($token, (string) config('jwt.site_secret'));

        if (! $claims) {
            return [];
        }

        $sessionActive = UserJwtSession::query()->where('jti', $claims->jti)->where('is_active', true)->exists();

        if (! $sessionActive) {
            return [];
        }

        $user = User::query()->find($claims->sub);
        $portal = Portal::query()->find($claims->portal_id);

        if (! $user || ! $portal) {
            return [];
        }

        return $menuService->getMenuForUser($user, (int) $claims->portal_id, $claims->role_id ?? null, $portal->portal_key, $siteContext->siteId);
    }
}
