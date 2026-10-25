<?php
/**
 * KOP Tools > Research Tasks: the open research questions (sources disagree, a record looks wrong, a draft had to
 * guess), one card each, assigned to the owner, to Claude or to a volunteer, answered and settled here.
 *
 * The table {prefix}kop_research_tasks is the list. Sessions add to it through seeds/research-tasks.json
 * (scripts/research-tasks/tasks.py add / answer / settle), imported on the first admin request after a deploy
 * (kop_rt_import_seed()): a new key is inserted once, and an entry that later gains an answer or a settled line
 * hands it over once (its hash is kept on the row), never over what the screen already holds. The 140 tasks that
 * were docs/RESEARCH-TASKS.md's "Open" list came in that way on 2026-10-23.
 *
 * Volunteers: ticked tasks go to one volunteer as a research project ('research:custom', inc/volunteer-research.php),
 * one question each (item key 'rt<id>'); their answer shows on the card and moves it to Answered
 * (kop_rt_sync_volunteer_answers()). Settling it marks their answer Used.
 * Claude: `php api/research-tasks.php --claude` (over SSH) lists the tasks assigned to Claude; a session answers
 * them through the seed.
 *
 * Every change on the screen is logged in {prefix}kop_research_task_log with the row as it was, so Undo puts it
 * back exactly (and closes a volunteer project the change made). Tested by scripts/test-research-tasks.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/admin-kit.php';

const KOP_RT_DB_VERSION = '2'; // 2: directions, companies, edited_at

add_filter('kop_admin_kit_pages', function ($pages) {
    $pages[] = 'kop-research-tasks';
    return $pages;
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Research Tasks', 'Research Tasks', 'manage_options', 'kop-research-tasks', 'kop_rt_page');
}, 30);

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos((string) $hook, 'kop-research-tasks') === false) return;
    $dir = get_stylesheet_directory();
    wp_enqueue_style('kop-research-tasks', get_stylesheet_directory_uri() . '/css/research-tasks.css', array(), (string) @filemtime($dir . '/css/research-tasks.css'));
});

// Merging two records moves the ids a task names (inc/facility-merge.php).
function kop_rt_merge_tables($tables, $prefix = null) {
    global $wpdb;
    $tables[] = array('t' => ($prefix ?? $wpdb->prefix) . 'kop_research_tasks', 'c' => 'records', 'key' => 'id');
    return $tables;
}
add_filter('kop_facility_merge_json_tables', 'kop_rt_merge_tables', 10, 2);

function kop_rt_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_research_tasks';
}

function kop_rt_log_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_research_task_log';
}

function kop_rt_install() {
    if (get_option('kop_rt_db') === KOP_RT_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    dbDelta('CREATE TABLE ' . kop_rt_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        task_key VARCHAR(191) NOT NULL,
        section VARCHAR(191) NOT NULL DEFAULT '',
        title VARCHAR(255) NOT NULL DEFAULT '',
        body MEDIUMTEXT NULL,
        context TEXT NULL,
        records TEXT NULL,
        found VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'open',
        assignee VARCHAR(12) NOT NULL DEFAULT '',
        volunteer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        question TEXT NULL,
        answer TEXT NULL,
        answer_by VARCHAR(100) NOT NULL DEFAULT '',
        answer_at DATETIME NULL,
        settled TEXT NULL,
        settled_at DATETIME NULL,
        directions TEXT NULL,
        companies TEXT NULL,
        edited_at DATETIME NULL,
        seed_hash VARCHAR(40) NOT NULL DEFAULT '',
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_by VARCHAR(60) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY task_key (task_key),
        KEY status (status)
    ) $c;");
    dbDelta('CREATE TABLE ' . kop_rt_log_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        action VARCHAR(20) NOT NULL,
        label VARCHAR(255) NOT NULL DEFAULT '',
        before_rows LONGTEXT NULL,
        project_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        user_login VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        undone_at DATETIME NULL,
        PRIMARY KEY  (id)
    ) $c;");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', kop_rt_table())) === kop_rt_table()) {
        update_option('kop_rt_db', KOP_RT_DB_VERSION, false);
    }
}

add_action('admin_init', function () {
    kop_rt_install();
    kop_rt_import_seed();
}, 6);

/* ---- Words ------------------------------------------------------------------------------ */

function kop_rt_statuses() {
    return array('open' => 'Open', 'answered' => 'Answered', 'settled' => 'Settled', 'dropped' => 'Dropped');
}

function kop_rt_assignees() {
    return array('' => 'Nobody yet', 'owner' => 'You', 'claude' => 'Claude', 'volunteer' => 'A volunteer');
}

function kop_rt_now() {
    return current_time('mysql', true);
}

function kop_rt_user() {
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    return $u && $u->exists() ? (string) $u->user_login : '';
}

/* ---- Rows ------------------------------------------------------------------------------- */

/** The facility ids a task names. */
function kop_rt_record_ids(array $t) {
    $r = json_decode((string) ($t['records'] ?? ''), true);
    $out = array();
    foreach (is_array($r) ? $r : array() as $x) {
        $id = (int) (is_array($x) ? ($x['id'] ?? 0) : $x);
        if ($id > 0 && !in_array($id, $out, true)) $out[] = $id;
    }
    return $out;
}

/** Ids as the records column stores them ([{"id": 9614}], so the merge tool can swap them). */
function kop_rt_records_json(array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return wp_json_encode(array_map(function ($id) { return array('id' => $id); }, $ids));
}

function kop_rt_get($id) {
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_rt_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    return $r ?: null;
}

/** Tasks for a view: open (open + answered, nobody's or anyone's), owner, claude, volunteer, answered, done, all. */
function kop_rt_list($view = 'open', $section = '', $search = '') {
    global $wpdb;
    $where = array('1=1');
    $args = array();
    switch ($view) {
        case 'owner': case 'claude': case 'volunteer':
            $where[] = "status IN ('open', 'answered') AND assignee = %s";
            $args[] = $view;
            break;
        case 'unassigned': $where[] = "status = 'open' AND assignee = ''"; break;
        case 'answered': $where[] = "status = 'answered'"; break;
        case 'done': $where[] = "status IN ('settled', 'dropped')"; break;
        case 'all': break;
        default: $where[] = "status IN ('open', 'answered')";
    }
    if ($section !== '') {
        $where[] = 'section = %s';
        $args[] = $section;
    }
    if ($search !== '') {
        $like = '%' . $wpdb->esc_like($search) . '%';
        $where[] = '(title LIKE %s OR body LIKE %s OR answer LIKE %s OR records LIKE %s)';
        array_push($args, $like, $like, $like, $like);
    }
    $sql = 'SELECT * FROM ' . kop_rt_table() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY section, id';
    return (array) $wpdb->get_results($args ? $wpdb->prepare($sql, $args) : $sql, ARRAY_A);
}

function kop_rt_counts() {
    global $wpdb;
    // pickable: unassigned outside kop_rt_volunteer_off_sections(), what the volunteer page offers.
    $out = array('open' => 0, 'unassigned' => 0, 'pickable' => 0, 'owner' => 0, 'claude' => 0, 'volunteer' => 0, 'answered' => 0, 'done' => 0, 'all' => 0);
    foreach ((array) $wpdb->get_results('SELECT status, assignee, section, COUNT(*) AS n FROM ' . kop_rt_table() . ' GROUP BY status, assignee, section', ARRAY_A) as $r) {
        $n = (int) $r['n'];
        $out['all'] += $n;
        if (in_array($r['status'], array('settled', 'dropped'), true)) { $out['done'] += $n; continue; }
        $out['open'] += $n;
        if ($r['status'] === 'answered') $out['answered'] += $n;
        if ($r['assignee'] === '' && $r['status'] === 'open') $out['unassigned'] += $n;
        if ($r['assignee'] === '' && $r['status'] === 'open' && kop_rt_for_volunteers($r)) $out['pickable'] += $n;
        if (isset($out[$r['assignee']]) && $r['assignee'] !== '') $out[$r['assignee']] += $n;
    }
    return $out;
}

