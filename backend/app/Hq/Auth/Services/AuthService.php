<?php

/* *****************************************
File: AuthService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 主站登入邏輯，見 README 6.12。
***************************************** */

namespace App\Hq\Auth\Services;

use App\Hq\Auth\Models\HqUser;
use App\Hq\Auth\Models\HqUserJwtSession;
use App\Support\Auth\JwtService;
use Illuminate\Support\Facades\Hash;

// 主站登入邏輯，見 README 6.12。沒有端口概念，也不做單帳號單 Token 限制
// （精簡設計，範疇窄，見 6.12 第 3 點）。
class AuthService
{
    public function __construct(private readonly JwtService $jwt) {}

    /**
     * @return array{ok: true, token: string, expires_at: int}|array{ok: false}
     */
    public function login(string $username, string $password): array
    {
        $user = HqUser::query()->where('username', $username)->where('is_enabled', true)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return ['ok' => false];
        }

        $issued = $this->jwt->issue(['sub' => $user->id], (string) config('jwt.hq_secret'));

        HqUserJwtSession::query()->create([
            'hq_user_id' => $user->id,
            'jti' => $issued['jti'],
            'last_access_at' => now(),
            'is_active' => true,
        ]);

        $user->update(['last_login_at' => now()]);

        return ['ok' => true, 'token' => $issued['token'], 'expires_at' => $issued['expires_at']];
    }
}
