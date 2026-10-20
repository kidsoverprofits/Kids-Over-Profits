<?php
/**
 * Links in the text to our own pages: the first mention of a facility, a
 * parent company, a state or a glossary term in a post, a page, a facility or
 * company page's prose, or a wiki entry links to that record's page, and a
 * post ends with "Related on Kids Over Profits", the record pages it names.
 *
 * Done as the page is served (nothing is stored in a post), from names only:
 * posts carry no record ids. To keep false links out:
 * - every word of a name (bar "of", "the", ...) is capitalised in the text, so
 *   "a turning point" never matches Turning Point;
 * - one-word names link only as an abbreviation in capitals (CEDU, UHS), or as
 *   a state; names made only of common words ("Youth Services") never link;
 * - a short name that is part of a longer one is left ("New York Times",
 *   "Department of Youth Services", "George Washington");
 * - a facility name of one or two words, or a name several facilities share,
 *   links only when the text also names the facility's state;
 * - nothing inside a link, heading, button, form, code or script is touched,
 *   and a page never links to itself.
 * Tested by scripts/test-content-links.php (rules, then real posts from the mirror).
 */

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

if (!defined('KOP_CONTENT_LINKS_VERSION')) {
    define('KOP_CONTENT_LINKS_VERSION', 1);
}

/** Words that may stay lower case inside a name ("Academy of the Sierras"). */
function kop_clinks_small_words() {
    static $w = null;
    if ($w === null) $w = array_flip(array('of', 'the', 'and', 'for', 'at', 'in', 'on', 'a', 'an', 'to', 'by', 'de', 'la', 'del', 'y', '&'));
    return $w;
}

/** Words that alone, or together, are no name ("Youth Services", "Family Center"). */
function kop_clinks_generic_words() {
    static $w = null;
    if ($w === null) $w = array_flip(array(
        'youth', 'services', 'service', 'family', 'families', 'center', 'centre', 'centers', 'home', 'homes', 'house',
        'academy', 'school', 'schools', 'ranch', 'treatment', 'behavioral', 'behavioural', 'health', 'care', 'group',
        'program', 'programs', 'residential', 'therapeutic', 'boarding', 'wilderness', 'children', 'childrens', 'child',
        'kids', 'teen', 'teens', 'boys', 'girls', 'girl', 'boy', 'adolescent', 'adolescents', 'juvenile', 'detention',
        'facility', 'facilities', 'shelter', 'camp', 'village', 'ministries', 'ministry', 'foundation', 'institute',
        'hospital', 'clinic', 'counseling', 'recovery', 'life', 'new', 'hope', 'inc', 'llc', 'corp', 'company', 'co',
        'department', 'office', 'county', 'state', 'national', 'american', 'christian', 'baptist', 'catholic', 'community',
        'mental', 'education', 'educational', 'learning', 'development', 'support', 'house', 'safe', 'place', 'the', 'of',
        'and', 'for', 'at', 'in', 'a', 'an', 'center', 'transition', 'transitional', 'living', 'independent', 'day', 'night',
        'girls', 'mens', 'womens', 'men', 'women', 'young', 'adult', 'adults', 'secure', 'treatment', 'unit', 'campus',
        'north', 'south', 'east', 'west', 'central', 'regional', 'lodge', 'farm', 'farms', 'cottage', 'cottages', 'hall',
    ));
    return $w;
}

