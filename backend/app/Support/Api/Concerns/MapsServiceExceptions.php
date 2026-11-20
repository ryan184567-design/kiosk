<?php

/* *****************************************
File: MapsServiceExceptions.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: 把 Service 拋出的業務 RuntimeException 轉成統一錯誤回應，但不吞掉資料庫等系統錯誤
***************************************** */

namespace App\Support\Api\Concerns;

use App\Support\Api\ErrorCode;
use PDOException;
use RuntimeException;

// 注意：Illuminate\Database\QueryException 繼承自 PDOException，而 PDOException 繼承自 RuntimeException，
// 單純 `catch (RuntimeException)` 會把「資料庫連線／SQL 錯誤」也當成業務錯誤回成 404／422，
// 掩蓋真正的系統問題。業務例外請用這裡轉換：資料庫類例外原樣拋出，交給全域處理（500）。
trait MapsServiceExceptions
{
    /** Service 以 `new RuntimeException($msg, 404)` 代表找不到；其餘業務例外視為驗證失敗。 */
    protected function failFromService(RuntimeException $e)
    {
        if ($e instanceof PDOException) {
            throw $e;
        }

        return $this->fail(
            $e->getCode() === 404 ? ErrorCode::RESOURCE_NOT_FOUND : ErrorCode::VALIDATION_FAILED,
            $e->getMessage()
        );
    }
}
