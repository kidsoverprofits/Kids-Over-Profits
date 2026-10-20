## 5. Tests failing on main

Each predates the work that found it; fix the test or the code. Last full run 2026-10-20 (mirror synced that day,
main at eb2acbeb, portable PHP 8.2 from windows.php.net).

- **test-review-inbox**: `scripts/test-review-inbox.php` (`-d memory_limit=6G`), 61 checks on the 2026-10-20 mirror
  (34 on the 2026-10-13 one: most of the rise is tests that sample live data). By cause:
  1. *Proposals naming records merged away since their build file was made*: business-filings, nonprofit-filings,
     data-audit and federal-funding "every proposal is well formed", data-audit "nearly every step can be made"
     (148 of 1105 blocked, e.g. #9644, #9659, #11653). Either follow the merge (`kop_fmerge_follow_waiting()` /
     the merge log) in those checks or rebuild the seed files (kop-pipeline).
  2. *Checks that pick a live sample that has changed*: nonprofit-filings Link ("Childhaven, Inc. already has this
     organization", so Undo / second Link / company Link all fall through), data-audit "company fields and moved
     articles still fit" (Sequel's executives and websites were edited), conflicts (0 staff items with a role;
     "Unknown conflict" at `inc/review-inbox/conflicts.php:86`), woodbury-facts auto-add "0 of 4089" and "the pass
     files what adds nothing", writeups (9 checks: approving holds facts, held list, waiting records), federal-funding
     "Undo leaves an entry added by hand since", drive-docs ("Undo it before editing" at drive-docs.php:475).
     Fix: seed the sample into the scratch copy, as the other queues' tests do.
  3. *`home_of_program` add-on on cards it should not reach*: data-audit rename-only card, wiki-updates card 75,
     writeups "with no draft offers only Write it with AI".
  4. *Harness gaps*: card-fields runs MySQL `SHOW` at `api/lib-suggested-edits.php:50`; inspection-links finds 0
     programs with homes and then reads `array_keys($map['programs'])[0]` unguarded
     (`scripts/review-inbox-tests/inspection-links.php:127`).
  5. *Fornits* (12 checks: filters, counts, progress, "lead to the news queue": Undefined array key "id"):
     `scripts/review-inbox-tests/fornits.php` seeds only an empty table; re-seed it into its own.
  6. *Still open from before*: facilities-from-news "after Remove the link it can be linked again", ya-file
     ("the card's own name picks its program": "0"), ya-moved and merge-follow (0 waiting rows to move),
     woodbury-facts "not conflicts: another staff role" (a 2021 HEAL sighting is now "Former Founder").
  - Run from a scratch worktree, program-practices and three wiki-updates draft checks also fail: they look for
    `kop-pipeline` next to the checkout. Not a bug; run from the real checkout.
- **test-fornits**: `scripts/test-fornits.php` cannot run: `tmp/fornits/` is gone (the 2026-10-17 junction wipe of
  kop-pipeline/tmp). Rebuild a batch with `python scripts/fornits-process.py --no-upload` (kop-pipeline), then
  check "every link added" (11 records missing before: #13972, #11097, #10333, ...; `kop_fmerge_follow_waiting()`).
- **test-mobile-api**: `scripts/test-mobile-api.php`, "facility 9607 library files are {id, title, url, ext, mime, size,
  thumb}": the document types commit (6165d1bd) added `types` to each file; the check predates it.
