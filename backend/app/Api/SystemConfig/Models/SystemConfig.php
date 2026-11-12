<?php

/* *****************************************
File: SystemConfig.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-12
Description: 系統環境配置，見 README 17。
***************************************** */

namespace App\Api\SystemConfig\Models;

use Illuminate\Database\Eloquent\Model;

// 系統環境配置，見 README 17。
//
// TYPES／TYPE_TITLES／OPTION_SOURCE_* 是 cfg_type 的單一真相來源：
//   - TYPES：目前支援的全部類型（擴充自原始的 string/int/enum/dic/date/datetime，
//     完整清單見 17.1）。cfg_type 欄位已放寬為 varchar(20)，新增類型只要
//     在這裡登記、SystemConfigService::assertValidValue 加驗證分支、
//     SiteBaselineSeeder 重灌 system_cfg_type 字典即可，不用改表。
//   - TYPE_TITLES：各類型的中文說明，供 SiteBaselineSeeder 灌
//     system_cfg_type 字典（README 17「附帶字典供前台使用」）使用。
//   - OPTION_SOURCE_*：依「cfg_option 怎麼解讀」分類，SystemConfigService
//     的驗證與 GET /api/system-configs 的 options 自動解析共用這些清單。
class SystemConfig extends Model
{
    protected $connection = 'site_current';

    public const TYPES = [
        'string', 'textarea', 'int', 'float', 'bool',
        'enum', 'multi', 'dic', 'dic_multi',
        'date', 'datetime', 'time',
        'email', 'url', 'color', 'password', 'json', 'image',
    ];

    public const TYPE_TITLES = [
        'string' => '單行文字',
        'textarea' => '多行文字',
        'int' => '整數',
        'float' => '小數',
        'bool' => '布林開關',
        'enum' => '下拉選項',
        'multi' => '多選',
        'dic' => '字典下拉',
        'dic_multi' => '字典多選',
        'date' => '日期',
        'datetime' => '日期時間',
        'time' => '時間',
        'email' => '信箱',
        'url' => '網址',
        'color' => '色碼',
        'password' => '密碼／金鑰',
        'json' => 'JSON 結構',
        'image' => '圖片上傳',
    ];

    // cfg_option 是「允許值陣列」，下拉選項直接由 cfg_option 展開。
    public const OPTION_SOURCE_ENUM = ['enum', 'multi'];

    // cfg_option 是字典代碼（App\Api\Dictionary），下拉選項由該字典底下
    // 啟用中的項目自動帶出（見 17.3）。
    public const OPTION_SOURCE_DICTIONARY = ['dic', 'dic_multi'];

    // cfg_option 是最大長度數字。
    public const OPTION_SOURCE_LENGTH = ['string', 'textarea', 'int'];

    // cfg_option 是小數位數上限。
    public const OPTION_SOURCE_PRECISION = ['float'];

    // cfg_option 只是顯示用格式字串（不是驗證規則）。
    public const OPTION_SOURCE_FORMAT = ['date', 'datetime', 'time'];

    // cfg_option 是縮圖規格物件，見 17.5、App\Api\SystemConfig\Services\SystemConfigImageService。
    // 支援的鍵（都可省略，省略時用 IMAGE_SPEC_DEFAULTS）：
    //   width/height：輸出尺寸（像素）
    //   mode：cover（填滿輸出，可能裁切）／contain（完整保留＋補背景）
    //   format：輸出格式 png／jpeg／webp／gif（預設 png）
    //   transparent：是否允許／預設透明背景（format 不支援透明時自動失效）
    //   background：補背景色碼（#rrggbb），設了就代表不透明；PNG 要透明就別設
    //   max_kb：允許上傳的來源檔大小上限
    //   allowed_formats：允許上傳的來源格式清單，例如 ["jpg","png"]
    public const OPTION_SOURCE_IMAGE = ['image'];

    // 支援的圖片輸出格式，與 IMAGE_EXTENSIONS／IMAGE_MIMES 對應（見 17.5）。
    public const IMAGE_FORMATS = ['png', 'jpeg', 'webp', 'gif'];

    // 支援透明背景的輸出格式（JPEG／GIF 沒有 alpha 通道）。
    public const TRANSPARENT_FORMATS = ['png', 'webp'];

    public const IMAGE_EXTENSIONS = [
        'png' => 'png',
        'jpeg' => 'jpg',
        'webp' => 'webp',
        'gif' => 'gif',
    ];

    // 可上傳的來源格式 → mime type（見 SystemConfigImageService::resolveAllowedMimes）。
    public const IMAGE_MIMES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    // 圖片規格的預設值：300×300、填滿輸出、透明 PNG、來源檔 5MB、來源格式全開。
    // 實際設定項（例如網站 Logo 是 300×200 PNG）由 seeder 的 cfg_option 覆寫。
    public static function defaultImageSpec(): array
    {
        return [
            'width' => 300,
            'height' => 300,
            'mode' => 'cover',
            'format' => 'png',
            'transparent' => true,
            'background' => null,
            'max_kb' => 5120,
            'allowed_formats' => array_keys(self::IMAGE_MIMES),
        ];
    }

    protected $fillable = [
        'site_id',
        'cfg_name',
        'cfg_title',
        'cfg_type',
        'cfg_option',
        'cfg_style',
        'cfg_value',
        'cfg_default',
        'cfg_module',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'cfg_option' => 'array',
            'cfg_style' => 'array',
        ];
    }

    // 給前端（後台設定頁、裁切對話框）讀的「這個欄位的圖片規格」：把
    // cfg_option 正規化成一份含預設值的完整規格，前端不用自己重複一套
    // fallback 邏輯，也避免兩邊預設值不一致（見 17.5）。
    public function imageSpec(): array
    {
        $option = is_array($this->cfg_option) ? $this->cfg_option : [];
        $defaults = self::defaultImageSpec();

        $format = strtolower((string) ($option['format'] ?? ($option['transparent'] ?? true ? 'png' : 'jpeg')));

        if ($format === 'jpg') {
            $format = 'jpeg';
        }

        if (! in_array($format, self::IMAGE_FORMATS, true)) {
            $format = $defaults['format'];
        }

        $background = $option['background'] ?? null;
        $transparent = $background === null
            && strtolower((string) ($option['transparent'] ?? 'true')) !== 'false'
            && in_array($format, self::TRANSPARENT_FORMATS, true);

        $allowed = is_array($option['allowed_formats'] ?? null) ? $option['allowed_formats'] : $defaults['allowed_formats'];

        return [
            'width' => max(1, (int) ($option['width'] ?? $defaults['width'])),
            'height' => max(1, (int) ($option['height'] ?? $defaults['height'])),
            'mode' => in_array($option['mode'] ?? $defaults['mode'], ['cover', 'contain'], true)
                ? ($option['mode'] ?? $defaults['mode'])
                : $defaults['mode'],
            'format' => $format,
            'transparent' => $transparent,
            'background' => $background,
            'max_kb' => max(1, (int) ($option['max_kb'] ?? $defaults['max_kb'])),
            'allowed_formats' => array_values(array_filter(
                array_map(fn ($item) => strtolower(trim((string) $item)), $allowed),
                fn ($item) => isset(self::IMAGE_MIMES[$item]),
            )) ?: $defaults['allowed_formats'],
        ];
    }

    public function getImageSpecAttribute(): array
    {
        return $this->imageSpec();
    }
}
