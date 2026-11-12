<?php

/* *****************************************
File: DictionaryController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 資料字典管理，見 README 17.3。
***************************************** */

namespace App\Api\Dictionary\Controllers;

use App\Api\Dictionary\Models\SystemDictionary;
use App\Api\Dictionary\Models\SystemDictionaryItem;
use App\Api\Dictionary\Services\DictionaryService;
use App\Support\Api\Concerns\PaginationHelper;
use App\Support\Api\ErrorCode;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;
use RuntimeException;

// 資料字典管理，見 README 17.3。不分端口（字典是整站層級，不像 Role/User/
// Menu 綁定 portal_id），權限一律用路由層 permission.api:{code} 檢查目前
// 登入端口是否持有對應權限碼，寫法比照 SystemConfigController 開頭註解。
class DictionaryController extends BaseApiController
{
    use PaginationHelper;

    /**
     * @api-method GET
     * @api-path /api/dictionaries
     * @api-tag Dictionary
     * @api-summary 列出資料字典（類型）
     * @api-description
     * 回傳每個字典類型本身（代碼、名稱、是否啟用），不含底下的項目——項目
     * 清單另外呼叫 `GET /api/dictionaries/{id}/items`。`items_count` 是
     * 該字典底下目前有幾筆項目（不分啟用/停用），供後台列表快速顯示，
     * 不需要另外呼叫項目清單才知道有沒有資料。
     * 分頁參數 `per_page=-1`（預設）代表不分頁，回傳全部。
     * @api-portal *
     * @api-response-field data.items[].id int 字典ID
     * @api-response-field data.items[].code string 字典代碼，供 system_configs.cfg_option（cfg_type=dic）查詢
     * @api-response-field data.items[].name string 顯示名稱
     * @api-response-field data.items[].description string 說明
     * @api-response-field data.items[].is_enabled bool 是否啟用
     * @api-response-field data.items[].sort int 排序
     * @api-response-field data.items[].items_count int 底下的項目筆數
     * @api-response-field data.total int 總筆數
     * @api-response-field data.per_page int 每頁筆數，-1 代表不分頁
     * @api-response-field data.current_page int 目前頁碼
     */
    public function index(Request $request, DictionaryService $dictionaries)
    {
        $result = $this->paginateOrGet(
            $dictionaries->queryDictionaries(),
            (int) $request->integer('per_page', -1),
            (int) $request->integer('page', 1),
        );

        $result['items'] = collect($result['items'])->map(fn (SystemDictionary $d) => $this->presentDictionary($d))->all();

        return $this->success($result);
    }

    /**
     * @api-method POST
     * @api-path /api/dictionaries
     * @api-tag Dictionary
     * @api-summary 建立資料字典（類型）
     * @api-description
     * `code` 是給 `system_configs.cfg_type=dic` 這類需要引用字典的地方用的
     * 識別碼，同站不可重複；建立好類型後，實際的可選值要另外呼叫
     * `POST /api/dictionaries/{id}/items` 逐筆新增。
     * @api-portal *
     * @api-request-field code string 字典代碼（同站不可重複）
     * @api-request-field name string 顯示名稱
     * @api-request-field description string 說明，可省略
     * @api-request-field is_enabled bool 是否啟用，省略預設 true
     * @api-request-field sort int 排序，省略預設 0
     * @api-request-example
     * {"code": "user_status", "name": "使用者狀態"}
     * @api-response-field data.id int 字典ID
     * @api-response-field data.code string 字典代碼
     */
    public function store(Request $request, DictionaryService $dictionaries)
    {
        $data = $this->validateInput($request, [
            'code' => 'required|string|max:100',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_enabled' => 'boolean',
            'sort' => 'integer',
        ]);

        try {
            $dictionary = $dictionaries->createDictionary($data);
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        $dictionaries->audit($this->currentUser($request), 'dictionary.create', "dictionary:{$dictionary->id}", $request->ip());

        return $this->success($this->presentDictionary($dictionary), 201);
    }

    /**
     * @api-method PUT
     * @api-path /api/dictionaries/{dictionary}
     * @api-tag Dictionary
     * @api-summary 更新資料字典（類型）
     * @api-portal *
     * @api-request-field code string 字典代碼（同站不可重複）
     * @api-request-field name string 顯示名稱
     * @api-request-field description string 說明
     * @api-request-field is_enabled bool 是否啟用
     * @api-request-field sort int 排序
     * @api-request-example
     * {"code": "user_status", "name": "使用者狀態", "is_enabled": true, "sort": 0}
     * @api-response-field data.id int 字典ID
     * @api-response-field data.code string 字典代碼
     */
    public function update(Request $request, int $dictionary, DictionaryService $dictionaries)
    {
        $model = SystemDictionary::query()->find($dictionary);

        if (! $model) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.dictionary.not_found'), 404);
        }

        $data = $this->validateInput($request, [
            'code' => 'required|string|max:100',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'is_enabled' => 'boolean',
            'sort' => 'integer',
        ]);

        try {
            $model = $dictionaries->updateDictionary($model, $data);
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        $dictionaries->audit($this->currentUser($request), 'dictionary.update', "dictionary:{$model->id}", $request->ip());

        return $this->success($this->presentDictionary($model));
    }

    /**
     * @api-method DELETE
     * @api-path /api/dictionaries/{dictionary}
     * @api-tag Dictionary
     * @api-summary 刪除資料字典（類型）
     * @api-description
     * 仍有 `system_configs` 設定項的 `cfg_type=dic` 引用這個字典代碼時會
     * 拒絕刪除，避免那些設定項的驗證悄悄失效；底下的項目會隨字典一併刪除
     * （資料庫層 cascade，見 migration）。
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function destroy(Request $request, int $dictionary, DictionaryService $dictionaries)
    {
        $model = SystemDictionary::query()->find($dictionary);

        if (! $model) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.dictionary.not_found'), 404);
        }

        try {
            $dictionaries->deleteDictionary($model);
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        $dictionaries->audit($this->currentUser($request), 'dictionary.delete', "dictionary:{$dictionary}", $request->ip());

        return $this->success();
    }

    /**
     * @api-method GET
     * @api-path /api/dictionaries/{dictionary}/items
     * @api-tag Dictionary
     * @api-summary 列出資料字典的項目
     * @api-portal *
     * @api-response-field data[].id int 項目ID
     * @api-response-field data[].value string 實際被引用的值
     * @api-response-field data[].label string 顯示文字
     * @api-response-field data[].is_enabled bool 是否啟用
     * @api-response-field data[].sort int 排序
     */
    public function items(Request $request, int $dictionary)
    {
        $model = SystemDictionary::query()->find($dictionary);

        if (! $model) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.dictionary.not_found'), 404);
        }

        $items = $model->items()->orderBy('sort')->orderBy('id')->get()
            ->map(fn (SystemDictionaryItem $item) => $this->presentItem($item))
            ->all();

        return $this->success($items);
    }

