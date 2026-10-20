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
