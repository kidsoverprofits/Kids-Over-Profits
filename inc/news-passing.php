<?php
/**
 * Articles that name a program only in passing (owner, 2026-11-17, plan 4.3 item 31): the link is kept, flagged
 * news_facility_links.passing = 1, and the facility and company pages list those articles apart, folded, after the
 * rest; the timeline leaves them out. The mark lives on the article's stored mention ({"name", "facility_id",
 * "passing": true} in facilities_mentioned) and the link sync copies it to the link row
 * (kop_sync_news_facility_links(), api/news-mentions.php). This adds the column once.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_NEWS_PASSING_SCHEMA', '1');

add_action('init', 'kop_news_passing_maybe_migrate', 31);

function kop_news_passing_maybe_migrate() {
    if (get_option('kop_news_passing_schema') === KOP_NEWS_PASSING_SCHEMA) return;
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) return;
    require_once get_stylesheet_directory() . '/api/news-mentions.php';
    try {
        if (kop_news_links_ensure_passing($pdo)) update_option('kop_news_passing_schema', KOP_NEWS_PASSING_SCHEMA, true);
    } catch (Throwable $e) {
        update_option('kop_news_passing_schema_error', $e->getMessage(), false);
    }
}

if (!function_exists('kop_news_passing_label')) {
    /** The folded group's line: "3 articles that mention it in passing". */
    function kop_news_passing_label($n) {
        return $n . ' ' . ($n === 1 ? 'article that mentions' : 'articles that mention') . ' it in passing';
    }
}
