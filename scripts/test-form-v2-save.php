<?php
/**
 * The data form sends v2 documents since 2026-10-25 (docs/plan 4.3 item 17, step 4a). The server must store
 * the same thing it stored for the legacy shape the form sent before:
 *   - an existing record saved from its v2 document and from its legacy copy gives the same stored document
 *     (kop_v2_save_facility_entries(), what save-master.php and Approve call);
 *   - a new facility under a new company, from either shape: the same document, company and links
 *     (kop_v2_save_form_project());
 *   - save-suggestion.php's "unnamed facility with data" check ignores a blank v2 document's defaults.
 * Runs on a scratch copy of the tables it needs from tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-form-v2-save.php [--db=tmp/prod.sqlite] [--records=25]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
$args = getopt('', array('db::', 'records::'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$want_records = (int) ($args['records'] ?? 25);
if (!file_exists($mirror)) { fwrite(STDERR, "No mirror at $mirror\n"); exit(2); }
$db_path = sys_get_temp_dir() . '/kop-form-v2-save-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
foreach ($copy->query("SELECT name, sql FROM src.sqlite_master WHERE type = 'table'
    AND (name LIKE 'wpdl_kop_operator%' OR name LIKE 'wpdl_kop_facility%' OR name IN ('facilities_v2', 'suggested_edits'))")->fetchAll() as $t) {
    $copy->exec($t['sql']);
    $copy->exec("INSERT INTO main.`{$t['name']}` SELECT * FROM src.`{$t['name']}`");
}
$copy->exec('DETACH DATABASE src');
$copy = null;
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });
$GLOBALS['kop_test_wpdb_writes'] = true;
if (!defined('KOP_DB_CONFIG_LOADED')) define('KOP_DB_CONFIG_LOADED', true);
require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
require_once dirname(__DIR__) . '/api/lib-suggested-edits.php';

// MySQL-only SQL the company save uses, on SQLite (as in scripts/test-suggestion-changes.php).
class KOP_Form_V2_Test_PDO extends PDO {
    private function fix($sql) {
        $sql = (string) $sql;
        if (preg_match('/ON\s+DUPLICATE\s+KEY\s+UPDATE/i', $sql)) {
            $sql = preg_replace('/^\s*INSERT\s+INTO\b/i', 'INSERT OR IGNORE INTO', preg_replace('/\s*ON\s+DUPLICATE\s+KEY\s+UPDATE.*$/is', '', $sql));
        }
        return preg_replace('/^\s*INSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
    }
    public function prepare(string $query, array $options = array()): PDOStatement|false { return parent::prepare($this->fix($query), $options); }
    public function exec(string $statement): int|false { return parent::exec($this->fix($statement)); }
}
$mp = new KOP_Form_V2_Test_PDO('sqlite:' . $db_path);
$mp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mp->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$mp->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
$mp->sqliteCreateFunction('JSON_UNQUOTE', function ($v) { return $v; }, 1);
$mp->sqliteCreateFunction('JSON_EXTRACT', function ($j, $p) {
    $d = json_decode((string) $j, true);
    foreach (explode('.', substr($p, 2)) as $k) { if (!is_array($d) || !array_key_exists($k, $d)) return null; $d = $d[$k]; }
    return is_array($d) ? json_encode($d) : $d;
}, 2);
$mp->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);

$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fails++;
};
$opts = array('pdo' => $mp, 'prefix' => 'wpdl_');
$stored = function ($fid) use ($mp) {
    $st = $mp->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
    $st->execute(array($fid));
    $doc = json_decode((string) $st->fetchColumn(), true);
    if (is_array($doc) && isset($doc['provenance'])) unset($doc['provenance']['migratedAt']);
    return $doc;
};
// The JS form converts a loaded record the same way (facilityToV2(), kept equal to the PHP by the parity test).
$as_v2 = function (array $legacy) { $d = kop_facility_normalize($legacy); return $d; };

// 1. Existing records: the legacy copy, then the v2 document, saved onto the same record.
$ids = array_map('intval', $mp->query('SELECT id FROM facilities_v2 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
$step = max(1, (int) floor(count($ids) / $want_records));
$same = 0; $tried = 0; $bad = array();
foreach ($ids as $i => $fid) {
    if ($i % $step !== 0 || $tried >= $want_records) continue;
    $rec = kop_facility_load($fid, $opts);
    if (!$rec) continue;
    $tried++;
    $legacy = kop_facility_to_legacy($rec['doc']);
    $legacy['facility_id'] = $fid;
    kop_v2_save_facility_entries($mp, 'wpdl_', array($legacy), array());
    $a = $stored($fid);
    $v2 = $as_v2($legacy);
    kop_v2_save_facility_entries($mp, 'wpdl_', array($v2), array());
    $b = $stored($fid);
    if ($a === $b) $same++;
    else $bad[] = $fid;
}
$check("existing records: the v2 document stores what the legacy copy stored ($same of $tried)", $tried > 0 && $same === $tried, implode(',', array_slice($bad, 0, 8)));

// 2. A new facility under a new company, from either shape.
$entry = array(
    'identification' => array('name' => 'Zzq Form V2 Ranch', 'currentOperator' => '', 'otherNames' => array('Zzq Old Name')),
    'otherOperators' => array('Zzq Earlier Co'),
    'address' => '1 Test Rd, Erda, UT 84074', 'location' => 'Erda, UT',
    'locationDetails' => array('city' => 'Erda', 'state' => 'UT', 'country' => '', 'zip' => '84074', 'additionalLocations' => array()),
    'facilityDetails' => array('type' => 'RTC', 'gender' => 'Girls'), 'isPrivatelyOwned' => false,
    'operatingPeriod' => array('startYear' => '1999', 'endYear' => '', 'status' => 'Open'),
);
$entry_v2 = $as_v2($entry);
$entry_v2['identification']['name'] = 'Zzq Form V2 Ranch Two';
$new_doc = function ($company, $facility) use ($mp, $stored) {
    $before = array_map('intval', $mp->query('SELECT id FROM facilities_v2')->fetchAll(PDO::FETCH_COLUMN));
    kop_v2_save_form_project($mp, 'wpdl_', $company, array('operator' => array('name' => $company), 'facilities' => array($facility)), 'companies', array('timestamp' => gmdate('c')));
    $after = array_map('intval', $mp->query('SELECT id FROM facilities_v2')->fetchAll(PDO::FETCH_COLUMN));
    $new = array_values(array_diff($after, $before));
    if (count($new) !== 1) return array(null, 0, $new);
    $doc = $stored($new[0]);
    $links = $mp->prepare('SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE facility_id = ?');
    $links->execute(array($new[0]));
    foreach (array('facility_id') as $k) unset($doc[$k]);
    unset($doc['identification']['name'], $doc['identification']['nameKey'], $doc['provenance']['uniqueName'], $doc['provenance']['sourceProject'], $doc['provenance']['sourceProjectId'], $doc['provenance']['sourceOperator']);
    return array($doc, (int) $links->fetchColumn(), $new);
};
list($doc_a, $links_a, $new_a) = $new_doc('Zzq Form V2 Company A', $entry);
list($doc_b, $links_b, $new_b) = $new_doc('Zzq Form V2 Company B', $entry_v2);
$check('new facility: one record from each shape', count($new_a) === 1 && count($new_b) === 1, json_encode(array($new_a, $new_b)));
$check('new facility: the same document from either shape', $doc_a !== null && $doc_a == $doc_b,
    $doc_a && $doc_b ? json_encode(array_diff_assoc(array_map('json_encode', $doc_a), array_map('json_encode', $doc_b))) : '');
$check('new facility: linked to its company either way', $links_a === $links_b && $links_a >= 1, "$links_a / $links_b");
$check('new facility: the v2 document kept what was sent', $doc_b && $doc_b['location']['city'] === 'Erda' && $doc_b['location']['state'] === 'UT'
    && $doc_b['operatingPeriod']['startYear'] === 1999 && in_array('Zzq Earlier Co', $doc_b['identification']['otherOperators'], true)
    && $doc_b['facilityDetails']['isPrivatelyOwned'] === false, json_encode($doc_b['location'] ?? null));

// 3. The "unnamed facility with data" check (save-suggestion.php).
$blank_v2 = kop_facility_normalize(array());
$check('meaningful: a blank v2 document says nothing', !kop_facility_has_meaningful_data($blank_v2));
$check('meaningful: a blank legacy entry says nothing', !kop_facility_has_meaningful_data(array('identification' => array('name' => ''), 'locationDetails' => array('city' => ''), 'operatingPeriod' => array('status' => ''))));
$with_city = $blank_v2; $with_city['location']['city'] = 'Erda';
$with_ops = $blank_v2; $with_ops['identification']['otherOperators'] = array('Zzq Co');
$with_status = $blank_v2; $with_status['operatingPeriod']['status'] = 'Closed';
$check('meaningful: a v2 city, other operator or real status counts', kop_facility_has_meaningful_data($with_city)
    && kop_facility_has_meaningful_data($with_ops) && kop_facility_has_meaningful_data($with_status));
$mp = null;

echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
