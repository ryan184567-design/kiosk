<?php

/* *****************************************
File: JwtService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 薄封裝 firebase/php-jwt，見 README 11.1。
***************************************** */

namespace App\Support\Auth;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Support\Str;
use stdClass;
use UnexpectedValueException;

// 薄封裝 firebase/php-jwt，見 README 11.1。子站與主站各自用不同的
// secret（見 config/jwt.php），呼叫時必須指定要用哪一組。
class JwtService
{
    public function issue(array $claims, string $secret): array
    {
        $jti = (string) Str::uuid();
        $now = time();
        $ttl = config('jwt.ttl_minutes') * 60;

        $payload = array_merge($claims, [
            'jti' => $jti,
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        return [
            'token' => JWT::encode($payload, $secret, config('jwt.algo')),
            'jti' => $jti,
            'expires_at' => $now + $ttl,
        ];
    }

    // 回傳 null 代表無效（過期、簽章錯誤、格式錯誤），呼叫端統一當作
    // 「未認證」處理，不需要分辨細節原因。
    public function decode(string $token, string $secret): ?stdClass
    {
        try {
            return JWT::decode($token, new Key($secret, config('jwt.algo')));
        } catch (ExpiredException|SignatureInvalidException|UnexpectedValueException) {
            return null;
        }
    }
}
