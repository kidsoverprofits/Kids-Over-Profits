<?php
/**
 * Woodbury Facts items that would add nothing to their record, filed as
 * "already on the record" (kop_wbf_clear_on_record(), inc/woodbury-facts.php):
 * the same fact word for word or in other words ("Ages: boys 12-17" on a
 * record aged 12-17 for boys, "Founded by: John Santa" with him on staff as a
 * founder, a past job whose employer and title the person's entry names).
 * Conflicts keep waiting. The hourly job does the same; this runs it now.
 *
 * CLI only, on the server (ea-php82):
 *   php api/clear-woodbury-on-record.php           dry run: counts
 *   php api/clear-woodbury-on-record.php --list    ...and every item with its reason
 *   php api/clear-woodbury-on-record.php apply     file them (Rejected tab, "Back to review" undoes one)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$apply = in_array('apply', $argv, true);
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
if (!defined('ABSPATH') || !function_exists('kop_wbf_clear_on_record')) {
    fwrite(STDERR, "WordPress did not load\n");
    exit(2);
}
if (!function_exists('kop_wbf_on_record')) {
    fwrite(STDERR, "The review inbox's Woodbury Facts source did not load\n");
    exit(2);
}

kop_wbf_ensure_table();
$res = kop_wbf_clear_on_record($apply, 3600);
if ($list) {
    foreach ($res['examples'] as $line) echo $line, "\n";
}
printf("%s %d of %d waiting items: %s%s\n", $apply ? 'Filed as already on the record:' : 'Would file as already on the record:',
    $res['cleared'], $res['checked'], json_encode($res['by_kind']), $res['done'] ? '' : ' (stopped early, run again)');