function kop_rt_sections() {
    global $wpdb;
    return array_map('strval', (array) $wpdb->get_col('SELECT DISTINCT section FROM ' . kop_rt_table() . " WHERE section <> '' ORDER BY section"));
}

/* ---- Changes, logged for Undo ------------------------------------------------------------- */

/** Writes $set to each task, logging the rows as they were. Returns the log id. */
function kop_rt_change(array $ids, array $set, $action, $label, $project_id = 0) {
    global $wpdb;
    $before = array();
    foreach ($ids as $id) {
        $row = kop_rt_get($id);
        if ($row) $before[] = $row;
    }
    if (!$before) throw new RuntimeException('Those tasks are gone. Reload the page.');
    if ($action === 'edit') $set['edited_at'] = kop_rt_now();   // a session's rewording never overwrites it after this
    $set['updated_by'] = kop_rt_user();
    $set['updated_at'] = kop_rt_now();
    foreach ($before as $row) $wpdb->update(kop_rt_table(), $set, array('id' => (int) $row['id']));
    $wpdb->insert(kop_rt_log_table(), array('action' => $action, 'label' => mb_substr($label, 0, 255), 'before_rows' => wp_json_encode($before),
        'project_id' => (int) $project_id, 'user_login' => kop_rt_user(), 'created_at' => kop_rt_now()));
    return (int) $wpdb->insert_id;
}

/** A new task from the screen. Returns its id. */
/** A task's title is the question itself: there, and ending with "?". Returns it trimmed. */
function kop_rt_check_question($title) {
    $title = trim((string) $title);
    if ($title === '') throw new RuntimeException('Type the question to answer.');
    if (substr($title, -1) !== '?') throw new RuntimeException('Phrase it as a simple question, ending with "?" (for example "What year did Rocky Mountain Academy close?").');
    if (preg_match(kop_rt_inside_words(), $title, $m)) {
        throw new RuntimeException('"' . $m[0] . '" only means something to us. Name the program, place, person and year instead, so the question makes sense on its own.');
    }
    return $title;
}

/**
 * Words that point inside our own files (sheet rows, record or entry numbers, "in scope"): a question carrying them
 * can't be followed by anyone who hasn't opened that file. Same pattern as tasks.py's INSIDE_WORDS.
 */
function kop_rt_inside_words() {
    return '/\brows?\s+\d+|\bsheet\b|\b(?:record|entry|memorial id|page id)\s+#?\d+|#\d+|\bin scope\b/i';
}

/** "What it is | https://..." lines (or bare addresses) as directions. */
function kop_rt_parse_directions($text) {
    $out = array();
    foreach (preg_split('/\R/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $bar = strrpos($line, '|');
        $label = $bar === false ? '' : trim(substr($line, 0, $bar));
        $url = trim($bar === false ? $line : substr($line, $bar + 1));
        if (!preg_match('#^https?://\S+$#', $url)) throw new RuntimeException('Each "where to look" line needs a web address: "What it is | https://...". This one has none: ' . $line);
        $out[] = array('label' => mb_substr($label !== '' ? $label : $url, 0, 200), 'url' => $url, 'kind' => 'note');
    }
    return $out;
}

function kop_rt_directions_text(array $dirs) {
    return implode("\n", array_map(function ($d) { return $d['label'] . ' | ' . $d['url']; }, $dirs));
}

function kop_rt_add(array $in) {
    global $wpdb;
    $title = mb_substr(kop_rt_check_question($in['title'] ?? ''), 0, 255);
    $now = kop_rt_now();
    $wpdb->insert(kop_rt_table(), array(
        'task_key' => 'site:' . bin2hex(random_bytes(8)),
        'section' => trim(mb_substr((string) ($in['section'] ?? ''), 0, 191)), 'title' => $title,
        'body' => trim((string) ($in['body'] ?? '')), 'context' => '', 'records' => kop_rt_records_json((array) ($in['records'] ?? array())),
        'directions' => wp_json_encode(array_values((array) ($in['directions'] ?? array()))),
        'found' => 'added ' . gmdate('Y-m-d') . ' by ' . kop_rt_user(), 'status' => 'open', 'assignee' => '',
        'created_by' => kop_rt_user(), 'created_at' => $now, 'updated_by' => kop_rt_user(), 'updated_at' => $now,
    ));
    $id = (int) $wpdb->insert_id;
    if (!$id) throw new RuntimeException('Could not save: ' . $wpdb->last_error);
    $wpdb->insert(kop_rt_log_table(), array('action' => 'add', 'label' => 'Added "' . mb_substr($title, 0, 200) . '"',
        'before_rows' => wp_json_encode(array(array('id' => $id, '__new' => 1))), 'user_login' => kop_rt_user(), 'created_at' => $now));
    return $id;
}

/** Puts every row of a log entry back as it was (deletes a task the entry added; closes a project it made). */
function kop_rt_undo($log_id) {
    global $wpdb;
    $log = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_rt_log_table() . ' WHERE id = %d', (int) $log_id), ARRAY_A);
    if (!$log) throw new RuntimeException('Nothing to undo.');
    if (!empty($log['undone_at'])) throw new RuntimeException('Already undone.');
    foreach ((array) json_decode((string) $log['before_rows'], true) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if (!$id) continue;
        if (!empty($row['__new'])) {
            $wpdb->delete(kop_rt_table(), array('id' => $id));
            continue;
        }
        // The seed hash stays as it is: an undone session change must not come back on the next import.
        unset($row['id'], $row['seed_hash']);
        $wpdb->update(kop_rt_table(), $row, array('id' => $id));
    }
    if ((int) $log['project_id'] && function_exists('kop_volp_set_status')) kop_volp_set_status((int) $log['project_id'], 'closed', 'undone');
    $wpdb->update(kop_rt_log_table(), array('undone_at' => kop_rt_now()), array('id' => (int) $log_id));
    return (string) $log['label'];
}

function kop_rt_recent_log($limit = 10) {
    global $wpdb;
    return (array) $wpdb->get_results($wpdb->prepare('SELECT id, action, label, user_login, created_at, undone_at FROM ' . kop_rt_log_table() . ' ORDER BY id DESC LIMIT %d', (int) $limit), ARRAY_A);
}

/* ---- Volunteers ---------------------------------------------------------------------------- */

/** The kop_operators ids a task names (companies column, same shape as records). */
function kop_rt_company_ids(array $t) {
    return kop_rt_record_ids(array('records' => $t['companies'] ?? ''));
}

/** The task's own "where to look" list: [{label, url, kind}]. */
function kop_rt_directions(array $t) {
    $d = json_decode((string) ($t['directions'] ?? ''), true);
    return array_values(array_filter(is_array($d) ? $d : array(), function ($x) {
        return is_array($x) && preg_match('#^https?://#', (string) ($x['url'] ?? ''));
    }));
}

