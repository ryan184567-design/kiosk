<?php

/* *****************************************
File: jwt_session.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: JWT 會話規則，見 README 11.1。
***************************************** */

// JWT 會話規則，見 README 11.1。

return [

    // 限制範疇是「同一帳號在同一端口」，不是整個帳號，見 11.1。
    'single_user_session' => (bool) env('JWT_SINGLE_USER_SESSION', true),

    'touch_interval_seconds' => (int) env('JWT_SESSION_TOUCH_INTERVAL_SECONDS', 60),

    'prune_enabled' => (bool) env('JWT_SESSION_PRUNE_ENABLED', true),

    'prune_retention_days' => (int) env('JWT_SESSION_PRUNE_RETENTION_DAYS', 30),

    'prune_time' => env('JWT_SESSION_PRUNE_TIME', '03:20'),

];
