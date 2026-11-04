# Data flows: what a change has to reach

[ADDING-CONTENT.md](ADDING-CONTENT.md) lists what to connect when a new *kind*
of thing is added. This page covers the other half: when an existing record
changes (a lawsuit is approved, a wiki entry is saved, a company is renamed),
every place that shows it has to follow. When one doesn't, the facility page,
the state hub, the app and the wiki say different things, and nobody notices.

Mapped 2026-10-13 from the code. File:line references are as of that date. The
fix plan is in [PLAN.md 3.17](PLAN.md).

Contents:

1. [How freshness works today](#1-how-freshness-works-today)
2. [The map, store by store](#2-the-map-store-by-store)
3. [Where it drifts](#3-where-it-drifts)
4. [The fix: one change event](#4-the-fix-one-change-event)

---

## 1. How freshness works today

- **Pages are built on every request.** `/facility/`
  (`kop_facility_page_data()`, inc/facility-pages.php:2798) and `/operator/`
  (`kop_operator_page_data()`, inc/operator-pages.php:533) query the tables
  each time. Only the index (which records get a page, their slugs and
  signals) is cached.
- **There is no "record changed" event.** `kop_facility_save()`
  (inc/facility-store.php:2057) is the one writer for facility documents. It
  fires nothing and flushes nothing. The only hook, `kop_facility_status_changed`,
  is fired by about 15 callers, and only the network map listens to it.
- **Each cache works out freshness on its own** from a *fingerprint* of live
  tables, mostly `COUNT(*)` and `MAX(updated_at)`.
  `kop_facility_pages_fingerprint()` (inc/facility-pages.php:354) is the main
  one. The state feed, the directory feed, the mobile ETags, the network map
  slices and the site map all build on it. Two consequences follow:
  - A change that leaves the counts alone goes unseen. Examples: a news
    article approved, a company link switched from current to past, a
    report edited in place.
  - A table missing from the fingerprint is invisible.
- **An accidental 10-minute flush hides much of this.** The legacy
  `kop_facility_v2_sync` cron still fires every 10 minutes
  (inc/facility-v2-sync.php:28). Its listeners delete the facility index
  and network map transients. Caches built on files have no such flush:
  the state feed (no max age at all), the directory feed (6 hours) and the
  inspection rollup.
- **`kop_facility_v2_request_sync()` does nothing since the write switch.**
  save-master.php:94, admin.php:2481 and save-wiki-submission call it as if
  it refreshed something.
- **LiteSpeed caches pages in front of all of this.** Only inline edit,
  doc types, doc placement, featured reporting, the glossary, page text and
  program-homes-convert purge it. Every other writer relies on the
  LiteSpeed TTL.
- **Links between records are written when a record is saved,** never
  re-checked afterwards:
  - `kop_sync_lawsuit_facility_links`, `kop_sync_news_facility_links`
  - `kop_sync_lawsuit_news_links`, `kop_sync_news_lawsuit_links`

  A write path that skips the sync leaves the link wrong until someone runs
  `api/backfill-lawsuit-*-links.php` by hand.
  The two facility syncs also write each mention's settled id back into the
  row's `facilities_mentioned` (`kop_mentions_fill_ids()` /
  `kop_mentions_save_ids()`, 2026-11-04), so a bare shared name keeps the link
  it had; `api/fill-mention-ids.php` did the rows saved before.

## 2. The map, store by store

Each entry gives what writes the store, how it is tied to a facility, what
reads it, and what keeps each reader fresh. "Live" means queried on every
request.

### Facility record (`facilities_v2`)

- **Writes.** All of these go through `kop_facility_save()`:
  - the data form (api/save-master.php)
  - suggested-edit approval (api/lib-suggested-edits.php:740)
  - inline edit (inc/inline-edit.php:853)
  - Woodbury Facts, Fornits, federal funding and data audit (all via
    `kop_wbf_save()`). Since 2026-10-15 an approved Woodbury Facts item or
    Fornits staff/incident/lead is only held (`status = 'held'`) and written
    on when the record's write-up is approved (`kop_wu_publish()`,
    inc/record-writeups.php), whose text replaces the notes-built About in
    `kop_facility_page_data()`
  - a record made by a Woodbury card's Create (`kop_wbc_create_facility()`) is unlisted until its write-up is
    approved: `kop_wu_hold_new_record()` adds it to option `kop_wu_unlisted` (no page in
    `kop_facility_pages_index()`, so none in links or the sitemap; the fingerprint carries the list) and queues
    its write-up; `kop_wu_publish()` releases it. The directory, search, API and open data do not read the
    option yet. A company's write-up (`kop_wu_operator_key()`, row key 2e9 + operator id) replaces the
    notes-built About in `kop_operator_page_data()`; nothing drafts company write-ups yet
  - closure reports, Drive Docs, State Lists, Facilities from News,
    program homes, seeds, and the Data Manager

  These write the table directly instead:
  - people sync (inc/people.php:754, 919, 944; no lock)
  - merge undo (inc/facility-merge.php:730)
  - the young adult move (`kop_ya_move_facility()`; also deletes the
    record's news links: a deleted article's, or one it lists on the
    program when asked)
  - program homes undo (inc/program-homes.php:679)
  - record to company (inc/record-to-company.php: deletes the row, its Undo
    re-inserts it)
  - record to a related category (inc/related-move.php: deletes the row, its Undo
    re-inserts it)
- **Readers:** facility page (live), facility index, state feed, directory
  feed, alias index and search, mobile API, open data (daily), network map
  (hourly fingerprint, then the GitHub build), people sync (hourly), the
  inspection rollup, and the "on file" review lists.
- **Fresh by:** `MAX(updated_at)` in most fingerprints. That column is
  `ON UPDATE CURRENT_TIMESTAMP` with one-second resolution.

### Companies (`kop_operators`) and links to them (`kop_operator_facilities`)

- **Writes:**
  - the data form: direct UPDATE/INSERT (inc/facility-v2-writer.php:547-593)
  - `kop_v2_rename_operator()` (:787)
  - KOP Tools > Directory Editor (inc/directory-editor.php): company edits, hide, delete, merge,
    program links and moves; each calls `kop_record_changed()` for the operators and facilities it touched
  - data audit `operator_field` / `operator_link`
  - inline operator save, woodbury-create (:1175),
    `kop_v2_add_operator_other_names()`, standardize-facilities (:135)
  - `kop_apply_operator_record_seeds()` (seeds/operator-records.json, template pass): new companies, notes, and
    `fix_fields` (one field corrected only while it still holds the wrong value; sets `updated_at` and calls
    `kop_record_changed('operator')`)
- **Readers:** facility page (live), operator page, state feed cards,
  directory feed, mobile, search, network map.
- **Fresh by:**
  - Operator index: `kop_operators` COUNT/MAX + **link COUNT only**.
  - Facility fingerprint: link COUNT only, and **no `kop_operators` at all**.
  - Directory feed, inspection rollup and network rebuild do include
    `kop_operators`.

### Location placements (`kop_facility_locations`)

- **Writes:**
  - `kop_facility_rebuild_memberships()`, which runs only when the
    location block changes
  - direct inserts and deletes: writer:616-699, state-lists:603,
    facility-discovery:941, federal-funding:224
- **Readers:** the state feed and the directory feed
  (`kop_v2_collect_programs`, facility-v2-readers.php:280, 465).
- **Fresh by:** nothing. The table is in no fingerprint.

### Lawsuits (`lawsuits`, `lawsuit_facility_links`, `lawsuit_news_links`)

- **Writes.** The full path is api/save-lawsuit.php:250 (facility sync, news
  sync, FileBird filing). Paths that skip some or all of that:

  | Path | Skipped |
  |---|---|
  | `kop_ext_insert_lawsuit()` (inc/source-submissions.php; the extension, the app, full=1 + publish=1 as admin files it published, resynced and logged for Undo) | none when published; the admin email when pending |
  | `api/lib-publish-approved.php:47` (one-click "publish approved") | every sync and the document filing |
  | `manage-submissions.php:895` update_status | every sync |
  | `lib-record-enrich.php:514` (AI fills case name, number, plaintiffs) | the news sync |
  | `inc/lawsuit-updates.php:109` (seed updates, including `facilities_mentioned`) | every sync |
  | `data-manager-extra.php:723` doc_move (source_urls) | the news sync |
  | delete, `manage-submissions.php:850` | link rows are left behind |
- **Tied to facilities by:**
  - `lawsuit_facility_links` (sync owns `link_type='mentioned'` rows only)
  - name matching at render on `facilities_mentioned`
    (facility-pages.php:3399), `kop_state_related_name_key` (rest-api.php:5626),
    and defendants/organizations on operator pages (operator-pages.php:774)
- **Readers:** facility and operator pages (live), state hub (feed cache),
  mobile, open data (links only), `/lawsuits/`, wiki gaps.
- **Status filter disagrees:**
  - Facility pages, operator pages, mobile, `/lawsuits/`: approved + published.
  - State and country hubs, open data: published only.

### News (`news_submissions`, `news_facility_links`)

- **Writes.** Two full paths: api/save-news-submission.php:349/405, and
  `kop_ext_insert_news()` (inc/source-submissions.php:484), which the
  extension, app, Fornits and Drive Docs use. Paths that skip a sync:

  | Path | Skipped |
  |---|---|
  | `manage-submissions.php:895` update_status | case matching |
  | inbox Undo (`inc/review-inbox/native.php:326`), after a reject has deleted the case links | the case links stay gone |
  | move-to-school (native.php:425), review-destinations (:215) | case matching |
  | `data-manager-extra.php:653` (article type to/from 'lawsuit') | case matching |
  | archive split (`api/lib-news-archive.php:152`) | case matching; the image is not reset |
  | update_fields (`manage-submissions.php:1236`) | story group, story arc, journalist |
  | duplicate merge (`api/manage-duplicate-articles.php:73`) | the dropped copies' facility, case, bill and featured links do not move |
  | hard deletes (`manage-submissions.php:850`, `manage-duplicate-articles.php:111`) | orphan link rows |
- **Tied to facilities by:** `news_facility_links` only. There is **no name
  fallback** at read time. A mention that didn't resolve when saved links
  later two ways, both hourly with the Facilities from News run: the scan
  links a name the AI matched to a record we hold (provider and agency names
  included), and `kop_facdisc_relink_resolved()` resyncs live articles whose
  names now resolve exactly to a record or company they are not linked to
  (one created or renamed since). It holds back an article naming a record
  that another record repeats with a "(...)" ("Lakeside Academy" beside
  "Lakeside Academy (MI)"), because the exact match always takes the bare
  one; a save still links it (open, plan 4.3 item 22).
  A company's name resolves to its operator id, which the sync writes to
  `kop_operator_links` (link_kind 'news'; lawsuits the same), never to
  `news_facility_links` (2026-10-28; before that 382 such rows sat there, read by no page).
  A mention that carries a `facility_id` is filed on the record that id lives in now
  (`kop_facility_follow_moves()`: merges, moves to a related category, moves to the young
  adult programs; lawsuits the same, 2026-11-02). Before that the Send page filed two
  articles on #100175 a day after it became a young adult program; a one-time repair
  (`kop_fmerge_maybe_links_follow_moves()`, log in option `kop_fmerge_links_follow_log`)
  moves links filed on moved ids before then.
  The state hub JS matches by stored name instead (js/state-page.js:837).
- **Readers:** facility and operator pages, mobile, state hub, search
  (`facilities_mentioned LIKE`), story groups, featured reporting, news
  images, closure scan.
- **Fresh by:** the facility fingerprint counts link rows only. Approving,
  rejecting or editing an article does not move it.

### Bills (`legislation`)

- **Tied to facilities:** not at all. `facilities_affected` is free text.
- **Tied to states:** an exact match on `jurisdiction`.
- **Readers:** state and country hubs (feed cache, COUNT/MAX in its
  fingerprint), `/legislation/`, open data.

### Wiki entries (`wiki_submissions`, `wiki_master`, js/data/reddit-wiki/)

- **Writes:**
  - editor save (api/save-wiki-submission.php): **every Submit inserts a
    new row** (:433)
  - approve/publish (manage-submissions.php:452-697)
  - link/confirm/unlink: api/link-wiki-facility.php, data-manager.php, the
    Wiki links queue, auto-connect
  - draft approval (inc/wiki-update-drafts.php:682)
- **Wiki to facility:**
  - Approval runs `kop_merge_wiki_into_facility()`
    through `kop_wiki_merge_submission()`, from `kop_resync_after_status()`.
    It fills blank fields.
  - Since 2026-10-13 (owner's choice: straight to the record) it also puts
    the entry's staff in `staff.notableStaff` as name, role ("Former ..."
    when marked former), past jobs, and source "r/troubledteens wiki"
    linked to the Reddit page. A name the record already lists, in any
    role, stays as it is.
  - Each approval's additions are logged in option `kop_wiki_staff_log`.
    Rejecting or un-approving takes off exactly those
    (`kop_wiki_staff_undo()`).
  - Accreditations go to `accreditations.current`.
  - Before that the merge never ran on the live site at all: it read the
    entry from `wiki_master`, a table that does not exist there (the
    approval's upsert into it fails quietly). It now reads the submission
    itself. Even where it ran, it wrote the old `staffMembers` shape, which
    the v2 save drops. `api/backfill-wiki-staff.php` merged the entries
    approved before the fix.
  - The facility page lists entry titles live (`kop_facility_pages_wiki()`,
    facility-pages.php:3483) and links to `/wiki-feed/?search=<current name>`.
- **Facility to wiki:** nothing on the server.
  - The gaps (`kop_wiki_upd_gaps()`) are worked out only by the offline
    scripts/wiki-gaps.php.
  - The editor never loads the record's content.
  - PLAN 3.12 step 6 (a daily "KOP has new information" badge) is not built.
- **Static copies:**
  - `programs-array.json` / `search-index.json` index the Reddit page URLs,
    not `facilities_v2`. The wiki editor's auto-linker reads them. Built
    2026-06-05 from December 2025 inputs.
  - `live-compare.json` and `update-drafts.json` are committed snapshots.

### Inspections

- **Writes:**
  - api/inspections-write.php: upsert, or `replace`, which deletes the
    state and gives new ids (the NV scraper uses it)
  - the highlight scanner and review inbox
  - Inspection Links (option `kop_inspection_links`)
  - the hourly counts batch, highlight summaries, and the NC cleanup
- **Readers:**
  - facility page (live: name keys + Inspection Links + approved highlights
    + summaries)
  - `/xx-reports/` (per-state file cache)
  - state hubs (feed cache; **never read Inspection Links**)
  - inspection rollup (file cache), mobile, open data (daily)
- **Fresh by:** the facility fingerprint reads `inspection_facilities`
  COUNT/MAX(id) and `inspection_reports` MAX(id). In-place report edits,
  highlight approvals and summaries do not move it.

### Documents (FileBird, folder tags, folder links, doc types)

- **Which folder is a record's:** a facility's `documentFolderId` or the
  folder of its name (the facility index), a company's `documentFolderId` /
  `kop_operators.document_folder_id`, a directory record's
  `documentFolderId`, and `young_adult_programs.document_folder_id`. The
  last is written by `kop_wbc_existing_target('ya', id)` (Woodbury filing,
  the document pencil's "Move to": made under "Young Adult Programs" the
  first time), `kop_ya_move_facility()` (the folder the facility's page
  showed) and the moved-programs link (plan 4.3 item 13b). A folder that is
  gone is replaced on the next filing (`kop_wbc_live_folder()`).
- **Readers:**
  - facility page library (live)
  - /young-adult-programs/ cards (`kop_ya_documents_html()`, live under
    LiteSpeed: doc placement purges it, Woodbury filing waits for the TTL)
  - operator page counts (transient `kop_operator_folder_counts`, 1 hour,
    nothing flushes it)
  - `/documents/<type>/` (keyed by `kop_doc_types_rev`)
  - document archive landing (`kop_doc_archive_v5`, 6 hours, flushed on
    attachment add/delete only)
  - mobile (content hash, always right)
- **Fresh by:**
  - Doc types and doc placement flush and purge properly.
  - Folder moves, folder tags and folder links are in no fingerprint.

### People (`kop_people`, `kop_person_roles`)

- **Writes:** the hourly sync stamps `personId` into facility documents
  (direct writes, no lock) and rebuilds roles with DELETE + INSERT, outside a
  transaction. Merge People, Separate.
- **Readers:** facility page careers (keyed by fingerprint +
  `kop_people_version`), name variants, the network build.
- **Gap:** the mobile ETag leaves out `kop_people_version`.

### Network map (graph.json in kop-data, plus runtime overlays)

- **Build:** `inc/network-rebuild.php` hashes the facility columns plus
  identification/staff/operatingPeriod, operators, links, referrers, memorial
  counts and industry people hourly. When the hash moves, it dispatches the
  kop-pipeline workflow.
- **Inputs the hash misses:**
  - `legacy.mergedFacilities` (rename years)
  - `kop_person_roles`
  - every kop-data CSV/overrides file
  - js/network-map/store.js and js/vendor/d3-force.bundle.min.js

  A change to those waits for "Rebuild now".
- **Runtime overlays** (applied in PHP/JS, never in graph.json): Map
  Renames, Map Years, data-audit years, edge drops, status overrides.
  - `kop_network_map_facility_connections()` (network-map.php:397) feeds
    the facility page "Network" section and the app. It applies none of
    them, so a rename the owner flipped at Map Renames still reads backwards
    there.
  - Status overrides only ever force "closed", so reopening a facility
    waits for the next build.
- **After a merge,** connections stay on the dropped id until the next
  build.

### Homes, campuses, conversions, eras

- **Homes, conversions and campuses** each have a cache key in the facility
  fingerprint and are registered for merges. Fine.
- **Eras** are computed per request from graph.json, option
  `kop_network_rename_review` and the record. The directory and alias index
  include that option. The mobile ETag does not.

### Merges (`kop_fmerge_ref_tables()`, `kop_fmerge_json_tables()`)

Registered tables move with a merge. Tables that hold facility ids or names
but are **not registered**:

- `kop_woodbury_mentions.also_facilities` and facility-kind `target_id`
- `kop_facility_addresses.facility`: a name key, rebuilt only by
  manage-addresses
- `suggested_edits.master_id`
- option `kop_federal_funding_review`
- graph.json (until the next build)

A record that becomes a company (`kop_rtc_convert()`, seeds/record-to-company.json)
reads the same list: link rows (`kop_rtc_link_tables()`) are deleted, rows still
waiting for review are set to facility_id 0 with a `match_note`, decided rows keep
the id. `/facility/<slug>/` 301s to the company from option
`kop_record_to_company_log`, which also holds everything for Undo.

A record moved into a related category, such as Christian Rehabs for Adults
(`kop_rmove_convert()`, seeds/related-moves.json), becomes a new related record
(id above KOP_RELATED_FIRST_ID) with the same document and the folder its page
showed. Its company, news, lawsuit and nonprofit-filing links
(`kop_rmove_kept_tables()`) move to the new id; location, identity, address,
business and investor rows (`kop_rmove_dropped_tables()`) are deleted; waiting
queue rows are unmatched as above. `/facility/<slug>/` 301s to the related page
from option `kop_related_move_log`, which also holds everything for Undo
(`kop_rmove_undo()`). Test: `php -d extension=pdo_sqlite -d extension=mbstring
scripts/test-related-move.php`.

### Fact queues (`kop_woodbury_facts`, `kop_fornits_items`, `news_facts`): clear, fold, link (2026-11-02)

The three queues of facts waiting to be added to a record. Statuses: Woodbury Facts `pending, held, applied, rejected,
gone, merged`; Fornits `pending, held, applied, rejected, merged`; news facts `pending, kept, dropped, filed, merged`
(`filed` = a card on Woodbury Facts was made from it). **`merged` is new** and means "folded into another card".
Nothing here changes a record: `held` stays the only state the write-up reads (`kop_wu_held_rows()`).

- **Writes (inc/fact-fold.php, its own hourly event `kop_fold_hourly_event`, half an hour after the Woodbury hook, `kop_fold_hourly()`):**
  - *Clear* (`kop_fold_write_clear()`): a waiting item already on hand (record, held items, approved items of the other
    queues) goes to Woodbury `rejected` / Fornits `rejected` / news `dropped`, `reviewed_by 'auto'`, `applied`
    `{"reason": "Already on the record: ..." | "Held for the write-up: ...", "cleared": "on_hand" | "held"}`. A news fact
    keeps the reason in `kop_auto_connect` (source `news-facts-fold`; the table has no column for it).
  - *Fold* (`kop_fold_write_group()`): the members get `merged`; a Woodbury member also `extra.merged_into` + `merged_into_queue`
    and `applied {"merged_into": "woodbury:<pkey>"}`; a Fornits member `applied {"merged_into": "<queue>:<pkey>"}`; a news fact
    `route = 'merged'` and `wbf_pkey` = the kept card's pkey (its target also in the `news-facts-fold` mark). The kept
    card takes the citations: a Woodbury card's `evidence` list (entries tagged `folded_from`) and `extra.folded` (the list the
    card shows); a Fornits card's `applied.folds` (no `extra` column). A group whose best item would be a news fact is not folded.
  - *AI step (d)* (`kop_fold_ai_judge()`, after the rules, same run, live only on the hourly event): the close pairs the rules did
    not join (`kop_fact_close()`: waiting group reps against each other and against on-hand lines) go to one
    `kop_ai_generate_alternating()` call per unit (split past 40 lines), job "Fact fold: same in other words", which only judges:
    per waiting line `same_as <n>`, `differs <n>` or `new`. `same_as` an on-hand line = the clear above with reason
    "Already on hand in other words: <line>" and `how 'ai'`; `same_as` a waiting line = the fold above with `how 'ai'`;
    `differs` = it stays waiting with a Conflict mark on its card (Woodbury `extra.ai_differs`, kept by `kop_wbf_sync()`;
    Fornits `applied.ai_differs`; news facts a `kop_auto_connect` row, source `news-facts-ai`), not on the Conflicts tab (that tab
    settles record-vs-item values); a later answer that is not `differs` takes the mark off. `kop_fold_ai_apart()`
    (`kop_fact_differs()`, other kinds, two incident types) overrules any AI "same". Answers are remembered per line (option
    `kop_fold_ai_<md5 unit>`, autoload off: line keys sent together + verdicts), so only a line with a partner it was never
    sent with is asked; a unit whose AI part was put off (10 calls a run, the daily budget, the time box, a failed call) waits
    on `kop_fold_state.ai_todo` and is judged first on the next run, even during the 3-hour idle between passes.
  - *Link* (`kop_nf_link_waiting()`, on the hourly auto-connect as source `news-facts` and before the fold): a waiting news fact
    naming one record exactly gets `facility_id`; when `kop_nf_wbf_row()` makes a card for it the card is filed
    (`kop_nf_file_wbf()`) and the fact becomes `route 'facility'`, `status 'filed'`, `wbf_pkey`. What Undo needs is the
    `kop_auto_connect` note.
  - *Company link* (`kop_nf_link_companies()`, hourly auto-connect pass `news-facts-companies`, mark source `news-facts-company`, key = fact id,
    note `{oid, lines}`): a waiting `company` fact (or a `facility` fact that is no program's name) whose subject is exactly one operator's name key
    (`kop_operators`: name, current name, other names; distinctive; one holder) is filed as a cited note line on the company
    (`kop_wbc_add_notes('company')`, the way Woodbury Facts files on an existing company) and becomes `route 'company'`, `status 'filed'`
    (no `facility_id`, no card). Undo (Facts from news item, Recently done as source `news-facts`): `kop_nf_undo_link()` takes the line off
    (`kop_wbc_remove_notes()`, only the lines this link added), the fact is `route 'waiting'`, `status 'pending'`, `reviewed_by` the person; the
    mark becomes `undone`, so it is not linked again. `kop_rinbox_nf_act()` tries it before `kop_nf_undo_auto()`.
  - *Person link* (`kop_nf_link_people()`, pass `news-facts-people`): a waiting `person` fact whose name is a staff entry
    (`kop_dd_same_person()`) of exactly one record, which the article is linked to (`news_facility_links`) or the fact's words name exactly, and
    whose state is one the article is about (`kop_nf_article_states()`): `kop_nf_link_one()`, the same writes, `news-facts` mark and Undo
    (`kop_nf_unlink()`) as the *Link* above, with `how` = `person-article` or `person-named` in the note. Agency subjects never.
  - *State rule* (`kop_autoconn_exact_record(..., $states)`, from `kop_nf_record_for()`): an exact name whose record's state is none of the states
    the article is about (location, linked records, states named in the fact) is `'state'`, not linked.
  - *Woodbury Reports page link* (`kop_autoconn_woodbury_nomatch()`, pass `woodbury-nomatch`, mark and log source `woodbury-reports`, key = ckey):
    a pending `unmatched` page whose header is exactly one record's name (and the page's state, when it has one, is the record's) is filed with
    `kop_wb_file_many()` as any filed page; Undo is the page's own `kop_wb_undo()`, and the mark stops a second filing.
  - *Woodbury link* (`kop_autoconn_woodbury_facts()`, hourly auto-connect, source `woodbury-facts-link`, key = pkey): a pending
    Woodbury Facts item with `facility_id = 0` whose program is exactly one record's name (`kop_autoconn_exact_record()` 'exact')
    gets `facility_id`, `match_kind 'id'` and `extra.auto_linked` (what it was). `kop_wbf_sync()` keeps such a row as it keeps an
    edited one. Undo (Recently done, or the card) runs `kop_wbf_auto_unlink()`; the `kop_auto_connect` row stops a second link.
    Young adult and consultant items stay out. The fold reads the new record on its next pass.
  - *Fold rules (2026-11-03)*: an empty date never keeps tellings apart; alleged and found fold and the found one is kept
    (`kop_fold_pick()`); a closure order never folds or clears against a closure (also `kop_wbf_on_record()`).
  - *Places, roles, marks (2026-11-03)*: two tellings naming different places (`kop_dd_campuses()` with the record's name:
    "The Cleveland Seed" / "The Seed of St. Petersburg"; "Mount Hope" whole) never fold or clear each other, in the rules, the
    on-hand check and the AI override; `kop_wbf_text_same()` also needs the same full dates. A kept staff card stores each folded
    member's role and years (`extra.folded[].staff`, Fornits `applied.folds[].staff`), and `kop_wbf_doc_apply()` writes them as the
    person's role history (`kop_wbf_staff_with_folded()`); "III" / "Jr." / "Sr." never fold or merge with the plain name
    (`kop_dd_suffix()`). A Fornits card keeps `applied.ai_differs`, `applied.folds` and `applied.found` through approve and Undo
    (`kop_fornits_applied_carry()`). An alleged card whose only found telling is a waiting news fact stays, takes
    `extra.found` / `applied.found` + standing "found", and is written with the "alleged" cleared; `kop_fold_unfold_kept()`
    removes the mark when that news fact is undone.
- **Undo:** a card's action or Recently done: Woodbury `kop_wbf_undo()` / the inbox `undo` action, Fornits `kop_fornits_undo()`,
  news facts `kop_nf_undo_auto()`. A folded member goes back to `pending` with the person as `reviewed_by` (and
  `extra.fold_undone`), its citations come off the kept card (`kop_fold_unfold_kept()`, only while that card is still
  waiting or held), and it is never folded or cleared again. A cleared item uses the queue's own "Back to review"
  (`reviewed_by` becomes the person; for news facts too: `kop_nf_decide()` keeps the person on a fact the engine had cleared).
  Unlinking removes the evidence the link added from the Woodbury card, or deletes the card when the link made it and nothing
  else rests on it.
- **Readers of the statuses** (checked 2026-11-02): the inbox views and counts (`merged` is its own view and never pending),
  the old Woodbury and Fornits screens (a "Folded together" tab; the Woodbury page's generic back-to-review button), the
  on-file and conflict caches (pending only), the data audit count (pending only), `kop_fmerge_*` (every status moves with a
  merge, so `merged_into` stays right), the write-up (`kop_wu_fact()` cites `folded_from` sources and a Fornits card's folds).
  `kop_wbf_sync()` rewrites only `pending` and `gone` rows, never a `merged` one, and puts a kept card's folded citations
  and `extra.folded` back after a reload.
- **Known drift:** rejecting a kept card leaves its members `merged` (visible under Folded together, each with Undo); approving a
  kept Fornits card writes its folded citations into the record line, but a member undone after that no longer finds
  the card to take them off.

## 3. Where it drifts

Ranked by how often a reader would see it. "Fix" names the change, and the
step in section 4 that covers it.

1. **News and lawsuit changes miss the links and the caches.**
   - About 20 write paths approve, publish, edit or delete without running
     the link syncs (the two tables above).
   - The facility fingerprint and the mobile ETags count link rows, so an
     approval or a reject moves nothing.
   - *Fix:* status and content changes go through one function per table
     (step 2); the fingerprints read a revision counter (step 3).
2. **Approving a wiki entry drops its staff.** The merge writes staff and
   accreditations in the old record shape, and the v2 save drops them (see
   the wiki entry above). Separately, 11 facility records held a whole staff
   list saved twice (59 repeated entries; Bernalillo Academy 56 -> 28).
   *Fixed 2026-10-13:* `kop_facility_save()` folds repeated name + role
   entries (`kop_facility_staff_dedupe()`), and a one-time pass
   (`kop_apply_staff_dedupe()`) re-saved the 11 records. Test:
   scripts/test-staff-dedupe.php. *Also fixed:* wiki staff now go on the
   record, cited and undoable (step 5; scripts/test-wiki-staff.php).
   Approving them again would add 784 staff from 229 entries; that backfill
   waits for the owner (PLAN 3.17).
3. **Facility changes never reach the wiki.**
   - Closures, staff, operators, lawsuits and news show on the facility
     page; the wiki entry learns of them only when someone runs the offline
     gaps + drafts pipeline.
   - *Fix:* PLAN 3.12 step 6 becomes a listener (step 4).
4. **Company edits, company-link relationship changes and location
   placements move no fingerprint.**
   - The state feed (no max age) keeps them stale until any other facility
     edit or a deploy.
   - The app gets 304s.
   - *Fix:* step 3.
5. **LiteSpeed is purged by a handful of writers.** A cached `/facility/` or
   `/operator/` page can outlive every fingerprint. *Fix:* the event purges
   the affected URLs (step 1).
6. **The mobile ETags leave out several inputs:** inspection highlights,
   summaries, `kop_people_version`, `kop_network_rename_review`,
   `kop_network_years_review`, operator edits, news status. *Fix:* step 3.
7. **Rename flips are not applied to a facility's Network section** (website
   and app). *Fix:* apply `kop_network_rename_review` in
   `kop_network_map_facility_connections()`.
8. **`replace`-mode inspection imports orphan** Inspection Links,
   highlights, counts and summaries. In-place report edits are never
   recounted. *Fix:* remap by state + licence/name key after a replace;
   count reports by content hash, not by "has a row".
9. **The wiki store has its own drifts:**
   - Each editor Submit is a new row, so pages are counted twice and drafts
     land on an older row.
   - Link edits bump `updated_at`, so the "newest row" changes and Reddit
     compares come out falsely "changed".
   - Re-saving downgrades "confirmed" links to "suggested".
   - An operator rename orphans its wiki links (`kop_v2_rename_operator()`
     doesn't repoint them).
   - `/wiki-feed/` shows `original_markdown`, so approved updates of edited
     rows are hidden.
   - The facility page's wiki link searches the feed by the current name
     only.
10. **Records not followed on merge** (list above) and **the network
    rebuild hash misses** `legacy.mergedFacilities`, `kop_person_roles` and
    the kop-data inputs.
11. **Smaller:**
    - State hubs ignore Inspection Links.
    - `kop_operator_folder_counts` and `kop_doc_archive_v5` are not flushed
      by moves.
    - The news-image cache survives a URL change.
    - The people sync writes with no lock (a lost-update race with admin
      saves).
    - Merge undo writes unvalidated JSON.
    - Indigenous-school articles are filtered out of some readers and not
      others.
    - The `KOP_NETWORK_REBUILD_REPO_DEFAULT` constant still names the
      archived repo (inc/network-rebuild.php:34). The config sets the real
      one.

## 4. The fix: one change event

**Shipped 2026-10-13** (steps 1-3 for news, lawsuits and bills; test
scripts/test-record-resync.php):

- inc/record-events.php: `kop_record_changed()`. It bumps `kop_rev_<kind>`
  and purges LiteSpeed for the affected `/facility/` pages at the end of the
  request. `kop_facility_save()` fires it on every write.
- api/lib-record-resync.php: `kop_resync_news()`, `kop_resync_lawsuit()`,
  `kop_resync_after_status()`, `kop_records_deleted()` and
  `kop_news_fold_links()`. Every news and lawsuit path in the tables of
  section 2 calls them; the test checks each file.
- The first resync runs `kop_resync_keep_underived()` once. It retypes to
  'related' the 'mentioned' links the sync could not give again (61 news,
  8 case links on the mirror), so no page loses a link. Seeded case links
  are now typed 'related'.
- `kop_facility_pages_fingerprint()` (v:6) reads the revisions,
  `kop_people_version`, Map Renames and Map Years, `kop_operators`,
  `kop_facility_locations`, approved inspection highlights, and sums (not
  only the count) of company links. The operator index and the app's news
  ETag read them too. The state, directory and mobile caches build on the
  facility fingerprint.

The memorial (`memorial_victims`) fires `kop_record_changed('memorial')`
since 2026-10-23 from all three of its writers: the pencil's
`kop_ie_row_save()`, `kop_apply_memorial_seeds()` and KOP Tools > Memorial
sheet (inc/memorial-sheet.php, which saves through `kop_ie_row_save()`), and
each purges /in-loving-memory/.

Open: the operator and link writers firing the event, the drift check (6),
and the rest of the no-bypass test (7). The wiki listener (step 4) is done
(2026-10-14) but for its badge.

Today every reader guesses whether something changed. The fix is for every
writer to say so, once, in one place, and for every reader to listen.

1. **`kop_record_changed($kind, $ids, $info)`** in a new
   inc/record-events.php.
   - `$kind` is one of: facility, operator, link, location, lawsuit, news,
     bill, wiki, inspection, document, person, map.
   - Fired from the shared writers, not from each screen:
     - `kop_facility_save()`
     - the operator and link writers in inc/facility-v2-writer.php
     - `kop_facility_rebuild_memberships()`
     - the news/lawsuit/bill status functions (step 2)
     - `kop_wiki_upd_link()`, the inspection writer, `kop_dt_changed()`,
       `kop_dp_after()`, the people sync
   - Listeners:
     - bump the per-kind revision (step 3)
     - flush the index transients
     - purge LiteSpeed for the affected `/facility/` and `/operator/` URLs
     - mark linked wiki entries stale (step 4)
     - call `kop_network_map_flush()` on status changes (this absorbs
       `kop_facility_status_changed`)
2. **One status and content function per records table.**
   - `kop_news_set_status()`, `kop_lawsuit_set_status()` and
     `kop_bill_set_status()` own the UPDATE, the link syncs, the document
     filing and the event.
   - The ~20 paths in section 2 call them.
   - Deletes go through matching delete functions that remove the link
     rows.
3. **Revisions instead of counts.**
   - Option `kop_rev_<kind>`, bumped by the event.
   - `kop_facility_pages_fingerprint()`, the operator index, the state and
     directory feeds and the mobile ETags all read the revisions they
     depend on. The COUNT/MAX parts stay as a safety net for writes made
     outside WordPress (the scrapers, SQL by hand).
   - The dead `kop_facility_v2_request_sync()` calls go away. The 10-minute
     flush is kept only until the revisions are proven.
4. **Wiki staleness as a listener.** Done 2026-10-14 except the badge
   (`inc/wiki-auto-updates.php`, test scripts/test-wiki-auto-updates.php).
   - On a facility, lawsuit, news, operator or wiki event, the wiki entries
     linked to the affected records get `kop_wiki_upd_gaps()` re-run, in an
     hourly batch rather than inline; a sweep of every linked entry catches
     writes that skip the event.
   - Each new gap becomes a written line, waiting at Review inbox > Wiki
     updates from record changes. Open: the "KOP has new information"
     badge in the editor (PLAN 3.12 step 6).
   - Write-ups listen too (2026-10-26, `kop_wu_on_change()` in
     `inc/record-writeups.php`): a facility event, or a news or lawsuit
     event's linked facilities, marks the record changed (option
     `kop_wu_changed`) and puts it in Review inbox > Write-ups to approve; its
     draft is written from what the record says now. The write-up's own save
     (`'source' => 'writeup'`) does not mark it. Not yet: operator events
     (a company's facilities) and writes that skip the event.
5. **Wiki approval writes staff the way the record holds them.** Done
   2026-10-13, straight to the record with a log and Undo (owner's choice).
   `kop_v2_pdo_master_rows()` no longer keeps a stale copy after a save, so
   several entries of one program approved in one request no longer
   overwrite each other.
6. **A drift check that runs nightly,** reported at KOP Tools (a
   one-page screen, no files):
   - re-run the four link syncs in dry-run mode over every
     approved/published row and list what they would change
   - list facility ids held anywhere that no longer exist (merged or
     deleted)
   - list wiki entries whose gaps are not empty

   Anything it finds is a write path that skipped the event.
7. **A test that no writer bypasses the event:** scripts/test-record-events.php.
   - Grep for UPDATE/INSERT/DELETE on the watched tables outside the shared
     writers. The allowed exceptions are listed in the test itself.
   - On an in-memory copy of tmp/prod.sqlite, check that every reader's
     fingerprint moves after each kind of write.
