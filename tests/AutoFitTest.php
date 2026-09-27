<?php

declare(strict_types=1);

// Tests for CvPdfGenerator's auto-fit engine (fitContent()/stretchColumns()):
// tightens spacing then font size as needed to fit one page, then spreads
// each column's entries across whatever room is left over. Loaded by
// tests/run.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/CvData.php';
require_once dirname(__DIR__) . '/src/LogoCache.php';
require_once dirname(__DIR__) . '/src/FlagSpriteCache.php';
require_once dirname(__DIR__) . '/src/CvPdfGenerator.php';

use Cv\CvPdfGenerator;
use Cv\FlagSpriteCache;
use Cv\LogoCache;

// ---- Helpers ---------------------------------------------------------------

function autoFitExampleData(): array
{
    $data = require dirname(__DIR__) . '/cv_example.php';

    foreach (['experiences', 'education'] as $section) {
        foreach ($data[$section] as $i => $entry) {
            unset($data[$section][$i]['logo']);
        }
    }

    return $data;
}

/** @return array{0: string, 1: CvPdfGenerator} [uncompressed PDF bytes, the built generator] */
function autoFitBuild(array $data): array
{
    $tmp = testTmpDir() . '/autofit-' . bin2hex(random_bytes(4));

    $pdf = new CvPdfGenerator(
        $data,
        new LogoCache($tmp . '/logos'),
        new FlagSpriteCache('http://127.0.0.1:9/flags.png', $tmp . '/flags')
    );
    $pdf->SetCompression(false);
    $pdf->build();

    return [(string) $pdf->Output('S'), $pdf];
}

/** Roughly doubles every did/used/content string's length, to simulate a much longer language. */
function autoFitLengthen($value)
{
    if (is_string($value)) {
        return $value . ' ' . $value;
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = isset($v['text']) ? ['text' => autoFitLengthen($v['text']), 'link' => $v['link'] ?? null] : autoFitLengthen($v);
        }

        return $value;
    }

    return $value;
}

function autoFitTextY(string $bytes, string $text): ?float
{
    $pattern = '/BT [0-9.\-]+ ([0-9.\-]+) Td \(' . preg_quote($text, '/') . '\) Tj/';
    if (preg_match($pattern, $bytes, $m) === 1) {
        return (float) $m[1];
    }

    return null;
}

// ---- Fitting on one page -----------------------------------------------------

test('AutoFit: the example data fits on one page at the base body font size', static function (): void {
    [$bytes, $pdf] = autoFitBuild(autoFitExampleData());

    assertSame(1, $pdf->PageNo());
    assertContains('8.30 Tf', $bytes, 'Expected the base body font size (FS_ENTRY_BODY) to still be in use.');
});

test('AutoFit: much longer text (e.g. a more verbose language) still fits on one page', static function (): void {
    $data = autoFitExampleData();
    foreach (['experiences'] as $section) {
        foreach ($data[$section] as $i => $entry) {
            foreach (['did', 'used'] as $field) {
                if (isset($entry[$field])) {
                    $data[$section][$i][$field] = autoFitLengthen($entry[$field]);
                }
            }
        }
    }
    foreach ($data['education'] as $i => $entry) {
        if (isset($entry['content'])) {
            $data['education'][$i]['content'] = autoFitLengthen($entry['content']);
        }
    }

    [, $pdf] = autoFitBuild($data);

    assertSame(1, $pdf->PageNo());
});

test('AutoFit: content that cannot possibly fit throws a clear RuntimeException', static function (): void {
    $data = autoFitExampleData();
    $data['experiences'] = array_merge($data['experiences'], $data['experiences'], $data['experiences']);

    assertThrowsRuntime(static function () use ($data): void {
        autoFitBuild($data);
    }, 'does not fit on a single A4 page');
});

// ---- Stretch-to-fill ----------------------------------------------------------

test('AutoFit: with little content, entries are spread out with a large gap instead of bunching at the top', static function (): void {
    $data = autoFitExampleData();
    $data['experiences'] = [
        ['title' => 'AAAAFirstEntryTitle', 'did' => 'Short one.', 'used' => 'Short.'],
        ['title' => 'BBBBSecondEntryTitle', 'did' => 'Short two.', 'used' => 'Short.'],
    ];

    [$bytes] = autoFitBuild($data);

    $y1 = autoFitTextY($bytes, 'AAAAFirstEntryTitle');
    $y2 = autoFitTextY($bytes, 'BBBBSecondEntryTitle');

    assertTrue($y1 !== null && $y2 !== null, 'Expected to find both entry titles.');
    // With only two short one-line entries, nearly the whole column height
    // ends up as the gap between them - a plain (un-stretched) tier would
    // never produce anywhere close to this, so a big gap is a reliable
    // signal that the leftover space was actually redistributed.
    assertTrue(($y1 - $y2) > 100, "Expected a large stretched gap, got " . ($y1 - $y2) . "pt.");
});

test('AutoFit: the last experience entry ends up close to the bottom margin when content is short', static function (): void {
    $data = autoFitExampleData();
    $data['experiences'] = [
        ['title' => 'FirstShortEntryTitle', 'did' => 'Short one.', 'used' => 'Short.'],
        ['title' => 'LastShortEntryTitle', 'did' => 'Short two.', 'used' => 'Short.'],
    ];

    [$bytes] = autoFitBuild($data);

    $y = autoFitTextY($bytes, 'LastShortEntryTitle');
    assertTrue($y !== null);
    // Page height is 297mm (~841.89pt); the bottom margin sits at 290mm
    // from the top, i.e. y ~= (297-290)*2.8346 ~= 19.8pt from the bottom
    // in PDF space. The last entry, stretched to fill the column, should
    // land near there (well under half the page height).
    assertTrue($y < 200, "Expected the last entry title to sit low on the page, got y={$y}pt.");
});

test('AutoFit: a single entry (no gap to stretch into) does not error', static function (): void {
    $data = autoFitExampleData();
    $data['experiences'] = [$data['experiences'][0]];
    $data['education'] = [$data['education'][0]];

    [, $pdf] = autoFitBuild($data);

    assertSame(1, $pdf->PageNo());
});

test('AutoFit: education entries stay above the freetime box even after stretching', static function (): void {
    // A regression guard for the freetime-pin / stretch interaction: even
    // with very few education entries (lots of slack to stretch into),
    // the freetime heading must still come out somewhere on the page and
    // the render must still succeed in one page.
    $data = autoFitExampleData();
    $data['education'] = [$data['education'][0]];

    [$bytes, $pdf] = autoFitBuild($data);

    assertSame(1, $pdf->PageNo());
    assertContains('(Things I like to do in my free time :)', $bytes);
});
