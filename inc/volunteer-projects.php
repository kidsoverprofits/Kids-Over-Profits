<?php
/**
 * Volunteer projects: a small, fixed batch of review inbox items (15 to 30
 * minutes) handed to one volunteer with one question to answer per item,
 * an example of a yes and of a no, and a finish line.
 *
 * Broad "help review the inbox" asks do not get done; a project does: it is
 * a link to a named batch ("Check 20 serious findings from state inspections,
 * about 20 minutes"), and the volunteer page opens on it, one item at a time,
 * "8 of 20", until it says Done.
 *
 * Made at KOP Tools > Volunteer Reviewers > Projects (kop_volp_admin_section()):
 * pick a queue, how many, optionally narrow by a word (a state, a program),
 * and who (a volunteer, anyone with a link, or a new person, whose invite link
 * is made at once). The items are fixed when the project is made, never the
 * same item in two open projects. The screen gives a message to paste to the
 * person, with the link.
 *
 * Volunteers still only recommend (inc/review-volunteers.php): a project opens
 * its queue to the person it is for, even a queue not ticked for everyone, but
 * never one of kop_vol_never_sources(). "Check their calls" lists the batch
 * with what they said; "Apply ticked" runs each item's own approve or reject
 * button (kop_rinbox_apply_action(), so every one is in Recently done with its
 * Undo). Items whose button needs a choice the card does not prefill (or the
 * five submission queues, approved through api/manage-submissions.php) are
 * left to the inbox.
 *
 * Tables: {prefix}kop_vol_projects, {prefix}kop_vol_project_items.
 * REST (volunteer cookie or admin): kop/v1/volunteer/{projects, project}.
 * Tested by scripts/test-volunteer-projects.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_VOLP_DB_VERSION = '3';

/** An item handed back from a stalled batch (kop_vol_project_items.gone): out of the project, free for another. */
const KOP_VOLP_RELEASED = 2;

/* ---- Tables --------------------------------------------------------------- */

function kop_volp_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_vol_projects';
}

function kop_volp_items_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_vol_project_items';
}

