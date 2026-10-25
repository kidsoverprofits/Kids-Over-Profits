# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Kids Over Profits is a WordPress child theme (Kadence parent) that powers a data management system for tracking "Troubled Teen Industry" (TTI) facilities. The theme lives at `app/public/wp-content/themes/child/` inside Flywheel Local development environment.

## Development Environment

> **DEFAULT TARGET: the live site.** Unless the user explicitly says otherwise,
> always assume work, testing, URLs, and diagnostics refer to the production
> site at `https://kidsoverprofits.org`. Only treat the task as local
> (Flywheel) when the user explicitly says "local", "Flywheel", or
> "kids-over-profits.local".

- **Production (default)**: `https://kidsoverprofits.org` — NixiHost shared hosting (Apache/LiteSpeed, PHP 8.2, MySQL)
- **Local (only when specified)**: Flywheel Local at `https://kids-over-profits.local`
- **CMS**: WordPress with Kadence parent theme
- **No build process**: Plain PHP, vanilla JavaScript (plain scripts loaded in order by `wp_enqueue_script` dependencies, not ES modules), CSS

## Related repositories (since the 2026-10-13 split)

This repository is the theme only. Three sibling repositories hold the rest; clone them next to this one
(`source\repos\Kids-Over-Profits`, `kop-data`, `kop-pipeline`, `kop-send-to-kop`) and run `scripts\link-data.ps1` once:

- **kop-data** (public, CC BY-SA): everything that was `js/data/` except `pages/` and `glossary/`, deployed into the
  theme's `js/data/` on the server by its own `.cpanel.yml` + `deploy.yml` (a second cPanel repository). Locally
  `link-data.ps1` makes a junction per folder, so PHP, JS and the tests read `js/data/...` as before. The network map
  bot commits `network/graph.json` there, never here. `.gitignore` here keeps `js/data/*` out except the two folders.
- **kop-pipeline** (private): every builder, scraper (the old Kids-Over-Profits-Tools repo, now `scrapers/`),
  importer and research tool, `markdown_output/`, the state scraper plans, `build-network-map.yml`. Commands below
  marked `(kop-pipeline)` run from that checkout; its `CLAUDE.md` has their details. They find this repo and kop-data
  by the layout above or `KOP_THEME_DIR` / `KOP_DATA_DIR`, and write into `seeds/` here and into kop-data.
  `tmp/` here is a junction to the pipeline's `tmp/` (the production mirror `tmp/prod.sqlite` lives there;
  `python ../kop-pipeline/scripts/sync-prod-sqlite.py` refreshes it).
- **kop-send-to-kop** (public): the "Send to KOP" browser extension; it only talks to `kop/v1/mobile/*` and
  `kop/v1/extension/*` here.
- **Kids-Over-Profits-archive** (the old account, read-only): the whole history before the split, with the original
  commit SHAs that older notes and `docs/PLAN.md` cite.

Tests of theme code, whatever the language (`scripts/test-*.php`, the Node parity tests, the Playwright page checks),
stay here. The server runs only `scripts/discover-articles.php` (+ its two JSON configs), `build-open-data.php` and the
anonymous portal key tools; `.cpanel.yml` ships exactly those.

## Adding something new

Many lists name every kind of record, page or screen by hand (search, site map, open data, the app, merges, people ids,
Woodbury, the tools registry, tutorials). Before adding a data category, a facility field, a page or route, an admin screen,
a review queue, a table holding facility ids, a state inspection page, a public form or seeded records, read
`docs/ADDING-CONTENT.md`: one checklist per kind of addition, naming every function and doc to update.
When changing how an existing record is written (a new save, approve, undo or delete path), read `docs/DATA-FLOWS.md`:
what each store's readers are and what keeps them fresh (link syncs, fingerprints, LiteSpeed), and the known drift.

## Sources found during research (standing rule for every agent)

Any time background research turns up a relevant source that KOP does not hold yet (news article, court record, bill,
forum post, survivor blog, program page, directory listing, anything), submit it to the database in the same session,
unprompted. Research agents do the same: every agent prompt that involves web research carries this rule.

1. Check first: `GET https://kidsoverprofits.org/wp-json/kop/v1/mobile/check?url=<url>&type=<type>`; `duplicate: true` = already held, skip it.
2. Send: `POST https://kidsoverprofits.org/wp-json/kop/v1/mobile/submit` with JSON
   `{"type": ..., "url": ..., "title": ..., "notes": "<which facility/company/person it is about, why it matters, the key quote>", "submitter_name": "Claude research agent", "via": "web"}`.
   `type`: `article` (news, opinion, press release) -> news review; `lawsuit` (court records, dockets) -> lawsuit review;
   `legislation` (bills) -> legislation review; `website` (everything else: forum posts, Reddit threads, blogs, program pages,
   directories, reports) -> Websites Sent In. A `website` may add `"website_kind"`: forum, reddit, blog, social, program, directory, marketing (marketing group or
   referral service), business (business or property record), staff (staff profile or resume), report, government, media,
   archive or other (kop_ext_website_kinds(); guessed from the address when left out). Nothing is published: it all waits in Review inbox. Never send the user's email.
