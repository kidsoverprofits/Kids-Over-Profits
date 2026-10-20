# Work plan

The one list of open work on the theme and on https://kidsoverprofits.org. Every item is an action:
who does it, what to do, where. Background lives elsewhere, so this file stays short:

- [PLAN-ARCHIVE.md](PLAN-ARCHIVE.md): finished items, verbatim, under their old headings. The whole of the
  old sections 2 and 3, as they stood before the 2026-10-20 reorganisation, is there under "Moved 2026-10-20":
  an item below marked "(old 3.12)" has its background in section 3.12 there.
- [RESEARCH-TASKS.md](RESEARCH-TASKS.md): open research questions (sources that disagree, records that look
  wrong). Add one whenever it comes up; take it off when settled.
- [DATA-FLOWS.md](DATA-FLOWS.md), [ADDING-CONTENT.md](ADDING-CONTENT.md), [ADMIN-UI.md](ADMIN-UI.md),
  [NETWORK-MAP.md](NETWORK-MAP.md), [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md),
  [FIX-PLAN-2026-09.md](FIX-PLAN-2026-09.md) and the area READMEs in [README.md](README.md).

Reorganised 2026-10-20 at
[841d47f4](https://github.com/kidsoverprofits/Kids-Over-Profits/commit/841d47f44e7a54c484476955c254b33cfd62fb70).
Add new items to the section they belong to, in the same shape (one bold lead, the action, where, the
old section for background); when one is done, delete it here and put its record in PLAN-ARCHIVE.md.

---

## 1. Working in this repository

The owner's rules:

- **The default target is the live site.** Test against it after every
  deploy. Treat work as local (Flywheel) only when the owner says so.
- **Work on `main`.** No branches, no PRs. A push to `main` deploys through
  `.github/workflows/deploy.yml` and `.cpanel.yml`.
- **No emojis** anywhere: chat, UI text, code, comments, commits.
- **Reports to the owner carry full clickable URLs**: live pages, GitHub
  commits as `https://github.com/carlygaejepsen/Kids-Over-Profits/commit/<full sha>`,
  admin endpoints. Never bare paths or short SHAs.
- **No text directly on the gradient background.** Check with
  `python scripts/check-bare-text.py` after any template or page CSS change.
- **Colours** from `var(--kop-*)` in `css/colors.css`. Chartreuse, coral pink
  and bubblegum pink are for borders and highlights; accent colours as text
  use the `-ink` tokens.
- **Never link `/tti-program-index/` for a facility.** Facility links go to
  `/facility/<slug>/` or the state hub's `?search=`.
- **Program and organisation websites** link to the Wayback Machine first,
  and to the live site only through `/go/`.
- **Ask before any write to production.** Reads are fine.
- **Open items go in this file as you go** (owner, 2026-10-14). Whenever a session has worked about
  five minutes without the owner's input, write down here what is still open from that work (owner
  steps, follow-ups, things found broken, checks to make after deploy), in the section it belongs
  to, before going on. Don't save them all up for the end of the session.
- **Finished items move to [PLAN-ARCHIVE.md](PLAN-ARCHIVE.md)** once nothing still open needs their
  context: verbatim, under the heading they had, dated. A finished step that explains how open work
  behaves (a rule it set, what a tool does) stays here; a finished step whose leftovers are open
  keeps those leftovers here.

The checkout at `C:\Users\daniu\source\repos\Kids-Over-Profits` is shared with
other sessions that commit and push to `main` at the same time:

- Stage files by path. Never `git add -A`, `git add .`, `git stash`,
  `git reset --hard` or `git clean`.
- Read `git diff --cached` before committing, and `git show --stat HEAD`
  after. Other sessions have swept staged files into their commits.
- When a file you need carries someone else's uncommitted hunk, stage a blob
  of `HEAD` plus only your change (`git hash-object -w`, then
  `git update-index --cacheinfo`). Committing another session's
  `require_once` of an uncommitted file would fatal production.
- Push with `git pull --rebase --autostash origin main`, then
  `git push origin HEAD:main`.
- If Source Control fills with files already on `main`, compare each to
  `origin/main`, `git reset --mixed origin/main` (moves the branch, touches no
  file), refresh only files equal to an older committed version, and set the
  upstream to `origin/main`.
- **Deleting a file in git does not delete it on the server.** Add an
  `rm -f` task to `.cpanel.yml` for each removed file.
- Template assignments are `kop_template_assignments()` in `inc/admin.php`;
  bump the `$version` in `kop_maybe_apply_template_assignments()` when the
  list changes. Redirects are `kop_redirect_map()` in `inc/redirects.php`.

Mechanics:

- **Line endings are mixed**, CRLF and LF, sometimes in one file. Keep each
  file's endings; match whitespace-tolerantly; assert each target string
  matches once.
- **The Bash tool collapses backslashes.** Put regex-bearing edit scripts in
  a file and run the file, or use the Edit tool. A Python script containing
  `unlink` was refused as destructive where the same Edit went through.
