## 5. Tests failing on main

Full run 2026-10-20 of all 126 `scripts/test-*.php`, then a rerun on main at b697895a finished 2026-10-21 against a
fresh mirror: every test passes except the five that need inputs below (PLAN-ARCHIVE, "5. Tests failing on main,
rerun"). Run them one PHP process at a time from the real checkout, with the portable PHP 8.2 wrapper plus
`-d extension=zip` (test-open-data) and `-d memory_limit=6G` (test-review-inbox).

1. **Need inputs, not failures** (exit 2 with a usage line). Two of the five were run with inputs on 2026-10-21 and pass:
   - test-fornits: all passed on `~/kop-import/fornits/batch-20261002-192403.jsonl.gz` scp'd from the server into a
     scratchpad (438 links on 173 records, plus staff/incident/testimony/closed/note on 40).
   - test-inspection-links: all 8 checks pass, but only with the fixture units in the file. A plain `--limit 15`
     scrape fails the Benet and Sarah Reed checks because those rows are not in it. The fixture run:
     `PA_STATE_FILE=<scratch copy of .pa_state.json> python pa_scraper.py --full --no-post --since 2024 --unit 474850
     --unit 410750 --unit 417340 --unit 450120 --unit 445320 --unit 144800 --unit 120280 --unit 120260 --unit 149720
     --unit 407960 --unit 407510 --unit 458090 --out <scratch>/pa-fixtures.json` (needs a Python with pdfplumber,
     e.g. C:\Python313; the state-file override keeps the real `.pa_state.json` untouched).

   Still open:
   - test-drive-docs: gdocs-extract.py reads `G:/My Drive/KOP Doc Export` (Drive folder
     https://drive.google.com/drive/folders/1NPajeUK0HUNzOmgrVHybvR1c46wVqmh1, many CSVs). Drive for desktop was not
     running on 2026-10-21 (no G: or I:), so it needs Drive mounted, then gdocs-extract.py, then the test.
   - test-facility-v2-readers and -writer (`--dsn` to a MySQL 8 copy): no MySQL or Docker on this machine. Needs a
     portable MySQL 8 loaded with a mysqldump of the tables in the writer's header; the writer is destructive, so
     only ever against that copy.
2. **test-review-inbox.php --source=news: "Take off removes the consultant's note exactly" fails** (found 2026-10-21, also
   with the session's own changes set aside, so it predates them). The run also prints "kop extension: lawsuit facility
   links failed: ... near "COMMENT"" (a MySQL column COMMENT in a CREATE on the SQLite copy). Look at
   `kop_rinbox_native_unconnect()` in `inc/review-inbox/native.php` for consultant notes.
