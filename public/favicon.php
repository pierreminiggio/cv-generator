<?php

declare(strict_types=1);

// Serves /favicon.ico, built from the CV's own photo, so the browser tab
// showing the generated CV PDF displays that picture instead of a generic
// PDF icon. Browsers request this path automatically for any site; the
// root .htaccess routes it here specifically instead of to index.php.

require_once dirname(__DIR__) . '/src/CvData.php';
require_once dirname(__DIR__) . '/src/FaviconBuilder.php';

use Cv\CvData;
use Cv\FaviconBuilder;

$projectRoot = dirname(__DIR__);

header('Content-Type: image/x-icon');
header('Cache-Control: public, max-age=86400');

$icoPath = null;

try {
    $data = CvData::load($projectRoot);
    $photoPath = $data['photo'] ?? null;

    if (is_string($photoPath)) {
        $icoPath = FaviconBuilder::buildCached($photoPath, $projectRoot . '/cache/favicon.ico');
    }
} catch (\Throwable $e) {
    // Fall through to the 404 below: no favicon is better than a broken
    // response for a request every browser makes silently and automatically.
}

if ($icoPath !== null) {
    header('Content-Length: ' . (string) filesize($icoPath));
    readfile($icoPath);
} else {
    http_response_code(404);
}
