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
    // The weekly roundup's cards read these too.
    'inspection_highlight_summaries', 'news_featured',
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
// Every queue open to volunteers, as when the other practice rounds are ready (off on the site: only the inspection findings).
$GLOBALS['kop_test_filters']['kop_vol_more_queues'] = true;
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
    status TEXT NOT NULL DEFAULT 'open', created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, closed_at TEXT, closed_why TEXT NOT NULL DEFAULT '')");
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
$GLOBALS['kop_test_options']['kop_vol_practice_db'] = KOP_VOLPR_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_practice (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, round_no INTEGER NOT NULL DEFAULT 1,
    source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', answer TEXT NOT NULL, verdict TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, answered_at TEXT, UNIQUE (volunteer_id, source, item_key))");
$GLOBALS['kop_test_options']['kop_vol_social_db'] = KOP_VSOC_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_social (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, item_key TEXT NOT NULL, volunteer_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'taken', caption TEXT, image_url TEXT NOT NULL DEFAULT '', image_file TEXT NOT NULL DEFAULT '',
    attachment_id INTEGER NOT NULL DEFAULT 0, note TEXT, owner_note TEXT,
    decided_by TEXT NOT NULL DEFAULT '', decided_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (project_id, item_key, volunteer_id))");
$GLOBALS['kop_test_options']['kop_vol_images_db'] = KOP_VIMG_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_images (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, item_key TEXT NOT NULL, volunteer_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'draft', page_url TEXT NOT NULL DEFAULT '', file_url TEXT NOT NULL DEFAULT '', author TEXT NOT NULL DEFAULT '',
    license TEXT NOT NULL DEFAULT '', alt TEXT NOT NULL DEFAULT '', note TEXT, owner_note TEXT, attachment_id INTEGER NOT NULL DEFAULT 0,
    decided_by TEXT NOT NULL DEFAULT '', decided_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (project_id, item_key, volunteer_id))");
foreach (array('highlight-summaries', 'featured-reporting', 'news-images', 'federal-funding') as $lib) require_once dirname(__DIR__) . "/inc/$lib.php";

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
$check('the choices are the queues he earned: news (his record there), not the findings he did in a project', array_column($st['queues'], 'key') === array('news'), json_encode(array_column($st['queues'], 'key')));
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
$check('a queue he has not earned is refused', strpos($throws(function () use ($sam, $Q) { kop_volp_self_assign($sam, $Q); }), 'practice') !== false,
    $throws(function () use ($sam, $Q) { kop_volp_self_assign($sam, $Q); }));
$check('a never-for-volunteers queue is refused', strpos($throws(function () use ($sam) { kop_volp_self_assign($sam, 'bug-reports'); }), 'Pick one') !== false,
    $throws(function () use ($sam) { kop_volp_self_assign($sam, 'bug-reports'); }));
$wpdb->query('DELETE FROM ' . kop_vol_recs_table() . " WHERE item_key LIKE 'selftest-%'");

echo "\nPractice round\n";
// The admins' real news decisions, plus rules filing things ('auto') and a call that went through on volunteers' word.
$log_from = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM wpdl_kop_review_log')->fetchColumn();
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($mirror) . ' AS src');
$pdo->exec("INSERT INTO wpdl_kop_review_log (source, item_key, title, action, action_label, style, message, undo_action, undo_params, user_login, created_at, undone_at, undone_by)
    SELECT source, item_key, title, action, action_label, style, message, undo_action, undo_params, user_login, created_at, undone_at, undone_by
    FROM src.wpdl_kop_review_log WHERE source = 'news' AND style IN ('approve', 'reject')");
$pdo->exec('DETACH DATABASE src');
$news_log = function ($user) use ($pdo, $log_from) {
    return $pdo->query("SELECT item_key FROM wpdl_kop_review_log WHERE id > $log_from AND user_login = " . $pdo->quote($user) . ' LIMIT 1')->fetchColumn();
};
$auto_key = $news_log('auto');
$pdo->exec("INSERT INTO wpdl_kop_review_log (source, item_key, title, action, style, user_login, created_at) VALUES ('news', 'voltest-auto', 'x', 'approve', 'approve', '" . KOP_VOL_AUTO_BY . "', '2026-01-01')");
$P = 'news';
$recs_before = (int) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_volunteer_recs')->fetchColumn();
$track_before = kop_vol_auto_track(array($alex_id));
$st = kop_volp_self_status($alex_id);
$check('Alex is not eligible yet, and is offered a practice round', !$st['eligible'] && isset($st['practice']) && in_array($P, array_column($st['practice']['queues'], 'key'), true),
    json_encode($st['practice']['queues'] ?? null));
$check('...only in queues a volunteer can earn, the violations first', !array_diff(array_column($st['practice']['queues'], 'key'), array_keys(kop_vol_earnable_sources()))
    && (!in_array($Q, array_column($st['practice']['queues'], 'key'), true) || $st['practice']['queues'][0]['key'] === $Q), json_encode(array_column($st['practice']['queues'], 'key')));
$check('a queue volunteers never see is refused', strpos($throws(function () use ($alex) { kop_volpr_start($alex, 'bug-reports'); }), 'Pick one') !== false);
$check('answering before starting says so', strpos($throws(function () use ($alex) { kop_volpr_answer($alex, 'x', 'approve'); }), 'Start') !== false);
$ps = kop_volpr_start($alex, $P);
$round = $pdo->query("SELECT item_key, answer FROM wpdl_kop_vol_practice WHERE volunteer_id = $alex_id ORDER BY position")->fetchAll(PDO::FETCH_KEY_PAIR);
$counts = array_count_values($round);
$check('a round of 10, half of them rejects', count($round) === 10 && ($counts['reject'] ?? 0) === 5 && ($counts['approve'] ?? 0) === 5, json_encode($counts));
$check('...every card has something to read and no CAPTCHA link', !array_filter(array_keys($round), function ($k) use ($P) {
    $it = kop_volpr_fetch($P, (string) $k);
    return !$it || kop_volpr_unclear(kop_volpr_copy($P, $it));
}));
$check('a card with nothing to read, or an archive.md link, is left out', kop_volpr_unclear(array('text' => '', 'facts' => array(array('label' => 'Outlet', 'value' => 'The Paper'))))
    && kop_volpr_unclear(array('text' => 'A long enough summary of the story.', 'url' => 'https://archive.md/NraFd'))
    && !kop_volpr_unclear(array('text' => '', 'url' => 'https://news.test/a', 'facts' => array(array('label' => 'Summary', 'value' => 'Two staff at a ranch program were charged after a teen was hurt.')))));
$tricky = kop_volpr_tricky('inspection-highlights');
$check('the findings a Brief FAQ answer rests on are left out (#2967, the range #3257-3263)', isset($tricky['2967']) && isset($tricky['3260']) && !isset($tricky['3264']));
$GLOBALS['kop_test_options']['kop_volpr_struck'] = array($P => array('struck-1' => true));
$check('a card the admin struck is left out', isset(kop_volpr_tricky($P)['struck-1']) && kop_volpr_admin_post('practice_unstrike', array('kop_volpr_source' => $P, 'kop_volpr_key' => 'struck-1'))
    && !isset(kop_volpr_tricky($P)['struck-1']));
$titles = $pdo->query("SELECT LOWER(TRIM(title)) FROM wpdl_kop_vol_practice WHERE volunteer_id = $alex_id")->fetchAll(PDO::FETCH_COLUMN);
$check('...no two cards with the same title', count($titles) === count(array_unique($titles)), json_encode(array_diff_assoc($titles, array_unique($titles))));
$check('...shown as the open round', $ps['open'] && $ps['open']['total'] === 10 && $ps['open']['done'] === 0 && $ps['open']['source'] === $P);
$check('...never items from rules filing things or volunteers\' word', !isset($round[(string) $auto_key]) && !isset($round['voltest-auto']));
$logged = $pdo->query("SELECT item_key, style FROM wpdl_kop_review_log WHERE source = 'news' AND undone_at IS NULL AND user_login = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
$by_admin = array();
foreach ($logged as $l) $by_admin[$l['item_key']][$l['style']] = true;
$check('...every answer is what the admin decided', !array_filter(array_keys($round), function ($k) use ($by_admin, $round) { return empty($by_admin[$k][$round[$k]]) || count($by_admin[$k]) !== 1; }));
$check('starting again carries on the same round', kop_volpr_start($alex, $P)['open']['round'] === $ps['open']['round']
    && (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_vol_practice WHERE volunteer_id = $alex_id")->fetchColumn() === 10);
$nx = kop_volpr_next($alex_id);
$card = $nx['item'];
$check('the next card is the first of the round, a practice copy', $card && $card['key'] === (string) array_keys($round)[0] && !empty($card['practice']) && !isset($card['actions']));
$check('...with nothing that says how it was decided', $card['facility'] === null && !$card['approve'] && !$card['reject']
    && !array_filter($card['facts'], function ($f) { return kop_volpr_hidden_fact($f['label']); }) && $card['subtitle'] === ''
    && !array_filter($card['links'], function ($l) { return kop_volpr_hidden_fact($l['label']); }));
$check('labels that give it away', kop_volpr_hidden_fact('Reviewed by') && kop_volpr_hidden_fact('Filed PDF') && kop_volpr_hidden_fact('Listed on')
    && !kop_volpr_hidden_fact('Case status') && !kop_volpr_hidden_fact('Outlet') && !kop_volpr_hidden_fact('Full issue'));
$check('...and the brief of its queue', strpos($nx['brief']['title'], 'Practice') === 0 && $nx['brief']['question'] !== '');
$check('a card outside the round is refused', strpos($throws(function () use ($alex) { kop_volpr_answer($alex, 'no-such-key', 'approve'); }), 'not in your') !== false);
// 3 wrong (one of them Not sure), 7 right: below 8 of 10.
$i = 0;
foreach ($round as $k => $ans) {
    $v = $i < 2 ? ($ans === 'approve' ? 'reject' : 'approve') : ($i === 2 ? 'unsure' : $ans);
    $res = kop_volpr_answer($alex, (string) $k, $v);
    if ($i === 0) $check('a wrong call says what the admin decided', !$res['right'] && $res['answer'] === $ans && strpos($res['said'], $ans === 'approve' ? 'approved' : 'rejected') !== false);
    if ($i === 3) $check('a right call says so', $res['right']);
    $i++;
}
$check('the same card twice is refused', $throws(function () use ($alex, $round) { kop_volpr_answer($alex, (string) array_keys($round)[3], 'approve'); }) !== '');
$check('7 of 10: finished, not passed', $res['round']['finished'] && !$res['round']['passed'] && $res['round']['right'] === 7, json_encode($res['round']));
$check('practice calls are never recommendations', (int) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_volunteer_recs')->fetchColumn() === $recs_before
    && !array_intersect(array_column(kop_vol_mine($alex_id), 'key'), array_map('strval', array_keys($round))));
$check('...never in the agreement rate', (int) array_values(array_filter(kop_vol_list(), function ($v) use ($alex_id) { return (int) $v['id'] === $alex_id; }))[0]['decided'] === 0);
$check('...never in going through on their own', kop_vol_auto_track(array($alex_id)) === $track_before, json_encode($track_before));
$st = kop_volp_self_status($alex_id);
$check('...and a failed round opens nothing', !$st['eligible'] && $st['decided'] === 0 && $st['practice']['last']['right'] === 7 && !$st['practice']['open']);
$check('the Volunteers table line', kop_volpr_summary($alex_id) === 'Practice: ' . kop_rinbox_sources()[$P]['label'] . ' 7 of 10, not passed', kop_volpr_summary($alex_id));
kop_volpr_start($alex, $P);
$round2 = $pdo->query("SELECT item_key, answer FROM wpdl_kop_vol_practice WHERE volunteer_id = $alex_id AND round_no = 2 ORDER BY position")->fetchAll(PDO::FETCH_KEY_PAIR);
$check('another round draws other items', count($round2) === 10 && !array_intersect_key($round2, $round));
$i = 0;
foreach ($round2 as $k => $ans) {
    $res = kop_volpr_answer($alex, (string) $k, $i === 0 ? 'unsure' : $ans);
    $i++;
}
$check('9 of 10: passed', $res['round']['finished'] && $res['round']['passed'] && $res['round']['right'] === 9, json_encode($res['round']));
$check('a passed queue offers no more rounds', !in_array($P, array_column(kop_volpr_status($alex_id)['queues'], 'key'), true)
    && strpos($throws(function () use ($alex, $P) { kop_volpr_start($alex, $P); }), 'passed') !== false);
$st = kop_volp_self_status($alex_id);
$check('passing opens self-assign, for that queue only', $st['eligible'] && ($st['via'] ?? '') === 'practice' && array_column($st['queues'], 'key') === array($P),
    json_encode(array_column($st['queues'], 'key')));
$other = array_values(array_diff(array_keys(kop_vol_sources()), array($P)))[0];
$check('...another open queue is refused', strpos($throws(function () use ($alex, $other) { kop_volp_self_assign($alex, $other); }), 'passed a practice') !== false);
$err = '';
try { $mine = kop_volp_self_assign($alex, $P); } catch (Throwable $e) { $err = $e->getMessage(); $mine = null; }
$check('...and a batch from it is made', $mine && kop_volp_get($mine['id'])['created_by'] === KOP_VOLP_SELF_CREATED_BY, $err);
if ($mine) kop_volp_set_status($mine['id'], 'closed');
$check('practice still never touched the recommendations', (int) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_volunteer_recs')->fetchColumn() === $recs_before);
// Once there is a real record, it decides: 10 checked calls agreeing 5 times closes self-assign again.
for ($i = 0; $i < 10; $i++) {
    $wpdb->insert(kop_vol_recs_table(), array('volunteer_id' => $alex_id, 'source' => 'news', 'item_key' => "practest-$i", 'title' => "Practice test $i",
        'verdict' => 'approve', 'note' => '', 'created_at' => $now, 'updated_at' => $now, 'outcome' => $i < 5 ? 'approve' : 'reject', 'outcome_by' => 'admin', 'outcome_at' => $now));
}
$st = kop_volp_self_status($alex_id);
$check('a real record of 10 at 50% outweighs the practice round', !$st['eligible'] && !isset($st['via']), json_encode(array($st['decided'], $st['agreed'])));
$wpdb->query('DELETE FROM ' . kop_vol_recs_table() . " WHERE item_key LIKE 'practest-%'");
$pdo->exec("DELETE FROM wpdl_kop_review_log WHERE id > $log_from");

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

echo "\nStalled and finished batches\n";
$ago = function ($days) { return gmdate('Y-m-d H:i:s', time() - (int) round($days * 86400)); };
$age = function ($pid, $days) use ($wpdb, $ago) {
    $wpdb->update(kop_volp_table(), array('created_at' => $ago($days)), array('id' => (int) $pid));
    $keys = array_column(kop_volp_item_rows($pid), 'item_key');
    foreach ($keys as $k) $wpdb->update(kop_vol_recs_table(), array('updated_at' => $ago($days)), array('item_key' => (string) $k, 'source' => kop_volp_get($pid)['source']));
};
$sweep_ids = function (array $res) { $o = array(); foreach ($res as $r) $o[$r['id']] = $r; return $o; };
// Stalled: Sam answers 1 of 4, then nothing for 4 days.
list($ps) = kop_volp_create(array('source' => $Q, 'size' => 4, 'volunteer_id' => $sam_id));
$ks = $keys_of($ps);
kop_vol_recommend($sam, $Q, $ks[0], 'approve', '');
$age($ps, 4);
// Waiting on us: Alex asked a question nobody has answered yet.
list($pw) = kop_volp_create(array('source' => $Q, 'size' => 3, 'volunteer_id' => $alex_id));
$age($pw, 4);
$wpdb->insert(kop_volm_table(), array('volunteer_id' => $alex_id, 'project_id' => $pw, 'sender' => 'vol', 'body' => 'Is a missed meal a yes?', 'created_at' => $ago(4)));
// Finished two days ago, and finished an hour ago.
list($pf) = kop_volp_create(array('source' => $Q, 'size' => 2, 'volunteer_id' => $sam_id));
foreach ($keys_of($pf) as $k) kop_vol_recommend($sam, $Q, $k, 'reject', '');
$age($pf, 2);
list($pn) = kop_volp_create(array('source' => $Q, 'size' => 2, 'volunteer_id' => $sam_id));
foreach ($keys_of($pn) as $k) kop_vol_recommend($sam, $Q, $k, 'reject', '');
$age($pn, 1 / 24);
// Untouched for a day only.
list($pq) = kop_volp_create(array('source' => $Q, 'size' => 2, 'volunteer_id' => $alex_id));
$age($pq, 1);
$swept = $sweep_ids(kop_volp_sweep());
$PS = kop_volp_get($ps);
$check('a batch quiet for 3+ days closes as handed back', $PS['status'] === 'closed' && $PS['closed_why'] === 'stalled' && ($swept[$ps]['released'] ?? 0) === 3, json_encode($swept[$ps] ?? null));
$check('...keeping only the answered item', $keys_of($ps) === array($ks[0]) && kop_volp_released_count($ps) === 3);
$check('...and Sam\'s call on it still waits for the admin', kop_volp_progress($PS)['open'] === 1);
$check('the handed-back items are free for the next project', !array_intersect(array_slice($ks, 1), array_keys(kop_volp_claimed_keys($Q))));
list($pnext) = kop_volp_create(array('source' => $Q, 'size' => 3, 'volunteer_id' => $alex_id));
$check('...and a new project takes them', !array_diff(array_slice($ks, 1), $keys_of($pnext)), json_encode($keys_of($pnext)));
$check('Sam\'s link says it was handed back', strpos($throws(function () use ($sam_id, $ps) { kop_volp_items($sam_id, $ps, 1); }), 'handed back') !== false);
$check('...and his page lists it to explain a link', in_array($ps, array_column(kop_volp_set_aside_for($sam_id), 'id'), true));
$check('reopening does not take the items back', kop_volp_released_count($ps) === 3 && (kop_volp_set_status($ps, 'open') || true) && $keys_of($ps) === array($ks[0]));
kop_volp_set_status($ps, 'closed');
$check('a batch waiting on our answer is never handed back', kop_volp_get($pw)['status'] === 'open' && !isset($swept[$pw]));
$check('a batch finished two days ago closes as finished', kop_volp_get($pf)['status'] === 'closed' && kop_volp_get($pf)['closed_why'] === 'finished');
$check('one finished an hour ago stays open a day', kop_volp_get($pn)['status'] === 'open');
$check('one untouched for a day stays open', kop_volp_get($pq)['status'] === 'open');
$check('an admin close has no reason', (kop_volp_set_status($pq, 'closed') || true) && kop_volp_get($pq)['closed_why'] === '');
foreach (array($pw, $pn, $pnext) as $x) kop_volp_set_status($x, 'closed');
$wpdb->query('DELETE FROM ' . kop_volm_table() . ' WHERE project_id = ' . (int) $pw);

echo "\nBrief FAQ\n";
unset($GLOBALS['kop_test_options']['kop_volf'], $GLOBALS['kop_test_options']['kop_volf_seeded']);
$faq = kop_volf_list($Q);
$check('the drafts from the Not sure notes are added', count($faq) === count(kop_volf_seed()[$Q]) && !array_filter(array_column($faq, 'live')));
$check('every draft says what it rests on', !array_filter($faq, function ($e) { return trim((string) $e['from']) === ''; }));
$check('a seed answer names no volunteer to volunteers', !array_filter($faq, function ($e) { return preg_match('/greenfionn|mission_eris|Prestigious|Seelie|awfully/i', $e['q'] . $e['a']); }));
$check('the answers keep to the brief\'s own words (recommend, not decide)', !array_filter($faq, function ($e) { return stripos($e['a'], 'recommend') === false && stripos($e['a'], 'Not sure') === false; }));
list($pfaq) = kop_volp_create(array('source' => $Q, 'size' => 1, 'volunteer_id' => $sam_id));
$check('drafts never reach the brief', kop_volp_public(kop_volp_get($pfaq), $sam_id)['faq'] === array());
$first = $faq[0];
$res = kop_volf_admin_post('faq_save', array('kop_volf_source' => $Q, 'kop_volf_id' => $first['id'], 'kop_volf_q' => $first['q'], 'kop_volf_a' => $first['a'] . ' Edited.', 'kop_volf_live' => '1'));
$pub = kop_volp_public(kop_volp_get($pfaq), $sam_id)['faq'];
$check('Publish puts it on every brief of the queue', $res[1] === '' && count($pub) === 1 && substr($pub[0]['a'], -7) === 'Edited.', json_encode($pub));
$check('the brief carries only the question and the answer', array_keys($pub[0]) === array('q', 'a'));
$check('another queue\'s brief does not show it', kop_volf_live('scraper-finds') === array());
$check('a research brief has none', kop_volp_public(kop_volp_get($r_any = kop_volp_create_research(array('source' => 'research:custom', 'questions' => "Who ran it?", 'volunteer_id' => $sam_id))[0]), $sam_id)['faq'] === array());
kop_volp_set_status($r_any, 'closed');
kop_volf_admin_post('faq_save', array('kop_volf_source' => $Q, 'kop_volf_id' => $first['id'], 'kop_volf_q' => $first['q'], 'kop_volf_a' => $first['a'], 'kop_volf_live' => '0'));
$check('Unpublish takes it off again', kop_volf_live($Q) === array());
kop_volf_admin_post('faq_delete', array('kop_volf_source' => $Q, 'kop_volf_id' => $faq[1]['id']));
$check('a deleted seed stays deleted', !in_array($faq[1]['id'], array_column(kop_volf_list($Q), 'id'), true) && count(kop_volf_list($Q)) === count($faq) - 1);
$new = kop_volf_save($Q, '', array('q' => 'Is a missed meal a yes?', 'a' => 'Recommend approve when it was a punishment.', 'live' => true));
$check('an admin can add their own', in_array($new, array_column(kop_volf_list($Q), 'id'), true) && count(kop_volf_live($Q)) === 1);
$check('a question needs its answer', $throws(function () use ($Q) { kop_volf_save($Q, '', array('q' => 'Half', 'a' => '')); }) !== '');
kop_volp_set_status($pfaq, 'closed');

echo "\nSocial media roundup\n";
$at = function ($days) { return gmdate('Y-m-d H:i:s', time() - (int) round($days * 86400)); };
$log = function ($source, $action, $key, $days, $undone = false) use ($wpdb, $at) {
    $wpdb->insert(kop_rinbox_log_table(), array('source' => $source, 'item_key' => (string) $key, 'title' => "t $key", 'action' => $action, 'style' => 'approve',
        'user_login' => 'owner', 'created_at' => $at($days), 'undone_at' => $undone ? $at($days - 0.1) : null));
};
$one = function ($sql) use ($pdo) { return $pdo->query($sql)->fetchColumn(); };
$cl = $one("SELECT r.id FROM facility_closure_reports r JOIN facilities_v2 f ON f.id = r.facility_id WHERE r.status = 'applied' ORDER BY r.id DESC LIMIT 1");
$ls = $one("SELECT id FROM lawsuits WHERE publication_status = 'published' ORDER BY id DESC LIMIT 1");
$ls_old = $one("SELECT id FROM lawsuits WHERE publication_status = 'published' ORDER BY id DESC LIMIT 1 OFFSET 1");
$bill = $one("SELECT id FROM legislation WHERE publication_status = 'published' ORDER BY id DESC LIMIT 1");
$hs = $one("SELECT s.highlight_id FROM inspection_highlight_summaries s JOIN inspection_highlights h ON h.id = s.highlight_id JOIN inspection_facilities f ON f.id = h.facility_id
    WHERE s.status = 'approved' AND h.status = 'approved' ORDER BY s.highlight_id DESC LIMIT 1");
$feat = $one("SELECT f.news_id FROM news_featured f JOIN news_submissions n ON n.id = f.news_id WHERE n.status IN ('approved', 'published') ORDER BY f.news_id DESC LIMIT 1");
// Only news makes a card: give these their own recent dates, and keep one old closure approved this week.
$recent = gmdate('Y-m-d', time() - 10 * 86400);
// The copy's own programs and documents are old, so the site-update cards count only what the test adds.
$pdo->exec("UPDATE facilities_v2 SET created_at = '2000-01-01 00:00:00'");
$pdo->exec("UPDATE wpdl_posts SET post_date_gmt = '2000-01-01 00:00:00' WHERE post_type = 'attachment'");
$pdo->exec('CREATE TABLE IF NOT EXISTS record_status_proposals (id INTEGER PRIMARY KEY, kind TEXT, record_id INTEGER, changes TEXT, change_hash TEXT, quote TEXT,
    quote_found INTEGER DEFAULT 0, source_url TEXT, source_label TEXT, status TEXT, previous TEXT, reviewed_by TEXT, reviewed_at TEXT, created_at TEXT)');
$cl_old = $one("SELECT r.id FROM facility_closure_reports r JOIN facilities_v2 f ON f.id = r.facility_id WHERE r.status = 'applied' AND r.id <> " . (int) $cl . " ORDER BY r.id DESC LIMIT 1");
$pdo->exec("UPDATE facility_closure_reports SET closure_date = '$recent' WHERE id = " . (int) $cl);
$pdo->exec("UPDATE facility_closure_reports SET closure_date = '1998-09' WHERE id = " . (int) $cl_old);
$pdo->exec("UPDATE lawsuits SET filing_date = '$recent' WHERE id = " . (int) $ls);
$pdo->exec("UPDATE legislation SET last_action_date = '$recent' WHERE id = " . (int) $bill);
$pdo->exec("UPDATE inspection_highlights SET finding_date = '$recent' WHERE id = " . (int) $hs);
$pdo->exec("UPDATE news_submissions SET publication_date = '$recent' WHERE id = " . (int) $feat);
$log('closure', 'apply', $cl, 2);
$log('closure', 'apply', $cl_old, 1);             // approved this week, closed in 1998
$log('lawsuit', 'approve', $ls, 3);
$log('lawsuit', 'approve', $ls_old, 10);           // the week before
$log('legislation', 'approve', $bill, 1);
$log('legislation', 'approve', $bill + 100000, 1); // gone
$log('highlight-summaries', 'approved', $hs, 2);
$log('featured-nominations', 'feature', $feat, 4);
$log('news', 'approve', 'n1', 1);
$log('news', 'approve', 'n2', 2);
$log('news', 'approve', 'n3', 2, true);           // undone
$log('drive-docs', 'auto', 'd1', 1);              // bulk work
$from = $at(7);
$to = $at(0);
$cards = kop_vsoc_cards($from, $to);
$angles = array_column($cards, 'angle');
$check('one card per item worth a post, in angle order, then the site updates', array_slice($angles, 0, 5) === array('closure', 'lawsuit', 'bill', 'finding', 'reporting')
    && in_array('records', $angles, true) && end($angles) === 'numbers', json_encode($angles));
$byk = array_column($cards, null, 'angle');
$check('nothing from the week before, nothing gone, no bulk work', !in_array('lawsuit:' . $ls_old, array_column($cards, 'key'), true)
    && !preg_grep('/^(news|drive)/', array_column($cards, 'key')), json_encode(array_column($cards, 'key')));
$old_name = kop_vsoc_facility($one('SELECT facility_id FROM facility_closure_reports WHERE id = ' . (int) $cl_old))[0];
$check('an old closure approved this week is a record brought up to date, not news', strpos($byk['records']['summary'], 'Now recorded as closed: ' . $old_name) === 0
    && strpos($byk['records']['fact'], '1 program now recorded as closed') !== false, json_encode($byk['records']));
$check('every card has the fact and a public page', !array_filter($cards, function ($c) { return trim($c['fact']) === '' || strpos($c['url'], 'https://example.test/') !== 0; }), json_encode(array_column($cards, 'url')));
$check('no card links an admin screen', !array_filter($cards, function ($c) { return preg_match('#wp-admin|admin-ajax|nonce#', json_encode($c)); }));
$sum = $one("SELECT summary FROM inspection_highlight_summaries WHERE highlight_id = " . (int) $hs);
$check('a finding carries its approved plain summary and the state\'s words', $byk['finding']['summary'] === trim($sum) && $byk['finding']['quote'] !== ''
    && strpos($byk['finding']['url'], '#finding-' . $hs) !== false);
$check('a closure links the facility page', strpos($byk['closure']['url'], 'example.test/') !== false && strpos($byk['closure']['fact'], 'has closed') !== false, $byk['closure']['url']);
$check('the week in numbers counts, leaving out what was undone', strpos($byk['numbers']['summary'], '2 news stories added') !== false, $byk['numbers']['summary']);
$check('an item from more than two months ago makes no card, however recently approved', !in_array('closure:' . $cl_old, array_column($cards, 'key'), true));
$check('a date reads as its last day; no date is not recent', kop_vsoc_recent(gmdate('Y-m'), $to) && !kop_vsoc_recent('2014', $to) && !kop_vsoc_recent('', $to)
    && !kop_vsoc_recent(gmdate('Y-m-d', time() - 70 * 86400), $to) && kop_vsoc_recent(gmdate('Y-m-d', time() - 50 * 86400), $to));
$check('a quiet week makes no cards', kop_vsoc_cards($at(40), $at(33)) === array());

// Three weeks back: news, a page of ours, and the essential-reporting story approved as news too.
$news_fac = $one("SELECT n.id FROM news_submissions n JOIN news_facility_links l ON l.news_id = n.id JOIN facilities_v2 f ON f.id = l.facility_id
    WHERE n.status = 'approved' AND n.article_url LIKE 'https://%' AND n.id <> " . (int) $feat . " ORDER BY n.id DESC LIMIT 1");
$news_bare = $one("SELECT id FROM news_submissions WHERE status = 'approved' AND article_url LIKE 'https://%' AND id NOT IN (SELECT news_id FROM news_facility_links) AND id <> " . (int) $feat . " ORDER BY id DESC LIMIT 1");
$news_old = $one("SELECT id FROM news_submissions WHERE status = 'approved' AND article_url LIKE 'https://%' AND id NOT IN (" . (int) $feat . ', ' . (int) $news_fac . ', ' . (int) $news_bare . ") ORDER BY id DESC LIMIT 1");
$page = $one("SELECT ID FROM wpdl_posts WHERE post_type = 'page' AND post_status = 'publish' AND post_title <> '' AND post_name <> '' ORDER BY ID DESC LIMIT 1");
// The same story twice: once in news_fac's story group, once with the same headline words as news_bare.
$news_grp = $one("SELECT id FROM news_submissions WHERE status = 'approved' AND article_url LIKE 'https://%' AND id NOT IN (" . implode(',', array_map('intval', array($feat, $news_fac, $news_bare, $news_old))) . ") ORDER BY id DESC LIMIT 1");
$news_same = $one("SELECT id FROM news_submissions WHERE status = 'approved' AND article_url LIKE 'https://%' AND id NOT IN (SELECT news_id FROM news_facility_links) AND id NOT IN ("
    . implode(',', array_map('intval', array($feat, $news_fac, $news_bare, $news_old, $news_grp))) . ") ORDER BY id DESC LIMIT 1");
$pdo->exec("UPDATE news_submissions SET publication_date = '$recent' WHERE id IN (" . implode(',', array_map('intval', array($news_fac, $news_bare, $news_grp, $news_same))) . ')');
$pdo->exec("UPDATE news_submissions SET publication_date = '2019-05-02' WHERE id = " . (int) $news_old);
$pdo->exec("UPDATE news_submissions SET article_title = '\$4.5 million settlement reached after a death at Waycross youth detention center', story_group_id = " . (int) $news_fac . " WHERE id = " . (int) $news_fac);
$pdo->exec("UPDATE news_submissions SET story_group_id = " . (int) $news_fac . ", publication_name = 'Second Outlet', article_title = 'Unrelated words entirely' WHERE id = " . (int) $news_grp);
$pdo->exec("UPDATE news_submissions SET story_group_id = NULL, publication_name = 'First Paper', article_title = 'The Family of a Teen Who Died in Foster Care Is Suing Maryland, Marriott' WHERE id = " . (int) $news_bare);
$pdo->exec("UPDATE news_submissions SET story_group_id = NULL, publication_name = 'Third Outlet', article_title = 'Mother of teen who died in foster care to sue state of Maryland and Marriott' WHERE id = " . (int) $news_same);
$pdo->exec("DELETE FROM news_facility_links WHERE news_id = " . (int) $news_grp);
$pdo->exec("UPDATE wpdl_posts SET post_date_gmt = '" . $at(21) . "' WHERE ID = " . (int) $page);
$log('news', 'approve', $news_fac, 21);
$log('news', 'approve', $news_grp, 21.5);
$log('news', 'approve', $news_bare, 22);
$log('news', 'approve', $news_same, 22.5);
$log('news', 'approve', $news_old, 22);
$log('news', 'approve', $feat, 23);
$log('featured-nominations', 'feature', $feat, 23);
$log('drafts', 'publish', $page, 21);
// The site that week: two programs added, three documents, one finding, one lawsuit brought up to date.
$new_fac = $pdo->query("SELECT id, name FROM facilities_v2 WHERE name <> '' AND name <> LOWER(name) ORDER BY id DESC LIMIT 2")->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec("UPDATE facilities_v2 SET created_at = '" . $at(21) . "' WHERE id IN (" . (int) $new_fac[0]['id'] . ', ' . (int) $new_fac[1]['id'] . ')');
$docs = $pdo->query("SELECT ID FROM wpdl_posts WHERE post_type = 'attachment' AND post_mime_type = 'application/pdf' ORDER BY ID DESC LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
foreach (array('123-14-003_insp-20250101-unannounced-inspection', 'Woodbury Reports, May 2007 (#153), p. 31', 'A court filing') as $n => $title) {
    $pdo->exec("UPDATE wpdl_posts SET post_date_gmt = '" . $at(21) . "', post_title = " . $pdo->quote($title) . ' WHERE ID = ' . (int) $docs[$n]);
}
$log('inspection-highlights', 'approved', $hs, 21);
$ls_name = $one('SELECT case_name FROM lawsuits WHERE id = ' . (int) $ls);
$pdo->exec("INSERT INTO record_status_proposals (id, kind, record_id, changes, change_hash, status, created_at) VALUES (900001, 'lawsuit', " . (int) $ls . ", '{\"status\":[\"open\",\"settled\"]}', 'x', 'applied', '" . $at(21) . "')");
$log('status_checks', 'apply', 900001, 21);
$cards3 = kop_vsoc_cards($at(27), $at(20));
$keys3 = array_column($cards3, 'key');
$wk = substr($at(27), 0, 10);
$check('one card per story, after the essential reporting and new pages, then the site updates',
    $keys3 === array('reporting:' . $feat, 'page:' . $page, 'news:' . $news_fac, 'news:' . $news_bare, 'directory:' . $wk, 'records:' . $wk, 'documents:' . $wk, 'findings:' . $wk, 'numbers:' . $wk), json_encode($keys3));
$by3 = array_column($cards3, null, 'key');
$check('a story links the facility page it is linked to, else the story itself', strpos($by3['news:' . $news_fac]['url'], 'https://example.test/facility/') === 0
    && strpos($by3['news:' . $news_bare]['url'], 'https://example.test/') !== 0 && strpos($by3['news:' . $news_bare]['url_label'], 'The story') === 0, json_encode(array_column($cards3, 'url')));
$check('a rolled-up story names the other outlets', substr($by3['news:' . $news_fac]['summary'], -30) === 'Also covered by Second Outlet.'
    && substr($by3['news:' . $news_bare]['summary'], -29) === 'Also covered by Third Outlet.', $by3['news:' . $news_bare]['summary']);
$check('the same story told in other words is the same story; two stories about detention centres are not',
    kop_vsoc_same_story('Children’s Home of Kingston to close, officials say', "Children's Home of Kingston plans to close programs in January")
    && !kop_vsoc_same_story('Juveniles assault staff and seize keys at Cabarrus Juvenile Detention Center', "\$4.5 million settlement reached after teen's death at Waycross youth detention center"));
$check('a page of ours links its page', strpos($by3['page:' . $page]['url'], 'https://example.test/') === 0 && strpos($by3['page:' . $page]['fact'], 'New on our site: ') === 0);
$check('programs added to the directory are named', $by3['directory:' . $wk]['fact'] === '2 programs added to our directory.'
    && strpos($by3['directory:' . $wk]['summary'], $new_fac[0]['name']) !== false && strpos($by3['directory:' . $wk]['url'], 'view=location') !== false, json_encode($by3['directory:' . $wk]));
$check('a lawsuit brought up to date is named with its new status', strpos($by3['records:' . $wk]['summary'], $ls_name . ' (now settled)') !== false
    && strpos($by3['records:' . $wk]['url'], '/lawsuits/') !== false, $by3['records:' . $wk]['summary']);
$check('documents added say what they are', $by3['documents:' . $wk]['fact'] === '3 documents added to our library.'
    && $by3['documents:' . $wk]['summary'] === 'Among them: 1 state inspection report, 1 Woodbury Reports page and 1 other.', $by3['documents:' . $wk]['summary']);
$check('findings added name the program', strpos($by3['findings:' . $wk]['fact'], '1 serious finding from state inspection reports added, at 1 program in 1 state.') === 0, $by3['findings:' . $wk]['fact']);
$check('the last card counts the rest of the week\'s work, old items too', strpos($by3['numbers:' . $wk]['summary'], '6 news stories added') !== false
    && strpos($by3['numbers:' . $wk]['summary'], '1 new page published') !== false && strpos($by3['numbers:' . $wk]['fact'], 'Also this week: ') === 0, $by3['numbers:' . $wk]['summary']);
$log('closure', 'apply', $cl_old, 30);
$cards4 = kop_vsoc_cards($at(31), $at(28));
$check('a week of only old items still says what changed on the site', array_column($cards4, 'angle') === array('records')
    && $cards4[0]['fact'] === 'Records brought up to date: 1 program now recorded as closed.', json_encode($cards4));

$check('a roundup is always one person\'s', strpos($throws(function () { kop_vsoc_make_roundup(0); }), 'Pick who') !== false);
list($ps1) = kop_vsoc_make_roundup($sam_id, 7);
$PS1 = kop_volp_get($ps1);
$check('the roundup is a social project of the cards', $PS1['source'] === KOP_VSOC_SOURCE && count($keys_of($ps1)) === count($cards) && (int) $PS1['volunteer_id'] === $sam_id);
$check('Sam sees it, Alex does not', in_array($ps1, $ids($sam_id), true) && !in_array($ps1, $ids($alex_id), true));
$res = kop_volp_items($sam_id, $ps1, 1);
$it = $res['items'][0];
$check('the page gets a social card: the idea, no draft yet', $res['project']['kind'] === 'social' && $it['angle'] === 'closure' && $it['fact'] !== '' && $it['post'] === null
    && $res['project']['caption_max'] === KOP_VSOC_CAPTION_MAX && $res['project']['faq'] === array());
$check('the message says nothing is posted before approval', strpos(kop_volp_message($PS1, 'https://x.test'), 'nothing is posted until I approve it') !== false);
$check('Alex cannot draft on Sam\'s ideas', $throws(function () use ($alex, $ps1, $it) { kop_vsoc_save($alex, $ps1, $it['key'], 'take'); }) !== '');
$check('Taking this starts a draft', kop_vsoc_save($sam, $ps1, $it['key'], 'take')['status'] === 'taken');
$check('no caption, not sent', strpos($throws(function () use ($sam, $ps1, $it) { kop_vsoc_save($sam, $ps1, $it['key'], 'ready'); }), 'caption') !== false);
$check('no picture of theirs or ours, not sent', strpos($throws(function () use ($sam, $ps1, $it) { kop_vsoc_save($sam, $ps1, $it['key'], 'ready', array('caption' => 'Closed.')); }), 'picture') !== false);
$check('a picture link must be a link', $throws(function () use ($sam, $ps1, $it) { kop_vsoc_save($sam, $ps1, $it['key'], 'draft', array('image_url' => 'javascript:alert(1)')); }) !== '');
$sent = kop_vsoc_save($sam, $ps1, $it['key'], 'ready', array('caption' => 'It closed.', 'image_url' => 'https://www.canva.com/design/x'));
$check('Send for review', $sent['status'] === 'ready' && $sent['caption'] === 'It closed.');
$prog = kop_volp_progress($PS1);
$check('it waits for the admin and counts as done for Sam', $prog['open'] === 1 && $prog['done'] === 1 && kop_volp_public($PS1, $sam_id)['done'] === 1, json_encode($prog));
$check('and leaves his list', kop_volp_items($sam_id, $ps1, 1)['items'][0]['key'] !== $it['key']);
$check('he can take it back', kop_vsoc_save($sam, $ps1, $it['key'], 'back')['status'] === 'taken' && kop_vsoc_save($sam, $ps1, $it['key'], 'ready')['status'] === 'ready');
$post = $wpdb->get_row('SELECT * FROM ' . kop_vsoc_table() . " WHERE project_id = $ps1 AND item_key = '" . $it['key'] . "'", ARRAY_A);
$check('Send back needs a note', strpos($throws(function () use ($post) { kop_vsoc_decide($post['id'], 'sent_back', ''); }), 'what to change') !== false);
kop_volp_set_status($ps1, 'closed', 'finished');
kop_vsoc_decide($post['id'], 'sent_back', 'Name the state in the first line.');
$back = kop_volp_items($sam_id, $ps1, 1)['items'][0];
$check('a sent-back post opens the batch again and comes back with the note', kop_volp_get($ps1)['status'] === 'open' && $back['key'] === $it['key']
    && $back['post']['status'] === 'sent_back' && $back['post']['owner_note'] === 'Name the state in the first line.');
$check('a draft saved while sent back stays sent back', kop_vsoc_save($sam, $ps1, $it['key'], 'draft', array('caption' => 'Utah: it closed.'))['status'] === 'sent_back');
kop_vsoc_save($sam, $ps1, $it['key'], 'ready');
kop_vsoc_decide($post['id'], 'approved');
$check('approved is final for the volunteer', strpos($throws(function () use ($sam, $ps1, $it) { kop_vsoc_save($sam, $ps1, $it['key'], 'draft', array('caption' => 'x')); }), 'approved') !== false
    && kop_volp_progress(kop_volp_get($ps1))['decided'] === 1);
// An uploaded picture instead of a link: checked by its bytes, stripped of its metadata, private until approved, then into the media library.
if (!function_exists('sanitize_file_name')) { function sanitize_file_name($n) { return preg_replace('/[^A-Za-z0-9._-]/', '', $n); } }
if (!function_exists('wp_upload_bits')) {
    function wp_upload_bits($name, $dep, $bits) { $f = sys_get_temp_dir() . '/kop-uploads/' . $name; @mkdir(dirname($f), 0777, true); file_put_contents($f, $bits); return array('file' => $f, 'error' => false); }
}
if (!function_exists('wp_insert_attachment')) { function wp_insert_attachment($a, $file) { $GLOBALS['kop_test_attached'][] = array($a, $file); return 9000 + count($GLOBALS['kop_test_attached']); } }
if (!function_exists('wp_update_attachment_metadata')) { function wp_update_attachment_metadata($id, $m) { return true; } }
if (!function_exists('update_post_meta')) { function update_post_meta($id, $k, $v) { return true; } }
$idea2 = $keys_of($ps1)[1];
$png = sys_get_temp_dir() . '/kop-vsoc-test.png';
$im = imagecreatetruecolor(40, 30);
imagepng($im, $png);
$jpg = sys_get_temp_dir() . '/kop-vsoc-test.jpg';
imagejpeg($im, $jpg);
// A JPEG carrying a comment block stands in for camera metadata: re-encoding must drop it.
$raw = file_get_contents($jpg);
file_put_contents($jpg, substr($raw, 0, 2) . "\xFF\xFE\x00\x12GPS 40.7N 74.0W!" . substr($raw, 2));
$fake = sys_get_temp_dir() . '/kop-vsoc-test-fake.png';
file_put_contents($fake, '<?php echo "not a picture"; ?>');
$check('a file that is not a picture is refused, whatever its name', strpos($throws(function () use ($sam, $ps1, $idea2, $fake) { kop_vsoc_store_upload($sam, $ps1, $idea2, $fake); }), 'not a picture') !== false);
$GLOBALS['kop_test_filters_vsoc'] = true;
$check('a picture over the cap is refused', strpos($throws(function () use ($sam, $ps1, $idea2, $png) { kop_vsoc_store_upload($sam, $ps1, $idea2, $png, 11 * 1048576); }), 'too big') !== false);
$check('Alex cannot upload to Sam\'s idea', $throws(function () use ($alex, $ps1, $idea2, $png) { kop_vsoc_store_upload($alex, $ps1, $idea2, $png); }) !== '');
$up = kop_vsoc_store_upload($sam, $ps1, $idea2, $jpg);
$row2 = kop_vsoc_get_post($ps1, $idea2, $sam_id);
$kept = kop_vsoc_upload_path($row2);
$check('an upload is kept for the idea and says so', $up['uploaded'] && $kept !== '' && $row2['status'] === 'taken');
$check('...re-encoded, its metadata gone', getimagesize($kept)[2] === IMAGETYPE_JPEG && strpos(file_get_contents($kept), 'GPS 40.7N') === false);
$check('...in a folder the web cannot open', strpos($kept, '/kop-private/social/') !== false && is_file(dirname(dirname($kept)) . '/.htaccess')
    && strpos(file_get_contents(dirname(dirname($kept)) . '/.htaccess'), 'Require all denied') !== false);
kop_vsoc_store_upload($sam, $ps1, $idea2, $png);
$row2 = kop_vsoc_get_post($ps1, $idea2, $sam_id);
$check('a second upload replaces the first', !is_file($kept) && substr(kop_vsoc_upload_path($row2), -4) === '.png');
$check('an upload is a picture for Send for review', kop_vsoc_save($sam, $ps1, $idea2, 'ready', array('caption' => 'A lawsuit.'))['status'] === 'ready');
$waiting = kop_vsoc_upload_path($row2);
kop_vsoc_decide($row2['id'], 'approved');
$row2 = kop_vsoc_get_post($ps1, $idea2, $sam_id);
$check('approving puts it in the media library and out of the private folder', (int) $row2['attachment_id'] > 0 && $row2['image_file'] === '' && !is_file($waiting)
    && end($GLOBALS['kop_test_attached'])[0]['post_mime_type'] === 'image/png');
$n_att = count($GLOBALS['kop_test_attached']);
kop_vsoc_decide($row2['id'], 'ready');
kop_vsoc_decide($row2['id'], 'approved');
$check('approving again does not copy it twice', count($GLOBALS['kop_test_attached']) === $n_att);
$check('an approved post takes no new picture', strpos($throws(function () use ($sam, $ps1, $idea2, $png) { kop_vsoc_store_upload($sam, $ps1, $idea2, $png); }), 'approved') !== false);
foreach (array($png, $jpg, $fake) as $f) @unlink($f);

$numbers = 'numbers:' . substr($from, 0, 10);
$check('Pass on this one counts as done', kop_vsoc_save($sam, $ps1, $numbers, 'pass')['status'] === 'passed' && kop_volp_public(kop_volp_get($ps1), $sam_id)['done'] === 3);
// The sweep: four quiet days hand the untouched ideas back and keep the ones he worked on.
$wpdb->update(kop_volp_table(), array('created_at' => $at(5)), array('id' => $ps1));
$wpdb->query('UPDATE ' . kop_vsoc_table() . " SET updated_at = '" . $at(4) . "' WHERE project_id = $ps1");
$swept = $sweep_ids(kop_volp_sweep());
$check('a quiet roundup hands back the ideas nobody took', ($swept[$ps1]['released'] ?? 0) === count($cards) - 3 && count($keys_of($ps1)) === 3, json_encode($swept[$ps1] ?? null));

// Monday's run.
unset($GLOBALS['kop_test_options']['kop_vsoc_designer'], $GLOBALS['kop_test_options']['kop_vsoc_last_week']);
$check('with nobody picked, Monday makes nothing', kop_vsoc_weekly_run() === null);
kop_vsoc_admin_post('social_designer', array('kop_vsoc_designer' => $alex_id));
$made = kop_vsoc_weekly_run();
$check('with someone picked, Monday makes their roundup', $made && (int) kop_volp_get($made[0])['volunteer_id'] === $alex_id && kop_volp_get($made[0])['created_by'] === 'weekly roundup');
$check('once a week', kop_vsoc_weekly_run() === null);
kop_volp_set_status($made[0], 'closed');
$wpdb->query('DELETE FROM ' . kop_rinbox_log_table() . " WHERE user_login = 'owner'");

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

echo "\nHanding back and stopping\n";
list($quit_id, $quit_url) = kop_vol_create('Quinn Example');
$quinn = kop_vol_by_token(substr($quit_url, -48));
$check('a new volunteer has not started yet', !kop_vol_has_started($quinn['id']));
list($hb) = kop_volp_create(array('source' => $Q, 'size' => 3, 'volunteer_id' => $quinn['id']));
list($hb2) = kop_volp_create(array('source' => $Q, 'size' => 2, 'volunteer_id' => $quinn['id']));
$hb_keys = $keys_of($hb);
kop_vol_recommend($quinn, $Q, $hb_keys[0], 'approve', 'A child was hurt.');
$check('...and has once they make a call', kop_vol_has_started($quinn['id']));
$check('someone else\'s batch cannot be handed back', strpos($throws(function () use ($alex, $hb) { kop_volp_leave($alex, $hb); }), 'not open in your name') !== false);
$res = kop_volp_leave($quinn, $hb);
$row = kop_volp_get($hb);
$check('handing a batch back closes it, the unanswered go back, the answer stays', $row['status'] === 'closed' && $row['closed_why'] === 'handed-back'
    && kop_volp_released_count($hb) === 2 && (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_volunteer_recs WHERE volunteer_id = {$quinn['id']}")->fetchColumn() === 1, json_encode($res));
$check('...and the link still works', kop_vol_by_token(substr($quit_url, -48)) !== null && kop_volp_get($hb2)['status'] === 'open');
kop_volp_leave($quinn, 0);
$check('stopping turns the link off and hands back every open batch', kop_vol_by_token(substr($quit_url, -48)) === null
    && kop_volp_get($hb2)['status'] === 'closed' && kop_volp_get($hb2)['closed_why'] === 'left' && kop_volp_released_count($hb2) === 2);

echo "\nPhoto projects\n";
// Placing a picture: just enough of WordPress's post functions, on the scratch copy.
if (!function_exists('set_post_thumbnail')) {
    function set_post_thumbnail($post, $att) {
        $GLOBALS['pdo']->exec('DELETE FROM wpdl_postmeta WHERE post_id = ' . (int) $post . " AND meta_key = '_thumbnail_id'");
        $GLOBALS['pdo']->exec('INSERT INTO wpdl_postmeta (post_id, meta_key, meta_value) VALUES (' . (int) $post . ", '_thumbnail_id', '" . (int) $att . "')");
        return true;
    }
    function get_post_thumbnail_id($post) { return (int) $GLOBALS['pdo']->query('SELECT meta_value FROM wpdl_postmeta WHERE post_id = ' . (int) (is_object($post) ? $post->ID : $post) . " AND meta_key = '_thumbnail_id'")->fetchColumn(); }
    function delete_post_thumbnail($post) { $GLOBALS['pdo']->exec('DELETE FROM wpdl_postmeta WHERE post_id = ' . (int) $post . " AND meta_key = '_thumbnail_id'"); return true; }
}
if (!function_exists('has_filter')) { function has_filter($tag, $fn = false) { return false; } }
// wp_update_post() is the stand-in in review-inbox-tests/websites.php, which stores what it is given: no slashes to undo.
if (!function_exists('wp_slash')) { function wp_slash($v) { return $v; } }
if (!function_exists('wp_get_attachment_image_url')) { function wp_get_attachment_image_url($id, $size = '') { return 'https://example.test/photo-' . (int) $id . '.jpg'; } }
if (!function_exists('wp_get_attachment_caption')) { function wp_get_attachment_caption($id) { return (string) $GLOBALS['pdo']->query('SELECT post_excerpt FROM wpdl_posts WHERE ID = ' . (int) $id)->fetchColumn(); } }
$spots = kop_vimg_spots();
$check('every spot in the seed is read, keyed by page and heading', count($spots) >= 20 && isset($spots['reputation-management#header'])
    && isset($spots['new-names-same-programs#sequel-bought-itself-back']), (string) count($spots));
foreach ($spots as $k => $sp) if (kop_vimg_spot_state($sp) !== 'open') echo "    (not open now: $k = " . kop_vimg_spot_state($sp) . ")\n";
$check('a page with a featured image is filled', kop_vimg_spot_state(array('page' => 'history', 'heading' => '')) === 'filled');
$check('a heading followed by a picture is filled', kop_vimg_spot_state(array('page' => 'tti-history-part-one', 'heading' => 'Guided Group Interaction comes home from the war')) === 'filled');
$check('a heading with no picture after it is open', kop_vimg_spot_state(array('page' => 'tti-history-part-one', 'heading' => 'Federal money and the first chains')) === 'open');
$check('a heading the page lacks is not offered', kop_vimg_spot_state(array('page' => 'tti-history-part-one', 'heading' => 'No such heading')) === 'no-heading'
    && kop_vimg_spot_state(array('page' => 'no-such-page', 'heading' => '')) === 'no-page');
list($open_spots) = kop_vimg_open_spots();
list($img_p) = kop_vimg_make_project(0, 'Try the history ones first.');
$IP = kop_volp_get($img_p);
$check('the photo project holds every open spot, for anyone', count(kop_volp_item_rows($img_p)) === count($open_spots) && count($open_spots) > 0 && (int) $IP['volunteer_id'] === 0);
$check('no second project of the same spots', strpos($throws(function () { kop_vimg_make_project(0); }), 'Every spot') !== false);
$pub = kop_volp_public($IP, $sam_id);
$check('it reaches the volunteer page as kind image, with no FAQ', $pub['kind'] === 'image' && $pub['faq'] === array() && in_array($img_p, $ids($sam_id), true));
$its = kop_volp_items($sam_id, $img_p, 2);
$it1 = $its['items'][0];
$check('a spot says what it is about, the page and ideas to search', $it1['source'] === 'image' && $it1['about'] !== '' && $it1['ideas'] && strpos($it1['page_url'], '/' . explode('#', $it1['key'])[0] . '/') !== false);
$k1 = $it1['key'];
$k2 = $its['items'][1]['key'];
$check('licenses: open ones yes, NC / ND / all rights reserved no', kop_vimg_license_ok('CC BY-SA 4.0') && kop_vimg_license_ok('Public domain') && kop_vimg_license_ok('CC0')
    && kop_vimg_license_ok('Pexels License') && !kop_vimg_license_ok('CC BY-NC 2.0') && !kop_vimg_license_ok('CC BY-ND 4.0') && !kop_vimg_license_ok('All rights reserved') && !kop_vimg_license_ok('cool'));
$good = array('page_url' => 'https://www.flickr.com/photos/someone/123', 'file_url' => 'https://live.staticflickr.com/1/123_b.jpg', 'author' => 'foot fingers',
    'license' => 'CC BY 2.0', 'alt' => 'Protesters outside a hotel holding signs');
$check('no page link, not sent', strpos($throws(function () use ($sam, $img_p, $k1) { kop_vimg_save($sam, $img_p, $k1, 'ready', array()); }), 'page') !== false);
$check('outside Commons, the file link is needed', strpos($throws(function () use ($sam, $img_p, $k1, $good) { kop_vimg_save($sam, $img_p, $k1, 'ready', array('file_url' => '') + $good); }), 'file') !== false);
$check('an NC license is refused', strpos($throws(function () use ($sam, $img_p, $k1, $good) { kop_vimg_save($sam, $img_p, $k1, 'ready', array('license' => 'CC BY-NC 2.0') + $good); }), 'cannot use') !== false);
$check('no line for people who cannot see it, not sent', strpos($throws(function () use ($sam, $img_p, $k1, $good) { kop_vimg_save($sam, $img_p, $k1, 'ready', array('alt' => '') + $good); }), 'cannot see') !== false);
$check('a link must be a link', $throws(function () use ($sam, $img_p, $k1) { kop_vimg_save($sam, $img_p, $k1, 'draft', array('page_url' => 'javascript:alert(1)')); }) !== '');
$cm = kop_vimg_save($sam, $img_p, $k1, 'ready', array('page_url' => '', 'file_url' => 'https://upload.wikimedia.org/wikipedia/commons/a/ab/Capitol_Building_Full_View.jpg',
    'author' => 'Noclip', 'license' => 'Public domain', 'alt' => 'The U.S. Capitol'));
$check('a Commons file link alone is enough, and becomes its Commons page', $cm['status'] === 'ready' && $cm['page_url'] === 'https://commons.wikimedia.org/wiki/File:Capitol_Building_Full_View.jpg', $cm['page_url']);
$check('Commons page links come out the same however they are written', kop_vimg_commons_page(kop_vimg_commons_title('https://commons.m.wikimedia.org/wiki/File:Capitol_Building_Full_View.jpg?uselang=de')) === $cm['page_url']);
$check('a sent spot leaves his list', !in_array($k1, array_column(kop_volp_items($sam_id, $img_p, 5)['items'], 'key'), true));
$check('could not find one needs where they looked', strpos($throws(function () use ($sam, $img_p, $k2) { kop_vimg_save($sam, $img_p, $k2, 'not_found', array('note' => '')); }), 'where you looked') !== false);
$check('...and with it, it is saved', kop_vimg_save($sam, $img_p, $k2, 'not_found', array('note' => 'Commons, Openverse: nothing from the 1960s'))['status'] === 'not_found');
$check('he can take a sent one back', kop_vimg_save($sam, $img_p, $k2, 'back')['status'] === 'draft');
$alex_pick = kop_vimg_save($alex, $img_p, $k1, 'ready', $good);
$check('another volunteer can send a pick for the same spot', $alex_pick['status'] === 'ready');
$prog = kop_vimg_progress($IP);
$check('progress: two waiting for the admin', $prog['open'] === 2 && $prog['done'] === 1, json_encode($prog));
$sam_row = $wpdb->get_row('SELECT * FROM ' . kop_vimg_table() . " WHERE project_id = $img_p AND item_key = '$k1' AND volunteer_id = $sam_id", ARRAY_A);
$alex_row = $wpdb->get_row('SELECT * FROM ' . kop_vimg_table() . " WHERE project_id = $img_p AND item_key = '$k1' AND volunteer_id = $alex_id", ARRAY_A);
$check('Send back needs a note', strpos($throws(function () use ($sam_row) { kop_vimg_decide($sam_row['id'], 'sent_back', ''); }), 'what to change') !== false);
kop_vimg_decide($sam_row['id'], 'sent_back', 'Find one from the 2000s.');
$check('sent back: he sees the note, and a draft stays sent back', kop_volp_items($sam_id, $img_p, 1)['items'][0]['pick']['owner_note'] === 'Find one from the 2000s.'
    && kop_vimg_save($sam, $img_p, $k1, 'draft', array('alt' => 'The Capitol at dusk'))['status'] === 'sent_back');
kop_vimg_save($sam, $img_p, $k1, 'ready');
// Already in the media library from the same page: Approve reuses it rather than downloading again.
$pdo->exec("INSERT INTO wpdl_posts (ID, post_title, post_excerpt, post_status, post_type, post_mime_type, post_content) VALUES (990001, 'Capitol', 'Noclip, Public domain, via Wikimedia Commons', 'inherit', 'attachment', 'image/jpeg', '')");
$pdo->exec("INSERT INTO wpdl_postmeta (post_id, meta_key, meta_value) VALUES (990001, '_kop_source_url', 'https://commons.wikimedia.org/wiki/File:Capitol_Building_Full_View.jpg')");
$spot1 = kop_vimg_spot_of($sam_row);
$att1 = kop_vimg_decide($sam_row['id'], 'approved');
$check('Approve reuses the library copy and fills the spot', $att1 === 990001 && kop_vimg_spot_state($spot1) === 'filled', $att1 . ' ' . kop_vimg_spot_state($spot1));
$check('the credit line reads like the earlier rounds', kop_vimg_credit(array('author' => 'Noclip', 'license' => 'Public domain', 'page_url' => $cm['page_url'])) === 'Noclip, Public domain, via Wikimedia Commons');
$check('a second pick for a filled spot cannot be approved', strpos($throws(function () use ($alex_row) { kop_vimg_decide($alex_row['id'], 'approved'); }), 'Another picture') !== false);
$check('...nor changed by its volunteer, and the spot is done for everyone', strpos($throws(function () use ($alex, $img_p, $k1) { kop_vimg_save($alex, $img_p, $k1, 'draft', array('alt' => 'x')); }), 'approved for this spot already') !== false
    && isset(kop_vimg_done_keys($img_p, $alex_id)[$k1]) && isset(kop_vimg_done_keys($img_p, $sam_id)[$k1]));
$check('a filled spot is not offered to a new project', !isset(kop_vimg_open_spots()[0][$k1]));
kop_vimg_decide($sam_row['id'], 'ready');
$check('Undo approve takes it off the page', kop_vimg_spot_state($spot1) === 'open');
kop_vimg_decide($sam_row['id'], 'approved');
// A section spot: the picture goes in as a block right after its heading.
$sec = null;
foreach (kop_volp_item_rows($img_p, true) as $r) { $d = json_decode($r['detail'], true); if ($d['heading'] !== '') { $sec = $r; break; } }
if ($sec) {
    $sk = $sec['item_key'];
    $sp = kop_vimg_save($sam, $img_p, $sk, 'ready', array('page_url' => 'https://commons.wikimedia.org/wiki/File:Charles_and_Betty_Dederich_in_1964.jpg',
        'author' => 'San Francisco Chronicle', 'license' => 'Public domain', 'alt' => 'Charles and Betty Dederich in 1964'));
    $pdo->exec("INSERT INTO wpdl_posts (ID, post_title, post_excerpt, post_status, post_type, post_mime_type, post_content) VALUES (990002, 'Dederich', 'San Francisco Chronicle, Public domain, via Wikimedia Commons', 'inherit', 'attachment', 'image/jpeg', '')");
    $pdo->exec("INSERT INTO wpdl_postmeta (post_id, meta_key, meta_value) VALUES (990002, '_kop_source_url', 'https://commons.wikimedia.org/wiki/File:Charles_and_Betty_Dederich_in_1964.jpg')");
    $row = $wpdb->get_row('SELECT * FROM ' . kop_vimg_table() . " WHERE project_id = $img_p AND item_key = '$sk' AND volunteer_id = $sam_id", ARRAY_A);
    $spot = kop_vimg_spot_of($row);
    kop_vimg_decide($row['id'], 'approved');
    $content = (string) $pdo->query('SELECT post_content FROM wpdl_posts WHERE post_name = ' . $pdo->quote($spot['page']) . " AND post_type = 'page' AND post_status = 'publish'")->fetchColumn();
    $check('a section picture goes in right after its heading, captioned', kop_vimg_spot_state($spot) === 'filled' && substr_count($content, 'wp-image-990002"') === 1
        && strpos($content, 'San Francisco Chronicle, Public domain, via Wikimedia Commons') !== false, $sk);
    kop_vimg_decide($row['id'], 'ready');
    $content = (string) $pdo->query('SELECT post_content FROM wpdl_posts WHERE post_name = ' . $pdo->quote($spot['page']) . " AND post_type = 'page' AND post_status = 'publish'")->fetchColumn();
    $check('...and Undo approve takes the block out again', strpos($content, 'wp-image-990002"') === false && kop_vimg_spot_state($spot) === 'open');
}
$check('the message for a photo project says what to do', strpos(kop_volp_message($IP, 'https://x.test'), 'pictures for our pages') !== false && strpos(kop_volp_message($IP, 'https://x.test'), 'could not find one') !== false);
$before = (int) $pdo->query('SELECT COUNT(*) FROM news_submissions')->fetchColumn();
kop_vimg_save($alex, $img_p, $k2, 'ready', $good);
$check('picture links never go to the review inbox', (int) $pdo->query('SELECT COUNT(*) FROM news_submissions')->fetchColumn() === $before);
$rel = kop_volp_release($IP, 'handed-back');
$check('handing back keeps every spot someone sent a picture for', in_array($k1, array_column(kop_volp_item_rows($img_p), 'item_key'), true)
    && $rel === count($open_spots) - 3, (string) $rel);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
