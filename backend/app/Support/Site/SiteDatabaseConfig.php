<?php

/* *****************************************
File: SiteDatabaseConfig.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 子站連線（site_current）的資料庫設定來源，見 README 6.4、2.5。
***************************************** */

namespace App\Support\Site;

// 子站連線（site_current）的資料庫設定來源，見 README 6.4、2.5。
//
// 這裡刻意只讀 config('database.connections.mysql')，**不在執行期呼叫 env()**：
// `php artisan config:cache` 之後 Laravel 根本不會載入 .env，執行期的 env()
// 一律回傳 null。原本 SiteResolver／SiteProvisioningService／各 CLI 指令各自
// 用 env('DB_HOST')／env('DB_DATABASE')／env('DB_USERNAME') 組這些值，在正式
// 環境（一定會開 config:cache）會變成「空帳號、空資料庫」的連線，症狀是
// `SQLSTATE[HY000] [1045] Access denied for user ''@'localhost'`——
// 單站部署包實際踩到，而且是「頁面打得開、一登入就 500」這種最難查的形態
// （站台首頁不需要資料庫，登入才第一次碰 DB）。預設連線的 config 是在
// config 載入階段就固定下來的，開不開 config:cache 都拿得到正確值。
class SiteDatabaseConfig
{
    /**
     * 產生 site_current 連線的完整設定（含既有佔位設定），呼叫端直接
     * Config::set('database.connections.site_current', ...) 後 DB::purge() 即可。
     *
     * @param  bool  $inheritReplica  是否沿用預設連線的讀寫分離設定
     *                                （單站模式要，多站模式不要，見說明）
     */
    public static function connection(string $databaseName, bool $inheritReplica = false): array
    {
        $source = config('database.connections.mysql');

        $connection = array_merge(config('database.connections.site_current'), [
            'host' => $source['host'] ?? '127.0.0.1',
            'port' => $source['port'] ?? '3306',
            'database' => $databaseName,
            'username' => $source['username'] ?? '',
            'password' => $source['password'] ?? '',
        ]);

        // 見 README 2.5：單站模式（這個部署包只服務一個客戶，資料庫就是 .env 這一組）
        // 可以選擇性開啟讀寫分離，所以沿用預設連線的 read/write/sticky。
        // 多站模式**刻意不沿用**：各子站是各自獨立的資料庫，若把主站的讀庫設定
        // 帶進子站連線，會讓某個子站的查詢悄悄跑去讀主站的讀庫（張冠李戴，
        // 比連線失敗更難發現），子站要讀寫分離應該是它自己的設定。
        if ($inheritReplica) {
            $connection['read'] = $source['read'] ?? null;
            $connection['write'] = $source['write'] ?? null;
            $connection['sticky'] = $source['sticky'] ?? true;
        }

        return $connection;
    }

    // 單站模式（見 6.8）的 site_current 連線：站點就是 .env 指定的那個資料庫，
    // 沒有站點清單可查，連線資訊直接沿用預設連線（含讀寫分離，見 2.5）。
    // HTTP 請求走 SiteResolver、CLI 指令（如 jwt:sessions:prune）直接呼叫這裡，
    // 兩邊必須一致，否則會出現「網頁正常、排程指令連到佔位假主機」的落差
    // （已實測踩過：CLI 沒設定 site_current，連到 site-context-not-resolved.invalid）。
    public static function singleSiteConnection(): array
    {
        return self::connection(
            (string) (config('database.connections.mysql')['database'] ?? ''),
            inheritReplica: true,
        );
    }
}