/** Company names by id. */
function kop_rt_company_names(array $ids) {
    global $wpdb;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return array();
    $out = array();
    foreach ((array) $wpdb->get_results('SELECT id, name FROM ' . $wpdb->prefix . 'kop_operators WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A) as $r) {
        $out[(int) $r['id']] = (string) $r['name'];
    }
    return $out;
}

/**
 * Where to look, among what KOP already holds: each record's KOP page (its news, court records, documents and
 * inspections), each company's page, then the task's own list (news, court cases, Woodbury issues, archived
 * websites, links from the notes). [{label, url, kind}]; a record or company with no page is left out.
 */
function kop_rt_where_to_look(array $t, array $names, array $companies) {
    $out = array();
    foreach (kop_rt_record_ids($t) as $rid) {
        $url = isset($names[$rid]) && function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($rid) : '';
        if (preg_match('#^https?://#', $url)) {
            $out[] = array('label' => 'KOP page: ' . $names[$rid] . ' (its news, court records, documents and inspections)', 'url' => $url, 'kind' => 'record');
        }
    }
    foreach (kop_rt_company_ids($t) as $cid) {
        $url = isset($companies[$cid]) && function_exists('kop_operator_page_url') ? (string) kop_operator_page_url($cid) : '';
        if (preg_match('#^https?://#', $url)) $out[] = array('label' => 'KOP company page: ' . $companies[$cid], 'url' => $url, 'kind' => 'company');
    }
    $seen = array_column($out, 'url');
    foreach (kop_rt_directions($t) as $d) {
        if (in_array($d['url'], $seen, true)) continue;
        $seen[] = $d['url'];
        $out[] = array('label' => (string) ($d['label'] ?? $d['url']), 'url' => (string) $d['url'], 'kind' => (string) ($d['kind'] ?? 'note'));
    }
    return $out;
}

/**
 * What a volunteer reads for a task: the question (its own wording for volunteers when one is set), then where to
 * look with each address. The details stay ours: they are written for staff.
 */
function kop_rt_volunteer_question(array $t) {
    $q = trim((string) ($t['question'] ?? ''));
    $q = $q !== '' ? $q : trim((string) $t['title']);
    $look = kop_rt_where_to_look($t, kop_rt_record_names(kop_rt_record_ids($t)), kop_rt_company_names(kop_rt_company_ids($t)));
    if ($look) {
        $q .= "\n\nWhere to start (what we already have):";
        foreach (array_slice($look, 0, 12) as $l) $q .= "\n- " . $l['label'] . ': ' . $l['url'];
    }
    return $q;
}

/**
 * Sends the tasks to one volunteer as a research project ($vid, or $new_name makes a volunteer).
 * Returns [log id, project id, project link, invite link or ''].
 */
function kop_rt_assign_volunteer(array $ids, $vid, $new_name = '', $note = '', $title = '', $by = '') {
    if (!function_exists('kop_volp_create_research')) throw new RuntimeException('Volunteer projects are not loaded.');
    $items = array();
    $tasks = array();
    foreach ($ids as $id) {
        $t = kop_rt_get($id);
        if (!$t || !in_array($t['status'], array('open', 'answered'), true)) continue;
        if (!kop_rt_for_volunteers($t)) {
            throw new RuntimeException('Task #' . (int) $t['id'] . ' is in ' . $t['section'] . ': ' . kop_rt_volunteer_off_sections()[$t['section']] . '. Untick it, or give it to yourself or to Claude.');
        }
        $tasks[] = $t;
        $detail = array('question' => mb_substr(kop_rt_volunteer_question($t), 0, 2000));
        $recs = kop_rt_record_ids($t);
        if (count($recs) === 1) $detail['facility_id'] = $recs[0];   // the card shows that record's facts
        $items['rt' . (int) $t['id']] = array(mb_substr($t['title'], 0, 255), $detail);
    }
    if (!$items) throw new RuntimeException('Tick at least one open task.');
    list($pid, $link, $invite) = kop_volp_create_research(array(
        'source' => 'research:custom', 'items' => $items, 'volunteer_id' => (int) $vid, 'new_name' => (string) $new_name,
        'note' => (string) $note, 'title' => $title !== '' ? $title : 'Research questions' . (count($tasks) === 1 ? ': ' . $tasks[0]['title'] : ''),
    ));
    $p = kop_volp_get($pid);
    $log = kop_rt_change(array_column($tasks, 'id'), array('assignee' => 'volunteer', 'volunteer_id' => (int) ($p['volunteer_id'] ?? $vid), 'project_id' => (int) $pid),
        'volunteer', $by !== '' ? $by . ' ' . count($tasks) . ' question' . (count($tasks) === 1 ? '' : 's') . ' on their volunteer page' : 'Sent ' . count($tasks) . ' to a volunteer', $pid);
    return array($log, $pid, $link, $invite);
}

/* ---- Volunteers pick their own questions (the volunteer page's "Research questions you can pick") ---- */

/**
 * Sections volunteers never get, section => why (filter kop_rt_volunteer_off_sections): In Loving Memory's questions
 * check the memorial sheet, which volunteers cannot open.
 */
function kop_rt_volunteer_off_sections() {
    return (array) apply_filters('kop_rt_volunteer_off_sections', array('In Loving Memory' => 'they check the memorial sheet, which volunteers cannot open'));
}

function kop_rt_for_volunteers(array $t) {
    return !isset(kop_rt_volunteer_off_sections()[(string) ($t['section'] ?? '')]);
}

/** How many unanswered questions one volunteer may hold at a time (filter kop_rt_volunteer_hold). */
function kop_rt_volunteer_hold() {
    return max(1, (int) apply_filters('kop_rt_volunteer_hold', 5));
}

/** The questions a volunteer may pick: open and given to nobody (the owner's and Claude's stay off it), outside the off sections. */
function kop_rt_pickable() {
    kop_rt_free_released();
    $out = array();
    $rows = array_values(array_filter(kop_rt_list('unassigned'), 'kop_rt_for_volunteers'));
    $ids = array();
    foreach ($rows as $t) $ids = array_merge($ids, kop_rt_record_ids($t));
    $names = kop_rt_record_names($ids);
    foreach ($rows as $t) {
        $places = array();
        foreach (kop_rt_record_ids($t) as $rid) if (isset($names[$rid])) $places[] = $names[$rid];
        $out[] = array('id' => (int) $t['id'], 'title' => (string) $t['title'], 'section' => (string) $t['section'],
            'about' => implode('; ', array_slice($places, 0, 3)), 'start' => count(kop_rt_where_to_look($t, $names, array())) + count(kop_rt_company_ids($t)));
    }
    return $out;
}

/** The questions a volunteer holds now (given to them, not answered yet). */
function kop_rt_held_by($vid) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_rt_table() . " WHERE assignee = 'volunteer' AND volunteer_id = %d AND status = 'open'", (int) $vid));
}

/**
 * A volunteer takes the questions they ticked: those still given to nobody become one research project of
 * theirs (the same as an admin's "Send to a volunteer"), up to kop_rt_volunteer_hold() held at once.
 * Returns [project id, how many taken].
 */
