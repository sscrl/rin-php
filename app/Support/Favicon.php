<?php
declare(strict_types=1);

namespace Rin\Support;

final class Favicon
{
    public const TYPES = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/gif' => '.gif',
        'image/webp' => '.webp',
    ];

    public static function resizeToWebp(string $binary): string
    {
        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new \RuntimeException('Unable to read image');
        }
        $srcW = imagesx($image);
        $srcH = imagesy($image);
        $dst = imagecreatetruecolor(144, 144);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, 144, 144, $transparent);
        $scale = max(144 / max($srcW, 1), 144 / max($srcH, 1));
        $newW = (int) round($srcW * $scale);
        $newH = (int) round($srcH * $scale);
        $x = (int) round((144 - $newW) / 2);
        $y = (int) round((144 - $newH) / 2);
        imagecopyresampled($dst, $image, $x, $y, 0, 0, $newW, $newH, $srcW, $srcH);
        ob_start();
        if (function_exists('imagewebp')) {
            imagewebp($dst, null, 100);
        } else {
            imagepng($dst);
        }
        $out = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($dst);
        return $out;
    }
}
