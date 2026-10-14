<?php
/**
 * Search engines: what each page tells Google beyond its text (the search plan,
 * https://claude.ai/artifact/FEpJSHihC9fDgdxoxzuQqb, and docs/PLAN.md 3.24).
 *
 *   names        facility and company pages name their past and other names in
 *                the title and description ("Sedona Sky Academy, formerly Copper
 *                Canyon Academy"), so a search for the old name finds the record
 *   schema       the program or company itself as an Organization (every name,
 *                place, years, the company running it), with the WebPage and
 *                breadcrumb Yoast leaves out on these generated routes; added to
 *                Yoast's own graph (wpseo_schema_graph), printed alone without Yoast
 *   descriptions pages Yoast has no description for: news posts (their excerpt or
 *                opening words), state hubs, the network map, the news feed
 *   home         one title on the home page instead of the site name twice
 *   share image  the site's banner on any page that has no image of its own
 *
 * The page data comes from kop_facility_page_data() / kop_operator_page_data() via
 * $GLOBALS['kop_facility_page'] / ['kop_operator_page'], set before the head prints.
 * Every filter here runs after the pages' own (priority 20), so this file is the
 * last word on them. scripts/test-seo.php checks it against tmp/prod.sqlite.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_SEO_TITLE_MAX')) define('KOP_SEO_TITLE_MAX', 65);
if (!defined('KOP_SEO_DESC_MAX')) define('KOP_SEO_DESC_MAX', 158);

// ---------------------------------------------------------------------------
// Words
// ---------------------------------------------------------------------------

if (!function_exists('kop_seo_clip')) {
    /** $text cut at a word to $max characters, "..." when cut. */
    function kop_seo_clip($text, $max = KOP_SEO_DESC_MAX) {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        if (mb_strlen($text) <= $max) return $text;
        // A whole sentence reads better than a cut one, when one ends far enough in.
        $period = mb_strrpos(mb_substr($text, 0, $max), '. ');
        if ($period !== false && $period >= $max * 0.5) return mb_substr($text, 0, $period + 1);
        $cut = mb_substr($text, 0, $max - 3);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $max * 0.6) $cut = mb_substr($cut, 0, $space);
        return rtrim($cut, " ,;:.-") . '...';
    }
}

if (!function_exists('kop_seo_other_names')) {
    /**
     * A page's other names, best first: [kind, name] with kind 'past' (formerly),
     * 'current' (now known as) or 'other'. Facility pages give formerly/later/aka,
     * company pages aka and current_name.
     */
    function kop_seo_other_names(array $page) {
        $own = mb_strtolower(trim((string) ($page['name'] ?? '')));
        $out = array();
        $seen = array($own => true);
        $add = function ($kind, $names) use (&$out, &$seen) {
            foreach ((array) $names as $n) {
                $n = trim((string) $n);
                $k = mb_strtolower($n);
                if ($n === '' || isset($seen[$k])) continue;
                $seen[$k] = true;
                $out[] = array($kind, $n);
            }
        };
        $add('past', $page['formerly'] ?? array());
        $add('current', array_merge((array) ($page['later'] ?? array()), array($page['current_name'] ?? '')));
        $add('other', $page['aka'] ?? array());
        return $out;
    }
}

if (!function_exists('kop_seo_names_lead')) {
    /** "Formerly X and Y." / "Also known as X." for the start of a description, '' when none. */
    function kop_seo_names_lead(array $names) {
        $by = array();
        foreach ($names as $p) $by[$p[0]][] = $p[1];
        $parts = array();
        foreach (array('past', 'current', 'other') as $kind) {
            if (empty($by[$kind])) continue;
            $list = array_slice($by[$kind], 0, 2);
            $joined = count($list) === 2 ? $list[0] . ' and ' . $list[1] : $list[0];
            $parts[] = kop_alias_label($kind, $joined);
        }
        return $parts ? implode('; ', $parts) . '.' : '';
    }
}

