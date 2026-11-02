<?php
/**
 * Proper citations for the Sources list (js/citation-previews.js).
 *
 * POST /wp-json/kop/v1/citations with {"urls": [...]} (up to 150) answers
 * {"citations": {"<url as sent>": {kind, author, date, title, title_style, publisher, detail, note}}}
 * for every address KOP holds a record for; the page formats it the way
 * Wikipedia's reference list does. An address with no record is left out and
 * the page keeps the citation text it already had. Read-only, public: only
 * approved news, published lawsuits, published sources and media library
 * copies are answered.
 *
 *   - our copy of a Woodbury Reports issue (woodbury-MMYY.pdf#page=N): the issue and page
 *   - another file in the media library: its title, and where the original was
 *   - an approved article (news_submissions, its link or its archived copy): author, date, title, publication
 *   - a court document of a published lawsuit (lawsuits.document_urls): case name, number, court, date filed
 *   - a published source sent in (kop_source posts): its title, author and site
 *
 * Tested by scripts/test-citation-lookup.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/api/url-dedupe.php';

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/citations', array(
        'methods'             => 'POST',
        'callback'            => 'kop_citation_lookup_rest',
        'permission_callback' => '__return_true',
    ));
});

if (!function_exists('kop_citation_lookup_rest')) {
    function kop_citation_lookup_rest(WP_REST_Request $request) {
        $urls = $request->get_param('urls');
        if (!is_array($urls)) $urls = array();
        $urls = array_slice(array_values(array_unique(array_filter(array_map('strval', $urls), static function ($u) {
            return preg_match('#^https?://#i', $u) && strlen($u) <= 2000;
        }))), 0, 150);
        return new WP_REST_Response(array('citations' => (object) kop_citation_lookup($urls)), 200);
    }
}

if (!function_exists('kop_citation_date')) {
    /** "2024-09-20" -> "September 20, 2024"; "2009-02" -> "February 2009"; anything else as given. */
    function kop_citation_date($date) {
        $date = trim((string) $date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) && (int) $m[2] >= 1 && (int) $m[3] >= 1) {
            return date('F', mktime(0, 0, 0, (int) $m[2], 1, 2000)) . ' ' . (int) $m[3] . ', ' . $m[1];
        }
        if (preg_match('/^(\d{4})-(\d{2})$/', $date, $m) && (int) $m[2] >= 1) {
            return date('F', mktime(0, 0, 0, (int) $m[2], 1, 2000)) . ' ' . $m[1];
        }
        return preg_match('/^0000/', $date) ? '' : $date;
    }
}

if (!function_exists('kop_citation_host')) {
    function kop_citation_host($url) {
        return preg_replace('/^www\./i', '', (string) parse_url((string) $url, PHP_URL_HOST));
    }
}

if (!function_exists('kop_citation_woodbury')) {
    /** Our copy of a Woodbury Reports issue, from its file name: woodbury-0209.pdf#page=20 -> February 2009, p. 20. */
    function kop_citation_woodbury($url) {
        if (!preg_match('#/wp-content/uploads/.*?woodbury-(\d{2})(\d{2})\.pdf(?:\#page=(\d+))?$#i', (string) $url, $m)) return null;
        $month = (int) $m[1];
        if ($month < 1 || $month > 12) return null;
        $year = ((int) $m[2] >= 80 ? 1900 : 2000) + (int) $m[2];
        return array(
            'kind'        => 'woodbury',
            'title'       => 'Woodbury Reports',
            'title_style' => 'italic',
            'date'        => date('F', mktime(0, 0, 0, $month, 1, 2000)) . ' ' . $year,
            'detail'      => !empty($m[3]) ? 'p. ' . (int) $m[3] : '',
            'note'        => 'Our copy',
        );
    }
}

if (!function_exists('kop_citation_upload_path')) {
    /**
     * "2024/12/x.pdf" for a file in this site's uploads folder, else ''. Our own addresses are stored
     * several ways (kidsoverprofits.org, www., the old /staging/ install), so the host is this site
     * or kidsoverprofits.org and the path is whatever follows /wp-content/uploads/.
     */
    function kop_citation_upload_path($url) {
        $host = strtolower(kop_citation_host($url));
        $home = strtolower(kop_citation_host(function_exists('home_url') ? home_url('/') : ''));
        if ($host === '' || ($host !== $home && $host !== 'kidsoverprofits.org')) return '';
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        return preg_match('#/wp-content/uploads/(.+)$#', $path, $m) ? rawurldecode($m[1]) : '';
    }
}

