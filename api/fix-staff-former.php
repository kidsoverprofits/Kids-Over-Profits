<?php
/**
 * Take off the staff entries that are not people (kop_staff_not_people()), then mark "Former" the staff on
 * facility records who have left (inc/staff-former.php): everyone at a
 * closed program, roles ending ", left", and dated sightings ("Admissions (2007, Woodbury Reports)",
 * "Teacher (2017, HEAL)") older than last year. Wiki staff have their own pass (fix-wiki-staff-former.php).
 *
 * CLI only, on the server (ea-php82):
 *   php api/fix-staff-former.php              dry run: what would change
 *   php api/fix-staff-former.php --list       ...one line per person
 *   php api/fix-staff-former.php apply        write it (logged in the option kop_staff_former_log)
 *   php api/fix-staff-former.php undo apply   put the roles back
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$apply = in_array('apply', $argv, true);
$undo = in_array('undo', $argv, true);
$list = in_array('--list', $argv, true);
set_time_limit(0);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_seed_pdo')) {
    fwrite(STDERR, "WordPress did not load\n");
    exit(2);
}
require_once dirname(__DIR__) . '/inc/staff-former.php';
$pdo = kop_seed_pdo();
$prefix = $pdo ? kop_seed_v2_prefix($pdo) : null;
if (!$pdo || $prefix === null) {
    fwrite(STDERR, "No records database connection, or facilities_v2 is not written\n");
    exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$opts = array('pdo' => $pdo, 'prefix' => $prefix);
// Entries that are not people come off first (a phase sheet HEAL's reader took for a staff list); undo puts them back last.
if (!$undo) $gone = kop_staff_not_people_pass($opts, $apply);
$changes = kop_staff_former_pass($opts, $apply, $undo);
if ($undo) $gone = kop_staff_not_people_pass($opts, $apply, true);
foreach ($gone as $g) if ($list) echo "  #{$g['id']} " . ($undo ? 'put back' : 'not a person, taken off') . ": {$g['name']}\n";
echo ($apply ? '' : 'Dry run: ') . count($gone) . ' entries that are not people ' . ($undo ? 'put back' : 'taken off') . "\n";
$why = array();
$records = array();
foreach ($changes as $c) {
    $why[$c['why']] = ($why[$c['why']] ?? 0) + 1;
    $records[$c['id']] = true;
    if ($list) echo "  #{$c['id']} {$c['name']}: {$c['from']} -> {$c['to']} ({$c['why']})\n";
}
echo ($apply ? '' : 'Dry run: ') . count($changes) . ' staff entries on ' . count($records) . ' records'
    . ($undo ? ' put back' : ' rewritten') . ' (' . implode(', ', array_map(function ($k, $v) { return "$v $k"; }, array_keys($why), $why)) . ")\n";
