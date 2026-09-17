<?php

declare(strict_types=1);

namespace Cv;

require_once __DIR__ . '/Fpdf/fpdf.php';

use FPDF;
use RuntimeException;

/**
 * Renders the CV data array into a single A4 page PDF, using FPDF.
 *
 * The whole layout is measured before anything is drawn: every wrapped
 * text block is first computed "dry" (same word-wrap code path, just
 * without emitting PDF drawing operators) so the generator knows, before
 * touching the page, exactly how tall the Work Experiences and Education
 * columns will be. If they don't fit under the available space, the
 * generator progressively tightens the spacing between entries and
 * retries - and if even the tightest spacing still doesn't fit, it fails
 * loudly with a clear RuntimeException instead of silently producing a
 * CV that spills onto a second page.
 */
final class CvPdfGenerator extends FPDF
{
    // ---- Page geometry (mm) ----------------------------------------------
    private const PAGE_W = 210.0;
    private const PAGE_H = 297.0;
    private const MARGIN_L = 12.0;
    private const MARGIN_R = 12.0;
    private const MARGIN_BOTTOM = 7.0;
    private const CONTENT_W = self::PAGE_W - self::MARGIN_L - self::MARGIN_R; // 186

    private const HEADER_H = 36.0;
    private const HEADER_BLEED = 4.0; // how far the grey banner extends past the content margins
    private const PHOTO_SIZE = 26.0;
    private const GAP_HEADER_TO_SKILLS = 6.0;

    private const COL_GAP_SKILLS = 8.0;
    private const SKILLS_COL_W = (self::CONTENT_W - self::COL_GAP_SKILLS) / 2; // 89

    private const DIVIDER_GAP_ABOVE = 3.0;
    private const DIVIDER_GAP_BELOW = 3.0;

    private const COL_GAP_EXP = 7.0;
    private const EXP_COL_W = 103.0;
    private const EDU_COL_W = self::CONTENT_W - self::COL_GAP_EXP - self::EXP_COL_W; // 76

    private const SECTION_HEADER_GAP = 3.0; // above & below "Work Experiences :" / "Education :"

    private const LOGO_SIZE = 8.0;
    private const LOGO_TEXT_GAP = 3.0;

    private const FREETIME_PAD = 2.5;

    // ---- Colours (RGB 0-255) ----------------------------------------------
    private const C_HEADING      = [27, 63, 139];   // section headings, dividers, borders
    private const C_ENTRY_DARK   = [20, 34, 79];    // entry titles / "what was done"
    private const C_VALUE_BLUE   = [46, 111, 217];  // skill values / "skills used"
    private const C_LOGO_FILL    = [207, 216, 234];
    private const C_TEXT_DEFAULT = [20, 20, 20];
    private const C_GRAD_START   = [197, 202, 209]; // header banner: darker grey
    private const C_GRAD_END     = [231, 233, 236]; // header banner: lighter grey (never pure white)

    // ---- Font sizes (pt) ---------------------------------------------------
    private const FS_TITLE           = 22.0;
    private const FS_NAME            = 15.0;
    private const FS_CONTACT         = 10.0;
    private const FS_SECTION_HEADING = 11.0;
    private const FS_SKILL           = 9.0;
    private const FS_ENTRY_TITLE     = 9.3;
    private const FS_ENTRY_BODY      = 8.3;
    private const FS_ENTRY_TITLE_MIN = 6.5; // floor for the force-one-line shrink search

    /** @var array<string,mixed> */
    private array $data;
    private LogoCache $logoCache;

    // Spacing "levers" tightened by fitContent() until everything fits.
    private float $entryGap = 7.0;  // vertical gap between two different entries
    private float $lineGap  = 0.9;  // gap between title/did/used (or title/content) lines

    public function __construct(array $data, LogoCache $logoCache)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->data = $data;
        $this->logoCache = $logoCache;

