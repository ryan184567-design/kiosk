<?php

/* *****************************************
File: filesystems.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: Laravel 檔案系統磁碟設定（骨架預設，實際檔案存取一律經過 FileManager，見 README 10.1）。
***************************************** */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        // 見 README 10.1 第 2 點、10.3：私有／不公開資源，落在 storage/ 底下，
        // 不透過任何 symlink 對外開放，FileManager 對 storage_type!=PUBLIC
        // 的檔案本來就不回傳任何存取網址（見 FileManager::url()）。
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private/uploads'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        // 見 README 10.1 第 2 點、10.3：公開資源直接落在 public/uploads/
        // 這個實體目錄下，不使用 Laravel 預設的 storage:link symlink 做法
        // （public/storage -> storage/app/public）——storage/ 底下同時也放
        // log、cache 等內部運作資料，不應該讓任何一段路徑被 symlink 整個
        // 對外曝露；公開/私有兩種資源用實體上分開的目錄區隔，不是靠
        // symlink 這種容易忘記執行、部分部署環境（例如某些共用主機、
        // Windows 開發環境需要額外權限）也可能無法正確建立的間接手法。
        'public' => [
            'driver' => 'local',
            'root' => public_path('uploads'),
            'url' => '/uploads',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    // 見上方 'public' disk 的說明：公開資源直接落在 public/uploads/ 實體
    // 目錄，不透過 storage:link 建立的 symlink 對外開放，這裡刻意不保留
    // 'links' 設定，避免有人誤以為還需要執行 php artisan storage:link。

];
