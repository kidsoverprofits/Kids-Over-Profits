<?php
/**
 * News articles and cases are saved with their facility ids (kop_mentions_fill_ids(), kop_mentions_save_ids() in
 * api/facility-aliases.php, run by kop_sync_news_facility_links() and kop_sync_lawsuit_facility_links()).
 * A bare name several states' records share ("Lakeside Academy": MI, MN, UT) keeps the one record of that name the
 * item is linked to, and is never sent to another; the stored mentions get the id.
 *
 * Runs the real syncs in an in-memory database: news and case tables are scratch copies there, the facility records
 * are read from tmp/prod.sqlite (attached read-only). Nothing is written to the mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-mention-ids.php [--db=<mirror>]
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

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("ATTACH DATABASE 'file:" . str_replace(array('\\', "'"), array('/', "''"), realpath($mirror)) . "?mode=ro' AS m");
$ro = false;
try { $pdo->exec('CREATE TABLE m.kop_test_probe (x)'); $pdo->exec('DROP TABLE m.kop_test_probe'); } catch (Throwable $e) { $ro = true; }
if (!$ro) { echo "FAIL the mirror did not attach read-only; stopping\n"; exit(1); }

// Scratch tables shadow the mirror's (an unqualified name is looked up in main first).
$pdo->exec('CREATE TABLE news_submissions (id INTEGER PRIMARY KEY, facilities_mentioned TEXT)');
$pdo->exec('CREATE TABLE news_facility_links (news_id INTEGER, facility_id INTEGER, link_type TEXT, created_at TEXT, created_by TEXT, PRIMARY KEY (news_id, facility_id))');
$pdo->exec('CREATE TABLE lawsuits (id INTEGER PRIMARY KEY, facilities_mentioned TEXT)');
$pdo->exec('CREATE TABLE wpdl_kop_operator_links AS SELECT * FROM m.wpdl_kop_operator_links WHERE 0');

$MI = 100098; $MN = 100099; $UT = 10459;
$news = function ($id, $mentions, array $links) use ($pdo) {
    $pdo->prepare('INSERT INTO news_submissions (id, facilities_mentioned) VALUES (?, ?)')->execute(array($id, json_encode($mentions)));
    foreach ($links as $fid => $type) {
        $pdo->prepare("INSERT INTO news_facility_links (news_id, facility_id, link_type) VALUES (?, ?, ?)")->execute(array($id, $fid, $type));
    }
    $row = $pdo->query('SELECT facilities_mentioned FROM news_submissions WHERE id = ' . (int) $id)->fetchColumn();
    kop_sync_news_facility_links($pdo, $id, kop_normalize_facility_mentions($row), 'test');
    return array(
        'links' => array_map('intval', $pdo->query('SELECT facility_id FROM news_facility_links WHERE news_id = ' . (int) $id . ' ORDER BY facility_id')->fetchAll(PDO::FETCH_COLUMN)),
        'stored' => json_decode($pdo->query('SELECT facilities_mentioned FROM news_submissions WHERE id = ' . (int) $id)->fetchColumn(), true),
    );
};
$id_of = function (array $stored, $name) {
    foreach ($stored as $e) if (is_array($e) && ($e['name'] ?? '') === $name) return $e['facility_id'] ?? null;
    return 'missing';
};

// 1. News 597 as saved: bare "Lakeside Academy", no id, linked to Michigan by an older sync.
$r = $news(1, array(array('name' => 'Lakeside Academy', 'facility_id' => null), array('name' => 'Sequel Youth & Family Services', 'facility_id' => null)), array($MI => 'mentioned'));
check($r['links'] === array($MI), 'a re-save keeps the Michigan link of a bare shared name', json_encode($r['links']));
check($id_of($r['stored'], 'Lakeside Academy') === $MI, 'and the article is saved with that id', json_encode($r['stored']));
check(array_key_exists('facility_id', $r['stored'][1]), 'the other entry keeps its shape', json_encode($r['stored'][1]));

// 2. The bare name with no link: no record, never Minnesota.
$r = $news(2, array(array('name' => 'Lakeside Academy', 'facility_id' => null)), array());
check($r['links'] === array() && $id_of($r['stored'], 'Lakeside Academy') === null, 'unlinked bare name stays unlinked (not MN, not UT)', json_encode($r));

// 3. The place given settles it, and is saved.
$r = $news(3, array(array('name' => 'Lakeside Academy (Buffalo, Minnesota)', 'facility_id' => null)), array());
check($r['links'] === array($MN) && $id_of($r['stored'], 'Lakeside Academy (Buffalo, Minnesota)') === $MN, 'a name with its place is saved with that record', json_encode($r));

// 4. A plain-string entry becomes {name, facility_id}.
$r = $news(4, array('Lakeside Academy'), array($MI => 'related'));
check($r['stored'] === array(array('name' => 'Lakeside Academy', 'facility_id' => $MI)), 'a plain-string entry is saved as {name, facility_id}', json_encode($r['stored']));

// 5. An id already stored is never changed.
$r = $news(5, array(array('name' => 'Lakeside Academy', 'facility_id' => $MN)), array($MI => 'related'));
check($id_of($r['stored'], 'Lakeside Academy') === $MN && in_array($MN, $r['links'], true), 'a stored id is kept as it is', json_encode($r));

// 6. Linked to two records of the name: not settled, both links stay as they were typed.
$r = $news(6, array(array('name' => 'Lakeside Academy', 'facility_id' => null)), array($MI => 'related', $UT => 'related'));
check($id_of($r['stored'], 'Lakeside Academy') === null && $r['links'] === array($UT, $MI), 'two linked records of the name: no id is guessed', json_encode($r));

// 7. Cases do the same.
$pdo->prepare('INSERT INTO lawsuits (id, facilities_mentioned) VALUES (?, ?)')->execute(array(7, json_encode(array(array('name' => 'Lakeside Academy', 'facility_id' => null)))));
kop_lawsuit_links_ensure_table($pdo);
$pdo->exec("INSERT INTO lawsuit_facility_links (lawsuit_id, facility_id, link_type) VALUES (7, $MI, 'mentioned')");
$got = kop_sync_lawsuit_facility_links($pdo, 7, $pdo->query('SELECT facilities_mentioned FROM lawsuits WHERE id = 7')->fetchColumn(), 'test');
$stored = json_decode($pdo->query('SELECT facilities_mentioned FROM lawsuits WHERE id = 7')->fetchColumn(), true);
check($got === array($MI) && $id_of($stored, 'Lakeside Academy') === $MI, 'a case re-save keeps its Michigan link and is saved with the id', json_encode(array($got, $stored)));

// 8. Every live article linked to Michigan by a bare "Lakeside Academy": filled with Michigan's id, none elsewhere.
$index = kop_build_facility_alias_index($pdo);
$rows = $pdo->query("SELECT n.id, n.facilities_mentioned FROM m.news_submissions n WHERE n.status <> 'deleted'
    AND n.id IN (SELECT news_id FROM m.news_facility_links WHERE facility_id = $MI)")->fetchAll();
$filled = 0; $wrong = 0; $bare = 0;
foreach ($rows as $row) {
    $links = array();
    foreach ($pdo->query('SELECT facility_id FROM m.news_facility_links WHERE news_id = ' . (int) $row['id'])->fetchAll(PDO::FETCH_COLUMN) as $f) $links[(int) $f] = 'mentioned';
    $pdo->exec('DELETE FROM news_facility_links WHERE news_id = ' . (int) $row['id']);
    $pdo->exec('DELETE FROM news_submissions WHERE id = ' . (int) $row['id']);
    $pdo->prepare('INSERT INTO news_submissions (id, facilities_mentioned) VALUES (?, ?)')->execute(array($row['id'], $row['facilities_mentioned']));
    foreach ($links as $f => $t) $pdo->prepare('INSERT INTO news_facility_links (news_id, facility_id, link_type) VALUES (?, ?, ?)')->execute(array($row['id'], $f, $t));
    $before = kop_normalize_facility_mentions($row['facilities_mentioned']);
    $after = kop_mentions_fill_ids($pdo, 'news', (int) $row['id'], $before, $index);
    foreach ($after as $i => $m) {
        if (kop_normalize_name_key($m['name']) !== 'lakeside academy' || !empty($before[$i]['facility_id'])) continue;
        $bare++;
        if ((int) $m['facility_id'] === $MI) $filled++;
        elseif (!empty($m['facility_id'])) $wrong++;
    }
}
check($bare > 0 && $filled === $bare && $wrong === 0, 'live articles: every bare "Lakeside Academy" linked to MI gets MI', "$filled of $bare filled, $wrong elsewhere, " . count($rows) . ' articles');

echo $fails ? "$fails failed\n" : "all passed\n";
exit($fails ? 1 : 0);
