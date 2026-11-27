<?php

namespace App\Api\Access\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;

use App\Api\Access\Services\AccessFloorService;

use App\Api\Access\Models\AccessFloor;

class AccessFloorSettingController extends BaseApiController
{
    /**
     * @api-method GET
     * @api-path /api/access/floor/
     * @api-tag AccessFloor
     * @api-summary 訪客樓層門禁權限設定列表
     * @api-portal admin
     * @api-description
     * 列出所有樓層的訪客門禁權限設定，依樓層由小到大排序，不分頁（樓層數量有限）。
     * - 每筆會依設定好的可到樓層，逐台電梯列出可到樓層名稱：樓層代碼會換成樓層名稱（例如 5 → 1F），對不到名稱時保留代碼
     * - 卡機名稱取自門禁系統的裝置清單，查不到時以 DeviceId 代替
     * - `ele_group_count` 為設定了可到樓層的電梯（卡機）數；`access_control_count` 為梯控卡機總數
     * @api-response-field data.floor array 設定列表
     * @api-response-field data.floor[].id int 設定ID
     * @api-response-field data.floor[].floor int 樓層
     * @api-response-field data.floor[].remark string 備註
     * @api-response-field data.floor[].status int 狀態，新增時固定為 1（啟用）
     * @api-response-field data.floor[].floors_by_device array 各電梯（卡機）可到的樓層
     * @api-response-field data.floor[].floors_by_device[].device_name string 裝置ID
     * @api-response-field data.floor[].floors_by_device[].floors string 可到樓層名稱，以逗號分隔，例如 1F, 2F, 3F
     * @api-response-field data.floor[].ele_group_count int 設定了可到樓層的電梯（卡機）數
     * @api-response-field data.floor[].access_control_count int 梯控卡機數
     * @api-response-field data.elevator array 電梯樓層列表
     * @api-response-field data.elevator[].key string 裝置ID
     * @api-response-field data.elevator[].key.Floor int 樓層代碼
     * @api-response-field data.elevator[].key.Label string 樓層名稱
     */
    public function indexFloorAccess(Request $request, AccessFloorService $access)
    {
        $items = collect($access->getFloorAccessList())
            ->map(fn (AccessFloor $item) => $this->presentListItem($item))
            ->all();

        $elevatorList = $access->elevatorList();

        return $this->success(['floor' => $items, 'elevator'=> $elevatorList]);
    }

    /**
     * @api-method POST
     * @api-path /api/access/floor/
     * @api-tag AccessFloor
     * @api-summary 新增訪客樓層門禁權限設定
     * @api-portal admin
     * @api-description
     * 為某一樓層建立訪客門禁權限設定：哪些卡機（`control_list`）可通行，以及每台電梯可到哪些樓層（`floor_list`）。
     * - 同一樓層只能有一筆設定，已存在時回傳 HTTP 409
     * - `control_list`、`floor_list` 都是 **JSON 字串**，不是物件；格式不合法時回傳一般驗證失敗
     * - `control_list`: DeviceId 為裝置ID，AccessMode為裝置模式，AccessScheduleId使用的通行時間ID
     * - `floor_list`: DeviceId 為裝置ID，Floors 為樓層代碼；沒有勾選任何樓層的卡機會被忽略，樓層代碼會去除重複
     * - 新增後狀態固定為 1（啟用）
     * @api-request-field floor int 樓層，必填，最大 100
     * @api-request-field control_list string 梯控卡機清單（JSON 字串），必填，例如 [{"DeviceId":"729d9e80-8b40-4891-b153-b88a0919d205","AccessMode":2,"AccessScheduleId":"9145c9b4-702f-408b-85f2-2daf9f6b17ee"}]
     * @api-request-field floor_list string 各電梯可到樓層（JSON 字串），必填，例如 [{"DeviceId":"4917b746-9835-47f1-8909-b17462d118cb","Floors":[5,6]}]
     * @api-request-field remark string 備註，必填，最長 200 字
     * @api-request-example
     * {"floor": 5, "control_list": "[{\"DeviceId\":\"4917b746-9835-47f1-8909-b17462d118cb\",\"AccessMode\":2,\"AccessScheduleId\":\"9145c9b4-702f-408b-85f2-2daf9f6b17ee\"}]", "floor_list": "[{\"DeviceId\":\"4917b746-9835-47f1-8909-b17462d118cb\",\"Floors\":[5,9]}]", "remark": "5 樓訪客卡"}
     * @api-response-field data.id int 新增的設定ID
     */
    public function storeFloorAccess(Request $request, AccessFloorService $access)
    {
        $data = $this->validateInput($request, [
            'floor' => ['required', 'int', 'max:100'],
            'control_list' => ['required', 'string'],
            'floor_list' => ['required', 'string'],
            'remark' => ['required', 'string', 'max:200'],
        ]);

        $result = $access->addFloorAccess(
            $data['floor'],
            $data['control_list'],
            $data['floor_list'],
            $data['remark'],
        );

        return $this->success();
    }

