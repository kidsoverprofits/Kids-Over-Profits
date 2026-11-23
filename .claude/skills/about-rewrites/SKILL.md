---
name: about-rewrites
description: Choose and write the next About rewrites. `/about-rewrites` (or `list`) shows the records the owner flagged at KOP Tools > About rewrites > Pick next, in order with notes; `/about-rewrites find <words>` searches every program and company page; `/about-rewrites flag <names or ids> [-- note]`, `unflag`, `top` change the flags from chat; `/about-rewrites work [N]` writes the next N flagged records (all of them when no N) through the batch pipeline and sends them to To review.
---

# /about-rewrites: pick what Claude writes next, then write it

The owner picks which records get a new About at KOP Tools > About rewrites > **Pick next** (search, Flag, order,
a note per record). This skill does the same from chat and runs the writing batch on the flags. Flags live on the
server (option `kop_ar_next`, theme `inc/about-rewrites.php`); a flag comes off by itself when that record's rewrite
arrives in To review.

## Works from any folder

The skill is linked into `%USERPROFILE%\.claude\skills\` (a junction to the theme checkout's copy). THEME is
`C:\Users\dani\source\repos\Kids-Over-Profits` and PIPE is `C:\Users\dani\source\repos\kop-pipeline`. Run the
`pick.py` commands from PIPE. Before `work`, read `THEME\CLAUDE.md` and `PIPE\scripts\about-rewrite\BRIEF.md`.
The research key is read by the scripts from `%USERPROFILE%\kop-research-key.txt`. Never print it.

## The commands

What the owner types after `/about-rewrites`, and what to run (from PIPE):

| Owner types | Run |
| --- | --- |
| nothing, or `list` | `python -I scripts/about-rewrite/pick.py list` |
| `find <words>` | `python -I scripts/about-rewrite/pick.py find <words>` (add `--kind facility` or `--kind operator`, `--show none` for no rewrite yet, `--sort news` or `--sort lawsuits`) |
| `find` with no words, or "what should be next?" | `python -I scripts/about-rewrite/pick.py find --show none --sort news --limit 25` |
| `flag <names or ids> [-- note]` | find each one, then `python -I scripts/about-rewrite/pick.py flag f123 o45 --note "<note>"` |
| `unflag <names or ids>` | `python -I scripts/about-rewrite/pick.py unflag f123` |
| `top <name or id>` (or `up` / `down` / `bottom`) | `python -I scripts/about-rewrite/pick.py move f123 top` |
| `work [N]` | the Work steps below |

Ids are packet ids: `f<id>` for a program, `o<id>` for a company. `find` prints them.

**Flagging by name.** Run `find` for each name first.
- When one result is plainly the match (the same name, and the owner didn't name a place that rules it out), flag it.
- When two or more could be it (Hyde School in Maine and in Connecticut; a program and its company of the same name),
  ask the owner once, listing the candidates with place, years and news count. Don't guess.
- Don't flag a record whose rewrite is waiting in To review: the server refuses it. Tell the owner to use **Send
  back to Claude** on its card instead.
- A name that finds nothing may not be in KOP at all. Say so; adding a record is a separate job (`docs/ADDING-CONTENT.md`).

Reply with the list as `pick.py` prints it after the change, and nothing more.

## Work (`/about-rewrites work [N]`)

This writes the flagged records in the owner's order: the first N of them, or all of them when no N is given. Each one
goes through the same pipeline as batches 1 to 3 (`docs/plan/2.5-writing-to-read-and-publish.md`, item 4, has the
history and lessons).

1. **Take the list.** Run `pick.py list` (for the notes and any sent-back draft) and `pick.py ids` (for the ids).
   Take the first N. If nothing is flagged, say so and stop: don't pick records yourself unless the owner says to.
2. **Make the batch folder.** In `PIPE\tmp\about-rewrite\`, make `batch<next number>\`. Copy from the newest batch:
   `WRITER.md`, `EDITOR.md`, `convert.py`, `linkcheck.py`, `check.py`, `build_manifest.py` and `url-map.json`.
   In the two .md files, change the batch number and paths. Add each record's note from the owner at the top of
   WRITER.md, under "The owner asked", naming the id.
3. **Export the packets** from the production mirror. First refresh it with `python ../kop-pipeline/scripts/sync-prod-sqlite.py`
   from THEME (a full sync, never `--tables`). Then, from THEME:
   `php -d extension=pdo_sqlite -d extension=mbstring scripts/export-about-packets.php --ids=<ids> --out=<PIPE>/tmp/about-rewrite/batch<N>/packets`
4. **Writers.** Use agents in waves of 3 to 5, with up to 3 records each, and keep related records together. Opus
   (medium effort) writes the best-known records; Sonnet writes the rest. Each agent gets:
   - WRITER.md's path and its ids;
   - the standing rules from THEME\CLAUDE.md (KOP's holdings first, sources found are submitted, the Wayback archive
     rule) and the research-key rule, so agents write not-sent JSON and the lead sends the sources;
   - an instruction to reply with one line per record.
5. **Editors.** Opus at high effort, one per group, in waves, with EDITOR.md. Every draft gets the edit pass before
   it is sent. The edit pass covers the endings rule (22a), testimony quoted word for word, and restoring claims
   that were cut only for having no link (rule 11).
6. **Check by script**, not by reading drafts: `python -I -X utf8 check.py`, then `convert.py <ids>` (fix every
   warning), then `linkcheck.py`. Map dead links in `url-map.json` and convert again.
7. **Sources and archives.**
   - Send the writers' sources: `python scripts/send-sources.py batch<N>/sources/*.not-sent.json` (from PIPE).
   - Wayback PDFs go through an archive agent (Sonnet) and `build_manifest.py`. Importing them into the media
     library needs SSH: ask the owner first.
8. **Send.** `python -I scripts/about-rewrite/send-rewrites.py tmp/about-rewrite/batch<N>/sendable/<id>.json ...`.
   Each flag comes off as its rewrite arrives. Run `pick.py list` to confirm.
9. **Research lines.** File the drafts' `research` lines in Research Tasks, as `/research-tasks add` describes
   (`TASKS.md` in `tmp/about-rewrite/` has the batch method). Then commit and push the theme's `seeds/research-tasks.json`.
10. **Record fixes** the editors found go to the owner as one list. Apply the ones the sources settle, as in batches
    2 and 3 (seed version bump, each one checked against its source).
11. **Plan and report.** Write what is still open in `docs/plan/2.5-writing-to-read-and-publish.md` under item 4 as
    "Batch <N> (flagged)". Then reply briefly:
    - how many were sent to To review, and which;
    - any `needs_you` questions;
    - sources sent;
    - research tasks filed;
    - what is still open.

Watch the usage limit: five Opus editors stopped partway through in batch 1. Agents run in waves and report one line
each. If a session has to stop, the next one resumes from whatever has no `sendable/<id>.json` yet.