/** Words that open a sentence before a name without being part of it ("In Utah", "Former Provo Canyon School staff"). */
function kop_clinks_openers() {
    static $w = null;
    if ($w === null) $w = array_flip(array('in', 'at', 'from', 'to', 'the', 'a', 'an', 'former', 'then', 'but', 'and', 'when', 'after',
        'before', 'since', 'while', 'both', 'only', 'even', 'like', 'unlike', 'across', 'outside', 'inside', 'near', 'throughout',
        'last', 'this', 'that', 'many', 'some', 'most', 'all', 'its', 'their', 'his', 'her', 'our', 'each', 'every', 'under', 'over',
        'by', 'for', 'with', 'without', 'as', 'if', 'so', 'yet', 'or', 'nor', 'during', 'until', 'about', 'between', 'among', 'into',
        'staff', 'students', 'teens', 'parents', 'today', 'now', 'later', 'earlier', 'meanwhile', 'also', 'once', 'there', 'here',
        'where', 'why', 'how', 'what', 'which', 'who', 'currently', 'previously', 'eventually', 'finally', 'first', 'second', 'third'));
    return $w;
}

/** Abbreviations whose full stop does not end a sentence. */
function kop_clinks_abbrevs() {
    static $w = null;
    if ($w === null) $w = array_flip(array('st', 'mt', 'ft', 'inc', 'co', 'corp', 'jr', 'sr', 'dr', 'mr', 'mrs', 'ms', 'ltd', 'no', 'vs', 'u.s', 'd.c'));
    return $w;
}

