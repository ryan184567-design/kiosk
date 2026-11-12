<?php

/* *****************************************
File: JwtExchangeSsoProvider.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 8.6：最常見的一種對接方式——瀏覽器帶一次性 code 導頁過來， 拿 code 換外部系統核發的身份資料（JWT 或一般 JSON）。
***************************************** */

namespace App\Support\Sso\Drivers;

use App\Support\Auth\JwtService;
use App\Support\Sso\Contracts\SsoProvider;
use App\Support\Sso\SsoIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// 見 README 8.6：最常見的一種對接方式——瀏覽器帶一次性 code 導頁過來，
// 拿 code 換外部系統核發的身份資料（JWT 或一般 JSON）。這是「預設可用的
// 一種對接方式」，不是唯一解法；案場若是完全不同的協定（SAML、純 Cookie
// 轉發…），另外寫一個實作 SsoProvider 的類別、在 config/sso.php 註冊即可，
// 不需要改這支或呼叫端程式碼。
class JwtExchangeSsoProvider implements SsoProvider
{
    public function __construct(private readonly JwtService $jwt) {}

    // 見 README 17.4：插件管理頁面用這份 schema 把設定項自動種進
    // system_configs（cfg_module 固定沿用既有的 'sso'，見
    // PluginRegistry::moduleCodeFor），複用系統環境配置的型別／驗證／
    // UI（SystemConfigEditor.vue），不用為 SSO 另外刻一份表單。shape 跟
    // App\Plugins\Ad\Contracts\AdConnectorInterface 的 configSchema() 一致
    // （鍵是 cfg_name，值至少有 type/required/description，option/default
    // 視類型需要才給），只是這支不強制走 6.10 標準插件骨架（見該檔案
    // 開頭類別註解的第 2 點）。
    public static function configSchema(): array
    {
        return [
            'sso_enabled' => ['type' => 'bool', 'required' => false, 'description' => '啟用 SSO 登入', 'default' => '0'],
            'sso_driver' => ['type' => 'string', 'required' => true, 'description' => '對接方式（見 config/sso.php drivers）', 'default' => 'jwt_exchange'],
            'sso_endpoint' => ['type' => 'url', 'required' => true, 'description' => '外部 SSO 端點網址'],
            'sso_client_id' => ['type' => 'string', 'required' => false, 'description' => 'Client ID', 'option' => 255],
            'sso_client_secret' => ['type' => 'password', 'required' => false, 'description' => 'Client Secret'],
            'sso_response_format' => ['type' => 'enum', 'required' => false, 'description' => '回應格式（json/jwt）', 'option' => ['json', 'jwt'], 'default' => 'json'],
            'sso_verify_key' => ['type' => 'password', 'required' => false, 'description' => 'JWT 驗簽金鑰（response_format=jwt 時）'],
            'sso_field_mapping' => ['type' => 'json', 'required' => false, 'description' => '欄位對應（JSON）', 'default' => '{"external_id":"sub","username":"username","name":"name"}'],
            'sso_portal_key' => ['type' => 'string', 'required' => false, 'description' => '登入後進入的端口', 'option' => 50, 'default' => 'admin'],
            'sso_auto_create_user' => ['type' => 'bool', 'required' => false, 'description' => '找不到帳號時自動建立', 'default' => '0'],
            'sso_default_identity_type' => ['type' => 'string', 'required' => false, 'description' => '自動建立帳號時的身份別代碼', 'option' => 50, 'default' => 'staff'],
            'sso_success_redirect' => ['type' => 'string', 'required' => false, 'description' => '登入成功後導向路徑', 'option' => 255, 'default' => '/admin/dashboard'],
        ];
    }

    public function resolveIdentity(Request $request, array $config): ?SsoIdentity
    {
        $code = $request->query('code');

        if (! $code || empty($config['sso_endpoint'])) {
            return null;
        }

        $response = Http::timeout((int) config('sso.request_timeout'))
            ->asJson()
            ->post($config['sso_endpoint'], [
                'code' => $code,
                'client_id' => $config['sso_client_id'] ?? null,
                'client_secret' => $config['sso_client_secret'] ?? null,
            ]);

        if (! $response->successful()) {
            Log::warning('SSO 端點呼叫失敗', ['endpoint' => $config['sso_endpoint'], 'status' => $response->status()]);

            return null;
        }

        $payload = $this->extractPayload($response->json() ?? [], $config);

        if ($payload === null) {
            return null;
        }

        return $this->mapToIdentity($payload, $config);
    }

    // response_format=jwt 時，回應本身是一個 JWT（例如 {"token":"..."}），需要
    // 用該站設定的 sso_verify_key 驗證簽章，不可以直接信任未驗證的內容；
    // =json（預設）則直接把回應當成已經是明碼身份資料（案場自行對傳輸層安全
    // 負責，例如僅限內網呼叫 + HTTPS）。
    private function extractPayload(array $body, array $config): ?array
    {
        if (($config['sso_response_format'] ?? 'json') !== 'jwt') {
            return $body;
        }

        $token = $body['token'] ?? null;

        if (! $token || empty($config['sso_verify_key'])) {
            Log::warning('SSO response_format=jwt 但缺少 token 或 sso_verify_key，無法驗證');

            return null;
        }

        $claims = $this->jwt->decode($token, (string) $config['sso_verify_key']);

        return $claims ? (array) $claims : null;
    }

    // sso_field_mapping：{"external_id":"sub","username":"username","name":"name"}
    // 值是外部 payload 裡的欄位名稱，支援點記法取巢狀欄位。
    private function mapToIdentity(array $payload, array $config): SsoIdentity
    {
        $mapping = $config['sso_field_mapping'] ?? [];

        return new SsoIdentity(
            externalId: $this->pick($payload, $mapping['external_id'] ?? null),
            username: $this->pick($payload, $mapping['username'] ?? null),
            name: $this->pick($payload, $mapping['name'] ?? null),
            raw: $payload,
        );
    }

    private function pick(array $payload, ?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $value = data_get($payload, $path);

        return $value === null ? null : (string) $value;
    }
}
