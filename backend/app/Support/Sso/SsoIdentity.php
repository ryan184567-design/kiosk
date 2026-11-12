<?php

/* *****************************************
File: SsoIdentity.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 外部 SSO 系統回傳、映射後的身份資料，見 README 8.6。
***************************************** */

namespace App\Support\Sso;

// 外部 SSO 系統回傳、映射後的身份資料，見 README 8.6。external_id/username/name
// 是套用 sso_field_mapping 後取出的值，raw 保留原始 payload 供驅動或日後除錯查看
// （不落地存進資料庫，只在單次請求內傳遞，見 SsoLoginService）。
final class SsoIdentity
{
    public function __construct(
        public readonly ?string $externalId,
        public readonly ?string $username,
        public readonly ?string $name,
        public readonly array $raw = [],
    ) {}
}
