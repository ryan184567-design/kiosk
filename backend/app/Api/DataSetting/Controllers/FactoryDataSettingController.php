<?php

namespace App\Api\DataSetting\Controllers;

use App\Api\DataSetting\Models\Doorplate;
use App\Api\DataSetting\Services\DataSettingService;
use App\Support\Api\Concerns\MapsServiceExceptions;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

// 機器接口標註慣例見 FactoryAuthController 開頭與 DEVELOP.MD 12.9：@api-auth app + @api-scope，
// 權限由路由上的 factory.scope:xxx 聲明，兩者要一致。
class FactoryDataSettingController extends BaseApiController
{
    use MapsServiceExceptions;

    /**
     * @api-method GET
     * @api-path /api/factory/data_setting
     * @api-tag Factory
     * @api-summary 查詢樓層、門牌、公司名稱
     * @api-portal *
     * @api-auth app
     * @api-scope setting.apply.read
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
    public function index(Request $request, DataSettingService $service)
    {

        try {
            $result = $this->paginateOrGet(
                $service->doorplate(),
                (int) $request->integer('per_page', -1),
                (int) $request->integer('page', 1),
            );
        } catch (RuntimeException $e) {
            return $this->failFromService($e);
        }

        $result['items'] = collect($result['items'])
            ->map(fn ($doorplate) => $this->present($doorplate))
            ->all();

        return $this->success($result);
    }

    private function present(Doorplate $doorplate): array
    {
        return [
            'id' => $doorplate->id,
            'doorplate' => $doorplate->doorplate,
            'floor' => $doorplate->floor,
            'household' => $doorplate->household,
            'company_name' => $doorplate->company_name,
            'status' => $doorplate->status
        ];
    }

}
