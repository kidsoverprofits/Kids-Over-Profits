<?php
/**
 * inc/about-rewrites.php on a scratch copy of the production mirror: every seed in seeds/about-rewrites/ finds its
 * page, applies once (the About becomes the record's write-up with its sentences and print sources kept, the quotes go
 * on the facility published, with credit, address and note), a second pass changes nothing, a write-up edited since is
 * left alone, a new version re-applies and replaces the quotes, and _undo.json puts back exactly what was there.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring -d memory_limit=3G scripts/test-about-rewrites.php [--db=tmp/prod.sqlite]
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");
$args = getopt('', array('db::'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($mirror)) { fwrite(STDERR, "No mirror at $mirror\n"); exit(2); }

$db_path = sys_get_temp_dir() . '/kop-about-rewrites-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
foreach (array('facilities_v2', 'wpdl_kop_operators', 'wpdl_kop_operator_facilities', 'wpdl_kop_operator_links', 'wpdl_kop_woodbury_facts', 'wpdl_kop_fornits_items',
    'wpdl_kop_record_writeups', 'wpdl_kop_record_writeup_log', 'news_submissions', 'news_facility_links', 'lawsuits', 'lawsuit_facility_links', 'memorial_victims', 'wpdl_kop_migration_state', 'wpdl_options') as $t) {
    $sql = $copy->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$copy->exec('DETACH DATABASE src');
$copy->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_record_writeups (id INTEGER PRIMARY KEY AUTOINCREMENT, facility_id INTEGER NOT NULL DEFAULT 0, draft TEXT NULL, published TEXT NULL, updated_at TEXT NULL)");
$copy->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_record_writeup_log (id INTEGER PRIMARY KEY AUTOINCREMENT, facility_id INTEGER NOT NULL DEFAULT 0, before_row TEXT NULL, items TEXT NULL, done_by TEXT NULL, done_at TEXT NULL, undone_at TEXT NULL)");
$copy = null;
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });

$GLOBALS['kop_test_wpdb_writes'] = true;
if (!defined('KOP_DB_CONFIG_LOADED')) define('KOP_DB_CONFIG_LOADED', true);
require __DIR__ . '/kop-test-harness.php';
$pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }
// The scratch copy has no migration state: saves are on facilities_v2 (as in scripts/review-inbox-tests/*.php).
if (!function_exists('kop_v2_writes_active')) {
    function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) { return true; }
}
require_once dirname(__DIR__) . '/inc/record-events.php';
require_once dirname(__DIR__) . '/inc/closure-reports.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/profile-notes.php';
require_once dirname(__DIR__) . '/inc/facility-pages.php';
require_once dirname(__DIR__) . '/inc/operator-pages.php';
require_once dirname(__DIR__) . '/inc/about-rewrites.php';
$GLOBALS['kop_test_options']['kop_woodbury_facts_db'] = KOP_WOODBURY_FACTS_DB_VERSION;
$GLOBALS['kop_test_options']['kop_closure_reports_db'] = KOP_CLOSURE_REPORTS_DB_VERSION;
$GLOBALS['kop_test_options']['kop_record_writeups_db'] = KOP_RECORD_WRITEUPS_DB_VERSION;

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$opts = kop_wbf_opts();
$quotes_on = function ($fid, $page) use ($opts) {
    $s = kop_facility_load($fid, $opts);
    return array_values(array_filter((array) ($s['doc']['survivorTestimony'] ?? array()), function ($e) use ($page) { return strpos((string) ($e['id'] ?? ''), 'about:' . $page . ':') === 0; }));
};

echo "-- Every seed finds its page --\n";
$seeds = kop_about_rewrite_seeds();
$check('seeds load', count($seeds) >= 21, (string) count($seeds));
$missing = array();
foreach ($seeds as $s) if (!kop_about_rewrite_resolve($s['page'])) $missing[] = $s['page'];
$check('every page resolves to a record', !$missing, implode(', ', $missing));

echo "-- First pass --\n";
$before = array();
foreach ($seeds as $s) { $r = kop_about_rewrite_resolve($s['page']); if ($r) $before[$s['page']] = kop_wu_get($r[0] === 'facility' ? $r[1] : kop_wu_operator_key($r[1])); }
$sum = kop_apply_about_rewrites();
$check('every seed applied', count($sum['applied']) === count($seeds) && !$sum['skipped'], json_encode($sum['skipped']));

$eagle = kop_about_rewrite_resolve('facility/eagle-ranch-academy-ut')[1];
$about = kop_facility_page_data($eagle)['about'];
$first = $about['paragraphs'][0][0] ?? array();
$check('the page About is the rewrite', !empty($about['writeup']) && strpos((string) ($first['text'] ?? ''), 'Eagle Ranch Academy is a licensed residential treatment center') === 0, (string) ($first['text'] ?? ''));
$check('its sources keep their citation labels', ($first['sources'][0]['cite'] ?? '') !== '' && ($first['sources'][0]['url'] ?? '') !== '');
$html = kop_pnotes_html($about);
$check('the About renders', strpos($html, 'St. George, Utah') !== false && strpos($html, 'kop-fp-src') !== false);

$aspen = kop_about_rewrite_resolve('facility/aspen-ranch-ut')[1];
$print = 0;
foreach (kop_wu_get($aspen)['published']['sentences'] as $p) foreach ($p as $sn) foreach ($sn['sources'] as $src) if ($src['url'] === '' && $src['cite'] !== '') $print++;
$check('print sources (no address) are kept with their citation', $print > 0, "$print");

$q = $quotes_on($eagle, 'facility/eagle-ranch-academy-ut');
$check('Eagle Ranch has its quotes, published (the recording with no transcript is skipped)', count($q) === 4 && !in_array(false, array_column($q, 'publish'), true), (string) count($q));
$shown = kop_facility_pages_testimony(kop_facility_load($eagle, $opts)['doc']['survivorTestimony']);
$mine = array_values(array_filter($shown, function ($t) { return $t['credit'] !== ''; }));
$check('the page shows them with credit and address', count($mine) === 4 && $mine[0]['url'] !== '' && strpos($mine[0]['credit'], 'WWASP Survivors') !== false, json_encode($mine[0] ?? null));
$falcon = kop_about_rewrite_resolve('facility/falcon-ridge-ranch-ut')[1];
$fq = $quotes_on($falcon, 'facility/falcon-ridge-ranch-ut');
$check('a quote naming staff carries the allegation note', (bool) array_filter($fq, function ($e) { return strpos((string) ($e['note'] ?? ''), 'Nale Fakahua') !== false; }));
$check("no writer's own name in a credit (Dj hansen, Chris)", !array_filter(array_merge($q, $quotes_on(100753, 'operator/teen-challenge')), function ($e) { return preg_match('/hansen|Chris\b/i', $e['source']); }));
$check("a company's quote goes on the program it names (Teen Challenge -> Prayer Mountain Academy)", count($quotes_on(100753, 'operator/teen-challenge')) === 1);
$tc = kop_about_rewrite_resolve('operator/teen-challenge')[1];
$check('the company page About is the rewrite', !empty(kop_operator_page_data($tc)['about']['writeup']));

echo "-- Again, edited since, new version, undo --\n";
$sum2 = kop_apply_about_rewrites();
$check('a second pass changes nothing', !$sum2['applied'] && !$sum2['skipped'], json_encode($sum2));
$check('and does not double the quotes', count($quotes_on($eagle, 'facility/eagle-ranch-academy-ut')) === 4);

$wu = kop_wu_get($aspen);
$pub = $wu['published'];
$pub['by'] = 'owner';
kop_wu_put($aspen, $wu['draft'], $pub);
$dir = dirname(__DIR__) . '/seeds/about-rewrites';
$orig = file_get_contents($dir . '/facility-aspen-ranch-ut.json');
$GLOBALS['kop_test_seed_override'] = true;
$edit = json_decode($orig, true);
$edit['version'] = 'test-new-version';
file_put_contents($dir . '/facility-aspen-ranch-ut.json', json_encode($edit));
try {
    $sum3 = kop_apply_about_rewrites();
    $check('a write-up edited since is left alone', !in_array('facility/aspen-ranch-ut', $sum3['applied'], true) && kop_wu_get($aspen)['published']['by'] === 'owner');
    $pub['by'] = KOP_ABOUT_REWRITE_BY;
    kop_wu_put($aspen, $wu['draft'], $pub);
    $edit['testimony'] = array_slice($edit['testimony'], 0, 1);
    file_put_contents($dir . '/facility-aspen-ranch-ut.json', json_encode($edit));
    $sum4 = kop_apply_about_rewrites();
    $check('a new version re-applies', in_array('facility/aspen-ranch-ut', $sum4['applied'], true) && kop_wu_get($aspen)['published']['seed'] === 'test-new-version');
    $check('and replaces the quotes it put on before', count($quotes_on($aspen, 'facility/aspen-ranch-ut')) === 1);
    file_put_contents($dir . '/_undo.json', json_encode(array('facility/aspen-ranch-ut')));
    $other = count($quotes_on($eagle, 'facility/eagle-ranch-academy-ut'));
    $sum5 = kop_apply_about_rewrites();
    $restored = kop_wu_get($aspen)['published'];
    $check('_undo.json puts back exactly what was there', in_array('facility/aspen-ranch-ut', $sum5['undone'], true) && $restored == $before['facility/aspen-ranch-ut']['published'], json_encode($sum5));
    $check('and takes its quotes off, others untouched', !$quotes_on($aspen, 'facility/aspen-ranch-ut') && count($quotes_on($eagle, 'facility/eagle-ranch-academy-ut')) === $other);
    $sum6 = kop_apply_about_rewrites();
    $check('it stays undone while listed', !in_array('facility/aspen-ranch-ut', $sum6['applied'], true) && !in_array('facility/aspen-ranch-ut', $sum6['undone'], true));
} finally {
    file_put_contents($dir . '/facility-aspen-ranch-ut.json', $orig);
    @unlink($dir . '/_undo.json');
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
