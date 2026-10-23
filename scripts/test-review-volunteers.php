<?php
/**
 * Volunteer reviewers (inc/review-volunteers.php): invite links, what a
 * volunteer sees of each queue (no contact details, no edit fields or
 * actions), recommending, the admin side (recs on cards, "Volunteers
 * recommend", agreement after an approve / reject, reopened by Undo).
 * Runs against a temporary copy of the tables in tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-volunteers.php [--db=tmp/prod.sqlite] [--list]
 *
 * --list prints the first item of each open queue as a volunteer gets it.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

$db_path = sys_get_temp_dir() . '/kop-review-volunteers-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
// The same tables as scripts/test-review-inbox.php: every source's test file loads with them.
$wanted = array(
    'facilities_v2', 'news_submissions', 'lawsuits', 'legislation', 'wiki_submissions', 'suggested_edits',
    'facility_closure_reports', 'news_closure_scans', 'lawsuit_facility_links', 'lawsuit_news_links', 'news_facility_candidates', 'news_facility_links', 'inspection_highlights',
    'inspection_facilities', 'indigenous_schools', 'indigenous_school_news', 'bug_reports', 'young_adult_programs',
    'wpdl_options', 'wpdl_kop_operators', 'wpdl_kop_operator_facilities', 'wpdl_kop_glossary_feedback',
    'wpdl_kop_woodbury_facts', 'wpdl_kop_woodbury_mentions', 'wpdl_kop_gdoc_links', 'wpdl_kop_fornits_items',
    'wpdl_kop_fornits_topics', 'wpdl_posts', 'wpdl_postmeta', 'wpdl_kop_review_tags', 'wpdl_fbv', 'wpdl_fbv_attachment_folder',
);
foreach ($wanted as $t) {
    $sql = $copy->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
// Only the reports the findings were read from (the whole table is large).
$sql = $copy->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = 'inspection_reports'")->fetchColumn();
if ($sql) {
    $copy->exec($sql);
    $copy->exec('INSERT INTO main.inspection_reports SELECT * FROM src.inspection_reports WHERE id IN (SELECT report_id FROM src.inspection_highlights)');
}
$copy->exec('DETACH DATABASE src');
$copy = null;
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new class { public $user_login = 'vol-test'; public $display_name = 'Vol Test'; public function exists() { return true; } }; }
}
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('wp_salt')) { function wp_salt() { return 'test-salt'; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('kop_facility_finder_label')) require_once dirname(__DIR__) . '/inc/facility-finder.php';
foreach (glob(__DIR__ . '/review-inbox-tests/*.php') ?: array() as $f) require_once $f;
require_once dirname(__DIR__) . '/inc/review-inbox.php';
$GLOBALS['kop_test_options']['kop_review_inbox_db'] = KOP_REVIEW_INBOX_DB_VERSION;
$GLOBALS['kop_test_options']['kop_volunteers_db'] = KOP_VOL_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_tags (source TEXT NOT NULL, item_key TEXT NOT NULL, tag TEXT NOT NULL,
    created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, PRIMARY KEY (source, item_key, tag))");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_log (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_key TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, action_label TEXT NOT NULL DEFAULT '', style TEXT NOT NULL DEFAULT '', message TEXT,
    undo_action TEXT NOT NULL DEFAULT '', undo_params TEXT, user_login TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, undone_at TEXT, undone_by TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_holds (source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    assigned_to INTEGER NOT NULL DEFAULT 0, snooze_until TEXT, research INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");
$pdo->exec("CREATE TABLE wpdl_kop_volunteers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
    token_hash TEXT NOT NULL UNIQUE, created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, link_at TEXT NOT NULL, last_seen TEXT, revoked_at TEXT)");
$pdo->exec("CREATE TABLE wpdl_kop_volunteer_recs (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, source TEXT NOT NULL,
    item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', verdict TEXT NOT NULL, note TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    outcome TEXT NOT NULL DEFAULT '', outcome_by TEXT NOT NULL DEFAULT '', outcome_at TEXT, UNIQUE (volunteer_id, source, item_key))");
$GLOBALS['kop_test_options']['kop_vol_practice_db'] = KOP_VOLPR_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_vol_practice (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, round_no INTEGER NOT NULL DEFAULT 1,
    source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', answer TEXT NOT NULL, verdict TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, answered_at TEXT, UNIQUE (volunteer_id, source, item_key))");
// A passed practice round (one right card is a pass) in each queue: what opens a queue to a volunteer (kop_vol_sources_for()).
$earn = function ($vid, array $sources) use ($pdo) {
    foreach ($sources as $s) {
        $no = 1 + (int) $pdo->query('SELECT COALESCE(MAX(round_no), 0) FROM wpdl_kop_vol_practice WHERE volunteer_id = ' . (int) $vid)->fetchColumn();
        $pdo->prepare("INSERT OR IGNORE INTO wpdl_kop_vol_practice (volunteer_id, round_no, source, item_key, answer, verdict, created_at) VALUES (?, ?, ?, ?, 'approve', 'approve', '2026-01-01')")
            ->execute(array((int) $vid, $no, $s, 'earned-' . $s));
    }
};

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
};
$throws = function (callable $fn) {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};

echo "Invite links\n";
list($id, $url) = kop_vol_create('Sam Example', 'from the Discord');
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
$token = (string) ($q['invite'] ?? '');
$check('link carries a 48-character token', strlen($token) === 48, $url);
$check('only the hash is stored', !$pdo->query("SELECT COUNT(*) FROM wpdl_kop_volunteers WHERE token_hash = " . $pdo->quote($token))->fetchColumn());
$check('the token finds the volunteer', (int) (kop_vol_by_token($token)['id'] ?? 0) === $id);
$check('a wrong token finds nobody', kop_vol_by_token(str_repeat('a', 48)) === null && kop_vol_by_token('x') === null);
$_COOKIE[KOP_VOL_COOKIE] = $token;
$vol = kop_vol_current();
$check('the cookie signs the browser in', $vol && (int) $vol['id'] === $id);
$check('the page header depends on the token', kop_vol_csrf($token) !== kop_vol_csrf(str_repeat('b', 48)) && strlen(kop_vol_csrf($token)) === 32);
kop_vol_revoke($id);
$check('turned off: the link stops working', kop_vol_by_token($token) === null);
$url2 = kop_vol_new_link($id);
parse_str((string) parse_url($url2, PHP_URL_QUERY), $q2);
$token2 = (string) $q2['invite'];
$check('a new link turns them back on', (int) (kop_vol_by_token($token2)['id'] ?? 0) === $id);
$check('the old link stays dead', kop_vol_by_token($token) === null);
$vol = kop_vol_by_token($token2);
list($id2) = kop_vol_create('Alex Example');
$vol2 = kop_vol_by_token(substr(kop_vol_new_link($id2), -48));
$check('second volunteer', $vol2 && (int) $vol2['id'] === $id2);

echo "\nWhat volunteers see\n";
$check('scrub removes emails and phone numbers',
    kop_vol_scrub('Write jane.doe@example.com or call (555) 123-4567, or 555.123.4567') === 'Write [email removed] or call [phone removed], or [phone removed]',
    kop_vol_scrub('Write jane.doe@example.com or call (555) 123-4567, or 555.123.4567'));
$check('scrub keeps years and case numbers', kop_vol_scrub('Filed 2021-04-05, case 2:21-cv-00123') === 'Filed 2021-04-05, case 2:21-cv-00123');
$open = array_keys(kop_vol_sources());
$check('default queues are open', in_array('news', $open, true) && in_array('lawsuit', $open, true), implode(', ', $open));
foreach (kop_vol_never_sources() as $never) {
    $GLOBALS['kop_test_options']['kop_volunteer_sources'] = array('news', $never);
    $check("$never can never be opened", !isset(kop_vol_sources()[$never]));
}
unset($GLOBALS['kop_test_options']['kop_volunteer_sources']);
$check('a closed queue is refused', $throws(function () { kop_vol_items(0, 'bug-reports', 0, 5); }) !== '');

echo "\nQueues are earned\n";
$check('a new volunteer has no queue of their own', kop_vol_sources_for($id) === array(), implode(', ', array_keys(kop_vol_sources_for($id))));
$check('...so a queue refuses them', strpos($throws(function () use ($id) { kop_vol_items($id, 'news', 0, 5); }), 'practice') !== false);
$check('the violations queue is the one to start in', array_keys(kop_vol_earnable_sources())[0] === 'inspection-highlights');
$earn($id, array('news'));
$check('a passed practice round opens that queue only', array_keys(kop_vol_sources_for($id)) === array('news'));
$pdo->prepare("INSERT INTO wpdl_kop_volunteer_recs (volunteer_id, source, item_key, verdict, created_at, updated_at) VALUES (?, 'lawsuit', 'earlier', 'approve', '2026-01-01', '2026-01-01')")->execute(array($id2));
$check('a queue they recommended in before stays open', array_keys(kop_vol_sources_for($id2)) === array('lawsuit'));
$pdo->exec("DELETE FROM wpdl_kop_volunteer_recs WHERE item_key = 'earlier'");
$check('an admin preview sees them all', isset(kop_vol_sources_for(0)['inspection-highlights']) && isset(kop_vol_sources_for(0)['news']));
$earn($id, array_keys(kop_vol_earnable_sources()));
$earn($id2, array_keys(kop_vol_earnable_sources()));

$forbidden = array('fields', 'actions', 'moves', 'tags', 'hold', 'recs', 'details');
$first = null;
foreach (kop_vol_sources() as $key => $src) {
    $err = $throws(function () use ($key, &$res, $vol) { $res = kop_vol_items((int) $vol['id'], $key, 0, 5); });
    if ($err !== '') { $check("$key lists", false, $err); continue; }
    $items = $res['items'];
    $bad = '';
    foreach ($items as $it) {
        foreach ($forbidden as $f) if (array_key_exists($f, $it)) $bad = "has $f";
        $blob = json_encode($it);
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $blob)) $bad = 'an email address got through';
        if (!$it['approve'] && !$it['reject']) $bad = 'no approve / reject explanation';
    }
    $check("$key: " . count($items) . ' item(s), copies only', $bad === '', $bad);
    if (isset($args['list']) && $items) echo json_encode($items[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "
";
    if (!$first && $items) $first = $items[0];
}

echo "\nRecommending\n";
if (!$first) {
    $check('some open queue has an item to recommend on', false);
} else {
    $s = $first['source'];
    $k = $first['key'];
    $check('not sure needs a note', $throws(function () use ($vol, $s, $k) { kop_vol_recommend($vol, $s, $k, 'unsure', ''); }) !== '');
    $check('only approve / reject / not sure', $throws(function () use ($vol, $s, $k) { kop_vol_recommend($vol, $s, $k, 'publish', ''); }) !== '');
    $check('a closed queue is refused', $throws(function () use ($vol) { kop_vol_recommend($vol, 'bug-reports', '1', 'approve', ''); }) !== '');
    $msg = $throws(function () use ($vol, $s, $k) { kop_vol_recommend($vol, $s, $k, 'reject', 'Off topic.'); });
    $check("recommend reject on $s:$k", $msg === '', $msg);
    $msg = $throws(function () use ($vol, $s, $k) { kop_vol_recommend($vol, $s, $k, 'approve', 'On second look, it is about a program.'); });
    $check('changing it keeps one row', $msg === '' && (int) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_volunteer_recs')->fetchColumn() === 1);
    kop_vol_recommend($vol2, $s, $k, 'approve', '');
    $after = kop_vol_items((int) $vol['id'], $s, 0, 50);
    $check('the item leaves that volunteer\'s list', !in_array($k, array_column($after['items'], 'key'), true));
    $recs = kop_vol_recs_for($s, array($k));
    $check('the admin card gets both recommendations', count($recs[$k] ?? array()) === 2 && $recs[$k][0]['name'] === 'Sam Example');
    $finished = kop_rinbox_finish_items($s, array(array('key' => $k, 'title' => 'x')));
    $check('finish_items adds recs', count($finished[0]['recs']) === 2);
    $check('open count', kop_vol_open_count() === 1);
    $admin = kop_vol_admin_items();
    $check('"Volunteers recommend" lists it', in_array($k, array_column($admin['items'], 'key'), true), count($admin['items']) . ' item(s)');

    // The admin rejects it through the log (what api/manage-submissions.php and the inbox both write).
    kop_rinbox_log_insert(array('source' => $s, 'item_key' => $k, 'title' => $first['title'], 'action' => 'reject', 'style' => 'reject'));
    $rows = $pdo->query('SELECT volunteer_id, outcome FROM wpdl_kop_volunteer_recs ORDER BY volunteer_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $check('a reject closes both', $rows[$id] === 'reject' && $rows[$id2] === 'reject');
    $check('closed recs leave the card', !kop_vol_recs_for($s, array($k)));
    $check('a decided one cannot be changed', $throws(function () use ($vol, $s, $k) { kop_vol_withdraw($vol, $s, $k); kop_vol_recommend($vol, $s, $k, 'reject', ''); }) !== '');
    $list = array();
    foreach (kop_vol_list() as $v) $list[(int) $v['id']] = $v;
    $check('agreement: Sam differed, Alex differed', (int) $list[$id]['decided'] === 1 && (int) $list[$id]['agreed'] === 0 && (int) $list[$id2]['agreed'] === 0);
    $mine = kop_vol_mine((int) $vol['id']);
    $check('"Your recommendations" shows the outcome', ($mine[0]['outcome'] ?? '') === 'reject' && $mine[0]['verdict'] === 'approve');
    kop_vol_reopen($s, $k);
    $check('Undo reopens them', count(kop_vol_recs_for($s, array($k))[$k] ?? array()) === 2);
    kop_vol_resolve($s, $k, 'approve', 'admin');
    $list = array();
    foreach (kop_vol_list() as $v) $list[(int) $v['id']] = $v;
    $check('agreement after approve: both agreed', (int) $list[$id]['agreed'] === 1 && (int) $list[$id2]['agreed'] === 1);
}

// A held item is never shown.
if ($first) {
    $pdo->exec("DELETE FROM wpdl_kop_volunteer_recs");
    $pdo->prepare("INSERT INTO wpdl_kop_review_holds (source, item_key, assigned_to, created_at) VALUES (?, ?, 1, ?)")->execute(array($first['source'], $first['key'], gmdate('Y-m-d H:i:s')));
    kop_rinbox_holds_active(null, true);
    $res = kop_vol_items((int) $vol2['id'], $first['source'], 0, 50);
    $check('an item handed to an admin is not shown', !in_array($first['key'], array_column($res['items'], 'key'), true));
    $check('and cannot be recommended', $throws(function () use ($vol2, $first) { kop_vol_recommend($vol2, $first['source'], $first['key'], 'approve', ''); }) !== '');
}

// Volunteers read a serious finding's whole scraped report (the admin viewer is closed to them).
$GLOBALS['kop_test_options']['kop_volunteer_sources'] = array('inspection-highlights', 'news');
$res = kop_vol_items(0, 'inspection-highlights', 0, 5);
$it = $res['items'][0] ?? null;
$check('a serious finding card offers the full report', $it && $it['full_text'] === true);
$check('the admin viewer link is not given to volunteers', $it && !preg_grep('#/api/review-#', array_column($it['links'], 'url')));
$news = kop_vol_items(0, 'news', 0, 1)['items'][0] ?? null;
$check('a queue with no report text offers none', !$news || $news['full_text'] === false);
if ($it) {
    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response { public $data; public $status; public function __construct($d, $s) { $this->data = $d; $this->status = $s; } }
    }
    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request { private $p; public function __construct($p) { $this->p = $p; } public function get_param($k) { return $this->p[$k] ?? null; } public function get_header($k) { return ''; } }
    }
    $rid = (int) $pdo->query('SELECT report_id FROM inspection_highlights WHERE id = ' . (int) $it['key'])->fetchColumn();
    $raw = trim((string) $pdo->query('SELECT raw_content FROM inspection_reports WHERE id = ' . $rid)->fetchColumn());
    $got = kop_vol_rest_fulltext(new WP_REST_Request(array('source' => 'inspection-highlights', 'key' => $it['key'])))->data;
    $check('fulltext returns the report as scraped', isset($got['text']) && ($raw === '' || strpos($got['text'], substr(kop_vol_scrub($raw), 0, 200)) !== false), strlen($got['text'] ?? '') . ' chars' . (isset($got['error']) ? ': ' . $got['error'] : ''));
    $bad = kop_vol_rest_fulltext(new WP_REST_Request(array('source' => 'news', 'key' => '1')))->data;
    $check('fulltext is refused for a queue without it', !empty($bad['error']));
    $closed = kop_vol_rest_fulltext(new WP_REST_Request(array('source' => 'fornits', 'key' => '1')))->data;
    $check('and for a queue closed to volunteers', !empty($closed['error']));
}
unset($GLOBALS['kop_test_options']['kop_volunteer_sources']);

// Agreed calls going through on their own (inc/volunteer-auto.php), on a made-up queue under an allowed key.
echo "\nAgreed calls go through on their own\n";
$pdo->exec("DELETE FROM wpdl_kop_volunteer_recs");
$pdo->exec("DELETE FROM wpdl_kop_review_holds");
kop_rinbox_holds_active(null, true);
$GLOBALS['kop_auto_items'] = array();
foreach (array('a', 'b', 'c', 'd', 'e', 'f') as $k) $GLOBALS['kop_auto_items'][$k] = 'pending';
$auto_item = function ($k) {
    $st = $GLOBALS['kop_auto_items'][$k] ?? null;
    if ($st === null) return null;
    $actions = $st === 'pending'
        ? array(array('id' => 'yes', 'label' => 'Same place', 'style' => 'approve'), array('id' => 'no', 'label' => 'Different', 'style' => 'reject'))
        : array(array('id' => 'back', 'label' => 'Back to waiting', 'style' => 'undo'));
    return array('key' => $k, 'title' => "Pair $k", 'text' => '', 'status' => $st, 'actions' => $actions);
};
$GLOBALS['kop_rinbox_builders']['folder-links'] = function () use ($auto_item) {
    return array('label' => 'Folder links', 'get' => $auto_item,
        'list' => function (array $q) use ($auto_item) { return array('items' => array_values(array_filter(array_map($auto_item, array_keys($GLOBALS['kop_auto_items'])))), 'total' => 0); },
        'act' => function ($k, $action, array $params) {
            $GLOBALS['kop_auto_items'][$k] = array('yes' => 'approved', 'no' => 'rejected', 'back' => 'pending')[$action];
            return array('message' => 'Done ' . $action . '.');
        });
};
kop_rinbox_sources(true);
$GLOBALS['kop_test_options']['kop_volunteer_sources'] = array('folder-links');
$va = kop_vol_by_token(substr($url2 = kop_vol_create('Trusted A')[1], strpos($url2, 'invite=') + 7));
$vb = kop_vol_by_token(substr($url3 = kop_vol_create('Trusted B')[1], strpos($url3, 'invite=') + 7));
$vc = kop_vol_by_token(substr($url4 = kop_vol_create('New C')[1], strpos($url4, 'invite=') + 7));
$earn($vc['id'], array('folder-links'));
$past = $pdo->prepare("INSERT INTO wpdl_kop_volunteer_recs (volunteer_id, source, item_key, verdict, created_at, updated_at, outcome, outcome_by)
    VALUES (?, 'folder-links', ?, 'approve', '2026-01-01', '2026-01-01', ?, ?)");
foreach (array($va, $vb) as $v) for ($i = 0; $i < 20; $i++) $past->execute(array($v['id'], 'past-' . $i, $i < 19 ? 'approve' : 'reject', 'admin'));
$GLOBALS['kop_test_options']['kop_vol_projects_db'] = KOP_VOLP_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_vol_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, source TEXT NOT NULL, search TEXT NOT NULL DEFAULT '',
    question TEXT, yes_example TEXT, no_example TEXT, note TEXT, minutes INTEGER NOT NULL DEFAULT 20, volunteer_id INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'open', created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, closed_at TEXT)");
// Self-assign reads practice rounds (inc/volunteer-practice.php); none here.
$GLOBALS['kop_test_options']['kop_vol_practice_db'] = KOP_VOLPR_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_vol_practice (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, round_no INTEGER NOT NULL DEFAULT 1,
    source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', answer TEXT NOT NULL, verdict TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, answered_at TEXT, UNIQUE (volunteer_id, source, item_key))");
$status = function ($k) { return $GLOBALS['kop_auto_items'][$k]; };
$auto_rows = function () use ($pdo) { return (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_review_log WHERE user_login = 'volunteers'")->fetchColumn(); };

$check('off until turned on', kop_vol_auto_settings()['on'] === false);
kop_vol_recommend($va, 'folder-links', 'a', 'approve', '');
$r = kop_vol_recommend($vb, 'folder-links', 'a', 'approve', '');
$check('off: two trusted agreeing still wait', $status('a') === 'pending' && empty($r['applied']));

$s = kop_vol_auto_save(array('on' => 1, 'sources' => array('folder-links', 'closure', 'writeups'), 'agree' => 1, 'decided' => 20, 'rate' => 90));
$check('only allowed queues can be ticked', $s['sources'] === array('folder-links'), implode(',', $s['sources']));
$check('never fewer than two volunteers', $s['agree'] === 2);

$r = kop_vol_recommend($va, 'folder-links', 'b', 'approve', '');
$check('on: one volunteer is not enough', $status('b') === 'pending' && empty($r['applied']));
$r = kop_vol_recommend($vb, 'folder-links', 'b', 'approve', '');
$check('on: two trusted agreeing go through', $status('b') === 'approved' && !empty($r['applied']), $r['message']);
$check('filed in Recently done under "volunteers", with an Undo', $auto_rows() === 1
    && (string) $pdo->query("SELECT undo_action FROM wpdl_kop_review_log WHERE user_login = 'volunteers'")->fetchColumn() === 'back');
$check('their recs close as "volunteers"', (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_volunteer_recs WHERE item_key = 'b' AND outcome = 'approve' AND outcome_by = 'volunteers'")->fetchColumn() === 2);
$list = array();
foreach (kop_vol_list() as $v) $list[(int) $v['id']] = $v;
$check('and do not count toward agreement', (int) $list[(int) $va['id']]['decided'] === 20 && (int) $list[(int) $va['id']]['agreed'] === 19);
$check('nor toward self-assign', kop_volp_self_status((int) $va['id'])['decided'] === 20);
$check('the recent list shows it', count(kop_vol_auto_recent()) === 1);

kop_vol_recommend($va, 'folder-links', 'c', 'approve', '');
kop_vol_recommend($vb, 'folder-links', 'c', 'reject', '');
$check('a disagreement waits', $status('c') === 'pending');

kop_vol_recommend($va, 'folder-links', 'd', 'approve', '');
kop_vol_recommend($vc, 'folder-links', 'd', 'unsure', 'Not sure the towns match');
kop_vol_recommend($vb, 'folder-links', 'd', 'approve', '');
$check('a Not sure holds it', $status('d') === 'pending');

kop_vol_recommend($va, 'folder-links', 'e', 'reject', '');
kop_vol_recommend($vc, 'folder-links', 'e', 'reject', '');
$check('an untrusted second volunteer does not count', $status('e') === 'pending');
kop_vol_recommend($vb, 'folder-links', 'e', 'reject', '');
$check('a third, trusted one does', $status('e') === 'rejected');

$log_id = (int) $pdo->query("SELECT id FROM wpdl_kop_review_log WHERE user_login = 'volunteers' AND item_key = 'b'")->fetchColumn();
kop_rinbox_log_undo($log_id);
$check('Undo puts it back and reopens their recs', $status('b') === 'pending' && count(kop_vol_recs_for('folder-links', array('b'))['b'] ?? array()) === 2);
kop_vol_withdraw($va, 'folder-links', 'b');
kop_vol_recommend($va, 'folder-links', 'b', 'approve', '');
$check('once undone, it never goes through on its own again', $status('b') === 'pending');

kop_vol_auto_save(array('on' => 1, 'sources' => array()));
kop_vol_recommend($va, 'folder-links', 'f', 'approve', '');
kop_vol_recommend($vb, 'folder-links', 'f', 'approve', '');
$check('a queue not ticked waits', $status('f') === 'pending');
kop_vol_auto_save(array('on' => 0, 'sources' => array('folder-links')));
$check('turning it off keeps the ticks', kop_vol_auto_settings()['sources'] === array('folder-links') && !kop_vol_auto_settings()['on']);

// Marking each volunteer: Yes counts without a record, No never counts.
kop_vol_auto_save(array('on' => 1, 'sources' => array('folder-links')));
foreach (array('g', 'h', 'i') as $k) $GLOBALS['kop_auto_items'][$k] = 'pending';
$check('everyone starts as "if their record is good"', kop_vol_auto_mark_of((int) $vc['id']) === 'record');
kop_vol_auto_set_mark((int) $vc['id'], 'yes');
kop_vol_recommend($vc, 'folder-links', 'g', 'approve', '');
kop_vol_recommend($va, 'folder-links', 'g', 'approve', '');
$check('marked Yes: a new volunteer counts', $status('g') === 'approved');
kop_vol_auto_set_mark((int) $va['id'], 'no');
kop_vol_recommend($va, 'folder-links', 'h', 'approve', '');
kop_vol_recommend($vb, 'folder-links', 'h', 'approve', '');
$check('marked No: a trusted volunteer does not count', $status('h') === 'pending');
kop_vol_recommend($vc, 'folder-links', 'h', 'approve', '');
$check('and the other two still can', $status('h') === 'approved');
kop_vol_recommend($va, 'folder-links', 'i', 'reject', '');
kop_vol_recommend($vb, 'folder-links', 'i', 'approve', '');
kop_vol_recommend($vc, 'folder-links', 'i', 'approve', '');
$check('a No volunteer who disagrees still holds it', $status('i') === 'pending');
kop_vol_auto_set_mark((int) $vc['id'], 'record');
$check('back to "if their record is good"', kop_vol_auto_mark_of((int) $vc['id']) === 'record' && kop_vol_auto_mark_of((int) $va['id']) === 'no');
$check('an unknown choice is refused', $throws(function () use ($vc) { kop_vol_auto_set_mark((int) $vc['id'], 'always'); }) !== '');
unset($GLOBALS['kop_test_options']['kop_volunteer_sources'], $GLOBALS['kop_test_options']['kop_vol_auto'], $GLOBALS['kop_test_options']['kop_vol_auto_people']);

echo "\nSign-up on /volunteer/ (inc/volunteer-signup.php)\n";
if (!function_exists('is_email')) { function is_email($v) { return (bool) filter_var((string) $v, FILTER_VALIDATE_EMAIL); } }
$made = kop_vol_signup('  Robin   Reader ', 'robin@example.org', 'who-a');
$row = $pdo->query('SELECT * FROM wpdl_kop_volunteers WHERE id = ' . (int) $made['id'])->fetch(PDO::FETCH_ASSOC);
$check('it makes a working link at once', strlen($made['token']) === 48 && (int) (kop_vol_by_token($made['token'])['id'] ?? 0) === $made['id']
    && strpos($made['url'], 'invite=' . $made['token']) !== false);
$check('the name is tidied and the email kept', $row['name'] === 'Robin Reader' && $row['email'] === 'robin@example.org');
$check('the row says how it was made', $row['note'] === KOP_VOLS_NOTE && $row['created_by'] === KOP_VOLS_BY);
$check('only the hash is stored', $row['token_hash'] !== $made['token']);
$check('the email is optional', kop_vol_signup('Kai', '', 'who-b')['id'] > 0);
$check('a one-letter name is refused', $throws(function () { kop_vol_signup('K', '', 'who-c'); }) !== '');
$check('a web address as a name is refused', $throws(function () { kop_vol_signup('cheap pills www.spam.example', '', 'who-c'); }) !== '');
$check('a bad email is refused', $throws(function () { kop_vol_signup('Lee', 'not-an-email', 'who-c'); }) !== '');
kop_vol_signup('Lee', '', 'who-a');
kop_vol_signup('Lee two', '', 'who-a');
$check('one device makes three a day at most', strpos($throws(function () { kop_vol_signup('Lee three', '', 'who-a'); }), 'several links') !== false);
$GLOBALS['kop_test_transients']['kop_vols_' . gmdate('Ymd')] = KOP_VOLS_PER_DAY;
$check('and the site takes a set number a day', strpos($throws(function () { kop_vol_signup('Morgan', '', 'who-d'); }), 'try again tomorrow') !== false);
$check('a signed-up volunteer never sees a barred queue', !array_intersect(array_keys(kop_vol_sources()), kop_vol_never_sources()));
$bot = kop_vols_rest_signup(new WP_REST_Request(array('name' => 'Bot', 'website' => 'http://spam.example')));
$check('the hidden field stops bots', $bot->status === 400 && !$pdo->query("SELECT COUNT(*) FROM wpdl_kop_volunteers WHERE name = 'Bot'")->fetchColumn());
$_COOKIE[KOP_VOL_COOKIE] = $token;
$again = kop_vols_rest_signup(new WP_REST_Request(array('name' => 'Sam again')));
$check('a device already signed in is sent to its page', $again->status === 409 && !empty($again->data['signed_in'])
    && !$pdo->query("SELECT COUNT(*) FROM wpdl_kop_volunteers WHERE name = 'Sam again'")->fetchColumn());
$st = kop_vols_rest_status()->data;
$check('the cached page asks first and is told who is signed in', !empty($st['signed_in']) && $st['name'] === 'Sam Example' && !empty($st['page']), json_encode($st));
$made_new = kop_vol_by_token($made['token']);
$check('a new sign-up starts with no queue of their own (practice first)', $made_new && kop_vol_sources_for((int) $made_new['id']) === array());
$check('the form is the /volunteer/ hub module', (kop_vols_hub_modules(array())['volunteer'] ?? '') === 'kop_vols_module');
$check('never-open queues include drive-docs and podcasts', !array_diff(array('drive-docs', 'podcasts'), kop_vol_never_sources()));

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
