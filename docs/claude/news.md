# News

Commands, tests and rules for news: legacy import, closures, bill and lawsuit statuses, discovery, facilities from news, record resync, essential reporting, story coverage, journalists. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# The legacy news posts + 2024 index going into news_submissions (against tmp/prod.sqlite)
php scripts/test-news-post-import.php
# Error pages are never a headline (plan 4.6 #16, 2026-10-25): kop_news_error_page() (api/lib-article-fetch.php) tells a
# "Page Not Found" / "522: Connection timed out" title or a "The requested article could not be retrieved" summary from an
# article. A dead link (404, 410, 5xx, a short 200 error page) tries the Wayback copy, else reads as no text;
# api/process-news-ai.php refuses an error-page read, and the hourly reader (api/lib-record-enrich.php) never writes one, so
# the row waits with its title on the once-a-day retry. Any new path that fetches a news title runs it too.
php -d extension=mbstring scripts/test-news-error-pages.php [--mirror]   # --mirror: approved/waiting rows in tmp/prod.sqlite that read as error pages
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-record-enrich.php
# Facility closures reported in the news (inc/closure-reports.php): hourly WP-Cron scan of saved
# articles -> KOP Tools > Closure Reports; confirming one sets the facility's status, which the
# /facility/ page and the network map (kop_network_map_status_overrides) follow at once
php scripts/test-closure-reports.php              # offline, against tmp/prod.sqlite, no Groq calls
php api/scan-closure-reports.php --type=closure   # on the server (ea-php82): dry run; "apply" stores reports
# Where a report goes (kop_closure_settle_report(), 2026-10-20): a name that names no program ("youth facility", "Missouri
# boarding school", "treatment center in Raeford"; kop_closure_is_vague_name()) is 'vague'; else the exact name, else the
# looser match (kop_closure_name_candidates(): same distinctive words, record's extra words = the report's place, a shared
# kind of place for one-word names; several matches all Closed = already recorded); a suspension > 1 year or an announced
# "closing" > 2 years old is 'old'; a second report of the same change to the same facility, or one of the same closure year as
# (or, undated, no newer than) a dismissed one, is 'duplicate'. Only pending + unmatched wait in the queue.
# Company names ("CEDU", "Sequel"; 2026-10-21): one record in the report's town is that record (kop_closure_candidate_in_town());
# else an unmatched report is a duplicate of one with the same name and change (kop_closure_same_name_key(), same state) that
# waits or was dismissed for the same year, and takes the record of one that was confirmed (kop_closure_same_name_report()) Facilities from News never creates a record from a vague name
# Discovery's batched sweep hits are confirmed only when the AI's discoveryCheck says the story is about that program
# (aboutProgram) and its place fits the record (confirm_batch_attribution()); a turned-down hit logs the AI's answer
# Bill and lawsuit statuses (inc/status-checks.php): a nightly WP-Cron run (09:00 UTC, then every 10 min until done, 30 runs max)
# checks every published bill and lawsuit that is not finished: federal bills from govinfo BILLSTATUS (no AI), state bills from the
# official page (California via CalMatters Digital Democracy), federal lawsuits from the CourtListener docket feed (found by docket
# number + CourtListener court id, never guessed; remembered per case; anonymous = 5 requests/min, so spaced 13 s; a free token in
# KOP_COURTLISTENER_TOKEN or COURTLISTENER_API_KEY (api/config.local.php or .env; kop_sc_cl_token()) = 5,000/hour), read by the AI; an unchanged page is not sent to the AI again. Changes
# wait at Review inbox > Bill and lawsuit updates (record_status_proposals; Apply, exact Undo, a dismissed change never comes back)
# The feed gives filings only as labels ("Report - Status"), so with the token the newest filings' own words are read too
# (kop_sc_recent_filing_text()); a case whose status never moves (in_progress through an appeal) still gets the docket's newer
# decisions added to the end of its outcome, when they are dated after every date the outcome gives (new_events, kop_sc_latest_date())
# Bill corrections and new bills by hand: seeds/legislation-updates.json + seeds/legislation.json (inc/legislation-updates.php, bump 'version')
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-status-checks.php   # parsing, matching, what is a change, runs with a made-up AI
php api/check-record-statuses.php [--kind=bill --ids=19] [apply]   # on the server (ea-php82): dry run prints what it finds
# News discovery (scripts/discover-articles.php, cPanel cron 01:30 + 13:30 Central, docs/news-processor/README.md): r/troubledteens, Google News
# topic queries, then the facility sweep: every record and every name it has had (closed too), distinctive names several to one Google News
# request (<= 16 words of names, when: first; Google reads ~32 words), short names one to a request with the state; a cursor in
# scripts/.discovery-state.json walks the sorted list. Google blocks the server's IP after ~180 searches in a row (measured 2026-10-15: >17 min, <12 h), so a
# run makes GN_REQUESTS_PER_RUN (140) at most, topics included. The daily list (inc/discovery-priority.php, kop/v1/discovery/priority: open programs
# with approved news or a serious finding in the last 12 months or a published lawsuit, plus the "Daily news search" switch in each facility/company page's
# admin toolbar, option kop_discovery_priority) is searched every day, half a run, first; every other name rotates through the room left (plan_sweep(),
# its own cursor; the daily list takes <= 3/4 of a run; ~5-6 weeks a pass). A batched hit naming no program in its headline is confirmed by the AI
# step (<= 30 a run) before it is submitted, else logged batch-unconfirmed (Review inbox > Scraper finds). Terms in scripts/discovery-queries.json
php scripts/test-discovery-sweep.php              # offline: names, batches, cursor, daily list + rotation, filter on real headlines, AI confirmation
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-discovery-priority.php [--list]   # the daily list's rule and switches on tmp/prod.sqlite
php scripts/discover-articles.php --dry-run --max-facilities 40 --no-topics   # against the live API, submits nothing (local php needs -d curl.cainfo=)
# Facilities the news mentions that have no record (inc/facility-discovery.php): the hourly scan creates
# them from the article, or links a known one; KOP Tools > Facilities from News to remove or create by hand
# Links follow records created later (kop_facdisc_relink_resolved(), 2026-10-28): links are worked out only when an article is
# saved, so each hourly run then resyncs (kop_resync_news()) up to 100 live articles naming a record or company by exact name
# that they are not linked to. An article is held back whole when one such name is a record's that another record repeats with a
# "(...)" ("Lakeside Academy", UT, beside "Lakeside Academy (MI)"): the exact match always takes the bare one. Checked in test-record-resync.php
php scripts/test-facility-discovery.php           # offline, against tmp/prod.sqlite, no Groq calls
php api/scan-new-facilities.php --ids=502         # on the server: dry run; "apply" creates records
# Every path writing a news article, lawsuit or bill resyncs its facility links, case coverage and story groups (api/lib-record-resync.php);
# kop_record_changed() (inc/record-events.php) moves the page caches and purges the facility pages. docs/DATA-FLOWS.md is the map
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-record-resync.php   # every path calls it, no link lost, reject/undo, deletes, duplicates
# Essential reporting (inc/featured-reporting.php): news articles the owner picks as especially good journalism, each with an
# optional note on why. Table news_featured (records DB); picked with the pencil on any article's headline ('feat:<id>') or at
# KOP Tools > Essential Reporting (search, notes, Recent changes with exact Undo; log in the kop_featured_log option). Shown on
# /essential-reporting/ (a route, templates/featured-reporting.php), a strip under the feed on /news/ (since 2026-10-25) and on the home page ([kop_featured_reporting]),
# flagged with the note and listed first on facility and company pages, 'featured' in the app's news payloads. Read at render time,
# never baked into cached page data. Byline = the article's author field, left out when it is only the outlet's name
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-featured-reporting.php [--db=...] [--out=tmp/featured-reporting]
# Top story (inc/news-top-story.php): one article shown first on /news/ (page 1, unfiltered; left out of the list below it). Picked with the
# admin-only "Top story" box in the News Processor's submit popup (api/save-news-submission.php, topStory) or the same pencil ('feat:<id>');
# a new pick replaces the old. Option kop_news_top_story keeps the last 20 picks: the newest approved/published one shows, so a pick sent in
# with a new article waits for approval while the old one stays up, and unticking one brings the one before it back. Tested in test-featured-reporting.php
# Nominations wait at Submissions Review > Nominated (inc/featured-nominations.php, table news_featured_nominations): each has a draft
# note and a reason; Feature picks it with the note as edited in its box (Undo = kop_feat_undo of that pick), Pass over changes nothing.
# New ones go in js/data/featured/nominations.json with a higher 'version' (adds missing ones only, never changes a decided one)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=featured-nominations
# "Also covered by" on the news feed is the automatic story grouping (api/news-story-groups.php); an admin corrects it with the pencil on
# the list ('storycov:<id>', inc/story-coverage.php: untick a wrong article, paste a link or #id to add one, exact Undo). Every row it
# touches gets story_group_manual = 1, which the automatic grouping (each save, the rebuild) never changes or joins to another story
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-story-coverage.php   # take off, add, move, rebuild keeps it, undo; made-up table
# Facts from news articles (inc/news-facts.php, 2026-10-28; owner's rules in docs/plan/4.6 item 17): hourly WP-Cron, 6 approved articles
# a run, newest first (publication_date), one AI call each (kop_ai_generate_alternating, job "News facts"). A fact counts when it is about a named
# program/company/person, rests on a quote found in the article and lands somewhere; closures are left to the closure scan. Survivors' names come
# out of every line (kop_nf_scrub_survivors(): survivors_mentioned names and their surnames; a young person who died keeps theirs). Facts about a
# linked program become {prefix}kop_woodbury_facts rows (extra.via = 'news', match_kind 'id', origin "News articles" on Woodbury Facts) with
# the same Add/Undo/conflicts/write-up hold; everything else waits at Review inbox > Facts from news (Keep/Drop) until its record kind has an Add.
# Automatic approval (staff, opened, capacity, ages, gender, type; quote found, no name taken out) is the option kop_news_facts_auto, off until
# the owner turns it on with that queue's tool; "Pause the reader" there sets kop_news_facts_paused. KOP_NEWS_FACTS_READER bumps re-read everything
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-news-facts.php   # offline: order, prompt, reply, survivors, routing, rows apply to 25 real records
php api/scan-news-facts.php [--ids=10553] [--limit=5]   # on the server (ea-php82): dry run, calls the AI, stores nothing; "apply" stores
# Journalist extraction from news bylines (api/lib-journalists.php), against tmp/prod.sqlite
php scripts/test-journalists.php [--list]
```
