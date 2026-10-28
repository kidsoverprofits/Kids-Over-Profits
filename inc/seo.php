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
    function kop_seo_clip($text, $max = KOP_SEO_DESC_MAX, $min_sentence = 0.5) {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        if (mb_strlen($text) <= $max) return $text;
        // A whole sentence reads better than a cut one, when one ends far enough in. "St. ", "Inc. "
        // and an initial ("William E. Hay") end no sentence.
        $head = mb_substr($text, 0, $max);
        $period = false;
        if (preg_match_all('/(?<!\bSt|\bMt|\bFt|\bDr|\bJr|\bSr|\bCo|\bNo|\bInc|\bLtd|\bLLC|\bCorp|\b[A-Z])\. /u', $head, $m, PREG_OFFSET_CAPTURE)) {
            $period = mb_strlen(substr($head, 0, end($m[0])[1]));
        }
        if ($period !== false && $period >= $max * $min_sentence) return mb_substr($text, 0, $period + 1);
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
        // "Centers for Adolescent Recovery and Education (CARE)" is the page's own name and CARE together,
        // so neither is another name of a page called either one.
        $keys = function ($n) {
            $n = mb_strtolower(trim((string) $n));
            $out = array($n => true);
            if (preg_match('/^(.*?)\s*\(([^()]+)\)$/u', $n, $m)) {
                $out[trim($m[1])] = true;
                $out[trim($m[2])] = true;
            }
            return $out;
        };
        $out = array();
        $seen = $keys($page['name'] ?? '') + (!empty($page['full_name']) ? $keys($page['full_name']) : array());
        $add = function ($kind, $names) use (&$out, &$seen, $keys) {
            foreach ((array) $names as $n) {
                $n = trim((string) $n);
                $k = $keys($n);
                if ($n === '') continue;
                $known = (bool) array_intersect_key($k, $seen);
                $seen += $k;   // a skipped "Name (ABBR)" still makes ABBR known
                if (!$known) $out[] = array($kind, $n);
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

if (!function_exists('kop_seo_lead_and')) {
    /**
     * The names lead and $base as one description that fits. When every name leaves the summary only a
     * few words, the lead keeps its first name. [lead, text]; cut with kop_seo_clip_after().
     */
    function kop_seo_lead_and(array $names, $base) {
        $base = trim((string) $base);
        if (!$names) return array('', $base);
        foreach (array($names, array($names[0])) as $set) {
            $lead = kop_seo_names_lead($set);
            $text = $lead . ' ' . $base;
            if (mb_strlen($text) <= KOP_SEO_DESC_MAX || mb_strlen($lead) + 45 <= KOP_SEO_DESC_MAX) break;
        }
        return array($lead, $text);
    }
}

if (!function_exists('kop_seo_clip_after')) {
    /** kop_seo_clip(), never cutting back to a sentence inside the first $keep characters (the names lead). */
    function kop_seo_clip_after($text, $keep) {
        return kop_seo_clip($text, KOP_SEO_DESC_MAX, max(0.5, min(0.95, ($keep + 30) / KOP_SEO_DESC_MAX)));
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
    /**
     * The page's own description, opened by the names it also went by. A summary that leaves room
     * (most say only "X is a group home in Corona, California.") goes on with what the record holds,
     * worst first: "On record: 1 death, 2 lawsuits and 14 state inspection reports." A merged profile
     * post's excerpt was written by hand and stays as it is.
     */
    function kop_seo_facility_description(array $page) {
        $summary = trim((string) ($page['summary'] ?? ''));
        $base = (string) ($page['seo_description'] ?? '');
        $own = $summary === '' || $base === $summary || substr($base, -3) === '...';
        // The page cuts its description at 155; the whole summary is better to cut again here.
        if (substr($base, -3) === '...' && $summary !== '') $base = $summary;
        list($lead, $text) = kop_seo_lead_and(kop_seo_other_names($page), $base);
        if ($own) $text = kop_seo_fill($text, kop_seo_facility_record_phrases($page), kop_seo_facility_missing($page));
        return kop_seo_clip_after($text, mb_strlen($lead));
    }
}

if (!function_exists('kop_seo_count')) {
    /** "1 lawsuit" / "3 lawsuits". */
    function kop_seo_count($n, $one, $many) {
        return $n . ' ' . ($n === 1 ? $one : $many);
    }
}

if (!function_exists('kop_seo_list')) {
    /** "a", "a and b", "a, b and c". */
    function kop_seo_list(array $parts) {
        $last = array_pop($parts);
        return $parts ? implode(', ', $parts) . ' and ' . $last : (string) $last;
    }
}

if (!function_exists('kop_seo_fill')) {
    /**
     * $text with "On record: <as many phrases as fit>." after it, when it ends short of a results
     * page's line; the first of $none (sentences, longest first) that fits when there are no phrases.
     * The text is never cut for them.
     */
    function kop_seo_fill($text, array $phrases, $none = '') {
        $text = trim((string) $text);
        $room = KOP_SEO_DESC_MAX - mb_strlen($text) - 1;
        if ($room < 30) return $text;
        $take = array();
        foreach ($phrases as $p) {
            $try = 'On record: ' . kop_seo_list(array_merge($take, array($p))) . '.';
            if (mb_strlen($try) > $room) break;
            $take[] = $p;
        }
        if ($take) return $text . ' On record: ' . kop_seo_list($take) . '.';
        foreach ((array) $none as $one) if ($one !== '' && mb_strlen($one) <= $room) return $text . ' ' . $one;
        return $text;
    }
}

if (!function_exists('kop_seo_facility_record_phrases')) {
    /** What a facility page holds, worst first: deaths, lawsuits, serious findings, reports, incidents, news, staff. */
    function kop_seo_facility_record_phrases(array $page) {
        $insp = is_array($page['inspections'] ?? null) ? $page['inspections'] : array();
        $staff = 0;
        foreach ((array) ($page['staff'] ?? array()) as $people) $staff += is_array($people) ? count($people) : 0;
        $counts = array(
            array(count((array) ($page['memorials'] ?? array())), 'death', 'deaths'),
            array(count((array) ($page['lawsuits'] ?? array())), 'lawsuit', 'lawsuits'),
            array(count((array) ($insp['violations'] ?? array())), 'serious inspection finding', 'serious inspection findings'),
            array((int) ($insp['total'] ?? 0), 'state inspection report', 'state inspection reports'),
            array(count((array) ($page['incidents'] ?? array())), 'reported incident', 'reported incidents'),
            array(count((array) ($page['news'] ?? array())), 'news story', 'news stories'),
            array($staff, 'named staff member', 'named staff'),
        );
        $out = array();
        foreach ($counts as $c) if ($c[0] > 0) $out[] = kop_seo_count($c[0], $c[1], $c[2]);
        return $out;
    }
}

if (!function_exists('kop_seo_facility_missing')) {
    /** For a record holding nothing yet: which of its company and years are not on file, as sentences longest first. */
    function kop_seo_facility_missing(array $page) {
        $doc = (array) ($page['doc'] ?? array());
        $op = (array) ($doc['operatingPeriod'] ?? array());
        $missing = array();
        if (trim((string) ($page['operator']['name'] ?? '')) === '') $missing[] = 'who ran it';
        if (kop_seo_year($op['startYear'] ?? '') === '' && kop_seo_year($op['endYear'] ?? ($page['end_year'] ?? '')) === '') $missing[] = 'its years';
        if (!$missing) return array();
        return array('Not on file yet: ' . kop_seo_list($missing) . '; send what you know.', 'Not on file yet: ' . kop_seo_list($missing) . '.', 'Not on file yet: ' . $missing[0] . '.');
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
    /**
     * The company's summary, opened by its other names. When the whole does not fit, its first sentence
     * goes on with what the record holds ("On record: 12 programs in 8 states, 2 lawsuits and 1 death.")
     * instead of being cut to the first sentence alone.
     */
    function kop_seo_operator_description(array $page) {
        $base = (string) ($page['summary'] ?? '');
        list($lead, $whole) = kop_seo_lead_and(kop_seo_other_names($page), $base);
        if (mb_strlen($whole) <= KOP_SEO_DESC_MAX) return $whole;
        // kop_operator_page_data() writes "<name> is a parent company ... ." then "Kids Over Profits has records of ...".
        $first = trim((string) strstr($base, ' Kids Over Profits has records of ', true));
        if ($first === '') return kop_seo_clip_after($whole, mb_strlen($lead));
        $text = $lead !== '' ? $lead . ' ' . $first : $first;
        if (mb_strlen($text) > KOP_SEO_DESC_MAX) $text = $first;
        $n = count((array) ($page['facilities'] ?? array()));
        $k = (int) ($page['place_count'] ?? 0);
        $phrases = array();
        if ($n > 0) $phrases[] = kop_seo_count($n, 'program', 'programs') . ($k > 1 ? ' in ' . $k . ' states' : '');
        $deaths = count((array) ($page['memorials'] ?? array()));
        $suits = count((array) ($page['lawsuits'] ?? array()));
        if ($deaths) $phrases[] = kop_seo_count($deaths, 'death', 'deaths');
        if ($suits) $phrases[] = kop_seo_count($suits, 'lawsuit', 'lawsuits');
        $news = count((array) ($page['news'] ?? array()));
        if ($news) $phrases[] = kop_seo_count($news, 'news story', 'news stories');
        return kop_seo_clip_after(kop_seo_fill($text, $phrases), $text === $first ? 0 : mb_strlen($lead));
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

if (!function_exists('kop_seo_post_lines')) {
    /** A post's text as plain lines, one per paragraph, heading or <br>; tags, shortcodes and URLs taken out. */
    function kop_seo_post_lines($post) {
        $html = (string) ($post->post_content ?? '');
        $html = function_exists('strip_shortcodes') ? strip_shortcodes($html) : $html;
        $html = preg_replace('/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i', ' ', $html);
        $html = preg_replace('#<(script|style|figure|figcaption)\b.*?</\1>#is', ' ', $html);
        // One line per paragraph or <br>, so blocks never run together ("abuse casePrograms").
        $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6]|blockquote)>#i', "\n", $html);
        $lines = array();
        foreach (preg_split('/\n+/', html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES, 'UTF-8')) as $line) {
            $line = trim((string) preg_replace(array('#https?://\S+#', '/\s+/u'), array(' ', ' '), $line));
            if ($line !== '') $lines[] = $line;
        }
        return $lines;
    }
}

if (!function_exists('kop_seo_lawsuit_sentence')) {
    /**
     * The old lawsuit posts are label/value pairs ("Plaintiffs:", "Anonymous survivors", "Defendant:",
     * "Vista RTC", "Location:", "Utah", "Date filed:", "03/23/2022"): one sentence from them,
     * "Anonymous survivors sued Vista RTC in Utah on March 23, 2022.", '' for any other post.
     */
    function kop_seo_lawsuit_sentence(array $lines) {
        $f = array();
        foreach ($lines as $i => $line) {
            if (!preg_match('/^(plaintiffs?|defendants?|location|date filed|complaint filed|filed|status)\s*:\s*(.*)$/i', $line, $m)) continue;
            $key = strtolower($m[1]);
            $key = strpos($key, 'plaintiff') === 0 ? 'p' : (strpos($key, 'defendant') === 0 ? 'd' : (strpos($key, 'filed') !== false ? 'date' : $key));
            $value = trim($m[2]) !== '' ? trim($m[2]) : (string) ($lines[$i + 1] ?? '');
            if ($value !== '' && !isset($f[$key]) && !preg_match('/:\s*$/', $value)) $f[$key] = rtrim($value, ' .');
        }
        if (empty($f['p']) || empty($f['d'])) return '';
        $d = preg_replace('/,?\s*et\.? al\.?$/i', ' and others', $f['d']);
        $s = $f['p'] . ' sued ' . $d;
        if (!empty($f['location'])) $s .= ' in ' . $f['location'];
        if (!empty($f['date'])) {
            $t = preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $f['date'], $dm) ? mktime(0, 0, 0, (int) $dm[1], (int) $dm[2], (int) $dm[3]) : false;
            $s .= ' on ' . ($t ? date('F j, Y', $t) : $f['date']);
        }
        $s .= '.';
        if (!empty($f['status'])) $s .= ' Status: ' . lcfirst($f['status']) . '.';
        return $s;
    }
}

if (!function_exists('kop_seo_post_description')) {
    /**
     * A post's excerpt, else its opening words: a lawsuit post's fields as one sentence, a news post's
     * facts without its date, headline, byline, trigger list or "Read more".
     */
    function kop_seo_post_description($post) {
        if (!$post) return '';
        $text = trim((string) $post->post_excerpt);
        if ($text === '') {
            $all = kop_seo_post_lines($post);
            $text = kop_seo_lawsuit_sentence($all);
        }
        if ($text === '') {
            $title = mb_strtolower(trim(html_entity_decode((string) ($post->post_title ?? ''), ENT_QUOTES, 'UTF-8')));
            $lines = array();
            foreach ($all as $line) {
                if (preg_match('/^(read more|programs? mentioned:|source:|author:|by [A-Z][a-z]+ [A-Z]|photo by |triggers?:|trigger warnings?:|content warnings?:|staff mentioned:|parent compan(y|ies):)/i', $line)) continue;
                // "Summary:" heads the news posts' own words.
                $line = trim((string) preg_replace('/^summary:\s*/i', '', $line));
                if ($line === '') continue;
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

if (!function_exists('kop_seo_state_count')) {
    /** Facility records in one state (2-letter code), cached 12 hours; 0 when unknown. */
    function kop_seo_state_count($code) {
        global $wpdb;
        $code = strtoupper(trim((string) $code));
        if (!preg_match('/^[A-Z]{2}$/', $code) || !isset($wpdb)) return 0;
        $all = get_transient('kop_seo_state_counts');
        if (!is_array($all)) {
            $all = array();
            foreach ((array) $wpdb->get_results('SELECT state, COUNT(*) AS n FROM facilities_v2 GROUP BY state', ARRAY_A) as $r) {
                $all[strtoupper((string) $r['state'])] = (int) $r['n'];
            }
            set_transient('kop_seo_state_counts', $all, 12 * HOUR_IN_SECONDS);
        }
        return (int) ($all[$code] ?? 0);
    }
}

if (!function_exists('kop_seo_state_description')) {
    /** A state hub's description, with its number of programs so no two hubs read the same. */
    function kop_seo_state_description($state_name) {
        $state_name = trim((string) $state_name);
        $code = function_exists('kop_facility_state_code') ? (string) kop_facility_state_code($state_name) : '';
        $n = $code !== '' ? kop_seo_state_count($code) : 0;
        if ($n < 2) return sprintf(kop_seo_template_descriptions()['templates/page-state.php'], $state_name);
        return kop_seo_clip(number_format($n) . ' troubled teen programs on record in ' . $state_name
            . ', with their owners, years and status, state inspection findings, lawsuits and news.');
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
            if ($tpl === 'templates/page-state.php') return kop_seo_state_description(wp_strip_all_tags(get_the_title(get_queried_object_id())));
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
// Tool pages
// ---------------------------------------------------------------------------

if (!function_exists('kop_seo_noindex_page')) {
    /**
     * A page kept out of search: the admin and editor tool templates (kop_template_layout_noindex()),
     * the tool pages built without one, and /location-index/, which only redirects to the directory.
     */
    function kop_seo_noindex_page($post, $template) {
        $tpls = function_exists('kop_template_layout_noindex') ? kop_template_layout_noindex() : array();
        $tpls[] = 'templates/page-location-index.php';
        if (in_array((string) $template, $tpls, true)) return true;
        return in_array((string) ($post->post_name ?? ''), array('tti-wiki-entry-generator', 'wiki-editor', 'news-processor', 'location-index'), true);
    }
}

if (!function_exists('kop_seo_sitemap_exclude_tools')) {
    /** The tool pages leave Yoast's sitemap: it listed them while the pages asked not to be indexed (SEO audit, 2026-10-20). */
    function kop_seo_sitemap_exclude_tools($ids) {
        global $wpdb;
        $ids = is_array($ids) ? $ids : array();
        $cached = get_transient('kop_seo_tool_page_ids');
        if (!is_array($cached)) {
            $cached = array();
            $rows = $wpdb->get_results("SELECT p.ID, p.post_name, m.meta_value AS tpl FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_page_template'
                WHERE p.post_type = 'page' AND p.post_status = 'publish'");
            foreach ((array) $rows as $r) if (kop_seo_noindex_page($r, (string) $r->tpl)) $cached[] = (int) $r->ID;
            set_transient('kop_seo_tool_page_ids', $cached, 12 * HOUR_IN_SECONDS);
        }
        return array_values(array_unique(array_merge(array_map('intval', $ids), $cached)));
    }
    add_filter('wpseo_exclude_from_sitemap_by_post_ids', 'kop_seo_sitemap_exclude_tools', 30);
}

// ---------------------------------------------------------------------------
// Structured data
// ---------------------------------------------------------------------------

if (!function_exists('kop_seo_year')) {
    function kop_seo_year($v) {
        return preg_match('/\b(1[89]\d\d|20\d\d)\b/', (string) $v, $m) ? $m[1] : '';
    }
}

// ---------------------------------------------------------------------------
// Breadcrumbs: the pages between Home and a record or place page, {title, url}.
// The visible trail (kop_page_trail_set(), inc/article-parts.php) and the
// BreadcrumbList (below, and Yoast's on the post-backed pages) read these, so
// the two never disagree.
// ---------------------------------------------------------------------------

if (!function_exists('kop_seo_place_steps')) {
    /** The location directory, then the state or country hub when it has a page. */
    function kop_seo_place_steps($place_name = '', $place_url = '') {
        $index = function_exists('kop_location_index_url') ? kop_location_index_url() : home_url('/tti-program-index/?view=location');
        $steps = array(array('title' => 'Programs by location', 'url' => $index));
        if (trim((string) $place_name) !== '' && trim((string) $place_url) !== '') {
            $steps[] = array('title' => (string) $place_name, 'url' => (string) $place_url);
        }
        return $steps;
    }
}

if (!function_exists('kop_seo_facility_steps')) {
    function kop_seo_facility_steps(array $page) {
        // A related-category record: Home > its category's list (inc/related-facilities.php).
        if (!empty($page['related'])) return array(array('title' => (string) $page['related']['label'], 'url' => (string) $page['related']['url']));
        return kop_seo_place_steps((string) ($page['hub_name'] ?? ''), (string) ($page['hub_url'] ?? ''));
    }
}

if (!function_exists('kop_seo_operator_steps')) {
    function kop_seo_operator_steps(array $page) {
        return !empty($page['index_url']) ? array(array('title' => 'Parent companies', 'url' => (string) $page['index_url'])) : array();
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
        // A company that only ran it in the past is not its parent organization.
        if (trim((string) ($operator['name'] ?? '')) !== '' && empty($operator['past'])) {
            $parent = array('@type' => 'Organization', 'name' => (string) $operator['name']);
            if (!empty($operator['url'])) $parent['url'] = (string) $operator['url'];
            $org['parentOrganization'] = $parent;
        }

        $crumbs = array(array('Home', $site));
        foreach (kop_seo_facility_steps($page) as $s) $crumbs[] = array($s['title'], $s['url']);
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
        foreach (kop_seo_operator_steps($page) as $s) $crumbs[] = array($s['title'], $s['url']);
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
