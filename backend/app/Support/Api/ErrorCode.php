<?php

/* *****************************************
File: ErrorCode.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 11.2：錯誤碼集中管理，禁止在 Controller/Middleware 直接寫 magic number。
***************************************** */

namespace App\Support\Api;

// 見 README 11.2：錯誤碼集中管理，禁止在 Controller/Middleware 直接寫 magic number。
class ErrorCode
{
    // 1xxxx 參數/驗證
    public const VALIDATION_FAILED = 10001;

    // 2xxxx 認證/授權
    public const CREDENTIALS_MISSING = 20001;
    public const PERMISSION_DENIED = 20002;
    public const APP_DISABLED = 20011;
    public const SIGNATURE_INVALID = 20012;
    public const TOKEN_REPLACED_BY_NEW_LOGIN = 20015;
    public const MODULE_NOT_ENABLED = 20016;
    public const PORTAL_ADMIN_ONLY = 20017;
    // 帳號密碼正確，但這個帳號不屬於要登入的端口；回應 data.portal_keys 列出它可以登入的端口，
    // 讓前端自動切換到正確的登入入口（例如公司帳號在後台登入頁登入 → 自動跳到公司端口）。
    public const PORTAL_MISMATCH = 20018;

    // 4xxxx 資源
    public const RESOURCE_NOT_FOUND = 40004;

    // 5xxxx 系統異常
    public const SYSTEM_ERROR = 50000;
}
