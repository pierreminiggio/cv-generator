<?php

declare(strict_types=1);

// Tests for link segments (['text' => ..., 'link' => ...] arrays usable in
// any 'did'/'used'/'content'/skill or freetime 'value'): a multi-word link
// gets one continuous underline (not one per word), and a "\n" at the very
// end of a segment still forces a line break for the next segment. Loaded
// by tests/run.php.

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

function linkTestExampleData(): array
{
    $data = require dirname(__DIR__) . '/cv_example.php';

    foreach (['experiences', 'education'] as $section) {
        foreach ($data[$section] as $i => $entry) {
            unset($data[$section][$i]['logo']);
        }
    }

    return $data;
}

/** @return array{0: string, 1: int} [uncompressed PDF bytes, page count] */
function linkTestRender(array $data): array
{
    $tmp = testTmpDir() . '/linktest-' . bin2hex(random_bytes(4));

    $pdf = new CvPdfGenerator(
        $data,
        new LogoCache($tmp . '/logos'),
        new FlagSpriteCache('http://127.0.0.1:9/flags.png', $tmp . '/flags')
    );
    $pdf->SetCompression(false);
    $pdf->build();

    return [(string) $pdf->Output('S'), $pdf->PageNo()];
}

/** Number of continuous-underline fill rects drawn in this colour (see CvPdfGenerator::drawLinkUnderlines()). */
function linkTestUnderlineFillCount(string $bytes, string $rgbTriplet): int
{
    $pattern = '/' . preg_quote($rgbTriplet, '/') . ' rg\n[0-9.\-]+ [0-9.\-]+ [0-9.\-]+ [0-9.\-]+ re f/';

    return preg_match_all($pattern, $bytes);
}

/** The Y coordinate FPDF's Text() drew $text's baseline at, or null if not found. */
function linkTestTextY(string $bytes, string $text): ?float
{
    $pattern = '/BT [0-9.\-]+ ([0-9.\-]+) Td \(' . preg_quote($text, '/') . '\) Tj/';
    if (preg_match($pattern, $bytes, $m) === 1) {
        return (float) $m[1];
    }

    return null;
}

const LINK_TEST_ENTRY_DARK_RGB = '0.078 0.133 0.310'; // self::C_ENTRY_DARK, [20,34,79]/255

// ---- Continuous underline ----------------------------------------------------

test('Links: a multi-word linked phrase gets exactly one continuous underline', static function (): void {
    $data = linkTestExampleData();
    $data['experiences'][0]['did'] = [
        ['text' => 'Built a '],
        ['text' => 'Password Manager tool', 'link' => 'https://example.com/pm'],
        ['text' => ' for people.'],
    ];

    [$bytes] = linkTestRender($data);

    $count = linkTestUnderlineFillCount($bytes, LINK_TEST_ENTRY_DARK_RGB);
    assertSame(1, $count, 'Expected exactly one underline fill spanning the whole 3-word link, not one per word.');
});

test('Links: a plain (non-linked) did/used with no links draws zero underline fills', static function (): void {
    $data = linkTestExampleData();
    $data['experiences'][0]['did'] = 'Just a plain sentence with no links at all here.';

    [$bytes] = linkTestRender($data);

    assertSame(0, linkTestUnderlineFillCount($bytes, LINK_TEST_ENTRY_DARK_RGB));
});

test('Links: two separate (non-adjacent) links on the same line get two separate underlines', static function (): void {
    $data = linkTestExampleData();
    $data['experiences'][0]['did'] = [
        ['text' => 'See '],
        ['text' => 'ProjectOne', 'link' => 'https://example.com/one'],
        ['text' => ' and also '],
        ['text' => 'ProjectTwo', 'link' => 'https://example.com/two'],
        ['text' => '.'],
    ];

    [$bytes] = linkTestRender($data);

    assertSame(2, linkTestUnderlineFillCount($bytes, LINK_TEST_ENTRY_DARK_RGB));
});

test('Links: a Link annotation with the right URI is attached to a linked phrase', static function (): void {
    $data = linkTestExampleData();
    $data['experiences'][0]['did'] = [
        ['text' => 'Built '],
        ['text' => 'Distinctive Project Name', 'link' => 'https://example.com/distinctive-project'],
    ];

    [$bytes] = linkTestRender($data);

    assertContains('/URI (https://example.com/distinctive-project)', $bytes);
});

// ---- "\n" at the end of a segment still forces a line break -----------------

test('Links: a "\\n" at the very end of one segment still breaks the line before the next segment', static function (): void {
    $data = linkTestExampleData();
    $data['experiences'][0]['did'] = [
        ['text' => "AlphaLineOne\n"],
        ['text' => 'BetaLineTwo', 'link' => 'https://example.com/beta'],
    ];

    [$bytes] = linkTestRender($data);

    $y1 = linkTestTextY($bytes, 'AlphaLineOne');
    $y2 = linkTestTextY($bytes, 'BetaLineTwo');

    assertTrue($y1 !== null, 'Expected to find the first segment\'s text.');
    assertTrue($y2 !== null, 'Expected to find the second segment\'s text.');
    // FPDF's Y axis increases upward: a lower line on the page has a
    // *smaller* Y. If the trailing "\n" were silently dropped (the bug),
    // both fragments would share the same line/Y instead.
    assertTrue($y2 < $y1, "Expected the second segment on a lower line (y2={$y2} < y1={$y1}).");
});

test('Links: without a trailing "\\n", two segments stay on the same line', static function (): void {
    $data = linkTestExampleData();
    $data['experiences'][0]['did'] = [
        ['text' => 'GammaSameLine '],
        ['text' => 'DeltaSameLine', 'link' => 'https://example.com/delta'],
    ];

    [$bytes] = linkTestRender($data);

    $y1 = linkTestTextY($bytes, 'GammaSameLine');
    $y2 = linkTestTextY($bytes, 'DeltaSameLine');

    assertTrue($y1 !== null && $y2 !== null, 'Expected to find both fragments.');
    assertSame($y1, $y2, 'Expected both segments to stay on the same line when there is no forced break.');
});

test('Links: punctuation right after a link stays glued (no stray space before it)', static function (): void {
    $data = linkTestExampleData();
    $data['experiences'][0]['did'] = [
        ['text' => 'Built '],
        ['text' => 'EpsilonProject', 'link' => 'https://example.com/epsilon'],
        ['text' => ', and more.'],
    ];

    [$bytes] = linkTestRender($data);

    assertNotContains('(EpsilonProject,)', $bytes, 'The link text itself should not include the trailing comma.');
    assertContains('(,)', $bytes, 'Expected the glued comma to be its own text fragment.');
});

test('Links: still fits on one page with several linked entries', static function (): void {
    $data = linkTestExampleData();
    $data['experiences'][0]['did'] = [
        ['text' => "Line one of the description:\n"],
        ['text' => 'LinkedItemOne', 'link' => 'https://example.com/one'],
        ['text' => ', '],
        ['text' => 'LinkedItemTwo', 'link' => 'https://example.com/two'],
        ["text" => "\nAnd a closing sentence after another break."],
    ];

    [, $pages] = linkTestRender($data);

    assertSame(1, $pages);
});
