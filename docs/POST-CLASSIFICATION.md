# Post Classification

Item 2 of [docs/plan/4.7-pages-and-templates.md](plan/4.7-pages-and-templates.md). Same shape as
[PAGE-CLASSIFICATION.md](PAGE-CLASSIFICATION.md), for the 103 published posts (`post_type = 'post'`).
Source: production mirror synced 2026-10-25 (kop-pipeline `scripts/inventory-posts.py`, CSV in `tmp/post-inventory.csv`).
"Inbound" counts published posts and pages whose content links the post. Treatments are item 17 of the plan file.

| Class | Posts |
| --- | ---: |
| `article` | 6 |
| `facility-profile` | 1 |
| `legal-record` | 13 |
| `news` | 78 |
| `obsolete` | 1 |
| `tool-or-test` | 4 |

## Posts other than News

| Post | ID | Categories | Inbound | Class | Decision | Treatment |
| --- | ---: | --- | ---: | --- | --- | --- |
| `discovery-ranch` | 10800 | Facility Profile; Utah; Where are the kids? | 0 | `facility-profile` | keep | Already on single-facility-profile.php (item 3) |
| `data-organizer` | 5702 | Tools | 0 | `tool-or-test` | unpublish | Draft (17b); only linked from the private post admin-data |
| `data-analysis` | 5659 | Uncategorized | 0 | `tool-or-test` | unpublish | Draft (17b) |
| `facility-form-test` | 5634 | Uncategorized | 0 | `tool-or-test` | unpublish | Draft (17b) |
| `data` | 3866 | Tools | 0 | `tool-or-test` | unpublish | Draft (17b) |
| `arizona-adhs-inspections` | 3195 | Arizona | 0 | `obsolete` | unpublish | Draft (17b); 2.7 MB of pasted inspection text, /az-reports/ replaces it |
| `promise-of-healing-became-sexual-abuse-in-homes-linked-to-te` | 3450 | Canada | 0 | `article` | keep | Canada news summary; not in the News category |
| `lawsuit-fuller-vs-hyde-school-2025` | 2962 | Lawsuits; Maine | 1 | `legal-record` | keep | Longer than lawsuit row 2; row links it as "Our write-up" (17d) |
| `three-springs-inc` | 2638 | Alabama; Company Profile; Florida; Georgia; Maryland; North Carolina; Tennessee; Where are the kids? | 0 | `article` | keep | Company history; no change |
| `spiritual-abuse-religious-trauma` | 2551 | Editorials | 0 | `article` | keep | Editorial |
| `lawsuit-claims-teen-was-assaulted-at-upstate-boarding-school` | 2837 | Uncategorized | 0 | `news` | keep | Whetstone Academy lawsuit news summary, Uncategorized; no lawsuit row and no feed row: article sent to the Review inbox |
| `investigatory-spotlight-john-santa-montana-academy` | 1856 | Investigatory Spotlight | 1 | `article` | keep | Investigatory Spotlight |
| `investigatory-spotlight-natsap` | 1025 | Investigatory Spotlight | 7 | `article` | keep | Investigatory Spotlight |
| `sweidy-v-spring-ridge-academy` | 1099 | Lawsuits | 0 | `legal-record` | redirect | /lawsuits/#lawsuit-5 |
| `sherman-et-al-v-trinity-teen-solutions-et-al` | 1097 | Lawsuits | 0 | `legal-record` | redirect | /lawsuits/#lawsuit-6 |
| `anonymous-v-hyde-connecticut` | 1095 | Lawsuits | 1 | `legal-record` | redirect | /lawsuits/#lawsuit-7 |
| `anonymous-v-vista-rtc` | 1093 | Lawsuits | 0 | `legal-record` | redirect | /lawsuits/#lawsuit-8 |
| `whitcomb-clifford-v-chrysalis-embark-behavioral-health-et-al` | 1091 | Lawsuits | 0 | `legal-record` | redirect | /lawsuits/#lawsuit-9 |
| `richardson-v-elevations-rtc` | 1089 | Lawsuits | 0 | `legal-record` | redirect | /lawsuits/#lawsuit-10 |
| `shiver-v-southstone-behavioral-health-acadia-healthcare` | 1087 | Lawsuits | 0 | `legal-record` | redirect | /lawsuits/#lawsuit-11 |
| `john-doe-mdy-v-trails-academy-et-al` | 1084 | Lawsuits | 0 | `legal-record` | keep | 4,456-word case write-up; row 43 links it (17d) |
| `11-12-2024` | 974 | Editorials | 1 | `article` | redirect | Same text as the page survivor-resources-nature: 301 there (17a) |
| `trails-carolina-lawsuits` | 551 | Lawsuits | 2 | `legal-record` | redirect | /lawsuits/#lawsuit-44 (its two PDFs are on rows 43 and 44) |
| `jane-june-doe-v-trails-et-al-summary` | 1909 | Lawsuits | 0 | `legal-record` | keep | 4,908-word case write-up; row 44 links it (17d) |

