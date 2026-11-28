<?php
/**
 * The Company histories and Older AI drafts tabs of KOP Tools > About rewrites (inc/about-rewrites.php,
 * inc/about-rewrites-screen.php), on a small scratch database built here, so it runs without the production mirror:
 *   - Company histories lists every company whose history is a draft (and none that is published, empty or unwritten),
 *     the card shows the draft, its sources and what to check, and Publish / Undo go through the pencil's own save
 *     (kop_ie_operator_save()), changing nothing but historyStatus;
 *   - Older AI drafts lists the write-up drafts that are not Claude's rewrites (newest row per record, facilities only,
 *     none without paragraphs), and Send to Claude flags it under Pick next with the note.
 * Publishing an older draft is kop_wu_publish(), tested on the mirror by scripts/test-review-inbox.php --source=writeups.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-about-rewrites-tabs.php
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$db_path = sys_get_temp_dir() . '/kop-about-tabs-' . getmypid() . '.sqlite';
@unlink($db_path);
$db = new PDO('sqlite:' . $db_path);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE wpdl_kop_operators (id INTEGER PRIMARY KEY, name TEXT, json_data TEXT, created_at TEXT, updated_at TEXT)');
$db->exec('CREATE TABLE wpdl_kop_operator_facilities (id INTEGER PRIMARY KEY AUTOINCREMENT, operator_id INTEGER, facility_id INTEGER, relationship TEXT)');
$db->exec('CREATE TABLE wpdl_kop_record_writeups (id INTEGER PRIMARY KEY AUTOINCREMENT, facility_id INTEGER NOT NULL DEFAULT 0, draft TEXT NULL, published TEXT NULL, updated_at TEXT NULL)');
$db->exec('CREATE TABLE wpdl_kop_record_writeup_log (id INTEGER PRIMARY KEY AUTOINCREMENT, facility_id INTEGER NOT NULL DEFAULT 0, before_row TEXT NULL, items TEXT NULL, done_by TEXT NULL, done_at TEXT NULL, undone_at TEXT NULL)');
$op = function ($id, $name, array $operator) use ($db) {
    $db->prepare('INSERT INTO wpdl_kop_operators (id, name, json_data) VALUES (?, ?, ?)')
        ->execute(array($id, $name, json_encode(array('operator' => array('name' => $name) + $operator))));
};
$op(1, 'Draft Co', array('history' => array('Founded in [1990](https://example.org/a).', "Table: Programs\n| Year | What |\n|---|---|\n| 1991 | Opened |"),
    'historySources' => array('A source | https://example.org/s'), 'historyStatus' => 'draft', 'historyReviewNotes' => 'Check the founding year.'));
$op(2, 'Published Co', array('history' => array('All checked.'), 'historyStatus' => 'published'));
$op(3, 'Empty Co', array('history' => array('', ' '), 'historyStatus' => 'draft'));
$op(4, 'Unwritten Co', array('type' => 'Company'));
$op(5, 'Another Draft', array('history' => array('Second draft.'), 'historyStatus' => 'draft'));
$base = 2000000000;
$wu = function ($key, $draft, $published = null) use ($db) {
    $db->prepare('INSERT INTO wpdl_kop_record_writeups (facility_id, draft, published) VALUES (?, ?, ?)')
        ->execute(array($key, $draft ? json_encode($draft) : null, $published ? json_encode($published) : null));
};
$wu(101, array('paragraphs' => array('Old AI draft one ([source](https://example.org/1)).'), 'by' => 'ai', 'at' => '2026-11-01 10:00:00', 'check' => 'Dates unsure.'));
$wu(102, array('paragraphs' => array('Old AI draft two.'), 'by' => 'ai', 'at' => '2026-11-10 10:00:00'), array('paragraphs' => array('Published before.')));
$wu(103, array('kind' => 'rewrite', 'paragraphs' => array('A rewrite.'), 'sentences' => array(array(array('text' => 'A rewrite.', 'sources' => array()))), 'by' => 'Claude'));
$wu(104, array('paragraphs' => array('Superseded AI draft.'), 'by' => 'ai', 'at' => '2026-10-30 10:00:00'));
$wu(104, array('kind' => 'rewrite', 'paragraphs' => array('Newer rewrite.'), 'by' => 'Claude'));   // a merge's second row: the newest counts
$wu(105, array('paragraphs' => array('', ''), 'by' => 'ai'));
$wu($base + 7, array('paragraphs' => array('A company draft.'), 'by' => 'ai'));
$wu(106, null, array('paragraphs' => array('Only published.')));
$db = null;

$GLOBALS['kop_test_wpdb_writes'] = true;
if (!defined('KOP_DB_CONFIG_LOADED')) define('KOP_DB_CONFIG_LOADED', true);
require __DIR__ . '/kop-test-harness.php';
$pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }
if (!function_exists('kop_v2_writes_active')) { function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) { return true; } }
if (!function_exists('esc_textarea')) { function esc_textarea($s) { return esc_html($s); } }
if (!function_exists('mysql2date')) { function mysql2date($f, $d) { return date($f, strtotime($d)); } }
require_once dirname(__DIR__) . '/inc/record-writeups.php';
require_once dirname(__DIR__) . '/inc/about-rewrites.php';
require_once dirname(__DIR__) . '/inc/admin-kit.php';
require_once dirname(__DIR__) . '/inc/inline-edit.php';
require_once dirname(__DIR__) . '/inc/about-rewrites-screen.php';
$GLOBALS['kop_test_options']['kop_record_writeups_db'] = KOP_RECORD_WRITEUPS_DB_VERSION;

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$stored = function ($id) use ($pdo) {
    return json_decode((string) $pdo->query('SELECT json_data FROM wpdl_kop_operators WHERE id = ' . (int) $id)->fetchColumn(), true)['operator'];
};

echo "-- Company histories --\n";
$drafts = kop_ar_history_drafts();
$check('every draft history is listed, by name, and nothing else', array_column($drafts, 'id') === array(5, 1), json_encode(array_column($drafts, 'name')));
$d = $drafts[1];
$check('with its paragraphs, sources and what to check', count($d['paragraphs']) === 2 && $d['sources'][0]['url'] === 'https://example.org/s' && $d['notes'] === 'Check the founding year.');
$card = kop_ar_history_card($d);
$check('the card shows the text with its links, the table, the sources and the check', strpos($card, 'href="https://example.org/a"') !== false && strpos($card, '<table') !== false
    && strpos($card, 'A source') !== false && strpos($card, 'Check the founding year.') !== false && strpos($card, 'data-act="history-publish"') !== false);
$before = $stored(1);
$msg = kop_ar_history_set_status(1, 'published');
$after = $stored(1);
$check('Publish sets the history to published', ($after['historyStatus'] ?? '') === 'published', $msg);
unset($before['historyStatus'], $after['historyStatus']);
$check('and changes nothing else on the record', $before == $after);
$check('it leaves the tab', array_column(kop_ar_history_drafts(), 'id') === array(5));
$threw = false;
try { kop_ar_history_set_status(1, 'published'); } catch (RuntimeException $e) { $threw = true; }
$check('publishing twice says it is published already', $threw);
kop_ar_history_set_status(1, 'draft');
$check('Undo makes it a draft again', ($stored(1)['historyStatus'] ?? '') === 'draft' && array_column(kop_ar_history_drafts(), 'id') === array(5, 1));
$threw = false;
try { kop_ar_history_set_status(4, 'published'); } catch (RuntimeException $e) { $threw = true; }
$check('a company with no written history cannot be published', $threw && !isset($stored(4)['historyStatus']));

echo "-- Older AI drafts --\n";
$older = kop_ar_older_drafts();
$check('the AI drafts are listed, newest first; rewrites, empty drafts, superseded rows and company rows are not', array_keys($older) === array(102, 101), json_encode(array_keys($older)));
$check('a record with a published About is marked', $older[102]['published'] === 'writeup' && $older[101]['published'] === '');
$lists = kop_ar_lists();
$check("Claude's rewrites are still under To review", isset($lists['review'][103]) && !isset($lists['review'][101]));
$card = kop_ar_older_card($older[101]);
$check('the card shows the draft with its citation and the AI\'s note', strpos($card, 'Old AI draft one.') !== false && strpos($card, 'kop-fp-src') !== false
    && strpos($card, 'Dates unsure.') !== false && strpos($card, 'data-act="older-publish"') !== false);
$check('a record with no page is not offered to Claude (Claude rewrites pages only)', strpos($card, 'data-act="older-flag"') === false);
// Flagged (as Send to Claude does on a record with a page: kop_ar_flag()), the card says so and keeps the note.
kop_ar_next_put(array(array('key' => 101, 'at' => '2026-11-27 00:00:00', 'by' => 'test', 'note' => 'Lead with the closure')));
$check('a flagged draft says where it is on Claude\'s list', strpos(kop_ar_older_card($older[101]), 'flagged for Claude, number 1') !== false);
$check("and it leads Claude's list", (int) (array_keys(kop_ar_lists()['claude'])[0] ?? 0) === 101);
$ar = file_get_contents(dirname(__DIR__) . '/inc/about-rewrites.php');
$check('Publish is kop_wu_publish() and Undo kop_wu_undo(), the existing write-up paths', strpos($ar, '$res = kop_wu_publish($key, $reviewer);') !== false
    && strpos($ar, 'return kop_wu_undo((int) $pub[\'log\'], $reviewer)') !== false);
// A rewrite coming in replaces the draft (kop_ar_save_rewrite() stores it with kind 'rewrite').
kop_wu_put(101, array('kind' => 'rewrite', 'paragraphs' => array('x'), 'by' => 'Claude'), array());
$check('once its rewrite is in, it leaves the tab', !isset(kop_ar_older_drafts()[101]));

echo "-- The screen --\n";
$GLOBALS['kop_test_filters'] = array();
$check('the tabs are registered', strpos(file_get_contents(dirname(__DIR__) . '/inc/about-rewrites-screen.php'), "'histories', 'older'") !== false);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
