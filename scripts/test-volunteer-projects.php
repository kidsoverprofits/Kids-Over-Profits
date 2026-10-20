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

// Sending a research link to the news queue reads every facility's names (kop_normalize_facility_mentions()).
ini_set('memory_limit', '1024M');

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
    assigned_to INTEGER NOT NULL DEFAULT 0, snooze_until TEXT, research INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");
$pdo->exec("CREATE TABLE wpdl_kop_volunteers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
    token_hash TEXT NOT NULL UNIQUE, created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, link_at TEXT NOT NULL, last_seen TEXT, revoked_at TEXT)");
$pdo->exec("CREATE TABLE wpdl_kop_volunteer_recs (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, source TEXT NOT NULL,
    item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', verdict TEXT NOT NULL, note TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    outcome TEXT NOT NULL DEFAULT '', outcome_by TEXT NOT NULL DEFAULT '', outcome_at TEXT, UNIQUE (volunteer_id, source, item_key))");

$GLOBALS['kop_test_options']['kop_vol_projects_db'] = KOP_VOLP_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, source TEXT NOT NULL, search TEXT NOT NULL DEFAULT '',
    question TEXT, yes_example TEXT, no_example TEXT, note TEXT, minutes INTEGER NOT NULL DEFAULT 20, volunteer_id INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'open', created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, closed_at TEXT)");
$pdo->exec("CREATE TABLE wpdl_kop_vol_project_items (project_id INTEGER NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT '', gone INTEGER NOT NULL DEFAULT 0, detail TEXT, PRIMARY KEY (project_id, item_key))");
$GLOBALS['kop_test_options']['kop_vol_messages_db'] = KOP_VOLM_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, project_id INTEGER NOT NULL DEFAULT 0,
    item_key TEXT NOT NULL DEFAULT '', item_title TEXT NOT NULL DEFAULT '', sender TEXT NOT NULL, author TEXT NOT NULL DEFAULT '', body TEXT NOT NULL,
    created_at TEXT NOT NULL, read_at TEXT)");
