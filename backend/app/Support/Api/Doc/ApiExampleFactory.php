<?php

/* *****************************************
File: ApiExampleFactory.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 依欄位名稱、型別與說明產生「有意義」的範例值，供 swagger:generate 輸出給 Swagger UI 當預設值與回傳範例，
避免畫面上出現 "string"／0 這種看不出該填什麼的值。標註若已明確給範例（@api-request-example）一律以標註為準。
***************************************** */

namespace App\Support\Api\Doc;

class ApiExampleFactory
{
    /**
     * 欄位名稱（路徑最後一段，去掉 []）符合規則時的範例值，由上到下第一個符合者優先。
     * 規則是 regex，比對小寫欄位名。
     */
    private const BY_NAME = [
        '/^export$/' => 0,
        '/^dictionary$/' => 'guest_visit_reason',
        '/^reservation_status$/' => 40,
        '/job_title$/' => '業務經理',
        '/^created_at$/' => '2026-11-23 15:32:00',
        '/^check_in_at$/' => '2026-11-23 15:35:00',
        '/^check_out_at$/' => '2026-11-23 16:20:00',
        '/^date_from$/' => '2026-11-01',
        '/^date_to$/' => '2026-11-30',
        '/^parent_id$/' => 0,
        '/^expires_in$/' => 60,
        '/^status_code$/' => 200,
        '/^duration_ms$/' => 35,
        '/^response_size$/' => 1024,
        '/^size_bytes$/' => 1048576,
        '/^file_count$/' => 128,
        '/^items_count$/' => 5,
        '/^output_width$/' => 360,
        '/^output_height$/' => 200,
        '/^output_path$/' => 'D:\deploy\site_a_20261123',
        '/^cascaded_modules$/' => ['user'],
        '/^class$/' => 'App\Plugins\Ad\Microsoft\V2019\Plugin',
        '/^component$/' => 'pages/guest/RecordList.vue',
        '/^db_connection$/' => 'site_current',
        '/^cfg_name$/' => 'site_title',
        '/^cfg_title$/' => '站台名稱',
        '/^cfg_value$/' => 'WebBase',
        '/^cfg_default$/' => 'WebBase',
        '/^cfg_module$/' => 'site',
        '/^cfg_option$/' => '',
        '/^cfg_style$/' => '{"width":"300px"}',
        '/^config_module$/' => 'site',
        '/^footer_text$/' => '© 2026 新保科技 版權所有',
        '/^logo$/' => '/storage/logo.png',
        '/^brand$/' => 'microsoft',
        '/^version$/' => 'v2019',
        '/^function$/' => 'ad',
        '/^function_name$/' => 'AD 整合',
        '/^last_migration_batch$/' => '3',
        '/^location$/' => '地上',
        '/^method$/' => 'GET',
        '/^route$/' => '/api/factory/guest/applies',
        '/^path_prefix$/' => 'site-a',
        '/^site_code$/' => 'site_a',
        '/^site_status$/' => '1',
        '/^target$/' => '_self',
        '/^template_namespace$/' => 'site_a',
        '/^token_type$/' => 'Bearer',
        '/^provision_failed_reason$/' => null,
        '/^request_data$/' => '{"client":{"ip":"192.168.1.10"},"url":"/api/factory/guest/applies?fast_pass_code=12345678"}',
        '/^response_data$/' => '{"status":true,"error_code":0,"data":{}}',
        '/^response_headers$/' => '{"content-type":["application/json"]}',
        '/^summary$/' => '業務洽談',
        '/^bname$/' => '新保大樓',
        '/^issued_via$/' => 'login',
        '/^portal_keys$/' => ['admin'],
        '/^(password|current_password|new_password|password_confirmation)$/' => 'Admin@123456',
        '/^username$/' => 'staff01',
        '/^(host_name|guest_name|real_name|contact_name|owner_name)$/' => '王小明',
        '/company_name$/' => '宏昇工程股份有限公司',
        '/(^name$|_name$)/' => '範例名稱',
        '/(^email$|_email$)/' => 'wang@example.com',
        '/(phone|mobile|tel)$/' => '0912-345-678',
        '/plate_number/' => 'ABC-1234',
        '/(^ip$|_ip$)/' => '192.168.1.10',
        '/^allowed_ips$/' => ['192.168.1.0/24'],
        '/(^domain$|_domain$)/' => 'site-a.example.com',
        '/(^url$|_url$)/' => 'https://example.com/path',
        '/^(access_token|token|refresh_token)$/' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxIn0.sig',
        '/^(sign|signature)$/' => '3f1c9a7e5b2d48c6a0f1e3d4b5c6a7988d9e0f1a2b3c4d5e6f708192a3b4c5d6',
        '/^nonce$/' => 'a1b2c3d4e5f60718',
        '/^app_id$/' => 'app_k3x9m2p0q7w1z5ab',
        '/^app_secret$/' => 'sk_9f8e7d6c5b4a39281706f5e4d3c2b1a0',
        '/^portal_key$/' => 'admin',
        '/^(code|module_code)$/' => 'guest',
        '/^key$/' => 'guest.records',
        '/^reservation_id$/' => 'GA202611230001',
        '/^(qr_code)$/' => 'Q7xK2mP9wL4tR8nB3vC6yH1jD5sF0gA2eU9iZ4oX',
        '/^fast_pass_code$/' => '12345678',
        '/^(access_card_no|card_no)$/' => '2548932547',
        '/^id_card_slot_no$/' => 'A10',
        '/^(host_floor|floor)$/' => '12F',
        '/^(visit_reason|reason)$/' => '業務洽談',
        '/^(remark|note|notes|description)$/' => '備註說明',
        '/^(title|label|subtitle)$/' => '範例標題',
        '/^icon$/' => 'Menu',
        '/^path$/' => '/guest/records',
        '/^(permission_code)$/' => 'api.guest.records',
        '/^permission_codes$/' => ['api.guest.records'],
        '/^scopes$/' => ['guest.apply.read'],
        '/^action$/' => 'FactoryGuestApplyController@index',
        '/^(module|scope)$/' => 'guest',
        '/^(keyword|q|search)$/' => '王小明',
        '/^(value)$/' => '1',
        '/^(type)$/' => 'standard',
        '/^(mode)$/' => 'contain',
        '/^(format)$/' => 'png',
        '/^(background)$/' => 'transparent',
        '/^(color|theme_color)$/' => '#1677ff',
        '/^(page)$/' => 1,
        '/^(per_page)$/' => 10,
        '/^(total)$/' => 126,
        '/^(current_page)$/' => 1,
        '/^(sort)$/' => 0,
        '/^(guest_count)$/' => 2,
        '/^(remind_time)$/' => 60,
        '/^(timestamp|expires_at|expires_in)$/' => 1790000000,
        '/^(growth_rate)$/' => 12.5,
        '/^(count|previous_count)$/' => 18,
        '/^(width)$/' => 360,
        '/^(height)$/' => 200,
        '/^(x|y)$/' => 0,
        '/^(output)$/' => 'site_a_20261123',
        '/^(db_name)$/' => 'kiosk_site_1',
        '/(^|_)id$/' => 1,
        '/^(parent_id)$/' => 0,
    ];

