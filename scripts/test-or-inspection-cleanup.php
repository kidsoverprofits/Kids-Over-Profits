<?php
/**
 * api/lib-fl-inspection-cleanup.php running seeds/or-inspection-cleanup.json
 * (bug report #5) on an in-memory copy of the OR inspection rows in
 * tmp/prod.sqlite: the dry run changes nothing; apply leaves Parry Center's
 * rows with no Children's Farm Home or Sender House visit, puts each visit on
 * every campus it names, folds the older copies, keeps every reviewed finding,
 * and a second run finds nothing left to do. The FL plan still runs as FL.
 *
 *   php -d extension=pdo_sqlite scripts/test-or-inspection-cleanup.php
 */

require_once dirname(__DIR__) . '/api/lib-fl-inspection-cleanup.php';

$root = dirname(__DIR__);
$plan = json_decode(file_get_contents("$root/seeds/or-inspection-cleanup.json"), true);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ATTACH '" . str_replace("'", "''", "$root/tmp/prod.sqlite") . "' AS s");
$or = "SELECT r.id FROM s.inspection_reports r JOIN s.inspection_facilities f ON f.id = r.facility_id WHERE f.state = 'OR'";
// As on the site, a new row gets the next id.
$pdo->exec('CREATE TABLE inspection_facilities (id INTEGER PRIMARY KEY, state TEXT, facility_name TEXT, full_address TEXT, phone TEXT, program_category TEXT, program_name TEXT,
            executive_director TEXT, bed_capacity TEXT, license_exp_date TEXT, relicense_visit_date TEXT, action TEXT, scraped_timestamp TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO inspection_facilities SELECT id, state, facility_name, full_address, phone, program_category, program_name, executive_director, bed_capacity,
            license_exp_date, relicense_visit_date, action, scraped_timestamp, created_at, updated_at FROM s.inspection_facilities WHERE state IN ('OR', 'FL')");
$pdo->exec('CREATE TABLE inspection_reports (id INTEGER PRIMARY KEY, facility_id INTEGER NOT NULL, report_id TEXT NOT NULL, report_date TEXT, raw_content TEXT, categories_json TEXT)');
$pdo->exec("INSERT INTO inspection_reports SELECT id, facility_id, report_id, report_date, raw_content, categories_json FROM s.inspection_reports WHERE id IN ($or)");
$pdo->exec("CREATE TABLE inspection_highlights AS SELECT * FROM s.inspection_highlights WHERE report_id IN ($or)");
$pdo->exec("CREATE TABLE inspection_report_counts AS SELECT * FROM s.inspection_report_counts WHERE report_id IN ($or)");
$pdo->exec('DETACH s');

$fail = 0;
function check($ok, $what) { global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n"; if (!$ok) $fail++; }
function one(PDO $pdo, $sql) { return $pdo->query($sql)->fetchColumn(); }
/** Visits on a Parry Center row whose text names no Parry Center (the bug). */
function parry_strays(PDO $pdo) {
    return (int) one($pdo, "SELECT COUNT(*) FROM inspection_reports WHERE facility_id IN (1711, 3739, 3740, 7171) AND LOWER(raw_content) NOT LIKE '%parry%'");
}
/** Report dates on the site-visit row named $name (its program is its name). */
function dates_on(PDO $pdo, $name) {
    $st = $pdo->prepare("SELECT r.report_date FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id
                          WHERE f.state = 'OR' AND f.facility_name = ? AND f.program_name = ? ORDER BY r.id");
    $st->execute(array($name, $name));
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

check(($plan['state'] ?? '') === 'OR', 'the plan is an Oregon plan');
$in = implode(',', array_map(function ($m) { return (int) $m['id']; }, $plan['moves']));
if ((int) one($pdo, "SELECT COUNT(*) FROM inspection_reports WHERE id IN ($in) AND facility_id IN (1711, 3740, 7171)") === 0) {
    echo "The plan has run on the site (no planned row is where the plan found it): nothing to test on this mirror.\n";
    exit($fail ? 1 : 0);
}
$before = array(
    'facilities' => (int) one($pdo, 'SELECT COUNT(*) FROM inspection_facilities'),
    'reports'    => (int) one($pdo, 'SELECT COUNT(*) FROM inspection_reports'),
    'others'     => $pdo->query("SELECT id, facility_id FROM inspection_reports WHERE id NOT IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR),
    'reviewed'   => (int) one($pdo, "SELECT COUNT(DISTINCT finding_key) FROM inspection_highlights WHERE status <> 'pending'"),
);
check(parry_strays($pdo) > 0, 'before: Parry Center rows hold Farm Home / Sender House visits (' . parry_strays($pdo) . ')');

$dry = kop_flclean_run($pdo, $plan, false);
check(!$dry['skipped'], 'dry run: every planned row is where the plan found it (' . implode('; ', array_slice($dry['skipped'], 0, 3)) . ')');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_facilities') === $before['facilities'], 'dry run writes nothing');
echo '     dry run: ' . json_encode($dry['stats']) . "\n";

$run = kop_flclean_run($pdo, $plan, true);
echo '     applied: ' . json_encode($run['stats']) . "\n";
check($run['stats'] === $dry['stats'], 'apply does what the dry run said');
check($run['stats']['facilities_created'] === 2, "two campus rows created (Children's Farm Home, Sender House)");
check(parry_strays($pdo) === 0, 'no Parry Center row holds a visit that does not name Parry Center');

$after = $pdo->query("SELECT id, facility_id FROM inspection_reports WHERE id NOT IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$changed = 0;
foreach ($before['others'] as $id => $fid) if (($after[$id] ?? null) !== $fid) $changed++;
check($changed === 0, "no report outside the plan moved ($changed)");
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_reports') === $before['reports'] - $run['stats']['twins_folded'] + $run['stats']['copied'], 'rows: folded twins gone, copies added');

$fh = dates_on($pdo, "Trillium Family Services - Children's Farm Home");
foreach (array('04/22/2021', '05/11/2022', '08/05/2022', '09/08/2022', '11/29/2023', '08/24/2021', '07/25/2023') as $d) {
    check(in_array($d, $fh, true), "Children's Farm Home holds the $d visit");
}
check(count($fh) === 7, "Children's Farm Home holds each of its 7 visits once (" . count($fh) . ')');
$sh = dates_on($pdo, 'Trillium Family Services - Sender House');
check(count($sh) === 5, 'Sender House holds its 3 visits and the 2 renewals (' . implode(', ', $sh) . ')');
$parry = $pdo->query('SELECT report_date FROM inspection_reports WHERE facility_id = 7171 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check($parry === array('08/24/2021', '12/09/2021', '12/20/2022', '07/25/2023', '02/27/2024', '05/06/2025'), "7171 keeps Parry Center's visits and the renewals (" . implode(', ', $parry) . ')');

check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_highlights WHERE report_id NOT IN (SELECT id FROM inspection_reports)') === 0, 'no orphan findings');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_highlights h JOIN inspection_reports r ON r.id = h.report_id WHERE h.facility_id <> r.facility_id') === 0, 'findings follow their report');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_report_counts c JOIN inspection_reports r ON r.id = c.report_id WHERE c.facility_id <> r.facility_id') === 0, 'counts follow their report');
check((int) one($pdo, 'SELECT COUNT(*) FROM (SELECT facility_id, report_id FROM inspection_reports GROUP BY 1, 2 HAVING COUNT(*) > 1)') === 0, 'no report twice on one facility');
check((int) one($pdo, "SELECT COUNT(DISTINCT finding_key) FROM inspection_highlights WHERE status <> 'pending'") === $before['reviewed'], 'every reviewed finding is kept');

$again = kop_flclean_run($pdo, $plan, false);
check($again['stats']['moved'] + $again['stats']['twins_folded'] + $again['stats']['copied'] === 0, 'a second run: nothing left to do (' . json_encode($again['stats']) . ')');

$fl = json_decode(file_get_contents("$root/seeds/fl-inspection-cleanup.json"), true);
kop_flclean_run($pdo, $fl, false);
check(kop_flclean_state() === 'FL', 'a plan with no state still runs as Florida');
foreach ($plan['renames'] as $r) {
    $n = $r['to']['facility_name'];
    check(strpos($n, 'Date of site visit') === false && substr_count($n, '(') === substr_count($n, ')'), "rename {$r['id']} gives a whole name");
}

echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
