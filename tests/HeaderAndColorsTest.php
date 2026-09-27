<?php

declare(strict_types=1);

// Tests for: text colours (section headings, entry titles and education
// descriptions moved off the old heading-blue), and the header box (grey
// border matching the dividers' weight, symmetric padding, and the title/
// name/contact lines auto-growing or auto-shrinking to fill the space
// between the text and the photo). Loaded by tests/run.php.

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

// ---- Colour triplets (RGB 0-255 / 255, 3dp - matches FPDF's own '%.3F rg' formatting) ----
const COLORS_TEXT_DEFAULT = '0.078 0.078 0.078'; // [20,20,20]
const COLORS_EDU_CONTENT = '0.129 0.286 0.580';  // [33,73,148]
const COLORS_HEADING_BLUE = '0.106 0.247 0.545'; // [27,63,139]
const COLORS_DARK_GREY = '0.353 0.353 0.353';    // [90,90,90]

// ---- Helpers ---------------------------------------------------------------

function colorsExampleData(): array
{
    $data = require dirname(__DIR__) . '/cv_example.php';

    foreach (['experiences', 'education'] as $section) {
        foreach ($data[$section] as $i => $entry) {
            unset($data[$section][$i]['logo']);
        }
    }

    return $data;
}

/** @return array{0: string, 1: CvPdfGenerator} */
function colorsBuild(array $data): array
{
    $tmp = testTmpDir() . '/colors-' . bin2hex(random_bytes(4));

    $pdf = new CvPdfGenerator(
        $data,
        new LogoCache($tmp . '/logos'),
        new FlagSpriteCache('http://127.0.0.1:9/flags.png', $tmp . '/flags')
    );
    $pdf->SetCompression(false);
    $pdf->build();

    return [(string) $pdf->Output('S'), $pdf];
}

/** The RGB triplet of the closest preceding "rg"/"RG" fill/stroke colour change before $text, if any. */
function colorsPrecedingColor(string $bytes, string $text, string $op = 'rg', int $window = 500): ?string
{
    $idx = strpos($bytes, '(' . $text . ')');
    if ($idx === false) {
        return null;
    }
    $chunk = substr($bytes, max(0, $idx - $window), min($idx, $window));
    if (preg_match('/([0-9.]+ [0-9.]+ [0-9.]+) ' . $op . '(?!.*' . $op . ')/s', $chunk, $m) === 1) {
        return $m[1];
    }

    return null;
}

/** The font size ("x.xx Tf") of the closest preceding font selection before $text. */
function colorsPrecedingFontSize(string $bytes, string $text, int $window = 300): ?float
{
    $idx = strpos($bytes, '(' . $text . ')');
    if ($idx === false) {
        return null;
    }
    $chunk = substr($bytes, max(0, $idx - $window), min($idx, $window));
    if (preg_match('/([0-9.]+) Tf(?!.*Tf)/s', $chunk, $m) === 1) {
        return (float) $m[1];
    }

    return null;
}

// ---- Text colours -------------------------------------------------------------

test('Colors: skill section headings are black, not heading-blue', static function (): void {
    [$bytes] = colorsBuild(colorsExampleData());

    assertSame(COLORS_TEXT_DEFAULT, colorsPrecedingColor($bytes, 'Software Development :'));
});

test('Colors: "Work Experiences :" and "Education :" are black', static function (): void {
    [$bytes] = colorsBuild(colorsExampleData());

    assertSame(COLORS_TEXT_DEFAULT, colorsPrecedingColor($bytes, 'Work Experiences :'));
    assertSame(COLORS_TEXT_DEFAULT, colorsPrecedingColor($bytes, 'Education :'));
});

test('Colors: an entry title (experience) is black', static function (): void {
    $data = colorsExampleData();
    $data['experiences'][0]['title'] = 'DistinctiveBlackTitleCheck';

    [$bytes] = colorsBuild($data);

    assertSame(COLORS_TEXT_DEFAULT, colorsPrecedingColor($bytes, 'DistinctiveBlackTitleCheck'));
});

test('Colors: an education entry\'s description uses the halfway colour, not the dark "did" colour', static function (): void {
    $data = colorsExampleData();
    $data['education'][0]['content'] = 'DistinctiveEduContentCheck';

    [$bytes] = colorsBuild($data);

    assertSame(COLORS_EDU_CONTENT, colorsPrecedingColor($bytes, 'DistinctiveEduContentCheck'));
});

test('Colors: the freetime heading is left as heading-blue (not changed to black)', static function (): void {
    [$bytes] = colorsBuild(colorsExampleData());

    assertSame(COLORS_HEADING_BLUE, colorsPrecedingColor($bytes, 'Things I like to do in my free time :'));
});

// ---- Header box ----------------------------------------------------------------

test('Header: the box border is dark grey, the same weight as the section dividers', static function (): void {
    [$bytes] = colorsBuild(colorsExampleData());

    assertContains(COLORS_DARK_GREY . ' RG', $bytes);
    // DIVIDER_LINE_WIDTH = 0.6mm -> 0.6 * (72/25.4) ~= 1.70pt, FPDF's SetLineWidth output.
    assertContains('1.70 w', $bytes, 'Expected the header border and dividers to share the same 0.6mm line width.');
});

test('Header: horizontal padding is exactly half the vertical padding', static function (): void {
    $ref = new ReflectionClass(CvPdfGenerator::class);
    $padY = $ref->getConstant('HEADER_PAD_Y');
    $padX = $ref->getConstant('HEADER_PAD_X');

    assertSame($padY / 2, $padX);
});

test('Header: a short title/name grow past their base font size', static function (): void {
    $data = colorsExampleData();
    $data['header']['title'] = 'Dev';
    $data['header']['name'] = 'Jo';
    $data['header']['phone'] = '';

    [$bytes] = colorsBuild($data);

    $size = colorsPrecedingFontSize($bytes, 'Dev');
    assertTrue($size !== null, 'Expected to find the title\'s font size.');
    assertTrue($size > 22.0, "Expected the short title to grow past 22pt (base FS_TITLE), got {$size}.");
});

test('Header: a very long title shrinks to fit rather than overlapping the photo', static function (): void {
    $data = colorsExampleData();
    $data['header']['title'] = 'Senior Full-Stack Software and Agentic AI Developer Engineer Extraordinaire';

    [$bytes, $pdf] = colorsBuild($data);

    assertSame(1, $pdf->PageNo());
    assertContains(substr($data['header']['title'], 0, 20), $bytes);
    $size = colorsPrecedingFontSize($bytes, $data['header']['title']);
    assertTrue($size !== null && $size <= 22.0, 'Expected the long title to be at or below its base size.');
});

test('Header: the title/name/contact scale never exceeds the 1.6x cap even for a single short word', static function (): void {
    $data = colorsExampleData();
    $data['header']['title'] = 'X';
    $data['header']['name'] = 'Y';
    $data['header']['phone'] = '';
    $data['header']['email'] = '';
    $data['header']['website'] = '';

    [$bytes] = colorsBuild($data);

    $size = colorsPrecedingFontSize($bytes, 'X');
    assertTrue($size !== null && $size <= 22.0 * 1.6 + 0.01, "Expected the size to stay within the 1.6x cap, got {$size}.");
});
