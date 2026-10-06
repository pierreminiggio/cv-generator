<?php

declare(strict_types=1);

// Tests for CvData::load()'s base validation: the required top-level keys
// (header, skills_left, skills_right, experiences, education, freetime)
// and the "must return an array" / "file must exist" guards. The optional
// section_titles validation already has its own coverage in
// SectionTitlesTest.php. Loaded by tests/run.php.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/src/CvData.php';

use Cv\CvData;

// ---- Helpers ---------------------------------------------------------------

function cvDataValidationMinimalData(): array
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

/** Writes $data as a cv.php in a throwaway project root and loads it via CvData. */
function cvDataValidationLoad(array $data): array
{
    $root = testTmpDir() . '/project-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);
    file_put_contents($root . '/cv.php', '<?php return ' . var_export($data, true) . ';');

    return CvData::load($root);
}

function cvDataValidationProjectRoot(): string
{
    $root = testTmpDir() . '/project-' . bin2hex(random_bytes(4));
    mkdir($root, 0775, true);

    return $root;
}

// ---- Required top-level keys ------------------------------------------------

test('CvData: a complete minimal data set loads without error', static function (): void {
    $data = cvDataValidationLoad(cvDataValidationMinimalData());

    assertTrue(is_array($data));
    assertSame([], $data['experiences']);
});

test('CvData: each required key is rejected when missing', static function (): void {
    foreach (['header', 'skills_left', 'skills_right', 'experiences', 'education', 'freetime'] as $missingKey) {
        $data = cvDataValidationMinimalData();
        unset($data[$missingKey]);

        assertThrowsRuntime(
            static fn () => cvDataValidationLoad($data),
            sprintf('missing the "%s" key', $missingKey)
        );
    }
});

test('CvData: a required key present but null still counts as present (no exception)', static function (): void {
    // array_key_exists() is true even for a null value - CvData only checks
    // the key is *present*, not that it is non-empty/non-null; downstream
    // code (CvPdfGenerator) tolerates empty/missing sections via `?? []`.
    $data = cvDataValidationMinimalData();
    $data['header'] = null;

    $loaded = cvDataValidationLoad($data);

    assertTrue(array_key_exists('header', $loaded));
    assertTrue($loaded['header'] === null);
});

// ---- File-level guards -------------------------------------------------------

test('CvData: throws when neither cv.php nor cv_example.php exist', static function (): void {
    $root = cvDataValidationProjectRoot();

    assertThrowsRuntime(
        static fn () => CvData::load($root),
        'No CV data file found'
    );
});

test('CvData: cv.php is preferred over cv_example.php when both exist', static function (): void {
    $root = cvDataValidationProjectRoot();
    $example = cvDataValidationMinimalData();
    $example['header'] = ['name' => 'From Example'];
    $private = cvDataValidationMinimalData();
    $private['header'] = ['name' => 'From Private'];

    file_put_contents($root . '/cv_example.php', '<?php return ' . var_export($example, true) . ';');
    file_put_contents($root . '/cv.php', '<?php return ' . var_export($private, true) . ';');

    $data = CvData::load($root);

    assertSame('From Private', $data['header']['name']);
});

test('CvData: falls back to cv_example.php when cv.php is absent', static function (): void {
    $root = cvDataValidationProjectRoot();
    $example = cvDataValidationMinimalData();
    $example['header'] = ['name' => 'From Example'];

    file_put_contents($root . '/cv_example.php', '<?php return ' . var_export($example, true) . ';');

    $data = CvData::load($root);

    assertSame('From Example', $data['header']['name']);
});

test('CvData: a data file that does not return an array throws', static function (): void {
    $root = cvDataValidationProjectRoot();
    file_put_contents($root . '/cv.php', '<?php return "not an array";');

    assertThrowsRuntime(
        static fn () => CvData::load($root),
        'must return an array'
    );
});
