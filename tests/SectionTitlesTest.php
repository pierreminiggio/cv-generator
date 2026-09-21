<?php

declare(strict_types=1);

// Tests for the optional 'section_titles' key of the CV data (the titles
// above the Work Experiences / Education columns). Loaded by tests/run.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/CvData.php';
require_once dirname(__DIR__) . '/src/LogoCache.php';
require_once dirname(__DIR__) . '/src/FlagSpriteCache.php';
require_once dirname(__DIR__) . '/src/CvPdfGenerator.php';

use Cv\CvData;
use Cv\CvPdfGenerator;
use Cv\FlagSpriteCache;
use Cv\LogoCache;

// ---- Helpers ---------------------------------------------------------------

/** The full example data, minus remote logos so nothing touches the network. */
function sectionTitlesExampleData(): array
{
    $data = require dirname(__DIR__) . '/cv_example.php';

    foreach (['experiences', 'education'] as $section) {
        foreach ($data[$section] as $i => $entry) {
            unset($data[$section][$i]['logo']);
        }
    }

    // The example sets the default titles explicitly; start from "not set".
    unset($data['section_titles']);

    return $data;
}

/** $data with 'section_titles' replaced by $titles (or removed when null). */
function sectionTitlesWith(array $data, ?array $titles): array
{
    unset($data['section_titles']);
    if ($titles !== null) {
        $data['section_titles'] = $titles;
    }

    return $data;
}

/**
 * Builds the PDF uncompressed (so headings are greppable as "(Text) Tj")
 * and returns [bytes without the volatile CreationDate, page count].
 *
 * The flag sprite URL points at a closed local port: the download fails
 * instantly and the generator falls back to plain badges, exactly as it
 * does offline in real life.
 *
 * @return array{0: string, 1: int}
 */
function sectionTitlesRender(array $data): array
{
    $tmp = testTmpDir();

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

/** Writes $data as a cv.php in a throwaway project root and loads it via CvData. */
function sectionTitlesLoad(array $data): array
{
    $root = testTmpDir() . '/project-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    file_put_contents($root . '/cv.php', '<?php return ' . var_export($data, true) . ';');

    return CvData::load($root);
}

function sectionTitlesMinimalData(): array
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

/** How a heading appears in the (uncompressed) PDF content stream. */
function sectionTitlesDrawn(string $text): string
{
    return '(' . $text . ') Tj';
}

// ---- CvData validation -----------------------------------------------------

test('CvData: data without section_titles still loads (older cv.php files)', static function (): void {
    $data = sectionTitlesLoad(sectionTitlesMinimalData());

    assertTrue(!array_key_exists('section_titles', $data));
});

test('CvData: a partial section_titles (one key only) loads', static function (): void {
    $input = sectionTitlesMinimalData();
    $input['section_titles'] = ['education' => 'Studies :'];

    $data = sectionTitlesLoad($input);

    assertSame(['education' => 'Studies :'], $data['section_titles']);
});

test('CvData: section_titles must be an array', static function (): void {
    $input = sectionTitlesMinimalData();
    $input['section_titles'] = 'Work Experiences :';

    assertThrowsRuntime(static fn () => sectionTitlesLoad($input), '"section_titles" must be an array');
});

test('CvData: a title must be a non-empty string', static function (): void {
    foreach (['experiences', 'education'] as $key) {
        foreach (['', '   ', 42, null, ['Work']] as $bad) {
            $input = sectionTitlesMinimalData();
            $input['section_titles'] = [$key => $bad];

            assertThrowsRuntime(
                static fn () => sectionTitlesLoad($input),
                sprintf('"section_titles" -> "%s" must be a non-empty string', $key)
            );
        }
    }
});

// ---- Rendering -------------------------------------------------------------

test('PDF: without section_titles, the built-in titles are used on one page', static function (): void {
    [$pdf, $pages] = sectionTitlesRender(sectionTitlesExampleData());

    assertSame(1, $pages, 'Page count.');
    assertContains(sectionTitlesDrawn('Work Experiences :'), $pdf);
    assertContains(sectionTitlesDrawn('Education :'), $pdf);
});

test('PDF: explicitly setting the default titles renders exactly like omitting them', static function (): void {
    $base = sectionTitlesExampleData();

    [$omitted] = sectionTitlesRender($base);
    [$explicit] = sectionTitlesRender(sectionTitlesWith($base, [
        'experiences' => 'Work Experiences :',
        'education'   => 'Education :',
    ]));

    assertTrue($omitted === $explicit, 'The two PDFs should be byte-identical (apart from CreationDate).');
});

test('PDF: overriding only "experiences" leaves "Education :" untouched', static function (): void {
    [$pdf, $pages] = sectionTitlesRender(sectionTitlesWith(sectionTitlesExampleData(), [
        'experiences' => 'Professional Experience :',
    ]));

    assertSame(1, $pages, 'Page count.');
    assertContains(sectionTitlesDrawn('Professional Experience :'), $pdf);
    assertNotContains(sectionTitlesDrawn('Work Experiences :'), $pdf);
    assertContains(sectionTitlesDrawn('Education :'), $pdf);
});

test('PDF: overriding only "education" leaves "Work Experiences :" untouched', static function (): void {
    [$pdf, $pages] = sectionTitlesRender(sectionTitlesWith(sectionTitlesExampleData(), [
        'education' => 'Education & Training :',
    ]));

    assertSame(1, $pages, 'Page count.');
    assertContains(sectionTitlesDrawn('Work Experiences :'), $pdf);
    assertContains(sectionTitlesDrawn('Education & Training :'), $pdf);
    assertNotContains(sectionTitlesDrawn('Education :'), $pdf);
});

test('PDF: both titles can be overridden together', static function (): void {
    [$pdf, $pages] = sectionTitlesRender(sectionTitlesWith(sectionTitlesExampleData(), [
        'experiences' => 'Career :',
        'education'   => 'Studies :',
    ]));

    assertSame(1, $pages, 'Page count.');
    assertContains(sectionTitlesDrawn('Career :'), $pdf);
    assertContains(sectionTitlesDrawn('Studies :'), $pdf);
    assertNotContains(sectionTitlesDrawn('Work Experiences :'), $pdf);
    assertNotContains(sectionTitlesDrawn('Education :'), $pdf);
});

test('PDF: accented titles are encoded for the PDF (UTF-8 -> Windows-1252)', static function (): void {
    [$pdf] = sectionTitlesRender(sectionTitlesWith(sectionTitlesExampleData(), [
        'experiences' => 'Expérience professionnelle :',
        'education'   => 'Formation :',
    ]));

    assertContains(sectionTitlesDrawn("Exp\xE9rience professionnelle :"), $pdf);
    assertContains(sectionTitlesDrawn('Formation :'), $pdf);
});

test('PDF: very long titles shrink to fit and the CV still fits on one page', static function (): void {
    $experiences = 'Professional Experience, Internships and Freelance Missions :';
    $education = 'Education, Diplomas & Certifications Obtained :';

    [$pdf, $pages] = sectionTitlesRender(sectionTitlesWith(sectionTitlesExampleData(), [
        'experiences' => $experiences,
        'education'   => $education,
    ]));

    assertSame(1, $pages, 'Page count.');
    assertContains(sectionTitlesDrawn($experiences), $pdf);
    assertContains(sectionTitlesDrawn($education), $pdf);
});
