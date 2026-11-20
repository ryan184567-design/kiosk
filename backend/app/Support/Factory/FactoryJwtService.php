<?php

/* *****************************************
File: FactoryJwtService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-19
Description: App access token 簽發與驗證（固定 HS256，密鑰獨立），見 docs/APP_DEVELOP.MD 10 節
***************************************** */

namespace App\Support\Factory;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FactoryJwtService
{
    private const ALGO = 'HS256';

    public function issue(string $appId): array
    {
        $now = time();
        $ttl = (int) config('factory.jwt_ttl');

        $token = JWT::encode([
            'iss' => config('factory.jwt_issuer'),
            'sub' => $appId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => (string) Str::uuid(),
        ], $this->secret(), self::ALGO);

        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $ttl];
    }

    /**
     * 回傳 app_id（sub）；無效一律回 null（含密鑰未設定）。演算法固定，不信任 token header。
     * 密鑰未設定時回 null 而不是拋例外：用戶 jwt.auth 也會呼叫這裡判斷「是不是 app token」，
     * 不能因為沒啟用廠商模式就讓一般用戶請求 500。
     */
    public function verify(string $token): ?string
    {
        try {
            $claims = JWT::decode($token, new Key($this->secret(), self::ALGO));
        } catch (Throwable) {
            return null;
        }

        if (($claims->iss ?? null) !== config('factory.jwt_issuer') || empty($claims->sub)) {
            return null;
        }

        return (string) $claims->sub;
    }

    private function secret(): string
    {
        $secret = (string) config('factory.jwt_secret');

        if (strlen($secret) < 32) {
            throw new RuntimeException('FACTORY_JWT_SECRET 未設定或長度不足 32 字元');
        }

        return $secret;
    }
}