## News posts

The 75 posts in the News category, two of them lawsuit news (2169, 2301; classed `legal-record` above, treated as news here).
`in_news_feed` is true for 74: each matches exactly one approved `news_submissions` row by `json_data.source_post_id`.
The one without a row, `the-walrus-robert-land-academy` (470), is the second copy of post 786 (same Walrus article);
it follows 786 to row 419. None is a boarding-school article (those are left out of the feed). Each 301s to
`/tti-news-feed/?item=<row>` (17c).

| Post | ID | Feed row |
| --- | ---: | ---: |
| `queen-creek-police-investigating-alleged-assault-at-desert-lily-academ` | 3625 | 481 |
| `children-were-beaten-encouraged-to-fight-at-state-funded-al-facility-l` | 3096 | 480 |
| `embark-behavioral-health-names-new-cfo` | 3109 | 479 |
| `state-sponsored-abandonment-no-more-california-stops-paying-for-adopte` | 3104 | 476 |
| `family-of-young-boy-allegedly-sexually-assaulted-at-psychiatric-hospit` | 3100 | 475 |
| `a-trans-teen-girl-was-put-in-a-male-detention-center-they-broke-her-ja` | 3098 | 474 |
| `dozens-of-teens-who-spent-time-at-abusive-florida-reform-school-ended-` | 2977 | 473 |
| `in-asheville-academy-suit-lack-of-supervision-led-to-self-harm-child-a` | 2960 | 472 |
| `child-victims-act-7-lawsuits-claim-sex-abuse-at-jcca-facilities-in-ple` | 2951 | 471 |
| `teen-sexually-abused-at-d-c-youth-rehab-center-by-employee-from-temple` | 2947 | 470 |
| `julio-sandoval-of-agape-boarding-school-cant-afford-travel-to-ca-trial` | 2958 | 469 |
| `former-youth-detention-center-psychologist-pleads-guilty-to-criminal-s` | 2949 | 468 |
| `hilda-finds-her-voice` | 2974 | 467 |
| `wa-juvenile-facility-accused-of-humiliating-conditions` | 2953 | 466 |
| `juvenile-jail-staffer-guilty-of-sex-assault-gets-71-month-prison-sente` | 2898 | 465 |
| `authorities-probe-exposure-at-los-padrinos-juvey-hall` | 2896 | 464 |
| `clay-county-man-pleads-guilty-to-sexually-assaulting-minor-sentenced-t` | 2900 | 463 |
| `prison-treatment-of-indigenous-teenager-cleveland-dodd-was-cruel-lawye` | 2903 | 462 |
| `levy-konigsberg-and-co-counsel-file-federal-civil-rights-claims-on-beh` | 2845 | 461 |
| `nc-state-investigation-continues-despite-asheville-academys-voluntary-` | 2793 | 460 |
| `federal-lawsuit-alleges-abuse-exploitation-of-teen-at-asheville-academ` | 2766 | 459 |
| `levy-konigsberg-files-lawsuits-on-behalf-of-six-survivors-abused-at-at` | 2841 | 458 |
| `woman-working-at-n-y-youth-care-facility-charged-with-sexual-abuse-kid` | 2549 | 457 |
| `state-investigation-finds-licensing-violations-at-asheville-academy-am` | 2524 | 456 |
| `88-children-placed-into-protective-custody-after-reports-of-child-abus` | 2519 | 455 |
| `levy-konigsberg-attorneys-madeleine-skaller-and-zoe-ferguson-have-file` | 2847 | 454 |
| `report-details-disturbing-abuse-against-youth-at-the-william-e-hay-cen` | 2283 | 453 |
| `like-youre-an-animal-nebraska-youth-advocates-say-juvenile-confinement` | 2287 | 451 |
| `la-county-probation-officer-charged-with-bringing-drugs-into-sylmar-ju` | 2285 | 450 |
| `fredonias-re-creation-retreat-has-license-suspended-during-department-` | 2798 | 449 |
| `lawsuit-claims-staff-at-former-st-george-youth-center-abused-impregnat` | 2169 | 448 |
| `ex-laurel-ridge-treatment-center-ceo-faces-6-child-sexual-abuse-charge` | 2800 | 447 |
| `asheville-academy-closes-after-license-surrender-states-investigation-` | 2171 | 446 |
| `former-juvenile-corrections-officer-accused-of-unlawful-sexual-activit` | 2804 | 444 |
| `suit-alleges-teen-repeatedly-abused-by-worker-at-former-youth-resident` | 2291 | 443 |
| `dcs-worker-tipped-off-youth-center-with-history-of-abuse-allegations-a` | 2293 | 442 |
| `legal-action-filed-over-misconduct-at-milton-girls-facility` | 2806 | 441 |
| `girls-confined-to-box-if-they-fought-sex-abuse-at-fl-youth-center-suit` | 2295 | 440 |
| `parents-sue-utahs-discovery-ranch-over-teens-death` | 2301 | 439 |
| `staff-member-at-utah-troubled-teen-school-charged-with-showing-student` | 2297 | 438 |
| `levy-konigsberg-sues-youth-consultation-service-over-child-sexual-abus` | 2849 | 437 |
| `trumps-exploratory-therapy-is-still-just-conversion-therapy-and-its-da` | 2303 | 436 |
| `lawsuit-upstate-boarding-school-failed-to-protect-resident-from-sexual` | 2546 | 435 |
| `arizona-lawmaker-calls-for-investigation-after-abc15-reports-into-faci` | 2305 | 434 |
| `former-employee-of-st-george-youth-facility-accused-of-giving-drugs-to` | 2839 | 433 |
| `man-admits-to-abusing-girl-at-billings-youth-care-facility` | 2309 | 432 |
| `there-was-no-oversight-nh-child-advocate-has-been-a-watchdog-for-child` | 2307 | 431 |
| `two-girls-fell-in-love-at-a-camp-for-troubled-teens-and-made-an-audaci` | 2311 | 430 |
| `their-kids-died-in-utah-teen-treatment-programs-now-these-parents-are-` | 2315 | 429 |
| `man-charged-with-sexual-abuse-of-girls-at-yellowstone-boys-and-girls-r` | 2313 | 428 |
| `provo-canyon-school-staff-hurt-kids-utah-licensers-found-its-springvil` | 2319 | 427 |
| `utah-treatment-center-closes-abruptly-after-history-of-violations-and-` | 2317 | 426 |
| `the-salt-lake-tribune-utah-tried-to-close-a-teen-treatment-program-ove` | 818 | 425 |
| `ksl-com-provo-canyon-school-employee-arrested` | 820 | 424 |
| `up-north-live-abandoned-by-parents-in-jamaica-traverse-city-teen-finds` | 821 | 423 |
| `former-students-report-injuries-and-isolation-at-elevations-rtc` | 473 | 422 |
| `los-angeles-times-with-support-from-paris-hilton-california-lawmakers-` | 810 | 421 |
| `al-com-alabama-youth-home-staff-instigated-fights-gambled-with-residen` | 823 | 420 |
| `the-walrus-a-military-style-school-for-troubled-teens-became-a-living-` | 786 | 419 |
| `the-walrus-robert-land-academy` | 470 | 419 (via 786) |
| `abc-action-news-tampa-bay-florida-youth-detention-contractor-will-no-l` | 825 | 418 |
| `california-globe-child-care-transparency-bill-passes-assembly-appropri` | 790 | 417 |
| `nbc-news-8-on-your-side-wfla-mother-expresses-concern-over-sons-treatm` | 788 | 416 |
| `abc-news10-poughkeepsie-center-for-autism-worker-charged-in-abuse-case` | 792 | 415 |
| `the-real-adoptea-moxie-blog-matt-bevins-despicable-legacy-from-white-s` | 794 | 414 |
| `lexington-herald-leader-ex-gov-matt-bevins-adopted-son-back-in-the-u-s` | 796 | 413 |
| `kentucky-lantern-former-ky-gov-matt-bevins-adopted-son-reportedly-remo` | 798 | 412 |
| `st-george-news-former-st-george-youth-facility-staff-member-admits-to-` | 800 | 411 |
| `wtmj-tv-milwaukee-i-just-want-my-baby-home-wisconsin-girl-still-missin` | 802 | 410 |
| `the-u-s-sun-i-was-abandoned-2-5k-miles-from-home-at-lord-of-the-flies-` | 812 | 409 |
| `the-new-york-times-paris-hilton-testifies-before-congress-on-troubled-` | 814 | 408 |
| `waff-vote-to-revoke-business-license-of-brighter-path-courtland-tabled` | 804 | 407 |
| `tampa-bay-times-state-closes-st-pete-treatment-facility-for-boys-amid-` | 806 | 406 |
| `the-new-yorker-the-shadow-penal-system-for-struggling-kids` | 816 | 405 |
| `former-fairfield-school-worker-sentenced-in-students-sex-assault` | 2489 | 404 |
