# Work plan archive

Finished items moved out of [PLAN.md](PLAN.md) once nothing open needed their context, verbatim,
under the heading they had there. Newest move first. Commit SHAs that name
`carlygaejepsen/Kids-Over-Profits` resolve in the archived old repository.

## Moved 2026-10-25

### 4.7 Pages and templates

2. **Post classification**: refresh the mirror, rerun `scripts/inventory-posts.py`, write
   `docs/POST-CLASSIFICATION.md`; confirm `in_news_feed` for every News post before item 17 (c) is acted on.
   Done 2026-10-25: docs/POST-CLASSIFICATION.md written from the 2026-10-25 mirror; 74 of 75 News posts have one
   approved feed row each, the 75th (470) is the duplicate of 786. Open: the mirror sync built
   kop-pipeline/tmp/prod.sqlite.building but could not replace tmp/prod.sqlite (file held open, WinError 5); close
   whatever holds it and rename, or rerun the sync. (kop-pipeline) inventory-posts.py lacked `import os`; fixed.
3. **"Where are the kids?" posts**: recommend `single-facility-profile.php` where a facility page covers the same
   facility. Then do item 17. Done 2026-10-25: nothing left; the only published one (Discovery Ranch, 10800) already
   uses it. Provo Canyon School (10796) was still a draft in the 2026-10-25 mirror though the Plan Board says published.
