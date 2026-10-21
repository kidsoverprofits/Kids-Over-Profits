## 5. Tests failing on main

Full run 2026-10-20 of all 126 `scripts/test-*.php`, then a rerun on main at b697895a finished 2026-10-21 against a
fresh mirror: every test passes except the five that need inputs below (PLAN-ARCHIVE, "5. Tests failing on main,
rerun"). Run them one PHP process at a time from the real checkout, with the portable PHP 8.2 wrapper plus
`-d extension=zip` (test-open-data) and `-d memory_limit=6G` (test-review-inbox).

1. **Need inputs, not failures** (exit 2 with a usage line): test-facility-v2-readers and -writer (`--dsn` to a MySQL
   copy), test-inspection-links (`--file=<scraper --out json>`), test-drive-docs (`tmp/gdocs/links.json`, from
   kop-pipeline's gdocs-extract.py), test-fornits (`--batch=<a copy of a server batch>`; never create
   kop-pipeline/tmp/fornits/).
2. **test-review-inbox.php --source=news: "Take off removes the consultant's note exactly" fails** (found 2026-10-21, also
   with the session's own changes set aside, so it predates them). The run also prints "kop extension: lawsuit facility
   links failed: ... near "COMMENT"" (a MySQL column COMMENT in a CREATE on the SQLite copy). Look at
   `kop_rinbox_native_unconnect()` in `inc/review-inbox/native.php` for consultant notes.
