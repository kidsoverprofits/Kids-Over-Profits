<?php
/**
 * inc/staff-copy-years.php: staff years that are only a wiki page's copy date come off roles and past jobs,
 * once, with an undo. Rules on fixed lines, then the pass and its undo on an in-memory copy of
 * facilities_v2 and kop_person_jobs from tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-staff-copy-years.php [--db=tmp/prod.sqlite] [--list]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$list = isset($args['list']);

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-store.php';
require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
require_once dirname(__DIR__) . '/inc/person-jobs.php';
require_once dirname(__DIR__) . '/inc/staff-copy-years.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// --- the rules ------------------------------------------------------------------
$wiki = 'r/troubledteens wiki, page "Spring Ridge Academy" (as of 2025-12-18)';
$check('a wiki copy names its year', kop_scy_copy_year($wiki) === 2025);
$check('HEAL and Woodbury name none', kop_scy_copy_year('HEAL, staff list for X (archived 2025-07-11)') === 0 && kop_scy_copy_year('Woodbury Reports, May 2013, p. 9') === 0);
$check('the copy year comes off a role', kop_scy_role('Former Therapist (2025, r/troubledteens wiki)', $wiki) === 'Former Therapist');
$check('"before" the copy year comes off', kop_scy_role('Former Staff (before 2025, r/troubledteens wiki)', $wiki) === 'Former Staff');
$check('a bare copy year comes off', kop_scy_role('Former Founder (2025)', $wiki) === 'Former Founder');
$check('a year the source gave stays', kop_scy_role('Former Staff (before 2001, r/troubledteens wiki)', $wiki) === 'Former Staff (before 2001, r/troubledteens wiki)');
$check('a range ending in the copy year stays', kop_scy_role('CEO (2021-2025, r/troubledteens wiki)', $wiki) === 'CEO (2021-2025, r/troubledteens wiki)');
$check('a HEAL year stays', kop_scy_role('Therapist (2025, HEAL)', 'HEAL, staff list for X (archived 2025-07-16)') === 'Therapist (2025, HEAL)');
$job = array('id' => 1, 'years' => '2025', 'source' => $wiki);
$check('a job dated only by the copy loses its years', kop_scy_job_action($job, array($job)) === 'clear');
$check('...or goes when another job at that place says it', kop_scy_job_action($job, array($job, array('id' => 2, 'years' => '1998-2008', 'source' => 'Woodbury'))) === 'hide');
$check('a job with its own year is left', kop_scy_job_action(array('id' => 3, 'years' => '1998', 'source' => $wiki), array()) === '');

// --- the pass and its undo, on a copy of the mirror ------------------------------
if (!file_exists($db_path)) {
    echo "SKIP mirror checks: no $db_path (scripts/sync-prod-sqlite.py)\n";
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("ATTACH DATABASE " . $pdo->quote($db_path) . " AS src");
    $pdo->exec('CREATE TABLE facilities_v2 AS SELECT * FROM src.facilities_v2');
    $pdo->exec('CREATE TABLE kop_person_jobs AS SELECT * FROM src.wpdl_kop_person_jobs');
    $pdo->exec('DETACH DATABASE src');
    $pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
    $opts = array('pdo' => $pdo, 'prefix' => '');
    $GLOBALS['kop_test_wpdb_writes'] = true;

    $roles = function () use ($pdo) {
        $out = array();
        foreach ($pdo->query('SELECT id, json_data FROM facilities_v2')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $doc = json_decode((string) $row['json_data'], true);
            foreach (array('administrator', 'notableStaff') as $l) {
                foreach ((array) ($doc['staff'][$l] ?? array()) as $i => $p) {
                    if (is_array($p)) $out[$row['id'] . "|$l|$i"] = array((string) ($p['name'] ?? ''), (string) ($p['role'] ?? ''), (string) ($p['source'] ?? ''));
                }
            }
        }
        return $out;
    };
    $jobs = function () use ($pdo) {
        $out = array();
        foreach ($pdo->query('SELECT id, years, hidden FROM kop_person_jobs')->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int) $r['id']] = $r['years'] . '|' . $r['hidden'];
        return $out;
    };
    $before_roles = $roles();
    $before_jobs = $jobs();
    $res = kop_scy_pass($opts);
    echo json_encode($res) . "\n";
    $after_roles = $roles();
    $changed = $left = 0;
    $wrong = array();
    // A role that loses its copy year can match another entry of the same person word for word; the save
    // folds the two (kop_facility_staff_dedupe()). Those records shift their entries and are checked by name.
    $folded = array();
    foreach (array_diff_key($before_roles, $after_roles) as $k => $b) $folded[strtok($k, '|')] = true;
    foreach ($before_roles as $k => $b) {
        $a = $after_roles[$k] ?? null;
        if (isset($folded[strtok($k, '|')])) continue;
        if (!$a) { $wrong[] = "$k gone"; continue; }
        if ($a[0] !== $b[0]) $wrong[] = "$k name {$b[0]} -> {$a[0]}";
        if ($a[1] !== $b[1]) {
            $changed++;
            if ($a[1] !== kop_scy_role($b[1], $b[2])) $wrong[] = "$k role {$b[1]} -> {$a[1]}";
            if ($list) echo "  {$b[0]}: {$b[1]} -> {$a[1]}\n";
        }
        if (kop_scy_role($a[1], $a[2]) !== $a[1]) $left++;
    }
    $check('the pass changed roles (folded entries aside)', $changed > 0 && $changed <= $res['roles'] && $res['roles'] - $changed <= count($folded), "$changed changed, reported {$res['roles']}");
    $check('every role kept its name, and only its copy year changed', !$wrong, implode('; ', array_slice($wrong, 0, 5)));
    $check('no role keeps a copy year', $left === 0, "$left left");
    $dropped = count($before_roles) - count($after_roles);
    $check('entries dropped only where two became one', $dropped >= 0 && $dropped <= count($folded), "$dropped dropped, " . count($folded) . ' records folded: ' . implode(',', array_keys($folded)));
    $names_ok = true;
    foreach (array_keys($folded) as $fid) {
        $nb = $na = array();
        foreach ($before_roles as $k => $b) if (strtok($k, '|') === (string) $fid) $nb[$b[0]] = true;
        foreach ($after_roles as $k => $a) if (strtok($k, '|') === (string) $fid) $na[$a[0]] = true;
        if (array_keys($nb) != array_keys($na)) $names_ok = false;
    }
    $check('a folded record still names everyone it did', $names_ok);
    $j = $pdo->query("SELECT COUNT(*) FROM kop_person_jobs WHERE hidden = 0 AND source LIKE '%troubledteens wiki%(as of %' AND (years = substr(source, instr(source, '(as of ') + 7, 4) OR years = 'before ' || substr(source, instr(source, '(as of ') + 7, 4))")->fetchColumn();
    $check('no shown job keeps a copy year', (int) $j === 0, "$j left");
    $check('jobs were cleared and hidden', $res['jobs_cleared'] > 0 && $res['jobs_hidden'] > 0);
    $sra = json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = 12293')->fetchColumn(), true);
    $belle = array_values(array_filter((array) $sra['staff']['notableStaff'], function ($p) { return ($p['name'] ?? '') === 'Belle Shook'; }));
    $check('Spring Ridge: Belle Shook is "Former Therapist"', ($belle[0]['role'] ?? '') === 'Former Therapist', $belle[0]['role'] ?? 'missing');
    $check('a second pass changes nothing', kop_scy_pass($opts) === array('records' => 0, 'roles' => 0, 'jobs_cleared' => 0, 'jobs_hidden' => 0));
    $n = kop_scy_undo($opts);
    $back = array_filter($roles(), function ($k) use ($folded) { return !isset($folded[strtok($k, '|')]); }, ARRAY_FILTER_USE_KEY);
    $want = array_filter($before_roles, function ($k) use ($folded) { return !isset($folded[strtok($k, '|')]); }, ARRAY_FILTER_USE_KEY);
    $check('undo puts every role back (folded records aside)', $back === $want, "$n put back");
    $check('undo puts every job back', $jobs() === $before_jobs);
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
