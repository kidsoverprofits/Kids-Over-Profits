<?php
/**
 * Mark "Former" the wiki staff that approvals put on facility records as if they still worked
 * there: everyone at a closed program, and everyone whose write-up opens in the past tense
 * ("was the Program Director"). kop_wiki_staff_former_pass() in api/sync-wiki-facilities.php;
 * new approvals do this themselves (kop_wiki_staff_is_former()).
 *
 * CLI only, on the server (ea-php82):
 *   php api/fix-wiki-staff-former.php              dry run: what would change
 *   php api/fix-wiki-staff-former.php --list       ...one line per person
 *   php api/fix-wiki-staff-former.php apply        write it
 *   php api/fix-wiki-staff-former.php undo apply   put the roles back
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
require_once __DIR__ . '/sync-wiki-facilities.php';
$pdo = kop_seed_pdo();
if (!$pdo) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$changes = kop_wiki_staff_former_pass($pdo, $apply, $undo);
$why = array();
$records = array();
foreach ($changes as $c) {
    $why[$c['why']] = ($why[$c['why']] ?? 0) + 1;
    $records[$c['record']] = true;
    if ($list) echo "  #{$c['submission']} {$c['record']}: {$c['name']}: {$c['from']} -> {$c['to']} ({$c['why']})\n";
}
echo ($apply ? '' : 'Dry run: ') . count($changes) . ' staff entries on ' . count($records) . ' records'
    . ($undo ? ' put back' : ' marked Former') . ' (' . implode(', ', array_map(function ($k, $v) { return "$v $k"; }, array_keys($why), $why)) . ")\n";
