<?php
/**
 * "What changed" on data suggestion cards (api/lib-suggestion-changes.php):
 * the record is found, empty and unticked fields never show, a whole-record
 * edit that matches the record is "nothing new", a name with no record is new.
 * Reads tmp/prod.sqlite; writes nothing.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-suggestion-changes.php [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) { fwrite(STDERR, "No mirror at $db_path\n"); exit(2); }
if (!defined('KOP_DB_CONFIG_LOADED')) define('KOP_DB_CONFIG_LOADED', true);
require __DIR__ . '/kop-test-harness.php';
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) { return $v; }, 1);
$pdo->sqliteCreateFunction('JSON_EXTRACT', function ($j, $p) {
    $d = json_decode((string) $j, true);
    foreach (explode('.', substr($p, 2)) as $k) { if (!is_array($d) || !array_key_exists($k, $d)) return null; $d = $d[$k]; }
    return is_array($d) ? json_encode($d) : $d;
}, 2);
require_once dirname(__DIR__) . '/api/lib-suggested-edits.php';
require_once dirname(__DIR__) . '/api/lib-suggestion-changes.php';

$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fails++;
};

// A facility with a page, a state, a type and a status to compare against.
$row = $pdo->query("SELECT id, json_data FROM facilities_v2 WHERE json_extract(json_data, '$.location.state') IS NOT NULL
    AND json_extract(json_data, '$.facilityDetails.type') <> '' AND json_extract(json_data, '$.operatingPeriod.status') = 'Open' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$doc = json_decode($row['json_data'], true);
$name = $doc['identification']['name'];
$state_name = strtolower(str_replace(' ', '-', kop_facility_states()[$doc['location']['state']] ?? ''));

// The mini-form's shape: every field it shows, blank unless typed in, every flag false.
$mini = function (array $over) use ($name) {
    $f = array(
        'identification' => array('name' => $name, 'currentName' => '', 'currentOperator' => '', 'currentOwners' => array(), 'otherNames' => array(), 'knownReferrers' => array()),
        'operatingPeriod' => array('status' => '', 'yearsOfOperation' => ''),
        'facilityDetails' => array('type' => '', 'capacity' => null, 'gender' => '', 'ageRange' => array('min' => null, 'max' => null)),
        'address' => '', 'profileLinks' => array(), 'staff' => array('notableStaff' => array()), 'notes' => array(),
    );
    return array('facilities' => array(array_replace_recursive($f, $over)), 'name' => $name);
};

$c = kop_suggestion_changes($pdo, $mini(array('notes' => array('A new note from a reader.'))), 'Public mini-form submission (facility) for "' . $name . '" from /' . $state_name . '/', 'wpdl_');
$e = $c['entries'][0] ?? null;
$check('mini-form: finds the record by name and the state page it came from', $e && $e['facility_id'] === (int) $row['id'], json_encode($e['facility_id'] ?? null));
$check('mini-form: only the note shows (blank fields and unticked flags are no information)',
    $e && count($e['changes']) === 1 && $e['changes'][0]['label'] === 'Notes' && $e['changes'][0]['added'] === array('A new note from a reader.'),
    json_encode($e['changes'] ?? null));

$c = kop_suggestion_changes($pdo, $mini(array('facilityDetails' => array('type' => 'Wilderness program'))), 'from /' . $state_name . '/', 'wpdl_');
$ch = $c['entries'][0]['changes'][0] ?? null;
$check('a changed value shows as on file -> suggested', $ch && $ch['kind'] === 'changed' && $ch['label'] === 'Program type' && $ch['before'] === $doc['facilityDetails']['type'] && $ch['after'] !== '', json_encode($ch));

$c = kop_suggestion_changes($pdo, $mini(array('facilityDetails' => array('type' => mb_strtoupper($doc['facilityDetails']['type'])))), 'from /' . $state_name . '/', 'wpdl_');
$check('the same value in other case is nothing new', !$c['entries'] && $c['unchanged'] === array($name), json_encode($c));

$whole = $doc;
$whole['facility_id'] = (int) $row['id'];
$c = kop_suggestion_changes($pdo, array('facilities' => array($whole)), 'Fixed it', 'wpdl_');
$check('a whole-record edit equal to the record is "nothing new"', !$c['entries'] && $c['unchanged'] === array($name), json_encode($c['entries']));

$whole['operatingPeriod']['status'] = '';
$whole['facilityDetails']['type'] = '';
$c = kop_suggestion_changes($pdo, array('facilities' => array($whole)), 'Fixed it', 'wpdl_');
$labels = array_column($c['entries'][0]['changes'] ?? array(), 'kind', 'label');
$check('a whole-record edit shows a field it empties', ($labels['Program type'] ?? '') === 'removed', json_encode($labels));
$check('...and only those (status emptied = Unknown, which Approve would write)', array_keys($labels) == array('Status', 'Program type') || array_keys($labels) == array('Program type', 'Status'), json_encode($labels));

$c = kop_suggestion_changes($pdo, array('facilities' => array(array('identification' => array('name' => 'Zzq No Such Program Anywhere'), 'facilityDetails' => array('type' => 'RTC')))), '', 'wpdl_');
$e = $c['entries'][0] ?? null;
$check('a name with no record is marked new, with what was sent (not its name again)', $e && $e['is_new'] && count($e['changes']) === 1 && $e['changes'][0]['label'] === 'Program type', json_encode($e));

$c = kop_suggestion_changes($pdo, array('referrerConsultants' => array(array('firstName' => 'Ann', 'lastName' => 'Lee', 'role' => '', 'affiliations' => array()))), '', 'wpdl_');
$check('other kinds show what was sent, blanks left out', $c['other'] === array(array('label' => 'Referrer consultants', 'after' => 'Ann, Lee')), json_encode($c['other']));

echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
