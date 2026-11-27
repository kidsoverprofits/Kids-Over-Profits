<?php
/**
 * The new-facility deploy seed (seeds/new-facilities.json, kop_apply_new_facility_seeds()
 * in inc/admin.php) against a temp copy of the production mirror: every entry not on
 * file is created once through the data form's save, placed on its state page, given
 * its "provenance_source" and tied to its "home_of" record; a second run adds nothing.
 * The tables it writes are copied into a temp database; the mirror is never written.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-new-facility-seeds.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$prod = $root . '/tmp/prod.sqlite';
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

$db_path = sys_get_temp_dir() . '/kop-new-facility-seeds-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec('ATTACH DATABASE ' . $copy->quote($prod) . ' AS prod');
foreach (array('facilities_v2', 'wpdl_kop_facility_identity', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_locations',
    'locations_master', 'wpdl_kop_program_homes', 'wpdl_kop_program_groups') as $t) {
    $sql = $copy->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.$t SELECT * FROM prod.$t");
}
// On production these columns are generated from json_data; here triggers fill them.
foreach (array('INSERT', 'UPDATE OF json_data') as $i => $when) {
    $copy->exec("CREATE TRIGGER facilities_v2_cols_$i AFTER $when ON facilities_v2 BEGIN
        UPDATE facilities_v2 SET name = json_extract(NEW.json_data, '$.identification.name'),
            name_key = json_extract(NEW.json_data, '$.identification.nameKey'),
            state = json_extract(NEW.json_data, '$.location.state'), city = json_extract(NEW.json_data, '$.location.city'),
            country = json_extract(NEW.json_data, '$.location.country'), status = json_extract(NEW.json_data, '$.operatingPeriod.status'),
            facility_type = json_extract(NEW.json_data, '$.facilityDetails.type')
        WHERE id = NEW.id; END");
}
$copy = null;

$GLOBALS['kop_test_wpdb_writes'] = true;   // the harness's options and $wpdb writes land on the temp copy
require __DIR__ . '/kop-test-harness.php';
// The store's upserts are MySQL's; every row this test writes is new, so a plain insert stands in.
class KopSeedTestPdo extends PDO {
    public function prepare(string $query, array $options = array()): PDOStatement|false {
        if (stripos($query, 'ON DUPLICATE KEY UPDATE') !== false) {
            $query = preg_replace('/\s+ON DUPLICATE KEY UPDATE.*$/is', '', $query);
            $query = preg_replace('/^\s*INSERT INTO/i', 'INSERT OR IGNORE INTO', $query);
        }
        return parent::prepare($query, $options);
    }
}
$pdo = null;
$pdo = new KopSeedTestPdo('sqlite:' . $db_path);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($prod) . ' AS prod');
$pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
    if (!is_string($v)) return $v;
    $d = json_decode($v);
    return is_string($d) ? $d : $v;
}, 1);
$GLOBALS['pdo'] = $pdo;
if (!function_exists('kop_v2_writes_active')) {
    function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) { return true; }
}
// The harness has no insert().
$GLOBALS['wpdb'] = $wpdb = new class($pdo) extends wpdb {
    private $db;
    public function __construct(PDO $pdo) { parent::__construct($pdo); $this->db = $pdo; }
    public function query($sql) { return $this->db->exec($sql); }
    public function insert($table, $data) {
        $cols = array_keys($data);
        $st = $this->db->prepare("INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
        return $st->execute(array_values($data)) ? 1 : false;
    }
};
require_once $root . '/inc/facility-v2-writer.php';
require_once $root . '/inc/program-homes.php';
require_once $root . '/inc/admin.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$one = function ($sql, array $params = array()) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
};
$opts = array('pdo' => $pdo, 'prefix' => 'wpdl_');

$entries = json_decode((string) file_get_contents($root . '/seeds/new-facilities.json'), true);
$check('the seed file reads', is_array($entries) && count($entries) > 0, count((array) $entries) . ' entries');

// What the runner should create: entries whose name is not on file in their state.
$expect = array();
$keys = array();
foreach ($entries as $i => $entry) {
    $f = $entry['facility'];
    $state = strtoupper(trim((string) ($f['locationDetails']['state'] ?? '')));
    $key = kop_facility_name_key((string) $f['identification']['name']);
    $check_key = $state . '|' . $key;
    if (isset($keys[$check_key])) {
        $check('no two entries share a name in one state', false, $f['identification']['name'] . ' ' . $state);
    }
    $keys[$check_key] = true;
    if (!$one("SELECT id FROM facilities_v2 WHERE name_key = ? AND (? = '' OR state IS NULL OR state = '' OR state = ?) LIMIT 1", array($key, $state, $state))) {
        $expect[] = $i;
    }
    foreach (array('provenance_source', 'home_of') as $k) {
        if (isset($entry[$k]) && $k === 'home_of') {
            $uq = $one('SELECT unique_name FROM facilities_v2 WHERE id = ?', array((int) $entry[$k]['id']));
            if ($uq !== $entry[$k]['unique_name']) {
                $check('home_of names a record on file', false, $f['identification']['name'] . ' -> #' . $entry[$k]['id'] . ' is ' . var_export($uq, true));
            }
        }
    }
    foreach (array((string) json_encode($f)) as $text) {
        if (preg_match('/[\w.+-]+@[\w-]+\.[a-z]{2,}/i', $text) && !empty($entry['provenance_source'])) {
            $check('no email address in a listed entry', false, $f['identification']['name']);
        }
    }
}
echo count($expect) . " entries are not on file in the mirror\n";

// A record made and merged away leaves its identity row on the record kept: its id is spent, never handed out again.
$spent = (int) $one('SELECT MAX(id) FROM facilities_v2') + 1;
if (!$one('SELECT 1 FROM wpdl_kop_facility_identity WHERE identity_key = ?', array('save:' . $spent))) {
    $pdo->prepare('INSERT INTO wpdl_kop_facility_identity (identity_key, facility_id, unique_name) VALUES (?, ?, ?)')
        ->execute(array('save:' . $spent, (int) $one('SELECT MIN(id) FROM facilities_v2'), 'merged away'));
}
$before = (int) $one('SELECT COUNT(*) FROM facilities_v2');
$homes_before = (int) $one('SELECT COUNT(*) FROM wpdl_kop_program_homes');
$done = kop_apply_new_facility_seeds();
$after = (int) $one('SELECT COUNT(*) FROM facilities_v2');
$check('every entry not on file is created, nothing else', $after - $before === count($expect) && count($done) === count($expect),
    'records ' . $before . ' -> ' . $after . ', reported ' . count($done) . ', expected ' . count($expect));

$ties = 0;
$bad = array();
foreach ($expect as $i) {
    $entry = $entries[$i];
    $f = $entry['facility'];
    $state = strtoupper((string) $f['locationDetails']['state']);
    $id = (int) $one('SELECT id FROM facilities_v2 WHERE name_key = ? AND state = ?', array(kop_facility_name_key($f['identification']['name']), $state));
    $stored = $id ? kop_facility_load($id, $opts) : null;
    if (!$stored) { $bad[] = $f['identification']['name'] . ': not found'; continue; }
    $doc = $stored['doc'];
    $name = $f['identification']['name'];
    if ($doc['identification']['name'] !== $name) $bad[] = "$name: name";
    if ((string) $doc['identification']['currentOperator'] !== (string) ($f['identification']['currentOperator'] ?? '')) $bad[] = "$name: operator";
    if ($doc['operatingPeriod']['status'] !== $f['operatingPeriod']['status']) $bad[] = "$name: status " . $doc['operatingPeriod']['status'];
    if ((string) $doc['location']['city'] !== (string) ($f['locationDetails']['city'] ?? '')) $bad[] = "$name: city " . $doc['location']['city'];
    if (count($doc['licensing']) !== count((array) ($f['licensing'] ?? array()))) $bad[] = "$name: licensing";
    if (count($doc['resourceLinks']) !== count((array) ($f['resourceLinks'] ?? array()))) $bad[] = "$name: links";
    if (array_slice($doc['notes'], 0, count($f['notes'])) !== array_values($f['notes'])) $bad[] = "$name: notes";
    $want_source = (string) ($entry['provenance_source'] ?? 'admin-form');
    if ($doc['provenance']['source'] !== $want_source) $bad[] = "$name: source " . $doc['provenance']['source'];
    if (!(int) $one("SELECT COUNT(*) FROM wpdl_kop_facility_locations WHERE facility_id = ? AND location_key = ? AND role <> 'unknown'", array($id, $entry['location_name']))) {
        $bad[] = "$name: not on the {$entry['location_name']} page";
    }
    $program = $one('SELECT program_id FROM wpdl_kop_program_homes WHERE home_id = ?', array($id));
    if (isset($entry['home_of'])) {
        $ties++;
        if ((int) $program !== (int) $entry['home_of']['id']) $bad[] = "$name: tied to " . var_export($program, true) . ', wanted #' . $entry['home_of']['id'];
        if (!(int) $one('SELECT COUNT(*) FROM wpdl_kop_program_groups WHERE program_id = ?', array((int) $entry['home_of']['id']))) $bad[] = "$name: no group row";
    } elseif ($program !== false) {
        $bad[] = "$name: tied to #$program, wanted no tie";
    }
}
$check('each new record carries its name, operator, status, city, licence, links, notes, source, page and tie', !$bad, implode('; ', array_slice($bad, 0, 12)));
$check('homes tied: one row each, nothing else moved', (int) $one('SELECT COUNT(*) FROM wpdl_kop_program_homes') === $homes_before + $ties, $ties . ' ties');

$check('the id of a record merged away is not handed out again', !$one('SELECT 1 FROM facilities_v2 WHERE id = ?', array($spent))
    && (int) $one('SELECT MAX(id) FROM facilities_v2') === $spent + count($expect), 'spent ' . $spent . ', highest now ' . $one('SELECT MAX(id) FROM facilities_v2'));
$again = kop_apply_new_facility_seeds();
$check('a second run adds nothing', !$again && (int) $one('SELECT COUNT(*) FROM facilities_v2') === $after
    && (int) $one('SELECT COUNT(*) FROM wpdl_kop_program_homes') === $homes_before + $ties, count($again) . ' reported');

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
