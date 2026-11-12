<?php

/* *****************************************
File: SsoProvider.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 8.6：不同案場的 SSO 對接方式不同（協定、欄位、驗證方式都可能不一樣）， 框架不假設固定用哪一種，只約定「輸入是這次的請求 + 該站的 sso_* 設定，輸出是 一份身份資料或 null（驗證失敗）」，新案場要接不同方式時實作這個介面即可， 不需要改動呼叫端（SsoLoginService）任何程式碼。
***************************************** */

namespace App\Support\Sso\Contracts;

use App\Support\Sso\SsoIdentity;
use Illuminate\Http\Request;

// 見 README 8.6：不同案場的 SSO 對接方式不同（協定、欄位、驗證方式都可能不一樣），
// 框架不假設固定用哪一種，只約定「輸入是這次的請求 + 該站的 sso_* 設定，輸出是
// 一份身份資料或 null（驗證失敗）」，新案場要接不同方式時實作這個介面即可，
// 不需要改動呼叫端（SsoLoginService）任何程式碼。
interface SsoProvider
{
    /**
     * @param  array<string,mixed>  $config  該站 system_configs（cfg_module=sso）目前的設定值
     */
    public function resolveIdentity(Request $request, array $config): ?SsoIdentity;
}
