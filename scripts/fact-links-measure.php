<?php
/**
 * What the open linking passes of docs/plan/4.4 item 20 would link on the production mirror: read only, nothing is written.
 *   php -d extension=pdo_sqlite -d extension=mbstring -d memory_limit=2G scripts/fact-links-measure.php [--db=tmp/prod.sqlite] [--examples=5]
 * Pages: Woodbury Reports pages with no record whose header is exactly one record's name (kop_autoconn_woodbury_nomatch_rows()).
 * Companies: waiting news facts about a company that is exactly one operator's name (kop_nf_link_companies(), count mode).
 * People: waiting news facts about a person on one record's staff, the article linked to it or the fact naming it (kop_nf_link_people()).
 * States: waiting facts the same-state rule turns away from an exact program name (kop_nf_record_for()).
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
$opt = array();
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
}
$db_path = (string) ($opt['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite'));
if (!file_exists($db_path)) { fwrite(STDERR, "Need $db_path (python ../kop-pipeline/scripts/sync-prod-sqlite.py).\n"); exit(2); }
$N = isset($opt['examples']) ? max(0, (int) $opt['examples']) : 5;
ini_set('memory_limit', '2G');

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/woodbury-mentions.php';
require_once dirname(__DIR__) . '/inc/woodbury-create.php';
require_once dirname(__DIR__) . '/inc/auto-connect.php';
require_once dirname(__DIR__) . '/inc/news-facts.php';
if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }

$pdo = $GLOBALS['pdo'];
$show = function ($title, $total, array $ex) use ($N) {
    echo "--- $title: $total ---\n";
    foreach (array_slice($ex, 0, $N) as $e) echo '  ' . $e . "\n";
    echo "\n";
};

// 1. Pages.
$rows = kop_autoconn_woodbury_nomatch_rows(100000);
$names = array();
$ex = array();
foreach ($rows as $row) {
    list($r, $fid) = $row;
    $names[strtolower($r['header'])] = true;
    $ex[] = $r['issue_label'] . ' p' . $r['pages'] . ' "' . $r['header'] . '" (' . $r['facility_state'] . ') -> #' . $fid;
}
$show('Woodbury Reports pages with no record that match one record by exact name (' . count($names) . ' names)', count($rows), $ex);
echo '  names: ' . implode('; ', array_keys($names)) . "\n\n";

// 2. Companies.
$c = kop_nf_link_companies($pdo, false);
$show('News facts about a company that is exactly one operator (checked ' . $c['checked'] . ', ambiguous ' . $c['ambiguous'] . ')', $c['linked'], $c['examples']);

// 3. People.
$p = kop_nf_link_people($pdo, false);
$show('News facts about a person on one record\'s staff, the article or the fact naming that record (checked ' . $p['checked'] . ')', $p['linked'], $p['examples']);

// 4. The state rule on exact program names.
$refused = 0;
$ex = array();
$kept = 0;
foreach (kop_nf_unlinked_rows($pdo, array('facility', 'company')) as $r) {
    list($fid, $how) = kop_nf_record_for($pdo, $r);
    if ($how === 'state') {
        $refused++;
        $ex[] = 'n' . $r['id'] . ' "' . $r['subject_name'] . '" article states ' . implode('/', kop_nf_article_states($pdo, (int) $r['news_id'], $r['summary'] . ' ' . $r['quote']));
    } elseif ($how === 'exact' || $how === 'article') {
        $kept++;
    }
}
$show('Exact program names the state rule refuses (still linked: ' . $kept . ')', $refused, $ex);
