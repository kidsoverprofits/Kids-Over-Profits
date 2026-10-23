<?php
/**
 * Offline checks for KOP Tools > Memorial sheet (inc/memorial-sheet.php) against an in-memory copy of
 * memorial_victims from the production mirror tmp/prod.sqlite.
 *
 *   php scripts/test-memorial-sheet.php [--db=tmp/prod.sqlite]
 *
 * The seed (seeds/memorial-sheet.json) must point at rows the page has; each tab renders; a settle writes
 * through kop_ie_row_save() and fires kop_record_changed('memorial'); Undo puts back exactly what was there;
 * a restraint death's cause can't lose the restraint; Add needs a source and its Undo removes the row.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (python ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
if (!function_exists('wp_doing_ajax')) {
    function wp_doing_ajax() { return false; }
}
if (!function_exists('selected')) {
    function selected($a, $b, $echo = true) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
}
if (!function_exists('esc_textarea')) {
    function esc_textarea($s) { return esc_html($s); }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($v) { return $v; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 1; }
}
if (!function_exists('kop_seed_pdo')) {
    function kop_seed_pdo() { return $GLOBALS['kop_test_mem_pdo']; }
}
$GLOBALS['kop_test_wpdb_writes'] = true;   // options are stored for this run
require_once dirname(__DIR__) . '/inc/record-events.php';
require_once dirname(__DIR__) . '/inc/inline-edit.php';
require_once dirname(__DIR__) . '/inc/memorial-sheet.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$src = new PDO('sqlite:' . $db_path);
$mem = new PDO('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mem->exec($src->query("SELECT sql FROM sqlite_master WHERE name = 'memorial_victims'")->fetchColumn());
foreach ($src->query('SELECT * FROM memorial_victims')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $cols = array_keys($r);
    $mem->prepare('INSERT INTO memorial_victims (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
}
$GLOBALS['kop_test_mem_pdo'] = $mem;
$get = function ($id) use ($mem) {
    $q = $mem->prepare('SELECT * FROM memorial_victims WHERE id = ?');
    $q->execute(array((int) $id));
    return $q->fetch(PDO::FETCH_ASSOC);
};
$rev = function () { return (int) get_option('kop_rev_memorial', 0); };
$throws = function (callable $f) { try { $f(); return ''; } catch (RuntimeException $e) { return $e->getMessage(); } };

echo "-- The seed --\n";
$seed = kop_ms_seed();
$rows = kop_ms_rows();
$missing = array_filter($seed['conflicts'], function ($c) use ($rows) { return !isset($rows[(int) $c['id']]); });
$check(count($seed['conflicts']) . ' people with conflicts, each on the page', $seed['conflicts'] && !$missing, implode(',', array_column($missing, 'id')));
$check(count($seed['add']) . ' sheet-only rows, ' . count($seed['page_only']) . ' page-only', count($seed['add']) > 0 && count($seed['page_only']) > 0);
$empty_sheet = 0;
foreach ($seed['conflicts'] as $c) foreach ($c['lines'] as $l) if (trim($l['sheet']) === '' || empty($l['set'])) $empty_sheet++;
$check('no line where the sheet has nothing (no info is not a conflict)', $empty_sheet === 0, (string) $empty_sheet);
$lines = kop_ms_conflict_lines();
$check(count($lines) . ' conflict lines, ' . count(array_filter($lines, function ($x) { return $x['state'] === 'open'; })) . ' open', count($lines) > 0);

echo "-- Each tab renders --\n";
foreach (array('conflicts' => 'kop_ms_conflicts_view', 'add' => 'kop_ms_add_view') as $tab => $fn) {
    foreach (array('open', 'decided') as $seg) {
        $_GET = array('tab' => $tab, 'seg' => $seg);
        ob_start();
        $fn($tab === 'conflicts' ? kop_ms_conflict_lines() : kop_ms_add_rows());
        $html = ob_get_clean();
        $check("$tab / $seg renders", strlen($html) > 200 && strpos($html, 'kop-ak-segments') !== false);
    }
}
$_GET = array('tab' => 'conflicts', 'check' => 'yes');
ob_start();
kop_ms_conflicts_view(kop_ms_conflict_lines());
$html = ob_get_clean();
$check('"Check the source" filter shows only flagged lines', substr_count($html, 'Check the source</span>') > 0 && substr_count($html, '<tr data-key=') === substr_count($html, 'Check the source</span>'));
ob_start();
kop_ms_page_only_view();
$html = ob_get_clean();
$check('Only on the page lists the page rows', substr_count($html, '<tr><td>') === count($seed['page_only']));
$_GET = array();

echo "-- Settle and Undo --\n";
$pick = null;
foreach ($lines as $x) {
    if ($x['state'] === 'open' && $x['l']['field'] === 'name') { $pick = $x; break; }
}
$before = $get($pick['row']['id']);
$r0 = $rev();
kop_ms_settle($pick['key'], 'sheet', '', '');
$after = $get($pick['row']['id']);
$check('taking the sheet\'s name writes it', $after['name'] === $pick['l']['set']['name'], $after['name']);
$check('and fires kop_record_changed(memorial)', $rev() === $r0 + 1);
$check('the line is decided', kop_ms_conflict_lines()[$pick['key']]['state'] === 'decided');
$check('deciding it again is refused', $throws(function () use ($pick) { kop_ms_settle($pick['key'], 'page', '', ''); }) !== '');
kop_ms_undo($pick['key']);
$back = $get($pick['row']['id']);
unset($before['updated_at'], $back['updated_at']);
$check('Undo puts the row back exactly', $back === $before);
$check('and the line is open again', kop_ms_conflict_lines()[$pick['key']]['state'] === 'open');

$date = null;
foreach (kop_ms_conflict_lines() as $x) {
    if ($x['state'] === 'open' && $x['l']['field'] === 'date') { $date = $x; break; }
}
$check('a typed date must be YYYY-MM-DD', $throws(function () use ($date) { kop_ms_settle($date['key'], 'typed', 'May 2003', 'day'); }) !== '');
$d0 = $get($date['row']['id']);
kop_ms_settle($date['key'], 'typed', '2001-05-01', 'month');
$d1 = $get($date['row']['id']);
$check('a typed date and precision land', $d1['date_of_death'] === '2001-05-01' && $d1['date_precision'] === 'month');
kop_ms_undo($date['key']);
$d2 = $get($date['row']['id']);
$check('Undo puts the date and precision back', $d2['date_of_death'] === $d0['date_of_death'] && $d2['date_precision'] === $d0['date_precision']);

$keep = null;
foreach (kop_ms_conflict_lines() as $x) {
    if ($x['state'] === 'open' && $x['l']['field'] === 'program') { $keep = $x; break; }
}
$r0 = $rev();
kop_ms_settle($keep['key'], 'page', '', '');
$check('keeping the page\'s writes nothing', $rev() === $r0 && $get($keep['row']['id'])['program'] === $keep['row']['program']);
kop_ms_undo($keep['key']);

echo "-- Restraint deaths stay restraint --\n";
$restraint = null;
foreach (kop_ms_conflict_lines() as $x) {
    if ($x['state'] === 'open' && $x['l']['field'] === 'cause' && kop_ms_drops_restraint($x['row'], $x['l']['set']['cause_of_death'])) { $restraint = $x; break; }
}
if ($restraint) {
    $c0 = $get($restraint['row']['id']);
    $check('taking a sheet cause without restraint is refused (' . $restraint['c']['name'] . ')', $throws(function () use ($restraint) { kop_ms_settle($restraint['key'], 'sheet', '', ''); }) !== '');
    $check('the row is untouched', $get($restraint['row']['id']) == $c0);
    kop_ms_settle($restraint['key'], 'typed', $c0['cause_of_death'] . ' ' . $restraint['l']['set']['cause_of_death'], '');
    $c1 = $get($restraint['row']['id']);
    $check('a typed cause keeping the restraint saves, the filter unchanged', $c1['cause_category'] === 'restraint' && $c1['cause_of_death'] !== $c0['cause_of_death']);
    kop_ms_undo($restraint['key']);
} else {
    $check('a restraint row with a non-restraint sheet cause exists to test', false);
}

echo "-- Add, Skip, Undo --\n";
$adds = kop_ms_add_rows();
$no_src = $with_src = null;
foreach ($adds as $x) {
    if ($x['state'] !== 'open') continue;
    if ($x['a']['source_url'] === '' && !$no_src) $no_src = $x;
}
$check('seeded rows (seeds/memorial.json) are not waiting', count(array_filter($adds, function ($x) { return $x['state'] === 'seeded'; })) === count(array_intersect(array_column($seed['add'], 'row'), $seed['seeded_rows'])));
$fields = array('name' => $no_src['a']['name'], 'program' => $no_src['a']['program'], 'date_of_death' => (string) $no_src['a']['date_of_death'], 'date_precision' => $no_src['a']['date_precision'], 'cause_of_death' => $no_src['a']['cause'], 'cause_category' => 'unknown', 'location' => $no_src['a']['location']);
$check('a row with no source is refused', strpos($throws(function () use ($no_src, $fields) { kop_ms_add($no_src['key'], $fields); }), 'No source') === 0);
$count = (int) $mem->query('SELECT COUNT(*) FROM memorial_victims')->fetchColumn();
$r0 = $rev();
$id = kop_ms_add($no_src['key'], $fields + array('source_url' => 'https://example.test/found-source'));
$new = $get($id);
$check('with a source it goes in, published', $new && $new['publication_status'] === 'published' && $new['source_url'] === 'https://example.test/found-source' && $rev() === $r0 + 1);
$check('adding it twice is refused', $throws(function () use ($no_src, $fields) { kop_ms_add($no_src['key'], $fields + array('source_url' => 'https://example.test/x')); }) !== '');
kop_ms_undo($no_src['key']);
$check('Undo removes the row', !$get($id) && (int) $mem->query('SELECT COUNT(*) FROM memorial_victims')->fetchColumn() === $count);
$check('and it is waiting again', kop_ms_add_rows()[$no_src['key']]['state'] === 'open');
kop_ms_log_put($no_src['key'], array('act' => 'skipped'));
$check('Skip remembers', kop_ms_add_rows()[$no_src['key']]['state'] === 'skipped');
kop_ms_undo($no_src['key']);
$check('Undo of a skip puts it back', kop_ms_add_rows()[$no_src['key']]['state'] === 'open');

echo $failures ? "\n$failures failed.\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
