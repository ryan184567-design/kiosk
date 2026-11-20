<?php

/* *****************************************
File: FactoryAuthController.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: App 認證：以 app_id + HMAC 簽名換取 access token，見 docs/APP_DEVELOP.MD 2、3 節
***************************************** */

namespace App\Api\Factory\Controllers;

use App\Api\Factory\Models\FactoryApp;
use App\Api\Factory\Models\FactoryLog;
use App\Support\Api\ApiResponder;
use App\Support\Api\ErrorCode;
use App\Support\Factory\FactoryCallLogger;
use App\Support\Factory\FactoryJwtService;
use App\Support\Factory\FactorySignatureService;
use App\Support\Http\Controllers\BaseApiController;
use Illuminate\Http\Request;

// 機器接口的標註慣例（見 DEVELOP.MD 12.9）：
//   @api-auth public／app 標明這是機器接口，permission:sync-api 會因此略過（不登記為後台用戶權限）；
//   @api-auth app 必須再加 @api-scope，內容須與路由上的 factory.scope:xxx 一致。
class FactoryAuthController extends BaseApiController
{
    /**
     * @api-method POST
     * @api-path /api/factory/token
     * @api-tag Factory
     * @api-summary App（機器）換取 access token
     * @api-portal *
     * @api-auth public
     * @api-description
     * 機器（如訪客機）用 app_id 與 HMAC-SHA256 簽名換取 access token（等同「用戶登入」，身份是 App）。
     * - 簽名原文：`app_id|timestamp|nonce`；密鑰：app_secret；輸出小寫 hex。**app_secret 不可經網路傳送**。
     * - `timestamp` 與伺服器時間相差需在 300 秒內；`nonce` 在時間窗內不可重複使用。
     * - 任何校驗失敗（App 不存在／停用／過期、IP 不在白名單、簽名錯誤、nonce 重複）一律回 401 同一訊息，
     *   原因只記在後台日誌；僅時間窗失敗時額外回 `server_time`（Unix 秒）供機器校準時鐘後重試一次。
     * - 此接口有限流（每 IP、每 app_id 分別限制）。
     * @api-request-field app_id string 對外 app_id，後台建立 App 時取得
     * @api-request-field timestamp int Unix 秒級時間戳
     * @api-request-field nonce string 客戶端產生的隨機英數字串，16～64 字元
     * @api-request-field sign string 小寫 hex 的 HMAC-SHA256 簽名，64 字元
     * @api-request-example {"app_id":"app_k3x9m2p0q7w1z5ab","timestamp":1790000000,"nonce":"a1b2c3d4e5f60718","sign":"3f1c...（64 字元 hex）"}
     * @api-response-field data.access_token string JWT access token，後續請求放 `Authorization: Bearer`
     * @api-response-field data.token_type string 固定為 Bearer
     * @api-response-field data.expires_in int 有效秒數，快到期或收到 401 時重新換取即可（無 refresh token）
     */
    public function token(Request $request, FactorySignatureService $signature, FactoryJwtService $jwt, FactoryCallLogger $logger)
    {
        $data = $this->validateInput($request, [
            'app_id' => 'required|string|max:40',
            'timestamp' => 'required|integer',
            'nonce' => 'required|alpha_num|min:16|max:64',
            'sign' => 'required|string|size:64',
        ]);

        [$app, $reason] = $signature->verify(
            $data['app_id'], (int) $data['timestamp'], $data['nonce'], $data['sign'], $request->ip()
        );

        // 模塊與具體方法一併記錄，後台才能依這兩項搜尋
        $route = FactoryLog::routeInfo($request);

        if (! $app) {
            FactoryLog::record('token', false, $data['app_id'], $request->ip(), null, $reason, $route);

            $response = ApiResponder::fail(ErrorCode::SIGNATURE_INVALID, __('api.factory.auth_failed'));

            if ($reason === 'timestamp_out_of_window') {
                $response->setData(array_merge($response->getData(true), ['server_time' => time()]));
            }

            // 呼叫記錄（失敗必記；App 開了除錯模式則成功也記）。app_id 不存在就認不出是哪個 App，不記，避免匿名灌爆日誌
            $logger->record($request, $response, FactoryApp::query()->where('app_id', $data['app_id'])->first());

            return $response;
        }

        $app->forceFill(['last_used_at' => now()])->save();
        FactoryLog::record('token', true, $app->app_id, $request->ip(), null, null, $route);

        $response = $this->success($jwt->issue($app->app_id));
        $logger->record($request, $response, $app);

        return $response;
    }
}
