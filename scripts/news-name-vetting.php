<?php
/**
 * The worklist for vetting the names in live news articles that link to no
 * facility (plan 4.3 item 22): each article with its names, what the
 * Facilities from News scan decided for each, and up to five look-alike
 * records (kop_facdisc_lookalikes()), cut into batches for the sorting agents.
 * Reads a mirror only; writes nothing to it.
 *
 *   php scripts/news-name-vetting.php [--db=tmp/prod.sqlite] [--out=<dir>] [--batch=25] [--extra=<names.json>]
 *
 * --extra: names pulled by hand or by an agent for articles that list none,
 * {"<news id>": ["name", ...]}; they are looked up like the stored ones.
 * Writes <out>/worklist.json (every unlinked article), <out>/batch-NN.json
 * (the articles with a name to sort) and <out>/no-names.json (articles that
 * list no name), and prints the counts.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$root = dirname(__DIR__);
$args = getopt('', array('db::', 'out::', 'batch::', 'extra::'));
$db_path = $args['db'] ?? ($root . '/tmp/prod.sqlite');
$out_dir = $args['out'] ?? ($root . '/tmp/news-name-vetting');
$per = max(1, (int) ($args['batch'] ?? 25));
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (python ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
$extra = array();
if (!empty($args['extra'])) {
    $extra = json_decode((string) file_get_contents($args['extra']), true);
    if (!is_array($extra)) {
        fwrite(STDERR, "--extra is not a JSON object.\n");
        exit(2);
    }
}

// inc/facility-discovery.php is a theme file; these are all it needs to load.
define('ABSPATH', $root . '/');
function add_action() {}
function add_filter() {}
function get_stylesheet_directory() { return dirname(__DIR__); }
$GLOBALS['table_prefix'] = 'wpdl_';   // the v2 facility and operator tables
$pdo = new PDO('sqlite:' . $db_path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
require $root . '/inc/facility-discovery.php';

$index = kop_facdisc_alias_index($pdo);
$operators = $index['operators'] ?? array();

$scan = array();
foreach ($pdo->query('SELECT name_key, decision, facility_id FROM news_facility_candidates') as $r) {
    $scan[$r['name_key']] = array($r['decision'], $r['facility_id'] !== null ? (int) $r['facility_id'] : null);
}
$on_company = array();
foreach ($pdo->query("SELECT l.link_id, o.name FROM wpdl_kop_operator_links l JOIN wpdl_kop_operators o ON o.id = l.operator_id WHERE l.link_kind = 'news'") as $r) {
    $on_company[(int) $r['link_id']][] = $r['name'];
}

$look = function ($name) use ($pdo, $index) {
    return array_map(function ($l) {
        return array('id' => (int) $l['id'], 'name' => (string) $l['unique_name'],
            'place' => trim(implode(', ', array_filter(array($l['city'], $l['state'] ?: $l['country']))), ', '), 'status' => (string) $l['status']);
    }, kop_facdisc_lookalikes($pdo, $name, $index, 5));
};

$rows = $pdo->query("SELECT id, article_title, publication_name, publication_date, article_url, article_location, summary, facilities_mentioned
                       FROM news_submissions n
                      WHERE status IN ('approved', 'published')
                        AND NOT EXISTS (SELECT 1 FROM news_facility_links l WHERE l.news_id = n.id)
                      ORDER BY id")->fetchAll();

$counts = array('articles' => count($rows), 'names' => 0, 'company' => 0, 'record' => 0, 'lookalikes' => 0, 'none' => 0, 'no_names' => 0, 'from_extra' => 0);
$list = array();
foreach ($rows as $r) {
    $nid = (int) $r['id'];
    $names = array();
    foreach (kop_normalize_facility_mentions($r['facilities_mentioned']) as $m) {
        $names[] = array($m['name'], 'article');
    }
    foreach ((array) ($extra[(string) $nid] ?? array()) as $n) {
        if (is_string($n) && trim($n) !== '') $names[] = array(trim($n), 'pulled');
    }
    $a = array('id' => $nid, 'title' => (string) $r['article_title'], 'outlet' => (string) $r['publication_name'],
        'date' => (string) $r['publication_date'], 'url' => (string) $r['article_url'], 'location' => (string) $r['article_location'],
        'summary' => (string) $r['summary'], 'on_company_pages' => $on_company[$nid] ?? array(), 'names' => array());
    $seen = array();
    foreach ($names as $pair) {
        list($name, $from) = $pair;
        $key = kop_normalize_name_key($name);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $n = array('name' => $name, 'from' => $from, 'scan' => $scan[$key][0] ?? null, 'scan_record' => $scan[$key][1] ?? null);
        $counts['names']++;
        if ($from === 'pulled') $counts['from_extra']++;
        $fid = kop_resolve_mention_to_facility($name, $index);
        if ($fid !== null && isset($operators[$fid])) {
            // On the company's /operator/ page already: nothing to sort.
            $n['resolves'] = array('operator', $fid, (string) ($index['names'][$fid] ?? ''));
            $counts['company']++;
        } else {
            $n['lookalikes'] = $look($name);
            if ($fid !== null) {
                // An exact match the relink held back (a bare name beside "<name> (...)"): the record is one choice, not the answer.
                $n['resolves'] = array('facility', $fid, (string) ($index['names'][$fid] ?? ''));
                if (!in_array($fid, array_column($n['lookalikes'], 'id'), true)) {
                    $row = $pdo->query('SELECT id, unique_name, city, state, country, status FROM facilities_v2 WHERE id = ' . (int) $fid)->fetch();
                    if ($row) array_unshift($n['lookalikes'], array('id' => (int) $row['id'], 'name' => (string) $row['unique_name'],
                        'place' => trim(implode(', ', array_filter(array($row['city'], $row['state'] ?: $row['country']))), ', '), 'status' => (string) $row['status']));
                }
                $counts['record']++;
            } else {
                $counts[$n['lookalikes'] ? 'lookalikes' : 'none']++;
            }
        }
        $a['names'][] = $n;
    }
    if (!$a['names']) $counts['no_names']++;
    $list[] = $a;
}

if (!is_dir($out_dir)) mkdir($out_dir, 0777, true);
foreach (glob($out_dir . '/batch-*.json') ?: array() as $old) unlink($old);
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
file_put_contents($out_dir . '/worklist.json', json_encode($list, $flags));
$to_sort = array_values(array_filter($list, function ($a) {
    foreach ($a['names'] as $n) if (isset($n['lookalikes'])) return true;
    return false;
}));
$no_names = array_values(array_filter($list, function ($a) { return !$a['names']; }));
file_put_contents($out_dir . '/no-names.json', json_encode($no_names, $flags));
foreach (array_chunk($to_sort, $per) as $i => $chunk) {
    file_put_contents(sprintf('%s/batch-%02d.json', $out_dir, $i + 1), json_encode($chunk, $flags));
}
printf("%d live articles link to no facility; %d names (%d pulled by hand): %d on a company page, %d an exact record held back,\n"
    . "%d with look-alikes, %d with none; %d articles list no name.\n%d articles to sort in %d batches of up to %d -> %s\n",
    $counts['articles'], $counts['names'], $counts['from_extra'], $counts['company'], $counts['record'], $counts['lookalikes'], $counts['none'],
    $counts['no_names'], count($to_sort), (int) ceil(count($to_sort) / $per), $per, $out_dir);
