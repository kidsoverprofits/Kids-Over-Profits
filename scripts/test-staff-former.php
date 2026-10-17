<?php
/**
 * Who on a staff list has left (inc/staff-former.php): kop_staff_role_with_former() on the roles the
 * importers write ("Former" only when the source says so, never "current"), then the passes over records
 * (kop_staff_former_pass(), api/fix-staff-former.php; kop_staff_unconfirmed_pass(),
 * api/fix-staff-unconfirmed.php) on an in-memory copy of facilities_v2 from tmp/prod.sqlite: what changes,
 * nothing else does, the records still validate, a second run finds nothing, undo puts every role back.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-staff-former.php [--db=tmp/prod.sqlite] [--list]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-store.php';
require_once dirname(__DIR__) . '/inc/staff-former.php';
if (!function_exists('kop_v2_with_write_lock')) {
    function kop_v2_with_write_lock($pdo, $fn) { return $fn(); }
}

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// --- the rule: "Former" only when the source says the person left, never "current" (owner, 2026-10-17) ---
$cases = array(
    // An old sighting is not a confirmation: its year says when.
    array('Admissions (2007, Woodbury Reports)', false, 'Admissions (2007, Woodbury Reports)'),
    array('Admissions, Marketing (2009-2010, Woodbury Reports)', false, 'Admissions, Marketing (2009-2010, Woodbury Reports)'),
    array('Founder (2021, HEAL)', false, 'Founder (2021, HEAL)'),
    // The source said they left.
    array('Director (2008, Woodbury Reports), left', false, 'Former Director (2008, Woodbury Reports)'),
    array('Cook (current) (2021, HEAL)', false, 'Cook (2021, HEAL)'),
    array('Current Clinical Director', false, 'Clinical Director'),
    array('Co-Founder and currently Executive Director', false, 'Co-Founder and Executive Director'),
    array('Teacher (2025, HEAL)', false, 'Teacher (2025, HEAL)'),
    array('Clinical Director (2005, r/troubledteens wiki)', false, 'Clinical Director (2005, r/troubledteens wiki)'),
    array('Board of Directors (2015-2025, HEAL)', false, 'Board of Directors (2015-2025, HEAL)'),
    array('Executive Director', false, 'Executive Director'),
    array('CEO (appointed 2000)', false, 'CEO (appointed 2000)'),
    // A closed program is not a confirmation either.
    array('Executive Director', true, 'Executive Director'),
    array('Former Program Director (before 2005, Woodbury Reports)', false, 'Former Program Director (before 2005, Woodbury Reports)'),
    array('Former Program Director', true, 'Former Program Director'),
    array('Founder, former Executive Director', false, 'Founder, former Executive Director'),
    array('Founder, former Executive Director', true, 'Founder, former Executive Director'),
    array('', true, ''),
);
foreach ($cases as $c) {
    $got = kop_staff_role_with_former($c[0], $c[1], 2026);
    $check('"' . $c[0] . '"' . ($c[1] ? ' at a closed program' : '') . ' -> "' . $c[2] . '"', $got === $c[2], $got);
}
$check('an old sighting is read from the last Woodbury/HEAL parenthesis', kop_staff_role_sighting_year('Psychiatric social worker (part-time) (2010, Woodbury Reports)') === 2010);
$check('closed: status or end year', kop_staff_doc_closed(array('operatingPeriod' => array('status' => 'Closed'))) && kop_staff_doc_closed(array('operatingPeriod' => array('endYear' => 2019)))
    && !kop_staff_doc_closed(array('operatingPeriod' => array('status' => 'Open'))));
$check('wiki staff and roleless entries are the wiki pass\'s, not this one\'s', kop_staff_former_skip(array('name' => 'A', 'role' => 'CEO', 'source' => 'r/troubledteens wiki')) && kop_staff_former_skip(array('name' => 'A', 'role' => '')));

// --- the pass, on a copy of the mirror ------------------------------------------------
if (!file_exists($db_path)) {
    echo "SKIP mirror checks: no $db_path (scripts/sync-prod-sqlite.py)\n";
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ATTACH DATABASE " . $pdo->quote($db_path) . " AS src");
    $pdo->exec('CREATE TABLE facilities_v2 AS SELECT * FROM src.facilities_v2');
    $pdo->exec('DETACH DATABASE src');
    $opts = array('pdo' => $pdo, 'prefix' => '');
    $GLOBALS['kop_test_wpdb_writes'] = true;   // update_option() keeps the pass's log
    $before = array();
    foreach ($pdo->query('SELECT id, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_ASSOC) as $r) $before[(int) $r['id']] = json_decode($r['json_data'], true);

    $dry = kop_staff_former_pass($opts, false, false, 2026);
    $why = array();
    foreach ($dry as $c) $why[$c['why']] = ($why[$c['why']] ?? 0) + 1;
    echo count($dry) . ' entries would change ' . json_encode($why) . "\n";
    if (isset($args['list'])) foreach ($dry as $c) echo "  #{$c['id']} {$c['name']}: {$c['from']} -> {$c['to']} ({$c['why']})\n";
    $check('the dry run writes nothing', $pdo->query('SELECT COUNT(*) FROM facilities_v2')->fetchColumn() == count($before)
        && json_decode($pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) ($dry[0]['id'] ?? 0))->fetchColumn(), true) == $before[(int) ($dry[0]['id'] ?? 0)]);
    $check('no wiki entry is touched', !array_filter($dry, function ($c) use ($before) {
        foreach (array('administrator', 'notableStaff') as $l) foreach ((array) ($before[$c['id']]['staff'][$l] ?? array()) as $e)
            if (($e['name'] ?? '') === $c['name'] && ($e['role'] ?? '') === $c['from'] && ($e['source'] ?? '') === 'r/troubledteens wiki') return true;
        return false;
    }));

    $done = kop_staff_former_pass($opts, true, false, 2026);
    $check('apply changes what the dry run said', count($done) === count($dry), count($done) . ' of ' . count($dry));
    $bad = array();
    $errors = 0;
    foreach (array_unique(array_column($done, 'id')) as $id) {
        $new = kop_facility_load($id, $opts)['doc'];
        foreach (array_filter(kop_facility_validate($new), function ($v) { return $v['severity'] === 'error'; }) as $v) $errors++;
        // Each changed entry holds its new role; nothing else in the staff lists moved.
        foreach (array('administrator', 'notableStaff') as $l) {
            $a = (array) ($before[$id]['staff'][$l] ?? array());
            $b = (array) ($new['staff'][$l] ?? array());
            if (count($a) !== count($b)) { $bad[] = "$id $l count"; continue; }
            foreach ($a as $i => $e) {
                $want = $e['role'] ?? '';
                foreach ($done as $c) if ($c['id'] === $id && $c['list'] === $l && $c['name'] === ($e['name'] ?? '') && $c['from'] === $want) $want = $c['to'];
                if (($b[$i]['role'] ?? '') !== $want || ($b[$i]['name'] ?? '') !== ($e['name'] ?? '') || ($b[$i]['source'] ?? null) !== ($e['source'] ?? null)) $bad[] = "$id $l #$i " . ($b[$i]['role'] ?? '');
            }
        }
    }
    $check('each changed entry holds its new role, names and sources as they were', !$bad, implode('; ', array_slice($bad, 0, 3)));
    $check('the records still validate', $errors === 0, "$errors errors");
    $check('a second run finds nothing', count(kop_staff_former_pass($opts, false, false, 2026)) === 0);
    $left = 0;
    foreach ($pdo->query('SELECT json_data FROM facilities_v2')->fetchAll(PDO::FETCH_COLUMN) as $j) {
        $d = json_decode($j, true);
        foreach (array('administrator', 'notableStaff') as $l) foreach ((array) ($d['staff'][$l] ?? array()) as $e)
            if (!kop_staff_former_skip($e) && preg_match('/,\s*left\s*$|\(current\) \(/i', (string) $e['role'])) $left++;
    }
    $check('no ", left" or "(current) (" left in a role', $left === 0, "$left left");

    $back = kop_staff_former_pass($opts, true, true, 2026);
    $check('undo puts every role back', count($back) === count($done), count($back) . ' of ' . count($done));
    $same = true;
    foreach (array_unique(array_column($done, 'id')) as $id) {
        $d = kop_facility_load($id, $opts)['doc'];
        foreach (array('administrator', 'notableStaff') as $l) {
            if (array_column((array) ($d['staff'][$l] ?? array()), 'role') !== array_column((array) ($before[$id]['staff'][$l] ?? array()), 'role')) $same = false;
        }
    }
    $check('...exactly as they were', $same);

    // Taking off the "Former" no source confirmed: the old pass's log says what each role was.
    $cand = array();
    foreach ($before as $id => $d) {
        foreach ((array) ($d['staff']['notableStaff'] ?? array()) as $i => $e) {
            if (!kop_staff_former_skip($e) && !preg_match('/\b(?:former|current)/i', (string) $e['role'])) $cand[] = array($id, $i, $e);
        }
        if (count($cand) >= 4) break;
    }
    if (count($cand) < 4) {
        $check('four staff entries to try the clean-up on', false);
    } else {
        $set = array();
        $GLOBALS['kop_test_options']['kop_staff_former_log'] = array();
        $GLOBALS['kop_test_options']['kop_staff_unconfirmed_log'] = array();
        // [0] old sighting marked Former, [1] the source said "left", [2] Former typed by hand (no log), [3] "Current ..."
        $roles = array(
            array('Former ' . $cand[0][2]['role'], $cand[0][2]['role']),
            array('Former ' . $cand[1][2]['role'], $cand[1][2]['role'] . ', left'),
            array('Former ' . $cand[2][2]['role'], null),
            array('Current ' . $cand[3][2]['role'], null),
        );
        foreach ($cand as $k => $c) {
            list($id, $i, $e) = $c;
            $d = kop_facility_load($id, $opts)['doc'];
            $d['staff']['notableStaff'][$i]['role'] = $roles[$k][0];
            $pdo->prepare('UPDATE facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(json_encode($d), $id));
            if ($roles[$k][1] !== null) $GLOBALS['kop_test_options']['kop_staff_former_log'][$id][] = array('list' => 'notableStaff', 'name' => $e['name'], 'from' => $roles[$k][1], 'to' => $roles[$k][0]);
        }
        $role = function ($k) use ($cand, $opts) { return (string) kop_facility_load($cand[$k][0], $opts)['doc']['staff']['notableStaff'][$cand[$k][1]]['role']; };
        $un = kop_staff_unconfirmed_pass($opts, false);
        if (isset($args['list'])) foreach ($un as $c) echo "  #{$c['id']} {$c['name']}: {$c['from']} -> {$c['to']} ({$c['why']})\n";
        kop_staff_unconfirmed_pass($opts, true);
        $check('an old sighting marked Former loses it', $role(0) === $cand[0][2]['role'], $role(0));
        $check('a Former the source confirmed ("left") stays', $role(1) === $roles[1][0], $role(1));
        $check('a Former typed by hand (nothing says what the source gave) stays', $role(2) === $roles[2][0], $role(2));
        $check('"Current" comes off', $role(3) === kop_staff_role_no_current($roles[3][0]) && stripos($role(3), 'current') === false, $role(3));
        $check('a second run finds nothing', count(kop_staff_unconfirmed_pass($opts, false)) === 0);
        kop_staff_unconfirmed_pass($opts, true, true);
        $check('undo puts the roles back exactly', $role(0) === $roles[0][0] && $role(1) === $roles[1][0] && $role(2) === $roles[2][0] && $role(3) === $roles[3][0], $role(0) . ' | ' . $role(3));
    }

    // Entries that are not people: two of Moonridge's phase-sheet rows put among its staff, taken off and put back.
    $mid = 10343;
    $row = $pdo->query("SELECT json_data FROM facilities_v2 WHERE id = $mid")->fetchColumn();
    if ($row) {
        $d = json_decode($row, true);
        $staff0 = (array) ($d['staff']['notableStaff'] ?? array());
        $junk = array(array('name' => 'Send mail', 'role' => 'All students. (2021, HEAL)', 'pastJobs' => '', 'source' => 'HEAL, staff list for Moonridge Academy'),
            array('name' => 'Worn down', 'role' => 'After taken off Safety (2021, HEAL)', 'pastJobs' => '', 'source' => 'HEAL, staff list for Moonridge Academy'));
        $with = $staff0;
        array_splice($with, min(1, count($with)), 0, array($junk[0]));
        $with[] = $junk[1];
        $d['staff']['notableStaff'] = $with;
        $pdo->prepare("UPDATE facilities_v2 SET json_data = ? WHERE id = $mid")->execute(array(json_encode($d)));
        $names = function () use ($pdo, $mid) { return array_column((array) (kop_facility_load($mid, array('pdo' => $pdo, 'prefix' => ''))['doc']['staff']['notableStaff'] ?? array()), 'name'); };
        $before_names = $names();
        $gone = kop_staff_not_people_pass($opts, true);
        $mine = array_filter($gone, function ($g) use ($mid) { return $g['id'] === $mid; });
        $check('not-a-person entries come off', count($mine) === 2 && !array_intersect(array('Send mail', 'Worn down'), $names()), count($mine) . ' taken off here, ' . count($gone) . ' in all');
        $check('...and nobody else', count($names()) === count($before_names) - 2);
        $check('a second run takes nothing', count(kop_staff_not_people_pass($opts, false)) === 0);
        kop_staff_not_people_pass($opts, true, true);
        $check('undo puts them back where they were', $names() === $before_names, implode(', ', array_slice($names(), 0, 3)));
    } else {
        echo "SKIP not-a-person checks: no record $mid in the mirror\n";
    }
    $check('importers refuse names that are not people', kop_staff_name_not_person('2 off campus activities in a month') && kop_staff_name_not_person('2 Investigates: Police raid Roswell school')
        && !kop_staff_name_not_person('Tawny Davis') && !kop_staff_name_not_person('Salesi Misinale Fakahua (aka Nale Fakahua)'));
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