    /**
     * @api-method GET
     * @api-path /api/access/floor/{id}
     * @api-tag AccessFloor
     * @api-summary 查看單筆訪客樓層門禁權限設定
     * @api-portal admin
     * @api-description
     * 取得單筆設定，供編輯表單帶出資料。
     * 系統會把已儲存的各電梯可到樓層，依 DeviceId 併回 `access_control_list` 的每一列（`Floors`），表單可以逐列直接帶出；
     * 該卡機沒有設定可到樓層時 `Floors` 為空陣列。找不到時回傳 HTTP 404。
     * @api-response-field data.id int 設定ID
     * @api-response-field data.floor int 樓層
     * @api-response-field data.remark string 備註
     * @api-response-field data.status int 狀態
     * @api-response-field data.access_control_list array 梯控卡機清單，新增時送出的各列欄位會原樣帶回，另外附上 Floors
     * @api-response-field data.access_control_list[].DeviceId string 卡機ID
     * @api-response-field data.access_control_list[].Floors array 該卡機可到的樓層代碼（整數陣列），沒有設定為空陣列
     * @api-response-field data.access_control_list[].Floors array 該卡機可到的樓層代碼（整數陣列），沒有設定為空陣列
     * @api-response-field data.created_at datetime 建立時間
     * @api-response-field data.updated_at datetime 最後更新時間
     */
    public function showFloorAccess(int $id, AccessFloorService $access)
    {
        $result = $access->getFloorAccessId($id);

        return $result;
    }

    /**
     * @api-method PUT
     * @api-path /api/access/floor/{id}
     * @api-tag AccessFloor
     * @api-summary 修改訪客樓層門禁權限設定
     * @api-portal admin
     * @api-description
     * 修改單筆設定，欄位規則與新增相同，`control_list`、`floor_list` 為整批覆寫。
     * - 找不到回傳 HTTP 404
     * - 改成的樓層已被其他設定使用時回傳 HTTP 409（改自己原本的樓層不算重複）
     * - `control_list`、`floor_list` 為 JSON 字串，格式不合法時回傳一般驗證失敗
     * - `control_list`: DeviceId 為裝置ID，AccessMode為裝置模式，AccessScheduleId使用的通行時間ID
     * - `floor_list`: DeviceId 為裝置ID，Floors 為樓層代碼；沒有勾選任何樓層的卡機會被忽略，樓層代碼會去除重複
     * @api-request-field floor int 樓層，必填，最大 100
     * @api-request-field control_list string 梯控卡機清單（JSON 字串），必填，例如 [{"DeviceId":"729d9e80-8b40-4891-b153-b88a0919d205","AccessMode":2,"AccessScheduleId":"9145c9b4-702f-408b-85f2-2daf9f6b17ee"}]
     * @api-request-field floor_list string 各電梯可到樓層（JSON 字串），必填，例如 [{"DeviceId":"4917b746-9835-47f1-8909-b17462d118cb","Floors":[5,6,7]}]
     * @api-request-field remark string 備註，必填，最長 200 字
     * @api-request-example
     * {"floor": 5, "control_list": "[{\"DeviceId\":\"4917b746-9835-47f1-8909-b17462d118cb\"}]", "floor_list": "[{\"DeviceId\":\"4917b746-9835-47f1-8909-b17462d118cb\",\"Floors\":[5,9]}]", "remark": "5 樓訪客卡"}
     * @api-response-empty
     */
    public function updateFloorAccess(Request $request, int $id, AccessFloorService $access)
    {
        $data = $this->validateInput($request, [
            'id' => ['required', 'int'],
            'floor' => ['required', 'int', 'max:100'],
            'control_list' => ['required', 'string', 'json'],
            'floor_list' => ['required', 'string', 'json'],
            'remark' => ['required', 'string', 'max:200'],
        ]);

        $result = $access->editFloorAccess(
            $id,
            $data['floor'],
            $data['control_list'],
            $data['floor_list'],
            $data['remark'],
        );

        return $this->success();
    }

    /**
     * @api-method DELETE
     * @api-path /api/access/floor/{id}
     * @api-tag AccessFloor
     * @api-summary 刪除訪客樓層門禁權限設定
     * @api-portal admin
     * @api-description
     * 刪除單筆設定，刪除後該樓層可以重新建立設定。找不到回傳 HTTP 404。
     * @api-no-request-body
     * @api-response-empty
     */
    public function destroyFloorAccess(Request $request, int $id, AccessFloorService $access)
    {
        $access->delFloorAccess($id);

        return $this->success();
    }

    /** 列表只回摘要欄位；原始 JSON 欄位（access_control_list／floor_list）不外送，需要時走查看單筆 */
    private function presentListItem(AccessFloor $item): array
    {
        return [
            'id' => $item->id,
            'floor' => $item->floor,
            'remark' => $item->remark,
            'status' => $item->status,
            'floors_by_device' => $item->floors_by_device,
            'ele_group_count' => $item->ele_group_count,
            'access_control_count' => $item->access_control_count,
            'created_at' => $item->created_at?->toDateTimeString(),
            'updated_at' => $item->updated_at?->toDateTimeString(),
        ];
    }
}
