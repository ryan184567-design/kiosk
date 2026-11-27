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

    // $data：少數錯誤需要附帶讓前端處理的資訊（例如 PORTAL_MISMATCH 附上可登入的端口），
    // 有值才輸出 data 欄位，其餘錯誤回應格式維持不變。
    public static function fail(int $errorCode, ?string $message = null, ?int $status = null, array $data = []): JsonResponse
    {
        $body = [
            'status' => false,
            'error_code' => $errorCode,
            'error_message' => $message ?? ErrorCatalog::message($errorCode),
            'trace_id' => (string) Str::uuid(),
        ];

        if ($data !== []) {
            $body['data'] = $data;
        }

        return response()->json($body, $status ?? ErrorCatalog::status($errorCode));
    }
}
