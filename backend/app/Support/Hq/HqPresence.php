<?php

/* *****************************************
File: HqPresence.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 判斷目前這份程式碼是否包含主站（app/Hq）模組，見 README 6.8、9。
***************************************** */

namespace App\Support\Hq;

// 判斷目前這份程式碼是否包含主站（app/Hq）模組，見 README 6.8、9。
//
// 這個開關存在的理由：單站部署包（site:package）**刻意不含 app/Hq**——現場只
// 服務一個客戶，不需要開站／站點管理功能。但共用層有幾處為了查「模塊登記」而
// 依賴主站的 Model，結果部署包一執行就 `Class "App\Hq\SiteModule\Models\Module"
// not found`，而且是「登入成功後第一次載入選單就 500」這種難查的形態（實測踩過）。
// 這裡統一判斷，讓共用層優雅降級，而不是到處複製 class_exists()。
//
// 判斷方式用「檔案存在 + 類別存在」兩段：composer 的 classmap 只是索引，真正的
// 類別定義在 app/Hq 底下，檔案不在就代表這個部署包沒有主站。
class HqPresence
{
    public static function available(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        return $available = is_dir(app_path('Hq'))
            && class_exists(\App\Hq\SiteModule\Models\Module::class);
    }

    // 單站部署包沒有 modules 主表（它存在主站資料庫，見 6.2、6.9），模塊開通
    // 清單是空集合；配合 ModuleAccessService 的「未登記即放行」原則（見
    // ModuleAccessService::isEnabledForSite），單站模式下所有模塊都可用，
    // 與「多站環境下該子站原本已開通的模塊」一致——不改客戶已購買的功能範圍。
    public static function moduleRegistryAvailable(): bool
    {
        return self::available();
    }
}
