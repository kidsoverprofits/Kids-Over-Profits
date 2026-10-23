<?php
/**
 * The research tasks on KOP Tools > Research Tasks (inc/research-tasks.php), as JSON, for a Claude session.
 * Read-only: a session sends answers, new tasks and settled lines through seeds/research-tasks.json
 * (scripts/research-tasks/tasks.py), committed.
 *
 * CLI only, on the server (ea-php82):
 *   php api/research-tasks.php             open and answered tasks
 *   php api/research-tasks.php --claude    the open ones given to Claude (what "work the research tasks" means)
 *   php api/research-tasks.php --all       every task, settled and dropped too
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_rt_list')) {
    fwrite(STDERR, "WordPress or inc/research-tasks.php did not load\n");
    exit(2);
}

kop_rt_install();
kop_rt_import_seed();
kop_rt_sync_volunteer_answers();
if (in_array('--claude', $argv, true)) {
    $rows = array_values(array_filter(kop_rt_list('claude'), function ($t) { return $t['status'] === 'open'; }));
} else {
    $rows = kop_rt_list(in_array('--all', $argv, true) ? 'all' : 'open');
}
echo json_encode(kop_rt_export($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
