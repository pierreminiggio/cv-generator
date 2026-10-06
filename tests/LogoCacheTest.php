<?php

declare(strict_types=1);

// Tests for src/LogoCache.php: downloads and locally caches remote logo
// images, or passes an already-local path through as-is. Loaded by
// tests/run.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/LogoCache.php';

use Cv\LogoCache;

// ---- Helpers ---------------------------------------------------------------

function logoCacheTestDir(): string
{
    $dir = testTmpDir() . '/logocache-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);

    return $dir;
}

/**
 * Starts PHP's built-in web server on 127.0.0.1 serving $docRoot, and
 * blocks until it actually answers requests (or gives up). This is a
 * *local* loopback server only - it never leaves the machine, so it stays
 * within the "no network access" spirit of the test suite while still
 * exercising LogoCache's real HTTP download path for real.
 *
 * @return array{0: resource, 1: int} [process handle, port]
 */
function logoCacheStartServer(string $docRoot): array
{
    $port = 8900 + random_int(0, 500);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open(['php', '-S', "127.0.0.1:{$port}", '-t', $docRoot], $descriptors, $pipes);

    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start the local test HTTP server.');
    }

    for ($i = 0; $i < 60; $i++) {
        $ch = curl_init("http://127.0.0.1:{$port}/");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 100);
        $ok = curl_exec($ch) !== false;
        curl_close($ch);
        if ($ok) {
            return [$proc, $port];
        }
        usleep(50_000);
    }

    proc_terminate($proc);
    throw new RuntimeException('The local test HTTP server never became ready.');
}

/** @param resource $proc */
function logoCacheStopServer($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

// ---- Local (non-http) paths --------------------------------------------------

test('LogoCache: an already-local existing file is returned as-is', static function (): void {
    $dir = logoCacheTestDir();
    $localLogo = $dir . '/logo.png';
    file_put_contents($localLogo, 'not really a png, just needs to exist');

    $cache = new LogoCache($dir . '/cache');

    assertSame($localLogo, $cache->resolve($localLogo));
});

test('LogoCache: a local path that does not exist resolves to null', static function (): void {
    $dir = logoCacheTestDir();
    $cache = new LogoCache($dir . '/cache');

    assertTrue($cache->resolve($dir . '/does-not-exist.png') === null);
});

test('LogoCache: null/empty URL resolves to null', static function (): void {
    $cache = new LogoCache(logoCacheTestDir() . '/cache');

    assertTrue($cache->resolve(null) === null);
    assertTrue($cache->resolve('') === null);
});

// ---- Remote downloads ----------------------------------------------------------

test('LogoCache: an unreachable URL resolves to null instead of hanging/throwing', static function (): void {
    $cache = new LogoCache(logoCacheTestDir() . '/cache');

    assertTrue($cache->resolve('http://127.0.0.1:9/logo.png') === null);
});

test('LogoCache: a real download over HTTP is cached to disk with matching content', static function (): void {
    $docRoot = logoCacheTestDir();
    file_put_contents($docRoot . '/logo.png', 'FAKE-PNG-BYTES-FOR-TEST');
    [$proc, $port] = logoCacheStartServer($docRoot);

    try {
        $cache = new LogoCache($docRoot . '/cache');
        $path = $cache->resolve("http://127.0.0.1:{$port}/logo.png");

        assertTrue($path !== null, 'Expected a resolved local path.');
        assertTrue(is_file((string) $path));
        assertSame('FAKE-PNG-BYTES-FOR-TEST', file_get_contents((string) $path));
    } finally {
        logoCacheStopServer($proc);
    }
});

test('LogoCache: the cached filename keeps the URL\'s original extension', static function (): void {
    $docRoot = logoCacheTestDir();
    file_put_contents($docRoot . '/photo.jpg', 'FAKE-JPG-BYTES');
    [$proc, $port] = logoCacheStartServer($docRoot);

    try {
        $cache = new LogoCache($docRoot . '/cache');
        $path = (string) $cache->resolve("http://127.0.0.1:{$port}/photo.jpg");

        assertSame('jpg', pathinfo($path, PATHINFO_EXTENSION));
    } finally {
        logoCacheStopServer($proc);
    }
});

test('LogoCache: an unrecognised/missing extension defaults to .png', static function (): void {
    $docRoot = logoCacheTestDir();
    file_put_contents($docRoot . '/asset', 'FAKE-BYTES-NO-EXTENSION');
    [$proc, $port] = logoCacheStartServer($docRoot);

    try {
        $cache = new LogoCache($docRoot . '/cache');
        $path = (string) $cache->resolve("http://127.0.0.1:{$port}/asset");

        assertSame('png', pathinfo($path, PATHINFO_EXTENSION));
    } finally {
        logoCacheStopServer($proc);
    }
});

test('LogoCache: a 404 response resolves to null, not an empty cached file', static function (): void {
    $docRoot = logoCacheTestDir();
    [$proc, $port] = logoCacheStartServer($docRoot);

    try {
        $cache = new LogoCache($docRoot . '/cache');
        $path = $cache->resolve("http://127.0.0.1:{$port}/does-not-exist.png");

        assertTrue($path === null);
    } finally {
        logoCacheStopServer($proc);
    }
});

// ---- Cache hits avoid the network entirely -------------------------------------

test('LogoCache: a pre-existing cached file is reused without contacting the network', static function (): void {
    $dir = logoCacheTestDir();
    $cacheDir = $dir . '/cache';
    mkdir($cacheDir, 0775, true);

    // Deliberately unreachable URL: if resolve() tried to download it,
    // it would fail and return null. Pre-seed the cache file it *would*
    // have written, under the same sha1(url)+ext naming resolve() uses.
    $url = 'http://127.0.0.1:9/logo.png';
    $expectedFilename = sha1($url) . '.png';
    file_put_contents($cacheDir . '/' . $expectedFilename, 'PRE-SEEDED-CACHED-CONTENT');

    $cache = new LogoCache($cacheDir);
    $path = $cache->resolve($url);

    assertTrue($path !== null, 'Expected the cached file to be used instead of failing the (unreachable) download.');
    assertSame('PRE-SEEDED-CACHED-CONTENT', file_get_contents((string) $path));
});
