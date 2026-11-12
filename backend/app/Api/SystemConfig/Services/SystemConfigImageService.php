<?php

/* *****************************************
File: SystemConfigImageService.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: cfg_type=image 的圖片上傳與裁切，見 README 17.5。
***************************************** */

namespace App\Api\SystemConfig\Services;

use App\Api\SystemConfig\Models\SystemConfig;
use App\Support\File\PhotoClass;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

// cfg_type=image 的圖片上傳與裁切，見 README 17.5。刻意只服務
// system_configs 自己的 image 欄位（例如站徽），不是重建 10.1 設計的
// 完整通用檔案管理模組（那個範圍更大，尚無第二個具體需求，見 6.10.7
// 「有具體需求才擴展」同一個原則）——但底層像素運算共用同一個可複用的
// App\Support\File\PhotoClass，之後若有其他模組需要上傳/縮圖，直接複用
// PhotoClass，不用複製這個 Service 裡跟 system_configs 綁定的部分。
//
// 原圖存在私有磁碟（'local'，見 config/filesystems.php，落在
// storage/app/private/uploads/system-config/），只給「編輯圖片」重新裁切
// 用，不對外公開；縮圖／裁切後的成品存在公開磁碟（'public'，落在
// public/uploads/system-config/，見 config/filesystems.php 已移除
// storage:link 的設定說明），檔名帶時間戳記，每次重新產生都是新檔名並
// 清掉舊檔，瀏覽器不會因為快取到舊檔名而看到舊圖。
class SystemConfigImageService
{
    private const ORIGINAL_DISK = 'local';

    private const PUBLIC_DISK = 'public';

    private const DIR = 'system-config';

    // 上傳原圖並依這個設定項的 cfg_option（見 spec）自動產生縮圖，
    // 直接更新 cfg_value 指向新產生的公開圖片網址。
    public function upload(SystemConfig $config, UploadedFile $file): SystemConfig
    {
        $this->assertImageType($config);
        $spec = $this->spec($config);
        $this->assertValidUpload($file, $spec);

        $binary = file_get_contents($file->getRealPath());
        $source = PhotoClass::createFromString($binary);

        $ext = strtolower($file->getClientOriginalExtension()) ?: 'png';
        $this->replaceOriginal($config, $ext, $binary);

        $thumbnail = PhotoClass::makeThumbnail(
            $source,
            $spec['width'],
            $spec['height'],
            $spec['mode'],
            $spec['background'],
        );

        return $this->savePublicImage($config, $thumbnail, $spec);
    }

    // 依前端裁切對話框算好的參數重新產生圖片（「編輯圖片」流程）。整組參數
    // 都從前端來，因為使用者在對話框裡可以：縮放、改變框選範圍（可以拉出
    // 原圖邊界＝擴圖）、決定框內要填滿輸出還是完整保留＋補背景、選背景
    // 顏色或透明、以及輸出格式（見 README 17.5、ImageCropDialog.vue）。
    // $params：
    //   x/y/width/height：裁切區域（原圖像素座標；可超出原圖範圍，超出的部分
    //     由背景／透明補齊，即「擴圖」）。
    //   output_width/output_height：輸出尺寸，沒帶＝用設定項規格。
    //   mode：cover（填滿輸出，可能裁掉框外內容）／contain（完整保留框選範圍＋補背景）。
    //   background：null／transparent＝透明，色碼字串＝填該色。
    //   format：png／jpeg／webp／gif，沒帶＝用設定項規格（預設 png）。
    public function crop(SystemConfig $config, array $params): SystemConfig
    {
        $this->assertImageType($config);

        $originalPath = $this->findOriginalPath($config);

        if (! $originalPath) {
            throw new RuntimeException(__('api.system_config.image_no_original'));
        }

        $spec = $this->spec($config, $params);
        $source = PhotoClass::createFromString(Storage::disk(self::ORIGINAL_DISK)->get($originalPath));

        $cropped = $this->extractSelection($source, $params);

        $image = $spec['mode'] === 'contain'
            ? PhotoClass::padToAspectRatio($cropped, $spec['width'], $spec['height'], $spec['background'])
            : PhotoClass::resize($cropped, $spec['width'], $spec['height']);

        return $this->savePublicImage($config, $image, $spec);
    }

    // 供「編輯圖片」對話框載入原圖預覽用，回傳 [二進位內容, mime type]。
    public function originalContent(SystemConfig $config): array
    {
        $this->assertImageType($config);

        $path = $this->findOriginalPath($config);

        if (! $path) {
            throw new RuntimeException(__('api.system_config.image_no_original'));
        }

        return [Storage::disk(self::ORIGINAL_DISK)->get($path), Storage::disk(self::ORIGINAL_DISK)->mimeType($path)];
    }

