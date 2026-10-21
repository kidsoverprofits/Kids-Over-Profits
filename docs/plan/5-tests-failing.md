## 5. Tests failing on main

Full run 2026-10-20 of all 126 `scripts/test-*.php`, then a rerun on main at b697895a finished 2026-10-21 against a
fresh mirror: every test passes except the five that need inputs below (PLAN-ARCHIVE, "5. Tests failing on main,
rerun"). Run them one PHP process at a time from the real checkout, with the portable PHP 8.2 wrapper plus
`-d extension=zip` (test-open-data) and `-d memory_limit=6G` (test-review-inbox).

1. **Need inputs, not failures** (exit 2 with a usage line): test-facility-v2-readers and -writer (`--dsn` to a MySQL
   copy), test-inspection-links (`--file=<scraper --out json>`), test-drive-docs (`tmp/gdocs/links.json`, from
   kop-pipeline's gdocs-extract.py), test-fornits (`--batch=<a copy of a server batch>`; never create
   kop-pipeline/tmp/fornits/).
