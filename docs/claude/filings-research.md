# Filings and program papers

Commands, tests and rules for nonprofit tax filings, state business filings, programs' own papers (rules and practices), federal funding. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# Nonprofit tax filings (docs/PLAN.md 3.19): nonprofits named like our records, from the IRS exempt organizations file (and ProPublica's search for
# closed programs), with every yearly Form 990 ProPublica holds -> js/data/nonprofit-filings/{candidates.json, orgs/<ein>.json} (commit in kop-data);
# linked to a facility or company at Review inbox > Nonprofit tax filings ({prefix}kop_nonprofit_links, inc/nonprofit-filings.php, exact Undo);
# the facility and company pages print a year-by-year table (revenue, expenses, officers' pay, assets, a link to each return), read at render time
(kop-pipeline) py -3 scripts/nonprofit-990.py [--refresh-bmf] [--refresh-orgs] [--no-search] [--selftest]   # cached in tmp/nonprofit-990/, ~1 request/s
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=nonprofit-filings
# State business filings (docs/PLAN.md 3.20), Florida first: the Division of Corporations' free quarterly bulk files (SFTP; every company,
# active and inactive, officers, registered agent, events, trade names) read streamed; a company is a candidate when its name, a trade
# name it registered, its EIN (a nonprofit found for the program) or its street address is a Florida record's -> js/data/business-filings/fl/
# {candidates.json, entities/<doc>.json} (commit in kop-data; people by name, title, city and state only, never a street address);
# linked at Review inbox > Business filings ({prefix}kop_business_links, inc/business-filings.php, exact Undo); facility and company pages
# print the filing (officers, agent, name changes, dissolution, trade names) at render time. A new state: its own build into
# business-filings/<st>/ with the same shape, and kop_bizf_states()
(kop-pipeline) py -3 scripts/florida-sunbiz.py fetch && py -3 scripts/florida-sunbiz.py build   # ~2 GB download, resumable; selftest too
(kop-pipeline) py -3 scripts/colorado-sos.py fetch && py -3 scripts/colorado-sos.py build      # Colorado open data (entities, history, trade names); same shape into business-filings/co/
# The state's PDF of each filing (free; annual and periodic reports left out) -> kop-pipeline tmp/<script>/pdfs/ (never committed);
# copied to ~/kop-import/business-filings/co/, the linked companies' PDFs go into a "Business filings" folder of the program's library
# and each filing on the page links our copy (_kop_bizf_filing = "<st>:<doc>:<filing>:<date>"; Florida's lines match by date)
(kop-pipeline) py -3 scripts/colorado-sos.py pdfs [--ids ...] [--limit N]   # resumable; one request every 30 s, waits out Cloudflare's checks
(kop-pipeline) py -3 scripts/florida-sunbiz.py pdfs [--docs ...] [--limit N] # search.sunbiz.org as Chrome; also keeps each company's page address for the build
php api/import-business-filings.php ~/kop-import/business-filings/<co|fl> [apply]   # on the server (ea-php82): dry run first; rerun after more links
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=business-filings
# Programs' own papers (docs/PLAN.md 3.21): handbooks, manuals, ground rules, phase sheets and enrollment papers in each record's
# document library, as text page by page (OCR for scans), read per kop-pipeline scripts/doc-read-instructions.md (every quote checked).
# Practice lines -> ~/kop-import/doc-read/practices.json -> Review inbox > Rules from documents (inc/program-practices.php,
# {prefix}kop_program_practices; one card per program and topic, exact Undo) -> the facility page's "Rules and practices" section
# (and the record's checklist boxes they show), the app's 'rules', wiki gaps of kind 'rules'. People/program facts -> woodbury-facts.py --also tmp/doc-read
php -d extension=pdo_sqlite -d extension=mbstring scripts/doc-inventory.php     # every record's documents -> tmp/doc-read/inventory.json
(kop-pipeline) py -3 scripts/doc-text.py fetch|batches|export|status [--types handbooks|reading|all] [--facility 12293]
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=program-practices
# Federal funding (docs/PLAN.md 3.15): Medicaid, foster care funds, ORR, TRICARE, PPP and Education money found for programs,
# js/data/federal-funding/proposals.json -> Review inbox > Federal funding (inc/federal-funding.php) -> the record's federalFunding
# field (kop_facility_federal_funding_list(), JS mirror in data-normalizer.js), listed on its facility page; Add/Undo exact by entry ref
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=federal-funding
```
