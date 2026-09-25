<?php

declare(strict_types=1);

// Tests for src/FaviconBuilder.php: packs the CV photo into a small
// multi-resolution .ico, cached on disk. Loaded by tests/run.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/FaviconBuilder.php';

use Cv\FaviconBuilder;

// ---- Helpers ---------------------------------------------------------------

/** A fresh throwaway directory under the shared test tmp dir. */
function faviconTestDir(): string
{
    $dir = testTmpDir() . '/favicon-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);

    return $dir;
}

/** Writes a small solid-colour square PNG to $path (a stand-in photo). */
function faviconTestPhoto(string $path, int $size = 40): void
{
    $im = imagecreatetruecolor($size, $size);
    $red = imagecolorallocate($im, 200, 30, 30);
    imagefill($im, 0, 0, $red);
    imagepng($im, $path);
    imagedestroy($im);
}

// ---- Building the ICO -------------------------------------------------------

test('FaviconBuilder: builds a valid ICO (ICONDIR header) from a square photo', static function (): void {
    $dir = faviconTestDir();
    $photo = $dir . '/photo.png';
    faviconTestPhoto($photo);

    $icoPath = FaviconBuilder::buildCached($photo, $dir . '/cache/favicon.ico');

    assertTrue($icoPath !== null, 'Expected a built favicon path.');
    assertTrue(is_file((string) $icoPath), 'Expected the ICO file to exist on disk.');

    $bytes = (string) file_get_contents((string) $icoPath);
    // ICONDIR: reserved=0 (2 bytes), type=1/icon (2 bytes), count=3 (2 bytes).
    assertSame("\x00\x00\x01\x00\x03\x00", substr($bytes, 0, 6), 'ICONDIR header.');
});

test('FaviconBuilder: embeds 48x48, 32x32 and 16x16 frames', static function (): void {
    $dir = faviconTestDir();
    $photo = $dir . '/photo.png';
    faviconTestPhoto($photo);

    $icoPath = (string) FaviconBuilder::buildCached($photo, $dir . '/cache/favicon.ico');
    $bytes = (string) file_get_contents($icoPath);

    // Each 16-byte ICONDIRENTRY starts right after the 6-byte ICONDIR header;
    // its first byte is the frame's width (0 conventionally means 256px).
    $widths = [];
    for ($i = 0; $i < 3; $i++) {
        $widths[] = ord($bytes[6 + $i * 16]);
    }
    sort($widths);
    assertSame([16, 32, 48], $widths);
});

test('FaviconBuilder: each embedded frame is itself a valid PNG', static function (): void {
    $dir = faviconTestDir();
    $photo = $dir . '/photo.png';
    faviconTestPhoto($photo);

    $icoPath = (string) FaviconBuilder::buildCached($photo, $dir . '/cache/favicon.ico');
    $bytes = (string) file_get_contents($icoPath);
    $pngSignature = "\x89PNG\r\n\x1a\n";

    // 3 frames -> the PNG signature should appear 3 times in the file.
    assertSame(3, substr_count($bytes, $pngSignature), 'Expected 3 embedded PNG frames.');
});

// ---- Caching -----------------------------------------------------------------

test('FaviconBuilder: a cache newer than the photo is reused as-is', static function (): void {
    $dir = faviconTestDir();
    $photo = $dir . '/photo.png';
    faviconTestPhoto($photo);
    touch($photo, 1_000_000);
    $cachePath = $dir . '/cache/favicon.ico';

    $first = (string) FaviconBuilder::buildCached($photo, $cachePath);
    touch($first, 2_000_000);
    $firstBytes = (string) file_get_contents($first);

    $second = (string) FaviconBuilder::buildCached($photo, $cachePath);

    assertSame($firstBytes, (string) file_get_contents($second), 'Expected byte-identical output (no rebuild).');
    assertSame(2_000_000, filemtime($second), 'Expected the cache mtime to be left untouched.');
});

test('FaviconBuilder: a photo newer than the cache triggers a rebuild', static function (): void {
    $dir = faviconTestDir();
    $photo = $dir . '/photo.png';
    faviconTestPhoto($photo);
    touch($photo, 1_000_000);
    $cachePath = $dir . '/cache/favicon.ico';

    FaviconBuilder::buildCached($photo, $cachePath);
    touch($cachePath, 2_000_000);

    touch($photo, 3_000_000);
    FaviconBuilder::buildCached($photo, $cachePath);

    assertTrue(filemtime($cachePath) !== 2_000_000, 'Expected the cache to be rebuilt once the photo is newer.');
});

// ---- Graceful failure ---------------------------------------------------------

test('FaviconBuilder: returns null for a missing photo', static function (): void {
    $dir = faviconTestDir();

    $result = FaviconBuilder::buildCached($dir . '/does-not-exist.png', $dir . '/cache/favicon.ico');

    assertTrue($result === null);
});

test('FaviconBuilder: returns null for a file that is not a valid image', static function (): void {
    $dir = faviconTestDir();
    $notAnImage = $dir . '/not-an-image.png';
    file_put_contents($notAnImage, 'this is definitely not a PNG');

    $result = FaviconBuilder::buildCached($notAnImage, $dir . '/cache/favicon.ico');

    assertTrue($result === null);
});
