<?php
/**
 * A person's past jobs held once on the person (inc/person-jobs.php), against a copy of the mirror:
 *   - the pastJobs reading ("Role - Place (years); later ...", the wiki's "Role, Place")
 *   - the people sync folds every staff entry's pastJobs into its person's jobs, and a second sync adds nothing
 *   - a job one profile's text gave is the person's: every record listing them reads it
 *   - add, change, take off, put back, each with an exact Undo; a job taken off never comes back from the text
 *   - a person merged into another brings their jobs
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-person-jobs.php [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$args = getopt('', array('db::'));
$root = dirname(__DIR__);
$db_path = $args['db'] ?? ($root . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (sync-prod-sqlite.py in kop-pipeline).\n");
    exit(2);
}

define('ABSPATH', $root . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
$GLOBALS['kop_test_options'] = array();
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function get_option($k, $d = false) { return $GLOBALS['kop_test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function get_transient($k) { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function home_url($p = '') { return 'https://example.test/' . ltrim($p, '/'); }
function get_page_by_path() { return null; }
function get_stylesheet_directory() { return dirname(__DIR__); }
function remove_accents($s) { return iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $s); }
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
require $root . '/inc/facility-store.php';
require $root . '/inc/facility-pages.php';
require $root . '/inc/people.php';
require $root . '/inc/person-jobs.php';

$prefix = 'wpdl_';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($db_path) . ' AS src');
foreach (array('facilities_v2', $prefix . 'kop_operators') as $t) {
    $sql = $pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
    if (!$sql) continue;
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$pdo->exec('DETACH DATABASE src');
// The MySQL tables of kop_people_install() and kop_pjobs_install(), in SQLite.
$pdo->exec("CREATE TABLE {$prefix}kop_people (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL,
    aliases TEXT, merged_into INTEGER, notes TEXT, pool TEXT NOT NULL DEFAULT 'industry', created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE {$prefix}kop_person_roles (record_kind TEXT NOT NULL, record_id INTEGER NOT NULL, list TEXT NOT NULL,
    position INTEGER NOT NULL, person_id INTEGER NOT NULL, name TEXT NOT NULL DEFAULT '', role TEXT, ref TEXT NOT NULL DEFAULT '',
    PRIMARY KEY (record_kind, record_id, list, position))");
$pdo->exec("CREATE TABLE {$prefix}kop_person_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER NOT NULL, place TEXT NOT NULL DEFAULT '',
    role TEXT NOT NULL DEFAULT '', years TEXT NOT NULL DEFAULT '', source TEXT NOT NULL DEFAULT '', source_url TEXT NOT NULL DEFAULT '',
    origin TEXT NOT NULL DEFAULT 'manual', import_key TEXT NOT NULL DEFAULT '', hidden INTEGER NOT NULL DEFAULT 0, created_at TEXT, updated_at TEXT)");
$opts = array('pdo' => $pdo, 'prefix' => $prefix, 'graph' => array('nodes' => array()));
$GLOBALS['kop_pjobs_opts'] = $opts;

$fails = 0;
function check($ok, $what, $detail = '') {
    global $fails;
    if (!$ok) $fails++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

// ---- Reading the text ----------------------------------------------------------------
$p = kop_pjobs_parse('Admissions Director - Sunrise Academy (2007); later Assistant Admissions Director - CARE (2008) [joined 2008-12-15]; Head of Theatre - Western Wyoming College (before 2013)');
check(count($p) === 3 && $p[0] == array('role' => 'Admissions Director', 'place' => 'Sunrise Academy', 'years' => '2007')
    && $p[1]['role'] === 'Assistant Admissions Director' && $p[1]['place'] === 'CARE' && $p[2]['years'] === 'before 2013', 'pastJobs reads as role, place, years', json_encode($p));
check(kop_pjobs_parse('Therapist, Provo Canyon School; Aspen') == array(array('role' => 'Therapist', 'place' => 'Provo Canyon School', 'years' => ''), array('role' => '', 'place' => 'Aspen', 'years' => '')),
    'the wiki\'s "Role, Place" too');
check(kop_pjobs_parse('a Therapist at [Logan River Academy](https://www') === array(), 'a sentence with a link is not a job');

// ---- The sync folds the text in --------------------------------------------------------
$s = kop_people_sync($opts);
echo "  sync: " . json_encode($s) . "
";
$n = (int) $pdo->query("SELECT COUNT(*) FROM {$prefix}kop_person_jobs")->fetchColumn();
check(($s['jobs'] ?? 0) > 500 && $n === (int) $s['jobs'], 'the sync reads every staff entry\'s past jobs in', ($s['jobs'] ?? 0) . ' jobs');
check(kop_pjobs_import($opts) === 0, 'a second read adds nothing');
check((int) $pdo->query("SELECT COUNT(*) FROM {$prefix}kop_person_jobs WHERE person_id NOT IN (SELECT id FROM {$prefix}kop_people)")->fetchColumn() === 0, 'every job has a person');

// A person on two records: the job one record's text gave is theirs on both.
$two = $pdo->query("SELECT r.person_id, MIN(r.record_id) a, MAX(r.record_id) b FROM {$prefix}kop_person_roles r JOIN {$prefix}kop_person_jobs j ON j.person_id = r.person_id
    WHERE r.record_kind = 'facility' GROUP BY r.person_id HAVING COUNT(DISTINCT r.record_id) > 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($two) {
    $jobs = kop_pjobs_for_person((int) $two['person_id'], $opts);
    check($jobs !== null && count($jobs) > 0, 'a person listed on records #' . $two['a'] . ' and #' . $two['b'] . ' has one list of past jobs for both', count($jobs) . ' jobs');
}

// ---- Edits on the People screen ------------------------------------------------------------
$pid = (int) $pdo->query("SELECT person_id FROM {$prefix}kop_person_jobs GROUP BY person_id ORDER BY COUNT(*) DESC LIMIT 1")->fetchColumn();
$before = kop_pjobs_for_person($pid, $opts);
$first = $before[0];
$new = kop_pjobs_save($pid, 0, array('role' => 'Program Director', 'place' => 'Test Ranch', 'years' => '2001-2003', 'source' => 'Test'), $opts);
check(count(kop_pjobs_for_person($pid, $opts)) === count($before) + 1, 'Add puts a job on the person');
kop_pjobs_save($pid, (int) $first['id'], array('role' => 'Changed Role'), $opts);
$after = array_column(kop_pjobs_for_person($pid, $opts), 'role', 'id');
check($after[(int) $first['id']] === 'Changed Role', 'a change shows at once');
kop_pjobs_save($pid, (int) $first['id'], array('hidden' => 1), $opts);
check(!isset(array_column(kop_pjobs_for_person($pid, $opts), 'role', 'id')[(int) $first['id']]), 'Take off takes it off every page');
check(kop_pjobs_import($opts) === 0, '...and the staff entry\'s text does not bring it back');
check(kop_pjobs_known($pid, array('role' => $first['role'], 'place' => $first['place'], 'years' => $first['years'])) || $first['origin'] !== 'pastjobs',
    'a profile\'s text knows the job was read in (so it is not printed again)');
$refused = '';
try { kop_pjobs_save($pid, 0, array('role' => 'X', 'place' => ''), $opts); } catch (RuntimeException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'a job needs a place', $refused);

$log = kop_pjobs_log_for($pid);
foreach ($log as $l) kop_pjobs_undo($l['n'], $opts);   // newest first
$back = kop_pjobs_for_person($pid, $opts);
check(array_map(function ($j) { return array($j['id'], $j['role'], $j['place'], $j['years']); }, $back)
    === array_map(function ($j) { return array($j['id'], $j['role'], $j['place'], $j['years']); }, $before), 'Undo, newest first, puts the jobs back exactly');
$refused = '';
try { kop_pjobs_undo($log[0]['n'], $opts); } catch (RuntimeException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'an undone change cannot be undone twice');

// ---- Merges -----------------------------------------------------------------------------------
$other = (int) $pdo->query("SELECT person_id FROM {$prefix}kop_person_jobs WHERE person_id <> $pid LIMIT 1")->fetchColumn();
$theirs = count(kop_pjobs_for_person($other, $opts));
$undo = kop_people_merge($other, $pid, $opts);
check(count(kop_pjobs_for_person($pid, $opts)) === count($before) + $theirs, 'a person merged into another brings their jobs', $theirs . ' moved');
kop_people_unmerge($undo, $opts);
check(count(kop_pjobs_for_person($pid, $opts)) === count($before) && count(kop_pjobs_for_person($other, $opts)) === $theirs, '...and the unmerge takes them back');

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
