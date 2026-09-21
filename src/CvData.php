<?php

declare(strict_types=1);

namespace Cv;

use RuntimeException;

/**
 * Loads the CV data array from cv.php (private, git-ignored) when it
 * exists, and falls back to cv_example.php otherwise.
 */
final class CvData
{
    public static function load(string $projectRoot): array
    {
        $privatePath = $projectRoot . '/cv.php';
        $examplePath = $projectRoot . '/cv_example.php';

        if (is_file($privatePath)) {
            $path = $privatePath;
        } elseif (is_file($examplePath)) {
            $path = $examplePath;
        } else {
            throw new RuntimeException(
                'No CV data file found. Expected either cv.php or cv_example.php ' .
                'at the project root (' . $projectRoot . ').'
            );
        }

        $data = require $path;

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('%s must return an array.', $path));
        }

        foreach (['header', 'skills_left', 'skills_right', 'experiences', 'education', 'freetime'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RuntimeException(sprintf('CV data is missing the "%s" key.', $key));
            }
        }

        self::validateSectionTitles($data);

        return $data;
    }

    /**
     * "section_titles" is optional (older cv.php files don't have it, and
     * CvPdfGenerator falls back to its built-in titles), but when present
     * it has to be well-formed so a typo fails loudly instead of silently
     * producing a blank or wrong heading.
     */
    private static function validateSectionTitles(array $data): void
    {
        if (!array_key_exists('section_titles', $data)) {
            return;
        }

        if (!is_array($data['section_titles'])) {
            throw new RuntimeException('CV data key "section_titles" must be an array.');
        }

        foreach (['experiences', 'education'] as $key) {
            if (!array_key_exists($key, $data['section_titles'])) {
                continue; // this one falls back to its default title
            }

            $value = $data['section_titles'][$key];
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException(
                    sprintf('CV data key "section_titles" -> "%s" must be a non-empty string.', $key)
                );
            }
        }
    }
}
