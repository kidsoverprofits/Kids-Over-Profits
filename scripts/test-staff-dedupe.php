<?php
/**
 * kop_facility_staff_dedupe() (inc/facility-store.php), which kop_facility_save()
 * runs on every staff list, and the one-time pass over records saved before it
 * (kop_apply_staff_dedupe() in inc/admin.php), against an in-memory copy of
 * facilities_v2 from tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-staff-dedupe.php [--db=tmp/prod.sqlite] [--list]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$list = isset($args['list']);

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-store.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// --- the rule -----------------------------------------------------------------
$out = kop_facility_staff_dedupe(array(
    array('name' => 'Larry D. White', 'role' => 'Medical Director', 'pastJobs' => ''),
    array('name' => 'Robert W. Lichfield', 'role' => 'Owner', 'pastJobs' => ''),
    array('name' => 'Robert B. Lichfield', 'role' => 'Owner', 'pastJobs' => ''),
    array('name' => 'Larry D White', 'role' => 'medical director', 'pastJobs' => 'Provo Canyon', 'personId' => 5, 'source' => 'Woodbury Reports'),
    array('name' => 'Larry D. White', 'role' => 'Psychiatrist', 'pastJobs' => ''),
    array('name' => '', 'role' => 'Therapist', 'pastJobs' => ''),
    array('name' => '', 'role' => 'Therapist', 'pastJobs' => ''),
));
$check('same name and role (case, punctuation aside) is one entry', count($out) === 6, count($out) . ' entries');
$check('the first keeps its place and spelling', $out[0]['name'] === 'Larry D. White' && $out[0]['role'] === 'Medical Director');
$check('the first takes blank fields from the copy', $out[0]['pastJobs'] === 'Provo Canyon' && $out[0]['personId'] === 5 && $out[0]['source'] === 'Woodbury Reports');
$check('a different middle initial stays a different person', $out[1]['name'] === 'Robert W. Lichfield' && $out[2]['name'] === 'Robert B. Lichfield');
$check('the same person in another role stays', $out[3]['role'] === 'Psychiatrist');
$check('entries with no name are left alone', $out[4]['role'] === 'Therapist' && $out[5]['role'] === 'Therapist');
$check('a list with no repeats is returned as it was', kop_facility_staff_dedupe(array_slice($out, 0, 4)) === array_slice($out, 0, 4));

// --- the one-time pass, on a copy of the mirror -----------------------------------
if (!file_exists($db_path)) {
    echo "SKIP mirror checks: no $db_path (scripts/sync-prod-sqlite.py)\n";
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ATTACH DATABASE " . $pdo->quote($db_path) . " AS src");
    $pdo->exec('CREATE TABLE facilities_v2 AS SELECT * FROM src.facilities_v2');
    $pdo->exec('DETACH DATABASE src');
    $opts = array('pdo' => $pdo, 'prefix' => '');

    $count = function (array $doc) {
        $n = 0;
        foreach (array('administrator', 'notableStaff') as $l) $n += count((array) ($doc['staff'][$l] ?? array()));
        return $n;
    };
    // As kop_apply_staff_dedupe() saves: the record normalized, its provenance kept.
    $normal = function (array $stored) {
        $doc = kop_facility_normalize(kop_facility_to_legacy($stored['doc']), array('facility_id' => $stored['id'], 'unique_name' => $stored['unique_name']));
        $doc['provenance'] = $stored['doc']['provenance'];
        return $doc;
    };
    $hits = array();
    foreach ($pdo->query('SELECT id, name, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $doc = json_decode((string) $row['json_data'], true);
        foreach (array('administrator', 'notableStaff') as $l) {
            $s = (array) ($doc['staff'][$l] ?? array());
            if (count(kop_facility_staff_dedupe($s)) !== count($s)) {
                $hits[(int) $row['id']] = array($row['name'], $count($doc), $doc);
            }
        }
    }
    echo count($hits) . " records with a repeated staff entry\n";

    $bad = array();
    $removed = 0;
    foreach ($hits as $id => $h) {
        list($name, $before, $old) = $h;
        $stored = kop_facility_load($id, $opts);
        kop_facility_save($normal($stored), $opts + array('skip_memberships' => true), $status);
        $old = $normal($stored);   // compare with what a plain save would write
        $new = kop_facility_load($id, $opts)['doc'];
        $removed += $before - $count($new);
        // Nothing but the staff lists may change.
        $a = $old; $b = $new;
        unset($a['staff']['administrator'], $a['staff']['notableStaff'], $b['staff']['administrator'], $b['staff']['notableStaff']);
        unset($a['identification']['nameKey'], $b['identification']['nameKey']);
        if ($a != $b || $status !== 'updated') $bad[] = "$id $name ($status)";
        // Every distinct person + role is still there.
        foreach (array('administrator', 'notableStaff') as $l) {
            $want = kop_facility_staff_dedupe((array) ($old['staff'][$l] ?? array()));
            if (count($want) !== count((array) ($new['staff'][$l] ?? array()))) $bad[] = "$id $name: $l";
        }
        if ($list) echo "  $id $name: $before -> " . $count($new) . "\n";
    }
    $check('each record is rewritten with only its staff lists changed', !$bad, implode('; ', array_slice($bad, 0, 5)));
    echo "$removed repeated entries folded\n";

    $left = 0;
    foreach (array_keys($hits) as $id) {
        $d = kop_facility_load($id, $opts)['doc'];
        foreach (array('administrator', 'notableStaff') as $l) {
            $s = (array) ($d['staff'][$l] ?? array());
            if (count(kop_facility_staff_dedupe($s)) !== count($s)) $left++;
        }
    }
    $check('no repeats left after the pass', $left === 0, "$left lists");

    $id = array_key_first($hits);
    if ($id) {
        $stored = kop_facility_load($id, $opts);
        kop_facility_save($normal($stored), $opts, $status);
        $check('saving a folded record again changes nothing', $status === 'unchanged', (string) $status);
    }
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
