# AGENTS.md

Instructions for whoever (human or AI) makes further changes to this
project and delivers them back to the project owner.

## Delivery format for changes (in effect from 2026-09-17 onward)

The **first** delivery of this project was a zip of the entire project
tree. **Every delivery after that one** must instead be incremental:

1. **A zip containing only the files that were created or edited** for
   that change, each at its correct relative path from the project root
   (e.g. `src/CvPdfGenerator.php`, `public/index.php`). The person drops
   these straight into their existing project folder, overwriting the old
   versions - so the zip must never include unchanged files, and must
   never omit a file that was actually touched.
2. **A list of shell `rm` commands** (e.g. `rm src/OldClass.php`) for any
   file that the change requires deleting - only when applicable. If
   nothing needs deleting, say so explicitly rather than omitting the
   point.
3. Both of the above are provided **in addition to** the normal written
   explanation of the change - they don't replace it.

This file itself (`AGENTS.md`) follows the same rule: if a future change
also needs to update these delivery instructions, `AGENTS.md` goes in that
change's zip too, as an edited file.

## Other standing project conventions

- **PHP 8.0 compatibility is a hard constraint.** Don't use syntax or
  functions introduced in PHP 8.1+ (enums, `readonly` properties, the
  `never` return type, first-class callable syntax, pure intersection
  types, etc.) anywhere in `src/`, `public/`, `cv_example.php`, or the
  vendored FPDF library. If the environment used to test changes only has
  a newer PHP available, that's fine for running the test - the
  constraint is about the syntax actually used in the code.
- **`cv.php` is never part of any delivery.** It holds the project
  owner's private data, is git-ignored, and is not something to generate,
  overwrite, or include in a zip.
- **`cache/logos/` and `cache/flags/` are runtime caches**, not source -
  never deliver their contents, only the `.gitkeep` placeholder if the
  directory itself is newly introduced.
- Before delivering a change to `src/CvPdfGenerator.php` (or anything
  affecting layout/spacing), regenerate the PDF from `cv_example.php` and
  check it still fits on a single A4 page - see the "How the fits-on-one-
  page part works" section of `README.md`. The auto-fit logic throws a
  clear `RuntimeException` if it can't fit; that exception is the signal
  something regressed, not something to silently work around.
- **Tests live in `tests/` and must pass before every delivery.** Run
  `php tests/run.php` (dependency-free runner - no Composer/PHPUnit, keep
  it that way). A behaviour change comes with new or updated tests in a
  `tests/*Test.php` file, and new/edited test files go in the delivery zip
  like any other file. Tests must stay hermetic: no network access, and
  never read or depend on the private `cv.php` (use a throwaway project
  folder for `CvData` tests and `cv_example.php` data for rendering tests).
  The same PHP 8.0 syntax constraint applies to test code. The suite's
  one-page checks complement, but don't replace, looking at the regenerated
  PDF after a layout change (previous bullet).