function kop_volp_ensure_tables() {
    if (get_option('kop_vol_projects_db') === KOP_VOLP_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $p = kop_volp_table();
    $i = kop_volp_items_table();
    dbDelta("CREATE TABLE $p (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(200) NOT NULL,
        source VARCHAR(40) NOT NULL,
        search VARCHAR(100) NOT NULL DEFAULT '',
        question TEXT NULL,
        yes_example TEXT NULL,
        no_example TEXT NULL,
        note TEXT NULL,
        minutes INT NOT NULL DEFAULT 20,
        volunteer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'open',
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        closed_at DATETIME NULL,
        closed_why VARCHAR(12) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        KEY status (status),
        KEY volunteer (volunteer_id)
    ) $c;");
    dbDelta("CREATE TABLE $i (
        project_id BIGINT UNSIGNED NOT NULL,
        item_key VARCHAR(191) NOT NULL,
        title VARCHAR(255) NOT NULL DEFAULT '',
        position INT NOT NULL DEFAULT 0,
        status VARCHAR(40) NOT NULL DEFAULT '',
        gone TINYINT NOT NULL DEFAULT 0,
        detail LONGTEXT NULL,
        PRIMARY KEY  (project_id, item_key)
    ) $c;");
    update_option('kop_vol_projects_db', KOP_VOLP_DB_VERSION);
}

/* ---- Kinds of project ------------------------------------------------------- */

/**
 * The ready-made briefs, one per queue that suits a volunteer: a title, the
 * one question, what a yes and a no look like (real cases from the queue),
 * and about how many minutes an item takes. A queue not listed here can still
 * be a project, with its kop_vol_guidance() as the question.
 */
function kop_volp_kinds() {
    return array(
        'inspection-highlights' => array(
            'title'    => 'Serious findings from state inspections',
            'question' => 'Does this finding describe real harm or danger to a young person (abuse, an injury, a restraint, neglect, sexual misconduct, a runaway at risk), rather than paperwork?',
            'yes'      => '"An inappropriate restraint was completed by a staff on a child." A child was put in a restraint that broke the rules: recommend approve.',
            'no'       => '"The file did not have a signed copy of the treatment plan." Paperwork, and nobody was hurt or put at risk: recommend reject.',
            'minutes'  => 1,
        ),
        'woodbury-facts' => array(
            'title'    => 'Facts from old industry newsletters',
            'question' => 'Open the source link. Does the quoted text really say what the one-line summary says, about the program it names?',
            'yes'      => 'The summary says a mother sued AMIkids Sand Hills over a 2015 death, and the quote says "filed a wrongful death lawsuit ... against the private company that runs it" about Sand Hills: recommend approve.',
            'no'       => 'The quote is about a different program with a similar name, or the summary adds something the quote does not say (a year, a job title, a death): recommend reject and say which in the note.',
            'minutes'  => 2,
        ),
        'woodbury-reports' => array(
            'title'    => 'Newsletter pages filed under a program',
            'question' => 'Do these Woodbury Reports pages really talk about the program they would be filed under? Read the quoted text, and open "Full issue" if you need more.',
            'yes'      => 'Pages about "Intermountain Children\'s Home, Helena, MT" that would be filed under Intermountain in Helena: recommend approve.',
            'no'       => 'The same pages filed under "The Children\'s Home (Amarillo, TX)": only the words "Children\'s Home" match, it is a different place: recommend reject.',
            'minutes'  => 2,
        ),
        'inspection-links' => array(
            'title'    => 'State licence entries matched to our programs',
            'question' => 'Is the state\'s licensing entry the same place as our record? Compare the names, the town and the address in the table.',
            'yes'      => 'Our "Embark at The Poconos" in White Haven, and the state\'s "Change Academy Lake of the Ozarks: Embark at the Poconos" at 108 Country Place Lane, White Haven: same place, recommend approve.',
            'no'       => 'Different towns, or the names only share a word like "Academy" or "Ranch": recommend reject.',
            'minutes'  => 1,
        ),
        'nonprofit-filings' => array(
            'title'    => 'Nonprofit tax returns matched to programs',
            'question' => 'Is this nonprofit the organization that runs (or ran) the program named? Compare the name and the town; the ProPublica link shows its returns.',
            'yes'      => '"New England Center for Children Inc", Southborough MA, for our "The New England Center for Children" in Southborough: recommend approve.',
            'no'       => 'A charity with a similar name in another state, or a church or foundation that only shares a word with the program: recommend reject.',
            'minutes'  => 2,
        ),
        'business-filings' => array(
            'title'    => 'State business filings matched to programs',
            'question' => 'Is this registered company the one that runs (or ran) the program named? Compare the name, the town and the officers.',
            'yes'      => 'The company has the program\'s name (or a trade name it registered) and is in the program\'s town: recommend approve.',
            'no'       => 'A company that only shares a word with the program, or is in another part of the state with nothing else in common: recommend reject.',
            'minutes'  => 2,
        ),
        'facility-merge' => array(
            'title'    => 'Programs that may be listed twice',
            'question' => 'Are these two records the same program at the same place? Open both pages.',
            'yes'      => 'Two "Dimondale Adolescent Care Facility" records, one in Carson CA and one with only "CA": the same place, recommend approve.',
            'no'       => 'Two campuses of one company in different towns, or a program and the name it took later (a rename is not a duplicate: reject and write "rename" in the note).',
            'minutes'  => 2,
        ),
        'people-merge' => array(
            'title'    => 'People who may be listed twice',
            'question' => 'Are these two names the same person? Look at where each is named.',
            'yes'      => '"Cat Jennings" and "Catherine Jennings", both on staff at New Leaf Academy and Lake House Academy: recommend approve.',
            'no'       => 'First names that are not short forms of each other, or a different middle initial (Robert W. and Robert B. Lichfield are father and son): recommend reject.',
            'minutes'  => 2,
        ),
        'map-renames' => array(
            'title'    => 'Program renames on the network map',
            'question' => 'Did the first program become the second (same program, new name)? Check the quoted sources and their links.',
            'yes'      => '"Mars Home for Youth has changed its name to MHY Family Services" (Butler Eagle, 2012): recommend approve, and give the year in the note if the card has none.',
            'no'       => 'Two different programs, or one closed and another opened on the same land under a new owner: recommend reject and say why.',
            'minutes'  => 3,
        ),
        'news' => array(
            'title'    => 'News articles sent in',
            'question' => 'Is the article about a troubled teen program, abuse in one, or the industry around them (owners, consultants, transport, laws), and is it real reporting rather than the program\'s own advertising?',
            'yes'      => 'A local paper on a staff member charged after a teen was hurt at a ranch program: recommend approve.',
            'no'       => 'A program\'s press release, a sports "academy", a day camp: recommend reject. Already on the site under another link? Reject and say so.',
            'minutes'  => 2,
        ),
        'facilities-from-news' => array(
            'title'    => 'Programs the news names that we have no record of',
            'question' => 'Is this a real residential program for young people, and if the card suggests one of our records, is it the same place?',
            'yes'      => 'A group home for teens named in a news story, and the card suggests our record of that group home in the same town: recommend approve.',
            'no'       => 'An adult rehab, a hospital ward or a day school, or the suggested record is a different place: recommend reject and say which.',
            'minutes'  => 2,
        ),
        'closure' => array(
            'title'    => 'Program closures in the news',
            'question' => 'Does the article really say this program closed (or is closing)?',
            'yes'      => 'The article says the state pulled the licence and the program shut its doors: recommend approve.',
            'no'       => 'A different program with a similar name, a rumour, or only one building closing: recommend reject.',
            'minutes'  => 2,
        ),
        'lawsuit' => array(
            'title'    => 'Court cases sent in',
            'question' => 'Is this a real court case about a troubled teen program, its staff or its owners, and do the case name, court and dates on the card match the source?',
            'yes'      => 'A wrongful death suit against a ranch program, and the docket link shows the same case number, court and filing date: recommend approve.',
            'no'       => 'A case about an unrelated business with a similar name, or the card says something the court record does not: recommend reject and say what is wrong.',
            'minutes'  => 3,
        ),
        'legislation' => array(
            'title'    => 'Bills sent in',
            'question' => 'Is this a real bill about youth residential programs (restraint, seclusion, licensing, oversight, transport), and do the bill number and state match the official link?',
            'yes'      => 'A state bill limiting restraint in youth residential treatment, and the legislature\'s page shows the same number and title: recommend approve.',
            'no'       => 'A bill about public schools only, or the number on the card is not the bill the link shows: recommend reject and say which.',
            'minutes'  => 3,
        ),
        'wiki' => array(
            'title'    => 'Wiki write-ups sent in',
            'question' => 'Is the write-up about the program it names, factual, and free of personal attacks on private people (former students, parents)?',
            'yes'      => 'A write-up of a program\'s history, staff and lawsuits, each claim from a news story or court record: recommend approve.',
            'no'       => 'Something that looks invented or copied from the program\'s advertising, or that names a private person who was a student: recommend reject and say which part.',
            'minutes'  => 4,
        ),
        'highlight-summaries' => array(
            'title'    => 'Plain summaries of hard-to-read findings',
            'question' => 'Does the plain summary say only what the state\'s own wording under it says, and nothing more?',
            'yes'      => 'The state wrote "Staff #3 utilized a prone hold on Child A for 12 minutes, contrary to policy"; the summary says a staff member held a child face down for 12 minutes against the rules: recommend approve.',
            'no'       => 'The summary adds an injury, a name or a number the state\'s text does not give, or gets who did what the wrong way round: recommend reject and say which words.',
            'minutes'  => 2,
        ),
        'map-years' => array(
            'title'    => 'Opening and closing years for the map',
            'question' => 'Does the quoted sentence (open its link) really give this year for this place or company?',
            'yes'      => '"The academy opened its doors in 1996" from a news story about that academy, for its opening year: recommend approve.',
            'no'       => 'The year is when a new owner bought it, or the sentence is about another program with a similar name: recommend reject and say which.',
            'minutes'  => 2,
        ),
        'doc-types' => array(
            'title'    => 'What kind of document is it?',
            'question' => 'Open the document. Is the AI\'s guess of what kind of document it is right?',
            'yes'      => 'A booklet of rules, levels and consequences for students, guessed as "Student handbook": recommend approve.',
            'no'       => 'A state inspection report guessed as "News clipping": recommend reject and write the right kind in the note.',
            'minutes'  => 1,
        ),
        'program-practices' => array(
            'title'    => 'Rules read from program handbooks',
            'question' => 'Open each line\'s page of the document. Does the line say what the program\'s own words on that page say?',
            'yes'      => 'The line "Students on Level 1 may not call home" and page 12 says "Level One students have no phone privileges": recommend approve.',
            'no'       => 'A line says more than the page (a punishment, a time limit), or the page is about something else: recommend reject and say which line.',
            'minutes'  => 3,
        ),
        'company-milestones' => array(
            'title'    => 'Events in parent companies\' histories',
            'question' => 'Do the quoted sources say this event happened to this company, in this year?',
            'yes'      => 'A buyout dated 2015 and a press story saying the deal closed in 2015, naming the same company: recommend approve.',
            'no'       => 'The source names a different company, gives another year, or only says the deal was planned: recommend reject and say which.',
            'minutes'  => 2,
        ),
        'federal-funding' => array(
            'title'    => 'Federal money paid to programs',
            'question' => 'Do the sources show that this program (the same place, not one with a similar name) received this federal money?',
            'yes'      => 'A PPP loan record with the program\'s own name, its town and its street: recommend approve.',
            'no'       => 'A loan or grant to a different business with a similar name, or in another state: recommend reject.',
            'minutes'  => 2,
        ),
        'websites' => array(
            'title'    => 'Websites sent in',
            'question' => 'Is this page useful to Kids Over Profits: about a troubled teen program, the people who ran one, or the industry (a forum post, a survivor\'s blog, a program page, a directory)?',
            'yes'      => 'A survivor\'s blog post about their time at a named program, or a directory listing programs for "troubled teens": recommend approve.',
            'no'       => 'Spam, a broken link, or a page about something else (an adult rehab, a summer camp): recommend reject.',
            'minutes'  => 2,
        ),
        'folder-links' => array(
            'title'    => 'Document folders for one program',
            'question' => 'Are these two folder names both names of one program (a rename, a rebrand, a past name)?',
            'yes'      => 'A folder for "Copper Canyon Academy" and one for "Sedona Sky Academy", the name it took later: recommend approve.',
            'no'       => 'Two different programs that share a word ("Academy", "Ranch"), or two programs of one company: recommend reject.',
            'minutes'  => 1,
        ),
        'program-homes' => array(
            'title'    => 'Homes of one program',
            'question' => 'Are these records the separate licensed homes of one program, rather than separate programs? Open the records.',
            'yes'      => 'Numbered homes ("Echelon 1", "Echelon 3") run by one company in one area: recommend approve.',
            'no'       => 'Programs that only share a word like "House" or "Youth", or are run by different companies: recommend reject.',
            'minutes'  => 2,
        ),
        'status_checks' => array(
            'title'    => 'Changes to bills and court cases',
            'question' => 'Open the source link. Does it say what the card says changed (a bill passed or died, a case settled or was dismissed)?',
            'yes'      => 'The card says the case settled in May, and the court\'s docket shows a settlement entered in May: recommend approve.',
            'no'       => 'The source does not say it, or it is about another bill or case: recommend reject and say what the source does say.',
            'minutes'  => 2,
        ),
        'writeups' => array(
            'title'    => 'Write-ups of what we learned about a program',
            'question' => 'Does every sentence of the draft rest on the facts listed with it (with their sources), and nothing more?',
            'yes'      => 'Each sentence matches a fact on the card, and the draft does not guess at anything: recommend approve.',
            'no'       => 'A sentence adds a year, a job, an event or an opinion that no listed fact gives: recommend reject and quote the sentence.',
            'minutes'  => 4,
        ),
        'wiki-links' => array(
            'title'    => 'Wiki entries matched to our records',
            'question' => 'Is the record the card suggests the program (or company, or person) this r/troubledteens wiki entry is about? Open both.',
            'yes'      => 'The wiki entry and our record have the same name and town, or the entry gives the name our record lists as a past name: recommend approve.',
            'no'       => 'A different place with a similar name, or the entry is about the company and the record is one of its programs: recommend reject and say which.',
            'minutes'  => 2,
        ),
        'indigenous-schools' => array(
            'title'    => 'Indian boarding schools from the news',
            'question' => 'Is this an Indian boarding, residential or mission school, and do the name, place and years on the card match the article?',
            'yes'      => 'A federal Indian boarding school named in a story about the Interior Department\'s report, with the same state: recommend approve.',
            'no'       => 'A present-day troubled teen program, a public school, or details the article does not give: recommend reject and say which.',
            'minutes'  => 2,
        ),
        'scraper-finds' => array(
            'title'    => 'Articles the news scanner set aside',
            'question' => 'Is the article really about a troubled teen program or the industry?',
            'yes'      => '"Ranch staff charged after teen injured": recommend approve.',
            'no'       => 'A sports team, a summer camp with no residential program, an unrelated "academy": recommend reject.',
            'minutes'  => 1,
        ),
    );
}

/** The brief a new project of this queue starts with. */
function kop_volp_kind($source) {
    $k = kop_volp_kinds()[$source] ?? null;
    if ($k) return $k;
    $all = function_exists('kop_rinbox_sources') ? kop_rinbox_sources() : array();
    return array(
        'title'    => (string) ($all[$source]['label'] ?? $source),
        'question' => function_exists('kop_vol_guidance') ? kop_vol_guidance($source) : '',
        'yes' => '', 'no' => '', 'minutes' => 2,
    );
}

/** Queues a project can be made from: every queue but the never-for-volunteers ones. */
function kop_volp_sources() {
    $out = array();
    foreach (kop_rinbox_sources() as $key => $src) {
        if (in_array($key, kop_vol_never_sources(), true) || empty($src['list']) || empty($src['get'])) continue;
        $out[$key] = $src;
    }
    return $out;
}

/* ---- Making and reading projects ------------------------------------------------- */

function kop_volp_get($id) {
    global $wpdb;
    kop_volp_ensure_tables();
    $p = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_volp_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    return $p ?: null;
}

function kop_volp_url($id) {
    return add_query_arg('project', (int) $id, kop_vol_page_url());
}

/** Keys already in an open project of this queue (never handed out twice). */
function kop_volp_claimed_keys($source) {
    global $wpdb;
    kop_volp_ensure_tables();
    $rows = $wpdb->get_col($wpdb->prepare('SELECT i.item_key FROM ' . kop_volp_items_table() . ' i JOIN ' . kop_volp_table()
        . " p ON p.id = i.project_id WHERE p.source = %s AND p.status = 'open' AND i.gone < %d", (string) $source, KOP_VOLP_RELEASED));
    return array_fill_keys(array_map('strval', (array) $rows), true);
}

/** Keys any volunteer has an open recommendation on (already looked at). */
function kop_volp_recommended_keys($source) {
    global $wpdb;
    kop_vol_ensure_tables();
    $rows = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT item_key FROM ' . kop_vol_recs_table() . " WHERE source = %s AND outcome = ''", (string) $source));
    return array_fill_keys(array_map('strval', (array) $rows), true);
}

/**
 * Make a project. $in: source, size (any; 0 = every item waiting), search, volunteer_id (0 = anyone
 * with a link) or new_name (makes a volunteer), note, and optional question /
 * yes / no / title over the kind's. Returns [id, link, invite link or ''].
 */
function kop_volp_create(array $in) {
    if (strpos((string) ($in['source'] ?? ''), 'research:') === 0) return kop_volp_create_research($in);
    $source = sanitize_key((string) ($in['source'] ?? ''));
    $sources = kop_volp_sources();
    if (!isset($sources[$source])) throw new RuntimeException('Pick a queue volunteers may see.');
    $src = $sources[$source];
    $size = (int) ($in['size'] ?? 20);   // 0 (or less) = every item waiting
    if ($size < 1) $size = PHP_INT_MAX;
    $search = trim(mb_substr((string) ($in['search'] ?? ''), 0, 100));
    $kind = kop_volp_kind($source);

    $vid = (int) ($in['volunteer_id'] ?? 0);
    $new_name = trim((string) ($in['new_name'] ?? ''));
    if ($vid && !$new_name) {
        global $wpdb;
        kop_vol_ensure_tables();
        $ok = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . kop_vol_table() . ' WHERE id = %d AND revoked_at IS NULL', $vid));
        if (!$ok) throw new RuntimeException('That volunteer is turned off or gone.');
    }

    // The batch: waiting items of the queue's first view, not held, not in another open project, not already recommended on.
    $skip = kop_vol_held_keys($source) + kop_volp_claimed_keys($source) + kop_volp_recommended_keys($source);
    $views = array_keys((array) $src['views']);
    $picked = array();
    $offset = 0;
    $seen = array();
    while (count($picked) < $size) {
        $res = call_user_func($src['list'], array('view' => $views[0], 'search' => $search, 'offset' => $offset, 'limit' => 25, 'origin' => '', 'filters' => array()));
        $items = (array) ($res['items'] ?? array());
        $fresh = 0;
        foreach ($items as $it) {
            $offset++;
            $k = (string) ($it['key'] ?? '');
            if ($k !== '' && !isset($seen[$k])) { $seen[$k] = true; $fresh++; }
            if ($k === '' || isset($skip[$k]) || isset($picked[$k]) || !kop_vol_actionable($source, $it)) continue;
            $picked[$k] = array((string) ($it['title'] ?? ''), (string) ($it['status'] ?? ''));
            if (count($picked) >= $size) break;
        }
        if (count($items) < 25 || !$fresh) break;   // the end, or a list that ignores offset
    }
    if (!$picked) {
        throw new RuntimeException($search !== '' ? 'Nothing waiting in that queue matches "' . $search . '" (or it is all in other projects already).'
            : 'Nothing is waiting in that queue that is not already in a project.');
    }

    $invite = '';
    if ($new_name !== '') {
        list($vid, $invite) = kop_vol_create($new_name, 'Made for a project');
    }

    global $wpdb;
    kop_volp_ensure_tables();
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') $title = $kind['title'] . ($search !== '' ? ': ' . $search : '');
    $per = max(1, (int) $kind['minutes']);
    $u = wp_get_current_user();
    $wpdb->insert(kop_volp_table(), array(
        'title'       => mb_substr($title, 0, 200),
        'source'      => $source,
        'search'      => $search,
        'question'    => trim((string) ($in['question'] ?? '')) ?: $kind['question'],
        'yes_example' => trim((string) ($in['yes'] ?? '')) ?: $kind['yes'],
        'no_example'  => trim((string) ($in['no'] ?? '')) ?: $kind['no'],
        'note'        => trim(mb_substr((string) ($in['note'] ?? ''), 0, 1000)),
        'minutes'     => max(5, (int) ceil(count($picked) * $per / 5) * 5),
        'volunteer_id' => (int) $vid,
        'status'      => 'open',
        'created_by'  => isset($in['created_by']) ? mb_substr((string) $in['created_by'], 0, 60) : ($u && $u->exists() ? $u->user_login : ''),
        'created_at'  => current_time('mysql', true),
    ));
    $id = (int) $wpdb->insert_id;
    $n = 0;
    foreach ($picked as $k => $t) {
        $wpdb->insert(kop_volp_items_table(), array('project_id' => $id, 'item_key' => (string) $k, 'title' => mb_substr($t[0], 0, 255), 'status' => $t[1], 'position' => $n++));
    }
    $link = kop_volp_url($id);
    if ($invite !== '') $invite = add_query_arg('project', $id, $invite);
    return array($id, $link, $invite);
}