3. Wayback-only sources still follow the archive rule below (PDF into the media library); submit the original URL with the
   archive link in `notes`.
4. Send with the header `X-KOP-Research-Key: <key>` (key in `C:\Users\daniu\kop-research-key.txt`, never pasted into a
   prompt, a commit or a report): it skips the public route's 12-an-hour cap. Agents without the key write the links
   (type, url, title, notes) to a not-sent JSON file, and the lead sends them with `(kop-pipeline) python scripts/send-sources.py <files>`.
   409 = duplicate (fine). A 429 means the key was missing or wrong.
5. The report says how many sources were sent and to which queues.

## Open items go in the plan files as you go (standing rule, 2026-10-14)

The open list is split by section: `docs/PLAN.md` is the index (one line per file, the Plan Board pass) and the
items are in `docs/plan/<section>-<slug>.md`. Read the index, then only the file the work belongs to.
Whenever a session has worked about five minutes without the owner's input, it writes what is still open from
that work (owner steps, follow-ups, things found broken, checks to make after deploy) into the file of the
section it belongs to, before going on. Don't save them all up for the end of the session. Finished items move to
`docs/PLAN-ARCHIVE.md` (verbatim, under their old heading, dated) once nothing still open needs their context;
a finished step that explains how open work behaves stays. `docs/plan/1-working-here.md` has the owner's rules
for working in this checkout.

## Topic change = handoff + new session (standing rule, 2026-10-20)

When the owner's message moves to a different task than the one the session has been working on (a different
feature, screen, pipeline, record or question; not a follow-up, fix or extension of the same work), stop before
starting it and hand off the last task:

1. Write what is still open from it into its `docs/plan/` file (the rule above), and commit + push whatever finished.
2. Reply with a short handoff: what was done (commit links), what is open (the plan items), and the files,
   commands and decisions a new session needs, ending with a paste-ready opening message for that session.
3. Remind the owner to start a new session (or `/clear`) for the new topic and paste that message in.

Do not start the new topic in the same session unless the owner says to ("go ahead here"). A one-line question
needing no tools or file reads can be answered in place. When unsure whether it is a new topic, ask in one line.

## Where to look (read the file for the area before working in it)

This file holds only what every session needs. Each area's commands, tests and rules are in `docs/claude/`;
read the one for the task before starting, and put new commands or rules for an area there, not here.

| Working on | Read |
| --- | --- |
| Network map (data, build, rebuild, embeds, years, renames) | `docs/claude/network-map.md` |
| State inspection reports, Inspection Links, rankings, plain summaries, state facility lists | `docs/claude/inspections.md` |
| /facility/ and /operator/ pages, eras, homes, campuses, operator hubs, merges, investors, inline edit | `docs/claude/facility-pages.md` |
| Mobile app API, Send to KOP extension, /send/ | `docs/claude/mobile-extension.md` |
| Review inbox, queues, add-ons, card fields, auto-connect, conflicts, admin kit, volunteers, data audit | `docs/claude/review-inbox.md` |
| Wiki entries, wiki editor, wiki gaps/drafts, wiki staff | `docs/claude/wiki.md` |
| Nonprofit / business filings, program papers (rules), federal funding | `docs/claude/filings-research.md` |
| Directory, alternate names, data form + wizard, finders, search, site map | `docs/claude/directory-search.md` |
| News: discovery, closures, statuses, facilities from news, essential reporting, story groups | `docs/claude/news.md` |
| Media library documents, document types, clippings OCR, Drive sync | `docs/claude/documents.md` |
| People ids, merges, past jobs, relationships | `docs/claude/people.md` |
| Glossary, reporting directory, hubs, page text, open data, icons, pull quotes, SEO, robots, other site pages | `docs/claude/pages-content.md` |
| Woodbury, HEAL, Google Docs, SCIAD, Unsilenced, survivor sites, Fornits, podcasts, write-ups | `docs/claude/imports-sources.md` |
| Any CSS or template change (contrast, overflow, Kadence colour traps) | `docs/claude/css-checks.md` |
| AI usage logging, daily AI budget | `docs/claude/ai-usage.md` |
| Database tables | `docs/claude/database-tables.md` |
| Adding a new kind of record, page, screen, queue or table | `docs/ADDING-CONTENT.md` |
| A new save / approve / undo / delete path | `docs/DATA-FLOWS.md` |
| Writing anything a paragraph or longer | `docs/WRITING-STYLE.md` |


## Key Commands

The rest are in the area files above.

