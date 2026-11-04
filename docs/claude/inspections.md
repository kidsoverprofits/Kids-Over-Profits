# State inspections

Commands, tests and rules for state inspection reports: text signals, verdict readers, NC cleanup, Inspection Links, rankings, plain summaries, state facility lists, the Drive archive. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# After editing the FL/NC adapters' text readers or api/lib-inspection-text-signals.php (PHP must match JS exactly)
node scripts/test-inspection-text-signals.js --php=<Local php.exe>
php scripts/test-inspections-read-lite.php     # inspections-read.php ?lite=1 / ?text= against tmp/prod.sqlite
# State hub inspection cards say what the state's own /xx-reports/ page says (badges, tone, count): one PHP reader per state in
# api/inspection-verdicts/<st>.php (api/lib-inspection-verdicts.php), used by kop_state_collect_inspection_summaries(). After
# editing an adapter in js/inspections/states/ or a reader there, run the parity test (real adapters vs PHP on every report)
node scripts/test-inspection-verdicts.js --php=<Local php.exe> [--states=PA,NC] [--ids=..]
# NC rows the old nc_scraper.py matcher misfiled (adult-only, one facility under many licences): seeds/nc-inspection-cleanup.json
php -d extension=pdo_sqlite scripts/test-nc-inspection-cleanup.php   # on an in-memory copy of tmp/prod.sqlite
php api/clean-nc-inspections.php [apply]      # on the server (ea-php82): dry run; apply backs up to ~/kop-backups/ first
# FL DJJ rows the old fl_scraper.py matcher misfiled (Marion Youth Academy on Marion RJDC, ...) and DJJ-unmatched-* twins:
# seeds/fl-inspection-cleanup.json, built with the fixed matcher against DJJ's live directory + the closed programs prod holds
(kop-pipeline) .venv/Scripts/python scripts/build-fl-djj-refile.py [--list] [--offline]   # after a mirror sync; writes the seed here
(kop-pipeline) .venv/Scripts/python scrapers/test_fl_scraper.py                           # matcher kinds, renames, __NEXT_DATA__ directory
php -d extension=pdo_sqlite scripts/test-fl-inspection-cleanup.php   # on an in-memory copy of tmp/prod.sqlite
php api/clean-fl-inspections.php [apply]      # on the server (ea-php82): dry run; apply backs up to ~/kop-backups/ first
# FL DJJ matching rule (fl_scraper.py _match_to_facility): never across detention/residential (_facility_kind); a one-word
# key matches only as the facility's side ("Orange ... TrueCore" -> Orange Youth Academy, "Lake Academy" not -> Lake Parrish);
# a report goes to every facility it is about (fl_scraper.py _resolve: a joint name "A & B" / "A/B" part by part; a name
# that cannot tell, and every PREA audit, also by the report's own "Name of Facility" (split on and, &, /) and, between the
# facilities a name fits (Hillsborough East/West), its street address; an address alone never picks one, DJJ reuses
# campuses); nothing settles it = DJJ-unmatched-<name>. Renamed programs: DJJ_FORMER_NAMES. The DJJ site is Next.js since 2026: the directory
# pages are read from __NEXT_DATA__, SPEP from its button/region accordion, the PREA list from the page drawn in headless
# Chromium (_rendered_download_links(); needs Playwright). Closed programs prod files under: DJJ_CLOSED_FACILITIES; reports
# no index lists any more: DJJ_EXTRA_QI_REPORTS.
# UT: the finding statement is declarativeStatement in Utah's API since mid-2026 (findingText before); utah_citation_scraper.py
# reads both (STATEMENT_KEYS) and won't post when most findings lack one. raw_content keeps "rule: title — statement" (ut.js and
# api/inspection-verdicts/ut.php count only those lines: a title-only line shows "No findings"); level, CMP, correction action/date/
# verification go in categories_json "Findings". Rows the Sept 28 / Nov 1 2026 runs stripped: ut_backfill_statements.py rebuilds
# only the finding lines of each prod row (checklist lines and the facility row kept as prod has them); dry run unless --post
# UT checklists: census/capacity come from two side-by-side boxes on the checklist PDF. A read with capacity or census
# missing (box not seen blank) or census above capacity goes to troubleshooting (scrapers/ut_checklist_troubleshoot.py):
# re-read by word position (pdfplumber), then Tesseract OCR (turned upright, each box OCR'd twice; disagreement = unresolved).
# Census above capacity stands only when two readers agree (over_capacity). Unresolved: no numbers posted, listed in
# middle/ut_checklist_review_<date>.json. Multi-program boxes post as "Capacity: 66 (SD 16, RT 50)" (no ";" inside: ut.js
# splits checklist fields on it). --recheck-json re-reads an export's suspect checklists; post it after with --from-json.
(kop-pipeline) .venv/Scripts/python scrapers/test_utah_scraper.py
(kop-pipeline) .venv/Scripts/python scrapers/utah_citation_scraper.py --recheck-json [middle/ut_reports_with_ocr.json]
(kop-pipeline) .venv/Scripts/python scrapers/ut_backfill_statements.py [--ids=94202] [--only=restored] [--from-api-cache DIR] [--post]
# Facility records linked to state inspection rows whose names differ (KOP Tools > Inspection Links, inc/inspection-links.php)
# A program with homes under it (Program Homes) is one card there, linked as a unit ("Same program"; "Only <home>" for a row one home
# suggested); rows a home already shows are not offered; "Link an entry by hand" links any row to any record
php scripts/test-inspection-links.php --file=<scraper --out json> [--state=PA]   # prints the suggestions, checks a linked row reaches the page
# Inspection rankings (KOP Tools > Inspection Rankings, inc/inspection-rollup.php): worst companies, facilities and states by
# approved serious findings (deaths, staff assaults, ...) and the states' own verdicts (citations, high-risk, repeat,
# substantiated complaints; kop_irl_state_measures() says which state publishes what, the rest show a dash). An hourly batch
# counts each report once into inspection_report_counts (bump kop_irl_version() after changing a rule); records and companies
# take inspection rows by the facility pages' name rule + Inspection Links. Pending findings are admin-only, never public
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-inspection-rollup.php [--list]   # rules, full count on an in-memory copy, page parity, the screen
# Plain-language summaries for hard-to-read serious findings (inc/highlight-summaries.php): kop_hs_reasons() picks approved findings with
# codes, numbered people, stitched fragments, run-on sentences or legal citations (about 10%); an hourly job asks the AI (alternating Groq/Gemini)
# for 1-3 plain sentences from the state's text only; drafts wait at KOP Tools > Review inbox > Plain summaries (edit, approve, reject, write
# again) and show above the state's wording on facility pages, home cards and /severe-reports/ only once approved. A waiting Serious violations
# card has "Write a plain summary" (any finding, to read it by: shown at the top of the card, a draft in the same queue, public only once the
# finding is approved too; kop_rinbox_ih_plain()). A summary belongs to the
# exact excerpt it was written from (excerpt_hash): a changed excerpt hides it and a new draft is made. Table inspection_highlight_summaries
php scripts/test-highlight-summaries.php           # which findings are picked (the owner's examples), answer checks, printing
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=highlight-summaries   # the queue with a made-up AI
php api/scan-highlight-summaries.php [--try|apply] [--limit=10] [--ids=704]   # on the server (ea-php82): dry run lists what would get a draft
# Facility lists that states publish instead of inspection reports (MO license-exempt registry, KY, AK, LA, IN, KS/MS PRTF) checked
# against facilities_v2 in tmp/prod.sqlite with the same name key as the facility pages; matched / no record / ambiguous (never forced),
# our open records the list lacks (only where the list covers that type), and an added/gone diff against the previous dated snapshot in
# tmp/state-lists/<st>/<date>/ (MO vanishing from the registry is news). Writes tmp/state-lists/report.md + report.json, no DB writes
(kop-pipeline) py -3 scripts/state-lists-check.py [--state MO ...] [--refresh] [--full]   # --refresh refetches (1 request/s) and adds today's snapshot
(kop-pipeline) py -3 scripts/state-lists-check.py --selftest     # parsers + matcher + diff on made-up fixtures
# Rows with no record (or more than one) carry candidates (record + why: part of the name, one name holds the other, spelling,
# shared words; same town only adds); the export is reviewed at KOP Tools > State Lists (inc/state-lists.php, {prefix}kop_state_list_rows):
# link (one click per candidate or the finder; the listed name becomes an other name only when ticked), create (kop_facdisc_create(),
# citing the list), not TTI, later, exact Undo on Done; "Left the list" never changes a record. Keys: list + licence, else name key + town
(kop-pipeline) py -3 scripts/state-lists-check.py --export tmp/state-lists/state-lists.json   # copy to ~/kop-import/state-lists/state-lists.json on the server
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-state-lists.php   # PHP/script key parity, import, link/create/undo, re-import, left; writes a preview
# Scraper report PDFs stay in link-shared Drive folders; the sync writes only wp-content/uploads/inspection-reports/<st>/index.json of Drive links,
# linked as "Archived copy" on the state report pages (server CLI + nightly cron; no apply = dry run)
php api/sync-inspection-archive.php apply --limit=2000 --minutes=25
```
