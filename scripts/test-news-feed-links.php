<?php
/**
 * The news feed's record links (kop_news_mention_links(), api/news-mentions.php;
 * templates/page-news-feed.php): each card links the facility and operator pages
 * its article is linked to, and only those. On tmp/prod.sqlite, read-only:
 *
 *   - every stored link with a page shows on its card, once;
 *   - a name with no stored link stays text;
 *   - a company's legal suffix ("Teen Challenge Inc.") still finds the company
 *     when an article is saved (kop_facility_name_variants()).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-news-feed-links.php [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$root = dirname(__DIR__);
$args = getopt('', array('db::'));
$mirror = $args['db'] ?? ($root . '/tmp/prod.sqlite');

$fails = 0;
function check($ok, $label, $detail = '') {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fails++;
}

// The page helpers (inc/facility-pages.php, inc/operator-pages.php) need WordPress: every
// record has a page here, and an operator is found by its name without the legal suffix.
$GLOBALS['table_prefix'] = 'wpdl_';
function kop_v2_detect_prefix($pdo) { return 'wpdl_'; }
function kop_facility_page_url($id) { return (int) $id > 0 ? '/facility/' . (int) $id . '/' : ''; }
function kop_operator_page_url($id) { return (int) $id > 0 ? '/operator/' . (int) $id . '/' : ''; }
function kop_operator_page_id_for_name($name) {
    global $operator_names;
    $k = trim((string) preg_replace('/\b(inc|llc|corp|corporation|company|co|ltd)$/', '', kop_normalize_name_key($name)));
    return $operator_names[$k] ?? 0;
}

require $root . '/api/news-mentions.php';

// ---- Names with a legal suffix (no database) ----------------------------------
$v = kop_facility_name_variants('Teen Challenge Inc.');
check(in_array('Teen Challenge', $v, true) && $v[0] === 'Teen Challenge Inc.', '"Teen Challenge Inc." is tried as given, then as "Teen Challenge"', implode(' | ', $v));
$v = kop_facility_name_variants('Three Springs, LLC');
check(in_array('Three Springs', $v, true), '"Three Springs, LLC" -> "Three Springs"', implode(' | ', $v));
$v = kop_facility_name_variants('Hyde School (Bath, Maine)');
check($v === array('Hyde School (Bath, Maine)', 'Hyde School'), 'a place in brackets is unchanged', implode(' | ', $v));
$v = kop_facility_name_variants('Acme Company Academy');
check($v === array('Acme Company Academy'), 'a word like "Company" inside a name is left alone', implode(' | ', $v));

if (!file_exists($mirror)) {
    echo "SKIP the mirror checks: no $mirror (python ../kop-pipeline/scripts/sync-prod-sqlite.py)\n";
    exit($fails ? 1 : 0);
}
$pdo = new PDO('sqlite:file:' . str_replace('\\', '/', $mirror) . '?mode=ro', null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$operator_names = array();
foreach ($pdo->query('SELECT id, name FROM wpdl_kop_operators') as $o) {
    $k = trim((string) preg_replace('/\b(inc|llc|corp|corporation|company|co|ltd)$/', '', kop_normalize_name_key($o['name'])));
    if ($k !== '' && !isset($operator_names[$k])) $operator_names[$k] = (int) $o['id'];
}

// ---- The save-time resolution on the real records ------------------------------
$index = kop_build_facility_alias_index($pdo);
$tc = (int) $pdo->query("SELECT id FROM wpdl_kop_operators WHERE name = 'Teen Challenge'")->fetchColumn();
if ($tc > 0) {
    $fid = kop_resolve_mention_to_facility('Teen Challenge Inc.', $index);
    check($fid === $tc && isset($index['operators'][$tc]), '"Teen Challenge Inc." resolves to the Teen Challenge company', var_export($fid, true) . " vs $tc");
}

// ---- The feed's last 300 approved articles -----------------------------------
$rows = $pdo->query("SELECT id, facilities_mentioned FROM news_submissions WHERE status IN ('approved','published')
                      ORDER BY publication_date DESC, created_at DESC LIMIT 300")->fetchAll();
$t = microtime(true);
$links = kop_news_mention_links($pdo, $rows);
$ms = (int) round((microtime(true) - $t) * 1000);
check(count($links) === count($rows), 'one list per card', count($links) . ' of ' . count($rows) . ", {$ms} ms");

$ids = implode(',', array_map(function ($r) { return (int) $r['id']; }, $rows));
$stored = array(); // news id => [url => true]
foreach ($pdo->query("SELECT l.news_id, l.facility_id FROM news_facility_links l JOIN facilities_v2 f ON f.id = l.facility_id WHERE l.news_id IN ($ids)") as $l) {
    $stored[(int) $l['news_id']][kop_facility_page_url($l['facility_id'])] = true;
}
foreach ($pdo->query("SELECT link_id, operator_id FROM wpdl_kop_operator_links WHERE link_kind = 'news' AND link_id IN ($ids)") as $l) {
    $stored[(int) $l['link_id']][kop_operator_page_url($l['operator_id'])] = true;
}

$missing = $extra = $twice = array();
$linked_cards = $linked_names = $text_names = $added = 0;
foreach ($rows as $r) {
    $nid = (int) $r['id'];
    $mentions = array_map('strtolower', kop_facility_mention_names($r['facilities_mentioned']));
    $shown = array();
    foreach ($links[$nid] ?? array() as $e) {
        if ($e['url'] === '') { $text_names++; continue; }
        $linked_names++;
        if (isset($shown[$e['url']])) $twice[] = "#$nid {$e['url']}";
        $shown[$e['url']] = true;
        if (empty($stored[$nid][$e['url']])) $extra[] = "#$nid {$e['name']} -> {$e['url']}";
        if (!in_array(strtolower($e['name']), $mentions, true)) $added++;
    }
    foreach (array_keys($stored[$nid] ?? array()) as $url) {
        if (!isset($shown[$url])) $missing[] = "#$nid $url";
    }
    if ($shown) $linked_cards++;
}
echo "cards with a record link: $linked_cards of " . count($rows) . "; linked names $linked_names (added under the record's own name: $added), text names $text_names\n";
check(!$missing, 'every stored link shows on its card', implode(', ', array_slice($missing, 0, 5)));
check(!$extra, 'no name links to a page the article is not linked to', implode(', ', array_slice($extra, 0, 5)));
check(!$twice, 'a record shows once per card', implode(', ', array_slice($twice, 0, 5)));
check($linked_cards > count($rows) / 2, 'most cards link a record');

// A name the article mentions links under that name, not the record's.
$kearney = $links[11002] ?? null;
if ($kearney !== null) {
    $hit = array_values(array_filter($kearney, function ($e) { return $e['url'] !== ''; }));
    check($hit && stripos($hit[0]['name'], 'Kearney') !== false, '#11002 links its Kearney YRTC mention', json_encode($kearney));
}

echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
