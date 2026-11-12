<?php

/* *****************************************
File: webbase.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: Web 權限同步與授權清單顯示設定，見 README 18.2.1。
***************************************** */

// Web 權限同步與授權清單顯示設定，見 README 18.2.1。

return [

    'permission' => [

        // permission:sync-web 的掃描排除規則。
        'web_sync' => [
            // 預設 false：只同步掛載 permission.web 的路由。
            'include_unprotected_routes' => false,

            // 路徑排除清單，支援萬用字元。
            'exclude_paths' => [
                '/docs/*',
                '/up',
            ],

            // route name 排除清單。
            'exclude_route_names' => [
                'ignition.*',
            ],
        ],

        // 授權清單顯示格式配置（RoleAdmin），見 18.2.1 第 2 點。
        'catalog_display' => [
            'domain_prefixes' => [
                'api' => '[API]',
                'web' => '[WEB]',
            ],
            'modules' => [
                // 'order' => '訂單',
            ],
            'actions' => [
                'view' => '檢視',
                'write' => '新增/編輯',
                'delete' => '刪除',
                'entry' => '入口',
                'create' => '新增',
                'update' => '編輯',
            ],
            'templates' => [
                'module' => '{prefix}{module} {action}',
            ],
        ],

    ],

];
