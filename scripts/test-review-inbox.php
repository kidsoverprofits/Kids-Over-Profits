<?php
/**
 * The review inbox (inc/review-inbox.php and every source in inc/review-inbox/):
 * each source lists its waiting items in the shape js/review-inbox.js draws,
 * opens one by key, saves edited fields, keeps tags, and runs its actions
 * (with Undo where the queue has one). Runs against a temporary copy of the
 * tables in tmp/prod.sqlite; the mirror itself is never written.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php [--source=closure] [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'source::'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$only = $args['source'] ?? '';
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

// The tables the sources read and write, copied into a scratch file.
$db_path = sys_get_temp_dir() . '/kop-review-inbox-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
$wanted = array(
    'facilities_v2', 'news_submissions', 'lawsuits', 'legislation', 'wiki_submissions', 'suggested_edits',
    'facility_closure_reports', 'news_closure_scans', 'lawsuit_facility_links', 'lawsuit_news_links', 'news_facility_candidates', 'news_facility_links', 'inspection_highlights',
    'inspection_facilities', 'indigenous_schools', 'indigenous_school_news', 'bug_reports', 'young_adult_programs',
    'wpdl_options', 'wpdl_kop_operators', 'wpdl_kop_operator_facilities', 'wpdl_kop_operator_links', 'wpdl_kop_glossary_feedback',
    'wpdl_kop_woodbury_facts', 'wpdl_kop_woodbury_mentions', 'wpdl_kop_gdoc_links', 'wpdl_kop_fornits_items',
    'wpdl_kop_fornits_topics', 'wpdl_posts', 'wpdl_postmeta', 'wpdl_kop_review_tags', 'wpdl_fbv', 'wpdl_fbv_attachment_folder',
    'referrers_master', 'wpdl_referrers_master', 'providers_master', 'wpdl_providers_master', 'transporters_master', 'wpdl_transporters_master',
    // Program homes: without them the scratch copy makes its own empty tables (inspection-links' program cards).
    'wpdl_kop_program_homes', 'wpdl_kop_program_groups',
);
foreach ($wanted as $t) {
    $sql = $copy->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$copy->exec('DETACH DATABASE src');
$copy = null;
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });

// Writes go to the scratch copy only.
$GLOBALS['kop_test_wpdb_writes'] = true;
// The scratch copy is the database: api/sync-wiki-facilities.php (loaded through inc/review-inbox-menu.php ->
// api/lib-publish-approved.php) would otherwise open api/config.php's MySQL connection.
if (!defined('KOP_DB_CONFIG_LOADED')) define('KOP_DB_CONFIG_LOADED', true);
require __DIR__ . '/kop-test-harness.php';
// The harness's get_option() does not read the copied options table: the live merge map, from it, so
// queues that follow merges (kop_facility_merge_resolve()) see the records merged since their build files.
$raw = $pdo->query("SELECT option_value FROM wpdl_options WHERE option_name = 'kop_facility_merged_into'")->fetchColumn();
if ($raw !== false) $GLOBALS['kop_test_options']['kop_facility_merged_into'] = unserialize($raw);
unset($raw);
// MySQL functions the queues use.
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
if (!function_exists('kop_seed_pdo')) {
    function kop_seed_pdo() { return $GLOBALS['pdo']; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new class { public $user_login = 'inbox-test'; public $display_name = 'Inbox Test'; public function exists() { return true; } }; }
}
if (!function_exists('do_action')) {
    function do_action() {}
}
if (!function_exists('kop_facility_finder_label')) {
    require_once dirname(__DIR__) . '/inc/facility-finder.php';
}
// One file per source in scripts/review-inbox-tests/: it loads its queue's
// code, marks the queue's tables as already created (their MySQL CREATE
// statements would not run on SQLite) and defines kop_rinbox_test_<source>()
// with that source's edit and action checks.
foreach (glob(__DIR__ . '/review-inbox-tests/*.php') ?: array() as $f) {
    require_once $f;
}
require_once dirname(__DIR__) . '/inc/review-inbox.php';
require_once dirname(__DIR__) . '/inc/review-inbox-menu.php';
$GLOBALS['kop_test_options']['kop_review_inbox_db'] = KOP_REVIEW_INBOX_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_tags (source TEXT NOT NULL, item_key TEXT NOT NULL, tag TEXT NOT NULL,
    created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, PRIMARY KEY (source, item_key, tag))");
// Recently done and set-aside items (inc/review-inbox-log.php).
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_log (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_key TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, action_label TEXT NOT NULL DEFAULT '', style TEXT NOT NULL DEFAULT '', message TEXT,
    undo_action TEXT NOT NULL DEFAULT '', undo_params TEXT, user_login TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, undone_at TEXT, undone_by TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_holds (source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    assigned_to INTEGER NOT NULL DEFAULT 0, snooze_until TEXT, research INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");

/*
 * A made-up queue for the log / Undo / set-aside checks: five items; approve
 * sends one to 'done' (where it offers Undo), undo sends it back, reject drops it.
 */