$GLOBALS['kop_test_options']['kop_vol_answers_db'] = KOP_VOLR_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_answers (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, item_key TEXT NOT NULL, volunteer_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '', answer TEXT, found INTEGER NOT NULL DEFAULT 1, links TEXT, status TEXT NOT NULL DEFAULT '', decided_by TEXT NOT NULL DEFAULT '',
    decided_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (project_id, item_key, volunteer_id))");

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
// A queue that needs a library this test does not load (doc-types) is still checked by name against its registration.
$registered = array();
foreach (glob(dirname(__DIR__) . '/inc/review-inbox/*.php') as $f) {
    if (preg_match_all("/kop_rinbox_register\('([a-z_-]+)'/", file_get_contents($f), $mm)) foreach ($mm[1] as $x) $registered[$x] = true;
}
foreach (kop_rinbox_native_types() as $x => $t) $registered[$x] = true;
foreach (kop_volp_kinds() as $key => $k) {
    $ok = (isset($all[$key]) || isset($registered[$key])) && !in_array($key, kop_vol_never_sources(), true) && $k['question'] !== '' && $k['yes'] !== '' && $k['no'] !== '' && (int) $k['minutes'] > 0;
    $check("$key: a real queue, open to volunteers, question + yes + no", $ok, isset($all[$key]) ? '' : 'not loaded here');
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

echo "\nSelf-assign\n";
$st = kop_volp_self_status($sam_id);
$check('2 decided calls is not enough to self-assign', !$st['eligible'] && $st['decided'] === 2 && $st['need_decided'] === 10 && !$st['queues'], json_encode($st));
$check('a refused self-assign says what earns it', strpos($throws(function () use ($sam, $Q) { kop_volp_self_assign($sam, $Q); }), '10 of your calls') !== false);
$check('a volunteer with no calls is not eligible', !kop_volp_self_status($alex_id)['eligible']);
// Eight more calls the admin agreed with, plus two that differed (8 of 10 + 2 = 10 of 12 = 83%).
$now = current_time('mysql', true);
for ($i = 0; $i < 10; $i++) {
    $wpdb->insert(kop_vol_recs_table(), array('volunteer_id' => $sam_id, 'source' => 'news', 'item_key' => "selftest-$i", 'title' => "Self test $i",
        'verdict' => 'approve', 'note' => '', 'created_at' => $now, 'updated_at' => $now, 'outcome' => $i < 8 ? 'approve' : 'reject', 'outcome_by' => 'admin', 'outcome_at' => $now));
}
$st = kop_volp_self_status($sam_id);
$check('10+ decided at 80%+ agreement: eligible', $st['eligible'] && $st['decided'] === 12 && $st['agreed'] === 10 && $st['open'] === 0, json_encode($st));
$check('the choices are the queues open to everyone', $st['queues'] && !array_diff(array_column($st['queues'], 'key'), array_keys(kop_vol_sources())), json_encode(array_column($st['queues'], 'key')));
$S = $st['queues'][0]['key'];
$err = '';
try { $mine = kop_volp_self_assign($sam, $S); } catch (Throwable $e) { $err = $e->getMessage(); $mine = null; }
if ($mine) {
    $row = kop_volp_get($mine['id']);
    $check('a batch is made for him, marked self-assigned', (int) $row['volunteer_id'] === $sam_id && $row['created_by'] === KOP_VOLP_SELF_CREATED_BY && $row['source'] === $S);
    $check('...of the self-assign size at most', count($keys_of($mine['id'])) >= 1 && count($keys_of($mine['id'])) <= $st['size']);
    $check('...and it is his only open self-assigned one', kop_volp_self_status($sam_id)['open'] === $mine['id']);
    $check('a second batch waits for the first', strpos($throws(function () use ($sam, $S) { kop_volp_self_assign($sam, $S); }), 'Finish') !== false);
    kop_volp_set_status($mine['id'], 'closed');
} else {
    $check('self-assign made a batch', false, $err);
}
$check('a queue not open to everyone is refused', strpos($throws(function () use ($sam, $Q) { kop_volp_self_assign($sam, $Q); }), 'Pick one') !== false);
$check('a never-for-volunteers queue is refused', strpos($throws(function () use ($sam) { kop_volp_self_assign($sam, 'bug-reports'); }), 'Pick one') !== false);
$wpdb->query('DELETE FROM ' . kop_vol_recs_table() . " WHERE item_key LIKE 'selftest-%'");

echo "\nHandled elsewhere, closing\n";
// The admin approves one of Sam's untouched items from the inbox: it counts as done for him.
kop_rinbox_apply_action($Q, $k1[3], 'approved', array('note' => ''));
$after = kop_volp_items($sam_id, $p1, 5);
$check('an item handled in the inbox leaves the project', array_column($after['items'], 'key') === array($k1[4]) && $after['project']['done'] === 4,
    json_encode(array_column($after['items'], 'key')) . ' done ' . $after['project']['done']);
kop_volp_set_status($p1, 'closed');
$check('a closed project is gone from Sam\'s page', !in_array($p1, $ids($sam_id), true));
$check('no recommending in a closed project', $throws(function () use ($sam, $Q, $k1) { kop_vol_recommend($sam, $Q, $k1[4], 'approve', ''); }) !== '');
list($p50) = kop_volp_create(array('source' => $Q, 'size' => 50, 'volunteer_id' => 0));
$check('and its items are free again', in_array($k1[4], $keys_of($p50), true));
$n50 = count($keys_of($p50));
kop_volp_set_status($p50, 'closed');
list($pall) = kop_volp_create(array('source' => $Q, 'size' => 0, 'volunteer_id' => 0));
$nall = count($keys_of($pall));
$check('size 0 takes every waiting item, no cap', $nall >= $n50 && $nall > 30, "$nall vs $n50");
kop_volp_set_status($pall, 'closed');

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

echo "\nMessages\n";
$check('no messages yet', kop_volm_unread_count() === 0 && !kop_volm_threads());
$check('an empty message is refused', $throws(function () use ($sam_id) { kop_volm_send($sam_id, 'vol', "  \n "); }) !== '');
$check('a very long one is refused', $throws(function () use ($sam_id) { kop_volm_send($sam_id, 'vol', str_repeat('a', KOP_VOLM_MAX + 1)); }) !== '');
$m1 = kop_volm_send($sam_id, 'vol', "Is a restraint with no injury still a yes?", array('project_id' => $p2, 'item_key' => $Q . ':' . $k2[0], 'item_title' => 'A finding'));
$check('Sam asks about a project item', $m1['id'] > 0 && $m1['author'] === 'Sam Example' && (int) $m1['project_id'] === $p2);
kop_volm_send($alex_id, 'vol', 'Where do I start?');
$check('two unread for the admins', kop_volm_unread_count() === 2 && kop_volm_unread_count($sam_id) === 1);
$threads = kop_volm_threads();
$check('both conversations listed, newest first', count($threads) === 2 && $threads[0]['unread'] === 1, json_encode(array_column($threads, 'name')));
$check('a project names its questions', count(kop_volm_for_project($p2)) === 1);
$check('a project that does not exist is not named', (int) kop_volm_send($alex_id, 'vol', 'Another', array('project_id' => 999999))['project_id'] === 0);
kop_volm_send($sam_id, 'admin', 'Yes: any restraint that broke the rules is a yes.', array('project_id' => $p2), 'Owner');
$check('answering reads what Sam wrote', kop_volm_unread_count($sam_id) === 0 && kop_volm_unread_count() === 2);
$check('Sam has one reply to read', kop_volm_unread_for_vol($sam_id) === 1);
$pub = kop_volm_public(kop_volm_thread($sam_id));
$check('his page shows both, the reply marked new, no admin name', count($pub) === 2 && $pub[1]['unread'] && $pub[1]['author'] === 'Kids Over Profits' && $pub[0]['author'] === 'You'
    && $pub[0]['project'] === kop_volp_get($p2)['title'], json_encode($pub));
kop_volm_mark_read($sam_id, 'vol');
$check('seen: nothing new for Sam', kop_volm_unread_for_vol($sam_id) === 0 && !kop_volm_public(kop_volm_thread($sam_id))[1]['unread']);
$check('Alex sees none of Sam\'s', count(kop_volm_thread($alex_id)) === 2 && !array_filter(kop_volm_thread($alex_id), function ($r) use ($sam_id) { return (int) $r['volunteer_id'] === $sam_id; }));
list($gone_id) = kop_vol_create('Gone Example');
kop_vol_revoke($gone_id);
$check('a turned-off volunteer cannot write', $throws(function () use ($gone_id) { kop_volm_send($gone_id, 'vol', 'Hello'); }) !== '');
$check('...but can be written to (read once they have a new link)', kop_volm_send($gone_id, 'admin', 'Your link is off.', array(), 'Owner')['id'] > 0);

echo "\nResearch projects\n";
$check('research is never a review queue', !isset(kop_volp_sources()['research:closing-year']));
$check('an unknown kind is refused', $throws(function () { kop_volp_create(array('source' => 'research:nope')); }) !== '');
$state = $pdo->query("SELECT state FROM facilities_v2 WHERE status = 'Closed' AND end_year IS NULL AND name <> '' GROUP BY state ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
list($r1) = kop_volp_create(array('source' => 'research:closing-year', 'size' => 3, 'search' => strtolower($state), 'volunteer_id' => $sam_id));
$R1 = kop_volp_get($r1);
$rk1 = $keys_of($r1);
$ok = count($rk1) === 3;
foreach ($rk1 as $k) {
    $f = $pdo->query('SELECT status, end_year, state FROM facilities_v2 WHERE id = ' . (int) substr($k, 1))->fetch(PDO::FETCH_ASSOC);
    $ok = $ok && $f['status'] === 'Closed' && $f['end_year'] === null && $f['state'] === $state;
}
$check("3 closed $state records with no closing year", $ok, implode(', ', $rk1));
$check('titled by the kind and the state', $R1['title'] === kop_volr_kinds()['closing-year']['title'] . ': ' . $state && $R1['source'] === 'research:closing-year', $R1['title']);
list($r2) = kop_volp_create(array('source' => 'research:closing-year', 'size' => 3, 'search' => $state, 'volunteer_id' => 0));
$check('a second project of the kind asks about other records', !array_intersect($rk1, $keys_of($r2)));
$check('typed questions with nothing typed are refused', $throws(function () { kop_volp_create(array('source' => 'research:custom', 'questions' => "\n  \n")); }) !== '');
list($r3) = kop_volp_create(array('source' => 'research:custom', 'size' => 2, 'volunteer_id' => $sam_id,
    'questions' => "1. Who owned Hidden Lake Academy before 2005?\n- Which company ran its transport?\n\n* When did it get its licence?"));
$titles = array_column(kop_volp_item_rows($r3), 'title');
$check('every typed line is a question (list marks dropped, size ignored)', $titles === array('Who owned Hidden Lake Academy before 2005?', 'Which company ran its transport?', 'When did it get its licence?'), json_encode($titles));
$listed = array();
foreach (kop_volp_list_for($sam_id) as $x) $listed[$x['id']] = $x;
$check('Sam\'s page lists them as research', isset($listed[$r1], $listed[$r3]) && $listed[$r1]['kind'] === 'research' && isset($listed[$r1]['link_kinds']['lawsuit']));

$res = kop_volp_items($sam_id, $r1, 3);
$it = $res['items'][0];
$check('a question card: the record, its page and what we hold', $it['source'] === 'research' && $it['key'] === $rk1[0] && $it['record']['name'] !== ''
    && in_array('Status on our record', array_column($it['facts'], 'label'), true) && $it['question'] === $R1['question'], json_encode($it));
$check('Alex cannot open Sam\'s research', $throws(function () use ($alex_id, $r1) { kop_volp_items($alex_id, $r1, 1); }) !== '');
$check('nothing found and nothing said is refused', $throws(function () use ($sam, $r1, $rk1) { kop_volr_answer($sam, $r1, $rk1[0], '', array()); }) !== '');
$check('"could not find it" needs where they looked', $throws(function () use ($sam, $r1, $rk1) { kop_volr_answer($sam, $r1, $rk1[0], '', array(), false); }) !== '');
$check('a link that is not a web address is refused', strpos($throws(function () use ($sam, $r1, $rk1) { kop_volr_answer($sam, $r1, $rk1[0], 'Closed', array(array('url' => 'not a link'))); }), 'web address') !== false);
$check('Alex cannot answer in Sam\'s project', $throws(function () use ($alex, $r1, $rk1) { kop_volr_answer($alex, $r1, $rk1[0], 'Closed in 2009', array()); }) !== '');
$check('a key outside the project is refused', $throws(function () use ($sam, $r1) { kop_volr_answer($sam, $r1, 'f1', 'Closed', array()); }) !== '');

// The links go to the review inbox as they are saved: a new one is a waiting news row, one we hold is not added twice.
require_once dirname(__DIR__) . '/inc/source-submissions.php';
$held = $pdo->query("SELECT article_url FROM news_submissions WHERE article_url LIKE 'https://%' ORDER BY id LIMIT 1")->fetchColumn();
$fresh = 'https://example-news.test/kop-volunteer-test-' . getmypid();
$before = (int) $pdo->query('SELECT COUNT(*) FROM news_submissions')->fetchColumn();
$a = kop_volr_answer($sam, $r1, $rk1[0], 'Closed in 2009: "the academy shut its doors in June 2009".',
    array(array('url' => $fresh, 'kind' => 'article', 'title' => 'Academy closes'), array('url' => $held, 'kind' => 'article'), array('url' => $fresh)));
$sent = array_column($a['links'], 'sent', 'url');
$check('the answer is saved with its two links (the repeat dropped)', count($a['links']) === 2 && $a['found'], json_encode($a));
$check('the new link went to the news queue', ($sent[$fresh] ?? '') === 'news review', json_encode($sent));
$row = $pdo->query('SELECT status, submitted_by, submission_notes AS notes FROM news_submissions WHERE article_url = ' . $pdo->quote($fresh))->fetch(PDO::FETCH_ASSOC);
$check('...waiting, from the volunteer, the question in its note', $row && $row['status'] === 'submitted' && strpos($row['submitted_by'], 'Sam Example (volunteer)') === 0
    && strpos($row['notes'], 'When did this program close?') !== false, json_encode($row));
$check('the held link was not added again', ($sent[$held] ?? '') === 'already held' && (int) $pdo->query('SELECT COUNT(*) FROM news_submissions')->fetchColumn() === $before + 1);
$a2 = kop_volr_answer($sam, $r1, $rk1[0], 'Closed in June 2009.', array(array('url' => $fresh, 'kind' => 'article')));
$check('changing the answer does not send the link twice', $a2['answer'] === 'Closed in June 2009.' && $a2['links'][0]['sent'] === 'news review'
    && (int) $pdo->query('SELECT COUNT(*) FROM news_submissions WHERE article_url = ' . $pdo->quote($fresh))->fetchColumn() === 1);
kop_volr_answer($sam, $r1, $rk1[1], 'Searched the state licence lookup and three newspapers, nothing.', array(), false);
$after = kop_volp_items($sam_id, $r1, 3);
$check('answered questions leave his list', array_column($after['items'], 'key') === array($rk1[2]) && $after['project']['done'] === 2);
$prog = kop_volp_progress($R1);
$check('admin progress: 2 of 3, 2 to read', $prog['done'] === 2 && $prog['open'] === 2 && $prog['total'] === 3, json_encode($prog));
$mine = kop_volr_mine($sam_id);
$check('"Your past answers" lists both', count($mine) === 2 && $mine[0]['kind'] === 'answer' && in_array(false, array_column($mine, 'found'), true));

$aid = (int) $pdo->query("SELECT id FROM wpdl_kop_vol_answers WHERE project_id = $r1 AND item_key = " . $pdo->quote($rk1[0]))->fetchColumn();
kop_volr_decide($aid, 'used');
$check('used: no more changes to it', $throws(function () use ($sam, $r1, $rk1) { kop_volr_answer($sam, $r1, $rk1[0], 'Changed', array()); }) !== '');
$check('...nor taking it back', (kop_volr_withdraw($sam, $r1, $rk1[0]) || true) && kop_volr_get_answer($r1, $rk1[0], $sam_id) !== null);
$prog = kop_volp_progress($R1);
$check('1 to read, 1 decided', $prog['open'] === 1 && $prog['decided'] === 1, json_encode($prog));
$check('a used answer keeps the record out of later projects', isset(kop_volr_taken_keys('research:closing-year')[$rk1[0]]));
kop_volp_set_status($r1, 'closed');
$check('a not-found answer does not', !isset(kop_volr_taken_keys('research:closing-year')[$rk1[1]]));
kop_volr_decide($aid, 'dismissed');
$check('"not needed" frees the record again', !isset(kop_volr_taken_keys('research:closing-year')[$rk1[0]]));
$check('no answering in a closed project', $throws(function () use ($sam, $r1, $rk1) { kop_volr_answer($sam, $r1, $rk1[2], 'Closed', array()); }) !== '');
$check('a closed research project leaves his page', !in_array($r1, $ids($sam_id), true));
kop_volr_answer($sam, $r3, 'q1', 'The founder, until a 2005 sale.', array());
kop_volr_withdraw($sam, $r3, 'q1');
$check('an unread answer can be taken back', kop_volr_get_answer($r3, 'q1', $sam_id) === null);
$check('the message for a research project says so', strpos(kop_volp_message(kop_volp_get($r3), 'https://x.test'), 'could not find it') !== false
    && strpos(kop_volp_message(kop_volp_get($r3), 'https://x.test'), 'Ask a question') !== false);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
