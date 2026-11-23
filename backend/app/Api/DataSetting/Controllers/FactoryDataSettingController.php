<?php

namespace App\Api\DataSetting\Controllers;

use App\Api\DataSetting\Models\Doorplate;
use App\Api\DataSetting\Services\DataSettingService;
use App\Support\Api\Concerns\MapsServiceExceptions;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;

// 機器接口標註慣例見 FactoryAuthController 開頭與 DEVELOP.MD 12.9：@api-auth app + @api-scope，
// 權限由路由上的 factory.scope:xxx 聲明，兩者要一致。
class FactoryDataSettingController extends BaseApiController
{
    use MapsServiceExceptions;

    /**
     * @api-method GET
     * @api-path /api/factory/data_setting/index
     * @api-tag Factory
     * @api-summary 門牌列表
     * @api-portal *
     * @api-auth app
     * @api-scope data_setting.doorplate.index
     * @api-description
     * 訪客機列出目前樓層、門牌、公司名稱資訊
     * @api-query-field page int optional 頁碼（預設 1）
     * @api-query-field per_page int optional 每頁筆數，-1 代表全部，未傳則使用配置值
     * @api-response-field data[].id int 門牌ID
     * @api-response-field data[].doorplate int 門牌
     * @api-response-field data[].floor int 樓層
     * @api-response-field data[].household int 室/戶/之
     * @api-response-field data[].company_name string 公司名稱
     */
    public function index(Request $request, DataSettingService $service)
    {
        $data = $this->validateInput($request, [
            'page' => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:-1|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? -1);

        $result = $this->paginateOrGet(
            $service->doorplate(),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])
            ->map(fn (Doorplate $doorplate) => $this->present($doorplate))
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
