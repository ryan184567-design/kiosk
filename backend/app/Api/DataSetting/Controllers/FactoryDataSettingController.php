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
     * @api-path /api/factory/data_setting/index
     * @api-tag Factory
     * @api-summary 查詢樓層、門牌、公司名稱
     * @api-portal *
     * @api-auth app
     * @api-scope data_setting.doorplate.index
     * @api-description
     * 訪客機查詢目前樓層、門牌、公司名稱資訊
     * @api-no-request-body
     * @api-response-field data.id int 門牌ID
     * @api-response-field data.doorplate int 門牌
     * @api-response-field data.floor int 樓層
     * @api-response-field data.household int 室/戶/之
     * @api-response-field data.company_name string 公司名稱
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
        ];
    }

}