if (!function_exists('kop_seo_facility_title')) {
    /**
     * "Sedona Sky Academy, formerly Copper Canyon Academy (Rimrock, Arizona) | Kids Over Profits",
     * the site's name dropped when the whole runs past what a results page shows. A record with
     * no past name keeps the title its page set.
     */
    function kop_seo_facility_title(array $page) {
        $base = (string) ($page['seo_title'] ?? '');
        $past = array();
        foreach (kop_seo_other_names($page) as $p) if ($p[0] === 'past') $past[] = $p[1];
        if (!$past) return $base;
        $place = trim((string) ($page['place'] ?? ''));
        $title = $page['name'] . ', formerly ' . $past[0] . ($place !== '' ? ' (' . $place . ')' : '');
        $branded = $title . ' | Kids Over Profits';
        if (mb_strlen($branded) <= KOP_SEO_TITLE_MAX) return $branded;
        if (mb_strlen($title) <= KOP_SEO_TITLE_MAX + 10) return $title;
        return $page['name'] . ', formerly ' . $past[0];
    }
}

if (!function_exists('kop_seo_facility_description')) {
    /** The page's own description, opened by the names it also went by. */
    function kop_seo_facility_description(array $page) {
        $base = (string) ($page['seo_description'] ?? '');
        // The page cuts its description at 155; the whole summary is better to cut again here.
        if (substr($base, -3) === '...' && trim((string) ($page['summary'] ?? '')) !== '') $base = (string) $page['summary'];
        $lead = kop_seo_names_lead(kop_seo_other_names($page));
        return kop_seo_clip($lead !== '' ? $lead . ' ' . $base : $base);
    }
}

if (!function_exists('kop_seo_operator_title')) {
    /** "Sequel TSI, also known as Sequel Youth and Family Services | Kids Over Profits" when it has one. */
    function kop_seo_operator_title(array $page) {
        $base = (string) ($page['seo_title'] ?? '');
        $names = kop_seo_other_names($page);
        if (!$names) return $base;
        $title = $page['name'] . ', ' . lcfirst(kop_alias_label($names[0][0], $names[0][1]));
        $branded = $title . ' | Kids Over Profits';
        return mb_strlen($branded) <= KOP_SEO_TITLE_MAX ? $branded : (mb_strlen($title) <= KOP_SEO_TITLE_MAX + 10 ? $title : $base);
    }
}

if (!function_exists('kop_seo_operator_description')) {
    function kop_seo_operator_description(array $page) {
        $lead = kop_seo_names_lead(kop_seo_other_names($page));
        $base = (string) ($page['summary'] ?? '');
        return kop_seo_clip($lead !== '' ? $lead . ' ' . $base : $base);
    }
}

if (!function_exists('kop_seo_page')) {
    /** ['facility'|'operator', page data] for the page being shown, or null. */
    function kop_seo_page() {
        if (function_exists('kop_facility_pages_is_page') && kop_facility_pages_is_page()) return array('facility', $GLOBALS['kop_facility_page']);
        if (function_exists('kop_operator_pages_is_page') && kop_operator_pages_is_page()) return array('operator', $GLOBALS['kop_operator_page']);
        return null;
    }
}

// ---------------------------------------------------------------------------
// Titles and descriptions
// ---------------------------------------------------------------------------

if (!function_exists('kop_seo_title_filter')) {
    function kop_seo_title_filter($title) {
        $p = kop_seo_page();
        if ($p) return $p[0] === 'facility' ? kop_seo_facility_title($p[1]) : kop_seo_operator_title($p[1]);
        // The home page's title was the site name twice ("Kids Over Profits - Kids Over Profits").
        if (function_exists('is_front_page') && is_front_page()) return 'Kids Over Profits | Records of the troubled teen industry';
        return $title;
    }
    add_filter('pre_get_document_title', 'kop_seo_title_filter', 30);
    add_filter('wpseo_title', 'kop_seo_title_filter', 30);
    add_filter('wpseo_opengraph_title', 'kop_seo_title_filter', 30);
    add_filter('wpseo_twitter_title', 'kop_seo_title_filter', 30);
}

if (!function_exists('kop_seo_template_descriptions')) {
    /** Descriptions for pages whose words are drawn by script, by template; %s = the page's title. */
    function kop_seo_template_descriptions() {
        return array(
            'templates/page-state.php'       => 'Troubled teen programs in %s: every facility on record with its owners, years and status, state inspection findings, lawsuits and news.',
            'templates/page-network-map.php' => 'Who ran which troubled teen program, and who owns it: programs, parent companies and the people who moved between them, drawn from documented records.',
            'templates/page-news-feed.php'   => 'News of the troubled teen industry: abuse, deaths, lawsuits, closures and the companies behind the programs, each linked to the program\'s record.',
        );
    }
}