/** "St. Mary's Academy" and "St Marys Academy" -> "st marys academy". */
function kop_clinks_key($s) {
    $s = (string) $s;
    if ($s === '') return '';
    $s = preg_replace('/\s*\([^)]*\)\s*$/u', '', $s);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = str_replace(array("\u{2019}", "\u{2018}", "'", '`'), '', $s);
    $s = str_replace('&', ' and ', $s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

// ---------------------------------------------------------------------------
// The names: {targets: [[kind, url, label, place]], keys: {key: [target, ...]}, max: words}
// ---------------------------------------------------------------------------

/**
 * Cached for six hours (and dropped on a facility sync), so a renamed record
 * or a new page links within the day without a fingerprint on every request.
 */
function kop_clinks_dict($force = false) {
    static $memo = null;
    if ($memo !== null && !$force) return $memo;
    if (!$force) {
        $cached = get_transient('kop_content_links_dict');
        if (is_array($cached) && ($cached['v'] ?? 0) === KOP_CONTENT_LINKS_VERSION) return $memo = $cached;
    }
    $memo = kop_clinks_build_dict();
    set_transient('kop_content_links_dict', $memo, 6 * 3600);
    return $memo;
}

function kop_clinks_flush() {
    delete_transient('kop_content_links_dict');
}
add_action('kop_facility_v2_sync', 'kop_clinks_flush', 30);

function kop_clinks_build_dict() {
    global $wpdb;
    $dict = array('v' => KOP_CONTENT_LINKS_VERSION, 'built' => time(), 'targets' => array(), 'keys' => array(), 'first' => array(), 'max' => 1);
    $add_target = function ($kind, $url, $label, $place = '') use (&$dict) {
        $dict['targets'][] = array($kind, (string) $url, (string) $label, (string) $place);
        return count($dict['targets']) - 1;
    };
    $add_key = function ($name, $t) use (&$dict) {
        $key = kop_clinks_key($name);
        if ($key === '') return;
        if (isset($dict['keys'][$key]) && in_array($t, $dict['keys'][$key], true)) return;
        $dict['keys'][$key][] = $t;
        $words = substr_count($key, ' ') + 1;
        $dict['max'] = max($dict['max'], $words);
        // The longest name starting with each word, so most words are passed over at once.
        $first = strtok($key, ' ');
        $dict['first'][$first] = max($dict['first'][$first] ?? 0, $words);
    };
    $abbr_to_state = function_exists('kop_state_abbrev_to_name') ? kop_state_abbrev_to_name() : array();

    // Facilities with a page: their name, then other and past names.
    if (function_exists('kop_facility_pages_index') && isset($wpdb)) {
        $index = kop_facility_pages_index();
        if (!empty($index['ids'])) {
            $rows = $wpdb->get_results('SELECT id, name, state, country, json_data FROM facilities_v2 ORDER BY id', ARRAY_A);
            $later = array();
            foreach ((array) $rows as $r) {
                $id = (int) $r['id'];
                if (!isset($index['ids'][$id])) continue;
                $url = kop_facility_page_url($id);
                if ($url === '') continue;
                $st = strtoupper(trim((string) $r['state']));
                $place = $st !== '' ? ($abbr_to_state[$st] ?? $st) : trim((string) $r['country']);
                $t = $add_target('facility', $url, $index['ids'][$id]['name'], $place);
                $add_key($index['ids'][$id]['name'], $t);
                $doc = function_exists('kop_v2_decode') ? kop_v2_decode($r['json_data']) : json_decode((string) $r['json_data'], true);
                $ident = is_array($doc) ? (array) ($doc['identification'] ?? array()) : array();
                foreach (array('otherNames', 'pastNames') as $k) {
                    $items = function_exists('kop_facility_pages_text_items') ? kop_facility_pages_text_items($ident[$k] ?? null) : (array) ($ident[$k] ?? array());
                    foreach ($items as $n) if (is_string($n)) $later[] = array($n, $t);
                }
            }
            // Another record's own name is never taken over by an other name, and a
            // short other name with a common word in it ("New Haven", a city too) is left out.
            $generic = kop_clinks_generic_words();
            foreach ($later as $pair) {
                $key = kop_clinks_key($pair[0]);
                if ($key === '' || isset($dict['keys'][$key])) continue;
                $words = explode(' ', $key);
                if (count($words) <= 2 && array_intersect_key(array_flip($words), $generic)) continue;
                $add_key($pair[0], $pair[1]);
            }
        }
    }

    // Parent companies, under every name the company pages answer to.
    if (function_exists('kop_operator_pages_index') && isset($wpdb)) {
        $oindex = kop_operator_pages_index();
        $ops = $wpdb->prefix . 'kop_operators';
        if (!empty($oindex['ids']) && (!function_exists('kop_facility_pages_table_exists') || kop_facility_pages_table_exists($ops))) {
            $tids = array();
            foreach ($oindex['ids'] as $id => $e) {
                $url = kop_operator_pages_url_for_slug($e['slug']);
                $tids[$id] = $add_target('operator', $url, $e['display'], '');
            }
            foreach ((array) $wpdb->get_results("SELECT id, name, json_data FROM `{$ops}` ORDER BY id", ARRAY_A) as $r) {
                $id = (int) $r['id'];
                $lead = isset($oindex['alias_of'][$id]) ? (int) $oindex['alias_of'][$id] : $id;
                if (!isset($tids[$lead])) continue;
                $op = function_exists('kop_operator_pages_decode') ? kop_operator_pages_decode($r['json_data']) : (array) json_decode((string) $r['json_data'], true);
                $names = array($r['name'], kop_operator_pages_display_name($r['name']), kop_operator_pages_abbreviation($r['name']));
                foreach ((array) ($op['otherNames'] ?? array()) as $n) $names[] = $n;
                if (!empty($op['currentName'])) $names[] = $op['currentName'];
                foreach ($names as $n) if (is_string($n)) $add_key($n, $tids[$lead]);
            }
        }
    }

    // States with a page of their own (/utah/).
    if (function_exists('kop_state_us_states') && isset($wpdb)) {
        $slugs = array();
        foreach (kop_state_us_states() as $state) $slugs[kop_state_slug($state)] = $state;
        $in = "'" . implode("','", array_keys($slugs)) . "'";
        $have = (array) $wpdb->get_col("SELECT post_name FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_parent = 0 AND post_name IN ($in)");
        foreach ($slugs as $slug => $state) {
            if (!in_array($slug, $have, true)) continue;
            $add_key($state, $add_target('state', home_url('/' . $slug . '/'), $state, $state));
        }
    }

    // Glossary terms of two words or more ("Privilege Freeze"); a term tied to
    // one program (a qualifier) means something else elsewhere.
    if (isset($wpdb) && function_exists('kop_glossary_entry_url')) {
        $ge = $wpdb->prefix . 'kop_glossary_entries';
        $ga = $wpdb->prefix . 'kop_glossary_aliases';
        if (!function_exists('kop_facility_pages_table_exists') || kop_facility_pages_table_exists($ge)) {
            $aliases = array();
            if (!function_exists('kop_facility_pages_table_exists') || kop_facility_pages_table_exists($ga)) {
                foreach ((array) $wpdb->get_results("SELECT entry_id, alias FROM `{$ga}`", ARRAY_A) as $a) $aliases[(int) $a['entry_id']][] = $a['alias'];
            }
            foreach ((array) $wpdb->get_results("SELECT id, anchor, term, qualifier FROM `{$ge}` ORDER BY id", ARRAY_A) as $g) {
                if (trim((string) $g['qualifier']) !== '' || trim((string) $g['anchor']) === '') continue;
                $t = null;
                $terms = array_merge(preg_split('#\s+/\s+#', (string) $g['term']), $aliases[(int) $g['id']] ?? array());
                foreach ($terms as $term) {
                    $key = kop_clinks_key($term);
                    if (substr_count($key, ' ') < 1 || isset($dict['keys'][$key])) continue;
                    if ($t === null) $t = $add_target('glossary', kop_glossary_entry_url($g['anchor']), trim((string) $g['term']), '');
                    $add_key($term, $t);
                }
            }
        }
    }
    return $dict;
}

// ---------------------------------------------------------------------------
// Linking
// ---------------------------------------------------------------------------

/** Text tokens of a string: [[text, byte offset], ...]. "St.", "D.C.", "Mary's" stay whole. */
function kop_clinks_tokens($text) {
    preg_match_all("/[\\p{L}\\p{N}]+(?:['\x{2019}.&\\-][\\p{L}\\p{N}]+)*\\.?/u", $text, $m, PREG_OFFSET_CAPTURE);
    return $m[0];
}

function kop_clinks_is_upper_start($tok) {
    return (bool) preg_match('/^[\p{Lu}\p{N}]/u', $tok);
}

/** Does this token close a sentence ("Utah." but not "St." or "Inc.")? */
function kop_clinks_ends_sentence($tok) {
    if (substr($tok, -1) !== '.') return false;
    $bare = strtolower(rtrim($tok, '.'));
    if (isset(kop_clinks_abbrevs()[$bare])) return false;
    if (preg_match('/^\p{Lu}$/u', rtrim($tok, '.'))) return false; // "John T. Smith"
    if (strpos(rtrim($tok, '.'), '.') !== false) return false;      // "U.S."
    return true;
}

/**
 * Link the first mention of each record in $html. $opts: self (URLs not to
 * link: the page's own), max (links at most, default 30), kinds (which kinds,
 * default all), context (extra plain text that counts as "the text names the
 * state", e.g. the page's title). Returns {html, found: [url => [kind, url, label]]}
 * where found lists every record named, linked or not (bar the page's own).
 */
function kop_content_links_apply($html, array $opts = array()) {
    $html = (string) $html;
    $out = array('html' => $html, 'found' => array());
    if (trim($html) === '') return $out;
    $dict = kop_clinks_dict();
    if (empty($dict['keys'])) return $out;

    $self = array();
    foreach ((array) ($opts['self'] ?? array()) as $u) if ($u) $self[untrailingslashit(strtolower(strtok((string) $u, '#')))] = true;
    $max = isset($opts['max']) ? (int) $opts['max'] : 30;
    $kinds = isset($opts['kinds']) ? array_flip((array) $opts['kinds']) : null;
    $already = array();
    foreach ((array) ($opts['linked'] ?? array()) as $u) $already[(string) $u] = true;

    // Already-linked addresses in the text count as linked.
    if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $hm)) {
        foreach ($hm[1] as $h) $already[untrailingslashit(html_entity_decode($h, ENT_QUOTES, 'UTF-8'))] = true;
    }

    $plain = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8') . ' ' . (string) ($opts['context'] ?? '');
    $seen = array();
    $place_named = function ($place) use ($plain, &$seen) {
        $k = (string) $place;
        if (!isset($seen[$k])) {
            $ok = $place !== '' && preg_match('/(?<![\p{L}])' . preg_quote($place, '/') . '(?![\p{L}])/u', $plain);
            if (!$ok && $place !== '' && function_exists('kop_state_abbrev_to_name')) {
                $code = array_search($place, kop_state_abbrev_to_name(), true);
                if ($code) $ok = (bool) preg_match('/,\s*' . preg_quote($code, '/') . '\b/u', $plain);
            }
            $seen[$k] = (bool) $ok;
        }
        return $seen[$k];
    };

    $parts = preg_split('/(<!--.*?-->|<[^>]*>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $skip_tags = array_flip(array('a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'textarea', 'select', 'option',
        'button', 'label', 'code', 'pre', 'form', 'nav', 'svg', 'iframe', 'noscript', 'title', 'cite', 'figcaption', 'dt'));
    $depth = array();
    $links = 0;
    $small = kop_clinks_small_words();
    $generic = kop_clinks_generic_words();

    foreach ($parts as $pi => $part) {
        if ($part === '') continue;
        if ($part[0] === '<') {
            if (preg_match('#^<(/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(/?)>$#s', $part, $tm)) {
                $tag = strtolower($tm[2]);
                if (isset($skip_tags[$tag]) && $tm[3] !== '/') {
                    if ($tm[1] === '/') { if (!empty($depth[$tag])) $depth[$tag]--; }
                    else $depth[$tag] = ($depth[$tag] ?? 0) + 1;
                }
                // A box made by this file, or a pencil-edited source, stays as it is.
                if ($tm[1] === '' && strpos($part, 'kop-related-links') !== false) $depth['_box'] = 1;
            }
            continue;
        }
        if (array_sum($depth) > 0) continue;

        $text = html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $toks = kop_clinks_tokens($text);
        $n = count($toks);
        if ($n === 0) continue;
        $gap = function ($a, $b) use ($toks, $text) { // text between token a and b (b = a + 1)
            $end = $toks[$a][1] + strlen($toks[$a][0]);
            return substr($text, $end, $toks[$b][1] - $end);
        };
        $joined = function ($a, $b) use ($toks, $gap) { // a and a+1 are one phrase: spaces only, no sentence end
            return preg_match('/^[\s\x{00A0}]+$/u', $gap($a, $b)) && !kop_clinks_ends_sentence($toks[$a][0]);
        };
        $sentence_start = function ($i) use ($toks, $gap) {
            if ($i === 0) return true;
            if (kop_clinks_ends_sentence($toks[$i - 1][0])) return true;
            return (bool) preg_match('/[.!?:;"\x{201C}(\[\x{2014}]\s*$/u', $gap($i - 1, $i));
        };

        $tk = array();
        foreach ($toks as $k => $tok) $tk[$k] = kop_clinks_key($tok[0]);
        $run = array(); // how many tokens from k on are one phrase
        for ($k = $n - 1; $k >= 0; $k--) $run[$k] = ($k < $n - 1 && $joined($k, $k + 1)) ? $run[$k + 1] + 1 : 1;

        $matches = array();
        $i = 0;
        while ($i < $n) {
            $hit = null;
            $span = 1;
            $first = $tk[$i] === '' ? '' : strtok($tk[$i], ' ');
            if ($first === '' || !isset($dict['first'][$first])) { $i++; continue; }
            for ($len = min($dict['first'][$first], $run[$i]); $len >= 1; $len--) {
                $key = implode(' ', array_filter(array_slice($tk, $i, $len), 'strlen'));
                $words = array();
                for ($k = $i; $k < $i + $len; $k++) $words[] = $toks[$k][0];
                if ($key === '' || !isset($dict['keys'][$key])) continue;
                $span = $len;
                $hit = kop_clinks_pick($dict, $dict['keys'][$key], $words, $i, $toks, $joined, $sentence_start, $place_named, $small, $generic);
                break;
            }
            if ($hit !== null && ($kinds === null || isset($kinds[$dict['targets'][$hit][0]]))) {
                list($kind, $url, $label) = $dict['targets'][$hit];
                $u = untrailingslashit($url);
                if (!isset($self[strtolower(untrailingslashit(strtok($url, '#')))])) {
                    $out['found'][$url] = array($kind, $url, $label);
                    if (!isset($already[$u]) && $links < $max) {
                        $already[$u] = true;
                        $links++;
                        $last = $toks[$i + $span - 1];
                        $end = $last[1] + strlen(rtrim($last[0], '.'));
                        if (substr($last[0], -1) === '.' && !kop_clinks_ends_sentence($last[0])) $end = $last[1] + strlen($last[0]);
                        $matches[] = array($toks[$i][1], $end, $url, $kind);
                    }
                }
            }
            $i += $span;
        }
        if (!$matches) continue;
        $new = '';
        $pos = 0;
        foreach ($matches as $mt) {
            $new .= htmlspecialchars(substr($text, $pos, $mt[0] - $pos), ENT_NOQUOTES, 'UTF-8');
            $new .= '<a class="kop-auto-link kop-auto-link-' . $mt[3] . '" href="' . esc_url($mt[2]) . '">'
                . htmlspecialchars(substr($text, $mt[0], $mt[1] - $mt[0]), ENT_NOQUOTES, 'UTF-8') . '</a>';
            $pos = $mt[1];
        }
        $new .= htmlspecialchars(substr($text, $pos), ENT_NOQUOTES, 'UTF-8');
        $parts[$pi] = $new;
    }
    $out['html'] = implode('', $parts);
    return $out;
}

