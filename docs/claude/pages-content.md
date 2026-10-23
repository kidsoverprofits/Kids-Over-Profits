# Site pages and content

Commands, tests and rules for reporting directory, glossary, hubs, page text, related industries, utility pages, Indigenous schools, young adult programs, marketers, trade orgs, open data, icons, images, pull quotes, URL labels, proper names, dropdown lists, robots, SEO, the program site scan. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# After editing the reporting directory data in js/data/reporting/
(kop-pipeline) node scripts/build-reporting-directory.js
(kop-pipeline) node scripts/verify-reporting-links.js          # slow, hits every agency site
(kop-pipeline) node scripts/pull-childusa-sol.js               # monthly: CHILD USA sexual-abuse deadlines, review the diff, then rebuild
php scripts/test-reporting-directory.php        # renders the page offline
# In Loving Memory (memorial_victims) against the owner's private "Death Toll" sheet: KOP Tools > Memorial sheet
# (inc/memorial-sheet.php; decisions + exact Undo in option kop_memorial_sheet_log). The site can't read the sheet: a new
# snapshot (read through gviz in the owner's browser) -> kop-pipeline tmp/memorial-sheet-diff.py -> the seed below -> deploy
(kop-pipeline) python scripts/build-memorial-sheet-seed.py   # writes seeds/memorial-sheet.json (sheet side only; the page side is read live)
php scripts/test-memorial-sheet.php             # seed vs tmp/prod.sqlite, tabs render, settle/add/skip + Undo, restraint guard
# The glossary is in SQL tables (inc/glossary-store.php), edited at KOP Tools > Glossary Editor; both tests import
# js/data/glossary/glossary.md into an in-memory SQLite copy (scripts/lib-glossary-test-db.php)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-glossary.php         # renders /glossary/ offline, checks every #link
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-glossary-store.php   # import == markdown build, add/edit/delete/undo, refs, sections
# Hub pages that list a category's posts (Editorials, Investigatory Spotlight; inc/hub-posts.php)
php scripts/test-hub-posts.php
# Every hub page through templates/page-hub.php + inc/hub-shell.php (per-hub settings), against tmp/prod.sqlite
# A hub links a page about institutional abuse OUTSIDE the TTI (Indian boarding schools) only in its boxed 'outside' block
# (kop_hub_outside(), headed and tagged as not the troubled teen industry), never among its own actions or reading list
php scripts/test-hub-pages.php
# Pages whose words live in js/data/pages/<slug>.json, edited at KOP Tools > Page Text
# (inc/page-text.php + inc/page-text-editor.php; e.g. /indian-boarding-schools/ and /faq/, both published)
php scripts/test-page-text.php
# Fixes to a published page's words ship as 'post' entries in seeds/text-fixes.json (exact swaps, all or none, once per 'fix'
# id, revision kept; kop_apply_text_fixes()); also fix the seeds/history/ copy. Never reseed an edited page
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-post-text-fixes.php   # every page entry applies once to the mirror's text
# Related industries OUTSIDE the TTI (foster care, adult disability group homes, nursing homes, psychiatric hospitals) and their
# /related-industries/ index: one shared template (templates/page-related-industry.php), text in js/data/pages/<slug>.json, Page Text.
# No facility records; linked from the History hub's 'outside' block. Migrant shelters, juvenile detention, PRTFs/RTCs are TTI, never here
# Utility and legal page templates: shortcode/share behavior, case metadata and image alt text
php scripts/test-utility-pages.php
# Live content with local utility/legal template CSS, screenshots at 390/768/1440
python scripts/preview-utility-pages.py --shots tmp/utility-preview
# Live hub frame preview at 390, 768 and 1440 px; defaults to all 12 hubs
python scripts/preview-hub-pages.py --shots tmp/hub-preview
# Indigenous residential schools (inc/indigenous-schools.php): their own records, never TTI facilities; listed on
# /indian-boarding-schools/ with their articles, managed at KOP Data Tools > Indigenous Schools (Move here, news scan names)
php scripts/test-indigenous-schools.php          # the first move and the page, on an in-memory copy of tmp/prod.sqlite
# Young adult programs (18+, inc/young-adult-programs.php): part of the industry (same companies, often the next step after a teen
# program), but kept in their own records, not facilities_v2; never grouped with the Indigenous schools as "outside the TTI"; listed on
# /young-adult-programs/, managed at KOP Tools > Young Adult Programs, filled from Woodbury Facts' "Young adult programs (18+)" tab
php scripts/test-young-adult-programs.php       # records, facts + exact undo, every no-record Woodbury item, the page
php scripts/test-young-adult-move.php           # the first move of 18+ facility records, on an in-memory copy (sync the mirror first)
#   ...and the second pass, kop_ya_maybe_link_and_move() (2026-10-21, log in option kop_ya_twins_log): the moved programs' facility-era
#   folders (kop_ya_folder_links()), then three second copies moved in (kop_ya_twin_moves(); kop_ya_move_facility() 'into' a program,
#   an article on the copy becomes a program link). seeds/new-facilities.json never re-adds a young adult program's name
#   (kop_apply_new_facility_seeds(); it brought Four Circles and Dragonfly back the night of the first move)
# Items still naming a moved record (kop_ya_moved_from_map(): source "Moved from facility record N", or option kop_ya_moved_into for a
# record moved into a program it did not make) are filed on the program; Woodbury Reports picks the program for one (kop_wb_moved_program()): Woodbury
# Facts moves them to its young adult tab, program picked (kop_wbf_moved_to_ya(), after every facts load and hourly); Fornits cards offer
# "Add to <program>" (kop_fornits_ya_file(), a fact citing the post; Undo takes it off)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=ya-moved
# Marketing companies (Marketers tab, /marketing-companies/): record shape, seed, page, form markup == marketer-form.js
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-marketers.php [--out=tmp/marketers]
# Trade organizations (/trade-organizations/): the same checks, plus membership matching on the mirror's facility records
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-tradeorgs.php [--out=tmp/tradeorgs]
# Open data bulk downloads (/open-data/, inc/open-data.php; built daily by WP-Cron into uploads/kop-open-data/).
# Every dataset names its columns; a new public table or column is added there, never via SELECT *
php scripts/test-open-data.php [--skip-fulltext]  # builds from tmp/prod.sqlite into tmp/kop-open-data/, privacy checks
php scripts/build-open-data.php                   # on the server (ea-php82), rebuild now
# SVG icons that replace emojis (inc/icons.php: kop_icon() / kopIcon(), and the
# render filter for emojis in post content, widgets and the ACF facility-key field)
php scripts/test-icons.php
# Pictures in editor content: 640px+ ones fill the text column (.kop-img-fill from inc/content-images.php), smaller ones
# are centred, left/right-aligned ones keep their float (css/content-images.css)
php scripts/test-content-images.php
# Pull quotes set large like a magazine (inc/pull-quotes.php, css/pull-quotes.css, every page): the block editor's Pullquote
# block, [kop_pullquote credit="..." align="center|left|right"]words[/kop_pullquote], or "> " lines in page text ("> -- Name" credits it).
# Admins select words in an article and press "Pull quote" (js/inline-edit.js): pq:<post>:<block>:<hash>:new adds a core Pullquote block
# before/after that block; a pull quote block's pencil is pq:...:edit (words, credit, placement, take off); Undo = pqundo:<token>.
# Company histories (operator.history, /operator/ and converted program pages): a paragraph whose lines all start "> " is a pull quote
# ("> -- Name", "> (right)"); the button there uses pqh:<o|p>:<company>:<paragraph>:<hash>; the app gets it as plain words.
# Another page joins by marking its paragraphs data-kop-pq="<ref base>" and adding a source
php -d extension=mbstring scripts/test-pull-quotes.php [--wp=<WordPress root>]   # add/edit/undo on WordPress's own block parser (Local's by default)
# Links read as words, never as web addresses: kop_url_label() (inc/url-labels.php, a late the_content filter) and
# kopUrlLabel() (js/url-labels.js, on every page, relabels JS-drawn lists as they appear) must give the same label;
# a new renderer that prints a link uses one of them for its text. data-kop-keep-url opts an element out
php -d extension=mbstring scripts/test-url-labels.php    # labels, PHP == JS (needs node), the content filter
# Links to our own pages in the text (inc/content-links.php): the first mention of a facility, parent company, state or
# glossary term in a post or page (the_content, 12), the About/history prose of facility and company pages
# (kop_content_links_html() in the templates) and a wiki entry (its card's title, company and an "Also on" box) links to
# its page; a post ends with "Related on Kids Over Profits" (css/content-links.css). Names only (posts hold no record ids),
# so: every word capitalised, one-word names only as capital abbreviations (CEDU) or states, names of common words never,
# a short name inside a longer one never ("New York Times"), a 1-2 word or shared facility name only when the text names
# its state, a record named like a company goes to the company page. Names cached 6 h (transient kop_content_links_dict,
# dropped on kop_facility_v2_sync; bump KOP_CONTENT_LINKS_VERSION after changing what goes in). A page opts out with the
# kop_content_links_skip filter. A false link: add the rule here and a case to the test, never a stored exception
php -d extension=mbstring -d extension=pdo_sqlite -d memory_limit=4G scripts/test-content-links.php [--posts=200] [--list]   # rules, then real posts
# Proper names (inc/proper-names.php, js/proper-names.js): one sitewide list of names always capitalised as written (NATSAP,
# MacLaren, LLC), edited at KOP Tools > Proper Names (option kop_proper_names; js/data/proper-names.json only until first save).
# Every function that recases text ends with kop_proper_names_apply() / kopProperNames(); a new one does the same
php -d extension=mbstring scripts/test-proper-names.php   # rules, every case changer wrapped, PHP == JS (needs node)
# Dropdown Lists (inc/choice-lists.php, KOP Tools > Dropdown Lists): every choice list (website/document/link kinds, closure stages,
# facility types, form checkboxes, article types, content warnings, news tags, lawsuit/bill statuses...) is registered with its built-in
# choices in inc/choice-lists/<group>.php and read with kop_choices($key, null, 'all'|'offered'); the owner adds, renames and hides
# choices (never deletes: records keep their values), Undo per save. Option kop_choice_lists + uploads/kop-choice-lists/lists.json (read
# by api/ endpoints without WordPress); window.KOP_CHOICE_LISTS for lists registered 'js'. A new list goes there (docs/ADDING-CONTENT.md 10)
php -d extension=mbstring scripts/test-choice-lists.php [--list]   # merge, form, Undo, every registered list reads
# robots.txt (inc/robots.php): every Disallow line goes inside Yoast's "User-agent: *" group (lines outside a group are ignored);
# a path to keep crawlers out of goes in kop_robots_disallow_paths(). WP-Cron runs from the server's crontab (wp-cron.php every
# 5 min, ~/logs/wp-cron.log); visitors start it only if that run has not been seen for 20 min (inc/server-cron.php)
php scripts/test-robots.php
# Search engines (inc/seo.php, the search plan https://claude.ai/artifact/FEpJSHihC9fDgdxoxzuQqb): facility and company titles and descriptions
# name past/other names (kop_alias_label()), each page describes its program or company as a schema.org Organization added to Yoast's graph
# (wpseo_schema_graph), posts/state hubs/map/news feed get descriptions, pages with no image share the banner, tag archives are noindex and out of the sitemap. A new page kind gets its pieces there
# Breadcrumbs on /facility/, /operator/, profile posts and state/country hubs: steps from kop_seo_facility_steps() /
# kop_seo_operator_steps() / kop_seo_place_steps() -> kop_page_trail_set() before get_header() -> kop_page_trail_html() in
# the header; the same steps make the BreadcrumbList (inc/seo.php, or Yoast's via kop_page_trail_yoast()). Never build a
# trail by hand in a template: a new record or place page adds a steps builder there
# Yoast's dashboard and editor analysis read the stored post meta (_yoast_wpseo_metadesc, _yoast_wpseo_focuskw), not our wpseo_* filters, so its "default meta description" / keyphrase warnings can show where the live page is fine (see docs/plan/4.7 items 8-10)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-seo.php [--list] [--id=12161]
# SEO audit (docs/plan/4.7 item 8): the crawl reads every sitemap page's live title, description, canonical and BreadcrumbList,
# compares them with the stored _yoast_wpseo_* fields in tmp/prod.sqlite and flags problems -> kop-pipeline/tmp/seo-audit/audit.json;
# copy it to wp-content/uploads/kop-seo-audit/audit.json on the server and it shows at KOP Tools > SEO Audit (inc/seo-audit.php, read-only)
(kop-pipeline) python scripts/seo-audit.py [--limit N] [--refresh]
scp ../kop-pipeline/tmp/seo-audit/audit.json <prod>:public_html/wp-content/uploads/kop-seo-audit/audit.json
php -d extension=mbstring scripts/test-seo-audit.php [--audit=../kop-pipeline/tmp/seo-audit/audit.json]
# Sparse facility/company descriptions (kop_seo_facility_description(), kop_seo_operator_description()): a summary that says only name,
# kind and place goes on with what the record holds, worst first ("On record: 1 death, 2 lawsuits and 14 state inspection reports."),
# or "Not on file yet: who ran it and its years"; a merged profile post's hand-written excerpt is left alone. State hubs give their count
# KOP Tools > SEO Descriptions (inc/seo-drafts.php): a draft description + focus keyphrase for every post/page with an empty Yoast field;
# stored in _yoast_wpseo_metadesc/_focuskw only when the owner approves (log + exact Undo, refused if the field changed since). Drafts:
# seeds/seo-descriptions.json (hand-written, by post id + title check) first, then one rule per kind (kop_seo_drafts_kind()). Keyphrase
# rules in kop_seo_drafts_keyphrase(); facility and state wording waits on option kop_seo_kw_wording, set on that screen (plan 2.4 item 11);
# a news post's program comes from news_facility_links (kop_seo_drafts_news_program(), records DB via kop_seed_pdo()).
# Tool pages (kop_seo_noindex_page()) are never listed and leave Yoast's sitemap (wpseo_exclude_from_sitemap_by_post_ids) and so do published pages that
# kop_redirect_map() redirects away (kop_redirected_post_ids(), inc/redirects.php)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-seo-drafts.php [--list]   # seeds, which pages, every rule, approve/undo, the screen
# Program websites scanned for instructions to AI (llms.txt/ai.txt, the copy Exceed's plugin hides in every page: sources
# to ignore, critics framed, AI-only notes), AI crawler rules, city doorway pages and pages answering critics. Domains from
# facilities_v2 profileLinks, tmp/gdocs/links.json program sites and the reputation series; resumable, one request a second
# per site -> tmp/seo-scan/report.html (filterable), report.csv, raw/<domain>/ copies of everything quoted
(kop-pipeline) python scripts/seo-scan.py [--limit N] [--domains a.com b.org] [--refresh] [--no-sitemaps] [--report]
```

## Reporting directory data

`js/data/reporting/` (the kop-data repo) holds where to report an abusive therapist or program,
state by state: `national.json`, one `states/<abbr>.json` per state, and the
generated `directory.json` the /report-abuse/ page reads. Every channel needs
a source URL and a `verified_on` date or the build (kop-pipeline) refuses it. `README.md` in
that folder is the field-by-field schema. Rendered server-side by
`inc/reporting-directory.php`; the state hubs embed their own state's block.

## Glossary data

The glossary lives in SQL tables (`inc/glossary-store.php`): `{prefix}kop_glossary_nodes`
(sections, and program groups under them), `_entries` (term, qualifier, definition, `anchor` = the
page's #id, kept unless the term changes), `_aliases`, `_tags` (`used`/`reported` program + note) and
`_log` (every entry change, before/after, for Undo); title and intro in the `kop_glossary_meta` option.
Edited at KOP Tools > Glossary Editor (`inc/glossary-editor.php`: entries, sections, intro, Recent
changes with Undo) and with the pencil on each entry. Every save runs `kop_glossary_finish()`
(`inc/glossary-build.php`) over the tables with the change applied: a `**cross-reference**` must name
exactly one entry or the save is refused. Rendered server-side by `inc/glossary.php`
(`templates/page-glossary.php`), cached by the `kop_glossary_rev` revision. The open data download
writes the same data as `glossary.json`. `js/data/glossary/glossary.md` is only the source of the
one-time import into empty tables (the old editor's `kop_glossary_edits` overlay applied first);
editing it changes nothing on the site.