if (!function_exists('kop_seo_post_description')) {
    /** A post's excerpt, else its opening words with tags, shortcodes and URLs taken out. */
    function kop_seo_post_description($post) {
        if (!$post) return '';
        $text = trim((string) $post->post_excerpt);
        if ($text === '') {
            $html = (string) $post->post_content;
            $html = function_exists('strip_shortcodes') ? strip_shortcodes($html) : $html;
            $html = preg_replace('/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i', ' ', $html);
            $html = preg_replace('#<(script|style|figure|figcaption)\b.*?</\1>#is', ' ', $html);
            // One line per paragraph or <br>, so blocks never run together ("abuse casePrograms").
            $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|blockquote)>#i', "\n", $html);
            $title = mb_strtolower(trim(html_entity_decode((string) ($post->post_title ?? ''), ENT_QUOTES, 'UTF-8')));
            $lines = array();
            foreach (preg_split('/\n+/', html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8')) as $line) {
                $line = trim((string) preg_replace(array('#https?://\S+#', '/\s+/u'), array(' ', ' '), $line));
                if ($line === '' || preg_match('/^(read more|programs? mentioned:|source:)/i', $line)) continue;
                // The news posts open with the article's own date and headline, which the title already gives.
                if ($title !== '' && mb_strpos(mb_strtolower($line), $title) !== false) continue;
                if (preg_match('/^[A-Z][a-z]+ \d{1,2}, \d{4}\b/', $line) && mb_strlen($line) < 160) continue;
                $lines[] = preg_match('/[.!?:"\')]$/u', $line) ? $line : $line . '.';
            }
            $text = implode(' ', $lines);
        }
        return kop_seo_clip($text);
    }
}

if (!function_exists('kop_seo_description_filter')) {
    function kop_seo_description_filter($desc) {
        $p = kop_seo_page();
        if ($p) return $p[0] === 'facility' ? kop_seo_facility_description($p[1]) : kop_seo_operator_description($p[1]);
        if (trim((string) $desc) !== '' || !function_exists('is_singular')) return $desc;
        if (is_singular('post')) return kop_seo_post_description(get_queried_object());
        if (is_page()) {
            $tpl = (string) get_page_template_slug(get_queried_object_id());
            $by = kop_seo_template_descriptions();
            if (isset($by[$tpl])) return sprintf($by[$tpl], wp_strip_all_tags(get_the_title(get_queried_object_id())));
            return kop_seo_post_description(get_queried_object());
        }
        return $desc;
    }
    add_filter('wpseo_metadesc', 'kop_seo_description_filter', 30);
    add_filter('wpseo_opengraph_desc', 'kop_seo_description_filter', 30);
    add_filter('wpseo_twitter_description', 'kop_seo_description_filter', 30);
}

// ---------------------------------------------------------------------------
// Share image
// ---------------------------------------------------------------------------

if (!function_exists('kop_seo_default_image')) {
    /** The home page's banner, shared by every page that has no image of its own. */
    function kop_seo_default_image() {
        return (string) apply_filters('kop_seo_default_image', home_url('/wp-content/uploads/2025/06/banner-scaled.png'));
    }
}

if (!function_exists('kop_seo_add_default_image')) {
    function kop_seo_add_default_image($images) {
        if (!is_object($images) || !method_exists($images, 'add_image_by_url')) return $images;
        if (method_exists($images, 'has_images') && $images->has_images()) return $images;
        $images->add_image_by_url(kop_seo_default_image());
        return $images;
    }
    add_filter('wpseo_add_opengraph_additional_images', 'kop_seo_add_default_image', 30);
}

// ---------------------------------------------------------------------------
// Tag archives
// ---------------------------------------------------------------------------

