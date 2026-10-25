<?php
/**
 * Top story: the one news article shown first on the news feed, above
 * everything else. Picking a new one replaces the old one.
 *
 *   store     the kop_news_top_story option: the picks, newest first
 *             ({id, at, by}, at most 20). The story shown is the newest pick
 *             whose article is approved or published, so a pick sent in with
 *             a new article waits for its approval while the last one stays
 *             up, and taking a pick off brings the one before it back
 *   picking   the "Top story" box in the News Processor's submit popup
 *             (admins; api/save-news-submission.php), or the pencil on any
 *             article's headline ('feat:<id>', inc/featured-reporting.php)
 *   readers   the top of the news feed's first page (templates/page-news-feed.php).
 *             Read at render time; a change empties the page caches
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_top_story_picks')) {
    /** Every pick, newest first: [{id, at, by}]. */
    function kop_top_story_picks() {
        $picks = function_exists('get_option') ? get_option('kop_news_top_story', array()) : array();
        return is_array($picks) ? array_values(array_filter($picks, static function ($p) { return is_array($p) && (int) ($p['id'] ?? 0) > 0; })) : array();
    }
}

if (!function_exists('kop_top_story_save')) {
    function kop_top_story_save(array $picks) {
        if (function_exists('update_option')) update_option('kop_news_top_story', array_slice(array_values($picks), 0, 20), false);
        kop_top_story_id(true);
        if (function_exists('do_action')) do_action('litespeed_purge_all');
    }
}

if (!function_exists('kop_top_story_set')) {
    /** Makes the article the top story (it shows once approved). False when it already was the newest pick. */
    function kop_top_story_set($news_id, $who = '') {
        $news_id = (int) $news_id;
        if ($news_id <= 0) return false;
        $picks = kop_top_story_picks();
        if ($picks && (int) $picks[0]['id'] === $news_id) return false;
        $picks = array_filter($picks, static function ($p) use ($news_id) { return (int) $p['id'] !== $news_id; });
        array_unshift($picks, array('id' => $news_id, 'at' => gmdate('Y-m-d H:i:s'), 'by' => (string) $who));
        kop_top_story_save($picks);
        return true;
    }
}

if (!function_exists('kop_top_story_unset')) {
    /** Takes the article off; the pick before it shows again. False when it was not picked. */
    function kop_top_story_unset($news_id) {
        $news_id = (int) $news_id;
        $picks = kop_top_story_picks();
        $left = array_filter($picks, static function ($p) use ($news_id) { return (int) $p['id'] !== $news_id; });
        if (count($left) === count($picks)) return false;
        kop_top_story_save($left);
        return true;
    }
}

if (!function_exists('kop_top_story_is_picked')) {
    function kop_top_story_is_picked($news_id) {
        foreach (kop_top_story_picks() as $p) {
            if ((int) $p['id'] === (int) $news_id) return true;
        }
        return false;
    }
}

if (!function_exists('kop_top_story_id')) {
    /** The article shown now: the newest pick that is approved or published; 0 for none. */
    function kop_top_story_id($reset = false) {
        static $memo = null;
        if ($reset) { $memo = null; return 0; }
        if ($memo !== null) return $memo;
        $memo = 0;
        $picks = kop_top_story_picks();
        $pdo = function_exists('kop_feat_pdo') ? kop_feat_pdo() : null;
        if (!$picks || !$pdo) return $memo;
        $ids = array_map(static function ($p) { return (int) $p['id']; }, $picks);
        $exclude = function_exists('kop_ischools_news_exclude_sql') ? kop_ischools_news_exclude_sql('id') : '';
        try {
            $live = $pdo->query('SELECT id FROM news_submissions WHERE id IN (' . implode(',', $ids) . ") AND status IN ('approved','published')$exclude")
                ->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            return $memo;
        }
        $live = array_flip(array_map('intval', $live));
        foreach ($ids as $id) {
            if (isset($live[$id])) return $memo = $id;
        }
        return $memo;
    }
}

