<?php
/**
 * Offline checks for inc/related-record-seeds.php (seeds/related-records.json: related records and the
 * articles about them, first the elite boarding schools) against the production mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-related-record-seeds.php [--db=tmp/prod.sqlite]
 *
 * The tables the seed and the related moves touch are copied into an in-memory database, so the mirror
 * is never changed. It runs the related moves of the seed's categories first (as the deploy does), then
 * every entry, and checks: each school is a record of its category, approved, made once; a record a move
 * brought over under another name is renamed with the old name kept; each article is linked to its school,
 * approved, and left out of the TTI news lists; links an import guessed are taken off; an entry with no
 * name files its articles under the category in general; a second run changes nothing; Undo puts every
 * table back exactly as it was after the moves.
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
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function get_stylesheet_directory() { return dirname(__DIR__); }
function sanitize_title($s) { return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $s)), '-'); }
function kop_seed_pdo() { return $GLOBALS['pdo']; }
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
require dirname(__DIR__) . '/inc/facility-store.php';
require dirname(__DIR__) . '/inc/facility-merge.php';
require dirname(__DIR__) . '/inc/related-facilities.php';
require dirname(__DIR__) . '/inc/related-move.php';
require dirname(__DIR__) . '/inc/related-record-seeds.php';

$prefix = 'wpdl_';
$pdo = $GLOBALS['pdo'] = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// The store's identity lookup uses MySQL's JSON_UNQUOTE when it cannot see the generated columns.
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) { return $v; }, 1);
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($db_path) . ' AS src');
$tables = array('facilities_v2', 'related_facilities', 'news_submissions');
foreach (kop_fmerge_ref_tables($prefix) as $r) $tables[] = $r['t'];
foreach (array_merge(kop_rmove_kept_tables($prefix), kop_rmove_dropped_tables($prefix)) as $r) $tables[] = $r['t'];
// related_facilities as MySQL makes it, with the generated columns the name lookups read (scripts/kop-test-related.php).
$pdo->exec("CREATE TABLE related_facilities (
    id INTEGER PRIMARY KEY, category TEXT NOT NULL DEFAULT '', review TEXT NOT NULL DEFAULT 'approved',
    unique_name TEXT NOT NULL UNIQUE, json_data TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    name TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.identification.name')) STORED,
    name_key TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.identification.nameKey')) STORED,
    state TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.location.state')) STORED,
    city TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.location.city')) STORED,
    country TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.location.country')) STORED,
    status TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.operatingPeriod.status')) STORED,
    facility_type TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.facilityDetails.type')) STORED,
    start_year INTEGER GENERATED ALWAYS AS (json_extract(json_data, '$.operatingPeriod.startYear')) STORED,
    end_year INTEGER GENERATED ALWAYS AS (json_extract(json_data, '$.operatingPeriod.endYear')) STORED)");
$pdo->exec('INSERT INTO related_facilities (id, category, review, unique_name, json_data, created_at, updated_at)
    SELECT id, category, review, unique_name, json_data, created_at, updated_at FROM src.related_facilities');
$copied = array('related_facilities');
foreach (array_unique($tables) as $t) {
    if ($t === 'related_facilities') continue;
    $sql = $pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
    if (!$sql) continue;
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
    $copied[] = $t;
}
$pdo->exec('DETACH DATABASE src');
kop_related_news_install($pdo);
$copied[] = 'related_category_news';

$fails = 0;
function check($ok, $what) {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
}
function snapshot(PDO $pdo, array $tables) {
    $out = array();
    foreach ($tables as $t) {
        $keys = array();
        $st = $pdo->query("SELECT * FROM `$t`");
        while (($row = $st->fetch(PDO::FETCH_ASSOC)) !== false) $keys[] = md5(serialize($row));
        sort($keys);
        $out[$t] = md5(implode('', $keys));
    }
    return $out;
}
$col = function ($sql, array $p = array()) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(PDO::FETCH_COLUMN); };
$opts = array('pdo' => $pdo, 'prefix' => $prefix);

$entries = json_decode((string) file_get_contents(dirname(__DIR__) . '/seeds/related-records.json'), true);
check(is_array($entries) && $entries, 'seeds/related-records.json reads');
$cats = array_unique(array_column((array) $entries, 'category'));
foreach ($cats as $c) check(kop_related_category($c) !== null, "the category $c exists");
$keys = array_map('kop_related_record_seed_key', (array) $entries);
check(count($keys) === count(array_unique($keys)), 'every entry has its own key (category and name)');

echo "-- The related moves of these categories, as the deploy runs them first --\n";
foreach ((array) json_decode((string) file_get_contents(dirname(__DIR__) . '/seeds/related-moves.json'), true) as $m) {
    if (!in_array($m['category'], $cats, true)) continue;
    $have = $col('SELECT unique_name FROM facilities_v2 WHERE id = ?', array((int) $m['id']));
    if (($have[0] ?? null) !== $m['unique_name']) {
        echo "  skip #{$m['id']} {$m['unique_name']} (not on file under that name in this mirror)\n";
        continue;
    }
    kop_rmove_convert($pdo, $prefix, (int) $m['id'], $m['unique_name'], $m['category'], (string) ($m['note'] ?? ''));
    echo "  moved #{$m['id']} {$m['unique_name']}\n";
}
$GLOBALS['kop_test_options']['kop_related_record_seed_log'] = array();
$before = snapshot($pdo, $copied);
$related_before = (int) $pdo->query('SELECT COUNT(*) FROM related_facilities')->fetchColumn();

echo "-- Every entry --\n";
$tti_first = defined('KOP_RELATED_FIRST_ID') ? KOP_RELATED_FIRST_ID : 900000;
$log = array();
foreach ((array) $entries as $e) {
    $key = kop_related_record_seed_key($e);
    $l = kop_related_record_seed_apply($pdo, $e);
    $log[$key] = $l;
    $GLOBALS['kop_test_options']['kop_related_record_seed_log'] = $log;
    $label = $e['name'] !== '' ? $e['name'] : '(the category in general)';
    if ($e['name'] !== '') {
        $rec = kop_related_get($l['record'], $opts);
        check($rec && $rec['category'] === $e['category'] && $rec['review'] === 'approved'
            && $rec['doc']['identification']['name'] === $e['name'], "$label: an approved record of its category, #{$l['record']}"
            . ($l['made'] ? ' (made)' : ' (on file)') . ($l['renamed_from'] !== '' ? ", renamed from {$l['renamed_from']}" : ''));
        if (!empty($e['find']) && $l['renamed_from'] !== '') {
            check(in_array($l['renamed_from'], (array) $rec['doc']['identification']['otherNames'], true), "$label: keeps {$l['renamed_from']} as another name");
        }
    }
    foreach ((array) $e['news'] as $nid) {
        $st = $col('SELECT status FROM news_submissions WHERE id = ?', array((int) $nid));
        if (!$st) {
            echo "  skip article #$nid (not in this mirror)\n";
            continue;
        }
        $links = array_map('intval', $col('SELECT facility_id FROM news_facility_links WHERE news_id = ?', array((int) $nid)));
        $ok = $st[0] === 'approved' && ($e['name'] !== '' ? in_array($l['record'], $links, true)
            : in_array((int) $nid, array_map('intval', $col('SELECT news_id FROM related_category_news WHERE category = ?', array($e['category']))), true));
        check($ok, "$label: article #$nid approved (was {$l['news'][$nid]}) and filed");
        foreach ((array) (($e['drop_links'] ?? array())[(string) $nid] ?? array()) as $fid) {
            check(!in_array((int) $fid, $links, true), "$label: article #$nid no longer linked to #$fid");
        }
        if ($e['name'] !== '' && $links) {
            check(min($links) > $tti_first, "$label: article #$nid is linked to no TTI record, so the TTI news lists leave it out");
        }
    }
}
$excluded = (function () use ($pdo, $tti_first) {
    // kop_related_news_ids() reads once per request; the same query, here.
    $ids = array_map('intval', $pdo->query('SELECT DISTINCT news_id FROM related_category_news')->fetchAll(PDO::FETCH_COLUMN));
    $only = array_map('intval', $pdo->query("SELECT news_id FROM news_facility_links GROUP BY news_id HAVING MIN(facility_id) > $tti_first")->fetchAll(PDO::FETCH_COLUMN));
    return array_merge($ids, $only);
})();
$all_news = array();
foreach ((array) $entries as $e) $all_news = array_merge($all_news, (array) $e['news']);
$held = array_map('intval', $col('SELECT id FROM news_submissions WHERE id IN (' . implode(',', array_map('intval', $all_news)) . ')'));
check(!array_diff($held, $excluded), 'every filed article is left out of the TTI news lists (' . count($held) . ')');
$approved_out = $col("SELECT n.id FROM news_submissions n JOIN news_facility_links l ON l.news_id = n.id WHERE n.status = 'approved'
    GROUP BY n.id HAVING MIN(l.facility_id) > $tti_first");
check(!array_diff(array_map('intval', $approved_out), $held), 'no other approved article leaves the TTI news lists ('
    . implode(', ', array_diff(array_map('intval', $approved_out), $held)) . ')');

echo "-- A second run --\n";
$mid = snapshot($pdo, $copied);
foreach ((array) $entries as $e) {
    $l = kop_related_record_seed_apply($pdo, $e);
    check(!$l['made'] && !$l['linked'] && !$l['general'] && $l['renamed_from'] === '', kop_related_record_seed_key($e) . ': nothing more to do');
}
check(snapshot($pdo, $copied) === $mid, 'a second run changes no table');

echo "-- Undo --\n";
foreach (array_reverse(array_keys($log)) as $key) kop_related_record_seed_undo($pdo, $key);
$after = snapshot($pdo, $copied);
$diff = array_keys(array_diff_assoc($before, $after));
check(!$diff, 'Undo puts every table back exactly' . ($diff ? ' (differs: ' . implode(', ', $diff) . ')' : ''));
check((int) $pdo->query('SELECT COUNT(*) FROM related_facilities')->fetchColumn() === $related_before, 'and the records it made are gone');

echo "\n" . ($fails ? "$fails FAILED\n" : "All passed\n");
exit($fails ? 1 : 0);
