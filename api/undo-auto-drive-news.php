<?php
/**
 * Takes back the Drive Docs news links that auto-connect (inc/auto-connect.php) put on the site by
 * itself: 8,561 SCIAD NET, Google Docs and data audit links sent in approved on 2026-10-14..16, with
 * no summary and often the wrong date (owner, 2026-10-20). Each goes through kop_gdl_undo(), as the
 * Added tab's Undo does: the news row is soft-deleted (status 'deleted', which duplicate checks
 * ignore) and the link goes back to waiting in Review inbox > Drive Docs, where Add now sends news to
 * Submissions Review > News to wait for its summary and an approval.
 *
 * Only rows nobody has handled since: the link still 'applied' by 'auto', its news row still
 * 'approved' with reviewed_by 'auto'. Anything a person has rejected, re-approved or changed is
 * left and listed.
 *
 * CLI only (ea-php82):
 *   php api/undo-auto-drive-news.php [apply] [--limit=N]
 * Without "apply" it reports what it would do.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$args = array_slice($argv, 1);
$apply = in_array('apply', $args, true);
$limit = 0;
foreach ($args as $a) {
    if (strpos($a, '--limit=') === 0) $limit = (int) substr($a, 8);
}

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH')) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(2);
}
@set_time_limit(0);

global $wpdb;
$table = kop_gdl_table();
$links = (array) $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'applied' AND reviewed_by = 'auto'
    AND applied LIKE '%\"target\":\"news\"%' ORDER BY id" . ($limit > 0 ? ' LIMIT ' . $limit : ''), ARRAY_A);

$pdo = kop_gdl_queue_pdo();
$news = $pdo->prepare('SELECT status, reviewed_by FROM news_submissions WHERE id = ?');
$todo = array();
$left = array();
foreach ($links as $r) {
    $done = json_decode((string) $r['applied'], true) ?: array();
    $news->execute(array((int) ($done['id'] ?? 0)));
    $n = $news->fetch(PDO::FETCH_ASSOC);
    $news->closeCursor();
    if ($n && $n['status'] === 'approved' && $n['reviewed_by'] === 'auto') {
        $todo[] = $r;
    } else {
        $left[] = array('link' => (int) $r['id'], 'news' => (int) ($done['id'] ?? 0),
            'why' => $n ? 'news row is ' . $n['status'] . ' by ' . ($n['reviewed_by'] ?: 'nobody') : 'news row gone');
    }
}

$counts = array('to take back' => count($todo), 'left (handled since)' => count($left));
if ($apply) {
    $ok = 0;
    $failed = array();
    foreach (array_chunk($todo, 200) as $i => $batch) {
        foreach (kop_gdl_undo($batch, 'undo of auto news (2026-10-20)') as $pkey => $res) {
            if (!empty($res['ok'])) {
                $ok++;
            } else {
                $failed[] = array('pkey' => $pkey, 'error' => (string) ($res['error'] ?? ''));
            }
        }
        fwrite(STDERR, 'taken back ' . $ok . ' of ' . count($todo) . "\n");
    }
    $counts['taken back'] = $ok;
    $counts['failed'] = count($failed);
    if (function_exists('kop_record_changed')) kop_record_changed('news', array());
} else {
    $failed = array();
}
echo json_encode(array('applied' => $apply, 'counts' => $counts, 'left' => array_slice($left, 0, 50), 'failed' => array_slice($failed, 0, 50)),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