/**
 * A research project (inc/volunteer-research.php): $in['source'] is
 * 'research:<kind>', search a state or a word, questions the typed lines
 * for 'research:custom'. Same return as kop_volp_create().
 */
function kop_volp_create_research(array $in) {
    $kind_key = substr(sanitize_text_field((string) $in['source']), 9);
    $kinds = kop_volr_kinds();
    if (!isset($kinds[$kind_key])) throw new RuntimeException('Unknown kind of research.');
    $kind = $kinds[$kind_key];
    $size = (int) ($in['size'] ?? 20);
    // Typed questions: every line typed, whatever the size box says.
    if ($size < 1 || $kind['where'] === '') $size = PHP_INT_MAX;
    $search = trim(mb_substr((string) ($in['search'] ?? ''), 0, 100));
    $vid = (int) ($in['volunteer_id'] ?? 0);
    $new_name = trim((string) ($in['new_name'] ?? ''));
    if ($vid && !$new_name) {
        global $wpdb;
        kop_vol_ensure_tables();
        if (!$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . kop_vol_table() . ' WHERE id = %d AND revoked_at IS NULL', $vid))) throw new RuntimeException('That volunteer is turned off or gone.');
    }
    $picked = kop_volr_pick($kind_key, $search, $size, (string) ($in['questions'] ?? ''));
    $invite = '';
    if ($new_name !== '') list($vid, $invite) = kop_vol_create($new_name, 'Made for a project');

    global $wpdb;
    kop_volp_ensure_tables();
    $title = trim((string) ($in['title'] ?? ''));
    if ($title === '') $title = $kind['title'] . ($search !== '' ? ': ' . (preg_match('/^[A-Za-z]{2}$/', $search) ? strtoupper($search) : $search) : '');
    $u = wp_get_current_user();
    $wpdb->insert(kop_volp_table(), array(
        'title' => mb_substr($title, 0, 200), 'source' => 'research:' . $kind_key, 'search' => $search,
        'question' => trim((string) ($in['question'] ?? '')) ?: $kind['question'],
        'yes_example' => trim((string) ($in['yes'] ?? '')) ?: $kind['yes'],
        'no_example' => trim((string) ($in['no'] ?? '')) ?: $kind['no'],
        'note' => trim(mb_substr((string) ($in['note'] ?? ''), 0, 1000)),
        'minutes' => max(5, (int) ceil(count($picked) * max(1, (int) $kind['minutes']) / 5) * 5),
        'volunteer_id' => (int) $vid, 'status' => 'open',
        'created_by' => $u && $u->exists() ? $u->user_login : '', 'created_at' => current_time('mysql', true),
    ));
    $id = (int) $wpdb->insert_id;
    $n = 0;
    foreach ($picked as $k => $t) {
        $wpdb->insert(kop_volp_items_table(), array('project_id' => $id, 'item_key' => (string) $k, 'title' => $t[0], 'status' => '', 'position' => $n++,
            'detail' => wp_json_encode($t[1])));
    }
    $link = kop_volp_url($id);
    if ($invite !== '') $invite = add_query_arg('project', $id, $invite);
    return array($id, $link, $invite);
}

/** What a project's items are, in words: the brief's title for a queue, or the research kind. */
function kop_volp_source_label(array $p) {
    if (kop_volr_is($p)) return 'Research: ' . kop_volr_kind_of($p)['title'];
    if (kop_vsoc_is($p)) return 'Social media: post ideas';
    $all = kop_rinbox_sources();
    return (string) ($all[$p['source']]['label'] ?? $p['source']);
}

/** Open or close a project. $why on a close: '' (an admin), 'finished' or 'stalled' (kop_volp_sweep()). */
function kop_volp_set_status($id, $status, $why = '') {
    if (!in_array($status, array('open', 'closed'), true)) throw new RuntimeException('Unknown status.');
    global $wpdb;
    kop_volp_ensure_tables();
    $wpdb->update(kop_volp_table(), array('status' => $status, 'closed_at' => $status === 'closed' ? current_time('mysql', true) : null,
        'closed_why' => $status === 'closed' ? (string) $why : ''), array('id' => (int) $id));
}

