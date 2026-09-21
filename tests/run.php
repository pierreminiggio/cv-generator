<?php

declare(strict_types=1);

// Minimal, dependency-free test runner (the project deliberately has no
// Composer / PHPUnit). Usage, from the project root:
//
//     php tests/run.php
//
// Loads every tests/*Test.php file, runs each test() it registers, prints
// one PASS/FAIL line per test and exits with a non-zero status if any test
// failed. Tests are hermetic: no network access, and they never read the
// private cv.php.

// CLI only - .htaccess lets existing files through untouched, and tests
// must never be runnable from a browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** @var array<int, array{0: string, 1: callable}> */
$GLOBALS['cvTests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['cvTests'][] = [$name, $fn];
}

function assertTrue(bool $condition, string $message = 'Expected the condition to be true.'): void
{
    if (!$condition) {
        throw new AssertionError($message);
    }
}

function assertSame($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionError(sprintf(
            '%sExpected %s, got %s.',
            $message !== '' ? $message . ' ' : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionError(sprintf(
            '%sExpected to find %s in the output, but it is not there.',
            $message !== '' ? $message . ' ' : '',
            var_export($needle, true)
        ));
    }
}

function assertNotContains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionError(sprintf(
            '%sDid not expect to find %s in the output.',
            $message !== '' ? $message . ' ' : '',
            var_export($needle, true)
        ));
    }
}

/** Passes only if $fn throws a RuntimeException whose message contains $messageFragment. */
function assertThrowsRuntime(callable $fn, string $messageFragment): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        assertContains($messageFragment, $e->getMessage(), 'RuntimeException message check:');

        return;
    }

    throw new AssertionError(sprintf('Expected a RuntimeException containing %s, but nothing was thrown.', var_export($messageFragment, true)));
}

/** A scratch directory shared by the whole run, deleted when the run ends. */
function testTmpDir(): string
{
    static $dir = null;

    if ($dir === null) {
        $dir = sys_get_temp_dir() . '/cv-generator-tests-' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);

        $created = $dir;
        register_shutdown_function(static function () use ($created): void {
            removeTree($created);
        });
    }

    return $dir;
}

function removeTree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $file) {
    require $file;
}

$passed = 0;
$failed = 0;

foreach ($GLOBALS['cvTests'] as [$name, $fn]) {
    try {
        $fn();
        echo "  PASS  {$name}\n";
        $passed++;
    } catch (Throwable $e) {
        echo "  FAIL  {$name}\n        " . str_replace("\n", "\n        ", $e->getMessage()) . "\n";
        $failed++;
    }
}

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
