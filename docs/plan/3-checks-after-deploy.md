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
