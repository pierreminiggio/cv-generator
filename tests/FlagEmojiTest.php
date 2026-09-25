<?php

declare(strict_types=1);

// Tests for flag handling: emoji flags typed directly into any text field
// are replaced by the matching flag image (CvPdfGenerator::styledWords()),
// and the explicit 'languages' list draws the same flags at native size
// (not upscaled). Loaded by tests/run.php.

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

/** The full example data, minus remote logos so nothing touches the network. */
function flagEmojiExampleData(): array
{
    $data = require dirname(__DIR__) . '/cv_example.php';

    foreach (['experiences', 'education'] as $section) {
        foreach ($data[$section] as $i => $entry) {
            unset($data[$section][$i]['logo']);
        }
    }

    return $data;
}

/** Pre-seeds a synthetic sprite sheet at FlagSpriteCache's documented coordinates. */
function flagEmojiSeedSheet(string $flagsCacheDir): void
{
    $positions = [
        'FR' => [0, 1976], 'US' => [72, 1950], 'ES' => [0, 1742],
        'CN' => [0, 1196], 'KE' => [36, 936],
    ];

    $sheet = imagecreatetruecolor(130, 2000);
    $white = imagecolorallocate($sheet, 255, 255, 255);
    imagefill($sheet, 0, 0, $white);
    $color = imagecolorallocate($sheet, 10, 20, 220);
    foreach ($positions as [$x, $y]) {
        imagefilledrectangle($sheet, $x, $y, $x + 15, $y + 10, $color);
    }

    mkdir($flagsCacheDir, 0775, true);
    imagepng($sheet, $flagsCacheDir . '/flags-sheet.png');
    imagedestroy($sheet);
}

/**
 * Builds the PDF uncompressed (greppable content stream) against a
 * pre-seeded local flag sprite - no network involved.
 *
 * @return array{0: string, 1: int}
 */
function flagEmojiRender(array $data): array
{
    $tmp = testTmpDir() . '/flagemoji-' . bin2hex(random_bytes(4));
    flagEmojiSeedSheet($tmp . '/flags');

    $pdf = new CvPdfGenerator(
        $data,
        new LogoCache($tmp . '/logos'),
        new FlagSpriteCache('http://127.0.0.1:9/flags.png', $tmp . '/flags')
    );
    $pdf->SetCompression(false);
    $pdf->build();

    $bytes = $pdf->Output('S');

    return [(string) preg_replace('/\/CreationDate \(.*?\)/', '', $bytes), $pdf->PageNo()];
}

// ---- Emoji flags inside free-flowing text -----------------------------------

test('FlagEmoji: the example data\'s freetime line embeds flags at native (non-upscaled) size', static function (): void {
    [$bytes] = flagEmojiRender(flagEmojiExampleData());

    // 16x11px at 96dpi = 12.00 x 8.25 pt - see PX_TO_MM_96DPI in CvPdfGenerator.
    assertContains('12.00 0 0 8.25', $bytes, 'Expected flags drawn at their native pt size.');
});

test('FlagEmoji: a flag emoji embedded in text is drawn as an image, not as literal text', static function (): void {
    $data = flagEmojiExampleData();
    [$bytes] = flagEmojiRender($data);

    // U+1F1FA U+1F1F8 ("US" flag) as raw UTF-8 bytes must never appear as
    // a drawn text string - it should have been intercepted and replaced
    // by an image instead.
    $usFlagUtf8 = "\u{1F1FA}\u{1F1F8}";
    assertNotContains($usFlagUtf8, $bytes, 'Raw flag emoji bytes should never reach the text-drawing path.');
});

test('FlagEmoji: the words around an in-text flag still render, split at the emoji', static function (): void {
    // "Taught myself <US flag>, currently learning ..." - both halves of
    // the sentence must still appear as drawn text.
    [$bytes] = flagEmojiRender(flagEmojiExampleData());

    assertContains('(Taught) Tj', $bytes);
    assertContains('(myself)', $bytes);
    assertContains('(currently)', $bytes);
});

test('FlagEmoji: punctuation glued to an in-text flag is not preceded by an extra space', static function (): void {
    // cv_example.php has "...myself \u{1F1FA}\u{1F1F8}, currently..." - the
    // comma is glued to the flag with no space in the source text, so it
    // must be drawn as its own short ",", not merged into "myself,".
    [$bytes] = flagEmojiRender(flagEmojiExampleData());

    assertNotContains('(myself,)', $bytes);
    assertContains('(,)', $bytes, 'Expected the glued comma to be drawn as its own text fragment.');
});

// ---- Unknown flag codes fall back gracefully --------------------------------

test('FlagEmoji: an emoji with no known crop position falls back to its 2-letter code as text', static function (): void {
    $data = flagEmojiExampleData();
    // German flag (DE) is not one of FlagSpriteCache's known positions.
    $data['freetime']['lines'][] = ['label' => 'Also', 'value' => "Learning \u{1F1E9}\u{1F1EA} too"];

    [$bytes] = flagEmojiRender($data);

    assertContains('(DE)', $bytes, 'Expected a plain-text "DE" fallback badge.');
    assertNotContains("\u{1F1E9}\u{1F1EA}", $bytes, 'Raw emoji bytes should still never appear as text.');
});

// ---- The explicit 'languages' list uses the same mechanism ------------------

test('FlagEmoji: the explicit languages list resolves and embeds all 5 known codes', static function (): void {
    $tmp = testTmpDir() . '/flagemoji-' . bin2hex(random_bytes(4));
    flagEmojiSeedSheet($tmp . '/flags');

    $data = flagEmojiExampleData();
    $pdf = new CvPdfGenerator(
        $data,
        new LogoCache($tmp . '/logos'),
        new FlagSpriteCache('http://127.0.0.1:9/flags.png', $tmp . '/flags')
    );
    $pdf->build();

    foreach (['fr', 'us', 'es', 'cn', 'ke'] as $code) {
        assertTrue(is_file("{$tmp}/flags/{$code}.png"), "Expected a cropped cache file for {$code}.");
    }
});

test('FlagEmoji: without a reachable sprite, the language list falls back to text badges and still fits on one page', static function (): void {
    $tmp = testTmpDir() . '/flagemoji-' . bin2hex(random_bytes(4));
    // No seeded sheet this time, and an unreachable URL: every code must
    // fall back gracefully rather than breaking the render.
    $data = flagEmojiExampleData();
    $pdf = new CvPdfGenerator(
        $data,
        new LogoCache($tmp . '/logos'),
        new FlagSpriteCache('http://127.0.0.1:9/flags.png', $tmp . '/flags')
    );
    $pdf->SetCompression(false);
    $pdf->build();
    $bytes = $pdf->Output('S');

    assertSame(1, $pdf->PageNo());
    assertContains('(FR)', $bytes);
    assertContains('(US)', $bytes);
});