17. **Site content, go-ahead given** (owner, Plan Board 2026-10-25: "please do all of that"; background in
    PLAN-ARCHIVE.md, old 2 items 11-15). Do all five, after items 2 and 3 here:
    (a) hub editor content, the five recommendations, through `kop_apply_text_fixes()` in `inc/admin.php`;
    (b) unpublish to draft `facility-form-test`, `data-organizer`, `data`, `data-analysis`,
    `arizona-adhs-inspections`, after checking no link or redirect depends on them;
    (c) 301 the 75 News posts to their feed entries (confirm `in_news_feed` for each first);
    (d) per lawsuit post, 301 to `/lawsuits/#lawsuit-<id>` when the row covers it, otherwise keep it and link it
    from the row; (e) deactivate Code Snippets snippet 6 (OG Image).
    Done 2026-10-25, owner's go-ahead on the change list, commit
    https://github.com/kidsoverprofits/Kids-Over-Profits/commit/db8e0b07dd60c222dc5511ef988f2d057b918a3b. (a) four
    seeds/text-fixes.json page entries (Where Are the Kids + Advocates link the location tab, Advocates h6 -> h2, Survivors'
    lists -> one line to Resources, Research & Reports' hidden grid deleted; History was already h2/h3); /11-12-2024/ 301s to
    /survivor-resources-nature/. (b)+(e) one-time pass kop_apply_site_content_cleanup() (template assignments 141, log in option
    kop_site_content_cleanup): the five posts are drafts (404 live), snippet 6 off (front page has one og:image/og:title).
    (c)+(d) seeds/post-redirects.json read by kop_post_redirects(): 75 News posts -> /tti-news-feed/?item=<row> (new one-article
    view, cards carry id="news-<row>"), 8 lawsuit posts -> /lawsuits/#lawsuit-<id>; all 84 checked live. Kept: 2962, 1084, 1909
    ("Our write-up" on rows 2, 43, 44, kop_lawsuit_writeups() in templates/page-lawsuits.php; links to redirected posts dropped);
    2837 (Whetstone Academy) kept, its WSPA article sent to lawsuit review. Checked live: contrast, bare text, overflow on the
    item view, /lawsuits/, /survivors/, /advocates/; screenshots at 390/768/1440.

## Moved 2026-10-21

### 4.7 Pages and templates

12. **"Report a problem" link contrast** (found 2026-10-20, already live before the home page phone trim):
    `python -u scripts/check-contrast.py --mobile /` flags `.kop-bug-report-link__text` at 3.97:1 on white
    (needs 4.5). css/bug-reporter.css:58 and its :hover/:focus rules; use `--kop-teal-ink` or navy.
    Done 2026-10-21 (e6e5c856, https://github.com/kidsoverprofits/Kids-Over-Profits/commit/e6e5c856): Kadence's
    `.button-style-outline:not(.has-text-color)` tied our rule and painted the link from `--global-palette-btn-out`
    (teal-ink), faded by the 0.85 phone opacity; the link now sets that variable to navy. check-contrast.py passes
    on / live, mobile and desktop.

## Moved 2026-10-20 (reorganisation)

PLAN.md had grown to 2,900 lines in which finished work, background and open steps were mixed. Its whole
sections 2 ("Waiting on the owner") and 3 ("Open work, in order") are kept here verbatim as they stood at
841d47f4: the finished items, and the background of the items still open. What is still open was rewritten
in PLAN.md as short action items, each citing its old section here ("old 3.12" = 3.12 below).

### 2. Waiting on the owner

Actions only the owner can take (admin browser sessions, hosting, editorial
calls). Tools that change data show a dry run first; add `?apply=1` to act.
The date is when each was last confirmed open.

#### Repository split (2026-10-13)

Done (the record is in [PLAN-ARCHIVE.md](PLAN-ARCHIVE.md)). Left for the owner: delete the two
tokens made under the old account at github.com/settings/personal-access-tokens,
and remove the duplicate `public_html/.env` (a copy of the theme's, not
web-readable).

#### Admin runs

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

#### Mobile app

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

#### Hosting

10. **Portal leftovers** (optional). NixiHost backups still hold the one
    pre-encryption submission (`SUB-2025-YLVUY0CL`) until they rotate, unless
    support purges them. The owner deleted the public copies and asked
    archive.org to remove its capture on 2026-09-24; not re-checked, at the
    owner's request.

#### Decisions

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

#### Unconfirmed settings

19. Which mode production's `kop_data_model` / `kop_data_model_areas`
    options use for the `program_index` read area, and the value of
    `kop_submission_notify_emails` (no admin screen; default `admin_email`).


### 3. Open work, in order

#### 3.1 Network map

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

#### 3.2 Page templates and posts

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

#### 3.3 Inspection reports

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
   **Scanned PA reports re-read with better OCR (2026-10-18, no AI):** the
   pre-2019 scans are 200 dpi faxes and Tesseract's fast model misread them
   badly ("Realdent # 8 had a safely plan ... thal"), and got dates wrong
   (8/6/16 for 8/5/15). OCR version 2 in kop-pipeline `pa_scraper.py` takes
   each page's scan out of the PDF as stored (the state's black boxes over
   blanked names are drawn over the scan, so every filled box is painted
   onto it first; a page with other drawings or annotations is drawn whole;
   `python scripts/test-pa-scan-image.py`), enlarges and smooths fax pages, uses
   Tesseract's best English model (downloaded once to
   `scrapers/.tessdata_best/`), then `scrapers/ocr_repair.py` swaps only the
   letters this typeface confuses, toward words the clean 2019+ reports use
   (`scripts/build-pa-ocr-vocab.py` -> `scrapers/pa_ocr_vocab.json`), and
   moves a year 2+ past the report's. Exact words on four hand-copied
   passages: 51-91% before, 93-100% after; clean text changes 0.06% of
   words (mostly fixing OCR junk). `python scripts/test-ocr-repair.py`.
   Open:
   - **Owner: rerun the PA scraper on the scanned years** (`python
     pa_scraper.py --full`; extractions from OCR version 1 are read again,
     text-layer ones are kept), about 5,750 documents at ~5.5 s a page, with
     memory free (it pauses below 1.5 GB). The 164 waiting PA serious
     findings from 2012-17 are replaced by fresh ones from the new text
     (pending findings follow the report; none from those years is approved).
   - A day or month misread (5/6/8 in this font) cannot be told from the
     text: pages the repair changed 8%+ of carry `categories.ocr_poor_pages`.
     Show "read from a poor scan: check dates against the PDF" on the
     Serious violations card and the report viewer for those pages
     (theme: inc/review-inbox/inspection-highlights.php, js/inspections/states/pa.js).
   - Do not write plain summaries (inc/highlight-summaries.php) from PA text
     read by OCR version 1; after the rerun, check a few 2013-16 findings.
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
   **VA links (2026-10-17, commit 3174a2a3):** serious-finding links (review
   cards, volunteers, facility pages, /severe-reports/) open the DBHDS report's
   Drive PDF instead of the provider search. VDSS inspection links land on DSS's
   facility search whatever the query says, and `va_pdfs/` holds no VDSS copies,
   so those findings show no outside link (one now: Alpha House I, 2023-11-30).
   Open: `va_scraper.py` saves each VDSS inspection page as a PDF into `va_pdfs/`
   and records its `archive_name`, then the same lookup links it. Scrapers with
   no PDF backup at all (checked 2026-10-17, no ReportStore or own archive):
   AZ, CA, CO, CT, GA, MN, NV, OK (keeps dated page HTML only), TX. Check after
   deploy: a VA card at Review inbox > Serious violations opens a Drive PDF.
   **Scraper folders moved out of the FileBird folder (2026-10-17):** all 24
   (`xx_pdfs`, `me_html`, `nh_html`, `nc_ocr`, `ok_html`, `ut_checklists`,
   `Inspections`) now sit in `My Drive/KOP Inspection Reports/` (kop-pipeline
   `kop_paths.reports_drive_base()`, theme `api/sync-inspection-archive.php`).
   **Owner, before 03:45 Central (the nightly sync):** share `KOP Inspection
   Reports` as "Anyone with the link". By 18:45 every scraper folder had lost the
   sharing it inherited from the FileBird folder, and the sync drops the archive
   index of a folder that is not shared (the VA index was already dropped by an
   `apply --only=va` run at 18:44; re-run `apply` once shared to rebuild all). Then check with
   `php api/sync-inspection-archive.php` (dry run): every state shows index entries.
   **PDF of every report (2026-10-17, in progress):** kop-pipeline
   `report_store.py` prints report pages to PDF (`PagePrinter`, `archive_page`);
   the scrapers with no copies (VA VDSS, AZ, CA, CO, CT, GA, MN, NV, OK, TX) are
   being given it, each with a backfill flag. The sync already lists their
   `xx_pdfs` folders, and every serious-finding card links "Archived copy (PDF)"
   once a state's index has the report (commit e636bd7f). Open: run each backfill
   (commands to follow), share each new folder (inside the shared parent it
   inherits), and Utah cards: their rows carry no checklist id, so the card
   lookup finds none (the /ut-reports/ page reads ids from ut_reports*.json).
   Done so far: VA VDSS (171 printed and posted 18:41), AZ, NV, OK backfills
   started 2026-10-17 (`py -3 <st>_scraper.py --backfill-pdfs`; VA: `py -3
   va_scraper.py --source vdss --archive-all`). CO has never posted: its first
   evening run with real citation reads also tests the PDFs. GA (813 surveys,
   VPN off) and TX (~11,460 deficiencies, 8-12 h, resumable) backfills started
   2026-10-17 evening. **Owner: MN backfill** needs Chrome closed:
   `py -3 scrapers/mn_scraper.py --backfill-pdfs` (210 reports, ~20 min).
   Pages linked: VA, AZ, CO, NV, OK, GA, MN, TX, CT, CA (tx_reports.js and
   ca-reports.js load their index themselves). NV posted 193 reports with PDFs
   (nv_scraper fixed to use the launcher's key, kop-pipeline 66d0dfa). CT backfill
   started. **CA backfill: owner's call** (30,885 reports, CCL is slow: 3-5 days
   in chunks, resumable): sync the mirror, then `py -3.14 ca_scraper.py
   --backfill-pdfs`. New CA runs already save PDFs. CA data issue seen: many CA
   facility rows have the facility number as their name, 98 numbers have two rows. **NV junk rows:** the
   old nv_scraper read the grid's pager as inspections; reports "1", "2", "3"
   and "..." on /nv-reports/ need deleting (owner's go-ahead first).
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

#### 3.4 Long-form articles

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
   **Screenshots (2026-10-15, 345de923):** both articles show the materials they quote, highlighted (13
   here at seed v9, 8 in Deceptive Marketing at v5; attachments 16045-16065, folder #8506 "Reputation
   series - document screenshots"; captions name the date and link the archived page and our copy).
   Open: our PDF copies of the Vive FAQ, Maple Lake FAQ + Emotional Health and Asheville Academy FAQ were
   printed with the answers collapsed or faded, so the quoted words are not in them; the screenshots and
   captions use the Wayback pages, but the "our copy" links in the text should be replaced by prints with
   the answers opened. New Lifehouse's "Licensed counseling" is only in its hidden AI copy (text corrected);
   the owner may want a PDF of that hidden text as our copy too.

#### 3.5 Data research

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

#### 3.6 Small and unverified

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
  code per queue. `scripts/test-doc-types.php` passes since 2026-10-15 (/documents/ now lists a document
  in a company's own folder under its /operator/ page). Still on no page, because no page shows their
  folder: the top-level folders "Hyde" (id 19, 10 Hyde School enrollment papers), "ANASAZI" (214) and
  "Agape Boarding School" (7815). Owner: link each to its program's folder (Hyde School 297, Anasazi
  Foundation 5659, Agape Baptist Academy 4819) with api/link-folders.php, or move the files with the
  tile pencil.
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
- Several program types (2026-10-17): a record's `facilityDetails.type` holds them joined with ", "
  (`kop_facility_types_split()`); every review card's Type is a box per type, a type conflict has "Both are
  right", the data forms' type box takes a comma list. After deploy: on Review inbox > Conflicts (Kind: Type) press
  "Both are right" on one card and Undo it; tick two types on a Facilities from News card. Not done: the
  directory, search and map still show the string as it is (no per-type filter there).
- Staff "Former" / "current" only when confirmed (2026-10-17): a closed program, the source saying they left,
  or the wiki write-up's own words; never an old sighting or the past tense alone; "current" never. Waiting on
  the owner's go (both write live records, both have exact undo; dry runs on the server 2026-10-17):
  `php api/fix-staff-unconfirmed.php apply` (2,651 entries on 226 open programs lose an unconfirmed "Former")
  and `php api/fix-wiki-staff-former.php apply` (29 wiki entries on 21 records). Check the woodbury-facts
  inbox test's "Founder (2021, HEAL)" check passes again with the rule changed.
- Past jobs held once on the person (2026-10-17, `{prefix}kop_person_jobs`, inc/person-jobs.php): after the
  deploy, open KOP Tools > People once (makes the table), wait for the hourly people sync (or "Sync now") to read
  the staff entries' pastJobs in (1,536 jobs on the mirror), then check a person listed on two programs shows the
  same "Also worked at" on both. Not done yet: Woodbury Facts, the wiki and the data forms still write pastJobs
  text on the entry (the sync reads it in); writing straight to the person's jobs, and a company person's jobs, come next.
- People editor additions (2026-10-17, KOP Tools > People): Details now open the person's page, with "Became an
  educational consultant" (`ed_consultant`, people table version 4: the ALTER runs on the first admin load) and
  Undo for every details save (option kop_people_details_log); a ticked person's career ends "Educational consultant"
  on every facility and company page. Relationships (`{prefix}kop_person_relations`, inc/person-relations.php; kinds in
  Dropdown Lists > People) show on both people. After deploy: open People once, tick one person and check their
  facility page. Relationships are public (owner, 2026-10-17): printed under each listed person on facility and company
  pages, and drawn on the map between two people already on it (kop-pipeline addPersonRelations(); a tie to someone not
  on the map is listed in tmp/network-qa.md, not drawn). After deploy: add one relationship between two map people, check
  both facility pages, and that the next hourly fingerprint starts a map build that draws the line. Not done yet: the map
  draws no new person node for a relationship. A consultant record naming the person is shown as a hint, never ticks the box.

#### 3.7 Name eras

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
- Names then on company pages (2026-10-16): /operator/ pages and the directory's company tab list a renamed
  program by the name it had under that company (`kop_facility_eras_name_under()`). Open:
  - Check after deploy: https://kidsoverprofits.org/operator/three-springs-inc/ lists "Three Springs of Courtland"
    ("Now known as Brighter Path Courtland"), https://kidsoverprofits.org/operator/sequel-tsi/ lists "Sequel TSI Courtland".
  - `scripts/test-name-under-operator.php` still fails one case it failed before: Psychiatric Solutions should list
    #10370 as Rivendell of Utah, gets the record's own name (map/rename data for that record, not this rule).
  - Duplicate records under Three Springs/Sequel, each one program under two names: merge as a rename at
    https://kidsoverprofits.org/wp-admin/admin.php?page=kop-merge-duplicates (keep first, earlier name second):
    #100076 Sequel TSI Madison <- #9656 Three Springs School of Madison (or, if #9656 is the school of the program,
    "Program and its academic program" on the same card); #100078 Sequel TSI of Tuskegee <- #9657
    Three Springs School of Tuskegee; #13640 Brighter Path Montgomery <- #100077 Sequel TSI Montgomery; #11098
    St. John's Youth Academy <- #9654 Three Springs of St. Johns. Check first: #9648 Three Springs of Duck River vs
    #9639 L.E.A.P.S. Program at Duck River (both Centerville TN; a program on the campus, or the same one?).
    #100076 lists "Three Springs New Directions", also a past name of Pathway of Madison County #13678: one is wrong.
  - Auldern Academy #9637 lists "Three Springs of North Carolina" among its other names, which is #11903's past name;
    one of the two records is wrong.


#### 3.8 Follow-through (session review, 2026-09-30)

A read of every session since 2026-09-02 for requests that were started and
not finished, checked against git, the code and the mirror. Everything else
asked for in that time shipped or is already listed above.

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

#### 3.9 Facility information from the Google Docs (2026-09-30)

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

#### 3.10 More survivor site archives (2026-10-01)

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

#### 3.11 Unsilenced backup and titles (2026-10-02)

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


#### 3.12 Wiki entries brought up to date from KOP (2026-10-05)

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


#### 3.13 Data audit against outside sources (2026-10-06)

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

#### 3.14 Mobile app (2026-10-06)

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

#### 3.15 Federal funding on facility records (2026-10-11)

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

#### 3.16 News coverage of every tracked program (2026-10-13)

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
   (not a bare file name; add it to `kop_ai_usage_jobs()`). Done 2026-10-15:
   a daily AI budget (`kop_ai_budget_check()`, api/ai-usage.php): background
   jobs stop at 70% of each provider's day (Gemini ~500, Groq ~400 requests,
   from midnight Pacific; KOP_AI_DAILY_GEMINI / _GROQ / KOP_AI_BACKGROUND_SHARE)
   and go on after midnight; discovery and anything by hand never wait.
   After a week: read AI Usage for the real daily ceilings (the first "out of
   quota" of a day) and set the two numbers from them. (c) Google News 503s:
   measured 2026-10-15 from the server, 178 batched searches at the cron's
   pace, then HTTP 503 for batched and single-name searches alike for 17+
   minutes (probe stopped there; cleared by the next 12-hourly run). Done
   2026-10-15: a run makes at most GN_REQUESTS_PER_RUN = 140 Google searches;
   the owner's daily list (inc/discovery-priority.php: open programs with
   news or a serious finding in the last 12 months, or a lawsuit; "Daily news
   search" switch on each page) is searched every day, the rest rotate
   (~5-6 weeks). Three years was 391 programs / 258 searches a day, more than
   two runs allow; the owner chose 12 months (~220 programs, ~155 searches).
   The block outlasts an hour (still 503 at 14:46, an hour after it began),
   so more cron runs do not help. After deploy: tonight's log shows "daily
   list: N records", "this run: 112 (N daily ...)" and no 503s; if 503s
   still come, lower GN_REQUESTS_PER_RUN in the crontab env. Check tonight's
   log that the 34 carried candidates went through. Not done: grouping short
   names by state ("Grafton" OR "Day One" "Wisconsin") would roughly halve
   the searches; the owner left it for now.
6. Declined for now (owner, 2026-10-13): a weekly GDELT full-text pass
   (`scripts/backfill-articles.js` has the query shape; GDELT throttled
   probes from here at one request per 5 seconds) and Bing News (returned
   nothing for a known story).


#### 3.17 Updates reach every page that shows them (2026-10-13)

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

#### 3.18 Podcast transcripts: facts and on-air statements (2026-10-13)

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
3. Review queue: built 2026-10-15. Review inbox > **Podcast statements**
   (`inc/podcast-statements.php`, `inc/review-inbox/podcasts.php`, table
   `{prefix}kop_podcast_items`). Each card has the words, "Listen at" the
   quote's second, who said it and how sure. "Approve and link" lets the owner
   correct the words and link a person id (suggested from the speaker's
   name; a new full name gets a new id, which Undo removes again) and the
   programs and companies it is about (suggested by exact name only). Approved
   items show only to administrators: "On air" on the person's KOP Tools >
   People screen and an "On air" section on each linked facility or company
   page. Filters: kind, severity, stance. Exact Undo.
   Still open:
   - After each `check`, copy kop-pipeline `tmp/podcasts/<show>/proposals.json`
     to `~/kop-import/podcasts/<show>.json` on the server (the queue imports
     it when the file changes; the 5 pilot episodes went up 2026-10-15).
   - Person and program facts are only linked so far; writing them onto the
     records (a staff entry with its role, a program's operator or years) with
     exact Undo, like Woodbury Facts, is the next step.
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

#### 3.19 Nonprofit tax filings (IRS Form 990) (2026-10-13)

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

#### 3.20 State business filings: Florida and Colorado (2026-10-14)

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


#### 3.21 Programs' own papers read: rules and practices (2026-10-13)

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

#### 3.22 Profile research notes as prose; profiles open by default (2026-10-13)

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

#### 3.23 Family Foundation School: the 2026 Education Plus lawsuits (2026-10-14)

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

#### 3.24 Clearview Horizon / Clearview Girls Academy; app donate, map and search; SEO (2026-10-14)

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
8. Search Console: set up for about two years; sitemap_index.xml (re)submitted to Google and Bing on 2026-10-15. Site Kit
   is connected to the property (owner = user 1) but lacks Search Console permission ("you haven't granted all
   permissions requested during setup"), so the server cannot read it. **Owner:** grant it in Site Kit (Settings >
   Connected services, or the "grant permissions" prompt on its dashboard), or export Performance (queries, pages) and
   Pages > "Why pages aren't indexed" to Google Sheets. Then read which program names bring searchers, which rank but
   are rarely clicked, and which pages Google refused, and adjust the plan. Left on the server by a blocked cleanup:
   ~/kop-gsc.json and ~/kop-gsc.err (only the permission error), safe to delete.
10. Lighter pages (2026-10-15, inc/defer-folds.php + js/defer-folds.js, scripts/test-defer-folds.php): the cost was elements,
    not bytes (pages are 43-218 KB compressed; Provo Canyon School had 12,400 elements in <main>). A closed fold's contents
    (older and celebrity/viral news, document subfolders, Unsilenced groups, more findings) now go out in a <template> and
    are drawn when opened, when the address points inside one, or before printing; the document type filter counts and
    draws them. Provo 12,371 -> 6,339 elements, Sedona Sky 3,308 -> 1,800, UHS 4,423 -> 2,217. Signed-in editors get the
    whole page (pencils); ?kop_full=1 does too. Trade-offs: text in a closed fold is no longer indexed by search engines
    or found by the browser's find-in-page until opened (news headlines of other outlets, file names); staff lists are
    not in folds and stay whole. Next if needed: Provo's staff (2,347 elements) and the timeline's later years.
    **Check after deploy:** https://kidsoverprofits.org/facility/provo-canyon-school-ut/ in a private window: open
    "more articles" and the Unsilenced groups, pick a document type, print preview.
11. Google's Rich Results Test on a facility and a company page once Google recrawls them. Our own parse of the live
    JSON-LD (2026-10-14) is clean: Sedona Sky's Organization carries alternateName Copper Canyon Academy, Devereux's
    lists 46 subOrganization programs, every @id reference resolves.

#### 3.25 KOP Tools screens on one look (2026-10-14)

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

#### 3.26 Volunteer projects (2026-10-14)

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

#### 3.27 Pencils on everything a facility page prints (2026-10-15)

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
4. **Check after deploy** (2026-10-16, commits 4a64b92d + 16de6d7b), signed in, at
   https://kidsoverprofits.org/facility/provo-canyon-school-ut/#documents: with "Woodbury Reports Mentions" closed, no stray
   pencils over the grid; "Select documents", pick a few (shift-click a run, "Pick all in this folder"), Move to another
   facility, then Undo from the note after the reload. Not checked live by Claude: the browser window drew no frames that
   session; scripts/test-doc-select.py (made-up page) and test-doc-placement.php (the moves) pass. Also try a company page's
   documents (each program's folder its own library, one bar for all).

#### 3.28 Richer timelines on company and facility pages (2026-10-15)

The owner asked for timelines with context, hover cards, pictures and scandals instead of bare "acquired" lists.
`inc/profile-timeline.php` (+ `css/profile-timeline.css`, `js/profile-timeline.js`) builds the "Year by year" timeline
of /operator/ pages and a new Timeline section on /facility/ pages: deals and takeovers from the map's operated years
(a program one company stopped running the year another began is a takeover; a deal names what moved with it), programs
begun/ended, lawsuits with outcome, deaths with cause, approved serious findings, incidents, renames, press arcs with
pictures; filter buttons, a year strip, "More about this" cards (hover with a mouse, tap on a phone). A program's events
count for a company only in the years it ran it. 114 researched company milestones (buyouts, private equity owners,
investigations, settlements, closures; each source quoted) are in `seeds/company-milestones.json` and wait at
Review inbox > Company milestones (`inc/review-inbox/company-milestones.php`; Approve / Leave it off / wording edits,
Undo); none shows until approved. With all approved, 110 render on company pages (the other 4 belong to Bain Capital,
CRC Health Group and Keystone, which have no company page).

Open:
1. **Owner:** review the 114 cards at https://kidsoverprofits.org/wp-admin/admin.php?page=kop-review-inbox (Company milestones).
   Cards with a "Check" line carry a research caveat (e.g. Sequel's founding year: 1999 in APM Reports, 1992 on the wiki).
2. **Check by hand:** https://kidsoverprofits.org/operator/sequel-tsi/ and https://kidsoverprofits.org/operator/universal-health-services/
   (filters, a card on hover and on a phone) and https://kidsoverprofits.org/facility/sedona-sky-academy-az/. Already
   checked live on 2026-10-15: contrast (after the year strip's hidden labels got their colours) and phone/tablet overflow.
3. Sources: 92 of the 109 milestone sources were new to KOP; `tmp/send-milestone-sources.py` sends them 12 an hour
   (log `tmp/send-milestone-sources.log`, done list `tmp/milestone-sources-sent.json`). If it stops early, rerun it; it resumes.
   After it, the same script sends `tmp/other-sources.json`: the two govinfo records and the Sabra 10-K from item 21 of
   section 2, and the ADAP letter on Sequel's Alabama PRTFs (log `tmp/send-other-sources.log`). The Royal Commission
   summary (item 21) still needs its full Wayback address and the PDF the archive rule asks for.
4. Company pages for Bain Capital, CRC Health Group and Keystone Education and Youth Services would carry their 4 milestones.
5. `scripts/test-facility-pages.php` "links a staff entry and a fact to their sources" fails with or without the timeline:
   its first sample (Aspen Education Group) is now a renamed program, whose staff go into the name sections. The fixture
   should pick a record without eras.

#### 3.29 Dropdown Lists: the owner adds choices to every dropdown (2026-10-15)

The owner asked for an admin panel to add categories to dropdown menus. KOP Tools > Dropdown Lists
(https://kidsoverprofits.org/wp-admin/admin.php?page=kop-choice-lists, `inc/choice-lists.php`) lists 26 choice lists in
four groups (`inc/choice-lists/{sorting,data-form,news,statuses}.php`): sorting kinds (website kinds, "Materials and
links" kinds, Drive Docs kinds, Fornits categories, closure stages, document types, bug report categories), data form
choices (facility types, staff roles, accreditations, certifications, treatment/philosophy/critical-incident tick boxes,
marketer services, trade-org activities), the news processor (article types, content warnings, preferred tags) and
lawsuit/bill/volunteer lists (lawsuit and bill statuses with "finished" and a badge colour, bill positions, volunteer
project types, memorial causes). Add, rename, hide (never delete); Undo per save. Untouched lists give the same AI
prompts as before (closure and news prompts compared byte for byte).

Open:
1. **After deploy, check:** the first wp-admin page load turns five ENUM columns in the records DB into VARCHAR(40)
   (`lawsuits.status`, `legislation.status`, `legislation.position`, `volunteer_projects.project_type`,
   `news_submissions.article_type`; `kop_maybe_status_columns_to_varchar()` in `inc/choice-lists/statuses.php`). Over SSH:
   `SHOW COLUMNS FROM lawsuits LIKE 'status'` says varchar(40), and option `kop_status_columns_varchar` is '1'
   (if not, `~/logs` / the PHP error log has `kop_status_columns_to_varchar:` with the reason).
2. **Check by hand:** the screen at 390 px and 1280 px; add a test choice to one list, see it in its dropdown, Undo it.
3. **Owner decision:** the /news/ feed, state and country pages print an article's type as its raw value ("Lawsuit");
   switching them to the list's labels ("Lawsuit Article", `kop_news_article_type_label()`) would change public wording.
   The browser extension's popup already shows the list's labels (it reads them from the site, no extension update).
4. A status added on the screen can only borrow a built-in status's badge colour (no new CSS).
5. `js/data-form-modules/data-page.js` (the old report) turns tick-box keys into words itself, so an added box prints as
   its key (`breathing_restraint`); make it read `window.KOP_CHOICE_LISTS` like `data-report-generator.js`.
6. Gender served, chamber, level and publication statuses stay in code (normalisation and workflow states, not categories).

#### 3.30 Card Fields: what review cards show, and filing on any kind of record (2026-10-15)

The owner asked to decide which fields review screens include, and to link items to a company, an educational
consultant, a transport company, a young adult program... instead of only a facility. KOP Tools > Card Fields
(https://kidsoverprofits.org/wp-admin/admin.php?page=kop-card-fields, `inc/review-inbox-fields.php`): per queue, the
fields in "Edit details" and the lines on the card to show (a hidden field still sends its value), and the kinds its
record picker offers. A non-program pick is filed by `kop_rinbox_file_on_record()` (`inc/review-record-file.php`):
company links/websites in its "Materials and links", news in `kop_operator_links` (listed on /operator/), notes; young
adult program links and cited facts; other kinds a cited line in their notes. Exact Undo everywhere. Queues taking any
record: Move to (news, lawsuits, bills, Websites sent in, Scraper finds), Drive Docs, Fornits, Facilities from News,
Federal funding, Closure reports, Woodbury Facts (now young adult programs too), Woodbury Reports pages (no young adult
programs: no document folder), Wiki links (no young adult programs: the link store cannot hold one).

Open:
1. **Check by hand:** open https://kidsoverprofits.org/wp-admin/admin.php?page=kop-review-inbox once (the screen learns
   each queue's fields as cards are drawn), then Card Fields; on a Websites sent in card, "Move to" Materials and links
   with a company picked, see it on the company page, Undo.
2. The older KOP Tools > Drive Docs and > Fornits screens still pick programs only: their finder box is
   `kop_facility_finder_field()` and `js/admin-kit.js` refuses a non-number value.
3. Podcasts stay program + company: `inc/podcast-statements.php` (`kop_pod_tokens()`, approve, chips, purge) reads only
   those, and "On air" exists only on facility and company pages.
4. Facilities from News: an article filed on a company does not make later articles naming that company follow it (the
   scan follows facility links only).
5. Notes on consultants, transporters, providers, marketers and trade organizations show where their list pages print
   notes; check a filed line reads well there.
6. `scripts/review-inbox-tests/fornits.php` seeds made-up rows only into an empty table; the mirror now holds 8,391 real
   waiting items, so its count and filter checks fail with or without this work. Re-seed it into its own table.
7. News cards now have "Connect a record" (2026-10-16, `kop_rinbox_native_connect()` in `inc/review-inbox/native.php`):
   the article stays in the news (pending or approved) and is also filed on a company (listed on /operator/ once approved),
   an educational consultant, transporter, provider, marketer, trade organization (a line in its notes citing the article)
   or a young adult program (its links); "Take off" on the card and Undo in Recently done. **Check by hand** at
   https://kidsoverprofits.org/wp-admin/admin.php?page=kop-review-inbox (News tab): connect a consultant, see the note on
   https://kidsoverprofits.org/referrers-educational-consultants/, Take off. The consultant notes path needs MySQL, so the
   SQLite test runs only the company path. Programs still go under the card's "Facilities mentioned".
8. The news card's Website button now asks whose website it is (2026-10-16, "Website of a" dropdown in
   `js/admin-submissions.js`, `kop_rinbox_record_websites()` in `inc/review-record-file.php`): program (profileLinks, as
   before), company (operator.websites), consultant / transporter / marketer / trade org (their websites list), provider
   (a note: no list), young adult program (its links). **Check by hand** at https://kidsoverprofits.org/admin-submissions/:
   move one link as a consultant's website, see it on https://kidsoverprofits.org/referrers-educational-consultants/,
   Undo move. Marketers and trade orgs are not in the mirror, so their path is untested.
9. The news card's Resource button asks which kind of record too (2026-10-16, "Resource for a", commit
   https://github.com/kidsoverprofits/Kids-Over-Profits/commit/ae0423ab37fd5b3f2be77202f22b09972ef2b879; the detail panel's
   "Not a news article?" finder takes any record): a company gets the link in operator.resourceLinks ("Materials and links"
   on /operator/), a young adult program its links, the others a line in their notes. **Check by hand** at
   https://kidsoverprofits.org/admin-submissions/: move one link as a company resource, see it under "Materials and links"
   on that company's /operator/ page, Undo move.

#### 3.31 Approved facts wait for a written-up About (2026-10-15)

The owner: approved facts should no longer go onto the public record by themselves; they wait in an admin-only place
until everything is written up well and approved. `inc/record-writeups.php`: Add in Woodbury Facts (every source:
Woodbury, HEAL, the wiki, program documents), its hourly exact-name run, "Use this value" on a conflict, and Fornits
staff, incidents and leads kept on a record now set the item to `held` (the record is unchanged; each item is still
checked against the record plus the items already held). Each record with held facts gets a write-up of its About
section, drafted by the AI hourly (3 records a run) or with "Write it with AI", reviewed at Review inbox > Write-ups to
approve (https://kidsoverprofits.org/admin-submissions/?type=writeups, `inc/review-inbox/writeups.php`). Approving
writes every held fact on in one save and makes the text the page's About (the app's 'about' too); Undo in Recently
done takes it all back. Facility pages show the held facts and the draft to administrators only ("Waiting for the
write-up"). Fornits discussion links and survivor accounts (added unpublished) still go on at once.

Open:
1. **Check after deploy:** approve one Woodbury Facts item, see it under "Approved, held for the write-up" and in the
   admin-only box on its facility page (logged out: not there); "Write it with AI" on its Write-ups card, edit the box,
   Approve, see the About and the staff list; Undo from Recently done.
2. The 10,468 items the hourly run used to add at once now pile up as held facts, one write-up per record; at 3 drafts an
   hour the backlog takes days. Decide whether to raise the batch (AI budget: KOP_AI_BACKGROUND_SHARE) or approve the
   first ones by hand to judge the drafts.
3. Not held yet (still written at once, as before): facts filed on companies, consultants and the other record kinds
   (`kop_wbf_file_existing()`, `kop_fornits_apply_record()`, `kop_rinbox_file_on_record()`), young adult program facts,
   wiki staff on approval (`kop_wiki_merge_submission()`), Rules from documents, federal funding, Drive Docs links,
   data audit corrections. Ask the owner which of these count as "facts" for this rule.
4. A record created from a Woodbury card ("Create") is still made at once (empty but public); only its facts wait.
5. Company pages: their About is still the notes-built one; a write-up for companies would need the same on
   `kop_operator_page_data()`.
6. `scripts/review-inbox-tests/woodbury-facts.php` "not conflicts: ... another staff role" fails with or without this
   work: a 2021 HEAL sighting is now "older than last year", so `kop_staff_role_with_former()` makes it "Former Founder".

#### 3.32 Investors table (2026-10-16)

The owner asked for a database table for investors (scope picked: table + links). `inc/investors.php`:
`{prefix}kop_investors` (name, kind, website, about, sources), `{prefix}kop_investor_links` (a stake in a company or
program: role, first and last year, its own words, source) and `{prefix}kop_investor_log` (exact Undo), edited at KOP
Tools > Investors (https://kidsoverprofits.org/wp-admin/admin.php?page=kop-investors). Company and facility pages print
the links in their Investors line; a program names its companies' investors ("majority owner of Sequel TSI, from 2017").
The first admin load after the deploy turns the free-text entries on file (Sequel TSI's four, Paradigm's one) into
investors and links. Test: `scripts/test-investors.php`.

Open:
1. **Check after deploy:** open the screen; the five imported investors are listed with their stakes (Patriot Capital,
   Levine Leichtman, Alaris Royalty, Altamont, Pine Tree Equity) and Recent changes shows the import; see the Investors
   line on https://kidsoverprofits.org/operator/sequel-tsi/ and on a Sequel program's facility page; link one, Undo it.
2. The import guessed each stake's role and years from the words ("growth financing, exited 2008" = lender, until
   2008). Check them on the screen; Patriot Capital's start year and Pine Tree's role are not in the text.
3. More investors are only on the network map, filed as "parent" or "other" nodes (Bain Capital, Trinity Hunt Partners,
   Youth Opportunity Investments, the four Sequel investors). Add them by hand or decide whether the map build should
   read this table and draw investors as their own kind.
4. Left out of this scope (owner's pick): a data form tab, a public /investors/ page, site map, open data
   (`kop_open_data_datasets()`), people ids for firm partners, filing Woodbury/review cards on an investor. Each is a
   step in `docs/ADDING-CONTENT.md` section 1 if wanted later.
5. The "About" and "Sources" of an investor print nowhere public yet (no investor page).
6. Investors are in search since 2026-10-17 (owner asked; `kop_inv_search()` in the header dropdown, the search popup and
   search.php). With no investor page, a hit opens the page of the company or program it holds (or held last) a stake
   in. **Check after deploy:** type "Altamont" in the header search and at https://kidsoverprofits.org/?s=altamont: an
   Investors result "Investor - majority owner of Sequel TSI" opening https://kidsoverprofits.org/operator/sequel-tsi/.
   If investors get their own page later, point the results there.

#### 3.33 Directory Editor: the directory's company cards in one screen (2026-10-16)

The owner asked for an admin screen that makes the TTI Facility Directory easier to edit (remove a company card, edit
fields, transfer programs). KOP Tools > Directory Editor
(https://kidsoverprofits.org/wp-admin/admin.php?page=kop-directory-editor, `inc/directory-editor.php`): one card per company
with what the directory does with it (on it, fewer than 2 programs, same name as another, taken off); edit the card's fields
(a rename keeps the old name and redirects the old /operator/ address); take the card off the directory or put it back
(`operator.directoryHidden`; the company page stays); delete; merge into another company; add a program, take one off, set
its tie (current / past / other); move ticked programs to another company, optionally kept as past. "Every field" and "Edit
program" open the site's pencil dialogs. Every change in `{prefix}kop_directory_edits` with exact Undo. Test:
`scripts/test-directory-editor.php`.

Open:
1. **Check after deploy:** open the screen (the log table is made on the first admin load); hide a company with two or more
   programs, see its card leave https://kidsoverprofits.org/tti-program-index/ within a few minutes, Undo it from the notice;
   open "Every field" and "Edit program" (the dialogs load in wp-admin from the same REST route as on the site).
2. The "Same name or no name" tab lists company records whose card another record of the same name holds; merging them is
   the owner's call one by one (Merge Duplicates is for programs only).
3. Merge keeps the target's own values and fills only empty ones; a history (`operator.history`) on both stays the
   target's. Moving a company's history by hand is the "Every field" dialog.
4. Not in this scope: reordering programs within a card (sort_order) and editing the location tab's place groups.
5. Check that saving a company in the admin data form keeps `operator.directoryHidden` (the form may drop keys it does not
   draw); if it does not, a hidden card comes back after such a save. Hide one, save it in the data form, look again.
6. Alternate names table (2026-10-17): the old-name tiles became an "Alternate names" table below the companies (sort by
   any heading, the page's search and letters filter it); admins tick two or more names of one record and fold them into one
   (keep one, reword it if wanted; Undo), or open Edit names. **Check after deploy:** on
   https://kidsoverprofits.org/tti-program-index/ fold two names of one program, reload, Undo it from the notice.
7. Name eras by hand (2026-10-17): the Alternate names table's "Run by then" column. Admins press Set company on a row:
   company (suggested from the companies on file, or typed), first and last year; Undo in the notice
   (`{prefix}kop_name_eras`, inc/name-eras.php). It wins over the guesses in inc/facility-eras.php for the directory's name
   under a company and the facility page's "Formerly ... (company, years)". **Check after deploy:** set one (the table is
   made on the first save), reload the directory: the program heads its listing under that company by that name; open
   its /facility/ page, see the company and years after the name; Undo. Open:
   a. The network map build (kop-pipeline build-network-graph.js) does not read kop_name_eras yet: the map still dates names
      from the board and Map Years. Add it to the sync's 7 tables and to the build, and to inc/network-rebuild.php's fingerprint.
   b. The facility page cuts into "As <name>" sections only from the map and rename merges; a hand-set era does not cut it yet.
   c. Only program names have the column; company names (a company's own other names) do not.
8. scripts/test-name-under-operator.php fails one check on the mirror as committed before this work: Psychiatric
   Solutions, Inc. lists 10370 under its own name, not "Rivendell of Utah". Look at whether the map or the record changed.
   Also failing on the mirror before this work (same set with and without it, 2026-10-17): scripts/test-facility-pages.php
   13 checks (one: "the page renders to the end after the post") and scripts/test-facility-eras.php 1.

#### 3.34 Drive Sync: "Sync now" for the Drive folder's documents (2026-10-17)

KOP Tools > Drive Sync (inc/drive-sync.php) starts api/sync-drive-documents.php in the background (exec + nohup, the same
lock as the nightly cron) and shows its progress and the last run's report (imported with links, not placed, failed).

Open:
1. **Check after deploy:** open https://kidsoverprofits.org/wp-admin/admin.php?page=kop-drive-sync, press Sync now: the line
   should turn to "Reading the Drive folder..." within a few seconds and to a report when it ends. If it says the sync did not
   start, the web server's PHP is not allowed to start a background process (or kills it when the request ends): look in
   ~/logs/drive-sync.log, and fall back to a WP-Cron single event that runs the import in the cron's own PHP.
2. Owner's choice: the server cron runs it once a night (`15 3 * * *`). Every 15 minutes (`*/15 * * * *` in cPanel > Cron Jobs)
   would bring a new document in within 15 minutes with no button; the lock keeps two runs from overlapping.

#### 3.35 News clippings read by OCR, searchable, filed as articles (2026-10-17)

The media library's news clippings (documents typed `news-clipping`, 54 on the mirror) are read on the owner's computer by
kop-pipeline `py -3 scripts/clippings-ocr.py run` (ocrmypdf: an invisible text layer over the scan, the picture unchanged;
pages that already have text are left alone) and `upload` to ~/kop-import/clippings/. On the server
`php api/import-clippings.php ~/kop-import/clippings [apply]` (inc/news-clippings.php) replaces each PDF with its searchable
copy (the original kept in uploads/kop-clipping-originals/<id>/), stores the text in {prefix}kop_doc_text (the header search
and its popup find documents by the words inside them, with the words around the match), and files one article per clipping
in Submissions Review > News through the News Processor's AI read: linked to the address a saved web page prints, else to
our copy of the scan; json_data.clippingAttachmentId names the document. Held articles (same link, same headline and
outlet) make no row. `undo apply [--ids=..]` puts every file back and deletes the articles still unreviewed.

Open:
1. **Trial of 10 applied 2026-10-17** (documents 2999, 3005, 4171, 4255, 5233, 9632, 11142, 11831, 13276, 13283 -> articles
   #10491-#10500, waiting in https://kidsoverprofits.org/admin-submissions/?type=news). Owner
   checks them; #10496 (CEDU, Alpenhorn News) tied to no record. Then the other 44: `run` + `upload` + `apply` (the 10 are skipped).
   The AI also lists places that are not programs (Bath Iron Works, Desert Hospital): untick them on the card.
2. New clippings later: run the script again (only unread ones are read; sync tmp/prod.sqlite first so it sees them).
3. Built 2026-10-18: each library holding documents with stored text has "Search the words inside these documents"
   (js/doc-type-filter.js, kop/v1/doc-text-search; tiles carry data-doc-id/data-doc-text from kop_dt_tile_attr()); file-name
   titles (23 of 54: "cedu crummel criminal", "medium.com-...") become "Headline (Outlet, date)" after their article
   (kop_clip_is_slug_title(), Undo puts the old title back); an image clipping becomes a searchable PDF beside its picture,
   whose address keeps working. Checked 2026-10-18: `titles apply` renamed 4 of the 10 (the rest are written titles); the
   search finds "Crummel" in two clippings on https://kidsoverprofits.org/operator/cedu/ (390 and 1280 px). The other 44 still
   wait for `run` + `upload` + `apply`; their file-name titles are renamed as they go.


#### 3.36 Duplicated fields on records: stop the direct writes, clean up (2026-10-18)

Measured on a fresh pull of facilities_v2 (2026-10-18) against the 2026-10-13 mirror: 1,174 records changed, ~5,250 staff
entries, ~2,000 note lines, ~1,600 incidents, ~4,200 links added. Main writers: the Woodbury Facts exact-name auto run on
2026-10-14 (9,982 Woodbury/HEAL/wiki items applied before the hold rule of 3.31 existed), Drive Docs auto (12,675 links),
Fornits auto (~1,330, still daily). Duplication found, all records: 523 roles carrying "(2021, HEAL)"; ~1,280 note lines
repeating a structured field (Ages, Address, Operator/Acquired, Capacity, Type, Serves, Opened, Past name) on ~600 records;
152 people listed twice under two spellings; 24 notes twice from two sources; 11 deaths listed 2-3 times; 5 past names
filed under pastOperators; 49 records with an exact repeat in one list.

Why the notes repeat fields: kop_wbf_doc_apply() (inc/woodbury-facts.php ~780-875, also kop_wbf_doc_overwrite ~1010) writes a
provenance note (kop_wbf_source_line()) beside every field it sets, deduped by exact string only, so a second source of the
same fact adds a second line even when the field write is a no-op; held facts take the same path when a write-up is approved
(kop_wu_apply_row()). kop-pipeline woodbury-facts.py note() also turns some facts into notes instead of fields.

Still writing with no click (not behind the hold gate): Fornits discussion links (auto-connect.php:312), Drive Docs facility
and company links (auto-connect.php:533, 573); these dedupe by URL key. Clicks that write fields directly: wiki approve /
wiki-staff (kop_wiki_merge_submission), closures, data audit, federal funding, program practices, Websites attach.

The note lines stay (owner, 2026-10-18): they are where the page's "source" links come from (kop_facility_pages_note_sources()).
Traced on the records the owner approved facts on (2026-10-18): Merge Duplicates joined both records' copies of the same facts
(Alternative Youth Adventures: James Omer 3 times, the 2007 death 3 times, "(AYA)" as another name); another source's
spelling of a listed person was a new person (McLain/McClain, Clarke/Clark; the wiki approve compared exact names only);
roles piled titles and "(year, source)"; one event told by two sources was two lines.

Built 2026-10-18 (api/lib-record-dedupe.php, inc/record-cleanup.php, inc/review-inbox/record-cleanup.php): approvals, wiki
approve and Merge Duplicates ask kop_dd_same_person() / kop_dd_name_key() / kop_dd_same_event() before adding a copy, and roles
are tidied; Review inbox > Record cleanup tidies what is there (dry run on the 2026-10-18 pull: 577 records; 253 people listed
twice, 549 records with roles to tidy, 44 names, 17 events).

Open:
1. After deploy: open https://kidsoverprofits.org/admin-submissions/ > Record cleanup (the first open works the cards out,
   ~30 s); check a few cards (Alternative Youth Adventures, Canyon State Academy, Boulder Creek Academy) before tidying many.
2. People the people table holds as two ids (different personId) are never folded: merge them at KOP Tools > Merge People
   first, then their card appears.
3. Not done: note lines that repeat a filled field from a second source (171 on 91 records) could fold into one line with both
   sources; Fornits/Drive Docs auto links still write without a click (they dedupe by address).

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

## 5. Tests failing on main (finished 2026-10-20)

Passing on the 2026-10-20 mirror; the last three fixed in eb2acbeb.

- **test-facility-pages**: `scripts/test-facility-pages.php`, 13 checks (merged Facility Profile posts; "the page renders to the end after
  the post"; "links a staff entry and a fact" samples Aspen Education Group, now a renamed program).
- **test-facility-eras**: `scripts/test-facility-eras.php`, 1 check.
- **test-name-under-operator**: `scripts/test-name-under-operator.php`, Psychiatric Solutions / #10370.
- **test-wiki-gaps**: `scripts/test-wiki-gaps.php`, "a page with no years or place matches only its exact name" (Mountain Home Academy).
  The page gained years and a place on 2026-10-14; the check now builds its own entry, and a placeholder `years_active` ("-")
  gives way to the header's years.
- **test-woodbury-facts**: `scripts/test-woodbury-facts.php`, "every staff entry added or filled names its source and link" (4 without).
  They were merges into entries that already named a source (r/troubledteens wiki, no link), which the merge rightly keeps.
- The `@unlink` at shutdown of a scratch copy (test-volunteer-projects, test-review-inbox) turned all-passed runs into exit 255;
  the harness's error handler now lets @-silenced warnings be.

## 2.4 Decisions waiting, item 10 (finished 2026-10-20)

10. **Not built, the owner's call**: two volunteers per item, or high-agreement volunteers applying directly
    (suggested 2026-10-20: two volunteers with 90%+ agreement who agree apply on their own in low-risk queues
    such as doc-types, scraper-finds, websites, woodbury-reports, map-years, folder-links, with Undo and a weekly
    sample of 10 for the owner; publishing queues and anything about a named person stay with the owner; see 4.4
    item 8).

Owner, 2026-10-20: build it with an On/Off toggle. Built in inc/volunteer-auto.php, off until turned on.

## 5. Tests failing on main, review inbox causes (finished 2026-10-20)

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

Done 2026-10-20 (42 -> 6): cause 4, Fornits and Drive Docs seed their own rows into emptied tables (the mirror now
holds the real ones); cause 2, the conflicts, Woodbury Facts, federal funding, nonprofit Link, data-audit, wiki-updates,
ya-moved and merge-follow checks seed or reset their samples in the scratch copy; cause 1, the filings and federal
funding queues follow records merged since their build files (kop_facility_merge_follow_candidates()) and the test loads
the live merge map; woodbury-facts "another staff role" expects a role without its source's name (kop_dd_role_tidy()).

### 5. Tests failing on main: test-review-inbox and test-inline-edit (done 2026-10-20)

- **test-review-inbox**: `scripts/test-review-inbox.php` (`-d memory_limit=6G`). Run it alone: a second PHP run
  beside it ran Windows out of paging file. Run from the real checkout (it looks for `kop-pipeline` next to it).
  2026-10-20: program-practices passes again (`python scripts/doc-text.py restore` in kop-pipeline rebuilt
  `tmp/doc-read/practices.json` from the mirror's 1,419 imported lines; the readers' facts are gone, so a fresh
  `export` needs the documents read again). The deleted records (#100014, #9660, company #832) were taken out of
  kop-data's data-audit, federal-funding and business-filings files by hand: none has a builder to rerun short of
  research agents (data audit, federal funding) or ~3 GB of fresh state downloads (Colorado + Florida `fetch`,
  which would also lose Florida's page links in the wiped `tmp/florida-sunbiz/pdfs/detail-urls.json`). What the
  pruned audit cards held is in docs/RESEARCH-TASKS.md ("Two Florida renames ...").
- **test-inline-edit**: `scripts/test-inline-edit.php`, 2 checks (same with and without 366c8d84): "31 earlier names
  filed as closed become renames" (#10401 -> Bryce Canyon Academy, #11201 -> Okeechobee Youth Development Center) and
  "52 company records round-trip unchanged" (#500017 keyStaff.keyExecutives.0.personId missing on one side).

Done 2026-10-20: the full test-review-inbox run passes (1,359 checks). program-practices: `doc-text.py restore`
(kop-pipeline 27a8d1c), its scratch table gained the `why` column, and its Undo / Leave / empty-Add checks look at the
card's own facility and topic (other sources' tests decide practice lines in the shared scratch copy). Deleted records
pruned from kop-data (da6664e). test-inline-edit passes (54): a row sent back unchanged gains no blank column it never
had and keeps its numbers (bf25d805); the era check asks for a year only when the earlier name gives one.

### 5. Tests failing on main: test-facility-pages, test-operator-pages, test-fornits (done 2026-10-20)
- **test-facility-pages** (2 checks): seeds/research-facility-tags.json named 11098, 12808 and 14160, merged on
  2026-10-16 into 11130 (St. Augustine Youth Academy), 12801 (Harbor Point, The Pines' later name: report name and
  page 85 added there) and 12800 (14160's entry was a duplicate of 12800's, dropped). 97a192c8.
- **test-operator-pages**: the nine companies converted into programs on 2026-10-09 (Beloved Ones, Center for Positive
  Changes, Chad Youth Enhancement Center, Dimondale, Heritage Youth Services, NeuroRestorative, New Hope Of Arizona,
  New Horizon Youth Homes, Pacific Autism Center) left seeds/operator-histories.json; each conversion row holds the same
  draft (hash checked), printed on the program page and restored on Undo. 03c5eed4.
- **test-fornits**: the batch's facility ids follow merges (kop_facility_merge_resolve() over the mirror's merge map),
  as kop_fmerge_follow_waiting() does on the server; 15 records of the Oct 2 batch were merged since. Run against a copy
  of `~/kop-import/fornits/batch-20261002-192403.jsonl.gz` with `--batch=`. e9f78c06.

- **Hourly task "KOP Fornits" fails every hour (exit 1)** since the 2026-10-17 wipe of kop-pipeline/tmp: the
  `tmp\fornits\` folder its `--log` writes to is gone. The crawl had probably finished by then (the batches on the
  server stop at `~/kop-import/fornits/batch-20261002-192403.jsonl.gz`). Owner's call: disable the task (`schtasks /change /tn "KOP
  Fornits" /disable`), or let it re-crawl: with `tmp/fornits/` present and no `crawl.done` it restarts the full crawl
  (about 19 hours) and, `processed.json` being gone too, sends every topic again as new batches. Do not create
  `tmp/fornits/` until this is decided; run test-fornits with `--batch=<a copy of a server batch>`.
  Owner, 2026-10-20: disable it. Disabled the same day (schtasks /change /disable); re-enable it only with a new crawl.

## 5. Tests failing on main, closure reports and facility discovery (finished 2026-10-20)

- **test-closure-reports**: 2 checks on the 2026-10-20 mirror, failing before the closure re-sort work too: "an untouched
  rewrite changes nothing but the schema upgrade" (kop_closure_rewrite_period() on a real document) and "a closed or
  rebranded name is never touched" (kop_network_map_status_overrides()).
  Passing in full by the end of 2026-10-20 with no test change: the code was wrong, fixed in 600857eb (a facility closed in
  the data no longer repaints its rebranded or closed old name) and the closure re-sort commits up to bd7b9923.
- **test-facility-discovery**: 3 checks on the 2026-10-20 mirror, failing before the vague-name rule too: "a genuinely
  new name is not a near duplicate", "new programs from the dry run stay new", "no record already has its name key in
  its state". Probably records created since the test's names were picked; check its names against the mirror.
  The samples went stale: the scan itself created Silver Oak Academy (#100185) and Walker's Point Youth and Family Center
  (#100184) on 2026-09-29, and kop_facdisc_near_duplicate() rightly finds them. Fixed in 40a7b516: the new name is made up
  (Larkspur Hollow Academy), and the two now check that a record the scan made is found again (Zion Hills Academy still new).

## 5. Tests failing on main, full run (finished 2026-10-20)

Full run of all 126 tests on the 2026-10-20 mirror (main at 95cde58d). Cause: *stale* = the sample changed in the
data, *code* = the code or the test's own harness was wrong.

- test-auto-connect: stale. The site's hourly pass has connected all it can; the test seeds 20 Woodbury pages, 50 Drive Docs ties and a second Rite of Passage company row (f07f18f1). Passes.
- test-citation-cleanup: test too broad. Woodbury Reports issues are news items since the SCIAD import (2026-10-12), so News coverage names the outlet, and a staff member's past job is at Woodbury Reports; neither is citation wording (a08d95f3). Passes.
- test-facility-eras: code wrong. kop_facility_eras_build() dropped a second item with the same words on the same record (Viewpoint Center's two identical incident lines cited to two wiki pages), so the cut page showed 6 of 7; it now drops only the same item on another record of the line (e789320a). Passes; facility-pages, operator-pages, name-eras, rename-merge, inline-edit still pass.
- test-indigenous-schools: stale. The move ran on the site 2026-09-30, so the four source records are gone; the test now checks what the real move left (86e9bc8b). Passes.
- test-inspection-rollup: stale. The mirror holds production's inspection_report_counts now; the check is that the mirror's counts are unchanged (f07f18f1). Passes.
- test-investors: stale. Company #500017 gained two investors (CIC Partners, Cressey & Company); the counts follow the entries found (447e0960). Passes.
- test-kop-tools-menu: test harness wrong. It stubbed only the 8 screens it knew in 2026-09; screens now count when the theme defines their function and adds their submenu (091022a3). Passes.
- test-name-under-operator: stale. 13678's past names were edited on the site ("Three Springs New Direction", "Three Springs Turning Point - Girls"), and Auldern Academy (#11939) gained "Three Springs of North Carolina", already a past name of #11903, so by the settle rule neither is listed under it. Expectations updated; which program carried the name is a new item in docs/RESEARCH-TASKS.md (283731c8). Passes.
- test-nc-inspection-cleanup: stale. The plan ran on the site 2026-10-06; the test checks what it left when no planned row remains (f07f18f1). Passes.
- test-network-renames: stale. The rename merge gave Copper Canyon 1998-2014 and Sedona Sky 2014-2025, so no overlap; 12 of 93 researched years in kop-data's network/rename-candidates.json are for lines no longer on the map (merged pairs), now counted, not failed (5b1d8185). Passes.
- test-news-archive: harness wrong. archive.js is read from the kop-send-to-kop checkout; the split copies every column but archive_url and skips the resync (2e870308). Passes.
- test-news-post-import: stale. The import ran on the site; the checks count its rows, and the row checks now cover them (56a561a5). Passes.
- test-open-data: environment. The portable PHP needs `-d extension=zip`; passes with it.
- test-operator-history-seed: stale. The Sequel rewrite is on the site already, so the seed reports "same"; accepted (596f211a). Passes.
- test-people: harness wrong + test too strict. The map builder moved to kop-pipeline (run from there now); Robert B. and Robert Walter Lichfield are rightly two ids (different middle names) (b697895a). Passes.
- test-post-text-fixes: stale. All eight fixes are applied on the site (kop_post_text_fixes_done); applied ones are checked as applied, the new text read as later fixes to the same page left it (88a3d4c0). Passes.
- test-program-homes-convert: stale. The site converted 15 companies on 2026-10-09; the test undoes them on its copy first, and compares documents rather than MySQL's JSON spacing (f7895df6). Passes.
- test-program-homes: stale. The site made #100284 Newport Academy on 2026-10-03, which the tool rightly reuses by name; the made-record case now uses a made-up name (62ebabd9). Passes.
- test-rename-merge: stale. Copper Canyon (#12155) was merged into Sedona Sky (#12161) as a rename on the site 2026-10-11; the test undoes that merge on its scratch copy from the merge log, and compares Sedona Sky's operator to its own pre-merge value (86e040d7). Passes.
- test-search-aliases: stale. Copper Canyon merged into Sedona Sky, Bethel Boys Academy moved to Eagle Point's past names, Three Springs of Osceola merged into Kissimmee Youth Academy; samples replaced (0d24c688). Passes.
- test-staff-former: stale. The pass ran on the site (4,299 roles), so the dry run found nothing; the test undoes it on the copy from the site's kop_staff_former_log first, and the clean-up check takes exactly four entries (it took more when one record had several) (94a0c295). Passes.
- test-state-feed-names: harness wrong. It now loads inc/heal-archive.php, stubs wp_upload_dir() and ignores @-silenced warnings (aa527fa0). Passes.
- test-state-lists: code gap. The State Lists import stored candidate ids as built, while the filings and federal funding queues follow merges; #100103 was merged since the export. The import now follows merges and the test loads the live merge map (08ac3ad5). Passes.
- test-submission-notify: stale expectations. f97643ac moved lawsuits and bills onto Submissions Review tabs; the fallback check hides the pages instead (aa527fa0). Passes.
- test-wiki-staff: stale. The Former pass ran on the site; the test runs the pass's own undo on the copy first (557 put back, 551 rewritten again) (df2b0293). Passes.
- test-woodbury-facts: test wrong. The corrected-staff check picked a record merged away since the facts file was built; it now skips those, as the main loop does (d8126f68). Passes.
- test-young-adult-move: stale. The move ran on the site 2026-10-01 and production has the ya columns; the test checks what the real move left (86e9bc8b). Passes.

## 4.4 Review inbox and admin screens, item 8: scratch copies left in %TEMP% (finished 2026-10-20)

   Found 2026-10-20: the mirror-copy tests (test-volunteer-projects, test-review-volunteers, test-review-inbox, test-seo-drafts, ...)
   leave their 200-450 MB %TEMP%\kop-*.sqlite copy behind on Windows (the shutdown unlink runs while the PDO is still open); 60 of
   them filled the C: drive today. Fix: close every handle before the unlink, or sweep kop-*.sqlite older than a day at the start.

Done: closing every handle cannot work (kop_seed_pdo() in inc/admin.php keeps the PDO in a function static), so
scripts/kop-test-harness.php, which every mirror-copy test loads right after making its copy, now (1) sweeps
kop-*.sqlite* in %TEMP% untouched for 2 hours (a peer's running test keeps its copy) and (2) on Windows starts a
detached PowerShell at shutdown that deletes this run's copy and its -journal once the process has let go of it.
Checked with test-investors: before, each run left its copy; after, none is left. The 2.3 GB left by finished runs
that evening was deleted (only copies whose PHP process had ended).

## 5. Tests failing on main, rerun (finished 2026-10-21)

1. test-media-subfolders: stale samples fixed (9493a271): the fixes' folders exist, documents 9609/9610/7600/8162... were deleted on the site, and subfolders with nothing left to file are not expected. **Still fails, on real data**: 36 of the 74 planned subfolders exist twice on production (consecutive ids, e.g. Evidence 7929/7930, Charles Britt Academy 7943/7944 under 252), with the same documents filed in both; the one-time subfolder pass ran twice at once before kop_maybe_apply_template_assignments() took its lock. Owner step: approve a clean-up on production that keeps the lower id of each pair and drops the empty copy's filings (back up wpdl_fbv and wpdl_fbv_attachment_folder first); the test then passes.
2. **Confirm the full rerun.** A rerun of all 126 on main at b697895a was started at the 2026-10-20 handoff; check
   that only test-media-subfolders and the five tests needing inputs (item 3) exit non-zero.

Done 2026-10-21. Item 1: on the owner's go-ahead, wpdl_fbv and wpdl_fbv_attachment_folder were backed up on the server
(~/backups/fbv-before-subfolder-dedupe-20261020.sql), then the 36 higher ids were deleted in one transaction with their
filings; every higher copy held only documents the lower one also held and had no child folders, so no document lost
a folder. test-media-subfolders passes on the fresh mirror.
Item 2: the rerun lost the mirror two thirds through: the harness change 314c4642 deleted $db_path at shutdown on
Windows, and about 30 tests use tmp/prod.sqlite itself as $db_path, so each run wiped the mirror (through the junction
into kop-pipeline/tmp). Fixed in 5f5977f1 (only a kop-* copy in %TEMP% is deleted); mirror rebuilt 2026-10-21 14:01.
The 18 tests that had failed were rerun: 17 pass; test-seo-drafts was stale (the site stored its 236 rule drafts)
and now undoes them on the copy first (3002693b). test-name-under-operator's earlier exit was Windows running out
of paging file, not the code; it passes alone.

## 4.7 Pages and templates, items 15 and 17: sideways scroll and the Subscribe button (finished 2026-10-21)

15. **Facility pages scroll sideways on desktop** (found 2026-10-20 while checking the sidebar float, already live without it):
    https://kidsoverprofits.org/facility/cedar-crest-hospital-rtc-tx/ at 1360 wide has a 1457px scroll width; no element in
    `body` reports a box past the viewport, so look for a transform, an off-screen tooltip or `100vw` width. The operator page is fine.
17. **MailerLite Subscribe button spills 6px at 1100 wide** (found 2026-10-21, not from the float: /open-data/ did it before the
    float, and /ca-reports/, which keeps Kadence's column, does it now): `python -u scripts/check-mobile-overflow.py --widths 1100
    /ca-reports/` reports `div.ml-button-horizontal.primary > button.primary` "Subscribe" 6px out. The newsletter form's
    horizontal row is too narrow for the button in a 338px column; let the row wrap or shrink the button padding in css/sidebar.css.

Done 2026-10-21 in [ee288bf7](https://github.com/kidsoverprofits/Kids-Over-Profits/commit/ee288bf7cf85296d8ffc0b19d616df8dd2373868). Item 15: the hidden source-preview tooltip
(`a[data-kop-citation-preview]::after`, css/citation-previews.css) was laid out all the time, only made invisible, and
up to 416px wide from the link's left edge, so a "source" link in the facility rail (x 1041) stretched the page to 1457px.
Hidden previews are now `display: none` (the fade kept through `@starting-style`), and the rail (960px and up) opens
them leftward (css/facility-profile.css). Item 17: above 400px the sidebar's MailerLite row is a flex row, the button
at its own width and the email field taking the rest (css/sidebar.css); under 401px MailerLite still stacks them.
Checked live after deploy: check-mobile-overflow.py --widths 1100,1360 is clean, with no sideways scroll, on the Cedar Crest,
Kolob Canyon, /ca-reports/ and /open-data/ pages (with --local also on 6 more facility pages and an operator page, and at
390/768); check-contrast.py --local passes; hovering a preview in the rail and in the body shows it inside the screen.

## 5. Tests failing on main, lawsuit 24 on a record v2 does not hold (finished 2026-10-21)

- **Lawsuit 24 (Struble v. Fountain, Bethel Boys Academy, Lucedale MS) was linked to facility 13930**, which is
  only in the frozen facilities_master, not in facilities_v2 or the merge map (link made 2026-09-24 by the
  4ba00597 seed). The live record is 100180 "Bethel Boys' Academy" (created 2026-09-29, Closed, holds lawsuit 185
  and 4 news links), which had no state or city. Found by test-facility-v2-readers on a copy of 2026-10-21. The
  owner confirmed 100180 is the same program; 7490219d2f95127018a3bbbe885f5efc242bbefd moved the link
  (seeds/lawsuit-updates.json version 2026-10-21, new `move_facility_links`) and gave 100180 Lucedale, MS
  (seeds/facility-records.json, template assignments 134). Checked live: the link is on 100180, the record is on the
  MISSISSIPPI page with no review flag, and https://kidsoverprofits.org/facility/bethel-boys-academy-ms/ shows the case.

## 4.3 Records, names and data quality, item 13: document folders for young adult programs (finished 2026-10-21)

13. **Document folders for young adult programs** (owner, 2026-10-21): the `young_adult_programs` table (made
    2026-09-30, `inc/young-adult-programs.php`) never got a document folder, so nothing can be filed under one and
    Woodbury Reports' "File under" leaves them out (`kop_rinbox_wb_record_kinds()`, `kop_rinbox_wb_record()` in
    `inc/review-inbox/woodbury-mentions.php`). Build:
    a. A `document_folder_id` column (bump `KOP_YA_DB_VERSION`; `kop_ya_install()` is a CREATE, so add an ALTER), made
       on demand as a company's is (`kop_wbc_existing_target()` / `kop_wbc_folder()` in `inc/woodbury-create.php`):
       add a `ya` branch there and to `kop_wbc_record_tables()` or beside it.
    b. The seven moved programs (Homelines, OPI, Legacy Outdoor Adventures, Fulshear, Cottonwood Tucson, Four
       Circles, Dragonfly Transitions; old facility ids in `kop_ya_moved_from_map()`, log in option
       `kop_ya_migration_log`) may still have their facility-era FileBird folders ("Woodbury Reports Mentions" and
       others). Find them on production (see the FileBird duplicate-folders memory: prefer the copy holding live
       files) and link them as each program's folder; nothing is moved or copied.
    c. Each program's documents on https://kidsoverprofits.org/young-adult-programs/ (its card, under "More about
       this program"), as facility pages list theirs; CSS checks per `docs/claude/css-checks.md`.
    d. Let every document filing screen take a young adult program: Woodbury Reports "File under" and "File under
       that record", Drive Docs, Media library document tagging (`docs/claude/documents.md`), and the "ya:<id>"
       token everywhere `kop_wb_clean_token()` / `kop_wb_file_many()` read tokens. Drop the "has no document folder"
       refusal.
    e. `docs/ADDING-CONTENT.md` (a table holding record ids) and `docs/DATA-FLOWS.md` for the new store; tests in
       `scripts/test-young-adult-programs.php` and `scripts/review-inbox-tests/woodbury-reports.php`.
    Progress (2026-10-21): a is done (https://github.com/kidsoverprofits/Kids-Over-Profits/commit/de58d1ed; the
    column is added the first time an admin screen loads after deploy). d is done: Woodbury
    (https://github.com/kidsoverprofits/Kids-Over-Profits/commit/2b0d2eee; Woodbury Reports cards also lose the generic
    "File under a young adult program" action, which added a fact line, not the pages; the one OPI card filed that way
    on 2026-10-13 keeps its Undo), the document pencil's "Move to"
    (https://github.com/kidsoverprofits/Kids-Over-Profits/commit/6b669e24), Drive Docs already took a program (check
    in https://github.com/kidsoverprofits/Kids-Over-Profits/commit/f006036a). c is done
    (https://github.com/kidsoverprofits/Kids-Over-Profits/commit/a38c8055), e is done
    (https://github.com/kidsoverprofits/Kids-Over-Profits/commit/7852214c). Check after deploy: an admin screen loads
    once (adds the column), then file one Woodbury page under a program and see it on
    https://kidsoverprofits.org/young-adult-programs/; run `python -u scripts/check-contrast.py young-adult-programs/`
    and `scripts/check-mobile-overflow.py young-adult-programs/` once a card has documents (checked so far with the
    block injected). b: production looked up read-only and
    waiting for the owner's go-ahead. Eight programs were moved, not seven: Austin Sendero Recovery (program 11, from
    facility 100441) too; it has no folder. Proposed links (program: folder): Homelines 3136 (IDAHO, empty), OPI 258
    (under Sequel TSI, 5 NATSAP profiles; 2403/2671/3546 are empty copies), Legacy Outdoor Adventures 3888 (UTAH, its
    Woodbury subfolder 8101 holds 2), Fulshear - The Ranch 4163 (3 NATSAP profiles + Woodbury 8085), Cottonwood Tucson
    5687 (ARIZONA, 2 NATSAP profiles; 2380 is an empty copy), Four Circles 193, Dragonfly Transitions 8228.
    **Owner question**: three moved programs still have a live facility record of the same name and place, and two of
    those pages show the folder the program would get: Four Circles Recovery Center 100254 (page shows 193),
    Dragonfly Transitions 100255 (Klamath Falls, page shows 8228), Fulshear Treatment to Transition 10612 (Needville,
    page shows 4162, its own Woodbury folder 8344; "Fulshear" 7789 under Embark holds its 2015-2017 NATSAP profiles).
    Are the three the same programs (move them too, `kop_ya_move_facility()`), or do the folders stay shared?
    Done 2026-10-21: a de58d1ed, d 2b0d2eee + 6b669e24 + f006036a + 2c448b78 (a best match that moved files on its
    program), c a38c8055, e 7852214c, b 525cd678 (https://github.com/kidsoverprofits/Kids-Over-Profits/commit/525cd678).
    The owner approved all seven folder links and moving the three second copies (Four Circles 100254, Dragonfly
    100255, Fulshear 10612, the last with its Treatment Magazine article as a program link); the pass ran on
    production 2026-10-21 (option kop_ya_twins_log): seven folders linked, three copies moved, 4162 linked to 4163.
    Six cards on https://kidsoverprofits.org/young-adult-programs/ list documents; contrast, phone width and bare
    text pass live. seeds/new-facilities.json can no longer re-add a moved program (the guard went in with 7490219d).

## 5. Tests failing on main, Bethel Boys' Academy names and the Eagle Point merge (finished 2026-10-23)

- Owner, 2026-10-23: Bethel Boys' Academy, Eagle Point Christian Academy, Gulf Coast Academy, Bethel Baptist
  Children's Home and Bethel Academy are one program at 2147 Mill St, Lucedale, MS; past names need no years or
  operator; the page keeps Bethel Boys' Academy. Shipped: e5d297467b4449229a02a508773f59eadf351b25 (past names,
  address, 1978-2008 on 100180), c0d00c600becd18d01716db24c56fd8afa0131b9 (seeds/facility-merges.json merges 13927
  into 100180; the merge seed gained `as_duplicate` for a pair whose names read as a rename),
  281b2a265c2296acf19bab16934367f825de3c37 (Eagle Point moved from other names to past names). Checked live:
  13927 gone, 38 news links and lawsuits 24 and 185 on 100180, /facility/eagle-point-christian-academy-ms/ redirects
  (301) to https://kidsoverprofits.org/facility/bethel-boys-academy-ms/. Undo is on Merge Duplicates > Merged.
  13926 Bethel Girls Academy (Petal, MS) was left alone.

## Plan Board pass 2026-10-25 (finished 2026-10-25)

- 2.1, Done (owner):
  **Share the inspection report folder** (2026-10-17). Share `My Drive/KOP Inspection Reports` as "Anyone
  with the link", then `php api/sync-inspection-archive.php apply` on the server and check the dry run lists
  index entries for every state. Until then the nightly sync drops the archive index of every scraper folder
  (old 3.3 item 6).
  Ask: Have you shared "KOP Inspection Reports" by link and run the archive sync?
- 2.1, Done (site check: the crontab runs list-unsilenced-files.php):
  **Monthly Unsilenced check cron** (2026-09-30). Add as is:
  `0 4 1 * * cd /home/kidsover/public_html/wp-content/themes/child && /opt/cpanel/ea-php82/root/usr/bin/php api/list-unsilenced-files.php restart --check --minutes=120 >> /home/kidsover/logs/unsilenced-check.log 2>&1`.
  On its mail, ask a session for the rebuild (old 2, item 10).
  Ask: Is the monthly Unsilenced check cron line added?
- 2.1, Done (owner):
  **News discovery crontab** (2026-10-13). (a) the 13:30 line loses `--no-facilities`; (b) the 01:30 line
  gets `--recency 30d` for one night, then loses it; (c) optional: drop `AI_PROVIDER=groq` from both;
  (d) optional: a third line at 07:30 (old 3.8, old 3.16). Read 2026-10-23: (a) and (c) are done, but the
  01:30 line still has `--recency 30d`, weeks after its one night.
  Ask: Have you taken `--recency 30d` off the 01:30 discovery line, and do you want (d)?
- 2.1, Done (owner):
  **PDF covers** (2026-09-29: 177 left).
  [regenerate-pdf-previews.php?apply=1](https://kidsoverprofits.org/wp-content/themes/child/api/regenerate-pdf-previews.php?apply=1)
  until it reports 0.
  Ask: Has the PDF covers tool reported 0 left?
- 2.2, Done (owner):
  **Scraper launcher window**: open it and confirm the size and Output pane look right.
  Ask: Do the launcher window's size and Output pane look right?
- 2.3, Done (site check: the four records to drop are merged away):
  **Merge Duplicates, Three Springs/Sequel** (keep first, earlier name second, as a rename):
  #100076 <- #9656 (or "Program and its academic program"), #100078 <- #9657, #13640 <- #100077,
  #11098 <- #9654. Check first: #9648 vs #9639 (Duck River); "Three Springs New Directions" on #100076 and
  #13678; "Three Springs of North Carolina" on #9637 and #11903 (old 3.7).
  Ask: Have you merged the four Three Springs/Sequel pairs as renames?
- 2.4, Done (owner: status set and published):
  **Provo Canyon School profile** ([post 10796](https://kidsoverprofits.org/wp-admin/post.php?post=10796&action=edit)):
  suspended or Closed? Then publish.
  Ask: Provo Canyon School: suspended or Closed?
- 2.6, Done (owner):
  **Accounts and builds**: an Expo account and `eas build --profile preview --platform android`; Apple
  Developer (99 USD a year) and Google Play (25 USD) only to publish; ship an EAS update or store build.
  Ask: Make an Expo account and a test build now, and the paid store accounts later?
- 2.6, Done (owner):
  **Admin mode on the phone** (update published 2026-10-25, app
  [41eb247](https://github.com/kidsoverprofits/kids-over-profits-mobile/commit/41eb247cad580eccc2a2ad2ce8a4ca5e59e33437)):
  close and reopen the app twice, then About > Reviewer sign-in > Sign in with WordPress > log in > Approve. The Admin
  tab appears with "Open the Review inbox". If the app still has no Map controls or Donate button after two reopens,
  the phone holds the 2026-10-07 APK: install the 2026-10-13 one
  (https://expo.dev/artifacts/eas/KakogI4zyflHk-87uGmOFzB61iMWbP8DSJJZZri_5Bk.apk) once.
  Ask: Did Sign in with WordPress work, and do the review queues work on the phone?
- 3, Done (owner):
  **Volunteer project**: at [Volunteer Reviewers](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-volunteer-reviewers)
   a 10-item serious-findings project for yourself; answer three in a private window, "Check their calls",
   Apply one; turn the test volunteer off.
- 4.1, Done (owner):
  **AI budget**: read AI Usage for each provider's real daily ceiling and set `KOP_AI_DAILY_GEMINI` / `_GROQ`.
- 2.4, Answered "please do all of that": moved to 4.7 item 17 as session work:
  **Site content** (old 2, items 11-15): hub editor content (the five recommendations); unpublish the dev
  posts `facility-form-test`, `data-organizer`, `data`, `data-analysis`, `arizona-adhs-inspections`; 301 the
  75 News posts to their feed entries; per lawsuit post, 301 or keep; deactivate Code Snippets snippet 6
  (OG Image).
  Ask: Your call on each of the five site content items?

## Plan Board pass 2026-10-25, second (finished 2026-10-25)

- 2.1, Answered "yes", done 2026-10-25: the three links made on the server through `kop_folder_links_link()`
  (undo: Unlink on api/link-folders.php):
  7. **Folders on no page** (2026-10-14). Link "Hyde" (19) to Hyde School (297), "ANASAZI" (214) to Anasazi
     Foundation (5659) and "Agape Boarding School" (7815) to Agape Baptist Academy (4819) with
     `api/link-folders.php`, or move the files with the tile pencil.
     Ask: Link the Hyde, ANASAZI and Agape folders to their records?
- 2.2, Answered "yes", done 2026-10-25 in kop-pipeline
  [576a699](https://github.com/kidsoverprofits/kop-pipeline/commit/576a6999fb18908236a30ce5298164250d95279c) (26 pass):
  8. **`scrapers/test_scrapers.py` has 5 stale failures** (2026-10-20, all from before the CA/PA fixes in kop-pipeline
     `f22040c`): the CA payload test unpacks two values from `scrape()` (it returns three now), the Oregon date
     format, `smart_title_case` (two cases) and the data structure check. Bring the tests up to the code.
     Run them with `py -3`; plain `python` lacks `requests` and `pymupdf`.
     Ask: OK for a session to bring the five stale scraper tests up to the code?
- 2.4, Answered "yes", done 2026-10-25 in
  [e7cc3f1](https://github.com/kidsoverprofits/Kids-Over-Profits/commit/e7cc3f1e28ea16976989676aa3362db20d11cc66):
  5. **Article type labels**: print the list's labels ("Lawsuit Article") on /news/, state and country pages
     instead of the raw value?
     Ask: Show readable labels ("Lawsuit Article") instead of the raw value?
- 2.4, Answered "reviews, residential treatment", done 2026-10-25 (wording saved, 118 pages; 4.7 item 9):
  9. **SEO keyphrase wording**: which words people search for. Facility name alone, or with "abuse" / "reviews";
     "troubled teen" vs "residential treatment" for state hubs. Blocks 4.7 item 9. Answer it in the "Keyphrase wording" box at
     KOP Tools > SEO Descriptions (https://kidsoverprofits.org/wp-admin/admin.php?page=kop-seo-drafts), e.g. `{name} abuse`. (People are settled: name +
     role or employer.)
     Ask: Facility keyphrase: name alone, or with "abuse" / "reviews"? State hubs: "troubled teen" or "residential treatment"?
- 2.4, Answered "one per case": moved to 4.6 item 15 as session work:
  3. **Family Foundation School lawsuits** (~33 federal suits, 2026): one lawsuit record per case, or one for
     the litigation listing every docket (old 3.23).
     Ask: Family Foundation School: one lawsuit record per case, or one for all of it?
- 2.4, Answered "investors as their own map kind, leave the other stuff pending": investors to 4.3 item 19, the rest
  to 4.3 item 20 (a review screen first):
  6. **Network map judgement calls** in `tmp/network-qa.md` (rebuilt by every build); the six curated aliases
     naming another era (#13927, #10602, #11361, #11462, #11355, #12621); investors as their own map kind
     reading the Investors table, or added by hand.
     Ask: Your calls on the map QA list, the six aliases, and how investors get on the map?
- 2.1, Answered "no, give me a full link"; nothing left to run, archived 2026-10-25: the server shows both passes
  already applied (option kop_staff_unconfirmed_log holds 226 records; both dry runs find 0 entries to change).
  2. **Staff "Former" passes** (2026-10-17, both with exact undo, dry runs done): on the server
     `php api/fix-staff-unconfirmed.php apply` (2,651 entries on 226 open programs) and
     `php api/fix-wiki-staff-former.php apply` (29 wiki entries on 21 records).
     Ask: Have you run the two staff "Former" passes on the server (dry runs are done)?
- 4.3, done 2026-10-25 (redirects live in https://github.com/kidsoverprofits/Kids-Over-Profits/commit/e0e2ad9b2bbf1fc2eca57ab360388d3da06a6c06; folder link made on the server):
  13. **Young adult programs: what is left after the document folders** (2026-10-21; the finished item is in
      docs/PLAN-ARCHIVE.md, "4.3 ... item 13"):
      a. Done 2026-10-25: the moved records' old /facility/ addresses (name + state slug, other names, twin names, or
         the old record id) 301 to the program's card (`kop_ya_moved_facility_target()`, called by
         `kop_facility_pages_route()` before its 404; checks in scripts/test-young-adult-programs.php). After deploy:
         https://kidsoverprofits.org/facility/dragonfly-transitions-or/ lands on the Dragonfly card.
      b. Done 2026-10-25 (owner said yes): folder 7789 "Fulshear" is linked to 4163 in kop_folder_links (the server
         now counts 7789 among the program's folders).

## 4.3 Records, names and data quality, items 19 and 20: investors on the map and Map Questions (finished 2026-10-25)

Done 2026-10-25. Item 19: kop-pipeline's `scripts/build-network-graph.js` reads `wpdl_kop_investors` + `_links`
(addInvestors) and draws each investor as kind `investor`, linked to the companies and programs it held with the
stake's role and years on the line; the four investors drawn by hand behind Sequel keep their nodes and take the kind
(kop-data `network-overrides.json`). The theme shows INVESTOR under the name, a legend row, the drawer label and the
filter label (`canvas.js`, `filters.js`, `drawer.js`, `kop_network_map_label()`), and the hourly fingerprint
(`inc/network-rebuild.php`) covers the two investor tables; `build-network-map.yml` syncs them. Item 20: the build
writes its judgement calls (kind guesses, who bought whom, which record, loose matches, unplaced wording, rebrands with
both ends closed, memorial programs matching two names) to kop-data `network/network-qa.json` (writeQaQuestions), and
KOP Tools > Map Questions (`inc/network-questions.php`, also the Review inbox tab `map-questions`) shows them with the
six curated alias questions, a button per answer; answers are rows of `{prefix}kop_network_answers`, which the build
reads as overrides (readAnswers) and the fingerprint watches, and an alias answer changes the record at once with Undo.
Tests: `scripts/test-network-questions.php`, `test-network-rebuild.php` (investor cases), `test-kop-tools-menu.php`;
kop-pipeline `test-network-graph.js`. The owner's step is 2.3 item 18.

19. **Investors on the network map** (owner, Plan Board 2026-10-25: "investors as their own map kind"). The map build
    (kop-pipeline `scripts/build-network-graph.js`) reads the Investors table (KOP Tools > Investors) and draws each
    investor as its own node kind, linked to the companies it held with the years on its row; the legend, filters and
    `docs/claude/network-map.md` name the new kind, and the hourly rebuild fingerprint (`inc/network-rebuild.php`) moves
    when an investor row changes.
20. **Network map judgement calls need a review screen** (owner, 2026-10-25: did not know what the old 2.4 item meant;
    it pointed at a build file nobody can open). Two lists wait for the owner's word: (a) the rows the map build had to
    guess at (`tmp/network-qa.md`, written by kop-pipeline's build in Actions, gitignored); (b) six curated aliases that
    may name another era of their record: Bethel Boys Academy (#13927, since merged into 100180), Laurel Ridge Treatment
    Center (#10602), Agape Boarding School (#11361), Refuge of Grace (#11462), Change Academy at Lake of the Ozarks
    (#11355), Cleo Wallace Academy (#12621) (background: PLAN-ARCHIVE.md "Owner pass on curated aliases"). Put both on
    one KOP Tools review screen, a card per question in plain words ("Is X a past name of Y, or its own program?") with
    the evidence and a button per answer, then add an `Ask:` item in 2.3 pointing at it.

## 4.3 Records, names and data quality, item 20, and 4.6 item 13: the kop-data half of investors, map years rerun (finished 2026-10-26)

Done 2026-10-26. kop-data https://github.com/kidsoverprofits/kop-data/commit/9086719 (merged onto the bot's three
hourly rebuilds, then https://github.com/kidsoverprofits/kop-data/commit/8d8af9a onto its next one): graph.json 2075
nodes with 7 investors, layout.json, network-overrides.json (the four Sequel investors `"kind": "investor"`) and
network-qa.json (218 questions for Map Questions); test-network-graph.js and test-network-layout.js pass. The module
suite's failures were compared against the last graph without investors (kop-data f649268): none comes from the
investors; they moved to 5-tests-failing.md item 6. Years: https://github.com/kidsoverprofits/kop-data/commit/f360397,
`years-candidates.json` from results 1-12, 215 names (116 dated on the map since dropped; 142 opening years, 44 closing,
61 nothing found; 18 of the 64 rerun names changed), `scripts/test-network-years.php` passes. They wait at KOP Tools >
Map Years (2.3 item 6).

20. **Investors and Map Questions: the kop-data half is not pushed yet** (2026-10-26). The build changes are in
    kop-pipeline https://github.com/kidsoverprofits/kop-pipeline/commit/945a6a6 and the theme half is on main, but the
    rebuilt `network/graph.json`, `layout.json`, `network-overrides.json` (the four Sequel investors retyped
    `"kind": "investor"`) and the new `network-qa.json` are not committed in kop-data. Until they are, the map draws no
    investor and Map Questions shows only the six alias cards. Steps: (a) `node scripts/test-network-modules.js` failed 5
    checks on the new graph (best-connected node lost its label; WWASPS has no drawn label; opening view puts 9 names off
    the stage; Provo lines long, median 674px; David Gilcrease has 6 connections, not 5). Run it on the previous graph
    to see which are new: in kop-data `git show HEAD:network/graph.json > network/graph.json` (and the same for
    layout.json), run the suite in the theme (about 12 minutes), then rebuild as in (c). (b) Fix any failure the investor nodes caused (the 7 new nodes and lines
    near Sequel, Paradigm, InnerChange are the only graph change). (c) From kop-pipeline: `node
    scripts/build-network-graph.js && node scripts/build-network-layout.js && node scripts/test-network-graph.js && node
    scripts/test-network-layout.js`, then commit + push the four files in kop-data (or press Rebuild now at KOP Tools >
    Map Rebuild, which does the same in Actions now that the workflow syncs the investor and answer tables).
13. **Network map undated names** (rerun 2026-10-26 on the 64 still undated in graph.json, a larger search budget):
    the results are in kop-pipeline `tmp/years-research/result-9.json` to `result-12.json` (gitignored; batches
    `batch-9` to `batch-12`); about 19 got an opening year, 4 a closing year, about 41 are open today with no
    opening year found. 140 sources found on the way were sent (9 already held, 1 refused). Left to do: from
    kop-pipeline, with kop-data's real graph.json in place, `node scripts/build-years-candidates.js` (writes
    kop-data `network/years-candidates.json`), check it with `php scripts/test-network-years.php` in the theme, commit +
    push it in kop-data; the new years then wait at KOP Tools > Map Years (2.3 item 6). Jemison Group Home's 1999 rests
    on KOP's own Three Springs timeline only.

## 2.4 item 1: held facts (finished 2026-10-26)

1. **Held facts**: raise the write-up batch (3 drafts an hour; AI budget `KOP_AI_BACKGROUND_SHARE`) or not;
   and which other writers count as "facts" for the hold rule (company/consultant filings, young adult facts,
   wiki staff on approval, Rules from documents, federal funding, Drive Docs links, data audit) (old 3.31).
   Ask: Raise the write-up batch above 3 an hour? Which other writers count as facts?
   Decided 2026-10-26 (owner): direct edits stay on the page at once and also feed the write-up; only records that
   change get write-ups; 6 drafts an hour. Built in commit 74f4fb46175791a352033ebea850609529af82bc
   (https://github.com/kidsoverprofits/Kids-Over-Profits/commit/74f4fb46175791a352033ebea850609529af82bc).

## 5. Tests failing on main, item 6: test-network-modules.js on kop-data 8d8af9a (finished 2026-10-26)

6. **`node scripts/test-network-modules.js` fails 5 checks on today's graph** (2026-10-26, kop-data 8d8af9a; the
   suite takes ~50 minutes on the laptop, not 12). None come from the investor nodes: the same run on the last graph
   without them (kop-data f649268) fails the same way, except where noted. (a) WWASPS's label is not drawn in the opening
   fit (zoom 0.19, 34 labels; Cross Creek Programs, Bell Academy or Dace Goulding wins the spot). It fails on both graphs
   in a probe that stops at the check, but passed in one full run, so it depends on timing. (b) The opening view puts 9
   names more than a row off the stage (Youth Services International, Correctional Services Corporation, Rite of
   Passage...), and the WWASPS 28-connections check prints a failure for "0 of 28". (c) Provo Canyon School's view lines
   are long: median 674px, 15% within 260px (bound 600px, 12%). On f649268 it was 571px: the only change in that view is
   the staff line Galumalemana Alema S. Fitisemanu II - Provo Canyon School leaving the live records, so it is data
   drift on a bound written for 504px. (d) David Gilcrease has 6 connections; the check expects the report's five.
   (e) On f649268 only: "the typical line turns 3 times" (2 on today's graph). Decide per check whether the data or the
   layout is right and fix the other; for (a) make the check not depend on frame timing.

Done 2026-10-26: the full suite passes on 8d8af9a (exit 0, ~50 min). Per check: (a) the renderer is right. WWASPS (32)
gives way to Cross Creek Programs (34), and WWASPS is not the best-connected name, so the check now asks that every dot on
the stage lost its room to a name at least as connected. The tap-a-name check taps the best-connected label drawn
instead of WWASPS. The probe was not timing-dependent: the first frame draws the same each time. (b) Data growth: the
opening view went from 32 names (ce810fa, 2026-10-12, passing) to 47 in 15 rows, which cannot fit 700px at full size.
The check now allows rows straight below the stage with no gap, the owner's 2026-09-23 rule. The WWASPS "0 of 28"
failure was the 0.75-width distance bound from the day before full-width rows. All 28 are on the stage, 844px corner to
corner, so the bound is now the stage width (as its comment said) and the message names which bound failed.
(c) Data drift: bound 600 -> 720px, written down above the check. Follow-up: plan item 5.6. (d) The data is right:
the sixth is a staff line at Cross Creek Manor for Girls from its staff list, so the check asks for the report's five
by name. (e) f649268 only, passes on 8d8af9a.

## 5. Tests failing on main, 2026-10-28 (finished 2026-10-28)

### Item 1, test-drive-docs follow-up
Follow-up: have the test follow the merge map the way
     test-fornits does, and check that the Drive Docs screen moves SCIAD rows for a merged record to the record it
     was merged into rather than dropping them.
Done 2026-10-28 (d32e03c6): the test resolves ids through kop_facility_merge_resolve() with the copied
`kop_facility_merged_into` option, as test-fornits does; "#id missing" went from 9 to 2 (#100255, #9660, deleted, not
merged). The real Drive Docs path does not drop merged rows: kop_fmerge_follow_waiting() moves pending rows after each
sync in the Review inbox queue and hourly. Two gaps went into the plan file's item 3.

### 2. **test-review-inbox.php --source=news: "Take off removes the consultant's note exactly" fails** (found 2026-10-21, also
   with the session's own changes set aside, so it predates them). The run also prints "kop extension: lawsuit facility
   links failed: ... near "COMMENT"" (a MySQL column COMMENT in a CREATE on the SQLite copy). Look at
   `kop_rinbox_native_unconnect()` in `inc/review-inbox/native.php` for consultant notes.
   Done 2026-10-28 (1a5f1180): not the theme code. The mirror stores Upwell Advisors' consultant notes with bare
   slashes and kop_wbc_change_notes() re-saves through wp_json_encode(), which escapes them, so the byte compare
   failed with the notes unchanged. The check now compares decoded JSON. The COMMENT error is separate (plan item 3).

### 3. **test-review-inbox.php: reader-accounts and wiki-updates fail in the full run** (found 2026-10-25 on a clean copy of
   main at 3f179304 against the 2026-10-23 mirror, so they predate the Review inbox tabs work). reader-accounts: "a reply
   that is not JSON gets one more try", "a line ... is dropped (0 lines, 2 dropped)", "a name is taken out ...", and
   "Undefined array key 0" at `scripts/review-inbox-tests/reader-accounts.php:111`; wiki-updates: the three "site builds
   the script's draft" / "bold names line" / "names carry the years" checks find 0 drafts. `--source=reader-accounts`
   alone passes, so something earlier in the full run changes what it reads (order-dependent); start there.
   Closed 2026-10-28: not reproduced. A full run on HEAD against the 2026-10-27 mirror passes every reader-accounts and
   wiki-updates check (123 same, 63 named, 22 with years). Likely cause of the old run: on a clean copy of main
   js/data/ is a gitignored junction to kop-data, so kop_wiki_drafts_all() found no update-drafts.json (0 drafts).

### 4. **kop-pipeline test-ocr-repair.py: 2 of the fax lines fail** (found 2026-10-25; the same on the committed
   `pa_scraper.py`, so it predates OCR version 3). `scrapers/ocr_repair.py` leaves "klesing" and "unclolhed" instead of
   "kissing" and "unclothed" (fax page lines 2 and 5). Check whether `scrapers/pa_ocr_vocab.json` (rebuilt 2026-10-18
   by `scripts/build-pa-ocr-vocab.py`) lost those words or the swap rules changed.
   Done 2026-10-28 (kop-pipeline 5d7dbc17): not the vocab or the rules. wordfreq was not installed in C:\Python313,
   and ocr_repair.py's ImportError fallback gave every word zero frequency in silence. Installed it (it was already in
   requirements.txt); ocr_repair.py now warns on stderr without it and the test exits 2. 15 of 15 pass.

### 5. **test-network-embed.js: 5 of 703 slices differ from the map** (found 2026-10-25, the same on the graph.json before
   that day's investor build, so not new): aspen-education-group, suws-of-the-carolinas, provo-canyon-school,
   aspen-achievement-academy, asheville-academy-for-girls. Each embed is missing Aspen-side names the map shows
   (aspiro-group, boulder-creek-academy, bain-capital, passages-to-recovery...) and draws fewer lines (145 against 176
   for Provo), so the PHP slice (`kop_network_map_slice_opened()` in inc/network-map.php) and focus.js have drifted,
   most likely on how the cap's dropped connections or an owner's step are treated for names joined through a folded
   line. Run with `--only=aspen-education-group,provo-canyon-school` against the portable PHP; fix the PHP to match
   focus.js (never the other way), then the full run.
   Done 2026-10-28 (390880b1): the PHP had a rule focus.js lacks. kop_network_map_slice_opened() kept the 40 most
   important direct connections and dropped everything they brought; visibleIds() has no cap. Only those five names
   have over 40 connections. The slice no longer trims, `more` stays 0 (the caption said "N more on the full map"),
   and the test's size bound went from 120 to 150 KB (Provo is 135 KB). 702 of 702 slices match.

### Second list, item 3. **test-facility-discovery.php: 3 checks fail on main** (found 2026-10-28, the same on unchanged HEAD): "known
   names ... closure programs are added" (Three Points Ranch), "a name decided before is not asked again", "a name held
   for want of a place is asked again". Likely mirror drift (Three Points Ranch now resolves); fix the fixtures.
   Done 2026-10-28: not Three Points Ranch. The fixture's "Silver Oak Academy" has had a record since the scan created
   it (#100185), so it resolved and dropped out of the list. The three checks use a made-up name (Larkspur Hollow
   Academy); all pass.

## 4.7 Pages and templates, items 7 and 17 (finished 2026-10-28)

7. **Admin stylesheets** still use about fifty accent colours as text (16B).
   Done 2026-10-28 (0979c0d272ef98a60b055c7abb47e0a5d1018446): every brand accent used as text was already an -ink token
   (likely a20373d0); two non-brand 3.3:1 text colours fixed (admin-data-manager.css .dm-wiki-suggested #7a5b15,
   admin-submissions.css .url-safety-icon #1b7e3c). plugin-contrast.css:147 chartreuse text is on the dark drawer, kept.
17. **Operator year-by-year timeline runs under the rail** (found 2026-10-25, live,
    https://kidsoverprofits.org/operator/universal-health-services/ at 1536 wide): `ol.kop-ptl-years` is a block box,
    so it spans the full 805px body under the floated rail (item 16's wrap) instead of stopping at it like the text.
    The axis line and its 2026 dot cross the rail's "Know something we do not?" text, and the list sits on top of the
    rail's Submit info button (elementFromPoint at the button's centre is the `ol`, so it can't be clicked there).
    Fix: give the timeline its own block formatting context (`display: flow-root` / `overflow: hidden` on `.kop-ptl`)
    so it narrows beside the float, or start it below the rail (`clear: both`); then check facility pages, which use the same rail.
    Done 2026-10-28 (5db4bb70f19bc07866ee7507e128c79bd20806da): `display: flow-root` on `.kop-ptl`. Checked live with the
    file injected on UHS and san-marcos-treatment-center-tx at 1536/1100/768/390: Submit info is clickable, no overlap,
    no overflow; UHS timeline 517px at 1536. check-contrast and check-bare-text clean.
