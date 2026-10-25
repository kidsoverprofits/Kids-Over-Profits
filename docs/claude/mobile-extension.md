# Mobile app, extension and /send/

Commands, tests and rules for the mobile app API and admin mode, the Send to KOP extension, /send/ and the extension news processor. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# The mobile app (github.com/kidsoverprofits/kids-over-profits-mobile) reads kop/v1/facility/<slug>, /operator/<slug|?name=>,
# /operators (the Companies list), /news and /resources (the /resources/ list, inc/resources-list.php) (inc/mobile-api.php): named keys copied from the page data, so a new page section goes in the keep lists there
# A facility's names on the network map come as 'network' (kop_mobile_facility_network(), the app's Connections drawing); the app's Map tab is the
# website's /network-map/ in a web view with the site's header/footer, toolbar and stage buttons hidden by the app (src/lib/mapPage.ts) and
# its own controls pressing the map's buttons by id and calling window.KOPNetworkMap / KOPNetworkSearch (src/lib/mapBridge.ts), so a class,
# id or one of those globals renamed there breaks it
# The app lists document libraries itself (/facility/<slug>/documents, /operator/<slug>/documents: the folder tree with file links) and
# opens our own PDFs (sources included, #page=N kept) in its own viewer, never the website
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-mobile-api.php [--id=14182] [--fixture]   # payloads, privacy walk, news filters; --fixture = made-up DB, no mirror needed
# The app's admin mode (inc/mobile-admin.php): kop/v1/app/session trades an administrator's application password for a one-use
# 2-minute token, /app-login/ spends it (remember-me cookie, same-site redirect); /app/tools lists KOP Tools, /app/signout ends every
# session; a session ends when its application password is revoked
# The app gets that application password from WordPress's own Authorize Application page ("Sign in with WordPress",
# app src/lib/wpAuthorize.ts: wp-admin/authorize-application.php -> kidsoverprofits://reviewer-auth?user_login=&password=),
# so it stays signed in until revoked; nothing on the theme side. The Admin tab shows once the login is known to be an admin's
# Ship app code to installed builds (no new native module since the build): (app repo) npx eas-cli update --channel preview
# --environment preview --platform android --message "..." ; the phone downloads it on one launch and runs it on the next
# A renamed program's page is cut into one section per name, earliest first ("As Copper Canyon Academy", "As Sedona Sky
# Academy"; inc/facility-eras.php): names from the map's rename lines, years from Map Renames; each holds the deaths, serious
# findings, lawsuits, incidents, news and staff of its years, another name's record included (both pages print every name).
# No year needed: a name's own record keeps its items under that name whatever their dates; items of a record that also lists a
# no-record name go by date (every rename has a year), else by the one name their words give. A new dated list goes in kop_facility_eras_kinds()
# Every page names all the names the map's rename lines join to it, cut or not (kop_facility_eras_map_names(): "Formerly" /
# "Later called", linked to the other name's page); names with no record of their own are searchable (kop_v2_alias_index()) and
# match inspection rows and folders (kop_facility_pages_doc_name_keys(), only a name no other record reaches)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-facility-eras.php [--list]   # every renamed program, rendered to the temp dir
# "Send to KOP" Chrome extension (its own repo, kop-send-to-kop; loaded unpacked, not deployed) posts to
# kop/v1/extension/* (inc/source-submissions.php): articles -> news_submissions, lawsuits, legislation,
# anything else -> KOP Tools > Websites Sent In; same duplicate rules as the public forms
php -d extension=pdo_sqlite scripts/test-source-submissions.php   # against an in-memory copy of tmp/prod.sqlite
# The app's "Send" tab (inc/mobile-submit.php): public kop/v1/mobile/submit + /mobile/check, the extension's inserts for links,
# suggested_edits for facility_new/facility_correction; duplicates come back as {type, status} only (never ids/titles); 12 an hour per sender.
# Research sessions skip the cap with an X-KOP-Research-Key header whose sha256 is KOP_MSUB_RESEARCH_KEY_SHA256 (the key is in
# C:\Users\daniu\kop-research-key.txt, never in git; rotate by writing a new key there and its hash in the constant).
# (kop-pipeline) python scripts/send-sources.py <not-sent files> sends a backlog with it.
# Signed-in reviewers in the app use kop/v1/extension/* with an application password, like the Chrome extension
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-mobile-submit.php [--db=...]
# The same route serves the browser extension without an account (via=extension; Chrome/Edge/Firefox from one folder, Safari via
# xcrun safari-web-extension-converter; package.ps1 zips it for the stores) and the /send/ page + bookmarklet for any browser (via=web;
# inc/send-page.php, templates/send-page.php, js/send-page.js, prefill ?url=&title=&text=)
php scripts/test-send-page.php
(kop-send-to-kop) npx web-ext lint --source-dir .   # 1 expected warning: Firefox ignores background.service_worker
# Signed-in reviewers get the News Processor in the extension's popup (inc/extension-news-processor.php): AI fill from the tab's text
# (buildPrompt in api/lib-news-ai.php through kop_ai_generate_alternating), every processor field, "Publish on the site now" (admins,
# filed approved, Undo in Recently done) or the review queue
# Articles keep two links: article_url = the article itself, archive_url = its archived copy (Wayback / archive.today). A Wayback link
# given as the article splits into both (api/lib-news-archive.php == js/news-archive-links.js == the extension's archive.js); readers
# print the title linked to the original and "(archived copy)" beside it. archive.today short links hide the address, so they stay alone
php -d extension=pdo_sqlite scripts/test-news-archive.php [--list]   # rules, PHP == both JS copies, the one-time split on a copy of the mirror
```
