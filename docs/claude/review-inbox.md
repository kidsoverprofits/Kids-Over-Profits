# Review inbox

Commands, tests and rules for the Review inbox and its queues, card add-ons and fields, auto-connect, on-file and conflicts, admin kit, volunteers, follow-up emails, data audit. Read this before working in that area; new commands and rules
for it go here, not in CLAUDE.md. `(kop-pipeline)` = run from that checkout.

```bash
# Review inbox: every approval queue on the Submissions Review page (inc/review-inbox.php, js/review-inbox.js). Each queue is
# a source in inc/review-inbox/<name>.php, registered with kop_rinbox_register(), calling the queue's own apply/undo functions;
# cards edit name/details, category, tags (shared {prefix}kop_review_tags unless the queue keeps its own), "Move to" another queue,
# "Fill empty fields with AI" (news/lawsuits: kop_enrich_*_row(), else a generic filler). A new queue gets a source file there.
# Every approve/reject action carries 'help' (one sentence: what clicking does), shown on the card. inc/review-inbox-log.php:
# Recently done ({prefix}kop_review_log, Undo = the item's 'undo' action, or 'restore' for the five native types, logged from
# api/manage-submissions.php), Later = snooze / hand to an admin ({prefix}kop_review_holds, off everyone else's list), "Needs research" on every card
# (holds.research = 1: off every queue into Your inbox > Needs research until "Back to the queue" or a decision; review-inbox/research), Preview
# (review-inbox/preview: framed where allowed, else a reading copy)
# Tabs (owner, 2026-10-25: no dropdowns, the daily news scans always their own tab): Just in / Sent in by people / Daily news scans /
# Found by imports and inspections / Checks and cleanup / Your inbox / All tools (js/review-inbox.js renderTabs()); the open tab's queues
# are a row of buttons under it, "Find a queue" searches every tab. A queue's tab is kop_rinbox_area() (inc/review-inbox-recent.php), read
# from its 'group' or its own 'area' ('people' | 'scans' | 'found' | 'checks'). News shows twice: under people on its "Came from" origin
# 'people', under Daily news scans on 'scans' (Google News + Reddit), and what imports sent (SCIAD NET, Google Docs, the wiki, Scraper finds...)
# as "<Queue> (imports)" under Found by imports on 'imports' (since 2026-10-25; before that, 51 imported articles showed in no tab),
# counts from kop_rinbox_native_split_counts(). The page's own five
# type tabs stay hidden. ?type= takes a queue, a special list (_new, _done...) or an area (people, scans, found, checks, _yours)
# Just in (the page opens on it; review-inbox/recent?sources=a,b&days=1|2|3|7): waiting items created in the window. People's are cards
# (News/Data/Wiki/Lawsuits/Legislation get Approve/Reject posting to api/manage-submissions.php, so Undo is in Recently done); the scans'
# and the imports' are counts per queue in two folds. A source answers with 'recent' (native: by created_at) or 'recent_count'
# (Drive Docs, Fornits, Woodbury Facts: SQL count, their cards are slow; Program practices: 0, undated); else its waiting view is read
# 20 then 100 at a time up to 300, stopping once a newest-first page passes the cutoff. Undated items never show
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=recent   # areas, window, people vs scans, every queue timed
# Counts never block the page: review-inbox/sources returns the saved counts (option kop_review_inbox_counts) + 'stale', the page
# recounts those after drawing (review-inbox/counts?sources=a,b, slow counters alone), a 10-minute cron keeps them warm;
# kop_rinbox_flush_counts() only marks them stale. A queue whose count scans goes in SLOW_COUNTS there
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php [--source=closure]   # every source on a scratch copy; checks in scripts/review-inbox-tests/
# "File under a young adult program" on every waiting card of a content queue (inc/review-inbox/_ya-file-action.php, like "Home of a program";
# kop_rinbox_cross_act()): a fact (People/Incidents/History/About) citing the card's link, or only the link, on a picked or new (hidden)
# program; the card moves to "Already on file" (option kop_review_ya_filed), exact Undo. Queues filing their own way are in its skip list
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=ya-file
# The site's own page and post drafts are a queue too (inc/review-inbox/drafts.php): Drafts / Pending / Scheduled / Published (60 days) /
# Trashed, Preview in the card, Publish, Schedule, Trash; the status and dates before are kept in _kop_rinbox_was, so Back to draft (the Undo)
# restores them exactly. Volunteers never see it
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=drafts
# One person, one name, one event per record (api/lib-record-dedupe.php): every approval (kop_wbf_doc_apply(), wiki approve) and every Merge
# Duplicates join asks kop_dd_same_person() (nickname, other spelling one letter off, maiden name in brackets; a different middle initial or
# "X and Y" stays two), kop_dd_name_key() ("X (AYA)" = X) and kop_dd_same_event() (same kind and month; two exact days or two ages stay two)
# before adding a copy; roles keep each title once and no source name (kop_dd_role_tidy()). What earlier approvals left is tidied at
# Review inbox > Record cleanup (inc/record-cleanup.php: one card per record, tick the changes, whole-record Undo while unchanged since)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=record-cleanup
# Card add-ons: an action written once for many queues' cards ("Home of a program", "File under a young adult program") is
# kop_rinbox_addon() in inc/review-inbox.php ('never' queues, 'default' on/off, a queue's 'addons'); the owner ticks each on/off per queue at
# KOP Tools > Card Add-ons (inc/review-inbox-addons.php, option kop_review_addons). Picking a company/program/consultant... in an action, or
# naming a new one, is kop_record_field_param() (inc/record-field.php; the finder lists those kinds + "New company named ..."):
# kop_record_field_resolve()/_link() makes it citing the card, Undo's kop_record_field_unmake() deletes it while untouched (nonprofit/business filings)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=addons
# KOP Tools > Card Fields (inc/review-inbox-fields.php): per queue, which fields ("Edit details", by name) and lines (by label) its cards
# show, and what kinds its record pickers offer (program, company, consultant, transporter, provider, marketer, trade org, young adult
# program). A queue with 'record_kinds' gets its 'facility' pickers as the any-record finder; a non-program pick is filed by
# kop_rinbox_file_on_record() (inc/review-record-file.php: company links/news/notes, YA links/facts, other kinds a cited note), exact Undo
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=card-fields
# News cards: "Connect a record" files the article on a company (kop_operator_links), consultant, transporter, provider, marketer,
# trade org (a note citing it) or young adult program while it stays in the news; "Take off" undoes (option kop_review_news_connections)
# Imports connect themselves when the name is exact (inc/auto-connect.php, hourly): a name found in the text that is exactly one record's
# (same name key, more than generic words, no other record's) is connected with no click: Woodbury Reports pages, wiki links, Fornits
# discussion links (records whose every name is 10+ letters), State Lists rows now on file, nonprofit and business filings on "Same name and town", Drive Docs links (no-record ones re-tied by their SCIAD collection or wiki page name; company-only
# ones onto the company page, operator.resourceLinks = its "Materials and links"; never news links: only a person adds those, and Add sends news to Submissions Review > News waiting, not approved, since 2026-10-20; api/undo-auto-drive-news.php [apply] took back the 8,561 sent in approved on 2026-10-14..16)
# tied by an exact name (Add publishes them), and in its own run Woodbury Facts items at an exact or past name with the quote found, no
# conflict (any source: Woodbury, HEAL, wiki; kop_wbf_auto_where()). A name of only common words counts if it has 3+ words and a place word
# ("Lake House Academy"; kop_autoconn_distinctive()). Close names, shared names, board/title-only, "Wilderness Treatment Center" wait. Each shows in its queue's done tab by 'auto' and in Recently done with Undo; an undone one is never redone
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-auto-connect.php [--list]   # every pick is exact, wiki link + log + undo, on a scratch copy
# Pending items already in our records leave the waiting list: news/lawsuits/bills (inc/review-inbox/_on-file.php: same link on a kept
# record or in a facility's resourceLinks, same headline+outlet, case number, bill) go to the Submissions Review "Already on file" tab
# Any other queue gives its source an 'on_file' => fn(): [key => {label, url}] (kop_rinbox_on_file_keys()): those leave the waiting view and
# its count for an "Already on file" view. Facilities from News (article already listed on the record meant), Websites sent in (link on a
# facility or kept record), State Lists (listed name now exactly one record's in that state: own tab, Link offered first, kop_sl_on_file())
# Woodbury Facts and Fornits: what Add would find already on the record (kop_wbf_on_record(); a different value is a conflict and waits),
# filtered in their own SQL ('on_file_in_list'), cached by kop_on_file_cached() until the queue or any record changes
# Conflicts are always marked: an item's 'conflict' (another value on the record, an open record for a closure, another role, a
# data-audit 'from' that no longer holds) draws a Conflict banner and is never ticked; Add keeps a conflict waiting (kop_wbf_conflict())
# Review inbox > Conflicts (inc/review-inbox/conflicts.php) settles them in one place: a queue names its conflicts ('conflicts', which leave its
# waiting list) and settles them ('resolve': keep the record / use this value / edit; kop_wbf_doc_overwrite() writes over a value, exact Undo);
# Woodbury Facts, Fornits and Data audit (approved over a changed 'from') take part. A new queue with conflicts adds both keys
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-on-file.php [--list] [--db=...]
python scripts/test-review-inbox-ui.py           # the page in a browser against fixtures: tabs, card edits, tags, AI, actions, phone width
# Data suggestions (suggested_edits, the "Data" queue) show "What changed" on the card, not the record sent: api/lib-suggestion-changes.php
# finds the record (facility_id, the /facility/<slug>/ or /<state>/ page named in the reason, name + state, the one record with that name),
# normalizes both sides and lists only fields whose sent value differs; empty, "Unknown" and unticked flags are no information. Dropped
# list items and emptied fields show only for whole-record edits (facility_id sent), which Approve writes over the record. Raw JSON is folded.
# Approve matches an entry sent without facility_id the same way and merges only its filled fields (kop_suggestion_resolve_entries());
# all on file = those facilities saved alone, no company made from a mini-form's facility name
# Undo of a data approval (Recently done 'restore', or the status set back on Submissions Review) puts the records back:
# api/lib-suggestion-undo.php (copy taken by kop_apply_suggested_edit(), option kop_sugg_undo_<id>; refuses over later edits)
# Since 2026-10-25 api/save-suggestion.php stores a facility suggestion's facilities[] in the v2 shape (kop_suggestion_normalize_facilities()
# in api/lib-suggested-edits.php, companies and locations only: providers, referrers, transporters, marketers and trade orgs keep the
# legacy shape their *_master tables read). Older rows stay legacy; the card, Approve and the autocompletes read either shape
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-suggestion-changes.php
# KOP Tools screens share one look (docs/ADMIN-UI.md: inc/admin-kit.php, css/admin-kit.css, js/admin-kit.js): decisions are buttons
# (primary first, three at most), the rest in More or a fold, a dropdown never acts, one filter toolbar per list, Undo after every action.
# A screen opts in with the kop_admin_kit_pages filter; Drive Docs, Fornits and Woodbury Facts are on it
php -d extension=pdo_sqlite -d extension=mbstring scripts/preview-admin-kit.php   # the screens' cards from made-up rows -> tmp/admin-kit-preview/index.html
# Volunteer reviewers (inc/review-volunteers.php, /volunteer-review/, js/volunteer-review.js): KOP Tools > Volunteer Reviewers makes a
# personal link per name (no account; only the token's hash stored, cookie kop_vol), turns one off, picks the queues they see
# (kop_vol_never_sources() can never open). Volunteers only recommend approve/reject/not sure ({prefix}kop_volunteer_recs); items reach
# them as kop_vol_item() copies (no fields/actions/tags, emails and phones blanked). Inbox cards show 'recs', "Volunteers recommend"
# lists them; an approve/reject in the log closes them (agreement rate per volunteer), Undo reopens them
# Sign-up (inc/volunteer-signup.php, the 'volunteer' hub module on /volunteer/ + js/volunteer-signup.js, public POST kop/v1/volunteer/signup):
# name + optional email -> kop_vol_signup() -> kop_vol_create() at once (note "Signed up on the Volunteer page", created_by 'sign-up',
# email in the volunteers row, admin-only); shows the link once and sets the cookie. Hidden bot field, 3 a day per address+browser, 30 a day
# in all (filters kop_vols_per_day_each / kop_vols_per_day); a signed-in device is sent to its page. The volunteer_projects listing (templates/page-volunteers.php) links to it.
# kop_vol_never_sources() also bars drive-docs (the owner's private Docs) and podcasts (statements by named people)
# Queues are earned (owner, 2026-10-23): kop_vol_sources_for($vid) = the queues a volunteer may open themselves (tabs, items, self-assign):
# passed its practice round, a record there (10 admin-decided, 80%), or recommended there before option kop_vol_earned_before (set on first
# run, so the earlier volunteers keep theirs). kop_vol_earnable_sources() = kop_vol_starter_source() ('inspection-highlights', the owner's
# top priority) + the ticked queues. A new sign-up sees "Start now": practice in the starter queue, and passing self-assigns the first 10 at
# once; a finished batch offers "Give me 10 more"; "Learn another queue" practises the rest. Projects still open their own items to anyone
# Practice cards are curated by kop_volpr_tricky() (a volunteer disagreed / Not sure, 2+ wrong in practice, a Brief FAQ 'from' #id or #a-b,
# struck at Volunteer Reviewers > Practice cards ?practice=<source>, option kop_volpr_struck) and kop_volpr_unclear() (nothing to read, an
# archive.md-family link); a round never repeats a title. /volunteer/: the review and research cards -> #kop-vol-signup; GET
# kop/v1/volunteer/signup tells the cached page the device is signed in (form swapped for "Open your volunteer page")
# The page intro (templates/volunteer-review.php) says what the work is, carries the content warning (also on the /volunteer/ form) and a
# "How this works" fold (open until kop_vol_has_started()): getting started, choosing next work, taking a break or stopping, questions.
# Opting out: "Start now" offers other work under "Rather not read the inspection reports?"; REST volunteer/leave (kop_volp_leave()) with
# project_id = "Hand this batch back" (closed_why 'handed-back'), without = "Stop volunteering" (every open batch back, closed_why 'left',
# link revoked). Both go through kop_volp_release(), as the hourly sweep does; answers already given stay
# /volunteer/ is templates/page-volunteer.php (2026-10-23, kop_template_assignments; the page's old editor copy is unused). Since
# 2026-10-25 (owner: cluttered, too much scrolling) four cards, two across: Review inspection findings and Research a question (both
# -> the one sign-up form, #kop-vol-signup; the research card shows kop_rt_counts()['unassigned'] open), Answer from experience
# (/questions/, no sign-up), Send us what you have (the tools folded under "Choose what to send"); "Have a skill to give?" sits open at the
# foot. Card words are in-place blocks (kop_ie_html_start keys volunteer:review / :research / :questions / :send / :skills); the
# standfirst is kop_hub_config('volunteer') (its buttons are no longer printed). Check a template change offline by rendering it with
# stubs into the live page (route-intercept), then scripts/check-contrast.py --local /volunteer/ (MSYS_NO_PATHCONV=1 in Git Bash)
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-volunteers.php [--list]   # links, copies, recommend, resolve, holds
# Volunteer projects (inc/volunteer-projects.php, KOP Tools > Volunteer Reviewers > Projects): a fixed batch of any size (0 = all waiting) from one queue,
# one question + a yes and a no example (kop_volp_kinds(), a ready-made brief per queue), sent to one person as /volunteer-review/?project=<id>
# (a new person's invite carries it). The page opens on it one item at a time; a project opens its queue to its volunteer, never a
# kop_vol_never_sources() one. "Check their calls" applies clear calls with each card's own button (kop_rinbox_apply_action(), Undo in Recently done)
# Self-assign (kop_volp_self_status()/kop_volp_self_assign(), REST volunteer/selfassign, 'self' in volunteer/projects): a volunteer with 10+ calls the admin decided,
# 80%+ agreeing (filters kop_volp_self_min_decided / _min_rate / _size) sees "Pick your next batch": a queue open to everyone, 10 items, created_by 'self-assigned';
# one unfinished self-assigned batch at a time. Turn a volunteer off with Revoke
# Hourly sweep (kop_volp_sweep(), cron kop_volp_sweep_hourly): a batch with no call, answer or question for 3 days (kop_volp_stall_days) hands
# its unanswered items back (gone = KOP_VOLP_RELEASED: out of the project, free for the next) and closes, closed_why 'stalled'; never while the
# last message about it is the volunteer's. A finished batch closes a day after its last answer (kop_volp_finished_close_hours), 'finished'.
# Calls stay open to check; the Projects list keeps closed batches with calls waiting up with the open ones
# Brief FAQ (inc/volunteer-faq.php, option kop_volf): per queue, live entries show on every brief as "Questions others asked" (kop_volf_live() via
# kop_volp_public()); edited at Volunteer Reviewers > Brief FAQ (?faq=<source>). kop_volf_seed() drafts are added once, never live until published;
# each has an admin-only 'from' (what it rests on, what goes the other way). A new seed = one entry there with a new id
# Social media projects (inc/volunteer-social.php, source 'social:roundup', {prefix}kop_vol_social, REST volunteer/post): Monday cron kop_vsoc_weekly
# makes a roundup of the 7 days before for the volunteer in option kop_vsoc_designer (none = no roundup; never "anyone"); Taking this -> caption +
# picture link -> Send for review; the admin approves or sends back with a note on the project page (a send-back reopens a closed batch). No AI
# captions, nothing posted yet (plan 4.4 10d). Cards: kop_vsoc_cards() in inc/volunteer-social-cards.php, one kop_vsoc_card_<angle>() per angle
# in kop_vsoc_angles() (closure, lawsuit, bill, finding with a plain summary, reporting, money) + the week in numbers; only public facts and URLs
# Picture uploads (REST volunteer/upload, multipart 'image'; kop_vsoc_store_upload()): checked by getimagesize, PNG/JPEG/WebP/GIF, 10 MB
# (kop_vsoc_upload_max), re-encoded by GD (no EXIF/location), kept in uploads/kop-private/social/ (.htaccess denies all); admins preview through
# admin-post.php?action=kop_vsoc_image; Approve copies it into the media library once (kop_vsoc_to_media(), meta _kop_vsoc_post)
# Photo projects (inc/volunteer-images.php, source 'images:pages', {prefix}kop_vol_images, REST volunteer/image do=draft|ready|not_found|pass|back):
# spots = seeds/image-spots.json ({page, heading|null, about, ideas}; key page#header or page#<heading slug>); kop_vimg_open_spots() leaves out a
# spot already showing a picture (featured image, or wp:image right after the heading), whose page/heading changed, or in an open photo project.
# "Make the photo project" (Projects > Photos for pages) = every open spot, for anyone or one volunteer. A pick = page link (+ file link unless
# Commons; Commons links are normalized to wiki/File:), author, license (kop_vimg_license_ok(): PD/CC0/CC BY/CC BY-SA/Pexels..., never NC/ND), alt;
# never sent to the inbox. Approve: kop_vimg_to_media() (reuses an attachment with the same _kop_source_url; Commons via its API at <=2000 px;
# caption = "Author, License, via Wikimedia Commons"; FileBird Site Photos) + kop_vimg_place() (inc/page-images.php helpers); Undo approve unplaces.
# The first approved pick fills the spot for every volunteer. A new spot = one line in the seed (no deploy step: it is read when a project is made)
# Practice round (inc/volunteer-practice.php, {prefix}kop_vol_practice, REST volunteer/practice do=start|next|answer, 'practice' in 'self' while not eligible):
# 10 items an admin already decided in one queue open to everyone, from kop_review_log (not undone, not 'auto' or 'volunteers', one answer per item,
# half rejects, never one waiting again), shown as kop_volpr_copy() (facts naming the decision, the linked facility and the button lines taken out);
# each call says what the admin decided; 8 of 10 passes (filters kop_volpr_size / _pass / _min_each). A pass opens self-assign for that queue
# ('via' 'practice') until the volunteer has 10 admin-checked calls, which then decide. Practice never writes kop_volunteer_recs, so it never
# closes anything or counts toward agreement, self-assign by record or volunteer-auto. The Volunteers table shows "Practice: News 9 of 10, passed"
# Research projects (inc/volunteer-research.php): source 'research:<kind>' (kop_volr_kinds(): closing-year, opening-year, status, news = facilities_v2
# records lacking it, narrowed by a state or a word; custom = typed questions, one per line). The volunteer writes what they found + links, or
# "Could not find it" + where they looked ({prefix}kop_vol_answers); each new link goes to the review inbox at once (kop_ext_insert_*, same
# duplicate rules as mobile/submit, submitter "<name> (volunteer)"). Nothing changes a record: the admin reads answers on the project page, Used /
# Not needed ("not needed" frees the record for a later project). A new kind = one entry in kop_volr_kinds() with its 'where'
# Research Tasks (inc/research-tasks.php, KOP Tools > Research Tasks, {prefix}kop_research_tasks + _log): the open research questions (what
# docs/RESEARCH-TASKS.md held until 2026-10-23), one card each, given to the owner, Claude or a volunteer; Answered -> Settled / Dropped, every
# change with exact Undo (the log keeps the rows as they were). Sessions add, answer and settle through seeds/research-tasks.json
# (python scripts/research-tasks/tasks.py add | answer <key> | settle <key> | list --open), imported on admin_init when the file changes
# (kop_rt_import_seed(): a new key inserts once; an entry's answer/settled line applies once by its hash, never over an answer already there,
# and an Undo of it sticks). Ticked tasks -> one volunteer: a 'research:custom' project via kop_volp_create_research(['items' => ...]), item key
# 'rt<id>', facility_id when the task names one record; kop_rt_sync_volunteer_answers() copies their answer + links onto the task (Answered),
# settling marks their answer Used; Undo of the send closes the project. Claude's list: php api/research-tasks.php --claude (over SSH, read-only)
# Each title is one plain question ending with "?" (kop_rt_check_question(), tasks.py add refuses others); the card leads with "Where to look":
# kop_rt_where_to_look() = each record's and company's KOP page (live URLs from records/companies) + the task's directions (JSON, built by
# tasks.py directions from the mirror: news, court cases, Woodbury issues with our PDF, archived links, links in the notes; records and companies
# named in full are matched by name). A volunteer gets the question + where to start, never the staff notes (Details). A seed entry whose hash
# moved rewords a card nobody edited (edited_at empty; records only gain ids); staff jobs go to docs/plan, not here
# Every piece of information is cited (owner, 2026-10-25): a research answer is facts, each with its link (and the sentence that says it).
# The volunteer card says so on top and has one box per fact (js/volunteer-review.js claimRow()); REST volunteer/answer sends 'claims' and
# kop_volr_clean_claims() refuses a fact without a link by its number; links store {claim, url, kind, quote, sent}, the answer text is
# kop_volr_claims_text() ("- fact ([source](url))"); one page backing several facts goes to the inbox once. tasks.py answer needs --claim
# "fact | url | quote" per fact (kop_rt_seed_answer_text()). Old answers (text + loose links) still read as before
# Volunteers pick their own (2026-10-25): the volunteer page's "Research questions you can pick" (js/volunteer-review.js pickBox, REST
# volunteer/research {do: list|take}) offers open tasks given to nobody (kop_rt_pickable(); the owner's and Claude's stay off), topic + search,
# tick and Take -> kop_rt_volunteer_take(): one research project of theirs, up to kop_rt_volunteer_hold() (5) unanswered at once, a task someone
# else just took refused. No practice round needed. kop_rt_free_released() puts questions from a closed project with no answer back on the
# list. Volunteer Reviewers > Projects says how many are on offer
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-research-tasks.php [--page=out.html]   # seed import, Undo, volunteer round trip, cards
# Messages (inc/volunteer-messages.php, {prefix}kop_vol_messages): one conversation per volunteer, "Ask a question" on a brief or card names the
# project/item; answered at Volunteer Reviewers > Messages (menu count bubble), shown on their page marked New; volunteers are never mailed, the
# notification list is (every 15 min per volunteer at most, option kop_volm_email)
# Agreed calls go through on their own (inc/volunteer-auto.php, option kop_vol_auto, OFF until the owner ticks On at Volunteer Reviewers >
# "Let agreed calls go through on their own"): 2+ trusted volunteers (20 admin-decided calls, 90% agreed, all three settable) give the same
# approve/reject, nobody disagrees or says Not sure, queue ticked (only kop_vol_auto_allowed() can be) -> kop_rinbox_apply_action() as reviewer
# 'volunteers' (KOP_VOL_AUTO_BY via $GLOBALS['kop_rinbox_reviewer_as']), in Recently done with Undo; once undone an item never auto-applies again.
# Recs closed this way (outcome_by 'volunteers') never count toward agreement or self-assign. Per person, the Volunteers table's "Goes through on
# their own" (option kop_vol_auto_people): Yes counts without a record, No never counts, default by record. Tests: the last section of test-review-volunteers.php
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-volunteer-projects.php   # batches, who sees what, apply + log, handled elsewhere, messages, research + inbox links
python scripts/test-volunteer-review-ui.py [--shots tmp/volunteer-review-ui]   # the volunteer page in a browser: research card, messages, 1280 + 390 px
# "Email me when this has been reviewed" on every public form (inc/submission-followup.php, js/submission-followup.js): forms post
# notify_email, the endpoint calls kop_followup_register(); a 10-minute cron reads each item's own status and mails once the decision
# has stood 10 minutes (an Undo inside that cancels it), then blanks the address. A new public form adds the block and a kind there.
# The block also has "Also sign me up for the newsletter" (never ticked by default; also in the bug reporter): newsletter_email ->
# inc/newsletter-signup.php -> MailerLite via the plugin's API key, group = KOP_NEWSLETTER_GROUP_ID / kop_newsletter_group_id option,
# else the plugin form's groups
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-submission-followup.php
# Data audit (docs/PLAN.md 3.13): flag data outside sources should check (renamed programs ending with their earlier name, status vs
# years, operators after a sale, one person under two spellings, memorials) -> tmp/data-audit/; research agents per tmp/data-audit/INSTRUCTIONS.md;
# typed proposals in js/data/data-audit/proposals.json, reviewed at Review inbox > Data audit (inc/data-audit.php: every "from" checked, exact Undo)
# One card does everything: KOP now (each record side by side, live), the steps to tick (fields, past/other names, company links, a new company,
# notes, staff roles, merges as a rename or as one program, map years, map lines taken off: kop_network_map_edge_drops(), memorial), each
# marked ready / already so / moved since (unticked, shows the value now) with the quote it rests on; "To check" lines are never made; "Edit
# details" saves the records' fields via kop_ie_facility_save() with its own Undo; each source shows its quote, how much was read and a link
# to the quote (#:~:text=). A new op type goes in kop_daudit_check(), _op_done(), _apply(), _undo() and _op_words(); 'manual' only when no type fits
# A rename is often why years disagree: a proposal's 'renames' [{earlier, later, year}] (or a renamed pair Merge Duplicates finds for its
# record) is printed on the card ("X became Y in 2016: that is why...") with "Merge as a rename" right there while both are records; Undo on
# the card (kop_daudit_rename_merge()). The flag script's rename_years kind finds such pairs; every flag on one name carries 'renames'
# Company names in program names (kop_daudit_rename_brands(), data-audit.py company_named()): a record that is only the name of a company
# running the other record and 2+ more programs, with no years outside it (bare "Embark Behavioral Health" in Marion = Embark at Flathead Valley)
# is folded in as one program, not a rename; "Sequel TSI Courtland" dates its name by Sequel's years in the operator lines; a company filed as
# a past name ("Three Springs Inc.") becomes a past-company step. KOP_DAUDIT_LIST=1 / KOP_DAUDIT_EXPORT=<file> on the test print / dump cards
(kop-pipeline) python scripts/data-audit.py [--kind rename_end_year ...] [--list]   # offline, against tmp/prod.sqlite
php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=data-audit
# The sources the audit read that KOP does not hold yet (every proposal's sources, incl. tmp/data-audit/proposals*.json) ->
# tmp/data-audit/audit-links.json, copied to ~/kop-import/gdocs/; reviewed at Drive Docs (source "Data audit") as news/court/resource links
(kop-pipeline) python scripts/data-audit-links.py
```
