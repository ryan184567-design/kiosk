<?php

/* *****************************************
File: PortalController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 端口管理，見 README 9.1。
***************************************** */

namespace App\Api\Portal\Controllers;

use App\Api\Portal\Services\PortalAdminService;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use App\Support\Portal\Models\Portal;
use Illuminate\Http\Request;
use RuntimeException;

// 端口管理，見 README 9.1。端口是基礎設施表（跟 SystemConfig／Dictionary
// 性質相同），不分端口，權限一律用路由層 permission.api:{code} 檢查目前
// 登入端口是否持有權限即可，寫法比照 SystemConfigController／
// DictionaryController 開頭註解，不套用 Role/User/Menu 那套跨端口 ceiling
// 機制（端口本身不是「某個端口底下」的業務資料）。
class PortalController extends BaseApiController
{
    /**
     * @api-method GET
     * @api-path /api/portals
     * @api-tag Portal
     * @api-summary 列出全部端口
     * @api-description
     * 供端口管理頁面使用，回傳全部端口（不論啟用/停用）。跟
     * `GET /api/users/grantable-portals`（User 模塊）刻意分開：那支只回傳
     * 呼叫者實際能授予「端口管理員」身份的端口子集，這支是完整清單，只有
     * 持有 `api.portal.index` 權限碼的人（一般是系統管理員／超級管理員）
     * 才看得到。
     * @api-portal *
     * @api-response-field data[].id int 端口ID
     * @api-response-field data[].portal_key string 端口代碼
     * @api-response-field data[].name string 端口名稱
     * @api-response-field data[].admin_only bool 是否僅端口管理員可登入
     * @api-response-field data[].is_enabled bool 是否啟用
     * @api-response-field data[].requires_login bool 是否需要登入才能進入（false=允許訪客瀏覽，見 GET /api/portals/public/{portal_key}）
     */
    public function index(PortalAdminService $portals)
    {
        return $this->success(['items' => $portals->queryAll()->get()->map(fn (Portal $p) => $this->present($p))->all()]);
    }

    /**
     * @api-method POST
     * @api-path /api/portals
     * @api-tag Portal
     * @api-summary 建立端口
     * @api-description
     * `portal_key` 建立後不可變更（多處程式碼與各端口自己的 SPA 建置都拿它
     * 當識別碼），只接受小寫英數與底線；同站不可重複。建立好之後，實際要
     * 讓這個端口的 SPA／登入功能運作，還需要另外開發對應的前端建置與
     * `app/Web/{Portal}` 頁面骨架（見 README 9.1），這支只負責建立資料列。
     * @api-portal *
     * @api-request-field portal_key string 端口代碼，小寫英數與底線，建立後不可變更
     * @api-request-field name string 端口名稱
     * @api-request-field admin_only bool 是否僅端口管理員可登入，省略預設 false
     * @api-request-field is_enabled bool 是否啟用，省略預設 true
     * @api-request-field requires_login bool 是否需要登入才能進入，省略預設 true
     * @api-request-example
     * {"portal_key": "vendor", "name": "廠商端口"}
     * @api-response-field data.id int 端口ID
     * @api-response-field data.portal_key string 端口代碼
     */
    public function store(Request $request, PortalAdminService $portals)
    {
        $data = $this->validateInput($request, [
            'portal_key' => 'required|string|max:50|regex:/^[a-z][a-z0-9_]*$/',
            'name' => 'required|string|max:100',
            'admin_only' => 'boolean',
            'is_enabled' => 'boolean',
            'requires_login' => 'boolean',
        ]);

        try {
            $portal = $portals->create($this->currentUser($request), $data, $request->ip());
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        return $this->success($this->present($portal), 201);
    }

    /**
     * @api-method PUT
     * @api-path /api/portals/{portal}
     * @api-tag Portal
     * @api-summary 更新端口設定
     * @api-description
     * 只能改 `name`／`admin_only`／`is_enabled`／`requires_login`，
     * `portal_key` 不接受更新。
     * @api-portal *
     * @api-request-field name string 端口名稱
     * @api-request-field admin_only bool 是否僅端口管理員可登入
     * @api-request-field is_enabled bool 是否啟用
     * @api-request-field requires_login bool 是否需要登入才能進入
     * @api-request-example
     * {"name": "廠商端口", "admin_only": false, "is_enabled": true, "requires_login": true}
     * @api-response-field data.id int 端口ID
     * @api-response-field data.portal_key string 端口代碼
     */
    public function update(Request $request, int $portal, PortalAdminService $portals)
    {
        $model = Portal::query()->find($portal);

        if (! $model) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.portal.not_found'), 404);
        }

        $data = $this->validateInput($request, [
            'name' => 'required|string|max:100',
            'admin_only' => 'boolean',
            'is_enabled' => 'boolean',
            'requires_login' => 'boolean',
        ]);

        $model = $portals->update($this->currentUser($request), $model, $data, $request->ip());

        return $this->success($this->present($model));
    }

    /**
     * @api-method GET
     * @api-path /api/portals/public/{portal_key}
     * @api-tag Portal
     * @api-summary 取得端口的公開資訊（不需要登入）
     * @api-description
     * 子站 SPA 啟動時（登入頁渲染之前）就要呼叫，不能卡在 `jwt.auth` 後面，
     * 見 `resources/vue_src_admin/src/main.js`。只回傳前端決定「這個端口
     * 要不要在進站時強制導去登入頁」所需的最小資訊；端口不存在或已停用
     * 一律回 404（不區分「不存在」與「已停用」，訪客不需要知道細節）。
     * @api-portal *
     * @api-no-request-body
     * @api-response-field data.portal_key string 端口代碼
     * @api-response-field data.name string 端口名稱
     * @api-response-field data.requires_login bool 是否需要登入才能進入
     */
    public function publicInfo(string $portal_key)
    {
        $portal = Portal::query()->where('portal_key', $portal_key)->where('is_enabled', true)->first();

        if (! $portal) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.portal.not_found'), 404);
        }

        return $this->success([
            'portal_key' => $portal->portal_key,
            'name' => $portal->name,
            'requires_login' => $portal->requires_login,
        ]);
    }

    private function present(Portal $portal): array
    {
        return [
            'id' => $portal->id,
            'portal_key' => $portal->portal_key,
            'name' => $portal->name,
            'admin_only' => $portal->admin_only,
            'is_enabled' => $portal->is_enabled,
            'requires_login' => $portal->requires_login,
        ];
    }
}