$GLOBALS['kop_rinbox_zz'] = array('a' => 'waiting', 'b' => 'waiting', 'c' => 'waiting', 'd' => 'waiting', 'e' => 'waiting');
$kop_rinbox_zz_item = function ($k) {
    $st = $GLOBALS['kop_rinbox_zz'][$k] ?? null;
    if ($st === null) return null;
    return array('key' => $k, 'title' => 'Item ' . strtoupper($k), 'status' => $st, 'actions' => $st === 'waiting'
        ? array(array('id' => 'approve', 'label' => 'Approve: do it', 'style' => 'approve', 'help' => 'Does it.'), array('id' => 'reject', 'label' => 'Reject', 'style' => 'reject'))
        : array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo')));
};
if ($only === '' || $only === 'log') kop_rinbox_register('zz-test', function () use ($kop_rinbox_zz_item) {
    return array(
        'label' => 'Test queue', 'views' => array('waiting' => 'Waiting', 'done' => 'Done'),
        'count' => function () { return count(array_filter($GLOBALS['kop_rinbox_zz'], function ($s) { return $s === 'waiting'; })); },
        'list' => function (array $q) use ($kop_rinbox_zz_item) {
            $keys = array_keys(array_filter($GLOBALS['kop_rinbox_zz'], function ($s) use ($q) { return $s === $q['view']; }));
            return array('items' => array_map($kop_rinbox_zz_item, array_slice($keys, $q['offset'], $q['limit'])), 'total' => count($keys));
        },
        'get' => $kop_rinbox_zz_item,
        'act' => function ($k, $action) {
            if ($action === 'approve') $GLOBALS['kop_rinbox_zz'][$k] = 'done';
            elseif ($action === 'undo') $GLOBALS['kop_rinbox_zz'][$k] = 'waiting';
            elseif ($action === 'reject') unset($GLOBALS['kop_rinbox_zz'][$k]);
            else throw new RuntimeException('unknown');
            return array('message' => ucfirst($action) . ' ' . $k . '.');
        },
    );
});
// WordPress user functions the set-aside code reads.
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('get_userdata')) {
    function get_userdata($id) {
        $names = array(1 => 'Inbox Test', 2 => 'Pat');
        return isset($names[$id]) ? (object) array('ID' => $id, 'display_name' => $names[$id], 'user_login' => strtolower($names[$id]), 'user_email' => '') : false;
    }
}
if (!function_exists('get_users')) { function get_users() { return array(get_userdata(1), get_userdata(2)); } }
if (!function_exists('user_can')) { function user_can($id) { return (bool) get_userdata($id); } }
if (!function_exists('wp_mail')) { function wp_mail() { return true; } }
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $p = array();
        public function set_param($k, $v) { $this->p[$k] = $v; }
        public function get_param($k) { return $this->p[$k] ?? null; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $d;
        public function __construct($d = null, $status = 200) { $this->d = $d; }
        public function get_data() { return $this->d; }
    }
}
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return $v; } }
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); } }
if (!function_exists('wp_date')) { function wp_date($f, $t = null) { return gmdate($f, $t ?? time()); } }

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

