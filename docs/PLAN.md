# Work plan

The one place for open work on the theme and on https://kidsoverprofits.org:
how to work in this repository, what is waiting on the owner, and what a
session can pick up next, in order. It replaces four handoffs (2026-09-18,
the two of 2026-09-24, and the page-template handoff of 2026-09-25) and the
open-work parts of the September fix plan. Those handoffs are in git history
if you need their wording.

Last checked against `main` at
[58b45532](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/58b455321b8a36123835f85d94cffac0ed6b4c4f)
on 2026-09-28. Check `git log origin/main` for what has landed since, and
update this file in the same commit as the work it tracks.

Open research questions (sources that disagree, records that look wrong, years a draft had to guess) are
in [RESEARCH-TASKS.md](RESEARCH-TASKS.md): add one there whenever it comes up, and take it off when settled.

The record of finished work, with the reasoning behind each decision, stays
where it was written:

- [PLAN-ARCHIVE.md](PLAN-ARCHIVE.md): finished items moved out of this
  file, verbatim, under their old headings.
- [FIX-PLAN-2026-09.md](FIX-PLAN-2026-09.md): items 1 to 20 of the
  September fix list (code comments cite its item numbers).
- [NETWORK-MAP.md](NETWORK-MAP.md): the map's phases, with a "Built" note
  per step.
- [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md) and
  [PAGE-CLASSIFICATION.md](PAGE-CLASSIFICATION.md): the template families and
  what became of every default-template page.
- The area READMEs listed in [README.md](README.md), for example
  `anonymous-portal/`, `state-inspection-reports/` and `news-processor/`.

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

## 2. Waiting on the owner

Actions only the owner can take (admin browser sessions, hosting, editorial
calls). Tools that change data show a dry run first; add `?apply=1` to act.
The date is when each was last confirmed open.

### Repository split (2026-10-13)

Done (the record is in [PLAN-ARCHIVE.md](PLAN-ARCHIVE.md)). Left for the owner: delete the two
tokens made under the old account at github.com/settings/personal-access-tokens,
and remove the duplicate `public_html/.env` (a copy of the theme's, not
web-readable).

### Admin runs