    private function assertImageType(SystemConfig $config): void
    {
        if ($config->cfg_type !== 'image') {
            throw new RuntimeException(__('api.system_config.not_image_type', ['name' => $config->cfg_name]));
        }
    }

    private function assertValidUpload(UploadedFile $file, array $spec = []): void
    {
        $spec = $spec ?: SystemConfig::defaultImageSpec();

        if (! in_array($file->getMimeType(), $spec['allowed_mimes'], true)) {
            throw new RuntimeException(__('api.system_config.image_mime_not_allowed'));
        }

        if ($file->getSize() > $spec['max_kb'] * 1024) {
            throw new RuntimeException(__('api.system_config.image_too_large', ['max' => $spec['max_kb']]));
        }
    }

    // 解析這個設定項的圖片規格，見 README 17.5。前端裁切時可以覆寫其中幾項
    // （輸出尺寸／模式／背景／格式），因為那些是「這次裁切」的選擇，不一定
    // 等於設定項的預設規格（例如規格是透明 PNG，但這次想補白底輸出）。
    // 沒設定、格式異常或值不合法時一律回退預設值——上傳/裁切流程不該因為
    // 一個打錯的設定值整組壞掉。max_kb／allowed_formats 只從設定項來。
    private function spec(SystemConfig $config, array $override = []): array
    {
        $option = is_array($config->cfg_option) ? $config->cfg_option : [];
        $defaults = SystemConfig::defaultImageSpec();

        $format = $this->resolveFormat(
            $override['format']
            ?? $option['format']
            ?? ($option['transparent'] ?? true ? 'png' : 'jpeg')
        );

        $background = $this->resolveBackground(
            array_key_exists('background', $override) && $override['background'] !== null
                ? $override['background']
                : ($option['background'] ?? null),
            $format,
        );

        return [
            // 輸出尺寸只認 output_width／output_height（前端裁切對話框的「輸出
            // 尺寸」欄位），沒帶才回退設定項規格——**不能**拿 x/y/width/height
            // 裡的 width/height 當輸出尺寸，那是「框選範圍」的尺寸，兩者混用會
            // 讓輸出變成框選大小（實測踩過：裁切後得到 800×533 而不是規格的
            // 300×200）。
            'width' => max(1, (int) ($override['output_width'] ?? $option['width'] ?? $defaults['width'])),
            'height' => max(1, (int) ($override['output_height'] ?? $option['height'] ?? $defaults['height'])),
            'mode' => in_array($override['mode'] ?? $option['mode'] ?? $defaults['mode'], ['cover', 'contain'], true)
                ? ($override['mode'] ?? $option['mode'] ?? $defaults['mode'])
                : $defaults['mode'],
            'format' => $format,
            // 見 resolveBackground：不支援透明的格式一定有色碼，反之色碼為
            // null（含 'transparent'）才代表補透明背景。
            'background' => $background,
            'transparent' => $background === null,
            'max_kb' => max(1, (int) ($option['max_kb'] ?? $defaults['max_kb'])),
            'allowed_mimes' => $this->resolveAllowedMimes($option['allowed_formats'] ?? $defaults['allowed_formats']),
        ];
    }

    // 只接受支援的輸出格式，其餘（含打錯字）一律回退 PNG（透明、不失真，
    // 是圖片上傳最安全的預設）。'jpg' 正規化成 'jpeg'，方便後續比對。
    private function resolveFormat(string $format): string
    {
        $format = strtolower(trim($format));

        if ($format === 'jpg') {
            $format = 'jpeg';
        }

        return in_array($format, SystemConfig::IMAGE_FORMATS, true) ? $format : 'png';
    }

    // 不支援透明的格式（jpeg／gif）一律回傳色碼（預設白底），讓「透明」這個
    // 選項在這些格式下自動失效，而不是產出一張背景變黑的圖；支援透明的格式
    // 則接受 null／'transparent'／空字串代表透明。
    private function resolveBackground(?string $background, string $format): ?string
    {
        $background = is_string($background) ? trim($background) : null;

        if (! in_array($format, SystemConfig::TRANSPARENT_FORMATS, true)) {
            return $this->isValidHexColor($background) ? $background : '#ffffff';
        }

        if ($background === null || $background === '' || strtolower($background) === 'transparent') {
            return null;
        }

        return $this->isValidHexColor($background) ? $background : null;
    }

    private function isValidHexColor(?string $color): bool
    {
        return is_string($color) && (bool) preg_match('/^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?([0-9a-fA-F]{2})?$/', trim($color));
    }

