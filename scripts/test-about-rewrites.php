<?php
/**
 * inc/about-rewrites.php (KOP Tools > About rewrites) on a scratch copy of the production mirror, with the pilot's
 * rewrites (docs/kop-about-rewrite-pilot/out/): a rewrite saved as a draft waits to review, an answer is kept,
 * Publish makes it the page's About (sentences and print sources as written) and puts its quotes on the facility
 * (published, credit, address, note), Undo puts the About and quotes back, Send back puts it on Claude's list with the
 * note and answers, a company's quote goes on the program it names, and the hourly AI drafts are off.
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
require_once dirname(__DIR__) . '/inc/admin-kit.php';
require_once dirname(__DIR__) . '/inc/about-rewrites-screen.php';
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

/** A pilot record (docs/kop-about-rewrite-pilot/out/recNN.json) as Claude sends a rewrite. */
$pilot = function ($rec) {
    $dir = dirname(__DIR__) . '/docs/kop-about-rewrite-pilot/out/';
    $j = json_decode(file_get_contents($dir . $rec . '.json'), true);
    $n = json_decode((string) @file_get_contents($dir . $rec . '.note.json'), true) ?: array();
    $plain = function ($t) { return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8')))); };
    $sentences = array();
    foreach ($j['paragraphs'] as $p) {
        $units = array();
        $pos = 0;
        preg_match_all('/\{([A-Za-z0-9_, ]+)\}/', $p, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $hit) {
            $text = $plain(substr($p, $pos, $hit[0][1] - $pos));
            $src = array();
            foreach (array_map('trim', explode(',', $hit[1][0])) as $k) $src[] = array('url' => $j['sources'][$k][0], 'cite' => $plain($j['sources'][$k][1]));
            if ($text !== '') $units[] = array('text' => $text, 'sources' => $src);
            elseif ($units) $units[count($units) - 1]['sources'] = array_merge($units[count($units) - 1]['sources'], $src);
            $pos = $hit[0][1] + strlen($hit[0][0]);
        }
        if ($units) $sentences[] = $units;
    }
    $quotes = array();
    foreach ((array) ($j['testimony'] ?? array()) as $t) $quotes[] = $t + array('url' => $j['sources'][$t['key'] ?? ''][0] ?? '');
    return array('sentences' => $sentences, 'testimony' => $quotes, 'needs_you' => array(array('ask' => 'A test question?', 'choices' => array('Yes', 'No'))),
        'research' => $n['research'] ?? array(), 'log' => $n['log'] ?? '');
};

echo "-- Save, answer, publish, undo (Eagle Ranch) --\n";
$eagle = kop_about_rewrite_resolve('facility/eagle-ranch-academy-ut')[1];
$page = 'facility/eagle-ranch-academy-ut';
$about_before = kop_facility_page_data($eagle)['about'];
$quotes_before = $quotes_on($eagle, $page);
kop_ar_save_rewrite('facility', $eagle, $pilot('rec04'));
$lists = kop_ar_lists();
$check('a saved rewrite is waiting to review', isset($lists['review'][$eagle]) && !isset($lists['claude'][$eagle]));
$check('the page is unchanged until it is published', kop_facility_page_data($eagle)['about'] == $about_before);
kop_ar_answer($eagle, 0, 'Yes', '');
kop_ar_answer($eagle, 0, 'Yes', 'and a note');
$a = kop_wu_get($eagle)['draft']['answers'][0] ?? array();
$check('an answer is kept, choice and note', ($a['choice'] ?? '') === 'Yes' && ($a['note'] ?? '') === 'and a note');
$check('the screen card renders', strpos(kop_ar_card($lists['review'][$eagle], 'review'), 'A test question?') !== false);
$msg = kop_ar_publish($eagle, 'test');
$about = kop_facility_page_data($eagle)['about'];
$first = $about['paragraphs'][0][0] ?? array();
$check('Publish makes the rewrite the page About', !empty($about['writeup']) && strpos((string) ($first['text'] ?? ''), 'Eagle Ranch Academy is a licensed residential treatment center') === 0, $msg);
$check('with its citation labels', ($first['sources'][0]['cite'] ?? '') !== '');
$q = $quotes_on($eagle, $page);
$check('its quotes are on the page, published (the recording without words skipped)', count($q) === 4 && !in_array(false, array_column($q, 'publish'), true), (string) count($q));
$shown = array_values(array_filter(kop_facility_pages_testimony(kop_facility_load($eagle, $opts)['doc']['survivorTestimony']), function ($t) { return $t['credit'] !== ''; }));
$check('the page shows them with credit and address', count($shown) === 4 && $shown[0]['url'] !== '');
$lists = kop_ar_lists();
$check('it moves to Published', isset($lists['published'][$eagle]) && !isset($lists['review'][$eagle]));
kop_ar_undo($eagle, 'test');
$check('Undo puts the About back', kop_facility_page_data($eagle)['about'] == $about_before);
$check('and the quotes as they were', $quotes_on($eagle, $page) == $quotes_before);
$check('and the rewrite is waiting again', isset(kop_ar_lists()['review'][$eagle]));

echo "-- Send back, Claude's list, rewrite again --\n";
kop_ar_send_back($eagle, 'Shorter lede, please.', 'test');
$lists = kop_ar_lists();
$check('Send back moves it to With Claude', isset($lists['claude'][$eagle]) && !isset($lists['review'][$eagle]));
$mine = array_values(array_filter(kop_ar_claude_list(0, 500), function ($x) use ($eagle) { return $x['kind'] === 'facility' && $x['id'] === $eagle; }));
$check("Claude's list carries the note, the answers and the draft", $mine && $mine[0]['note'] === 'Shorter lede, please.' && ($mine[0]['answers'][0]['answer']['choice'] ?? '') === 'Yes'
    && !empty($mine[0]['draft']['sentences']) && $mine[0]['packet_id'] === 'f' . $eagle);
kop_ar_save_rewrite('facility', $eagle, $pilot('rec04'));
$lists = kop_ar_lists();
$check('a new rewrite takes it off the list and back to review', isset($lists['review'][$eagle]) && !isset($lists['claude'][$eagle]));

echo "-- A company: Teen Challenge --\n";
$tc = kop_about_rewrite_resolve('operator/teen-challenge')[1];
$tkey = kop_ar_key('operator', $tc);
$rw = $pilot('rec17');
foreach ($rw['testimony'] as &$t) $t['facility_id'] = 100753;
unset($t);
kop_ar_save_rewrite('operator', $tc, $rw);
kop_ar_publish($tkey, 'test');
$check('the company page About is the rewrite', !empty(kop_operator_page_data($tc)['about']['writeup']));
$check('its quote goes on the program it names (Prayer Mountain Academy)', count($quotes_on(100753, 'operator/teen-challenge')) === 1);
kop_ar_undo($tkey, 'test');
$check('Undo takes the quote off again', !$quotes_on(100753, 'operator/teen-challenge') && empty(kop_wu_get($tkey)['published']['rewrite']));

echo "-- Claude writes every About --\n";
$t0 = microtime(true);
$first = kop_ar_claude_list(0, 50);
$secs = microtime(true) - $t0;
$check("Claude's list pages and is quick", count($first) <= 50 && ($GLOBALS['kop_ar_claude_total'] ?? 0) >= count($first) && $secs < 20,
    sprintf('%.1fs for %d of %d', $secs, count($first), $GLOBALS['kop_ar_claude_total'] ?? 0));
$todo = kop_wu_todo_ids();
$listed = array_column(array_filter(kop_ar_claude_list(0, 500), function ($x) { return $x['kind'] === 'facility'; }), 'id');
$check("records waiting for a write-up are on Claude's list", !array_diff(array_slice($todo, 0, 20), array_merge($listed, array_keys(kop_ar_lists()['review']))), count($todo) . ' waiting');
$src = file_get_contents(dirname(__DIR__) . '/inc/record-writeups.php');
$check('the hourly AI drafts are no longer scheduled', strpos($src, "wp_clear_scheduled_hook('kop_wu_hourly')") !== false && strpos($src, "wp_schedule_event(time() + 1200, 'hourly', 'kop_wu_hourly')") === false);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
