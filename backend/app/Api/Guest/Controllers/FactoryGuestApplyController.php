<?php

/* *****************************************
File: FactoryGuestApplyController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 訪客機專用的訪客預約接口（App 認證 + scope 授權），業務邏輯沿用 GuestApplyService
***************************************** */

namespace App\Api\Guest\Controllers;

use App\Api\Guest\Models\Guest;
use App\Api\Guest\Models\GuestApply;
use App\Api\Guest\Services\GuestApplyService;
use App\Support\Api\Concerns\MapsServiceExceptions;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

// 機器接口標註慣例見 FactoryAuthController 開頭與 DEVELOP.MD 12.9：@api-auth app + @api-scope，
// 權限由路由上的 factory.scope:xxx 聲明，兩者要一致。
class FactoryGuestApplyController extends BaseApiController
{
    use MapsServiceExceptions;

    /**
     * @api-method GET
     * @api-path /api/factory/guest/applies
     * @api-tag Factory
     * @api-summary 以速通碼查詢訪客預約記錄
     * @api-portal *
     * @api-auth app
     * @api-scope guest.apply.read
     * @api-description
     * 訪客機以速通碼查詢「今天、已通知」的預約單，只回傳訪客機需要的欄位。
     * 查無資料（速通碼錯誤、非今天、狀態不符、已取消）回 404。
     * 查詢參數（query string）：`fast_pass_code`（必填，速通碼），例如 `GET /api/factory/guest/applies?fast_pass_code=12345678`。
     * @api-no-request-body
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.host_name string 受訪人
     * @api-response-field data.host_company_name string 受訪公司名稱，門牌不存在時為 null
     * @api-response-field data.guest_count int 訪客人數
     * @api-response-field data.visitor array 來訪者明細
     * @api-response-field data.visitor[].id int 來訪者ID
     * @api-response-field data.visitor[].guest_name string 來訪者姓名
     * @api-response-field data.visitor[].guest_company_name string 公司名稱，未填為 null
     * @api-response-field data.visitor[].guest_phone string 手機號碼
     */
    public function index(Request $request, GuestApplyService $service)
    {
        $data = $this->validateInput($request, [
            'fast_pass_code' => 'required|string|max:20',
        ]);

        try {
            $apply = $service->check($data['fast_pass_code']);
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        return $this->success($this->present($apply));
    }

    private function present(GuestApply $apply): array
    {
        return [
            'id' => $apply->id,
            'reservation_id' => $apply->reservation_id,
            'host_name' => $apply->host_name,
            'host_company_name' => $apply->doorplate?->company_name,
            'guest_count' => $apply->guest_count,
            'visitor' => $apply->guests->map(fn (Guest $g) => [
                'id' => $g->id,
                'guest_name' => $g->name,
                'guest_company_name' => $g->company_name,
                'guest_phone' => $g->phone,
            ])->all(),
        ];
    }
}
