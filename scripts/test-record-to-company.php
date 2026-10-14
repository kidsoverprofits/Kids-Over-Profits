<?php
/**
 * Offline checks for inc/record-to-company.php (a facility record that is
 * really a parent company) against the production mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-record-to-company.php [--db=tmp/prod.sqlite]
 *
 * Every table the conversion touches is copied into an in-memory database, so
 * the mirror is never changed. For each entry of seeds/record-to-company.json
 * it converts the record, checks that nothing links to it, that waiting queue
 * rows are unmatched with the note and decided ones untouched, that its page
 * address resolves to the company, that Undo is refused while a company holds
 * the name, then undoes it and checks every table is back exactly as it was.
 * It also checks the companies, merges and links the seeds name are the
 * records on file.
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
function get_option($k, $d = false) { return $GLOBALS['kop_test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function get_transient($k) { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function home_url($p = '') { return 'https://kidsoverprofits.org' . $p; }
function kop_seed_pdo() { return $GLOBALS['pdo']; }
// The company page a name resolves to, as kop_operator_page_url_for_name() would give it.
function kop_operator_page_url_for_name($name) {
    $q = $GLOBALS['pdo']->prepare('SELECT COUNT(*) FROM wpdl_kop_operators WHERE name = ?');
    $q->execute(array($name));
    return (int) $q->fetchColumn() ? 'https://kidsoverprofits.org/operator/' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)) . '/' : '';
}
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
require dirname(__DIR__) . '/inc/facility-store.php';
require dirname(__DIR__) . '/inc/facility-merge.php';
require dirname(__DIR__) . '/inc/record-to-company.php';

$prefix = 'wpdl_';
$pdo = $GLOBALS['pdo'] = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($db_path) . ' AS src');
$tables = array('facilities_v2', $prefix . 'kop_operators');
foreach (kop_fmerge_ref_tables($prefix) as $r) $tables[] = $r['t'];
foreach (kop_rtc_link_tables($prefix) as $r) $tables[] = $r['t'];
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

$seeds = dirname(__DIR__) . '/seeds/';
$entries = json_decode((string) file_get_contents($seeds . 'record-to-company.json'), true);
check(is_array($entries) && $entries, 'seeds/record-to-company.json reads');
$companies = json_decode((string) file_get_contents($seeds . 'operator-records.json'), true);
$company_names = array_map(function ($c) { return $c['name']; }, (array) $companies);

foreach ((array) $entries as $e) {
    $fid = (int) $e['id'];
    echo "\n#$fid {$e['unique_name']} -> company {$e['company']}\n";
    if ($name_of($fid) !== $e['unique_name']) {
        echo "  skip (not on file under that name in this mirror)\n";
        continue;
    }
    check(in_array($e['company'], $company_names, true), 'seeds/operator-records.json makes the company');
    $q = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}kop_operators WHERE LOWER(unique_name) = LOWER(?)");
    $q->execute(array($e['unique_name']));
    check((int) $q->fetchColumn() === 0, 'no company holds the key yet');

    $wbf = $prefix . 'kop_woodbury_facts';
    $count = function ($sql) use ($pdo, $fid) { $s = $pdo->prepare($sql); $s->execute(array($fid)); return (int) $s->fetchColumn(); };
    $pending = $count("SELECT COUNT(*) FROM `$wbf` WHERE facility_id = ? AND status IN ('pending', 'gone')");
    $decided = $count("SELECT COUNT(*) FROM `$wbf` WHERE facility_id = ? AND status NOT IN ('pending', 'gone')");
    $pending_keys = $pdo->query("SELECT pkey FROM `$wbf` WHERE facility_id = $fid AND status IN ('pending', 'gone')")->fetchAll(PDO::FETCH_COLUMN);

    $before = snapshot($pdo, $copied);
    $entry = kop_rtc_convert($pdo, $prefix, $fid, $e['unique_name'], $e['company'], (string) ($e['note'] ?? ''));
    echo '  report: ' . json_encode($entry['report']) . '  slugs: ' . implode(', ', $entry['slugs']) . "\n";
    check($name_of($fid) === false, 'the record left facilities_v2');
    foreach (kop_rtc_link_tables($prefix) as $l) {
        if (!in_array($l['t'], $copied, true) || !in_array($l['c'], kop_fmerge_table_columns($pdo, $l['t']), true)) continue;
        $s = $pdo->prepare("SELECT COUNT(*) FROM `{$l['t']}` WHERE `{$l['c']}` = ?");
        $s->execute(array(($l['k'] ?? 'id') === 'name' ? $e['unique_name'] : $fid));
        check((int) $s->fetchColumn() === 0, "no {$l['t']} row links it");
    }
    check($count("SELECT COUNT(*) FROM `$wbf` WHERE facility_id = ? AND status IN ('pending', 'gone')") === 0, "$pending waiting Woodbury items unmatched");
    if ($pending_keys) {
        $in = implode(',', array_map(array($pdo, 'quote'), $pending_keys));
        $notes = $pdo->query("SELECT DISTINCT match_note FROM `$wbf` WHERE pkey IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        check(count($notes) === 1 && strpos((string) $notes[0], $e['company']) !== false, 'they carry the note naming the company');
    }
    check($count("SELECT COUNT(*) FROM `$wbf` WHERE facility_id = ? AND status NOT IN ('pending', 'gone')") === $decided, "$decided decided items keep their record");
    try {
        kop_rtc_convert($pdo, $prefix, $fid, $e['unique_name'], $e['company']);
        check(false, 'a second conversion is refused');
    } catch (RuntimeException $ex) {
        check(true, 'a second conversion is refused');
    }

    // The page address: no company yet = the /operator/ list, then the company's page.
    foreach ($entry['slugs'] as $slug) check(kop_rtc_redirect_url($slug) !== '', "/facility/$slug/ redirects");
    check(kop_rtc_redirect_url('some-other-place-ut') === '', 'another slug is left alone');
    $pdo->prepare("INSERT INTO {$prefix}kop_operators (id, unique_name, name, json_data) VALUES (?, ?, ?, ?)")
        ->execute(array(999999, $e['unique_name'], $e['company'], '{"operator":{}}'));
    check(kop_rtc_redirect_url(end($entry['slugs'])) === kop_operator_page_url_for_name($e['company']), 'it goes to the company page once the company exists');
    try {
        kop_rtc_undo($pdo, $prefix, $fid);
        check(false, 'Undo is refused while the company holds the key');
    } catch (RuntimeException $ex) {
        check(true, 'Undo is refused while the company holds the key');
    }
    $pdo->exec("DELETE FROM {$prefix}kop_operators WHERE id = 999999");

    kop_rtc_undo($pdo, $prefix, $fid);
    $after = snapshot($pdo, $copied);
    $diff = array_keys(array_diff_assoc($before, $after));
    check(!$diff, 'Undo puts every table back exactly' . ($diff ? ' (differs: ' . implode(', ', $diff) . ')' : ''));
    check(kop_rtc_redirect_url(end($entry['slugs'])) === '', 'and the address no longer redirects');
}

// ---- What the other seeds name ---------------------------------------------
echo "\nSeeds\n";
foreach ((array) $companies as $c) {
    if (!in_array($c['name'], array('InnerChange', 'Embark Behavioral Health', 'Aspen Education Group'), true) || empty($c['note']) || strpos($c['note'], '2026-10-14') === false) continue;
    foreach ((array) ($c['facilities'] ?? array()) as $f) {
        check($name_of($f['id']) === $f['unique_name'], "{$c['name']}: #{$f['id']} is {$f['unique_name']}");
    }
}
$merges = json_decode((string) file_get_contents($seeds . 'facility-merges.json'), true);
foreach ((array) $merges as $m) {
    if (strpos((string) ($m['note'] ?? ''), 'InnerChange cleanup') === false) continue;
    $k = $name_of($m['keep']);
    $d = $name_of($m['drop']);
    if ($d === false) { echo "  skip merge #{$m['drop']} (already gone)\n"; continue; }
    check($k === $m['keep_name'] && $d === $m['drop_name'], "merge #{$m['drop']} {$m['drop_name']} into #{$m['keep']} {$m['keep_name']}");
}

echo "\n" . ($fails ? "$fails FAILED\n" : "All passed\n");
exit($fails ? 1 : 0);
