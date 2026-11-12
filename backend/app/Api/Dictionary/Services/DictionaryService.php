<?php

/* *****************************************
File: DictionaryService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 資料字典管理業務邏輯，見 README 17.3。
***************************************** */

namespace App\Api\Dictionary\Services;

use App\Api\Dictionary\Models\SystemDictionary;
use App\Api\Dictionary\Models\SystemDictionaryItem;
use App\Api\SystemConfig\Models\SystemConfig;
use App\Api\User\Models\User;
use App\Support\Audit\Models\AuditLog;
use Illuminate\Contracts\Database\Eloquent\Builder;
use RuntimeException;

// 資料字典管理業務邏輯，見 README 17.3。跟 Role/User/Menu 一樣屬於「純資料，
// 後台可以完全自主管理」的情境（對比 SystemConfig 只能改值、不能新增/刪除
// 設定項，見 17.2），字典本身的類型與選項都是純資料，工程師不需要另外寫
// 程式碼配合。
class DictionaryService
{
    public function queryDictionaries(): Builder
    {
        return SystemDictionary::query()->withCount('items')->orderBy('sort')->orderBy('id');
    }

    public function createDictionary(array $data): SystemDictionary
    {
        if (SystemDictionary::query()->where('code', $data['code'])->exists()) {
            throw new RuntimeException(__('api.dictionary.code_exists', ['code' => $data['code']]));
        }

        // sort/is_enabled 未帶入時交給資料庫欄位預設值，create() 後的記憶體
        // 物件不會自動反映資料庫算出的預設值，refresh() 一次拉回真正存進去
        // 的值，避免回傳給呼叫端的資料跟資料庫實際狀態不一致（見
        // MenuAdminService::create() 同一個既有寫法）。
        $dictionary = SystemDictionary::query()->create($data);
        $dictionary->refresh();

        return $dictionary;
    }

    public function updateDictionary(SystemDictionary $dictionary, array $data): SystemDictionary
    {
        if (isset($data['code']) && $data['code'] !== $dictionary->code
            && SystemDictionary::query()->where('code', $data['code'])->exists()) {
            throw new RuntimeException(__('api.dictionary.code_exists', ['code' => $data['code']]));
        }

        $dictionary->update($data);

        return $dictionary;
    }

    // 見 17.2：system_configs.cfg_type=dic 的 cfg_option 存的就是字典代碼，
    // 刪除前要先確認沒有任何設定項還在引用這個字典，否則會讓那些設定項的
    // dic 驗證悄悄失效（找不到字典、卻沒有任何提示）。
    public function deleteDictionary(SystemDictionary $dictionary): void
    {
        $inUse = SystemConfig::query()
            ->where('cfg_type', 'dic')
            ->where('cfg_option', $dictionary->code)
            ->exists();

        if ($inUse) {
            throw new RuntimeException(__('api.dictionary.in_use'));
        }

        $dictionary->delete();
    }

    public function createItem(SystemDictionary $dictionary, array $data): SystemDictionaryItem
    {
        if ($dictionary->items()->where('value', $data['value'])->exists()) {
            throw new RuntimeException(__('api.dictionary.item_value_exists', ['value' => $data['value']]));
        }

        // 見 createDictionary() 同一段說明：refresh() 拉回資料庫算出的預設值。
        $item = $dictionary->items()->create($data);
        $item->refresh();

        return $item;
    }

    public function updateItem(SystemDictionaryItem $item, array $data): SystemDictionaryItem
    {
        if (isset($data['value']) && $data['value'] !== $item->value
            && $item->dictionary->items()->where('value', $data['value'])->exists()) {
            throw new RuntimeException(__('api.dictionary.item_value_exists', ['value' => $data['value']]));
        }

        $item->update($data);

        return $item;
    }

    public function deleteItem(SystemDictionaryItem $item): void
    {
        $item->delete();
    }

    // 見 SystemConfigService::assertValidValue()：cfg_type=dic 時查這裡，
    // 只回傳啟用中的字典底下啟用中的項目值，停用的字典/項目不算合法選項。
    public function resolveEnabledValues(string $code): array
    {
        $dictionary = SystemDictionary::query()->where('code', $code)->where('is_enabled', true)->first();

        if (! $dictionary) {
            return [];
        }

        return $dictionary->items()->where('is_enabled', true)->pluck('value')->all();
    }

    // 見 SystemConfigService::resolveOptions()：GET /api/system-configs 要在
    // 每筆 cfg_type=dic/dic_multi 的設定項上自動帶出下拉選項（17.2），一次
    // 查完整頁設定用到的所有字典代碼（去重、單一查詢），回傳
    // [code => [['value' => ..., 'label' => ...], ...]]；查不到的代碼（不存
    // 在或已停用）回空陣列，由呼叫端決定怎麼呈現。
    public function resolveEnabledItemsByCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $dictionaries = SystemDictionary::query()
            ->where('is_enabled', true)
            ->whereIn('code', $codes)
            ->with(['items' => fn ($query) => $query
                ->where('is_enabled', true)
                ->orderBy('sort')
                ->orderBy('id')])
            ->get();

        $result = array_fill_keys($codes, []);

        foreach ($dictionaries as $dictionary) {
            $result[$dictionary->code] = $dictionary->items
                ->map(fn (SystemDictionaryItem $item) => ['value' => $item->value, 'label' => $item->label])
                ->all();
        }

        return $result;
    }

    // 見 README 15 節第 3、5 點：字典結構變更屬於需審計的操作，寫入該子站
    // 自己的 audit_logs，寫法比照 Role/Menu 既有的審計呼叫。
    public function audit(User $actor, string $action, string $targetKey, ?string $ip = null): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'target' => $targetKey,
            'context' => [],
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }
}
