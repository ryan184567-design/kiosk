<?php

/* *****************************************
File: SystemConfigService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 系統環境配置管理業務邏輯，見 README 17。
***************************************** */

namespace App\Api\SystemConfig\Services;

use App\Api\Dictionary\Services\DictionaryService;
use App\Api\SystemConfig\Models\SystemConfig;
use App\Api\User\Models\User;
use App\Support\Audit\Models\AuditLog;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// 系統環境配置管理業務邏輯，見 README 17。設定「有哪些設定項、型別、選項」
// 屬於開發階段透過 seeder（見 SiteBaselineSeeder::seedSiteBasicConfig）或
// 插件啟用當下（見 App\Api\Plugin\Services\PluginAdminService::ensureConfigSeeded）
// 定義的結構，管理員只能改 cfg_value，不開放新增/刪除設定項——新增一筆
// 設定項代表程式碼要多讀一個 cfg_name，本來就得由工程師配合開發，不是後台
// 純資料操作能獨立完成的事，跟 Role/User/Menu 那種「純資料、後台可以完全
// 自主管理」的情境不同。
class SystemConfigService
{
    public function __construct(private readonly DictionaryService $dictionaries) {}

    public function queryByModule(?string $module): Builder
    {
        return SystemConfig::query()
            ->when($module, fn ($q) => $q->where('cfg_module', $module))
            ->orderBy('cfg_module')
            ->orderBy('sort');
    }

    // 給實際要「用」設定值的消費端呼叫（例如 PluginManager 組裝插件
    // connector 的建構參數），回傳 cfg_name => 解密後的實際值；跟後台
    // 列表用的 present()／maskedValue() 是相反方向（那邊是「給人看，密碼
    // 不回顯」，這裡是「給程式用，密碼要解密」），不要搞混兩者的用途。
    public function valuesForModule(string $module): array
    {
        return SystemConfig::query()
            ->where('cfg_module', $module)
            ->get()
            ->mapWithKeys(fn (SystemConfig $config) => [$config->cfg_name => $this->decryptPassword($config)])
            ->all();
    }