if (!function_exists('kop_citation_key')) {
    /** The key an address is matched on: "upload:<path>" for our own files, else kop_normalize_url(). */
    function kop_citation_key($url) {
        $p = kop_citation_upload_path($url);
        if ($p !== '') return 'upload:' . $p;
        $n = kop_normalize_url((string) $url);
        return $n === null ? '' : $n;
    }
}

if (!function_exists('kop_citation_id_map')) {
    /**
     * normalized address => row id, for a table whose rows carry addresses, built once and kept
     * an hour ($name names the transient). $rows_fn returns [[id, [addresses...]], ...].
     */
    function kop_citation_id_map($name, callable $rows_fn) {
        $map = function_exists('get_transient') ? get_transient($name) : false;
        if (is_array($map)) return $map;
        $map = array();
        foreach ($rows_fn() as $row) {
            foreach ((array) $row[1] as $u) {
                $n = kop_citation_key($u);
                if ($n !== '' && !isset($map[$n])) $map[$n] = (int) $row[0];
            }
        }
        if (function_exists('set_transient')) set_transient($name, $map, HOUR_IN_SECONDS);
        return $map;
    }
}

if (!function_exists('kop_citation_lookup')) {
    /** @return array<string, array> the citation for each address KOP holds a record for, keyed by the address as given. */
    function kop_citation_lookup(array $urls) {
        global $wpdb;
        $out = array();
        $left = array();
        foreach ($urls as $url) {
            $w = kop_citation_woodbury($url);
            if ($w) {
                $out[$url] = $w;
            } else {
                $left[] = $url;
            }
        }
        if (!$left || !isset($wpdb)) return $out;

        // Files in the media library: the title, and where the original came from.
        $paths = array();
        foreach ($left as $url) {
            $p = kop_citation_upload_path($url);
            if ($p !== '') $paths[$p][] = $url;
        }
        if ($paths) {
            $in = implode(',', array_fill(0, count($paths), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT m.meta_value AS file, p.post_title, p.post_date,
                        (SELECT s.meta_value FROM {$wpdb->postmeta} s WHERE s.post_id = p.ID AND s.meta_key = '_kop_source_url' LIMIT 1) AS original
                 FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
                 WHERE m.meta_key = '_wp_attached_file' AND p.post_type = 'attachment' AND m.meta_value IN ($in)",
                array_keys($paths)
            ), ARRAY_A);
            foreach ((array) $rows as $r) {
                $title = trim(html_entity_decode((string) $r['post_title'], ENT_QUOTES, 'UTF-8'));
                $original = (string) ($r['original'] ?? '');
                // An archive capture: say whose page it was, not archive.org.
                if (preg_match('#^https?://web\.archive\.org/web/\d+[a-z_]*/(.+)$#i', $original, $am)) $original = $am[1];
                $from = $original !== '' ? kop_citation_host(preg_match('#^https?://#i', $original) ? $original : 'http://' . $original) : '';
                foreach ($paths[$r['file']] ?? array() as $url) {
                    if ($title === '') continue;
                    $out[$url] = array(
                        'kind'        => 'document',
                        'title'       => $title,
                        'title_style' => 'quote',
                        'note'        => 'Our copy' . ($from !== '' && strcasecmp($from, kop_citation_host($url)) !== 0 ? ' of the original at ' . $from : ''),
                    );
                }
            }
        }
        // Every address but the Woodbury issues: a court filing is a media library file too, and gets its case below.
        $norm = array();
        foreach ($left as $url) {
            $n = kop_citation_key($url);
            if ($n !== '') $norm[$n][] = $url;
        }

        // Approved news articles, by their own link or their archived copy.
        $news = kop_citation_id_map('kop_cite_news_urls', static function () use ($wpdb) {
            $rows = $wpdb->get_results("SELECT id, article_url, archive_url FROM news_submissions WHERE status IN ('approved','published')", ARRAY_A);
            return array_map(static function ($r) { return array($r['id'], array($r['article_url'], $r['archive_url'])); }, (array) $rows);
        });
        $ids = array();
        foreach ($norm as $n => $given) if (!isset($out[$given[0]]) && isset($news[$n])) $ids[$news[$n]] = $given;
        if ($ids) {
            $in = implode(',', array_map('intval', array_keys($ids)));
            foreach ((array) $wpdb->get_results("SELECT id, article_title, alternate_title, author, publication_name, publication_date FROM news_submissions WHERE id IN ($in)", ARRAY_A) as $r) {
                $title = trim((string) ($r['alternate_title'] ?: $r['article_title']));
                if ($title === '') continue;
                foreach ($ids[(int) $r['id']] as $url) {
                    $out[$url] = array(
                        'kind'        => 'news',
                        'author'      => trim((string) $r['author']),
                        'date'        => kop_citation_date($r['publication_date']),
                        'title'       => $title,
                        'title_style' => 'quote',
                        'publisher'   => trim(preg_replace('/^Source:\s*/i', '', (string) $r['publication_name'])),
                    );
                }
            }
        }

        // Court documents of published lawsuits.
        $suits = kop_citation_id_map('kop_cite_lawsuit_urls', static function () use ($wpdb) {
            $rows = $wpdb->get_results("SELECT id, document_urls FROM lawsuits WHERE publication_status = 'published'", ARRAY_A);
            return array_map(static function ($r) {
                $list = json_decode((string) $r['document_urls'], true);
                return array($r['id'], is_array($list) ? array_map(static function ($d) { return is_array($d) ? ($d['url'] ?? '') : $d; }, $list) : array());
            }, (array) $rows);
        });
        $ids = array();
        foreach ($norm as $n => $given) {
            if (isset($suits[$n]) && (!isset($out[$given[0]]) || $out[$given[0]]['kind'] === 'document')) $ids[$suits[$n]] = $given;
        }
        if ($ids) {
            $in = implode(',', array_map('intval', array_keys($ids)));
            foreach ((array) $wpdb->get_results("SELECT id, case_name, case_number, court, filing_date FROM lawsuits WHERE id IN ($in)", ARRAY_A) as $r) {
                if (trim((string) $r['case_name']) === '') continue;
                $court = trim((string) $r['court']);
                $filed = kop_citation_date($r['filing_date']);
                $case = trim((string) $r['case_name']);
                $about = trim(($r['case_number'] !== '' && $r['case_number'] !== null ? 'No. ' . $r['case_number'] : '')
                    . ($court !== '' || $filed !== '' ? ' (' . trim($court . ($court !== '' && $filed !== '' ? ', ' : '') . ($filed !== '' ? 'filed ' . $filed : '')) . ')' : ''));
                foreach ($ids[(int) $r['id']] as $url) {
                    if (isset($out[$url])) {
                        // Our copy of a filing: its own title, then the case it was filed in.
                        $out[$url]['kind'] = 'case-document';
                        $out[$url]['detail'] = 'In ' . $case . ($about !== '' ? ', ' . $about : '');
                        continue;
                    }
                    $out[$url] = array(
                        'kind'        => 'case',
                        'title'       => $case,
                        'title_style' => 'italic',
                        'detail'      => $about,
                    );
                }
            }
        }

        // Published sources sent in (Websites sent in), matched on their stored normalized address.
        $want = array_keys(array_filter($norm, static function ($given) use ($out) { return !isset($out[$given[0]]); }));
        if ($want) {
            $in = implode(',', array_fill(0, count($want), '%s'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT n.meta_value AS norm, p.post_title, p.post_date,
                        (SELECT a.meta_value FROM {$wpdb->postmeta} a WHERE a.post_id = p.ID AND a.meta_key = '_kop_author' LIMIT 1) AS author
                 FROM {$wpdb->postmeta} n JOIN {$wpdb->posts} p ON p.ID = n.post_id
                 WHERE n.meta_key = '_kop_url_norm' AND p.post_type = 'kop_source' AND p.post_status = 'publish' AND n.meta_value IN ($in)",
                $want
            ), ARRAY_A);
            foreach ((array) $rows as $r) {
                $title = trim(html_entity_decode((string) $r['post_title'], ENT_QUOTES, 'UTF-8'));
                if ($title === '') continue;
                foreach ($norm[$r['norm']] ?? array() as $url) {
                    $out[$url] = array(
                        'kind'        => 'web',
                        'author'      => trim((string) $r['author']),
                        'title'       => $title,
                        'title_style' => 'quote',
                        'publisher'   => kop_citation_host($url),
                    );
                }
            }
        }
        return $out;
    }
}
