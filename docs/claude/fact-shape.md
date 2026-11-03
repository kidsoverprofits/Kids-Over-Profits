# One shape for every fact (spec, 2026-11-02)

Status: specification only, nothing built. It serves `docs/plan/4.4-review-inbox-and-admin-screens.md` item 20,
"Compare facts, not queues or fields". Read that item first, then `docs/DATA-FLOWS.md` (Woodbury Facts, Fornits,
news facts) and `docs/claude/review-inbox.md`. All numbers are from the mirror `tmp/prod.sqlite` of 2026-11-02 13:31.

## 1. Why, in one paragraph

The same fact reaches us from Woodbury Reports, HEAL, the r/troubledteens wiki, news articles and Fornits, and each
queue words it its own way: a closure is a `set_closed` row, a Fornits lead or a "Closed (2026-06-30) per news report"
line in `operatingPeriod.notes`; a founder is a "Founded by:" note or a staff entry; an injury is five lines from five
articles. `scripts/fact-dedupe.php` compares them only within a field class (`path_class()`: incident, notes, other)
and builds each source's line ad hoc. This spec turns every row, from every queue and from the record itself, into one
array (the fact shape), so grouping, "on hand" and the AI step all compare like with like.

## 2. What the mirror shows (read before building)

These numbers change what the work buys. The big ones:

- **News is mostly in the Woodbury queue already.** `news_facts` has 1,874 rows: 1,265 have `route = 'facility'` and
  were filed as Woodbury Facts rows (`extra.via = 'news'`, `kop_nf_wbf_row()`); 1,104 of those are pending there now.
  Only 539 still wait in `news_facts` (`route = 'waiting'`, `status = 'pending'`), and 533 of them have no
  `facility_id`. `fact-dedupe.php` counts the 1,104 as "woodbury", so its queue split hides that news is the biggest
  source of incident lines (666 of the 1,309 pending incident lines).
- **Few groups would mix sources.** On records, the same person key across origins gives 29 staff groups
  (Fornits+HEAL 15, Fornits+Woodbury 7, Fornits+wiki 3, the other four pairs 1 each). Closures and opening years
  give 0 such groups. Among no-record items, about 38 program-key groups mix origins (mostly notes). Most repeats come
  from many articles of one origin (the Provo injury is five news tellings), so a group's sources have to be counted
  per article or page, not per origin family. Section 8 defines `told_by` for that.
- **No waiting note is a closure.** No pending or held note starts with "Closed". The "Closed (date) per news report"
  lines (`kop_closure_source_note()`) and the "Closed in 2008 (sets status Closed, ...)" lines (`kop_wbf_source_line()`)
  are on the records, in `operatingPeriod.notes`. They are "on hand" lines, not waiting facts. The news reader leaves
  closures out on purpose (`kop_nf_build_prompt()`), so closures come only from `set_closed` (123 pending, 21 held),
  Fornits closure leads (127) and the records.
