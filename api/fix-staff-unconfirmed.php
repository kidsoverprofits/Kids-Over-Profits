<?php
/**
 * Take "Former" off staff roles no source confirmed, and "current" off every role (owner, 2026-10-17;
 * inc/staff-former.php). The "Former" that api/fix-staff-former.php and Woodbury Facts put on for a closed
 * program or an old sighting goes when the role as the source gave it (kop_staff_former_log, the Woodbury
 * Facts item) never said the person left; one typed by hand stays. Wiki staff: api/fix-wiki-staff-former.php,
 * whose rule now reads only the write-up's own words.
 *
 * CLI only, on the server (ea-php82):
 *   php api/fix-staff-unconfirmed.php              dry run: what would change
 *   php api/fix-staff-unconfirmed.php --list       ...one line per person
 *   php api/fix-staff-unconfirmed.php apply        write it (logged in the option kop_staff_unconfirmed_log)
 *   php api/fix-staff-unconfirmed.php undo apply   put the roles back
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

$changes = kop_staff_unconfirmed_pass(array('pdo' => $pdo, 'prefix' => $prefix), $apply, $undo);
$why = array();
$records = array();
foreach ($changes as $c) {
    $why[$c['why']] = ($why[$c['why']] ?? 0) + 1;
    $records[$c['id']] = true;
    if ($list) echo "  #{$c['id']} {$c['name']}: {$c['from']} -> {$c['to']} ({$c['why']})\n";
}
echo ($apply ? '' : 'Dry run: ') . count($changes) . ' staff entries on ' . count($records) . ' records'
    . ($undo ? ' put back' : ' rewritten') . ' (' . implode(', ', array_map(function ($k, $v) { return "$v $k"; }, array_keys($why), $why)) . ")\n";
