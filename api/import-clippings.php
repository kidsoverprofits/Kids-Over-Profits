<?php
/**
 * News clippings read by OCR into the site (inc/news-clippings.php).
 *
 * The folder comes from kop-pipeline `py -3 scripts/clippings-ocr.py run` + `upload`
 * (tmp/clippings/upload/ -> ~/kop-import/clippings/: manifest.json, <id>.pdf, <id>.txt); never committed.
 *
 * CLI only (ea-php82):
 *   php api/import-clippings.php <dir> [apply] [--ids=1,2] [--limit=10] [--no-news] [--news-only]
 *   php api/import-clippings.php undo [apply] [--ids=1,2]
 *   php api/import-clippings.php titles [apply] [--ids=1,2]   (file-name titles of clippings applied earlier)
 * Without "apply" it reports what it would do.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$args = array_slice($argv, 1);
$apply = in_array('apply', $args, true);
$opt = function ($name) use ($args) {
    foreach ($args as $a) {
        if (strpos($a, '--' . $name . '=') === 0) return substr($a, strlen($name) + 3);
    }
    return null;
};
$ids = array_values(array_filter(array_map('intval', explode(',', (string) $opt('ids')))));
$undo = ($args[0] ?? '') === 'undo';
$titles = ($args[0] ?? '') === 'titles';
$dir = ($undo || $titles) ? '' : rtrim((string) ($args[0] ?? ''), '/');
if (!$undo && !$titles && ($dir === '' || !is_readable($dir . '/manifest.json'))) {
    fwrite(STDERR, "Usage: php api/import-clippings.php <dir with manifest.json> [apply] [--ids=..] [--limit=N] [--no-news] [--news-only]\n"
        . "       php api/import-clippings.php undo [apply] [--ids=..]\n"
        . "       php api/import-clippings.php titles [apply] [--ids=..]\n");
    exit(2);
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

try {
    if ($undo) {
        $report = kop_clip_undo($apply, $ids);
    } elseif ($titles) {
        $report = kop_clip_retitle_all($apply, $ids);
    } else {
        $report = kop_clip_import($dir, $apply, array(
            'ids' => $ids, 'limit' => (int) $opt('limit'),
            'no_news' => in_array('--no-news', $args, true), 'news_only' => in_array('--news-only', $args, true),
        ));
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
$counts = array();
foreach ($report as $line) {
    $k = ($line['result'] ?? '') . (isset($line['news']) ? ' / article: ' . $line['news'] : '');
    $counts[$k] = ($counts[$k] ?? 0) + 1;
}
echo json_encode(array('applied' => $apply, 'counts' => $counts, 'clippings' => $report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
