<?php

// 見 README 開發約定第 11 點。app/Hq 程式碼／資料庫與子站完全獨立
// （見 6.5），文字另外用這份語言包，不與 api.php 混用。

return [

    'auth' => [
        'invalid_credentials' => '帳號或密碼錯誤',
        'token_invalid' => 'token 無效或已過期',
        'session_replaced' => '登入已失效，請重新登入',
    ],

    'impersonation' => [
        'site_not_ready' => '站點不存在或尚未就緒',
    ],

    'site' => [
        'not_found' => '找不到站點',
    ],

    'module' => [
        'not_registered' => '模塊未登記：:code',
        'core_always_enabled' => '核心模塊恆為開通，不能停用，見 6.9 第 1 點',
        'disable_blocked_by_dependents' => '無法停用，以下已開通的模塊依賴它：:codes（請先停用這些模塊）',
    ],

    'provisioning' => [
        'invalid_code_format' => 'code 只能是小寫英數底線。',
        'code_exists' => '站點代碼已存在：:code',
        'invalid_type' => 'type 只能是 standard 或 customized。',
        'site_not_ready_for_domain' => '此子站尚未建置完成，無法設定網域。',
        'invalid_domain_format' => '網域格式不正確，請輸入不含 http:// 或路徑的網域名稱。',
        'domain_taken' => '此網域已被其他子站使用：:domain',
    ],

    // 單站部署包輸出（CLI site:package 與主站後台 API 共用，見 6.8）
    'packaging' => [
        'site_not_ready' => '此子站尚未建置完成（provision_status 需為 ready），無法輸出部署包。',
        'output_exists' => '輸出目錄已存在，避免覆蓋既有部署包：:path',
        'invalid_output' => '輸出資料夾只能是英數、底線、減號、點與斜線組成的相對路徑。',
        'env_hint' => '部署包已附可直接使用的 .env（APP_KEY／JWT secret 自動產生），現場只需依實際狀況調整 DB_HOST／DB_PORT／DB_USERNAME／DB_PASSWORD／APP_URL，並先執行 composer install（部署包不含 vendor，見 6.8 第 3 點）。',
        'deploy_guide_hint' => '部署包根目錄另有 DEPLOY.md，列出完整現場部署步驟（composer install、目錄權限、migrate、Tier 3 的 vue:build、快取指令），見 6.8 第 8 點。',
        'no_merge_hint' => '打包只做篩選、不做程式碼合併：共用程式碼不可有寫死判斷其他 site_code 的例外邏輯（見 6.8 第 7 點）。',
        'standard_site_hint' => '此站是公版子站，部署包不含任何客製化目錄；若客戶日後要追加客製化，直接在 app/Sites/{StudlyCode} 加檔案即可。',
        'shared_vue_hint' => '此站前台 Vue 是共用建置（Tier 1/2）：現場沿用共用產物即可，客製頁面靠 custom_routes 動態掛載（見 6.8 第 4 點）。',
        'dedicated_vue_hint' => '此站前台 Vue 是獨立建置（Tier 3）：現場需跑 php artisan vue:build --site=:code --clean --output=public/vue（見 6.8 第 4 點）。',
    ],

];
