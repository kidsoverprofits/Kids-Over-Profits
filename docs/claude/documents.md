# Documents and media

Commands, tests and rules for media library documents: placement, selection, document types, news clippings OCR, FileBird subfolders, Drive sync. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# A document on the wrong page: its tile's pencil (doc:<id>:h<page folder>) lists where it is filed and moves it to another
# facility or a folder found by name, takes it off the page, or deletes it (inc/doc-placement.php; move/remove have Undo)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-doc-placement.php   # real moves + undo on TEMP copies of the folder tables
# "Move to" also takes a young adult program (y:<id>): its folder (young_adult_programs.document_folder_id, made under "Young
# Adult Programs" the first time by kop_wbc_existing_target('ya', id)), shown on its card at /young-adult-programs/ (kop_ya_documents_html())
# Many at once: "Select documents" on each library (js/inline-edit.js docSel; click, shift-click, "Pick all in this folder"), a bar at the
# foot opens one docs:<id>h<folder>... dialog (move / take off / delete, kop_dp_apply_many(), one Undo). A hidden pencil needs [hidden] display:none
python scripts/test-doc-select.py [--shots tmp/doc-select]   # pencils of closed folders, picking, the ref, no redraw loop; made-up page
# Document types (inc/doc-types.php): what kind each media library document is (student handbook, enrollment document, inspection
# report, news clipping, legal filing... kop_dt_types(); a new type is one entry there). {prefix}kop_doc_types, one row per document
# and type. Hourly: the folder and title rules (kop_dt_rules(), nearest folder first, strong titles win; bump KOP_DOC_TYPES_RULES_VERSION
# after changing them) type most documents with no review, one logged change; the AI reads the rest and its guess waits at Review inbox >
# Document types ("Typed by rule" there spot-checks the rules). Readers: /documents/ and /documents/<type>/ (a route,
# templates/document-types.php: grouped by program, filter by name/state/decade), a chip on every document tile, a type filter over
# facility/company libraries (js/doc-type-filter.js), 'types' in the app's document payloads; set with the pencil on a tile or at KOP Tools >
# Document Types (counts, tag many, Recent changes with Undo; log in the kop_doc_types_log option)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-doc-types.php [--list] [--untyped]   # rules, run + undo, pencil, AI stand-in, inbox, pages
# News clippings by OCR (docs/PLAN.md 3.35, inc/news-clippings.php): documents typed news-clipping get an ocrmypdf text layer (picture
# unchanged, original kept in uploads/kop-clipping-originals/<id>/), their text in {prefix}kop_doc_text (the header search finds words inside
# documents) and one waiting article each in Submissions Review > News (the processor's AI read; linked to the printed web address, else our scan)
(kop-pipeline) py -3 scripts/clippings-ocr.py run [--ids ..] [--limit N] && py -3 scripts/clippings-ocr.py upload   # resumable; selftest too
php api/import-clippings.php ~/kop-import/clippings [apply] [--ids=..] [--news-only]   # on the server (ea-php82): dry run first; undo|titles [apply] [--ids=..]
# File-name titles become "Headline (Outlet, date)" (kop_clip_is_slug_title()); libraries with stored text get a search box (js/doc-type-filter.js, kop/v1/doc-text-search)
php -d extension=mbstring scripts/test-news-clippings.php
# Subfolders for the largest flat FileBird folders: plan from tmp/prod.sqlite into
# seeds/media-subfolders.json (report in tmp/), applied on deploy by kop_apply_media_subfolders()
(kop-pipeline) python scripts/build-media-subfolders.py
php scripts/test-media-subfolders.php
# PDFs and Word files in the Drive FileBird folder -> media library, filed by their Drive path (nightly cron, 03:15). KOP Tools > Drive Sync
# (inc/drive-sync.php) has "Sync now" (starts the same script in the background) and the last run's report (option kop_drive_sync_status)
php api/sync-drive-documents.php [apply] [--only="Folder"] [--limit=100]   # on the server (ea-php82)
php scripts/test-drive-sync.php                   # the screen's states, the lock, the report; offline
```