function kop_rt_volunteer_take(array $vol, array $ids) {
    $vid = (int) $vol['id'];
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) throw new RuntimeException('Tick the questions you want to research first.');
    kop_rt_free_released();
    $room = kop_rt_volunteer_hold() - kop_rt_held_by($vid);
    if ($room <= 0) throw new RuntimeException('You already have ' . kop_rt_volunteer_hold() . ' questions to answer. Answer or hand back some of them first, then pick more.');
    $free = array();
    foreach ($ids as $id) {
        $t = kop_rt_get($id);
        if ($t && $t['status'] === 'open' && $t['assignee'] === '' && kop_rt_for_volunteers($t)) $free[] = $id;
    }
    if (!$free) throw new RuntimeException('Someone else has just taken ' . (count($ids) === 1 ? 'that question' : 'those questions') . '. Pick another.');
    if (count($free) > $room) throw new RuntimeException('You can hold ' . kop_rt_volunteer_hold() . ' questions at a time and have room for ' . $room . ' more: untick ' . (count($free) - $room) . '.');
    list(, $pid) = kop_rt_assign_volunteer($free, $vid, '', '', count($free) === 1 ? '' : 'Research questions you picked', $vol['name'] . ' picked');
    return array($pid, count($free));
}

/**
 * Questions whose volunteer project closed without an answer (handed back, left, set aside after a long wait)
 * go back to nobody, so the list offers them again.
 */
function kop_rt_free_released() {
    global $wpdb;
    if (!function_exists('kop_volp_get')) return 0;
    $n = 0;
    foreach ((array) $wpdb->get_results('SELECT id, project_id FROM ' . kop_rt_table() . " WHERE status = 'open' AND assignee = 'volunteer' AND project_id > 0", ARRAY_A) as $t) {
        $p = kop_volp_get((int) $t['project_id']);
        if ($p && $p['status'] === 'open') continue;
        $wpdb->update(kop_rt_table(), array('assignee' => '', 'volunteer_id' => 0, 'project_id' => 0, 'updated_at' => kop_rt_now()), array('id' => (int) $t['id']));
        $n++;
    }
    return $n;
}

add_action('rest_api_init', function () {
    if (!function_exists('kop_vol_rest_who')) return;
    register_rest_route('kop/v1', '/volunteer/research', array(
        'methods' => 'POST', 'callback' => 'kop_rt_rest_volunteer',
        'permission_callback' => function (WP_REST_Request $req) {
            $who = kop_vol_rest_who($req);
            return is_wp_error($who) ? $who : true;
        },
    ));
});

/** POST volunteer/research: {do: 'list'} the questions to pick from; {do: 'take', ids: [...]} takes them. */
function kop_rt_rest_volunteer(WP_REST_Request $req) {
    return kop_rinbox_rest(function () use ($req) {
        kop_rt_install();
        kop_rt_sync_volunteer_answers();
        $who = kop_vol_rest_who($req);
        $vid = isset($who['vol']) ? (int) $who['vol']['id'] : 0;
        if ($req->get_param('do') === 'take') {
            if (!$vid) throw new RuntimeException('Admins see this page as a preview; picking questions is for volunteers.');
            $ids = $req->get_param('ids');
            list($pid, $n) = kop_rt_volunteer_take($who['vol'], is_array($ids) ? $ids : array());
            return array('id' => $pid, 'taken' => $n, 'message' => $n === 1 ? 'It is yours: it opens below.' : 'They are yours: they open below, one at a time.');
        }
        return array('questions' => kop_rt_pickable(), 'hold' => kop_rt_volunteer_hold(), 'held' => $vid ? kop_rt_held_by($vid) : 0);
    });
}

/** A volunteer's answer moves its task to Answered (the answer and its links copied onto the task). */
function kop_rt_sync_volunteer_answers() {
    global $wpdb;
    if (!function_exists('kop_volr_table')) return 0;
    kop_volr_ensure_tables();
    $waiting = array();
    foreach ((array) $wpdb->get_results('SELECT id, project_id FROM ' . kop_rt_table() . " WHERE status = 'open' AND project_id > 0", ARRAY_A) as $t) {
        $waiting[(int) $t['project_id'] . ':rt' . (int) $t['id']] = (int) $t['id'];
    }
    if (!$waiting) return 0;
    $pids = implode(',', array_unique(array_map(function ($k) { return (int) $k; }, array_keys($waiting))));
    $answers = (array) $wpdb->get_results('SELECT a.project_id, a.item_key, a.answer, a.found, a.links, a.updated_at, v.name FROM ' . kop_volr_table() . ' a LEFT JOIN '
        . kop_vol_table() . " v ON v.id = a.volunteer_id WHERE a.project_id IN ($pids) ORDER BY a.updated_at", ARRAY_A);
    $n = 0;
    foreach ($answers as $r) {
        $tid = $waiting[(int) $r['project_id'] . ':' . $r['item_key']] ?? 0;
        if (!$tid) continue;
        $r['id'] = $tid;
        $text = ((int) $r['found'] ? '' : 'Could not find it. ') . trim((string) $r['answer']);
        // Cited facts already carry their links in the text ("- fact ([source](url))"); older answers list theirs under it.
        foreach (kop_volr_links_of($r) as $l) {
            if (($l['claim'] ?? '') === '') $text .= "\n- " . (($l['title'] ?? '') !== '' ? $l['title'] . ': ' : '') . $l['url'];
        }
        $wpdb->update(kop_rt_table(), array('status' => 'answered', 'answer' => $text, 'answer_by' => mb_substr(($r['name'] ?: 'A volunteer') . ' (volunteer)', 0, 100),
            'answer_at' => $r['updated_at'], 'updated_at' => kop_rt_now()), array('id' => (int) $r['id']));
        $n++;
    }
    return $n;
}

/** Marks the volunteer's answer to a task Used, when it has one. */
function kop_rt_mark_volunteer_used(array $t) {
    global $wpdb;
    if (!(int) $t['project_id'] || !function_exists('kop_volr_decide')) return;
    $aid = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . kop_volr_table() . " WHERE project_id = %d AND item_key = %s AND status = ''", (int) $t['project_id'], 'rt' . (int) $t['id']));
    if ($aid) {
        try { kop_volr_decide((int) $aid, 'used'); } catch (RuntimeException $e) { /* already decided */ }
    }
}

/* ---- The seed ------------------------------------------------------------------------------- */

function kop_rt_seed($path = null) {
    $path = $path ?? get_stylesheet_directory() . '/seeds/research-tasks.json';
    $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
    return is_array($data) && isset($data['tasks']) && is_array($data['tasks']) ? $data : array('tasks' => array());
}

/**
 * New keys in the seed become tasks; an entry whose hash moved hands over its answer (an open task becomes
 * Answered) and its settled line (an open or answered task becomes Settled), and its wording (title, details,
 * where to look, records and companies named) when nobody has edited the task on the screen (edited_at empty).
 * Records only ever gain ids that way: one the merge tool moved stays moved. Runs when the file changes.
 * Returns [added, answered, settled, reworded].
 */
