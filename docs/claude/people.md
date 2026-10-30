# People

Commands, tests and rules for person ids, merges, past jobs, relationships, staff dedupe. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# Person ids (inc/people.php, KOP Tools > People): everyone on a facility staff list (administrator, notableStaff) gets a
# {prefix}kop_people id, stamped into the entry as personId by an hourly sync (same name key = same id); operators and network
# map people are linked by name, each map node keeps its own id (two board people with one name stay two until merged);
# {prefix}kop_person_roles is rebuilt from the records. The map build reads both tables (readPeople: personId on every person
# node, a merged person drawn as one node). Facility/company careers group by kop_people_group_key(). "Separate" splits one name.
# KOP Tools > Merge People (inc/people-merge.php): pairs found automatically (short first name, maiden/married name, one letter
# apart, swapped, same initial at one program, same name under two ids), one-click merge, exact Undo from the Merged tab
# Everyone else named gets an id too, linked by name (kop_people_named_sources(); docs not rewritten): facility owners, consultants,
# transport/provider/marketing company people, published lawsuits' staff + plaintiffs, the memorial, young adult staff, current wiki entries' staff, journalists.
# Pools (kop_people.pool): industry / harmed (victims, plaintiffs) / press: a name finds an id only in its own pool; map + pages read industry.
# A different middle initial is a different person (Robert W. and Robert B. Lichfield, father and son; kop_people_find())
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-people.php [--list] [--no-build]   # sync, pairs, merge+undo, PHP/JS key parity, a map build into tmp/; personKey parity needs ../kop-pipeline (or KOP_PIPELINE_DIR)
# A person's past jobs are held once on the person ({prefix}kop_person_jobs, inc/person-jobs.php): the hourly people sync reads each staff
# entry's pastJobs text in once (kop_pjobs_import()); every page that lists the person reads them (kop_facility_pages_person_career()), so a
# job is fixed once at KOP Tools > People > Past jobs (add, change, take off, put back; Undo there, log kop_person_jobs_log), not per profile
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-person-jobs.php   # reading pastJobs, the import, edits + undo, merges
# The People editor also holds "Became an educational consultant" (kop_people.ed_consultant: a ticked person's career ends "Educational
# consultant" on every page; details saves logged in kop_people_details_log, Undo there) and relationships ({prefix}kop_person_relations,
# inc/person-relations.php: family, business partners; by id or name, shown on both people, Parent <-> Child; kinds in Dropdown Lists 'person-relations')
# Public: facility and company pages print each listed person's ties under their name (kop_prel_public_lines(); never a harmed/press person), the
# map build draws a line between two people already on the map (kop-pipeline addPersonRelations(), provenance 'relations'; the table is synced and in the rebuild fingerprint)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-person-relations.php   # details + undo, the tick through merges, relationships both sides + undo
# kop_facility_save() folds a person listed twice under one role (kop_facility_staff_dedupe()); the one-time pass is kop_apply_staff_dedupe()
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-staff-dedupe.php [--list]
# Years that were only a wiki or HEAL page's retrieval date ("Former Therapist (2025, r/troubledteens wiki)", "(2019-2025, HEAL)",
# jobs "before 2025") came off
# once (inc/staff-copy-years.php, kop_apply_staff_copy_years(); kop_scy_undo() puts roles back, jobs undo at People > Past jobs)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-staff-copy-years.php [--list]   # rules, the pass + undo on a copy of the mirror
```
