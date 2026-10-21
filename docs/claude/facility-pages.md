# Facility and company pages

Commands, tests and rules for the generated /facility/ and /operator/ pages: notes, citations, eras, homes, campuses, operator hubs, merges and renames, investors, inline edit, deferred folds. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# Generated /facility/ and /operator/ (parent company) pages, against tmp/prod.sqlite
# Facility Profile posts listed in kop_facility_pages_merged_profiles() (hyde) print unchanged on their /facility/ page, the post 301s there
php scripts/test-facility-pages.php
# A record's research notes are printed as prose, each sentence with its "source" link, in an "About" section near the top of
# facility and company pages (inc/profile-notes.php; bookkeeping lines become "Also drawn on", rail facts and forum claims stay out).
# A citation with no address links our Woodbury issue copy, the wiki page or a HEAL capture another record gives (kop_facility_pages_cite_url())
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-profile-notes.php [--list] [--id=10347]   # rules on real lines, every record of the mirror
# Citations on those pages read as a small "source" link and nothing more: one that only points back to us (the network map, a page of
# this site; kop_facility_pages_is_own_source()) is not shown, and the Woodbury Reports wording ("Woodbury Reports, May 2007, p. 20") is
# not printed or put in a preview, only the link to our copy of the issue (kop_facility_pages_woodbury_clean(), _tidy_citations(), _cited_html())
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-citation-cleanup.php [--no-db] [--id=9607]   # functions, then real pages from tmp/prod.sqlite
# Facility pages show approved serious findings (inspection_highlights), staff with their other industry roles
# (other records' staff lists + network map), incidents as a timeline, and news cards with pictures:
# inc/news-images.php copies each article's share image (else the outlet's logo) into uploads/kop-news-images/, hourly cron
php scripts/test-news-images.php [--live=20]      # parser fixture; --live fetches real articles (needs -d extension=curl)
php api/fetch-news-images.php apply --limit=400 --minutes=25   # on the server (ea-php82): fill the backlog now
php scripts/test-operator-pages.php               # renders every /operator/<slug>/ page to tmp/operator-pages/
# Operator hubs (inc/operator-history.php): /operator/ lists every company (major = 5+ programs, a published history or
# a large map node); each page has a written history (operator.history, draft = admins only, published with the pencil;
# drafts come from seeds/operator-histories.json, which fills only empty records), a year-by-year timeline from the map's
# "operated" years + programs' years + lawsuits + deaths, the map's people, and the documents filed under its programs
(kop-pipeline) python scripts/build-operator-histories.py <slug> ...   # merges those drafts into seeds/operator-histories.json, which is the
                                                # source of truth (--export refreshes kop-pipeline's tmp/ drafts from it); bump
                                                # KOP_OPERATOR_HISTORY_SEED_VERSION to apply (replaces only drafts nobody edited)
