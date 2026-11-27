<?php

namespace App\Api\Access\Controllers;

use App\Support\Http\Controllers\BaseApiController;
use App\Support\Api\Concerns\PaginationHelper;
use Illuminate\Http\Request;

use App\Api\Access\Services\AccessApiService;

use App\Api\Access\Models\AccessApi;

class AccessApiController extends BaseApiController
{
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/access/api
     * @api-tag AccessApi
     * @api-summary API列表
     * @api-description
     * - `per_page` 傳 -1 代表不分頁，一次回傳全部符合條件的資料；省略時預設 10
     * @api-portal admin
     * @api-dict access_api_status 0:未使用，1:使用中
     * @api-query-field page int optional 頁碼（預設 1）
     * @api-query-field per_page int optional 每頁筆數，-1 代表全部，未傳則使用配置值
     * @api-response-field data.items[].id int API ID
     * @api-response-field data.items[].name string API 名稱
     * @api-response-field data.items[].url string API網址
     * @api-response-field data.items[].status int 使用狀態
     * @api-response-field data.total int 符合條件的總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function indexApi(Request $request, AccessApiService $access)
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

        $result['items'] = collect($result['items'])->map(fn (AccessApi $a) => $this->present($a))->all();

        return $this->success($result);
    }

    /**
     * @api-method POST
     * @api-path /api/access/api
     * @api-tag AccessApi
     * @api-summary 新增API
     * @api-portal admin
     * @api-description
     * 新增API網址
     * 新增後狀態固定為 1（使用中）
     * @api-dict access_api_status 0:未使用，1:使用中
     * @api-request-field name string API 名稱
     * @api-request-field url string API網址
     * @api-request-example {"name": "BuildingPlus", "url":"http://192.168.4.2:83/Building/API/"}
     * @api-response-field data.id int API ID
     * @api-response-field data.name string API 名稱
     * @api-response-field data.url string API網址
     * @api-response-field data.status int 使用狀態
     */
    public function storeApi(Request $request, AccessApiService $access)
    {
        $data = $this->validateInput($request, [
            'name' => ['required', 'string', 'max:200'],
            'url' => ['required', 'string']
        ]);

        $result = $access->add(
            $data['name'],
            $data['url']
        );

        return $this->success($this->present($result));
    }

    /**
     * @api-method PUT
     * @api-path /api/access/api/{id}
     * @api-tag AccessApi
     * @api-summary 修改API
     * @api-portal admin
     * @api-description
     * 修改API設定
     * @api-dict access_api_status 0:未使用，1:使用中
     * @api-request-field name string API 名稱
     * @api-request-field url string API網址
     * @api-request-field status int 使用狀態
     * @api-request-example {"name": "BuildingPlus", "url":"http://192.168.4.2:83/Building/API/", "status": 1}
     * @api-response-field data.id int API ID
     * @api-response-field data.name string API 名稱
     * @api-response-field data.url string API網址
     * @api-response-field data.status int 使用狀態
     */
    public function updateApi(Request $request, string $id, AccessApiService $access)
    {
        $data = $this->validateInput($request, [
            'name' => ['required', 'string', 'max:200'],
            'url' => ['required', 'string'],
            'status' => ['required', 'int', 'in:0,1']
        ]);

        $result = $access->update(
            $id,
            $data['name'],
            $data['url'],
            $data['status']
        );

        return $this->success($this->present($result));
    }

    /**
     * @api-method DELETE
     * @api-path /api/access/api/{id}
     * @api-tag AccessApi
     * @api-summary 刪除Api
     * @api-portal admin
     * @api-description 刪除Api
     * @api-no-request-body
     * @api-response-empty
     */
    public function destroyApi(string $id, AccessApiService $access)
    {
        $access->delete($id);
        //
        return $this->success();
    }

    /** 列表只需要摘要欄位 */
    private function present(AccessApi $access): array
    {
        return [
            'id' => $access->id,
            'name' => $access->name,
            'url' => $access->url,
            'status' => $access->status,
        ];
    }
}
