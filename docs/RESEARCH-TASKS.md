# Research tasks

Facts that need checking before a record, a wiki draft or a page can say them for certain: two sources
disagree, KOP's record looks wrong, or a draft had to guess. Since 2026-10-23 the open ones live on the site, at
[KOP Tools > Research Tasks](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-research-tasks), where the
owner gives each to themselves, to Claude or to a volunteer, reads the answers and settles them (with Undo). The
140 tasks this file listed then came over as they were; `git log -p docs/RESEARCH-TASKS.md` has the old text.

## Adding one (every session, unprompted, in the same commit as the work that found it)

```bash
python scripts/research-tasks/tasks.py add --section "Sequel renames" \
    --title "Is Norris Academy in Norris or in Andersonville, Tennessee?" \
    --text "The Sequel draft says Norris, TN; record 9614 says Andersonville, TN. The answer goes on 9614 and wiki draft 82." \
    --records 9614 --found-by "Sequel audit session" \
    --look "Tennessee DCS licence list, 2015 (our copy) | https://kidsoverprofits.org/wp-content/uploads/..."
```

It writes `seeds/research-tasks.json`; the task shows on the screen after the deploy. Two rules (owner, 2026-10-25):

- **The title is one simple question**, in plain words a volunteer with no background can follow, ending with "?":
  name the program (and town and state), company or person in full; no record ids, file paths or words like "era",
  "rename merge" or "operator link". `add` refuses a title without "?". A job for us rather than a fact to find
  (fix a draft, link reports, make records) is not a research task: it goes in `docs/plan/`.
- **It points to what we already have.** `add` fills "where to look" from `tmp/prod.sqlite`: the records' news
  articles, court cases, Woodbury Reports issues (our copies) and archived links, the links in `--text`; the
  screen adds each record's and company's KOP page. Add with `--look "What it is | https://..."` anything else KOP
  holds about it (a media library PDF, an archived page, a Drive document); it prints what it found, so read it.

`--text` is for us: what is in doubt, what each source says (with links), where the answer goes (record id, wiki
draft, page), who found it. `--records` takes `facilities_v2` ids (checked against the mirror); records and companies
the question names in full are found by name too (`tasks.py directions` rebuilds every task's list after a mirror
sync). Use an existing section name when one fits (`tasks.py list` shows them).

## Who works them

The owner gives a task to themselves, to Claude, or to one volunteer (ticked on the screen, "Send to a volunteer").
Every task given to nobody is also on every volunteer's page under "Research questions you can pick": a volunteer
ticks the ones they want (up to 5 unanswered at a time) and they become that volunteer's project. A question handed
back returns to the list. Give a task to yourself or Claude to keep it off the volunteers' list.

## Working the ones given to Claude

"Work the research tasks" means: list them on the server (`php api/research-tasks.php --claude`, read-only, over
SSH from `~/public_html/wp-content/themes/child` with `/opt/cpanel/ea-php82/root/usr/bin/php`, or ask the owner to
copy them from the screen's Claude tab), research each, submit every new source per CLAUDE.md, then send the answer.

**Cite every piece of information** (owner, 2026-10-25), the same rule volunteers answer under: each fact is its own
`--claim`, with the link to the page that says it and, when you can, the sentence that says it. A fact you cannot link
stays out of the answer; one page can back several facts (repeat its link), but never stands for a fact it does not
say. `answer` refuses an answer without a claim; `--text` is only for what is not a fact (sources that disagree, how
sure, what to check).

```bash
python scripts/research-tasks/tasks.py answer <key> --by "Claude session" \
    --claim "Rocky Mountain Academy closed in March 2009 | https://... | the academy shut its doors in March" \
    --claim "Its licence was surrendered the same month | https://... | licence surrendered 3/2009" \
    --text "The 2010 directory still lists it; it was printed before the closure."
python scripts/research-tasks/tasks.py settle <key> --text "Fixed on record 9614, commit <url>"   # when the session also made the fix
```

An answer moves the card to Answered for the owner; it never replaces an answer already there. A task added on the
screen (key `site:...`) has no seed entry: report its answer in the session instead.

## Settled before the move

Tasks settled while this file was the list. New ones are settled on the screen (Settled or dropped tab).

- **Paul Geer's first year at the Family Foundation School**: 1991. WBNG's indictment report (4/9/2024) says
  1991; the NYT (4/4/2024) gives a career of more than 20 years there before the 2014 closure, and 1994 in the
  sentencing report is the year of the earliest charged conduct. Recorded in the seed and the wiki draft (2026-10-14).
