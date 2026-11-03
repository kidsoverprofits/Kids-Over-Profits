<?php
/**
 * api/lib-fl-inspection-cleanup.php on an in-memory copy of the FL inspection
 * rows in tmp/prod.sqlite: the dry run changes nothing, apply moves every
 * planned report (findings with it) and nothing else, keeps every reviewed
 * finding, leaves no report twice on a facility and no empty DJJ-unmatched-*
 * facility it emptied, and a second run finds nothing left to do.
 *
 *   php -d extension=pdo_sqlite scripts/test-fl-inspection-cleanup.php
 */

require_once dirname(__DIR__) . '/api/lib-fl-inspection-cleanup.php';

$root = dirname(__DIR__);
$plan = json_decode(file_get_contents("$root/seeds/fl-inspection-cleanup.json"), true);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("ATTACH '" . str_replace("'", "''", "$root/tmp/prod.sqlite") . "' AS s");
$fl = "SELECT r.id FROM s.inspection_reports r JOIN s.inspection_facilities f ON f.id = r.facility_id WHERE f.state = 'FL'";
$pdo->exec("CREATE TABLE inspection_facilities AS SELECT * FROM s.inspection_facilities WHERE state = 'FL'");
$pdo->exec("CREATE TABLE inspection_reports AS SELECT id, facility_id, report_id, report_date, categories_json FROM s.inspection_reports WHERE id IN ($fl)");
$pdo->exec("CREATE TABLE inspection_highlights AS SELECT * FROM s.inspection_highlights WHERE report_id IN ($fl)");
$pdo->exec("CREATE TABLE inspection_highlight_scans AS SELECT * FROM s.inspection_highlight_scans WHERE report_id IN ($fl)");
$pdo->exec("CREATE TABLE inspection_report_counts AS SELECT * FROM s.inspection_report_counts WHERE report_id IN ($fl)");
$pdo->exec('DETACH s');
// The real table gives a new row the next id.
$pdo->exec('CREATE TABLE f2 (id INTEGER PRIMARY KEY, state TEXT, facility_name TEXT, full_address TEXT, phone TEXT, program_category TEXT, program_name TEXT,
            executive_director TEXT, bed_capacity TEXT, license_exp_date TEXT, relicense_visit_date TEXT, action TEXT, scraped_timestamp TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec('INSERT INTO f2 SELECT id, state, facility_name, full_address, phone, program_category, program_name, executive_director, bed_capacity,
            license_exp_date, relicense_visit_date, action, scraped_timestamp, created_at, updated_at FROM inspection_facilities');
$pdo->exec('DROP TABLE inspection_facilities');
$pdo->exec('ALTER TABLE f2 RENAME TO inspection_facilities');

$fail = 0;
function check($ok, $what) { global $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n"; if (!$ok) $fail++; }
function one(PDO $pdo, $sql) { return $pdo->query($sql)->fetchColumn(); }
function place(PDO $pdo, $raw) {
    $st = $pdo->prepare("SELECT f.facility_name FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id
                          WHERE json_extract(r.categories_json, '$.program_name_raw') = ? GROUP BY 1 ORDER BY COUNT(*) DESC");
    $st->execute(array($raw));
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

$moved_ids = array_map(function ($m) { return (int) $m['id']; }, $plan['moves']);
$in = implode(',', $moved_ids);
$before = array(
    'facilities' => (int) one($pdo, 'SELECT COUNT(*) FROM inspection_facilities'),
    'reports'    => (int) one($pdo, 'SELECT COUNT(*) FROM inspection_reports'),
    'others'     => $pdo->query("SELECT id, facility_id FROM inspection_reports WHERE id NOT IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR),
    'reviewed'   => $pdo->query("SELECT h.status, r.report_id, h.finding_key FROM inspection_highlights h JOIN inspection_reports r ON r.id = h.report_id WHERE h.status <> 'pending' ORDER BY 2, 3")->fetchAll(PDO::FETCH_ASSOC),
);

if ((int) one($pdo, "SELECT COUNT(*) FROM inspection_reports WHERE id IN ($in)") === 0 || kop_flclean_run($pdo, $plan, false)['stats']['moved'] === 0) {
    echo "The plan has run on the site (no planned row is where the plan found it): nothing to test on this mirror.\n";
    exit(0);
}

check(place($pdo, 'Marion Youth Academy') === array('Marion Regional Juvenile Detention Center'), 'before: Marion Youth Academy reports are on Marion RJDC');

$dry = kop_flclean_run($pdo, $plan, false);
check(!$dry['skipped'], 'dry run: every planned row is where the plan found it (' . implode('; ', array_slice($dry['skipped'], 0, 3)) . ')');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_facilities') === $before['facilities'], 'dry run writes nothing');
echo '     dry run: ' . json_encode($dry['stats']) . "\n";

$run = kop_flclean_run($pdo, $plan, true);
echo '     applied: ' . json_encode($run['stats']) . "\n";
check($run['stats'] === $dry['stats'], 'apply does what the dry run said');
check($run['stats']['moved'] + $run['stats']['twins_folded'] === count($plan['moves']), 'every planned report moved or folded');

$after = $pdo->query("SELECT id, facility_id FROM inspection_reports WHERE id NOT IN ($in) ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
$changed = 0;
foreach ($before['others'] as $id => $fid) if (($after[$id] ?? null) !== $fid) $changed++;
check($changed === 0, "no report outside the plan moved ($changed)");
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_reports') === $before['reports'] - $run['stats']['twins_folded'], 'only folded twins are gone');

$moved_ok = 0;
foreach ($plan['moves'] as $m) {
    $st = $pdo->prepare('SELECT f.program_name FROM inspection_reports r JOIN inspection_facilities f ON f.id = r.facility_id WHERE r.id = ?');
    $st->execute(array((int) $m['id']));
    $want = !empty($m['to']['id']) ? $m['to']['program_name'] : $m['to']['create']['program_name'];
    $got = $st->fetchColumn();
    if ($got === false || strcasecmp($got, $want) === 0) $moved_ok++;
}
check($moved_ok === count($plan['moves']), 'each moved report is on its planned facility');

check(place($pdo, 'Marion Youth Academy') === array('Silver Springs Youth Academy'), 'Marion Youth Academy reports are on Silver Springs Youth Academy');
check(!in_array('Hillsborough Juvenile Detention Center West', place($pdo, 'Hillsborough Girls Academy'), true), 'Hillsborough Girls Academy is off Hillsborough JDC West');
check(!in_array('Lake Parrish Youth Academy', place($pdo, 'Lake Academy'), true), 'Lake Academy (Tampa) is off Lake Parrish Youth Academy');
check(!in_array('Brevard Juvenile Detention Center', place($pdo, 'Brevard Group Treatment Home'), true), 'Brevard Group Treatment Home is off Brevard JDC');

check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_reports WHERE facility_id NOT IN (SELECT id FROM inspection_facilities)') === 0, 'no orphan reports');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_highlights WHERE report_id NOT IN (SELECT id FROM inspection_reports)') === 0, 'no orphan findings');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_highlights h JOIN inspection_reports r ON r.id = h.report_id WHERE h.facility_id <> r.facility_id') === 0, 'findings follow their report');
check((int) one($pdo, 'SELECT COUNT(*) FROM inspection_report_counts c JOIN inspection_reports r ON r.id = c.report_id WHERE c.facility_id <> r.facility_id') === 0, 'counts follow their report');
check((int) one($pdo, 'SELECT COUNT(*) FROM (SELECT facility_id, report_id FROM inspection_reports GROUP BY 1, 2 HAVING COUNT(*) > 1)') === 0, 'no report twice on one facility');
$reviewed = $pdo->query("SELECT h.status, r.report_id, h.finding_key FROM inspection_highlights h JOIN inspection_reports r ON r.id = h.report_id WHERE h.status <> 'pending' ORDER BY 2, 3")->fetchAll(PDO::FETCH_ASSOC);
check(count($reviewed) >= count(array_unique(array_map('json_encode', $before['reviewed']))), 'every reviewed finding is kept (' . count($before['reviewed']) . ' before, ' . count($reviewed) . ' after)');
$emptied = $run['removed'] ? (int) one($pdo, 'SELECT COUNT(*) FROM inspection_facilities WHERE id IN (' . implode(',', array_merge($run['removed'], array_keys($run['merged']))) . ')') : 0;
check($emptied === 0, 'the emptied DJJ-unmatched-* facilities are gone');

$again = kop_flclean_run($pdo, $plan, false);
check($again['stats']['moved'] + $again['stats']['twins_folded'] === 0 && count($again['skipped']) === count($plan['moves']), 'a second run: nothing left to do');

list($links) = kop_ncclean_remap_links(array('links' => array(9 => array(4307, 999999)), 'rejected' => array()), array(999999), array());
check($links['links'][9] === array(4307), 'inspection links drop a removed row');

echo $fail ? "$fail failed\n" : "all passed\n";
exit($fail ? 1 : 0);
