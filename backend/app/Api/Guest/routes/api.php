<?php

use Illuminate\Support\Facades\Route;
use App\Api\Guest\Controllers\GuestApplyController;
use App\Api\Guest\Controllers\GuestApprovalController;
use App\Api\Guest\Controllers\GuestRecordController;
use App\Api\Guest\Controllers\GuestReportController;
use App\Api\Guest\Controllers\GuestSlotController;
use App\Api\Guest\Controllers\FactoryGuestApplyController;
use App\Api\Guest\Controllers\FactoryGuestKioskController;
use App\Api\Guest\Controllers\GuestDashboardReportController;


Route::middleware(['module:guest', 'jwt.auth'])->prefix('guest')->group(function () {

    Route::prefix('applies')->group(function () {
        Route::middleware('permission.api:api.guest.index')->get('/', [GuestApplyController::class, 'index']);
        Route::middleware('permission.api:api.guest.show')->get('/{id}', [GuestApplyController::class, 'show'])->whereNumber('id');
        Route::middleware('permission.api:api.guest.store')->post('/', [GuestApplyController::class, 'store']);
        Route::middleware('permission.api:api.guest.update')->put('/{id}', [GuestApplyController::class, 'update'])->whereNumber('id');
        Route::middleware('permission.api:api.guest.cancel')->put('/{id}/cancel', [GuestApplyController::class, 'cancel'])->whereNumber('id');
        Route::middleware('permission.api:api.guest.statusChange')->put('/{id}/status', [GuestApplyController::class, 'statusChange'])->whereNumber('id');
        Route::middleware('permission.api:api.guest.destroy')->delete('/{id}', [GuestApplyController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('approvals')->group(function () {
        Route::middleware('permission.api:api.guest.approval')->get('/', [GuestApprovalController::class, 'approval']);
        Route::middleware('permission.api:api.guest.approvalStatus')->get('/data', [GuestApprovalController::class, 'approvalStatus']);
    });

    // 訪客記錄：/stats 要放在 /{id} 之前（雖然 {id} 已限數字，保持順序一致較不易出錯）
    Route::prefix('records')->group(function () {
        Route::middleware('permission.api:api.guest.recordStats')->get('/stats', [GuestRecordController::class, 'recordStats']);
        Route::middleware('permission.api:api.guest.records')->get('/', [GuestRecordController::class, 'records']);
        Route::middleware('permission.api:api.guest.recordShow')->get('/{id}', [GuestRecordController::class, 'recordShow'])->whereNumber('id');
    });

    // 統計報表：兩支 API，總覽（卡片＋圖表）與明細（列表，支援匯出）
    Route::prefix('reports')->group(function () {
        Route::middleware('permission.api:api.guest.reports')->get('/', [GuestReportController::class, 'reports']);
        Route::middleware('permission.api:api.guest.reportDetails')->get('/details', [GuestReportController::class, 'reportDetails']);
    });

    // 儀錶板上報記錄：每次送出與儀錶板的回應
    Route::middleware('permission.api:api.guest.dashboardLogs')->get('dashboard-logs', [GuestDashboardReportController::class, 'dashboardLogs']);

    // 儀錶板上報設定：讀取、儲存、連線測試（手動上報一次心跳）
    Route::prefix('dashboard-settings')->group(function () {
        Route::middleware('permission.api:api.guest.dashboardSettingShow')->get('/', [GuestDashboardReportController::class, 'dashboardSettingShow']);
        Route::middleware('permission.api:api.guest.dashboardSettingUpdate')->put('/', [GuestDashboardReportController::class, 'dashboardSettingUpdate']);
        Route::middleware('permission.api:api.guest.dashboardSettingTest')->post('/test', [GuestDashboardReportController::class, 'dashboardSettingTest']);
    });

    Route::prefix('slots')->group(function () {
        Route::middleware('permission.api:api.guest.slotIndex')->get('/', [GuestSlotController::class, 'slotIndex']);
        Route::middleware('permission.api:api.guest.slotStore')->post('/', [GuestSlotController::class, 'slotStore']);
        Route::middleware('permission.api:api.guest.slotUpdate')->put('/{id}', [GuestSlotController::class, 'slotUpdate'])->whereNumber('id');
        Route::middleware('permission.api:api.guest.slotDestroy')->delete('/{id}', [GuestSlotController::class, 'slotDestroy'])->whereNumber('id');
    });
});

// 廠商模式（App Token）：訪客機以 App 身份呼叫，路徑一律在 /api/factory/*（DEVELOP.MD 12.8）。
// 認證用 factory.auth、權限用 factory.scope，與上面的用戶 jwt.auth／permission.api 是兩套互不相干的認證；
// 新增接口後執行 `php artisan factory:sync-scopes` 登記 scope。
Route::prefix('factory/guest')->middleware(['module:guest', 'factory.auth'])->group(function () {
    Route::get('applies', [FactoryGuestApplyController::class, 'index'])->middleware('factory.scope:guest.apply.read');
    Route::post('done', [FactoryGuestApplyController::class, 'done'])->middleware('factory.scope:guest.apply.done');
    Route::post('create', [FactoryGuestApplyController::class, 'create'])->middleware('factory.scope:guest.apply.create');
    Route::get('slot', [FactoryGuestApplyController::class, 'slot'])->middleware('factory.scope:guest.apply.slot');
    Route::post('leave', [FactoryGuestApplyController::class, 'leave'])->middleware('factory.scope:guest.apply.leave');
    // 訪客機狀態回報：每 30 秒心跳；硬體故障／恢復／系統正常即時回報
    Route::post('kiosk/heartbeat', [FactoryGuestKioskController::class, 'heartbeat'])->middleware('factory.scope:guest.kiosk.heartbeat');
    Route::post('kiosk/fault', [FactoryGuestKioskController::class, 'fault'])->middleware('factory.scope:guest.kiosk.fault');
});
