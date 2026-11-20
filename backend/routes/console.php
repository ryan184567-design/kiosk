<?php

/* *****************************************
File: console.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: Laravel Scheduler 排程登記：JWT 會話清理，見 README 18.4。
***************************************** */

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 見 README 18.4：JWT 會話清理排程，實際觸發時間由 JWT_SESSION_PRUNE_TIME 決定。
// 廠商模式（App Token）記錄：保留 FACTORY_LOG_RETENTION_DAYS 天（預設 30）後自動移除，見 docs/APP_DEVELOP.MD 7.5。
if (config('factory.log_prune_enabled')) {
    Schedule::command('factory:logs-prune')
        ->dailyAt((string) config('factory.log_prune_time'))
        ->withoutOverlapping();
}

if (config('jwt_session.prune_enabled')) {
    Schedule::command('jwt:sessions:prune')
        ->dailyAt((string) config('jwt_session.prune_time'))
        ->withoutOverlapping();
}
