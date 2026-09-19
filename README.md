# CV Generator

Generates a one-page A4 PDF resume from a plain PHP data array, using
[FPDF](http://www.fpdf.org/) (pure-PHP, no external binaries, MIT-licensed,
vendored under `src/Fpdf/`).

## Requirements

- PHP **8.0** (no later-only syntax is used anywhere in this project - see
  *"Why FPDF"* below for why that also drove the library choice)
- PHP extensions: `zlib` (core, always available), `iconv` (for accented
  characters), and ideally `curl` and `gd` (used to download and safely
  decode the remote logo images; the code falls back gracefully if a logo
  can't be fetched, drawing a placeholder box instead)
- Apache with `mod_rewrite` enabled

## Project layout

```
.
├── .htaccess              Routes every request to public/index.php
├── cv_example.php         Example data (safe to commit - delivered as-is)
├── cv.php                 YOUR data - not delivered, git-ignored (see below)
├── cv_image.png           Photo shown top-right on the CV
├── public/
│   └── index.php          Front controller: loads the data, builds and streams the PDF
├── src/
│   ├── CvData.php         Picks cv.php if present, else cv_example.php
│   ├── CvPdfGenerator.php All the layout/drawing logic
│   ├── LogoCache.php      Downloads + locally caches the remote logo images
│   ├── FlagSpriteCache.php Downloads + crops + caches the language flag sprite
│   └── Fpdf/               Vendored FPDF 1.9 (fpdf.php + core font metrics + license)
└── cache/
    ├── logos/              Downloaded logo cache (git-ignored, auto-created)
    └── flags/              Downloaded/cropped flag cache (git-ignored, auto-created)
```

## Getting started

1. Point your Apache vhost's `DocumentRoot` at this project's root folder
   (the one containing `.htaccess`) - **not** at `public/`. The root
   `.htaccess` is what forwards every request to `public/index.php`
   internally (the URL in the browser doesn't change), so `src/`, `cv.php`
   and `cv_image.png` never need to be, and never are, served directly.
2. Copy `cv_example.php` to `cv.php` and fill in your own information.
   `cv.php` is listed in `.gitignore` and is **always preferred over
   `cv_example.php`** whenever both exist (see `src/CvData.php`) - that's
   the file the app actually "pulls from"; `cv_example.php` is only the
   fallback/example and is what gets used if you haven't created `cv.php`
   yet.
3. Visit the site. The PDF is streamed inline by default; add
   `?download=1` to the URL to force a "Save As" download instead.

The very first request will download and cache every logo image under
`cache/logos/`; later requests reuse those cached files instead of
re-downloading them.

## Editing your data (`cv.php`)

The array shape is documented with inline comments at the top of
`cv_example.php`. A few things worth knowing:

- Any `title` / `did` / `used` / `content` / freetime `value` string can
  contain a literal newline to force a line break exactly where you want
  one (e.g. to put a course name and its school on two separate lines).
  Without one, text just wraps automatically to fit the column. **This
  must be a real newline, not the two characters `\n`** - which only
  happens automatically in a PHP string written with double quotes (or a
  heredoc). `"Line one\nLine two"` works; `'Line one\nLine two'` does not -
  single quotes don't interpret `\n` at all, so it stays as a literal
  backslash-n and prints as such. If you need to keep single quotes for
  some reason, write `'Line one' . "\n" . 'Line two'` instead.
- One experience entry can be marked `'force_one_line' => true` (see the
  SantéVet entry in the example) to force its title onto a single line -
  the generator automatically shrinks that title's font just enough to
  make it fit, rather than letting it wrap.
- The languages block's `code` (e.g. `FR`, `US`, `ES`, `CN`, `KE`) draws a
  small flag, cropped at request time from the semantic-ui-flag sprite
  sheet (`FLAG_SPRITE_URL` in `public/index.php`) and cached locally under
  `cache/flags/` (see `src/FlagSpriteCache.php`). The crop coordinates for
  `FR`, `US`, `ES`, `CN` and `KE` are in `FlagSpriteCache::POSITIONS`; add
  more codes there (taken from the `background-position` values in
  semantic-ui-flag's `flag.min.css`) if you add more languages. Any code
  without a known position - or if the sprite can't be downloaded - falls
  back to a plain badge showing the code itself.
- A flag emoji (🇫🇷, 🇺🇸, 🇪🇸, 🇨🇳, 🇰🇪, ...) typed directly into **any**
  text field is automatically replaced by that same flag image - even
  glued to punctuation, e.g. `"...🇺🇸, currently..."`. Only the 5 codes
  above have an actual flag image; any other flag emoji falls back to its
  2-letter code badge, same as the `code` field above.
- **Any of those same fields - skill `value`, freetime `value`, an
  entry's `did`/`used`/`content` - can turn part of their text into a
  clickable link.** Instead of a plain string, give an array of
  segments, each with a `text` and an optional `link`:
  ```php
  'did' => [
      ['text' => 'Rebuilt the internal '],
      ['text' => 'reporting dashboard', 'link' => 'https://example.com/case-study'],
      ['text' => ' from scratch.'],
  ],
  ```
  A segment without `link` (or with `link => null`) is drawn like normal
  text; a segment with `link` gets the exact same colour/weight/style as
  the surrounding text, with an underline added so it reads as a link.
  This is what you'd use for the freetime "Built a web video editing
  software" / "Web scraping, APIs" links, or for a specific project
  mentioned in a job's `did`:
  ```php
  'value' => [
      ['text' => 'Side projects, examples : '],
      ['text' => 'Built a web video editing software', 'link' => 'https://twitter.com/PierreMiniggio/status/1409875579181142020'],
      ['text' => ', '],
      ['text' => 'Web scraping, APIs', 'link' => 'https://github.com/pierreminiggio'],
  ],
  ```
  Note on "open in a new tab": that's not something a PDF file can force -
  unlike an HTML `target="_blank"`, a PDF link's behaviour (same tab, new
  tab, downloads, ...) is entirely up to whatever PDF viewer/browser the
  reader is using, with no setting in the file itself to control it.

## How the "fits on one page, guaranteed" part works

Nothing is drawn until it's been measured. `CvPdfGenerator::build()` first
runs every layout routine in "measure-only" mode (same word-wrapping code,
just without emitting any PDF drawing operators) to compute exactly how
tall the Work Experiences and Education columns will be. If they don't fit
in the space left below the skills section, the generator tightens the
gap between entries (and the gap between an entry's title/description
lines) in a few defined steps and re-measures - a real, deterministic
re-layout, not a guess.

If the content still doesn't fit even at the tightest spacing, the
generator throws a `RuntimeException` with the exact numbers (how many mm
were needed vs. available) instead of silently producing a CV that spills
onto a second page. In `public/index.php` that exception is caught and
shown as a plain-text error (with `$debug = true`); turn that flag off
before going live so a real visitor never sees a stack trace, and shorten
the offending entry in `cv.php` instead.

This was tested with the example data (fits comfortably at the most
generous spacing) and, deliberately, with extra dummy entries added on
top of it to confirm both paths: the spacing-tightening path succeeding
under moderate overflow, and the clear-error path firing under an
unreasonable amount of extra content.

## Why FPDF

FPDF is a small, dependency-free, pure-PHP, MIT-licensed PDF library with
no known PHP 8.0 incompatibilities, which made it a safe fit for the
"must run on PHP 8.0" constraint without pulling in Composer or a larger
library (TCPDF/mPDF) whose newer releases increasingly assume PHP 8.1+.
Only `fpdf.php` and the core font metric files it needs are vendored here
(license included at `src/Fpdf/LICENSE.txt`); the tutorial/doc files from
the upstream repository were left out.

## Design notes / limitations

- The original CV mockup used emoji flags (🇫🇷🇺🇸🇪🇸🇨🇳🇰🇪) for languages.
  Core PDF fonts (used here to keep the project dependency-free) can't
  render color emoji, so language flags are instead cropped from the
  semantic-ui-flag sprite sheet used on miniggiodev.fr (see above) - same
  visual idea, PDF-safe. That sprite's flags are tiny in their source
  (16x11px), so they'll look a little soft/blocky at print resolution;
  that's an inherent limit of that source image, not a bug here.
- Accented characters (é, è, à, ç, …) are converted from UTF-8 to the
  Windows-1252 encoding FPDF's core fonts use. That covers French and
  Western-European text; characters outside that encoding (e.g. Chinese,
  Cyrillic) would need an embedded Unicode (TTF) font instead of the
  built-in core fonts, which is a bigger change than this project needed.
