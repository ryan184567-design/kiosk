<?php

/* *****************************************
File: AdConnector.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 6.10：驗證範例插件，示範整條「PluginManager 解析 -> 讀站點設定 -> 呼叫廠商 SDK/擴充套件 -> 內部例外轉換成統一 PluginConnectorException」 的鏈路，用 PHP 內建 ldap 擴充套件實作，不需要額外的 composer 套件。
***************************************** */

namespace App\Plugins\Ad\Ldap\V1;

use App\Plugins\Ad\Contracts\AdConnectorInterface;
use App\Support\Plugin\PluginConnectorException;
use Throwable;

// 見 README 6.10：驗證範例插件，示範整條「PluginManager 解析 -> 讀站點設定
// -> 呼叫廠商 SDK/擴充套件 -> 內部例外轉換成統一 PluginConnectorException」
// 的鏈路，用 PHP 內建 ldap 擴充套件實作，不需要額外的 composer 套件。真實
// 案場對接時，通常只需要調整 configSchema／bind_dn_template，不需要換掉
// 這整支邏輯。
class AdConnector implements AdConnectorInterface
{
    public function __construct(private readonly array $config) {}

    public static function configSchema(): array
    {
        return [
            'host' => ['type' => 'string', 'required' => true, 'description' => 'LDAP 主機位址'],
            'port' => ['type' => 'int', 'required' => false, 'description' => 'LDAP 埠號，預設 389'],
            'base_dn' => ['type' => 'string', 'required' => true, 'description' => '搜尋基準 DN，例如 dc=example,dc=com'],
            'bind_dn_template' => [
                'type' => 'string',
                'required' => true,
                'description' => '組出使用者 DN 的樣板，:username 會被換成實際帳號，例如 uid=:username,ou=people,dc=example,dc=com',
            ],
        ];
    }

    public function authenticate(array $credentials): array
    {
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;

        if (! $username || ! $password) {
            throw new PluginConnectorException(PluginConnectorException::AUTH_REJECTED, '缺少帳號或密碼');
        }

        $host = $this->config['host'] ?? null;

        if (! $host) {
            throw new PluginConnectorException(PluginConnectorException::CONNECTION_FAILED, 'AD/LDAP 設定缺少 host（見 configSchema）');
        }

        $connection = $this->connect($host, (int) ($this->config['port'] ?? 389));
        $bindDn = str_replace(':username', $username, (string) ($this->config['bind_dn_template'] ?? ''));

        try {
            $bound = @ldap_bind($connection, $bindDn, $password);
        } catch (Throwable $e) {
            // 見 6.10.3：新版 PHP 的 ldap 擴充套件對部分錯誤改拋例外而非
            // 回傳 false，這裡統一攔截、轉換成 PluginConnectorException，
            // 呼叫端不需要認識 ldap 擴充套件的例外類別。
            @ldap_unbind($connection);

            throw new PluginConnectorException(PluginConnectorException::AUTH_REJECTED, 'LDAP bind 失敗：'.$e->getMessage(), $e);
        }

        if (! $bound) {
            $error = ldap_error($connection);
            @ldap_unbind($connection);

            throw new PluginConnectorException(PluginConnectorException::AUTH_REJECTED, "LDAP 驗證失敗：{$error}");
        }

        $profile = $this->fetchProfile($connection, $username);
        @ldap_unbind($connection);

        return $profile;
    }

    /**
     * @return resource|\LDAP\Connection
     */
    private function connect(string $host, int $port)
    {
        $connection = @ldap_connect($host, $port);

        if (! $connection) {
            throw new PluginConnectorException(PluginConnectorException::CONNECTION_FAILED, "無法建立 LDAP 連線：{$host}:{$port}");
        }

        ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 5);

        return $connection;
    }

    /**
     * @param  resource|\LDAP\Connection  $connection
     */
    private function fetchProfile($connection, string $username): array
    {
        $baseDn = (string) ($this->config['base_dn'] ?? '');
        $entries = @ldap_search($connection, $baseDn, "(uid={$username})");
        $result = $entries ? ldap_get_entries($connection, $entries) : false;

        if (! $result || $result['count'] === 0) {
            throw new PluginConnectorException(PluginConnectorException::INVALID_RESPONSE, '驗證通過但查無使用者資料，請檢查 base_dn 設定');
        }

        $entry = $result[0];

        return [
            'external_id' => $entry['uid'][0] ?? $username,
            'username' => $username,
            'name' => $entry['cn'][0] ?? $username,
            'email' => $entry['mail'][0] ?? null,
        ];
    }
}
