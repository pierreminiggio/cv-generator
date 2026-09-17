<?php

declare(strict_types=1);

namespace Cv;

/**
 * Downloads the semantic-ui-flag sprite sheet (flags.png) once, and crops
 * out individual country flags from it on demand, caching each one as its
 * own small PNG so later requests don't need GD to re-crop anything.
 *
 * The crop coordinates below are taken directly from the upstream
 * semantic-ui-flag stylesheet (flag.min.css, Semantic UI 2.5.0 - MIT
 * licensed: https://github.com/Semantic-Org/UI-Flag), which defines each
 * flag as a 16x11px `background-position` offset into flags.png. We only
 * need the handful of codes actually used in the CV.
 *
 * If the sprite can't be downloaded, or GD isn't available, resolve()
 * simply returns null and the caller falls back to a plain badge -
 * exactly like LogoCache does for company logos.
 */
final class FlagSpriteCache
{
    private const ICON_W = 16;
    private const ICON_H = 11;

    /**
     * [x, y] top-left offset (in px) of each flag within the sprite sheet,
     * i.e. the absolute value of that flag's CSS `background-position`.
     * Extend this table with more codes/offsets from flag.min.css if you
     * add more languages later.
     */
    private const POSITIONS = [
        'FR' => [0, 1976],  // i.flag.fr / i.flag.france
        'US' => [72, 1950], // i.flag.us / i.flag.america / i.flag.united.states
        'ES' => [0, 1742],  // i.flag.es / i.flag.spain
        'CN' => [0, 1196],  // i.flag.cn / i.flag.china
        'KE' => [36, 936],  // i.flag.ke / i.flag.kenya
    ];

    private string $spriteUrl;
    private string $cacheDir;

    public function __construct(string $spriteUrl, string $cacheDir)
    {
        $this->spriteUrl = $spriteUrl;
        $this->cacheDir = rtrim($cacheDir, '/');

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0775, true);
        }
    }

    /**
     * Returns a local filesystem path to a small PNG containing just the
     * requested flag, or null if it isn't available for any reason.
     */
    public function resolve(string $code): ?string
    {
        $code = strtoupper($code);

        if (!isset(self::POSITIONS[$code]) || !function_exists('imagecreatefrompng')) {
            return null;
        }

        $cropPath = $this->cacheDir . '/' . strtolower($code) . '.png';

        if (is_file($cropPath) && filesize($cropPath) > 0) {
            return $cropPath;
        }

        $sheetPath = $this->downloadSheet();

        if ($sheetPath === null) {
            return null;
        }

        return $this->crop($sheetPath, $code, $cropPath) ? $cropPath : null;
    }

    private function downloadSheet(): ?string
    {
        $sheetPath = $this->cacheDir . '/flags-sheet.png';

        if (is_file($sheetPath) && filesize($sheetPath) > 0) {
            return $sheetPath;
        }

        $body = null;

        if (function_exists('curl_init')) {
            $ch = curl_init($this->spriteUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'cv-generator/1.0',
            ]);
            $result = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($result !== false && $error === '' && $status >= 200 && $status < 300) {
                $body = $result;
            }
        } else {
            $context = stream_context_create([
                'http' => ['timeout' => 10, 'follow_location' => 1, 'ignore_errors' => true],
                'https' => ['timeout' => 10],
            ]);
            $result = @file_get_contents($this->spriteUrl, false, $context);
            $body = $result === false ? null : $result;
        }

        if ($body === null) {
            return null;
        }

        $tmpPath = $sheetPath . '.tmp-' . getmypid();
        if (@file_put_contents($tmpPath, $body) === false) {
            return null;
        }
        @rename($tmpPath, $sheetPath);

        return is_file($sheetPath) ? $sheetPath : null;
    }

    private function crop(string $sheetPath, string $code, string $destPath): bool
    {
        [$srcX, $srcY] = self::POSITIONS[$code];

        $sheet = @imagecreatefrompng($sheetPath);
        if ($sheet === false) {
            return false;
        }

        $crop = imagecreatetruecolor(self::ICON_W, self::ICON_H);
        if ($crop === false) {
            imagedestroy($sheet);
            return false;
        }

        imagecopy($crop, $sheet, 0, 0, $srcX, $srcY, self::ICON_W, self::ICON_H);

        $tmpPath = $destPath . '.tmp-' . getmypid();
        $ok = imagepng($crop, $tmpPath);

        imagedestroy($sheet);
        imagedestroy($crop);

        if (!$ok) {
            return false;
        }

        @rename($tmpPath, $destPath);

        return is_file($destPath);
    }
}
