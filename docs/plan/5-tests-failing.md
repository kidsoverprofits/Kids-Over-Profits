## 5. Tests failing on main

Full run 2026-10-20 of all 126 `scripts/test-*.php`, then a rerun on main at b697895a finished 2026-10-21 against a
fresh mirror: every test passes except the five that need inputs below (PLAN-ARCHIVE, "5. Tests failing on main,
rerun"). Run them one PHP process at a time from the real checkout, with the portable PHP 8.2 wrapper plus
`-d extension=zip` (test-open-data) and `-d memory_limit=6G` (test-review-inbox).

1. **Need inputs, not failures** (exit 2 with a usage line). Three of the five were run with inputs on 2026-10-21 and pass:
   - test-drive-docs: all passed after `python scripts/gdocs-extract.py --export "G:/My Drive/KOP Doc Export"`
     (kop-pipeline, 25 s, writes tmp/gdocs/links.json) and `--python=C:/Python313/python.exe`: 1306 links on 437
     records, 100 added, 1183 refused as already there. It prints "#id missing" for 9 records named by
     tmp/sciad/sciad-links.json (built 2026-10-07) that were merged since (e.g. 11132 -> 11213, 100090 -> 10380, in
     `kop_facility_merged_into['ids']`) and skips them. Follow-up: have the test follow the merge map the way
     test-fornits does, and check that the Drive Docs screen moves SCIAD rows for a merged record to the record it
     was merged into rather than dropping them.
   - test-fornits: all passed on `~/kop-import/fornits/batch-20261002-192403.jsonl.gz` scp'd from the server into a
     scratchpad (438 links on 173 records, plus staff/incident/testimony/closed/note on 40).
   - test-inspection-links: all 8 checks pass, but only with the fixture units in the file. A plain `--limit 15`
     scrape fails the Benet and Sarah Reed checks because those rows are not in it. The fixture run:
     `PA_STATE_FILE=<scratch copy of .pa_state.json> python pa_scraper.py --full --no-post --since 2024 --unit 474850
     --unit 410750 --unit 417340 --unit 450120 --unit 445320 --unit 144800 --unit 120280 --unit 120260 --unit 149720
     --unit 407960 --unit 407510 --unit 458090 --out <scratch>/pa-fixtures.json` (needs a Python with pdfplumber,
     e.g. C:\Python313; the state-file override keeps the real `.pa_state.json` untouched).

   - test-facility-v2-readers and -writer, run 2026-10-21 on a portable MySQL 8.4.13 (cdn.mysql.com zip, MD5
     checked, unpacked into a scratchpad, `--initialize-insecure`, 127.0.0.1:3399; PHP needs `-d extension=pdo_mysql`).
     Loaded from the local dumps, nothing new taken off production: `tmp/prod-dump.sql` (2026-10-21, compact
     mysqldump, filter by `CREATE TABLE`/`INSERT INTO` lines) and `tmp/prod-app-dump.sql` (2026-09-17 15:03, the
     pre-switch copy; leave out wpdl_users and wpdl_options, and swap in today's news_submissions, lawsuits and
     the other record tables because the September ones lack newer columns such as `archive_url`). The readers test
     needed `require_once inc/utilities.php` (rest-api.php calls `kop_transporters_table()` since 3bf682b2);
     added. Both are switch rehearsals that diff the frozen facilities_master against v2, so on today's copy they
     mostly report drift since 2026-09-18 (merged records, cleaned-up values), not bugs. The writer test also
     begins by redoing the switch, which on a post-switch copy rebuilds facilities_v2 from the frozen legacy table
     (removed 492 rows in the copy).

   Still open:
   - Readers test on the pre-switch copy: 3 checks fail, against none at the switch (DATA-MODEL-MIGRATION.md
     phase 4: "no search result, autocomplete value, resolved name or link is lost"). Search "academy" loses CALO
     Programs (capped compare, maybe only ranking at the cap); "wilderness" loses Outback Therapeutic Expeditions
     (10350, whose v2 document has no "wilderness" anywhere); the alias index loses Barry J Nidorf Juvenile Hall /
     SYTF, Campus Kilpatrick, Central Juvenile Hall, Los Padrinos Juvenile Hall and Outback Wilderness. The
     facility data is September's, so the change is in code since (candidates: ce5fb501 renames, 82a49850
     alternate names, fb7271f0 past names) or in the swapped-in record tables. Find which side moved.
   - Decide what these two tests are for now: keep them as switch rehearsals that need the September copy, or
     rebase them on v2 only. Writer on today's copy: 73 of 77 pass; the 4 failures come from the opening resync.
     Run it on the pre-switch copy next.