/**
 * Which target, if any, the words at token $i name. Facilities first, then
 * companies, states and glossary terms; null when the rules above say no.
 */
function kop_clinks_pick(array $dict, array $cands, array $words, $i, array $toks, $joined, $sentence_start, $place_named, array $small, array $generic) {
    $len = count($words);
    $lower = array();
    foreach ($words as $w) $lower[] = kop_clinks_key($w);
    $all_generic = true;
    $capitalised = true;
    foreach ($words as $wi => $w) {
        $lk = $lower[$wi];
        if (!isset($generic[$lk]) && !isset($small[$lk])) $all_generic = false;
        if ($wi > 0 && isset($small[$lk])) continue;
        if (!kop_clinks_is_upper_start($w)) $capitalised = false;
    }
    $n = count($toks);
    // Part of a longer name: a capitalised word right before (not opening the
    // sentence), "X of" before, or a capitalised word right after.
    $inside_longer = false;
    if ($i > 0 && $joined($i - 1, $i)) {
        $prev = $toks[$i - 1][0];
        $pk = kop_clinks_key($prev);
        if (kop_clinks_is_upper_start($prev) && !isset($small[$pk]) && !($sentence_start($i - 1) && isset(kop_clinks_openers()[$pk]))) $inside_longer = true;
        if (isset($small[$pk]) && $i > 1 && $joined($i - 2, $i - 1) && kop_clinks_is_upper_start($toks[$i - 2][0]) && !$sentence_start($i - 2)) $inside_longer = true;
    }
    $after = $i + $len;
    if ($after < $n && $joined($after - 1, $after) && kop_clinks_is_upper_start($toks[$after][0])) $inside_longer = true;

    $by_kind = array();
    foreach ($cands as $t) $by_kind[$dict['targets'][$t][0]][] = $t;

    // A facility record carrying a company's name ("Embark Behavioral Health", "... Inc.") is the company.
    $company_words = '/\b(group|health|healthcare|services|solutions|companies|corporation|corp|inc|llc|education|network|partners|holdings|ministries)\b/';
    if (!empty($by_kind['facility']) && preg_match($company_words, implode(' ', $lower))) {
        if (empty($by_kind['operator'])) {
            $bare = preg_replace('/ (inc|llc|corp|corporation|co|ltd)$/', '', implode(' ', array_filter($lower, 'strlen')));
            foreach ($dict['keys'][$bare] ?? array() as $t) {
                if ($dict['targets'][$t][0] === 'operator') $by_kind['operator'][] = $t;
            }
        }
        if (!empty($by_kind['operator'])) unset($by_kind['facility']);
    }
    if (!empty($by_kind['facility']) && $capitalised && $len >= 2 && !$all_generic) {
        $urls = array();
        foreach ($by_kind['facility'] as $t) $urls[$dict['targets'][$t][1]][] = $t;
        if (count($urls) === 1 && $len >= 3) return $by_kind['facility'][0];
        if (!($len <= 2 && $inside_longer)) {
            $named = array();
            foreach ($urls as $u => $ts) {
                if ($place_named($dict['targets'][$ts[0]][3])) $named[$u] = $ts[0];
            }
            if (count($named) === 1) return reset($named);
        }
    }
    if (!empty($by_kind['operator']) && $capitalised && !$all_generic) {
        $one_word_abbr = $len === 1 && preg_match('/^\p{Lu}[\p{Lu}\p{N}&]{2,}$/u', rtrim($words[0], '.'));
        if (($len >= 2 || $one_word_abbr) && !($len <= 2 && $inside_longer)) return $by_kind['operator'][0];
    }
    if (!empty($by_kind['state']) && $capitalised && !$inside_longer) {
        $t = $by_kind['state'][0];
        $next = $toks[$i + $len] ?? null;
        // Washington, D.C. is not the state, nor is "a Delaware corporation" about Delaware.
        $dc = $dict['targets'][$t][2] === 'Washington' && $next && preg_match('/^D\.?C\b/u', $next[0]);
        $registered = $next && preg_match('/^(corporation|corp|company|entity|entities|llc|nonprofit|non-profit|limited|partnership|statutory|business|charter|incorporation|incorporated)\b/iu', $next[0]);
        if (!$dc && !$registered) return $t;
    }
    if (!empty($by_kind['glossary']) && $len >= 2 && $capitalised && !$inside_longer) {
        return $by_kind['glossary'][0];
    }
    return null;
}

