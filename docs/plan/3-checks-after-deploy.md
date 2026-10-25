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
23. **Volunteer messages and research projects**: open
    [Volunteer Reviewers](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-volunteer-reviewers) once (makes
    `kop_vol_messages`, `kop_vol_answers` and the projects' new `detail` column); make a small "When did these programs
    close?" project for yourself with a state, open its link in a private window, answer one with a link (it appears
    waiting in Submissions Review > News), ask a question, answer it under Messages (the menu shows a count, an email
    arrives), and see the reply marked New on the volunteer page. Mark the answer Used, then close the project.
24. **"+ Add new..." in dropdowns**: on [Investors](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-investors) pick
    Kind > "+ Add new...", type a test kind, Add: it is selected and appears at
    [Dropdown Lists](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-choice-lists&list=investor-kinds); Undo it
    there. Also the Status dropdown on the admin lawsuits and legislation pages and the Type dropdowns on Woodbury Facts.
    Selects not fed by a registered list (publication status, jurisdiction, filters) deliberately have no such option.
25. **Links to our own pages in the text** (inc/content-links.php): logged out, a news post (e.g.
    [Provo probation](https://kidsoverprofits.org/man-on-probation-after-hitting-student-at-provo-residential-treatment-center/)) links its
    facility and state in the text and ends with "Related on Kids Over Profits" on a sand panel; the
    [Sequel TSI](https://kidsoverprofits.org/operator/sequel-tsi/) history and a facility page's About link other records;
    [Wiki feed](https://kidsoverprofits.org/wiki-feed/) titles link to facility pages with an "Also on" box under each.
    First load after deploy builds the name list (~5-10 s, once per 6 h); `check-contrast.py` on one post and the wiki
    feed; `check-mobile-overflow.py` at 390. Look for a wrong link on 10 posts; report any to the session for a rule.
26. **Volunteers' agreed calls switch**: [Volunteer Reviewers](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-volunteer-reviewers#kop-vol-auto)
    shows "Let agreed calls go through on their own" as Off; the Volunteers table's agreement rates are unchanged. Turn it On only
    when you want it; the first ones show under "Gone through on their own" with Undo in Recently done.
27. **Drive Docs news waits for review** (2026-10-20, [587a4532](https://github.com/kidsoverprofits/Kids-Over-Profits/commit/587a4532)):
    in [Review inbox > Drive Docs](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-review-inbox) Add one news link; it shows waiting in
    [Submissions Review > News](https://kidsoverprofits.org/admin-submissions/), not on the site; within an hour or two the reader fills its summary and
    date; approve it there, then Undo the Add on a second one. The 8,561 news rows auto-connect approved on 2026-10-14..16 were taken back by
    `api/undo-auto-drive-news.php apply` (soft-deleted, links back to waiting in Drive Docs, reviewed_by "undo of auto news (2026-10-20)").
    Open: the owner works through those links again in Drive Docs (SCIAD NET source filter), or skips them in bulk.
    Ask: The 8,561 taken-back Drive Docs news links: work through them again, or skip them in bulk?
28. **Desktop sidebar sticky column** (2026-10-21, docs/plan/4.7 item 16): at 1280+ wide, open a post (any
    https://kidsoverprofits.org/ blog post), https://kidsoverprofits.org/ and a facility page: the newsletter and Donate sit in the
    side column, not in the body, and stay in view (below the sticky header, not under it) while scrolling to the bottom.
    https://kidsoverprofits.org/wp-content/themes/child/js/sidebar-float.js should 404 (deleted by .cpanel.yml). Under 1025 the sidebar follows the content.
    Run `python scripts/check-bare-text.py` and `python -u scripts/check-contrast.py`.
    On https://kidsoverprofits.org/facility/provo-canyon-school-ut/ at 1100+, the About text runs full width once the At a glance
    rail ends (it was a 381px strip the whole way down), and About comes before Survivor testimony and History as before.
29. **Research Tasks screen** (2026-10-23, [1ecf8452](https://github.com/kidsoverprofits/Kids-Over-Profits/commit/1ecf8452)):
    open https://kidsoverprofits.org/wp-admin/admin.php?page=kop-research-tasks (the first visit makes the tables and imports
    seeds/research-tasks.json): 140 open tasks in 23 sections, KOP Tools lists it under Review queue, a record finder on
    Add a task and Edit. Then give one task to a test volunteer (Send to a volunteer), answer it on their page, reload:
    the card is Answered with the answer and links; Undo of the send closes the project.
    Ask: Research Tasks is up with the old list's 140 questions; give some to Claude or a volunteer to try it?

30. **Volunteer page chooser** (2026-10-25, [b5acfd97](https://github.com/kidsoverprofits/Kids-Over-Profits/commit/b5acfd97)):
    signed in as admin, open https://kidsoverprofits.org/volunteer-review/ : "Choose your work" shows four cards
    (inspection findings marked Most needed, research questions, social media posts, photos), buttons off in the
    preview, and no queue opens by itself. The queue fold lists only Violations.