2. **test-review-inbox.php --source=news: "Take off removes the consultant's note exactly" fails** (found 2026-10-21, also
   with the session's own changes set aside, so it predates them). The run also prints "kop extension: lawsuit facility
   links failed: ... near "COMMENT"" (a MySQL column COMMENT in a CREATE on the SQLite copy). Look at
   `kop_rinbox_native_unconnect()` in `inc/review-inbox/native.php` for consultant notes.
3. **test-review-inbox.php: reader-accounts and wiki-updates fail in the full run** (found 2026-10-25 on a clean copy of
   main at 3f179304 against the 2026-10-23 mirror, so they predate the Review inbox tabs work). reader-accounts: "a reply
   that is not JSON gets one more try", "a line ... is dropped (0 lines, 2 dropped)", "a name is taken out ...", and
   "Undefined array key 0" at `scripts/review-inbox-tests/reader-accounts.php:111`; wiki-updates: the three "site builds
   the script's draft" / "bold names line" / "names carry the years" checks find 0 drafts. `--source=reader-accounts`
   alone passes, so something earlier in the full run changes what it reads (order-dependent); start there.
4. **kop-pipeline test-ocr-repair.py: 2 of the fax lines fail** (found 2026-10-25; the same on the committed
   `pa_scraper.py`, so it predates OCR version 3). `scrapers/ocr_repair.py` leaves "klesing" and "unclolhed" instead of
   "kissing" and "unclothed" (fax page lines 2 and 5). Check whether `scrapers/pa_ocr_vocab.json` (rebuilt 2026-10-18
   by `scripts/build-pa-ocr-vocab.py`) lost those words or the swap rules changed.
5. **test-network-embed.js: 5 of 703 slices differ from the map** (found 2026-10-25, the same on the graph.json before
   that day's investor build, so not new): aspen-education-group, suws-of-the-carolinas, provo-canyon-school,
   aspen-achievement-academy, asheville-academy-for-girls. Each embed is missing Aspen-side names the map shows
   (aspiro-group, boulder-creek-academy, bain-capital, passages-to-recovery...) and draws fewer lines (145 against 176
   for Provo), so the PHP slice (`kop_network_map_slice_opened()` in inc/network-map.php) and focus.js have drifted,
   most likely on how the cap's dropped connections or an owner's step are treated for names joined through a folded
   line. Run with `--only=aspen-education-group,provo-canyon-school` against the portable PHP; fix the PHP to match
   focus.js (never the other way), then the full run.
6. **Provo Canyon School's view reorders its rows when one line leaves** (found 2026-10-26 working item 6, now in
   PLAN-ARCHIVE). One staff line leaving the live records (Fitisemanu II - Provo Canyon School, kop-data f649268 ->
   8d8af9a) moved the view's median line from 571px to 654px (the 2026-10-26 full run after the fix and a probe agree on
   654px; the run before the fix printed 674px, unexplained). The bound in
   `scripts/test-network-modules.js` is now 720px with the reason written above it. Look at why the row order in
   focus.js is that sensitive (a stable tie-break, or ordering rows by what they connect to), and whether the 674px run
   points to state carried over from earlier checks.
7. **test-facility-discovery.php: 3 checks fail on main** (found 2026-10-28, the same on unchanged HEAD): "known
   names ... closure programs are added" (Three Points Ranch), "a name decided before is not asked again", "a name held
   for want of a place is asked again". Likely mirror drift (Three Points Ranch now resolves); fix the fixtures.