if (!function_exists('kop_top_story_item')) {
    /** The top story ready to print (the fields of kop_feat_items()), or null. */
    function kop_top_story_item() {
        $id = kop_top_story_id();
        $pdo = $id ? kop_feat_pdo() : null;
        if (!$pdo) return null;
        $theme = function_exists('get_stylesheet_directory') ? get_stylesheet_directory() : dirname(__DIR__);
        require_once $theme . '/api/lib-news-archive.php';
        kop_news_archive_ensure($pdo);
        $st = $pdo->prepare('SELECT id, article_title, alternate_title, author, publication_name, publication_date,
                                    article_url, archive_url, article_type, summary
                               FROM news_submissions WHERE id = ?');
        $st->execute(array($id));
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;

        $facilities = array();
        try {
            $st = $pdo->prepare('SELECT f.id, f.name FROM news_facility_links l JOIN facilities_v2 f ON f.id = l.facility_id WHERE l.news_id = ? ORDER BY f.name');
            $st->execute(array($id));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
                $url = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $l['id']) : '';
                if ($url !== '') $facilities[$url] = array('name' => trim((string) $l['name']), 'url' => $url);
            }
        } catch (Throwable $e) {
            // no link table: the card names no programs
        }

        $links = kop_news_links((string) $r['article_url'], (string) ($r['archive_url'] ?? ''));
        $feat = function_exists('kop_feat_get') ? kop_feat_get($id) : null;
        return array(
            'id'         => $id,
            'title'      => trim((string) ($r['alternate_title'] ?: $r['article_title'])),
            'url'        => $links['original'] !== '' ? $links['original'] : ($links['archive'] !== '' ? $links['archive'] : (string) $r['article_url']),
            'archive'    => $links['original'] !== '' ? $links['archive'] : '',
            'outlet'     => trim((string) $r['publication_name']),
            'byline'     => kop_feat_byline($r['author'], $r['publication_name']),
            'date'       => (string) $r['publication_date'],
            'date_label' => kop_feat_date_label((string) $r['publication_date']),
            'type'       => trim((string) $r['article_type']),
            'summary'    => trim((string) $r['summary']),
            'note'       => $feat ? $feat['note'] : '',
            'image'      => function_exists('kop_news_image') ? kop_news_image($id, (string) $r['article_url']) : null,
            'facilities' => array_values($facilities),
        );
    }
}

if (!function_exists('kop_top_story_html')) {
    /** The top story as a wide card, '' when there is none. */
    function kop_top_story_html() {
        $it = kop_top_story_item();
        if (!$it) return '';
        if (function_exists('kop_feat_enqueue_style')) kop_feat_enqueue_style();
        $has_url = $it['url'] !== '' && preg_match('#^https?://#i', $it['url']);
        $img = $it['image'];
        $kind = is_array($img) ? (string) $img['kind'] : 'none';
        $outlet = $it['outlet'] !== '' ? $it['outlet'] : ($has_url ? (string) preg_replace('/^www\./', '', (string) parse_url($it['url'], PHP_URL_HOST)) : '');
        $label = function_exists('kop_text') ? kop_text('news:top-story-label', 'Top story', 'News: top story label') : 'Top story';

        $html = '<section class="kop-top-story" aria-labelledby="kop-top-story-title" data-kop-bug-feature="news-feed/top-story" data-kop-bug-label="Top story">';
        if (is_array($img)) {
            $pic = '<img src="' . esc_url($img['src']) . '" alt="" decoding="async"' . ($kind === 'photo' ? ' width="960" height="540"' : ' width="200" height="200"') . '>';
            $html .= $has_url
                ? '<a class="kop-top-story__thumb kop-top-story__thumb--' . esc_attr($kind) . '" href="' . esc_url($it['url']) . '" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true">' . $pic . '</a>'
                : '<span class="kop-top-story__thumb kop-top-story__thumb--' . esc_attr($kind) . '" aria-hidden="true">' . $pic . '</span>';
        }
        $html .= '<div class="kop-top-story__body">';
        $html .= '<p class="kop-top-story__label"' . (function_exists('kop_text_attr') ? kop_text_attr('news:top-story-label', 'News: top story label') : '') . '>' . esc_html($label) . '</p>';
        $meta = array();
        if ($outlet !== '') $meta[] = '<span class="kop-top-story__outlet">' . esc_html($outlet) . '</span>';
        if ($it['date_label'] !== '') $meta[] = '<span>' . esc_html($it['date_label']) . '</span>';
        if ($meta) $html .= '<p class="kop-top-story__meta">' . implode('', $meta) . '</p>';
        $html .= '<h2 id="kop-top-story-title" class="kop-top-story__title"' . (function_exists('kop_feat_attr') ? kop_feat_attr($it['id']) : '') . '>';
        if ($has_url) {
            $html .= '<a href="' . esc_url($it['url']) . '" target="_blank" rel="noopener">' . esc_html($it['title']) . '</a>';
            if ($it['archive'] !== '') $html .= ' <a class="kop-archived-link" href="' . esc_url($it['archive']) . '" target="_blank" rel="noopener noreferrer">archived copy</a>';
        } else {
            $html .= esc_html($it['title']);
        }
        $html .= '</h2>';
        if ($it['byline'] !== '') $html .= '<p class="kop-top-story__byline">By ' . esc_html($it['byline']) . '</p>';
        if ($it['note'] !== '' && function_exists('kop_feat_note_html')) {
            $html .= '<div class="kop-feat-note">' . kop_feat_note_html($it['note']) . '</div>';
        } elseif ($it['summary'] !== '') {
            $html .= '<p class="kop-top-story__summary">' . esc_html($it['summary']) . '</p>';
        }
        if ($it['facilities']) {
            $names = array();
            foreach ($it['facilities'] as $f) $names[] = '<a href="' . esc_url($f['url']) . '">' . esc_html($f['name']) . '</a>';
            $html .= '<p class="kop-top-story__about">About ' . implode(', ', $names) . '</p>';
        }
        return $html . '</div></section>';
    }
}