/** Change the words of a project's brief. */
function kop_volp_update_brief($id, array $in) {
    $p = kop_volp_get($id);
    if (!$p) throw new RuntimeException('No such project.');
    $row = array();
    foreach (array('title' => 'title', 'question' => 'question', 'yes' => 'yes_example', 'no' => 'no_example', 'note' => 'note') as $from => $col) {
        if (isset($in[$from])) $row[$col] = trim((string) $in[$from]);
    }
    if (isset($row['title']) && $row['title'] === '') throw new RuntimeException('The project needs a title.');
    global $wpdb;
    if ($row) $wpdb->update(kop_volp_table(), $row, array('id' => (int) $id));
}

/** A project's items in order: [{item_key, title, status (when the project was made), gone}], handed-back ones left out. */
function kop_volp_item_rows($id, $detail = false) {
    global $wpdb;
    kop_volp_ensure_tables();
    return (array) $wpdb->get_results($wpdb->prepare('SELECT item_key, title, status, gone' . ($detail ? ', detail' : '') . ' FROM ' . kop_volp_items_table()
        . ' WHERE project_id = %d AND gone < %d ORDER BY position', (int) $id, KOP_VOLP_RELEASED), ARRAY_A);
}

/** How many items were handed back from this project after it went quiet. */
function kop_volp_released_count($id) {
    global $wpdb;
    kop_volp_ensure_tables();
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_volp_items_table() . ' WHERE project_id = %d AND gone = %d', (int) $id, KOP_VOLP_RELEASED));
}

/** Recommendations on a project's items: [item_key => [rows]], the project's volunteer only when it has one. */
function kop_volp_recs(array $p, array $keys = null) {
    global $wpdb;
    kop_vol_ensure_tables();
    $keys = $keys ?? array_column(kop_volp_item_rows($p['id']), 'item_key');
    if (!$keys) return array();
    $ph = implode(',', array_fill(0, count($keys), '%s'));
    $args = array_merge(array((string) $p['source']), array_map('strval', $keys));
    $who = '';
    if ((int) $p['volunteer_id']) {
        $who = ' AND r.volunteer_id = %d';
        $args[] = (int) $p['volunteer_id'];
    }
    $rows = $wpdb->get_results($wpdb->prepare('SELECT r.*, v.name FROM ' . kop_vol_recs_table() . ' r JOIN ' . kop_vol_table()
        . " v ON v.id = r.volunteer_id WHERE r.source = %s AND r.item_key IN ($ph)$who ORDER BY r.updated_at", $args), ARRAY_A);
    $out = array();
    foreach ((array) $rows as $r) $out[(string) $r['item_key']][] = $r;
    return $out;
}

/** {total, done (recommended or handled elsewhere), open (recommended, waiting on you), decided} for a project. */
function kop_volp_progress(array $p) {
    if (kop_volr_is($p)) return kop_volr_progress($p);
    if (kop_vsoc_is($p)) return kop_vsoc_progress($p);
    $rows = kop_volp_item_rows($p['id']);
    $recs = kop_volp_recs($p, array_column($rows, 'item_key'));
    $out = array('total' => count($rows), 'done' => 0, 'open' => 0, 'decided' => 0, 'last' => '');
    foreach ($rows as $r) {
        $rs = $recs[$r['item_key']] ?? array();
        if ($rs || (int) $r['gone']) $out['done']++;
        foreach ($rs as $x) if ($x['updated_at'] > $out['last']) $out['last'] = $x['updated_at'];
        if (!$rs) continue;
        $open = false;
        foreach ($rs as $x) if ($x['outcome'] === '') $open = true;
        $out[$open ? 'open' : 'decided']++;
    }
    return $out;
}

/** Can this volunteer work on this project? */
function kop_volp_for_volunteer(array $p, $vid) {
    return $p['status'] === 'open' && ((int) $p['volunteer_id'] === 0 || (int) $p['volunteer_id'] === (int) $vid)
        && !in_array($p['source'], kop_vol_never_sources(), true);
}

/** Is this item in an open project this volunteer may work on? (Lets kop_vol_recommend() take a queue not open to everyone.) */
function kop_volp_allows($vid, $source, $key) {
    global $wpdb;
    kop_volp_ensure_tables();
    $rows = $wpdb->get_results($wpdb->prepare('SELECT p.* FROM ' . kop_volp_table() . ' p JOIN ' . kop_volp_items_table()
        . " i ON i.project_id = p.id WHERE p.source = %s AND i.item_key = %s AND p.status = 'open' AND i.gone < %d", (string) $source, (string) $key, KOP_VOLP_RELEASED), ARRAY_A);
    foreach ((array) $rows as $p) if (kop_volp_for_volunteer($p, $vid)) return true;
    return false;
}

/** The open projects a volunteer sees (theirs, then anyone's), with progress. $vid 0 = admin preview: every open one. */
function kop_volp_list_for($vid) {
    global $wpdb;
    kop_volp_ensure_tables();
    $rows = $vid
        ? $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_volp_table() . " WHERE status = 'open' AND volunteer_id IN (0, %d) ORDER BY volunteer_id DESC, id", (int) $vid), ARRAY_A)
        : $wpdb->get_results('SELECT * FROM ' . kop_volp_table() . " WHERE status = 'open' ORDER BY id", ARRAY_A);
    $out = array();
    foreach ((array) $rows as $p) {
        if (in_array($p['source'], kop_vol_never_sources(), true)) continue;
        $out[] = kop_volp_public($p, $vid);
    }
    return $out;
}

/** A project as the volunteer page gets it: the brief and their own progress. */
function kop_volp_public(array $p, $vid) {
    $rows = kop_volp_item_rows($p['id']);
    $mine = array();
    $research = kop_volr_is($p);
    $social = kop_vsoc_is($p);
    if ($research) {
        if ($vid) $mine = kop_volr_answered_keys($p['id'], $vid);
    } elseif ($social) {
        if ($vid) $mine = kop_vsoc_done_keys($p['id'], $vid);
    } elseif ($vid) {
        global $wpdb;
        $keys = array_column($rows, 'item_key');
        if ($keys) {
            $ph = implode(',', array_fill(0, count($keys), '%s'));
            $mine = array_fill_keys(array_map('strval', (array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_vol_recs_table()
                . " WHERE volunteer_id = %d AND source = %s AND item_key IN ($ph)", array_merge(array((int) $vid, (string) $p['source']), $keys)))), true);
        }
    }
    $done = 0;
    foreach ($rows as $r) if (isset($mine[$r['item_key']]) || (int) $r['gone']) $done++;
    return array(
        'id' => (int) $p['id'], 'title' => (string) $p['title'], 'question' => (string) $p['question'],
        'yes' => (string) $p['yes_example'], 'no' => (string) $p['no_example'], 'note' => (string) $p['note'],
        'minutes' => (int) $p['minutes'], 'total' => count($rows), 'done' => $done, 'mine' => (int) $p['volunteer_id'] === (int) $vid && $vid,
        'source' => (string) $p['source'],
        'kind' => $research ? 'research' : ($social ? 'social' : 'review'), 'link_kinds' => $research ? kop_volr_link_kinds() : null,
        'faq' => $research || $social ? array() : kop_volf_live((string) $p['source']),
        'caption_max' => $social ? KOP_VSOC_CAPTION_MAX : null,
    );
}

/**
 * The next items of a project for this volunteer: not recommended on by
 * them yet, still waiting. An item handled some other way is marked gone (it
 * counts as done).
 */
function kop_volp_items($vid, $id, $limit = 3, array $skip = array()) {
    $p = kop_volp_get($id);
    if (!$p || ($vid && !kop_volp_for_volunteer($p, $vid))) {
        throw new RuntimeException($p && ($p['closed_why'] ?? '') === 'stalled' ? kop_volp_stalled_words()
            : 'This project is finished or not yours. Thank you!');
    }
    if (kop_volr_is($p)) {
        $items = kop_volr_items($p, $vid, $limit, $skip);
        return array('project' => kop_volp_public(kop_volp_get($id), $vid), 'items' => $items);
    }
    if (kop_vsoc_is($p)) {
        return array('project' => kop_volp_public($p, $vid), 'items' => kop_vsoc_items($p, $vid, $limit, $skip));
    }
    $src = kop_rinbox_sources()[$p['source']] ?? null;
    if (!$src) throw new RuntimeException('That queue is gone.');
    $held = kop_vol_held_keys($p['source']);
    // Skipped for now on the page: left for the end.
    $mine = array_fill_keys(array_map('strval', $skip), true);
    if ($vid) {
        global $wpdb;
        foreach ((array) $wpdb->get_col($wpdb->prepare('SELECT item_key FROM ' . kop_vol_recs_table() . ' WHERE volunteer_id = %d AND source = %s', (int) $vid, $p['source'])) as $k) {
            $mine[(string) $k] = true;
        }
    }
    $items = array();
    foreach (kop_volp_item_rows($id) as $r) {
        if ((int) $r['gone'] || isset($mine[$r['item_key']])) continue;
        $it = kop_vol_raw_item($src, $p['source'], $r['item_key']);
        // Decided some other way: its status moved since the project was made (queues keep buttons on decided items too).
        $moved = $it && $r['status'] !== '' && (string) ($it['status'] ?? '') !== $r['status'];
        if (!$it || $moved || !kop_vol_actionable($p['source'], $it) || isset($held[$r['item_key']])) {
            kop_volp_mark_gone($id, $r['item_key']);
            continue;
        }
        $items[] = kop_vol_item($p['source'], $it);
        if (count($items) >= $limit) break;
    }
    return array('project' => kop_volp_public(kop_volp_get($id), $vid), 'items' => $items);
}