- **PHP**: none on PATH. Use
  `$LOCALAPPDATA/Programs/Local/resources/extraResources/lightning-services/php-8.2.27+1/bin/win64/php.exe`
  with `-n -l` to lint, and `-n -d extension_dir=<that bin>/ext -d
  extension=pdo_sqlite -d extension=mbstring` (plus `sodium`, `fileinfo`,
  `zip` where a test needs them) for the harnesses.
- **Production mirror**: `tmp/prod.sqlite`, refreshed by
  `python scripts/sync-prod-sqlite.py`. It goes stale fast; refresh before
  inventory work, and confirm a finding on the rendered page before
  reporting it (item 20 of the fix plan was wrong for reading the mirror).
- **Production over SSH**: `ssh -i ~/.ssh/kop_nixihost -p 1157
  kidsover@dfw-s07.nixihost.com`, database `kidsover_production`, prefix
  `wpdl_`, no wp-cli. The auto-mode classifier has refused SSH reads in some
  sessions; do not work around it, use the mirror and ask the owner.
- **Deploy check**: `gh run list --workflow=deploy.yml --limit 5`. A failure
  reading "VersionControl/update failed after 3 attempts" is the host; the
  next push deploys your commit too (`git merge-base --is-ancestor`). Then
  fetch a static asset with a browser user agent; HTML pages are bot-walled
  for outside curl, but curl over SSH with a browser user agent works.
- **Browser checks**: Python Playwright with `channel="chrome"` against the
  live site. `scripts/preview-network-map.py`, `scripts/preview-hub-pages.py`
  and `scripts/preview-utility-pages.py` splice the working tree into live
  pages.

---

## 2. Owner: runs and settings only the owner can do

Admin browser sessions, cPanel, SSH, scripts on the owner's computer. Tools that change data show a dry run
first; add `?apply=1` to act. The date is when the item was last confirmed open.

### 2.1 cPanel, server and accounts

1. **Share the inspection report folder** (2026-10-17). Share `My Drive/KOP Inspection Reports` as "Anyone
   with the link", then `php api/sync-inspection-archive.php apply` on the server and check the dry run lists
   index entries for every state. Until then the nightly sync drops the archive index of every scraper folder
   (old 3.3 item 6).
2. **Severe findings scan cron** (2026-09-29). Add the line in `docs/state-inspection-reports/README.md` in
   cPanel > Cron Jobs; send the first lines of `/home/kidsover/logs/inspection-highlights-scan.log`.
3. **Monthly Unsilenced check cron** (2026-09-30). Add as is:
   `0 4 1 * * cd /home/kidsover/public_html/wp-content/themes/child && /opt/cpanel/ea-php82/root/usr/bin/php api/list-unsilenced-files.php restart --check --minutes=120 >> /home/kidsover/logs/unsilenced-check.log 2>&1`.
   On its mail, ask a session for the rebuild (old 2, item 10).
4. **News discovery crontab** (2026-10-13). (a) the 13:30 line loses `--no-facilities`; (b) the 01:30 line
   gets `--recency 30d` for one night, then loses it; (c) optional: drop `AI_PROVIDER=groq` from both;
   (d) optional: a third line at 07:30 (old 3.8, old 3.16).
5. **Drive Sync schedule** (2026-10-17). Keep the nightly `15 3 * * *`, or `*/15 * * * *` for documents
   within 15 minutes.
