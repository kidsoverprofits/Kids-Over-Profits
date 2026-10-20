## 5. Tests failing on main

Each predates the work that found it; fix the test or the code. Last full run 2026-10-20 (mirror synced that day,
main at 366c8d84, portable PHP 8.2 from windows.php.net). test-review-inbox and test-inline-edit pass in full
since 2026-10-20, and test-facility-pages, test-operator-pages and test-fornits
since later that day (see PLAN-ARCHIVE).

- **Hourly task "KOP Fornits" fails every hour (exit 1)** since the 2026-10-17 wipe of kop-pipeline/tmp: its `--log
  tmpornits\process.log` folder is gone. The crawl had finished by 2026-10-02 (the last batch on the server is
  `~/kop-import/fornits/batch-20261002-192403.jsonl.gz`). Owner's call: disable the task (`schtasks /change /tn "KOP
  Fornits" /disable`), or let it re-crawl: with `tmp/fornits/` present and no `crawl.done` it restarts the full crawl
  (about 19 hours) and, `processed.json` being gone too, sends every topic again as new batches. Do not create
  `tmp/fornits/` until this is decided; run test-fornits with `--batch=<a copy of a server batch>`.
- **test-closure-reports**: 2 checks on the 2026-10-20 mirror, failing before the closure re-sort work too: "an untouched
  rewrite changes nothing but the schema upgrade" (kop_closure_rewrite_period() on a real document) and "a closed or
  rebranded name is never touched" (kop_network_map_status_overrides()).
- **test-facility-discovery**: 3 checks on the 2026-10-20 mirror, failing before the vague-name rule too: "a genuinely
  new name is not a near duplicate", "new programs from the dry run stay new", "no record already has its name key in
  its state". Probably records created since the test's names were picked; check its names against the mirror.
