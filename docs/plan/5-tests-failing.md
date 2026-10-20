## 5. Tests failing on main

Each predates the work that found it; fix the test or the code. Last full run 2026-10-20 (mirror synced that day,
main at 366c8d84, portable PHP 8.2 from windows.php.net).

- **test-review-inbox**: `scripts/test-review-inbox.php` (`-d memory_limit=6G`), 4 checks on the 2026-10-20 mirror
  (was 42; see PLAN-ARCHIVE "5. Tests failing on main, review
  inbox causes"). Run it alone: a second PHP run beside it ran Windows out of paging file. What is left:
  1. *Build files naming records deleted outright* (no merge to follow; merged ones are followed since 2a11d220):
     data-audit and federal-funding "every proposal is well formed" (#100014, #9660), business-filings "every company
     is well formed" (company #832). Rebuild those files in kop-pipeline.
  2. *program-practices*: needs `kop-pipeline/tmp/doc-read/practices.json`, lost in the 2026-10-17 tmp wipe;
     rebuild it with doc-text.py (kop-pipeline).
  (The two older ones, facilities-from-news "after Remove the link it can be linked again" and ya-file "the card's
  own name picks its program", were test bugs and are fixed: 4 checks left in the full run, all rebuilds above.)
  - Run from a scratch worktree, program-practices and three wiki-updates draft checks also fail: they look for
    `kop-pipeline` next to the checkout. Not a bug; run from the real checkout.
- **test-fornits**: `scripts/test-fornits.php` cannot run: `tmp/fornits/` is gone (the 2026-10-17 junction wipe of
  kop-pipeline/tmp). Rebuild a batch with `python scripts/fornits-process.py --no-upload` (kop-pipeline), then
  check "every link added" (11 records missing before: #13972, #11097, #10333, ...; `kop_fmerge_follow_waiting()`).
- **test-inline-edit**: `scripts/test-inline-edit.php`, 2 checks (same with and without 366c8d84): "31 earlier names
  filed as closed become renames" (#10401 -> Bryce Canyon Academy, #11201 -> Okeechobee Youth Development Center) and
  "52 company records round-trip unchanged" (#500017 keyStaff.keyExecutives.0.personId missing on one side).
- **test-facility-pages**: `scripts/test-facility-pages.php`, 2 checks on the 2026-10-20 mirror: "every seeded facility
- **test-closure-reports**: 2 checks on the 2026-10-20 mirror, failing before the closure re-sort work too: "an untouched
  rewrite changes nothing but the schema upgrade" (kop_closure_rewrite_period() on a real document) and "a closed or
  rebranded name is never touched" (kop_network_map_status_overrides()).
- **test-facility-discovery**: 3 checks on the 2026-10-20 mirror, failing before the vague-name rule too: "a genuinely
  new name is not a near duplicate", "new programs from the dry run stay new", "no record already has its name key in
  its state". Probably records created since the test's names were picked; check its names against the mirror.
  exists and cites pages" and "every tagged facility has a page" (11098, 12808, 14160 have no facilities_v2 row;
  merged or deleted since the seed). Data, not the page template.
- **test-operator-pages**: `scripts/test-operator-pages.php`, "every drafted history names a company with a page":
  Beloved Ones, Center for Positive Changes, Chad Youth Enhancement Center, Dimondale, Heritage Youth Services,
  NeuroRestorative, New Hope Of Arizona, New Horizon Youth Homes, Pacific Autism Center (converted into programs or
  removed since seeds/operator-histories.json was written).
