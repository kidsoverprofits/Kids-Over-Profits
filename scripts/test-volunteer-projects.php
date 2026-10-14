<?php
/**
 * Volunteer projects (inc/volunteer-projects.php): making a batch (never the
 * same item in two open projects, never a closed queue), who sees which,
 * recommending inside a project in a queue not open to everyone, "Check
 * their calls" (what starts ticked), Apply (the card's own button, logged in
 * Recently done, recommendations closed as agreed), items handled elsewhere,
 * closing, and no admin-only links on volunteer copies.
 * Runs against a temporary copy of the tables in tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-volunteer-projects.php [--db=tmp/prod.sqlite]
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

$db_path = sys_get_temp_dir() . '/kop-volunteer-projects-' . getmypid() . '.sqlite';
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
    assigned_to INTEGER NOT NULL DEFAULT 0, snooze_until TEXT, note TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");
$pdo->exec("CREATE TABLE wpdl_kop_volunteers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT NOT NULL DEFAULT '',
    token_hash TEXT NOT NULL UNIQUE, created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, link_at TEXT NOT NULL, last_seen TEXT, revoked_at TEXT)");
$pdo->exec("CREATE TABLE wpdl_kop_volunteer_recs (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, source TEXT NOT NULL,
    item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', verdict TEXT NOT NULL, note TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    outcome TEXT NOT NULL DEFAULT '', outcome_by TEXT NOT NULL DEFAULT '', outcome_at TEXT, UNIQUE (volunteer_id, source, item_key))");

$GLOBALS['kop_test_options']['kop_vol_projects_db'] = KOP_VOLP_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, source TEXT NOT NULL, search TEXT NOT NULL DEFAULT '',
    question TEXT, yes_example TEXT, no_example TEXT, note TEXT, minutes INTEGER NOT NULL DEFAULT 20, volunteer_id INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'open', created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, closed_at TEXT)");
$pdo->exec("CREATE TABLE wpdl_kop_vol_project_items (project_id INTEGER NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT '', gone INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (project_id, item_key))");

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
};
$throws = function (callable $fn) {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};
$keys_of = function ($id) { return array_column(kop_volp_item_rows($id), 'item_key'); };

// Only the default queues are open to everyone: inspection-highlights is not, so a project has to open it.
unset($GLOBALS['kop_test_options']['kop_volunteer_sources']);
$Q = 'inspection-highlights';

echo "Ready-made briefs\n";
$all = kop_rinbox_sources();
foreach (kop_volp_kinds() as $key => $k) {
    $ok = isset($all[$key]) && !in_array($key, kop_vol_never_sources(), true) && $k['question'] !== '' && $k['yes'] !== '' && $k['no'] !== '' && (int) $k['minutes'] > 0;
    $check("$key: a real queue, open to volunteers, question + yes + no", $ok);
}
$check('the queue under test is not open to everyone', !isset(kop_vol_sources()[$Q]));

echo "\nMaking projects\n";
list($sam_id, $sam_url) = kop_vol_create('Sam Example');
$sam = kop_vol_by_token(substr($sam_url, -48));
list($alex_id, $alex_url) = kop_vol_create('Alex Example');
$alex = kop_vol_by_token(substr($alex_url, -48));
$check('never-for-volunteers queues are refused', $throws(function () { kop_volp_create(array('source' => 'bug-reports', 'volunteer_id' => 0)); }) !== '');
$check('a search that matches nothing says so', strpos($throws(function () use ($Q) { kop_volp_create(array('source' => $Q, 'search' => 'zzqx no such words zzqx')); }), 'matches') !== false);
list($p1, $link1, $inv1) = kop_volp_create(array('source' => $Q, 'size' => 5, 'volunteer_id' => $sam_id, 'note' => 'Restraint is a yes.'));
$k1 = $keys_of($p1);
$check('5 items for Sam', count($k1) === 5, implode(', ', $k1));
$check('the link opens the project', $link1 === 'https://example.test/volunteer-review/?project=' . $p1, $link1);
$check('no invite for an existing volunteer', $inv1 === '');
$P1 = kop_volp_get($p1);
$check('the brief comes from the kind', $P1['question'] === kop_volp_kinds()[$Q]['question'] && $P1['note'] === 'Restraint is a yes.');
$check('minutes: about 1 a finding, rounded to 5', (int) $P1['minutes'] === 5, $P1['minutes']);
list($p2, , $inv2) = kop_volp_create(array('source' => $Q, 'size' => 5, 'new_name' => 'Robin New'));
$k2 = $keys_of($p2);
$check('a second project gets other items', count($k2) === 5 && !array_intersect($k1, $k2));
$check('a new person gets an invite that opens the project', strpos($inv2, 'invite=') !== false && strpos($inv2, 'project=' . $p2) !== false, $inv2);
$robin = kop_vol_by_token(preg_replace('/^.*invite=([a-f0-9]{48}).*$/', '$1', $inv2));
$check('...and is a volunteer now', $robin && $robin['name'] === 'Robin New' && (int) kop_volp_get($p2)['volunteer_id'] === (int) $robin['id']);
list($p3) = kop_volp_create(array('source' => $Q, 'size' => 5, 'volunteer_id' => 0));
$k3 = $keys_of($p3);
$check('an open-to-anyone project, items not in the others', count($k3) === 5 && !array_intersect($k3, array_merge($k1, $k2)));

echo "\nWho sees what\n";
$ids = function ($vid) { return array_column(kop_volp_list_for($vid), 'id'); };
$check('Sam sees his project and the open one', $ids($sam_id) === array($p1, $p3), json_encode($ids($sam_id)));
$check('Alex sees only the open one', $ids($alex_id) === array($p3), json_encode($ids($alex_id)));
$check('Alex cannot open Sam\'s', $throws(function () use ($alex_id, $p1) { kop_volp_items($alex_id, $p1, 1); }) !== '');
$res = kop_volp_items($sam_id, $p1, 3);
$check('Sam gets the first 3, as volunteer copies', count($res['items']) === 3 && $res['items'][0]['key'] === $k1[0] && !isset($res['items'][0]['actions']));
$check('the brief travels with them', $res['project']['question'] !== '' && $res['project']['total'] === 5 && $res['project']['done'] === 0 && $res['project']['mine']);
$skip = kop_volp_items($sam_id, $p1, 1, array($k1[0]));
$check('a skipped item waits for the end', $skip['items'][0]['key'] === $k1[1]);

echo "\nRecommending inside a project\n";
$msg = $throws(function () use ($sam, $Q, $k1) { kop_vol_recommend($sam, $Q, $k1[0], 'approve', 'A child was restrained.'); });
$check('Sam can recommend in a queue not open to everyone', $msg === '', $msg);
$check('Alex cannot (not his project)', $throws(function () use ($alex, $Q, $k1) { kop_vol_recommend($alex, $Q, $k1[1], 'approve', ''); }) !== '');
$check('Alex can on the open project', $throws(function () use ($alex, $Q, $k3) { kop_vol_recommend($alex, $Q, $k3[0], 'reject', 'Paperwork.'); }) === '');
$check('nobody can on an item outside every project', $throws(function () use ($sam, $Q, $k1, $k2, $k3) {
    $res = call_user_func(kop_rinbox_sources()[$Q]['list'], array('view' => 'pending', 'search' => '', 'offset' => 0, 'limit' => 60, 'origin' => '', 'filters' => array()));
    foreach ($res['items'] as $it) {
        if (!in_array((string) $it['key'], array_merge($k1, $k2, $k3), true)) { kop_vol_recommend($sam, $Q, $it['key'], 'approve', ''); return; }
    }
}) !== '');
kop_vol_recommend($sam, $Q, $k1[1], 'reject', 'Only paperwork.');
kop_vol_recommend($sam, $Q, $k1[2], 'unsure', 'Cannot tell who was hurt.');
$after = kop_volp_items($sam_id, $p1, 5);
$check('done ones leave Sam\'s list', array_column($after['items'], 'key') === array($k1[3], $k1[4]));
$check('his progress: 3 of 5', $after['project']['done'] === 3);
$prog = kop_volp_progress($P1);
$check('admin progress: 3 done, 3 waiting on you', $prog['done'] === 3 && $prog['open'] === 3 && $prog['total'] === 5, json_encode($prog));

echo "\nChecking their calls\n";
$rows = array();
foreach (kop_volp_review_rows($P1) as $r) $rows[$r['key']] = $r;
$check('approve: ticked, the card\'s own approve button', $rows[$k1[0]]['ticked'] && $rows[$k1[0]]['action'][0] === 'approved', json_encode($rows[$k1[0]]['action']));
$check('reject: ticked, the card\'s own reject button', $rows[$k1[1]]['ticked'] && $rows[$k1[1]]['action'][0] === 'rejected');
$check('not sure: never ticked, sent to the inbox', !$rows[$k1[2]]['ticked'] && $rows[$k1[2]]['state'] === 'unsure');
$check('not looked at: waiting', $rows[$k1[3]]['state'] === 'waiting' && !$rows[$k1[3]]['ticked']);
$check('a button needing a choice is not picked', kop_volp_pick_action(array('actions' => array(array('id' => 'x', 'style' => 'approve', 'params' => array(array('name' => 'facility_id', 'value' => ''))))), 'approve') === null);
$check('an "ask" button is not picked', kop_volp_pick_action(array('actions' => array(array('id' => 'x', 'style' => 'approve', 'ask' => true))), 'approve') === null);
$check('optional params ride along', kop_volp_pick_action(array('actions' => array(array('id' => 'a', 'style' => 'approve', 'params' => array(array('name' => 'note', 'value' => '', 'optional' => true), array('name' => 'f', 'value' => 7))))), 'approve') === array('a', array('note' => '', 'f' => 7), 'a'));

$status = function ($key) use ($pdo) { return $pdo->query('SELECT status FROM inspection_highlights WHERE id = ' . (int) $key)->fetchColumn(); };
$res = kop_volp_apply($P1, array($k1[0], $k1[1], $k1[2]));
$ok = array_column($res, 'ok', 'key');
$check('applied the two clear calls', $ok[$k1[0]] && $ok[$k1[1]], json_encode($res));
$check('left the unsure one', isset($ok[$k1[2]]) && !$ok[$k1[2]]);
$check('the finding is approved / rejected', $status($k1[0]) === 'approved' && $status($k1[1]) === 'rejected', $status($k1[0]) . ' / ' . $status($k1[1]));
$log = $pdo->query("SELECT action, style FROM wpdl_kop_review_log WHERE source = '$Q' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$check('both are in Recently done', count($log) === 2 && $log[0]['style'] === 'approve' && $log[1]['style'] === 'reject', json_encode($log));
$outcomes = $pdo->query("SELECT item_key, outcome FROM wpdl_kop_volunteer_recs WHERE volunteer_id = $sam_id")->fetchAll(PDO::FETCH_KEY_PAIR);
$check('Sam\'s calls are closed as agreed', $outcomes[$k1[0]] === 'approve' && $outcomes[$k1[1]] === 'reject' && $outcomes[$k1[2]] === '');
$rows = array();
foreach (kop_volp_review_rows($P1) as $r) $rows[$r['key']] = $r;
$check('now shown as decided, never applied twice', $rows[$k1[0]]['state'] === 'decided' && !$rows[$k1[0]]['ticked']);
$check('applying again does nothing', !array_filter(kop_volp_apply($P1, array($k1[0])), function ($r) { return $r['ok']; }));
$sam_row = array_values(array_filter(kop_vol_list(), function ($v) use ($sam_id) { return (int) $v['id'] === $sam_id; }))[0];
$check('agreement rate counts project calls', (int) $sam_row['decided'] === 2 && (int) $sam_row['agreed'] === 2);

echo "\nHandled elsewhere, closing\n";
// The admin approves one of Sam's untouched items from the inbox: it counts as done for him.
kop_rinbox_apply_action($Q, $k1[3], 'approved', array('note' => ''));
$after = kop_volp_items($sam_id, $p1, 5);
$check('an item handled in the inbox leaves the project', array_column($after['items'], 'key') === array($k1[4]) && $after['project']['done'] === 4,
    json_encode(array_column($after['items'], 'key')) . ' done ' . $after['project']['done']);
kop_volp_set_status($p1, 'closed');
$check('a closed project is gone from Sam\'s page', !in_array($p1, $ids($sam_id), true));
$check('no recommending in a closed project', $throws(function () use ($sam, $Q, $k1) { kop_vol_recommend($sam, $Q, $k1[4], 'approve', ''); }) !== '');
$check('and its items are free again', in_array($k1[4], $keys_of(kop_volp_create(array('source' => $Q, 'size' => 50, 'volunteer_id' => 0))[0]), true));

echo "\nWhat volunteers can open\n";
$check('admin screens are not links for volunteers', kop_vol_admin_only_url('https://example.test/wp-admin/admin-ajax.php?action=kop_wb_view&key=x&nonce=y')
    && kop_vol_admin_only_url('https://example.test/theme/api/review-inspection-highlights.php?report=1') && !kop_vol_admin_only_url('https://example.test/wp-content/uploads/2024/12/woodbury-1108.pdf#page=20'));
foreach (array('woodbury-reports', 'inspection-highlights') as $src_key) {
    $src = kop_rinbox_sources()[$src_key];
    $r = call_user_func($src['list'], array('view' => array_keys($src['views'])[0], 'search' => '', 'offset' => 0, 'limit' => 5, 'origin' => '', 'filters' => array()));
    $bad = 0;
    foreach ($r['items'] as $it) {
        $v = kop_vol_item($src_key, $it);
        foreach (array_merge($v['links'], $v['facts']) as $l) if (!empty($l['url']) && kop_vol_admin_only_url($l['url'])) $bad++;
    }
    $check("$src_key: no admin-only links on the volunteer copy", $bad === 0, $bad . ' found');
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
