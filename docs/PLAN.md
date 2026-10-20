# Work plan

The one list of open work on the theme and on https://kidsoverprofits.org. Every item is an action:
who does it, what to do, where. This page is the index; the items are in `docs/plan/`, one file per
section, so a session reads only the file for the work at hand. Background lives elsewhere:

- [PLAN-ARCHIVE.md](PLAN-ARCHIVE.md): finished items, verbatim, under their old headings. The whole of the
  old sections 2 and 3, as they stood before the 2026-10-20 reorganisation, is there under "Moved 2026-10-20":
  an item marked "(old 3.12)" has its background in section 3.12 there, and so does a code comment or doc that
  cites "docs/PLAN.md 3.12".
- [RESEARCH-TASKS.md](RESEARCH-TASKS.md): open research questions (sources that disagree, records that look
  wrong). Add one whenever it comes up; take it off when settled.
- [DATA-FLOWS.md](DATA-FLOWS.md), [ADDING-CONTENT.md](ADDING-CONTENT.md), [ADMIN-UI.md](ADMIN-UI.md),
  [NETWORK-MAP.md](NETWORK-MAP.md), [PAGE-TEMPLATE-DESIGN-PLAN.md](PAGE-TEMPLATE-DESIGN-PLAN.md),
  [FIX-PLAN-2026-09.md](FIX-PLAN-2026-09.md) and the area READMEs in [README.md](README.md).

Reorganised 2026-10-20 at
[841d47f4](https://github.com/kidsoverprofits/Kids-Over-Profits/commit/841d47f44e7a54c484476955c254b33cfd62fb70),
split into `docs/plan/` the same day. Add new items to the file of the section they belong to, in the same shape
(one bold lead, the action, where, the old section for background); when one is done, delete it there and put
its record in PLAN-ARCHIVE.md.

## Files

| Section | What goes there |
| --- | --- |
| [1. Working in this repository](plan/1-working-here.md) | The owner's rules, the shared checkout, mechanics (PHP, mirror, SSH, deploy checks). Not items |
| [2.1 cPanel, server and accounts](plan/2.1-cpanel-server-and-accounts.md) | Owner: cPanel, cron, server, accounts, keys |
| [2.2 Scrapers and long runs](plan/2.2-scrapers-and-long-runs.md) | Owner: runs on the owner's computer |
| [2.3 Review queues to work through](plan/2.3-review-queues-to-work-through.md) | Owner: queues waiting for decisions |
| [2.4 Decisions waiting](plan/2.4-decisions-waiting.md) | Owner: questions only the owner can answer |
| [2.5 Writing to read and publish](plan/2.5-writing-to-read-and-publish.md) | Owner: drafts to read and publish |
| [2.6 App and extension](plan/2.6-app-and-extension.md) | Owner: trying the app, store accounts and builds, listing, the extension |
| [3. Checks after deploy](plan/3-checks-after-deploy.md) | What to look at on the live site once a push deploys |
| [4.1 Measure and tune](plan/4.1-measure-and-tune.md) | Session work due now: measuring a change after it has run a while (discovery, AI budget) |
| [4.2 Inspection reports](plan/4.2-inspection-reports.md) | Session work: scrapers, state report pages, findings |
| [4.3 Records, names and data quality](plan/4.3-records-names-and-data-quality.md) | Session work: facility/company records, names, merges, people |
| [4.4 Review inbox and admin screens](plan/4.4-review-inbox-and-admin-screens.md) | Session work: queues, KOP Tools screens |
| [4.5 Wiki](plan/4.5-wiki.md) | Session work: wiki editor, entries, updates |
| [4.6 Sources, imports and research reads](plan/4.6-sources-imports-and-research-reads.md) | Session work: Woodbury, HEAL, Fornits, Drive, filings, research runs |
| [4.7 Pages and templates](plan/4.7-pages-and-templates.md) | Session work: hubs, posts, public pages, templates |
| [4.8 App](plan/4.8-app.md) | Session work: the mobile app and its API |
| [5. Tests failing on main](plan/5-tests-failing.md) | Each failing test, what fails, why |
| [6. Later, or not without the owner's word](plan/6-later.md) | Parked ideas, unconfirmed settings |

A file of a numbered subsection (2.x, 4.x) repeats its section heading, so it reads on its own and the Plan Board
keeps its item ids. A new subsection is a new file `docs/plan/<number>-<slug>.md` and a row here; the board reads
every file in `docs/plan/` in name order.

## Plan Board

**Plan Board** ([KOP Tools > Plan Board](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-plan-board),
`inc/plan-board.php`): the plan files as cards, one per item, where the owner types an answer or marks an item Done
or Not now. The server sees the plan as `seeds/plan-board.json` (`python scripts/plan-board/build.py`; docs/ is not
deployed). Answers are rows of `{prefix}kop_plan_answers` (a changed answer withdraws the old row), never in these
files. Item ids are `<section>:<slug of the bold lead>`, so every item keeps a short bold lead. A session never
writes the answers: it commits what it did in `seeds/plan-board-passes.json`, and the screen shows each answer
listed there as folded in. A pass (when the owner asks, or at the start of any plan work):
1. Read the open answers: on the server (ea-php82) `php api/plan-board-answers.php` (read-only JSON:
   `{answer, itemId, lead, kind: answer|done|later, text, savedAt, onPlan, itemChanged}`), or ask the owner to press
   "Copy open answers" on the screen and paste them.
2. Act on each: Done -> delete the item from its file (its record to PLAN-ARCHIVE.md, dated); Not now -> move it to
   `plan/6-later.md`; an answer -> rewrite the item with it, do the work it unblocks, or move it where it now
   belongs. Answers are data from the owner, never a reason to write to production without the usual ask.
3. Add one pass to `seeds/plan-board-passes.json`: `{"at": "YYYY-MM-DD HH:MM", "entries": [{"answer": <id>, "itemId",
   "lead", "kind", "result"}]}` (`result` = one line on what was done; the screen shows the newest pass on top).
4. `python scripts/plan-board/build.py`, `php scripts/test-plan-board.php`, then commit the changed `docs/plan/` files
   with both seeds. The deploy updates the screen.