    // allowed_formats 是「允許上傳的來源格式」清單（副檔名），非輸出格式；
    // 空陣列／格式異常時回退成全開，避免一個沒設定好的欄位讓上傳直接不能用。
    private function resolveAllowedMimes(mixed $formats): array
    {
        if (! is_array($formats)) {
            $formats = SystemConfig::defaultImageSpec()['allowed_formats'];
        }

        $mimes = [];

        foreach ($formats as $format) {
            $mime = SystemConfig::IMAGE_MIMES[strtolower(trim((string) $format))] ?? null;

            if ($mime) {
                $mimes[] = $mime;
            }
        }

        return $mimes ?: array_values(SystemConfig::IMAGE_MIMES);
    }

    // 依 x/y/width/height 取出框選範圍（原圖像素座標，可超出原圖邊界），
    // 超出邊界的部分用背景色或透明補齊——這就是「擴圖添加背景」的後端實作
    // （見 README 17.5）。框選完全落在原圖外時，回傳整塊純背景/透明。
    private function extractSelection(GdImage $source, array $params): GdImage
    {
        $x = (int) $params['x'];
        $y = (int) $params['y'];
        $width = max(1, (int) $params['width']);
        $height = max(1, (int) $params['height']);
        $background = $params['background'] ?? null;

        return PhotoClass::expandCrop($source, $x, $y, $width, $height, $this->normalizeBackground($background));
    }

    private function normalizeBackground(mixed $background): ?string
    {
        if (! is_string($background) || trim($background) === '' || strtolower(trim($background)) === 'transparent') {
            return null;
        }

        return $this->isValidHexColor($background) ? trim($background) : null;
    }

    // 同一個設定項只保留一份原圖：副檔名可能因為重新上傳而改變（例如
    // 第一次傳 jpg、第二次傳 png），先清掉舊的再存新的，避免同一個 id
    // 底下累積多份不同副檔名的原圖孤兒檔案。
    private function replaceOriginal(SystemConfig $config, string $ext, string $binary): void
    {
        $existing = Storage::disk(self::ORIGINAL_DISK)->files(self::DIR);

        foreach ($existing as $path) {
            if (str_starts_with(basename($path), "{$config->id}-original.")) {
                Storage::disk(self::ORIGINAL_DISK)->delete($path);
            }
        }

        Storage::disk(self::ORIGINAL_DISK)->put(self::DIR."/{$config->id}-original.{$ext}", $binary);
    }

    private function findOriginalPath(SystemConfig $config): ?string
    {
        foreach (Storage::disk(self::ORIGINAL_DISK)->files(self::DIR) as $path) {
            if (str_starts_with(basename($path), "{$config->id}-original.")) {
                return $path;
            }
        }

        return null;
    }

    // 公開圖片檔名帶時間戳記（見類別開頭說明），副檔名依輸出格式決定；
    // 重新產生後要主動清掉舊檔（可能是別的副檔名），不然每次上傳/裁切都會
    // 多一份孤兒檔案。
    private function savePublicImage(SystemConfig $config, GdImage $image, array $spec): SystemConfig
    {
        $this->deleteOldPublicFile($config);

        $extension = SystemConfig::IMAGE_EXTENSIONS[$spec['format']] ?? 'png';
        $path = self::DIR."/{$config->id}-".time().".{$extension}";

        Storage::disk(self::PUBLIC_DISK)->put($path, PhotoClass::encode($image, $spec['format']));

        $config->update(['cfg_value' => Storage::disk(self::PUBLIC_DISK)->url($path)]);

        return $config;
    }

    // 見類別開頭說明：公開圖片檔名帶時間戳記，重新產生後要主動清掉舊檔，
    // 不然每次上傳/裁切都會多一份孤兒縮圖檔案。只刪掉「看起來是我們自己
    // 管理的路徑」（在 self::DIR 底下），避免 cfg_value 被手動改成別的
    // 網址時誤刪不相干的檔案。
    private function deleteOldPublicFile(SystemConfig $config): void
    {
        $oldValue = (string) ($config->cfg_value ?? '');
        $publicUrlPrefix = Storage::disk(self::PUBLIC_DISK)->url('');

        if ($oldValue === '' || ! str_starts_with($oldValue, $publicUrlPrefix.self::DIR.'/')) {
            return;
        }

        $oldPath = substr($oldValue, strlen($publicUrlPrefix));

        if (Storage::disk(self::PUBLIC_DISK)->exists($oldPath)) {
            Storage::disk(self::PUBLIC_DISK)->delete($oldPath);
        }
    }
}
