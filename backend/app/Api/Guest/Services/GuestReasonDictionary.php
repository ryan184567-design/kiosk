<?php

/* *****************************************
File: GuestReasonDictionary.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-11-23
Description: 來訪事由字典（guest_visit_reason）取值：預約單的 visit_reason 存的是字典項目的值，這裡負責換成顯示文字。
***************************************** */

namespace App\Api\Guest\Services;

use App\Api\Dictionary\Models\SystemDictionary;

class GuestReasonDictionary
{
    /** 字典代碼，種子資料見 SiteBaselineSeeder::seedGuestVisitReasonDictionary */
    public const CODE = 'guest_visit_reason';

    /** 字典沒有對應項目（或未填）時顯示的文字 */
    public const FALLBACK_LABEL = '其他';

    private ?array $labels = null;

    /** @return array<int, string> 值 => 顯示文字（含停用項目，歷史資料仍要能顯示） */
    public function labels(): array
    {
        if ($this->labels === null) {
            $dictionary = SystemDictionary::query()->where('code', self::CODE)->first();

            $this->labels = $dictionary
                ? $dictionary->items()->orderBy('sort')->orderBy('id')->pluck('label', 'value')
                    ->mapWithKeys(fn ($label, $value) => [(int) $value => $label])->all()
                : [];
        }

        return $this->labels;
    }

    public function label(?int $value): string
    {
        return $this->labels()[(int) $value] ?? self::FALLBACK_LABEL;
    }
}
