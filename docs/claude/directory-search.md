# Directory, search and forms

Commands, tests and rules for the directory and its editor, alternate names, name eras, the public data form and wizard, finders, search, site map, facility-suggest, testimony moves. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# The directory's company cards (KOP Tools > Directory Editor, inc/directory-editor.php): per company, edit the card's fields (a rename keeps the old
# name and its /operator/ address, option kop_dired_redirects), take the card off (operator.directoryHidden: the feed and js/tti-program-index.js skip
# it, the company page stays), delete, merge into another (every kop_record_field_company_refs() row moves, names join), add / take off / move
# programs (current, past, other; a moved program's currentOperator follows); {prefix}kop_directory_edits logs each with exact Undo.
# "Every field" / "Edit program" open inc/inline-edit.php's dialogs from buttons (data-kop-edit-open, KOP_INLINE_EDIT.noBar)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-directory-editor.php [--list]   # every action + exact undo on a scratch copy of the company tables
# The directory's Alternate names table (below the companies, js/tti-program-index.js setupAlternateNames(): sort by heading, search/letter
# filter): admins tick names of one record and fold them into one (inc/directory-names.php, kop/v1/directory-names/fold|undo; log option
# kop_dirnames_log, exact Undo) or open its names dialog (facility:<id>:names, operator:<id>:names)
php -d extension=mbstring scripts/test-directory-names.php
# Its "Run by then" column (admins: Set company): which company ran a program under which name, and when ({prefix}kop_name_eras,
# inc/name-eras.php, kop/v1/name-eras/save|undo, exact Undo). Wins over the guesses: the directory's name under a company
# (kop_name_eras_name_under() in kop_v2_get_facilities_projects()), the facility page's "Formerly" years; not read by the map build yet
php -d extension=mbstring scripts/test-name-eras.php
# Survivor Testimony "Move to testimony" in the admin data form (js/data-form/testimony.js): every copy of a
# moved note can be cleared ("Remove copy"), moved text leaves the fields the public pages list
node scripts/test-testimony-move.js
# Public data form (/tti-data-submission/) opens on a guided start (js/data-form/data-wizard.js, css/data-wizard.css): find the
# record or add a new facility/company/provider/transporter/referrer, pick topics, see only those sections; saves still go
# through submitSuggestion(). Topic -> section ids in TOPICS there; ?find=<name>, ?add=<type>, ?full=1. Its text inputs need
# data-growing-text-field="true" or ui-events.js swaps them for textareas
python scripts/test-data-wizard.py               # live page + working-tree wizard, 1280 and 390 px
# The data forms' Download JSON / Copy JSON (buildFormExport() in js/data-form/utilities.js) export each facility as its v2
# document (KOP_DataNormalizer.facilityToV2(), docs/FACILITY-SCHEMA.md) without provenance or legacy; the rest of formData as is.
# A blank form exports the full field set; the file loads back through normalizeProjectData() (facilityFromV2())
node scripts/test-data-form-export.js
# The form modules move to v2 one per commit (docs/plan 4.3 item 17 c): facility fields through kopFacilityField() /
# KOP_FacilityFields (end of js/data-form-modules/data-normalizer.js), by v2 path on either shape. Each commit: the live public form with
# the working-tree js/ against the live js/, every field and window.formData the same, and the moved functions giving the same on a
# facility and its v2 document
python scripts/test-data-form-equivalence.py [--projects 30] [--html v2] [--v2-render]   # --html v2: the page with the templates' v2 paths
# (run both: LiteSpeed serves cached HTML with the old paths for a while after a deploy); it also types into the place fields
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-form-v2-save.php   # the server stores the same from a v2 entry as from its legacy copy
python scripts/test-provider-index-v2.py      # /mental-health-providers/ reads providers in either shape
node scripts/test-move-to-new-state.js        # admin form's Move to a new state, legacy as before, v2 as its normalized form
node scripts/test-report-generator-v2.js copies.jsonl   # the form's report: legacy as before, v2 the same, 400 real facilities
# /questions/ ("We have questions", inc/questions.php + templates/questions.php + js/questions.js + css/questions.css): one short
# question at a time, phone first, about what a record lacks (open?, opened, closed, town, boys/girls, ages, until when an earlier
# name was used, each unticked practice, "was <staff> there when you were? year", remembered rules; companies: founded, based,
# operating?, owner) and Review inbox > Conflicts as multiple choice. Yes/no cards swipe (right yes, left no). Found by search or a
# state deck; #f<id> / #o<id> / #q=<key> open a record. Answers: {prefix}kop_question_answers, one per person (keyed hash of address +
# browser), 60 an hour. KOP_Q_AGREE (3) people agreeing with nobody disagreeing settles it on its own through Review inbox > Answers
# to our questions ('settle', reviewer "questions page", Undo in Recently done); staff years always wait, and so does an answer whose note
# is hedged (kop_q_hedged(): "not sure", "I think", "not physically"...). A "No" to a practice or a staff member never settles
# (kop_q_is_no(), owner 2026-10-25: practices change over time): only-no questions sit under Collecting no answers and come to Waiting,
# flagged, at KOP_Q_AGREE people. Facility values go in as
# Woodbury Facts rows (extra.via 'questions', badged "Questions page" there and in Conflicts, kop_wbf_origin()) through kop_wbf_apply(), so they are held for the write-up and conflicts wait as usual;
# name years through kop_name_eras_save(); companies through kop_dired_edit(); conflicts through kop_rinbox_conflicts_act().
# Email is optional and only for "tell me when my answers are checked" (the page says so): kop_q_notify() puts the reader's answers
# in one {prefix}kop_question_batches row (status from kop_q_batch_refresh()), and inc/submission-followup.php kind 'questions' sends
# the receipt and one email when all are decided, then deletes the address. No newsletter box on this page.
# Owner rule (2026-10-20): never tell readers that agreeing answers settle a question (page text, tour, toasts, emails)
# Facility and company pages carry "We have questions about <name>" (kop_q_box_html(), js/questions-box.js): an empty hidden section
# filled from kop/v1/questions/record when the page opens (the pages are cached whole), up to four questions linking to
# /questions/#f<id>:<key> (#o<id>:<key>), which opens that record's questions with that one first
# The data form's wizard (steps 1-2) and its full-form bar end with "Short on time? Answer a few questions instead"
# (questionsLine() in data-wizard.js, URL from window.kopWizardConfig in inc/enqueue.php); for a picked facility or
# company it links /questions/#find=<name>, which fills that page's search box
# Guided tour: the /questions/ block in js/tool-tutorials.js (card steps point at '#kop-q-deck ...' and show centred when no deck is open)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-questions.php                         # questions, answers, agreement, every real record
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=questions   # the queue, settle + Undo, dismiss, deck
# Free text (owner, 2026-10-23; inc/reader-accounts.php): remembered rules (f<id>.rules) and every note on a facility answer are read hourly
# (cron kop_ra_hourly, KOP_RA_BATCH 8, kop_ai_generate_alternating(), job "Readers' answers read into lines") into rows of
# kop_program_practices with answer_id = the answer: topic, our plain line, the reader's words (must be in the answer, else dropped;
# spelling fixed; names -> [name]), year, checklist boxes. Emails, phones and links are cut before the AI sees it (kop_ra_redact()).
# Reviewed one card per answer at Review inbox > What readers remember (never volunteers): Add -> facility page section "What people
# who were there remember" (kop_ra_section_html(), cited "A former resident (2010-2012), answering on Kids Over Profits' questions page,
# October 2026" from their How do you know? or the AI's role); opinions stay on the card; contact offers, court cases and other
# programs named are the Leads tab. A reader's new answer drops their old one's waiting lines (kop_ra_forget()). Not in the app or wiki yet
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=reader-accounts   # reading, redaction, card, section, Undo, leads, hedging
# Admin facility id boxes: always kop_facility_finder_field() (inc/facility-finder.php), never a bare id input
php scripts/test-facility-finder.php              # search by name/past name/id against tmp/prod.sqlite, no bare id boxes left
# Facility directory (/tti-program-index/, both tabs) loads in steps: lists from kop/v1/facilities?view=index (each facility
# cut to names/place/years/status/report counts by kop_directory_feed_slim_facility()), a company's or place's full records when
# opened (?view=detail&key[]=), file cached in uploads/kop-cache/directory/<fingerprint>/ (inc/directory-feed.php). A field the
# closed lists read goes in kop_directory_feed_slim_facility()
python scripts/test-directory-feed.py [--refresh] [--cpu 4]   # split feed draws the same page as the whole feed, timings
# On the parent company tab a renamed program is headed by the name it had under that company ("Now known as" the record's name):
# kop_facility_eras_name_under() (inc/facility-eras.php) from its names' companies, stamped as nameUnderOperator per company link in the feed
# The /operator/ page's program list does the same (name then, "Now known as" beside it). A name carrying the company's own name
# ("Three Springs of Courtland", "Sequel TSI Courtland") wins over the map's dated names; every name a program had under that company is listed ('also': "Also called"), one carrying another of its companies' names too is left out; two programs never share one (_settle())
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-name-under-operator.php [--list]   # every company link on tmp/prod.sqlite, the feed
# Alternate names always show up in every search box and autocomplete: a facility's or company's pastNames, otherNames
# and currentName match, and the row says which, worded by kop_alias_label() ("Formerly X" / "Also known as X" / "Now
# known as X"). kop_v2_search() (header dropdown, search widget, search.php, facility-suggest, research tags) ranks from
# the cached kop_v2_alias_index() and keeps a third of the slots for alias hits; list filters use kop_v2_alias_match_ids();
# the admin finder, facility-search.php (Data Manager, news processor, wiki picker), the data forms' autocompletes
# (js/autocomplete.js alternateNameDetail()), the wizard and the map search carry the kind too. A new box does the same
php scripts/test-search-aliases.php               # against tmp/prod.sqlite
# Wherever companies and facilities share a list, each row carries a Company / Facility pill: kop_kind_pill() == kopKindPill() (inc/kind-pill.php)
php scripts/test-kind-pill.php                    # PHP == JS markup (needs node)
# Reader's site map (/site-map/, a route like /operator/, inc/site-map.php + templates/site-map.php + js/site-map.js): every public
# page by section (hubs with their articles from kop_article_parents(), placed by template in kop_site_map_template_sections(), admin
# tools/redirected/password pages never), posts by category, every /facility/ and /operator/ page A to Z, a "Find a page" filter.
# kop_site_map_quick_links() also fills the search popup before typing (js/global-search.js) and the 404 page (404.php: "Did you mean"
# from the address's words). A new page template gets a section there. /sitemap/ 301s here; XML sitemaps stay Yoast's
# How to use this site (/how-to-use-this-site/, a route too, inc/how-to-use.php + templates/how-to-use.php): the main destinations grouped by
# what a reader came to do (kop_how_to_use_groups(), hand-picked: add new main pages there) and how to search; linked from the footer and the site map
php scripts/test-site-map.php [--out=tmp/site-map-preview/site-map.html]   # placement, A to Z, cache, 404 helpers, PHP == JS filter folding
# Search results link to the record's own page (/facility/, /operator/; wiki entries and inspection rows to the matching
# facility page in the same state), falling back to the state hub or directory search: kop_search_v2_result_url() and
# kop_search_record_page_url() in inc/ajax-search-lite.php, used by the dropdown, the search bar and search.php
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-search-links.php
# Facility names in free-text lists (inc/facility-suggest.php): kop/v1/facility-suggest feeds the data forms'
# 'facilityref' autocomplete (ttiReferrals, knownReferrals, facilitiesReferred; reuses kop_v2_search); kop/v1/facility-links
# resolves names to /facility/<slug>/ for "Refers young people to" on /mental-health-providers/ (one batch, ambiguous never links)
# kop/v1/mobile/suggest?q=&kinds=facility,operator (kop_mobile_suggest()) feeds the app's autocomplete: name and alternate-name hits only,
# each {kind, id, name, hint, place, status, slug, url}, facilities before companies
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-facility-links.php   # prints the Billings list's linked/ambiguous/unmatched names
node scripts/test-facility-ref-split.js           # the forms split a pasted comma list into entries and mark names that will not link
```
