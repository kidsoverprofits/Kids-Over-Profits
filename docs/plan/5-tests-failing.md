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
     `kop_facility_merged_into['ids']`) and skips them. The test now follows the merge map (2026-10-28, see below).
   - test-fornits: all passed on `~/kop-import/fornits/batch-20261002-192403.jsonl.gz` scp'd from the server into a
     scratchpad (438 links on 173 records, plus staff/incident/testimony/closed/note on 40).
   - test-inspection-links: all 8 checks pass, but only with the fixture units in the file. A plain `--limit 15`
     scrape fails the Benet and Sarah Reed checks because those rows are not in it. The fixture run:
     `PA_STATE_FILE=<scratch copy of .pa_state.json> python pa_scraper.py --full --no-post --since 2024 --unit 474850
     --unit 410750 --unit 417340 --unit 450120 --unit 445320 --unit 144800 --unit 120280 --unit 120260 --unit 149720
     --unit 407960 --unit 407510 --unit 458090 --out <scratch>/pa-fixtures.json` (needs a Python with pdfplumber,
     e.g. C:\Python313; the state-file override keeps the real `.pa_state.json` untouched).

   - test-facility-v2-readers and -writer: rebased on v2 alone 2026-11-02 (owner: use current data only; the
     September switch-rehearsal reruns were dropped, history in PLAN-ARCHIVE "5. Tests failing on main: facility v2
     switch rehearsals"). How to run: portable MySQL 8.4.13 (cdn.mysql.com zip, MD5 checked against dev.mysql.com,
     unpacked into a scratchpad, `--initialize-insecure`, 127.0.0.1:3399), loaded from `tmp/prod-dump.sql` (keep
     CREATE TABLE blocks and INSERT INTO lines; leave out wpdl_users, wpdl_usermeta, wpdl_options; nothing new taken
     off production), then `php -d extension=pdo_mysql -d extension=mbstring -d extension=pdo_sqlite -d memory_limit=3G
     scripts/test-facility-v2-<readers|writer>.php --dsn "mysql:host=127.0.0.1;port=3399;dbname=kop;charset=utf8mb4"
     --user root`. The readers test is read-only; the writer test edits the copy, so reload before each writer run
     (about 2.5 minutes). On the 2026-11-01 copy: writer 63 of 63 pass; readers 36 of 37, the one failure real data:
   - Fixed 2026-11-02 (owner: repoint, and follow the move): news 10763 and 10764 (status submitted) were linked to
     #100175 Three Strands, a young adult program since 2026-10-26 19:01; "Claude research agent (Send page)" linked
     them again on 2026-10-27 14:25. The news and lawsuit link syncs now file a mention's facility_id on the record it
     lives in (kop_facility_follow_moves(): merges, related moves, young adult moves), and a one-time repair on
     admin_init (kop_fmerge_maybe_links_follow_moves(), version 1) moves links already on moved ids; on the 2026-11-01
     mirror that is exactly those two, to #900013. After deploy, check on production: option
     `kop_fmerge_links_follow_log` says `news_facility_links: 100175 -> 900013 (2 moved, 0 already there)`, and the
     readers test's "every linked news and lawsuit id resolves to a name" passes on a fresh dump.
     Done 2026-11-02 (deploy checked on a fresh mirror): the log says exactly that; news 10763 and 10764 are on
     #900013 and nothing is on #100175. Still open: rerun the readers test on a fresh dump. A full dump of
     2026-11-02 12:51 is in `tmp/prod-dump.sql` (1.1 GB); load it as above and expect 37 of 37. Before using
     `tmp/prod.sqlite` or the dump, check the sizes (about 1.2 GB / 1.1 GB): on 2026-11-02 a sync of only the volunteer
     tables replaced the whole mirror with a 32 KB file for a few minutes, until a full sync ran.
     Rerun 2026-11-02 on that dump (1,132,304,362 bytes; 162 tables, 5069 facilities; the load needs
     `SET SESSION sql_mode=''` first, or wpdl_comments' zero-date default stops it): 36 of 37. The one failure is
     no longer a dangling id: "every linked news and lawsuit id resolves to a name" misses 900013, 901001, 901002,
     which are live related_facilities records (news 10763/10764 on Three Strands, 10559 on Cape Fear Teen
     Challenge, 250 on Grace House Teen Challenge). `kop_v2_pdo_names_by_id()` (inc/facility-v2-writer.php) reads
     only facilities_v2 and kop_operators, and real readers share that blind spot, not just the test:
     api/link-news-to-facility.php's GET drops a news item's links to related records from its list, and its POST
     answers 404 "facility_id not found" for a related id; api/lawsuit-facility-links.php finds no document folder
     for one; api/data-manager.php shows no name. The public pages are fine (Three Strands and Grace House show
     their links). Open: teach the name lookup (or those callers) about related_facilities, then the test passes as
     written; decide whether the data manager should list related records at all.
   - Fixed 2026-11-02 (found by the writer round trip): saving an operator project in the data form deleted its
     joins to related-category records (ids above KOP_RELATED_FIRST_ID, which the form never lists): Embark (10) lost
     900002 Optimum Performance Institute and 900007 Dragonfly Transitions. After deploy, check on production that
     `wpdl_kop_operator_facilities` still has operator 10 -> 900002 and 900007 (both were there in the 2026-11-01
     dump; an admin save of Embark before the fix would have removed them).
     Done 2026-11-02 (deploy checked on a fresh mirror): both rows are there, 900002 current and 900007 past.
   - Known, not a bug: the first save of an older document rewrites it in the current shape (about 4,550 of 5,067
     on a full round trip: empty fields filled in, schema_version 2 -> 3, placeholder flags "migration: unparsed
     endYear: Present" dropped, an old project label such as "ROP" replaced by the name it is filed under). The
     test allows that as long as the form shows the same projects and a second save writes nothing.
2. **Provo Canyon School's view reorders its rows when one line leaves** (found 2026-10-26 working item 6, now in
   PLAN-ARCHIVE). One staff line leaving the live records (Fitisemanu II - Provo Canyon School, kop-data f649268 ->
   8d8af9a) moved the view's median line from 571px to 654px (the 2026-10-26 full run after the fix and a probe agree on
   654px; the run before the fix printed 674px, unexplained). The bound in
   `scripts/test-network-modules.js` is now 720px with the reason written above it. Look at why the row order in
   focus.js is that sensitive (a stable tie-break, or ordering rows by what they connect to), and whether the 674px run
   points to state carried over from earlier checks.
   Read 2026-11-01 (no code changed; the 2026-11-01 full run passes at 654px again): a tie-break would not help, the
   keys are floats. orderRow() sorts a row by where each name's neighbours sit on the row already placed, and a name
   with no neighbour there falls back to the x the force settle left it at (`keys[p.id] = n ? sum / n : p.x`). The
   settle starts from `seed`, which for a new view is positionOf(node), where the previous view drew each name
   (settleLayout(), the `from` argument). So the order depends on what was on screen before, which fits the 674px
   run (state carried over from earlier checks), and one line fewer moves the whole settle, so the unanchored names
   reorder. A fix to weigh: seed the unanchored names from something stable (their own two-step neighbours' order,
   or the map's positions) instead of the last view, so the same graph always draws the same rows.
   Checked 2026-11-02: that is not it. For a click, seedCluster() places every name in the view by hand (fan rows
   round the click, then each two-step name beyond what it hangs off), overwriting the `seed` positions, so the
   last view never reaches the settle. A new check in test-network-modules.js draws Provo Canyon School, opens
   WWASPS, draws it again and compares every name's position: identical (median 654px both times). The same graph
   does draw the same rows. What one line changes is the hand seed itself (fanRows' order and each anchor's `hung`
   offsets follow the edge list), then 220 ticks of the force settle, then shelve() cutting rows by y; a small
   change upstream moves names across a row cut, and orderRow() then sorts the unanchored ones by settled x. Making
   that insensitive to one line is a layout redesign (e.g. rows by graph distance from the click instead of by
   settled y), not a tie-break: owner's call whether it is worth it. The 674px run stays unexplained; not reproduced.
3. **Found 2026-10-28 while fixing items 2-5 of that day** (archive, "5. Tests failing on main, 2026-10-28"):
   - test-review-inbox.php rerun 2026-11-01 (quiet checkout, 2026-11-01 13:36 mirror): 1418 pass, 25 fail.
     Fixed the same day: business-filings (#100175 Three Strands moved to the young adult programs; kop_bizf_candidates()
     now drops moved records), conflicts (the owner settled every type conflict and roll-up: the test seeds them, and
     resets the conflict memo after seeding), data-audit (links_move follows merges: #13090 -> #13209),
     program-practices (`doc-text.py restore` in kop-pipeline now leaves out reader-account lines; the 1461-vs-1419
     count was those 101 lines), questions (stale since 37922245: settled answers are applied, not held),
     reader-accounts (the new machine's CRLF checkout, below), writeups (the test now picks a record with nothing
     held, and compares `covered` with the record's notes), young-adult-programs (opens its own test program by id),
     woodbury-facts (picks an item whose close names include another record), wiki-updates (skips the draft
     comparisons when tmp/wiki-updates/drafts is missing; rebuild it with kop-pipeline scripts/wiki-drafts.py to run
     them), recent (the import count reads fresh counts before as well as after). program-practices also failed only
     in a full run: the facility-merge checks copy the mirror's practices table in first, readers' lines included, so
     its checks now look at documents' lines (answer_id = 0) only.
   - Also 2026-11-01: api/lawsuit-news-links.php got a SQLite table and upsert (it logged "near COMMENT" on every
     news resync in the tests, as lawsuit-facility-links did). Still logged in test runs: api/news-mentions.php's
     `INSERT IGNORE` ("near IGNORE", SQLite wants `OR IGNORE`) and two missing tables in the scratch copy
     (news_story_arcs, journalists). news-mentions.php was being edited by another session that day, so left alone.
     Fixed 2026-11-02 (090976e1): news-mentions.php and kop_link_mentioned_operators() (api/facility-aliases.php,
     the same "near IGNORE") say INSERT OR IGNORE on SQLite; the scratch copy takes news_story_arcs, journalists,
     journalist_articles, marketers_master and tradeorgs_master. Review inbox 1523 pass, 0 fail. Still logged:
     five `SHOW COLUMNS ... LIKE` probes (bug_reports.notify_updates, wpdl_kop_people.pool), MySQL-only, harmless.
   - (The 10-28 Drive Docs and lawsuit-links items were fixed in e163d779. A 'gone' Drive Docs row needs no follow: a
     revived one takes the build's id again as 'pending' and the follow after every sync moves it.)
   - Production: check that the PA scraper's Python has `wordfreq` (`python -c "import wordfreq"`). Without it
     ocr_repair.py now prints a warning; before 2026-10-28 it repaired with zero word frequencies and said nothing.
   - The network embed is now uncapped: Provo Canyon School's slice is 135 KB (was 102 KB). Look at the live
     /facility/provo-canyon-school-ut/ after deploy: it should paint, now with the Aspen owners, and the caption no
     longer says "N more on the full map".
4. **test-operator-pages: "embark-behavioral-health lists its programs"** (found 2026-11-02): the check counts
   `kop-fp-siblings` once per page, but since 75481c13 the related-programs list (templates/operator-page.php line 261)
   has the same class, so Embark (which has related programs) counts 2. The page is right; the test needs to count only the
   first list (e.g. a class of its own on the related list). The rest of the test passes, timelines included.
