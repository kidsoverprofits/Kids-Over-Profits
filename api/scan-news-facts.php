<?php
/**
 * Read approved news articles for facts from the server shell, the same read
 * the hourly WP-Cron job makes (inc/news-facts.php). Facts about a linked
 * program land on the Woodbury Facts cards (From: News articles), the rest at
 * Review inbox > Facts from news; nothing reaches a record until someone adds
 * it (or, once switched on, the automatic pass adds the plain ones).
 *
 * CLI only:
 *   php api/scan-news-facts.php                       dry run: the 5 newest unread articles, printed
 *   php api/scan-news-facts.php --ids=10553,10555     dry run on those articles
 *   php api/scan-news-facts.php apply --limit=25 --minutes=20   store what it finds
 *
 * A dry run calls the AI (it counts against the day's budget) but stores nothing.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$opts = array('limit' => 5, 'minutes' => 10, 'ids' => '');
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(limit|minutes|ids)=(.*)$/', $arg, $m)) {
        $opts[$m[1]] = $m[1] === 'ids' ? trim($m[2]) : max(1, (int) $m[2]);
    }
}
$apply = in_array('apply', $argv, true);
set_time_limit(0);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_nf_read_batch')) {
    fwrite(STDERR, "WordPress or inc/news-facts.php did not load\n");
    exit(2);
}
$pdo = kop_nf_pdo();
if (!$pdo) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}

$ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $opts['ids'])));
$cats = kop_nf_categories();
$log = static function ($news, $outcome, $facts, $detail) use ($cats) {
    echo '#' . $news['id'] . ' ' . $news['publication_date'] . ' [' . $outcome . '] ' . mb_substr($news['article_title'], 0, 90)
        . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n";
    foreach ($facts as $f) {
        echo '    ' . str_pad($f['route'] === 'facility' ? '-> #' . $f['facility_id'] : 'waiting', 9) . ' '
            . $cats[$f['category']][0] . ($f['standing'] !== '' ? ' (' . $f['standing'] . ')' : '') . ($f['date'] !== '' ? ' ' . $f['date'] : '')
            . ': ' . $f['summary'] . ($f['quote_found'] ? '' : '  [quote not found]') . ($f['names_removed'] ? '  [name removed]' : '') . "\n";
        if ($f['quote'] !== '') {
            echo '        "' . mb_substr($f['quote'], 0, 220) . "\"\n";
        }
    }
};
$c = kop_nf_read_batch($pdo, $ids ? count($ids) : $opts['limit'], $opts['minutes'] * 60, $apply, $ids, $log);
echo "\n" . json_encode($c) . ($apply ? '' : "\nDry run: nothing stored. Add \"apply\" to store.") . "\n";
