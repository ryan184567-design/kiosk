<?php

/* *****************************************
File: sso.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: SSO 對接方式註冊表，見 README 8.6。
***************************************** */

// SSO 對接方式註冊表，見 README 8.6。這裡只登記「框架認得哪些對接方式（驅動）」
// 這種程式碼層級的清單；每個子站實際要用哪一種、要打哪個網址、憑證是什麼，
// 屬於案場資料，不寫在這裡，存在各站自己的 system_configs（cfg_module=sso，
// 見 17 節），後台可編輯、不需要改程式碼重新部署。

return [

    // driver key => 實作 App\Support\Sso\Contracts\SsoProvider 的類別。
    // 不同案場對接方式不同時，新增一個實作該介面的類別、在這裡註冊一個 key，
    // 子站端只要把 system_configs 的 sso_driver 設定值改成這個 key 即可切換，
    // 不需要改動呼叫端程式碼。
    'drivers' => [
        'jwt_exchange' => \App\Support\Sso\Drivers\JwtExchangeSsoProvider::class,
    ],

    // 呼叫外部 SSO 端點的逾時秒數，避免案場端點異常時卡住請求。
    'request_timeout' => (int) env('SSO_REQUEST_TIMEOUT', 5),

];
