## 5. Tests failing on main

Full run 2026-10-20 of all 126 `scripts/test-*.php` (mirror synced 2026-10-20 14:44, main at 95cde58d): 93 passed,
33 exited non-zero. 27 of the 28 real failures are fixed (PLAN-ARCHIVE, "5. Tests failing on main, full run").
Run them one PHP process at a time from the real checkout, with the portable PHP 8.2 wrapper plus `-d extension=zip`
(test-open-data) and `-d memory_limit=6G` (test-review-inbox).

1. test-media-subfolders: stale samples fixed (9493a271): the fixes' folders exist, documents 9609/9610/7600/8162... were deleted on the site, and subfolders with nothing left to file are not expected. **Still fails, on real data**: 36 of the 74 planned subfolders exist twice on production (consecutive ids, e.g. Evidence 7929/7930, Charles Britt Academy 7943/7944 under 252), with the same documents filed in both; the one-time subfolder pass ran twice at once before kop_maybe_apply_template_assignments() took its lock. Owner step: approve a clean-up on production that keeps the lower id of each pair and drops the empty copy's filings (back up wpdl_fbv and wpdl_fbv_attachment_folder first); the test then passes.
2. **Confirm the full rerun.** A rerun of all 126 on main at b697895a was started at the 2026-10-20 handoff; check
   that only test-media-subfolders and the five tests needing inputs (item 3) exit non-zero.
3. **Need inputs, not failures** (exit 2 with a usage line): test-facility-v2-readers and -writer (`--dsn` to a MySQL
   copy), test-inspection-links (`--file=<scraper --out json>`), test-drive-docs (`tmp/gdocs/links.json`, from
   kop-pipeline's gdocs-extract.py), test-fornits (`--batch=<a copy of a server batch>`; never create
   kop-pipeline/tmp/fornits/).