function kop_rt_import_seed($path = null, $force = false) {
    global $wpdb;
    $path = $path ?? get_stylesheet_directory() . '/seeds/research-tasks.json';
    if (!is_readable($path) || get_option('kop_rt_db') !== KOP_RT_DB_VERSION) return array(0, 0, 0, 0);
    $sha = sha1_file($path);
    if (!$force && get_option('kop_rt_seed_sha') === $sha) return array(0, 0, 0, 0);
    $have = array();
    foreach ((array) $wpdb->get_results('SELECT id, task_key, status, seed_hash, answer, title, body, context, records, directions, companies, edited_at FROM '
        . kop_rt_table(), ARRAY_A) as $r) $have[$r['task_key']] = $r;
    $added = $answered = $settled = $reworded = 0;
    $now = kop_rt_now();
    foreach (kop_rt_seed($path)['tasks'] as $e) {
        $key = (string) ($e['key'] ?? '');
        if ($key === '' || trim((string) ($e['title'] ?? '')) === '') continue;
        $hash = sha1(wp_json_encode($e));
        $ans = is_array($e['answer'] ?? null) ? $e['answer'] : null;
        $set = is_array($e['settled'] ?? null) ? $e['settled'] : null;
        $row = $have[$key] ?? null;
        if (!$row) {
            $wpdb->insert(kop_rt_table(), array(
                'task_key' => mb_substr($key, 0, 191), 'section' => mb_substr((string) ($e['section'] ?? ''), 0, 191), 'title' => mb_substr((string) $e['title'], 0, 255),
                'body' => (string) ($e['text'] ?? ''), 'context' => (string) ($e['context'] ?? ''), 'records' => kop_rt_records_json((array) ($e['records'] ?? array())),
                'found' => mb_substr((string) ($e['found'] ?? ''), 0, 255),
                'directions' => wp_json_encode(array_values((array) ($e['directions'] ?? array()))), 'companies' => kop_rt_records_json((array) ($e['companies'] ?? array())),
                'status' => $set ? 'settled' : ($ans ? 'answered' : 'open'),
                'answer' => $ans ? kop_rt_seed_answer_text($ans) : null, 'answer_by' => $ans ? mb_substr((string) ($ans['by'] ?? 'Claude session'), 0, 100) : '',
                'answer_at' => $ans ? $now : null, 'settled' => $set ? (string) ($set['text'] ?? '') : null, 'settled_at' => $set ? $now : null,
                'seed_hash' => $hash, 'created_by' => 'seed', 'created_at' => $now, 'updated_by' => 'seed', 'updated_at' => $now,
            ));
            $added++;
            continue;
        }
        if ($row['seed_hash'] === $hash) continue;
        $up = array('seed_hash' => $hash);
        if (empty($row['edited_at']) && in_array($row['status'], array('open', 'answered'), true)) {
            $words = array('title' => mb_substr((string) $e['title'], 0, 255), 'body' => (string) ($e['text'] ?? ''), 'context' => (string) ($e['context'] ?? ''),
                'directions' => wp_json_encode(array_values((array) ($e['directions'] ?? array()))),
                'records' => kop_rt_records_json(array_merge(kop_rt_record_ids($row), (array) ($e['records'] ?? array()))),
                'companies' => kop_rt_records_json(array_merge(kop_rt_company_ids($row), (array) ($e['companies'] ?? array()))));
            $changed = array_filter($words, function ($v, $k) use ($row) { return (string) $v !== (string) $row[$k]; }, ARRAY_FILTER_USE_BOTH);
            if ($changed) {
                $up += $changed;
                $reworded++;
            }
        }
        if ($ans && $row['status'] === 'open' && trim((string) $row['answer']) === '') {
            $up += array('status' => 'answered', 'answer' => kop_rt_seed_answer_text($ans), 'answer_by' => mb_substr((string) ($ans['by'] ?? 'Claude session'), 0, 100), 'answer_at' => $now);
            $answered++;
        }
        if ($set && in_array($row['status'], array('open', 'answered'), true)) {
            $up = array('seed_hash' => $hash, 'status' => 'settled', 'settled' => (string) ($set['text'] ?? ''), 'settled_at' => $now) + $up;
            $settled++;
        }
        if (count($up) > 1) {
            kop_rt_change(array((int) $row['id']), $up, 'seed', 'From a session: ' . (isset($up['settled']) ? 'settled' : (isset($up['answer']) ? 'answered' : 'reworded'))
                . ' "' . mb_substr((string) $e['title'], 0, 100) . '"');
        } else {
            $wpdb->update(kop_rt_table(), $up, array('id' => (int) $row['id']));
        }
    }
    update_option('kop_rt_seed_sha', $sha, false);
    return array($added, $answered, $settled, $reworded);
}

/**
 * A session's answer from the seed as the card shows it: each cited fact on its own line with its source link
 * ("- fact ([source](url))", the sentence under it), then any other text. Same form as a volunteer's answer.
 */
function kop_rt_seed_answer_text(array $ans) {
    $lines = array();
    foreach ((array) ($ans['claims'] ?? array()) as $c) {
        $url = (string) ($c['url'] ?? '');
        if (trim((string) ($c['claim'] ?? '')) === '' || !preg_match('#^https?://\S+$#', $url)) continue;
        $lines[] = '- ' . preg_replace('/\s+/', ' ', trim($c['claim'])) . ' ([source](' . $url . '))';
        if (trim((string) ($c['quote'] ?? '')) !== '') $lines[] = '  - It says: "' . preg_replace('/\s+/', ' ', trim($c['quote'], " \"")) . '"';
    }
    $text = trim((string) ($ans['text'] ?? ''));
    return trim(implode("\n", $lines) . ($text !== '' ? "\n\n" . $text : ''));
}

/* ---- What sessions read (api/research-tasks.php) ---------------------------------------------- */

function kop_rt_export(array $rows) {
    $out = array();
    foreach ($rows as $t) {
        $out[] = array('id' => (int) $t['id'], 'key' => $t['task_key'], 'section' => $t['section'], 'title' => $t['title'], 'text' => (string) $t['body'],
            'context' => (string) $t['context'], 'records' => kop_rt_record_ids($t), 'status' => $t['status'], 'assignee' => $t['assignee'],
            'answer' => (string) $t['answer'], 'answerBy' => $t['answer_by'], 'settled' => (string) $t['settled'],
            'directions' => kop_rt_directions($t), 'companies' => kop_rt_company_ids($t), 'fromSeed' => strpos((string) $t['task_key'], 'site:') !== 0);
    }
    return $out;
}

/* ---- The screen -------------------------------------------------------------------------------- */

/** The body's markdown: lists (any depth flattened one level) and paragraphs, inline as the Plan Board reads it. */
function kop_rt_md($s) {
    $md = function_exists('kop_pb_md') ? 'kop_pb_md' : 'esc_html';
    $out = '';
    foreach (preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", (string) $s))) as $block) {
        $lines = explode("\n", $block);
        $items = array();
        $para = array();
        foreach ($lines as $l) {
            if (preg_match('/^\s*[-*]\s+(.*)$/', $l, $m)) {
                if ($para) { $out .= '<p>' . $md(implode(' ', $para)) . '</p>'; $para = array(); }
                $items[] = array($m[1], strlen($l) - strlen(ltrim($l)) > 0);
            } elseif ($items && trim($l) !== '') {
                $items[count($items) - 1][0] .= ' ' . trim($l);
            } else {
                $para[] = trim($l);
            }
        }
        if ($para) $out .= '<p>' . $md(implode(' ', $para)) . '</p>';
        if ($items) {
            $out .= '<ul>';
            foreach ($items as $it) $out .= '<li' . ($it[1] ? ' class="is-sub"' : '') . '>' . $md($it[0]) . '</li>';
            $out .= '</ul>';
        }
    }
    return $out;
}

function kop_rt_url(array $args = array()) {
    return add_query_arg(array_filter($args + array('page' => 'kop-research-tasks'), function ($v) { return $v !== '' && $v !== null; }), admin_url('admin.php'));
}

