<?php
/**
 * Category archives (category.php): a solid panel with a search box scoped to
 * the category, links to the matching hub and the main hubs, the posts as
 * cards and the theme's pagination.
 *
 * The scoped search is ?cs=words on the category's own address, not ?s=: a
 * query with s set becomes a search and loads search.php, which searches the
 * whole site. kop_category_archive_pre_get_posts() turns cs into a title and
 * content match on the main category query, so paging and the post count stay
 * the archive's own.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_category_archive_term')) {
    /** The searched words for this request, trimmed, '' when none. */
    function kop_category_archive_term() {
        if (!isset($_GET['cs'])) {
            return '';
        }
        $raw = is_array($_GET['cs']) ? '' : (string) wp_unslash($_GET['cs']);
        return trim(mb_substr(wp_strip_all_tags($raw), 0, 100));
    }
}

if (!function_exists('kop_category_archive_pre_get_posts')) {
    function kop_category_archive_pre_get_posts($query) {
        if (is_admin() || !$query->is_main_query() || !$query->is_category()) {
            return;
        }
        $term = kop_category_archive_term();
        if ($term !== '') {
            $query->set('kop_category_search', $term);
        }
    }
    add_action('pre_get_posts', 'kop_category_archive_pre_get_posts');
}

if (!function_exists('kop_category_archive_posts_where')) {
    function kop_category_archive_posts_where($where, $query) {
        $term = $query->get('kop_category_search');
        if (!is_string($term) || $term === '') {
            return $where;
        }
        global $wpdb;
        $like = '%' . $wpdb->esc_like($term) . '%';
        return $where . $wpdb->prepare(
            " AND ({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s)",
            $like, $like, $like
        );
    }
    add_filter('posts_where', 'kop_category_archive_posts_where', 10, 2);
}

if (!function_exists('kop_category_archive_enqueue')) {
    function kop_category_archive_enqueue() {
        if (!is_category()) {
            return;
        }
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        $css = $dir . '/css/category-archive.css';
        if (file_exists($css)) {
            wp_enqueue_style('kop-category-archive', $uri . '/css/category-archive.css', array('kop-colors'), filemtime($css));
        }
    }
    add_action('wp_enqueue_scripts', 'kop_category_archive_enqueue', 20);
}

if (!function_exists('kop_category_archive_body_class')) {
    function kop_category_archive_body_class($classes) {
        if (is_category()) {
            $classes[] = 'kop-category-archive-page';
        }
        return $classes;
    }
    add_filter('body_class', 'kop_category_archive_body_class');
}

if (!function_exists('kop_category_archive_hubs')) {
    /**
     * Hub links for a category: its own hub first (a page with the same slug,
     * or the hub that covers its topic), then the main hubs. Each is
     * array(title, url, note); a hub that is not published is left out.
     */
    function kop_category_archive_hubs($category) {
        $topic = array(
            'lawsuits'                => 'law-policy',
            'legislation'             => 'law-policy',
            'editorials'              => 'editorials',
            'investigatory-spotlight' => 'investigatory-spotlight',
            'independent-journalism'  => 'journalists',
            'where-are-the-kids'      => 'where-are-the-kids',
            'facility-profile'        => 'where-are-the-kids',
            'news'                    => 'researchreports',
            'local-news'              => 'researchreports',
            'us-news'                 => 'researchreports',
            'international-news'      => 'researchreports',
        );
        $notes = array(
            'law-policy'              => 'Lawsuits and bills, tracked as they move.',
            'editorials'              => 'Opinion and analysis from Kids Over Profits.',
            'investigatory-spotlight' => 'Long reads on single programs and people.',
            'journalists'             => 'Sources and background for reporters.',
            'where-are-the-kids'      => 'Where the programs are, by location.',
            'researchreports'         => 'Reports, news and research in one place.',
            'history'                 => 'How the industry grew, and who fought it.',
            'survivors'               => 'Support and records for survivors.',
            'families'                => 'For family and friends of survivors.',
            'resources'               => 'Guides, directories and tools.',
        );
        $slugs = array();
        $slug = is_object($category) ? (string) $category->slug : '';
        if ($slug !== '' && isset($notes[$slug])) {
            $slugs[] = $slug;
        }
        if ($slug !== '' && isset($topic[$slug])) {
            $slugs[] = $topic[$slug];
        } elseif (is_object($category) && $category->parent) {
            $parent = get_term($category->parent, 'category');
            if ($parent && !is_wp_error($parent) && isset($topic[$parent->slug])) {
                $slugs[] = $topic[$parent->slug];
            }
        } elseif ($slug !== '' && function_exists('kop_state_slug_to_name') && kop_state_slug_to_name($slug)) {
            $slugs[] = 'where-are-the-kids';
        }
        $slugs = array_merge($slugs, array('researchreports', 'law-policy', 'history', 'survivors', 'families', 'resources'));
        $out = array();
        foreach (array_unique($slugs) as $s) {
            $page = function_exists('kop_article_page') ? kop_article_page($s) : null;
            if (!$page || !isset($notes[$s])) {
                continue;
            }
            $out[] = array('title' => wp_specialchars_decode($page['title']), 'url' => $page['url'], 'note' => $notes[$s]);
        }
        return $out;
    }
}
