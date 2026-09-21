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
    private const MARGIN_L = 6.0;
    private const MARGIN_R = 6.0;
    private const MARGIN_TOP = 6.0;
    private const MARGIN_BOTTOM = 7.0;
    private const CONTENT_W = self::PAGE_W - self::MARGIN_L - self::MARGIN_R; // 198

    private const HEADER_H = 40.0;
    private const HEADER_PAD_Y = 7.0; // gap from the header box's top/bottom border to its content
    private const HEADER_PAD_X = self::HEADER_PAD_Y / 2; // left/right padding: half the top/bottom one
    private const PHOTO_SIZE = 26.0;
    private const GAP_HEADER_TO_SKILLS = 6.0;

    private const COL_GAP_SKILLS = 8.0;
    private const SKILLS_COL_W = (self::CONTENT_W - self::COL_GAP_SKILLS) / 2;

    private const DIVIDER_GAP_ABOVE = 3.0;
    private const DIVIDER_GAP_BELOW = 3.0;
    private const DIVIDER_LINE_WIDTH = 0.6; // also used for the header box border
    private const FREETIME_GAP_ABOVE = 8.0; // minimum gap between the last education entry and the freetime box

    private const COL_GAP_EXP = 7.0;
    // Keeps the original 103:76 experience/education proportions regardless
    // of CONTENT_W, instead of hard-coding absolute widths.
    private const EXP_EDU_RATIO = 103.0 / 179.0;
    private const EXP_COL_W = (self::CONTENT_W - self::COL_GAP_EXP) * self::EXP_EDU_RATIO;
    private const EDU_COL_W = (self::CONTENT_W - self::COL_GAP_EXP) - self::EXP_COL_W;

    private const SECTION_HEADER_GAP = 3.0; // above & below the experiences / education section titles

    // Built-in section titles, used whenever cv.php doesn't set its own
    // under 'section_titles' (see sectionTitle()). The trailing " :" is part
    // of the string, like every other heading in the CV data.
    private const DEFAULT_TITLE_EXPERIENCES = 'Work Experiences :';
    private const DEFAULT_TITLE_EDUCATION   = 'Education :';

    private const LOGO_SIZE = 8.0;
    private const LOGO_TEXT_GAP = 3.0;

    // The semantic-ui-flag sprite's flags are 16x11px, authored for 96dpi
    // screens; drawing them at that same physical size (rather than
    // stretching them to fill a bigger box) keeps them crisp instead of
    // soft/blocky.
    private const FLAG_NATIVE_W_PX = 16.0;
    private const FLAG_NATIVE_H_PX = 11.0;
    private const PX_TO_MM_96DPI = 25.4 / 96.0;

    private const FREETIME_PAD = 2.5;

    // ---- Colours (RGB 0-255) ----------------------------------------------
    private const C_HEADING      = [27, 63, 139];   // section headings text
    private const C_ENTRY_DARK   = [20, 34, 79];    // "what was done" / "skills used" (dark side)
    private const C_VALUE_BLUE   = [46, 111, 217];  // skill values / "skills used" (light side)
    private const C_EDU_CONTENT  = [33, 73, 148];   // education descriptions: halfway between the two above
    private const C_LOGO_FILL    = [207, 216, 234];
    private const C_TEXT_DEFAULT = [20, 20, 20];
    private const C_GRAD_START   = [197, 202, 209]; // header banner: darker grey
    private const C_GRAD_END     = [231, 233, 236]; // header banner: lighter grey (never pure white)
    private const C_DARK_GREY    = [90, 90, 90];    // header box border + section dividers

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
    private FlagSpriteCache $flagCache;

    // Spacing "levers" tightened by fitContent() until everything fits.
    // Experience/education entries are stretched independently, after the
    // base spacing/font tier is chosen, to fill whatever vertical room is
    // left in each column (so entries spread out with a page-filling
    // rhythm instead of bunching at the top with dead space below).
    private float $expEntryGap = 7.0;
    private float $eduEntryGap = 7.0;
    private float $lineGap  = 0.9;  // gap between title/did/used (or title/content) lines
    // Font sizes for entry titles/bodies: start at the normal sizes, only
    // tightened by fitContent() as a last resort, after spacing alone no
    // longer buys enough room - this matters most for languages whose text
    // simply runs longer than English/French for the same content (word-
    // for-word German, say), where tighter spacing eventually stops
    // helping because the real problem is wrapped *line count*, not gaps.
    private float $bodyFontSize  = self::FS_ENTRY_BODY;
    private float $titleFontSize = self::FS_ENTRY_TITLE;

    public function __construct(array $data, LogoCache $logoCache, FlagSpriteCache $flagCache)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->data = $data;
        $this->logoCache = $logoCache;
        $this->flagCache = $flagCache;

        $this->SetAutoPageBreak(false);
        $this->SetMargins(self::MARGIN_L, 0, self::MARGIN_R);
        $title = 'CV';
        if (isset($data['header']['name']) && is_string($data['header']['name'])) {
            $title = $data['header']['name'];
        }

        if (isset($data['header']['title']) && is_string($data['header']['title'])) {
            $title .= ' - ' . $data['header']['title'];
        }
        $this->SetTitle($title, true);
        $this->SetCreator('cv-generator');
        $this->SetDisplayMode('fullpage');
    }

    public function build(): void
    {
        $this->fitContent();

        $this->AddPage();
        $this->drawHeader();

        $skillsBottom = $this->drawSkills(self::MARGIN_TOP + self::HEADER_H + self::GAP_HEADER_TO_SKILLS, true);
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
        $skillsBottom = $this->drawSkills(self::MARGIN_TOP + self::HEADER_H + self::GAP_HEADER_TO_SKILLS, false);
        $rowTop = $skillsBottom + self::DIVIDER_GAP_ABOVE + self::DIVIDER_GAP_BELOW;
        $available = self::PAGE_H - self::MARGIN_BOTTOM - $rowTop;

        // [entryGap, lineGap, bodyFontSize, titleFontSize], tried in order.
        // The first tiers only tighten spacing, keeping the normal font
        // sizes - that alone covers most content. Once spacing alone isn't
        // enough, later tiers also trim the font size a little: that helps
        // far more for a language whose text simply runs longer for the
        // same content (French vs English, German more so still), since a
        // smaller font also reduces how many lines things *wrap* onto, not
        // just the gap between those lines.
        $tiers = [
            [7.0, 0.9, self::FS_ENTRY_BODY, self::FS_ENTRY_TITLE],
            [6.0, 0.8, self::FS_ENTRY_BODY, self::FS_ENTRY_TITLE],
            [5.0, 0.6, self::FS_ENTRY_BODY, self::FS_ENTRY_TITLE],
            [4.0, 0.5, self::FS_ENTRY_BODY, self::FS_ENTRY_TITLE],
            [3.2, 0.4, self::FS_ENTRY_BODY, self::FS_ENTRY_TITLE],
            [2.6, 0.3, self::FS_ENTRY_BODY, self::FS_ENTRY_TITLE],
            [2.0, 0.3, self::FS_ENTRY_BODY, self::FS_ENTRY_TITLE],
            [1.5, 0.2, self::FS_ENTRY_BODY, self::FS_ENTRY_TITLE],
            [1.0, 0.15, self::FS_ENTRY_BODY, self::FS_ENTRY_TITLE],
            [1.0, 0.15, 7.9, 9.0],
            [1.0, 0.15, 7.6, 8.8],
            [1.0, 0.15, 7.3, 8.5],
            [1.0, 0.15, 7.0, 8.2],
            [1.0, 0.15, 6.8, 8.0],
            [1.0, 0.15, 6.6, 7.8],
            [1.0, 0.15, 6.4, 7.6],
        ];

        $expBottom = $eduBottom = PHP_FLOAT_MAX;

        foreach ($tiers as [$gap, $lineGap, $bodyFs, $titleFs]) {
            $this->expEntryGap = $gap;
            $this->eduEntryGap = $gap;
            $this->lineGap = $lineGap;
            $this->bodyFontSize = $bodyFs;
            $this->titleFontSize = $titleFs;

            $expBottom = $this->drawExperiences($rowTop, false);
            $eduBottom = $this->drawEducationAndFreetime($rowTop, false);

            $expH = $expBottom - $rowTop;
            $eduH = $eduBottom - $rowTop;

            if ($expH <= $available && $eduH <= $available) {
                $this->stretchColumns($rowTop, $available);
                return;
            }
        }

        throw new RuntimeException(sprintf(
            'CV content does not fit on a single A4 page even at the tightest spacing ' .
            'and smallest font size (experience column needs %.1fmm, education column needs %.1fmm, ' .
            'only %.1fmm available). Please shorten some entries in cv.php.',
            $expBottom - $rowTop,
            $eduBottom - $rowTop,
            $available
        ));
    }

    /**
     * Once a spacing/font tier that fits has been found, spread each
     * column's entries across whatever vertical room is actually left in
     * it - so entries fill the page with a deliberate, even rhythm instead
     * of bunching at the top with dead space left below (or, for
     * education, left above the pinned freetime box).
     */
    private function stretchColumns(float $rowTop, float $available): void
    {
        $expCount = count($this->data['experiences'] ?? []);
        if ($expCount > 1) {
            $expH = $this->drawExperiences($rowTop, false) - $rowTop;
            $slack = $available - $expH;
            if ($slack > 0) {
                $this->expEntryGap += $slack / ($expCount - 1);
            }
        }

        $eduCount = count($this->data['education'] ?? []);
        if ($eduCount > 1) {
            $freetimeH = $this->measureFreetime(self::EDU_COL_W);
            $freetimeTargetTop = max(
                self::PAGE_H - self::MARGIN_BOTTOM - $freetimeH,
                $rowTop
            );
            $availableForEntries = $freetimeTargetTop - self::FREETIME_GAP_ABOVE - $rowTop;
            $eduH = $this->drawEducationEntries($rowTop, false) - $rowTop;
            $slack = $availableForEntries - $eduH;
            if ($slack > 0) {
                $this->eduEntryGap += $slack / ($eduCount - 1);
            }
        }
    }

    // =========================================================================
    // Header
    // =========================================================================

    private function drawHeader(): void
    {
        $x0 = self::MARGIN_L;
        $y0 = self::MARGIN_TOP;
        $boxW = self::CONTENT_W;
        $slices = 120;
        $sliceW = $boxW / $slices;

        for ($i = 0; $i < $slices; $i++) {
            $t = $i / ($slices - 1);
            $color = $this->lerpColor(self::C_GRAD_START, self::C_GRAD_END, $t);
            $this->SetFillColor($color[0], $color[1], $color[2]);
            $this->Rect($x0 + $i * $sliceW, $y0, $sliceW + 0.3, self::HEADER_H, 'F');
        }

        // A contained box (not bleeding past the page margins), bordered at
        // the same weight as the section dividers.
        $this->SetDrawColor(...self::C_DARK_GREY);
        $this->SetLineWidth(self::DIVIDER_LINE_WIDTH);
        $this->Rect($x0, $y0, $boxW, self::HEADER_H);

        $textX = $x0 + self::HEADER_PAD_X;
        $photoX = ($x0 + $boxW) - self::HEADER_PAD_X - self::PHOTO_SIZE;
        // How much horizontal room the 3 text lines have before the photo.
        $textMaxWidth = $photoX - $textX - 5.0;
        // How much vertical room they have, between the top and bottom padding.
        $textMaxHeight = self::HEADER_H - 2 * self::HEADER_PAD_Y;

        $title = (string) ($this->data['header']['title'] ?? '');
        $name = trim(($this->data['header']['name'] ?? '') . '  ' . ($this->data['header']['phone'] ?? ''));
        $email = (string) ($this->data['header']['email'] ?? '');
        $website = (string) ($this->data['header']['website'] ?? '');
        $websiteUrl = (string) ($this->data['header']['website_url'] ?? ('https://' . $website));

        $titleTxt = $this->txt($title);
        $nameTxt = $this->txt($name);
        $emailTxt = $this->txt($email);
        $websiteTxt = $this->txt($website);
        $contactGap = ($email !== '' && $website !== '') ? 3.0 : 0.0;

        $this->applyFont(true, false, self::FS_TITLE);
        $titleW = $this->GetStringWidth($titleTxt);
        $this->applyFont(true, false, self::FS_NAME);
        $nameW = $this->GetStringWidth($nameTxt);
        $this->applyFont(false, false, self::FS_CONTACT);
        $contactW = $this->GetStringWidth($emailTxt) + $contactGap + $this->GetStringWidth($websiteTxt);

        // Scale all 3 lines up together (keeping their relative sizes, so
        // the title stays the biggest, etc.) by whichever amount lets the
        // widest-relative-to-its-own-size line reach the available width,
        // without any line's height pushing the stack past the available
        // vertical room. Never shrinks below the base sizes, and capped so
        // one short line alone can't blow the scale up absurdly.
        $baseHeightSum = $this->lineHeightFor(self::FS_TITLE)
            + $this->lineHeightFor(self::FS_NAME)
            + $this->lineHeightFor(self::FS_CONTACT);

        $scale = min(
            $titleW > 0 ? $textMaxWidth / $titleW : PHP_FLOAT_MAX,
            $nameW > 0 ? $textMaxWidth / $nameW : PHP_FLOAT_MAX,
            $contactW > 0 ? $textMaxWidth / $contactW : PHP_FLOAT_MAX,
            $textMaxHeight / $baseHeightSum,
            1.6
        );
        // No artificial floor of 1.0 here: if even the base sizes don't
        // fit (a very long title, say), $scale legitimately needs to drop
        // below 1 to avoid the title overlapping the photo - the min()
        // above already keeps every line within the available box either
        // way, growing when there's room to spare and shrinking only when
        // truly necessary.

        $sizeTitle = self::FS_TITLE * $scale;
        $sizeName = self::FS_NAME * $scale;
        $sizeContact = self::FS_CONTACT * $scale;
        $lhTitle = $this->lineHeightFor($sizeTitle);
        $lhName = $this->lineHeightFor($sizeName);
        $lhContact = $this->lineHeightFor($sizeContact);

        // Centre the (now taller) 3-line block in the leftover vertical
        // space rather than always hugging the top padding.
        $y = $y0 + (self::HEADER_H - ($lhTitle + $lhName + $lhContact)) / 2;

        $this->SetTextColor(...self::C_HEADING);
        $this->applyFont(true, false, $sizeTitle);
        $this->SetXY($textX, $y);
        $this->Cell(0, $lhTitle, $titleTxt);
        $y += $lhTitle;

        $this->applyFont(true, false, $sizeName);
        $this->SetXY($textX, $y);
        $this->Cell(0, $lhName, $nameTxt);
        $y += $lhName;

        $this->applyFont(false, false, $sizeContact);
        $this->SetXY($textX, $y);
        if ($email !== '') {
            $w = $this->GetStringWidth($emailTxt);
            $this->Cell($w, $lhContact, $emailTxt, 0, 0, '', false, 'mailto:' . $email);
            $this->SetX($this->GetX() + $contactGap);
        }
        if ($website !== '') {
            $this->Cell($this->GetStringWidth($websiteTxt), $lhContact, $websiteTxt, 0, 0, '', false, $websiteUrl);
        }

        // Photo, top right corner of the header box.
        $photoY = $y0 + (self::HEADER_H - self::PHOTO_SIZE) / 2;
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
            $y = $this->drawSectionHeading($block['heading'] ?? '', $x, $y, $width, $draw, false, self::C_TEXT_DEFAULT);

            if (isset($block['languages'])) {
                foreach ($block['languages'] as $lang) {
                    $y = $this->drawLanguageLine($lang, $x, $y, $width, $draw);
                }
            } else {
                foreach (($block['lines'] ?? []) as $line) {
                    $words = $this->skillLineWords((string) ($line['label'] ?? ''), $line['value'] ?? '');
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
        // The flag sits a little inset from the column's left edge, and the
        // label starts right after it with only a small gap - previously
        // both were separated by an oversized fixed "slot", which read as a
        // big empty margin between the flag and its text.
        $flagInsetX = 1.2;
        $textGap = 1.3;
        $flagW = self::FLAG_NATIVE_W_PX * self::PX_TO_MM_96DPI;
        $flagH = self::FLAG_NATIVE_H_PX * self::PX_TO_MM_96DPI;
        $lh = 4.6;

        $flagX = $x + $flagInsetX;
        $textX = $flagX + $flagW + $textGap;

        if ($draw) {
            $flagY = $y + ($lh - $flagH) / 2;
            $this->drawFlag((string) ($lang['code'] ?? ''), $flagX, $flagY, $flagW, $flagH, $flagW + $flagInsetX, $lh - 0.6);
        }

        $this->applyFont(false, false, self::FS_SKILL);
        $this->SetTextColor(...self::C_TEXT_DEFAULT);
        if ($draw) {
            $this->SetXY($textX, $y);
            $this->Cell($width - ($textX - $x), $lh, $this->txt((string) ($lang['label'] ?? '')));
        }

        return $y + $lh;
    }

    // =========================================================================
    // Divider
    // =========================================================================

    private function drawDivider(float $y): void
    {
        $this->SetDrawColor(...self::C_DARK_GREY);
        $this->SetLineWidth(self::DIVIDER_LINE_WIDTH);
        $this->Line(self::MARGIN_L, $y, self::MARGIN_L + self::CONTENT_W, $y);
    }

    /**
     * Title shown above the experiences / education columns: the value from
     * $data['section_titles'][$key] when cv.php sets one, otherwise $default.
     */
    private function sectionTitle(string $key, string $default): string
    {
        $title = $this->data['section_titles'][$key] ?? null;

        return is_string($title) && trim($title) !== '' ? $title : $default;
    }

    // =========================================================================
    // Work Experiences (left column)
    // =========================================================================

    private function drawExperiences(float $top, bool $draw): float
    {
        $x = self::MARGIN_L;
        $y = $this->drawSectionHeading($this->sectionTitle('experiences', self::DEFAULT_TITLE_EXPERIENCES), $x, $top, self::EXP_COL_W, $draw, true, self::C_TEXT_DEFAULT);

        $entries = $this->data['experiences'] ?? [];
        foreach ($entries as $i => $entry) {
            if ($i > 0) {
                $y += $this->expEntryGap;
            }
            $y = $this->layoutEntry($entry, $x, $y, self::EXP_COL_W, $draw, false);
        }

        return $y;
    }

    // =========================================================================
    // Education (right column) + "free time" pinned to the bottom-right corner
    // =========================================================================

    private function drawEducationEntries(float $top, bool $draw): float
    {
        $x = self::MARGIN_L + self::EXP_COL_W + self::COL_GAP_EXP;
        $y = $this->drawSectionHeading($this->sectionTitle('education', self::DEFAULT_TITLE_EDUCATION), $x, $top, self::EDU_COL_W, $draw, true, self::C_TEXT_DEFAULT);

        $entries = $this->data['education'] ?? [];
        foreach ($entries as $i => $entry) {
            if ($i > 0) {
                $y += $this->eduEntryGap;
            }
            $y = $this->layoutEntry($entry, $x, $y, self::EDU_COL_W, $draw, true);
        }

        return $y;
    }

    private function drawEducationAndFreetime(float $top, bool $draw): float
    {
        $x = self::MARGIN_L + self::EXP_COL_W + self::COL_GAP_EXP;
        $y = $this->drawEducationEntries($top, $draw);

        // "Things I like to do in my free time" is pinned to the bottom-right
        // corner of the page: measure its height first, then place it flush
        // with the page's bottom margin, whatever the education column's height.
        $freetimeH = $this->measureFreetime(self::EDU_COL_W);
        $freetimeY = self::PAGE_H - self::MARGIN_BOTTOM - $freetimeH;
        $freetimeTop = max($y + self::FREETIME_GAP_ABOVE, $freetimeY);

        if ($draw) {
            $this->drawFreetime($x, $freetimeTop, self::EDU_COL_W);
        }

        return max($y, $freetimeTop + $freetimeH);
    }

    private function measureFreetime(float $width): float
    {
        $innerWidth = $width - 2 * self::FREETIME_PAD;

        return $this->layoutFreetime(0, 0, $innerWidth, false) + 2 * self::FREETIME_PAD;
    }

    private function drawFreetime(float $x, float $y, float $width): void
    {
        // Measure at the same (padded) width used for the actual content
        // below, not the full column width - otherwise the box would be
        // sized as if text wrapped less than it actually does once padding
        // narrows the usable width, throwing off its computed height.
        $innerWidth = $width - 2 * self::FREETIME_PAD;
        $h = $this->layoutFreetime($x + self::FREETIME_PAD, $y + self::FREETIME_PAD, $innerWidth, false)
            + 2 * self::FREETIME_PAD;

        $this->SetDrawColor(...self::C_DARK_GREY);
        $this->SetLineWidth(self::DIVIDER_LINE_WIDTH);
        $this->Line($x, $y, $x + $width, $y);           // top border
        $this->Line($x, $y, $x, $y + $h);                // left border

        $this->layoutFreetime($x + self::FREETIME_PAD, $y + self::FREETIME_PAD, $innerWidth, true);
    }

    private function layoutFreetime(float $x, float $y, float $width, bool $draw): float
    {
        $startY = $y;
        $freetime = $this->data['freetime'] ?? [];

        $y = $this->drawSectionHeading((string) ($freetime['heading'] ?? ''), $x, $y, $width, $draw, false);

        foreach (($freetime['lines'] ?? []) as $line) {
            $words = $this->skillLineWords((string) ($line['label'] ?? ''), $line['value'] ?? '');
            $wrapped = $this->wrapStyled($words, $width, self::FS_SKILL);
            $lh = $this->lineHeightFor(self::FS_SKILL);
            $y = $this->drawStyledLines($wrapped, $x, $y, $lh, self::FS_SKILL, $draw);
        }

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
            $size = $this->shrinkToFit($flat, $textWidth, $this->titleFontSize, self::FS_ENTRY_TITLE_MIN);
            $lh = $this->lineHeightFor($size);
            if ($draw) {
                $this->applyFont(true, false, $size);
                $this->SetTextColor(...self::C_TEXT_DEFAULT);
                // Text(), not Cell(), so this lines up flush with every
                // other (wrapped) entry title - see drawStyledLines().
                $this->Text($textX, $y + 0.5 * $lh + 0.3 * $this->FontSize, $flat);
            }
            $y += $lh;
        } else {
            $words = $this->styledWords($title, true, false, self::C_TEXT_DEFAULT);
            $wrapped = $this->wrapStyled($words, $textWidth, $this->titleFontSize);
            $lh = $this->lineHeightFor($this->titleFontSize);
            $y = $this->drawStyledLines($wrapped, $textX, $y, $lh, $this->titleFontSize, $draw);
        }

        if ($isEducation) {
            $content = $entry['content'] ?? '';
            if (!empty($content)) {
                $y += $this->lineGap;
                $words = $this->styledWordsFromValue($content, false, true, self::C_EDU_CONTENT);
                $wrapped = $this->wrapStyled($words, $textWidth, $this->bodyFontSize);
                $lh2 = $this->lineHeightFor($this->bodyFontSize);
                $y = $this->drawStyledLines($wrapped, $textX, $y, $lh2, $this->bodyFontSize, $draw);
            }
        } else {
            $did = $entry['did'] ?? '';
            if (!empty($did)) {
                $y += $this->lineGap;
                $words = $this->styledWordsFromValue($did, false, false, self::C_ENTRY_DARK);
                $wrapped = $this->wrapStyled($words, $textWidth, $this->bodyFontSize);
                $lh2 = $this->lineHeightFor($this->bodyFontSize);
                $y = $this->drawStyledLines($wrapped, $textX, $y, $lh2, $this->bodyFontSize, $draw);
            }
            $used = $entry['used'] ?? '';
            if (!empty($used)) {
                $y += $this->lineGap;
                $words = $this->styledWordsFromValue($used, false, true, self::C_VALUE_BLUE);
                $wrapped = $this->wrapStyled($words, $textWidth, $this->bodyFontSize);
                $lh2 = $this->lineHeightFor($this->bodyFontSize);
                $y = $this->drawStyledLines($wrapped, $textX, $y, $lh2, $this->bodyFontSize, $draw);
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
                return;
            } catch (\Throwable $e) {
                // fall through to the placeholder box below
            }
        }

        $this->SetFillColor(...self::C_LOGO_FILL);
        $this->Rect($x, $y, self::LOGO_SIZE, self::LOGO_SIZE, 'F');
    }

    // =========================================================================
    // Section headings ("Software Development :", the experiences / education titles, ...)
    // =========================================================================

    private function drawSectionHeading(string $text, float $x, float $y, float $width, bool $draw, bool $bigGap, ?array $color = null): float
    {
        $color = $color ?? self::C_HEADING;
        $floorSize = 8.5;

        if ($bigGap) {
            $y += self::SECTION_HEADER_GAP;
        }

        // Headings read best on a single line (e.g. "Network and
        // Telecommunication (Background) :" wrapping with just "(Background) :"
        // orphaned onto its own line looked bad), so shrink the font just
        // enough to make that happen instead of wrapping whenever possible.
        $flat = $this->txt(str_replace("\n", ' ', $text));
        $this->applyFont(true, false, self::FS_SECTION_HEADING);
        $size = $this->GetStringWidth($flat) <= $width
            ? self::FS_SECTION_HEADING
            : $this->shrinkToFit($flat, $width, self::FS_SECTION_HEADING, $floorSize);

        $this->applyFont(true, false, $size);

        if ($this->GetStringWidth($flat) <= $width) {
            $lh = $this->lineHeightFor($size);
            if ($draw) {
                $this->SetFont('Arial', 'BU', $size);
                $this->SetTextColor(...$color);
                // Text() (no implicit left padding) instead of Cell(), so
                // headings line up flush with the body text below them,
                // which is also drawn with Text() (see drawStyledLines()).
                $this->Text($x, $y + 0.5 * $lh + 0.3 * $this->FontSize, $flat);
            }
            $y += $lh;
        } else {
            // Safety net for an even longer heading than expected: wrap
            // rather than let it overflow past the column.
            $words = $this->styledWords($text, true, false, $color);
            $wrapped = $this->wrapStyled($words, $width, $floorSize, true);
            $lh = $this->lineHeightFor($floorSize);
            $y = $this->drawStyledLines($wrapped, $x, $y, $lh, $floorSize, $draw, true);
        }

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
                // A token may itself contain one or more flag emoji glued to
                // surrounding punctuation with no space (e.g. "\u{1F1FA}\u{1F1F8},"),
                // so split it into text/flag pieces first. Only the first
                // piece of a token can start a new line ('break') or needs a
                // space before it from the previous token ('glue' = false);
                // every other piece stays glued directly to what precedes it.
                foreach ($this->splitFlagPieces($token) as $pj => $piece) {
                    $isFirst = $pj === 0;

                    if ($piece['type'] === 'flag') {
                        $words[] = [
                            'type' => 'flag',
                            'code' => $piece['value'],
                            'bold' => $bold,
                            'italic' => $italic,
                            'color' => $color,
                            'break' => $isFirst && $pi > 0 && $wi === 0,
                            'glue' => !$isFirst,
                            'link' => null,
                        ];
                    } elseif ($piece['value'] !== '') {
                        // Convert to the font's native CP1252 encoding right
                        // away, so every later width measurement (word-wrap,
                        // shrink-to-fit) is computed on the exact same bytes
                        // that get drawn - mixing UTF-8 measurements with
                        // CP1252 drawing produces mismatched cell widths
                        // (visible as stray extra spacing after accented
                        // words).
                        $words[] = [
                            'type' => 'text',
                            'text' => $this->txt($piece['value']),
                            'bold' => $bold,
                            'italic' => $italic,
                            'color' => $color,
                            'break' => $isFirst && $pi > 0 && $wi === 0,
                            'glue' => !$isFirst,
                            'link' => null,
                        ];
                    }
                }
            }
        }

        return $words;
    }

    /**
     * Splits a whitespace-free token into an ordered list of
     * ['type' => 'text'|'flag', 'value' => ...] pieces, extracting any
     * regional-indicator flag-emoji pair (e.g. \u{1F1FA}\u{1F1F8} = "US") found inside it -
     * even when glued directly to punctuation, like "\u{1F1FA}\u{1F1F8},".
     * A 'flag' piece's value is the resulting 2-letter country code.
     *
     * @return array<int,array{type:string,value:string}>
     */
    private function splitFlagPieces(string $token): array
    {
        $parts = preg_split(
            '/([\x{1F1E6}-\x{1F1FF}]{2})/u',
            $token,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ($parts === false) {
            return [['type' => 'text', 'value' => $token]];
        }

        $pieces = [];
        foreach ($parts as $part) {
            if (preg_match('/^[\x{1F1E6}-\x{1F1FF}]{2}$/u', $part) === 1) {
                $pieces[] = ['type' => 'flag', 'value' => $this->flagCodeFromEmoji($part)];
            } else {
                $pieces[] = ['type' => 'text', 'value' => $part];
            }
        }

        return $pieces;
    }

    /**
     * Decodes a two-codepoint regional-indicator flag emoji (e.g.
     * \u{1F1EB}\u{1F1F7}) into its 2-letter country code ("FR").
     */
    private function flagCodeFromEmoji(string $pair): string
    {
        $letters = '';

        foreach (mb_str_split($pair, 1, 'UTF-8') ?: [] as $char) {
            $bytes = array_map('ord', str_split($char));
            if (count($bytes) === 4) {
                $codepoint = (($bytes[0] & 0x07) << 18)
                    | (($bytes[1] & 0x3F) << 12)
                    | (($bytes[2] & 0x3F) << 6)
                    | ($bytes[3] & 0x3F);
                $letters .= chr(ord('A') + ($codepoint - 0x1F1E6));
            }
        }

        return $letters;
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

        return array_merge($words, $this->styledWordsFromValue($value, false, false, self::C_VALUE_BLUE));
    }

    /**
     * Builds a styled-word list for any text field that may either be a
     * plain string, or an array of ['text' => ..., 'link' => ?string]
     * segments used to turn part of the text into a hyperlink (see
     * styledWords() for the plain-string case, used directly here). This
     * is the single mechanism behind every linkable field in the CV: skill
     * values, freetime lines, and each entry's "did"/"used"/"content".
     *
     * @param string|array<int,array{text:string,link?:?string}> $value
     */
    private function styledWordsFromValue($value, bool $bold, bool $italic, array $color): array
    {
        if (!is_array($value)) {
            return $this->styledWords((string) $value, $bold, $italic, $color);
        }

        $words = [];
        // A "\n" at the very end of a segment's text has no following word
        // *within that same segment* to carry the forced-line-break flag,
        // so the break would otherwise be silently lost at the boundary.
        // Remember it here and apply it to the next segment's first word
        // instead.
        $pendingBreak = false;

        foreach ($value as $si => $segment) {
            $segText = (string) ($segment['text'] ?? '');
            $segWords = $this->styledWords($segText, $bold, $italic, $color);
            $link = $segment['link'] ?? null;

            foreach ($segWords as &$w) {
                $w['link'] = $link;
            }
            unset($w);

            if ($segWords !== []) {
                if ($pendingBreak) {
                    $segWords[0]['break'] = true;
                    $segWords[0]['glue'] = false;
                } elseif ($si > 0 && preg_match('/^[,.;:!?)]/', ltrim($segText)) === 1) {
                    // A segment continues a sentence, it doesn't start a new
                    // one. If it begins with punctuation that conventionally
                    // has no space before it (a comma, a closing parenthesis,
                    // ...) - typically a ", " segment right after a link -
                    // glue its first word to the previous segment's last word
                    // instead of inserting the usual space between words.
                    $segWords[0]['glue'] = true;
                }
                $pendingBreak = false;
            }

            if (preg_match('/\n\s*$/', $segText) === 1) {
                $pendingBreak = true;
            }

            $words = array_merge($words, $segWords);
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
        $flagWidth = self::FLAG_NATIVE_W_PX * self::PX_TO_MM_96DPI;

        foreach ($words as $w) {
            $bold = $forceBold ?? $w['bold'];
            $italic = $forceItalic ?? $w['italic'];
            $isFlag = ($w['type'] ?? 'text') === 'flag';
            $glue = $w['glue'] ?? false;

            if ($isFlag) {
                $wordWidth = $flagWidth;
            } else {
                $this->applyFont($bold, $italic, $fontSizePt);
                $wordWidth = $this->GetStringWidth($w['text']);
            }

            $needsBreak = ($w['break'] ?? false) && $current !== [];
            $spaceBefore = ($current !== [] && !$glue) ? $spaceWidth : 0.0;
            $addWidth = $wordWidth + $spaceBefore;

            if ($current !== [] && ($needsBreak || $currentWidth + $addWidth > $maxWidth)) {
                $lines[] = $current;
                $current = [];
                $currentWidth = 0.0;
                $addWidth = $wordWidth;
            }

            $entry = [
                'type' => $isFlag ? 'flag' : 'text',
                'bold' => $bold,
                'italic' => $italic,
                'color' => $w['color'],
                'link' => $w['link'] ?? null,
                // A word can only be "glued" to whatever precedes it within
                // the SAME line; a word that starts a fresh line never needs
                // a leading space anyway.
                'glue' => $current === [] ? false : $glue,
            ];
            $entry[$isFlag ? 'code' : 'text'] = $isFlag ? $w['code'] : $w['text'];

            $current[] = $entry;
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
        $flagW = self::FLAG_NATIVE_W_PX * self::PX_TO_MM_96DPI;
        $flagH = self::FLAG_NATIVE_H_PX * self::PX_TO_MM_96DPI;

        foreach ($lines as $line) {
            if ($draw) {
                $this->applyFont(false, false, $fontSizePt);
                $spaceWidth = $this->GetStringWidth(' ');

                $cx = $x;
                $n = count($line);
                // One entry per word: its drawn span and, for text words,
                // the exact font metrics used - so a run of consecutive
                // linked words can get ONE continuous underline afterwards
                // (see drawLinkUnderlines()), instead of relying on FPDF's
                // own per-word underline, which stops at each word's own
                // glyphs and leaves visible gaps over the spaces between
                // them.
                $positions = [];

                foreach ($line as $i => $w) {
                    $nextGlued = $i < $n - 1 && ($line[$i + 1]['glue'] ?? false);
                    $trailingSpace = ($i < $n - 1 && !$nextGlued) ? $spaceWidth : 0.0;
                    $startX = $cx;
                    $baseline = null;
                    $up = null;
                    $ut = null;

                    if (($w['type'] ?? 'text') === 'flag') {
                        $flagY = $y + ($lineHeight - $flagH) / 2;
                        $this->drawFlag($w['code'], $cx, $flagY, $flagW, $flagH, $flagW, $flagH);
                        if (!empty($w['link'])) {
                            $this->Link($cx, $flagY, $flagW, $flagH, $w['link']);
                        }
                        $cx += $flagW;
                    } else {
                        $style = ($w['bold'] ? 'B' : '') . ($w['italic'] ? 'I' : '') . ($underline ? 'U' : '');
                        $this->SetFont('Arial', $style, $fontSizePt);
                        $this->SetTextColor(...$w['color']);
                        $text = $w['text'];
                        $w2 = $this->GetStringWidth($text);
                        $baseline = $y + 0.5 * $lineHeight + 0.3 * $this->FontSize;
                        // Text() places the string at an exact baseline with
                        // no implicit left padding, unlike Cell() (which
                        // insets text by cMargin) - that padding cancels out
                        // between two Cell()-drawn words, but not between a
                        // Cell() word and an Image()-drawn flag, which was
                        // leaving a stray gap right after every flag.
                        $this->Text($cx, $baseline, $text);
                        if (!empty($w['link'])) {
                            $this->Link($cx, $y, $w2, $lineHeight, $w['link']);
                        }
                        $up = $this->CurrentFont['up'] ?? -100;
                        $ut = $this->CurrentFont['ut'] ?? 50;
                        $cx += $w2;
                    }

                    $positions[] = [
                        'start' => $startX,
                        'end' => $cx,
                        'link' => $w['link'] ?? null,
                        'color' => $w['color'],
                        'baseline' => $baseline,
                        'up' => $up,
                        'ut' => $ut,
                        'fontSizeUser' => $this->FontSize,
                    ];

                    $cx += $trailingSpace;
                }

                $this->drawLinkUnderlines($positions);
            }
            $y += $lineHeight;
        }

        return $y;
    }

    /**
     * Draws one continuous underline per contiguous run of words that
     * share the same link (spanning the gaps/spaces between them too),
     * using the same position/thickness formula FPDF's own automatic
     * underline uses internally, just applied to the whole run's width
     * rather than one word at a time.
     */
    private function drawLinkUnderlines(array $positions): void
    {
        $n = count($positions);
        $i = 0;

        while ($i < $n) {
            $link = $positions[$i]['link'];

            if (empty($link) || $positions[$i]['baseline'] === null) {
                $i++;
                continue;
            }

            $j = $i;
            while ($j + 1 < $n && ($positions[$j + 1]['link'] ?? null) === $link && $positions[$j + 1]['baseline'] !== null) {
                $j++;
            }

            $p = $positions[$i];
            $x1 = $p['start'];
            $x2 = $positions[$j]['end'];
            $yTop = $p['baseline'] - $p['up'] / 1000 * $p['fontSizeUser'];
            $h = max($p['ut'] / 1000 * $p['fontSizeUser'], 0.15);

            $this->SetFillColor(...$p['color']);
            $this->Rect($x1, $yTop, $x2 - $x1, $h, 'F');

            $i = $j + 1;
        }
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

    private function drawFlag(string $code, float $x, float $y, float $w, float $h, float $fallbackW, float $fallbackH): void
    {
        $path = $this->flagCache->resolve($code);

        if ($path !== null) {
            try {
                $this->Image($path, $x, $y, $w, $h);
                return;
            } catch (\Throwable $e) {
                // fall through to the placeholder badge below
            }
        }

        // No image available: fall back to a readable text badge instead
        // of trying to cram the code into the (deliberately tiny,
        // native-resolution) flag box.
        $this->SetFillColor(...self::C_LOGO_FILL);
        $this->Rect($x, $y, $fallbackW, $fallbackH, 'F');
        $this->applyFont(true, false, 5.5);
        $this->SetTextColor(...self::C_HEADING);
        $this->SetXY($x, $y + $fallbackH / 2 - 1.6);
        $this->Cell($fallbackW, 3.2, strtoupper($code), 0, 0, 'C');
    }
}
