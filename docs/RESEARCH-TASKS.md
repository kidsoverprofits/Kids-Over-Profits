# Research tasks

Facts that need checking before a record, a wiki draft or a page can say them for certain: two sources
disagree, KOP's record looks wrong, or a draft had to guess. Since 2026-10-23 the open ones live on the site, at
[KOP Tools > Research Tasks](https://kidsoverprofits.org/wp-admin/admin.php?page=kop-research-tasks), where the
owner gives each to themselves, to Claude or to a volunteer, reads the answers and settles them (with Undo). The
140 tasks this file listed then came over as they were; `git log -p docs/RESEARCH-TASKS.md` has the old text.

## Adding one (every session, unprompted, in the same commit as the work that found it)

```bash
python scripts/research-tasks/tasks.py add --section "Sequel renames"     --title "Which town was Norris Academy in?"     --text "The Sequel draft says Norris, TN; record 9614 says Andersonville, TN. The answer goes on 9614 and wiki draft 82."     --records 9614 --found-by "Sequel audit session"
```

It writes `seeds/research-tasks.json`; the task shows on the screen after the deploy. Say what is in doubt, what
each source says (with links), where the answer goes (record id, wiki draft, page), and who found it. `--records`
takes `facilities_v2` ids (checked against `tmp/prod.sqlite`); a record named gets its page linked on the card and
its facts shown to a volunteer. Use an existing section name when one fits (`tasks.py list` shows them).

## Working the ones given to Claude

"Work the research tasks" means: list them on the server (`php api/research-tasks.php --claude`, read-only, over
SSH from `~/public_html/wp-content/themes/child` with `/opt/cpanel/ea-php82/root/usr/bin/php`, or ask the owner to
copy them from the screen's Claude tab), research each, submit every new source per CLAUDE.md, then send the answer:

```bash
python scripts/research-tasks/tasks.py answer <key> --text "Closed March 2009: Iowa DHS notice <url>" --by "Claude session"
python scripts/research-tasks/tasks.py settle <key> --text "Fixed on record 9614, commit <url>"   # when the session also made the fix
```

An answer moves the card to Answered for the owner; it never replaces an answer already there. A task added on the
screen (key `site:...`) has no seed entry: report its answer in the session instead.

## Settled before the move

Tasks settled while this file was the list. New ones are settled on the screen (Settled or dropped tab).

- **Paul Geer's first year at the Family Foundation School**: 1991. WBNG's indictment report (4/9/2024) says
  1991; the NYT (4/4/2024) gives a career of more than 20 years there before the 2014 closure, and 1994 in the
  sentencing report is the year of the earliest charged conduct. Recorded in the seed and the wiki draft (2026-10-14).
