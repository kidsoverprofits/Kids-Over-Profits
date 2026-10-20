## 5. Tests failing on main

Each predates the work that found it; fix the test or the code. Last full run 2026-10-20 (mirror synced that day,
main at 366c8d84, portable PHP 8.2 from windows.php.net). test-review-inbox and test-inline-edit pass in full
since 2026-10-20 (see PLAN-ARCHIVE).

- **test-fornits**: `scripts/test-fornits.php` cannot run: `tmp/fornits/` is gone (the 2026-10-17 junction wipe of
  kop-pipeline/tmp). Rebuild a batch with `python scripts/fornits-process.py --no-upload` (kop-pipeline), then
  check "every link added" (11 records missing before: #13972, #11097, #10333, ...; `kop_fmerge_follow_waiting()`).
- **test-facility-pages**: `scripts/test-facility-pages.php`, 2 checks on the 2026-10-20 mirror: "every seeded facility
  exists and cites pages" and "every tagged facility has a page" (11098, 12808, 14160 have no facilities_v2 row;
  merged or deleted since the seed). Data, not the page template.
- **test-operator-pages**: `scripts/test-operator-pages.php`, "every drafted history names a company with a page":
  Beloved Ones, Center for Positive Changes, Chad Youth Enhancement Center, Dimondale, Heritage Youth Services,
  NeuroRestorative, New Hope Of Arizona, New Horizon Youth Homes, Pacific Autism Center (converted into programs or
  removed since seeds/operator-histories.json was written).
- **test-closure-reports**: 2 checks on the 2026-10-20 mirror, failing before the closure re-sort work too: "an untouched
  rewrite changes nothing but the schema upgrade" (kop_closure_rewrite_period() on a real document) and "a closed or
  rebranded name is never touched" (kop_network_map_status_overrides()).
- **test-facility-discovery**: 3 checks on the 2026-10-20 mirror, failing before the vague-name rule too: "a genuinely
  new name is not a near duplicate", "new programs from the dry run stay new", "no record already has its name key in
  its state". Probably records created since the test's names were picked; check its names against the mirror.
