<?php
/**
 * Offline check of inc/seo.php against the production mirror (tmp/prod.sqlite): titles and descriptions
 * that name a program's past and other names, the structured data for facility and company pages, and the
 * description fallback for posts. WordPress is stubbed by scripts/kop-test-harness.php; read-only.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-seo.php [--db=tmp/prod.sqlite] [--sample=150] [--id=12161] [--list]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$args = getopt('', array('db::', 'sample::', 'id::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$sample = isset($args['sample']) ? (int) $args['sample'] : 150;
$list = isset($args['list']);
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/seo.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

echo "-- Words --\n";
$check('a short text is kept whole', kop_seo_clip('Short text.') === 'Short text.');
$long = str_repeat('word ', 60);
$c = kop_seo_clip($long);
$check('a long text is cut at a word, with "..."', mb_strlen($c) <= KOP_SEO_DESC_MAX && substr($c, -3) === '...' && strpos($c, 'wor...') === false, $c);
$s2 = 'Sedona Sky Academy was a therapeutic boarding school in Rimrock, Arizona, operated by EmotiCare. It operated from 2014 to 2025 and was formerly Copper Canyon Academy in its first years.';
$check('a cut ends on a whole sentence when one ends far enough in', kop_seo_clip('Formerly Copper Canyon Academy. ' . $s2) === 'Formerly Copper Canyon Academy. Sedona Sky Academy was a therapeutic boarding school in Rimrock, Arizona, operated by EmotiCare.');
$check('one past name leads with Formerly', kop_seo_names_lead(array(array('past', 'Copper Canyon Academy'))) === 'Formerly Copper Canyon Academy.');
$check('two past names and another name', kop_seo_names_lead(array(array('past', 'A'), array('past', 'B'), array('other', 'C'))) === 'Formerly A and B; Also known as C.');
$check('no names, no lead', kop_seo_names_lead(array()) === '');

echo "-- Facility pages --\n";
$index = kop_facility_pages_index(true);
$ids = array();
if (!empty($args['id'])) {
    $ids = array_map('intval', explode(',', (string) $args['id']));
} else {
    // Every record with another name, then a sample of the rest.
    foreach ($wpdb->get_results('SELECT id, json_data FROM facilities_v2', ARRAY_A) as $r) {
        if (!isset($index['ids'][(int) $r['id']]) || $index['ids'][(int) $r['id']]['editorial'] !== '') continue;
        $d = json_decode((string) $r['json_data'], true);
        $i = (array) ($d['identification'] ?? array());
        if (!empty($i['pastNames']) || !empty($i['otherNames'])) $ids[] = (int) $r['id'];
    }
    $rest = array_values(array_diff(array_keys($index['ids']), $ids));
    mt_srand(7);
    shuffle($rest);
    $ids = array_merge(array(12161), $ids, array_slice($rest, 0, $sample));
    $ids = array_values(array_unique($ids));
}
$named = $long_titles = $bad_desc = $bad_json = $leaks = $no_alt = 0;
$examples = array();
foreach ($ids as $id) {
    $page = kop_facility_page_data($id);
    if (!$page) continue;
    $title = kop_seo_facility_title($page);
    $desc = kop_seo_facility_description($page);
    $names = kop_seo_other_names($page);
    $past = array_values(array_filter($names, function ($p) { return $p[0] === 'past'; }));
    if ($past) {
        $named++;
        if (stripos($title, 'formerly ' . $past[0][1]) === false) $examples['title without its past name'][] = "$id $title";
        if (strpos($desc, 'Formerly ') !== 0) $examples['description not opening with Formerly'][] = "$id $desc";
    } elseif ($title !== $page['seo_title']) {
        $examples['title changed with no past name'][] = "$id $title";
    }
    if (mb_strlen($title) > KOP_SEO_TITLE_MAX + 10 && $past && $title !== $page['name'] . ', formerly ' . $past[0][1]) $long_titles++;
    if ($desc === '' || mb_strlen($desc) > KOP_SEO_DESC_MAX) { $bad_desc++; $examples['bad description'][] = "$id [" . mb_strlen($desc) . "] $desc"; }
    $pieces = kop_seo_facility_pieces($page);
    $json = json_encode($pieces, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || count($pieces) !== 3) { $bad_json++; continue; }
    $org = $pieces[2];
    $alt = $org['alternateName'] ?? array();
    if (count($alt) !== count($names)) { $no_alt++; $examples['alternateName != other names'][] = $id; }
    // Only names, place, years, the company and the summary leave the page data; never notes or contacts.
    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $json) || strpos($json, '"notes"') !== false || strpos($json, '"doc"') !== false) { $leaks++; $examples['leak'][] = $id; }
    if ($list) echo "  $id | $title\n      $desc\n";
}
$n = count($ids);
echo "  checked $n pages, $named with a past name\n";
foreach ($examples as $what => $rows) echo "  $what: " . implode(' | ', array_slice($rows, 0, 4)) . "\n";
$check('every page with a past name has it in the title and opens its description with it', empty($examples['title without its past name']) && empty($examples['description not opening with Formerly']));
$check('a page with no past name keeps its own title', empty($examples['title changed with no past name']));
$check('no title runs long when a shorter form fits', $long_titles === 0, (string) $long_titles);
$check('every description is there and fits', $bad_desc === 0, (string) $bad_desc);
$check('structured data encodes for every page, three pieces', $bad_json === 0, (string) $bad_json);
$check('alternateName lists every other name', $no_alt === 0, (string) $no_alt);
$check('nothing private reaches the structured data', $leaks === 0, (string) $leaks);

$sedona = kop_facility_page_data(12161);
if ($sedona) {
    $t = kop_seo_facility_title($sedona);
    echo "  12161 title: $t\n  12161 description: " . kop_seo_facility_description($sedona) . "\n";
    $check('Sedona Sky Academy is found by Copper Canyon Academy', stripos($t, 'formerly Copper Canyon Academy') !== false);
    $p = kop_seo_facility_pieces($sedona);
    $check('its Organization carries the old name, the years and the company', in_array('Copper Canyon Academy', $p[2]['alternateName'] ?? array(), true)
        && !empty($p[2]['foundingDate']) && !empty($p[2]['parentOrganization']['name']), json_encode($p[2], JSON_UNESCAPED_SLASHES));
    $check('the WebPage points at the program and the breadcrumb', $p[0]['about']['@id'] === $p[2]['@id'] && $p[0]['breadcrumb']['@id'] === $p[1]['@id']);
    $check('the breadcrumb ends on the page, unlinked', end($p[1]['itemListElement'])['name'] === $sedona['name'] && !isset(end($p[1]['itemListElement'])['item']));

    // Added to Yoast's graph: one piece of the same @id is replaced, the rest kept.
    $GLOBALS['kop_facility_page'] = $sedona;
    $graph = kop_seo_schema_graph(array(array('@type' => 'WebSite', '@id' => home_url('/') . '#website'), array('@type' => 'WebPage', '@id' => $sedona['url'])));
    $types = array_map(function ($g) { return $g['@type']; }, $graph);
    $check("Yoast's graph keeps its own pieces and takes this page's", $types === array('WebSite', 'WebPage', 'BreadcrumbList', 'Organization'), implode(',', $types));
    unset($GLOBALS['kop_facility_page']);
    $check('any other page leaves the graph alone', kop_seo_schema_graph(array(1, 2)) === array(1, 2));
}

echo "-- Company pages --\n";
$ops = kop_operator_pages_index(true);
$op_bad = 0;
$op_named = 0;
foreach (array_keys($ops['ids']) as $oid) {
    $page = kop_operator_page_data($oid);
    if (!$page) continue;
    $p = kop_seo_operator_pieces($page);
    $subs = count($p[2]['subOrganization'] ?? array());
    $want = min(100, count(array_filter((array) $page['facilities'], function ($f) { return is_array($f) && trim((string) ($f['name'] ?? '')) !== ''; })));
    if ($subs !== $want || json_encode($p) === false) { $op_bad++; echo "  bad: $oid {$page['name']} subs $subs of $want\n"; }
    if (kop_seo_other_names($page)) {
        $op_named++;
        if ($list || $op_named <= 3) echo '  ' . kop_seo_operator_title($page) . "\n      " . kop_seo_operator_description($page) . "\n";
    }
    $d = kop_seo_operator_description($page);
    if ($d === '' || mb_strlen($d) > KOP_SEO_DESC_MAX) { $op_bad++; echo "  bad description: $oid $d\n"; }
}
$check('every company page lists its programs as subOrganization and has a description', $op_bad === 0, count($ops['ids']) . " companies, $op_named with another name");

echo "-- Posts --\n";
$post = (object) array('post_excerpt' => '', 'post_content' => '<p>ABC News10 reported <a href="https://x.test/a">here</a> that a worker at the center was charged.</p>[gallery ids="1"] https://news.test/story <script>bad()</script>');
$d = kop_seo_post_description($post);
$check('a post with no excerpt is described by its opening words, plain', $d === 'ABC News10 reported here that a worker at the center was charged.', $d);
$post->post_excerpt = 'The excerpt wins.';
$check('an excerpt wins', kop_seo_post_description($post) === 'The excerpt wins.');
foreach (kop_seo_template_descriptions() as $tpl => $text) {
    $check("$tpl has a description that fits", mb_strlen(sprintf($text, 'Utah')) <= KOP_SEO_DESC_MAX, (string) mb_strlen(sprintf($text, 'Utah')));
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
