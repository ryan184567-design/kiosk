<?php

namespace App\Api\Guest\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use App\Support\Api\Concerns\PaginationHelper;
use App\Api\Guest\Services\GuestSlotService;
use Illuminate\Http\Request;

use App\Api\Guest\Models\GuestSlot;

class GuestSlotController extends BaseApiController
{
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/guest/slots
     * @api-tag GuestSlot
     * @api-summary 列出證件櫃
     * @api-description
     * 分頁參數 `per_page=-1`（預設）代表不分頁，回傳全部。
     * @api-portal *
     * @api-response-field data.items[].id int 證件櫃ID
     * @api-response-field data.items[].name string 證件櫃名稱
     * @api-response-field data.items[].remark string 備註
     * @api-response-field data.items[].status int 使用狀態
     * @api-response-field data.total int 總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function slotIndex(Request $request, GuestSlotService $slot)
    {
        $result = $this->paginateOrGet(
            $slot->slots(),
            (int) $request->integer('per_page', -1),
            (int) $request->integer('page', 1),
        );

        $result['items'] = collect($result['items'])->map(fn (GuestSlot $g) => $this->present($g))->all();

        return $this->success($result);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * @api-method POST
     * @api-path /api/guest/slots
     * @api-tag GuestSlot
     * @api-summary 新增證件櫃
     * @api-portal admin
     * @api-description
     * 建立一筆證件櫃資料
     * @api-request-field name string 證件櫃名稱
     * @api-request-field remark string 備註
     * @api-request-example
     * {"name": "A10","remark": "身分證"}
     * @api-response-empty
     */
    public function slotStore(Request $request, GuestSlotService $slot)
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:50',
            'remark' => 'required|string',
        ]);

        $slot->create(
            $data['name'],
            $data['remark'],
        );

        return $this->success();
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

     /**
     * @api-method PUT
     * @api-path /api/guest/slots/{id}
     * @api-tag GuestSlot
     * @api-summary 修改證件櫃
     * @api-portal admin
     * @api-description
     * 修改證件櫃內容
     * @api-request-field name string 證件櫃名稱
     * @api-request-field remark string 備註
     * @api-request-field status int 使用狀態
     * @api-request-example
     * {"name": "E02","remark": "專放證件", "status": 1}
     * @api-response-empty
     */
    public function slotUpdate(Request $request, string $id, GuestSlotService $slot)
    {
        $data = $this->validateInput($request, [
            'name' => 'required|string|max:50',
            'remark' => 'required|string',
            'status' => 'required|int|max:1',
        ]);

        $slot->update(
            $id,
            $data['name'],
            $data['remark'],
            $data['status'],
        );

        return $this->success();
    }

    /**
     * @api-method DELETE
     * @api-path /api/guest/slots/{id}
     * @api-tag GuestSlot
     * @api-summary 刪除證件櫃
     * @api-description
     * 仍在使用中的證件櫃會拒絕刪除，需先將狀態改為未使用
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function slotDestroy(string $id, GuestSlotService $slot)
    {
        $slot->delete($id);

        return $this->success();
    }

    private function present(GuestSlot $guestSlot): array
    {
        return [
            'id' => $guestSlot->id,
            'name' => $guestSlot->name,
            'remark' => $guestSlot->remark,
            'status' => $guestSlot->status,
        ];
    }
}
