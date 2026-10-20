# Imported sources

Commands, tests and rules for Woodbury Reports, HEAL, wiki source facts, Google Docs, SCIAD NET, Unsilenced, survivor sites, Fornits, podcasts, record write-ups. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# Woodbury Reports pages about each program (inc/woodbury-mentions.php): scan every issue in the
# media library (reads tmp/prod.sqlite), cut the pages into C:/tmp/kop-woodbury/pending/, copy that
# folder to ~/kop-import/woodbury/ on the server, review and file at KOP Tools > Woodbury Reports
(kop-pipeline) python scripts/woodbury-scan.py [--no-cut]
php scripts/test-woodbury-mentions.php            # "File under" facilities and parent companies (c<id>), company search, rows
# Woodbury Reports facts (inc/woodbury-facts.php): staff and careers, incidents, openings/closings, names,
# owners, moves read from every issue into tmp/woodbury-extract/facts/ (readers follow INSTRUCTIONS.md there);
# the build checks each quote, matches records, drops what they hold -> C:/tmp/kop-woodbury/pending/facts.json,
# copied to ~/kop-import/woodbury/; owner adds them at KOP Tools > Woodbury Facts (live at once, Undo)
(kop-pipeline) python scripts/woodbury-facts.py
php scripts/test-woodbury-facts.php               # applies every proposal to the real docs, validator, exact undo
# Approved facts are not written onto the record by themselves (owner, 2026-10-15; inc/record-writeups.php): Add in Woodbury Facts
# (any source, the hourly exact-name run, "Use this value") and Fornits staff/incidents/leads set the item to 'held'. The AI drafts the
# record's About from its current About + the held facts (hourly, or "Write it with AI"); the owner edits and approves it at Review inbox >
# Write-ups to approve, which writes every held fact on in one save and makes the text the About (page and app); Undo in Recently done.
# Facility pages show the held facts and the draft to admins only. A new path that adds facts to a record holds them the same way
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=writeups   # approve -> held -> draft -> approve -> About -> undo
# An item that would add nothing, word for word or in other words (kop_wbf_list_covers(): ages/size/gender in the record's fields,
# founders/owners/operators it names, its names, its address, NATSAP years, a line saying the same; kop_wbf_job_held(): a past job
# whose employer and title the person's entry names; a nickname is one person), is refused by Add and filed hourly as rejected
# "Already on the record" (kop_wbf_clear_on_record(); Back to review undoes one, never cleared again). Conflicts keep waiting
php api/clear-woodbury-on-record.php [--list] [apply]   # on the server (ea-php82): run the pass now
# Same information in other words or run together, which rules cannot see, read by AI agents (Haiku, Sonnet for the tricky ones) next to
# each record; every verdict quotes the record and is checked: clear (rejected, the quote shown), trim (cut to the new part, still waiting,
# "Back to what Woodbury said" undoes), dup. First run 2026-10-17: 105 cleared, 108 trimmed, 7 duplicates of 3,978 waiting items
php api/apply-woodbury-same-info.php [file] [apply] | undo apply   # on the server: ~/kop-import/woodbury/same-info.json, re-checked live
# HEAL's archived site (heal-online.org, gone; Wayback copies) into tmp/heal/: staff lists and program pages are read
# like Woodbury issues (tmp/heal/INSTRUCTIONS.md -> facts/), PDFs one by one (DOCS-INSTRUCTIONS.md -> docs/).
# Fetch is resumable and slow (the Archive refuses connections past ~15 requests a minute)
(kop-pipeline) python scripts/heal-archive.py fetch && python scripts/heal-archive.py text && python scripts/heal-batches.py
(kop-pipeline) python scripts/woodbury-facts.py --also tmp/heal   # HEAL facts join Woodbury Facts, cited to the archived copy, added automatically at an exact name
(kop-pipeline) python scripts/heal-docs.py                         # tmp/heal/heal-links.json -> ~/kop-import/gdocs/, reviewed at Drive Docs
# The r/troubledteens wiki (markdown_output/ + the wiki editor's copies in tmp/prod.sqlite) read the same way:
# text -> tmp/wiki/text/ + INSTRUCTIONS.md, batches of pages not read yet; readers write tmp/wiki/facts/
(kop-pipeline) python scripts/wiki-source.py text && python scripts/wiki-source.py batches
(kop-pipeline) python scripts/woodbury-facts.py --also tmp/heal --also tmp/wiki   # wiki facts cite the wiki page, added automatically at an exact name
(kop-pipeline) python scripts/wiki-links.py --gdocs <server links.json>   # the pages' links -> tmp/wiki/wiki-links.json, copied to ~/kop-import/gdocs/, reviewed at Drive Docs
php scripts/test-drive-docs.php --links=tmp/wiki/wiki-links.json
# Links from the owner's Google Docs and Sheets (docs/PLAN.md 3.9): tmp/gdocs/export.gs (from scripts/gdocs-select.py)
# exports them to G:/My Drive/KOP Doc Export; the links pass ties each link to a facility and drops what is on file ->
# tmp/gdocs/links.json, copied to ~/kop-import/gdocs/; reviewed at KOP Tools > Drive Docs (inc/drive-docs.php): news,
# court records and bills to their queues (no emails), program sites to profileLinks, the rest to the record's
# resourceLinks ({url, label, kind, source}, listed on the /facility/ page under "Materials and links")
(kop-pipeline) python scripts/gdocs-extract.py
php scripts/test-drive-docs.php                   # every link on its real record, validator, exact undo, the page list
# SCIAD NET (a Zotero archive, survey in tmp/sciad/survey.md) links that are not Drive documents: news
# (Wayback unwrapped), court records (neutral titles, never a party's name), program pages, media; private classes and what
# KOP holds dropped -> tmp/sciad/sciad-links.json (report sciad-links-report.md), copied to ~/kop-import/gdocs/, never
# committed; reviewed at Drive Docs (source filter, Add all per facility), every row it fills credits SCIAD NET, linked
(kop-pipeline) python scripts/sciad-links.py [--selftest]       # --selftest: court titles and privacy rules on a made-up fixture
php scripts/test-drive-docs.php                   # also SCIAD NET: credit on the page, no private class, court titles, paging
# Unsilenced's archive (public Drive folders) on facility/operator pages: only documents KOP has no copy of.
# Server lists every file with its md5 (read-only; ~/kop-import/unsilenced/files.jsonl -> tmp/unsilenced/),
# then the build compares with the media library md5s and inspection scrapers -> js/data/unsilenced/
php api/list-unsilenced-files.php [probe] [--minutes=25]   # on the server (ea-php82), resumable
php api/list-unsilenced-files.php restart --check          # monthly cron: lists into ~/kop-import/unsilenced/check/, mails when Unsilenced added enough
(kop-pipeline) python scripts/build-unsilenced-links.py                   # report in tmp/unsilenced/build-report.md
php scripts/test-unsilenced-archive.php                    # inc/unsilenced-archive.php, fixture + the build
# Backup + content titles for the listed files (docs/PLAN.md 3.11): md5-checked copies to I:/My Drive/Unsilenced archive backup,
# title evidence -> tmp/unsilenced-titles/; titles.json is read by the build (link shows the title, Unsilenced's name muted).
# Generated titles never name a person (court: type/case/date; reports: type/program/date; clippings: headline/paper/date)
(kop-pipeline) python scripts/unsilenced-backup.py run [--limit N] [--dest DIR] [--no-keep]   # resumable, ~2-3 s/file, pauses when C: < 4 GB
(kop-pipeline) python scripts/unsilenced-backup.py titles && python scripts/unsilenced-backup.py status
# The same for survivor-run sites (inc/survivor-archives.php; ssi, wwasp, straights = thestraights.net (http only), nhym =
# nhym-alumni.org): fetch hashes every document their pages link (nothing kept; straights/nhym crawl same-host pages,
# 1 request a second), build drops what the media library holds or an earlier site lists for the record, drops private
# records by pattern and by js/data/survivor-archives/privacy.json (sha1 of url keys only, never a name; the repo is
# public; "include" keeps a reviewed document a pattern would drop), and files the rest under the record RULES in the
# script -> js/data/survivor-archives/<site>/. Site sciad = SCIAD NET's Google Drive files (not fetched: the build reads
# the survey in tmp/sciad/ and tmp/unsilenced/files.jsonl; a file whose name Unsilenced has is dropped, records from the
# exact program match or SCIAD_RULES, court records titled from kind/court/year/number only and grouped by case, the
# block credits SCIAD NET linked to its archive page; every listed title -> tmp/survivor-archives/sciad-review.tsv)
(kop-pipeline) python scripts/survivor-archives.py fetch [--site ssi|wwasp|straights|nhym]   # resumable, into tmp/survivor-archives/
(kop-pipeline) python scripts/survivor-archives.py build                      # needs tmp/prod.sqlite (+ tmp/sciad/ for sciad); report in tmp/survivor-archives/build-report.md
(kop-pipeline) python scripts/survivor-archives.py privacy-review             # every listed document + sha1 -> tmp/survivor-archives/privacy-review.md
(kop-pipeline) python scripts/survivor-archives.py selftest                   # privacy patterns, SCIAD court titles, privacy.json shape
php scripts/test-survivor-archives.php
# Fornits survivor forum (inc/fornits.php): the crawl copies the treatment-abuse boards into tmp/fornits/ (never committed);
# the hourly Windows task "KOP Fornits" ties new topics to facilities and uploads them to ~/kop-import/fornits/; on the
# server an hourly read, Groq and Gemini free tiers in turn (daily caps KOP_FORNITS_GEMINI_CALLS / KOP_FORNITS_DAILY_CALLS),
# proposes staff, incidents, survivor accounts (unpublished) and leads; reviewed at KOP Tools > Fornits, with exact Undo.
# Each read thread gets a headline from its summary (20 a call) that replaces the forum title on its links, on records too unless
# a person changed the label; a lead sent to News/Lawsuits is retitled from the article by the hourly enrich (api/lib-record-enrich.php)
php scripts/test-record-enrich.php                # which bare-link news/lawsuits the enrich picks, title handling, offline
python scripts/test-gemini.py                     # is a Gemini key usable? lists its models, names the fix
# Podcast series transcribed locally with Whisper (scripts/podcast-transcribe.py), each episode's prompt primed with the KOP names its
# feed mentions + scripts/podcast-vocab/<show>.json (corrections there, 'fix' re-applies); transcripts in tmp/podcasts/, never committed
(kop-pipeline) py -3.13 scripts/podcast-transcribe.py feed|vocab|run|fix|suspects|status [--show sftf]   # resumable; stops when C: runs low
(kop-pipeline) python scripts/podcast-facts.py batches|check|status   # facts + industry people's on-air statements, own stream (docs/PLAN.md 3.18)
# tmp/podcasts/<show>/proposals.json -> ~/kop-import/podcasts/<show>.json -> Review inbox > Podcast statements (inc/podcast-statements.php,
# {prefix}kop_podcast_items): "Approve and link" fixes the words, links a person id and programs/companies; admins only (People screen, "On air"
# on the linked pages), exact Undo
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=podcasts
```