function kop_volp_mark_gone($id, $key) {
    global $wpdb;
    $wpdb->update(kop_volp_items_table(), array('gone' => 1), array('project_id' => (int) $id, 'item_key' => (string) $key));
}

/* ---- Applying the volunteer's calls ------------------------------------------------ */

/**
 * The card's own button for a verdict, when it runs with what the card
 * already holds: [action id, params, label], or null (a choice to make, or a
 * submission-queue item, which is approved from the inbox).
 */
function kop_volp_pick_action(array $item, $verdict) {
    if (!in_array($verdict, array('approve', 'reject'), true)) return null;
    foreach ((array) ($item['actions'] ?? array()) as $a) {
        if (($a['style'] ?? '') !== $verdict || !empty($a['ask'])) continue;
        $params = array();
        $ok = true;
        foreach ((array) ($a['params'] ?? array()) as $pp) {
            $v = $pp['value'] ?? '';
            if (empty($pp['optional']) && ($v === '' || $v === null || $v === array())) { $ok = false; break; }
            $params[(string) $pp['name']] = $v;
        }
        if ($ok) return array((string) $a['id'], $params, (string) ($a['label'] ?? $a['id']));
    }
    return null;
}

/**
 * The project's rows for "Check their calls": each item with what was said,
 * the button Apply would press, and whether it starts ticked (one clear
 * approve or reject, nobody disagreeing, a button that needs no choice).
 */
function kop_volp_review_rows(array $p) {
    $rows = kop_volp_item_rows($p['id']);
    $recs = kop_volp_recs($p, array_column($rows, 'item_key'));
    $out = array();
    foreach ($rows as $r) {
        $rs = $recs[$r['item_key']] ?? array();
        $open = array_values(array_filter($rs, function ($x) { return $x['outcome'] === ''; }));
        $row = array('key' => $r['item_key'], 'title' => $r['title'], 'recs' => $rs, 'state' => '', 'verdict' => '', 'action' => null, 'ticked' => false);
        if (!$rs) {
            $row['state'] = (int) $r['gone'] ? 'gone' : 'waiting';
        } elseif (!$open) {
            $row['state'] = 'decided';
        } else {
            $verdicts = array_unique(array_column($open, 'verdict'));
            $row['state'] = 'ready';
            if (count($verdicts) === 1) $row['verdict'] = $verdicts[0];
            else $row['state'] = 'split';
            if ($row['verdict'] === 'approve' || $row['verdict'] === 'reject') {
                try {
                    $item = kop_rinbox_get_item($p['source'], $r['item_key']);
                    $row['action'] = kop_volp_pick_action($item, $row['verdict']);
                } catch (Throwable $e) {
                    $row['state'] = 'gone';
                }
                if ($row['state'] === 'ready' && !$row['action']) $row['state'] = 'inbox';
            } elseif ($row['state'] === 'ready') {
                $row['state'] = 'unsure';
            }
            $row['ticked'] = $row['state'] === 'ready' && $row['action'];
        }
        $out[] = $row;
    }
    return $out;
}

/** Apply the volunteers' calls on these keys: [{key, ok, message}]. */
function kop_volp_apply(array $p, array $keys) {
    $want = array_fill_keys(array_map('strval', $keys), true);
    $results = array();
    foreach (kop_volp_review_rows($p) as $row) {
        if (!isset($want[$row['key']])) continue;
        if ($row['state'] !== 'ready' || !$row['action']) {
            $results[] = array('key' => $row['key'], 'title' => $row['title'], 'ok' => false, 'message' => 'Not applied: open it in the inbox.');
            continue;
        }
        list($action, $params) = $row['action'];
        try {
            $res = kop_rinbox_apply_action($p['source'], $row['key'], $action, $params);
            $results[] = array('key' => $row['key'], 'title' => $row['title'], 'ok' => true, 'message' => (string) ($res['message'] ?? 'Done.'));
        } catch (Throwable $e) {
            $results[] = array('key' => $row['key'], 'title' => $row['title'], 'ok' => false, 'message' => $e->getMessage());
        }
    }
    return $results;
}

/* ---- Stalled and finished batches (hourly) ---------------------------------------------- */

/** Days without a call, an answer or a question before a batch's unanswered items are handed back; hours a finished batch stays open. */
function kop_volp_sweep_rules() {
    return array(
        'stall_days'     => max(1, (int) apply_filters('kop_volp_stall_days', 3)),
        'finished_hours' => max(0, (int) apply_filters('kop_volp_finished_close_hours', 24)),
    );
}

/** What a volunteer reads on a batch that was handed back. */
function kop_volp_stalled_words() {
    return 'This batch was handed back after ' . kop_volp_sweep_rules()['stall_days'] . ' days without answers, so others can pick its items up. '
        . 'The answers you gave on it are kept. Thank you!';
}

/**
 * The last time anything happened on a project: made, a call or an answer on
 * one of its items, or a question about it. 'waiting' is true when the last
 * message about it is the volunteer's: the batch is waiting on us, not on
 * them, so it is never handed back then.
 */
function kop_volp_activity(array $p) {
    $prog = kop_volp_progress($p);
    $last = max((string) $p['created_at'], (string) $prog['last']);
    $waiting = false;
    if (function_exists('kop_volm_for_project')) {
        $msgs = kop_volm_for_project((int) $p['id']);   // oldest first
        foreach ($msgs as $m) if ((string) $m['created_at'] > $last) $last = (string) $m['created_at'];
        if ($msgs) $waiting = end($msgs)['sender'] === 'vol';
    }
    return array('last' => $last, 'waiting' => $waiting, 'progress' => $prog);
}

/**
 * Hand back a quiet batch's unanswered items (they leave the project and are
 * free for the next one) and close it; close every finished batch (every item
 * answered or handled elsewhere) once it has been quiet 'finished_hours'. The
 * calls stay open for the admin under "Check their calls" and "Volunteers
 * recommend". Returns [{id, title, why, released}].
 */
function kop_volp_sweep($now = null) {
    global $wpdb;
    kop_volp_ensure_tables();
    $now = $now ?? time();
    $rules = kop_volp_sweep_rules();
    $out = array();
    foreach ((array) $wpdb->get_results('SELECT * FROM ' . kop_volp_table() . " WHERE status = 'open' ORDER BY id", ARRAY_A) as $p) {
        $act = kop_volp_activity($p);
        $quiet = $now - strtotime($act['last'] . ' UTC');
        $prog = $act['progress'];
        if ($prog['done'] < $prog['total'] && !$act['waiting'] && $quiet >= $rules['stall_days'] * DAY_IN_SECONDS) {
            $answered = kop_volr_is($p) ? kop_volr_answered_keys((int) $p['id'], 0) : (kop_vsoc_is($p) ? kop_vsoc_touched_keys((int) $p['id']) : kop_volp_recs($p));
            $released = 0;
            foreach (kop_volp_item_rows($p['id']) as $r) {
                if ((int) $r['gone'] || isset($answered[$r['item_key']])) continue;
                $wpdb->update(kop_volp_items_table(), array('gone' => KOP_VOLP_RELEASED), array('project_id' => (int) $p['id'], 'item_key' => (string) $r['item_key']));
                $released++;
            }
            kop_volp_set_status($p['id'], 'closed', 'stalled');
            $out[] = array('id' => (int) $p['id'], 'title' => (string) $p['title'], 'why' => 'stalled', 'released' => $released);
        } elseif ($prog['done'] >= $prog['total'] && $quiet >= $rules['finished_hours'] * HOUR_IN_SECONDS) {
            kop_volp_set_status($p['id'], 'closed', 'finished');
            $out[] = array('id' => (int) $p['id'], 'title' => (string) $p['title'], 'why' => 'finished', 'released' => 0);
        }
    }
    return $out;
}

add_action('init', function () {
    if (!wp_next_scheduled('kop_volp_sweep_hourly')) wp_schedule_event(time() + 900, 'hourly', 'kop_volp_sweep_hourly');
});
add_action('kop_volp_sweep_hourly', function () { kop_volp_sweep(); });

