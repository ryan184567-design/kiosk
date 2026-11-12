<?php

/* *****************************************
File: database.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 資料庫連線設定，含主站/子站連線與 site_current 動態連線，見 README 6.4。
***************************************** */

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        // 主站（hq）連線：多站模式下的預設連線，見 README 6.1、6.12。
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            // 見 README 2.5：未配置 DB_READ_HOST / DB_WRITE_HOST 時整組省略，
            // Laravel 會回退用下面的單一 host/port/username/password。
            'read' => env('DB_READ_HOST') ? [
                'host' => explode(',', env('DB_READ_HOST')),
                'port' => env('DB_READ_PORT', env('DB_PORT', '3306')),
                'username' => env('DB_READ_USERNAME', env('DB_USERNAME', 'root')),
                'password' => env('DB_READ_PASSWORD', env('DB_PASSWORD', '')),
            ] : null,
            'write' => env('DB_READ_HOST') ? [
                'host' => [env('DB_WRITE_HOST', env('DB_HOST', '127.0.0.1'))],
                'port' => env('DB_WRITE_PORT', env('DB_PORT', '3306')),
                'username' => env('DB_WRITE_USERNAME', env('DB_USERNAME', 'root')),
                'password' => env('DB_WRITE_PASSWORD', env('DB_PASSWORD', '')),
            ] : null,
            'sticky' => env('DB_STICKY', true),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => env('DB_ENGINE', 'InnoDB'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        // 子站動態連線的固定名稱（見 README 6.4）。這裡只是佔位設定，
        // EnsureSiteContext middleware 會在每次請求時用 Config::set() 覆寫成
        // 目前站點的真實連線資訊，再 DB::purge('site_current') 生效。
        // 未經過 middleware 就直接使用這個連線名稱會連到下面的假資料庫，
        // 刻意設計成明顯不存在的 host，若忘記掛 middleware 會直接連線失敗而不是悄悄連錯站。
        'site_current' => [
            'driver' => 'mysql',
            'host' => 'site-context-not-resolved.invalid',
            'port' => env('DB_PORT', '3306'),
            'database' => 'site_current_unresolved',
            'username' => '',
            'password' => '',
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => env('DB_ENGINE', 'InnoDB'),
        ],

        // 建站流程專用的高權限連線（見 README 6.3 安全性提醒），
        // 只在 site:create 這條受保護流程中使用，一般請求路徑不會用到。
        'mysql_provision' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_PROVISION_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_PROVISION_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => env('DB_ENGINE', 'InnoDB'),
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => env('DB_ENGINE', 'InnoDB'),
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
