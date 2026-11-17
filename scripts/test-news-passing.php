<?php
/**
 * Articles that name a program only in passing (inc/news-passing.php, plan 4.3 item 31): the stored mention's
 * "passing" mark survives the normalizer and the save, the link sync copies it to news_facility_links.passing, a
 * re-save without the mark clears it, and a sync on a table without the column still works.
 *
 * Runs the real sync in an in-memory database; facility records come from tmp/prod.sqlite (attached read-only).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-news-passing.php [--db=<mirror>]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
$root = dirname(__DIR__);
$args = getopt('', array('db::'));
$mirror = $args['db'] ?? ($root . '/tmp/prod.sqlite');
if (!file_exists($mirror)) {
    echo "SKIP: no $mirror (python ../kop-pipeline/scripts/sync-prod-sqlite.py)\n";
    exit(0);
}

$fails = 0;
function check($ok, $label, $detail = '') {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fails++;
}

$GLOBALS['table_prefix'] = 'wpdl_';
function kop_v2_detect_prefix($pdo) { return 'wpdl_'; }
function kop_facility_page_url($id) { return ''; }
function kop_operator_page_url($id) { return ''; }
function kop_operator_page_id_for_name($name) { return 0; }
require $root . '/api/lawsuit-facility-links.php';   // news-mentions.php + facility-aliases.php

// 1. The normalizer keeps the mark, and only when set.
$n = kop_normalize_facility_mentions('[{"name":"Spring Ridge Academy","facility_id":12293},{"name":"Provo Canyon School","facility_id":10371,"passing":true},"Blue Ridge"]');
check(!isset($n[0]['passing']) && !empty($n[1]['passing']) && !isset($n[2]['passing']), 'normalizer keeps passing only where set', json_encode($n));

$mk = function ($with_column) use ($mirror) {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("ATTACH DATABASE 'file:" . str_replace(array('\\', "'"), array('/', "''"), realpath($mirror)) . "?mode=ro' AS m");
    $pdo->exec('CREATE TABLE news_submissions (id INTEGER PRIMARY KEY, facilities_mentioned TEXT)');
    $pdo->exec('CREATE TABLE news_facility_links (news_id INTEGER, facility_id INTEGER, link_type TEXT, created_at TEXT, created_by TEXT, PRIMARY KEY (news_id, facility_id))');
    $pdo->exec('CREATE TABLE wpdl_kop_operator_links AS SELECT * FROM m.wpdl_kop_operator_links WHERE 0');
    if ($with_column) kop_news_links_ensure_passing($pdo);
    return $pdo;
};
$sync = function (PDO $pdo, $id, $mentions) {
    $pdo->prepare('INSERT OR REPLACE INTO news_submissions (id, facilities_mentioned) VALUES (?, ?)')->execute(array($id, json_encode($mentions)));
    kop_sync_news_facility_links($pdo, $id, kop_normalize_facility_mentions(json_encode($mentions)), 'test');
};
$links = function (PDO $pdo, $id) {
    $out = array();
    foreach ($pdo->query('SELECT * FROM news_facility_links WHERE news_id = ' . (int) $id . ' ORDER BY facility_id') as $r) {
        $out[(int) $r['facility_id']] = isset($r['passing']) ? (int) $r['passing'] : null;
    }
    return $out;
};

$pdo = $mk(true);
check(kop_news_links_has_passing($pdo), 'ensure adds the passing column');
check(kop_news_links_ensure_passing($pdo), 'ensure a second time is a no-op');

// 2. #18's shape: Spring Ridge about, Blue Ridge and Provo Canyon in passing.
$sync($pdo, 18, array(
    array('name' => 'Spring Ridge Academy', 'facility_id' => 12293),
    array('name' => 'Blue Ridge Wilderness Therapy', 'facility_id' => 12506, 'passing' => true),
    array('name' => 'Provo Canyon School', 'facility_id' => 10371, 'passing' => true),
));
$l = $links($pdo, 18);
check($l === array(10371 => 1, 12293 => 0, 12506 => 1), 'the sync writes the passing flag per link', json_encode($l));
$stored = json_decode($pdo->query('SELECT facilities_mentioned FROM news_submissions WHERE id = 18')->fetchColumn(), true);
check(!empty($stored[2]['passing']), 'the stored mention keeps its mark after the sync', json_encode($stored));

// 3. A re-save without the mark makes it a full link again; without the name the link goes.
$sync($pdo, 18, array(
    array('name' => 'Spring Ridge Academy', 'facility_id' => 12293),
    array('name' => 'Blue Ridge Wilderness Therapy', 'facility_id' => 12506),
));
$l = $links($pdo, 18);
check($l === array(12293 => 0, 12506 => 0), 'unmarked: passing cleared; dropped name: link gone', json_encode($l));

// 4. A name with no id that resolves, marked passing.
$sync($pdo, 19, array(array('name' => 'Provo Canyon School', 'passing' => true)));
$l = $links($pdo, 19);
check($l === array(10371 => 1), 'a resolved name keeps its passing mark', json_encode($l));

// 5. A hand-made link the mention names gets the mark too; one the mentions do not name keeps its own.
$pdo->exec("INSERT INTO news_facility_links (news_id, facility_id, link_type, passing) VALUES (20, 12293, 'related', 0), (20, 10371, 'related', 1)");
$sync($pdo, 20, array(array('name' => 'Spring Ridge Academy', 'facility_id' => 12293, 'passing' => true)));
$l = $links($pdo, 20);
check($l === array(10371 => 1, 12293 => 1), 'related links: marked when named, left alone when not', json_encode($l));

// 6. No column (an older table): the sync still links, with no flag.
$old = $mk(false);
check(!kop_news_links_has_passing($old), 'a table without the column is seen as such');
$sync($old, 21, array(array('name' => 'Provo Canyon School', 'facility_id' => 10371, 'passing' => true)));
$l = $links($old, 21);
check($l === array(10371 => null), 'and the sync links without it', json_encode($l));

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