/** Just the linked HTML, for templates: the facility and company pages' prose. */
function kop_content_links_html($html, $self_url = '', $context = '') {
    if (!function_exists('kop_clinks_dict')) return $html;
    $res = kop_content_links_apply($html, array('self' => (array) $self_url, 'context' => $context));
    return $res['html'];
}

// ---------------------------------------------------------------------------
// "Related on Kids Over Profits"
// ---------------------------------------------------------------------------

/** The box listing the record pages a text names; '' when it names none. */
function kop_content_links_related_html(array $found, $title = 'Related on Kids Over Profits') {
    $groups = array('facility' => 'Facilities', 'operator' => 'Parent companies', 'state' => 'States', 'glossary' => 'Glossary');
    $by = array();
    foreach ($found as $f) {
        if (isset($groups[$f[0]])) $by[$f[0]][$f[1]] = $f[2];
    }
    if (!$by) return '';
    $html = '<aside class="kop-related-links" aria-label="' . esc_attr($title) . '"><p class="kop-related-links-title">' . esc_html($title) . '</p>';
    foreach ($groups as $kind => $label) {
        if (empty($by[$kind])) continue;
        asort($by[$kind], SORT_NATURAL | SORT_FLAG_CASE);
        $items = array();
        foreach (array_slice($by[$kind], 0, 15, true) as $url => $name) {
            $items[] = '<a href="' . esc_url($url) . '">' . esc_html($name) . '</a>';
        }
        $html .= '<p class="kop-related-links-group"><span class="kop-related-links-label">' . esc_html($label) . ':</span> ' . implode(', ', $items) . '</p>';
    }
    return $html . '</aside>';
}

