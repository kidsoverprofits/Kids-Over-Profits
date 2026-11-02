<?php
/**
 * Offline checks for inc/related-move.php (a facility record moved into a
 * related category, such as Christian Rehabs for Adults) against the
 * production mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-related-move.php [--db=tmp/prod.sqlite]
 *
 * Every table the move touches is copied into an in-memory database, so the
 * mirror is never changed. For each entry of seeds/related-moves.json it moves
 * the record, checks that the related record holds its document, that the
 * company, news, lawsuit and filing links point at the new id, that nothing
 * else links the old id, that waiting queue rows are unmatched with the note
 * and decided ones untouched, that its page address resolves to the related
 * page, then undoes it and checks every table is back exactly as it was.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

define('ABSPATH', dirname(__DIR__) . '/');
$GLOBALS['kop_test_options'] = array();
function add_action() {}
function add_filter() {}
function get_option($k, $d = false) { return $GLOBALS['kop_test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function get_transient($k) { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function home_url($p = '') { return 'https://kidsoverprofits.org' . $p; }
function sanitize_title($s) { return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $s)), '-'); }
function kop_seed_pdo() { return $GLOBALS['pdo']; }
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
require dirname(__DIR__) . '/inc/facility-store.php';
require dirname(__DIR__) . '/inc/facility-merge.php';
require dirname(__DIR__) . '/inc/related-facilities.php';
require dirname(__DIR__) . '/inc/related-move.php';

$prefix = 'wpdl_';
$pdo = $GLOBALS['pdo'] = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($db_path) . ' AS src');
$tables = array('facilities_v2', 'related_facilities');
foreach (kop_fmerge_ref_tables($prefix) as $r) $tables[] = $r['t'];
foreach (array_merge(kop_rmove_kept_tables($prefix), kop_rmove_dropped_tables($prefix)) as $r) $tables[] = $r['t'];
$copied = array();
foreach (array_unique($tables) as $t) {
    $sql = $pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
    if (!$sql) {
        echo "  (no $t in the mirror)\n";
        continue;
    }
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
    $copied[] = $t;
}
$pdo->exec('DETACH DATABASE src');

$fails = 0;
function check($ok, $what) {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
}
function snapshot(PDO $pdo, array $tables) {
    $out = array();
    foreach ($tables as $t) {
        // One hash per row, streamed (postmeta is large), order-free.
        $keys = array();
        $st = $pdo->query("SELECT * FROM `$t`");
        while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) $keys[] = md5(serialize($row));
        sort($keys);
        $out[$t] = md5(implode('', $keys));
    }
    return $out;
}
$name_of = function ($id) use ($pdo) {
    $q = $pdo->prepare('SELECT unique_name FROM facilities_v2 WHERE id = ?');
    $q->execute(array((int) $id));
    return $q->fetchColumn();
};
$count = function ($sql, array $p) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); };

$entries = json_decode((string) file_get_contents(dirname(__DIR__) . '/seeds/related-moves.json'), true);
check(is_array($entries) && $entries, 'seeds/related-moves.json reads');

foreach ((array) $entries as $e) {
    $fid = (int) $e['id'];
    echo "\n#$fid {$e['unique_name']} -> {$e['category']}\n";
    check(kop_related_category($e['category']) !== null, 'the category exists');
    if ($name_of($fid) !== $e['unique_name']) {
        echo "  skip (not on file under that name in this mirror)\n";
        continue;
    }
    $q = $pdo->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
    $q->execute(array($fid));
    $old_doc = json_decode((string) $q->fetchColumn(), true);

    $kept = array();
    foreach (kop_rmove_kept_tables($prefix) as $l) {
        if (in_array($l['t'], $copied, true)) $kept[$l['t']] = $count("SELECT COUNT(*) FROM `{$l['t']}` WHERE `{$l['c']}` = ?", array($fid));
    }
    $wbf = $prefix . 'kop_woodbury_facts';
    $pending = $count("SELECT COUNT(*) FROM `$wbf` WHERE facility_id = ? AND status IN ('pending', 'gone')", array($fid));
    $decided = $count("SELECT COUNT(*) FROM `$wbf` WHERE facility_id = ? AND status NOT IN ('pending', 'gone')", array($fid));
    $pending_keys = $pdo->query("SELECT pkey FROM `$wbf` WHERE facility_id = $fid AND status IN ('pending', 'gone')")->fetchAll(PDO::FETCH_COLUMN);

    $before = snapshot($pdo, $copied);
    $entry = kop_rmove_convert($pdo, $prefix, $fid, $e['unique_name'], $e['category'], (string) ($e['note'] ?? ''));
    $rid = (int) $entry['related_id'];
    echo "  related #$rid  report: " . json_encode($entry['report']) . '  slugs: ' . implode(', ', $entry['slugs']) . "\n";
    check($name_of($fid) === false, 'the record left facilities_v2');
    check(kop_facility_is_related_id($rid), 'it has a related id');
    $rec = kop_related_get($rid, array('pdo' => $pdo, 'prefix' => $prefix));
    check($rec && $rec['category'] === $e['category'] && $rec['review'] === 'approved', 'the related record is in the category, approved');
    check($rec && $rec['doc']['identification']['name'] === $old_doc['identification']['name']
        && $rec['doc']['identification']['currentOperator'] === $old_doc['identification']['currentOperator']
        && $rec['doc']['notes'] === $old_doc['notes'], 'it keeps the name, operator and notes');
    foreach ($kept as $t => $n) {
        $c = $t === $prefix . 'kop_operator_facilities' || $t === $prefix . 'kop_nonprofit_links' || $t === 'news_facility_links' || $t === 'lawsuit_facility_links' ? 'facility_id' : '';
        check($count("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?", array($fid)) === 0 && $count("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?", array($rid)) === $n, "its $n $t rows point at the related record");
    }
    foreach (kop_rmove_dropped_tables($prefix) as $l) {
        if (!in_array($l['t'], $copied, true) || !in_array($l['c'], kop_fmerge_table_columns($pdo, $l['t']), true)) continue;
        check($count("SELECT COUNT(*) FROM `{$l['t']}` WHERE `{$l['c']}` = ?", array(($l['k'] ?? 'id') === 'name' ? $e['unique_name'] : $fid)) === 0, "no {$l['t']} row links it");
    }
    check($count("SELECT COUNT(*) FROM `$wbf` WHERE facility_id = ? AND status IN ('pending', 'gone')", array($fid)) === 0, "$pending waiting Woodbury items unmatched");
    if ($pending_keys) {
        $in = implode(',', array_map(array($pdo, 'quote'), $pending_keys));
        $notes = $pdo->query("SELECT DISTINCT match_note FROM `$wbf` WHERE pkey IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        check(count($notes) === 1 && strpos((string) $notes[0], 'Christian Rehabs for Adults') !== false, 'they carry the note naming the category');
    }
    check($count("SELECT COUNT(*) FROM `$wbf` WHERE facility_id = ? AND status NOT IN ('pending', 'gone')", array($fid)) === $decided, "$decided decided items keep their record");
    try {
        kop_rmove_convert($pdo, $prefix, $fid, $e['unique_name'], $e['category']);
        check(false, 'a second move is refused');
    } catch (RuntimeException $ex) {
        check(true, 'a second move is refused');
    }

    $want = kop_related_url($rid);
    check($want !== '' && strpos($want, '/related/' . $e['category'] . '/') !== false, "the related page is $want");
    foreach ($entry['slugs'] as $slug) check(kop_rmove_redirect_url($slug) === $want, "/facility/$slug/ redirects there");
    check(kop_rmove_redirect_url('some-other-place-ut') === '', 'another slug is left alone');

    kop_rmove_undo($pdo, $prefix, $fid);
    kop_related_flush();
    $after = snapshot($pdo, $copied);
    $diff = array_keys(array_diff_assoc($before, $after));
    check(!$diff, 'Undo puts every table back exactly' . ($diff ? ' (differs: ' . implode(', ', $diff) . ')' : ''));
    check(kop_rmove_redirect_url(end($entry['slugs'])) === '', 'and the address no longer redirects');
}

echo "\n" . ($fails ? "$fails FAILED\n" : "All passed\n");
exit($fails ? 1 : 0);
