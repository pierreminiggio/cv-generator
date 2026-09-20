<?php

declare(strict_types=1);

namespace Cv;

/**
 * Builds a small, multi-resolution .ico file (the format browsers request
 * at GET /favicon.ico) from the CV's own photo, so a browser tab showing
 * the generated CV PDF displays that picture instead of a generic PDF
 * icon. Results are cached on disk and only rebuilt when the source photo
 * file changes.
 */
final class FaviconBuilder
{
    /** Icon sizes (px) embedded in the .ico, largest first. */
    private const SIZES = [48, 32, 16];

    /**
     * Returns a filesystem path to a ready-to-serve .ico file, (re)building
     * it from $photoPath first if needed, or null if that isn't possible
     * (missing photo, GD unavailable, unwritable cache, ...).
     */
    public static function buildCached(string $photoPath, string $cachePath): ?string
    {
        if (!is_file($photoPath) || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        if (is_file($cachePath) && filemtime($cachePath) >= filemtime($photoPath)) {
            return $cachePath;
        }

        $ico = self::build($photoPath);

        if ($ico === null) {
            return null;
        }

        $tmpPath = $cachePath . '.tmp-' . getmypid();

        if (@file_put_contents($tmpPath, $ico) === false) {
            return null;
        }

        @rename($tmpPath, $cachePath);

        return is_file($cachePath) ? $cachePath : null;
    }

    private static function build(string $photoPath): ?string
    {
        $raw = @file_get_contents($photoPath);

        if ($raw === false) {
            return null;
        }

        $src = @imagecreatefromstring($raw);

        if ($src === false) {
            return null;
        }

        $frames = [];
        foreach (self::SIZES as $size) {
            $frames[] = ['size' => $size, 'data' => self::squareThumbnailPng($src, $size)];
        }

        imagedestroy($src);

        return self::packIco($frames);
    }

    /**
     * Centre-crops $src to a square and resamples it down to a small PNG,
     * exactly like the header photo box: same picture, just tiny.
     *
     * @param \GdImage|resource $src
     */
    private static function squareThumbnailPng($src, int $size): string
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $cropX = (int) (($w - $side) / 2);
        $cropY = (int) (($h - $side) / 2);

        $dst = imagecreatetruecolor($size, $size);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagecopyresampled($dst, $src, 0, 0, $cropX, $cropY, $size, $size, $side, $side);

        ob_start();
        imagepng($dst);
        $data = (string) ob_get_clean();
        imagedestroy($dst);

        return $data;
    }

    /**
     * Packs one or more PNG images into a single .ico container (the
     * modern "PNG-in-ICO" format supported by every current browser/OS).
     *
     * @param array<int,array{size:int,data:string}> $frames
     */
    private static function packIco(array $frames): string
    {
        $count = count($frames);
        $header = pack('vvv', 0, 1, $count); // reserved=0, type=1 (icon), image count
        $offset = 6 + 16 * $count; // ICONDIR + one ICONDIRENTRY per frame
        $dir = '';
        $body = '';

        foreach ($frames as $frame) {
            $dim = $frame['size'] >= 256 ? 0 : $frame['size']; // 0 means "256px" in the ICO format
            $len = strlen($frame['data']);
            $dir .= pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, $len, $offset);
            $body .= $frame['data'];
            $offset += $len;
        }

        return $header . $dir . $body;
    }
}
