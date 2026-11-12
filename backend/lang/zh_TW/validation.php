<?php

/* *****************************************
File: validation.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: Laravel 內建驗證規則的繁體中文訊息，見 README 11.2。
***************************************** */

// Laravel 內建驗證規則的繁體中文訊息，見 README 11.2：$request->validate()
// 丟出的 ValidationException 會用這份語言檔產生欄位層級的錯誤訊息（見
// bootstrap/app.php 的 withExceptions()），取代原本「一律顯示模糊的
// 『驗證失敗』」——使用者才看得出來是哪個欄位、什麼原因不合法。這份檔案
// 只覆蓋 app/Api、app/Hq 目前實際用到的規則（見各 Controller 的
// validateInput() 呼叫），不是把 Laravel 全部規則都翻譯一輪；之後新增
// 規則時如果訊息顯示成英文原始鍵名（例如 "validation.xxx"），代表這裡
// 還沒有對應翻譯，補上即可，不用改動框架層的例外處理邏輯。
return [

    'accepted' => ':attribute 必須勾選或同意。',
    'active_url' => ':attribute 不是合法的網址。',
    'after' => ':attribute 必須是 :date 之後的日期。',
    'after_or_equal' => ':attribute 必須是 :date（含當天）之後的日期。',
    'alpha' => ':attribute 只能是英文字母。',
    'alpha_dash' => ':attribute 只能是英文字母、數字、破折號與底線。',
    'alpha_num' => ':attribute 只能是英文字母與數字。',
    'array' => ':attribute 必須是陣列。',
    'before' => ':attribute 必須是 :date 之前的日期。',
    'before_or_equal' => ':attribute 必須是 :date（含當天）之前的日期。',
    'between' => [
        'array' => ':attribute 項目數量必須介於 :min 到 :max 之間。',
        'file' => ':attribute 檔案大小必須介於 :min 到 :max KB 之間。',
        'numeric' => ':attribute 必須介於 :min 到 :max 之間。',
        'string' => ':attribute 長度必須介於 :min 到 :max 個字元之間。',
    ],
    'boolean' => ':attribute 只能是 true 或 false。',
    'confirmed' => ':attribute 兩次輸入不一致。',
    'current_password' => '密碼不正確。',
    'date' => ':attribute 不是合法的日期。',
    'date_equals' => ':attribute 必須是等於 :date 的日期。',
    'date_format' => ':attribute 格式必須符合 :format。',
    'different' => ':attribute 與 :other 必須不同。',
    'digits' => ':attribute 必須是 :digits 位數字。',
    'digits_between' => ':attribute 位數必須介於 :min 到 :max 之間。',
    'distinct' => ':attribute 有重複值。',
    'email' => ':attribute 不是合法的信箱格式。',
    'ends_with' => ':attribute 必須以下列其中之一結尾：:values。',
    'enum' => '選擇的 :attribute 不合法。',
    'exists' => '選擇的 :attribute 不存在。',
    'file' => ':attribute 必須是檔案。',
    'filled' => ':attribute 不能是空的。',
    'gt' => [
        'array' => ':attribute 項目數量必須大於 :value。',
        'file' => ':attribute 檔案大小必須大於 :value KB。',
        'numeric' => ':attribute 必須大於 :value。',
        'string' => ':attribute 長度必須大於 :value 個字元。',
    ],
    'gte' => [
        'array' => ':attribute 項目數量必須大於或等於 :value。',
        'file' => ':attribute 檔案大小必須大於或等於 :value KB。',
        'numeric' => ':attribute 必須大於或等於 :value。',
        'string' => ':attribute 長度必須大於或等於 :value 個字元。',
    ],
    'hex_color' => ':attribute 必須是合法的十六進位色碼。',
    'image' => ':attribute 必須是圖片。',
    'in' => '選擇的 :attribute 不合法。',
    'in_array' => ':attribute 必須存在於 :other 之中。',
    'integer' => ':attribute 必須是整數。',
    'ip' => ':attribute 必須是合法的 IP 位址。',
    'json' => ':attribute 必須是合法的 JSON 字串。',
    'lowercase' => ':attribute 必須是小寫。',
    'lt' => [
        'array' => ':attribute 項目數量必須小於 :value。',
        'file' => ':attribute 檔案大小必須小於 :value KB。',
        'numeric' => ':attribute 必須小於 :value。',
        'string' => ':attribute 長度必須小於 :value 個字元。',
    ],
    'lte' => [
        'array' => ':attribute 項目數量必須小於或等於 :value。',
        'file' => ':attribute 檔案大小必須小於或等於 :value KB。',
        'numeric' => ':attribute 必須小於或等於 :value。',
        'string' => ':attribute 長度必須小於或等於 :value 個字元。',
    ],
    'mac_address' => ':attribute 必須是合法的 MAC 位址。',
    'max' => [
        'array' => ':attribute 項目數量不能超過 :max 個。',
        'file' => ':attribute 檔案大小不能超過 :max KB。',
        'numeric' => ':attribute 不能大於 :max。',
        'string' => ':attribute 長度不能超過 :max 個字元。',
    ],
    'mimes' => ':attribute 檔案格式必須是：:values。',
    'mimetypes' => ':attribute 檔案格式必須是：:values。',
    'min' => [
        'array' => ':attribute 項目數量至少要有 :min 個。',
        'file' => ':attribute 檔案大小至少要有 :min KB。',
        'numeric' => ':attribute 不能小於 :min。',
        'string' => ':attribute 長度至少要有 :min 個字元。',
    ],
    'not_in' => '選擇的 :attribute 不合法。',
    'not_regex' => ':attribute 格式不正確。',
    'numeric' => ':attribute 必須是數字。',
    'present' => ':attribute 必須存在。',
    'prohibited' => ':attribute 不允許填寫。',
    'regex' => ':attribute 格式不正確。',
    'required' => ':attribute 為必填欄位。',
    'required_array_keys' => ':attribute 必須包含以下項目：:values。',
    'required_if' => '當 :other 為 :value 時，:attribute 為必填欄位。',
    'required_unless' => '當 :other 不是 :values 時，:attribute 為必填欄位。',
    'required_with' => '當 :values 存在時，:attribute 為必填欄位。',
    'required_with_all' => '當 :values 皆存在時，:attribute 為必填欄位。',
    'required_without' => '當 :values 不存在時，:attribute 為必填欄位。',
    'required_without_all' => '當 :values 皆不存在時，:attribute 為必填欄位。',
    'same' => ':attribute 與 :other 必須相同。',
    'size' => [
        'array' => ':attribute 必須包含 :size 個項目。',
        'file' => ':attribute 檔案大小必須是 :size KB。',
        'numeric' => ':attribute 必須是 :size。',
        'string' => ':attribute 長度必須是 :size 個字元。',
    ],
    'starts_with' => ':attribute 必須以下列其中之一開頭：:values。',
    'string' => ':attribute 必須是字串。',
    'timezone' => ':attribute 必須是合法的時區。',
    'unique' => ':attribute 已經被使用。',
    'uploaded' => ':attribute 上傳失敗。',
    'uppercase' => ':attribute 必須是大寫。',
    'url' => ':attribute 不是合法的網址。',
    'uuid' => ':attribute 必須是合法的 UUID。',

    // 見檔頭說明：只需要覆蓋這個專案目前實際會用到的欄位名稱（見各
    // Controller 的 validateInput() 呼叫），沒有列在這裡的欄位會直接
    // 顯示程式裡的英文鍵名（例如 "foo_bar"），仍然看得懂是哪個欄位、
    // 只是沒有中文化，不影響「使用者看得出來是哪裡的問題」這個目標。
    'attributes' => [
        'username' => '帳號',
        'password' => '密碼',
        'current_password' => '目前密碼',
        'portal_key' => '端口代碼',
        'portal_keys' => '端口代碼',
        'portal_id' => '端口',
        'name' => '名稱',
        'email' => '信箱',
        'code' => '代碼',
        'description' => '說明',
        'is_enabled' => '啟用狀態',
        'sort' => '排序',
        'value' => '值',
        'label' => '標籤',
        'key' => '識別碼',
        'title' => '標題',
        'parent_id' => '父節點',
        'module_code' => '模塊代碼',
        'permission_code' => '額外權限碼',
        'permission_codes' => '權限碼',
        'permission_codes.*' => '權限碼',
        'subtitle' => '副標題',
        'icon' => '圖示',
        'path' => '前端路徑',
        'target' => '開啟方式',
        'items' => '項目清單',
        'items.*.id' => '項目 ID',
        'items.*.parent_id' => '項目父節點',
        'items.*.sort' => '項目排序',
        'items.*.value' => '項目的值',
        'image' => '圖片',
        'x' => '裁切區域 X 座標',
        'y' => '裁切區域 Y 座標',
        'width' => '寬度',
        'height' => '高度',
        'identity_type_id' => '身份別',
        'role_id' => '角色',
        'is_portal_admin' => '端口管理員',
        'domain' => '網域',
        'type' => '類型',
    ],

];
