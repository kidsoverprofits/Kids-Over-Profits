<?php
/**
 * The owner's open answers on KOP Tools > Plan Board (inc/plan-board.php), as JSON, for a Claude session's pass.
 * Read-only: what the session did with each answer goes in seeds/plan-board-passes.json, committed.
 *
 * CLI only, on the server (ea-php82):
 *   php api/plan-board-answers.php            open answers (not withdrawn, not folded in by a pass)
 *   php api/plan-board-answers.php --all      every answer that stands, folded in or not
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
if (!defined('ABSPATH') || !function_exists('kop_pb_split')) {
    fwrite(STDERR, "WordPress or inc/plan-board.php did not load\n");
    exit(2);
}

kop_pb_install();
list($handled) = kop_pb_passes();
$rows = kop_pb_rows();
$items = kop_pb_items(kop_pb_plan());
if (in_array('--all', $argv, true)) {
    $out = array();
    foreach ($rows as $r) {
        $out[] = array('answer' => (int) $r['id'], 'itemId' => $r['item_id'], 'lead' => $r['item_lead'], 'kind' => $r['kind'],
            'text' => (string) $r['body'], 'savedAt' => $r['saved_at'], 'foldedIn' => $handled[(int) $r['id']] ?? null);
    }
} else {
    list($open) = kop_pb_split($rows, $handled);
    $out = kop_pb_open_export($open, $items);
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
