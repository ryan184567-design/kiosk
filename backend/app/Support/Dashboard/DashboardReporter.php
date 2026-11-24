<?php

/* *****************************************
File: DashboardReporter.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 儀錶板上報的公共方法：業務事件（核准、修改、取消、報到、離場、訪客機故障）發生時呼叫，
由設定決定要不要上報、上報到哪。用法與格式見 docs/DASHBOARD_REPORT.MD 第 8 節。
***************************************** */

namespace App\Support\Dashboard;

use App\Api\Guest\Models\GuestApply;
use App\Api\Guest\Services\DashboardReportService;
use Illuminate\Support\Facades\Log;

// 在事件發生的接口裡，動作成功「且交易已提交」之後呼叫一行即可，例如：
//     DashboardReporter::approved($apply);
//
// - 不用判斷設定：沒啟用、或上傳內容沒勾選該事件時，這裡什麼都不做。
// - 不會丟例外、不拖慢回應：這裡只把通知排進佇列（一筆資料庫寫入），實際 POST 由 queue worker 執行，
//   單次最多等 5 秒、失敗 10 秒後重試、共 3 次失敗就丟棄（SendDashboardNotification）。儀錶板慢或掛掉都不影響使用者的操作。
// - 通知內容在呼叫當下從資料庫讀最新的預約資料，所以傳入 Model 即可，不必先組好；因此請在交易提交之後再呼叫。
class DashboardReporter
{
    /** 核准：預約審核通過（reservation_status = 10） */
    public static function approved(GuestApply $apply): void
    {
        self::reservation('approved', $apply);
    }

    /** 修改：修改後「重新審核通過」時呼叫（reservation_status = 11），不是修改當下（那時還是待審核） */
    public static function modified(GuestApply $apply): void
    {
        self::reservation('modified', $apply);
    }

    /** 取消：訪客取消預約（reservation_status = 60） */
    public static function cancelled(GuestApply $apply): void
    {
        self::reservation('cancelled', $apply);
    }

    /** 報到：訪客到場完成報到（reservation_status = 40） */
    public static function checkin(GuestApply $apply): void
    {
        self::reservation('checkin', $apply);
    }

    /** 離場：訪客離開現場（reservation_status = 50） */
    public static function checkout(GuestApply $apply): void
    {
        self::reservation('checkout', $apply);
    }

    /**
     * 訪客機故障／恢復／系統正常。已在 POST /api/factory/guest/kiosk/fault 內呼叫，一般業務程式不需要自己呼叫。
     *
     * @param  string  $faultStatus  occurred 或 resolved
     */
    public static function deviceFault(string $kioskId, string $faultCode, string $faultStatus, string $faultMessage, string $occurredAt): void
    {
        self::safely('device_fault', fn () => app(DashboardReportService::class)
            ->reportDeviceFault($kioskId, $faultCode, $faultStatus, $faultMessage, $occurredAt));
    }

    private static function reservation(string $event, GuestApply $apply): void
    {
        self::safely($event, fn () => app(DashboardReportService::class)->reportReservation($event, $apply));
    }

    /** 上報出任何問題都只記 log，絕不影響呼叫端的業務動作 */
    private static function safely(string $what, \Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::error('儀錶板上報發生例外', ['event' => $what, 'error' => $e->getMessage()]);
        }
    }
}
