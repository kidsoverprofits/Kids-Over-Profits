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
- test-indigenous-schools: stale. The move ran on the site 2026-09-30, so the four source records are gone; the test now checks what the real move left (86e9bc8b). Passes.
- test-inspection-rollup: "nothing written to the mirror". ?
- test-investors: "import: 5 investors, 5 links, 10 log rows", "a second run adds nothing". ?
- test-kop-tools-menu: test harness wrong. It stubbed only the 8 screens it knew in 2026-09; screens now count when the theme defines their function and adds their submenu (091022a3). Passes.
- test-media-subfolders: 5 checks (Mel Blount / 1970s folders, documents 9609, 9610 under 150/Aspen Achievement Academy). ?
- test-name-under-operator: Three Springs Inc. lists 13678 / 11903 with their old names. ?
- test-nc-inspection-cleanup: "every plan row matches the mirror" (merges 6576, 6577, ... changed or gone) and 3 more. ?
- test-network-renames: stale. The rename merge gave Copper Canyon 1998-2014 and Sedona Sky 2014-2025, so no overlap; 12 of 93 researched years in kop-data's network/rename-candidates.json are for lines no longer on the map (merged pairs), now counted, not failed (5b1d8185). Passes.
- test-news-archive: harness wrong. archive.js is read from the kop-send-to-kop checkout; the split copies every column but archive_url and skips the resync (2e870308). Passes.
- test-news-post-import: "at least 70 of the posts reach the feed", "the table holds what the stats say". ?
- test-open-data: environment. The portable PHP needs `-d extension=zip`; passes with it.
- test-operator-history-seed: "the Sequel rewrite replaces the draft with the pull quotes" ({"Sequel TSI":"same"}). ?
- test-people: "one id per name key across records", "770 of 778 person nodes carry personId", "two merged map
  people are one node (Brad M. Reedy -> Tere Snodgrass)". ?
- test-post-text-fixes: "every swap applies" for 8 fix files (2026-10-08 ... 2026-10-14). ?
- test-program-homes-convert: Dimondale / NeuroRestorative not suggested, then a fatal ("Company #5748 is not one
  program any more"). ?
- test-program-homes: "undo removes the record this tool made" (kept), "undo leaves no company link behind". ?
- test-rename-merge: stale. Copper Canyon (#12155) was merged into Sedona Sky (#12161) as a rename on the site 2026-10-11; the test undoes that merge on its scratch copy from the merge log, and compares Sedona Sky's operator to its own pre-merge value (86e040d7). Passes.
- test-search-aliases: stale. Copper Canyon merged into Sedona Sky, Bethel Boys Academy moved to Eagle Point's past names, Three Springs of Osceola merged into Kissimmee Youth Academy; samples replaced (0d24c688). Passes.
- test-staff-former: fatal at line 86 (Undefined array key 0) after 22 passes. ?
- test-state-feed-names: harness wrong. It now loads inc/heal-archive.php, stubs wp_upload_dir() and ignores @-silenced warnings (aa527fa0). Passes.
- test-state-lists: "every candidate is a facilities_v2 record" (108 of 109). ?
- test-submission-notify: stale expectations. f97643ac moved lawsuits and bills onto Submissions Review tabs; the fallback check hides the pages instead (aa527fa0). Passes.
- test-wiki-staff: "0 wiki staff roles rewritten" then a fatal at line 254. ?
- test-woodbury-facts: fatal at line 308 (no doc) after 20 passes. ?
- test-young-adult-move: stale. The move ran on the site 2026-10-01 and production has the ya columns; the test checks what the real move left (86e9bc8b). Passes.
