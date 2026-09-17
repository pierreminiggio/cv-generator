<?php

declare(strict_types=1);

// Front controller: loads the CV data (cv.php if present, cv_example.php
// otherwise), builds the PDF with FPDF, and streams it to the browser.
//
// Query string options:
//   ?download=1   force a "Save As" download instead of an inline preview.

require_once dirname(__DIR__) . '/src/CvData.php';
require_once dirname(__DIR__) . '/src/LogoCache.php';
require_once dirname(__DIR__) . '/src/CvPdfGenerator.php';

use Cv\CvData;
use Cv\CvPdfGenerator;
use Cv\LogoCache;

$projectRoot = dirname(__DIR__);

try {
    $data = CvData::load($projectRoot);

    $logoCache = new LogoCache($projectRoot . '/cache/logos');

    $pdf = new CvPdfGenerator($data, $logoCache);
    $pdf->build();

    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($data['header']['name'] ?? 'CV'));
    $filename = ($name !== '' ? $name : 'CV') . '.pdf';

    $disposition = (isset($_GET['download']) && $_GET['download'] !== '0') ? 'D' : 'I';

    // FPDF's Output() prints directly and sets the appropriate headers itself.
    $pdf->Output($disposition, $filename);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');

    // Keep this on while wiring things up locally; turn it off (display
    // only a generic message) once cv.php is filled with real data and
    // the site goes live, so internal paths are never leaked publicly.
    $debug = true;

    if ($debug) {
        echo "Could not generate the CV PDF:\n" . $e->getMessage() . "\n";
    } else {
        echo "Sorry, the CV could not be generated right now.";
    }
}
