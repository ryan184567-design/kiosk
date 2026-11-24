<?php

namespace App\Api\DataSetting\Controllers;

use App\Api\DataSetting\Services\DataSettingService;
use App\Api\Guest\Services\GuestReasonDictionary;
use App\Support\Http\Controllers\BaseApiController;

// 機器接口標註慣例見 FactoryAuthController 開頭與 DEVELOP.MD 12.9：@api-auth app + @api-scope，
// 權限由路由上的 factory.scope:xxx 聲明，兩者要一致。
class FactoryDataSettingController extends BaseApiController
{
    /**
     * @api-method GET
     * @api-path /api/factory/data_setting/index
     * @api-tag Factory
     * @api-summary 門牌列表
     * @api-portal *
     * @api-auth app
     * @api-scope data_setting.doorplate.index
     * @api-description
     * 訪客機畫面用的選項：依樓層分組的門牌（公司）清單，以及來訪事由清單。
     * 樓層依自然排序（2 排在 10 之前），同一樓層內依公司名稱排序。
     * 只列出啟用中、且有公司名稱的門牌；一次回傳全部資料，不分頁。
     * @api-no-request-body
     * @api-response-field data.items array 樓層清單
     * @api-response-field data.items[].floor int 樓層
     * @api-response-field data.items[].companies array 該樓層的公司（門牌）
     * @api-response-field data.items[].companies[].id int 門牌ID，新增預約時作為 doorplate_id
     * @api-response-field data.items[].companies[].company_name string 公司名稱
     * @api-response-field data.reason object 來訪事由列表
     * @api-response-field data.reason[] string 來訪事由選項。key 為選項代碼（數字，以字串形式出現），value 為顯示文字；
     */
    public function index(DataSettingService $service, GuestReasonDictionary $reason)
    {
        return $this->success([
            'items' => $service->companyList(),
            'reason' => $reason->labels(),
        ]);
    }
}