    /** 範例值：$path 為欄位路徑（如 data.items[].host_name），$type 為標註的型別 */
    public function value(string $path, string $type, string $description, array $dicts): mixed
    {
        if (str_starts_with($type, 'dict:')) {
            $options = $dicts[substr($type, 5)] ?? [];

            return $options ? $this->castKey(array_key_first($options)) : null;
        }

        $name = strtolower(preg_replace('/\[\]$/', '', (string) preg_replace('/^.*\./', '', $path)));

        foreach (self::BY_NAME as $regex => $example) {
            if (preg_match($regex, $name)) {
                // 路徑以 [] 結尾（如 permission_codes[]）代表每個元素的值，取清單第一個
                if (is_array($example) && str_ends_with($path, '[]')) {
                    $example = $example[0] ?? '';
                }

                return $this->fit($example, $type);
            }
        }

        // 說明裡寫了格式時沒有名稱規則可用時，以說明裡寫的格式為準（YYYY-MM-DD、HH:mm 最常見）
        if (stripos($description, 'YYYY-MM-DD HH:mm') !== false) {
            return '2026-11-23 14:00';
        }
        if (stripos($description, 'YYYY-MM-DD') !== false) {
            return '2026-11-23';
        }
        if (stripos($description, 'HH:mm') !== false) {
            return '14:00';
        }

        return match ($type) {
            'int' => 1,
            'bool' => true,
            'float' => 1.5,
            'datetime' => '2026-11-23 10:30:00',
            'array' => [],
            'object' => new \stdClass,
            default => $this->stringFallback($name, $description),
        };
    }

    /** 依欄位路徑巢狀組出整份範例（陣列取一筆），$fields 為 [{path,type,description}] */
    public function tree(array $fields, array $dicts): array
    {
        $root = [];

        foreach ($fields as $f) {
            $this->put($root, explode('.', $f['path']), $f, $dicts);
        }

        return $root;
    }

    private function put(array &$node, array $segments, array $field, array $dicts): void
    {
        $segment = array_shift($segments);
        $isArray = str_ends_with($segment, '[]');
        $key = $isArray ? substr($segment, 0, -2) : $segment;

        if ($segments === []) {
            $leaf = $this->value($field['path'], $field['type'], $field['description'], $dicts);
            // 型別是 array／object 的父欄位，若稍早已經長出子結構就不要覆蓋
            if (! array_key_exists($key, $node) || $leaf !== [] && ! ($leaf instanceof \stdClass)) {
                $node[$key] = $isArray ? [$leaf] : $leaf;
            }

            return;
        }

        if ($isArray) {
            if (! isset($node[$key][0]) || ! is_array($node[$key][0])) {
                $node[$key] = [[]];
            }
            $this->put($node[$key][0], $segments, $field, $dicts);
        } else {
            if (! isset($node[$key]) || ! is_array($node[$key])) {
                $node[$key] = [];
            }
            $this->put($node[$key], $segments, $field, $dicts);
        }
    }

    /** 範例型別要和宣告型別一致：int 欄位不能給字串 */
    private function fit(mixed $example, string $type): mixed
    {
        if ($example === null) {
            return null;
        }

        return match (true) {
            $type === 'int' && ! is_int($example) => is_numeric($example) ? (int) $example : 1,
            $type === 'float' && ! is_numeric($example) => 1.5,
            $type === 'bool' => true,
            $type === 'datetime' => '2026-11-23 10:30:00',
            $type === 'array' && ! is_array($example) => [],
            $type === 'string' && ! is_string($example) => (string) (is_array($example) ? '' : $example),
            default => $example,
        };
    }

    private function castKey(int|string|null $key): int|string|null
    {
        return is_string($key) && ctype_digit($key) ? (string) $key : $key;
    }

    private function stringFallback(string $name, string $description): string
    {
        if (str_ends_with($name, '_at')) {
            return '2026-11-23 10:30:00';
        }
        if (str_ends_with($name, '_date') || $name === 'date') {
            return '2026-11-23';
        }
        if (str_ends_with($name, '_time')) {
            return '14:00';
        }

        // 最後手段：用說明文字的第一段當範例（至少是中文語意，不是 "string"）
        $text = trim((string) preg_split('/[，,（(。；;]/u', $description)[0]);

        return $text !== '' ? $text : '範例';
    }
}
