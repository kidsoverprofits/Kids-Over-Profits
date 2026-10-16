<?php
/**
 * KOP Tools > Directory Editor (inc/directory-editor.php) on a scratch copy of
 * the mirror's company tables: what the directory does with each company
 * (card, too few programs, same name, taken off), then every action and its
 * Undo, each checked to put the tables back exactly: edit and rename (old
 * name kept, old address remembered), take off / put back (the directory
 * feed leaves it out), add a program, set a tie, take one off, move programs
 * to another company (kept as past, the record's Operator line follows),
 * merge two companies (programs, news, filings and investor links move, names
 * join), delete, add a company; Undo refused once a later change touched the
 * rows.
 *
 * The tables are copied out of tmp/prod.sqlite into a temp database and the
 * mirror is never attached, so it cannot be written.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite scripts/test-directory-editor.php [--list]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('list'));
$prod = dirname(__DIR__) . '/tmp/prod.sqlite';
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

$db_path = sys_get_temp_dir() . '/kop-directory-editor-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec('ATTACH DATABASE ' . $copy->quote($prod) . ' AS prod');
foreach (array('facilities_v2', 'wpdl_kop_facility_identity', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_locations',
    'wpdl_kop_operators', 'wpdl_kop_operator_links', 'wpdl_kop_nonprofit_links') as $t) {
    $sql = $copy->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) {
        fwrite(STDERR, "The mirror has no $t (sync it).\n");
        exit(2);
    }
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.$t SELECT * FROM prod.$t");
}
$copy->exec('DETACH DATABASE prod');
// Tables the mirror may lack; the merge and delete must carry their rows too.
$copy->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_business_links (id INTEGER PRIMARY KEY AUTOINCREMENT, state TEXT, doc TEXT, facility_id INTEGER NOT NULL DEFAULT 0,
    operator_id INTEGER NOT NULL DEFAULT 0, created_by TEXT, created_at TEXT)');
$copy->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_investor_links (id INTEGER PRIMARY KEY AUTOINCREMENT, investor_id INTEGER NOT NULL, operator_id INTEGER NOT NULL DEFAULT 0,
    facility_id INTEGER NOT NULL DEFAULT 0, role TEXT NOT NULL DEFAULT \'\', start_year INTEGER NULL, end_year INTEGER NULL, detail TEXT NOT NULL DEFAULT \'\',
    source_url TEXT NOT NULL DEFAULT \'\', created_by TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL DEFAULT \'\')');
$copy->exec('CREATE TABLE wpdl_kop_directory_edits (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, operator_id INTEGER NOT NULL DEFAULT 0,
    other_id INTEGER NOT NULL DEFAULT 0, before_json TEXT NULL, after_json TEXT NULL, note TEXT NOT NULL DEFAULT \'\', done_by TEXT NOT NULL DEFAULT \'\',
    done_at TEXT NOT NULL, undone_by TEXT NOT NULL DEFAULT \'\', undone_at TEXT NULL)');
// On production these columns are generated from json_data; here a trigger fills them.
$copy->exec("CREATE TRIGGER facilities_v2_cols AFTER UPDATE OF json_data ON facilities_v2 BEGIN
    UPDATE facilities_v2 SET name = json_extract(NEW.json_data, '$.identification.name') WHERE id = NEW.id; END");
$copy = null;

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
    if (!is_string($v)) return $v;
    $d = json_decode($v);
    return is_string($d) ? $d : $v;
}, 1);
if (file_exists(dirname(__DIR__) . '/inc/record-field.php')) require_once dirname(__DIR__) . '/inc/record-field.php';
require_once dirname(__DIR__) . '/inc/directory-feed.php';
require_once dirname(__DIR__) . '/inc/directory-editor.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' && !$ok ? "  ($detail)" : '') . "\n";
};
$opts = array('pdo' => $pdo, 'prefix' => 'wpdl_', 'skip_memberships' => true);
// Every table an action touches, as rows (updated_at aside), for the exact-undo checks.
$snapshot = function () use ($pdo) {
    $out = array();
    foreach (array('wpdl_kop_operators' => 'id', 'wpdl_kop_operator_facilities' => 'operator_id, facility_id', 'wpdl_kop_operator_links' => 'operator_id, link_kind, link_id',
        'wpdl_kop_nonprofit_links' => 'rowid', 'wpdl_kop_business_links' => 'id', 'wpdl_kop_investor_links' => 'id', 'facilities_v2' => 'id') as $t => $order) {
        $cols = $t === 'wpdl_kop_operators' ? 'id, unique_name, name, json_data, document_folder_id, legacy_master_id, created_at'
            : ($t === 'facilities_v2' ? 'id, unique_name, json_data' : '*');
        $out[$t] = md5(json_encode($pdo->query("SELECT $cols FROM $t ORDER BY $order")->fetchAll(PDO::FETCH_NUM)));
    }
    return $out;
};
$same = function ($a, $b) {
    $diff = array_keys(array_filter($a, function ($v, $k) use ($b) { return $b[$k] !== $v; }, ARRAY_FILTER_USE_BOTH));
    return array($diff === array(), implode(', ', $diff));
};
$who = 'test';

// ---------------------------------------------------------------------------
echo "-- What the directory does with each company --\n";
$all = kop_dired_companies();
$states = array();
foreach ($all as $c) $states[$c['state']][] = $c;
foreach ($states as $s => $list) printf("  %-7s %d\n", $s, count($list));
if (isset($args['list'])) {
    foreach ($all as $id => $c) printf("  #%-7d %-60s %-7s %d links\n", $id, $c['name'], $c['state'], count($c['links']));
}
$check('every company read', count($all) === (int) $pdo->query('SELECT COUNT(*) FROM wpdl_kop_operators')->fetchColumn());
$shown = $states['shown'] ?? array();
$check('most companies have a card', count($shown) > 10, (string) count($shown));
$few_ok = true;
foreach ($states['few'] ?? array() as $c) if ($c['named'] >= 2) $few_ok = false;
$check('"few" means under two named programs', $few_ok);

// Two companies with programs: A (most programs after the first) and B.
usort($shown, function ($a, $b) { return count($b['links']) - count($a['links']); });
$A = $shown[1];
$B = $shown[2];
$aid = (int) $A['row']['id'];
$bid = (int) $B['row']['id'];
echo "  A = #$aid {$A['name']} (" . count($A['links']) . "), B = #$bid {$B['name']} (" . count($B['links']) . ")\n";
$start = $snapshot();

// ---------------------------------------------------------------------------
echo "\n-- Edit and rename --\n";
list($msg, $log) = kop_dired_edit($aid, array('name' => $A['name'] . ' Holdings', 'founded' => '1999', 'keep_old' => 1,
    'otherNames' => kop_dired_list_text($A['op']['otherNames'] ?? array()), 'websites' => kop_dired_list_text($A['op']['websites'] ?? array())), $who);
$row = kop_dired_op_row($aid);
$op = kop_dired_json($row)['operator'];
$check('renamed: name column and operator.name', $row['name'] === $A['name'] . ' Holdings' && $op['name'] === $A['name'] . ' Holdings', $msg);
$check('old name kept as an other name', in_array($A['name'], (array) $op['otherNames'], true));
$check('founded saved, unchanged lists untouched', $op['founded'] === '1999' && ($op['websites'] ?? null) === ($A['op']['websites'] ?? null));
$check('unique_name (record key) unchanged', $row['unique_name'] === $A['row']['unique_name']);
$check('logged as edit with its fields', kop_dired_log_get($log)['action'] === 'edit' && strpos(kop_dired_log_get($log)['note'], 'name') !== false);
try { kop_dired_edit($aid, array('name' => $B['name']), $who); $check('a name another company has is refused', false); }
catch (RuntimeException $e) { $check('a name another company has is refused', strpos($e->getMessage(), 'Merge') !== false, $e->getMessage()); }
list($none) = kop_dired_edit($aid, array('founded' => '1999'), $who);
$check('saving the same value changes nothing', $none === 'Nothing changed.');
echo '  ' . kop_dired_undo($log, $who, $opts) . "\n";
list($ok, $d) = $same($start, $snapshot());
$check('undo: exactly as before', $ok, $d);
try { kop_dired_undo($log, $who, $opts); $check('undo twice refused', false); }
catch (RuntimeException $e) { $check('undo twice refused', true); }

// ---------------------------------------------------------------------------
echo "\n-- Take off the directory --\n";
list($msg, $log) = kop_dired_set_hidden($aid, true, $who);
$all = kop_dired_companies();
$check('hidden: state "hidden"', $all[$aid]['state'] === 'hidden', $msg);
$project = array('category' => 'companies', 'data' => array('operator' => kop_dired_json(kop_dired_op_row($aid))['operator']));
$check('the directory feed leaves it out', kop_directory_feed_wanted($project) === false);
$project['data']['operator']['directoryHidden'] = false;
$check('...and keeps a company that is not hidden', kop_directory_feed_wanted($project) === true);
list($msg2, $log2) = kop_dired_edit($aid, array('founded' => '2001'), $who);
try { kop_dired_undo($log, $who, $opts); $check('undo refused after a later edit', false); }
catch (RuntimeException $e) { $check('undo refused after a later edit', strpos($e->getMessage(), 'changed since') !== false, $e->getMessage()); }
kop_dired_undo($log2, $who, $opts);
kop_dired_undo($log, $who, $opts);
list($ok, $d) = $same($start, $snapshot());
$check('undo (later first): exactly as before', $ok, $d);
list($msg, $log) = kop_dired_set_hidden($aid, true, $who);
list($msg2, $log2) = kop_dired_set_hidden($aid, false, $who);
$check('put back: state "shown"', kop_dired_companies()[$aid]['state'] === 'shown', $msg2);
kop_dired_undo($log2, $who, $opts);
kop_dired_undo($log, $who, $opts);
list($ok, $d) = $same($start, $snapshot());
$check('hide + show undone: exactly as before', $ok, $d);

// ---------------------------------------------------------------------------
echo "\n-- Programs: add, tie, take off --\n";
$a_fids = kop_dired_link_ids($aid);
$free = (int) $pdo->query('SELECT id FROM facilities_v2 WHERE id NOT IN (SELECT facility_id FROM wpdl_kop_operator_facilities WHERE operator_id = ' . $aid . ') AND name <> \'\' ORDER BY id LIMIT 1')->fetchColumn();
list($msg, $log) = kop_dired_link_add($aid, $free, 'past', $who);
$l = kop_dired_link($aid, $free);
$check('added as past, at the end', $l && $l['relationship'] === 'past' && (int) $l['sort_order'] >= max(array_map(function ($x) { return (int) $x['sort_order']; }, kop_dired_links($aid))), $msg);
try { kop_dired_link_add($aid, $free, 'current', $who); $check('the same program twice is refused', false); }
catch (RuntimeException $e) { $check('the same program twice is refused', true); }
try { kop_dired_link_add($aid, 999999999, 'current', $who); $check('a record not on file is refused', false); }
catch (RuntimeException $e) { $check('a record not on file is refused', true); }
list($msg2, $log2) = kop_dired_link_relationship($aid, $free, 'current', $who);
$check('tie set to current', kop_dired_link($aid, $free)['relationship'] === 'current', $msg2);
kop_dired_undo($log2, $who, $opts);
kop_dired_undo($log, $who, $opts);
list($ok, $d) = $same($start, $snapshot());
$check('add + tie undone: exactly as before', $ok, $d);
list($msg, $log) = kop_dired_link_remove($aid, $a_fids[0], $who);
$check('taken off', !kop_dired_link($aid, $a_fids[0]), $msg);
kop_dired_undo($log, $who, $opts);
list($ok, $d) = $same($start, $snapshot());
$check('take off undone: exactly as before', $ok, $d);

// ---------------------------------------------------------------------------
echo "\n-- Move programs to another company --\n";
$move = array_slice($a_fids, 0, 2);
// One of them names A as its operator, so its record must follow.
$rec = kop_facility_load($move[0], $opts);
$doc = $rec['doc'];
$doc['identification']['currentOperator'] = $A['name'];
kop_facility_save($doc, $opts + array('force' => true));
$start_move = $snapshot();
list($msg, $log) = kop_dired_transfer($aid, $bid, $move, 'current', true, $who, $opts);
echo "  $msg\n";
$ok = true;
foreach ($move as $fid) {
    $ok = $ok && kop_dired_link($bid, $fid) && kop_dired_link($bid, $fid)['relationship'] === 'current'
        && kop_dired_link($aid, $fid) && kop_dired_link($aid, $fid)['relationship'] === 'past';
}
$check('moved: current under B, past under A', $ok);
$now = kop_facility_load($move[0], $opts)['doc'];
$check('the record naming A as operator names B now', ($now['identification']['currentOperator'] ?? '') === kop_dired_name($B['row']), (string) ($now['identification']['currentOperator'] ?? ''));
kop_dired_undo($log, $who, $opts);
list($ok, $d) = $same($start_move, $snapshot());
$check('move undone: links and record exactly as before', $ok, $d);
list($msg, $log) = kop_dired_transfer($aid, $bid, $move, 'past', false, $who, $opts);
$check('moved without keeping: off A, past under B', !kop_dired_link($aid, $move[0]) && kop_dired_link($bid, $move[0])['relationship'] === 'past', $msg);
$check('a past move leaves the Operator line alone', (kop_facility_load($move[0], $opts)['doc']['identification']['currentOperator'] ?? '') === $A['name']);
kop_dired_undo($log, $who, $opts);
list($ok, $d) = $same($start_move, $snapshot());
$check('second move undone: exactly as before', $ok, $d);
try { kop_dired_transfer($aid, $bid, array(), 'current', false, $who, $opts); $check('nothing ticked is refused', false); }
catch (RuntimeException $e) { $check('nothing ticked is refused', true); }

// ---------------------------------------------------------------------------
echo "\n-- Merge --\n";
// Give A one of B's programs as past, a filing and an investor stake, so every kind of row moves.
$shared = kop_dired_link_ids($bid)[0];
$pdo->exec("INSERT INTO wpdl_kop_operator_facilities (operator_id, facility_id, relationship, sort_order) VALUES ($aid, $shared, 'current', 500)");
$pdo->exec("UPDATE wpdl_kop_operator_facilities SET relationship = 'past' WHERE operator_id = $bid AND facility_id = $shared");
$pdo->exec("INSERT INTO wpdl_kop_business_links (state, doc, facility_id, operator_id, created_by, created_at) VALUES ('FL', 'N1', 0, $aid, 't', '2026-10-16')");
$pdo->exec("INSERT INTO wpdl_kop_investor_links (investor_id, operator_id, role, created_at) VALUES (7, $aid, 'Investor', '2026-10-16')");
$a_news = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_operator_links WHERE operator_id = $aid")->fetchColumn();
$start_merge = $snapshot();
$a_links = count(kop_dired_links($aid));
$b_links = count(kop_dired_links($bid));
list($msg, $log) = kop_dired_merge($aid, $bid, $who);
echo "  $msg\n";
$check('A is gone', kop_dired_op_row($aid) === null);
$check('B lists every program of both (the shared one once)', count(kop_dired_links($bid)) === $a_links + $b_links - 1, count(kop_dired_links($bid)) . ' vs ' . ($a_links + $b_links - 1));
$check('the shared program A ran now is current under B', kop_dired_link($bid, $shared)['relationship'] === 'current');
$check('filing and investor stake point at B', (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_business_links WHERE operator_id = $bid")->fetchColumn() === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_investor_links WHERE operator_id = $bid")->fetchColumn() === 1);
$check('news filed under A is under B', (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_operator_links WHERE operator_id = $aid")->fetchColumn() === 0);
$bop = kop_dired_json(kop_dired_op_row($bid))['operator'];
$check("A's name is one of B's other names", in_array($A['name'], (array) $bop['otherNames'], true));
$check("B's own name unchanged", $bop['name'] === $B['op']['name']);
echo '  ' . kop_dired_undo($log, $who, $opts) . "\n";
list($ok, $d) = $same($start_merge, $snapshot());
$check('merge undone: exactly as before (' . $a_news . ' news links)', $ok, $d);
$pdo->exec("DELETE FROM wpdl_kop_operator_facilities WHERE operator_id = $aid AND facility_id = $shared");
$pdo->exec("UPDATE wpdl_kop_operator_facilities SET relationship = 'current' WHERE operator_id = $bid AND facility_id = $shared");
$pdo->exec('DELETE FROM wpdl_kop_business_links; DELETE FROM wpdl_kop_investor_links');
try { kop_dired_merge($aid, $aid, $who); $check('merging into itself is refused', false); }
catch (RuntimeException $e) { $check('merging into itself is refused', true); }

$merge_json = kop_dired_merge_json(array('operator' => array('name' => 'X', 'founded' => '', 'websites' => array('a'), 'history' => array('text' => 'mine'))),
    array('operator' => array('name' => 'Y', 'otherNames' => array('Y Inc', 'X'), 'founded' => '1990', 'websites' => array('a', 'b'), 'history' => array('text' => 'theirs'), 'directoryHidden' => true)), 'Y');
$check('merge fills empty fields, joins lists, keeps its own', $merge_json['operator']['founded'] === '1990' && $merge_json['operator']['websites'] === array('a', 'b')
    && $merge_json['operator']['history']['text'] === 'mine' && $merge_json['operator']['otherNames'] === array('Y', 'Y Inc') && empty($merge_json['operator']['directoryHidden']),
    json_encode($merge_json));

// ---------------------------------------------------------------------------
echo "\n-- Delete, add --\n";
$before_delete = $snapshot();
list($msg, $log) = kop_dired_delete($bid, $who);
echo "  $msg\n";
$check('deleted with its links', kop_dired_op_row($bid) === null && !kop_dired_links($bid));
$check('its programs stay', (int) $pdo->query('SELECT COUNT(*) FROM facilities_v2 WHERE id = ' . (int) $B['links'][0]['facility_id'])->fetchColumn() === 1);
kop_dired_undo($log, $who, $opts);
list($ok, $d) = $same($before_delete, $snapshot());
$check('delete undone: exactly as before', $ok, $d);
list($nid, $log) = kop_dired_create('Directory Editor Test Company', $who);
$check('added, no programs', kop_dired_op_row($nid) && !kop_dired_links($nid) && $nid >= 500000);
$check('a new company has no card yet', kop_dired_companies()[$nid]['state'] === 'few');
try { kop_dired_create($A['name'], $who); $check('a name on file is refused', false); }
catch (RuntimeException $e) { $check('a name on file is refused', true); }
list($m, $l2) = kop_dired_link_add($nid, $a_fids[0], 'current', $who);
try { kop_dired_undo($log, $who, $opts); $check('undo of add refused while it has a program', false); }
catch (RuntimeException $e) { $check('undo of add refused while it has a program', true); }
kop_dired_undo($l2, $who, $opts);
kop_dired_undo($log, $who, $opts);
list($ok, $d) = $same($start_move, $snapshot());
$check('add undone: exactly as before', $ok, $d);

// ---------------------------------------------------------------------------
echo "\n-- Log words --\n";
foreach (array_slice(kop_dired_log_rows(30), 0, 30) as $e) $words[] = kop_dired_log_words($e);
$check('every log row has words', !array_filter($words, function ($w) { return trim($w) === '' || preg_match('/^[a-z_]+$/', $w); }));
if (isset($args['list'])) foreach ($words as $w) echo "  $w\n";

$pdo = null;
$GLOBALS['wpdb'] = $wpdb = null;
try { unlink($db_path); } catch (Throwable $e) { /* Windows holds it a moment; the temp folder clears it */ }
echo "\n" . ($failures ? "$failures FAILED\n" : "All passed.\n");
exit($failures ? 1 : 0);
