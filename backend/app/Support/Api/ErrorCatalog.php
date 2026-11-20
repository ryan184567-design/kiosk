<?php

/* *****************************************
File: ErrorCatalog.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 錯誤碼對應的預設訊息與 HTTP status，見 README 11.2 第 4 點。
***************************************** */

namespace App\Support\Api;

// 錯誤碼對應的預設訊息與 HTTP status，見 README 11.2 第 4 點。訊息文字見
// lang/zh_TW/api.php（開發約定第 11 點：API 代碼不允許寫死文字），這裡
// 常數只存語言包 key（__() 是函式呼叫，不能直接放進 const 陣列裡）。
class ErrorCatalog
{
    private const STATUS = [
        ErrorCode::VALIDATION_FAILED => 422,
        ErrorCode::CREDENTIALS_MISSING => 401,
        ErrorCode::PERMISSION_DENIED => 403,
        ErrorCode::APP_DISABLED => 403,
        ErrorCode::SIGNATURE_INVALID => 401,
        ErrorCode::TOKEN_REPLACED_BY_NEW_LOGIN => 401,
        ErrorCode::MODULE_NOT_ENABLED => 403,
        ErrorCode::PORTAL_ADMIN_ONLY => 403,
        ErrorCode::RESOURCE_NOT_FOUND => 404,
        ErrorCode::SYSTEM_ERROR => 500,
    ];

    private const MESSAGE_KEYS = [
        ErrorCode::VALIDATION_FAILED => 'api.error_catalog.validation_failed',
        ErrorCode::CREDENTIALS_MISSING => 'api.error_catalog.credentials_missing',
        ErrorCode::PERMISSION_DENIED => 'api.error_catalog.permission_denied',
        ErrorCode::APP_DISABLED => 'api.error_catalog.app_disabled',
        ErrorCode::SIGNATURE_INVALID => 'api.error_catalog.signature_invalid',
        ErrorCode::TOKEN_REPLACED_BY_NEW_LOGIN => 'api.error_catalog.token_replaced_by_new_login',
        ErrorCode::MODULE_NOT_ENABLED => 'api.error_catalog.module_not_enabled',
        ErrorCode::PORTAL_ADMIN_ONLY => 'api.error_catalog.portal_admin_only',
        ErrorCode::RESOURCE_NOT_FOUND => 'api.error_catalog.resource_not_found',
        ErrorCode::SYSTEM_ERROR => 'api.error_catalog.system_error',
    ];

    public static function message(int $code): string
    {
        return isset(self::MESSAGE_KEYS[$code]) ? __(self::MESSAGE_KEYS[$code]) : __('api.error_catalog.unknown_error');
    }

    public static function status(int $code): int
    {
        return self::STATUS[$code] ?? 500;
    }
}
