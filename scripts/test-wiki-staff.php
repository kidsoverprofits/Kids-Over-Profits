<?php
/**
 * Wiki approval puts the entry's staff on its facility record
 * (api/sync-wiki-facilities.php kop_wiki_merge_submission(), called from
 * kop_resync_after_status('wiki', ...)), cited to the wiki page; a name the
 * record already lists stays as it is; taking the approval back takes them
 * off (kop_wiki_staff_undo()). On an in-memory copy of tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-staff.php [--db=tmp/prod.sqlite] [--list]
 *
 * --list prints, for every approved entry with staff, how many would go on
 * its record (what approving all of them again would add).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$root = dirname(__DIR__);
$args = getopt('', array('db::', 'list'));
$mirror = $args['db'] ?? ($root . '/tmp/prod.sqlite');

$fails = 0;
function check($ok, $label, $detail = '') {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fails++;
}

define('KOP_DB_CONFIG_LOADED', true);
// The citation's Reddit page comes from inc/wiki-updates.php (js/data/reddit-wiki/live-compare.json).
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');   // the file stops without it
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');   // inc/wiki-updates.php loads only under WordPress
if (!function_exists('get_stylesheet_directory')) {
    function get_stylesheet_directory() { return dirname(__DIR__); }
}   // no api/config.php: the copy below is the database
require_once $root . '/inc/facility-store.php';
require_once $root . '/api/sync-wiki-facilities.php';

// ---- The mapping ------------------------------------------------------------------
$e = kop_wiki_staff_entries(array(
    array('name' => ' Jane  Doe ', 'role' => 'Clinical Director', 'bio' => 'is the...', 'previousRoles' => array(array('role' => 'Therapist', 'employer' => 'Provo Canyon School'), array('role' => '', 'employer' => 'Aspen')), 'isFormer' => true),
    array('name' => 'John Roe', 'role' => 'Former Owner', 'isFormer' => true),
    array('name' => '', 'role' => 'Therapist'),
), 'https://www.reddit.com/r/troubledteens/wiki/index/x');
check(count($e) === 2, 'entries with no name are left out');
check($e[0]['name'] === 'Jane Doe' && $e[0]['role'] === 'Former Clinical Director', 'a former staff member reads "Former <role>", as records do', $e[0]['role']);
check($e[1]['role'] === 'Former Owner', '"Former" is not doubled');
check($e[0]['pastJobs'] === 'Therapist, Provo Canyon School; Aspen', 'previous roles become past jobs', $e[0]['pastJobs']);
check($e[0]['source'] === 'r/troubledteens wiki' && $e[0]['sourceUrl'] === 'https://www.reddit.com/r/troubledteens/wiki/index/x', 'each entry cites the wiki page');
check(!isset($e[0]['bio']), 'the write-up text stays on the wiki');
$f = kop_wiki_staff_entries(array(
    array('name' => 'A B', 'role' => 'reported to feel no remorse for the children whom CEDU abused ([source](https://medium.com/x))'),
    array('name' => 'C D', 'role' => 'co-owned/co-founded by Robert Crist, Larry Carter, and Jeff Smith and others'),
    array('name' => 'E F', 'role' => 'a Primary Therapist & the Transition and Alumni Support Coordinator'),
));
check($f[0]['role'] === '' && $f[1]['role'] === '', 'a sentence of the write-up is not printed as a role', $f[0]['role'] . ' / ' . $f[1]['role']);
check(count($f) === 3, '...the person still goes on the record');

if (!file_exists($mirror)) {
    echo "SKIP the mirror checks: no $mirror\n";
    exit($fails ? 1 : 0);
}

// ---- A copy of the records ----------------------------------------------------------
/** The scratch copy, with the MySQL the writer uses turned into SQLite's. */
class KOP_Wiki_Staff_Test_PDO extends PDO {
    private function fix($sql) {
        $sql = preg_replace('/^\s*SHOW TABLES LIKE (.+)$/i', "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE $1 ESCAPE '\\'", (string) $sql);
        $sql = preg_replace('/^\s*INSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        // MySQL upserts (location memberships, company links) in SQLite's words.
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT DO UPDATE SET', $sql);
            $sql = preg_replace('/\bVALUES\((\w+)\)/i', 'excluded.$1', $sql);
            $sql = preg_replace('/\bIF\(/i', 'IIF(', $sql);
        }
        return $sql;
    }
    public function prepare(string $query, array $options = array()): PDOStatement|false {
        return parent::prepare($this->fix($query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return $fetchMode === null ? parent::query($this->fix($query)) : parent::query($this->fix($query), $fetchMode, ...$fetchModeArgs);
    }
}
$pdo = new KOP_Wiki_Staff_Test_PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $mirror) . "?mode=ro' AS prod");
$tables = array('wiki_submissions', 'wiki_master', 'facilities_master', 'facilities_v2');
foreach ($pdo->query("SELECT name FROM prod.sqlite_master WHERE type = 'table' AND name LIKE 'wpdl_kop_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (preg_match('/^wpdl_kop_(operators|operator_facilities|facility_locations|facility_identity|migration_state|facility_addresses|addresses)$/', $t)) $tables[] = $t;
}
foreach ($tables as $t) {
    $sql = $pdo->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn();
    if (!$sql) continue;
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.\"$t\" SELECT * FROM prod.\"$t\"");
}
$pdo->exec('DETACH DATABASE prod');
// wiki_master (the approved copy per page) is not in the mirror: what the approval writes into it.
$pdo->exec('CREATE TABLE IF NOT EXISTS wiki_master (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, program_name TEXT, city_state TEXT, organization TEXT,
    program_type TEXT, years_active TEXT, json_data TEXT, markdown TEXT, facility_unique_name TEXT, last_updated_by TEXT, updated_at TEXT)');