/** Record names by id, for the cards. */
function kop_rt_record_names(array $ids) {
    global $wpdb;
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return array();
    $out = array();
    foreach ((array) $wpdb->get_results('SELECT id, name, city, state FROM facilities_v2 WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A) as $r) {
        $out[(int) $r['id']] = $r['name'] . (trim((string) $r['state']) !== '' ? ' (' . trim($r['city'] . ', ' . $r['state'], ', ') . ')' : '');
    }
    return $out;
}

function kop_rt_submit($label, $act, $variant = 'secondary', $extra = '') {
    return '<button type="submit" name="rt_act" value="' . esc_attr($act) . '" class="button kop-ak-btn kop-ak-btn--' . esc_attr($variant) . '"' . $extra . '>' . esc_html($label) . '</button>';
}

function kop_rt_card_html(array $t, array $names, array $vols, array $companies = array()) {
    $id = (int) $t['id'];
    $st = $t['status'];
    $statuses = kop_rt_statuses();
    $who = kop_rt_assignees();
    $assignee = $t['assignee'] === 'volunteer' ? ($vols[(int) $t['volunteer_id']] ?? 'A volunteer') : ($who[$t['assignee']] ?? '');
    $out = '<article class="kop-rt-card is-' . esc_attr($st) . '" id="rt-' . $id . '">';
    $out .= '<div class="kop-rt-meta"><label class="kop-rt-pick"><input type="checkbox" name="rt_pick[]" value="' . $id . '"' . (in_array($st, array('open', 'answered'), true) ? '' : ' disabled')
        . '><span class="screen-reader-text">Tick: ' . esc_html($t['title']) . '</span></label>'
        . '<span class="kop-rt-chip is-' . esc_attr($st) . '">' . esc_html($statuses[$st] ?? $st) . '</span>'
        . ($t['assignee'] !== '' ? '<span class="kop-rt-chip is-who">' . esc_html($assignee) . '</span>' : '') . '</div>';
    $out .= '<h3 class="kop-rt-title">' . esc_html($t['title']) . '</h3>';

    $recs = kop_rt_record_ids($t);
    // Where to look: what KOP already holds about it.
    $look = kop_rt_where_to_look($t, $names, $companies);
    if ($look) {
        $out .= '<div class="kop-rt-look"><p class="kop-rt-look__head">Where to look (what we already have)</p><ul>';
        foreach ($look as $l) {
            $out .= '<li class="is-' . esc_attr($l['kind']) . '"><a href="' . esc_url($l['url']) . '" target="_blank" rel="noopener">' . esc_html($l['label']) . '</a></li>';
        }
        $out .= '</ul></div>';
    } else {
        $out .= '<p class="kop-rt-look__none">Nothing on file about this yet: start with a web search, then add what you find with Edit.</p>';
    }
    $gone = array_filter($recs, function ($rid) use ($names) { return !isset($names[$rid]); });
    if ($gone) $out .= '<p class="kop-rt-found">Record' . (count($gone) > 1 ? 's' : '') . ' #' . esc_html(implode(', #', $gone)) . ' gone since (merged or deleted).</p>';
    // The notes behind it, written for staff.
    $body = trim((string) $t['body']);
    $notes = ($body !== '' && $body !== $t['title'] ? kop_rt_md($body) : '')
        . (trim((string) $t['context']) !== '' ? '<p class="kop-rt-look__head">Background for this section</p>' . kop_rt_md($t['context']) : '');
    if ($notes !== '') $out .= '<details class="kop-rt-more"><summary>Details</summary><div class="kop-rt-body">' . $notes . '</div></details>';
    $foot = array();
    if ($t['found'] !== '') $foot[] = ucfirst($t['found']);
    if ((int) $t['project_id']) {
        $foot[] = '<a href="' . esc_url(add_query_arg('project', (int) $t['project_id'], admin_url('admin.php?page=kop-volunteer-reviewers'))) . '">Volunteer project #' . (int) $t['project_id'] . '</a>';
    }
    if ($foot) $out .= '<p class="kop-rt-found">' . implode(' &middot; ', array_map(function ($f) { return strpos($f, '<a ') === 0 ? $f : esc_html($f); }, $foot)) . '</p>';

    if (trim((string) $t['answer']) !== '') {
        $out .= '<div class="kop-rt-answer"><strong>Answer' . ($t['answer_by'] !== '' ? ' from ' . esc_html($t['answer_by']) : '') . ':</strong>'
            . '<div class="kop-rt-body">' . kop_rt_md($t['answer']) . '</div></div>';
    }
    if (in_array($st, array('settled', 'dropped'), true)) {
        $out .= '<div class="kop-rt-answer is-settled"><strong>' . esc_html($statuses[$st]) . ($t['settled_at'] ? ' ' . esc_html(substr($t['settled_at'], 0, 10)) : '') . ':</strong> '
            . '<div class="kop-rt-body">' . kop_rt_md((string) $t['settled']) . '</div></div>';
        $out .= '<div class="kop-rt-actions">' . kop_rt_submit('Reopen', 'reopen:' . $id, 'quiet') . '</div>';
        return $out . '</article>';
    }

    // Who has it.
    $out .= '<div class="kop-rt-actions">';
    foreach (array('owner' => 'Mine', 'claude' => 'Claude', '' => 'Nobody') as $k => $l) {
        if ($t['assignee'] === $k) continue;
        $out .= kop_rt_submit($k === '' ? 'Unassign' : 'Give to ' . ($k === 'owner' ? 'me' : $l), 'assign-' . ($k === '' ? 'none' : $k) . ':' . $id, 'secondary');
    }
    $out .= '</div>';
    // Settle, drop, edit.
    $ta = 'rt-text-' . $id;
    $out .= '<details class="kop-rt-more kop-rt-settle"><summary>' . ($st === 'answered' ? 'Settle it' : 'Settle or drop it') . '</summary>'
        . '<label for="' . $ta . '">What settled it (the source, and where the answer went: a record, a draft, a commit)</label>'
        . '<textarea id="' . $ta . '" name="rt_text[' . $id . ']" rows="3"></textarea><div class="kop-rt-actions">'
        . kop_rt_submit('Settled', 'settle:' . $id, 'primary') . kop_rt_submit('Drop it (not worth finding)', 'drop:' . $id, 'danger')
        . ($st === 'answered' ? kop_rt_submit('The answer does not settle it', 'reopen:' . $id, 'quiet') : '') . '</div></details>';
    $out .= '<details class="kop-rt-more kop-rt-edit"><summary>Edit</summary>'
        . '<label for="rt-title-' . $id . '">The question (plain words, ending with ?)</label><input type="text" id="rt-title-' . $id . '" name="rt_title[' . $id . ']" value="' . esc_attr($t['title']) . '">'
        . '<label for="rt-look-' . $id . '">Where to look: one per line, "What it is | https://..." (the records&#39; and companies&#39; KOP pages are added by themselves)</label>'
        . '<textarea id="rt-look-' . $id . '" name="rt_look[' . $id . ']" rows="4">' . esc_textarea(kop_rt_directions_text(kop_rt_directions($t))) . '</textarea>'
        . '<label for="rt-body-' . $id . '">Details (for us: what each source says, where the answer goes)</label><textarea id="rt-body-' . $id . '" name="rt_body[' . $id . ']" rows="5">' . esc_textarea((string) $t['body']) . '</textarea>'
        . '<label for="rt-q-' . $id . '">Different wording for a volunteer (optional; they always get the question and where to look)</label>'
        . '<textarea id="rt-q-' . $id . '" name="rt_question[' . $id . ']" rows="2">' . esc_textarea((string) $t['question']) . '</textarea>';
    if ($recs) {
        $out .= '<fieldset class="kop-rt-recs"><legend>Records (untick to take one off)</legend>';
        foreach ($recs as $rid) {
            $out .= '<label><input type="checkbox" name="rt_keep[' . $id . '][]" value="' . $rid . '" checked> ' . esc_html(($names[$rid] ?? 'Record') . ' #' . $rid) . '</label>';
        }
        $out .= '</fieldset>';
    }
    $out .= '<label>Add a record</label>' . kop_facility_finder_field('rt_addrec[' . $id . ']')
        . '<input type="hidden" name="rt_recs_shown[' . $id . ']" value="1"><div class="kop-rt-actions">' . kop_rt_submit('Save', 'edit:' . $id, 'primary') . '</div></details>';
    return $out . '</article>';
}

/** The screen's POSTs. Returns [message, log id for Undo, extra html]. */
function kop_rt_handle_post() {
    check_admin_referer('kop_rt');
    $act = sanitize_text_field(wp_unslash($_POST['rt_act'] ?? ''));
    list($verb, $id) = array_pad(explode(':', $act, 2), 2, '');
    $id = (int) $id;
    $text = function ($field) use ($id) {
        $v = $_POST[$field][$id] ?? '';
        return trim((string) wp_unslash(is_string($v) ? $v : ''));
    };
    switch ($verb) {
        case 'undo':
            return array('Undone: ' . kop_rt_undo($id), 0, '');
        case 'add':
            $recs = array_filter(array((int) ($_POST['rt_new_record'] ?? 0)));
            $nid = kop_rt_add(array('title' => wp_unslash($_POST['rt_new_title'] ?? ''), 'body' => wp_unslash($_POST['rt_new_body'] ?? ''),
                'section' => wp_unslash($_POST['rt_new_section'] ?? ''), 'records' => $recs,
                'directions' => kop_rt_parse_directions((string) wp_unslash($_POST['rt_new_look'] ?? ''))));
            global $wpdb;
            return array('Added.', (int) $wpdb->get_var('SELECT MAX(id) FROM ' . kop_rt_log_table()), '<a href="#rt-' . $nid . '">Go to it</a>');
        case 'assign-owner': case 'assign-claude': case 'assign-none':
            $who = $verb === 'assign-none' ? '' : substr($verb, 7);
            return array($who === '' ? 'Unassigned.' : 'Given to ' . ($who === 'owner' ? 'you' : 'Claude') . '.',
                kop_rt_change(array($id), array('assignee' => $who, 'volunteer_id' => 0), 'assign', 'Assigned task #' . $id), '');
        case 'settle': case 'drop':
            $t = kop_rt_get($id);
            $why = $text('rt_text');
            if ($verb === 'settle' && $why === '') throw new RuntimeException('Say what settled it first (the source, and where the answer went).');
            $log = kop_rt_change(array($id), array('status' => $verb === 'settle' ? 'settled' : 'dropped', 'settled' => $why, 'settled_at' => kop_rt_now()),
                $verb, ($verb === 'settle' ? 'Settled' : 'Dropped') . ' "' . mb_substr((string) ($t['title'] ?? ''), 0, 120) . '"');
            if ($verb === 'settle' && $t) kop_rt_mark_volunteer_used($t);
            return array($verb === 'settle' ? 'Settled.' : 'Dropped.', $log, '');
        case 'reopen':
            return array('Reopened.', kop_rt_change(array($id), array('status' => 'open', 'settled' => null, 'settled_at' => null), 'reopen', 'Reopened task #' . $id), '');
        case 'edit':
            $t = kop_rt_get($id);
            if (!$t) throw new RuntimeException('That task is gone.');
            $title = kop_rt_check_question($text('rt_title'));
            $keep = array_map('intval', (array) ($_POST['rt_keep'][$id] ?? array()));
            $add = (int) ($_POST['rt_addrec'][$id] ?? 0);
            if ($add) $keep[] = $add;
            return array('Saved.', kop_rt_change(array($id), array('title' => mb_substr($title, 0, 255), 'body' => $text('rt_body'), 'question' => $text('rt_question'),
                'directions' => wp_json_encode(kop_rt_parse_directions($text('rt_look'))), 'records' => kop_rt_records_json($keep)), 'edit', 'Edited task #' . $id), '');
        case 'bulk-owner': case 'bulk-claude': case 'bulk-none':
            $ids = array_map('intval', (array) ($_POST['rt_pick'] ?? array()));
            if (!$ids) throw new RuntimeException('Tick the tasks first.');
            $who = $verb === 'bulk-none' ? '' : substr($verb, 5);
            return array(count($ids) . ' ' . ($who === '' ? 'unassigned' : 'given to ' . ($who === 'owner' ? 'you' : 'Claude')) . '.',
                kop_rt_change($ids, array('assignee' => $who, 'volunteer_id' => 0), 'assign', 'Assigned ' . count($ids) . ' tasks'), '');
        case 'bulk-volunteer':
            $ids = array_map('intval', (array) ($_POST['rt_pick'] ?? array()));
            if (!$ids) throw new RuntimeException('Tick the tasks first.');
            list($log, $pid, $link, $invite) = kop_rt_assign_volunteer($ids, (int) ($_POST['rt_vol'] ?? 0), trim((string) wp_unslash($_POST['rt_vol_new'] ?? '')),
                (string) wp_unslash($_POST['rt_vol_note'] ?? ''));
            $send = $invite !== '' ? $invite : $link;
            return array('Sent as volunteer project #' . $pid . '.', $log, '<span class="kop-rt-link">Send them this link: <input type="text" readonly value="' . esc_attr($send) . '" onclick="this.select()"></span>'
                . ($invite !== '' ? ' <span class="description">It is their own sign-in link: it is shown once.</span>' : ''));
    }
    throw new RuntimeException('Unknown action.');
}

function kop_rt_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    kop_rt_page_body();
}

