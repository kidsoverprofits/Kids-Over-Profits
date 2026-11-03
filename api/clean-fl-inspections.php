<?php
/**
 * Move the Florida DJJ reports the old fl_scraper.py matcher filed under the
 * wrong facility (plan: seeds/fl-inspection-cleanup.json, rules in
 * api/lib-fl-inspection-cleanup.php). Before writing, the facilities, the moved
 * rows' places, their findings and counts are saved to
 * ~/kop-backups/fl-inspection-cleanup-<time>.json.
 *
 * CLI (/opt/cpanel/ea-php82/root/usr/bin/php, from the theme directory):
 *   php api/clean-fl-inspections.php          dry run: what would change
 *   php api/clean-fl-inspections.php apply
 * Browser, as an administrator: dry run; ?apply=1 to write.
 */

$kop_flc_cli = php_sapi_name() === 'cli';

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib-fl-inspection-cleanup.php';

if ($kop_flc_cli) {
    while (ob_get_level() > 0) ob_end_clean();
    $apply = in_array('apply', $argv, true);
} else {
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not authorized. Log in to WordPress as an administrator first.';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    $apply = !empty($_GET['apply']);
}

if (!$pdo) {
    echo "No database connection.\n";
    exit(1);
}

$plan = json_decode((string) file_get_contents(dirname(__DIR__) . '/seeds/fl-inspection-cleanup.json'), true);
if (!is_array($plan)) {
    echo "Plan file missing or unreadable.\n";
    exit(1);
}

if ($apply) {
    $home = getenv('HOME') ?: dirname(rtrim(ABSPATH, '/'));
    $dir = rtrim($home, '/') . '/kop-backups';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/fl-inspection-cleanup-' . gmdate('Ymd-His') . '.json';
    if (!file_put_contents($file, json_encode(kop_flclean_backup($pdo, $plan), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))) {
        echo "Could not write the backup to $file; nothing changed.\n";
        exit(1);
    }
    echo "Backup: $file\n";
}

$result = kop_flclean_run($pdo, $plan, $apply);
echo ($apply ? 'Applied' : 'Dry run') . ":\n";
foreach ($result['stats'] as $k => $v) echo "  $k: $v\n";
foreach ($result['skipped'] as $s) echo "  skipped: $s\n";

if (function_exists('get_option') && !function_exists('kop_inspection_links_get')) {
    $links_file = dirname(__DIR__) . '/inc/inspection-links.php';
    if (file_exists($links_file)) require_once $links_file;
}
if (function_exists('kop_inspection_links_get')) {
    list($stored, $changed) = kop_ncclean_remap_links(kop_inspection_links_get(), $result['removed'], $result['merged']);
    echo "  inspection links pointing at changed rows: $changed\n";
    if ($apply && $changed) {
        $stored['updated'] = time();
        update_option(KOP_INSPECTION_LINKS_OPTION, $stored, false);
    }
}
