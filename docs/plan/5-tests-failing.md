## 5. Tests failing on main

Each predates the work that found it; fix the test or the code.

- **test-review-inbox**: `scripts/test-review-inbox.php` (`-d memory_limit=6G`), 28 checks in data-audit, drive-docs,
  facilities-from-news, federal-funding, fornits, inspection-highlights ("a rejected finding offers approve and back
  to pending"), inspection-links ("Undefined array key 0" at inspection-links.php:127), websites, wiki-updates
  (another queue's `home_of_program` action), ya-file, ya-moved, merge-follow, woodbury-facts ("not conflicts:
  another staff role": a 2021 HEAL sighting is now "Former Founder"). `scripts/review-inbox-tests/fornits.php`
  seeds only an empty table; re-seed it into its own.
- **test-facility-pages**: `scripts/test-facility-pages.php`, 13 checks (merged Facility Profile posts; "the page renders to the end after
  the post"; "links a staff entry and a fact" samples Aspen Education Group, now a renamed program).
- **test-facility-eras**: `scripts/test-facility-eras.php`, 1 check.
- **test-name-under-operator**: `scripts/test-name-under-operator.php`, Psychiatric Solutions / #10370.
- **test-wiki-gaps**: `scripts/test-wiki-gaps.php`, "a page with no years or place matches only its exact name" (Mountain Home Academy).
- **test-woodbury-facts**: `scripts/test-woodbury-facts.php`, "every staff entry added or filled names its source and link" (4 without).
- **test-fornits**: `scripts/test-fornits.php`, "every link added" finds 11 records missing (#13972, #11097, #10333, #13795, #10496,
  #100048 ...): check `kop_fmerge_follow_waiting()` moved the waiting rows.
- **test-mobile-api**: `scripts/test-mobile-api.php`, "facility 9607 library files are {id, title, url, ext, mime, size,
  thumb}": the document types commit (6165d1bd) added `types` to each file; the check predates it.
