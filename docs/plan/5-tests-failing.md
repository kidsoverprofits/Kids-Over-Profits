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
   - Owner: the frozen legacy record 13930 "Bethel Academy" also has an address (2147 Mill St Ext, Lucedale, MS
     39452), years 1978-2008 and past names Eagle Point Christian Academy, Gulf Coast Academy and Bethel Baptist
     Children's Home. None of it was carried to 100180 (Bethel Boys' Academy, live since 2026-10-21 in Lucedale, MS)
     because each name needs its own era and operator. Say which belong to 100180 (or to 13926 Bethel Girls Academy /
     Eagle Point Christian Academy) and they go in by seed.
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
