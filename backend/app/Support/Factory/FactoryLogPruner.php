<?php

/* *****************************************
File: FactoryLogPruner.php
Author: cliffdean
Date: 2026-11-20
Description: 清理超過保留天數的廠商模式記錄（factory_logs，所有類型），作用在目前的 site_current 連線
***************************************** */

namespace App\Support\Factory;

use App\Api\Factory\Models\FactoryLog;
use Illuminate\Support\Carbon;

// 記錄保留 factory.log_retention_days 天（預設 30），超過就移除。
// 範圍是 factory_logs 全部類型（簽發、權限被拒、API 呼叫、後台操作），依 created_at 判斷。
// 只管「目前連線的這一個子站」；多站遍歷與排程在 FactoryLogsPruneCommand。
class FactoryLogPruner
{
    /** @return int 被（或預覽時將被）移除的筆數 */
    public function prune(?int $days = null, bool $dryRun = false): int
    {
        $days ??= (int) config('factory.log_retention_days');

        if ($days < 1) {
            throw new \InvalidArgumentException('保留天數必須 >= 1');
        }

        $query = FactoryLog::query()->where('created_at', '<', Carbon::now()->subDays($days));
        $count = $query->count();

        if (! $dryRun && $count > 0) {
            // 分批刪除：除錯記錄量大時避免一次刪太多造成長時間鎖表
            do {
                $deleted = FactoryLog::query()
                    ->where('created_at', '<', Carbon::now()->subDays($days))
                    ->limit(5000)
                    ->delete();
            } while ($deleted > 0);
        }

        return $count;
    }
}