2. **PDF covers** (2026-09-29: 177 left).
   [regenerate-pdf-previews.php?apply=1](https://kidsoverprofits.org/wp-content/themes/child/api/regenerate-pdf-previews.php?apply=1)
   until it reports 0 remaining. A PDF that gets the process killed (the
   browser sees a 503) is marked and skipped on the next run; two full
   Woodbury issues and one Sequel TSI PDF are marked so far.
3. **Severe findings review** (2026-09-29). The nightly scan cron is not
   installed (no crontab entry, no log on 2026-09-29). Add it in cPanel >
   Cron Jobs, the line in `docs/state-inspection-reports/README.md`. Then
   send the first lines of
   `/home/kidsover/logs/inspection-highlights-scan.log` (expect "Saved:
   scanned N reports, 0 remaining"), then review candidates in
   [review-inspection-highlights.php](https://kidsoverprofits.org/wp-content/themes/child/api/review-inspection-highlights.php).
   Nothing reaches [/severe-reports/](https://kidsoverprofits.org/severe-reports/)
   unapproved. Scanner version 4 (2026-09-28) adds NC, GA, MN, AR and FL
   and tightens two rules; approved findings the new rules no longer back
   stay published until rejected by hand:
   [13 approved findings to recheck](https://kidsoverprofits.org/wp-content/themes/child/api/review-inspection-highlights.php?status=approved&ids=230,1285,1123,1306,561,1307,616,859,875,114,199,408,894)
   (minors' sexual activity without an adult, single medication errors).
4. **Featured report notes** (2026-09-18). The two featured UHS of Provo
   Canyon reports have no `featured_note`; add one each in
   [manage-featured-inspections.php](https://kidsoverprofits.org/wp-content/themes/child/api/manage-featured-inspections.php).
5. **Research facility tags** (2026-09-23).
   [propose-research-facility-tags.php](https://kidsoverprofits.org/wp-content/themes/child/api/propose-research-facility-tags.php)
   writes `kop-research-tag-proposals.json` to uploads; prune it, then
   `?apply=1`.
6. **Research relevance tiers** (2026-09-23). Rate documents in the
   [research library](https://kidsoverprofits.org/researchreports/) editor.
7. **Staging pingback** (2026-09-23). Delete comment 453 on
   [/hyde/](https://kidsoverprofits.org/hyde/), a pingback from the staging
   copy.
8. **WA full scrape** (2026-09-24). `wa_scraper.py --full` in the Tools repo,
   with `KOP_DATA_API_KEY`.
9. **Woodbury Reports pages** (2026-09-29). Review at
   [KOP Data Tools > Woodbury Reports](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-woodbury-reports):
   2,208 candidates from 73 issues (2006-2014). "File it" puts the pages in
   the program's doc library. Start with Articles (exact matches are
   pre-ticked), then News items. "No record yet" holds ~230 program articles
   with no facility record, a lead list for new records.
10. **Monthly Unsilenced check** (2026-09-30). Add in cPanel > Cron Jobs
    (Once Per Month, or this line as is):
    `0 4 1 * * cd /home/kidsover/public_html/wp-content/themes/child && /opt/cpanel/ea-php82/root/usr/bin/php api/list-unsilenced-files.php restart --check --minutes=120 >> /home/kidsover/logs/unsilenced-check.log 2>&1`
    It lists Unsilenced's archive (read-only, about 20 minutes) and mails the
    submission notification list only when 10+ new documents sit in programs
    the pages list, 100+ anywhere, or the run failed. On such a mail, ask for
    a rebuild: copy `~/kop-import/unsilenced/check/files.jsonl` to
    `tmp/unsilenced/files.jsonl`, sync the mirror, run the build, commit. The
    first full list ran 2026-09-30 (148,269 files); about 5,900 of their
    folders stay unmatched (`tmp/unsilenced/build-report.md`); a past or
    other name on a facility record ties one in.
11. **Billings Clinic testimony** (2026-09-30). In the
    [admin data form](https://kidsoverprofits.org/admin-data/), refresh, open
    **MONTANA PROVIDERS** on the providers tab, and open Survivor Testimony
    (near the bottom; click its heading). Combined and moved on 2026-09-30:
    one 29-paragraph account. Left to do: delete the stray paragraph
    "Medication" (the custom philosophy, moved in by "Move all"), tick "OK
    to publish", save. The account then shows on
    [/mental-health-providers/](https://kidsoverprofits.org/mental-health-providers/)
    as "Submitted by a survivor, shared September 2026"; its source reads
    "Submitted by a survivor (submission #50)" once the page is reloaded
    (bc693647, and the follow-up that drops "Added by an admin" beside a
    survivor and keeps one-word labels out of "Move all").

12. **Export the facility Google Docs** (2026-09-30, step 1 of 3.9).
    Ask Claude for `tmp/gdocs/export.gs` (made by `python
    scripts/gdocs-select.py`; it lists Drive file ids, so it never goes in
    the repo). Signed in as ttiresearch.dani, open
    [script.google.com](https://script.google.com/), New project, paste it
    over Code.gs, Save, pick `exportAll` and press Run; allow the access it
    asks for. Press Run again while the log says "Run again". It saves 167
    Docs as HTML and 18 Sheets as one CSV per tab into My Drive > KOP Doc
    Export. "HEAL INFO NWBHS" lives on kidsoverprofitsdani and is expected
    to fail unless shared to ttiresearch.dani first. First run 2026-10-01:
    182 of 185 saved; "TTI Database - Master" and "Troubled Teen Industry
    Program Analysis - 2014" hit Google's rate limit (HTTP 429). The script
    now waits and retries; paste the regenerated `tmp/gdocs/export.gs` and
    Run once more (it skips the 182).

13. **State Lists** (2026-10-05). Facilities that seven state-published lists
    (MO license-exempt registry, KY, AK, LA, IN, KS and MS PRTF) name but our
    records do not: 168 rows, 81 with a suggested record and 13 that several fit. Ask Claude for
    `tmp/state-lists/state-lists.json` (`py -3 scripts/state-lists-check.py
    --export tmp/state-lists/state-lists.json`; it stays out of git) and put
    it on the server as `~/kop-import/state-lists/state-lists.json`: in
    cPanel File Manager make the folder `kop-import/state-lists` in the home
    directory (beside `public_html`, where `kop-import/gdocs` already is) and
    upload the file there. Then open
    [KOP Tools > State Lists](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-state-lists):
    it reads the file on opening. Per card: Link to this record (tick "Also
    add" only when the listed name should become an other name), Create
    record, Not a TTI facility (Missouri's ordinary boarding schools), or
    Later; Undo on Done. After each `--refresh` (monthly for Missouri) export
    and upload again: decisions are kept, and "Left the list" shows what
    dropped off.

### Mobile app

The app is built and its server routes are live (section 3.14). These steps
need the owner's accounts, devices or artwork.

1. **Try it on a phone** (2026-10-06, not done). Install Expo Go (the
   current version; the app is Expo SDK 57), then in
   `C:\Users\daniu\source\repos\kids-over-profits-mobile` run
   `npx expo start` and scan the QR code (`--tunnel` if the phone cannot
   reach the PC). Open a facility with incidents, staff and survivor accounts
   and say what reads wrong: those sections were empty in every record the
   tests used.
2. **An Expo account** (free) for `npx eas-cli@latest login`, then
   `eas build --profile preview --platform android` gives an APK to install
   without a store.
3. **Store accounts, only to publish**: Apple Developer Program (99 USD a
   year) and Google Play Console (25 USD once).
4. **Artwork and words**: the icon, splash and adaptive icon are still the
   Expo defaults (`assets/images/` in the app repo); a logo is needed. The
   store listing needs a short and a long description, screenshots, and an
   age rating (the pages describe abuse and deaths; the app opens with a
   notice and the Childhelp number). Privacy policy: the site's
   `/privacy-policy/` page works if it says the app asks only for the pages a
   reader opens.
5. **Site links that open the app** need the Apple Team ID and the Android
   signing SHA-256 from the first real build, then
   `/.well-known/apple-app-site-association` and `/.well-known/assetlinks.json`
   shipped from the theme (`.cpanel.yml` copies files, it never deletes). The
   `kidsoverprofits://` links work without them.

### Hosting

9. **Staging is deleted** (checked 2026-10-14: no ~/public_html/staging; /staging/ is a 404 and its old
    inner addresses redirect to the live pages). Only a LiteSpeed-cached copy of its old home page still
    answers at /staging/ (noindex). **Owner:** LiteSpeed Cache > Toolbox > Purge > Purge by URL `/staging/`.
    robots.txt no longer disallows /staging/, so Google can recrawl the old addresses and drop them.
10. **Portal leftovers** (optional). NixiHost backups still hold the one
    pre-encryption submission (`SUB-2025-YLVUY0CL`) until they rotate, unless
    support purges them. The owner deleted the public copies and asked
    archive.org to remove its capture on 2026-09-24; not re-checked, at the
    owner's request.

### Decisions

11. **Hub editor content** (page templates 4.4). Changes ship through
    `kop_apply_text_fixes()` in `inc/admin.php`, never by hand on
    production. Recommendations:
    - Where Are the Kids and Advocates link `/international`, which 301s to
      `/location-index/?type=country`: point the links at the destination.
      (Advocates' `/tti-program-index/` link is correct: operators.)
    - Survivors repeats the support groups and accounts the Resources module
      carries: keep one list on Resources and link to it.
    - Survivors links "Tips for Enjoying Nature..." to the post
      `/11-12-2024/`; if it matches the page `survivor-resources-nature`,
      redirect the post to the page.
    - Research & Reports: "Summary coming soon" on six entries and "Click
      here for notes" links; the library module is the canonical list, so
      trim the editor content to the introduction.
    - Survivors, Research & Reports, Advocates and Resources use `h6` for
      section headings, History `h5` under `h3`: use `h2` and `h3`.
12. **Development leftover posts** (page templates 6.2, most exposed).
    Published and public: `facility-form-test`, `data-organizer`, `data`,
    `data-analysis`, `arizona-adhs-inspections` (2.7 MB). Recommendation:
    unpublish to draft, after checking no link or redirect depends on them.
    `tmp/post-inventory.csv` shows zero inbound links for the first four.
13. **News posts** (6.3). The 75 News posts are now duplicated in the news
    feed. Recommended: 301 each to its feed entry, as `/news/` was.
    Alternative: a `templates/single-news.php`.
14. **Lawsuit posts** (6.4). Per post: 301 to `/lawsuits/#lawsuit-<id>` when
    the row covers it, or keep the analysis and link it from the row.
15. **OG Image snippet.** Code Snippets snippet 6 duplicates Yoast's five
    `og:` tags on the front page. Recommended: deactivate it.
16. **Network map.** Kind marks answered 2026-09-28 (a small-caps kind
    word under the name; open work 3.1.3). Still open: the judgement
    calls in `tmp/network-qa.md` (rebuilt by every map build):
    about 50 profiles naming another node as a past or other name, closed-
    to-closed rebrands, unplaced memorial programs, Silverado Academy,
    "Brent Hall" folded into Brent Charles Hall, and the 56 staff-list
    places not on the board.
17. **Portal table.** Clear the leftover `wpdl_anonymous_submissions` row
    (empty message and contact, a `files_data` blob naming the file) or drop
    the table.
18. **Survivor site archives** (2026-10-01, open work 3.10). Answered
    2026-10-01: go ahead with all of 3.10, redact all private information,
    credit SCIAD NET (its owner agreed to KOP using it).
    - Private information: KOP only links, so a document that is itself
      private (yearbooks, client, intake, medical or school records,
      personal letters of private people) is not listed at all, and a
      private person's name (a survivor, parent, minor, witness, victim; not
      staff, owners, officials or public figures) is taken out of every
      title and group KOP shows. A document whose URL carries a private name
      is not listed. The privacy list in the repo holds no names (keyed by a
      hash of the URL). This covers the New Horizons yearbooks: left out.
    - DDoSecrets' Teen Challenge release stays out: leaked residents'
      records cannot be redacted when KOP only links.
    - SCIAD NET is credited on every block it fills, linked to WWASP Survivor
      Truth's archive page.

19. **Parent company histories** (2026-10-02). 35 drafted histories sit on
    their [/operator/](https://kidsoverprofits.org/operator/) pages as drafts only
    admins see, each with "What to check before publishing" (conflicting dates,
    claims resting on KOP rows or the wiki alone). Read each against its
    sources, fix it with the pencil and set it to Published. Record questions the
    drafts raised: Kaizen Academy filed under CERTS (the Utah licence names
    Heritage Youth Services, a different company from the California operator of
    that name); PACE's homes may be adult; Vivant still listed in Alabama after the
    Senate report says it divested there; Wayne Halfway House's Florida sites
    listed open (Tampa Bay Times 2026 says it left Florida); TrueCore's lost state
    contract dated 2023 on the timeline, 2024 in the news; The Brown Schools page
    mixes in later UHS programs; Rite of Passage has two records (18, 832).
    Sequel TSI was rewritten on 2026-10-14 to docs/WRITING-STYLE.md (seed v11, still a draft): it opens on
    the verdict, and four tables (growth, investors, states that acted, what became of the programs) hold
    the figures. A history paragraph whose lines start with "|" is now a table (an optional first line
    "Table: <caption>"). The four pull quotes the owner added are kept. After deploy, check
    https://kidsoverprofits.org/operator/sequel-tsi/ shows the new draft (tables at 390 px too); open
    questions are under Company histories in RESEARCH-TASKS.md.

20. **Program homes** (2026-10-03). [KOP Tools > Program Homes](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-program-homes)
    suggests 139 programs whose homes or cottages are separate licensed records (Newport Academy CA 34,
    Muir Wood 18, Grafton 16, Hope Institute 15...). Confirm each (untick a record that is not one of its
    homes, rename the program), or mark it "Not one program". Groups with a warning start with a company's
    name (CERTS, Straight, Ascend) and are probably separate programs. Not done yet: the facility directory,
    the network map and search still list each home on its own.
    Its tab [Companies that are one program](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-program-homes&tab=companies)
    (2026-10-08) lists 15 parent companies that are really one program: 9 groups of same-named homes (Dimondale,
    Heritage Youth Services, New Hope of Arizona, New Horizon Youth Homes, Center for Positive Changes, PACE,
    NeuroRestorative IL, Beloved Ones, Four Directions) and 6 companies that are one record (Key Assets Group
    Homes, Chad, D.O.V.E.S., Linden Oaks, Ridge House, Summit School at Nyack). "Make it one program" groups the
    homes, moves the company's history draft to the program page, and 301s /operator/<slug>/ there; Undo on
    the Converted tab. NeuroRestorative is warned (based in Dedham, MA: likely a real company); keep it with
    "It is a real company".

21. **Related industries pages** (2026-10-08). Five drafts, created on deploy:
    /related-industries/ (index) and /foster-care/, /disability-group-homes/, /nursing-homes/,
    /psychiatric-hospitals/, each arguing only how its industry connects to the TTI (rewritten 2026-10-14: shared
    roots, the same companies and investors, the same methods, the same children), then where to report and who
    to learn from. The nursing homes page and the index lead with shared vulnerability (older people, disabled
    people and children depend on their keepers, are isolated and not believed; teen programs build that
    isolation on purpose; NIA, WHO, BJS, the 2007 House hearing). Sources found then and not sent yet (429), to send
    as type website: the April 24, 2008 House hearing (govinfo CHRG-110hhrg41839), H. Rept. 110-669 (govinfo
    CRPT-110hrpt669), and the Australian Royal Commission's final report summary (site down; Wayback copy
    web.archive.org/web/20261011115114/..., which the archive rule wants as a PDF in the media library). Still drafts, so the public URLs 404
    until published; check for older Page Text saved edits overriding the new sections before publishing. Read each, edit at
    [KOP Data Tools > Page Text](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-page-text) or with the pencil,
    then publish in wp-admin; the History hub's "outside" box lists the index once it is published. Questions the
    research raised: UHS's Cedar Ridge Behavioral Hospital and other psychiatric hospitals sit among its TTI records
    (the psychiatric hospitals page says hospitals are outside the TTI); the nursing homes page says the overlap is
    smaller there and names Onex (Skilled Healthcare and ResCare) as the one owner documented on both sides; the
    Joint Commission complaint number on the psychiatric hospitals page came from a search summary (check it);
    the disability page links NeuroRestorative's program page, which changes if that conversion is undone.
    The nursing homes page was rewritten again on 2026-10-14 to docs/WRITING-STYLE.md: the statement gives the
    thesis (same people, same dependence, same investors run both the same way); "Cut off and not believed" puts the
    rates in a table and Bob Bacon's words in a pull quote; the almshouse root leads into it; the owners are a table
    [later the same day, at the owner's word, the isolation section and its sources were cut: no proven parallel in
    nursing homes; the page now argues only the shared root and the owners and staffing model]
    Third draft the same day (owner: pull elder abuse figures, find more owners): the statement says they are one
    business; "The same owners" is a table of six (National Medical Enterprises/Hillhaven + Psychiatric Institutes of
    America, Carlyle, Onex, Bain Capital with Broadstep, Sabra as landlord of Chicago Lakeshore Hospital); "What thin
    staffing costs" has Gupta's 22,500 deaths, the 2024 staffing rule and its 2025 repeal, Ripley, and a table of
    nursing home abuse figures (WHO, GAO, OIG); "The same drugs" sets OIG 2026 and HRW beside Warehouses of Neglect
    pp. 56 and 68. 32 sources. Leads not confirmed: KKR (Benchmark, Sunrise; BrightSpring's youth services not shown
    residential), Kindred/Vencor (Hillhaven + Community Psychiatric Centers 1997), Medical Properties Trust, American
    Health Properties (1990s REIT with nursing homes and child psychiatric hospitals). KOP has no company page for NME/PIA,
    ResCare/BrightSpring, Broadstep or Sabra. Not sent yet (429): website, https://www.sec.gov/Archives/edgar/data/1492298/
    000149229819000006/sbra10k2018.htm, "Sabra Health Care REIT Form 10-K for 2018", notes: nursing home REIT owns Aurora
    Chicago Lakeshore Hospital (acquired 08/17/17) beside its nursing homes.
    Fourth draft (owner: strike the almshouses, say "thin staff" once, drive it with real cases): each owner's cases
    on both sides: Onex (Skilled Healthcare's 2010 understaffing verdict; ResCare Woodward Children's Home death,
    March 15, 2017; Newport Pleasanton substantiated abuse 2023), Carlyle (ManorCare after the 2011 sale-leaseback,
    Washington Post 2018; Newport Bethlehem CT charge 2021), NME (Colonial Hills plea 1992; Christy Scheck, March
    1992), Bain (Brendan Blum 2007; Broadstep Georgetown 2021, Kingstree 2024), Sabra (Chicago Lakeshore, Medicare
    cut 2019). 45 sources. Owner checks: the 13-year-old who died after leaving ResCare's Woodward Children's Home
    (Charleston, WV, March 15, 2017) is not in the memorial or KOP's records; Chicago Lakeshore's record (13461)
    is empty (ProPublica 2018-2019, the CMS termination and ALJ CR5480 belong on it); Christy Scheck's memorial
    date (1992-03-06) may be two days early (San Diego Reader). Nursing home cases left out as weak or unconfirmed:
    Hillhaven (Seattle Times 1991 citations only), Estia (care-minutes undertakings, document not opened), Good Hope
    Manor (Wisconsin orders 2026, Broadstep's ownership then unconfirmed), Sabra tenants (Senior Care Centers after
    Harvey, ownership unconfirmed). Not sent yet (429): 14 youth sources (ProPublica x2, CMS notice, ALJ CR5480,
    Livermore Vine, WSAZ, Gazette-Mail, Live 5, WIS, Deseret, UPI x2, Bain Double Impact, CCP 2017 exhibit); the
    research agent's scratchpad sub.py sends what is still new.
    (Carlyle, Onex) followed by the buyout mortality study and Ripley's "control staffing" (new source 20, Warehouses
    of Neglect pp. 27 and 104), ending on a verdict. Production had no saved Page Text edits for it on that date.
    Checked against the History articles (2026-10-08); the pages now link them where they meet. Errors found in
    the articles themselves, all fixed on deploy by seeds/text-fixes.json (page entries, once each, the old text
    kept as a revision; the 'mental defectives' line moved to Devereux 1935): /birth-of-the-tti/ and /idd-timeline/ date Buck v. Bell 1924 (decided
    1927, 274 U.S. 200; /juvenile-justice-timeline/ already says 1927); /idd-timeline/'s 1965 Lovaas citation
    (title and journal not found; the shock study is Lovaas, Schaeffer & Simmons, Journal of Experimental Research
    in Personality, 1965) and its line "Initially served 'mental defectives'..." under 1933, which reads as
    Devereux's; /warehouses-of-neglect/ says UHS paid about $150m in 2020 (the report's p. 19 says $122 million);
    /juvenile-justice-timeline/ has "1989 – YSI founded by James F. Slattery": YSI was founded in 1991 by W. James
    Hindman (Jiffy Lube's founder; referenceforbusiness.com company history), with Jay Ripley as a co-founder
    (Senate Finance report, p. 26); Slattery's Correctional Services Corp. bought it in 1999 (SEC 10-K, FY1999) and
    in 2005 he bought the youth business back and ran YSI himself (Democracy Now/HuffPost 2013), so the entry
    should say he owned it, not that he founded it; /corporatization/ (1987) and /war-on-drugs/ (1993) give two
    years for Lichfield's Brightway Adolescent Hospital contract: 1993 is right (Deseret News, 1998-03-14: the
    Utah Alcoholism Foundation, the owner, ended its agreement with Lichfield, "who has managed the facility since
    1993"). All six checked against the sources on 2026-10-08 (Buck v. Bell decided 1927-05-02; the Lovaas paper is
    vol. 1, pp. 99-109; the misplaced 1933 line fits Devereux or the Southard School, the owner's call).

### Unconfirmed settings

19. Which mode production's `kop_data_model` / `kop_data_model_areas`
    options use for the `program_index` read area, and the value of
    `kop_submission_notify_emails` (no admin screen; default `admin_email`).

---

## 3. Open work, in order

### 3.1 Network map

Live at https://kidsoverprofits.org/network-map/. Build and ship each on its
own.

1. **Phase 4 integration** (owner's go-ahead 2026-09-28; specified
   2026-09-29 under "Phase 4: integration" in NETWORK-MAP.md, with a
   ten-commit sequence and six open decisions for the owner). Build
   order: 4.4 first step (built 2026-09-29: /history/ drops Miro for a
   still of the Historical view, `images/network-map-historical.jpg`,
   seed_version 2, open decision 6 taken as proposed); 4.1 the map on
   facility pages and profile posts (built 2026-09-29: the Focus view's
   own slice, not one hop, held to focus.js by
   `scripts/test-network-embed.js`; decisions 1 and 2 as proposed); 4.4
   second step, the
   `[kop_network_preview]` shortcode making that still live (built
   2026-09-29, seed_version 3); 4.3 the
   timeline, a year slider (owner 2026-09-29: names with no known years
   are left out while it is on, with a count; people follow their
   places; built 2026-09-29, and the undated names researched on the
   web: 250 with sourced years wait on the owner at KOP Data Tools >
   Map Years (a second pass on 2026-09-29 found 99 of the 180 the first missed); 81 still have none, worth a rerun with a raised web search cap). 4.2, the board re-import, is dropped (owner 2026-09-29:
   nobody edits the Miro board; corrections stay in
   network-overrides.json).
2. Later, not to be started without a word from the owner: chain hulls and
   group-by-network (2d.4), "Suggest a correction" from the map (Phase 3).

After any map change: `node scripts/build-network-graph.js`,
`node scripts/test-network-graph.js`, and
`node scripts/test-network-modules.js > tmp/nm-test.log 2>&1` in the
background (9 to 12 minutes, silent until the end).

### 3.2 Page templates and posts

Phases 0 to 3 of [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md)
are done: hubs from `kop_hub_config()` (`inc/hub-shell.php`), the preview
tool, the Law & Policy updated date and `--live` link check in
`scripts/test-hub-pages.php`, the utility and legal-document templates
(`inc/utility-pages.php`), `/support/` 301 to `/donate/`, the Links card
image. `no-access` still prints a raw `[dlm_no_access]` (Download Monitor
inactive; the owner is not concerned). Never trash, rename or re-slug
`no-access`: Download Monitor points at it by ID.

Open:

- **Review inbox dropdowns and quick load (2026-10-15)**: after deploy, open the inbox on the live
  site: the page and its group dropdowns should be up in a second or two, the counts filling in
  afterwards (Inspection links ~10 s, Duplicate facilities ~4 s cold). Approving an item
  recounts only that queue. If a count stays at "...", check the review-inbox/counts request
  in the browser's network tab. The cron `kop_review_inbox_counts_cron` (every 10 min) should
  appear in WP-Cron after the first page load.
- **Page and post drafts in the Review inbox (2026-10-14)**: after deploy, open Review inbox >
  Page and post drafts on the live site: the Preview frame shows a draft while signed in,
  Publish then Back to draft (or Undo in Recently done) brings back the old dates, Schedule
  sets a future date. 75 drafts were waiting in the mirror (57 pages, 17 posts).

1. **Visual QA of all hubs** (4.2). Run
   `python scripts/preview-hub-pages.py --shots tmp/hub-preview` and look at
   every shot at 390, 768 and 1440. Only Law & Policy, History, Survivors and
   Support were checked. Watch the longest action labels at 390, the
   contribute band at 768 (it switches at 760), and wide editor blocks
   (Research & Reports, Resources, Where Are the Kids' map) sitting on a
   module with no spacing. Fix in `css/hub.css` under `.kop-hub*` only.
2. **Post classification** (6.1). `scripts/inventory-posts.py` exists and
   `tmp/post-inventory.csv` was written on 2026-09-25 with a proposed class
   per post. Refresh the mirror, re-run it, and write
   `docs/POST-CLASSIFICATION.md` in the shape of PAGE-CLASSIFICATION.md
   (`news`, `article`, `legal-record`, `facility-profile`, `tool-or-test`,
   `obsolete`). Confirm `in_news_feed` is true for every News post before
   decision 14 is acted on.
3. **"Where are the kids?" posts** (6.5). For each of the 12, where a
   `/facility/<slug>/` page covers the same facility, recommend
   `single-facility-profile.php`, as the 12 facility-profile posts got.
4. **Act on decisions 12 to 14** once answered.
5. **Category archive layout and a 404 page** (6.6), with search and links to
   the main hubs, after 2 to 4. Both on a solid panel.
   The 404 page is done (2026-10-06); the category archive layout is still
   open.
6. **"In this article" and "In this section" on the history pages**
   (owner's suggestion 2026-09-29; spec at the end of
   PAGE-TEMPLATE-DESIGN-PLAN.md). Programs, people and companies each
   article names, found by matching the network map's names against the
   content and linked to profiles and the map, with a pin/skip map in
   `inc/article-parts.php`; the same across the section on the History hub
   beside the map preview, plus six to eight owner-chosen dated events
   linking to their entries. No events per article: the period band and
   Contents already are that. Hidden below three names, which leaves the
   six pre-industry pages as they are.

Done for any template task: PHP lints, the offline test passes, screenshots
at 390, 768 and 1440 looked at, `check-bare-text.py` passes, deploy green,
live HTML checked (and `critical error` absent), and the design plan's Status
updated.

### 3.3 Inspection reports

1. **The first scan log** (owner item 3), then any new candidates.
2. **Move TX and CA to the shared viewer.** The last two states on legacy
   scripts (`js/inspections/tx_reports.js`, `js/inspections/ca-reports.js`).
   Fix CA first: it loads static `ccl_reports_batch_*.json` and holds about
   9,100 reports twice under two id schemes (`455002153-3` and
   `455002153-3-d92232c9bd`), which inflates hub counts. Use the jsdom
   harness and a before/after snapshot of counts, badges, previews and
   summaries (the FL/NC lite-list method). `severe-flags.js` reads both
   markups; check the flags still land.
3. **Severe-finding extractor for WA.** Built
   (scanner version 4, 2026-09-28): TX, CA, UT, AZ, CT, NC (harm tags only:
   V 131/132, 289-296, 314/315, 366/367, 512-525), GA, MN, AR (federal
   surveys only), FL (DJJ reviews, Failed/Limited indicators). Left out: OR
   (findings are the rule's own wording), WA (scraper interleaves the PDF
   columns), NV (no text). Rules: substantiated only; no child-on-child
   fights; minors' sexual activity only with an adult taking part or an
   assault; single medication errors never, a pattern of them yes;
   elopement only with death or injury. Flags on the trackers match NC,
   GA, MN, AR and FL reports by their document link (08172021).
   Scanner version 5 (2026-09-30): "Suicide attempt" and "Self-harm" are
   separate categories, and training, screenings, prevention plans and
   definitions no longer read as deaths or attempts. Version 6
   (2026-09-30) adds OK: substantiated complaint items at full score, NRS
   visit items at full score, other visit items as citations (17 candidates
   from 994 reports, 6 severe). A facility page used as every report's link
   (OK, MI) is never matched as a document; OK flags match by text.
4. **"What inspectors found" on facility pages** (the rest of fix-plan step
   14.5): approved highlights on `/facility/<slug>/`.
5. **Next state scrapers: Oklahoma, Michigan, Pennsylvania** (researched
   2026-09-30; 15 uncovered states checked, these three plus Idaho and
   Virginia publish report text). One build plan per state, ready to hand to
   an agent, in `docs/state-inspection-reports/plans/` (`README.md` first).
   Oklahoma shows a rolling 36 months and Michigan lets licensees have
   reports removed after two years, so both lose data while they wait. Owner
   items per state: create the `/xx-reports/` page and approve the first
   production post.
   **Michigan live 2026-09-30:** posted (110 facilities, 1,891 reports),
   `/mi-reports/` published. `mi_scraper.py` (Tools
   repo) and `js/inspections/states/mi.js`. A full `--no-post` run: 110
   facilities, 1,891 reports (1,300 special investigations, 281 renewal,
   229 interim, 81 original), 997 flagged, 2003-01-06 to 2026-09-30; every
   investigation but one scan yields its allegations. One posted document
   was a seclusion sheet naming a detained youth: documents that are not
   licensing reports are never posted. 51 of the 110 names reach a
   `facilities_v2` record (`php scripts/match-inspection-names.php
   --state=MI --file=<out.json>`; near misses such as "Calumet Center" /
   "Calumet RTC" are left for other names on the records). Owner: create
   `/mi-reports/` (State Reports template; the tracker lists pick it up once
   published) and approve the first post (both done). (The 1,890 PDFs that landed in the
   local `mi_pdfs/` fallback were moved to Drive on 2026-09-30.) Then the severe
   finding extractor (plan step 10).
   **Oklahoma live 2026-09-30:** posted (93 facilities, 994 reports) and
   `/ok-reports/` published (created on deploy by `kop_tool_page_specs()`). `ok_scraper.py` (Tools
   repo) and `js/inspections/states/ok.js`. A full `--no-post` run: 93
   programs (72 residential, 21 shelters), 994 reports: 844 monitoring
   visits (561 with non-compliances, 1,553 items, 38 marked numerous,
   repeated or serious) and 150 substantiated complaints; 711 flagged;
   2023-10-03 to 2026-09-30; no unparsed blocks. Every page is kept gzipped
   in the Drive folder `ok_html/` (the state shows only 36 months), and
   `--from-saved` rebuilds the same payload from those copies. 50 of the 93
   names reach a `facilities_v2` record (most unmatched are shelters KOP
   does not track); near misses left as they are: "Positive Outcome/St.
   Anthony" / "Positive Outcome - St. Anthony" and "Cookson Hills" /
   "Cookson Hills Christian Ministries". Owner: run the scraper **at
   least monthly** (Oklahoma in `scraper_launcher.py`; owner runs it by
   hand). Severe findings: scanner version 6, see item 3.
   **Pennsylvania live 2026-10-01 (2019 on), older years posting:**
   `pa_scraper.py` (Tools repo), `js/inspections/states/pa.js`,
   `/pa-reports/` created on deploy. DHS Licensing Inspection Summaries per
   licensed unit (595 units: residential incl. PRTFs, transitional living,
   secure detention and care, outdoor, mobile). 2019 on, posted: 529
   units, 3,947 documents (446 citation, 846 plan-of-correction
   follow-ups, 31 licence actions incl. the 2019-20 ChildFirst
   non-renewals and Cove Prep's 2026 provisional licence, 1,615 clean, 998
   licences), 4,647 citations, 1,249 counted as violations; 11 documents
   unclassified, 18 cited ones without a parsed list (full text shown).
   Follow-ups count unless the inspection's citation document is listed
   (the state usually replaces it: 772 of 846). Scans (before mid-2019 and
   many single pages since) are OCR'd; 2009-12 tables give regulation
   numbers only. Long citation text rides in `categories.detail`, sent with
   the report text on open: the list is 3.8 MB (0.36 MB gzipped). The
   pre-2019 pass (about 6,000 mostly scanned documents) was started
   2026-10-01 00:35 and posts as it goes; if it stopped, run `python
   pa_scraper.py` again (seen reports skip, extractions are cached). Names:
   only 5 of 90 `facilities_v2` records match by name (the state lists
   buildings, the records campuses); **owner: KOP Tools > Inspection
   Links** has 30 records with suggested links, 48 same-town pairs ticked:
   check them and Save (`php scripts/test-inspection-links.php
   --file=<pa --out json>` prints the list). Then the severe finding
   extractor (plan step 11: each citation's description of violation;
   follow-ups that repeat a counted citation document skipped).
6. **Second round of state scrapers** (researched 2026-10-01; every state
   and DC has now been checked). Ten build plans in
   `docs/state-inspection-reports/plans/`, in build order: New Hampshire
   and Wyoming first (New Hampshire shows only three years; Wyoming removes
   a provider's documents when it leaves the list), then Virginia, Ohio,
   Idaho, Iowa, West Virginia, Maine, South Dakota, Maryland. `research-log.md`
   there lists what each state publishes and which publish nothing, so the
   research is not repeated. Owner items per
   state: approve the first production post; West Virginia and Maine also need their
   scope tables settled, and New Hampshire, Wyoming and Ohio need a monthly
   run.
   **New Hampshire built 2026-10-01, waiting for the owner's word to
   post:** `nh_scraper.py` (Tools repo), `js/inspections/states/nh.js`,
   `/nh-reports/` created on deploy (empty until the first post). A full
   `--no-post` run: 24 programs, 146 visits (108 complaint, 21 renewal, 5
   compliance, 5 monitoring, 4 quality assurance, 2 revision, 1 new), 141
   flagged, 240 rules not met (15 "Founded, Problem Resolved", counted as
   the state's own compliance level counts them), 2023-10-02 to
   2026-08-13. 133 statements of findings archived to the Drive folder
   `nh_pdfs/` (27 scans read by OCR) and every page kept gzipped in
   `nh_html/`; `--from-saved` rebuilds the same payload. Six visits whose
   counts and items disagree are the state's own (run log). One statement
   (The Ridge RTC, 2025-12-15) is held by the privacy check and the visit
   posts without it; **owner** to decide (children are lettered only; the
   trigger is "Child Advocacy Center"). 11 of 24 names reach a
   `facilities_v2` record; the seven Mount Prospect Academy sites and the
   Nashua Children's Home names are for KOP Tools > Inspection Links.
   Owner: approve the first post, then run it monthly from the launcher.
   **Wyoming built 2026-10-01, waiting for the owner's word to post:**
   `wy_scraper.py` (Tools repo), `js/inspections/states/wy.js`,
   `/wy-reports/` created on deploy. A full `--no-post` run: 26
   facilities (24 Family Services providers, 2 of them with no documents;
   2 health department PRTFs), 413 documents: 88 notices of
   non-compliance, 313 handwritten facility visits (shown as documents
   only, never transcribed, always neutral), 5 corrective action plans and
   recertifications, 7 federal surveys (3 with deficiencies). 69 flagged
   (66 notices where the evidence supports non-compliance, 3 surveys),
   2019-09-05 to 2026-08-12. Notices are scans read by OCR: 16 lack a
   readable allegation or finding (listed in the run log; a notice with no
   finding read shows neutral, never clean). Visit photos (JPEG) are
   wrapped as one-page PDFs so the archive sync picks them up; every
   document goes to the Drive folder `wy_pdfs/`, since the state removes a
   provider's documents when it leaves the list. One notice (Meadowlark,
   2024-04-04) is held by the privacy check, a false positive on "calling
   a youth Miley Cyrus": **owner** to decide; one (Cathedral Home,
   2024-01-29) is not shared by the state (Drive asks for a sign-in). 9
   of 26 names reach a `facilities_v2` record; Cathedral Home, VOA
   Milestone and the Fremont County group homes are near misses for KOP
   Tools > Inspection Links. Owner: approve the first post, then run it
   monthly from the launcher.
   **Idaho built 2026-10-01, waiting for the owner's word to post:**
   `id_scraper.py` (Tools repo), `js/inspections/states/id.js`,
   `/id-reports/` created on deploy. A full `--no-post` run: 40 facilities
   (39 residential, Blue Fire Wilderness Therapy), 156 documents: 117
   statements of deficiencies (all flagged; 503 deficiencies, 75 repeat)
   and 39 no-deficiency letters, 2021-11-16 to 2026-09-21. 12 facility
   folders are no longer on the state's provider list; their reports are
   kept and marked so (three share a licence number with a listed
   facility and say so). PDFs archived to the Drive folder `id_pdfs/`.
   Nothing held by the privacy check. 11 names reach 10 `facilities_v2`
   records; near misses for KOP Tools > Inspection Links: Hawks Landing,
   Hays Shelter Home, IYR Residential Center, Blue Fire. Owner: approve
   the first post.
   **Maine built 2026-10-02:** `me_scraper.py` and `me_scope.json` (Tools
   repo), `js/inspections/states/me.js`, `/me-reports/` created on deploy.
   Scope settled by the owner 2026-10-01 (16 licences of 11 operators in;
   Charlie Health, St. Andre, the Collaborative, Little Wanderers,
   Woodfords and Youth Villages out). A full `--no-post` run: 400 surveys,
   2013-02-25 to 2026-10-02 (256 desk reviews, 127 full agency surveys,
   17 waived; 46 complaint surveys), 136 flagged (accepted plan of
   correction), 69 documents since late 2024 archived to `me_pdfs/`; 3
   surveys of adult programs hidden by default. Nothing held. Only Day One
   and Summit Achievement reach a record by name; NFI North (Beacon House,
   Bridge Crossing, Dirigo Place, Oliver Place, Sidney Riverbend, Stetson
   Ranch, Summit View), Sweetser, Connections for Kids, Good Will-Hinckley,
   KidsPeace and Aroostook (Calais Children's Residential) are for KOP
   Tools > Inspection Links. Owner: run Maine from the launcher to post.
   **Ohio built 2026-10-02:** `oh_scraper.py` (Tools repo),
   `js/inspections/states/oh.js`, `/oh-reports/` created on deploy. One
   row per agency (owner's question: per-facility rows would reach 5 of our
   63 records instead of 3 and repeat each agency's reports across 228
   rows). A full `--no-post` run: 159 agencies in scope (303 residential
   facilities), 116 with reports, 296 compliance reviews (54 full, 18
   focused, 224 other), 169 flagged, 747 findings, 963 technical assistance
   items (never flagged), 2025-07-28 to 2026-09-30. Nothing held. Agency
   rows reach Christian Children's Home of Ohio, Gateway to Success and
   Wilson Children's Home; Michael's Resource & Treatment Center, Necco
   Center and The Buckeye Ranch are for KOP Tools > Inspection Links.
   Owner: run Ohio from the launcher to post, then monthly.
   **West Virginia prepared:** `wv_scraper.py` and `wv_scope.json` (Tools
   repo), with the report adapter and `/wv-reports/` page wired in. The scope
   currently has 66 included, 122 excluded and 16 unsure records; unsure
   records are not scraped. The Helvetica filter passed 55 varied report
   samples (35 State, 20 Federal; 2000-2026), with 98.1% mean OCR word recall
   and a 90.3% minimum; a real 2016 C 173 finding was verified in OCR.
   Owner: settle the 16 unsure rows, confirm Drive capacity for `wv_pdfs/`
   (about 1 GB), and approve the first post.
   **Iowa, Maryland, South Dakota and Virginia built 2026-10-03:**
   `ia_scraper.py`, `md_scraper.py`, `sd_scraper.py`, `va_scraper.py`
   (Tools repo, in the launcher and the guide), adapters
   `js/inspections/states/{ia,md,sd,va}.js`, `/ia-reports/`, `/md-reports/`,
   `/sd-reports/` and `/va-reports/` created on deploy as drafts, PDFs
   synced from `ia_pdfs/`, `md_pdfs/`, `sd_pdfs/`, `va_pdfs/`. Full
   `--no-post` runs: **Iowa** 29 PMICs with visits (46 listed), 242 survey
   visits 2018-09 to 2026-08, 78 with deficiencies (two visits the state's
   database counts as 0 are flagged from the published form, which cites 2
   and 4), nothing held, 2 PDFs the state fails to serve. **Maryland** 20
   providers, 148 inspection summaries 2019-01 to 2025-05, 114 with
   citations, 37 with citations that "may present safety risks"; 7 duplicate
   copies the state posted are skipped; **owner:** 15 summaries are held
   whole because citation comments carry youths' or staff initials ("(KR)"),
   most of Jumoke's 2022-2025 reports among them; say whether to post them
   with the initials cut out. **South Dakota** 25 providers, 118 documents
   2024-05 to 2026-09 (62 licensing studies, 14 with a section not met; 9
   corrective action plans; 47 fire and health inspections, 13 with items
   answered No, 20 scanned forms unread), nothing held. **Virginia** VDSS 19
   facilities and about 180 inspections (run 2026-10-01; on 2026-10-03 the
   state's facility pages returned empty inspection lists, so run VDSS again
   before posting); DBHDS 165 youth service licences, about 5 minutes each
   (one click per plan), a full run takes 10+ hours: the first was started
   detached 2026-10-03 into `tmp/scraper-out/va-dbhds.json` with the code from
   before that day's fixes, so run `va_scraper.py --no-post --source dbhds` once
   more when it ends (everything comes from cache) to mark "No Violation"
   plans clean. **Owner:** approve
   each state's first post (run it from the launcher), then publish its page.
   **Hawaii built 2026-10-05, waiting for the owner's word to post:**
   `hi_scraper.py` and `hi_scope.json` (Tools repo, in the launcher and the
   guide), `js/inspections/states/hi.js`, `/hi-reports/` created on deploy
   as a draft, PDFs synced from `hi_pdfs/`. Plan:
   `docs/state-inspection-reports/plans/hawaii.md`. Source: the Health
   department's inspection reports page (one static page, 2023 on); 43
   special treatment facilities and therapeutic living programs, 110
   statements. Scope is a youth allowlist, settled by the owner
   2026-10-05: 9 in (Benchmark Behavioral Health System, Bobby Benson
   Center, Hiki Mai Ka La, Na Ohana Pulama, Nova Luna, Pearl Haven, Pacific
   Quest Awapuhi, Olena and Reed's Bay), 34 out (33 adult programs, and E
   Ho'oulu Hou Elua Program, whose population is not established). A full
   `--no-post` run: 9 facilities, 21 statements 2023-02-17 to 2026-03-17,
   12 with deficiencies (14 cited, all flagged) and 9 with none; 13 read by
   OCR (scans of the signed form, columns cropped and read one by one); 4
   plans of correction handwritten and not transcribed (Benchmark 2024 and
   2025, Pearl Haven 2023 and 2025); nothing held by the privacy check, no
   not_a_report, nothing unread. The Drive folder `hi_pdfs/` lists without
   a sign-in (shared by link, like `wy_pdfs/` and `ia_pdfs/`). 4 names
   reach 2 `facilities_v2` records (the three Pacific Quest sites ->
   Pacific Quest, Pearl Haven); Benchmark, Bobby Benson, Hiki Mai Ka La, Na
   Ohana Pulama and Nova Luna have no record and no near miss.
   **Owner:** approve the first post (run Hawaii from the launcher), then
   publish `/hi-reports/`; the state adds reports a few times a year, so a
   quarterly run is enough.
   **Colorado built 2026-10-05, never run live:** `co_scraper.py` and
   `co_scope.json` (Tools repo, in the launcher and the guide),
   `js/inspections/states/co.js` (tested on a fixture only), registrations
   and the draft `/co-reports/` page spec (swept into another session's
   commit f7451626). Plan: `docs/state-inspection-reports/plans/colorado.md`.
   Source: the Health department's Tableau dashboard (last three years,
   citation text and plans of correction). Earlier that day scripted probes
   of its session commands hung the state's server, so the scraper reads
   citation text only by clicking in real Chrome, checks the page header
   names the one citation before downloading, waits 5 s between actions,
   stops on any 30 s/5xx/double failure and reads `--max-citations` (40) a
   run. Scope: 9 in (Southern Peaks, Third Way Center, Devereux Cleo Wallace,
   Cedar Springs, Peak View, Highlands, Denver Springs, Centennial Peaks,
   Tennyson Center), 9 unsure (West Pines, Sierra Vista, Johnstown Heights,
   West Springs, three Eating Recovery Center licences, HCA Aurora,
   M.I.K.I.D.), 54 out. No `--out` run exists: the server timed out at 18:27
   and answered 503 at 18:57 ET, and the owner stopped the attempt.
   Self-reported occurrences (Cedar Springs: 223 since 2023) are not read.
   **Owner:** on a healthy evening run the five steps in the plan's Status
   section (lists only, one citation watched with `--headed --shots`, one
   facility, then 40 a run), settle the 9 unsure facilities, then approve
   the first post.
   **Oregon extension planned 2026-10-05** (`docs/state-inspection-reports/plans/oregon.md`):
   `/or-reports/` has site visits for two program types and no abuse findings. The same
   library has 61 substantiated abuse cases (quarterly legislative reports, 2021-Q4 on),
   restraint and seclusion reports per program (940 PDFs to 2023, a workbook a quarter
   since) and site visits for the other types. Build into `or_scraper.py`; the abuse
   cases also give the serious-findings scan its first Oregon input. **Owner:**
   records request for complaint investigations and licensing actions (not published).

7. **Inspection rankings** (started 2026-10-05, `inc/inspection-rollup.php`,
   KOP Tools > Inspection Rankings). Built: the hourly count of every report's
   state verdicts into `inspection_report_counts`, the rollup by company,
   facility and state, and the admin screen (pending findings shown there
   only). Owner rule: public numbers are approved findings plus the states'
   own verdicts. Against the 2026-09-30 mirror only 22 companies and 876
   records reach any inspection row, because `kop_operator_facilities` links
   643 facilities to 47 companies; the company view is only as complete as
   those links. Next, in order: (a) look over the screen once prod has
   counted (about a day of hourly runs, or "Count the next reports now");
   (b) review the North Carolina queue (782 pending), since no NC finding is
   approved yet; (c) a public page, ranked within each state, with the source
   table under it; (d) a block on each facility and company page; (e)
   per-bed rates where the state gives capacity.

The FL/NC lite list has one standing rule: the PHP readers in
`api/lib-inspection-text-signals.php` must match `nc.js` and `fl.js`
exactly. Change both, bump `kop_its_version()`, and run
`node scripts/test-inspection-text-signals.js --php=<php.exe>`.

### 3.4 Long-form articles

The code is done (fix plan 15, 17, 19). What is left is mostly writing, and
the text goes in the page body, never in post meta, ACF or a table (owner,
2026-09-23), so the site search can find it:

1. **15B**: decide which sections of the long articles to wrap in
   `[kop_section]` and above what length (candidates: Corporatization,
   Advocacy History, Straight Inc, WWASP).
2. **15C** summaries (`[kop_summary]`), **15E** "Why this matters"
   (`[kop_why]`), **15D** era tags (`[kop_era]`).
3. **18A** Advocacy History (the brief's "Survivors and Families Fight Back"
   is the same page), **18B** Corporatization (a corporate-layering SVG, a
   key-concepts block, a link into the map on its chain), **18C** Lawsuits
   (intro, status labels, filter micro-copy, grouping, recent updates, how to
   submit).
4. **The Brochure and the Record rewrite (2026-10-14).** Rewritten around current programs' own claims
   (Diamond Ranch, Vive, Maple Lake, Trails Carolina, Asheville Academy, Discovery Ranch, Sequel/Lakeside,
   New Lifehouse, Resolution Ranch, Turning Winds, Senate length of stay, UHS, JRC); WWASP-era material moved
   to Deceptive Marketing, Then and Now ("Then: the ads", seed v2, allow_published). The 15 Wayback pages it
   cites were imported (attachments 15910-15924, filed in each program's folder). Open: owner reads the
   draft (seed v7, overwrite_existing; the Oct 2 wp-admin edit survives as a revision) and publishes it.
   Follow-ups: submit https://www.fox13now.com/2019/08/14/employee-at-treatment-center-in-southern-utah-facing-felony-child-abuse-charge
   (Liahona, 2019 choke-hold restraint, 11 staples; the hourly cap refused it); lawsuits #54 Parker and #55
   Bence have empty source_urls (CourtListener: gov.uscourts.oknd.78072 complaint, docket 73624806).

### 3.5 Data research

- **Alternate names**: 4,334 of 4,663 facilities carry none;
  [alternate-names-gap-2026-09-18.csv](alternate-names-gap-2026-09-18.csv)
  lists them. Re-check with `scripts/test-state-feed-names.php`.
- **Years that disagree** between text and start/end years: New Dominion
  School of Virginia, Withlacoochee JRF, CEDU Middle School, Elevations RTC,
  Marion Youth Academy, Bartow Youth Training Center, Lexington Academy,
  Auldern Academy, Three Springs of Englishton Park, Three Springs Paint Rock
  Valley, Three Springs School of Madison, Oakley School, New Leaf Academy of
  Oregon.
- **Unplaced records**: New Directions Home for Boys (Three Springs), and
  "Aspen Education Group #2" (100001), an operator filed as a facility.

### 3.6 Small and unverified

- The admin stylesheets still use about fifty accent colours as text (16B).
- No real upload has gone through the live anonymous portal since it began
  sealing files; the offline test covers the path.
- **Tests failing on `main` before 2026-10-14's wiki work** (the same with it removed; data has
  moved under them in `tmp/prod.sqlite`): `scripts/test-review-inbox.php` (runnable again since
  2026-10-14; run it with `-d memory_limit=6G`) has 28 failing checks in data-audit (records merged
  since the proposals), drive-docs, facilities-from-news, federal-funding, fornits (filters, counts,
  the lead path), inspection-highlights, inspection-links ("Undefined array key 0" at
  inspection-links.php:127), websites, wiki-updates (a card's actions include another queue's
  `home_of_program`), ya-file, ya-moved and merge-follow; `scripts/test-wiki-gaps.php` fails "a page
  with no years or place matches only its exact name" (Mountain Home Academy). Fix the tests or the
  code per queue. `scripts/test-doc-types.php` fails "most are placed on a program page" (only 20 of
  47 enrollment documents sit in a program's library), with or without the 2026-10-14 staff ideology
  and editor's note types.
- **New document types `staff-ideology` and `editor-note` (2026-10-14)**: after deploy, the hourly run
  (rules version 3) looks again at documents the old rules left untyped; check KOP Tools > Document
  Types for the 7 the rules give staff ideology (mostly Teen Challenge's "Philosophy & Values" folder)
  and file KOP's own notes as editor's notes (folder "Editor's Notes" or a title with "editor's note"),
  and retype any AI guesses still waiting that belong under the new types.
- **Lawsuit documents by pasted text; "Mark as lawsuit news" (2026-10-14)**: after deploy, paste a few
  paragraphs of a court order into /submit-lawsuit/ ("Paste text"), KOP Tools > Lawsuits ("Paste text &
  auto-extract", on a saved case: only empty fields fill, the id stays) and a pending lawsuit card in
  Submissions Review ("Paste text from a court document"), and press "Mark as lawsuit news" on a
  pending lawsuit card that is really an article (it should wait in News as category Lawsuit; Undo).
  No live AI call has read pasted text yet; the offline inbox test covers the button.
- No live call has been made to the new Claude, Gemini or Hugging Face
  models in the AI helpers (`docs/news-processor/README.md`); the pages send
  `provider: 'groq'`, so they only run when a request names them.

### 3.7 Name eras

Owner's rule, 2026-09-29. A name belongs to the years and operator it had.
Copper Canyon Academy was the Aspen-era name, 1998 to 2014, and Sedona Sky
Academy is the Family Help & Wellness name from 2014. Anything dated shows
the name in use at its date: the map's timeline, an article, a lawsuit, an
inspection, a closure. Each name is its own record and its own map name,
joined by a rename. Bethel Boys' Academy (#100180) and Eagle Point
Christian Academy (#13927) are two records, not duplicates. A facility is
listed under its current name unless the owner marks another.

What stood in the way:
- **Most renamed pairs carry the site's whole life on both names.** Of the
  map's 94 rename lines, 27 give both names the same years and 11 overlap,
  such as Copper Canyon 1998-2014 with Sedona Sky from 1998. So the timeline
  showed both at once.
- **48 lines have no years on one side.**
- **A few lines are drawn backwards**, such as KHK a Pathway Family Center
  (2006) to Kids Helping Kids (1982).
- **Past and other names are undated strings on a record.** #13927 lists
  Bethel Boys Academy, Gulf Coast Academy and Bethel Baptist Children's
  Home, so articles using those names link to the current name.

Owner's rule, 2026-10-08: when a renamed program's two records are merged
into one, the open and closure dates stay different for each name, and the
facts stay with the name they came from.

Done 2026-10-08 (`php scripts/test-rename-merge.php [--list]`):
- **Merge as a rename.** KOP Tools > Merge Duplicates has a **Renamed
  programs** tab (52 pairs on the 2026-10-08 mirror: one record lists the
  other as a past name or says what it is called now), and "Merge as a
  rename" for any two records. A plain merge of a renamed pair is refused.
  - The later name's record is kept, with its own years from the rename
    year. The earlier name goes to pastNames.
  - The earlier name keeps its years, status, operator and notes on its
    `legacy.mergedFacilities` entry (`rename`). The data form's round trip
    keeps that entry.
  - Its company links move over as past operators.
  - Map Renames gets the year at once. Undo puts all of it back.
- **Facts stay with their name.** The merge records the keys of what each
  record's page listed (deaths, findings, lawsuits, incidents, news, staff).
  The page cuts the record by its own names (`kop_facility_eras_doc_chain()`)
  and puts each item under the name it was filed under, whatever its date.
  Only items neither record held are placed by date.
- **Dates per name everywhere.** The "Formerly" line prints each earlier
  name's years. The map build gives the earlier name its own years and keeps
  the rename line. A closure dated in an earlier name's years is refused
  for the record (item 3 below, for merged records).
- The data audit's "merge these" notes now say to merge as a rename.

Done 2026-09-29:
- **Map Renames** (KOP Data Tools > Map Renames, `inc/network-renames.php`,
  `php scripts/test-network-renames.php`) lists every rename line with both
  names' operators and years and what looks wrong. The owner gives the
  rename year, and swaps a backwards line. A saved rename ends the earlier
  name and starts the later one on the map at once. It is layered over Map
  Years through the `kop_network_map_year_overrides` filter, and a swap
  reverses the line (`renameFlips`).
- **The news scan** (`inc/facility-discovery.php`) holds an earlier or
  later name as "Earlier or later name" instead of linking it to the
  current name's record.

Next, in order:
1. **Owner:** work through Map Renames. Every rename was researched on
   2026-09-29 (`tmp/rename-research`, built by
   `node scripts/build-rename-candidates.js` into
   `js/data/network/rename-candidates.json`). 50 of the 94 have a sourced
   year (16 high confidence; the 14 whose year is in the quoted words save
   with one button). Kids Over Profits' own reporting counts as a primary
   source, and 19 were
   found drawn backwards and start swapped. The notes also flag lines that
   are not renames: a reused campus, a road name, or two schools mixed up
   (Woodward Academy and Georgia Military School). The rest fall back to
   the board's own years.
2. **Rename years into the records.** A saved rename sets the two facility
   records' start and end years (facilities_v2, through the
   to_legacy/normalize path) and records the link as
   `identification.renamedFrom` / `renamedTo` (`{facility_id, year}`). The
   map build then draws rename lines from those links instead of guessing
   from listed names.
3. **Dated items follow the years.** An article, lawsuit, inspection or
   closure report links to the name whose years cover its date. A report
   lists items linked outside those years while another name covers them.
   A closure applies only to the name in use then: a renamed place that
   kept operating is not closed.
4. **Matching.** A name that belongs to another name's record never
   resolves through a different record's otherNames, pastNames or match
   aliases. On "Earlier or later name", Facilities from News offers
   "Create this name's record", prefilled with the rename link.
5. **Pages.** Done 2026-10-05 (`inc/facility-eras.php`,
   `php scripts/test-facility-eras.php --list`): a renamed program's page
   is cut into one section per name, earliest first ("As Copper Canyon
   Academy", "As Sedona Sky Academy"), each holding the deaths, serious
   findings, lawsuits, incidents, news and staff of its years. Where the
   other name has its own record, both pages print both names' sections and
   link each other. It reads the map's rename lines and Map Renames' years,
   so it covers only renames with a usable year (48 pages on the
   2026-09-30 mirror; 48 more records on a rename line stay whole until
   their year is saved at Map Renames, or because a name split or joined).
   Still open: records whose past names are not on the map (about 150)
   have no rename year anywhere; licensing reports and documents are not
   split by name; staff carry no dates, so one record holding two names
   keeps its staff in the ordinary section. `identification.listAs` holds
   the owner's headline-name override.
6. **Owner pass on curated aliases that name another era**, restored on
   2026-09-29:
   - Bethel Boys Academy (#13927)
   - Laurel Ridge Treatment Center (#10602)
   - Agape Boarding School (#11361)
   - Refuge of Grace (#11462)
   - Change Academy at Lake of the Ozarks (#11355)
   - Cleo Wallace Academy (#12621)

   New curated aliases go in `api/match-aliases-seed.json` and are copied
   into the v2 documents by `api/restore-match-aliases.php`
   (`api/apply-match-aliases.php`, which wrote the frozen legacy tables,
   was deleted 2026-09-30).

---

### 3.8 Follow-through (session review, 2026-09-30)

A read of every session since 2026-09-02 for requests that were started and
not finished, checked against git, the code and the mirror. Everything else
asked for in that time shipped or is already listed above.

Each item names the model to run it with:

- **Opus 5.5** for open-ended work: a bug with no known cause, anything
  that deletes or rewrites production data, matching rules that need
  judgement, and writing in the owner's voice.
- **Sonnet 5** for a scoped change with a known cause and a test to run,
  and for web research that ends in data entry.
- **Haiku 4.5** for small mechanical edits that follow an existing pattern.

In order:

3. **Moved testimony still shows in the normal sections** (Opus 5.5).
   Done 2026-09-30 in
   [da753fae](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/da753fae737d39ab3a75ec0bf4070a53bc86804a).
   Two causes, read from the stored record (`providers_master` "MONTANA
   PROVIDERS", submission #50). (a) The six paragraphs still under "TTI
   practices used" on /mental-health-providers/ were never moved in the
   record: the moves after approval were saved while the project was named
   `MONTANA`, and the form saved that as Montana's location profile (fixed
   by the rename in 3c82628c). (b) The submission held the same account
   twice, a field note plus a copy under a key from the submitter's own
   form; once one was moved, the move list hid the other for good. It is
   now offered with "Remove copy", which deletes it without a second
   entry. `node scripts/test-testimony-move.js` (19 checks, 5 fail on the
   old code). Owner step: Admin runs item 11. Still open: the admin form's
   save (`saveProjectToCloud` in `js/data-form-modules/api.js`, and the
   state-name override in `api/save-master.php`) still files any project
   named after a state as a location profile even on the providers tab;
   a provider project typed as a bare state name would lose its saves the
   same way. Approval already names them "<STATE> PROVIDERS".
6. **Research tag proposals from document text** (Opus 5.5). Asked
   2026-09-29: read the contents of documents with no tagged facility and
   propose facilities and operators. Today's
   `api/propose-research-facility-tags.php` proposes facilities only and
   is reviewed as a JSON file. Build a one-page review screen (the owner's
   rule) and guard acronyms and short names against over-matching.
   Replaces owner item 5.
7. **Rest of the old /lawsuits/ page** (Sonnet 5). The 2026-09-03 import
   brought in ten cases. Still missing: the Dimple Dell canyon suit
   (DocumentCloud) and the cases behind two Salt Lake Tribune lawsuit
   stories on revision 926 of the old page. Add them through the lawsuit
   form path so facility links and folders follow.
8. **Renaissance Center, Fresno** (Sonnet 5). Record 100134 has no
   sources, no FileBird folder and status Unknown; the other three
   facilities submitted on 2026-09-21 got theirs. Find public sources,
   file them, and set the status. Empty duplicate folders 7765 and 7766
   under parent 1692 can go.
9. **Advocacy History and Survivors hub writing** (Opus 5.5, owner
   review). Advocacy History's "Key Milestones" list and "Submit your
   story" call to action, beside 18A above. 18D, the Survivors hub (a
   thesis line, a description under each link, icons), fell off this list
   when the handoffs were merged; it is back here. Draft in the page body
   (owner rule: never post meta, ACF or a table) and leave it for the
   owner to read.
10. **Data model phase 5** (Opus 5.5, owner go-ahead first). Not started
    (`docs/DATA-MODEL-MIGRATION.md`, "Phase 5: Remove the old model").
    The legacy tables are frozen; the dual write
    (`inc/facility-store.php`), `kop_unwrap_project_payload()`,
    `api/promote-facilities-to-rows.php` and the `_bak_20260916` tables
    remain. Ask the owner whether the bake is over before deleting
    anything, back up first, and do one step per commit.

Owner:

- **Provo Canyon School profile** (draft,
  [post 10796](https://kidsoverprofits.org/wp-admin/post.php?post=10796&action=edit)):
  the text says suspended, the record (10371) says Closed. Say which,
  then publish.
- **Document finder retries.** `.tti_doc_finder_state.json` holds 1,783
  uploads that failed before the OneDrive fix, plus 383 waiting for
  review: `py tti_doc_finder.py upload --retry-failed`, or tick "retry the
  ones that failed" in its window.
- **Industry PR refiling.** 148 rejected news items to sort through
  (4 refiled so far).
- **News discovery crontab (2026-10-13, section 3.16).** Both lines exist
  in cPanel > Cron Jobs (01:30 and 13:30 Central). The sweep now walks every
  name by a cursor, half per run, so: (a) the 13:30 line loses
  `--no-facilities`; (b) the 01:30 line gets `--recency 30d` after
  `discover-articles.php` for one night (the last month's backlog), then
  loses it again; (c) optional, both lines drop `AI_PROVIDER=groq` so the AI
  step alternates with Gemini like every other job (the confirm step adds
  calls, and Groq's free tier is 8,000 tokens a minute); (d) optional, a
  third line (07:30, the 13:30 line's shape with `flock`) makes the full
  pass daily instead of every day and a half.
- **Scraper launcher window** (Tools 4a0df69): open it and confirm the
  size and Output pane look right.
- **Pushing local database changes to prod** (asked 2026-09-17).
  `scripts/local-db.pyw` only pulls. Recommendation: do not build a
  general push. Schema changes ship through `api/update-schema.php` and
  data changes through a seed or an admin tool, both reviewed, so the
  shared live database never takes an unreviewed bulk write.

### 3.9 Facility information from the Google Docs (2026-09-30)

The owner keeps program links and notes in Google Docs and Sheets on the
ttiresearch.dani Drive. `tmp/gdocs/inventory.json` lists all 426 Docs and
43 Sheets on G: and I:, read from DriveFS's `metadata_sqlite_db` (no
connector reads Drive in a Claude Code session). `scripts/gdocs-select.py`
picks 167 Docs and 18 Sheets by folder and title: Active/Closed Programs,
Altior, Focus Locations (Massachusetts, Israel, Ohio), Investigations,
Lawsuits, Legislation, Sequel, the staff and owner lists, the address and
website sheets, and the shared "TTI Database - Master" sheet (import it,
no credit, owner 2026-09-30). Left out by the owner: podcast transcripts
and survivor interviews. Also left out: the NATSAP directories (already
backfilled), board, IRS, FOIA templates and personal docs.

1. **Export** (owner, Admin runs item 12). HTML, not text, because a text
   export drops the address behind every linked word.
2. **Links pass** (Opus 5.5). `scripts/gdocs-extract.py` reads the HTML
   (unwrapping `google.com/url?q=`) and, for every link, keeps the linked
   text and its sentence. It assigns the facility from the folder path and
   nearest heading against `facilities_v2` names and past/other names in
   `tmp/prod.sqlite`, sorts the link (news, court, legislation, licensing,
   the program's own site, other) and drops anything
   `kop_ext_find_duplicates()` would. Program sites follow the `/go/` rule.
   Built 2026-10-01; writes `tmp/gdocs/links.json` and
   `tmp/gdocs/links-report.md`. On the first 182 files: 2,878 distinct
   links, 943 already on file (848 of them CA CCL reports, matched by
   facNum + inx against `inspection_reports.report_id`, which holds no
   URL), 1,935 new: 895 tied to a facility, 314 to a company only, 726 to
   neither (state docs, the consultant lists, Death List rows with no
   record). New by kind: 545 news, 270 social, 121 reference, 112 people,
   103 archive copies, 95 government, 88 licensing, 66 program sites,
   49 court, 16 legislation, 470 other. Matching: a record name in the
   words around the link (never inside the URL; names of only generic
   words skipped), else the heading, else an exact or close folder/title
   name; a sheet or a doc naming many programs does not hand its folder's
   program to every link; company folders ("Sequel", "UHS", "FHW") give
   the operator.
3. **Facts pass** (Opus 5.5). Staff and owners, years, past names,
   addresses, arrests and closures, read like Woodbury Facts: every fact
   carries its quote, the build drops a quote not found in the doc and
   anything the record already holds.
4. **Review screen**. Built 2026-10-01: KOP Tools > Drive Docs
   (`inc/drive-docs.php`, table `{prefix}kop_gdoc_links`, synced from
   `~/kop-import/gdocs/links.json` when the page opens). Owner decisions
   (2026-10-01): survivor/social posts and people links are not news; they
   and the licensing reports the scrapers lack go on the facility as
   resource links. So: news to the news queue, court records and bills to
   their queues (`kop_ext_insert_*`, no admin emails via the
   `kop_notify_admins_enabled` filter), the program's own site to
   `profileLinks`, everything else to the new `resourceLinks` field
   (`{url, label, kind, source}`, PHP and JS normalizers, listed by kind on
   the /facility/ page; live links except kind `other`, shown as a
   snapshot). Each row's "Goes to" can be changed; Undo takes a link off the
   record, or a queue row nobody has reviewed yet. Company-only and
   no-facility links need a record picked (news can go without one).
   `php scripts/test-drive-docs.php`. Not yet: operator pages have no
   resource links, and `resourceLinks` is not in the open data downloads.
5. **Sheets** (Sonnet 5). A column map per sheet; only cells that differ
   from the record reach the review screen.

### 3.10 More survivor site archives (2026-10-01)

Surviving Straight Inc. and WWASP Survivors are live on the facility and
operator pages since 13e8a328 (`scripts/survivor-archives.py`,
`inc/survivor-archives.php`): link only, documents KOP holds dropped by
md5 or distinctive name, records chosen by explicit RULES. Candidates
checked 2026-10-01; dead or too small: jeriwho.net (gone), cedulegacy.org
(domain lost), pfctruth.com and isaccorp.org (gone, no PDFs on Wayback),
elanabuse.nfshost.com and survivingcedu.wordpress.com (1-5 documents,
mostly HEAL copies), the Hephzibah House blog (a few scanned images).
Owner decisions in Waiting on the owner item 18.

1. **thestraights.net** (built 2026-10-01). `survivor-archives.py` has a
   generic same-host crawler (`crawl_site`: BFS from the home page plus
   `sitemap.xml`, 1 request a second, images/styles/scripts skipped, page
   path, title, nearest bold heading as section, folder from the document
   path) and the site `straights`. It has no https (the TLS handshake
   fails), so its links are http; `kop_survivor_archives_scheme()` in
   `inc/survivor-archives.php` allows http for that site only (tested).
   Real size: 388 live pages (89 dead links), 53 documents, not the 150-250
   guessed: 46 listed (Straight Inc. o21 42, KIDS Centers o14 6, KIDS of
   Bergen County f12760 2, Straight Tampa Bay f9632 1, SAFE/Substance Abuse
   - Family Education f11222 1), 4 dead, 2 private (a survivor's story and a
   list of named suicides), 1 Scientology press kit left unmapped. Also new:
   a document an earlier site (SITES order) already lists for the same
   record by md5 is not listed again.
2. **nhym-alumni.org** (built 2026-10-01). Site `nhym`: 45 documents, 31
   listed (New Horizons Youth Ministries f13339 28; Caribbean Mountain
   Academy f14115, past name Escuela Caribe, 3), 10 yearbooks left out, 3
   the site answers 403 to (never fetchable), 1 KOP already holds. KOP has
   no NHYM operator and no other NHYM facility record under that name.
   Privacy (owner decision 18), all four sites: the build drops yearbooks
   and documents that look like private records by file name, link text and
   section (client/student/medical records, progress reports on a youth,
   filled-in applications, victim statements and personal letters, survivor
   stories, named victims) and reads `js/data/survivor-archives/privacy.json`
   ({exclude, titles, groups}, keys are sha1 of the url or the group label,
   never a name; the repo is public). `python scripts/survivor-archives.py
   privacy-review` writes `tmp/survivor-archives/privacy-review.md` (every
   listed document with its hashes) for a reviewer to fill privacy.json,
   (filled for SCIAD NET in 4A; the other sites' name-by-name pass is still open). `selftest` checks
   the patterns. Automatic exclusions so far: ssi 1, wwasp 5 (the Ben
   Trane "Part 6 - Victim statements" group, which also holds two non-victim
   exhibits), straights 2, nhym 10.
3. **SCIAD NET survey** (Opus 5.5, a separate general-purpose agent, read
   only, runs alongside 1-2). The WWASP Survivor Truth archive is a public
   Zotero group (`api.zotero.org/groups/4552235`, no key needed): 104,282
   items in 11,406 collections, no attachments; a 300-item sample was
   archive.ph / Wayback page captures, forum posts, newspaper clippings
   and about one in seven Google Drive files, none of them in Unsilenced's
   archive. Page every item and the collection tree into `tmp/sciad/`
   (about 1,050 requests, resumable, honouring `Backoff`). Report in
   `tmp/sciad/survey.md`: items by type and host; how many collections are
   a program and match a `facilities_v2` name or past/other name exactly;
   the Drive files and how many KOP holds under the same name; the
   newspaper items already in `news_submissions` (URL, then title + date);
   overlap with Unsilenced; a recommendation for step 4. Nothing reaches
   the site.
4. **SCIAD NET build** (Opus 5.5, after the owner reads the survey). Likely
   two parts: Drive documents as a `sciad` site in the survivor archives
   (the collection gives the record, name dedupe only, since Drive gives
   no md5 without a key); archived articles and clippings into a review
   screen like KOP Tools > Drive Docs, through `kop_ext_find_duplicates()`,
   never published on their own. Credit per decision 18.
   - **Part A built 2026-10-01** (not committed yet). Site `sciad` in
     `scripts/survivor-archives.py` (`build_sciad()`): no fetch, the build
     reads the survey (`tmp/sciad/items.jsonl`, `programs.json`, the raw
     pages for tags and the Legal case subcollections) and
     `tmp/unsilenced/files.jsonl`. Of 76,769 distinct Drive files: 64,804
     dropped because Unsilenced has a file of the same distinctive name, 4,301
     a short name Unsilenced has in the same program's folder (as many times
     as it has it there), 8 a name in the media library, 0 a state report KOP
     holds for the same date (the survey's 625 were all Unsilenced names);
     2,554 private (1,634 photos and event media, 466 Texas CCL portal
     screenshots, 150 client/student/medical papers, 47 filled-in
     applications, 42 police, 40 juvenile/custody, 29 personal letters, 39
     survivor stories and blogs, 19 payroll and payment papers, 15 videos,
     18 by reviewed hash, the rest by title words); 1,618 on no KOP record.
     **3,484 listed on 232 facility and 12 operator pages** (WWASP o29 349,
     UHS o25 306, Devereux o8 191, Teen Challenge o22 178, Trinity Teen
     Solutions 148, Triangle Cross 128), 572 KB in
     `js/data/survivor-archives/sciad/`. Records: the program collection's
     exact match, or `SCIAD_RULES` (86 collections: WWASP HQ, Sequel Services
     HQ, Sequel and Devereux campuses KOP has no record of on the parent
     company, renamed programs, and for KOP's duplicate records the record
     that carries the name now); tags only for an unmatched collection (1
     item). Groups are SCIAD's categories (DHS Records, Public Records,
     Court records, Program Documents, News clippings ...), court records
     split by case as "Court records: case 2 (2010-2021)"; a campus's
     documents on a parent company say which campus. Court titles are made
     from the kind of filing, court, year and case or docket number only;
     a court record named for a party is never shown under that name.
     `inc/survivor-archives.php` accepts drive.google.com and
     docs.google.com for this site only (`kop_survivor_archives_hosts()`),
     and the block's heading is "From SCIAD NET, the WWASP Survivor Truth
     archive" linked to its program-archive page. Every listed title was
     read (`tmp/survivor-archives/sciad-review.tsv`, gitignored);
     `privacy.json` gained 18 exclusions, 5 neutral titles and an `include`
     list (the two WWASP Survivors exhibits the victim-statement page title
     had dropped: an admissions-criteria sheet and a redacted DHS affidavit
     attachment, both read first; wwasp now lists 144).
   - **Part B built 2026-10-01.**
     `scripts/sciad-links.py` writes `tmp/sciad/sciad-links.json` (and
     `sciad-links-report.md`) in the Drive Docs row format from the survey's
     `items.jsonl`/`programs.json`, plus group 772277's links not already in
     SCIAD NET (385 rows). 12,651 rows: 9,438 news, 2,244 reference (program
     info, wikis, research, media), 426 archived program pages, 265
     government, 167 court, 18 legislation, 89 other, 4 more. Tied by exact
     program collection only: 6,109 to a facility (680 facilities), 892 to a
     company only (14 operators), 358 to an ambiguous name (the KOP records
     listed, none tied), 5,292 to none. News goes in under its original
     address where the Wayback link gives it (archived copy kept for the
     reviewer and the queue note); archive.today short ids stay as the
     archive link with the title. Court records get neutral titles
     ("Complaint, 13 pages - SAFE", "Court opinion - Elevations RTC (case
     no. 1:2014cv00015)"); a court record whose address carries a party's
     name (120) and family or juvenile files are left out. Dropped: 77,779
     Drive documents (part A), the survey's private classes (3,180 survivor,
     185 photo, 33 police ...), 1,671 "Random" folder items not from a news
     outlet, court or government, 859 blog, forum and social posts, 708
     titles that are only a person's name, 680 by private title words
     (letters, statements, testimony, obituaries), 259 testimony videos, 252
     survivor-site pages, 1,115 already offered by the Google Docs, HEAL or
     wiki files, 2,154 survivor-archive and HEAL addresses, about 170 held in
     KOP's records, posts or news headlines; 943 news rows merged by headline
     and year, 1,750 by address. No abstract, tag, creator or Zotero user is
     written.
   - Drive Docs (`inc/drive-docs.php`, table version 2: `source`, `rhash`)
     reads `sciad-links.json` (and the wiki's file) through
     `kop_gdl_sources()`; a reload leaves unchanged rows alone and inserts
     in batches. The screen filters by source, shows 40 rows per card with
     the rest a click away (200 a page), pages cards with a windowed pager,
     and a facility card's **Add all** adds its sure matches under the
     filters, 40 per request. SCIAD NET rows put "SCIAD NET, the WWASP
     Survivor Truth archive" on the record (resourceLinks `source`) and in
     the queue note with its link and the archived copy; the facility page
     shows the credit, linked, under each group it fills
     (`kop_facility_pages_resource_link_credit()`). Google Docs, HEAL and
     wiki rows behave as before. `php scripts/test-drive-docs.php` and
     `python scripts/sciad-links.py --selftest`.
   - Main session: copy `tmp/sciad/sciad-links.json` to
     `~/kop-import/gdocs/` after the deploy; the first page load converts
     the table and loads about 12,700 rows.
5. **Review and ship** (Sonnet 5.5 running `/code-review medium` after
   steps 1-2 and again after 4). `php scripts/test-survivor-archives.php`,
   `test-facility-pages.php`, `test-operator-pages.php`,
   `python -u scripts/check-contrast.py --local` on one facility page with
   the new block, commit to main, confirm the deploy by fetching
   `js/data/survivor-archives/<site>/index.json` from the live site.

### 3.11 Unsilenced backup and titles (2026-10-02)

The facility and operator pages list 58,924 Unsilenced documents KOP has
no copy of (117.8 GB), under Unsilenced's file names, a third of which say
nothing ("0235.pdf", "DocumentInquiry-45.pdf", "Compliance History 38.pdf").
Owner decisions (2026-10-02):

- Back up Unsilenced's copies of every listed file to the owner's Google
  Drive (`I:/My Drive/Unsilenced archive backup`, I: being upgraded to
  2 TB), in Unsilenced's folder layout, each checked against their md5.
- Retitle the links from the content. No privacy pass on Unsilenced: their
  names and groups stay as they are. Titles made here never add a personal
  name: court papers by document type, court, case number and filing date;
  state reports by report type, program and date (Michigan names only
  institutions, agencies and county homes, Texas never a foster home);
  newspaper clippings by headline, paper, date and page (headlines kept).
  Anything else keeps its name.

Built (`scripts/unsilenced-backup.py`, not committed yet): `run` downloads
from `drive.usercontent.google.com` without a key (browser User-Agent, no
email anywhere, 1.75 s between files, the large-file virus-scan page
confirmed, 5xx retried, backoff on 429 or rate-limit pages), checks the md5,
keeps the file and writes title evidence (PDF Title, two pages of text, OCR
of a scan's first page or a small image) to
`tmp/unsilenced-titles/evidence.jsonl`; resumable from `state.jsonl`
(a done file is checked on disk by size); pauses while C: has under 4 GB
(Drive for desktop buffers uploads there) or the destination has under the
file + 2 GB. Opaque names go first, files over 200 MB last. `titles` writes
`tmp/unsilenced-titles/titles.json`, which `build-unsilenced-links.py` puts
in the shards as `title`; the page shows the title with Unsilenced's name
muted after it (and as the link's tooltip); `index.json` `titled` moves the
page cache key. Rules: newspapers.com clippings, federal court headers
(district and appeals), Connecticut Superior Court, state courts, Texas
compliance histories (with the span of entries), Arizona, Georgia, North
Carolina and CMS statements of deficiencies, Arkansas DHS reports and DPSQA
letters, Michigan, Minnesota and Pennsylvania licensing letters, Oregon
restraint reports, Ohio incident notices, Oklahoma monitoring, Form 990s.

Test batch (300 files into `tmp/unsilenced-titles/test-backup`): 3.3 s a
file with OCR (2.1 s the download), 300 of 300 md5 verified, 3 Google 503s
that succeeded on retry, 157 given a title (154 of the 271 opaque names).
Rebuilt the shards with those titles: 112 shards gained titles only; 7
shards came or went because `tmp/prod.sqlite` is newer than the last build.

Full run: about 2.5 days and 118 GB on I: (36.9 GB of it 62 videos at the
end). Owner, once I: has room:

1. Start it detached from Windows PowerShell in the repo (survives the
   session): `Start-Process python -ArgumentList 'scripts/unsilenced-backup.py','run' -WindowStyle Hidden`.
   Follow `tmp/unsilenced-titles/run.log`, or
   `python scripts/unsilenced-backup.py status` (counts, GB, paused or
   not). Starting it again resumes; a second copy refuses to start.
2. Keep C: above 4 GB free; the run waits, it does not fail.
3. Afterwards: `python scripts/unsilenced-backup.py titles`,
   `python scripts/build-unsilenced-links.py`,
   `php scripts/test-unsilenced-archive.php`, read
   `tmp/unsilenced-titles/titles-report.md` (samples per shape), commit
   `js/data/unsilenced/`. Titles can be rebuilt part way through.
4. `run --no-keep` reads PDFs and images for titles without keeping copies,
   if the backup has to wait for storage.

---

### 3.12 Wiki entries brought up to date from KOP (2026-10-05)

The 425 r/troubledteens program entries in `wiki_submissions` were bulk
imported from the Reddit wiki in Dec 2025 - Jan 2026 and have not been
updated since. In that time KOP has gained confirmed closures, rename
years, operator changes, news, lawsuits, deaths and approved serious
findings for many of the same programs. Nothing compares an entry with its
facility record: `scripts/reddit-wiki-live.py` compares KOP's copy only
with Reddit's. Owner decision (2026-10-05): existing entries first; stub
and missing pages (827 stubs, 724 index slugs with no page) come later, in
the same pipeline in "new entry" mode.

Facts that shape the work:

- The text of most entries is only in `original_markdown`; `json_data`
  holds the lists (staff, media, lawsuits) but almost none of the prose. An
  update edits the markdown in place. Regenerating it from the form
  (`generateWikiMarkdown`) would lose text.
- Only 46 entries are tied to a `facilities_v2` record
  (`facility_unique_name`, 58 filled, 12 of them operators); 246 match a
  record by exact name, 10 of those ambiguously.
- `kop_facility_page_data()` (`inc/facility-pages.php`) already gathers
  everything public about a record and runs offline against
  `tmp/prod.sqlite` (`scripts/test-facility-pages.php`).
- Publishing stays a manual paste: Reddit refuses scripts.

Model delegation. Every step that can be a script is a script; models only
write and check prose. Claude subagents do the one-off batch, run from a
workflow; anything that runs on the server later goes through
`kop_ai_generate_alternating()` (Groq/Gemini), never a fixed provider.

| Step | Who | Why |
|---|---|---|
| 0 Linking, 1 Gaps | Scripts, no model | Deterministic matching; free and repeatable |
| 0 Ambiguous links | Owner, in the review inbox | A wrong link puts another program's facts in an entry |
| 2a Mechanical edits | Haiku 4.5 | Adding links to In the Media / Related Media, header years and status, past tense for a closed program: fixed formats |
| 2b New prose | Sonnet 5.5 | Closure and rebrand paragraphs, lawsuit and finding summaries in the entry's own voice |
| 2c Check | Opus 5.5 | Every added claim against its source; anything about a death, an abuse allegation or a named person is written or rewritten here, not by 2b |
| 3 Screen, 5 Upkeep code | Sonnet 5.5, reviewed by `/code-review medium` | Ordinary inbox source and cron, existing patterns |
| 5 Daily check | Script; any prose on the server via Groq/Gemini | Project rule for on-site AI |

1. **Link every entry to its record** (script). Match by the Reddit page
   the entry came from (`sourceSlug` or the bulk note's file name, the
   pairing `reddit-wiki-live.py` uses), then name, past and other names
   (`kop_v2_alias_index()`) within the state from `city_state`. A single
   match is saved as `suggested`; ambiguous and unmatched ones become a
   review inbox source with candidates (one click, or
   `kop_facility_finder_field()`), saving through
   `api/link-wiki-facility.php`. Operator entries link to `kop_operators`.
   Built 2026-10-05: `inc/wiki-updates.php` + review inbox source
   `wiki-links` (tabs One clear match / Pick the record / No record found /
   Linked here / Set aside; tool "Link every clear match"; Undo restores the
   entry's old link from option `kop_wiki_link_log`). Matching: name, past,
   other and current names inside the entry's states, then every
   distinctive word of the name in the same state (town words never count);
   pages with no years or place match only an exact name or a company. On
   the 2026-10-05 mirror: 415 current entries (334 programs, 21 topic
   pages, 9 companies, 51 lists), 56 already linked, 225 clear, 25 to pick,
   41 with no record. **Owner:** open Wiki links, press "Link every clear
   match", then pick the 25.
2. **Gaps per entry** (script, `scripts/wiki-gaps.php`, offline against
   `tmp/prod.sqlite`). For each linked entry, `kop_facility_page_data()`
   against the entry's markdown, into `tmp/wiki-updates/gaps/<id>.json`:
   status and end year (confirmed closures only) against the header;
   rename years, past names and operator changes the text lacks; news,
   lawsuits and documents whose URL is not in the entry; memorial names
   not in it; approved serious findings at or above `kop_ih_min_score`.
   Public, approved data only: no pending Woodbury, Fornits or inspection
   items, no unpublished testimony, never `journalists`. A gap that
   contradicts the entry (open vs closed, different years) is marked
   `conflict`, never written over. `tmp/wiki-updates/report.md` counts
   gaps by kind and lists the entries with the most.
   Built 2026-10-05: `scripts/wiki-gaps.php`, `scripts/test-wiki-gaps.php`.
   An entry about an earlier name of its record (Integrity House RTC, now
   Havenwood Academy) gets "Later operated as", nothing about today's
   status or operator, and only items dated in its own years. Staff: leaders
   as `staff`, others only with a career elsewhere (`staff_other`).
   A Sonnet audit of 25 entries (351 gaps) found 59% right, 15% already in
   the entry in other words, 5% another program's, 21% doubtful; 23 of 25
   record matches right. Its rules are in: news must name the program or
   one of its names (else `news_mention`, kept for the owner but not
   drafted), same titles once, `newer_than_entry` marks news after the
   entry's latest year; lawsuits must name the program, KOP's own "not a
   case about" and money cases out; deaths inside the entry's years;
   findings risk High / Medium High or weight 70+; label names (LLC, dba,
   branch, "Academy at X") skipped; staff spelled one letter off or in
   "X and Y Surname" count as named, duplicates once; operators compared
   without Group/Inc/TSI; a one-year closing difference is no conflict.
   After: 268 compared, 200 with gaps, 5 conflicts; gaps 519 staff,
   377 staff_other, 147 news (+107 news_mention), 39 closure, 30 finding,
   27 lawsuit, 26 death, 25 operator, 17 name. Left for step 3's check: a
   record whose other names include another program at the same address
   (Olympus Academy lists Diamond Ranch Academy, so Diamond Ranch suits
   reach the Olympus entry). **Owner:** the closures behind Provo Canyon
   School (Closed 2026) and the closing years of Oakley School (record
   2007, wiki 2017), Old West Academy (2007 vs 2019) and Seneca Ranch
   (2009 vs 2017) are worth a look before drafting.
3. **Drafts** (subagents, per the table). Haiku makes the mechanical
   edits, Sonnet writes the new paragraphs, Opus checks the whole new
   entry against the gap file and the sources and returns a verdict per
   added line. Rules for every drafter: change nothing outside the added
   lines (the script diffs and refuses a draft that deletes or rewrites
   existing text); every added sentence cites its link (the KOP facility
   page, the article, the court record; KOP's own reporting is a primary
   source); plain stated facts, no framing the reader is told to conclude
  ; no emojis; the modmail contact line
   unchanged; a person is named only as the source names them, never from
   a pending item. Output: `tmp/wiki-updates/drafts/<id>.md` + `<id>.json`
   (each added line, its source, Opus's verdict).
4. **Pilot of 10** (run 2026-10-05: `scripts/wiki-pilot-prep.py` picks and
   writes `tmp/wiki-updates/drafts/<id>/{entry.md,gaps.json}`; a workflow ran
   Haiku, Sonnet and Opus per batch of 5 into ops-haiku.json, ops-sonnet.json
   and ops.json (every op with a verdict); `scripts/wiki-drafts.py assemble`
   applies only additions and refuses a draft that loses or changes a line or
   adds an uncited one. All 10 assembled; 66 ops; Opus opened sources and
   fixed 10 or so, dropped wrong-program items. Owner reads the pilot page;
   record fixes it raised: Clark Harman's memorial date (Feb 1 vs Feb 3,
   2024), Turn-About Ranch operator Acadia and status Transferred, Kelly
   Corey / Kelly Cole and Jeff Johnson (one name, several people), a Fornits
   link on kidsoverprofits.org, Oakley's 2007 closing year. Owner
   2026-10-05: a closed program's entry must read in the past tense, so
   step 3 has a tense pass (Sonnet) for entries whose record is Closed, that
   are about an earlier name, or whose header has a closing year:
   ops-tense.json pairs each line with its past-tense form, and the
   assembler takes a line only when the sole changes are verbs put in the
   past ("is" -> "was", "must" -> "had to", irregular verbs listed) and
   dropped "current"/"still"/"now"; quotes, testimony, titles, links and
   facts still true (pending suits, people's jobs elsewhere) stay as
   written. Pilot: 51 lines in 4 entries. Earlier plan text:) Picked for the most gaps across kinds (closure,
   rename, lawsuit, death, finding). Owner reads them on the screen in
   step 5; prompts are adjusted; then the rest (about 290 once linked) in
   batches of 25 from a background workflow, resumable by entry id.
5. **Review screen**: review inbox source `wiki-updates`. Before/after per
   entry with the added lines marked and their sources, each line editable
   or droppable, conflicts shown apart for a decision. Approve writes the
   new markdown to `wiki_submissions` (`original_markdown` for imported
   rows) and logs the old text for an exact Undo. Approved entries go to a
   **Ready for Reddit** list: Copy button and a link to the page's Reddit
   edit screen. After pasting, `reddit-wiki-live.py fetch --slugs ...` and
   `compare` turn the badge back to "same".
   Built 2026-10-06: `python scripts/wiki-drafts.py export [ids]` writes the
   passing drafts' ops (not their text) to
   `js/data/reddit-wiki/update-drafts.json`; `inc/wiki-update-drafts.php`
   applies them to the entry as it is now (PHP port of the script's apply,
   same output on every pilot draft), so an entry edited since drafting is
   still reviewable (the card says so). Source `wiki-updates`: tabs To
   review / Ready for Reddit / On Reddit / Set aside; each added line with
   Opus's note, the past tense side by side with a switch, conflicts;
   "Edit details" changes or empties any line; "Show the whole entry"
   (admin-post `kop_wiki_draft_view`) marks the changes and has Copy and
   Reddit's edit link. Approve writes the column the entry is read from,
   keeping the old text in option `kop_wiki_draft_old_<id>` for an exact
   Undo (refused if the entry was edited after). Tested by
   `scripts/test-review-inbox.php --source=wiki-updates`.
   Pilot accepted by the owner 2026-10-06, after these rules (each now in
   code): KOP's own record is a source (written without a KOP link,
   `kop_record`), names link to their r/troubledteens wiki pages, never KOP
   profiles; the entry's KOP facility page is the last Related Media item;
   the text is put back in Reddit's markdown (KOP's copies were converted
   from rendered pages: `reddit_format()`); heal-online.org links go to
   HEAL's own pre-2023 Wayback capture (the domain is now spam); a closed
   program's own description is always past tense; corrections of existing
   lines go in `ops-fix.json`.
   **Full run, cheap and in priority order (2026-10-06):**
   `scripts/wiki-update-order.py` ranks entries by what KOP adds (closure
   10, death 8, lawsuit 6, finding 5, newer news 4, name/operator 3, staff
   0.5; 194 entries); `scripts/wiki-script-drafts.py` writes every gap with
   a fixed form (staff, news links, names, operators, closures, findings);
   models only write lawsuits, deaths and news events (Sonnet, from
   `model-gaps.json` and `excerpt.md`, not the whole entry), Opus checks
   only those lines, Haiku does the past tense. Batches of 25 from
   `order.json`.
6. **Keep them current**. A daily cron reruns step 2's gap check for
   linked entries; an entry whose record gained a confirmed closure,
   approved news, a lawsuit, a death or a finding since its last update
   gets a "KOP has new information" badge in the wiki editor and a new
   inbox item.
   **Inbox item done 2026-10-14** (owner: every change to a record makes an
   update for its wiki entry): `inc/wiki-auto-updates.php` listens to
   `kop_record_changed()`, re-runs the gaps hourly for the linked entries
   (and sweeps every linked entry, 30 a run, for writes that skip the
   event), and writes each new gap as the script drafts do, with no model;
   Review inbox > Wiki updates from record changes. The first look at an
   entry is its baseline: what it lacked before this shipped stays with the
   exported drafts and the workflow. Open: the editor badge; lawsuit and
   death lines in the models' wording (the site writes the record's own
   summary); `rules` lines are the handbook's words as they stand.
   Still open from it:
   - **Check on the server** (after the first hourly runs since the 2026-10-14 deploy): option `kop_wiki_auto_last` shows the
     baselines taken (about 296 entries, `left` 0, no `errors`); after the next record change to a
     linked program, a card appears at Review inbox > Wiki updates from record changes.
   - **Owner:** entries with no exported draft (about 170 of the 296 linked) keep the gaps they had
     before 2026-10-14 unwritten (their baseline). Say whether the site should draft those too
     (one switch: drop the baseline for entries `update-drafts.json` lacks).
   - The "KOP has new information" badge in the wiki editor.
   - One staff line differs from the script: "was also" a role pair at one place ("Director of
     Business Development, Therapist" at Foundation House) is not split into two phrases as
     `also_roles()` splits it (`kop_wiki_auto_staff_line()`; the test allows it).

Tests: `scripts/test-wiki-gaps.php` (linking on the real rows, gap kinds,
the no-deletion diff, approve and exact Undo on an in-memory copy) and the
existing `scripts/test-wiki-contact.php`.

---

### 3.13 Data audit against outside sources (2026-10-06)

The wiki pilot (3.12) turned up wrong KOP data: Oakley School closed in
2017, not 2007; Turn-About Ranch was never Acadia's; Clark Harman died on
Feb 3, 2024, not Feb 1 (zipped into a bivy sack, the restraint); Kelly Cole is Kelly
Corey misspelled. `scripts/data-audit.py` (offline, tmp/prod.sqlite + the map)
flags the patterns behind them: a renamed program's name ending the year its
earlier name did, an end year with Woodbury issues naming the program later,
status and years that disagree, closures confirmed from an order, operator
lines the record never names, a current operator that bought a past one (the
program may have been sold off first), two people one or two letters apart at
one program, one person id on 5+ programs, and every memorial entry.
`tmp/data-audit/{flags.json,report.md}`; research instructions in
`tmp/data-audit/INSTRUCTIONS.md`, results per flag in `tmp/data-audit/results/`.

Round 1 (2026-10-06): 134 flags researched (renames, status, operators,
people): 88 KOP wrong or partly wrong, 31 right, 16 unsure. Turned into 103
typed proposals in `js/data/data-audit/proposals.json` (field, past operator,
note, map years, company link current/past/removed, person merge, by hand),
each "from" checked against the mirror. Reviewed in the review inbox, queue
**Data audit** (`inc/data-audit.php`, `inc/review-inbox/data-audit.php`):
Approve checks every "from" first and changes nothing when the record moved
since; Undo puts back exactly what it changed. Map years go over Map Years and
Map Renames (option `kop_data_audit_map_years`). Test:
`scripts/test-review-inbox.php --source=data-audit`.

Owner decision 2026-10-06: a program running under a new company is **Open**
on its record; "Transferred" shows only on the page of the company it left
(its link in `kop_operator_facilities` is `past`; `kop_operator_program_status()`
in `inc/operator-pages.php`). The 29 records still saying Transferred are in
the queue as `transferred-<id>`.

Open:
1. **Owner:** work through the Data audit queue (30 high confidence, the
   "approve every high-confidence correction" tool skips any with a by-hand
   step). By-hand steps it lists: split Jenny Jones off person #181, two Glenn
   Bender role dates, the Kelly Cole consultant record, map line p0439
   (Wayne Halfway House -> Jacksonville Youth Academy) in
   `network-overrides.json`, several record merges (Timberline into Daytona,
   Island View into Elevations, Three Springs campuses into their successors).
2. Round 2 (done 2026-10-06): all 230 memorial entries researched (Sonnet),
   every proposed correction re-checked against its sources by Opus, which wrote
   the final change (op type `memorial`, one column of `memorial_victims`;
   init-memorial-db.php seeds only an empty table, so approved fixes stay):
   86 memorial proposals (52 high), among them Clark Harman (Feb 3, 2024;
   restraint in a bivy sack, homicide), Rylan Harris (drowned, not struck by a car), Alana
   Richardson (2023, not 2020), Kenneth Barkley and Corey Foster (dates and
   restraint findings). 91 entries checked out; 48 could not be settled and
   change nothing. The 31 programs with later Woodbury issues: 23 right, 4
   proposals. Queue total 193; 195 after two added 2026-10-07 from a cloud
   session's check of the wiki pilot's flags: `status_years-10371` (the
   Provo campus closed August 16, 2026, like Springville) and
   `person_common-34` (Jeff Johnson: one career, but no source yet for
   Boulder Creek, Allynwood or Auldern). That session's proxy blocked the
   article pages, so open a source before approving either.
3. Turn-About Ranch's current operator (done 2026-10-06): the buyer in
   Aspen's March 7, 2014 sale was Escalante RTC, LLC, co-owned by its
   managers (Webster, Carter, Bartlett); still operating. Its proposal now
   sets current operator and status Open instead of a by-hand step. The same
   sale took Island View, the Aspen Institute for Behavioral Assessment and
   Copper Canyon, which became Family Help & Wellness partners; no source
   names Turn-About Ranch as one, so the record does not.
4. **Shipped 2026-10-14 (8f0289f6 Woodbury side, next commit Data audit): one correction, one decision.** 51 steps sit on
   more than one card (146 copies; the four `sequel_history-*` Three Springs
   cards share merges, past names and notes). A step made or left off on one
   card is settled on every card carrying it ("Made on" / "Left off on" with
   the card's name, unticked); a card with nothing left open moves to its own
   "Settled on other cards" tab and comes back when that card's decision is
   undone. Same push: Woodbury Facts conflicts that are not (a type that holds
   the other, a typo, the wiki's "If you attended this program..." placeholder
   as a type, a different staff role, which now joins the work history, a
   dated note, a closure on a record no longer open), and a Woodbury reject
   rejecting the same fact from the other sources with one Undo. After deploy:
   count the Conflicts section and the Data audit Waiting tab again.

### 3.14 Mobile app (2026-10-06)

An iOS and Android app, [kids-over-profits-mobile](https://github.com/kidsoverprofits/kids-over-profits-mobile) (Expo SDK 57,
expo-router, TypeScript; the README there has the commands). Version one is
public and read-only: search a facility by any of its names, the news feed,
states and countries, parent companies, and a facility page with everything
the website page shows. No accounts, no analytics; it only asks
kidsoverprofits.org for the pages a reader opens.

**What the server gives it** (`inc/mobile-api.php`, live since
[5b48839e](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/5b48839ea39af308e861d724f5db4b2bbc396f27)): three public
GET routes under `kop/v1`, each a copy of named keys, never `SELECT *`, so a
new private column cannot leak: `/facility/<slug or id>`
([example](https://kidsoverprofits.org/wp-json/kop/v1/facility/falcon-ridge-ranch-ut)),
`/operator/<slug or id>` and `/operator?name=UHS`
([example](https://kidsoverprofits.org/wp-json/kop/v1/operator?name=UHS)),
and `/news` with `page`, `per_page` (50 at most), `archive=YYYY-MM`,
`story=<arc slug>` and `facility=<id>`
([example](https://kidsoverprofits.org/wp-json/kop/v1/news?per_page=5)). Each
sends an ETag and `Cache-Control: public, max-age=600`; a matching
`If-None-Match` gets a 304. The app also reads `facility-suggest` (now with
`id` and `url`), `global-search`, `state/<slug>`, `country/<slug>` and
`facilities?view=index`. Survivor testimony that is not published never
leaves the server (`kop_rest_redact_private_testimony()` still applies). A new
section on the facility or company page goes in the keep lists of
`kop_mobile_facility_payload()` / `kop_mobile_operator_payload()` or the app
never sees it. Test: `scripts/test-mobile-api.php` (`--fixture` needs no
mirror; `--dump <dir>` writes the files the app's tests read). It checks every
payload for private keys, HTML and fixture sentinel text.

**Rules in force on the website and in the app** ([68a622df](https://github.com/carlygaejepsen/Kids-Over-Profits/commit/68a622df2bb188cca27715d4a576f8c2201997ef),
[84f93b2b](https://github.com/kidsoverprofits/kids-over-profits-mobile/commit/84f93b2b95d67843c888eade9517d46e283b0617)): a
citation is only a small "source" link, numbered when there are several. One
that only points back to us (the network map, a page of this site) is not
shown. The Woodbury Reports name, issue and page are not printed or put in a
link's preview; the link to our copy of the issue stays. Website:
`kop_facility_pages_is_own_source()`, `_woodbury_clean()`,
`_tidy_citations()`, `_cited_html()`, test `scripts/test-citation-cleanup.php`.
App: `src/lib/citations.ts`, `InlineSources` in `src/components/ui.tsx`.

**Open work, in order**

Still to do by hand: Newport Academy – Alberca's address reads "1850 Calle Alberc,. Camarillo" (record
10087). No record holds published survivor testimony yet, so that section is tested on a made-up account
(the 2026-10-07 fixture work is in [PLAN-ARCHIVE.md](PLAN-ARCHIVE.md)).

1. **Test on devices** (owner step 1 above), then a pass with VoiceOver and
   TalkBack, the largest font setting, and a tablet. Labels exist on every
   card, chip and link; nobody has listened to them. The site's
   `check-contrast.py` does not cover the app; the colours come from
   `src/theme/colors.ts`, which copies `css/colors.css` (keep the two in step).
2. **Deep links.** `npx uri-scheme open kidsoverprofits://facility/<slug>`
   on a device; site addresses (`/facility/<slug>/`, `/operator/<slug>/`)
   open in the app only after owner step 5.
3. **Build and publish.** `eas init`, the preview APK, then store builds
   (owner steps 2 to 4). `eas.json` and `app.json` are ready; bundle id
   `org.kidsoverprofits.app`.
4. **Code to tidy**: the news tab keeps its story and month filters in
   component state, so they reset when it unmounts. (The facility screen is
   split into section components now.)
5. **Later ideas, not started**: other outlets' coverage of the same story
   (`story_group_id` is already in each news item), paging through every
   inspection report (the app shows the newest 20 and links the rest), an
   offline snapshot of the index, and notifications for new articles.
6. **Essential reporting in the app** (server side done 2026-10-11, `inc/featured-reporting.php`): every news
   card carries `featured` ({note, byline, page_url}, null when not picked), facility and company payloads list
   picks first, `/news` returns `featured` (the newest three picks, unfiltered first page only) and
   `featured_total`, and `/news?featured=1` lists only the picks. The app does not draw any of it yet: a strip
   above the news tab, the flag and note on a card, and a list screen for `?featured=1`.

**Home screen and network map in the app (2026-10-11).** The first tab is Home: search on top, then
tiles into the map, companies, states, news, Send and About (About left the tab bar for the Map tab),
the three newest articles, the programs and companies this reader opened last (on the phone only,
AsyncStorage, with Clear) and the Resources box. The Map tab is the website's `/network-map/` in a web
view, the site's header, footer, donate and floating buttons hidden by CSS the app injects
(`src/lib/mapPage.ts`; a renamed theme class there shows them again); links to facility and company
pages open the app's screens, and any site link to `/network-map/#open=` opens the tab on that name. A
facility page's Connections section draws the facility with its map names in two columns (16 at most,
the rest counted) and lists the website's Network groups; the payload's `network` key
(`kop_mobile_facility_network()`, checked by `scripts/test-mobile-api.php`). Owner: try the Map tab on a
phone (pinch, the drawer, a facility link back into the app).

**Send to KOP in the app (2026-10-07).** A "Send" tab: links (article,
lawsuit, bill, website; the extension's `classify.js` ported) and facility
information (a missing facility, or a correction from the facility page's
"Suggest a correction"). Public sends go to `kop/v1/mobile/submit`
(`inc/mobile-submit.php`, test `scripts/test-mobile-submit.php`): the same
queues and duplicate rules as the extension, facility information to
`suggested_edits`, "email me when reviewed" and the newsletter box, a
honeypot and 12 sends an hour per sender; a duplicate is reported only as
"on the site" or "in review". Reviewer sign-in (About tab, application
password in secure storage) sends links through `kop/v1/extension/*`
exactly like the Chrome extension. Sharing a link into the app from another
app needs a development build (not Expo Go); `kidsoverprofits://send?url=`
and Paste link work everywhere. Owner: try each kind once on a phone and
check it arrives at Review inbox.

**Send from any browser (2026-10-07).** The extension needs no account now
(public route, `via=extension`; reviewer sign-in still optional) and loads in
Chrome, Edge and Firefox from one folder; `/send/` takes a link from any
browser, with a bookmarklet (`?url=&title=&text=`). Owner steps: load the
extension unpacked and send one page; publish `dist/send-to-kop-<version>.zip`
(`package.ps1`) to the Chrome Web Store (5 USD once) and Firefox add-ons
(free); Safari needs a Mac with Xcode and the Apple Developer Program
(`xcrun safari-web-extension-converter`). Then put the store links on
`/send/` (they say "coming soon" now).

**Known, not the app's to fix**: `scripts/test-facility-pages.php` has 12
failing checks on `main` (the merged Facility Profile posts: excerpt, jump
links, written sections first, old anchors); they predate this work and are
unchanged by it. The `notes` list in the app's payload still carries the
Woodbury wording and raw addresses; both the website and the app clean them
when they print.

### 3.15 Federal funding on facility records (2026-10-11)

Research of 2026-10-11 found 133 programs that received federal money and
operated since October 2021: Medicaid (Montana's and Maine's out-of-state
rosters, Arizona's AHCCCS, and the 63 youth facilities named in the Senate
Finance Committee's June 2024 report "Warehouses of Neglect", which covers
placements paid through Medicaid and the Social Security Act's child welfare
provisions), federal foster care funds, Office of Refugee Resettlement grants
for unaccompanied children (shelters and secure units count as TTI), TRICARE,
2020 PPP loans and Department of Education pandemic aid. The research agents
read search results only (page fetches and the USAspending, CMS and SBA sites
were blocked), so every entry carries its links; several 2026 ORR amounts come
from HigherGov, an aggregator.

Each program is a proposal in `js/data/federal-funding/proposals.json`: name,
place, the records it may be (`candidates`, best first, matched to
`facilities_v2` by name, other and past names in the same state; 93 to one
record, 22 to pick between, 18 with none), and its funding entries in plain
words. The record field is `federalFunding` (`kop_facility_federal_funding_list()`
in `inc/facility-store.php`, mirrored in `js/data-form-modules/data-normalizer.js`;
no form UI, it round-trips): `[{stream, detail, years, sources [{url, label}],
ref, source}]`. Facility pages list it under "Federal funding", grouped by
kind, after Lawsuits; the app gets `federal_funding`.

Reviewed at Review inbox > **Federal funding** (`inc/federal-funding.php`,
`inc/review-inbox/federal-funding.php`): Add puts the entries on the record in
"Record" (the best match filled in), New record makes one (`kop_facdisc_create()`)
for a program KOP lacks, Leave it off changes nothing; the wording and years
can be edited on the card first. Undo takes off exactly the entries Add put on
(each has a ref) and deletes a record it made while nobody has edited it.
Decisions: option `kop_federal_funding_review`; wording edits:
`kop_federal_funding_edits`. Test: `scripts/test-review-inbox.php
--source=federal-funding`.

Open:
1. **Owner:** work through the queue. "Add every high-confidence match" adds
   the 39 high-confidence single matches at once.
2. Two records end in 2021 (Bernalillo Academy, Evoke Cascades: its only
   entry is a 2020 PPP loan); 62 matched records have no years.
3. Rosters not read in full yet: Montana Medicaid's out-of-state PRTF list
   (Apr 2026), Maine's legislative answer (legislature.maine.gov/doc/11622),
   Alaska's DOJ findings (Dec 2022) and Comagine inventory, Oregon ODHS
   out-of-state reports, TRICARE manual addenda C7ADD_13/14, the SBA PPP FOIA
   files, USAspending/TAGGS for CFDA 93.676. New finds go in as more
   proposals with the same shape.

### 3.16 News coverage of every tracked program (2026-10-13)

The nightly discovery job (`scripts/discover-articles.php`,
`docs/news-processor/README.md`) queried a seventh of the open records a
night, by position in the feed (so the seventh drifted), only their current
names, never a closed program, with the state's name required in the text,
and rejected any hit whose headline did not name the program
(`facility-unmatched`: 185 of 215 sweep rejections in October). 562 requests
on 2026-10-13 kept 0 stories; the Desert Willow Treatment Center closure
(three outlets, Oct 10-12) came back three nights running and was rejected
each time. Since August the sweep's submissions were 45 approved / 109
rejected; Reddit's 79 / 11.

Shipped 2026-10-13 (45155818, b0df00b8): every record and every name it has
had (5,304 names, closed programs and past names included); distinctive
names several to one Google News request within Google's ~32-word reading of
a query (16 words of names, 9 keywords, the `when:` window first; at the end
it was the part dropped and 2020 stories came back); short names and
everyday phrases ("A Mother's Love") one to a request with the state; a
cursor in the state file (version 3), half the list per run, resuming after
a crash; a batched hit whose headline names no program is kept with its
attribution pending and the AI step that reads the article confirms the
program (30 a run) or logs it `batch-unconfirmed` for Scraper finds; finance
sites and obituaries blocked; finds submitted every 50 requests while the
link resolver still answers. `kop_sc_cl_fetch()` falls back to
`COURTLISTENER_API_KEY`, the name the server holds (the status checks had
run anonymously). Test: `php scripts/test-discovery-sweep.php`.

Open:
1. **Owner:** the crontab edits in section 2 (Admin runs).
2. **Measure after a week** (from about 2026-10-20): `news_submissions`
   approved vs rejected for `auto-discovery via google-news` against the
   45 / 109 baseline, how many `batch-unconfirmed` rows Scraper finds holds
   and whether any is a real story (then the confirm rule is too strict),
   and the `cursor X -> Y of T` and `AI confirmations` lines in
   `~/logs/discovery-2026-10.log` (is 30 a run enough; does the resolver
   still 429 by the end of a run). Tune `AI_CONFIRM_PER_RUN`,
   `GN_SUBMIT_EVERY` and the blacklist from that, not before.
3. **New lawsuits from CourtListener** (the owner chose this with the sweep;
   ships once the sweep has run two nights clean). Tested 2026-10-13:
   `GET courtlistener.com/api/rest/v4/search/?type=r&q="Provo Canyon School"
   &order_by=dateFiled desc&filed_after=YYYY-MM-DD` answers (37 dockets),
   accepts `"A" OR "B"`, and the token gives 5,000 requests an hour.
   Plan: `inc/lawsuit-discovery.php` + `api/discover-lawsuits.php` (CLI
   like `api/check-record-statuses.php`: dry run by default, `apply`,
   `--limit`, `--minutes`, `--names=`; crontab 03:00 Central). Names from
   `kop_v2_alias_index()` + `facilities_v2.name` + operators, the sweep's
   distinctive-name rule, 10 per query, `filed_after` = last run minus two
   days, state in option `kop_lawsuit_discovery_state`. A docket counts for
   a name only when the name is in `caseName`, `party[]` or a
   `recap_documents[].description`; others are dropped. Skip a docket whose
   number matches `lawsuits.case_number` (`kop_on_file_case_key()` rule),
   whose URL is in any `source_urls`, or that the job filed before
   (`{prefix}kop_lawsuit_discovery`: docket_id, names, decision, lawsuit_id,
   found_at; Reject in the inbox marks it so it is never refiled). File with
   a direct insert like `api/save-lawsuit-suggestion.php:99-127`
   (`kop_ext_insert_lawsuit()` takes no `filing_date`): case_name, docket
   number, court, jurisdiction federal, filing_date, status filed/closed,
   plaintiffs/defendants split around " v. ", facilities_mentioned, the
   CourtListener URL as the source, `publication_status = 'pending'`,
   `submitted_by = 'CourtListener search'`, then
   `kop_sync_lawsuit_facility_links()`; keep the docket id so the status
   checks need no second search. Drop employment and shareholder suits
   (nature-of-suit 442, 445, 710-791, ERISA, securities; the owner declines
   them) with the reason logged. Add `'courtlistener'` to
   `kop_rinbox_native_origins()` so "Came from" filters them. Test:
   `scripts/test-lawsuit-discovery.php` on an in-memory copy of
   `tmp/prod.sqlite` with `$GLOBALS['kop_sc_fetch_stub']` fixtures.
4. **Scraper finds:** a `batch-unconfirmed` row can be rescued by hand
   there (it keeps the request's names in "Searched for"); worth a one-click
   "It is about <name>" that files it with that attribution, once the
   measurement says such rows exist.
5. **Night run of 2026-10-15 "failed" (found 2026-10-15).** It finished, but
   both AI providers were out of their daily quota (Gemini "exceeded your
   current quota", Groq 429): 0 submitted, 34 carried to the next run.
   Cause: Document Types' AI backlog was drained in one go on 2026-10-14
   (741 calls 16:00-17:00 Central, 994 that day, against its hourly 12),
   inside the same Gemini day (resets 00:00 Pacific = 07:00 UTC, so the
   01:30 Central run is the last half hour of it). Backlog is now 0, so it
   should not repeat. Done 2026-10-15: an AI rate limit no longer counts as
   a submit error (logged as "AI out of quota: N", carried over), so a night
   that lost nothing no longer exits 1. Check after the next deploy that a
   quota night ends without the cron's "failed" line. Done 2026-10-15:
   KOP Tools > AI Usage (`inc/ai-usage.php`) logs every request by job,
   with 48 hourly bars and "Check now" (moved off the Fornits screens).
   After deploy: open it once a job has run and check each job is named
   (not a bare file name; add it to `kop_ai_usage_jobs()`). Open: (b) a
   shared daily AI budget, so a backfill (doc types, Fornits, summaries)
   leaves calls for discovery; the log is the count to budget from. (c) Google News 503s
   ended Google News early (24 failures, then "abandoning") on the night
   runs of 10-03 to 10-06, 10-14 and 10-15 (sweep stopped at 170 of 1,455
   requests last night); the three sweep runs so far covered 97-146
   requests each, not ~1,000, so a full pass takes weeks at this rate.
   Check tonight's log that the 34 carried candidates went through.
6. Declined for now (owner, 2026-10-13): a weekly GDELT full-text pass
   (`scripts/backfill-articles.js` has the query shape; GDELT throttled
   probes from here at one request per 5 seconds) and Bing News (returned
   nothing for a known story).

---

### 3.17 Updates reach every page that shows them (2026-10-13)

The owner wants a change in one place (a wiki entry, a lawsuit) to update
every facility page, hub and app screen that shows it, with no drift.
`docs/DATA-FLOWS.md` maps every store: what writes it, how it is tied to a
facility, who reads it and what keeps each reader fresh. It also lists where
that fails today. In short: no writer announces a change, and each cache
guesses from row counts. About 20 news and lawsuit paths skip the link syncs.
Company, link and location edits move no fingerprint. Most writers never purge
LiteSpeed. Wiki approval's staff are dropped (old record shape). Nothing tells
a wiki entry that its record changed.

Done 2026-10-13: repeated staff entries (11 records, 59 entries) folded by
`kop_facility_save()` and a one-time pass (scripts/test-staff-dedupe.php).

Done 2026-10-13 (DATA-FLOWS section 4 has the list): `kop_record_changed()`
(inc/record-events.php, fired by `kop_facility_save()`, purges the facility
pages); every news/lawsuit write path resyncs links, coverage and story groups
through api/lib-record-resync.php, deletes leave no link rows, duplicate merges
move the ties; the fingerprints and ETags read the revisions and the tables
they missed. Test: scripts/test-record-resync.php.

Open, in order (details in DATA-FLOWS.md section 4):
1. **Check on the server after deploy:** approve one article and confirm its
   facility page shows it at once; the `kop_resync_keep_underived` option is 1.
2. The operator and company-link writers (inc/facility-v2-writer.php) and the
   people sync fire `kop_record_changed()`. Also the memorial, approved inspection highlights and
   Map Renames/Years: today the wiki updates (3.12 step 6) reach those only through their slow sweep.
3. Drop the dead `kop_facility_v2_request_sync()` calls, then the 10-minute
   flush once the revisions have proven themselves.
4. Wiki approval's staff go straight onto the record: done 2026-10-13 (record in
   [PLAN-ARCHIVE.md](PLAN-ARCHIVE.md); cards at Review inbox > Wiki staff added). Not merged and still
   open: 14 entries whose linked record is gone (merged or renamed since), 7 linked to a company whose
   program they name is not under it, 8 with no link (Review inbox > Wiki links).
   **Too many wiki staff read as current (2026-10-14).** New approvals now write the job title, not the
   parser's wording ("current Clinical Director", "one", "married to ..."; `kop_wiki_staff_title()`), and
   "Former <title>" at a closed program or when the write-up opens "was/were/worked/served"
   (`kop_wiki_staff_record_role()`). Staff already on records: `php api/fix-wiki-staff-former.php apply` ran on
   the live site 2026-10-14, 586 entries on 182 records (227+8 titles cleaned, 172+2 closed, 40 past tense,
   137 not a title -> no role); a second dry run finds 0. `php api/fix-wiki-staff-former.php undo apply` (ea-php82)
   puts every role back; the log keeps the new role so taking an approval back still works. js/wiki-parser.js
   gives the same titles (staffRoleTitle(), scripts/test-wiki-staff-title.js: JS == PHP on all 1,049 roles).
   **Every other staff entry, done 2026-10-14** (inc/staff-former.php, `api/fix-staff-former.php apply` on the live
   site): 7,169 roles on 522 records now "Former" (4,500 at closed programs, 2,629 dated sightings older than last
   year such as "Admissions (2007, Woodbury Reports)" / "Teacher (2021, HEAL)", 40 ", left"), and 19 entries that
   were not people taken off (17 rows of Moonridge Academy's phase sheet HEAL read as a staff list, a licence line at
   Bethel Bible Village, a headline at Advancing Youth's Academy). `php api/fix-staff-former.php undo apply` puts it
   all back. Woodbury Facts and Fornits now write "Former" when they add such a person and refuse a non-person
   name; kop-pipeline's woodbury-facts.py proposes them that way (dbd3ea7).
   Still open: about 400 wiki staff at open programs whose write-up says "is the ...": the wiki says they are
   there now and no rule can tell otherwise; a check against each program's current staff page would (a Data
   audit kind?). A sighting from last year (2025) still reads current until the daily WP-Cron check
   (kop_staff_former_yearly()) runs the pass in the first days of 2027; check ~/logs/wp-cron.log or the
   kop_staff_former_year option then. Two checks failed
   before this work and still do: scripts/test-woodbury-facts.php "every staff entry added or filled names its
   source and link" (4 without) and scripts/test-fornits.php "every link added" (11 records missing).
5. Wiki staleness listener + badge (this is 3.12 step 6). Listener and inbox item done 2026-10-14
   (`inc/wiki-auto-updates.php`); the badge is open.
6. Nightly drift check screen; scripts/test-record-events.php (no bypass, every
   fingerprint moves).
7. The smaller items in DATA-FLOWS section 3: rename flips in a facility's
   Network section, inspection `replace` remap, merge registry gaps, the
   network rebuild hash inputs, state hubs reading Inspection Links.

### 3.18 Podcast transcripts: facts and on-air statements (2026-10-13)

The industry's own podcasts, transcribed locally (kop-pipeline
`scripts/podcast-transcribe.py`; Stories from the Field first, 333 episodes,
transcripts in kop-pipeline `tmp/podcasts/`, never committed). The owner wants
two things from them, kept in their own stream (never through Woodbury Facts):
facts about programs and people (careers, openings, sales, incidents), and
every on-air statement by an industry professional, the host included, that
belongs on the record: restraint, transport, isolation, deprivation, cutting
kids off from their parents, discrediting kids or survivors, minimising deaths,
dodging oversight, the business. Whisper has no speaker labels and mishears
names, so every statement carries who said it, how sure that is
(sure/likely/unclear) and why, and nothing is used before someone has listened
to the audio at its time.

Done 2026-10-13: kop-pipeline `scripts/podcast-facts.py`. `batches` writes
`tmp/podcasts/INSTRUCTIONS.md` and batches of episodes not read yet (68
episodes in 20 batches so far). Readers write `tmp/podcasts/<show>/facts/<stem>.json`.
`check` tests every quote against the transcript, stamps its time and an audio
link that starts there, and writes `proposals.json` + `report.md`.

Open, in order:
1. Pilot: read 2-3 episodes (221, the Open Sky closure, and 115/116, "A
   History of Controversies") and have the owner look at what gets flagged
   before the rest are read.
2. Read every batch as the transcriber finishes episodes (`batches` again
   picks up new ones).
3. A review queue of its own in the theme (Review inbox source
   `inc/review-inbox/podcasts.php`): each card plays the audio from its time,
   shows the quote with its context and the attribution, and lets the owner
   correct the words and the speaker. Facts are matched to records and added
   with exact Undo, like the other fact queues.
4. Owner decisions (2026-10-13): approved statements stay internal, shown
   nowhere public for now; a former participant who later worked in the
   industry is a professional (name, jobs, statements, including about their
   own time in a program); "downplays" is a stance of its own.
5. A professional's own time in a program is a map connection (owner,
   2026-10-13: they carry its practices and beliefs with them): readers record
   it as a person fact with event `attended`, and the review queue's Add for it
   draws the map's existing survivor-category line (as Rocky Mountain Academy
   to Mike Strader) through whatever the map reads besides the board CSVs,
   with Undo.

### 3.19 Nonprofit tax filings (IRS Form 990) (2026-10-13)

Many programs are nonprofits, and their yearly returns are public. kop-pipeline
`scripts/nonprofit-990.py` matches every record's names (name, current, other,
past; the facility pages' name key) against the IRS Exempt Organizations
Business Master File in the same state, and companies' names anywhere; for
closed, transferred and unknown records no organization matched it asks
ProPublica's Nonprofit Explorer search (a closed program's nonprofit has often
lost its exemption and left the IRS file). For each organization it fetches
every return ProPublica holds: revenue, expenses, assets, liabilities,
officers' and directors' pay, other salaries, program revenue, gifts and
grants, and the filed PDF. Output in kop-data `nonprofit-filings/`
(`candidates.json`, `orgs/<ein>.json`, ~4 MB), report in kop-pipeline
`tmp/nonprofit-990/report.md`.

First run (2026-10-13): 546 organizations (524 from the IRS file, 22 from the
search), 504 with returns. 343 are "matched" (the organization's own name is
exactly one record's, same town); 203 are "check" (another town, several
records, only the IRS's second name, or exempt other than as a charity: each
card says which). Some big ones sit in "check" only because the IRS has the
headquarters town (Devereux, Youth Villages, Southwest Key, Justice Resource
Institute, Eckerd).

Reviewed at Review inbox > **Nonprofit tax filings**
(`inc/nonprofit-filings.php`, `inc/review-inbox/nonprofit-filings.php`): Link
to this program (best facility filled in), Link to a company, Not this program,
Undo (deletes exactly the links the card made). Every "Same name and town"
card links itself (inc/auto-connect.php, hourly, by 'auto', Undo in Recently
done; an undone card is never linked again); "Link every same-name, same-town
match with filings" is left for any the run has not reached. Links are rows
of `{prefix}kop_nonprofit_links` (moved by Merge Duplicates); the facility and
company pages print "Nonprofit tax filings" (a table per organization, ten
latest years, earlier ones folded) from the files at render time, so a rebuild
shows at once. Test: `scripts/test-review-inbox.php --source=nonprofit-filings`.

Open:
1. **Owner:** work through the queue (the bulk button first, then "Check the
   match").
2. The app: add the section to `inc/mobile-api.php` (page data key and keep
   list) and the app's facility/company screens.
3. Officers, directors and key employees by name (Form 990 Part VII, in the
   IRS e-file XML, not in ProPublica's summary) into people ids and the network
   map's board/staff lines; related organizations (Schedule R) and payments to
   management companies (who really runs a nonprofit program).
4. Rerun monthly (`--refresh-bmf`, and `--refresh-orgs` for new returns); open
   data: a `nonprofit_filings` dataset of linked organizations.
5. For-profit companies: state business registries (Secretary of State
   filings: officers, registered agents, formation and dissolution), one
   reader per state starting with UT, ID, MT, FL, TX, AZ, NC, or OpenCorporates'
   API (ask about its public-interest access first); SEC EDGAR 10-K / Exhibit 21
   subsidiary lists for the public parents (UHS, Acadia).
6. (2026-10-14) "Link to a company" here and on Business filings now finds the
   company by name or makes a new one ("New company named ..." under the
   matches; its notes cite the IRS listing or state filing; Undo on the Linked
   tab deletes it again while nothing else uses it). The same field is
   `kop_record_field_param()` for any queue, and actions for many queues are
   card add-ons, switched on per queue at KOP Tools > Card Add-ons
   (`docs/ADDING-CONTENT.md` section 5). After deploy:
   - **Owner:** open https://kidsoverprofits.org/wp-admin/admin.php?page=kop-card-addons
     and tick the add-ons where you want them; on a Nonprofit tax filings card,
     "Link to a company…", type a name not on file, pick the "New company"
     line, then Undo it from the Linked tab.
   - Not tested offline: the create itself (`kop_wbc_create()`, MySQL-only
     SQL); it is the create Woodbury Reports and Wiki links already use. Check
     the first one made on the live site has its note and folder.
   - Queues still with their own create form, to move onto the field: Wiki
     links ("Create the record and link it"), Woodbury Reports, Woodbury
     Facts. Their forms also ask for city, state and type, which the field
     does not yet; add those as optional params beside it when moving them.
   - Next add-on to offer: "Run by a company" (a card's facility tied to a
     company found or made with the field), off by default.

---

### 3.20 State business filings: Florida and Colorado (2026-10-14)

Most programs are run by a company registered with a state, and the states
publish those filings. kop-pipeline `scripts/florida-sunbiz.py` reads Florida's
free quarterly bulk files (Division of Corporations SFTP: about 13 million
companies, active and inactive, with officers, registered agent, the last three
annual reports, events and fictitious names), streamed, ~2 GB download.
`scripts/colorado-sos.py` reads Colorado's open data (data.colorado.gov:
entities, the full filing history, trade names; no key). A company is a
candidate when its name, a name it had before, a trade name it registered, its
EIN (only the nonprofit already matched for the program, and only for nonprofit
company types) or its street address is a record's in that state; address
matches with no shared distinctive word, crowded addresses and one-word trade
names outside the program's town are dropped. Shared rules in
`scripts/lib/business_filings.py`. Output in kop-data
`business-filings/<st>/` (`candidates.json`, `entities/<doc>.json`): people by
name, title, city and state only, never a street address. Reports in
kop-pipeline `tmp/florida-sunbiz/report.md`, `tmp/colorado-sos/report.md`.

First runs (2026-10-14): Florida 140 companies (13 matched: own name, same
town; 127 to check), Colorado 48 (12 matched, 36 to check).

Reviewed at Review inbox > **Business filings** (`inc/business-filings.php`,
`inc/review-inbox/business-filings.php`): Link to this program, Link to a
company, Not this program, Undo (exact). The matched view links itself
(inc/auto-connect.php, hourly, like nonprofit filings); "Link every
same-name, same-town match" does any it has not reached. Links are rows of
`{prefix}kop_business_links` (moved by Merge Duplicates); facility and company
pages print "Business filings" (status, officers, agent, name changes,
dissolution, trade names, a link to the state's page) from the files at render
time. Test: `scripts/test-review-inbox.php --source=business-filings`.

Open:
1. **Owner:** work through the queue (bulk link first, then the "check" view).
2. **Filed documents as PDFs** (built 2026-10-14). Colorado: every filing is
   a free PDF at `coloradosos.gov/biz/ViewImage.do?masterFileId=<entity>&fileId=<transaction>`
   (a cookie from the detail page, a browser user agent). `colorado-sos.py pdfs`
   saves every filing of the 48 companies except the periodic reports (owner's
   choice: address and agent only), 245 filings, into kop-pipeline
   `tmp/colorado-sos/pdfs/<entity>/<filing>.pdf` + `manifest.json`, never
   committed; the viewer puts up a Cloudflare check after about ten requests
   (a script-driven Chrome window only loops on it), so the plain step goes one
   request every 30 s and waits each check out: most of a day. Each page event
   carries its filing id (`file`). Florida: `florida-sunbiz.py pdfs` reaches
   search.sunbiz.org as Chrome (curl_cffi), finds each company's page by its
   document number (the site's own search form; its page address is kept in
   `pdfs/detail-urls.json` and the next build links it as "Florida's record of
   it", the old search link answered 500) and saves every document but the
   annual reports (one request every 10 s); Florida's events have no filing id,
   so a page line links the one document filed that day
   (`kop_bizf_event_copy()`). The next Florida build also fixes event names
   that kept the file's padding ("Name change         amendment").
   `api/import-business-filings.php <dir> [apply]` (`kop_bizf_import()`) files
   the PDFs of linked companies in a "Business filings" folder of the
   program's library (a company's own folder when linked to a company), typed
   "Business or property record", source = the state's address; the page links
   each filing to our copy (`_kop_bizf_filing` on the attachment). **To do:**
   once the owner has linked companies, copy the folder to
   `~/kop-import/business-filings/co/` (and `fl/`) and run it per folder (dry
   run first); rerun after more links. Rebuild Florida once its download ends.
3. **Utah** (businessregistration.utah.gov, Cloudflare + a Turnstile check on
   the search): a reader driving real Chrome for exact-name searches of the UT
   records and their past names, then principal-name searches for the map's
   people; ask the Division of Corporations about a custom list (fields,
   price; $5 for 200 records, then $0.05 each, Utah.gov subscriber);
   OpenCorporates as a fallback.
4. Refreshes: Colorado by targeted API queries per record instead of the whole
   entity file; Florida each quarter.
5. The app (`inc/mobile-api.php` keep lists), open data, officers into people
   ids and the network map.

---

### 3.21 Programs' own papers read: rules and practices (2026-10-13)

Owner, 2026-10-13: facility profiles and wiki entries make little use of what
we hold. 361 of 409 current wiki entries said nothing was known about the
program's rules, and the checklist boxes (level system, restraint, seclusion,
limits on contact with family) were ticked on 2 of 4,961 records, while the
media library holds 122 student and parent handbooks, 48 enrollment papers,
staff manuals and hundreds of untyped ground-rule and phase sheets, filed
under their programs. The wiki gaps (3.12) counted documents only as links.

Built:
- `scripts/doc-inventory.php` (theme, offline): every record's document
  library (the page's folder tree) and its linked wiki entries ->
  `tmp/doc-read/inventory.json` (3,720 documents under 540 records).
- kop-pipeline `scripts/doc-text.py`: `fetch` downloads each document from the
  live site and takes its text page by page (pdftotext; scanned pages OCRed
  with pdftoppm + tesseract; .doc via antiword) into `tmp/doc-read/text/`, with
  `issues.json` in the shape `woodbury-facts.py --also` reads; `batches` writes
  one reading list per program (the same text uploaded twice is read once);
  `export` writes the checked practice lines to `tmp/doc-read/practices.json`.
  Readers follow `scripts/doc-read-instructions.md` (kinds practice, person,
  program, incident; every quote verbatim with its page, checked by the
  build). Person/program/incident items join Woodbury Facts with
  `woodbury-facts.py --also tmp/doc-read` (undated documents now accepted).
- Review inbox > **Rules from documents** (`inc/program-practices.php`,
  `inc/review-inbox/program-practices.php`, table
  `{prefix}kop_program_practices`): one card per program and topic, every
  line ticked and linked to its page of our copy with its quote. Add puts the
  ticked lines on the facility page and ticks the record's boxes they show;
  the rest are left off; Undo puts back exactly that. Pencil `pp:<id>` rewords
  a line or takes it off. The facility page's "Reported practices" section is
  now **Rules and practices**: the checklist chips (ttiPractices shown too)
  and the document lines by topic, each with a "source" link. App: `rules` in
  the facility payload.
- Wiki: gap kind `rules` (`kop_wiki_upd_gaps()`): added lines whose document
  the entry does not link yet, for its Program Structure or Rules and
  Punishments section (`detail.section`); `wiki-script-drafts.py` sends them
  to the models, and the assembler already replaces the stand-in text.
- Test: `scripts/test-review-inbox.php --source=program-practices`.

Pilot (Sonnet readers): Spring Ridge Academy (10 documents, 312 items) and
Midwest Academy (14, 347), every quote found on its page; 582 practice lines
exported. Owner, 2026-10-14: far too much. Only what is unusual, shocking,
oppressive or telling goes out: every practice line carries `notable` (one
sentence on why it matters, shown on the card under the line); a line with
none is dropped at export, and lines judged on review keep their reason in
kop-pipeline `scripts/doc-read-notable.json`. Lines sent before and no longer
kept go out under `withdrawn` and the import deletes them while still waiting.
The pilot fell to 87 lines; the owner reviewed them (81 added, 4 left off).

Full run, 2026-10-14 (22 readers, by program): the other 54 programs'
handbook-type documents. 1,419 lines exported, 1,334 waiting on 359 cards.
A line a document gives to another program goes on the record whose name,
past name or other name it is (Copper Canyon -> Sedona Sky, Island View ->
Elevations, a sister program's handbook in the wrong folder); 4 lines of a
Three Points Center staff handbook are left out (two records share the name).
Court exhibits: a filing stamp on a scanned page no longer counts as its text
(the Casa by the Sea handbook, 57 pages, was never OCRed).

Open:
1. **Owner:** review the 359 cards (Review inbox > Rules from documents).
2. Six "handbooks" in the library are only a JPG thumbnail of a PDF
   (`...-pdf.jpg`: Gateway Academy 2013 and 2014, Caribe Vista 1999, Cherokee
   Creek family handbook x3, GCTC 2021 x3); the PDFs themselves are not in the
   media library. Find them (Drive) and import them, then read.
3. Several readers went over 25 lines on dense manuals (Moonridge 2008: 46,
   Midwest 2011: 43); if the owner finds those cards too long, tighten the cap.
4. `--types reading` (NATSAP profiles, marketing, depositions) for people,
   program facts and incidents; the person/program/incident items already read
   join Woodbury Facts with `woodbury-facts.py --also tmp/doc-read`.
5. After the owner adds lines: rerun `scripts/wiki-gaps.php` (sync the mirror
   with the new table first) and draft the wiki sections.
6. Operator libraries (company pages) are not in the inventory yet.

### 3.22 Profile research notes as prose; profiles open by default (2026-10-13)

Owner, 2026-10-13: the facility and company profiles buried most of the good
information in "Research notes" (a bullet list at the bottom of the page), and
too much was hidden by default.

Built:
- `inc/profile-notes.php`: each note ("Cost: about $7,100 per month (cite)",
  "April 2003: bought ...") is read into a sentence that keeps its own "source"
  link, and the sentences are grouped into paragraphs: what happened and when
  (oldest first), what the program was like, then licences, accreditation and
  memberships. The same fact from two sources is one sentence citing both. Left
  out of the prose: bookkeeping ("Added ... by the news scan from", "Corrected
  from outside sources") which becomes "Also drawn on" links, facts the rail
  prints with their source (type, ages, capacity, start year, past names), and
  Fornits claims ("Reported closed on Fornits" now joins the forum leads with
  the testimony). Printed as **About <name>** right after the jump links on
  facility pages, renamed programs' era sections and company pages (after the
  History); the app gets it as `about`. Nothing is reworded beyond the subject a
  fragment lacks.
- Citations with no address now link wherever we can: our copy of the Woodbury
  issue at its page (`kop_facility_pages_woodbury_url()`, from the media
  library), the wiki page, or the HEAL archive address another record gives for
  the same citation (`kop_facility_pages_heal_url()`); `kop_facility_pages_sources_html()`
  is the one "source" link printer. A "KOP copy: <pdf>" Wayback citation cites
  both the archive and our copy.
- `js/facility-profile.js`: every section starts open (staff, licensing,
  history, programs, people and the era sections were collapsed);
  "Collapse all" stays. Lists fold later: 8 serious findings, 12 articles, 30
  timeline years, other staff open up to 40, a program's homes open up to 6.
- Test: `scripts/test-profile-notes.php [--list] [--id=10347]`.

Open:
1. About 76 HEAL citations in the records carry no Wayback address anywhere
   (e.g. "HEAL, Aspen Institute for Behavioral Assessment Exposed! (archived
   2016-01-03)"): their "source" is unlinked. Fix in kop-pipeline: have
   `woodbury-facts.py --also tmp/heal` write the capture's address (from
   `tmp/heal/cdx.txt`) into every HEAL citation, then rerun the facts load.
2. If the owner wants smoother prose than the rules give, an AI pass over the
   same sentences (citations kept, owner approves each, like the plain
   summaries) can replace the rule-built paragraphs per record.

### 3.23 Family Foundation School: the 2026 Education Plus lawsuits (2026-10-14)

Found while adding *United States v. Paul Geer* (3:24-cr-00170, N.D.N.Y.; seeded 2026-10-14, lawsuit 117):
about 33 federal suits filed June to October 2026 against **Education Plus Corporation**, the company behind
the Family Foundation School (record 12050, Hancock, NY; Allynwood Academy from 2013, closed 2014). KOP
holds none of them; the NY Post article of 6/20/2026 (news 213) covers the first.

What is known (CourtListener search `"Education Plus Corporation"`, type r, 2026-10-14):
- Most are in the Northern District of New York (`nynd`, 3:26-cv-01397 onward), cause 18 U.S.C. 1962 (RICO),
  nature of suit 360 personal injury; a few are in the Southern and Eastern Districts (Pizzoferrato,
  1:26-cv-03396 E.D.N.Y. filed 2026-06-05 and 1:26-cv-05250 S.D.N.Y.; Savinovich, 1:26-cv-08085 S.D.N.Y.).
  Older: Kohlhaus v. Education Plus Corp., 3:23-cv-01453 (N.D.N.Y., 2023-11-19).
- Defendants named on Doe v. Education Plus Corporation (3:26-cv-01397): Education Plus Corporation, Betty,
  Emmanuel ("Mike") and Cindy Argiros, Paul and Diane Geer, Jeff Brain, Susan Runge, Lynda Drake, Jennifer
  Whipple, Ivan Fras, Karen Wells, Nancy Edwards, Suzanne Cusack, Heather Palmer, Kasos Enterprises LLC, Kasos
  Co Family LP, Chapel Hill Land Holdings LLC, Quest Family Services Inc., Villa Veritas Foundation Inc.,
  Lackawanna College, K9-5 Inc., NATSAP, NBT Bank / NBT Bancorp, Garnet Health Medical Center
  Catskills-Callicoon, The Hancock Herald, the Village of Hancock and its police department, Delaware County,
  its Board of Supervisors and its Sheriff's Office. The Argiros family, Education Plus, the Kasos companies
  and The Hancock Herald share counsel (Hinman, Howard & Kattell).
- Plaintiffs: several file under initials or as Doe (V.P., J.K., C.R., S.H.).

Open:
1. **Owner:** one lawsuit record per case, or one record for the litigation that lists every docket
   (the cases look like one coordinated filing by the same firms)? Either way, link each to record 12050
   and to NATSAP (a trade organization record). None of the other companies is a KOP record yet: Education
   Plus and the Kasos companies may need company records with the Argiros family on them; Quest Family
   Services and Villa Veritas need looking into first.
2. Read each complaint (RECAP copies on CourtListener; PACER for the rest) for the claims, the years each
   plaintiff attended and the staff named. Plaintiffs who filed under initials or as Doe stay that way in
   every record, page and wiki line; only a name the plaintiff filed under is printed.
3. Staff named as defendants who are not on the record's staff list yet go on it, cited to the complaint;
   people already listed (Susan Runge, Paul Geer) get the case on their careers.
4. Seed through `seeds/lawsuits.json` with docket numbers and full court names, so the nightly status check
   (`inc/status-checks.php`, CourtListener `nynd`/`nysd`/`nyed`) follows them; a CourtListener token
   (`KOP_COURTLISTENER_TOKEN`) is needed for 30+ dockets at 5 requests a minute.
5. Then the wiki: entry 533's draft (3.12) gives only the June 2026 suit; a line for the litigation as a
   whole goes in its Abuse Allegations, Deaths, and Lawsuits section (record changes draft it, or by hand
   as on 2026-10-14).

### 3.24 Clearview Horizon / Clearview Girls Academy; app donate, map and search; SEO (2026-10-14)

One program held as three records (9698 Sandpoint, 100124 Heron, 12698 Clearview Girls Academy). The research is in
`docs/RESEARCH-TASKS.md` (Clearview). Renamed in 2020: the program's own home page changed between the Wayback captures
of 2020-10-25 and 2020-11-25 (our copies are media 15932 and 15933, and HEAL's Clear View page is 15934, all in the
"Clearview Horizon Inc" folder).

Open:
1. **Owner:** the card `clearview-horizon-2026-10-14` at Review inbox > Data audit. It merges 9698 into 100124 (a move),
   then merges 100124 into 12698 as a 2020 rename. It adds past name Clear View and other name Clearview Academy,
   creates the companies Clearview Horizon, Inc. (past) and Elk Mountain Academy, Inc. (current), and sets Mary
   Thielbahr's role. It replaces the bare `rename_years-100124-12698` card.
2. 16 sources sent to Review inbox on 2026-10-14: nine articles (KFF, AP, Parsons Behle, Law360, five Woodbury pages)
   to news review, three court records (Markel, Kinsale, DA 24-0687) to lawsuit review and four websites (CARF,
   GuideStar, NATSAP, the 2023 DPHHS provider list) to Websites Sent In; all waiting for review.
3. A folder "ClearView Communities LLC" (FileBird 92, under "Clearview Horizon Inc") holds 2 files: another company
   name for the program to look into.

App work from the same session, pushed to kids-over-profits-mobile (not yet in a build):
- Donate: a header button on Home and a "Support this work" box on Home and About open /donate/ in the in-app browser.
- Autocomplete: Home suggests programs and companies from the second letter (`kop/v1/mobile/suggest`,
  `kop_mobile_suggest()` in inc/facility-suggest.php, live), Companies adds companies found by another name, and the
  Send form's company field suggests companies.
- Map tab: the app's own search, zoom, Key/List/Timeline chips, Back through the trail, reload and a menu
  (src/lib/mapBridge.ts drives the website's page; the website is unchanged). The style that hides the site's header
  had never applied (it ran before the page had a root element); fixed.

Open:
4. **Owner:** try the app on a phone (Expo Go or the next EAS build): the Map tab's search, Back through three names,
   Android's back, the drawer's drag handle, Key/List/Timeline; Donate on Home and About; search "Copper Canyon" on Home.
5. **Owner:** ship it: an EAS update or a store build (the app's README has the commands).
6. The map page's class names and button ids are now read by the app (src/lib/mapPage.ts HIDE_SELECTORS,
   src/lib/mapBridge.ts BUTTONS): renaming one on the website breaks the Map tab.

SEO: the plan is at https://claude.ai/artifact/FEpJSHihC9fDgdxoxzuQqb. Live since 2026-10-14 (inc/seo.php,
scripts/test-seo.php): past and other names in facility and company titles and descriptions, each program and company
as a schema.org Organization in Yoast's graph (with the WebPage and breadcrumb these routes lacked), descriptions for
news posts, state hubs, the map and the news feed, one home title plus an h1, the banner as share image where a page
has none. Company pages were already in facility-sitemap.xml.

Open:
7. Tag archives out of search: the owner said yes on 2026-10-14; `kop_seo_tag_robots()` makes them noindex, follow
   and `kop_seo_sitemap_exclude_tags()` drops post_tag from Yoast's sitemap. Checked live after deploy:
   https://kidsoverprofits.org/tag/utah/ reads `noindex, follow` and https://kidsoverprofits.org/sitemap_index.xml lists
   no tag sitemap. LiteSpeed's cached copies of other tag pages pick it up as they expire.
8. **Owner:** verify the site in Google Search Console and Bing Webmaster Tools, submit sitemap_index.xml. Then read
   which program names bring searchers and which pages Google refused, and adjust the plan from that.
9. **Owner:** purge the cached /staging/ page (Hosting, item 9 above).
10. Facility pages are ~500 KB of HTML, company pages ~660 KB: load the long lists (documents, inspections) when
    opened. Its own project.
11. Google's Rich Results Test on a facility and a company page once Google recrawls them. Our own parse of the live
    JSON-LD (2026-10-14) is clean: Sedona Sky's Organization carries alternateName Copper Canyon Academy, Devereux's
    lists 46 subOrganization programs, every @id reference resolves.

### 3.25 KOP Tools screens on one look (2026-10-14)

The owner asked for streamlined admin screens: fewer, organised buttons, real filtering, and the same meaning for a
button and a dropdown everywhere. The rules and the shared pieces are in `docs/ADMIN-UI.md` (`inc/admin-kit.php`,
`css/admin-kit.css`, `js/admin-kit.js`). Done: Drive Docs, Fornits and Woodbury Facts (one card component, tabs with
counts, one filter toolbar, a numbered pager, Undo on the result line after every action).

Open, in order:
1. **Check after deploy:** open https://kidsoverprofits.org/wp-admin/admin.php?page=kop-drive-docs,
   https://kidsoverprofits.org/wp-admin/admin.php?page=kop-fornits and
   https://kidsoverprofits.org/wp-admin/admin.php?page=kop-woodbury-facts; add one item, click Undo on the result
   line, and on a phone check the cards stack. Only the offline preview (`scripts/preview-admin-kit.php`, 390/768/1280 px,
   nothing runs past a card) has been looked at so far.
2. Review inbox (`inc/review-inbox.php`, `js/review-inbox.js`) aligned to the kit: one primary decision, the rest in
   More, and its own buttons sorted out (`rinbox-btn-undo` is reused for Cancel, Rename and Close).
3. Data Manager rows and the merge screens (Merge Duplicates, Merge People) on the kit.
4. Search and paging for the lists that have neither: Bug Reports, Closure Reports, Facilities from News, Glossary
   Feedback, Young Adult Programs, Indigenous Schools.
5. The old wp-admin screens that repeat a Review inbox queue stay (owner's choice) and get the same kit.
6. Last: the `api/*` tool pages.
7. `scripts/test-fornits.php` fails one check against today's mirror: "every link added (327)" finds 11 records missing
   (#13972, #11097, #10333, #13795, #10496, #100048 and others). The screen change did not touch that code; the
   records were probably merged or moved since the batch was read. Check whether the waiting Fornits rows still point
   at those ids (kop_fmerge_follow_waiting() should have moved them).

### 3.26 Volunteer projects (2026-10-14)

The owner could not hand work out: "help review the inbox" got no results. A project is now a small fixed batch from one
queue (10 to 30 items, 15 to 30 minutes) with one question, an example of a yes and of a no, and a finish line, sent to one
person as a link (`inc/volunteer-projects.php`, KOP Tools > Volunteer Reviewers > Projects; volunteer side in
`js/volunteer-review.js`). Ready-made briefs for 13 queues (`kop_volp_kinds()`). "Check their calls" applies the clear
approve/reject calls with each card's own button (Recently done, Undo). Tested offline (`scripts/test-volunteer-projects.php`).

Open, in order:
1. **Check after deploy:** at https://kidsoverprofits.org/wp-admin/admin.php?page=kop-volunteer-reviewers make a
   10-item "Serious findings from state inspections" project for "Someone new" with your own name; open the message's
   link in a private window, answer three, then "Check their calls" and Apply one. Turn that test volunteer off after.
2. The five submission queues (News, Lawsuits, Legislation, Wiki, Data) approve through `api/manage-submissions.php`, so
   "Apply ticked" cannot run them: their rows say "open it in the inbox". Route them through the same apply if News
   projects get used.
3. Drive Docs cards are a whole facility's links (up to 100) with nothing a volunteer can click: not offered as a ready-made
   brief. A per-link volunteer view would open the 16,660-link backlog to projects.
4. Owner's call, not built: two volunteers per item (apply only where both agree), and letting a volunteer with a high
   agreement rate apply directly.
5. `scripts/test-review-inbox.php --source=inspection-highlights` fails "a rejected finding offers approve and back to
   pending" with or without this change (found 2026-10-14); look at the rejected card's actions.

### 3.27 Pencils on everything a facility page prints (2026-10-15)

The owner could not edit an earlier name's "It closed in 2005" (San Marcos Treatment Center), article titles or document
types. Now: `era:<id>:<n>` edits an earlier name merged as a rename (name, years, rename year, status, companies, notes) and
"Renamed, not closed" (`erarename`, Undo `eraundo`) turns a closing filed on it into the rename (17 names on the mirror);
the Links form holds `resourceLinks` (Materials and links, Fornits threads); research documents get the `doc:` pencil;
Unsilenced files get `unsil:<Drive id>` (title + types, option `kop_unsilenced_edits`). Commits 228b45e8, 57965b2c and
the one after. A scratch audit (render a page with markers on, list text with no `data-kop-edit` ancestor) found the rest.

Open, in order:
1. **Check after deploy**, signed in, at https://kidsoverprofits.org/facility/san-marcos-treatment-center-tx/: the pencil
   on "As The Brown Schools at San Marcos", "Renamed, not closed" then Undo, a Fornits thread retitled through the
   testimony list's pencil, an Unsilenced file's title and type (chip shows, survives a reload).
2. Still without a pencil: the survivor-site archive lists (`kop_survivor_archives_render()`, same shape as Unsilenced:
   give it the `unsil` treatment keyed by url), the operator pages' research and Unsilenced lists (`templates/operator-page.php`),
   the network map's names on the page (edited on the map), inspection report rows and findings (state data; findings have
   Review inbox), "Other programs run by" rows (each is its own page), and the summary lines under Materials and links.
3. Unsilenced types are kept on our side only: they are not on /documents/<type>/ pages, which list media library documents.
