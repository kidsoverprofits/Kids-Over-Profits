# Work plan archive

Finished items moved out of [PLAN.md](PLAN.md) once nothing open needed their context, verbatim,
under the heading they had there. Newest move first. Commit SHAs that name
`carlygaejepsen/Kids-Over-Profits` resolve in the archived old repository.

## Moved 2026-10-18

### Waiting on the owner > Hosting

9. **Staging is deleted** (checked 2026-10-14: no ~/public_html/staging; /staging/ is a 404 and its old
    inner addresses redirect to the live pages). Only a LiteSpeed-cached copy of its old home page still
    answers at /staging/ (noindex). **Owner:** LiteSpeed Cache > Toolbox > Purge > Purge by URL `/staging/`.
    robots.txt no longer disallows /staging/, so Google can recrawl the old addresses and drop them.

### 3.13 Data audit against outside sources (2026-10-06)

3. Turn-About Ranch's current operator (done 2026-10-06): the buyer in
   Aspen's March 7, 2014 sale was Escalante RTC, LLC, co-owned by its
   managers (Webster, Carter, Bartlett); still operating. Its proposal now
   sets current operator and status Open instead of a by-hand step. The same
   sale took Island View, the Aspen Institute for Behavioral Assessment and
   Copper Canyon, which became Family Help & Wellness partners; no source
   names Turn-About Ranch as one, so the record does not.

### 3.24 Clearview Horizon / Clearview Girls Academy; app donate, map and search; SEO (2026-10-14)

7. Tag archives out of search: the owner said yes on 2026-10-14; `kop_seo_tag_robots()` makes them noindex, follow
   and `kop_seo_sitemap_exclude_tags()` drops post_tag from Yoast's sitemap. Checked live after deploy:
   https://kidsoverprofits.org/tag/utah/ reads `noindex, follow` and https://kidsoverprofits.org/sitemap_index.xml lists
   no tag sitemap. LiteSpeed's cached copies of other tag pages pick it up as they expire.
9. /staging/: done 2026-10-15. The owner's purge did not reach it (its cache entry carried the deleted staging site's
   tags, d5d_*); the entry was purged by tag from a one-off script, now removed. /staging/ is a 404 for every visitor.

## Moved 2026-10-14

### Waiting on the owner > Repository split (2026-10-13)