/** The volunteer's own batches handed back in the last two weeks: [{id, title}] (the page explains a link to one). */
function kop_volp_set_aside_for($vid) {
    if (!(int) $vid) return array();
    global $wpdb;
    kop_volp_ensure_tables();
    $rows = $wpdb->get_results($wpdb->prepare('SELECT id, title FROM ' . kop_volp_table() . " WHERE status = 'closed' AND closed_why = 'stalled' AND volunteer_id = %d AND closed_at >= %s ORDER BY id",
        (int) $vid, gmdate('Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS)), ARRAY_A);
    return array_map(function ($r) { return array('id' => (int) $r['id'], 'title' => (string) $r['title']); }, (array) $rows);
}

/* ---- Self-assign (volunteers with a track record) ---------------------------------- */

const KOP_VOLP_SELF_CREATED_BY = 'self-assigned';

/** What earns self-assign: this many calls the admin decided, agreeing at this share (0..1). */
function kop_volp_self_rules() {
    return array(
        'decided' => max(1, (int) apply_filters('kop_volp_self_min_decided', 10)),
        'rate'    => (float) apply_filters('kop_volp_self_min_rate', 0.8),
        'size'    => max(1, (int) apply_filters('kop_volp_self_size', 10)),
    );
}

/**
 * Can this volunteer pick their own batch? {eligible, decided, agreed, need_decided, need_rate, open (an unfinished
 * self-assigned project's id, or 0), queues [{key, label, count}]}. One self-assigned batch at a time: the next
 * opens when the last is finished. Before the record has 'decided' calls, a passed practice round opens its queue
 * ('via' => 'practice', queues only those); not eligible yet, 'practice' carries kop_volpr_status().
 */
function kop_volp_self_status($vid) {
    $rules = kop_volp_self_rules();
    $out = array('eligible' => false, 'decided' => 0, 'agreed' => 0, 'need_decided' => $rules['decided'], 'need_rate' => (int) round($rules['rate'] * 100),
        'size' => $rules['size'], 'open' => 0, 'queues' => array());
    if (!(int) $vid) return $out;
    global $wpdb;
    kop_vol_ensure_tables();
    $r = kop_vol_recs_table();
    $row = $wpdb->get_row($wpdb->prepare("SELECT
            SUM(outcome IN ('approve', 'reject') AND verdict IN ('approve', 'reject')) AS decided,
            SUM(outcome IN ('approve', 'reject') AND verdict = outcome) AS agreed
        FROM $r WHERE volunteer_id = %d AND outcome_by <> %s", (int) $vid, KOP_VOL_AUTO_BY), ARRAY_A);
    $out['decided'] = (int) ($row['decided'] ?? 0);
    $out['agreed'] = (int) ($row['agreed'] ?? 0);
    $out['eligible'] = $out['decided'] >= $rules['decided'] && $out['agreed'] >= $rules['rate'] * $out['decided'];
    // Until there is a record of real calls, a passed practice round (inc/volunteer-practice.php) opens its own queue.
    $practiced = $out['decided'] < $rules['decided'] && function_exists('kop_volpr_passed') ? kop_volpr_passed((int) $vid) : array();
    if ($practiced) {
        $out['eligible'] = true;
        $out['via'] = 'practice';
    }
    // Before they can pick a batch: the round to start with. After: the queues they can still learn ("Learn another queue").
    if (function_exists('kop_volpr_status')) $out['practice'] = kop_volpr_status((int) $vid);
    if (!$out['eligible']) return $out;

    kop_volp_ensure_tables();
    $mine = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_volp_table() . " WHERE status = 'open' AND volunteer_id = %d AND created_by = %s ORDER BY id",
        (int) $vid, KOP_VOLP_SELF_CREATED_BY), ARRAY_A);
    foreach ((array) $mine as $p) {
        $pub = kop_volp_public($p, (int) $vid);
        if ($pub['done'] < $pub['total']) { $out['open'] = (int) $p['id']; break; }
    }
    $counts = function_exists('kop_rinbox_counts') ? kop_rinbox_counts() : array();
    // Only the queues they have earned (kop_vol_sources_for()); before a record, only the ones they passed in practice.
    foreach (kop_vol_sources_for((int) $vid) as $key => $src) {
        if (empty($src['list']) || empty($src['get'])) continue;
        if ($practiced && !in_array($key, $practiced, true)) continue;
        $n = isset($counts[$key]) ? (int) $counts[$key] : null;
        if ($n === 0) continue;
        $out['queues'][] = array('key' => $key, 'label' => (string) $src['label'], 'count' => $n);
    }
    return $out;
}

/** Make the volunteer a batch of their own from a queue open to everyone. Returns {id, link}. */
function kop_volp_self_assign(array $vol, $source) {
    $vid = (int) $vol['id'];
    $st = kop_volp_self_status($vid);
    if (!$st['eligible']) throw new RuntimeException('Picking your own batch opens after you pass a practice round, or after ' . $st['need_decided'] . ' of your calls have been checked by an admin, agreeing ' . $st['need_rate'] . '% of the time.');
    if ($st['open']) throw new RuntimeException('Finish the batch you have open first. Thank you!');
    $source = sanitize_key((string) $source);
    if (!isset(kop_vol_earnable_sources()[$source])) throw new RuntimeException('Pick one of the queues listed.');
    if (($st['via'] ?? '') === 'practice' && !in_array($source, array_column($st['queues'], 'key'), true)) throw new RuntimeException('Pick one of the queues listed: the ones you passed a practice round in.');
    if (!in_array($source, array_column($st['queues'], 'key'), true)) throw new RuntimeException('That queue opens to you once you pass its practice round.');
    kop_vol_rate_check($vid);
    list($id, $link) = kop_volp_create(array('source' => $source, 'size' => $st['size'], 'volunteer_id' => $vid,
        'note' => 'Picked by ' . $vol['name'] . '.', 'created_by' => KOP_VOLP_SELF_CREATED_BY));
    return array('id' => (int) $id, 'link' => $link);
}

function kop_volp_rest_selfassign(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        if (empty($who['vol'])) throw new RuntimeException('Admins see this page as a preview; make a project on the Volunteer Reviewers screen.');
        return kop_volp_self_assign($who['vol'], (string) $req->get_param('source'));
    });
}

/* ---- REST (volunteer page) --------------------------------------------------------- */

add_action('rest_api_init', function () {
    foreach (array('projects' => 'kop_volp_rest_projects', 'project' => 'kop_volp_rest_project', 'selfassign' => 'kop_volp_rest_selfassign') as $path => $cb) {
        register_rest_route('kop/v1', '/volunteer/' . $path, array(
            'methods' => 'POST', 'callback' => $cb,
            'permission_callback' => function (WP_REST_Request $req) {
                $who = kop_vol_rest_who($req);
                return is_wp_error($who) ? $who : true;
            },
        ));
    }
});

function kop_volp_rest_projects(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        $vid = isset($who['vol']) ? (int) $who['vol']['id'] : 0;
        return array('projects' => kop_volp_list_for($vid), 'self' => kop_volp_self_status($vid), 'set_aside' => kop_volp_set_aside_for($vid),
            'set_aside_words' => kop_volp_stalled_words());
    });
}

function kop_volp_rest_project(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        $who = kop_vol_rest_who($req);
        $skip = $req->get_param('skip');
        return kop_volp_items(isset($who['vol']) ? (int) $who['vol']['id'] : 0, (int) $req->get_param('id'), max(1, min(5, (int) ($req->get_param('limit') ?: 3))),
            is_array($skip) ? array_slice(array_map('strval', $skip), 0, 100) : array());
    });
}

/* ---- KOP Tools > Volunteer Reviewers: Projects ---------------------------------------- */

/** Handle a projects form post on the Volunteer Reviewers screen: [notice, error, made] or null when not ours. */
function kop_volp_admin_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['kop_volp_do'])) return null;
    check_admin_referer('kop_vol_admin');
    $do = sanitize_key($_POST['kop_volp_do']);
    $id = (int) ($_POST['kop_volp_id'] ?? 0);
    $in = wp_unslash($_POST);
    try {
        if ($faq = kop_volf_admin_post($do, $in)) return $faq;
        if ($practice = kop_volpr_admin_post($do, $in)) return $practice;
        if ($social = kop_vsoc_admin_post($do, $in)) return $social;
        if ($do === 'create') {
            $who = (string) ($in['kop_volp_who'] ?? '0');
            if ($who === 'new' && trim((string) ($in['kop_volp_new_name'] ?? '')) === '') throw new RuntimeException('Give the new person a name.');
            $made = kop_volp_create(array(
                'source' => $in['kop_volp_source'] ?? '', 'size' => $in['kop_volp_size'] ?? 20, 'search' => $in['kop_volp_search'] ?? '',
                'volunteer_id' => $who === 'new' ? 0 : (int) $who, 'new_name' => $who === 'new' ? (string) ($in['kop_volp_new_name'] ?? '') : '',
                'note' => $in['kop_volp_note'] ?? '', 'questions' => (string) ($in['kop_volp_questions'] ?? ''),
            ));
            return array('Project made.', '', $made);
        }
        if ($do === 'decide') {
            kop_volr_decide((int) ($in['kop_volr_answer'] ?? 0), sanitize_key((string) ($in['kop_volr_status'] ?? '')));
            return array('Saved. They see it under "Your answers".', '', null);
        }
        if ($do === 'close') { kop_volp_set_status($id, 'closed'); return array('Project closed. Its items go back to being free for other projects.', '', null); }
        if ($do === 'reopen') {
            kop_volp_set_status($id, 'open');
            return array('Project reopened.' . (kop_volp_released_count($id) ? ' The items it handed back stay out of it; make a new project for them.' : ''), '', null);
        }
        if ($do === 'brief') {
            kop_volp_update_brief($id, array('title' => $in['kop_volp_title'] ?? '', 'question' => $in['kop_volp_question'] ?? '',
                'yes' => $in['kop_volp_yes'] ?? '', 'no' => $in['kop_volp_no'] ?? '', 'note' => $in['kop_volp_note'] ?? ''));
            return array('Brief saved. The volunteer sees it the next time they open the project.', '', null);
        }
        if ($do === 'apply') {
            $p = kop_volp_get($id);
            if (!$p) throw new RuntimeException('No such project.');
            $res = kop_volp_apply($p, array_map('strval', (array) ($in['kop_volp_keys'] ?? array())));
            $ok = count(array_filter($res, function ($r) { return $r['ok']; }));
            $bad = array_filter($res, function ($r) { return !$r['ok']; });
            $msg = $ok . ' applied. Each is in Recently done on the review inbox, with Undo.';
            $err = $bad ? 'Not applied: ' . implode('; ', array_map(function ($r) { return $r['title'] . ' (' . $r['message'] . ')'; }, $bad)) : '';
            return array($ok ? $msg : '', $err, null);
        }
    } catch (Throwable $e) {
        return array('', $e->getMessage(), null);
    }
    return null;
}

