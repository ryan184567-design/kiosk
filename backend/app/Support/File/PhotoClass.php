<?php

/* *****************************************
File: PhotoClass.php
Author: cliffdean yangtinghui@gmail.com
Date: 2026-09-13
Description: 純 GD 像素操作工具，見 README 10.1 第 5 點、17.5。
***************************************** */

namespace App\Support\File;

use GdImage;
use RuntimeException;

// 純 GD 像素操作工具，見 README 10.1 第 5 點、17.5。刻意只操作記憶體中的
// GdImage／二進位字串，不碰檔案系統路徑——磁碟讀寫交給呼叫端透過
// Illuminate\Support\Facades\Storage 處理（見 SystemConfigImageService），
// 這樣才能吃到 Flysystem 自動建立目錄的能力，不會踩到「GD 直接寫入實體
// 路徑不會自動建目錄」這個已知陷阱（見 README 10.1 註記）。輸出一律轉成
// PNG（支援透明背景，logo 這類圖片常見需求；來源格式不影響輸出格式，
// 呼叫端不用自己判斷副檔名）。
class PhotoClass
{
    public static function createFromString(string $binary): GdImage
    {
        $image = @imagecreatefromstring($binary);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('無法解析圖片內容，可能不是合法的圖片格式');
        }

        return $image;
    }

    // 依 mode 產生固定尺寸的縮圖，供「上傳後自動依設定縮圖」使用（見
    // App\Api\SystemConfig\Services\SystemConfigImageService::upload）：
    //   - cover（預設）：等比縮放到「兩邊都 >= 目標尺寸」後置中裁切滿版，
    //     不留白、可能裁掉部分內容，最接近一般網站 logo/縮圖的直覺效果。
    //   - contain：等比縮放到「兩邊都 <= 目標尺寸」後置中，其餘部分補
    //     $background（色碼）或透明（null），保留完整原圖內容、不裁切。
    public static function makeThumbnail(GdImage $source, int $width, int $height, string $mode = 'cover', ?string $background = null): GdImage
    {
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        return match ($mode) {
            'contain' => self::padToAspectRatio($source, $width, $height, $background),
            default => self::coverResize($source, $srcWidth, $srcHeight, $width, $height),
        };
    }

    // 從來源圖片裁出指定像素區域（不縮放，單純裁切），供「編輯圖片」手動
    // 圈選裁切區域使用——區域座標／尺寸一律是來源圖片本身的原始像素座標
    // （見 README 10.3 第 2 點，前端負責把顯示座標換算回原圖像素座標，
    // 這裡不處理任何顯示縮放）。裁切範圍會被限制在來源圖片邊界內，避免
    // 前端算出界外座標時 GD 補出未定義的黑邊。
    public static function crop(GdImage $source, int $x, int $y, int $width, int $height): GdImage
    {
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);

        $x = max(0, min($x, $srcWidth - 1));
        $y = max(0, min($y, $srcHeight - 1));
        $width = max(1, min($width, $srcWidth - $x));
        $height = max(1, min($height, $srcHeight - $y));

        $dest = self::createCanvas($width, $height);
        imagecopyresampled($dest, $source, 0, 0, $x, $y, $width, $height, $width, $height);

        return $dest;
    }

    // 直接縮放成指定寬高（不維持長寬比），主要供內部 makeThumbnail(stretch)
    // 與「使用者已經圈選好符合比例的區域，裁切後再縮放到目標輸出尺寸」
    // 這兩種情境使用——後者呼叫端本來就該確保裁切區域的長寬比已經符合
    // 目標比例（見前端 ImageCropDialog.vue 的 aspectRatio 鎖定），這裡不重
    // 複檢查比例，單純負責縮放。
    public static function resize(GdImage $source, int $width, int $height): GdImage
    {
        $dest = self::createCanvas($width, $height);
        imagecopyresampled($dest, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $dest;
    }

    public static function toPng(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }

    // 依框選範圍取出圖片，允許框選範圍超出原圖邊界：超出的部分用背景色填滿，
    // 背景色為 null 時填透明——這就是「擴圖添加背景」（見 README 17.5）。
    // 框選範圍完全落在原圖之外時，回傳一整塊背景/透明，不會拋例外（使用者
    // 可能先拉出一個大框再慢慢調整）。
    public static function expandCrop(GdImage $source, int $x, int $y, int $width, int $height, ?string $background = null): GdImage
    {
        $width = max(1, $width);
        $height = max(1, $height);

        $canvas = self::createFilledCanvas($width, $height, $background);

        // 與原圖重疊的區域才需要複製；左/上為負代表框選範圍在原圖左/上方，
        // 對應到畫布內的偏移量就是 -x / -y。
        $destX = max(0, -$x);
        $destY = max(0, -$y);
        $srcX = max(0, $x);
        $srcY = max(0, $y);
        $copyWidth = min($width - $destX, imagesx($source) - $srcX);
        $copyHeight = min($height - $destY, imagesy($source) - $srcY);

        if ($copyWidth > 0 && $copyHeight > 0) {
            // 透明背景要先開啟 alpha 保留，否則 PNG 來源的透明區域會被填成黑。
            imagealphablending($canvas, true);
            imagesavealpha($canvas, true);
            imagecopy($canvas, $source, $destX, $destY, $srcX, $srcY, $copyWidth, $copyHeight);
        }

        return $canvas;
    }

    // 把圖片等比縮放後置中放進指定尺寸的畫布並補上背景（contain 模式）。
    // 這支與 containResize 的差別只有「可以指定補背景的顏色」（見 17.5：
    // 使用者可以在裁切對話框選擇透明或純色背景）——補背景色的需求不只在
    // 自動縮圖，手動裁切也會用到（擴圖後框選範圍的比例通常不等於輸出比例，
    // 剩下的空間要填什麼由使用者決定）。
    public static function padToAspectRatio(GdImage $source, int $width, int $height, ?string $background = null): GdImage
    {
        $srcWidth = imagesx($source);
        $srcHeight = imagesy($source);
        $scale = min($width / $srcWidth, $height / $srcHeight);
        $scaledWidth = max(1, (int) round($srcWidth * $scale));
        $scaledHeight = max(1, (int) round($srcHeight * $scale));

        $canvas = self::createFilledCanvas($width, $height, $background);

        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);
        imagecopyresampled(
            $canvas,
            $source,
            (int) (($width - $scaledWidth) / 2),
            (int) (($height - $scaledHeight) / 2),
            0,
            0,
            $scaledWidth,
            $scaledHeight,
            $srcWidth,
            $srcHeight,
        );

        return $canvas;
    }

    // 依指定格式輸出編碼（見 README 17.5，設定項可用 cfg_option.format 指定）。
    // PNG／WebP 走無損路徑（quality 不影響畫質）；JPEG 不支援透明，輸出前
    // 先把畫布壓成白底，否則透明區域在 JPEG 會變成黑色方塊。
    public static function encode(GdImage $image, string $format = 'png', int $quality = 90): string
    {
        $quality = max(1, min(100, $quality));

        ob_start();

        match (strtolower($format)) {
            'jpg', 'jpeg' => imagejpeg(self::onOpaqueBackground($image, '#ffffff'), null, $quality),
            'webp' => imagewebp($image, null, $quality),
            'gif' => imagegif(self::onOpaqueBackground($image, '#ffffff')),
            default => imagepng($image),
        };

        return ob_get_clean();
    }

    // 把圖片填上不透明底色後輸出成新畫布，供不支援透明的格式（JPEG／GIF）使用。
    public static function onOpaqueBackground(GdImage $image, string $hexColor = '#ffffff'): GdImage
    {
        $canvas = self::createFilledCanvas(imagesx($image), imagesy($image), $hexColor);
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $canvas;
    }

    // 填色畫布：$hexColor 為 null 時是透明（見 createCanvas），否則填滿指定色，
    // 供「擴圖補背景」使用（見 README 17.5、ImageCropDialog 的補背景選項）。
    public static function createFilledCanvas(int $width, int $height, ?string $hexColor = null): GdImage
    {
        if ($hexColor === null) {
            return self::createCanvas($width, $height);
        }

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, self::allocateHexColor($canvas, $hexColor));

        return $canvas;
    }

    // 把 hex 色碼轉成 GD 的顏色索引。支援 #rgb／#rrggbb／#rrggbbaa（alpha：
    // 00=完全透明、ff=不透明，與 CSS 的 8 位寫法一致）；格式不合法時回退白色，
    // 不讓一個打錯的色碼把整個上傳流程弄掛。
    public static function allocateHexColor(GdImage $image, string $hexColor): int
    {
        $hex = ltrim(trim($hexColor), '#');

        if (preg_match('/^[0-9a-fA-F]{3}$/', $hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (! preg_match('/^[0-9a-fA-F]{6}([0-9a-fA-F]{2})?$/', $hex)) {
            $hex = 'ffffff';
        }

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $a = strlen($hex) === 8 ? hexdec(substr($hex, 6, 2)) : 0;

        return imagecolorallocatealpha($image, $r, $g, $b, (int) round($a / 2));
    }

    public static function width(GdImage $image): int
    {
        return imagesx($image);
    }

    public static function height(GdImage $image): int
    {
        return imagesy($image);
    }

    // 建立支援透明背景的畫布，PNG 輸出才能正確保留透明區域（例如
    // contain 模式留白部分、來源圖片本身帶透明通道的情況）。
    private static function createCanvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);

        return $canvas;
    }

    private static function coverResize(GdImage $source, int $srcWidth, int $srcHeight, int $width, int $height): GdImage
    {
        $scale = max($width / $srcWidth, $height / $srcHeight);
        $scaledWidth = (int) ceil($srcWidth * $scale);
        $scaledHeight = (int) ceil($srcHeight * $scale);

        $scaled = self::createCanvas($scaledWidth, $scaledHeight);
        imagealphablending($scaled, true);
        imagesavealpha($scaled, true);
        imagecopyresampled($scaled, $source, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $srcWidth, $srcHeight);

        $offsetX = (int) (($scaledWidth - $width) / 2);
        $offsetY = (int) (($scaledHeight - $height) / 2);

        return self::crop($scaled, $offsetX, $offsetY, $width, $height);
    }
}
