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
- test-citation-cleanup: test too broad. Woodbury Reports issues are news items since the SCIAD import (2026-10-12), so News coverage names the outlet, and a staff member's past job is at Woodbury Reports; neither is citation wording (a08d95f3). Passes.
- test-facility-eras: code wrong. kop_facility_eras_build() dropped a second item with the same words on the same record (Viewpoint Center's two identical incident lines cited to two wiki pages), so a cut page showed 6 of 7; it now drops only the same item on another record of the line. Fixed in inc/facility-eras.php; checking the tests that read it before committing.
- test-indigenous-schools: stale. The move ran on the site 2026-09-30, so the four source records are gone; the test now checks what the real move left (86e9bc8b). Passes.
- test-inspection-rollup: "nothing written to the mirror". ?
- test-investors: stale. Company #500017 gained two investors (CIC Partners, Cressey & Company); the counts follow the entries found (447e0960). Passes.
- test-kop-tools-menu: test harness wrong. It stubbed only the 8 screens it knew in 2026-09; screens now count when the theme defines their function and adds their submenu (091022a3). Passes.
- test-media-subfolders: 5 checks (Mel Blount / 1970s folders, documents 9609, 9610 under 150/Aspen Achievement Academy). ?
- test-name-under-operator: stale. 13678's past names were edited on the site ("Three Springs New Direction", "Three Springs Turning Point - Girls"), and Auldern Academy (#11939) gained "Three Springs of North Carolina", already a past name of #11903, so by the settle rule neither is listed under it. Expectations updated; which program carried the name is a new item in docs/RESEARCH-TASKS.md (283731c8). Passes.
- test-nc-inspection-cleanup: "every plan row matches the mirror" (merges 6576, 6577, ... changed or gone) and 3 more. ?
- test-network-renames: stale. The rename merge gave Copper Canyon 1998-2014 and Sedona Sky 2014-2025, so no overlap; 12 of 93 researched years in kop-data's network/rename-candidates.json are for lines no longer on the map (merged pairs), now counted, not failed (5b1d8185). Passes.
- test-news-archive: harness wrong. archive.js is read from the kop-send-to-kop checkout; the split copies every column but archive_url and skips the resync (2e870308). Passes.
- test-news-post-import: stale. The import ran on the site; the checks count its rows, and the row checks now cover them (56a561a5). Passes.
- test-open-data: environment. The portable PHP needs `-d extension=zip`; passes with it.
- test-operator-history-seed: stale. The Sequel rewrite is on the site already, so the seed reports "same"; accepted (596f211a). Passes.
- test-people: harness wrong for the map build: scripts/build-network-graph.js moved to kop-pipeline in the split; the test now runs it from there. "one id per name key across records" still to look at.
- test-post-text-fixes: stale. All eight fixes are applied on the site (kop_post_text_fixes_done); applied ones are checked as applied, the new text read as later fixes to the same page left it (88a3d4c0). Passes.
- test-program-homes-convert: stale. The site converted 15 companies on 2026-10-09 (Dimondale, NeuroRestorative ...); the test now undoes those conversions on its copy first. Not yet run.
- test-program-homes: "undo removes the record this tool made" (kept), "undo leaves no company link behind". ?
- test-rename-merge: stale. Copper Canyon (#12155) was merged into Sedona Sky (#12161) as a rename on the site 2026-10-11; the test undoes that merge on its scratch copy from the merge log, and compares Sedona Sky's operator to its own pre-merge value (86e040d7). Passes.
- test-search-aliases: stale. Copper Canyon merged into Sedona Sky, Bethel Boys Academy moved to Eagle Point's past names, Three Springs of Osceola merged into Kissimmee Youth Academy; samples replaced (0d24c688). Passes.
- test-staff-former: stale. The pass ran on the site (4,299 roles), so the dry run found nothing; the test undoes it on the copy from the site's kop_staff_former_log first, and the clean-up check takes exactly four entries (it took more when one record had several) (94a0c295). Passes.
- test-state-feed-names: harness wrong. It now loads inc/heal-archive.php, stubs wp_upload_dir() and ignores @-silenced warnings (aa527fa0). Passes.
- test-state-lists: code gap. The State Lists import stored candidate ids as built, while the filings and federal funding queues follow merges (kop_facility_merge_follow_candidates()); one candidate was merged away since the export. inc/state-lists.php now follows merges on import, and the test loads the live merge map. Committing after its run.
- test-submission-notify: stale expectations. f97643ac moved lawsuits and bills onto Submissions Review tabs; the fallback check hides the pages instead (aa527fa0). Passes.
- test-wiki-staff: stale. The Former pass ran on the site; the test runs the pass's own undo on the copy first (557 put back, 551 rewritten again) (df2b0293). Passes.
- test-woodbury-facts: test wrong. The corrected-staff check picked a record merged away since the facts file was built; it now skips those, as the main loop does (d8126f68). Passes.
- test-young-adult-move: stale. The move ran on the site 2026-10-01 and production has the ya columns; the test checks what the real move left (86e9bc8b). Passes.