$GLOBALS['table_prefix'] = 'wpdl_';   // the mirror's WordPress prefix
require_once $root . '/inc/facility-migration.php';
require_once $root . '/inc/facility-v2-writer.php';
$prefix = kop_v2_detect_prefix($pdo);
check($prefix !== null && kop_v2_writes_active($pdo, $prefix), 'the copy writes facilities_v2', var_export($prefix, true));

$staff_of = function ($unique) use ($pdo, $prefix) {
    $row = kop_v2_pdo_master_row_by_name($pdo, $prefix, $unique);
    $p = $row ? json_decode($row['json_data'], true) : array();
    $f = $p['data']['facility'] ?? ($p['data']['facilities'][0] ?? array());
    return (array) ($f['staff']['notableStaff'] ?? array());
};

$master = function (array $r) use ($pdo) {
    // The approval writes wiki_master first: the row the merge reads.
    $j = json_decode((string) $r['json_data'], true);
    $slug = !empty($j['slug']) ? $j['slug'] : strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $r['program_name'])));
    $pdo->prepare('DELETE FROM wiki_master WHERE slug = ?')->execute(array($slug));
    $pdo->prepare('INSERT INTO wiki_master (slug, program_name, json_data) VALUES (?, ?, ?)')->execute(array($slug, $r['program_name'], $r['json_data']));
};
$rows = $pdo->query("SELECT id, program_name, facility_unique_name, json_data FROM wiki_submissions
    WHERE status IN ('approved', 'published') AND json_data LIKE '%staffMembers%' ORDER BY id")->fetchAll();

// ---- One entry: approve, again, take back ---------------------------------------------
$pick = null;
foreach ($rows as $r) {
    $row = $r['facility_unique_name'] ? kop_v2_pdo_master_row_by_name($pdo, $prefix, $r['facility_unique_name']) : null;
    $p = $row ? json_decode($row['json_data'], true) : null;
    if (!empty($p['__facility_ref']) && !empty(json_decode((string) $r['json_data'], true)['staffMembers'])) { $pick = $r; break; }
}
if ($pick) {
    $id = (int) $pick['id'];
    $unique = $pick['facility_unique_name'];
    $master($pick);
    $before = $staff_of($unique);
    $res = kop_wiki_merge_submission($pdo, $id);
    $after = $staff_of($unique);
    $added = array_values(array_filter($after, function ($s) { return ($s['source'] ?? '') === 'r/troubledteens wiki'; }));
    check(!empty($res['staff_added']) && count($after) === count($before) + $res['staff_added'], "approving #$id ({$pick['program_name']}) puts its staff on the record", json_encode($res));
    check($added && $added[0]['source'] === 'r/troubledteens wiki', 'each one cites the wiki', json_encode($added[0] ?? null));
    check($added && strpos((string) ($added[0]['sourceUrl'] ?? ''), 'https://www.reddit.com/r/troubledteens/wiki/') === 0, '...linked to its Reddit page', (string) ($added[0]['sourceUrl'] ?? ''));
    $fold = function ($l) { return array_map(function ($s) { return kop_wiki_staff_fold($s['name'] ?? ''); }, $l); };
    check(count(array_unique($fold($added))) === count($added) && !array_intersect($fold($added), $fold($before)), 'none of them was on the record already, none twice');
    $fid = (int) $pdo->query('SELECT id FROM facilities_v2 WHERE unique_name = ' . $pdo->quote($unique))->fetchColumn();
    $doc = kop_facility_load($fid, array('pdo' => $pdo, 'prefix' => $prefix));
    $errors = array_filter(kop_facility_validate($doc['doc'] ?? array()), function ($v) { return $v['severity'] === 'error'; });
    check($doc && !$errors, 'the record still validates', json_encode(array_slice(array_values($errors), 0, 2)));
    $again = kop_wiki_merge_submission($pdo, $id);
    check(count($staff_of($unique)) === count($after), 'approving it again adds nothing', json_encode($again));
    $removed = kop_wiki_staff_undo($pdo, $id);
    $back = $staff_of($unique);
    check($removed === (int) $res['staff_added'] && $back == $before, 'taking the approval back takes them off, the rest as it was', "$removed removed");
    check(kop_wiki_staff_undo($pdo, $id) === 0, 'a second undo does nothing');
} else {
    echo "SKIP approve/undo: no approved entry with staff linked to one facility\n";
}

// ---- Every approved entry: what approving them all again would put on the records ---------
$added = 0;
$entries = 0;
$why = array();
foreach ($rows as $r) {
    $master($r);
    $res = kop_wiki_merge_submission($pdo, (int) $r['id']);
    $n = (int) ($res['staff_added'] ?? 0);
    $added += $n;
    if ($n) $entries++;
    else $why[preg_replace("/'[^']*'/", "'..'", (string) $res['reason'])] = ($why[preg_replace("/'[^']*'/", "'..'", (string) $res['reason'])] ?? 0) + 1;
    if (isset($args['list']) && $n) echo "  #{$r['id']} {$r['program_name']}: $n\n";
}
echo count($rows) . " approved entries with staff: $entries would add $added staff to their records\n";
arsort($why);
foreach (array_slice($why, 0, 6, true) as $k => $v) echo "  $v nothing added: $k\n";

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
