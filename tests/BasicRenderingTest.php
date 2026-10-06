<?php

declare(strict_types=1);

// Tests for CvPdfGenerator basics not covered elsewhere: page-geometry
// constants, skills-column rendering, and the grey placeholder box drawn
// in place of a header photo / entry logo when none is available.
// Loaded by tests/run.php.

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

function basicRenderingMinimalData(): array
{
    return [
        'header'       => [],
        'skills_left'  => [],
        'skills_right' => [],
        'experiences'  => [],
        'education'    => [],
        'freetime'     => [],
    ];
}

/**
 * @return array{0: string, 1: int}
 */
function basicRenderingBuild(array $data): array
{
    $tmp = testTmpDir();

    $pdf = new CvPdfGenerator(
        $data,
        new LogoCache($tmp . '/logos-' . bin2hex(random_bytes(4))),
        new FlagSpriteCache('http://127.0.0.1:9/flags.png', $tmp . '/flags-' . bin2hex(random_bytes(4)))
    );
    $pdf->SetCompression(false);
    $pdf->build();

    $bytes = (string) preg_replace('/\/CreationDate \(.*?\)/', '', $pdf->Output('S'));

    return [$bytes, $pdf->PageNo()];
}

function basicRenderingConst(string $name)
{
    return (new ReflectionClass(CvPdfGenerator::class))->getConstant($name);
}

/** The "%.3F %.3F %.3F rg" string FPDF emits for an [r,g,b] (0-255) colour. */
function basicRenderingRgbString(array $rgb255): string
{
    return sprintf('%.3F %.3F %.3F rg', $rgb255[0] / 255, $rgb255[1] / 255, $rgb255[2] / 255);
}

// ---- Page geometry constants -------------------------------------------------

test('Geometry: CONTENT_W equals PAGE_W minus the left/right margins', static function (): void {
    $contentW = basicRenderingConst('CONTENT_W');
    $pageW = basicRenderingConst('PAGE_W');
    $marginL = basicRenderingConst('MARGIN_L');
    $marginR = basicRenderingConst('MARGIN_R');

    assertSame($pageW - $marginL - $marginR, $contentW);
});

test('Geometry: the experience/education column widths plus their gap equal CONTENT_W', static function (): void {
    $expW = basicRenderingConst('EXP_COL_W');
    $eduW = basicRenderingConst('EDU_COL_W');
    $gap = basicRenderingConst('COL_GAP_EXP');
    $contentW = basicRenderingConst('CONTENT_W');

    assertTrue(abs(($expW + $eduW + $gap) - $contentW) < 0.001);
});

test('Geometry: the two skills columns plus their gap equal CONTENT_W', static function (): void {
    $skillsW = basicRenderingConst('SKILLS_COL_W');
    $gap = basicRenderingConst('COL_GAP_SKILLS');
    $contentW = basicRenderingConst('CONTENT_W');

    assertTrue(abs((2 * $skillsW + $gap) - $contentW) < 0.001);
});

// ---- Skills columns -----------------------------------------------------------

test('Skills: left and right column headings and label/value lines are drawn', static function (): void {
    $data = basicRenderingMinimalData();
    $data['skills_left'] = [[
        'heading' => 'Left Heading :',
        'lines' => [['label' => 'LeftLabel', 'value' => 'LeftValue']],
    ]];
    $data['skills_right'] = [[
        'heading' => 'Right Heading :',
        'lines' => [['label' => 'RightLabel', 'value' => 'RightValue']],
    ]];

    [$pdf, $pages] = basicRenderingBuild($data);

    assertSame(1, $pages);
    // Short headings are drawn as one flat string (see drawSectionHeading()),
    // and a skill line's "label :" is glued into one word (a lone ":" never
    // starts its own line - see styledWords()), while the value is its own
    // separate word(s) (see drawStyledLines()).
    assertContains('(Left Heading :) Tj', $pdf);
    assertContains('(LeftLabel :) Tj', $pdf);
    assertContains('(LeftValue) Tj', $pdf);
    assertContains('(Right Heading :) Tj', $pdf);
    assertContains('(RightLabel :) Tj', $pdf);
    assertContains('(RightValue) Tj', $pdf);
});

test('Skills: a second skill block in the same column still renders both headings', static function (): void {
    $data = basicRenderingMinimalData();
    $data['skills_left'] = [
        ['heading' => 'FirstBlock :', 'lines' => [['label' => 'A', 'value' => 'B']]],
        ['heading' => 'SecondBlock :', 'lines' => [['label' => 'C', 'value' => 'D']]],
    ];

    [$pdf] = basicRenderingBuild($data);

    assertContains('(FirstBlock :) Tj', $pdf);
    assertContains('(SecondBlock :) Tj', $pdf);
});

// ---- Placeholder boxes (no photo / no logo) ------------------------------------

test('Placeholder: a missing header photo draws the grey logo-fill placeholder box', static function (): void {
    $data = basicRenderingMinimalData();
    // No 'photo' key at all.

    [$pdf] = basicRenderingBuild($data);

    assertContains(basicRenderingRgbString(basicRenderingConst('C_LOGO_FILL')), $pdf);
});

test('Placeholder: an entry with no logo URL draws the grey placeholder box too', static function (): void {
    $data = basicRenderingMinimalData();
    $data['experiences'] = [
        ['title' => 'EntryWithNoLogo', 'did' => 'Did something.'],
    ];

    [$pdf] = basicRenderingBuild($data);

    $fillLine = basicRenderingRgbString(basicRenderingConst('C_LOGO_FILL'));
    // Expect at least 2 occurrences: the header photo placeholder, plus this entry's logo placeholder.
    assertTrue(substr_count($pdf, $fillLine) >= 2, 'Expected the placeholder fill colour to appear at least twice.');
});

test('Placeholder: a valid local photo file is drawn as an image, not the placeholder box', static function (): void {
    $dir = testTmpDir() . '/basicrendering-' . bin2hex(random_bytes(4));
    mkdir($dir, 0775, true);
    $photoPath = $dir . '/photo.png';
    $img = imagecreatetruecolor(20, 20);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
    imagepng($img, $photoPath);
    imagedestroy($img);

    $data = basicRenderingMinimalData();
    $data['photo'] = $photoPath;

    [$pdf] = basicRenderingBuild($data);

    assertContains('/Image', $pdf);
});