php scripts/test-operator-history-seed.php      # fill, replace untouched drafts, keep edited/published, on a temp copy
# Homes and cottages of one program (inc/program-homes.php): each licensed home keeps its record; {prefix}kop_program_homes ties it
# to a program record (never a field in the facility document, which the form's normalizer would drop). KOP Tools > Program Homes
# suggests groups in one state from "Program – Home" names, then (kop_program_homes_prefix_groups()) numbered homes ("Echelon 1/3"), one
# name in several towns, "Program <X> House" names and 3+ records sharing a 2-word start (each card says why, filter by it); an admin confirms each (new or existing program record), Undo deletes
# a record it made if untouched. Program pages list homes + their news/lawsuits/serious findings; homes name their program;
# company pages fold homes under it. Directory, map and search still list homes one by one (docs/PLAN.md)
# Satellite homes of a main facility (names that share nothing): Program Homes > By hand picks the main record and each home; groups.satellite
# words both pages "Satellite homes" / "One of N satellite homes of" (switch per group on Grouped, which also has "Add a home")
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-program-homes.php   # suggestions, group Newport Academy CA, pages, undo, on a temp copy
# Parent companies that are one program (every record carries the company's own name, one state; inc/program-homes-convert.php): Program Homes >
# Companies that are one program converts one: homes grouped under a program record, operator.history printed there (pencil phistory:<id>),
# the company row deleted (saved whole in {prefix}kop_program_conversions), /operator/<slug>/ 301s to the program; exact Undo on the Converted tab
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-program-homes-convert.php [--list]   # converts every suggestion and undoes it on a temp copy
# Separate programs on one campus (inc/campus-programs.php; {prefix}kop_campuses + kop_campus_programs, one campus per record): not homes
# (no record over the others), closer than "Same operator". Program Homes > Same campus / Campus suggestions (one street address, one current
# company, never a rename or homes of one program), "Separate programs on one campus" on a homes suggestion, "Separate programs, one campus"
# on a Merge Duplicates pair (pairs on one campus leave that list). Pages: "On the <campus> with ..." + "Other programs on ..." ('campus', also
# in the app's payload), and they leave "Same operator". Every change logged with the campuses as they were (kop_campus_log): exact Undo
# A program and its academic program (its school) are a campus pair with role 'school' on the school (kop_campus_tie_school()): "Program and its
# academic program" on Merge Duplicates cards (both tabs) or Same campus by hand; pages say "Its academic program: X" / "The academic program of Y"
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-campus-programs.php   # suggestions, tie/join/rename/take off + undo, both pages
# Duplicate facility records (KOP Tools > Merge Duplicates, inc/facility-merge.php + facility-merge-match.php): pairs found
# automatically (same words, one word apart, spelling, company name in front, same street address; renamed programs never),
# merged with one click: doc fields join, every table/JSON/option pointing at the dropped id moves (kop_fmerge_ref_tables(),
# kop_fmerge_json_tables()), its FileBird library moves into the kept folder, its page 301s; exact Undo from the Merged tab.
# A new table holding facility ids goes in kop_fmerge_ref_tables(); files keyed by id read kop_facility_merge_expand_ids()
# Import loads (Woodbury facts.json, Fornits batches, Drive Docs links.json) write the build's old ids back: kop_fmerge_follow_waiting()
# re-points waiting rows of merged-away records after each load and hourly (a Fornits thread link gets the kept record's key)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-facility-merge.php [--list]   # real merges + undo on an in-memory copy
# Renamed programs are never merged as duplicates (a plain merge is refused): Merge Duplicates > Renamed programs (or "Merge as a rename")
# keeps the later name's record with its own years; the earlier name goes to pastNames and keeps its years, status, operator (moved company
# links become 'past') and notes on its legacy.mergedFacilities entry ('rename'), with the keys of what each record listed
# (kop_facility_eras_item_key()). The page cuts by those names (kop_facility_eras_doc_chain()) and an item stays with the name it was filed
# under whatever its date; the map build gives that name its own years; a closure dated in its years never closes the record
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-rename-merge.php [--list]   # Copper Canyon into Sedona Sky + undo on a scratch copy; --list = every renamed pair
# Investors (inc/investors.php, KOP Tools > Investors): one row per investor ({prefix}kop_investors, kind from Dropdown Lists 'investor-kinds'),
# one per stake in a company or program ({prefix}kop_investor_links: role 'investor-roles', years, detail, source), every change in
# {prefix}kop_investor_log (Undo puts back exactly that change while nothing changed it since). The pages' Investors line is
# kop_inv_fact_items() (a program names its companies' investors too, "of Sequel TSI"); free-text operator.investors /
# identification.investors entries print only while no link has their name, and wait on the screen's "Not linked yet" tab
# (the first admin load after the deploy linked those on file then, kop_inv_maybe_import()). Search (header dropdown, popup, search.php)
# finds them by name through kop_inv_search(): no investor page, so a hit opens the company or program it holds (or held last) a stake in
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-investors.php   # parsing, import, page lines, every write + exact undo, on a scratch copy
# Edit in place (inc/inline-edit.php, js/inline-edit.js): admins get a pencil on every marked element
# (kop_ie_attr('<source>:<what>')) that saves through the source's own save path; a new page or field gets a marker there
php scripts/test-inline-edit.php                  # every facility sent back unchanged is unchanged, edits land, against tmp/prod.sqlite
# Who runs it: identification.currentOperator, else the first company link (kop_v2_operators_for_facilities(), current links
# first, returns 'relationship'). When that link is 'past' the page, cards and JSON-LD word it as the former operator, never
# "operated by"; a new place that prints the operator reads $page['operator']['past'] (or the card's past_operators)
# One name shows once on facility and company pages: "Formerly", "Later called", "Also known as" / "Other names" never repeat a name
# between them (or inside one), nor the page's own title or "Now known as". Priority: Formerly, then Later called, then Also known as.
# Names compare by kop_facility_pages_name_key(); every new name list on a page goes through kop_facility_pages_dedupe_name_lists()
# (inc/facility-pages.php, used by the facility and operator page builders), never a hand-made in_array check.
# Closed folds on facility and company pages go out as <template data-kop-defer>, drawn when opened (inc/defer-folds.php,
# js/defer-folds.js fires 'kop:stamped'); a script that sets up pieces once listens for it. data-kop-nodefer keeps a fold whole
php scripts/test-defer-folds.php [--pages=<dir of saved pages>]
```