// ---------------------------------------------------------------------------
// Where it runs
// ---------------------------------------------------------------------------

/**
 * Posts and pages: the text links its first mentions; a post also gets the
 * box. Runs after shortcodes and wpautop (11) so their markup is in place.
 */
function kop_content_links_the_content($content) {
    if (is_admin() || is_feed() || !in_the_loop() || !is_main_query()) return $content;
    if (!is_singular(array('post', 'page'))) return $content;
    $post_id = get_the_ID();
    if (!$post_id || post_password_required($post_id)) return $content;
    if (apply_filters('kop_content_links_skip', false, $post_id)) return $content;
    $self = array(get_permalink($post_id));
    $res = kop_content_links_apply($content, array('self' => $self, 'context' => get_the_title($post_id)));
    $content = $res['html'];
    if (is_singular('post')) $content .= kop_content_links_related_html($res['found']);
    return $content;
}
add_filter('the_content', 'kop_content_links_the_content', 12);

/** The box's look, on posts and the wiki feed. */
function kop_content_links_enqueue() {
    $css = get_stylesheet_directory() . '/css/content-links.css';
    if (!file_exists($css)) return;
    if (!is_singular('post') && !is_page_template('templates/page-wiki-feed.php')) return;
    wp_enqueue_style('kop-content-links', get_stylesheet_directory_uri() . '/css/content-links.css', array('kop-colors'), filemtime($css));
}
add_action('wp_enqueue_scripts', 'kop_content_links_enqueue', 30);