    /**
     * @api-method POST
     * @api-path /api/dictionaries/{dictionary}/items
     * @api-tag Dictionary
     * @api-summary 新增資料字典的項目
     * @api-portal *
     * @api-request-field value string 實際被引用的值（同一字典底下不可重複）
     * @api-request-field label string 顯示文字
     * @api-request-field is_enabled bool 是否啟用，省略預設 true
     * @api-request-field sort int 排序，省略預設 0
     * @api-request-example
     * {"value": "active", "label": "啟用中"}
     * @api-response-field data.id int 項目ID
     * @api-response-field data.value string 實際被引用的值
     */
    public function storeItem(Request $request, int $dictionary, DictionaryService $dictionaries)
    {
        $model = SystemDictionary::query()->find($dictionary);

        if (! $model) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.dictionary.not_found'), 404);
        }

        $data = $this->validateInput($request, [
            'value' => 'required|string|max:255',
            'label' => 'required|string|max:255',
            'is_enabled' => 'boolean',
            'sort' => 'integer',
        ]);

        try {
            $item = $dictionaries->createItem($model, $data);
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        $dictionaries->audit($this->currentUser($request), 'dictionary.item.create', "dictionary_item:{$item->id}", $request->ip());

        return $this->success($this->presentItem($item), 201);
    }

    /**
     * @api-method PUT
     * @api-path /api/dictionaries/{dictionary}/items/{item}
     * @api-tag Dictionary
     * @api-summary 更新資料字典的項目
     * @api-portal *
     * @api-request-field value string 實際被引用的值（同一字典底下不可重複）
     * @api-request-field label string 顯示文字
     * @api-request-field is_enabled bool 是否啟用
     * @api-request-field sort int 排序
     * @api-request-example
     * {"value": "active", "label": "啟用中", "is_enabled": true, "sort": 0}
     * @api-response-field data.id int 項目ID
     * @api-response-field data.value string 實際被引用的值
     */
    public function updateItem(Request $request, int $dictionary, int $item, DictionaryService $dictionaries)
    {
        $model = SystemDictionaryItem::query()->where('dictionary_id', $dictionary)->find($item);

        if (! $model) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.dictionary.item_not_found'), 404);
        }

        $data = $this->validateInput($request, [
            'value' => 'required|string|max:255',
            'label' => 'required|string|max:255',
            'is_enabled' => 'boolean',
            'sort' => 'integer',
        ]);

        try {
            $model = $dictionaries->updateItem($model, $data);
        } catch (RuntimeException $e) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, $e->getMessage());
        }

        $dictionaries->audit($this->currentUser($request), 'dictionary.item.update', "dictionary_item:{$model->id}", $request->ip());

        return $this->success($this->presentItem($model));
    }

    /**
     * @api-method DELETE
     * @api-path /api/dictionaries/{dictionary}/items/{item}
     * @api-tag Dictionary
     * @api-summary 刪除資料字典的項目
     * @api-portal *
     * @api-no-request-body
     * @api-response-empty
     */
    public function destroyItem(Request $request, int $dictionary, int $item, DictionaryService $dictionaries)
    {
        $model = SystemDictionaryItem::query()->where('dictionary_id', $dictionary)->find($item);

        if (! $model) {
            return $this->fail(ErrorCode::VALIDATION_FAILED, __('api.dictionary.item_not_found'), 404);
        }

        $dictionaries->deleteItem($model);
        $dictionaries->audit($this->currentUser($request), 'dictionary.item.delete', "dictionary_item:{$item}", $request->ip());

        return $this->success();
    }

    private function presentDictionary(SystemDictionary $dictionary): array
    {
        return [
            'id' => $dictionary->id,
            'code' => $dictionary->code,
            'name' => $dictionary->name,
            'description' => $dictionary->description,
            'is_enabled' => $dictionary->is_enabled,
            'sort' => $dictionary->sort,
            'items_count' => $dictionary->items_count ?? $dictionary->items()->count(),
        ];
    }

    private function presentItem(SystemDictionaryItem $item): array
    {
        return [
            'id' => $item->id,
            'value' => $item->value,
            'label' => $item->label,
            'is_enabled' => $item->is_enabled,
            'sort' => $item->sort,
        ];
    }
}
