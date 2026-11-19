<?php

// 見 README 開發約定第 11 點：API 代碼中不允許直接寫死文字，一律透過語言包。
// 涵蓋範圍：app/Api、app/Support/Api（含 ErrorCatalog 的預設訊息）、
// app/Support/Http/Middleware（子站／API 共用的驗證中介層）、app/Web/Sso
// 與 app/Web/Impersonation（SSO／跳轉登入流程雖然在 Web 命名空間下，但本質上
// 是登入類 API 行為）。
// app/Hq 是完全獨立的程式碼／資料庫（見 6.5），文字另外放在 hq.php。

return [

    // 見 ErrorCatalog：錯誤碼對應的預設訊息，Api／Hq 共用同一組錯誤碼。
    'error_catalog' => [
        'validation_failed' => '驗證失敗',
        'credentials_missing' => '缺少憑證',
        'permission_denied' => '沒有權限',
        'app_disabled' => 'APP 已停用',
        'signature_invalid' => '簽章錯誤',
        'token_replaced_by_new_login' => '此帳號已於其他裝置登入',
        'module_not_enabled' => '模塊未開通',
        'portal_admin_only' => '此端口僅開放端口管理員登入',
        'system_error' => '系統異常',
        'unknown_error' => '未知錯誤',
    ],

    'auth' => [
        'invalid_credentials' => '帳號、密碼或端口錯誤',
        'token_invalid' => 'token 無效或已過期',
        'session_replaced' => '登入已失效，請重新登入',
        'exchange_code_invalid' => '交換碼無效或已過期',
        'exchange_code_required' => '缺少交換碼',
        'exchange_site_mismatch' => '交換碼與目前站點不符',
        'no_super_admin' => '此站尚未有超級管理員帳號',
        'portal_not_found' => '端口不存在',
        'current_password_incorrect' => '目前密碼不正確',
    ],

    // 見 DEVELOP.MD 6.11：主站跳轉登入子站。子站端的 Blade 落地頁會用到
    // （失敗訊息沿用上方 api.auth.*，與 JSON API 同一套錯誤碼對應）。
    'impersonation' => [
        'landing_title' => '正在登入子站後台',
        'landing_hint' => '已取得子站身份，正在為您進入後台。',
        'landing_link' => '若沒有自動跳轉，請點此進入後台',
        'landing_note' => '提醒：目前是以此站的超級管理員身份操作，請留意操作影響範圍。',
        'failed_title' => '跳轉登入失敗',
        'failed' => '跳轉登入失敗，請回到主站重新核發交換碼。',
    ],

    'module' => [
        'not_registered' => '模塊未登記：:code',
    ],

    'role' => [
        'still_assigned' => '此角色仍有使用者指派中，請先改派或移除該使用者的端口權限，才能刪除角色。',
        'name_exists' => '角色名稱已存在：:name',
        'permission_code_not_found' => '權限碼在目錄中不存在，可能是打字錯誤：:code',
        'permission_code_wrong_portal' => '權限碼 :code 標註的適用端口不含此角色所屬的端口，無法指派（可能是跨端口選錯了權限碼）。',
        'exceeds_delegation_ceiling' => '您在此端口持有的權限不足以授權這些權限碼給角色，只能授予自己目前實際持有的權限子集（見 README 7.1 授權上限）。',
    ],

    'user' => [
        'username_exists' => '帳號已存在。',
        'identity_type_invalid' => '指定的身份別不存在，或您無權指派此身份別。',
        'system_no_portal_access' => '超級管理員不透過端口存取表授權，不需要（也不能）指派端口。',
        'role_invalid' => '指定的角色不存在，或不屬於目前端口。',
        'exceeds_delegation_ceiling' => '您在此端口持有的權限不足以做這項授權，只能授予自己目前實際持有的權限子集（見 README 7.1 授權上限）。',
    ],

    'menu' => [
        'has_children' => '此選單仍有子選單，請先刪除或改掛子選單，才能刪除此節點。',
        'module_not_found' => '模塊未登記於 modules 主表：:code',
        'permission_code_not_found' => '權限碼在目錄中不存在，可能是打字錯誤：:code',
        'parent_cannot_be_self' => '父節點不能是自己。',
        'parent_invalid' => '指定的父節點不存在，或不屬於目前端口。',
        'key_exists' => '選單識別碼在此端口已存在：:key',
        'max_depth_exceeded' => '選單最多只能有兩層，不能把節點掛在已經是子節點的項目底下。',
        'reorder_invalid_items' => '排序清單包含不存在或不屬於此端口的選單節點，請重新整理後再試。',
    ],

    'sso' => [
        'default_identity_type_missing' => 'sso_default_identity_type 設定的身份別不存在：:code',
        'driver_not_registered' => 'SSO 對接方式未註冊：:key（見 config/sso.php drivers）',
        'driver_not_implementing_interface' => ':class 未實作 :interface',
    ],

    'plugin' => [
        'not_configured' => '此站尚未設定插件：:function/:slot',
        'not_registered_in_config' => '找不到指定的插件，或尚未登記於 config/plugins.php：:key',
        'entry_not_found' => '找不到指定的插件項目：:function/:key',
    ],

    'portal' => [
        'not_found' => '找不到指定的端口',
        'key_exists' => '端口代碼已存在：:key',
    ],

    'system_config' => [
        'not_found' => '找不到指定的系統設定項',
        'value_not_in_options' => ':name 的值不在允許的選項範圍內',
        'value_must_be_int' => ':name 必須是整數',
        'value_must_be_float' => ':name 必須是數字',
        'value_must_be_bool' => ':name 只能是 0 或 1',
        'value_must_be_json' => ':name 必須是合法的 JSON 字串',
        'value_must_be_json_array' => ':name 必須是 JSON 陣列字串，例如 ["a","b"]',
        'value_must_be_email' => ':name 必須是合法的信箱格式',
        'value_must_be_url' => ':name 必須是合法的網址格式',
        'value_must_be_color' => ':name 必須是 #RRGGBB 色碼格式',
        'options_source_empty' => ':name 的選項來源（字典或 cfg_option）不存在或為空，請檢查設定項結構',
        'value_too_long' => ':name 超過最大長度限制（:max）',
        'dictionary_not_found' => ':name 對應的字典不存在或已停用：:code',
        'value_not_in_dictionary' => ':name 的值不在字典 :code 允許的選項範圍內',
        'type_invalid' => ':name 的類型 :type 尚未支援，請檢查設定項結構',
        'not_image_type' => ':name 不是圖片類型的設定項',
        'image_mime_not_allowed' => '不支援的圖片格式，僅接受 JPEG／PNG／GIF／WEBP',
        'image_too_large' => '圖片檔案超過大小限制（:max KB）',
        'image_no_original' => '尚未上傳過原圖，無法編輯裁切',
    ],

    'dictionary' => [
        'not_found' => '找不到指定的字典',
        'code_exists' => '字典代碼已存在：:code',
        'in_use' => '此字典仍有系統設定項引用中，請先修改該設定項的類型，才能刪除字典。',
        'item_not_found' => '找不到指定的字典項目',
        'item_value_exists' => '此字典底下該值已存在：:value',
    ],

    'data_setting' => [
        'doorplate_exists' => '門牌號碼已存在'
    ],

    'guest_apply' => [
        'not_found' => '找不到此訪客預約單。',
        'invalid_time_range' => '預約時段的結束時間必須晚於開始時間。',
        'status_not_editable' => '此預約單目前狀態不可修改或取消。',
        'already_checked_in' => '此預約單已報到，不可修改或取消。',
        'visitor_count_mismatch' => '來訪者名單共 :count 位，超過來訪人數 :max 人。',
        'no_generate_failed' => '預約單號產生失敗，請稍後再試。',
        'invalid_check_type' => '請選擇使用qrcode或電話查詢'
    ],

    'guest_slot' => [
        'not_found' => '找不到此證件櫃',
        'in_use' => '此證件櫃正在使用中',
    ],

];
