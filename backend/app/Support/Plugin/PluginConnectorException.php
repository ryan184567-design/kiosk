<?php

/* *****************************************
File: PluginConnectorException.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 統一插件例外，見 README 6.10.3。
***************************************** */

namespace App\Support\Plugin;

use RuntimeException;

// 統一插件例外，見 README 6.10.3。各品牌 Connector 攔截廠商 SDK 例外後
// 轉換成這個類型，呼叫端只需要處理這一組例外，不需要認識各廠商 SDK。
class PluginConnectorException extends RuntimeException
{
    public const CONNECTION_FAILED = 'CONNECTION_FAILED';
    public const AUTH_REJECTED = 'AUTH_REJECTED';
    public const INVALID_RESPONSE = 'INVALID_RESPONSE';

    public function __construct(
        public readonly string $type,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