```bash
# After editing any program data files in js/data/
(kop-pipeline) node scripts/aggregate-all-programs.js

# Other data pipeline scripts
(kop-pipeline) node scripts/rebuild-search-index.js
(kop-pipeline) node scripts/extract-reddit-wiki-links.js

```

## Architecture

### Dual-Workflow Data System
1. **Admin workflow**: Direct writes via `api/save-master.php` (to `facilities_v2` through `inc/facility-v2-writer.php`; the old `facilities_master` was retired in data model phase 5, 2026-10-25)
2. **Public workflow**: Writes to `suggested_edits` table for approval via `api/save-suggestion.php`

### Database Tables
Listed in `docs/claude/database-tables.md` (what each table holds, which file owns it).

### Page Template → Script Loading Pattern
Templates live in `templates/`; `inc/enqueue.php` loads each page's assets conditionally:
- `templates/page-admin-data.php` → Admin form assets, mode='admin'
- `templates/page-data.php` → Public form assets, mode='suggestions'
- `templates/page-tti-program-index.php` → Facility directory, two tabs: by parent company (`js/tti-program-index.js`) and by location (`?view=location`, `js/location-index.js`; `/location-index/` redirects here)
- `templates/page-wiki-editor.php` → Wiki content editor
- `templates/page-news-processor.php` → News processing
- State report pages: `kop_enqueue_report_scripts()` matches a fixed list of slugs (`ca-reports`, `ut-reports`, ...), not a `*-reports` pattern; a new state must be added there and in `inc/rest-api.php`

### JavaScript Module Structure
```
js/data-form-modules/    ← Core modules (config.js loads first, no deps)
js/data-form/            ← Form utilities (utilities.js, data-form.v4.js orchestrator)
js/inspections/          ← State report viewers
js/data/                 ← Static JSON fallbacks
```

Module dependency chain: `config.js` → `data-normalizer.js` → `api.js` → `project.js` → UI modules

### API Configuration
Credentials loaded from `.env`, WordPress constants, or `api/config.local.php` (gitignored). The data forms read `KOP_DATA_FORM_CONFIG` (also localized as `dataFormConfig`), which carries `ajaxUrl`, `restUrl`, `nonce`, `isAdmin`, `endpoints` and `mode`. There is no `apiBase` key.

All AI work (news processing, discovery, closure/facility scans, Fornits, lawsuit extraction, wiki prose,
retitling) alternates between Groq and Gemini: `kop_ai_generate_alternating()` in `api/ai-providers.php`
(provider `'auto'` on `api/process-news-ai.php`) starts each call with the other one from the call before
and gives the prompt to the other when one fails. A new AI call goes through it, never straight to one provider.

## Code Conventions

- **Versioned filenames**: Use explicit versions when iterating (e.g., `data-form.v4.js`)
- **Procedural PHP**: API endpoints use procedural style
- **No build tooling**: Avoid introducing bundlers/transpilers
- **CSS variables**: Use `var(--kop-*)` from `css/colors.css` for styling
- **Archive what you cite from the Wayback Machine**: any time a Wayback Machine (or other web archive) copy is used to read or cite something, download it as a PDF (keep the banner with the original URL and capture date) and import it into the media library with `api/import-documents.php` (`source_url` = the archive.org link), then cite our copy beside the archive link. Never rely on archive.org alone; never commit the PDFs (the repo is public)

## Color Palette (css/colors.css)
Primary: Midnight Blue (#000435), Navy (#000080), Teal (#33A7B5)
Accents: Orange (#EF9034), Chartreuse (#B2E102), Coral Pink (#FE8088)
Backgrounds: Sand (#F2EEDF), Soft Pastel Yellow (#FFF5CB), Mint Green (#B6E3D4)

Reserve bright accents (Chartreuse, Coral Pink, Bubblegum Pink) for borders/highlights, not backgrounds.

### Kadence colour traps
Kadence's CSS loads after ours and colours `h1`-`h6`, `a`, `button` and form fields directly, so they never inherit a
panel's colour. Every CSS or template change follows the rules in `docs/claude/css-checks.md` (read it first) and
passes its contrast checks.

## Program Data Pipeline

Source files in `js/data/reddit-wiki/programs-XX.json` (by state) and `js/data/tti-program-links.json` (uncategorized), both in the kop-data repo. After any edits, from the kop-pipeline checkout:
```bash
(kop-pipeline) node scripts/aggregate-all-programs.js
```
This generates `programs-array.json`, `search-index.json`, and `metadata.json`.

## REST API

- `GET /wp-json/kop/v1/facilities` - Returns facility data (registered in `functions.php`)
- Falls back to static JSON in `js/data/` when API unavailable

## Deployment

- Git deployment via `.cpanel.yml` or manual upload to NixiHost/cPanel
- Export database snapshots if schema changes
- Never commit `.env` or `config.local.php`
