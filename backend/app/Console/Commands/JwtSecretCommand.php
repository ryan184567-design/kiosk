<?php

/* *****************************************
File: JwtSecretCommand.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-13
Description: 產生子站／主站 JWT 簽章密鑰並寫入 .env，見 README 3.4、11.1。
***************************************** */

namespace App\Console\Commands;

use Illuminate\Console\Command;

// 比照 key:generate：把 JWT_SITE_SECRET、JWT_HQ_SECRET 兩把互相獨立的密鑰
// 寫進 .env。預設只補「目前是空的」那把，已有值的不動（避免誤蓋掉讓所有
// 已簽發的 token 失效）；要強制全部重產用 --force。密鑰留空時登入看似正常，
// 但之後每個需要驗證的 API 都會丟 "Key material must not be empty"。
class JwtSecretCommand extends Command
{
    protected $signature = 'jwt:secret {--force : 已有值也重新產生（既有 token 全部失效）}';

    protected $description = '產生子站／主站 JWT 密鑰並寫入 .env（見 README 3.4、11.1）';

    private const KEYS = ['JWT_SITE_SECRET', 'JWT_HQ_SECRET'];

    public function handle(): int
    {
        $path = base_path('.env');

        if (! is_file($path)) {
            $this->error('找不到 .env，請先 copy .env.example .env。');

            return self::FAILURE;
        }

        $env = (string) file_get_contents($path);
        $force = (bool) $this->option('force');
        $changed = [];

        foreach (self::KEYS as $key) {
            $current = $this->currentValue($env, $key);

            if ($current !== '' && ! $force) {
                $this->line("{$key} 已有值，略過（要重產請加 --force）。");

                continue;
            }

            $secret = bin2hex(random_bytes(32));
            $line = "{$key}={$secret}";

            if (preg_match($this->pattern($key), $env)) {
                $env = preg_replace($this->pattern($key), $line, $env, 1);
            } else {
                $env = rtrim($env, "\r\n").PHP_EOL.$line.PHP_EOL;
            }

            $changed[] = $key;
        }

        if ($changed === []) {
            $this->info('兩把 JWT 密鑰都已設定，沒有變更。');

            return self::SUCCESS;
        }

        file_put_contents($path, $env);
        $this->info('已寫入 .env：'.implode('、', $changed));
        $this->comment('設定若已快取，請再執行 php artisan config:clear。');

        return self::SUCCESS;
    }

    private function currentValue(string $env, string $key): string
    {
        if (! preg_match($this->pattern($key), $env, $m)) {
            return '';
        }

        return trim(substr($m[0], strlen($key) + 1), " \t\r\n\"'");
    }

    private function pattern(string $key): string
    {
        return '/^'.preg_quote($key, '/').'=.*$/m';
    }
}