6. **PDF covers** (2026-09-29: 177 left).
   [regenerate-pdf-previews.php?apply=1](https://kidsoverprofits.org/wp-content/themes/child/api/regenerate-pdf-previews.php?apply=1)
   until it reports 0.
7. **Staff "Former" passes** (2026-10-17, both with exact undo, dry runs done): on the server
   `php api/fix-staff-unconfirmed.php apply` (2,651 entries on 226 open programs) and
   `php api/fix-wiki-staff-former.php apply` (29 wiki entries on 21 records).
8. **Search Console** (2026-10-15). Grant Site Kit the Search Console permission (Settings > Connected
   services), or export Performance and "Why pages aren't indexed" to Sheets. Then delete `~/kop-gsc.json`
   and `~/kop-gsc.err` on the server.
9. **Small cleanups.** Delete the two tokens made under the old GitHub account
   (github.com/settings/personal-access-tokens); remove the duplicate `public_html/.env`; delete comment 453 on
   [/hyde/](https://kidsoverprofits.org/hyde/) (a staging pingback); clear the leftover
   `wpdl_anonymous_submissions` row or drop the table; optional: ask NixiHost to purge
   `SUB-2025-YLVUY0CL` from backups.
10. **State Lists upload** (2026-10-05). Ask a session for `tmp/state-lists/state-lists.json`, upload it to
    `~/kop-import/state-lists/` (make the folder beside `kop-import/gdocs`), then review at
    [KOP Tools > State Lists](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-state-lists).
11. **Google Docs export, last two sheets** (2026-10-01). Ask a session for a fresh `tmp/gdocs/export.gs`,
    paste it at script.google.com and Run once more ("TTI Database - Master" and the 2014 analysis sheet hit
    Google's rate limit).
12. **Folders on no page** (2026-10-14). Link "Hyde" (19) to Hyde School (297), "ANASAZI" (214) to Anasazi
    Foundation (5659) and "Agape Boarding School" (7815) to Agape Baptist Academy (4819) with
    `api/link-folders.php`, or move the files with the tile pencil.
13. **Billings Clinic testimony** (2026-09-30). In the [admin data form](https://kidsoverprofits.org/admin-data/),
    providers tab, MONTANA PROVIDERS > Survivor Testimony: delete the stray paragraph "Medication", tick "OK to
    publish", save.
14. **Featured report notes** (2026-09-18). Add a `featured_note` to the two featured UHS of Provo Canyon
    reports in [manage-featured-inspections.php](https://kidsoverprofits.org/wp-content/themes/child/api/manage-featured-inspections.php).

### 2.2 Scrapers and long runs on the owner's computer

1. **PA rerun with OCR version 2**: `python pa_scraper.py --full` (~5,750 documents, ~5.5 s a page, needs
   1.5 GB free). Replaces the 164 waiting 2012-17 PA findings (old 3.3 item 5).
2. **Backfills**: MN with Chrome closed (`py -3 scrapers/mn_scraper.py --backfill-pdfs`, ~20 min); CA is the
   owner's call (30,885 reports, 3-5 days: sync the mirror, then `py -3.14 ca_scraper.py --backfill-pdfs`).
   GA, TX and CT were started 2026-10-17: confirm they finished.
3. **First posts, then publish the page** (run from the launcher): New Hampshire, Wyoming, Idaho, Maine, Ohio,
   Iowa, Maryland, South Dakota, Virginia (run VDSS again first, and `va_scraper.py --no-post --source dbhds`
   once from cache), Hawaii, West Virginia (after 2.4's decisions). Colorado: the five steps in
   `plans/colorado.md` Status on a healthy evening.
4. **Regular runs**: Oklahoma at least monthly; New Hampshire, Wyoming and Ohio monthly; Hawaii quarterly;
   WA once with `wa_scraper.py --full` and `KOP_DATA_API_KEY`.
5. **Unsilenced backup** (once I: has room, ~2.5 days, 118 GB): start
   `Start-Process python -ArgumentList 'scripts/unsilenced-backup.py','run' -WindowStyle Hidden` in kop-pipeline;
   keep C: above 4 GB. Then ask a session for the titles and rebuild (old 3.11).
6. **Document finder retries**: `py tti_doc_finder.py upload --retry-failed` (1,783 failed, 383 waiting).
7. **Scraper launcher window**: open it and confirm the size and Output pane look right.
8. **MN report filed under two licences** (2026-10-20): the 2026-10-18 MN run filed the 2024-12-02 correction
   order `LLO_471879` (licence 1074484, 180 Degrees Inc) under 180 Degrees Hope House (1086169) too, as
   `MN-1086169-002`. Check whether DHS lists it on both licence pages; if not, fix `mn_scraper.py`'s matching and
   take the copy off Hope House on /mn-reports/.
9. **`scrapers/test_scrapers.py` has 5 stale failures** (2026-10-20, all from before the CA/PA fixes in kop-pipeline
   `f22040c`): the CA payload test unpacks two values from `scrape()` (it returns three now), the Oregon date
   format, `smart_title_case` (two cases) and the data structure check. Bring the tests up to the code.
   Run them with `py -3`; plain `python` lacks `requests` and `pymupdf`.

### 2.3 Review queues to work through

All at [Review inbox](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-review-inbox) unless named.

1. **Wiki links**: "Link every clear match", then pick the 25 (old 3.12 step 1).
2. **Data audit**: the high-confidence button first, then the rest; the card `clearview-horizon-2026-10-14`;
   open a source before approving `status_years-10371` or `person_common-34`. By-hand steps it lists: split
   Jenny Jones off person #181, two Glenn Bender role dates, the Kelly Cole consultant record, map line p0439
   in `network-overrides.json`, the record merges (old 3.13).
3. **Record cleanup**: check Alternative Youth Adventures, Canyon State Academy and Boulder Creek Academy
   before tidying many; merge people with two ids at Merge People first (old 3.36).
4. **Write-ups to approve**: approve the first few by hand to judge the drafts (see 2.4).
5. **Rules from documents** (359 cards), **Company milestones** (114; "Check" lines carry caveats),
   **Federal funding** ("Add every high-confidence match" first), **Nonprofit tax filings** and **Business
   filings** (bulk link first, then "Check the match").
6. **Map Years** (250 sourced years) and **Map Renames** (50 with a sourced year) at KOP Data Tools.
7. **Woodbury Reports** pages ([2,208 candidates](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-woodbury-reports)):
   Articles first, then News items; "No record yet" is a lead list.
8. **Program Homes**: 139 suggested programs and the
   [Companies that are one program](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-program-homes&tab=companies)
   tab (keep NeuroRestorative with "It is a real company").
9. **Merge Duplicates, Three Springs/Sequel** (keep first, earlier name second, as a rename):
   #100076 <- #9656 (or "Program and its academic program"), #100078 <- #9657, #13640 <- #100077,
   #11098 <- #9654. Check first: #9648 vs #9639 (Duck River); "Three Springs New Directions" on #100076 and
   #13678; "Three Springs of North Carolina" on #9637 and #11903 (old 3.7).
10. **Inspection Links**: PA (30 records, 48 same-town pairs ticked), then the near misses named for MI, OK,
    NH, WY, ID, ME and OH (old 3.3 item 6).
11. **Serious findings**: the North Carolina queue (782 pending, none approved), then
    [13 approved findings to recheck](https://kidsoverprofits.org/wp-content/themes/child/api/review-inspection-highlights.php?status=approved&ids=230,1285,1123,1306,561,1307,616,859,875,114,199,408,894).
12. **News clippings trial**: articles #10491-#10500 at
    [Submissions Review > News](https://kidsoverprofits.org/admin-submissions/?type=news); untick places that
    are not programs. Then say go for the other 44 (3.6).
13. **Industry PR refiling**: 148 rejected news items (4 done).
14. **Research library**: rate relevance tiers in the [research library](https://kidsoverprofits.org/researchreports/)
    editor. (Research tag proposals wait for the review screen in 3.6.)
15. **Directory Editor, "Same name or no name" tab**: company records whose card another record of the same
    name holds; merge them one by one.
16. **Card Add-ons**: tick the add-ons per queue at
    [KOP Tools > Card Add-ons](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-card-addons).

### 2.4 Decisions waiting

1. **Held facts**: raise the write-up batch (3 drafts an hour; AI budget `KOP_AI_BACKGROUND_SHARE`) or not;
   and which other writers count as "facts" for the hold rule (company/consultant filings, young adult facts,
   wiki staff on approval, Rules from documents, federal funding, Drive Docs links, data audit) (old 3.31).
2. **Wiki updates for entries with no exported draft** (~170 of 296): should the site draft their old gaps
   too (one switch: drop the baseline for entries `update-drafts.json` lacks) (old 3.12 step 6).
3. **Family Foundation School lawsuits** (~33 federal suits, 2026): one lawsuit record per case, or one for
   the litigation listing every docket (old 3.23).
4. **Scraper privacy and scope**: NH, The Ridge RTC 2025-12-15 statement; WY, Meadowlark 2024-04-04 notice
   (false positive); MD, post the 15 summaries with initials cut out?; WV, the 16 unsure rows and ~1 GB of
   Drive for `wv_pdfs/`; CO, the 9 unsure facilities; NV, go-ahead to delete the junk reports "1", "2", "3",
   "..." on /nv-reports/; OR, a records request for complaint investigations and licensing actions.
5. **Site content** (old 2, items 11-15): hub editor content (the five recommendations); unpublish the dev
   posts `facility-form-test`, `data-organizer`, `data`, `data-analysis`, `arizona-adhs-inspections`; 301 the
   75 News posts to their feed entries; per lawsuit post, 301 or keep; deactivate Code Snippets snippet 6
   (OG Image).
6. **Provo Canyon School profile** ([post 10796](https://kidsoverprofits.org/wp-admin/post.php?post=10796&action=edit)):
   suspended or Closed? Then publish.
7. **Article type labels**: print the list's labels ("Lawsuit Article") on /news/, state and country pages
   instead of the raw value?
8. **Network map judgement calls** in `tmp/network-qa.md` (rebuilt by every build); the six curated aliases
   naming another era (#13927, #10602, #11361, #11462, #11355, #12621); investors as their own map kind
   reading the Investors table, or added by hand.
9. **The misplaced 1933 line** on /idd-timeline/ ("Initially served 'mental defectives'"): Devereux or the
   Southard School.
10. **Not built, the owner's call**: two volunteers per item, or high-agreement volunteers applying directly;
    an AI pass smoothing the About prose; tighten the Rules-from-documents cap if long cards bother.

### 2.5 Writing to read and publish

1. **Parent company histories**: 35 drafts on their [/operator/](https://kidsoverprofits.org/operator/) pages
   (Sequel TSI rewritten 2026-10-14, seed v11). Read against the sources, fix with the pencil, Publish. Record
   questions they raised are in old 2, item 19 and RESEARCH-TASKS.md.
2. **Related industries pages** (5 drafts): check for older Page Text edits overriding the new sections, read,
   publish. Owner checks: the 13-year-old who died after leaving ResCare's Woodward Children's Home (March 15,
   2017) is not in the memorial; Christy Scheck's memorial date may be two days early (old 2, item 21).
3. **The Brochure and the Record** (seed v7): read and publish (old 3.4).
4. **Long-form articles**: which sections to wrap in `[kop_section]` (15B); summaries, "Why this matters",
   era tags (15C-E); Advocacy History, Corporatization, Lawsuits (18A-C) (old 3.4).

### 2.6 App and extension

1. **Try the app on a phone** (Expo Go, `npx expo start` in `kids-over-profits-mobile`): a facility with
   incidents and staff, the Map tab (search, Back, the drawer, Key/List/Timeline), Send (each kind once, check
   it reaches the Review inbox), Donate, a search for "Copper Canyon".
2. **Accounts and builds**: an Expo account and `eas build --profile preview --platform android`; Apple
   Developer (99 USD a year) and Google Play (25 USD) only to publish; ship an EAS update or store build.
3. **Artwork and listing**: icon, splash, adaptive icon; short and long descriptions, screenshots, age rating;
   check `/privacy-policy/` covers the app.
4. **Site links that open the app**: after the first real build, the Apple Team ID and the Android signing
   SHA-256, for a session to ship the two `/.well-known/` files.
5. **Send to KOP extension**: load it unpacked and send one page; publish
   `dist/send-to-kop-<version>.zip` to the Chrome Web Store (5 USD) and Firefox add-ons; then a session puts the
   store links on `/send/`. Safari needs a Mac with Xcode.

---

## 3. Checks after deploy

Shipped and never looked at on the live site. Signed in unless noted; take each off when it passes.

1. **Review inbox**: the page and dropdowns up in a second or two, counts filling in after; cron
   `kop_review_inbox_counts_cron` in WP-Cron. Page and post drafts: Preview, Publish then Back to draft,
   Schedule.
2. **Record cleanup**: the first open of [Record cleanup](https://kidsoverprofits.org/admin-submissions/) works
   out the cards (~30 s).
3. **Write-ups**: approve one Woodbury Facts item; it shows under "Approved, held for the write-up" and in the
   admin-only box on its facility page (not logged out); "Write it with AI", edit, Approve, Undo.
4. **Records reach pages at once**: approve one article, its facility page shows it; option
   `kop_resync_keep_underived` is 1.
5. **Wiki auto updates**: option `kop_wiki_auto_last` shows ~296 baselines, `left` 0, no `errors`; a card
   appears after the next change to a linked program.
6. **Status columns**: over SSH, `SHOW COLUMNS FROM lawsuits LIKE 'status'` is varchar(40) and option
   `kop_status_columns_varchar` is '1'. [Dropdown Lists](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-choice-lists)
   at 390 and 1280 px: add a test choice, see it, Undo.
7. **Card Fields and filing on any record**: open the inbox once, then
   [Card Fields](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-card-fields); on a Websites sent in
   card, Move to Materials and links on a company, Undo. On a News card, "Connect a record" to a consultant
   (see it on [/referrers-educational-consultants/](https://kidsoverprofits.org/referrers-educational-consultants/)),
   Take off. At [/admin-submissions/](https://kidsoverprofits.org/admin-submissions/): Website as a consultant's,
   Resource for a company, each with Undo move.
8. **Card add-ons**: on a Nonprofit tax filings card, "Link to a company", pick "New company named ...", check
   its note and folder, Undo from the Linked tab.
9. **Admin kit screens**: [Drive Docs](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-drive-docs),
   [Fornits](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-fornits),
   [Woodbury Facts](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-woodbury-facts): add one, Undo, cards
   stack on a phone.
10. **Volunteer project**: at [Volunteer Reviewers](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-volunteer-reviewers)
    a 10-item serious-findings project for yourself; answer three in a private window, "Check their calls",
    Apply one; turn the test volunteer off.
11. **Pencils**: at [San Marcos Treatment Center](https://kidsoverprofits.org/facility/san-marcos-treatment-center-tx/),
    the era pencil, "Renamed, not closed" then Undo, a Fornits thread retitled, an Unsilenced file's title and
    type. At [Provo Canyon School documents](https://kidsoverprofits.org/facility/provo-canyon-school-ut/#documents):
    no stray pencils, "Select documents", move a few, Undo; the same on a company page.
12. **Lighter pages**: [Provo Canyon School](https://kidsoverprofits.org/facility/provo-canyon-school-ut/) in a
    private window: open "more articles" and the Unsilenced groups, pick a document type, print preview.
13. **Timelines**: [Sequel TSI](https://kidsoverprofits.org/operator/sequel-tsi/),
    [UHS](https://kidsoverprofits.org/operator/universal-health-services/) (filters, hover and tap cards) and
    [Sedona Sky Academy](https://kidsoverprofits.org/facility/sedona-sky-academy-az/). Sequel TSI's history draft
    shows its tables, at 390 px too.
14. **Names then on company pages**: [Three Springs](https://kidsoverprofits.org/operator/three-springs-inc/)
    lists "Three Springs of Courtland" (now Brighter Path Courtland); Sequel TSI lists "Sequel TSI Courtland".
15. **Investors**: [Investors](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-investors) lists the five
    imported (check each guessed role and years: Patriot Capital's start, Pine Tree's role); the Investors line on
    Sequel TSI and one of its programs; link one, Undo; "Altamont" in the header search and at
    [/?s=altamont](https://kidsoverprofits.org/?s=altamont).
16. **Directory Editor**: [the screen](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-directory-editor):
    hide a company, it leaves [/tti-program-index/](https://kidsoverprofits.org/tti-program-index/), Undo; "Every
    field" and "Edit program" open. Hide one, save it in the admin data form, check `operator.directoryHidden`
    survives. On the directory: fold two alternate names, Undo; Set company on a row, check the listing and the
    facility page, Undo.
17. **Drive Sync**: [Sync now](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-drive-sync) turns to
    "Reading the Drive folder..." then a report. If it never starts, see `~/logs/drive-sync.log` and fall back
    to a WP-Cron single event.
18. **People**: open KOP Tools > People once (makes the tables); after the hourly sync, a person on two
    programs shows the same "Also worked at" on both; tick "Became an educational consultant" on one, check the
    facility page; add a relationship between two map people, check both pages and the next map build draws it.
19. **Data entry changes of 2026-10-14 and 10-17**: KOP Tools > Document Types shows the 7 staff-ideology
    documents and the editor's notes, retype waiting AI guesses; paste court-order text at /submit-lawsuit/,
    KOP Tools > Lawsuits and a pending lawsuit card; "Mark as lawsuit news" then Undo; Review inbox > Conflicts
    (Kind: Type) "Both are right" then Undo; tick two types on a Facilities from News card.
20. **Discovery and AI**: the nightly discovery log shows "daily list: N records", no 503s and no cron "failed"
    line on a quota night; [AI Usage](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-ai-usage) names every
    job (unnamed ones go in `kop_ai_usage_jobs()`). Data audit Waiting and the Conflicts section counted again.
21. **Inspections**: a VA card at Review inbox > Serious violations opens a Drive PDF.
22. **Rich Results Test** on a facility and a company page once Google recrawls them.

---

## 4. Session work, by area

Pick from here. Items marked "owner first" need the owner's word before starting.

### 4.1 Measure and tune (due now)

1. **News discovery after a week** (from 2026-10-20): approved vs rejected for `auto-discovery via google-news`
   against the 45 / 109 baseline, `batch-unconfirmed` rows in Scraper finds (any real story?), the cursor and
   "AI confirmations" lines in `~/logs/discovery-2026-10.log`. Then tune `AI_CONFIRM_PER_RUN`, `GN_SUBMIT_EVERY`
   and the blacklist. If real stories sit in batch-unconfirmed, add "It is about <name>" to Scraper finds
   (old 3.16).
2. **AI budget**: read AI Usage for each provider's real daily ceiling and set `KOP_AI_DAILY_GEMINI` / `_GROQ`.

### 4.2 Inspection reports

1. **CA and TX onto the shared viewer**, CA first (9,100 reports held twice under two id schemes; many rows
   named by facility number). jsdom harness, before/after snapshot; `severe-flags.js` still lands (old 3.3 item 2).
2. **Poor-scan flag**: show "read from a poor scan: check dates against the PDF" for `categories.ocr_poor_pages`
   on the Serious violations card and the PA viewer. No plain summaries from PA OCR version 1 text; after the
   rerun, check a few 2013-16 findings.
3. **VA VDSS PDFs**: `va_scraper.py` saves each VDSS inspection page into `va_pdfs/` with its `archive_name`.
   **Utah cards**: rows carry no checklist id, so "Archived copy" finds none.
4. **Serious-findings scan coverage**: confirm MI and PA have their extractor rules (plan steps 10 and 11); WA,
   OR and NV are out for now. Oregon extension (abuse cases, restraint reports) per `plans/oregon.md`.
5. **Inspection rankings**: look at the screen once prod has counted; a public page ranked within each state;
   a block on facility and company pages; per-bed rates (old 3.3 item 7).

### 4.3 Records, names and data quality

1. **Name eras**: rename years into the records (`identification.renamedFrom` / `renamedTo`) so the map
   draws from them; dated items follow the years (articles, lawsuits, inspections, closures); a name never
   resolves through another record's names, and Facilities from News offers "Create this name's record"
   (old 3.7, steps 2-4). Hand-set eras (`kop_name_eras`): into the map build, its sync and
   `inc/network-rebuild.php`'s fingerprint, and cutting the facility page; company names have no column yet.
   About 150 past names not on the map have no rename year.
2. **Data flows** (DATA-FLOWS.md section 4): the operator/company-link writers, the people sync, the memorial,
   approved highlights and Map Renames/Years fire `kop_record_changed()`; drop the dead
   `kop_facility_v2_request_sync()` calls, then the 10-minute flush; a nightly drift check screen and
   `scripts/test-record-events.php`; the smaller items in its section 3 (old 3.17).
3. **Duplicated fields**: fold note lines repeating a filled field from a second source (171 on 91 records) into
   one line with both sources; Fornits and Drive Docs auto links still write without a click (old 3.36).
4. **Held facts**: a record created from a Woodbury card is still public at once; companies' About still comes
   from notes (needs the same write-up on `kop_operator_page_data()`) (old 3.31).
5. **Staff**: ~400 wiki staff at open programs whose write-up says "is the ..." (check against each program's
   staff page, perhaps a Data audit kind); confirm `kop_staff_former_yearly()` ran in early 2027; write past
   jobs straight to `kop_person_jobs` from Woodbury Facts, the wiki and the data forms, and company people's jobs.
6. **Wiki staff not merged**: 14 entries whose record is gone, 7 linked to the wrong company, 8 unlinked.
7. **Data research** (old 3.5): alternate names (4,334 records with none); years that disagree (New Dominion
   School of Virginia, Withlacoochee JRF, CEDU Middle School, Elevations RTC, Marion Youth Academy, Bartow Youth
   Training Center, Lexington Academy, Auldern Academy, Three Springs of Englishton Park, Three Springs Paint Rock
   Valley, Three Springs School of Madison, Oakley School, New Leaf Academy of Oregon); unplaced records (New
   Directions Home for Boys; "Aspen Education Group #2" 100001, an operator filed as a facility).
8. **Single records**: Renaissance Center, Fresno (100134: sources, folder, status; empty folders 7765 and 7766
   can go); Chicago Lakeshore Hospital (13461) is empty (ProPublica 2018-19, the CMS termination, ALJ CR5480);
   Newport Academy Alberca's address (10087) reads "1850 Calle Alberc,."; Psychiatric Solutions should list
   #10370 as Rivendell of Utah; "ClearView Communities LLC" folder (FileBird 92), another company name to look into.
9. **Provider saves**: a provider project named after a bare state is still filed as a location profile
   (`saveProjectToCloud` in `js/data-form-modules/api.js`, the state-name override in `api/save-master.php`).
10. **Program types**: the directory, search and map show the joined string; no per-type filter.
    **Program homes**: the directory, the map and search still list each home of a grouped program on its own.
11. **Data model phase 5** (owner first): remove the old model per `docs/DATA-MODEL-MIGRATION.md`, backup
    first, one step per commit.

### 4.4 Review inbox and admin screens

1. **Lawsuits from CourtListener**: build `inc/lawsuit-discovery.php` + `api/discover-lawsuits.php` per the
   plan in old 3.16 item 3 (crontab 03:00 Central; `scripts/test-lawsuit-discovery.php`).
2. **Admin kit**: the Review inbox (one primary decision, the rest in More; `rinbox-btn-undo` reused for
   Cancel/Rename/Close); Data Manager and the merge screens; search and paging for Bug Reports, Closure
   Reports, Facilities from News, Glossary Feedback, Young Adult Programs, Indigenous Schools; the old wp-admin
   screens; last the `api/*` pages (old 3.25).
3. **Record pickers**: Drive Docs and Fornits finders take programs only (`js/admin-kit.js` refuses a
   non-number); Wiki links, Woodbury Reports and Woodbury Facts still have their own create forms (move onto
   `kop_record_field_param()` with optional city/state/type); a "Run by a company" add-on, off by default;
   Facilities from News does not follow articles filed on a company; check notes filed on consultants and the
   other kinds read well on their list pages; podcasts link programs and companies only (old 3.19, 3.30).
4. **Volunteers**: route the five submission queues (`api/manage-submissions.php`) through the same apply so
   "Apply ticked" runs them; a per-link volunteer view for Drive Docs (old 3.26).
5. **Pencils still missing**: survivor-site archive lists (the `unsil` treatment keyed by url), the operator
   page's research and Unsilenced lists, the summary lines under Materials and links (old 3.27).
6. **Dropdown Lists**: `js/data-form-modules/data-page.js` should read `window.KOP_CHOICE_LISTS`; an added
   status can only borrow a built-in badge colour.
7. **Research tag proposals from document text**: a one-page review screen proposing facilities and operators
   from documents' contents, with acronyms guarded; replaces the JSON file of
   `api/propose-research-facility-tags.php` (old 3.8 item 6).

### 4.5 Wiki

1. **Wiki editor badge**: "KOP has new information" on entries whose record changed.
2. **Staff line split**: `kop_wiki_auto_staff_line()` does not split a "was also" role pair as `also_roles()` does.
3. **Stub and missing pages** (827 stubs, 724 index slugs) in "new entry" mode, after the existing entries.
4. **Rules sections**: after Rules from documents lines are added: sync the mirror, rerun `scripts/wiki-gaps.php`, draft the
   sections.
5. **Family Foundation School**: once 2.4 item 3 is decided, read the complaints, add named staff (Doe and
   initials plaintiffs stay so), seed through `seeds/lawsuits.json` with docket numbers, then the wiki line for
   entry 533 (old 3.23).

### 4.6 Sources, imports and research reads

1. **Business filings PDFs**: once companies are linked, copy kop-pipeline `tmp/colorado-sos/pdfs/` (and
   Florida's) to `~/kop-import/business-filings/co/` (`fl/`), run `api/import-business-filings.php` (dry run
   first); rebuild Florida after its download. Then Utah (old 3.20 item 3), quarterly refreshes, the app, open
   data, officers into people ids.
2. **Nonprofit filings**: the app section; Form 990 Part VII officers and Schedule R into people and the map;
   monthly rerun; an open data dataset (old 3.19).
3. **News clippings**: the other 44 (`clippings-ocr.py run` + `upload`, then `api/import-clippings.php apply`
   with the owner's go).
4. **Program documents**: find and import the six handbooks held only as JPG thumbnails (Gateway Academy 2013
   and 2014, Caribe Vista 1999, Cherokee Creek x3, GCTC 2021 x3); `--types reading`; operator libraries into the
   inventory (old 3.21).
5. **Podcasts**: pilot episodes 221, 115 and 116 for the owner; read every batch as transcripts finish; copy
   each `proposals.json` to `~/kop-import/podcasts/`; write person and program facts onto records with Undo;
   `attended` facts draw the survivor-category map line (old 3.18).
6. **Google Docs**: the facts pass (staff, owners, years, names, addresses, closures, every fact quoted) and the
   sheets (a column map per sheet); `resourceLinks` in the open data downloads (old 3.9).
7. **Survivor archives**: the name-by-name privacy pass for ssi, wwasp, straights and nhym (SCIAD NET is done).
8. **HEAL citations**: ~76 have no Wayback address; have `woodbury-facts.py --also tmp/heal` write the capture
   address from `tmp/heal/cdx.txt`, rerun the facts load (old 3.22).
9. **Federal funding**: years for Bernalillo Academy and Evoke Cascades and the 62 records with none; the
   rosters not read (Montana, Maine, Alaska, Oregon, TRICARE, SBA PPP FOIA, USAspending/TAGGS) (old 3.15).
10. **Unsent sources** (12-an-hour cap): rerun `tmp/send-milestone-sources.py` until done, then it sends
    `tmp/other-sources.json`; the Royal Commission summary needs its Wayback address and PDF; the 14 youth
    sources of the nursing homes draft; https://www.fox13now.com/2019/08/14/employee-at-treatment-center-in-southern-utah-facing-felony-child-abuse-charge;
    lawsuits #54 Parker and #55 Bence need source_urls (CourtListener gov.uscourts.oknd.78072, docket 73624806).
11. **Our copies**: reprint the Vive FAQ, Maple Lake FAQ + Emotional Health and Asheville Academy FAQ with the
    answers opened; perhaps a PDF of New Lifehouse's hidden AI text.
12. **Old /lawsuits/ page**: the Dimple Dell canyon suit and the cases behind two Salt Lake Tribune stories on
    revision 926, through the lawsuit form path.
13. **Network map undated names**: rerun the year research for the 81 with none, with a raised search cap.
14. **Company pages** for Bain Capital, CRC Health Group and Keystone Education and Youth Services (they carry 4
    approved milestones).

### 4.7 Pages and templates

1. **Visual QA of all hubs** at 390, 768 and 1440 (`scripts/preview-hub-pages.py`); only Law & Policy, History,
   Survivors and Support were checked. Fix in `css/hub.css` under `.kop-hub*`.
2. **Post classification**: refresh the mirror, rerun `scripts/inventory-posts.py`, write
   `docs/POST-CLASSIFICATION.md`; confirm `in_news_feed` for every News post before 2.4 item 5 is acted on.
3. **"Where are the kids?" posts**: recommend `single-facility-profile.php` where a facility page covers the same
   facility. Then act on the owner's decisions in 2.4 item 5.
4. **Category archive layout** on a solid panel, with search and hub links.
5. **"In this article" / "In this section"** on the history pages (spec at the end of
   PAGE-TEMPLATE-DESIGN-PLAN.md).
6. **Advocacy History and Survivors hub writing**: Key Milestones, "Submit your story", the Survivors hub's
   thesis line and link descriptions; drafted in the page body for the owner.
7. **Admin stylesheets** still use about fifty accent colours as text (16B).

Done for any template task: PHP lints, the offline test passes, screenshots at 390, 768 and 1440, `check-bare-text.py`
passes, deploy green, live HTML checked (no `critical error`), the design plan's Status updated.

### 4.8 App

1. **Accessibility pass**: VoiceOver and TalkBack, the largest font and a tablet (colours in `src/theme/colors.ts` follow
   `css/colors.css`).
2. **Deep links** on a device (`npx uri-scheme open kidsoverprofits://facility/<slug>`); site addresses after 2.6 item 4.
3. **Essential reporting**: the strip above the news tab, the flag and note on a card, a list for `?featured=1`.
4. **News tab filters**: the story and month filters reset when it unmounts.
5. **Payload notes**: the app payload's `notes` list still carries the Woodbury wording and raw addresses (both readers clean
   them when they print).
6. **Map tab coupling** (a standing caution): the map page's class names and button ids are read by the app (`mapPage.ts`, `mapBridge.ts`): renaming one
   on the website breaks the Map tab.

---

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

---

## 6. Later, or not without the owner's word

- **Network map**: chain hulls and group-by-network (2d.4); "Suggest a correction" from the map (Phase 3); new person
  nodes for relationships to people not on the map.
- **Investors**: a data form tab, a public page, site map, open data, people ids for partners, filing cards on an
  investor.
- **Discovery**: grouping short names by state; a weekly GDELT pass and Bing News (declined 2026-10-13).
- **App**: other outlets' coverage of a story, paging through every inspection report, an offline index, notifications.
- **Directory Editor**: reordering programs within a card; editing the location tab's place groups.
- **Lighter pages**: Provo's staff list and the timeline's later years, if pages are still heavy.
- **Unchecked**: no real upload through the anonymous portal since it began sealing files; no live call to the
  Claude, Gemini or Hugging Face models in the AI helpers.

### Unconfirmed settings

- **Data model and notify settings**: which mode production's `kop_data_model` / `kop_data_model_areas` options use for the `program_index` read area,
  and the value of `kop_submission_notify_emails` (no admin screen; default `admin_email`).