The repository was cut into four (`CLAUDE.md`, "Related repositories";
`docs/REPOSITORY-MAP.md`): this theme, `kop-data` (the datasets, public),
`kop-pipeline` (builders, scrapers, research tools, private) and
`kop-send-to-kop` (the extension), all under a new GitHub account. The
four were pushed on 2026-10-13 (https://github.com/kidsoverprofits), the
server's checkout at `/home/kidsover/repositories/Kids-Over-Profits` follows
the new theme, the second cPanel repository `kop-data` deploys into
`js/data/`, and the eleven commits made on the old repository later that
day were replayed by path (old tip b7ea3dc5). Still open:

1. (Done 2026-10-13.) The owner's secrets are in place and exercised: `CPANEL_TOKEN`
   on both deploying repositories (push-deploys green), `KOP_DATA_TOKEN` on
   `kop-pipeline` (the build's first step now dry-run pushes to `kop-data`,
   so a token made by the wrong account fails in seconds with the fix named)
   and the dispatch token in the server's `.env` next to
   `KOP_NETWORK_REBUILD_REPO=kidsoverprofits/kop-pipeline` (the hourly check
   and Rebuild now both start a `kop-pipeline` run; a fine-grained token
   needs Actions: Read and write there, not only repository access).
   Two tokens made under the old account are to be deleted at
   github.com/settings/personal-access-tokens, and the duplicate
   `public_html/.env` (a copy of the theme's, not web-readable) removed.
2. (Done 2026-10-13 23:40Z.) Every commit made on `carlygaejepsen/Kids-Over-Profits`
   after the split was replayed by path (last one 92d860e5); the Tools
   repository's head (a6126ba8) is what `kop-pipeline/scrapers` holds. Both old
   repositories are archived by step 7 of the script below; their commit SHAs,
   cited in this file and in older notes, keep resolving there.
3. (Done 2026-10-13: the owner ran the script; the mobile app repo was also transferred to kidsoverprofits.) **This machine** (owner, one script):
   `powershell -ExecutionPolicy Bypass -File ..\kop-pipeline\split\finish-local.ps1`
   with no session or scraper using the old checkout. It repoints
   `source\repos\Kids-Over-Profits` at the new repository and resets it to
   `main` (the tree was clean), moves its `tmp/` (8 GB, the mirror included) to
   `kop-pipeline/tmp/`, runs `scripts\link-data.ps1`, repoints the Windows
   task "KOP Fornits" to `kop-pipeline`, shrinks `.git`, deletes
   `C:\tmp\kop-split` and the OneDrive Tools checkout (its cursors and caches
   were copied into `kop-pipeline/scrapers/` first) and archives the two old
   repositories. `Kids-Over-Profits-Tools.code-workspace` already lists the
   four repositories.

### Waiting on the owner > Admin runs, item 1

1. (Done 2026-09-29, no action: Severe Reports joins the Monitor menu
   through `kop_nav_item_specs()` on deploy. `rebuild-header-menu.php`
   was retired (its apply would have dropped TTI Glossary, Open Data and
   Report Abuse) and deleted 2026-09-30.)

### 3.1 Network map

Shipped or closed from this list, all 2026-09-28: the route highlight
(2d.6), the list view with CSV download, the David Gilcrease checks
(2b.1, closed: the module test holds and the deployed build draws all
five), and the kind marks with their legend rows (2d.2 and the rest of
2d.9, the owner's small-caps kind word). The record of what was decided
is NETWORK-MAP.md.

### 3.2 Page templates and posts, item 5 (the 404 page)

   The 404 page is done (2026-10-06,
   [13826135](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/13826135e025cf1605e1024050bee5896a3d1507):
   `404.php`, "Did you mean" from the address's words, a prefilled search
   box, the main destinations and the site map). The category archive
   layout is still open.

### 3.8 Follow-through, items 1 and 2

1. **Home page lawsuit and legislation previews** (Sonnet 5). Done
   2026-09-30 in
   [e169c90f](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/e169c90f20ae8ae77ad68e24732d089cc2d92f5f).
   `templates/page-home.php` now reads `legislation` and `lawsuits`
   through `kop_seed_pdo()`, as the legal-document page fix did
   (c3a7c02d04f72c1cea4c22c81bbfba0cfb66e95a); a null handle or a failed
   query still just hides the card, never fatals. The `inspection_reports`
   / `inspection_facilities` / `facilities_master` counts further down the
   same file stayed on `$wpdb`: they are WordPress-side tables, confirmed
   working that way elsewhere (`inc/facility-pages.php`,
   `inc/global-search.php`). Verified live at
   [kidsoverprofits.org](https://kidsoverprofits.org/): both cards render,
   three bills and three lawsuits.
2. **Site search finds past and other names** (Sonnet 5). Done 2026-09-30
   in
   [72d5e405](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/72d5e4053835a5d1021fbcf74babcbdd44cde82e).
   `kop_v2_search()` now also matches `identification.pastNames` /
   `otherNames` with a LIKE on `json_data` (~4,700 rows), run only when the
   direct name/name_key/unique_name match leaves the result limit unfilled
   and the query is 3+ characters (acronym guard); a hit carries
   `matched_name` / `matched_kind` and `kop_v2_search_alias_hint()` formats
   "Formerly X" / "Also known as X", shown by every caller: the Ajax
   Search Lite header dropdown (`inc/ajax-search-lite.php`), the
   site-wide search widget (`inc/global-search.php`), and the full search
   results page (`search.php`). A direct name match always ranks first, so
   a name belonging to its own separate record (3.7's name-era rule, e.g.
   Bethel Boys' Academy's own record vs. Eagle Point Christian Academy's
   "Bethel Boys Academy" otherName) outranks another record's alias.
   `scripts/test-search-aliases.php` checks this against `tmp/prod.sqlite`.
   Verified live: `/wp-json/kop/v1/global-search?q=Copper+Canyon` returns
   Copper Canyon Academy first, then Sedona Sky Academy marked "Formerly
   Copper Canyon Academy"; `?q=Bethel+Boys+Academy` returns Bethel Boys'
   Academy first, then Eagle Point Christian Academy marked "Also known as
   Bethel Boys Academy".

### 3.8 Follow-through, items 4 and 5

4. **Per-file retry in the report backup** (Done 2026-09-30 in
   [5f98a09f](https://github.com/carlygaejepsen/Kids-Over-Profits-Tools/commit/5f98a09fae021b528ad327201d3679433ae7dc7d)).
   `migrate_pair` in `backup_reports.py` now asks to retry when OneDrive or
   Drive drops partway through, using the existing `kop_paths.ask_retry()`
   prompt. Only cloud-provider errors (WinError 362, 404) trigger the prompt;
   other errors keep the current behaviour. No files are deleted unless the
   upload is verified. Three stray files remain in the `.nc_ocr_cache` and
   `.ar_pdf_cache` local caches; they will be picked up on the next migration
   attempt with the new retry logic.
5. **Guided tours for the public pages** (Done 2026-09-30,
   [583d17f5](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/583d17f560c24419019dd031340a4a24517bf96e)).
   Owner: public-facing tours only, no admin screens. Tours on the
   [network map](https://kidsoverprofits.org/network-map/), the
   [facility directory](https://kidsoverprofits.org/tti-program-index/) (both
   tabs), the state inspection report pages on `report-page.js` and the
   [glossary](https://kidsoverprofits.org/glossary/), loaded by
   `kop_enqueue_tour()` in `inc/enqueue.php`, each checked live. Skipped, as
   having no controls a visitor could miss: /severe-reports/, /report-abuse/,
   /open-data/, facility and operator pages; the data form's provider
   category is already a step of its tour; a site-wide search tour would put
   a second "?" button on every page. Open: CA and TX report pages get a
   tour when they move to the shared viewer (3.3.2).

### 3.8 Follow-through, owner: the FAQ

- ~~**Read and publish the FAQ**~~ Done: the FAQ is published (15 questions,
  KOP Tools > Page Text; `js/data/pages/faq.json`), as are Young Adult
  Programs, Indian Boarding Schools and the Network Map.

### 3.14 Mobile app, done 2026-10-07

Done 2026-10-07 (theme [5d8b452b](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/5d8b452b),
[9fc251fa](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/9fc251fa); app
[7930afd](https://github.com/kidsoverprofits/kids-over-profits-mobile/commit/7930afd)): fixtures refreshed and
extended to Copper Canyon / Sedona Sky, Newport Academy and its home Acre, Provo Canyon, Rebekah Home, Acadia
Montana and Ascent (`php scripts/test-mobile-api.php --db=tmp/prod.sqlite --id=9605,9606,9607,12155,12161,100284,9758,10371,10865,12688,9688 --dump=<dir>`;
on the mirror it now builds homes and name eras as the site does); a render test per section
(`__tests__/facility-sections.test.tsx`); the "Formerly" line carries its sources; every `fact_sources` key is
checked to have its fact; `kop/v1/operators` (12 KB) feeds the Companies tab, which opens companies by slug.
Found on the way and fixed: a renamed program's page in the app showed only its own record's lists, never the
other name's (it is now cut into "As <name>" sections like the website); the other name's staff carried the
"network map" citation on the website too; Elan School's payload pointed at the /facility/ page that 301s to
/elan/. Still to do by hand: Newport Academy – Alberca's address reads "1850 Calle Alberc,. Camarillo" (record
10087). No record holds published survivor testimony yet, so that section is tested on a made-up account.

### 3.17 Updates reach every page, item 4

4. Wiki approval's staff go straight onto the record (owner, 2026-10-13):
   done, cited to the Reddit page, logged, taken off when the approval is
   taken back (scripts/test-wiki-staff.php). The merge had never run on the
   live site (it read `wiki_master`, which does not exist there). Backfill run
   2026-10-13 on the server (api/backfill-wiki-staff.php apply): 285 pages,
   785 staff added from 231 entries; a "role" that is a sentence of the
   write-up goes on blank. Not merged: 14 entries whose linked record is gone
   (merged or renamed since), 7 linked to a company whose program they name
   is not under it, 8 with no link (Review inbox > Wiki links).
   Every entry's additions are a card at Review inbox > Wiki staff added
   (inc/review-inbox/wiki-staff.php): Looks right, Take them off, Undo.