    public function updateValue(User $actor, SystemConfig $config, string $value, ?string $ip = null): SystemConfig
    {
        // password 類型的空字串代表「不更改」，不是「清空」——密碼／金鑰
        // 輸入框基於安全考量從不回顯明文（見 present()），前端留白送出時
        // 沒辦法區分「使用者故意要清空」跟「本來就沒填、原樣送出」，統一
        // 解讀成「不更改」；SystemConfigEditor.vue 也會把留白的密碼欄位
        // 直接排除在送出的批次之外，這裡是雙重保險。
        if ($config->cfg_type === 'password' && $value === '') {
            return $config;
        }

        // 見 17.1：cfg_type 決定 cfg_value 的驗證方式。normalizeValue 會先驗證、
        // 必要時把值正規化（例如 multi/dic_multi 存回 JSON 陣列字串）。
        $value = $this->normalizeValue($config, $value);

        // password 類型加密存放（見 README 17.4）：先前是明文存 text 欄位，
        // 只在後台呈現時遮罩，資料庫本身沒有保護；用 APP_KEY 對稱加密後
        // 才寫入，讀取端一律呼叫 decryptPassword() 解回明文使用。
        $storedValue = $config->cfg_type === 'password' ? Crypt::encryptString($value) : $value;

        $config->update(['cfg_value' => $storedValue]);

        // 見 README 15 節第 3、6 點：系統設定變更屬於需審計的操作。mail/payment
        // 這類模組的 cfg_value 可能含密碼/金鑰，context 只記 cfg_name 不記
        // 實際值，避免審計日誌本身變成另一個外洩管道（同節第 2 點）。
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => 'system_config.update',
            'target' => "system_config:{$config->id}",
            'context' => ['cfg_module' => $config->cfg_module, 'cfg_name' => $config->cfg_name],
            'ip' => $ip,
            'created_at' => now(),
        ]);

        return $config;
    }

    // password 類型一律不把值（含加密後的密文）吐回前端，統一回空字串，
    // 前端靠 hasValue() 顯示「已設定」提示；任何回傳 cfg_value 給呼叫端的
    // 地方（present()／update／batchUpdate 的回應）都要走這裡，不要各自
    // 直接讀 $config->cfg_value。
    public function maskedValue(SystemConfig $config): string
    {
        return $config->cfg_type === 'password' ? '' : (string) $config->cfg_value;
    }

    public function hasValue(SystemConfig $config): bool
    {
        return ($config->cfg_value ?? '') !== '';
    }

    // 給實際要用到密碼／金鑰明文的消費端呼叫（例如插件連接器讀取自己的
    // bind password，見 README 6.10）；解密失敗（舊資料格式、或本來就是
    // 空字串）一律回空字串，不讓例外往外炸，由呼叫端自行判斷空值代表
    // 未設定。
    public function decryptPassword(SystemConfig $config): string
    {
        if ($config->cfg_type !== 'password' || ($config->cfg_value ?? '') === '') {
            return (string) ($config->cfg_value ?? '');
        }

        try {
            return Crypt::decryptString($config->cfg_value);
        } catch (DecryptException) {
            return '';
        }
    }

    // 見後台改版說明：整頁一次送出多筆更新，取代舊版每一列各自獨立 PUT。
    // 包在同一個 DB 交易內——任何一筆驗證失敗（型別不符、必選類型送空值
    // ...等，規則與 updateValue 單筆更新完全相同）就整批回滾，不會出現
    // 「有些欄位存了、有些沒存」這種頁面顯示與資料庫不一致的情況。
    public function batchUpdate(User $actor, array $items, ?string $ip = null): array
    {
        return DB::connection('site_current')->transaction(function () use ($actor, $items, $ip) {
            $results = [];

            foreach ($items as $item) {
                $config = SystemConfig::query()->find($item['id']);

                if (! $config) {
                    throw new RuntimeException(__('api.system_config.not_found')." (id={$item['id']})");
                }

                $updated = $this->updateValue($actor, $config, $item['value'] ?? '', $ip);
                $results[] = ['id' => $updated->id, 'cfg_value' => $this->maskedValue($updated), 'has_value' => $this->hasValue($updated)];
            }

            return $results;
        });
    }

    // 見 17.1：cfg_type 決定 cfg_value 的驗證方式。normalizeValue 會先驗證、
    // 必要時把值正規化（multi/dic_multi 一律存 JSON 陣列字串），回傳正規化後
    // 的值。date/datetime/time 的 cfg_option 只是顯示格式字串，不是驗證規則，
    // 故不在此擋格式。
    private function normalizeValue(SystemConfig $config, string $value): string
    {
        switch ($config->cfg_type) {
            case 'enum':
                $options = is_array($config->cfg_option) ? $config->cfg_option : [];

                if (! in_array($value, $options, true)) {
                    throw new RuntimeException(__('api.system_config.value_not_in_options', ['name' => $config->cfg_name]));
                }

                break;

            case 'multi':
                return $this->normalizeMulti($config, $value, $this->enumOptions($config));

            case 'dic':
                $this->assertValueInDictionary($config, $value);

                break;

            case 'dic_multi':
                return $this->normalizeMulti(
                    $config,
                    $value,
                    $this->dictionaries->resolveEnabledValues((string) $config->cfg_option),
                    'value_not_in_dictionary',
                );

            case 'bool':
                // bool 與既有 enum ['0','1'] 的值格式完全相同（'0'/'1' 字串），
                // 讀值端（如 SsoSiteConfig::load 的 $value === '1'）不受影響。
                if (! in_array($value, ['0', '1'], true)) {
                    throw new RuntimeException(__('api.system_config.value_must_be_bool', ['name' => $config->cfg_name]));
                }

                break;

            case 'int':
                if ($value !== '' && preg_match('/^-?\d+$/', $value) !== 1) {
                    throw new RuntimeException(__('api.system_config.value_must_be_int', ['name' => $config->cfg_name]));
                }

                break;

            case 'float':
                if ($value !== '' && ! is_numeric($value)) {
                    throw new RuntimeException(__('api.system_config.value_must_be_float', ['name' => $config->cfg_name]));
                }

                break;

            case 'string':
            case 'textarea':
                $maxLength = is_numeric($config->cfg_option) ? (int) $config->cfg_option : null;

                if ($maxLength !== null && mb_strlen($value) > $maxLength) {
                    throw new RuntimeException(__('api.system_config.value_too_long', ['name' => $config->cfg_name, 'max' => $maxLength]));
                }

                break;

            case 'json':
                if ($value !== '' && json_decode($value) === null && json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException(__('api.system_config.value_must_be_json', ['name' => $config->cfg_name]));
                }

                break;

            case 'email':
                if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new RuntimeException(__('api.system_config.value_must_be_email', ['name' => $config->cfg_name]));
                }

                break;

            case 'url':
                if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                    throw new RuntimeException(__('api.system_config.value_must_be_url', ['name' => $config->cfg_name]));
                }

                break;

            case 'color':
                if ($value !== '' && preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
                    throw new RuntimeException(__('api.system_config.value_must_be_color', ['name' => $config->cfg_name]));
                }

                break;

                // date/datetime/time：cfg_option 只是顯示格式字串，不驗證內容
                // 格式（與擴充前 date/datetime 的既有行為一致）。

            default:
                // cfg_type 已放寬為 varchar(20)（見 migration），未知類型在此
                // 統一擋下，避免新類型還沒實作驗證就被悄悄存進去。
                if (! in_array($config->cfg_type, SystemConfig::TYPES, true)) {
                    throw new RuntimeException(__('api.system_config.type_invalid', ['name' => $config->cfg_name, 'type' => (string) $config->cfg_type]));
                }
        }

        return $value;
    }

    private function enumOptions(SystemConfig $config): array
    {
        return is_array($config->cfg_option) ? $config->cfg_option : [];
    }

    // multi/dic_multi 的 cfg_value 存 JSON 陣列字串（例如 ["a","b"]），空值
    // 正規化成 []。每個成員都必須在允許的選項（enum 的 cfg_option／字典啟用
    // 中項目）內，不合法直接拒絕。
    private function normalizeMulti(SystemConfig $config, string $value, array $allowed, string $notInMessageKey = 'value_not_in_options'): string
    {
        if ($value === '' || $value === '[]') {
            return '[]';
        }

        $items = json_decode($value, true);

        if (! is_array($items) || array_values($items) !== $items || array_filter($items, fn ($item) => ! is_string($item)) !== []) {
            throw new RuntimeException(__('api.system_config.value_must_be_json_array', ['name' => $config->cfg_name]));
        }

        if ($allowed === []) {
            // 允許清單本身是空的（字典不存在／停用，或 cfg_option 沒設），
            // 視為設定結構有問題，比照 dic 的既有行為直接拒絕。
            throw new RuntimeException(__('api.system_config.options_source_empty', ['name' => $config->cfg_name]));
        }

        foreach ($items as $item) {
            if (! in_array($item, $allowed, true)) {
                // code 佔位符只有字典類型（cfg_option 為字串）才會用到；
                // multi 的 cfg_option 是陣列，不能直接 (string) 轉型（會噴
                // Array to string conversion），不是字串就傳空字串。
                $code = is_string($config->cfg_option) ? $config->cfg_option : '';

                throw new RuntimeException(__('api.system_config.'.$notInMessageKey, ['name' => $config->cfg_name, 'code' => $code]));
            }
        }

        return json_encode(array_values($items), JSON_UNESCAPED_UNICODE);
    }

    // 見 17.3：cfg_option 存的是字典代碼（App\Api\Dictionary），去查那個字典
    // 底下目前啟用中的項目值有哪些，cfg_value 必須是其中之一；字典代碼本身
    // 不存在或已停用，視為設定結構本身有問題，直接拒絕。
    private function assertValueInDictionary(SystemConfig $config, string $value): void
    {
        $dictionaryCode = (string) $config->cfg_option;
        $validValues = $this->dictionaries->resolveEnabledValues($dictionaryCode);

        if ($validValues === []) {
            throw new RuntimeException(__('api.system_config.dictionary_not_found', ['name' => $config->cfg_name, 'code' => $dictionaryCode]));
        }

        if (! in_array($value, $validValues, true)) {
            throw new RuntimeException(__('api.system_config.value_not_in_dictionary', ['name' => $config->cfg_name, 'code' => $dictionaryCode]));
        }
    }

    // 見 17.2：GET /api/system-configs 逐筆回傳 options（下拉選項），讓前端
    // 不用再另外查字典 API——enum/multi 由 cfg_option 展開、dic/dic_multi 由
    // 字典啟用中項目自動帶出（17.3），其餘類型回空陣列。回傳格式為
    // [config_id => [['value' => ..., 'label' => ...], ...]]。
    public function resolveOptions(iterable $configs): array
    {
        $configs = collect($configs);

        $dictionaryCodes = $configs
            ->whereIn('cfg_type', SystemConfig::OPTION_SOURCE_DICTIONARY)
            ->map(fn (SystemConfig $config) => (string) $config->cfg_option)
            ->filter(fn (string $code) => $code !== '')
            ->unique()
            ->values()
            ->all();

        $dictionaryItems = $this->dictionaries->resolveEnabledItemsByCodes($dictionaryCodes);

        return $configs->mapWithKeys(function (SystemConfig $config) use ($dictionaryItems): array {
            if (in_array($config->cfg_type, SystemConfig::OPTION_SOURCE_ENUM, true)) {
                $options = collect($this->enumOptions($config))
                    ->map(fn ($option) => ['value' => (string) $option, 'label' => (string) $option])
                    ->all();
            } elseif (in_array($config->cfg_type, SystemConfig::OPTION_SOURCE_DICTIONARY, true)) {
                $options = $dictionaryItems[(string) $config->cfg_option] ?? [];
            } else {
                $options = [];
            }

            return [$config->id => $options];
        })->all();
    }
}
