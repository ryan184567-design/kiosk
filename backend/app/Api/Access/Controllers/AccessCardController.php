<?php

namespace App\Api\Access\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use App\Support\Api\Concerns\PaginationHelper;
use Illuminate\Http\Request;

use App\Api\Access\Services\AccessCardService;

use App\Api\Access\Models\AccessCard;

class AccessCardController extends BaseApiController
{
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/access/cards
     * @api-tag AccessCard
     * @api-summary 訪客卡列表
     * @api-description
     * - `per_page` 傳 -1 代表不分頁，一次回傳全部符合條件的資料；省略時預設 10
     * @api-portal admin
     * @api-dict access_card_status 0:未使用，1:使用中
     * @api-query-field page int optional 頁碼（預設 1）
     * @api-query-field per_page int optional 每頁筆數，-1 代表全部，未傳則使用配置值
     * @api-response-field data.items[].id int 感應卡ID
     * @api-response-field data.items[].card_number int 訪客卡號
     * @api-response-field data.items[].status int 使用狀態
     * @api-response-field data.items[].person_id string 訪客卡ID
     * @api-response-field data.items[].reserve_date string 啟用日期
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function indexCard(Request $request, AccessCardService $access)
    {
        $data = $this->validateInput($request, [
            'page' => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:-1|max:200',
        ]);

        $perPage = (int) ($data['per_page'] ?? 10);

        $result = $this->paginateOrGet(
            $access->list(),
            $perPage === 0 ? 10 : $perPage,
            (int) ($data['page'] ?? 1),
        );

        $result['items'] = collect($result['items'])->map(fn (AccessCard $a) => $this->present($a))->all();

        return $this->success($result);
    }

    /**
     * @api-method POST
     * @api-path /api/access/cards
     * @api-tag AccessCard
     * @api-summary 新增訪客卡
     * @api-portal admin
     * @api-description
     * 新增可使用的訪客卡片
     * 新增後狀態固定為 0（未使用）
     * @api-dict access_card_status 0:未使用，1:使用中
     * @api-request-field card_number int 感應卡號
     * @api-request-example {"card_number": 1211123200}
     * @api-response-field data.id int 感應卡ID
     * @api-response-field data.card_number int 訪客卡號
     * @api-response-field data.starus int 使用狀態
     * @api-response-field data.person_id string 訪客卡ID
     * @api-response-field data.reserve_date string 啟用日期
     */
    public function storeCard(Request $request, AccessCardService $access)
    {
        $data = $this->validateInput($request, [
            'card_number' => ['required', 'string', 'regex:/^\d{1,10}$/'],
        ]);

        $result = $access->add($data['card_number']);

        return $this->success($this->present($result));
    }

    /**
     * @api-method PUT
     * @api-path /api/access/cards/{id}
     * @api-tag AccessCard
     * @api-summary 修改訪客卡
     * @api-portal admin
     * @api-description
     * 修改訪客卡片，修改狀態與啟用日期
     * 更改為未使用時，啟用日期為null
     * @api-dict access_card_status 0:未使用，1:使用中
     * @api-request-field status int 必填，使用狀態
     * @api-request-field reserve_date string 啟用日期
     * @api-request-example {"status": 1, "reserve_date": "2026-11-10"}
     * @api-response-field data.id int 感應卡ID
     * @api-response-field data.card_number int 訪客卡號
     * @api-response-field data.starus int 使用狀態
     * @api-response-field data.person_id string 訪客卡ID
     * @api-response-field data.reserve_date string 啟用日期
     */
    public function updateCard(Request $request, string $id, AccessCardService $access)
    {
        $data = $this->validateInput($request, [
            'status' => ['required', 'int', 'in:0,1'],
            'reserve_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $result = $access->update(
            $id,
            $data['status'],
            $data['reserve_date'] ?? '',
        );

        return $this->success($this->present($result));
    }

    /**
     * @api-method DELETE
     * @api-path /api/access/cards/{id}
     * @api-tag AccessCard
     * @api-summary 刪除訪客卡
     * @api-portal admin
     * @api-description 刪除訪客卡
     * @api-no-request-body
     * @api-response-empty
     */
    public function destroyCard(string $id, AccessCard $access)
    {
        $access->delete($id);
        //
        return $this->success();
    }

    /** 列表只需要摘要欄位 */
    private function present(AccessCard $access): array
    {
        return [
            'id' => $access->id,
            'card_number' => $access->card_number,
            'status' => $access->status,
            'person_id' => $access->person_id,
            'reserve_date' => $access->reserve_date
        ];
    }
}
