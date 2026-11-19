<?php


namespace App\Api\Guest\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use App\Api\Guest\Services\GuestApplyService;
use App\Api\Guest\Services\VisitMachineService;
use Illuminate\Http\Request;

use App\Api\Guest\Models\Guest;
use App\Api\Guest\Models\GuestApply;

use RuntimeException;

class VisitorMachineController extends BaseApiController
{
    /**
     * @api-method POST
     * @api-path /api/guest/applies/check
     * @api-tag GuestApply
     * @api-summary 訪客機預約單查詢狀態
     * @api-portal admin
     * @api-description
     * 傳入速通碼查詢預約單是否存在，並回傳基本資料
     * @api-request-field check_value string 速通碼
     * @api-request-example {"check_value": 4562684331}
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.host_company_name string 受訪公司名稱
     * @api-response-field data.host_name string 受訪人
     * @api-response-field data.guest_count int 訪客人數
     * @api-response-field data.visitor array 來訪者明細
     * @api-response-field data.visitor[].id int 來訪者ID
     * @api-response-field data.visitor[].name string 來訪者姓名
     * @api-response-field data.visitor[].company_name string 公司名稱
     * @api-response-field data.visitor[].phone string 手機號碼
     */
    public function check(Request $request, GuestApplyService $service, VisitMachineService $auth)
    {
        $key = $request->header('Authorization');
        if (! $key) {
            throw new RuntimeException(__('api.visit_machine.api_key_not_found'));
        }

        // 驗證api key
        $auth->apiAuth($key);

        $data = $this->validateInput($request, [
            'check_value' => 'required|string|max:10',
        ]);

        $guestApply = $service->check($data['check_value']);

        return $this->success($this->presentCheck($guestApply), 201);
    }

    /**
     * @api-method POST
     * @api-path /api/guest/applies/checkDone
     * @api-tag GuestApply
     * @api-summary 訪客機資料記錄
     * @api-portal admin
     * @api-description
     * 訪客完成報到，訪客機回傳資訊寫入紀錄
     * @api-request-field id int 預約單ID
     * @api-request-field slot int 證件櫃ID
     * @api-request-field consent_form int 訪客同意書
     * @api-request-example {"id": 1, "slot": 2, "consent_form", 1}
     * @api-response-field data.id int 預約單ID
     * @api-response-field data.reservation_id string 預約單號
     * @api-response-field data.guest_count int 訪客人數
     * @api-response-field data.access_card_no string 門禁卡號
     */
    public function checkDone(Request $request, GuestApplyService $service, VisitMachineService $auth)
    {
        $key = $request->header('Authorization');
        if (! $key) {
            throw new RuntimeException(__('api.visit_machine.api_key_not_found'));
        }

        // 驗證api key
        $auth->apiAuth($key);

        $data = $this->validateInput($request, [
            'id' => 'required|int|max:10',
            'slot' => 'required|int|max:10',
            'consent_form' => 'required|int|max:1',
        ]);

        $guestApply = $service->checkDone(
            $data['id'],
            $data['slot'],
            $data['consent_form'],
        );

        return $this->success($this->presentCheckDone($guestApply), 201);
    }

    /** 訪客機預約單查詢 */
    private function presentCheck(GuestApply $apply): array
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

    /** 訪客機完成報到 */
    private function presentCheckDone(GuestApply $apply): array
    {
        return [
            'id' => $apply->id,
            'reservation_id' => $apply->reservation_id,
            'guest_count' => $apply->guest_count,
            'access_card_no' => $apply->access_card_no
        ];
    }
}