if (!function_exists('kop_seo_tag_robots')) {
    /**
     * Tag archives stay out of search (owner's decision, 2026-10-14): each lists a few posts and adds
     * nothing the posts don't. Links on them are still followed, and they leave Yoast's sitemap.
     */
    function kop_seo_tag_robots($robots) {
        if (!function_exists('is_tag') || !is_tag() || !is_array($robots)) return $robots;
        $robots['index'] = 'noindex';
        $robots['follow'] = 'follow';
        return $robots;
    }
    add_filter('wpseo_robots_array', 'kop_seo_tag_robots', 30);
}

if (!function_exists('kop_seo_sitemap_exclude_tags')) {
    function kop_seo_sitemap_exclude_tags($exclude, $taxonomy) {
        return $taxonomy === 'post_tag' ? true : $exclude;
    }
    add_filter('wpseo_sitemap_exclude_taxonomy', 'kop_seo_sitemap_exclude_tags', 30, 2);
}

// ---------------------------------------------------------------------------
// Structured data
// ---------------------------------------------------------------------------

if (!function_exists('kop_seo_year')) {
    function kop_seo_year($v) {
        return preg_match('/\b(1[89]\d\d|20\d\d)\b/', (string) $v, $m) ? $m[1] : '';
    }
}

if (!function_exists('kop_seo_facility_pieces')) {
    /** WebPage, BreadcrumbList and the program as an Organization, for one facility page. */
    function kop_seo_facility_pieces(array $page) {
        $url = (string) $page['url'];
        $site = home_url('/');
        $doc = (array) ($page['doc'] ?? array());
        $op = (array) ($doc['operatingPeriod'] ?? array());
        $start = kop_seo_year($op['startYear'] ?? '');
        $end = kop_seo_year($op['endYear'] ?? ($page['end_year'] ?? ''));
        $closed = in_array((string) ($page['status'] ?? ''), array('Closed', 'Transferred'), true);

        $org = array(
            '@type' => 'Organization',
            '@id'   => $url . '#program',
            'name'  => (string) $page['name'],
        );
        $alt = array_map(function ($p) { return $p[1]; }, kop_seo_other_names($page));
        if ($alt) $org['alternateName'] = array_values($alt);
        if (trim((string) ($page['summary'] ?? '')) !== '') $org['description'] = (string) $page['summary'];
        $address = array_filter(array(
            'addressLocality' => (string) ($page['city'] ?? ''),
            'addressRegion'   => (string) ($page['state_code'] ?? ''),
            'addressCountry'  => (string) ($page['country'] ?? '') !== '' ? (string) $page['country'] : ((string) ($page['state_code'] ?? '') !== '' ? 'US' : ''),
        ), 'strlen');
        if ($address) $org['address'] = array('@type' => 'PostalAddress') + $address;
        if ($start !== '') $org['foundingDate'] = $start;
        if ($end !== '' && ($closed || $end <= gmdate('Y'))) $org['dissolutionDate'] = $end;
        $operator = (array) ($page['operator'] ?? array());
        if (trim((string) ($operator['name'] ?? '')) !== '') {
            $parent = array('@type' => 'Organization', 'name' => (string) $operator['name']);
            if (!empty($operator['url'])) $parent['url'] = (string) $operator['url'];
            $org['parentOrganization'] = $parent;
        }

        $crumbs = array(array('Home', $site));
        if (!empty($page['hub_url']) && !empty($page['hub_name'])) $crumbs[] = array((string) $page['hub_name'], (string) $page['hub_url']);
        $crumbs[] = array((string) $page['name'], $url);
        return kop_seo_page_pieces($url, kop_seo_facility_title($page), kop_seo_facility_description($page), $crumbs, $org, (string) ($page['updated_at'] ?? ''));
    }
}