function kop_rt_page_body() {
    kop_rt_install();
    kop_rt_import_seed();
    $notice = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rt_act'])) {
        try {
            list($msg, $log, $extra) = kop_rt_handle_post();
            $notice = '<div class="notice notice-success kop-rt-notice"><p>' . esc_html($msg) . ' ' . $extra
                . ($log ? ' <button type="submit" form="kop-rt-form" name="rt_act" value="undo:' . (int) $log . '" class="button-link">Undo</button>' : '') . '</p></div>';
        } catch (RuntimeException $e) {
            $notice = '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
    kop_rt_sync_volunteer_answers();
    kop_rt_free_released();

    $view = sanitize_key($_GET['view'] ?? 'open');
    $section = sanitize_text_field(wp_unslash($_GET['section'] ?? ''));
    $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
    $counts = kop_rt_counts();
    $rows = kop_rt_list($view, $section, $q);
    $vols = array();
    $vol_options = '';
    if (function_exists('kop_vol_list')) {
        foreach (kop_vol_list() as $v) {
            $vols[(int) $v['id']] = (string) $v['name'];
            if (empty($v['revoked_at'])) $vol_options .= '<option value="' . (int) $v['id'] . '">' . esc_html($v['name']) . '</option>';
        }
    }
    $ids = array();
    foreach ($rows as $t) $ids = array_merge($ids, kop_rt_record_ids($t));
    $names = kop_rt_record_names($ids);
    $cids = array();
    foreach ($rows as $t) $cids = array_merge($cids, kop_rt_company_ids($t));
    $companies = kop_rt_company_names($cids);

    echo '<div class="wrap kop-ak kop-rt">';
    echo kop_ak_header('Research Tasks', array(
        'lede' => 'Questions that need finding out before a record, a draft or a page can say them for certain: two sources disagree, a record looks wrong, or a draft had to guess. Give each one to yourself, to Claude or to a volunteer, and settle it here.',
        'steps' => array(
            '<em>Give to me</em> or <em>Give to Claude</em> on a card, or tick several and use the bar at the top. Claude works through its list when you say "work the research tasks".',
            'For a volunteer, tick the tasks, pick the person (or type a new name) and press <em>Send to a volunteer</em>. They get the tasks as a research project, one question each; edit a card&#39;s <em>Question for a volunteer</em> first when its details are written for us, not for them.',
            'An answer from a volunteer or from Claude moves the card to <em>Answered</em>. Read it, change the record if it holds, then <em>Settled</em> with what settled it. <em>Drop it</em> when it is not worth finding.',
            'Every change has Undo in the green line after it and under <em>Recent changes</em>.',
        ),
        'help' => '<p>Volunteers pick their own questions on their page from <em>Nobody yet</em> (up to ' . kop_rt_volunteer_hold() . ' held at a time); a question given to you or to Claude stays off their list, and one they hand back returns to it.</p><p>Claude sessions add tasks as they find them (seeds/research-tasks.json, imported after each deploy) and answer the ones given to Claude the same way. Every link a volunteer gives is already in the review inbox.</p>',
        'notices' => $notice,
    ));
    $holds = function_exists('kop_rinbox_held_counts') ? (int) (kop_rinbox_held_counts()['research'] ?? 0) : 0;
    echo kop_ak_status('<strong>' . (int) $counts['open'] . '</strong> open &middot; <strong>' . (int) $counts['answered'] . '</strong> answered, waiting for you &middot; <strong>'
        . (int) $counts['unassigned'] . '</strong> not given to anyone'
        . ($holds ? ' &middot; <a href="' . esc_url(home_url('/admin-submissions/?type=_research')) . '">' . $holds . ' review inbox item' . ($holds === 1 ? '' : 's') . ' set aside as Needs research</a>' : ''));

    $tabs = array('open' => array('label' => 'Open', 'count' => $counts['open']), 'unassigned' => array('label' => 'Nobody yet', 'count' => $counts['unassigned']),
        'owner' => array('label' => 'Mine', 'count' => $counts['owner']), 'claude' => array('label' => 'Claude', 'count' => $counts['claude']),
        'volunteer' => array('label' => 'Volunteers', 'count' => $counts['volunteer']), 'answered' => array('label' => 'Answered', 'count' => $counts['answered']),
        'done' => array('label' => 'Settled or dropped', 'count' => $counts['done']));
    echo kop_ak_tabs($tabs, isset($tabs[$view]) ? $view : 'open', function ($k) use ($section, $q) { return kop_rt_url(array('view' => $k, 'section' => $section, 'q' => $q)); });
    $secs = kop_rt_sections();
    echo kop_ak_filters(array('page' => 'kop-research-tasks', 'view' => $view), array(
        array('type' => 'select', 'name' => 'section', 'value' => $section, 'label' => 'Section', 'all' => 'Every section', 'options' => array_combine($secs, $secs) ?: array()),
        array('type' => 'search', 'name' => 'q', 'value' => $q, 'placeholder' => 'Find a task, a name or a record id', 'label' => 'Find'),
    ), kop_rt_url(array('view' => $view)));

    // Add a task (its own form, so the list's form below stays one).
    $add = '<form method="post" class="kop-rt-add">' . wp_nonce_field('kop_rt', '_wpnonce', true, false)
        . '<label for="rt-new-title">The question, in plain words, ending with ?</label><input type="text" id="rt-new-title" name="rt_new_title" required placeholder="When did Rocky Mountain Academy close?">'
        . '<label for="rt-new-body">What each source says, and where the answer goes</label><textarea id="rt-new-body" name="rt_new_body" rows="4"></textarea>'
        . '<label for="rt-new-look">Where to look: what we already have about it, one per line, "What it is | https://..."</label><textarea id="rt-new-look" name="rt_new_look" rows="3"></textarea>'
        . '<label for="rt-new-section">Section</label><input type="text" id="rt-new-section" name="rt_new_section" list="rt-sections" value="' . esc_attr($section) . '">'
        . '<datalist id="rt-sections">' . implode('', array_map(function ($s) { return '<option value="' . esc_attr($s) . '">'; }, $secs)) . '</datalist>'
        . '<label>Record it is about (optional)</label>' . kop_facility_finder_field('rt_new_record')
        . '<div class="kop-rt-actions">' . kop_rt_submit('Add the task', 'add:0', 'primary') . '</div></form>';
    echo kop_ak_fold('Add a task', $add, false, 'kop-rt-addfold');

    echo '<form method="post" id="kop-rt-form">' . wp_nonce_field('kop_rt', '_wpnonce', true, false);
    if (in_array($view, array('open', 'unassigned', 'owner', 'claude', 'volunteer', 'answered', 'all'), true) && $rows) {
        echo '<div class="kop-ak-bar kop-rt-bar"><span>Ticked:</span>' . kop_rt_submit('Give to me', 'bulk-owner:0') . kop_rt_submit('Give to Claude', 'bulk-claude:0')
            . kop_rt_submit('Unassign', 'bulk-none:0', 'quiet')
            . '<span class="kop-rt-volpick"><label for="rt-vol" class="screen-reader-text">Volunteer</label><select id="rt-vol" name="rt_vol"><option value="0">Pick a volunteer</option>' . $vol_options . '</select>'
            . '<label for="rt-vol-new" class="screen-reader-text">Or a new volunteer&#39;s name</label><input type="text" id="rt-vol-new" name="rt_vol_new" placeholder="or a new name">'
            . '<label for="rt-vol-note" class="screen-reader-text">Note to them</label><input type="text" id="rt-vol-note" name="rt_vol_note" placeholder="Note to them (optional)">'
            . kop_rt_submit('Send to a volunteer', 'bulk-volunteer:0', 'primary') . '</span></div>';
    }
    if ($view === 'claude' && $rows) {
        echo kop_ak_fold('For Claude: what a session reads', '<p>A session lists these with <code>php api/research-tasks.php --claude</code> on the server, works them, and sends each answer back with '
            . '<code>python scripts/research-tasks/tasks.py answer &lt;key&gt; --claim "fact | link | the sentence that says it"</code>, one claim per fact: every piece of information is cited. Or copy this into the chat:</p><textarea readonly rows="8" class="kop-rt-copy" onclick="this.select()">'
            . esc_textarea(wp_json_encode(kop_rt_export($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</textarea>', false);
    }
    if (!$rows) {
        echo kop_ak_empty($q !== '' || $section !== '' ? 'Nothing here matches.' : 'Nothing in this view.');
    }
    $last = null;
    foreach ($rows as $t) {
        if ($t['section'] !== $last) {
            if ($last !== null) echo '</section>';
            echo '<section class="kop-rt-group"><h2>' . esc_html($t['section'] !== '' ? $t['section'] : 'No section') . '</h2>';
            $last = $t['section'];
        }
        echo kop_rt_card_html($t, $names, $vols, $companies);
    }
    if ($last !== null) echo '</section>';

    $log = kop_rt_recent_log(12);
    if ($log) {
        $list = '';
        foreach ($log as $l) {
            $list .= '<li>' . esc_html($l['label']) . ' <span class="description">' . esc_html(($l['user_login'] ?: 'seed') . ', ' . $l['created_at'] . ' UTC') . '</span> '
                . ($l['undone_at'] ? '<em>undone</em>' : kop_rt_submit('Undo', 'undo:' . (int) $l['id'], 'quiet')) . '</li>';
        }
        echo kop_ak_fold('Recent changes', '<ul class="kop-rt-log">' . $list . '</ul>', false);
    }
    echo '</form></div>';
}
