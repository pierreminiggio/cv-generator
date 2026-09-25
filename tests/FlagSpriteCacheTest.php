<?php

declare(strict_types=1);

// Tests for src/FlagSpriteCache.php: crops individual country flags out of
// the semantic-ui-flag sprite sheet, cached on disk. Loaded by tests/run.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/FlagSpriteCache.php';

use Cv\FlagSpriteCache;

// ---- Helpers ---------------------------------------------------------------

/** A fresh throwaway cache directory under the shared test tmp dir. */
function flagSpriteTestDir(): string
{
    $dir = testTmpDir() . '/flags-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);

    return $dir;
}

/**
 * Builds a synthetic sprite sheet with a distinct solid colour at each of
 * FlagSpriteCache's own documented crop coordinates, and pre-seeds it as
 * the cache's "already downloaded" sheet - so resolve() crops from it
 * without ever touching the network. This directly exercises the same
 * position table the real semantic-ui-flag sprite uses, without needing
 * network access to the real asset.
 *
 * @return array<string, array{0:int,1:int,2:int}> code => [r, g, b] used for that code
 */
function flagSpriteSeedSheet(string $cacheDir): array
{
    // Mirrors FlagSpriteCache::POSITIONS - kept in sync deliberately: if
    // that table changes, this test data must be updated to match, which
    // is exactly the point (it pins down the coordinates as a contract).
    $positions = [
        'FR' => [0, 1976],
        'US' => [72, 1950],
        'ES' => [0, 1742],
        'CN' => [0, 1196],
        'KE' => [36, 936],
    ];
    $colors = [
        'FR' => [10, 20, 220],
        'US' => [220, 10, 10],
        'ES' => [220, 200, 10],
        'CN' => [10, 200, 10],
        'KE' => [200, 10, 200],
    ];

    $sheet = imagecreatetruecolor(130, 2000);
    $white = imagecolorallocate($sheet, 255, 255, 255);
    imagefill($sheet, 0, 0, $white);

    foreach ($positions as $code => [$x, $y]) {
        [$r, $g, $b] = $colors[$code];
        $c = imagecolorallocate($sheet, $r, $g, $b);
        imagefilledrectangle($sheet, $x, $y, $x + 16 - 1, $y + 11 - 1, $c);
    }

    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }
    imagepng($sheet, $cacheDir . '/flags-sheet.png');
    imagedestroy($sheet);

    return $colors;
}

/** @return array{0:int,1:int,2:int} */
function flagSpriteCenterColor(string $pngPath): array
{
    $im = imagecreatefrompng($pngPath);
    $rgb = imagecolorat($im, (int) (imagesx($im) / 2), (int) (imagesy($im) / 2));
    imagedestroy($im);

    return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
}

// ---- Cropping the right region ----------------------------------------------

test('FlagSpriteCache: crops the exact documented region for FR/US/ES/CN/KE', static function (): void {
    $dir = flagSpriteTestDir();
    $colors = flagSpriteSeedSheet($dir);
    $cache = new FlagSpriteCache('http://127.0.0.1:9/flags.png', $dir);

    foreach ($colors as $code => $expectedColor) {
        $path = $cache->resolve($code);

        assertTrue($path !== null, "Expected a resolved path for {$code}.");
        assertSame($expectedColor, flagSpriteCenterColor((string) $path), "Colour mismatch for {$code}.");
    }
});

test('FlagSpriteCache: resolve() is case-insensitive', static function (): void {
    $dir = flagSpriteTestDir();
    $colors = flagSpriteSeedSheet($dir);
    $cache = new FlagSpriteCache('http://127.0.0.1:9/flags.png', $dir);

    $path = $cache->resolve('fr');

    assertTrue($path !== null);
    assertSame($colors['FR'], flagSpriteCenterColor((string) $path));
});

test('FlagSpriteCache: each cropped flag is 16x11px (the sprite\'s native size)', static function (): void {
    $dir = flagSpriteTestDir();
    flagSpriteSeedSheet($dir);
    $cache = new FlagSpriteCache('http://127.0.0.1:9/flags.png', $dir);

    $path = (string) $cache->resolve('FR');
    $im = imagecreatefrompng($path);

    assertSame(16, imagesx($im));
    assertSame(11, imagesy($im));
});

// ---- Fallback / edge cases ---------------------------------------------------

test('FlagSpriteCache: an unknown code resolves to null', static function (): void {
    $dir = flagSpriteTestDir();
    flagSpriteSeedSheet($dir);
    $cache = new FlagSpriteCache('http://127.0.0.1:9/flags.png', $dir);

    assertTrue($cache->resolve('ZZ') === null);
});

test('FlagSpriteCache: an unreachable sprite URL resolves to null instead of hanging/throwing', static function (): void {
    $dir = flagSpriteTestDir();
    // No pre-seeded sheet this time: resolve() must actually attempt (and
    // fail) the download against a closed local port.
    $cache = new FlagSpriteCache('http://127.0.0.1:9/flags.png', $dir);

    assertTrue($cache->resolve('FR') === null);
});

test('FlagSpriteCache: cached crops are reused without needing the sheet again', static function (): void {
    $dir = flagSpriteTestDir();
    flagSpriteSeedSheet($dir);
    $cache = new FlagSpriteCache('http://127.0.0.1:9/flags.png', $dir);

    $first = (string) $cache->resolve('FR');
    assertTrue($first !== '');

    // Remove the sheet: a second resolve() for the same code must still
    // succeed by reusing the already-cropped file, not by re-downloading.
    unlink($dir . '/flags-sheet.png');

    $second = $cache->resolve('FR');
    assertSame($first, $second);
    assertTrue(is_file($second));
});