if (!function_exists('kop_seo_operator_pieces')) {
    /** WebPage, BreadcrumbList and the company as an Organization with its programs. */
    function kop_seo_operator_pieces(array $page) {
        $url = (string) $page['url'];
        $org = array('@type' => 'Organization', '@id' => $url . '#company', 'name' => (string) $page['name']);
        $alt = array_map(function ($p) { return $p[1]; }, kop_seo_other_names($page));
        if (!empty($page['full_name']) && mb_strtolower($page['full_name']) !== mb_strtolower($page['name'])) array_unshift($alt, (string) $page['full_name']);
        if ($alt) $org['alternateName'] = array_values(array_unique($alt));
        if (trim((string) ($page['summary'] ?? '')) !== '') $org['description'] = (string) $page['summary'];
        $subs = array();
        foreach ((array) ($page['facilities'] ?? array()) as $f) {
            if (!is_array($f) || trim((string) ($f['name'] ?? '')) === '') continue;
            $one = array('@type' => 'Organization', 'name' => (string) $f['name']);
            if (!empty($f['url']) && strpos((string) $f['url'], '/facility/') !== false) $one['url'] = (string) $f['url'];
            $subs[] = $one;
            if (count($subs) >= 100) break;
        }
        if ($subs) $org['subOrganization'] = $subs;
        $parents = array();
        foreach ((array) ($page['parents'] ?? array()) as $p) {
            if (!is_array($p) || trim((string) ($p['name'] ?? '')) === '') continue;
            $parents[] = array_filter(array('@type' => 'Organization', 'name' => (string) $p['name'], 'url' => (string) ($p['url'] ?? '')), 'strlen');
        }
        if ($parents) $org['parentOrganization'] = count($parents) === 1 ? $parents[0] : $parents;

        $crumbs = array(array('Home', home_url('/')));
        if (!empty($page['index_url'])) $crumbs[] = array('Parent companies', (string) $page['index_url']);
        $crumbs[] = array((string) $page['name'], $url);
        return kop_seo_page_pieces($url, kop_seo_operator_title($page), kop_seo_operator_description($page), $crumbs, $org, '');
    }
}

if (!function_exists('kop_seo_page_pieces')) {
    function kop_seo_page_pieces($url, $title, $description, array $crumbs, array $org, $modified) {
        $site = home_url('/');
        $items = array();
        foreach ($crumbs as $i => $c) {
            $item = array('@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0]);
            if ($i < count($crumbs) - 1) $item['item'] = $c[1];
            $items[] = $item;
        }
        $webpage = array(
            '@type'       => 'WebPage',
            '@id'         => $url,
            'url'         => $url,
            'name'        => preg_replace('/\s*\|\s*Kids Over Profits$/', '', (string) $title),
            'description' => (string) $description,
            'isPartOf'    => array('@id' => $site . '#website'),
            'about'       => array('@id' => $org['@id']),
            'mainEntity'  => array('@id' => $org['@id']),
            'breadcrumb'  => array('@id' => $url . '#breadcrumb'),
            'inLanguage'  => 'en-US',
            'publisher'   => array('@id' => $site . '#organization'),
        );
        $time = $modified !== '' ? strtotime($modified . ' UTC') : false;
        if ($time) $webpage['dateModified'] = gmdate('c', $time);
        return array(
            $webpage,
            array('@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $items),
            $org,
        );
    }
}

if (!function_exists('kop_seo_pieces')) {
    /** The structured data pieces for the page being shown, [] for any other page. */
    function kop_seo_pieces() {
        $p = kop_seo_page();
        if (!$p) return array();
        return $p[0] === 'facility' ? kop_seo_facility_pieces($p[1]) : kop_seo_operator_pieces($p[1]);
    }
}

if (!function_exists('kop_seo_schema_graph')) {
    /** Yoast's graph with this page's pieces added (one of the same @id is replaced). */
    function kop_seo_schema_graph($graph, $context = null) {
        $mine = kop_seo_pieces();
        if (!$mine || !is_array($graph)) return $graph;
        $ids = array();
        foreach ($mine as $m) $ids[$m['@id']] = true;
        $graph = array_values(array_filter($graph, function ($g) use ($ids) {
            return !(is_array($g) && isset($g['@id']) && isset($ids[$g['@id']]));
        }));
        return array_merge($graph, $mine);
    }
    add_filter('wpseo_schema_graph', 'kop_seo_schema_graph', 20, 2);
}

if (!function_exists('kop_seo_schema_fallback')) {
    /** Without Yoast, the same pieces as their own graph. */
    function kop_seo_schema_fallback() {
        if (defined('WPSEO_VERSION')) return;
        $mine = kop_seo_pieces();
        if (!$mine) return;
        echo '<script type="application/ld+json">' . wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $mine), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' . "\n";
    }
    add_action('wp_head', 'kop_seo_schema_fallback', 6);
}