/** The message to paste to the person. */
function kop_volp_message(array $p, $link, $name = '') {
    $prog = kop_volp_progress($p);
    $hi = $name !== '' ? 'Hi ' . $name . ', c' : 'C';
    return $hi . 'ould you do a small task for Kids Over Profits? "' . $p['title'] . '": ' . $prog['total'] . ' items, about ' . (int) $p['minutes'] . " minutes.\n\n"
        . (kop_vsoc_is($p) ? 'What to do: ' : 'For each one there is one question: ') . $p['question'] . "\n\n"
        . (kop_vsoc_is($p) ? "Each one is a post idea with the facts and the page to point to. Take the ones you want, make the picture and write the caption; nothing is posted until I approve it.\n\n"
            : '')
        . (kop_vsoc_is($p) ? '' : (kop_volr_is($p) ? "Nothing changes on the site until I read your answer, so it is fine to say you could not find it (and where you looked).\n\n"
            : "You only recommend; nothing changes on the site until I check it, so it is fine to say \"not sure\".\n\n"))
        . "If anything is unclear, ask me on the page (\"Ask a question\"); my answer shows up there.\n\n"
        . 'Start here: ' . $link;
}

function kop_volp_admin_section($nonce, array $vols, $notice_made = null) {
    kop_volp_ensure_tables();
    $base = admin_url('admin.php?page=kop-volunteer-reviewers');
    $counts = kop_rinbox_counts();
    $sources = kop_volp_sources();
    $kinds = kop_volp_kinds();
    $names = array();
    foreach ($vols as $v) $names[(int) $v['id']] = $v['name'];
    ?>
    <h2 id="projects">Projects</h2>
    <p style="max-width:760px">A project is a batch from one queue (as many items as you like; 10 to 30 is a good first try) with one question per item, an example of a yes and a no,
        and a finish line. Send the link to one person. When they are done, <em>Check their calls</em> lists what they said and applies the ones you tick,
        each with its Undo in Recently done.</p>
    <?php if ($notice_made) :
        list($pid, $link, $invite) = $notice_made;
        $p = kop_volp_get($pid);
        $send = $invite !== '' ? $invite : $link;
        ?>
        <div class="notice notice-info" style="padding:12px;max-width:900px">
            <p><strong>Send this to <?php echo esc_html((int) $p['volunteer_id'] ? ($names[(int) $p['volunteer_id']] ?? 'them') : 'whoever you like'); ?></strong>
                (a direct message<?php echo $invite !== '' ? '; the link signs them in and is shown only this once' : ''; ?>):</p>
            <textarea readonly rows="8" style="width:100%;font-family:inherit" onclick="this.select()"><?php
                echo esc_textarea(kop_volp_message($p, $send, (string) ($names[(int) $p['volunteer_id']] ?? ''))); ?></textarea>
            <p><button type="button" class="button button-primary" onclick="var t=this.parentNode.previousElementSibling;t.select();navigator.clipboard&&navigator.clipboard.writeText(t.value);this.textContent='Copied'">Copy the message</button></p>
            <?php if ($invite === '' && (int) $p['volunteer_id']) : ?>
                <p>This link works on any device where they have opened their invite link once. On a new device, make them a new link above.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php kop_volf_admin_summary(); ?>
    <?php kop_volpr_admin_summary(); ?>
    <?php kop_vsoc_admin_settings($nonce, $vols); ?>

    <h3>Start a project</h3>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:10px 16px;max-width:900px;align-items:end">
        <?php echo $nonce; ?><input type="hidden" name="kop_volp_do" value="create">
        <label>What to check<br><select name="kop_volp_source" required style="width:100%">
            <?php
            $ready = array_intersect_key($sources, $kinds);
            $other = array_diff_key($sources, $kinds);
            foreach (array('Ready-made briefs' => $ready, 'Other queues (the brief is the queue\'s own help; edit it after)' => $other) as $group => $list) {
                echo '<optgroup label="' . esc_attr($group) . '">';
                uksort($list, function ($a, $b) use ($counts) { return (int) ($counts[$b] ?? 0) <=> (int) ($counts[$a] ?? 0); });
                foreach ($list as $key => $src) {
                    $n = $counts[$key] ?? null;
                    if ($n === 0) continue;
                    echo '<option value="' . esc_attr($key) . '">' . esc_html(($kinds[$key]['title'] ?? $src['label']) . ($n !== null ? ' (' . number_format((int) $n) . ' waiting)' : '')) . '</option>';
                }
                echo '</optgroup>';
            }
            echo '<optgroup label="Research: find something out, with a link">';
            foreach (kop_volr_kinds() as $key => $k) {
                echo '<option value="research:' . esc_attr($key) . '">' . esc_html($k['title'] . ($key === 'custom' ? ' (type your own)' : '')) . '</option>';
            }
            echo '</optgroup>';
            ?>
        </select></label>
        <label>How many (blank or 0 = every item waiting)<br><input type="number" name="kop_volp_size" min="0" step="1" value="20" style="width:100%"></label>
        <label>Only ones about (optional)<br><input type="text" name="kop_volp_search" maxlength="100" placeholder="a state (UT), a program, a word" style="width:100%"></label>
        <label>Who<br><select name="kop_volp_who" style="width:100%" onchange="this.form.querySelector('.kop-volp-new').hidden=this.value!=='new'">
            <option value="new">Someone new (makes their link)</option>
            <?php foreach ($vols as $v) : if (!empty($v['revoked_at'])) continue; ?>
                <option value="<?php echo (int) $v['id']; ?>"><?php echo esc_html($v['name']); ?></option>
            <?php endforeach; ?>
            <option value="0">Anyone with a volunteer link</option>
        </select></label>
        <label class="kop-volp-new">Their name<br><input type="text" name="kop_volp_new_name" maxlength="100" style="width:100%"></label>
        <label class="kop-volp-questions" style="grid-column:1/-1" hidden>The questions, one on each line<br>
            <textarea name="kop_volp_questions" rows="6" style="width:100%" placeholder="Who owned Hidden Lake Academy before 2005?&#10;Which company ran the transport for Ascent in 2003?"></textarea></label>
        <script>(function () {
            var f = document.currentScript.parentNode, s = f.querySelector('[name=kop_volp_source]'), q = f.querySelector('.kop-volp-questions');
            function show() { q.hidden = s.value !== 'research:custom'; }
            s.addEventListener('change', show); show();
        })();</script>
        <label style="grid-column:1/-1">A line from you (optional, shown above the question)<br><input type="text" name="kop_volp_note" maxlength="1000" style="width:100%"
            placeholder="e.g. These are all Texas findings; anything about restraint is a yes."></label>
        <p style="grid-column:1/-1;margin:0"><button type="submit" class="button button-primary">Make the project</button></p>
    </form>

    <h3>Open projects</h3>
    <?php
    global $wpdb;
    $projects = (array) $wpdb->get_results('SELECT * FROM ' . kop_volp_table() . ' ORDER BY id DESC LIMIT 60', ARRAY_A);
    if (!$projects) { echo '<p>None yet.</p>'; return; }
    // A batch that closed by itself with calls still waiting on you stays up with the open ones.
    $progs = array();
    foreach ($projects as $p) $progs[(int) $p['id']] = kop_volp_progress($p);
    $down = function ($p) use ($progs) { return $p['status'] === 'closed' && !$progs[(int) $p['id']]['open'] ? 1 : 0; };
    usort($projects, function ($a, $b) use ($down) { return $down($a) <=> $down($b) ?: (int) $b['id'] <=> (int) $a['id']; });
    echo '<p class="description" style="max-width:760px">A batch closes by itself a day after its last item is answered. One with no answers for ' . (int) kop_volp_sweep_rules()['stall_days']
        . ' days hands its unanswered items back (free for the next project) and closes; a question the volunteer is waiting on holds it open. Their calls stay here to check.</p>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Project</th><th>Who</th><th>Done</th><th>Waiting on you</th><th>Last activity</th><th></th></tr></thead><tbody>';
    foreach ($projects as $p) {
        $prog = $progs[(int) $p['id']];
        $closed = $p['status'] === 'closed';
        $who = (int) $p['volunteer_id'] ? ($names[(int) $p['volunteer_id']] ?? '(gone)') : 'Anyone';
        $why = array('finished' => ', finished', 'stalled' => ', handed back ' . kop_volp_released_count($p['id']) . ' unanswered')[$p['closed_why'] ?? ''] ?? '';
        echo '<tr' . ($closed ? ' style="opacity:.65"' : '') . '><td><a href="' . esc_url(add_query_arg('project', (int) $p['id'], $base)) . '"><strong>' . esc_html($p['title']) . '</strong></a><br><span class="description">'
            . esc_html(kop_volp_source_label($p) . ', about ' . (int) $p['minutes'] . ' min' . ($closed ? ', closed' . $why : '')) . '</span></td><td>' . esc_html($who) . '</td><td>'
            . (int) $prog['done'] . ' of ' . (int) $prog['total'] . '</td><td>' . ($prog['open'] ? '<strong>' . (int) $prog['open'] . '</strong>' : '0') . '</td><td>'
            . ($prog['last'] ? esc_html(get_date_from_gmt($prog['last'], 'M j g:i a')) : 'not started') . '</td><td>'
            . '<a class="button' . ($prog['open'] ? ' button-primary' : '') . '" href="' . esc_url(add_query_arg('project', (int) $p['id'], $base)) . '">' . (kop_volr_is($p) ? 'Read their answers' : (kop_vsoc_is($p) ? 'Read their posts' : 'Check their calls')) . '</a> '
            . '<form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="kop_volp_do" value="' . ($closed ? 'reopen' : 'close') . '"><input type="hidden" name="kop_volp_id" value="' . (int) $p['id'] . '">'
            . '<button type="submit" class="button-link">' . ($closed ? 'Reopen' : 'Close') . '</button></form></td></tr>';
    }
    echo '</tbody></table>';
}

