<?php

/* *****************************************
File: ApiResponder.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 統一 API 回傳格式，見 README 11。
***************************************** */

namespace App\Support\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

// 統一 API 回傳格式，見 README 11。
class ApiResponder
{
    public static function success(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json([
            'status' => true,
            'error_code' => 0,
            'data' => $data,
        ], $status);
    }

    public static function fail(int $errorCode, ?string $message = null, ?int $status = null): JsonResponse
    {
        return response()->json([
            'status' => false,
            'error_code' => $errorCode,
            'error_message' => $message ?? ErrorCatalog::message($errorCode),
            'trace_id' => (string) Str::uuid(),
        ], $status ?? ErrorCatalog::status($errorCode));
    }
}