// ---------------------------------------------------------------------------
// Wiki feed cards (templates/page-wiki-feed.php)
// ---------------------------------------------------------------------------

/**
 * For one wiki entry: {title_url: its facility's page (the entry's linked
 * record, else its name), org_url: its company's page, box: the record pages
 * its text names}. The entry's markdown renders in the browser, so the links
 * are a box under it rather than inside it.
 */
function kop_content_links_wiki_card(array $item, $markdown) {
    global $wpdb;
    $out = array('title_url' => '', 'org_url' => '', 'box' => '');
    $uname = trim((string) ($item['facility_unique_name'] ?? ''));
    if ($uname !== '' && isset($wpdb) && function_exists('kop_facility_page_url')) {
        $id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM facilities_v2 WHERE unique_name = %s LIMIT 1', $uname));
        if ($id > 0) $out['title_url'] = kop_facility_page_url($id);
        if ($out['title_url'] === '' && function_exists('kop_operator_page_url_for_name')) $out['title_url'] = kop_operator_page_url_for_name($uname);
    }
    if ($out['title_url'] === '' && function_exists('kop_facility_page_url_for_name')) {
        $out['title_url'] = kop_facility_page_url_for_name((string) ($item['program_name'] ?? ''));
    }
    $org = trim((string) ($item['organization'] ?? ''));
    if ($org !== '' && function_exists('kop_operator_page_url_for_name')) $out['org_url'] = kop_operator_page_url_for_name($org);

    // Markdown to plain paragraphs: link targets, emphasis and headings out.
    $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', (string) $markdown);
    $text = preg_replace('/^#{1,6}\s*/mu', '', $text);
    $text = str_replace(array('**', '__', '*'), '', $text);
    $html = '<p>' . implode('</p><p>', array_map('esc_html', preg_split('/\n\s*\n/u', $text))) . '</p>';
    $res = kop_content_links_apply($html, array(
        'self'    => array_filter(array($out['title_url'], $out['org_url'])),
        'context' => (string) ($item['program_name'] ?? '') . ' ' . (string) ($item['city_state'] ?? ''),
        'max'     => 0,
    ));
    $out['box'] = kop_content_links_related_html($res['found'], 'Also on Kids Over Profits');
    return $out;
}
