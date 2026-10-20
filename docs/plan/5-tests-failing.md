## 5. Tests failing on main

Full run 2026-10-20 of all 126 `scripts/test-*.php`, one at a time from the real checkout, on the mirror synced
2026-10-20 14:44, main at 95cde58d, portable PHP 8.2 (pdo_sqlite, sqlite3, mbstring; add `zip` for test-open-data).
The earlier "nothing open" covered only the tests named in PLAN-ARCHIVE; 93 pass, 33 exit non-zero.

**Need inputs, not failures** (exit 2 with a usage line): test-facility-v2-readers and -writer (`--dsn` to a MySQL
copy), test-inspection-links (`--file=<scraper --out json>`), test-drive-docs (`tmp/gdocs/links.json`, from
gdocs-extract.py), test-fornits (`--batch=<a copy of a server batch>`). test-rename-merge exits 2 because
Copper Canyon Academy / Sedona Sky Academy are no longer one record each in the mirror (see below).

**Failing** (check name; cause: *stale* = the sample changed in the data, *code* = the code or the test's own
harness is wrong; *?* = not looked at yet):

- test-auto-connect: "woodbury: some waiting pages connect themselves" (0 of 375), "drive docs: links with no record
  are tied to the record their collection or page names exactly" (0 of 2866), "drive docs: a name two company records
  share finds none". ?
- test-citation-cleanup: "no Woodbury wording in the text" on pages 9643, 9689, 9690, 9691. ?
- test-facility-eras: "every cut page is sound" (10361 Viewpoint Center: incidents 7 on the record, 6 on the page). ?
- test-indigenous-schools: 12+ checks; the four schools (11605, 100211, 13578, 13576) are already moved out of the
  mirror's facility tables, so the first move finds nothing. ?
- test-inspection-rollup: "nothing written to the mirror". ?
- test-investors: "import: 5 investors, 5 links, 10 log rows", "a second run adds nothing". ?
- test-kop-tools-menu: 25 screens "not loaded by the theme". ?
- test-media-subfolders: 5 checks (Mel Blount / 1970s folders, documents 9609, 9610 under 150/Aspen Achievement Academy). ?
- test-name-under-operator: Three Springs Inc. lists 13678 / 11903 with their old names. ?
- test-nc-inspection-cleanup: "every plan row matches the mirror" (merges 6576, 6577, ... changed or gone) and 3 more. ?
- test-network-renames: "the overlap is flagged", "saving 2014 splits the years: Copper Canyon 1998-2014", "the
  researched years load" (93 candidates). ?
- test-news-archive: node part imports `browser-extension/send-to-kop/archive.js`, which moved to kop-send-to-kop in
  the split; then "no such column: facilities_mentioned" in api/lib-record-resync.php:115. ?
- test-news-post-import: "at least 70 of the posts reach the feed", "the table holds what the stats say". ?
- test-open-data: "ZIP written": the portable PHP lacked `zip` (environment).
- test-operator-history-seed: "the Sequel rewrite replaces the draft with the pull quotes" ({"Sequel TSI":"same"}). ?
- test-people: "one id per name key across records", "770 of 778 person nodes carry personId", "two merged map
  people are one node (Brad M. Reedy -> Tere Snodgrass)". ?
- test-post-text-fixes: "every swap applies" for 8 fix files (2026-10-08 ... 2026-10-14). ?
- test-program-homes-convert: Dimondale / NeuroRestorative not suggested, then a fatal ("Company #5748 is not one
  program any more"). ?
- test-program-homes: "undo removes the record this tool made" (kept), "undo leaves no company link behind". ?
- test-rename-merge: exits before any check (Copper Canyon / Sedona Sky not one record each). ?
- test-search-aliases: "Copper Canyon finds Copper Canyon Academy", "...flagged as an other-name hit", "Kissimmee
  Youth finds Three Springs of Osceola". ?
- test-staff-former: fatal at line 86 (Undefined array key 0) after 22 passes. ?
- test-state-feed-names: fatal, kop_heal_archive_url() undefined (inc/facility-pages.php:2804). ?
- test-state-lists: "every candidate is a facilities_v2 record" (108 of 109). ?
- test-submission-notify: "lawsuit: links the review screen", "legislation: no review page, falls back to the
  tools menu". ?
- test-wiki-staff: "0 wiki staff roles rewritten" then a fatal at line 254. ?
- test-woodbury-facts: fatal at line 308 (no doc) after 20 passes. ?
- test-young-adult-move: fatal "duplicate column name: ya" at line 106. ?