/** One project: its brief (editable), each item with what was said, Apply ticked. */
function kop_volp_admin_project($id, $nonce, array $vols) {
    $p = kop_volp_get($id);
    $base = admin_url('admin.php?page=kop-volunteer-reviewers');
    if (!$p) { echo '<p>No such project. <a href="' . esc_url($base . '#projects') . '">Back</a></p>'; return; }
    $names = array();
    foreach ($vols as $v) $names[(int) $v['id']] = $v['name'];
    $src = array('label' => kop_volp_source_label($p));
    $inbox = function_exists('kop_find_template_page_url') ? (string) kop_find_template_page_url('page-admin-submissions.php') : '';
    $research = kop_volr_is($p);
    $social = kop_vsoc_is($p);
    $rows = $research || $social ? array() : kop_volp_review_rows($p);
    $prog = kop_volp_progress($p);
    $labels = kop_vol_verdicts();
    $state_words = array(
        'waiting' => 'Not looked at yet', 'gone' => 'Handled elsewhere', 'decided' => 'You decided it',
        'split' => 'Volunteers disagree: open it in the inbox', 'unsure' => 'Not sure: open it in the inbox',
        'inbox' => 'Needs a choice on the card: open it in the inbox',
    );
    ?>
    <p><a href="<?php echo esc_url($base . '#projects'); ?>">&larr; All projects</a></p>
    <h2><?php echo esc_html($p['title']); ?></h2>
    <p><?php echo esc_html($src['label'] . ' · ' . ((int) $p['volunteer_id'] ? 'for ' . ($names[(int) $p['volunteer_id']] ?? '(gone)') : 'for anyone with a link')
        . ' · ' . $prog['done'] . ' of ' . $prog['total'] . ' done · ' . ($p['status'] === 'closed' ? 'closed' : 'open')); ?>
        · Link: <input type="text" readonly value="<?php echo esc_attr(kop_volp_url($p['id'])); ?>" style="width:360px;max-width:100%;font-family:monospace" onclick="this.select()"></p>
    <details style="max-width:900px;margin-bottom:16px"><summary><strong>Message to send</strong></summary>
        <textarea readonly rows="8" style="width:100%" onclick="this.select()"><?php echo esc_textarea(kop_volp_message($p, kop_volp_url($p['id']), (string) ($names[(int) $p['volunteer_id']] ?? ''))); ?></textarea>
    </details>

    <?php kop_volp_admin_project_messages($p, $names); ?>

    <?php if ($research) : kop_volr_admin_project($p, $nonce, $names); elseif ($social) : kop_vsoc_admin_project($p, $nonce, $names); else : ?>
    <h3>Their calls</h3>
    <form method="post">
        <?php echo $nonce; ?><input type="hidden" name="kop_volp_do" value="apply"><input type="hidden" name="kop_volp_id" value="<?php echo (int) $p['id']; ?>">
        <p style="max-width:760px">Ticked rows are a clear approve or reject that the item's own button can carry out as it stands. Untick any you want to look at yourself;
            the rest wait in the inbox under <em>Volunteers recommend</em>.</p>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th style="width:30px"></th><th>Item</th><th>They said</th><th>Apply would</th></tr></thead><tbody>
            <?php foreach ($rows as $r) :
                $said = array();
                foreach ($r['recs'] as $x) {
                    $said[] = '<strong>' . esc_html($labels[$x['verdict']] ?? $x['verdict']) . '</strong>' . ((int) $p['volunteer_id'] ? '' : ' (' . esc_html($x['name']) . ')')
                        . ($x['note'] !== '' && $x['note'] !== null ? ': ' . esc_html($x['note']) : '');
                }
                $can = $r['state'] === 'ready' && $r['action'];
                $link = $inbox !== '' ? add_query_arg('type', $r['state'] === 'decided' || $r['state'] === 'waiting' ? $p['source'] : '_volunteers', $inbox) : '';
                ?>
                <tr>
                    <td><?php if ($can) : ?><input type="checkbox" name="kop_volp_keys[]" value="<?php echo esc_attr($r['key']); ?>" <?php checked($r['ticked']); ?> aria-label="Apply"><?php endif; ?></td>
                    <td><?php echo $link ? '<a href="' . esc_url($link) . '" target="_blank" rel="noopener">' . esc_html($r['title'] ?: $r['key']) . '</a>' : esc_html($r['title']); ?></td>
                    <td><?php echo $said ? implode('<br>', $said) : '<span class="description">' . esc_html($state_words[$r['state']] ?? '') . '</span>'; ?></td>
                    <td><?php echo $can ? esc_html($r['action'][2]) : ($said ? '<span class="description">' . esc_html($state_words[$r['state']] ?? '') . '</span>' : ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="submit" class="button button-primary">Apply ticked</button></p>
    </form>
    <?php endif; ?>

    <h3>The brief they see</h3>
    <?php if (!$research && !$social) : ?>
        <p><a href="<?php echo esc_url(kop_volf_admin_url($p['source'])); ?>">Questions others asked</a>: <?php echo count(kop_volf_live($p['source'])); ?> live on every brief of this queue.</p>
    <?php endif; ?>
    <form method="post" style="max-width:900px">
        <?php echo $nonce; ?><input type="hidden" name="kop_volp_do" value="brief"><input type="hidden" name="kop_volp_id" value="<?php echo (int) $p['id']; ?>">
        <p><label>Title<br><input type="text" name="kop_volp_title" value="<?php echo esc_attr($p['title']); ?>" style="width:100%"></label></p>
        <p><label>A line from you<br><input type="text" name="kop_volp_note" value="<?php echo esc_attr((string) $p['note']); ?>" style="width:100%"></label></p>
        <p><label>The question<br><textarea name="kop_volp_question" rows="2" style="width:100%"><?php echo esc_textarea((string) $p['question']); ?></textarea></label></p>
        <p><label>A yes looks like<br><textarea name="kop_volp_yes" rows="2" style="width:100%"><?php echo esc_textarea((string) $p['yes_example']); ?></textarea></label></p>
        <p><label>A no looks like<br><textarea name="kop_volp_no" rows="2" style="width:100%"><?php echo esc_textarea((string) $p['no_example']); ?></textarea></label></p>
        <p><button type="submit" class="button">Save the brief</button></p>
    </form>
    <?php
}

/** Questions volunteers asked about this project (inc/volunteer-messages.php), each linked to its conversation. */
function kop_volp_admin_project_messages(array $p, array $names) {
    $rows = kop_volm_for_project((int) $p['id']);
    if (!$rows) return;
    $unread = count(array_filter($rows, function ($r) { return $r['sender'] === 'vol' && empty($r['read_at']); }));
    echo '<details style="max-width:900px;margin-bottom:16px"' . ($unread ? ' open' : '') . '><summary><strong>Questions about this project</strong> ('
        . count($rows) . ($unread ? ', ' . $unread . ' new' : '') . ')</summary>';
    foreach ($rows as $r) {
        $who = $r['sender'] === 'admin' ? 'You' : (string) ($r['name'] ?? '');
        echo '<p style="margin:8px 0"><strong>' . esc_html($who) . '</strong> · ' . esc_html(get_date_from_gmt($r['created_at'], 'M j g:i a'))
            . ($r['item_title'] !== '' ? ' · ' . esc_html($r['item_title']) : '') . '<br><span style="white-space:pre-wrap">' . esc_html($r['body']) . '</span>'
            . ($r['sender'] === 'vol' ? '<br><a href="' . esc_url(kop_volm_admin_url((int) $r['volunteer_id'])) . '">' . (empty($r['read_at']) ? 'Answer' : 'Open the conversation') . '</a>' : '') . '</p>';
    }
    echo '</details>';
}

require_once __DIR__ . '/volunteer-research.php';
require_once __DIR__ . '/volunteer-faq.php';
require_once __DIR__ . '/volunteer-social.php';
require_once __DIR__ . '/volunteer-messages.php';