        $this->SetAutoPageBreak(false);
        $this->SetMargins(self::MARGIN_L, 0, self::MARGIN_R);
        $this->SetTitle((string) ($data['header']['name'] ?? 'CV'));
        $this->SetCreator('cv-generator');
        $this->SetDisplayMode('fullpage');
    }

    public function build(): void
    {
        $this->fitContent();

        $this->AddPage();
        $this->drawHeader();

        $skillsBottom = $this->drawSkills(self::HEADER_H + self::GAP_HEADER_TO_SKILLS, true);
        $dividerY = $skillsBottom + self::DIVIDER_GAP_ABOVE;
        $this->drawDivider($dividerY);
        $rowTop = $dividerY + self::DIVIDER_GAP_BELOW;

        $this->drawExperiences($rowTop, true);
        $this->drawEducationAndFreetime($rowTop, true);
    }

    // =========================================================================
    // Auto-fit: measure first, tighten spacing until the content fits.
    // =========================================================================

    private function fitContent(): void
    {
        $skillsBottom = $this->drawSkills(self::HEADER_H + self::GAP_HEADER_TO_SKILLS, false);
        $rowTop = $skillsBottom + self::DIVIDER_GAP_ABOVE + self::DIVIDER_GAP_BELOW;
        $available = self::PAGE_H - self::MARGIN_BOTTOM - $rowTop;

        $gapSteps = [7.0, 6.0, 5.0, 4.0, 3.2, 2.6, 2.0];
        $lineGapSteps = [0.9, 0.8, 0.6, 0.5, 0.4, 0.3, 0.3];

        $expBottom = $eduBottom = PHP_FLOAT_MAX;

        foreach ($gapSteps as $i => $gap) {
            $this->entryGap = $gap;
            $this->lineGap = $lineGapSteps[$i];

            $expBottom = $this->drawExperiences($rowTop, false);
            $eduBottom = $this->drawEducationAndFreetime($rowTop, false);

            $expH = $expBottom - $rowTop;
            $eduH = $eduBottom - $rowTop;

            if ($expH <= $available && $eduH <= $available) {
                return;
            }
        }

        throw new RuntimeException(sprintf(
            'CV content does not fit on a single A4 page even at the tightest spacing ' .
            '(experience column needs %.1fmm, education column needs %.1fmm, only %.1fmm available). ' .
            'Please shorten some entries in cv.php.',
            $expBottom - $rowTop,
            $eduBottom - $rowTop,
            $available
        ));
    }

    // =========================================================================
    // Header
    // =========================================================================

    private function drawHeader(): void
    {
        $x0 = -self::HEADER_BLEED;
        $bannerW = self::PAGE_W + 2 * self::HEADER_BLEED;
        $slices = 120;
        $sliceW = $bannerW / $slices;

        for ($i = 0; $i < $slices; $i++) {
            $t = $i / ($slices - 1);
            $color = $this->lerpColor(self::C_GRAD_START, self::C_GRAD_END, $t);
            $this->SetFillColor($color[0], $color[1], $color[2]);
            $this->Rect($x0 + $i * $sliceW, 0, $sliceW + 0.3, self::HEADER_H, 'F');
        }

        $this->SetDrawColor(...self::C_HEADING);
        $this->SetLineWidth(1.0);
        $this->Line($x0, self::HEADER_H, $x0 + $bannerW, self::HEADER_H);

        $textX = self::MARGIN_L;
        $y = 7.0;

        $this->SetTextColor(...self::C_HEADING);
        $this->applyFont(true, false, self::FS_TITLE);
        $this->SetXY($textX, $y);
        $this->Cell(0, 9.5, $this->txt($this->data['header']['title'] ?? ''));
        $y += 9.5;

        $this->applyFont(true, false, self::FS_NAME);
        $this->SetXY($textX, $y);
        $name = trim(($this->data['header']['name'] ?? '') . '  ' . ($this->data['header']['phone'] ?? ''));
        $this->Cell(0, 6.5, $this->txt($name));
        $y += 7.2;

        $this->applyFont(false, false, self::FS_CONTACT);
        $email = (string) ($this->data['header']['email'] ?? '');
        $website = (string) ($this->data['header']['website'] ?? '');
        $websiteUrl = (string) ($this->data['header']['website_url'] ?? ('https://' . $website));

        $this->SetXY($textX, $y);
        if ($email !== '') {
            $emailTxt = $this->txt($email);
            $w = $this->GetStringWidth($emailTxt);
            $this->Cell($w, 5.0, $emailTxt, 0, 0, '', false, 'mailto:' . $email);
            $this->SetX($this->GetX() + 3.0);
        }
        if ($website !== '') {
            $websiteTxt = $this->txt($website);
            $this->Cell($this->GetStringWidth($websiteTxt), 5.0, $websiteTxt, 0, 0, '', false, $websiteUrl);
        }

        // Photo, top right corner of the header.
        $photoX = self::PAGE_W - self::MARGIN_R - self::PHOTO_SIZE;
        $photoY = (self::HEADER_H - self::PHOTO_SIZE) / 2;
        $photoPath = $this->data['photo'] ?? null;

        if ($photoPath && is_file($photoPath)) {
            $this->Image($photoPath, $photoX, $photoY, self::PHOTO_SIZE, self::PHOTO_SIZE);
        } else {
            $this->SetFillColor(...self::C_LOGO_FILL);
            $this->Rect($photoX, $photoY, self::PHOTO_SIZE, self::PHOTO_SIZE, 'F');
        }
        $this->SetDrawColor(...self::C_HEADING);
        $this->SetLineWidth(0.3);
        $this->Rect($photoX, $photoY, self::PHOTO_SIZE, self::PHOTO_SIZE);
    }

    /** @param int[] $a @param int[] $b @return int[] */
    private function lerpColor(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    // =========================================================================
    // Skills (two columns)
    // =========================================================================

    private function drawSkills(float $top, bool $draw): float
    {
        $leftBottom = $this->drawSkillColumn(
            $this->data['skills_left'] ?? [],
            self::MARGIN_L,
            $top,
            self::SKILLS_COL_W,
            $draw
        );
        $rightX = self::MARGIN_L + self::SKILLS_COL_W + self::COL_GAP_SKILLS;
        $rightBottom = $this->drawSkillColumn(
            $this->data['skills_right'] ?? [],
            $rightX,
            $top,
            self::SKILLS_COL_W,
            $draw
        );

        return max($leftBottom, $rightBottom);
    }

    private function drawSkillColumn(array $blocks, float $x, float $y, float $width, bool $draw): float
    {
        foreach ($blocks as $bi => $block) {
            if ($bi > 0) {
                $y += 3.0;
            }
            $y = $this->drawSectionHeading($block['heading'] ?? '', $x, $y, $width, $draw, false);

            if (isset($block['languages'])) {
                foreach ($block['languages'] as $lang) {
                    $y = $this->drawLanguageLine($lang, $x, $y, $width, $draw);
                }
            } else {
                foreach (($block['lines'] ?? []) as $line) {
                    $words = $this->skillLineWords((string) ($line['label'] ?? ''), (string) ($line['value'] ?? ''));
                    $wrapped = $this->wrapStyled($words, $width, self::FS_SKILL);
                    $lh = $this->lineHeightFor(self::FS_SKILL);
                    $y = $this->drawStyledLines($wrapped, $x, $y, $lh, self::FS_SKILL, $draw);
                }
            }
        }

        return $y;
    }

    private function drawLanguageLine(array $lang, float $x, float $y, float $width, bool $draw): float
    {
        $flagW = 8.0;
        $flagH = 5.0;
        $lh = 4.6;

        if ($draw) {
            $this->drawFlag((string) ($lang['code'] ?? ''), $x, $y + 0.3, $flagW, $flagH);
        }

        $this->applyFont(false, false, self::FS_SKILL);
        $this->SetTextColor(...self::C_TEXT_DEFAULT);
        if ($draw) {
            $this->SetXY($x + $flagW + 2.5, $y);
            $this->Cell($width - $flagW - 2.5, $lh, $this->txt((string) ($lang['label'] ?? '')));
        }

        return $y + $lh;
    }

    // =========================================================================
    // Divider
    // =========================================================================

    private function drawDivider(float $y): void
    {
        $this->SetDrawColor(...self::C_HEADING);
        $this->SetLineWidth(0.6);
        $this->Line(self::MARGIN_L, $y, self::MARGIN_L + self::CONTENT_W, $y);
    }

    // =========================================================================
    // Work Experiences (left column)
    // =========================================================================

    private function drawExperiences(float $top, bool $draw): float
    {
        $x = self::MARGIN_L;
        $y = $this->drawSectionHeading('Work Experiences :', $x, $top, self::EXP_COL_W, $draw, true);

        $entries = $this->data['experiences'] ?? [];
        foreach ($entries as $i => $entry) {
            if ($i > 0) {
                $y += $this->entryGap;
            }
            $y = $this->layoutEntry($entry, $x, $y, self::EXP_COL_W, $draw, false);
        }

        return $y;
    }

    // =========================================================================
    // Education (right column) + "free time" pinned to the bottom-right corner
    // =========================================================================

    private function drawEducationAndFreetime(float $top, bool $draw): float
    {
        $x = self::MARGIN_L + self::EXP_COL_W + self::COL_GAP_EXP;
        $y = $this->drawSectionHeading('Education :', $x, $top, self::EDU_COL_W, $draw, true);

        $entries = $this->data['education'] ?? [];
        foreach ($entries as $i => $entry) {
            if ($i > 0) {
                $y += $this->entryGap;
            }
            $y = $this->layoutEntry($entry, $x, $y, self::EDU_COL_W, $draw, true);
        }

        // "Things I like to do in my free time" is pinned to the bottom-right
        // corner of the page: measure its height first, then place it flush
        // with the page's bottom margin, whatever the education column's height.
        $freetimeH = $this->measureFreetime(self::EDU_COL_W);
        $freetimeY = self::PAGE_H - self::MARGIN_BOTTOM - $freetimeH;
        $freetimeTop = max($y + 8.0, $freetimeY);

        if ($draw) {
            $this->drawFreetime($x, $freetimeTop, self::EDU_COL_W);
        }

        return max($y, $freetimeTop + $freetimeH);
    }

    private function measureFreetime(float $width): float
    {
        return $this->layoutFreetime(0, 0, $width, false);
    }

    private function drawFreetime(float $x, float $y, float $width): void
    {
        $h = $this->layoutFreetime($x, $y, $width, false);

        $this->SetDrawColor(...self::C_HEADING);
        $this->SetLineWidth(0.6);
        $this->Line($x, $y, $x + $width, $y);           // top border
        $this->Line($x, $y, $x, $y + $h);                // left border

        $this->layoutFreetime($x + self::FREETIME_PAD, $y + self::FREETIME_PAD, $width - self::FREETIME_PAD, true);
    }

    private function layoutFreetime(float $x, float $y, float $width, bool $draw): float
    {
        $startY = $y;
        $freetime = $this->data['freetime'] ?? [];
        $y += ($draw ? 0 : self::FREETIME_PAD); // account for padding when only measuring

        $y = $this->drawSectionHeading((string) ($freetime['heading'] ?? ''), $x, $y, $width, $draw, false);

        foreach (($freetime['lines'] ?? []) as $line) {
            $words = $this->skillLineWords(
                (string) ($line['label'] ?? ''),
                $line['value_segments'] ?? (string) ($line['value'] ?? '')
            );
            $wrapped = $this->wrapStyled($words, $width, self::FS_SKILL);
            $lh = $this->lineHeightFor(self::FS_SKILL);
            $y = $this->drawStyledLines($wrapped, $x, $y, $lh, self::FS_SKILL, $draw);
        }

        $y += ($draw ? 0 : self::FREETIME_PAD);

        return $y - $startY;
    }

    // =========================================================================
    // One experience/education entry: logo + title (+ did/used, or content)
    // =========================================================================

    private function layoutEntry(array $entry, float $x, float $y, float $width, bool $draw, bool $isEducation): float
    {
        $startY = $y;
        $textX = $x + self::LOGO_SIZE + self::LOGO_TEXT_GAP;
        $textWidth = $width - self::LOGO_SIZE - self::LOGO_TEXT_GAP;

        $title = (string) ($entry['title'] ?? '');
        $forceOneLine = !empty($entry['force_one_line']);

        if ($forceOneLine) {
            $flat = $this->txt(str_replace("\n", ' ', $title));
            $size = $this->shrinkToFit($flat, $textWidth, self::FS_ENTRY_TITLE, self::FS_ENTRY_TITLE_MIN);
            $lh = $this->lineHeightFor($size);
            if ($draw) {
                $this->applyFont(true, false, $size);
                $this->SetTextColor(...self::C_ENTRY_DARK);
                $this->SetXY($textX, $y);
                $this->Cell($textWidth, $lh, $flat);
            }
            $y += $lh;
        } else {
            $words = $this->styledWords($title, true, false, self::C_ENTRY_DARK);
            $wrapped = $this->wrapStyled($words, $textWidth, self::FS_ENTRY_TITLE);
            $lh = $this->lineHeightFor(self::FS_ENTRY_TITLE);
            $y = $this->drawStyledLines($wrapped, $textX, $y, $lh, self::FS_ENTRY_TITLE, $draw);
        }

        if ($isEducation) {
            $content = (string) ($entry['content'] ?? '');
            if ($content !== '') {
                $y += $this->lineGap;
                $words = $this->styledWords($content, false, true, self::C_ENTRY_DARK);
                $wrapped = $this->wrapStyled($words, $textWidth, self::FS_ENTRY_BODY);
                $lh2 = $this->lineHeightFor(self::FS_ENTRY_BODY);
                $y = $this->drawStyledLines($wrapped, $textX, $y, $lh2, self::FS_ENTRY_BODY, $draw);
            }
        } else {
            $did = (string) ($entry['did'] ?? '');
            if ($did !== '') {
                $y += $this->lineGap;
                $words = $this->styledWords($did, false, false, self::C_ENTRY_DARK);
                $wrapped = $this->wrapStyled($words, $textWidth, self::FS_ENTRY_BODY);
                $lh2 = $this->lineHeightFor(self::FS_ENTRY_BODY);
                $y = $this->drawStyledLines($wrapped, $textX, $y, $lh2, self::FS_ENTRY_BODY, $draw);
            }
            $used = (string) ($entry['used'] ?? '');
            if ($used !== '') {
                $y += $this->lineGap;
                $words = $this->styledWords($used, false, true, self::C_VALUE_BLUE);
                $wrapped = $this->wrapStyled($words, $textWidth, self::FS_ENTRY_BODY);
                $lh2 = $this->lineHeightFor(self::FS_ENTRY_BODY);
                $y = $this->drawStyledLines($wrapped, $textX, $y, $lh2, self::FS_ENTRY_BODY, $draw);
            }
        }

        if ($draw) {
            $this->drawLogo($entry['logo'] ?? null, $x, $startY);
        }

        return max($y, $startY + self::LOGO_SIZE);
    }

    private function drawLogo(?string $url, float $x, float $y): void
    {
        $path = $this->logoCache->resolve($url);

        if ($path !== null) {
            try {
                $this->Image($path, $x, $y, self::LOGO_SIZE, self::LOGO_SIZE);
                $this->SetDrawColor(...self::C_HEADING);
                $this->SetLineWidth(0.25);
                $this->Rect($x, $y, self::LOGO_SIZE, self::LOGO_SIZE);
                return;
            } catch (\Throwable $e) {
                // fall through to the placeholder box below
            }
        }

        $this->SetFillColor(...self::C_LOGO_FILL);
        $this->SetDrawColor(...self::C_HEADING);
        $this->SetLineWidth(0.25);
        $this->Rect($x, $y, self::LOGO_SIZE, self::LOGO_SIZE, 'DF');
    }

    // =========================================================================
    // Section headings ("Software Development :", "Work Experiences :", ...)
    // =========================================================================

    private function drawSectionHeading(string $text, float $x, float $y, float $width, bool $draw, bool $bigGap): float
    {
        if ($bigGap) {
            $y += self::SECTION_HEADER_GAP;
        }

        $words = $this->styledWords($text, true, false, self::C_HEADING);
        $wrapped = $this->wrapStyled($words, $width, self::FS_SECTION_HEADING, true);
        $lh = $this->lineHeightFor(self::FS_SECTION_HEADING);
        $y = $this->drawStyledLines($wrapped, $x, $y, $lh, self::FS_SECTION_HEADING, $draw, true);

        if ($bigGap) {
            $y += self::SECTION_HEADER_GAP;
        }

        return $y;
    }

    // =========================================================================
    // Word-wrap / rich-text primitives
    // =========================================================================

    /**
     * Builds a flat list of styled "words" from a string that may contain
     * literal "\n" to force a line break.
     *
     * @param int[] $color
     * @return array<int,array{text:string,bold:bool,italic:bool,color:int[],break:bool,link:?string}>
     */
    private function styledWords(string $text, bool $bold, bool $italic, array $color): array
    {
        $words = [];
        $paragraphs = explode("\n", $text);

        foreach ($paragraphs as $pi => $para) {
            $tokens = preg_split('/\s+/u', trim($para)) ?: [];

            // A lone ":" must never start a new line on its own (e.g. the
            // heading "Network and Telecommunication (Background) :" would
            // otherwise wrap with an orphaned ":" alone on the next line).
            // Glue it back onto the previous token, keeping the visible
            // space before it.
            $merged = [];
            foreach ($tokens as $token) {
                if ($token === ':' && $merged !== []) {
                    $merged[count($merged) - 1] .= ' :';
                } elseif ($token !== '') {
                    $merged[] = $token;
                }
            }

            foreach ($merged as $wi => $token) {
                // Convert to the font's native CP1252 encoding right away,
                // so every later width measurement (word-wrap, shrink-to-fit)
                // is computed on the exact same bytes that get drawn -
                // mixing UTF-8 measurements with CP1252 drawing produces
                // mismatched cell widths (visible as stray extra spacing
                // after accented words).
                $words[] = [
                    'text' => $this->txt($token),
                    'bold' => $bold,
                    'italic' => $italic,
                    'color' => $color,
                    'break' => $pi > 0 && $wi === 0,
                    'link' => null,
                ];
            }
        }

        return $words;
    }

    /**
     * Same as styledWords(), but for a "label : value" skill/freetime line
     * where the label is bold/dark and the value is the default skill colour.
     * $value may be a plain string, or an array of
     * ['text' => ..., 'link' => ...] segments (for inline hyperlinks).
     *
     * @param string|array<int,array{text:string,link?:?string}> $value
     */
    private function skillLineWords(string $label, $value): array
    {
        $words = $this->styledWords($label . ' :', true, false, self::C_ENTRY_DARK);

        if (is_array($value)) {
            foreach ($value as $segment) {
                $segWords = $this->styledWords((string) ($segment['text'] ?? ''), false, false, self::C_VALUE_BLUE);
                $link = $segment['link'] ?? null;
                foreach ($segWords as &$w) {
                    $w['link'] = $link;
                }
                unset($w);
                $words = array_merge($words, $segWords);
            }
        } else {
            $words = array_merge($words, $this->styledWords((string) $value, false, false, self::C_VALUE_BLUE));
        }

        return $words;
    }

    /**
     * Wraps a flat styled-word list into lines that fit within $maxWidth,
     * given the words' own bold/italic flags and the shared $fontSizePt.
     * $forceBold/$forceItalic (when non-null) override each word's own
     * style - used for section headings, which are always bold.
     *
     * @return array<int,array<int,array{text:string,bold:bool,italic:bool,color:int[],link:?string}>>
     */
    private function wrapStyled(array $words, float $maxWidth, float $fontSizePt, ?bool $forceBold = null, ?bool $forceItalic = null): array
    {
        $lines = [];
        $current = [];
        $currentWidth = 0.0;

        $this->applyFont(false, false, $fontSizePt);
        $spaceWidth = $this->GetStringWidth(' ');

        foreach ($words as $w) {
            $bold = $forceBold ?? $w['bold'];
            $italic = $forceItalic ?? $w['italic'];
            $this->applyFont($bold, $italic, $fontSizePt);
            $wordWidth = $this->GetStringWidth($w['text']);

            $needsBreak = $w['break'] && $current !== [];
            $addWidth = $wordWidth + ($current !== [] ? $spaceWidth : 0.0);

            if ($current !== [] && ($needsBreak || $currentWidth + $addWidth > $maxWidth)) {
                $lines[] = $current;
                $current = [];
                $currentWidth = 0.0;
                $addWidth = $wordWidth;
            }

            $current[] = ['text' => $w['text'], 'bold' => $bold, 'italic' => $italic, 'color' => $w['color'], 'link' => $w['link']];
            $currentWidth += $addWidth;
        }

        if ($current !== []) {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Draws (or, when $draw is false, only measures) a set of wrapped lines
     * produced by wrapStyled(), and returns the Y position right after them.
     */
    private function drawStyledLines(array $lines, float $x, float $y, float $lineHeight, float $fontSizePt, bool $draw, bool $underline = false): float
    {
        foreach ($lines as $line) {
            if ($draw) {
                $cx = $x;
                foreach ($line as $i => $w) {
                    $style = ($w['bold'] ? 'B' : '') . ($w['italic'] ? 'I' : '') . ($underline ? 'U' : '');
                    $this->SetFont('Arial', $style, $fontSizePt);
                    $this->SetTextColor(...$w['color']);
                    $suffix = $i < count($line) - 1 ? ' ' : '';
                    $text = $w['text'] . $suffix;
                    $w2 = $this->GetStringWidth($text);
                    $this->SetXY($cx, $y);
                    $this->Cell($w2, $lineHeight, $text, 0, 0, '', false, $w['link'] ?? '');
                    $cx += $w2;
                }
            }
            $y += $lineHeight;
        }

        return $y;
    }

    /**
     * Binary-searches the largest font size (down to $minSize) at which
     * $text fits on a single line within $maxWidth, using bold Arial.
     */
    private function shrinkToFit(string $text, float $maxWidth, float $maxSize, float $minSize): float
    {
        $this->applyFont(true, false, $maxSize);
        if ($this->GetStringWidth($text) <= $maxWidth) {
            return $maxSize;
        }

        $lo = $minSize;
        $hi = $maxSize;
        for ($i = 0; $i < 20; $i++) {
            $mid = ($lo + $hi) / 2;
            $this->applyFont(true, false, $mid);
            if ($this->GetStringWidth($text) <= $maxWidth) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    private function lineHeightFor(float $fontSizePt): float
    {
        return $fontSizePt * 0.3528 * 1.18;
    }

    private function applyFont(bool $bold, bool $italic, float $sizePt): void
    {
        $style = ($bold ? 'B' : '') . ($italic ? 'I' : '');
        $this->SetFont('Arial', $style, $sizePt);
    }

    /**
     * FPDF's core fonts use Windows-1252: convert our UTF-8 source strings
     * before handing them to Cell()/Text(), so accented French characters
     * (é, è, à, ç, …) render correctly.
     */
    private function txt(string $s): string
    {
        $converted = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);

        return $converted !== false ? $converted : $s;
    }

    // =========================================================================
    // Flag swatches for the Languages block
    // =========================================================================

    private function drawFlag(string $code, float $x, float $y, float $w, float $h): void
    {
        $this->SetLineWidth(0.15);
        $this->SetDrawColor(120, 120, 120);

        switch (strtoupper($code)) {
            case 'FR':
                $this->stripe3($x, $y, $w, $h, [0, 38, 84], [255, 255, 255], [206, 17, 38], true);
                break;
            case 'US':
                $this->SetFillColor(178, 34, 52);
                $this->Rect($x, $y, $w, $h, 'F');
                $this->SetFillColor(255, 255, 255);
                for ($i = 1; $i < 7; $i += 2) {
                    $this->Rect($x, $y + $i * $h / 7, $w, $h / 7, 'F');
                }
                $this->SetFillColor(60, 59, 110);
                $this->Rect($x, $y, $w * 0.4, $h * 4 / 7, 'F');
                break;
            case 'ES':
                $this->SetFillColor(170, 21, 27);
                $this->Rect($x, $y, $w, $h, 'F');
                $this->SetFillColor(241, 191, 0);
                $this->Rect($x, $y + $h * 0.25, $w, $h * 0.5, 'F');
                break;
            case 'CN':
                $this->SetFillColor(222, 41, 16);
                $this->Rect($x, $y, $w, $h, 'F');
                $this->SetFillColor(255, 222, 0);
                $this->Rect($x + $w * 0.12, $y + $h * 0.18, $w * 0.16, $h * 0.28, 'F');
                break;
            case 'KE':
                $this->stripe3($x, $y, $w, $h, [0, 0, 0], [200, 16, 46], [0, 104, 60], false);
                break;
            default:
                $this->SetFillColor(...self::C_LOGO_FILL);
                $this->Rect($x, $y, $w, $h, 'F');
                $this->applyFont(true, false, 5.5);
                $this->SetTextColor(...self::C_HEADING);
                $this->SetXY($x, $y + $h / 2 - 1.6);
                $this->Cell($w, 3.2, $code, 0, 0, 'C');
                return;
        }

        $this->Rect($x, $y, $w, $h);
    }

    /** @param int[] $c1 @param int[] $c2 @param int[] $c3 */
    private function stripe3(float $x, float $y, float $w, float $h, array $c1, array $c2, array $c3, bool $vertical): void
    {
        if ($vertical) {
            $sw = $w / 3;
            $this->SetFillColor(...$c1);
            $this->Rect($x, $y, $sw, $h, 'F');
            $this->SetFillColor(...$c2);
            $this->Rect($x + $sw, $y, $sw, $h, 'F');
            $this->SetFillColor(...$c3);
            $this->Rect($x + 2 * $sw, $y, $w - 2 * $sw, $h, 'F');
        } else {
            $sh = $h / 3;
            $this->SetFillColor(...$c1);
            $this->Rect($x, $y, $w, $sh, 'F');
            $this->SetFillColor(...$c2);
            $this->Rect($x, $y + $sh, $w, $sh, 'F');
            $this->SetFillColor(...$c3);
            $this->Rect($x, $y + 2 * $sh, $w, $h - 2 * $sh, 'F');
        }
    }
}
