<?php

/* *****************************************
File: GuestKioskService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 訪客機狀態 Service：記錄心跳與故障回報（日誌表＋最後回報時間表），並提供給儀錶板心跳用的各機狀態。
訪客機每 30 秒回報一次；最後回報超過 1 分鐘（GuestKioskStatus::OFFLINE_AFTER_SECONDS）即視為離線。
***************************************** */

namespace App\Api\Guest\Services;

use App\Api\Guest\Models\GuestKioskLog;
use App\Api\Guest\Models\GuestKioskStatus;
use Illuminate\Support\Facades\DB;

class GuestKioskService
{
    /**
     * 記錄一次心跳：更新最後回報時間，並寫日誌。
     * 訪客機若帶了 $faults（目前仍未恢復的故障），以它為準整批覆蓋，漏收的故障／恢復回報下次心跳就能補正；
     * 沒帶（null）則不動故障清單。
     *
     * @param  array<int, array{fault_code: string, occurred_at?: string|null}>|null  $faults
     */
    public function heartbeat(string $appId, ?array $faults, ?string $ip, array $payload = []): GuestKioskStatus
    {
        return DB::connection('site_current')->transaction(function () use ($appId, $faults, $ip, $payload) {
            $status = $this->touch($appId);

            if ($faults !== null) {
                $status->faults = collect($faults)
                    ->map(fn ($f) => ['fault_code' => $f['fault_code'], 'occurred_at' => $f['occurred_at'] ?? now()->toDateTimeString()])
                    ->unique('fault_code')->values()->all();
                $status->save();
            }

            GuestKioskLog::query()->create([
                'app_id' => $appId,
                'log_type' => GuestKioskLog::TYPE_HEARTBEAT,
                'payload' => $payload ?: null,
                'ip' => $ip,
            ]);

            return $status;
        });
    }

    /**
     * 記錄一次故障回報：故障發生、故障恢復，或「系統正常」（SYSTEM_OK＝清除這台所有未恢復故障）。
     * 回報同時也代表這台訪客機還活著，所以一併更新最後回報時間。
     *
     * @return array{status: GuestKioskStatus, changed: bool} changed：故障清單有沒有真的變動（重複回報同一個狀態為 false，不需再通知儀錶板）
     */
    public function fault(string $appId, string $faultCode, string $faultStatus, string $occurredAt, ?string $ip, array $payload = []): array
    {
        return DB::connection('site_current')->transaction(function () use ($appId, $faultCode, $faultStatus, $occurredAt, $ip, $payload) {
            $status = $this->touch($appId);
            $before = collect($status->faults ?? []);

            if ($faultCode === GuestKioskStatus::SYSTEM_OK) {
                $after = collect();
            } elseif ($faultStatus === 'resolved') {
                $after = $before->reject(fn ($f) => $f['fault_code'] === $faultCode);
            } else {
                // 同一種故障重複回報，保留最早的發生時間
                $after = $before->contains(fn ($f) => $f['fault_code'] === $faultCode)
                    ? $before
                    : $before->concat([['fault_code' => $faultCode, 'occurred_at' => $occurredAt]]); // concat 回傳新集合，不能用 push（會改到 $before，導致比對不出變動）
            }

            $after = $after->values()->all();
            $changed = $after !== $before->values()->all();

            if ($changed) {
                $status->faults = $after;
                $status->save();
            }

            GuestKioskLog::query()->create([
                'app_id' => $appId,
                'log_type' => GuestKioskLog::TYPE_FAULT,
                'fault_code' => $faultCode,
                'fault_status' => $faultCode === GuestKioskStatus::SYSTEM_OK ? 'resolved' : $faultStatus,
                'payload' => $payload ?: null,
                'ip' => $ip,
            ]);

            return ['status' => $status, 'changed' => $changed];
        });
    }

    /**
     * 給儀錶板心跳用的各訪客機狀態（每台一筆，依 app_id 排序）。
     * online 依最後回報時間判斷：超過 1 分鐘沒回報＝offline。
     *
     * @return array<int, array{kiosk_id: string, status: string, last_reported_at: ?string, faults: array}>
     */
    public function snapshot(): array
    {
        return GuestKioskStatus::query()->orderBy('app_id')->get()
            ->map(fn (GuestKioskStatus $s) => [
                'kiosk_id' => $s->app_id,
                'status' => $s->isOnline() ? 'online' : 'offline',
                'last_seen_at' => $s->last_reported_at?->format('Y-m-d H:i:s'),
                'faults' => $s->faults ?? [],
            ])->all();
    }

    private function touch(string $appId): GuestKioskStatus
    {
        $status = GuestKioskStatus::query()->lockForUpdate()->firstOrNew(['app_id' => $appId]);
        $status->last_reported_at = now();
        $status->faults ??= [];
        $status->save();

        return $status;
    }
}
