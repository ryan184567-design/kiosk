<?php

/* *****************************************
File: HqDatabaseSeeder.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 見 README 6.12 第 4 點：主站資料庫首次建置需要至少一個 is_super_admin=true 的帳號，否則沒有人能執行第一次 site:create。
***************************************** */

namespace Database\Hq\Seeders;

use App\Hq\Auth\Models\HqUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use RuntimeException;

// 見 README 6.12 第 4 點：主站資料庫首次建置需要至少一個 is_super_admin=true
// 的帳號，否則沒有人能執行第一次 site:create。帳密統一在 config/initial_accounts.php
// 定義，不是每次隨機產生，見該檔案說明。
class HqDatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $account = config('initial_accounts.hq_admin');
        $existing = HqUser::query()->where('username', $account['username'])->first();

        // 帳號建好之後，.env 的明文密碼建議清空避免外流；清空後重跑
        // db:seed 不能把密碼重置成空字串或報錯擋住其他 seeder，只有「帳號
        // 還不存在」這種第一次建置的情況才需要密碼、沒設就中斷。
        if (! $account['password']) {
            if (! $existing) {
                throw new RuntimeException(
                    '未設定 HQ_ADMIN_PASSWORD（見 config/initial_accounts.php），無法建立主站初始管理員帳號。'
                );
            }

            $existing->forceFill(['is_super_admin' => true, 'is_enabled' => true])->save();

            $this->command?->info("HQ_ADMIN_PASSWORD 未設定，沿用現有密碼不重置：{$account['username']}");

            return;
        }

        HqUser::query()->updateOrCreate(
            ['username' => $account['username']],
            [
                'name' => '主站初始管理員',
                'password' => bcrypt($account['password']),
                'is_super_admin' => true,
                'is_enabled' => true,
            ]
        );

        $this->command?->info("主站初始管理員帳號已就緒：{$account['username']}");
    }
}
