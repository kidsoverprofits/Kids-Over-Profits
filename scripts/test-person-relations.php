<?php
/**
 * The People editor's additions, on made-up people in SQLite (no mirror needed):
 *   - details (name, other names, notes, "Became an educational consultant") written with an exact Undo,
 *     refused while a later change stands
 *   - kop_people_is_ed_consultant() through merges, and its cache cleared by a write
 *   - relationships (inc/person-relations.php): by id or by name only, shown on both people with the kind read
 *     the other way round (Parent <-> Child), through merges; change, take off, put back, each with an exact Undo
 *   - a relationship with oneself is refused
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-person-relations.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
define('ABSPATH', $root . '/');
$GLOBALS['kop_test_options'] = array();
function add_action() {}
function add_filter() {}
function get_option($k, $d = false) { return $GLOBALS['kop_test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function remove_accents($s) { return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $s); }
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
require $root . '/inc/facility-store.php';
require $root . '/inc/people.php';
require $root . '/inc/person-relations.php';

$prefix = 'wpdl_';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// The MySQL tables of kop_people_install() and kop_prel_install(), in SQLite.
$pdo->exec("CREATE TABLE {$prefix}kop_people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL,
    aliases TEXT, merged_into INTEGER, notes TEXT, pool TEXT NOT NULL DEFAULT 'industry', ed_consultant INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE {$prefix}kop_person_relations (id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER NOT NULL, other_id INTEGER NOT NULL DEFAULT 0,
    other_name TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT '', detail TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '',
    source_url TEXT NOT NULL DEFAULT '', hidden INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)");
foreach (array('Robert Lichfield', 'Karr Farnsworth', 'Ken Kay', 'Kenneth Kay') as $n) {
    $pdo->prepare("INSERT INTO {$prefix}kop_people (name, name_key, aliases, notes) VALUES (?, ?, '', '')")->execute(array($n, kop_people_key($n)));
}
$pdo->exec("UPDATE {$prefix}kop_people SET merged_into = 3 WHERE id = 4");   // Kenneth Kay is Ken Kay
$opts = array('pdo' => $pdo, 'prefix' => $prefix);
$GLOBALS['kop_people_opts'] = $opts;

$fails = 0;
function check($ok, $what, $detail = '') {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($detail !== '' ? "  ($detail)" : '') . "\n";
}
function throws(callable $f) {
    try {
        $f();
    } catch (RuntimeException $e) {
        return true;
    }
    return false;
}
function row($id) {
    global $pdo, $prefix;
    return $pdo->query("SELECT name, name_key, aliases, notes, ed_consultant FROM {$prefix}kop_people WHERE id = " . (int) $id)->fetch(PDO::FETCH_ASSOC);
}

echo "Details\n";
$was = row(1);
$n1 = kop_people_details_write(1, array('notes' => 'Founder of WWASPS', 'ed_consultant' => 1), $opts);
check(row(1)['ed_consultant'] == 1 && row(1)['notes'] === 'Founder of WWASPS', 'tick and notes saved');
check(kop_people_details_write(1, array('notes' => 'Founder of WWASPS', 'ed_consultant' => 1), $opts) === 0, 'saving the same again logs nothing');
check(kop_people_is_ed_consultant(1) && !kop_people_is_ed_consultant(2), 'the tick is read');
$n2 = kop_people_details_write(1, array('name' => 'Robert B. Lichfield', 'name_key' => kop_people_key('Robert B. Lichfield'), 'notes' => 'Founder'), $opts);
check(throws(function () use ($n1, $opts) { kop_people_details_undo($n1, $opts); }), 'the first change will not undo under a later one');
kop_people_details_undo($n2, $opts);
kop_people_details_undo($n1, $opts);
check(row(1) == $was, 'both undone: the row is as it was', json_encode(row(1)));
check(!kop_people_is_ed_consultant(1), 'the tick is gone once undone (cache cleared)');
check(throws(function () use ($n1, $opts) { kop_people_details_undo($n1, $opts); }), 'an undone change will not undo twice');
kop_people_details_write(4, array('ed_consultant' => 1), $opts);
check(kop_people_is_ed_consultant(3) && kop_people_is_ed_consultant(4), 'a tick on a merged-away id counts for the person');
check(count(kop_people_details_log_for(1)) === 2, 'the log keeps the person\'s changes');

echo "Relationships\n";
$r1 = kop_prel_save(1, 0, array('other_id' => 2, 'kind' => 'Parent', 'detail' => 'Karr\'s father-in-law'), $opts);
$mine = kop_prel_for_person(1, $opts);
$theirs = kop_prel_for_person(2, $opts);
check(count($mine) === 1 && $mine[0]['kind'] === 'Parent' && $mine[0]['mine'] && $mine[0]['other_label'] === 'Karr Farnsworth', 'shown on the person who has it');
check(count($theirs) === 1 && $theirs[0]['kind'] === 'Child' && !$theirs[0]['mine'] && $theirs[0]['other_label'] === 'Robert Lichfield', 'read the other way round on the other person');
$r2 = kop_prel_save(4, 0, array('other_name' => 'Jane Kay', 'kind' => 'Spouse'), $opts);
$k = kop_prel_for_person(3, $opts);
check(count($k) === 1 && $k[0]['other_label'] === 'Jane Kay' && (int) $k[0]['person_id'] === 3, 'a name with no id is kept; written on the resolved person');
$r3 = kop_prel_save(2, 0, array('other_id' => 4, 'kind' => 'Business partner'), $opts);
check(count(kop_prel_for_person(3, $opts)) === 2 && kop_prel_for_person(2, $opts)[1]['other_label'] === 'Ken Kay', 'an id merged away shows as the person it joined');
check(throws(function () use ($opts) { kop_prel_save(3, 0, array('other_id' => 4, 'kind' => 'Friend'), $opts); }), 'a relationship with oneself is refused');
check(throws(function () use ($opts) { kop_prel_save(1, 0, array('other_id' => 2), $opts); }), 'a relationship needs a kind');
check(throws(function () use ($opts, $r1) { kop_prel_save(3, $r1, array('kind' => 'Friend'), $opts); }), 'a third person cannot change it');
$before = $pdo->query("SELECT * FROM {$prefix}kop_person_relations WHERE id = $r1")->fetch(PDO::FETCH_ASSOC);
kop_prel_save(2, $r1, array('kind' => 'Parent', 'detail' => 'Father-in-law'), $opts);   // the other side may change it
kop_prel_save(1, $r1, array('hidden' => 1), $opts);
check(count(array_filter(kop_prel_for_person(2, $opts), function ($r) { return empty($r['hidden']); })) === 1, 'taken off: gone from both people\'s shown list');
$log = kop_prel_log_for(1);
check(throws(function () use ($log, $opts) { kop_prel_undo($log[1]['n'], $opts); }), 'an older change will not undo under a later one');
kop_prel_undo($log[0]['n'], $opts);
kop_prel_undo($log[1]['n'], $opts);
check($pdo->query("SELECT * FROM {$prefix}kop_person_relations WHERE id = $r1")->fetch(PDO::FETCH_ASSOC) == $before, 'undone: as it was');
kop_prel_undo(kop_prel_log_for(1)[2]['n'], $opts);
check((int) $pdo->query("SELECT COUNT(*) FROM {$prefix}kop_person_relations WHERE id = $r1")->fetchColumn() === 0, 'undoing an add deletes it');
check(kop_prel_reverse('Employer') === 'Employee' && kop_prel_reverse('Spouse') === 'Spouse' && kop_prel_reverse('Neighbour') === 'Neighbour', 'kinds read back');

echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