- **The note label decides the kind. Keywords inside a note do not.** Inside "Note:" lines, a closure or opening
  keyword is almost always about something else ("lowered fees ... due to a school closure", "opened an additional
  office in Dallas"). The only reliable signal inside a "Note:" is the also-known-as pattern that
  `kop_wbf_list_covers()` already uses (58 pending, 23 held).
- **Incident dates are often missing.** Of the 673 news-origin incident lines (pending and held), 343 have no date,
  and `kop_dd_event_parts()` gives them no kind either (it reads the "Kind:" label only after a date). So
  `kop_dd_same_event()` can never join them. Three of the five Provo jaw-injury lines are undated. Records also hold 84
  incident lines that do not start with an ISO date (16 "June 2026:" / "July 17, 2026:" style), and those are not read
  either.

## 3. The shape

`kop_fact_shape()` returns one array per fact, or null for a row that is not a record fact (section 5 lists those).

| Field | Example (Provo #10371, row 28458) | Grouping or display |
| --- | --- | --- |
| `kind` | `incident` | grouping: facts are compared only within a kind (or within the linked kinds in 6.2) |
| `type` | `injury` | grouping: incident category, or the sub-kind (`sale` under owner, `capacity` under size) |
| `record` | `10371`, or `p:teen challenge` with no record | grouping: groups never cross records |
| `subject` | the line's core: "A 13-year-old resident suffered a fractured jaw ..." | display; its words feed `words` |
| `keys` | `{person: "", org: [], name: [], url: "", nums: ["13"], ages: [13], people: []}` | grouping (per kind, 6.1) |
| `date` | `2026-05-14` | grouping: `YYYY`, `YYYY-MM`, `YYYY-MM-DD`, or `''` for unknown |
| `told` | `2026-06-15` (publication, issue month or post date) | display and the AI line only (an undated fact fits a dated one whatever `told` says, 6.3) |
| `place` | `[]`, or `["springville"]` | grouping: `kop_dd_campuses()` of the text, and `kop_wbf_street_key()` / city+state for `place` facts |
| `standing` | `alleged` | never keeps two tellings apart; it picks the keeper (`kop_fact_standing_rank()`, 6.4) |
| `words`, `dwords`, `caps` | from `kop_wbf_words()` on the core | grouping: rule and close-pair tests |
| `src` | `news` | counting: origin family, one of `woodbury`, `heal`, `wiki`, `news`, `questions`, `fornits`, `paper`, `record` |
| `told_by` | `["news:207"]` | counting and citing: one id per telling (6.6) |
| `queue`, `id` | `wbf`, `3f0ab72e...` (pkey); `fornits`, pkey; `news`, `n1359`; `doc`, `doc:criticalIncidents.customIncidents#12` | writing back |
| `state` | `waiting`, `held`, `on_record`, `kept` | grouping: on-hand facts clear waiting ones but are never cleared |
| `text` | the full line with its citation | display |
| `evidence` | the row's evidence list, as `kop_wbf_evidence()` gives it | display, and the folded entry's citations |
| `op`, `path` | `add_list`, `criticalIncidents.customIncidents` | writing back only |

How the fields are filled:

- **Unknown date is `''`**, never `0000` or a guess. "Reported 2007" (Woodbury) and a Fornits post's year are
  `told`, not `date`. "1970s", "active in the 1980s" and "in business since 2006" give `date = ''`, and the
  decade or "since" year stays in `subject`. `kop_fact_date()` reads ISO dates, "June 2026", "July 17, 2026" and
  "12/6/1869", so the 84 record lines in other formats get a date too.
- **`record`** is `facility_id` (for a held row, `applied_fid` when it is set). With no record it is
  `'p:' . kop_autoconn_key()` of the program name with a trailing "(...)" taken off. This is the key the exact-name
  index uses, so a group made before a record exists maps straight onto it later. `kop_wbf_org_key()`, which
  `fact-dedupe.php` uses now, gives 1,983 keys over the 6,372 no-record items; `kop_autoconn_key()` gives 1,974.
- **`keys.person`** is `kop_wbf_person_key()` of the person, plus a `suffix` field ("jr", "sr", "ii", "iii", "iv")
  taken before the key drops it. The plan asks never to fold "III" or "Jr." against the plain name, and the key
  alone cannot tell them apart.
- **`keys.org`** lists `kop_wbf_org_key()` of each company named. The body is split on `,` `;` `and` `&`, and filler
  ("umbrella corporation is", "operated by", "parent company:", "owned by") is taken off. A part counts as a person,
  and goes to `keys.person`, when `kop_wbf_person_key()` is not empty and `kop_staff_name_not_person()` is false and
  it has no company word (Inc, LLC, Schools, Services, Office, Center, Academy, Group, Health, Ministries, Family).
  Otherwise it is a company.
- **`keys.nums`** lists the numbers of the core, with dates and the "x-year-old" ages taken out (those go to `date`
  and `keys.ages`). **`keys.people`** holds the head counts `kop_dd_same_event()` reads ("17 former students").

## 4. The kinds

The owner's list is staff, incident, closure, opening, name, owner and size. The data needs these as well: operator
and investor apart from owner (a company that runs a program is not always its owner, and `kop_investors` is a separate
store), sale (an ownership change, with a date), founder (a note naming who founded it), place, ages, gender, type,
cost, detail, license, membership, money, practice, connection, statement, link, testimony and note.

| Kind | `type` values | Key that blocks candidates (6.1) | Single value per record? |
| --- | --- | --- | --- |
| staff | the role as given | `person` | no |
| founder | `person` or `company` | `person` (shared with staff) or `org` (shared with owner) | no |
| incident | the category (`death`, `injury`, `abuse`, `sexual_abuse`, `restraint`, `seclusion`, `neglect`, `runaway`, `arrest`, `criminal_charge`, `conviction`, `license_action`, `citation`, `fine`, `placement_ban`, `investigation`, `lawsuit`, `settlement`, `verdict`, `closure_order`, `legislation`, `incident`) | `type` + year (or the `told` year and the year before it) | no |
| closure | `closed`, `suspended`, `reopened` | record | yes: end year |
| opening | `opened` | record | yes: start year (an "Opened:" note with no year is about a new office or program and has no value) |
| name | `past`, `other`, `renamed` | each name's `kop_dd_name_key()` | no |
| operator | `current`, `past` | each `org` | no |
| owner | `owner`, `parent` | each `org` / `person` | no |
| sale | `acquired`, `sold`, `merger` | each `org` | no |
| investor | `investor` | each `org` | no |
| size | `capacity` | record | yes, when a year is given or both are current |
| ages | `ages` | record | yes: min and max |
| gender | `gender` | record | yes: `kop_wbf_gender_of()` |
| type | `type` | record | no: `kop_wbf_value_same()` decides |
| cost | `cost` | record | no |
| detail | `length_of_stay` | record | no |
| license | `license` | the acronyms in it | no |
| membership | `membership`, `accreditation` | the acronyms in it | no |
| place | `address`, `campus`, `move`, `former_location` | street key, city and state, or campus name | no |
| money | `public_money`, `revenue`, `placements` | record | no |
| practice | `practice` | record (words) | no |
| connection | `connection` | each `org` / `person` | no |
| statement | `statement` | the speaker's `person` (or `org`) | no |
| link | `forum`, `article`, `lawsuit` | `kop_normalize_urls()` key | no |
| testimony | `survivor`, `staff`, `parent` | none: never grouped | no |
| note | `note` | record (words) | no |

## 5. Mapping, source by source

Counts are pending / held for Woodbury Facts, and pending for Fornits and news facts. "Origins" splits a row
by its evidence (`issue` "heal-..." is HEAL, "wiki-..." the wiki, `extra.via = 'news'` news, else Woodbury Reports).
No program-paper rows are in the queue on the mirror (program papers write to `inc/program-practices.php`);
`src = 'paper'` is reserved for when they do.

### 5.1 Woodbury Facts (`{prefix}kop_woodbury_facts`), structured ops

| op + path | Count | Kind | How the fields are filled |
| --- | --- | --- | --- |
| `add_staff` `staff.notableStaff` / `staff.administrator` | 4,471 / 2,330 (pending: Woodbury 1,497, HEAL 2,414, wiki 445, news 115) | staff | `keys.person` from `value.name`; `type` = `value.role`; a year range in the role becomes `date` (start year); `subject` = name |
| `set_closed` `operatingPeriod` | 123 / 21 (HEAL 100+15, wiki 17+6, Woodbury 6) | closure | `date` = `value.endYear` or `''` |
| `set_if_empty` `operatingPeriod.startYear` | 300 / 42 | opening | `date` = the year |
| `set_if_empty` `facilityDetails.ageRange` | 10 / 37 | ages | `keys.nums` = [min, max] |
| `set_if_empty` `facilityDetails.capacity` | 4 / 20 | size | `keys.nums` = [n] |
| `set_if_empty` `facilityDetails.type` | 10 / 25 | type | `subject` = the type |
| `set_if_empty` `facilityDetails.gender` | 0 / 15 | gender | `type` = m, f or c |
| `add_list` `identification.pastNames` | 6 / 7 | name (past) | `keys.name` = [`kop_dd_name_key()`] |
| `add_list` `identification.pastOperators` | 8 / 9 | operator (past) | `keys.org` |
| `add_list` `location.formerLocations` | 2 / 10 | place (former_location) | city and state, as `kop_wbf_list_covers()` reads them |
| `add_list` `criticalIncidents.customIncidents` | 1,309 / 443 (pending: news 666, HEAL 564, wiki 58, Woodbury 21) | incident | `type` = `extra.category` for news rows, else the "Kind:" label after the date ("Closure order" gives `closure_order`); `date` via `kop_fact_date()`; `told` = the evidence's `date` or `issue_date`; `place`, `keys` from the core |
| `consultant_jobs` `referrer` | 1 / 0 | none | not a record fact (a consultant's jobs); never grouped |

Dates on incident lines (pending and held): Woodbury-family lines have a day on 60, a month on 561, a year on 258,
"Reported YYYY" on 188 and nothing on 12. News lines have a day on 135, a month on 117, a year on 78 and nothing on 343.

### 5.2 Woodbury Facts `add_list` `notes`, by label

`kop_fact_note_kind()` reads the "Label:" prefix (`/^([A-Z][\w \/'-]{0,30}):/`). For news rows it uses
`extra.category` instead (more reliable than the label). Pending notes: 1,871. Held: 608.

| Label | Pending / held | Kind (`type`) | Notes |
| --- | --- | --- | --- |
| Note | 380 / 162 (after the next row) | note | words only |
| Note with "also known as / formerly / renamed / rebranded / now called / aka" | 58 / 23 | name (other) | the names after the phrase go to `keys.name` |
| Name change | 60 / 9 | name (renamed) | "A -> B": both keys, the one that is not the record's own is the subject |
| Described as, Type | 271 / 41 | type | |
| Ages | 180 / 17 | ages | numbers |
| Gender | 39 / 4 | gender | `kop_wbf_gender_of()` |
| Capacity | 78 / 14 | size (capacity) | numbers; an "(as of 2010)" year goes to `date` |
| Operator | 112 / 24 | operator | `keys.org` (and `keys.person` for a person) |
| Owner | 36 / 16 | owner | same split |
| Acquired, Sale, Merger | 35 / 18 | sale | `keys.org`; a year to `date` |
| Investor | 1 / 0 | investor | |
| License | 76 / 47 | license | |
| Accreditation, Membership | 118 / 56 | membership | acronyms and years, as `kop_wbf_list_covers()` reads them |
| Campus, Address, Move | 143 / 68 | place | street key, or city and state, or campus name |
| Opened, Start year | 27 / 7 | opening | `date` only when the body leads with a year ("1976 per HEAL ..."); otherwise `''` |
| Founded by | 31 / 15 | founder | split into persons and companies (3); "in business since 2006" gives founder with no subject and `date = ''` |
| Length of stay | 54 / 44 | detail | numbers |
| Cost | 28 / 43 | cost | numbers |
| Public money, Revenue, Placements | 27 / 0 | money | numbers |
| Statement | 65 / 0 | statement | speaker = the first person name in the line |
| Practice | 34 / 0 | practice | words |
| Connection | 18 / 0 | connection | `keys.org` / `keys.person` |

**Result: 1,491 of the 1,871 pending notes (80%) and 446 of the 608 held notes get a kind more specific than
"note".** Of the pending ones, 118 are names, 271 types, 180 ages, 143 places, 118 memberships, 112 operators and 78
sizes.

### 5.3 Fornits (`{prefix}kop_fornits_items`, pending)

| Kind on the row | Count | Kind | How the fields are filled |
| --- | --- | --- | --- |
| link `{url, board}` | 7,013 | link (forum) | `keys.url` = `kop_normalize_urls()`. The Fornits on-file rule uses `kop_gdl_url_key()` instead; the shape uses one key (open question 6) |
| staff `{person, role, years}` | 1,882 | staff | `keys.person`; `type` = role; `date` = the first year of `years`; `told` = the post month |
| incident `{category, year, summary}` | 1,365 (abuse 568, investigation 122, other 109, death 107, restraint 105, sexual_abuse 74, neglect 73, lawsuit 73, injury 60, runaway 44, seclusion 30) | incident | `type` = category (`other` gives `incident`); `date` = year (599) or `''` (766); `told` = the post date |
| testimony `{who, years, summary}` | 1,352 | testimony | never grouped: each post is one person's account; only its own id on `survivorTestimony` clears it, as now |
| lead, type closure | 127 (82 with a year) | closure | `date` = year |
| lead, type investigation / lawsuit | 227 / 191 (58 / 52 with a url) | incident (`investigation` / `lawsuit`); with a url also a link (`article` / `lawsuit`) | the url key meets the news and lawsuit links |
| lead, type news | 120 (97 with a url) | link (article) with a url, note without one | |
| lead, type other | 99 | note | |

### 5.4 News facts still waiting (`news_facts`, `route = 'waiting'`, `status = 'pending'`: 539)

A news fact becomes a shape only when it has a record (its own `facility_id`, or one from the rule in section 7).
Without a record it is counted under "no record" and never grouped. The mapping, by category (waiting count):

| Category | Waiting | Kind (`type`) | Fields |
| --- | --- | --- | --- |
| death 10, injury 10, abuse 18, sexual_abuse 8, restraint 3, seclusion 2, neglect 4, runaway 0 | 55 | incident (the category) | `date` = `event_date` (`kop_nf_clean_date()`; 345 of the 539 are empty); `told` = publication date; `standing` |
| arrest 18, criminal_charge 37, conviction 15, license_action 2, citation 6, fine 6, placement_ban 1, investigation 41, lawsuit 27, settlement 4, verdict 3 | 160 | incident (the category) | as above; `keys.person` = `person` when given |
| staff | 83 | staff | `keys.person` = `person` (the staff member). `subject_name` is the employer, and that is the name the record rule uses |
| owner 7, operator 11, acquired 1, investor 4 | 23 | owner / operator / sale / investor | `keys.org` from `value`, else from `summary` |
| opened 3, renamed 1, moved 0 | 4 | opening / name / place | `date` = `value` year; "A -> B" names; "from -> to" places |
| capacity 7, ages 3, gender 0, program_type 17 | 27 | size / ages / gender / type | `value` |
| funding 4, rate 1, revenue 1, placement 1 | 7 | money / cost | numbers of `value` |
| practice 18, connection 8 | 26 | practice / connection | |
| statement | 154 | statement | speaker `keys.person` = `person` |

None of the 37 categories is outside the record wholesale. What decides is the subject: **a fact whose `subject_kind`
is `person` (206) or `agency` (108) is not a record fact and is never grouped**, because it is about a person or a
government body, not a program (a governor's statement, a sheriff's arrest, a department director). Those 314 stay at
Facts from news until people and agencies have their own Add. That pass is not part of this work.

The 1,104 pending Woodbury rows with `extra.via = 'news'` are shaped by 5.1 and 5.2 with `src = 'news'`,
`type` = `extra.category`, `told` = evidence `date`, and `told_by` = `news:<news_id>` per evidence entry.

### 5.5 On hand: the record, its held items, kept news facts

`kop_fact_onhand($doc, $fid, $held_rows, $kept_news)` returns shapes with `state = 'on_record'`, `'held'` or `'kept'`:

- **Fields:** `operatingPeriod.status` and `endYear` give one closure fact (when Closed). `startYear` gives an
  opening fact. `facilityDetails.capacity`, `ageRange`, `gender` and `type` give size, ages, gender and type. Each staff
  entry gives a staff fact. `identification` names, operators and owners give name, operator and owner facts.
  `resourceLinks` urls and the record's news links (`news_facility_links` joined to `news_submissions`, article and
  archive url) give link facts.
- **Lines:** `criticalIncidents.customIncidents` lines give incidents. `notes` lines go through 5.2.
  `operatingPeriod.notes` lines go through the same label map, plus three line forms that only appear there:
  "Closed / Suspended / Reopened / Opened (YYYY-MM-DD) per news report: ..." (`kop_closure_source_note()`, the regex
  `inc/profile-notes.php` already uses), "Closed in YYYY (sets status Closed, ...)" and "Reported closed on Fornits: ..."
  give closures; "Start year: YYYY" gives an opening; "Ages 13-17 (...)" and "Serves: Female" give ages and gender.
  These lines carry citations: when a line and a field say the same, they are one fact and the line's evidence is kept.
- **Held items:** `status = 'held'` rows of the record (`applied_fid`) are shaped directly by 5.1 and 5.2. Laying
  them over a copy of the doc with `kop_wbf_doc_apply()`, which the script does now, is still needed for
  `kop_wbf_on_record()` and for conflicts.
- **News already filed:** Woodbury rows with `via = 'news'` that are held or applied count as above. `news_facts`
  rows with `status = 'kept'` (37) count when their record resolves to this one. A fact of an article linked to the
  record counts for that record only when the fact itself is about it (its `facility_id`, or section 7 gives it). An
  article linked to Provo Canyon that tells of a death at Maple Lake (news facts 1098-1101) is not on hand for Provo.

## 6. Grouping on the shape

### 6.1 Candidates

Two facts are candidates when they have the same `record` and share a block key. Groups never cross records.
`kop_fact_blocks($f)` gives the keys:

- staff: `person:<key>`; founder, owner and connection with a person subject: the same `person:<key>`
- incident: `incident:<type>:<year>` and `incident:<type>:any` for a dated fact; `incident:<type>:any` for an undated one,
  so an undated telling meets every dated one of its type (owner, 2026-11-03: any number of years apart)
- closure, opening, size, ages, gender, type, cost, detail, money, practice, note: `<kind>` (one bucket per record;
  values and words decide inside it)
- name: `name:<key>` for each name
- operator, owner, sale, investor, founder (company), connection (company): `org:<key>` for each company
- link: `url:<key>`
- license, membership: `<kind>:<acronym>` for each acronym
- place: `place:<street key>`, `place:<city|state>`, `campus:<name>`
- statement: `said:<person key>`
- testimony: no key

A bucket over 400 facts (the largest records) falls back to `candidate_pairs()` (two shared distinctive words, or
one kind in one year), as the script does now.

### 6.2 Kinds that meet

The point of item 20 is that a fact told in two fields meets itself. Inside a candidate block, these kinds compare
with each other. All others compare only within their own kind.

| Meet | Same fact when |
| --- | --- |
| staff ~ founder (person) ~ owner (person) | same person (`kop_dd_same_person()`), and the staff role says found / own as the note does (the `founded by` / `owner` rule inside `kop_wbf_list_covers()`, moved into a shared helper) |
| owner ~ operator ~ sale ~ founder (company) | same company (`kop_wbf_org_is()`). Owner and operator are the same fact only when both say the same relation ("parent company" vs "runs it" goes to the AI step, never folds by rule) |
| name (note "also known as") ~ name (pastNames / otherNames) ~ name (renamed) | same `kop_dd_name_key()` |
| closure (set_closed) ~ closure (Fornits lead) ~ closure (record line) | dates fit (6.3) and the end years are equal when both give one |
| incident or note that is a closure order ~ closure | never folded or cleared, either way round (owner, 2026-11-03): an order and a closing are two facts. A closure order is an incident or note of type `closure_order`, or whose words say ordered closed / to cease / shut down, closure or cease order, license revoked, cease and desist (`kop_fact_is_closure_order()`). `kop_fact_differs()` gives "closure order", so the rules, `kop_fact_close()`, an AI "same" (`kop_fold_ai_apart()`) and the on-hand check (`kop_wbf_on_record()` through `kop_fact_closure_clash_why()`) all keep them apart |
| opening (set_if_empty) ~ opening ("Opened: 1976 per ...") ~ opening (record) | same year |
| link (Fornits lead url) ~ link (record news link) ~ link (resourceLinks) | same url key |

### 6.3 Never the same

`kop_fact_differs($a, $b)` returns a reason, or `''` when nothing keeps them apart. Any reason means they never
join, by the rules or by the AI:

- **dates:** both are dated and differ: different years; different months when both give one; different days when
  both give one. An empty date never blocks (owner, 2026-11-03): an undated fact fits a dated one any number of years
  apart (the `told` date no longer limits it); two different dates still never join.
- **closure order:** a closure order against a closure fact (6.2), whatever the words.
- **standing** never keeps two tellings apart: "alleged" and "found by regulators" are one event at two stages.
- **numbers:** `keys.nums` differ when both have numbers (the script's current rule, which keeps the 44 pairs
  `kop_wbf_text_same()` wrongly calls the same apart).
- **ages and head counts:** the `kop_dd_same_event()` checks, applied to every kind.
- **places:** both name campuses and none is shared (`kop_dd_campuses()`). Two `place` facts with different street
  keys, or different cities, also never join.
- **people:** `kop_dd_same_person()` is false, or the two names have different suffixes (Jr / III vs none).
- **single values:** closure end year, opening year, ages, gender and a dated size that differ are not one fact. They
  stay apart in the group listing as `differs`, and a waiting fact that differs from an on-hand one is a conflict
  (Review inbox > Conflicts, through `kop_wbf_conflict_parts()`), never cleared.

### 6.4 What the rules decide inside a candidate pair

`kop_fact_rule_same($a, $b)` returns how they matched, or `''`. The first test that applies:

1. `kop_fact_differs()` returns a reason: not the same.
2. Same `keys.url` (link), same `keys.person` plus a role the kept entry can take (staff: the roles join with
   `kop_dd_join_role()`; 421 of the 473 Fornits staff folds join different roles), same name key, same company with
   the same relation, or the same single value (closure year, opening year, ages, gender, size): same.
3. Incidents: `kop_dd_same_event()`. It needs the date and the kind, so it is called on lines rebuilt from the shape
   as `"<date>: <Type>: <subject>"` (the shape supplies the kind the label lacked on undated lines), with the standing
   words ("alleged", "found by regulators", ...) taken out of the core first (`kop_fact_strip_standing()`). For two
   undated lines, or one undated: the same words by `kop_wbf_text_same()` join by rule ("text"); otherwise the pair goes
   to step (d) if it is close.
4. Every other kind: `kop_wbf_text_same()` on the two cores, both ways. For type, `kop_wbf_value_same()`.

Close pairs (the script's `close_pair()`: dates fit, at least 3 shared distinctive words, Jaccard at least 0.30) that
the rules did not join go to the AI step (d). So do all owner/operator pairs with the same company and a different
relation, and the undated incident pairs (any dated telling of the type, any years apart). The prompt tells the model
that an undated line can be a dated one's event, that alleged and found are one event, and that a state's order to close
is not the closing (`KOP_FOLD_AI_VERSION` 'v2' re-asks the cached answers). The AI's answer (`same_as <n>`, `new`, `differs`)
is checked against `kop_fact_differs()` before it is used: an AI "same" over different numbers is dropped.

### 6.5 The Provo Canyon injury, worked through

Record #10371, pending Woodbury rows, all news:

| Row | Line (core) | date | told | Rule result |
| --- | --- | --- | --- | --- |
| 28458 | 13-year-old, fractured jaw, traumatic brain injury, slammed into the ground | 2026-05-14 | 2026-06-15 | kept (most detail) |
| 28504 | teen, jaw injury and brain bleed, fight at the Provo facility | 2026-05 | 2026-06-02 | fits 28458; words close: (d) |
| 28373 | 13-year-old, broken jaw and brain bleed, slammed onto his head | `''` | 2026-07-17 | fits (undated, told after); (d) |
| 28423 | 13-year-old seriously injured in a fight, staff did not call 911 | `''` | 2026-06-23 | fits; (d) |
| 28289 | 13-year-old beaten unconscious, life-threatening head injuries | 2026 | 2026-08-04 | fits the year; (d) |

The rows on the same event in another category (28459 and 28505, neglect: care delayed an hour; 28405 and 28416,
investigation) are other facts: the same event seen as a different harm. They are never folded into the injury.
The record's own line "June 2026: licensers sanction the school for delaying care to a teen with a jaw injury ..."
is read as `date = 2026-06`, `type` = (none: no label), so it is an on-hand line the AI step sees for the neglect and
citation facts, not a rule match.

### 6.6 Counting sources

A group's `told_by` is the union of its members' `told_by` values. They are `woodbury:<issue>:<page>`,
`heal:<issue>`, `wiki:<issue>`, `news:<news_id>`, `fornits:<topic>-<post>`, `questions:<pkey>` and
`record:<path>`. "Cites N sources" is the count of that union. The source combination is the sorted set of `src`
families. Both are reported.

## 7. Linking news facts to records: the exact-name rule

### 7.1 The rule

A waiting news fact with no `facility_id` gets a record when:

1. its `subject_kind` is `facility` or `company`. A `person` or `agency` subject never links by name (5.4). The
   measurement shows why: five facts about Manitoba's Child and Family Services agency ("PCs renew calls for CFS
   inquiry") would otherwise link to #100501, a New York record named "Child and Family Services".
2. `kop_autoconn_key($subject_name)` is a key of exactly one record in `kop_autoconn_name_index()` (name, current,
   other and past names), and `kop_autoconn_distinctive($subject_name)` is true. The full key must match: no partial
   names, no distinctive-word guesses, no fuzzy match.
3. or, when several records hold that key, exactly one of them is linked to the fact's article
   (`news_facility_links`). That is the "article linked to the record, and the subject is its name or alias" case.
   It only breaks a tie between exact names; it never links on its own.

For a staff fact the name is `subject_name` (the employer). `person` is the staff member, which `kop_nf_wbf_row()`
puts into `add_staff`.

### 7.2 Measured on the mirror (533 waiting facts with no record)

| Outcome | Facts |
| --- | --- |
| `person` subjects (never linked) | 206 |
| `agency` subjects (never linked; 5 would have matched #100501 wrongly) | 108 |
| facility or company subject, one record holds the exact key: **linked** | 16 (Venture Academy 7, Madison County Detention Center 4, Maple Lake Academy 2, Diamond Ranch Academy to #10545 Three Points Center by its past name 1, Discovery Ranch 1, Foundations for Living 1) |
| several records hold the key, the article picks one: **linked** | 4 (Embark Behavioral Health 2, to #100093; Juvenile Detention Center 2, to #9999) |
| several records hold the key, no tie-break: **ambiguous** | 2 (Hyde School, #11021 and #100107) |
| facility or company subject, no record of that exact name | 197 (69 facility, 128 company); 50 of them are exactly one company's name in `kop_operators` (Newport Healthcare, Wayne Halfway House), a company link for later |

So **the rule links 20 of the 533 (4%), 2 are ambiguous, and 314 name a person or an agency.** The rule does not get
news facts to records in bulk. What would: a company link (50), and a person pass for the 206.

11 of the 16 single matches are facts whose article is already linked to that very record. The model left
`facilityId` empty although the subject was the linked program's exact name. That is a reader bug as much as a
linking gap, so the same rule also runs inside `kop_nf_parse_reply()` against the linked records only.

### 7.3 Functions

- `kop_autoconn_exact_record(PDO $pdo, string $name, array $prefer_ids = array()): array` in `inc/auto-connect.php`
  returns `array(int $fid, string $how)`, with `$how` one of `exact`, `article` (the tie broken by `$prefer_ids`),
  `ambiguous`, `generic` (one holder but `kop_autoconn_distinctive()` false) or `none`. It reads
  `kop_autoconn_name_index()` and nothing else. The existing callers (`kop_autoconn_names_only()`, the Woodbury and
  wiki passes) keep their own logic. This is the one function both the live code and `fact-dedupe.php` call.
- `kop_nf_record_for(PDO $pdo, array $fact): array` in `inc/news-facts.php` applies the subject-kind guard, then calls
  `kop_autoconn_exact_record()` with the article's linked ids. Same return shape.
- In `kop_nf_parse_reply()`: when `facility_id` comes out 0, call `kop_autoconn_exact_record($pdo, $subject,
  array_keys($ids))` and keep its id only when that id is one of the linked ones (`$ids`). The reader then routes the
  fact to Woodbury Facts as any linked fact.

### 7.4 Where the link is written

1. **Measurement first.** `fact-dedupe.php` calls `kop_nf_record_for()` and treats the result as the fact's record
   for that run. Nothing is written, and the outcome table above is printed.
2. **Then live** (after the owner reads the sample): `kop_nf_link_waiting(PDO $pdo, $write, $seconds)` on the hourly
   auto-connect run (`kop_autoconn_run()`, source `news-facts`, key = `news_facts.id`). For each fact it links, it sets
   `news_facts.facility_id`, builds the row with `kop_nf_wbf_row()` (which needs the news row and `linked_name`),
   files it with `kop_nf_file_wbf()` (a second article's telling joins the waiting row as evidence), and sets
   `route = 'facility'`, `wbf_pkey`, `status = 'filed'`. `kop_autoconn_mark()` records it, so one a person undoes is
   never linked again. `kop_autoconn_log()` puts it in Recently done with Undo. Undo takes the evidence off the
   Woodbury row, or deletes the row when this link created it and it is still pending, then resets `facility_id`,
   `route = 'waiting'`, `wbf_pkey = ''` and `status = 'pending'`. This is a new write path: add it to
   `docs/DATA-FLOWS.md` (news facts store) in the same commit.

## 8. PHP: `inc/fact-shape.php`

A new plain-PHP file, loaded by `functions.php` after `inc/woodbury-facts.php` and required by
`scripts/fact-dedupe.php`. It requires `api/lib-record-dedupe.php` and expects the `kop_wbf_*` helpers (it checks
`function_exists` and exits with a clear message otherwise). It reuses the existing helpers and duplicates none of
them.

```php
/** kind => [label, single_valued (bool), groupable (bool)]. */
function kop_fact_kinds(): array;

/** One fact in the common shape, or null when the row is not a record fact.
 *  $src: 'wbf' | 'fornits' | 'news' (a news_facts row) | 'doc_line' ($row = ['path' => .., 'line' => .., 'i' => ..]).
 *  $ctx: ['fid' => int (a record from kop_nf_record_for()), 'record_name' => string, 'state' => 'waiting'|'held'|'on_record'|'kept']. */
function kop_fact_shape(string $src, array $row, array $ctx = array()): ?array;

/** The record's own facts: fields, lines, news links (section 5.5), with held rows and kept news facts. */
function kop_fact_onhand(array $doc, int $fid, array $held_rows = array(), array $kept_news = array(), array $news_urls = array()): array;

/** "Label: body" -> [kind, type, body]; the one label map (5.2). */
function kop_fact_note_kind(string $line, string $news_category = ''): array;

/** Normalized date ('YYYY' | 'YYYY-MM' | 'YYYY-MM-DD' | '') of a line's lead or of a value; reads ISO, "June 2026", "July 17, 2026", "12/6/1869". */
function kop_fact_date(string $s): string;

/** Whether two shapes' dates allow one event (6.3), told dates included. */
function kop_fact_dates_fit(array $a, array $b): bool;

/** Block keys (6.1). */
function kop_fact_blocks(array $f): array;

/** Why two facts are never one ('' when nothing keeps them apart) (6.3). */
function kop_fact_differs(array $a, array $b): string;

/** How two facts are one by rule ('' when not) (6.4); calls kop_fact_differs() first. */
function kop_fact_rule_same(array $a, array $b): string;

/** Close enough for the AI step (d). */
function kop_fact_close(array $a, array $b): bool;

/** Record key: (string) $fid, or 'p:' . kop_autoconn_key(name without its trailing "(...)"). */
function kop_fact_record_key(int $fid, string $program): string;

/** Persons and companies a body names (section 3, keys.person / keys.org). */
function kop_fact_names(string $body): array;   // ['persons' => [[name, key, suffix]], 'orgs' => [key]]
```

`kop_fact_record_key()` calls `kop_autoconn_key()`, so `inc/fact-shape.php` also requires `inc/auto-connect.php`
when it is loaded outside WordPress. That file exits without `ABSPATH`, which the test harness defines.

Moves rather than copies: the Fornits incident and lead lines `fact-dedupe.php` builds by hand are the lines
`kop_fornits_doc_apply()` writes. Extract `kop_fornits_incident_line(array $r)` and `kop_fornits_lead_line(array $r)`
in `inc/fornits.php`, have `kop_fornits_doc_apply()` call them, and have the shape use them for `text`. Likewise, the
founder / owner person check inside `kop_wbf_list_covers()` becomes `kop_wbf_person_role_on_record(array $doc, string
$name, string $as)`, which both it and `kop_fact_rule_same()` call.

The live code that uses the shape later: `kop_wbf_on_record()` and `kop_wbf_clear_on_record()` for step (b),
the folding pass for (c)-(e), and `kop_nf_link_waiting()`. This spec does not change their behaviour; it gives them
one input.

## 9. Changes to `scripts/fact-dedupe.php`

1. Delete `row_text()`, `path_class()`, `INCIDENT_NEWS`, `make_item()` and the three hand-built line builders
   (Woodbury, Fornits, news). Every item, waiting and on hand, comes from `kop_fact_shape()` and `kop_fact_onhand()`.
   `make_item()`'s comparison fields (`core`, `words`, `dwords`, `nums`, `caps`, `ev`) are filled by the shape.
2. Load the 539 waiting news facts with their article (`news_id`) and run `kop_nf_record_for()` on each one with no
   `facility_id`. Print the section 7.2 table, with 20 examples per outcome. A linked fact joins its record's items
   for the rest of the run.
3. Group per `record` by `kop_fact_blocks()` instead of by `cls`, with one union-find per record, so kinds that meet
   (6.2) can join. Keep the step buckets (0 baseline, b held, c fold, d AI) and their totals table, so the numbers stay
   comparable with the 2026-11-02 run. Report the queue split by `queue` (wbf / fornits / news) **and** by `src`
   (woodbury / heal / wiki / news / fornits / questions), so the 1,104 news rows in the Woodbury queue show as news.
4. **Groups that mix sources.** For each group of two or more (waiting, or waiting plus on hand), count by the
   source combination (`heal+news`, `fornits+woodbury`, `fornits+heal+wiki`, ...) and by kind, with the number of
   tellings (`told_by`). Print 20 examples per combination, each member's `src`, `told_by`, `text` and how it joined.
   Print two more tables beside it: "one origin, several tellings" (e.g. five news articles) and "joined across kinds"
   (6.2), e.g. a "Founded by:" note with a staff entry, or a Fornits closure lead with a set_closed row.
5. `--shapes` (with `--facility=N`) prints every shape of the record, one per line (kind, type, date, told, place,
   keys, src, state, text), to check the mapping by eye. `--json` writes the shapes and the groups.
6. Add `notes_specific` to the summary: notes pending and held by the kind `kop_fact_note_kind()` gives (the 5.2
   counts), so the claim "1,491 of 1,871" can be read off each run.

## 10. Tests

A new file `scripts/review-inbox-tests/fact-shape.php`, which the test runner's glob loads, defines
`kop_test_fact_shape(callable $check)` with fixtures, no mirror rows. `kop_rinbox_test_woodbury_facts()` in
`scripts/review-inbox-tests/woodbury-facts.php` calls it. The end-to-end fold stays in `scripts/test-review-on-file.php`,
as the plan says.

1. **Provo Canyon, the jaw injury told five ways** (6.5): five shapes, all `incident/injury`, record 10371. 28458 and
   28504 fit by date. 28373 and 28423 (undated, told after) fit. 28289 (year only) fits. None is joined by rule except
   where the rules can tell. All five land in one AI candidate group. A sixth telling dated 2026-07 does not fit
   28458's day and never joins. The neglect lines 28459 and 28505 are in another block.
2. **Two campus revocations** in July 2026 (Springville vs Provo campus): `kop_fact_differs()` = places.
3. **Closure three ways**: a `set_closed` {endYear 2017}, a Fornits closure lead year 2017, and a record line
   "Closed in 2017 (sets status Closed, ...)" give one closure fact. With endYear 2018 on one of them: `differs`, and
   against the record it is a conflict.
4. **Founder in a note vs a staff entry**: "Founded by: Arthur Robert Barker (HEAL ...)" and a staff entry "Art
   Barker, Founder" join (person key, role says found). "Founded by: in business since 2006" gets no person and no
   date and joins nothing.
5. **Name in a note vs pastNames**: "Note: also known as Benchmark Transitions (...)" and pastNames "Benchmark
   Transitions" are one name fact.
6. **Owner vs operator**: "Owner: Universal Health Services is the parent company" and "Operator: Universal Health
   Services": same company, different relation: AI step, not a rule fold.
7. **Numbers**: "Capacity: 22 (as of 2010)" and "Capacity: 32" differ. "Ages: 12-17" and ageRange {12, 17} are one.
8. **Suffix**: "John Smith Jr." and "John Smith" never join. "Jim Omer" and "James Omer" join.
9. **Note label beats keywords**: "Note: lowered fees on transports for families relocated due to a school closure"
   stays `note`, not closure.
10. **Dates**: `kop_fact_date()` on "2026-05-14: ...", "Reported 2007: ..." (date `''`, told 2007), "June 2026:
    ...", "July 17, 2026: ...", "Opened: 1970s (...)" (`''`).
11. **News exact-name rule**, on a made-up index: one holder links (`exact`); two holders plus an article link to one
    links (`article`); two holders without one is `ambiguous`; an `agency` subject named like a record never links;
    a generic name ("Youth Care") is `generic`. `kop_nf_parse_reply()` with a linked record and an empty facilityId
    whose subject is that record's past name gets that id.
12. **On hand**: an article linked to record A whose fact is about record B is not on hand for A.
13. **Testimony** never forms a group, even with two identical summaries.

## 11. Open questions for the owner

1. **News link scope.** The rule links only 20 facts. Should the next step be company links (50 facts name exactly
   one `kop_operators` company) and a person pass for the 206 person facts, or is it fine that those wait at Facts
   from news?
2. **Generic names.** `kop_autoconn_distinctive()` passes "Child and Family Services" ("child" is not on the generic
   word list). Add "child" and "services" to `kop_wiki_upd_tokens()`'s generic list? That also changes the
   Woodbury, wiki and Fornits auto-connect passes. Or check the article's state or country against the record's?
3. **Closure order vs closure. Answered 2026-11-03: a state closure order is not the closure.** It never folds or clears
   against a closure fact, and a closure never clears an order (6.2; built in `kop_fact_differs()`, `kop_fold_ai_apart()`,
   `kop_wbf_on_record()`).
4. **Standing. Answered 2026-11-03: fold them.** An "alleged" telling folds with a found / substantiated / confirmed / convicted
   telling of the same event and the kept card is the found one (its wording), citing both. Standing never keeps two
   tellings apart. Keeper order (`kop_fold_pick()`): a Woodbury or Fornits item before a news fact when the group has one;
   then the strongest standing (found 2, unstated 1, alleged 0, `kop_fact_standing_rank()`); then Woodbury before Fornits;
   then the longest wording. A found telling that is only a waiting news fact cannot be kept (no place for citations): the
   best Woodbury or Fornits telling is, even when it says alleged.
5. **Owner and operator.** Treat "Owner: X" and "Operator: X" for the same company as one fact (fold by rule), or
   always ask (this spec sends them to the AI step)?
6. **One URL key.** Fornits' on-file rule uses `kop_gdl_url_key()`; `fact-dedupe.php` and the news links use
   `kop_normalize_urls()`. Use `kop_normalize_urls()` everywhere for links?
7. **No-record items that now match a record. Answered 2026-11-03: move them onto it automatically.** The hourly
   auto-connect (`kop_autoconn_woodbury_facts()`, source `woodbury-facts-link`) links a pending no-record Woodbury Facts item
   whose program is exactly one record's name (`kop_autoconn_exact_record()`: full key, distinctive, one holder; ambiguous,
   generic and none stay) to that record: `facility_id`, `match_kind 'id'`, `extra.auto_linked`; Undo on the card or in
   Recently done (`kop_wbf_auto_unlink()`). Young adult and consultant items are not touched. Why `kop_autoconn_woodbury()`
   never did it: it reads the Woodbury Reports page queue (`kop_wb_table()`, `facility_id > 0`), not fact rows; the facts'
   record is set once by the build (`scripts/woodbury-facts.py`) and no PHP pass matched `match_kind none` rows again
   (`kop_wbf_auto_where()` starts from `facility_id > 0`).
8. **Undated incidents. Answered 2026-11-03: any number of years apart.** An empty date never blocks (`kop_fact_dates_fit()`);
   two different dates still never join, and a rule join that would put two different dates in one group through an
   undated telling is refused (`rule_clash`).
