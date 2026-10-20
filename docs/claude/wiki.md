# Wiki

Commands, tests and rules for r/troubledteens wiki entries: contact, live compare, editor rules, name variants, wiki gaps and drafts, wiki staff and "Former". Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# Program wiki entries (wiki editor, /wiki-feed/): the contact is the r/troubledteens modmail (js/wiki-generation.js CONTACT_LINK ==
# api/lib-wiki-contact.php; saved rows rewritten once by inc/wiki-contact.php, bump KOP_WIKI_CONTACT_VERSION to rerun). Each entry
# is compared with its LIVE Reddit page (never markdown_output/, which is stale): fetch reads the pages in a real Chrome window
# (solve Reddit's human check when it asks; resumable), compare pairs entries by the page they were imported from, never by name
# -> js/data/reddit-wiki/live-compare.json (commit it) -> editor index badge + /wiki-feed/ note; no result = no mark
(kop-pipeline) python scripts/reddit-wiki-live.py fetch [--pages a b] [--refresh] && python scripts/reddit-wiki-live.py compare [--list]   # sync tmp/prod.sqlite first
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-contact.php [--list]   # rewrite, PHP == JS, migration on a copy, live-result states
# The editor (js/wiki-generation.js) writes staff as the drafts do: "a therapist" / "the Executive Director" / "worked in admissions",
# no "Former" or abbreviations in a role, a year only as "from X to Y", a closed program's staff in the past, one paragraph per person;
# normalizePunctSpacing() must match scripts/wiki-drafts.py punct_spacing() and kop_wiki_drafts_reddit_format()
node scripts/test-wiki-generation-rules.js       # role sentences, merging, a whole page; spacing JS == Python on every draft line
# One person, many names (inc/name-variants.php -> window.KOP_NAME_VARIANTS on the wiki editor): the people table's other names and
# merges, the map's, js/data/people/name-variants-reviewed.json (checked by hand: "same" groups, "distinct" pairs) and
# js/data/people/nicknames.json ("same": only that name, merged, Charlie = Charles; "maybe": warned, Sam = Samuel or Samantha).
# The editor writes one paragraph per person and lists names that may be one person above the staff list
php -d extension=pdo_sqlite scripts/test-name-variants.php   # every merge and other name is a group, on tmp/prod.sqlite; writes tmp/name-variants.json (run before the JS test)
# Wiki entries brought up to date from KOP (docs/PLAN.md 3.12, inc/wiki-updates.php): each entry (newest row per Reddit page) is
# linked to its facilities_v2 record at KOP Tools > Review inbox > Wiki links (wiki_submissions.facility_unique_name, 'suggested',
# Undo); gaps = what kop_facility_page_data() holds that the entry's markdown lacks (closure, names, operator, news, lawsuits,
# deaths, approved findings, incidents, staff); an entry about an earlier name gets only its own years, never today's status
php -d extension=pdo_sqlite -d extension=mbstring scripts/wiki-gaps.php [--id=471] [--list]   # -> tmp/wiki-updates/{links.json,gaps/<id>.json,report.md}
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-gaps.php                 # helpers, matching, gaps on real entries
# Drafts (tmp/wiki-updates/drafts/<id>/, written by a Haiku/Sonnet/Opus workflow, assembled by scripts/wiki-drafts.py) are reviewed at
# Review inbox > Wiki updates (inc/wiki-update-drafts.php): export ships only the ops, the site applies them to the entry's text as it is
# now; Approve writes KOP's copy (exact Undo), then Ready for Reddit has the whole text with Copy and Reddit's edit link
# Every record change drafts an update for its linked wiki entry (inc/wiki-auto-updates.php): kop_record_changed() queues the ids
# (facility, news, lawsuit, operator, wiki), an hourly job re-runs kop_wiki_upd_gaps() for the linked entries (plus a sweep of every
# linked entry, for writes that skip the event) and writes each new gap as wiki-script-drafts.py's line, placed as the editor's
# template places it -> Review inbox > Wiki updates from record changes (inc/review-inbox/wiki-changes.php; same tabs, exact Undo).
# New = not in the entry's keys (option kop_wiki_auto_known: its gaps at the first look, then each decision's); a new line kind
# goes in kop_wiki_auto_ops() and kop_wiki_auto_gap_key()
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-auto-updates.php [--list]   # script parity, change -> draft -> approve/undo, every entry applies
(kop-pipeline) python scripts/wiki-drafts.py assemble <ids> && python scripts/wiki-drafts.py export <ids>   # -> js/data/reddit-wiki/update-drafts.json (commit it)
# Approving a wiki entry puts its staff on the facility record, cited to the wiki page (kop_wiki_merge_submission(), api/sync-wiki-facilities.php);
# a name already listed stays; taking the approval back takes them off (kop_wiki_staff_undo(), log in option kop_wiki_staff_log)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-staff.php [--list]   # mapping, approve/again/undo, what approving every entry would add
# "Former" / "current" only when confirmed (owner, 2026-10-17): a wiki person is "Former <role>" when the program is closed or the write-up says so
# ("formerly", "retired", "no longer", a year range, "until 2015"; kop_wiki_staff_is_former()), never for the past tense alone;
# staff approved before that rule: php api/fix-wiki-staff-former.php [--list] [apply] [undo apply]   # on the server (ea-php82), log kept so Undo works
# The role is the title, not the parser's wording ("current Clinical Director", "one"): kop_wiki_staff_title() == js/wiki-parser.js staffRoleTitle()
node scripts/test-wiki-staff-title.js --php=<Local php.exe>   # JS == PHP on every wiki role in tmp/prod.sqlite
# Every other staff entry (Woodbury Facts, HEAL, Fornits, by hand) is "Former" when the program is closed or its source said the person left
# (", left"); never for an old sighting at an open program ("Admissions (2007, Woodbury Reports)" stays), and "current" comes off (inc/staff-former.php, applied by
# Woodbury Facts when it adds a person); names that are not people (a phase sheet read as a staff list) are refused. The "Former" the old rule
# put on: php api/fix-staff-unconfirmed.php [--list] [apply] [undo apply]   # on the server (ea-php82), log kop_staff_unconfirmed_log
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-staff-former.php   # the rule, the pass + undo on a copy of the mirror
```