/** Every item must carry what the browser draws. */
$shape_ok = function (array $it) {
    foreach (array('key', 'title', 'fields', 'actions', 'tags') as $k) if (!array_key_exists($k, $it)) return "missing $k";
    if (!is_string($it['key']) || $it['key'] === '') return 'empty key';
    foreach ($it['fields'] as $f) {
        if (empty($f['name']) || empty($f['label'])) return 'field without name/label';
        if (($f['type'] ?? 'text') === 'select' && empty($f['options'])) return 'select without options: ' . $f['name'];
    }
    foreach ($it['actions'] as $a) if (empty($a['id']) || empty($a['label'])) return 'action without id/label';
    return '';
};

// Young adult programs are records in related_facilities: the table, and the copied rows moved in as on the site.
if (function_exists('kop_ya_move_to_related')) kop_test_related_table($pdo, true);

$sources = kop_rinbox_sources(true);
$check('sources are registered', count($sources) > 0, implode(', ', array_keys($sources)));

foreach ($sources as $key => $src) {
    if ($only !== '' && $only !== $key) continue;
    echo "-- $key ({$src['label']}) --\n";
    try {
        $n = call_user_func($src['count']);
        $check("$key: count", is_int($n) || ctype_digit((string) $n), (string) $n);
        foreach (array_keys($src['views']) as $view) {
            $res = call_user_func($src['list'], array('view' => $view, 'search' => '', 'offset' => 0, 'limit' => 5));
            $items = kop_rinbox_finish_items($key, $res['items']);
            $bad = '';
            foreach ($items as $it) if (($bad = $shape_ok($it)) !== '') break;
            $check("$key/$view: " . count($items) . ' of ' . $res['total'] . ' items have the full shape', $bad === '', $bad);
        }
        $first = call_user_func($src['list'], array('view' => array_keys($src['views'])[0], 'search' => '', 'offset' => 0, 'limit' => 1))['items'][0] ?? null;
        if (!$first) {
            echo "  (nothing waiting in the mirror)\n";
            // A queue whose checks make their own items (Write-ups: approving facts fills it) runs them anyway.
            $hook = 'kop_rinbox_test_' . str_replace('-', '_', $key) . '_from_empty';
            if (function_exists($hook)) $hook($src, $check);
            continue;
        }
        $again = kop_rinbox_get_item($key, $first['key']);
        $check("$key: an item opens by its key", $again['key'] === (string) $first['key'] && $again['title'] === $first['title']);

        $tags = kop_rinbox_set_tags($key, $first['key'], array('Needs Source', ' needs source ', 'follow up'));
        // The shared table keeps tags lower case and sorted; a queue with its own tag column keeps their case.
        $lower = array_map('mb_strtolower', $tags);
        sort($lower);
        $check("$key: tags are cleaned and kept", $lower === array('follow up', 'needs source') && (!empty($src['tags_set']) || $tags === $lower), implode('|', $tags));
        $read = kop_rinbox_tags_for($key, array($first['key']));
        $check("$key: tags read back", ($read[$first['key']] ?? array()) == $tags, json_encode($read));

        $hook = 'kop_rinbox_test_' . str_replace('-', '_', $key);
        if (function_exists($hook)) $hook($src, $first, $check);
    } catch (Throwable $e) {
        $check("$key: no errors", false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'log') {
    echo "-- Recently done, Undo, set aside --\n";
    try {
        global $wpdb;
        $act = function ($key, $action) {
            $req = new WP_REST_Request();
            foreach (array('source' => 'zz-test', 'key' => $key, 'action' => $action, 'params' => array()) as $k => $v) $req->set_param($k, $v);
            return kop_rinbox_rest_act($req)->get_data();
        };
        $act('a', 'approve');
        $row = $wpdb->get_row("SELECT * FROM wpdl_kop_review_log ORDER BY id DESC LIMIT 1", ARRAY_A);
        $check('log: approving records title, label, style and the Undo to run', $row && $row['title'] === 'Item A' && $row['action_label'] === 'Approve: do it'
            && $row['style'] === 'approve' && $row['undo_action'] === 'undo', json_encode($row));
        $list = kop_rinbox_log_list(array('offset' => 0, 'limit' => 10));
        $check('log: Recently done lists it with Undo', $list['total'] >= 1 && $list['rows'][0]['can_undo'] && $list['rows'][0]['source_label'] === 'Test queue');
        $u = kop_rinbox_log_undo((int) $row['id']);
        $check("log: Undo runs the queue's own undo", $GLOBALS['kop_rinbox_zz']['a'] === 'waiting', $u['message']);
        $twice = '';
        try { kop_rinbox_log_undo((int) $row['id']); } catch (Throwable $e) { $twice = $e->getMessage(); }
        $check('log: a second Undo is refused', $twice !== '', $twice);
        $act('b', 'reject');
        $row = $wpdb->get_row("SELECT * FROM wpdl_kop_review_log ORDER BY id DESC LIMIT 1", ARRAY_A);
        $check('log: an item that left the queue has no Undo', $row['style'] === 'reject' && $row['undo_action'] === '', json_encode($row));

        // Set aside: c handed to someone else, d snoozed; neither is in my waiting list, and the pages skip them.
        kop_rinbox_hold_set('zz-test', 'c', 'Item C', 0, 2, 'check it');
        kop_rinbox_hold_set('zz-test', 'd', 'Item D', 7, 0, '');
        kop_rinbox_holds_active(null, true);
        $src = kop_rinbox_source('zz-test');
        $hidden = kop_rinbox_hidden_keys('zz-test');
        $check('holds: assigned to someone else and snoozed leave my list', isset($hidden['c'], $hidden['d']) && count($hidden) === 2, json_encode(array_keys($hidden)));
        $q = array('view' => 'waiting', 'search' => '', 'offset' => 0, 'limit' => 1, 'filters' => array());
        $p1 = kop_rinbox_list_unheld($src, 'zz-test', $q);
        $p2 = kop_rinbox_list_unheld($src, 'zz-test', array('offset' => $p1['next_offset']) + $q);
        $check('holds: pages skip set-aside items', array_column($p1['items'], 'key') === array('a') && array_column($p2['items'], 'key') === array('e')
            && $p1['total'] === 2, json_encode(array(array_column($p1['items'], 'key'), array_column($p2['items'], 'key'), $p1['total'])));
        $info = kop_rinbox_hold_info('zz-test', 'c');
        $check('holds: the card says who has it and the note', $info && $info['assigned_name'] === 'Pat' && $info['note'] === 'check it' && $info['hidden'], json_encode($info));
        $snoozed = kop_rinbox_held_items('snoozed');
        $check('holds: Snoozed lists it with its queue', array_column($snoozed['items'], 'key') === array('d') && $snoozed['items'][0]['source_label'] === 'Test queue');
        $act('d', 'approve');
        kop_rinbox_holds_active(null, true);
        $check('holds: approving clears the hold', kop_rinbox_hold_info('zz-test', 'd') === null);
        kop_rinbox_hold_set('zz-test', 'c', 'Item C', 0, 0, '');
        kop_rinbox_holds_active(null, true);
        $check('holds: Put back in the list removes the hold', kop_rinbox_hold_info('zz-test', 'c') === null);

        // Needs research: off every waiting list, listed across queues, kept through a snooze, toggled back.
        kop_rinbox_research_set('zz-test', 'e', 'Item E', true);
        $info = kop_rinbox_hold_info('zz-test', 'e');
        $check('research: the card says so and it leaves the list', $info && $info['research'] && $info['hidden'] && isset(kop_rinbox_hidden_keys('zz-test')['e']), json_encode($info));
        $res = kop_rinbox_held_items('research');
        $check('research: Needs research lists it with its queue', array_column($res['items'], 'key') === array('e') && kop_rinbox_held_counts()['research'] === 1, json_encode(array_column($res['items'], 'key')));
        kop_rinbox_hold_set('zz-test', 'e', 'Item E', 7, 0, '');
        kop_rinbox_holds_active(null, true);
        $check('research: a snooze keeps it waiting for research', !empty(kop_rinbox_hold_info('zz-test', 'e')['research']));
        kop_rinbox_research_set('zz-test', 'e', 'Item E', false);
        $info = kop_rinbox_hold_info('zz-test', 'e');
        $check('research: Back to the queue keeps only the snooze', $info && !$info['research'] && $info['snooze_until'] !== '', json_encode($info));
        kop_rinbox_hold_set('zz-test', 'e', 'Item E', 0, 0, '');
        kop_rinbox_research_set('zz-test', 'e', 'Item E', true);
        kop_rinbox_research_set('zz-test', 'e', 'Item E', false);
        $check('research: off with nothing else holding it removes the hold', kop_rinbox_hold_info('zz-test', 'e') === null);
        kop_rinbox_research_set('zz-test', 'e', 'Item E', true);
        $act('e', 'approve');
        kop_rinbox_holds_active(null, true);
        $check('research: approving clears it', kop_rinbox_hold_info('zz-test', 'e') === null);
    } catch (Throwable $e) {
        $check('log: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'homes') {
    echo "-- Home of a program (every card about one facility) --\n";
    try {
        kop_rinbox_test_homes_action($check);
    } catch (Throwable $e) {
        $check('homes: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'ya-file') {
    echo "-- File under a young adult program (every content queue's card) --
";
    try {
        kop_rinbox_test_ya_file_action($check);
    } catch (Throwable $e) {
        $check('ya-file: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'addons') {
    echo "-- Card add-ons and the find-or-make record field --\n";
    try {
        kop_rinbox_test_card_addons($check);
    } catch (Throwable $e) {
        $check('addons: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'card-fields') {
    echo "-- Card Fields and filing on any record --
";
    try {
        kop_rinbox_test_card_fields($check);
    } catch (Throwable $e) {
        $check('card-fields: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'counts') {
    echo "-- Waiting counts: saved, drawn at once, recounted only when stale --
";
    try {
        $GLOBALS['kop_rinbox_count_calls'] = 0;
        kop_rinbox_register('zz-count', function () {
            return array('label' => 'Count test', 'views' => array('waiting' => 'Waiting'),
                'count' => function () { $GLOBALS['kop_rinbox_count_calls']++; return 7; },
                'list' => function () { return array('items' => array(), 'total' => 0); });
        });
        kop_rinbox_sources(true);
        unset($GLOBALS['kop_test_options']['kop_review_inbox_counts']);
        $calls = $GLOBALS['kop_rinbox_count_calls'];
        $counts = kop_rinbox_counts();
        $check('counts: reading them never counts', $GLOBALS['kop_rinbox_count_calls'] === $calls && !isset($counts['zz-count']));
        $check('counts: a queue never counted is stale', in_array('zz-count', kop_rinbox_counts_stale(), true));
        $got = kop_rinbox_counts_refresh(array('zz-count', 'no-such-queue'));
        $check('counts: a recount counts only the named queues, and saves them', $got === array('zz-count' => 7) && (kop_rinbox_counts()['zz-count'] ?? null) === 7, json_encode($got));
        $check('counts: a fresh count is not stale', !in_array('zz-count', kop_rinbox_counts_stale(), true));
        kop_rinbox_flush_counts();
        $check('counts: after an action every count is stale but still shown', in_array('zz-count', kop_rinbox_counts_stale(), true) && (kop_rinbox_counts()['zz-count'] ?? null) === 7);
        $res = kop_rinbox_rest_sources(new WP_REST_Request());
        $data = $res->get_data();
        $row = array_values(array_filter($data['sources'] ?? array(), function ($r) { return $r['key'] === 'zz-count'; }))[0] ?? array();
        $check('counts: the sources answer carries the saved count and the stale list, without counting',
            ($row['count'] ?? null) === 7 && in_array('zz-count', (array) ($data['stale'] ?? array()), true) && $GLOBALS['kop_rinbox_count_calls'] === $calls + 1);
    } catch (Throwable $e) {
        $check('counts: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'recent') {
    echo "-- Tabs and \"Just in\": areas, what came in lately, people vs the daily news scans --\n";
    try {
        $check('recent: the news scans have a tab of their own', kop_rinbox_area(array('group' => 'Found by the news scans')) === 'scans'
            && kop_rinbox_area(array('group' => 'Imports to review')) === 'found' && kop_rinbox_area(array('group' => 'Sent in by readers')) === 'people'
            && kop_rinbox_area(array('group' => 'Suggestions to check')) === 'checks' && kop_rinbox_area(array('group' => 'Submissions', 'area' => 'checks')) === 'checks');
        // A queue listed oldest first, with one undated item: the cutoff still finds every recent one.
        $now = time();
        $GLOBALS['kop_rinbox_rc'] = array(
            'old' => gmdate('Y-m-d H:i:s', $now - 9 * DAY_IN_SECONDS), 'new1' => gmdate('Y-m-d H:i:s', $now - 3600),
            'nodate' => '', 'new2' => gmdate('Y-m-d\TH:i:s', $now - DAY_IN_SECONDS), 'edge' => gmdate('Y-m-d H:i:s', $now - 3 * DAY_IN_SECONDS),
        );
        foreach (array('zz-rc-people' => 'Sent in by readers', 'zz-rc-scan' => 'Found by the news scans') as $k => $group) {
            kop_rinbox_register($k, function () use ($group) {
                return array('label' => 'Recent ' . $group, 'group' => $group, 'views' => array('waiting' => 'Waiting'),
                    'count' => function () { return 5; },
                    'list' => function (array $q) {
                        $items = array();
                        foreach ($GLOBALS['kop_rinbox_rc'] as $key => $c) $items[] = array('key' => $key, 'title' => $key, 'created' => $c);
                        return array('items' => array_slice($items, $q['offset'], $q['limit']), 'total' => count($items));
                    });
            });
        }
        kop_rinbox_sources(true);
        $got = kop_rinbox_recent_items('zz-rc-people', $now - 2 * DAY_IN_SECONDS);
        $check('recent: only items from the window, newest first, undated ones left out', array_column($got, 'key') === array('new1', 'new2'), json_encode(array_column($got, 'key')));
        $check('recent: a readers\' queue counts as sent by people, with its queue named', $got && $got[0]['people'] && !$got[0]['scan'] && $got[0]['source_label'] === 'Recent Sent in by readers');
        $req = new WP_REST_Request();
        $req->set_param('sources', 'zz-rc-people,zz-rc-scan,no-such-queue');
        $req->set_param('days', '2');
        $data = kop_rinbox_rest_recent($req)->get_data();
        $r = $data['recent'] ?? array();
        $check('recent: REST gives people\'s cards, counts the scans\' finds, and names a queue it could not read',
            count($r['zz-rc-people']['people'] ?? array()) === 2 && ($r['zz-rc-scan']['scans'] ?? null) === 2 && empty($r['zz-rc-scan']['people'])
            && !empty($r['no-such-queue']['error']), json_encode($r));

        // News: what a person sent is a card, what the Google News scan found is counted with the scans.
        $pdo = kop_rinbox_pdo();
        $ins = $pdo->prepare("INSERT INTO news_submissions (article_title, article_url, status, submitted_by, submission_notes, created_at) VALUES (?, ?, 'submitted', ?, ?, ?)");
        $ins->execute(array('ZZ sent by a reader', 'https://example.org/zz-reader-' . $now, 'A reader', '', gmdate('Y-m-d H:i:s', $now - 600)));
        $reader = (string) $pdo->lastInsertId();
        $ins->execute(array('ZZ found by the scan', 'https://example.org/zz-scan-' . $now, 'scraper', 'auto-discovery via google-news: test', gmdate('Y-m-d H:i:s', $now - 600)));
        $scan = (string) $pdo->lastInsertId();
        $ins->execute(array('ZZ old reader link', 'https://example.org/zz-old-' . $now, 'A reader', '', gmdate('Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS)));
        $old = (string) $pdo->lastInsertId();
        kop_rinbox_test_news_origins($check);
        $before = kop_rinbox_native_split_counts();
        $ins->execute(array('ZZ from an import', 'https://example.org/zz-import-' . $now, 'admin (SCIAD NET import)', 'Found in SCIAD NET', gmdate('Y-m-d H:i:s', $now - 600)));
        $import = (string) $pdo->lastInsertId();
        delete_transient('kop_rinbox_native_split');
        $after = kop_rinbox_native_split_counts();
        $check('recent: an imported article counts under imports, not people or scans', ($after['news']['imports'] ?? 0) === ($before['news']['imports'] ?? 0) + 1
            && ($after['news']['people'] ?? 0) === ($before['news']['people'] ?? 0), json_encode(array($before['news'] ?? null, $after['news'] ?? null)));
        $pdo->prepare('DELETE FROM news_submissions WHERE id = ?')->execute(array($import));
        $news = kop_rinbox_recent_items('news', $now - 2 * DAY_IN_SECONDS);
        $by = array();
        foreach ($news as $it) $by[$it['key']] = $it;
        $check('recent: News sent by a person is a card, the scan\'s find is a scan, the old one is left out',
            !empty($by[$reader]['people']) && isset($by[$scan]) && empty($by[$scan]['people']) && !empty($by[$scan]['scan']) && !isset($by[$old]),
            json_encode(array_map(function ($it) { return array($it['key'], $it['people'], $it['scan']); }, $news)));
        delete_transient('kop_rinbox_native_split');
        $split = kop_rinbox_native_split_counts();
        $check('recent: the News tab counts split people from the daily news scans', ($split['news']['people'] ?? 0) >= 1 && ($split['news']['scans'] ?? 0) >= 1
            && array_key_exists('people', $split['data'] ?? array()), json_encode($split));
        $pdo->prepare('DELETE FROM news_submissions WHERE id IN (?, ?, ?)')->execute(array($reader, $scan, $old));

        // Every real queue answers "Just in" (a week back on the mirror), and says how long it took.
        $slow = array();
        foreach (array_keys(kop_rinbox_sources()) as $k) {
            if (strpos($k, 'zz-') === 0) continue;
            $t0 = microtime(true);
            try {
                $req = new WP_REST_Request();
                $req->set_param('sources', $k);
                $req->set_param('days', '7');
                $r = kop_rinbox_rest_recent($req)->get_data()['recent'][$k] ?? array();
                $check("recent: $k answers", empty($r['error']), $r['error'] ?? '');
                $ms = (int) ((microtime(true) - $t0) * 1000);
                if ($ms > 3000) $slow[] = "$k {$ms}ms";
                echo "  $k: " . count($r['people'] ?? array()) . ' people, ' . (int) ($r['scans'] ?? 0) . ' scans, ' . (int) ($r['others'] ?? 0) . " other in the last 7 days ({$ms} ms)\n";
            } catch (Throwable $e) {
                $check("recent: $k answers", false, $e->getMessage());
            }
        }
        if ($slow) echo '  slow: ' . implode(', ', $slow) . "\n";
    } catch (Throwable $e) {
        $check('recent: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

if ($only === '' || $only === 'ya-moved') {
    echo "-- Items of records moved to the young adult programs --\n";
    try {
        kop_rinbox_test_ya_moved($check);
        kop_rinbox_test_merge_follow($check);
    } catch (Throwable $e) {
        $check('ya-moved: no errors', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
