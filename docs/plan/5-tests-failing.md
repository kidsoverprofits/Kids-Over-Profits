## 5. Tests failing on main

Each predates the work that found it; fix the test or the code. Last full run 2026-10-20 (mirror synced that day,
main at 366c8d84, portable PHP 8.2 from windows.php.net).

- **test-review-inbox**: `scripts/test-review-inbox.php` (`-d memory_limit=6G`), 42 checks on the 2026-10-20 mirror
  after 366c8d84 (was 61; that commit fixed card-fields, writeups, record-cleanup, program-homes, inspection-links and
  the `home_of_program` action lists). What is left, by cause:
  1. *Proposals naming records merged away since their build file was made*: business-filings, nonprofit-filings,
     data-audit and federal-funding "every proposal is well formed", data-audit "nearly every step can be made"
     (148 of 1105 blocked, e.g. #9644, #9659, #11653). Either follow the merge (`kop_fmerge_follow_waiting()` /
     the merge log) in those checks or rebuild the seed files (kop-pipeline).
  2. *Checks that pick a live sample that has changed*: nonprofit-filings Link ("Childhaven, Inc. already has this
     organization", so Undo / second Link / company Link all fall through), data-audit "company fields and moved
     articles still fit" (Sequel's executives and websites were edited), conflicts (0 staff items with a role on the
     record; no roll-up, so its roll-up checks skip: seed two Woodbury conflicts of one kind on one record),
     woodbury-facts auto-add "0 of 4089" and "the pass files what adds nothing", federal-funding "Add puts every entry
     on the record" and "Undo leaves an entry added by hand since", drive-docs ("Undo it before editing" at
     drive-docs.php:475), wiki-updates "moves to Ready for Reddit" and "the text is put in Reddit form".
     Fix: seed the sample into the scratch copy, as the other queues' tests do.
  3. *program-practices*: needs `kop-pipeline/tmp/doc-read/practices.json`, lost in the 2026-10-17 tmp wipe;
     rebuild it with doc-text.py (kop-pipeline).
  4. *Fornits* (12 checks: filters, counts, progress, "lead to the news queue": Undefined array key "id"):
     `scripts/review-inbox-tests/fornits.php` seeds only an empty table; re-seed it into its own.
  5. *Still open from before*: facilities-from-news "after Remove the link it can be linked again", ya-file
     ("the card's own name picks its program": "0"), ya-moved and merge-follow (0 waiting rows to move),
     woodbury-facts "not conflicts: another staff role" (a 2021 HEAL sighting is now "Former Founder").
  - Run from a scratch worktree, program-practices and three wiki-updates draft checks also fail: they look for
    `kop-pipeline` next to the checkout. Not a bug; run from the real checkout.
- **test-fornits**: `scripts/test-fornits.php` cannot run: `tmp/fornits/` is gone (the 2026-10-17 junction wipe of
  kop-pipeline/tmp). Rebuild a batch with `python scripts/fornits-process.py --no-upload` (kop-pipeline), then
  check "every link added" (11 records missing before: #13972, #11097, #10333, ...; `kop_fmerge_follow_waiting()`).
- **test-inline-edit**: `scripts/test-inline-edit.php`, 2 checks (same with and without 366c8d84): "31 earlier names
  filed as closed become renames" (#10401 -> Bryce Canyon Academy, #11201 -> Okeechobee Youth Development Center) and
  "52 company records round-trip unchanged" (#500017 keyStaff.keyExecutives.0.personId missing on one side).
- **test-mobile-api**: `scripts/test-mobile-api.php`, "facility 9607 library files are {id, title, url, ext, mime, size,
  thumb}": the document types commit (6165d1bd) added `types` to each file; the check predates it.
