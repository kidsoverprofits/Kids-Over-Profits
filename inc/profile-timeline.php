<?php
/**
 * Year-by-year timelines on company (/operator/) and facility (/facility/)
 * pages: what happened, in order, with the context a reader needs to follow
 * it, and a card with more on each event (on hover or focus with a mouse, a
 * tap on a phone; printed in place when scripts do not run).
 *
 * What goes on a timeline:
 *   - ownership: the year a company was founded, the companies it bought and
 *     sold, the programs it took over from another company (the network map's
 *     "operated 2009-2021" lines: a program one company stopped running the
 *     year another began is a takeover, named as such), its investors' stakes
 *     (inc/investors.php, when the link has years); on a facility page the
 *     companies that ran it and the deals that moved it from one to the next.
 *   - programs: on a company page the openings and closings of its programs
 *     inside the years it is known to have run them; nothing from before it
 *     took a program over or after it let go.
 *   - leadership: a chief executive, president, executive director, chair or
 *     owner of the company starting or leaving, from past jobs
 *     ({prefix}kop_person_jobs) and the map's leadership lines, only where the
 *     years are a span ("2010-2015"): one year alone is when a source saw them.
 *   - company milestones (seeds/company-milestones.json): buyouts, private
 *     equity owners, investigations, settlements, mass closures, each with its
 *     sources. They wait at Review inbox > Company milestones
 *     (inc/review-inbox/company-milestones.php) and appear only once approved;
 *     an admin can add more there (option kop_company_milestones_added).
 *   - what the records hold: lawsuits (with outcome and settlement), deaths
 *     (with cause), approved serious findings by state inspectors, critical
 *     incidents (a riot, an escape, a death: the programs' criticalIncidents,
 *     on company pages too), renames, and the press: story arcs, a story
 *     several outlets covered, Essential Reporting picks, each with the
 *     article's picture.
 * A program's events count on its company's timeline only inside the years
 * the company is known to have run it (a death at Provo Canyon School in 1990
 * is not UHS history): the map's "operated" years, else the program's own list
 * of operators with years (kop_ptl_run_years()), which also dates its sale or
 * transfer to the next one.
 *
 * Shape: [{year, events: [event], text}] oldest first; an event is
 *   {kind, group, year, date, when, title, lead, items: [{name, url, meta}],
 *    detail: [paragraphs], facts: [[label, value]], image: {src, kind, alt},
 *    sources: [{url, cite}], more: {label, url}, major}
 * 'text' is the year in one line, for the mobile app.
 *
 * Guarded with function_exists so the offline tests can load it next to
 * WordPress stubs.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ---------------------------------------------------------------------------
// Kinds and filters
// ---------------------------------------------------------------------------

if (!function_exists('kop_ptl_kinds')) {
    /** kind => [icon, filter group, tag shown on the event]. */
    function kop_ptl_kinds() {
        return array(
            'founded'       => array('building', 'company', 'Founded'),
            'deal'          => array('shuffle', 'company', 'Ownership'),
            'takeover'      => array('shuffle', 'company', 'Ownership'),
            'handover'      => array('shuffle', 'company', 'Ownership'),
            'operator'      => array('building', 'company', 'Ownership'),
            'leadership'    => array('user', 'company', 'Leadership'),
            'rename'        => array('tag', 'company', 'New name'),
            'began'         => array('home', 'programs', 'Programs'),
            'opened'        => array('home', 'programs', 'Opened'),
            'ended'         => array('x-circle', 'programs', 'Programs'),
            'closed'        => array('x-circle', 'programs', 'Closed'),
            'investigation' => array('search', 'oversight', 'Investigation'),
            'finding'       => array('alert-triangle', 'oversight', 'Inspections'),
            'lawsuit'       => array('scale', 'legal', 'Lawsuit'),
            'settlement'    => array('scale', 'legal', 'Settlement'),
            'death'         => array('candle', 'harm', 'Death'),
            'incident'      => array('siren', 'harm', 'Incident'),
            'scandal'       => array('newspaper', 'press', 'In the news'),
            'story'         => array('newspaper', 'press', 'In the news'),
            'featured'      => array('star', 'press', 'Essential reporting'),
        );
    }
}

if (!function_exists('kop_ptl_groups')) {
    /** Filter buttons, in order. */
    function kop_ptl_groups() {
        return array(
            'company'   => 'Ownership and leadership',
            'programs'  => 'Programs',
            'harm'      => 'Deaths and incidents',
            'oversight' => 'Inspections and investigations',
            'legal'     => 'Lawsuits',
            'press'     => 'In the news',
        );
    }
}

if (!function_exists('kop_ptl_event')) {
    /** An event with every key set. */
    function kop_ptl_event($year, $kind, $title, array $more = array()) {
        $kinds = kop_ptl_kinds();
        $e = array_merge(array(
            'kind' => $kind, 'group' => $kinds[$kind][1] ?? 'programs', 'year' => (int) $year, 'date' => (string) (int) $year,
            'when' => '', 'title' => (string) $title, 'lead' => '', 'items' => array(), 'detail' => array(), 'facts' => array(),
            'image' => null, 'sources' => array(), 'more' => null, 'major' => false,
        ), $more);
        $e['detail'] = array_values(array_filter(array_map('trim', array_map('strval', (array) $e['detail'])), 'strlen'));
        return $e;
    }
}

if (!function_exists('kop_ptl_date')) {
    /** "2010-11-15" / "2010-11" / "2010" => [year, sortable date, label ("November 15, 2010", "November 2010", "")]. */
    function kop_ptl_date($s) {
        $s = trim((string) $s);
        if (!preg_match('/^(1[89]\d\d|20\d\d)(?:-(\d\d)(?:-(\d\d))?)?/', $s, $m)) {
            return preg_match('/\b(1[89]\d\d|20\d\d)\b/', $s, $y) ? array((int) $y[1], $y[1], '') : array(0, '', '');
        }
        $y = (int) $m[1];
        $mo = isset($m[2]) ? (int) $m[2] : 0;
        $d = isset($m[3]) ? (int) $m[3] : 0;
        if ($mo < 1 || $mo > 12) return array($y, (string) $y, '');
        $month = date('F', mktime(0, 0, 0, $mo, 1, 2000));
        if ($d < 1) return array($y, sprintf('%04d-%02d', $y, $mo), $month . ' ' . $y);
        return array($y, sprintf('%04d-%02d-%02d', $y, $mo, $d), $month . ' ' . $d . ', ' . $y);
    }
}

if (!function_exists('kop_ptl_list_words')) {
    /** "A", "A and B", "A, B and C", "A, B, C and 4 more". */
    function kop_ptl_list_words(array $names, $max = 3) {
        $names = array_values(array_unique(array_filter(array_map('strval', $names), 'strlen')));
        $n = count($names);
        if ($n === 0) return '';
        if ($n === 1) return $names[0];
        if ($n <= $max) return implode(', ', array_slice($names, 0, -1)) . ' and ' . $names[$n - 1];
        return implode(', ', array_slice($names, 0, $max)) . ' and ' . ($n - $max) . ' more';
    }
}

if (!function_exists('kop_ptl_short')) {
    /** Text cut at a sentence or word near $max characters. */
    function kop_ptl_short($text, $max = 320) {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        if (mb_strlen($text) <= $max) return $text;
        $cut = mb_substr($text, 0, $max);
        $dot = max(mb_strrpos($cut, '. ') ?: 0, 0);
        if ($dot > $max * 0.5) return mb_substr($cut, 0, $dot + 1);
        $sp = mb_strrpos($cut, ' ');
        return rtrim(mb_substr($cut, 0, $sp ?: $max), " ,;:") . '...';
    }
}

// ---------------------------------------------------------------------------
// Company milestones (seeds/company-milestones.json + review decisions)
// ---------------------------------------------------------------------------

if (!function_exists('kop_ptl_ms_file')) {
    function kop_ptl_ms_file() {
        return get_stylesheet_directory() . '/seeds/company-milestones.json';
    }
}

if (!function_exists('kop_ptl_ms_seed')) {
    /** key => milestone, as the file has it. */
    function kop_ptl_ms_seed($reset = false) {
        static $memo = null;
        if ($reset) $memo = null;
        if ($memo !== null) return $memo;
        $memo = array();
        $file = kop_ptl_ms_file();
        $data = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;
        foreach ((array) ($data['milestones'] ?? array()) as $m) {
            if (!is_array($m) || empty($m['key']) || empty($m['company']) || empty($m['year']) || trim((string) ($m['title'] ?? '')) === '') continue;
            $memo[(string) $m['key']] = $m;
        }
        return $memo;
    }
}

if (!function_exists('kop_ptl_ms_added')) {
    /** key => milestone an admin added at Review inbox > Company milestones (option kop_company_milestones_added; keys "added-..."). */
    function kop_ptl_ms_added() {
        $saved = function_exists('get_option') ? get_option('kop_company_milestones_added', array()) : array();
        $out = array();
        foreach ((array) $saved as $key => $m) {
            if (is_array($m) && !empty($m['company']) && !empty($m['year']) && trim((string) ($m['title'] ?? '')) !== '') $out[(string) $key] = array('key' => (string) $key) + $m;
        }
        return $out;
    }
}

if (!function_exists('kop_ptl_ms_all')) {
    /** key => milestone, the file's and the admins' (the reviewer's wording not applied). */
    function kop_ptl_ms_all($reset = false) {
        return kop_ptl_ms_seed($reset) + kop_ptl_ms_added();
    }
}

if (!function_exists('kop_ptl_ms_edit_fields')) {
    /** The fields a reviewer's edit can change. */
    function kop_ptl_ms_edit_fields() {
        return array('company', 'kind', 'title', 'sentence', 'detail', 'date', 'also', 'programs', 'sources');
    }
}

if (!function_exists('kop_ptl_ms_decisions')) {
    /** key => {decision: approved|rejected, by, at}. */
    function kop_ptl_ms_decisions() {
        $saved = function_exists('get_option') ? get_option('kop_company_milestones_review', array()) : array();
        return is_array($saved) ? $saved : array();
    }
}

if (!function_exists('kop_ptl_ms_edits')) {
    /** key => {title, sentence, detail, date, company, kind, also, programs, sources}: the reviewer's changes. */
    function kop_ptl_ms_edits() {
        $saved = function_exists('get_option') ? get_option('kop_company_milestones_edits', array()) : array();
        return is_array($saved) ? $saved : array();
    }
}

if (!function_exists('kop_ptl_ms_get')) {
    /** One milestone with the reviewer's wording, or null. */
    function kop_ptl_ms_get($key) {
        $m = kop_ptl_ms_all()[(string) $key] ?? null;
        if (!$m) return null;
        foreach ((array) (kop_ptl_ms_edits()[(string) $key] ?? array()) as $f => $v) {
            if (!in_array($f, kop_ptl_ms_edit_fields(), true)) continue;
            $m[$f] = is_array($v) ? $v : (string) $v;
        }
        if (!empty($m['date'])) {
            $d = kop_ptl_date($m['date']);
            if ($d[0]) $m['year'] = $d[0];
        }
        $m['year'] = (int) $m['year'];
        return $m;
    }
}

if (!function_exists('kop_ptl_ms_approved')) {
    /** The approved milestones, with the reviewer's wording. */
    function kop_ptl_ms_approved() {
        $out = array();
        foreach (kop_ptl_ms_decisions() as $key => $d) {
            if (($d['decision'] ?? '') !== 'approved') continue;
            $m = kop_ptl_ms_get($key);
            if ($m) $out[(string) $key] = $m;
        }
        return $out;
    }
}

if (!function_exists('kop_ptl_company_key')) {
    /** One key per company page: its /operator/ address, else the name key. */
    function kop_ptl_company_key($name) {
        static $memo = array();
        $name = trim((string) $name);
        if ($name === '') return '';
        if (isset($memo[$name])) return $memo[$name];
        $url = function_exists('kop_operator_page_url_for_name') ? (string) kop_operator_page_url_for_name($name) : '';
        return $memo[$name] = $url !== '' ? 'url:' . $url : 'name:' . kop_facility_pages_name_key($name);
    }
}

if (!function_exists('kop_ptl_ms_companies')) {
    /** Company keys a milestone belongs to: [key => 'main'|'also']. */
    function kop_ptl_ms_companies(array $m) {
        $out = array();
        $k = kop_ptl_company_key($m['company']);
        if ($k !== '') $out[$k] = 'main';
        foreach ((array) ($m['also'] ?? array()) as $a) {
            $k = kop_ptl_company_key($a);
            if ($k !== '' && !isset($out[$k])) $out[$k] = 'also';
        }
        return $out;
    }
}

if (!function_exists('kop_ptl_ms_event')) {
    /**
     * A milestone as an event. $role 'main' reads it from the company's side
     * ("Bought Psychiatric Solutions"); anywhere else it is the whole
     * sentence ("Universal Health Services bought Psychiatric Solutions").
     */
    function kop_ptl_ms_event(array $m, $role, $lead = '') {
        $kind = (string) ($m['kind'] ?? 'deal');
        if ($kind === 'founded' || $kind === 'rename' || $kind === 'closure') $kind = $kind === 'closure' ? 'closed' : $kind;
        if (!isset(kop_ptl_kinds()[$kind])) $kind = 'deal';
        $title = $role === 'main' ? (string) $m['title'] : (string) (($m['sentence'] ?? '') !== '' ? $m['sentence'] : $m['company'] . ': ' . $m['title']);
        list(, $sort, $label) = kop_ptl_date($m['date'] ?? '');
        $sources = array();
        foreach ((array) ($m['sources'] ?? array()) as $s) {
            if (!empty($s['url'])) $sources[] = array('url' => (string) $s['url'], 'cite' => (string) ($s['label'] ?? ''));
        }
        $items = array();
        foreach ((array) ($m['programs'] ?? array()) as $p) {
            $p = trim((string) $p);
            if ($p === '') continue;
            $url = function_exists('kop_facility_page_url_for_name') ? (string) kop_facility_page_url_for_name($p) : '';
            $items[] = array('name' => $p, 'url' => $url, 'meta' => '');
        }
        return kop_ptl_event((int) $m['year'], $kind, $title, array(
            'date'    => $sort !== '' ? $sort : (string) (int) $m['year'],
            'when'    => $label,
            'lead'    => $lead,
            'detail'  => preg_split('/\n\s*\n/', trim((string) ($m['detail'] ?? ''))),
            'items'   => $items,
            'sources' => $sources,
            'major'   => in_array($kind, array('deal', 'scandal', 'investigation', 'settlement', 'death'), true),
            'ms'      => (string) $m['key'],
        ));
    }
}

// ---------------------------------------------------------------------------
// The network map: who ran what, and when
// ---------------------------------------------------------------------------

if (!function_exists('kop_ptl_graph')) {
    /** {nodes: id => node, adj: id => [edge...], by_fid: facility id => [node id]} or null. */
    function kop_ptl_graph() {
        static $memo = false;
        if ($memo !== false) return $memo;
        $g = function_exists('kop_network_map_graph') ? kop_network_map_graph() : null;
        if (!$g) return $memo = null;
        $out = array('nodes' => array(), 'adj' => array(), 'by_fid' => array());
        foreach ($g['nodes'] as $n) {
            $out['nodes'][$n['id']] = $n;
            if (!empty($n['facilityId'])) $out['by_fid'][(int) $n['facilityId']][] = $n['id'];
        }
        foreach ($g['edges'] as $e) {
            $out['adj'][$e['source']][] = $e;
            $out['adj'][$e['target']][] = $e;
        }
        return $memo = $out;
    }
}

if (!function_exists('kop_ptl_is_company')) {
    function kop_ptl_is_company(array $node) {
        return in_array($node['kind'] ?? '', array('parent', 'church', 'other', 'government', 'association'), true);
    }
}

if (!function_exists('kop_ptl_edge_role')) {
    function kop_ptl_edge_role(array $e) {
        $role = trim(str_replace(array("\xEF\xBF\xBD", "\xE2\x80\x93", "\xE2\x80\x94"), '-', implode(', ', (array) ($e['roles'] ?? array()))));
        return preg_replace('/\s*-\s*/', '-', $role);
    }
}

if (!function_exists('kop_ptl_runners')) {
    /**
     * The companies the map says ran a facility node: [{node, from, to, acquired, role}]
     * (from/to 0 when the line gives no year).
     */
    function kop_ptl_runners($facility_node_id) {
        $g = kop_ptl_graph();
        if (!$g) return array();
        $out = array();
        foreach ($g['adj'][$facility_node_id] ?? array() as $e) {
            if (($e['category'] ?? '') !== 'corporate' || ($e['direction'] ?? '') === 'renamed') continue;
            $other = $e['source'] === $facility_node_id ? $e['target'] : $e['source'];
            $node = $g['nodes'][$other] ?? null;
            if (!$node || !kop_ptl_is_company($node)) continue;
            list($from, $to) = function_exists('kop_operator_history_years') ? kop_operator_history_years(kop_ptl_edge_role($e)) : array(0, 0);
            $out[] = array('node' => $node, 'from' => $from, 'to' => $to, 'acquired' => ($e['direction'] ?? '') === 'acquirer' && $e['source'] === $other, 'role' => kop_ptl_edge_role($e));
        }
        return $out;
    }
}

if (!function_exists('kop_ptl_company_deals')) {
    /**
     * Company-to-company lines of a company node: [{node, as: bought|bought-by|owner|owned-by, year}].
     * 'owner' lines run from the company owned to its owner on the board ("CRC Health Group -> Bain Capital: owner").
     */
    function kop_ptl_company_deals($node_id) {
        $g = kop_ptl_graph();
        if (!$g || $node_id === '') return array();
        $out = array();
        foreach ($g['adj'][$node_id] ?? array() as $e) {
            if (($e['category'] ?? '') !== 'corporate') continue;
            $other = $e['source'] === $node_id ? $e['target'] : $e['source'];
            $node = $g['nodes'][$other] ?? null;
            if (!$node || ($node['kind'] ?? '') !== 'parent') continue;
            $role = kop_ptl_edge_role($e);
            $year = function_exists('kop_operator_history_years') ? kop_operator_history_years($role)[0] : 0;
            $mine = $e['source'] === $node_id;
            if (($e['direction'] ?? '') === 'acquirer' || preg_match('/\bacquired\b|\bbought\b/i', $role)) {
                $as = $mine ? 'bought' : 'bought-by';
            } elseif (preg_match('/\bowner\b|parent company|majority owner|investor/i', $role)) {
                // "X -> Y: owner" on the board reads "Y owns X", except "parent company" lines drawn from the parent.
                $as = preg_match('/parent company/i', $role) ? ($mine ? 'owner' : 'owned-by') : ($mine ? 'owned-by' : 'owner');
            } else {
                continue;
            }
            $out[] = array('node' => $node, 'as' => $as, 'year' => $year, 'role' => $role);
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// Records: the press, findings
// ---------------------------------------------------------------------------

if (!function_exists('kop_ptl_news_meta')) {
    /** news id => {group, arc, arc_title, arc_text, arc_facility} for the given ids. */
    function kop_ptl_news_meta(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids || !isset($wpdb) || !kop_facility_pages_table_exists('news_submissions')) return array();
        $out = array();
        $rows = $wpdb->get_results('SELECT id, story_group_id, story_arc_id FROM news_submissions WHERE id IN (' . implode(',', $ids) . ')', ARRAY_A);
        $arcs = array();
        foreach ((array) $rows as $r) {
            $out[(int) $r['id']] = array('group' => (string) ($r['story_group_id'] ?? ''), 'arc' => (int) ($r['story_arc_id'] ?? 0), 'arc_title' => '', 'arc_text' => '', 'arc_facility' => '');
            if ((int) ($r['story_arc_id'] ?? 0) > 0) $arcs[(int) $r['story_arc_id']] = true;
        }
        if ($arcs && kop_facility_pages_table_exists('news_story_arcs')) {
            $arows = $wpdb->get_results("SELECT id, title, description, facility_label FROM news_story_arcs WHERE status = 'active' AND id IN (" . implode(',', array_keys($arcs)) . ')', ARRAY_A);
            $by = array();
            foreach ((array) $arows as $a) $by[(int) $a['id']] = $a;
            foreach ($out as &$m) {
                if ($m['arc'] && isset($by[$m['arc']])) {
                    $m['arc_title'] = trim((string) $by[$m['arc']]['title']);
                    $m['arc_text'] = trim((string) $by[$m['arc']]['description']);
                    $m['arc_facility'] = trim((string) ($by[$m['arc']]['facility_label'] ?? ''));
                } else {
                    $m['arc'] = 0;
                }
            }
            unset($m);
        } else {
            foreach ($out as &$m) $m['arc'] = 0;
            unset($m);
        }
        return $out;
    }
}

if (!function_exists('kop_ptl_press_events')) {
    /**
     * The press as events: a story arc (the newsroom's named stories), a story
     * three or more outlets covered, an Essential Reporting pick. Ordinary
     * single articles stay in the News section. $news: the page's news rows
     * (id, title, outlet, date, url, summary, image); $in_years(year, row, meta)
     * says whether an article counts here (meta: its kop_ptl_news_meta() row).
     */
    function kop_ptl_press_events(array $news, $in_years = null) {
        $news = array_values(array_filter($news, static function ($n) { return empty($n['aside']) && !empty($n['id']) && !empty($n['date']); }));
        if (!$news) return array();
        $meta = kop_ptl_news_meta(array_column($news, 'id'));
        $featured = function_exists('kop_feat_map') ? kop_feat_map() : array();
        usort($news, static function ($a, $b) { return strcmp((string) $a['date'], (string) $b['date']); });

        $item = static function (array $n) {
            return array('name' => (string) $n['title'], 'url' => (string) $n['url'], 'meta' => trim($n['outlet'] . ($n['date_label'] !== '' ? ', ' . $n['date_label'] : ''), ', '));
        };
        $photo = static function (array $rows) {
            foreach ($rows as $n) {
                if (!empty($n['image']['src']) && ($n['image']['kind'] ?? '') === 'photo') return array('src' => $n['image']['src'], 'kind' => 'photo', 'alt' => '');
            }
            foreach ($rows as $n) {
                if (!empty($n['image']['src'])) return array('src' => $n['image']['src'], 'kind' => 'logo', 'alt' => '');
            }
            return null;
        };

        $arcs = array();
        $groups = array();
        foreach ($news as $n) {
            $m = $meta[(int) $n['id']] ?? null;
            if ($m && $m['arc']) $arcs[$m['arc']][] = $n;
            elseif ($m && $m['group'] !== '') $groups[$m['group']][] = $n;
        }

        $events = array();
        $used = array();
        foreach ($arcs as $arc => $rows) {
            $m = $meta[(int) $rows[0]['id']];
            list($y, $sort, $label) = kop_ptl_date($rows[0]['date']);
            if (!$y || ($in_years && !$in_years($y, $rows[0], $m))) continue;
            $last = kop_ptl_date($rows[count($rows) - 1]['date'])[0];
            $outlets = array_values(array_unique(array_filter(array_column($rows, 'outlet'))));
            $detail = array();
            if ($m['arc_text'] !== '') $detail[] = $m['arc_text'];
            elseif (($rows[0]['summary'] ?? '') !== '') $detail[] = kop_ptl_short($rows[0]['summary']);
            $events[] = kop_ptl_event($y, 'scandal', $m['arc_title'], array(
                'date' => $sort, 'when' => $label,
                'lead' => count($rows) . ' ' . (count($rows) === 1 ? 'article' : 'articles') . ($outlets ? ' in ' . kop_ptl_list_words($outlets) : '') . ($last > $y ? ', ' . $y . ' to ' . $last : '') . '.',
                'items' => array_map($item, array_slice($rows, 0, 8)),
                'detail' => $detail,
                'image' => $photo($rows),
                'major' => true,
            ));
            foreach ($rows as $r) $used[(int) $r['id']] = true;
        }
        foreach ($groups as $rows) {
            $outlets = array_values(array_unique(array_filter(array_column($rows, 'outlet'))));
            if (count($outlets) < 3) continue;
            $lead_row = $rows[0];
            foreach ($rows as $r) {
                if (isset($featured[(int) $r['id']])) { $lead_row = $r; break; }
            }
            list($y, $sort, $label) = kop_ptl_date($rows[0]['date']);
            if (!$y || ($in_years && !$in_years($y, $rows[0], $meta[(int) $rows[0]['id']] ?? array()))) continue;
            $events[] = kop_ptl_event($y, 'story', (string) $lead_row['title'], array(
                'date' => $sort, 'when' => $label,
                'lead' => 'Covered by ' . count($outlets) . ' outlets: ' . kop_ptl_list_words($outlets) . '.',
                'items' => array_map($item, array_slice($rows, 0, 8)),
                'detail' => ($lead_row['summary'] ?? '') !== '' ? array(kop_ptl_short($lead_row['summary'])) : array(),
                'image' => $photo(array_merge(array($lead_row), $rows)),
                'major' => count($outlets) >= 5,
            ));
            foreach ($rows as $r) $used[(int) $r['id']] = true;
        }
        foreach ($news as $n) {
            if (isset($used[(int) $n['id']]) || !isset($featured[(int) $n['id']])) continue;
            list($y, $sort, $label) = kop_ptl_date($n['date']);
            if (!$y || ($in_years && !$in_years($y, $n, $meta[(int) $n['id']] ?? array()))) continue;
            $note = $featured[(int) $n['id']]['note'];
            $events[] = kop_ptl_event($y, 'featured', (string) $n['title'], array(
                'date' => $sort, 'when' => $label,
                'lead' => trim(($n['outlet'] !== '' ? $n['outlet'] : 'Article') . ($note !== '' ? ': ' . kop_ptl_short($note, 200) : '.')),
                'detail' => ($n['summary'] ?? '') !== '' ? array(kop_ptl_short($n['summary'])) : array(),
                'image' => $photo(array($n)),
                'more' => array('label' => 'Read the article', 'url' => (string) $n['url']),
            ));
        }
        return $events;
    }
}

if (!function_exists('kop_ptl_rollup')) {
    /** The inspection rollup (inc/inspection-rollup.php) from its file cache, never built here. */
    function kop_ptl_rollup() {
        static $memo = false;
        if ($memo !== false) return $memo;
        $memo = null;
        if (!function_exists('kop_irl_cache_dir') || !function_exists('kop_irl_version')) return $memo;
        $files = (array) glob(kop_irl_cache_dir() . '/rollup-*.json');
        if (!$files) return $memo;
        $data = json_decode((string) file_get_contents($files[0]), true);
        if (is_array($data) && ($data['version'] ?? 0) === kop_irl_version()) $memo = $data;
        return $memo;
    }
}

if (!function_exists('kop_ptl_findings_by_year')) {
    /** year => [facility id => [approved findings, [category => n]]] for the given records. */
    function kop_ptl_findings_by_year(array $fids) {
        $r = kop_ptl_rollup();
        if (!$r) return array();
        $out = array();
        foreach ($fids as $fid) {
            foreach ((array) ($r['records'][(int) $fid]['sites'] ?? array()) as $sid) {
                foreach ((array) ($r['sites'][$sid]['y'] ?? array()) as $y => $b) {
                    $n = (int) ($b['f'] ?? 0);
                    if ($n <= 0 || !$y) continue;
                    if (!isset($out[$y][$fid])) $out[$y][$fid] = array(0, array());
                    $out[$y][$fid][0] += $n;
                    foreach ($b as $k => $v) {
                        if (strpos((string) $k, 'f.') === 0) $out[$y][$fid][1][substr($k, 2)] = ($out[$y][$fid][1][substr($k, 2)] ?? 0) + (int) $v;
                    }
                }
            }
        }
        return $out;
    }
}

if (!function_exists('kop_ptl_finding_words')) {
    /** "3 staff assaults, 2 restraint injuries" from category counts. */
    function kop_ptl_finding_words(array $cats) {
        $labels = function_exists('kop_irl_finding_columns') ? kop_irl_finding_columns() : array();
        arsort($cats);
        $bits = array();
        foreach ($cats as $c => $n) {
            if (!isset($labels[$c])) continue;
            $bits[] = $n . ' ' . strtolower($n === 1 ? preg_replace('/s$/', '', $labels[$c]['label']) : $labels[$c]['label']);
        }
        return implode(', ', array_slice($bits, 0, 4));
    }
}

// ---------------------------------------------------------------------------
// Company pages
// ---------------------------------------------------------------------------

if (!function_exists('kop_ptl_operator')) {
    /**
     * The company's timeline. $ctx: founded, name, facilities (operator page
     * rows), links (map links, kop_operator_history_map_links()), map_node,
     * lawsuits, memorials, news, lawsuits_url, incidents ([facility_id,
     * incident line]), leaders ([name, url, role, years]), investors
     * ([name, url, role, start_year, end_year, detail, source_url]).
     */
    function kop_ptl_operator(array $ctx) {
        $now = (int) gmdate('Y');
        $founded = preg_match('/\b(1[89]\d\d|20\d\d)\b/', (string) ($ctx['founded'] ?? ''), $fm) ? (int) $fm[1] : 0;
        $events = array();
        $add = static function (array $e) use (&$events, $founded, $now) {
            if ($e['year'] < 1800 || $e['year'] > $now + 1) return;
            if ($founded && $e['year'] < $founded && !in_array($e['kind'], array('deal', 'rename'), true)) return;
            $events[] = $e;
        };
        $name = trim((string) ($ctx['name'] ?? ''));
        $me = $name !== '' ? kop_ptl_company_key($name) : '';
        $node = $ctx['map_node'] ?? null;
        $node_id = $node ? (string) $node['id'] : '';

        if ($founded) $add(kop_ptl_event($founded, 'founded', 'Founded' . (preg_match('/\b(?:in|at)\s+([A-Z][^,;.()]+(?:,\s*[A-Z][^,;.()]+)?)/', (string) $ctx['founded'], $where) ? ' in ' . trim($where[1]) : '')));

        // Facility id => [from, to]: the map's "operated 2009-2021", else the program's own years when
        // the company ran it from the start (own_years, operator-pages.php), else from a dated deal below.
        $by_key = array();
        $by_id = array();
        foreach ((array) ($ctx['facilities'] ?? array()) as $f) {
            $by_key[kop_facility_pages_name_key($f['name'])] = $f;
            $by_id[(int) $f['id']] = $f;
        }
        $spans = array();
        $program = static function ($name, $fid) use ($by_key) {
            $f = $by_key[kop_facility_pages_name_key($name)] ?? null;
            $url = $f && !empty($f['has_page']) ? (string) $f['url'] : ($fid > 0 && function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '');
            return array('name' => (string) $name, 'url' => $url, 'meta' => $f && !empty($f['place']) ? (string) $f['place'] : '');
        };

        // ---- Milestones (approved) -------------------------------------------
        $ms_pairs = array();   // other company key => [year => milestone key] for deals
        if ($me !== '') {
            foreach (kop_ptl_ms_approved() as $key => $m) {
                $cos = kop_ptl_ms_companies($m);
                if (!isset($cos[$me])) continue;
                $e = kop_ptl_ms_event($m, $cos[$me]);
                $events[] = $e;
                if (($m['kind'] ?? '') === 'deal') {
                    foreach ($cos as $ck => $role) {
                        if ($ck !== $me) $ms_pairs[$ck][(int) $m['year']] = count($events) - 1;
                    }
                }
            }
        }

        // ---- Company deals on the map ---------------------------------------
        // Bought X (the map's "acquired" line): dated by a milestone, the line's own year, or the year
        // X's programs began here. Without a year it stays off; the history says it in words.
        if ($node_id !== '') {
            foreach (kop_ptl_company_deals($node_id) as $d) {
                $other = (string) $d['node']['name'];
                $ok = kop_ptl_company_key($other);
                if (isset($ms_pairs[$ok])) {
                    // Dated by the milestone: the programs that company ran become ours from that year.
                    if ($d['as'] === 'bought') {
                        $y = (int) min(array_keys($ms_pairs[$ok]));
                        foreach (kop_ptl_runners_of_company((string) $d['node']['id']) as $fid) {
                            if (!isset($spans[$fid]) && isset($by_id[$fid])) $spans[$fid] = array($y, (int) ($by_id[$fid]['end_year'] ?? 0) ?: $now);
                        }
                    }
                    continue;
                }
                $y = (int) $d['year'];
                if (!$y || !in_array($d['as'], array('bought', 'bought-by'), true)) continue;
                $title = $d['as'] === 'bought' ? 'Bought ' . $other : 'Sold to ' . $other;
                $events[] = kop_ptl_event($y, 'deal', $title, array('major' => true, 'deal_as' => $d['as']));
                $ms_pairs[$ok][$y] = count($events) - 1;
            }
        }

        // ---- Programs: began / ended / taken over ---------------------------
        $map_years = array();
        $began = array();   // year => [items], with the company each came from
        $ended = array();
        $g = kop_ptl_graph();
        foreach ((array) ($ctx['links'] ?? array()) as $l) {
            if ($l['category'] !== 'corporate' || ($l['node']['kind'] ?? '') !== 'facility') continue;
            list($from, $to) = kop_operator_history_years($l['role']);
            $fname = (string) $l['node']['name'];
            $fid = (int) ($l['node']['facilityId'] ?? 0);
            $nk = kop_facility_pages_name_key($fname);
            // A line without years leaves the program's own years in charge.
            if ($from) $map_years[$nk] = true;
            $span_id = isset($by_key[$nk]) ? (int) $by_key[$nk]['id'] : $fid;
            $f = $by_id[$span_id] ?? null;
            // "operated (2009)" on a program that has since closed: the company ran it to the end.
            if ($from && !$to && $f && strcasecmp((string) $f['status'], 'Closed') === 0 && (int) $f['end_year'] >= $from) $to = (int) $f['end_year'];
            if ($from && $span_id > 0) $spans[$span_id] = array($from, $to ?: $now);
            if (!$from) continue;
            // Who ran it before and after: another company whose years end as ours begin.
            $prev = '';
            $next = '';
            if ($g) {
                foreach (kop_ptl_runners((string) $l['node']['id']) as $r) {
                    if ((string) $r['node']['id'] === $node_id || !$r['from']) continue;
                    if ($r['to'] && $r['to'] >= $from - 1 && $r['to'] <= $from && $r['from'] < $from) $prev = (string) $r['node']['name'];
                    if ($to && $r['from'] >= $to && $r['from'] <= $to + 1) $next = (string) $r['node']['name'];
                }
            }
            // Began running it the year it opened: the opening below says so.
            if ($prev !== '' || !$f || (int) $f['start_year'] !== $from) $began[$from][$prev][] = $program($fname, $fid);
            if ($to) $ended[$to][$next][] = $program($fname, $fid);
        }
        // Where the map has no years, the program's own record of its operators ("Aspen Education Group
        // (2000-2014)", the current operator after it): the takeover, and the sale or transfer away.
        $unvouched = array();   // year => [next on record => [items]]
        // One spelling per company ("Three Springs Inc." / "Three Springs, Inc."): the first one met.
        $spelled = array();
        foreach (array($began, $ended) as $list) foreach ($list as $by) foreach (array_keys($by) as $c) {
            if ($c !== '' && !isset($spelled[kop_ptl_company_key($c)])) $spelled[kop_ptl_company_key($c)] = (string) $c;
        }
        $spell = static function ($c) use (&$spelled) {
            $c = (string) $c;
            if ($c === '') return '';
            $ck = kop_ptl_company_key($c);
            if (!isset($spelled[$ck])) $spelled[$ck] = $c;
            return $spelled[$ck];
        };
        foreach ($by_key as $k => $f) {
            $run = (array) ($f['run'] ?? array());
            // Taken over with a dated deal: the company that ran it last before sold it (or itself) to us that year.
            if (empty($run['from']) && !empty($run['last'])) {
                $lk = kop_ptl_company_key($run['last']['name']);
                $ly = (int) $run['last']['to'];
                if (isset($ms_pairs[$lk][$ly]) || isset($ms_pairs[$lk][$ly - 1]) || isset($ms_pairs[$lk][$ly + 1])) {
                    $run['from'] = $ly;
                    $run['prev'] = $run['last']['name'];
                }
            }
            if (isset($map_years[$k]) || (empty($run['from']) && empty($run['to']))) continue;
            $fid = (int) $f['id'];
            $from = (int) $run['from'];
            $to = (int) $run['to'];
            if ($from && !$to && strcasecmp((string) $f['status'], 'Closed') === 0 && (int) $f['end_year'] >= $from) $to = (int) $f['end_year'];
            $map_years[$k] = true;
            if ($from) {
                $spans[$fid] = array($from, $to ?: $now);
                if ($run['prev'] !== '' || (int) $f['start_year'] !== $from) $began[$from][$spell($run['prev'])][] = $program($f['name'], $fid);
            }
            if ($to) {
                $next = (string) $run['next'];
                $it = $program($f['name'], $fid);
                $after = (string) ($run['after'] ?? '');
                if ($next === '' && $after !== '') {
                    $ak = kop_ptl_company_key($after);
                    if (isset($ms_pairs[$ak][$to]) || isset($ms_pairs[$ak][$to + 1]) || isset($ms_pairs[$ak][$to - 1])) {
                        $next = $after;
                    } elseif (!(strcasecmp((string) $f['status'], 'Closed') === 0 && (int) $f['end_year'] === $to)) {
                        // Not a handover we can vouch for: who came next, without the year it came.
                        $unvouched[$to][$spell($after)][] = $it;
                        continue;
                    }
                }
                $ended[$to][$spell($next)][] = $it;
            }
        }
        foreach ($unvouched as $year => $by_after) {
            foreach ($by_after as $after => $items) {
                $n = count($items);
                $add(kop_ptl_event((int) $year, 'ended', 'Stopped running ' . ($n === 1 ? $items[0]['name'] : $n . ' programs'), array(
                    'items' => $items,
                    'lead'  => 'Next on record: ' . $after . ', from a year the records do not give. Who ran ' . ($n === 1 ? 'it' : 'them') . ' in between, if anyone, is not on record.',
                    'major' => $n >= 3,
                )));
            }
        }
        foreach ($by_key as $k => $f) {
            if (isset($map_years[$k]) || empty($f['own_years'])) continue;
            // With no opening year on record, all of the program's years up to its closing are the company's.
            if (!isset($spans[(int) $f['id']])) $spans[(int) $f['id']] = array((int) $f['start_year'], (int) $f['end_year'] ?: $now);
        }
        $count_words = static function ($n) { return $n === 1 ? 'a program' : $n . ' programs'; };
        $closed_fids = array();
        foreach ($began as $year => $by_prev) {
            foreach ($by_prev as $prev => $items) {
                $n = count($items);
                if ($prev !== '') {
                    $pk = kop_ptl_company_key($prev);
                    $title = 'Took over ' . ($n === 1 ? $items[0]['name'] : $n . ' programs') . ' from ' . $prev;
                    // A deal milestone with that company the same year carries the programs.
                    if (isset($ms_pairs[$pk][$year]) || isset($ms_pairs[$pk][$year - 1])) {
                        $i = $ms_pairs[$pk][$year] ?? $ms_pairs[$pk][$year - 1];
                        $events[$i]['items'] = array_merge($events[$i]['items'], $items);
                        $events[$i]['lead'] = trim($events[$i]['lead'] . ' With the deal it took over ' . $count_words($n) . ' ' . $prev . ' had run.');
                        continue;
                    }
                    $add(kop_ptl_event((int) $year, 'takeover', $title, array(
                        'items' => $items,
                        'lead' => $prev . ' stopped running ' . ($n === 1 ? 'it' : 'them') . ' as ' . ($name !== '' ? $name : 'the company') . ' began.',
                        'major' => $n >= 3,
                    )));
                } else {
                    $add(kop_ptl_event((int) $year, 'began', $n === 1 ? 'Began running ' . $items[0]['name'] : 'Began running ' . $n . ' programs', array('items' => $items)));
                }
            }
        }
        foreach ($ended as $year => $by_next) {
            foreach ($by_next as $next => $items) {
                $n = count($items);
                $nk = $next !== '' ? kop_ptl_company_key($next) : '';
                if ($nk !== '' && (isset($ms_pairs[$nk][$year]) || isset($ms_pairs[$nk][$year - 1]))) {
                    // Moved with a deal between the two: on the deal.
                    $i = $ms_pairs[$nk][$year] ?? $ms_pairs[$nk][$year - 1];
                    $events[$i]['items'] = array_merge($events[$i]['items'], $items);
                    $events[$i]['lead'] = trim($events[$i]['lead'] . ' With the deal, ' . $count_words($n) . ' it had run moved to ' . $next . '.');
                    continue;
                }
                if ($next !== '') {
                    $add(kop_ptl_event((int) $year, 'handover', ($n === 1 ? $items[0]['name'] : $n . ' programs') . ' passed to ' . $next, array(
                        'items' => $items,
                        'lead' => $next . ' began running ' . ($n === 1 ? 'it' : 'them') . ' as ' . ($name !== '' ? $name : 'the company') . ' stopped.',
                        'major' => $n >= 3,
                    )));
                } else {
                    // How many closed that year, by the programs' own records.
                    $closed = 0;
                    foreach ($items as &$it) {
                        $f = $by_key[kop_facility_pages_name_key($it['name'])] ?? null;
                        if ($f && (int) ($f['end_year'] ?? 0) === (int) $year) {
                            $closed++;
                            $closed_fids[(int) $f['id']] = true;
                            $it['meta'] = trim($it['meta'] . ($it['meta'] !== '' ? ', ' : '') . 'closed');
                        }
                    }
                    unset($it);
                    $title = $n === 1
                        ? ($closed ? 'Closed ' . $items[0]['name'] : 'Stopped running ' . $items[0]['name'])
                        : ($closed === $n ? 'Closed ' . $n . ' programs' : 'Stopped running ' . $n . ' programs' . ($closed ? ', ' . $closed . ' of which closed that year' : ''));
                    $add(kop_ptl_event((int) $year, $closed ? 'closed' : 'ended', $title, array('items' => $items, 'major' => $closed >= 3)));
                }
            }
        }

        // ---- Its programs' openings and closings, while it ran them ---------------
        // Only inside the company's known years (owner, 2026-11-02): an opening before it took a program
        // over, a closing after it let go, or one in years the records do not tie to it stays off. A
        // program linked to it now that has closed closed under it; nothing before its founding year.
        $opens = array();   // year => [items]
        $shuts = array();
        foreach ($by_id as $fid => $f) {
            $span = $spans[$fid] ?? null;
            $s = (int) $f['start_year'];
            $e = strcasecmp((string) $f['status'], 'Closed') === 0 ? (int) $f['end_year'] : 0;
            if ($s && $span && $s >= $span[0] && $s <= $span[1]) $opens[$s][] = $program($f['name'], $fid);
            if ($e && !isset($closed_fids[$fid]) && ($span ? $e >= $span[0] && $e <= $span[1] : ($f['relationship'] ?? '') === 'current')) {
                $shuts[$e][] = $program($f['name'], $fid);
            }
        }
        foreach (array('Opened ' => array('opened', $opens), 'Closed ' => array('closed', $shuts)) as $verb => $pair) {
            foreach ($pair[1] as $year => $items) {
                $n = count($items);
                $add(kop_ptl_event((int) $year, $pair[0], $verb . ($n === 1 ? $items[0]['name'] : $n . ' programs'), array('items' => $items, 'major' => $n >= 3)));
            }
        }

        $in_span = static function (array $fids, $year) use ($spans) {
            foreach ($fids as $fid) {
                if (isset($spans[(int) $fid]) && $year >= $spans[(int) $fid][0] && $year <= $spans[(int) $fid][1]) return true;
            }
            return false;
        };

        // ---- Lawsuits -----------------------------------------------------------
        foreach ((array) ($ctx['lawsuits'] ?? array()) as $l) {
            if ($l['year'] === '') continue;
            // A suit naming the company as a defendant is its own; one about a program counts
            // only inside the years the company is known to have run it.
            if (empty($l['by_name']) && !$in_span((array) ($l['facility_ids'] ?? array()), (int) $l['year'])) continue;
            $add(kop_ptl_lawsuit_event($l, (string) ($ctx['lawsuits_url'] ?? ''), $by_id));
        }

        // ---- Deaths -------------------------------------------------------------
        $deaths_at = array();   // facility id => [year => true]: a death already on the timeline
        foreach ((array) ($ctx['memorials'] ?? array()) as $m) {
            if (!preg_match('/\b(1[89]\d\d|20\d\d)\b/', $m['date_label'], $y)) continue;
            if (!$in_span(array((int) ($m['facility_id'] ?? 0)), (int) $y[1])) continue;
            $add(kop_ptl_death_event($m, (int) $y[1], $program($m['program'], (int) ($m['facility_id'] ?? 0))));
            $deaths_at[(int) ($m['facility_id'] ?? 0)][(int) $y[1]] = true;
        }

        // ---- Critical incidents at its programs (riots, escapes, ...) ------------
        // One event per program and year: an investigation is often a dozen lines from as many sources.
        // A "Death" line where the memorial's death is already on the timeline says it twice.
        $inc_by = array();
        foreach ((array) ($ctx['incidents'] ?? array()) as $row) {
            $fid = (int) ($row['facility_id'] ?? 0);
            $inc = (array) $row['incident'];
            $y = kop_ptl_incident_year($inc);
            if (!$y || !isset($by_id[$fid]) || !$in_span(array($fid), $y)) continue;
            if (isset($deaths_at[$fid][$y]) && strcasecmp(trim((string) ($inc['kind'] ?? '')), 'Death') === 0) continue;
            $inc_by[$fid][$y][] = $inc;
        }
        foreach ($inc_by as $fid => $by_year) {
            $it = $program($by_id[$fid]['name'], $fid);
            foreach ($by_year as $y => $list) {
                $evs = array_values(array_filter(array_map(static function ($inc) use ($it) { return kop_ptl_incident_event($inc, '', $it); }, $list)));
                if (!$evs) continue;
                if (count($evs) === 1) {
                    $add($evs[0]);
                    continue;
                }
                $labels = array();
                $sources = array();
                $major = false;
                foreach ($evs as $e) {
                    $l = preg_replace('/ at .*$/', '', $e['title']);
                    $labels[strtolower($l)] = true;
                    foreach ($e['sources'] as $s) $sources[$s['url'] . '|' . $s['cite']] = $s;
                    if ($e['major']) $major = true;
                }
                $add(kop_ptl_event((int) $y, 'incident', count($evs) . ' incidents at ' . $it['name'], array(
                    'lead'    => ucfirst(kop_ptl_list_words(array_keys($labels), 5)) . '.',
                    'detail'  => array_map(static function ($e) { return ($e['when'] !== '' ? $e['when'] . ': ' : '') . $e['lead']; }, array_slice($evs, 0, 8)),
                    'items'   => $it['url'] !== '' ? array($it) : array(),
                    'sources' => array_slice(array_values($sources), 0, 8),
                    'major'   => $major,
                )));
            }
        }

        // ---- Leadership and investors ------------------------------------------------
        foreach (kop_ptl_leadership_events((array) ($ctx['leaders'] ?? array()), $name !== '' ? $name : 'the company') as $e) $add($e);
        foreach (kop_ptl_investor_events((array) ($ctx['investors'] ?? array())) as $e) $add($e);

        // ---- Serious findings by state inspectors --------------------------------
        foreach (kop_ptl_findings_by_year(array_keys($spans)) as $year => $per) {
            $per = array_filter($per, static function ($fid) use ($in_span, $year) { return $in_span(array($fid), (int) $year); }, ARRAY_FILTER_USE_KEY);
            if (!$per) continue;
            $total = 0;
            $cats = array();
            $items = array();
            foreach ($per as $fid => $c) {
                $total += $c[0];
                foreach ($c[1] as $k => $v) $cats[$k] = ($cats[$k] ?? 0) + $v;
                $it = $program($by_id[$fid]['name'] ?? '', $fid);
                if ($it['url'] !== '') $it['url'] .= '#violations';
                $it['meta'] = $c[0] . ' ' . ($c[0] === 1 ? 'finding' : 'findings');
                $items[] = $it;
            }
            $words = kop_ptl_finding_words($cats);
            $add(kop_ptl_event((int) $year, 'finding',
                'State inspectors recorded ' . $total . ' serious ' . ($total === 1 ? 'finding' : 'findings') . (count($per) > 1 ? ' at ' . count($per) . ' of its programs' : ' at ' . $items[0]['name']),
                array('items' => $items, 'lead' => $words !== '' ? 'Among them: ' . $words . '.' : '', 'major' => !empty($cats['death']) || !empty($cats['sexual_abuse']))));
        }

        // ---- The press ------------------------------------------------------------
        $news = (array) ($ctx['news'] ?? array());
        $fid_by_news = (array) ($ctx['news_facilities'] ?? array());
        foreach (kop_ptl_press_events($news, static function ($year, $n, $m) use ($in_span, $fid_by_news, $by_key, $me) {
            if ($in_span((array) ($fid_by_news[(int) $n['id']] ?? array()), $year)) return true;
            if (($n['about'] ?? '') !== 'company') return false;
            // An article naming the company in passing does not bring in a story about another
            // program: one mention of Teen Challenge put the Provo Canyon School story on its page.
            $about = (string) ($m['arc_facility'] ?? '');
            return empty($m['arc']) || $about === '' || isset($by_key[kop_facility_pages_name_key($about)]) || kop_ptl_company_key($about) === $me;
        }) as $e) $add($e);

        // An approved founding milestone says more than the bare "Founded" from the record.
        $ms_founded = array_filter($events, static function ($e) { return $e['kind'] === 'founded' && !empty($e['ms']); });
        if ($ms_founded) {
            $events = array_values(array_filter($events, static function ($e) { return $e['kind'] !== 'founded' || !empty($e['ms']); }));
        }
        // The founding year alone is already in the facts.
        if (count($events) === 1 && $events[0]['kind'] === 'founded') return array();
        return kop_ptl_years($events);
    }
}

if (!function_exists('kop_ptl_runners_of_company')) {
    /** Facility ids of the programs a company node is drawn running (any years). */
    function kop_ptl_runners_of_company($node_id) {
        $g = kop_ptl_graph();
        if (!$g) return array();
        $out = array();
        foreach ($g['adj'][$node_id] ?? array() as $e) {
            if (($e['category'] ?? '') !== 'corporate') continue;
            $other = $e['source'] === $node_id ? $e['target'] : $e['source'];
            $n = $g['nodes'][$other] ?? null;
            if ($n && ($n['kind'] ?? '') === 'facility' && !empty($n['facilityId'])) $out[] = (int) $n['facilityId'];
        }
        return $out;
    }
}

if (!function_exists('kop_ptl_lawsuit_event')) {
    function kop_ptl_lawsuit_event(array $l, $lawsuits_url, array $programs = array()) {
        $outcome = trim((string) ($l['outcome'] ?? ''));
        $amount = trim((string) ($l['settlement_amount'] ?? ''));
        $settled = $amount !== '' || preg_match('/\b(settle|verdict|award|jury)/i', $outcome);
        $facts = array();
        if (($l['court'] ?? '') !== '') $facts[] = array('Court', (string) $l['court']);
        if (($l['case_number'] ?? '') !== '') $facts[] = array('Case number', (string) $l['case_number']);
        if (($l['status'] ?? '') !== '') $facts[] = array('Status', (string) $l['status']);
        if ($amount !== '') $facts[] = array('Settlement', $amount);
        $detail = array();
        if (($l['summary'] ?? '') !== '') $detail[] = kop_ptl_short($l['summary'], 420);
        if ($outcome !== '') $detail[] = 'Outcome: ' . kop_ptl_short($outcome, 300);
        $about = array();
        foreach ((array) ($l['facility_ids'] ?? array()) as $fid) {
            if (isset($programs[(int) $fid])) $about[] = $programs[(int) $fid]['name'];
        }
        return kop_ptl_event((int) $l['year'], 'lawsuit', (string) $l['case_name'], array(
            'lead'   => $about ? 'About ' . kop_ptl_list_words($about) . '.' : '',
            'detail' => $detail,
            'facts'  => $facts,
            'more'   => $lawsuits_url !== '' ? array('label' => 'All lawsuits on record', 'url' => $lawsuits_url) : null,
            'major'  => (bool) $settled,
        ));
    }
}

if (!function_exists('kop_ptl_death_event')) {
    function kop_ptl_death_event(array $m, $year, array $program = null) {
        $who = trim($m['name'] . (($m['age'] ?? '') !== '' ? ', ' . $m['age'] . ',' : ''));
        $where = $program ? $program['name'] : (string) ($m['program'] ?? '');
        $sources = array();
        if (($m['source_url'] ?? '') !== '') $sources[] = array('url' => (string) $m['source_url'], 'cite' => (string) ($m['source_name'] ?? ''));
        return kop_ptl_event((int) $year, 'death', $who . ' died' . ($where !== '' ? ' at ' . $where : ''), array(
            'when'    => preg_match('/^\d{4}$/', (string) $m['date_label']) ? '' : (string) $m['date_label'],
            'date'    => (string) ($m['date'] ?? $year),
            'lead'    => ($m['cause'] ?? '') !== '' ? kop_ptl_short($m['cause'], 220) : '',
            'items'   => $program && $program['url'] !== '' ? array($program) : array(),
            'sources' => $sources,
            'more'    => ($m['kop_url'] ?? '') !== '' ? array('label' => 'In the memorial', 'url' => (string) $m['kop_url']) : null,
            'major'   => true,
        ));
    }
}

if (!function_exists('kop_ptl_run_entry')) {
    /**
     * One of a program's operators as its record writes them (identification.pastOperators,
     * currentOperator): "Aspen Education Group (2000-2014)", "(until 2015)", "(since 2014)",
     * "CRC Health Group (parent, 2006-2014)" => {name, from, to, parent}; 0 for a year not given.
     */
    function kop_ptl_run_entry($text) {
        $text = trim(preg_replace('/\s+/', ' ', str_replace(array("\xE2\x80\x93", "\xE2\x80\x94"), '-', (string) $text)));
        $name = $text;
        $in = '';
        if (preg_match('/^(.+?)\s*\(([^()]*)\)\s*$/u', $text, $m)) {
            $name = trim($m[1]);
            $in = strtolower($m[2]);
        }
        $from = $to = 0;
        if (preg_match('/\b(1[89]\d\d|20\d\d)\s*(?:-|to)\s*(1[89]\d\d|20\d\d)\b/', $in, $m)) {
            $from = (int) $m[1];
            $to = (int) $m[2];
        } elseif (preg_match('/\b(?:until|through|to|till|sold in|exited)\s+(?:[a-z]+\s+)?(1[89]\d\d|20\d\d)\b/', $in, $m)) {
            $to = (int) $m[1];
        } elseif (preg_match('/\b(1[89]\d\d|20\d\d)\b/', $in, $m)) {
            // "since 2014", "from 2010", "(2016)": the year it began.
            $from = (int) $m[1];
        }
        return array('name' => $name, 'from' => $from, 'to' => $to, 'parent' => (bool) preg_match('/\bparent\b|\bowned by\b/', $in));
    }
}

if (!function_exists('kop_ptl_run_years')) {
    /**
     * The years a company ran a program by the program's own record, and who ran it before
     * and after: {from, to, prev, next} (0 / '' when not said). $past: pastOperators;
     * $current: currentOperator; $is_me(name): whether a name is this company;
     * $still: the company is linked to it now (no end). The one it passed to is the
     * operator whose years start as the company's end, else the current one.
     */
    function kop_ptl_run_years(array $past, $current, callable $is_me, $still) {
        $mine = array();
        $others = array();
        foreach ($past as $p) {
            if (!is_string($p) || trim($p) === '') continue;
            $e = kop_ptl_run_entry($p);
            if ($is_me($e['name'])) $mine[] = $e;
            elseif (!$e['parent']) $others[] = $e;
        }
        $cur = trim((string) $current) !== '' ? kop_ptl_run_entry($current) : null;
        if ($cur && $is_me($cur['name'])) {
            $mine[] = $cur;
            $cur = null;
        }
        $from = 0;
        $to = 0;
        foreach ($mine as $e) {
            if ($e['from'] && (!$from || $e['from'] < $from)) $from = $e['from'];
            if ($e['to'] > $to) $to = $e['to'];
        }
        if ($still) $to = 0;
        if ($from && $to && $to < $from) $to = 0;
        // The current operator with no year of its own may have begun when the last one before it
        // ended ("Psychiatric Solutions, Inc. (2003-2010)", then Universal Health Services); 'last'
        // names that one, for the timeline to use only where a dated deal between the two says so
        // (Daytona Juvenile Residential Facility listed YSI until 2016, then Rite of Passage, with no word
        // of G4S and TrueCore between).
        $last = null;
        if ($still && !$from && $mine && !$cur) {
            foreach ($others as $e) {
                if ($e['to'] && (!$last || $e['to'] > $last['to'])) $last = array('name' => $e['name'], 'to' => $e['to']);
            }
        }
        $prev = '';
        $next = '';
        $after = '';
        foreach ($others as $e) {
            if ($from && $e['to'] && $e['to'] >= $from - 1 && $e['to'] <= $from) $prev = $e['name'];
            if ($to && $e['from'] && $e['from'] >= $to && $e['from'] <= $to + 1) $next = $e['name'];
        }
        // An operator with a start year as ours ends took it from us. One with no year of its own (the
        // current one, usually) is only the next on record: it may have come years later, with owners
        // between that the record leaves out (Daytona Juvenile Residential Facility: YSI until 2016, then
        // Rite of Passage, and nothing of G4S and TrueCore). 'after' names it for the timeline, which calls
        // it a handover only where a dated deal between the two says so.
        if ($to && $next === '' && $cur) {
            if ($cur['from'] >= $to && $cur['from'] <= $to + 1) $next = $cur['name'];
            elseif (!$cur['from']) $after = $cur['name'];
        }
        return array('from' => $from, 'to' => $to, 'prev' => $prev, 'next' => $next, 'after' => $after, 'last' => $last);
    }
}

if (!function_exists('kop_ptl_incident_year')) {
    /** The year a critical incident line (kop_facility_pages_incidents()) happened, or 0. */
    function kop_ptl_incident_year(array $inc) {
        return preg_match('/\b(1[89]\d\d|20\d\d)\b/', (string) ($inc['when'] ?? ''), $m) ? (int) $m[1] : 0;
    }
}

if (!function_exists('kop_ptl_incident_event')) {
    /**
     * A critical incident line as an event, or null without a year or words.
     * $program (company pages): the program it happened at, named in the title.
     * A riot, an escape, a fire, a death or a sexual assault is a major event.
     */
    function kop_ptl_incident_event(array $inc, $lead_prefix = '', array $program = null) {
        $y = kop_ptl_incident_year($inc);
        if (!$y) return null;
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $inc['text'])));
        if ($text === '') return null;
        $first = preg_match('/^(.{20,180}?[.!?])(\s|$)/u', $text, $s) ? $s[1] : kop_ptl_short($text, 160);
        $sources = array();
        if (($inc['source'] ?? '') !== '' || ($inc['url'] ?? '') !== '') $sources[] = array('url' => (string) ($inc['url'] ?? ''), 'cite' => (string) ($inc['cite'] ?? $inc['source'] ?? ''));
        $label = ($inc['kind'] ?? '') !== '' ? (string) $inc['kind'] : 'Incident';
        return kop_ptl_event($y, 'incident', $program ? $label . ' at ' . $program['name'] : $label, array(
            'when'    => trim((string) $inc['when']) !== (string) $y ? (string) $inc['when'] : '',
            'lead'    => trim($lead_prefix . $first),
            'detail'  => $first !== $text ? array(kop_ptl_short($text, 600)) : array(),
            'items'   => $program && $program['url'] !== '' ? array($program) : array(),
            'sources' => $sources,
            'major'   => (bool) preg_match('/\b(riots?|uprising|escaped?|runaways?|fires?|arson|died|death|killed|suicide|murder(ed)?|shot|stabbed|raped?|sexual(ly)? assault(ed)?)\b/i', $label . ' ' . $first),
        ));
    }
}

if (!function_exists('kop_ptl_leader_title')) {
    /**
     * The top title in a job's words ("President and Chief Clinical Officer,
     * then CEO" => "chief executive"), or '' for anything below the top (a vice
     * president, a director of marketing) or a founder alone (the founding is
     * its own event).
     */
    function kop_ptl_leader_title($role) {
        $role = (string) $role;
        if (preg_match('/vice[- ]president|\bvp\b|\bassistant\b|\bdeputy\b/i', $role) && !preg_match('/\b(ceo|chief executive)\b/i', $role)) return '';
        $titles = array(
            '/\b(ceo|chief executive( officer)?)\b/i' => 'chief executive',
            '/\bpresident\b/i'                         => 'president',
            '/\bexecutive director\b/i'                => 'executive director',
            '/\bchair(man|woman|person)?\b/i'          => 'chair',
            '/\b(co-?)?owners?\b/i'                    => 'owner',
        );
        foreach ($titles as $re => $title) {
            if (preg_match($re, $role)) return $title;
        }
        return '';
    }
}

if (!function_exists('kop_ptl_leadership_events')) {
    /**
     * Who led the company, as events: [{name, url, role, years}] (company
     * pages: past jobs and map lines at the company). Only spans count
     * ("2010-2015", "2010-present"); an end in this year or later is "still".
     * One leaving and another starting in one title the same year is one
     * event ("A succeeded B as chief executive").
     */
    function kop_ptl_leadership_events(array $leaders, $company) {
        $now = (int) gmdate('Y');
        $starts = array();
        $ends = array();
        $seen = array();
        foreach ($leaders as $l) {
            $title = kop_ptl_leader_title($l['role'] ?? '');
            $years = str_replace(array("\xE2\x80\x93", "\xE2\x80\x94", "\xEF\xBF\xBD"), '-', (string) ($l['years'] ?? ''));
            if ($title === '' || !preg_match('/\b(1[89]\d\d|20\d\d)\s*-\s*(1[89]\d\d|20\d\d|present|now|current)\b/i', $years, $m)) continue;
            $from = (int) $m[1];
            $to = ctype_digit($m[2]) ? (int) $m[2] : 0;
            if ($to && ($to < $from || $to >= $now)) $to = 0;
            $key = kop_facility_pages_name_key($l['name']) . '|' . $title;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $who = array('name' => (string) $l['name'], 'url' => (string) ($l['url'] ?? ''), 'meta' => '');
            $starts[$from][$title][] = array('who' => $who, 'to' => $to, 'role' => trim((string) $l['role']));
            if ($to) $ends[$to][$title][] = array('who' => $who, 'from' => $from, 'role' => trim((string) $l['role']));
        }
        $events = array();
        $as = static function ($title) use ($company) {
            return $title === 'owner' ? 'an owner of ' . $company : $title . ' of ' . $company;
        };
        foreach ($starts as $year => $by_title) {
            foreach ($by_title as $title => $list) {
                $left = $ends[$year][$title] ?? (isset($ends[$year - 1][$title]) && count($ends[$year - 1][$title]) === 1 ? $ends[$year - 1][$title] : array());
                foreach ($list as $i => $s) {
                    $prev = $i === 0 && count($left) === 1 && kop_facility_pages_name_key($left[0]['who']['name']) !== kop_facility_pages_name_key($s['who']['name']) ? $left[0] : null;
                    $title_words = $prev
                        ? $s['who']['name'] . ' succeeded ' . $prev['who']['name'] . ' as ' . $title
                        : $s['who']['name'] . ' became ' . $as($title);
                    $lead = $s['to'] ? 'Until ' . $s['to'] . '.' : 'Still ' . $title . ' as far as the records show.';
                    if ($prev) $lead = $prev['who']['name'] . ' had held it since ' . $prev['from'] . '. ' . $lead;
                    $events[] = kop_ptl_event((int) $year, 'leadership', $title_words, array(
                        'lead'  => $lead,
                        'items' => array_values(array_filter(array($s['who'], $prev ? $prev['who'] : null))),
                        'facts' => $s['role'] !== '' ? array(array('Role', $s['role'])) : array(),
                        'major' => $title === 'chief executive' || $title === 'owner',
                    ));
                    if ($prev) {
                        foreach ($ends as $ey => &$et) {
                            if (($ey === $year || $ey === $year - 1) && isset($et[$title])) {
                                $et[$title] = array_values(array_filter($et[$title], static function ($x) use ($prev) { return $x !== $prev; }));
                            }
                        }
                        unset($et);
                    }
                }
            }
        }
        foreach ($ends as $year => $by_title) {
            foreach ($by_title as $title => $list) {
                foreach ($list as $e) {
                    $events[] = kop_ptl_event((int) $year, 'leadership', $e['who']['name'] . ' left as ' . $title, array(
                        'lead'  => $title === 'owner' ? 'An owner since ' . $e['from'] . '.' : ucfirst($title) . ' since ' . $e['from'] . '.',
                        'items' => array($e['who']),
                        'facts' => $e['role'] !== '' ? array(array('Role', $e['role'])) : array(),
                    ));
                }
            }
        }
        return $events;
    }
}

if (!function_exists('kop_ptl_investor_events')) {
    /** Investors' stakes with years (inc/investors.php links: name, url, role, start_year, end_year) as ownership events. */
    function kop_ptl_investor_events(array $stakes) {
        $events = array();
        foreach ($stakes as $s) {
            $role = trim((string) ($s['role'] ?? ''));
            $r = $role !== '' && strcasecmp($role, 'Investor') !== 0 ? strtolower($role) : '';
            $owner = (bool) preg_match('/\bowner\b|controlling|majority/i', $role);
            $who = array('name' => (string) $s['name'], 'url' => (string) ($s['url'] ?? ''), 'meta' => '');
            $facts = ($s['detail'] ?? '') !== '' ? array(array('Stake', (string) $s['detail'])) : array();
            $sources = ($s['source_url'] ?? '') !== '' ? array(array('url' => (string) $s['source_url'], 'cite' => '')) : array();
            if ((int) ($s['start_year'] ?? 0)) {
                // The investor roles (Dropdown Lists 'investor-roles') as what the investor did.
                if (preg_match('/majority|controlling/', $r)) $did = ' became majority owner';
                elseif (preg_match('/^(co-)?owner$/', $r)) $did = ' became an owner';
                elseif (strpos($r, 'minority') !== false) $did = ' became a minority investor';
                elseif (preg_match('/preferred|royalt/', $r)) $did = ' took preferred equity or royalties';
                elseif (preg_match('/lend|financ|loan|debt|credit/', $r)) $did = ' lent it money';
                else $did = ' invested';
                $events[] = kop_ptl_event((int) $s['start_year'], 'deal', $s['name'] . $did, array(
                    'lead' => (int) ($s['end_year'] ?? 0) ? 'Until ' . (int) $s['end_year'] . '.' : '',
                    'items' => array($who), 'facts' => $facts, 'sources' => $sources, 'major' => $owner,
                ));
            }
            if ((int) ($s['end_year'] ?? 0)) {
                $events[] = kop_ptl_event((int) $s['end_year'], 'deal', $s['name'] . ' sold its stake', array(
                    'lead' => (int) ($s['start_year'] ?? 0) ? ($r !== '' ? ucfirst($r) : 'An investor') . ' since ' . (int) $s['start_year'] . '.' : '',
                    'items' => array($who), 'facts' => $facts, 'sources' => $sources, 'major' => $owner,
                ));
            }
        }
        return $events;
    }
}

// ---------------------------------------------------------------------------
// Facility pages
// ---------------------------------------------------------------------------

if (!function_exists('kop_ptl_facility')) {
    /**
     * A program's timeline from its page data (kop_facility_page_data(), read
     * before the name sections split the lists). Empty when it would hold
     * fewer than three events: the facts rail says that much already.
     */
    function kop_ptl_facility(array $page) {
        $fid = (int) $page['id'];
        $now = (int) gmdate('Y');
        $events = array();
        $doc = (array) ($page['doc'] ?? array());
        $op = (array) ($doc['operatingPeriod'] ?? array());
        $start = (int) ($op['startYear'] ?? 0);
        $end = (int) ($op['endYear'] ?? 0);
        $name = (string) $page['name'];

        if ($start) $events[] = kop_ptl_event($start, 'opened', 'Opened' . (!empty($page['place']) ? ' in ' . $page['place'] : ''));
        if ($end && ($page['status'] ?? '') !== 'Open') $events[] = kop_ptl_event($end, 'closed', 'Closed');

        // ---- Names ----------------------------------------------------------------
        $eras = (array) ($page['eras']['list'] ?? array());
        foreach ($eras as $i => $era) {
            if ($i === 0 || !preg_match('/^(\d{4})/', (string) $era['years'], $m)) continue;
            $prev = $eras[$i - 1]['name'];
            $events[] = kop_ptl_event((int) $m[1], 'rename', 'Renamed ' . $era['name'], array('lead' => 'It had been ' . $prev . '.', 'major' => true));
        }

        // ---- Companies that ran it, and the deals that moved it -----------------
        $g = kop_ptl_graph();
        $runners = array();
        foreach ($g ? ($g['by_fid'][$fid] ?? array()) : array() as $nid) {
            foreach (kop_ptl_runners($nid) as $r) $runners[(string) $r['node']['id']] = $r;
        }
        $company_item = static function ($cname) {
            $url = function_exists('kop_operator_page_url_for_name') ? (string) kop_operator_page_url_for_name($cname) : '';
            return array('name' => (string) $cname, 'url' => $url, 'meta' => '');
        };
        $run_keys = array();
        foreach ($runners as $r) $run_keys[kop_ptl_company_key($r['node']['name'])] = $r;
        if (!empty($page['operator']['name'])) $run_keys += array(kop_ptl_company_key($page['operator']['name']) => array('node' => array('name' => $page['operator']['name']), 'from' => 0, 'to' => 0));
        $deal_years = array();
        foreach (kop_ptl_ms_approved() as $m) {
            $cos = kop_ptl_ms_companies($m);
            $named = false;
            foreach ((array) ($m['programs'] ?? array()) as $p) {
                if (kop_facility_pages_name_key($p) === kop_facility_pages_name_key($name)) $named = true;
            }
            $ours = array_intersect_key($run_keys, $cos);
            $deal = ($m['kind'] ?? '') === 'deal' && count($ours) >= 1;
            // A deal counts when the companies on both sides ran it, or the one that ran it then.
            if ($deal && !$named) {
                $hit = false;
                foreach ($ours as $r) {
                    $y = (int) $m['year'];
                    if ($r['from'] && $y >= $r['from'] - 1 && $y <= ($r['to'] ?: $now)) $hit = true;
                }
                if (count($ours) >= 2) $hit = true;
                if (!$hit) continue;
            } elseif (!$named) {
                continue;
            }
            $who = array_map(static function ($r) { return $r['node']['name']; }, $ours);
            $e = kop_ptl_ms_event($m, 'other', $deal ? kop_ptl_list_words(array_values($who)) . ' ran ' . $name . (count($who) > 1 ? ' on either side of the deal.' : ' at the time.') : '');
            $e['items'] = array();
            $events[] = $e;
            if ($deal) $deal_years[(int) $m['year']] = true;
        }
        $starts = array();
        foreach ($runners as $r) {
            if (!$r['from']) continue;
            $starts[$r['from']][] = $r;
        }
        foreach ($runners as $r) {
            $cname = (string) $r['node']['name'];
            if ($r['from']) {
                $prev = null;
                foreach ($runners as $q) {
                    if ($q !== $r && $q['to'] && $q['to'] >= $r['from'] - 1 && $q['to'] <= $r['from'] && $q['from'] < $r['from']) $prev = $q;
                }
                $deal_note = isset($deal_years[$r['from']]) || isset($deal_years[$r['from'] - 1]) ? ' (see the deal above)' : '';
                $events[] = kop_ptl_event($r['from'], 'operator', $prev ? $cname . ' took it over from ' . $prev['node']['name'] . $deal_note : $cname . ' began running it', array(
                    'items' => array_values(array_filter(array($company_item($cname), $prev ? $company_item($prev['node']['name']) : null))),
                    'lead'  => $r['to'] ? 'It ran the program until ' . $r['to'] . '.' : '',
                ));
            }
            if ($r['to'] && $r['to'] !== $end) {
                $next = false;
                foreach ($runners as $q) {
                    if ($q !== $r && $q['from'] >= $r['to'] && $q['from'] <= $r['to'] + 1) $next = true;
                }
                if (!$next) $events[] = kop_ptl_event($r['to'], 'operator', $cname . ' stopped running it', array('items' => array($company_item($cname))));
            }
        }

        // ---- What the records hold ----------------------------------------------
        $lists = array('memorials' => array(), 'lawsuits' => array(), 'incidents' => array(), 'news' => array(), 'violations' => array());
        $seen = array();
        $take = static function ($kind, array $rows, $era_name = '') use (&$lists, &$seen) {
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $k = $kind . ':' . (isset($r['id']) ? 'i' . $r['id'] : md5(json_encode(array($r['text'] ?? '', $r['when'] ?? '', $r['name'] ?? ''))));
                if (isset($seen[$k])) continue;
                $seen[$k] = true;
                $r['_era'] = $era_name;
                $lists[$kind][] = $r;
            }
        };
        foreach (array_keys($lists) as $kind) {
            $take($kind, $kind === 'violations' ? (array) ($page['inspections']['violations'] ?? array()) : (array) ($page[$kind] ?? array()));
        }
        foreach ($eras as $era) {
            foreach (array_keys($lists) as $kind) $take($kind, (array) ($era[$kind] ?? array()), (string) $era['name']);
        }
        $as = static function ($r) use ($name) {
            return ($r['_era'] ?? '') !== '' && kop_facility_pages_name_key($r['_era']) !== kop_facility_pages_name_key($name) ? 'As ' . $r['_era'] . '. ' : '';
        };

        foreach ($lists['memorials'] as $m) {
            if (!preg_match('/\b(1[89]\d\d|20\d\d)\b/', (string) ($m['date_label'] ?? ''), $y)) continue;
            $e = kop_ptl_death_event($m, (int) $y[1], null);
            $e['title'] = trim($m['name'] . (($m['age'] ?? '') !== '' ? ', ' . $m['age'] . ',' : '')) . ' died here';
            $e['lead'] = trim($as($m) . $e['lead']);
            $events[] = $e;
        }
        foreach ($lists['lawsuits'] as $l) {
            if (($l['year'] ?? '') === '') continue;
            $e = kop_ptl_lawsuit_event($l, (string) ($page['lawsuits_url'] ?? ''));
            $e['lead'] = trim($as($l) . $e['lead']);
            $events[] = $e;
        }
        // A page cut by name lists each finding under its name's section (#<era id>-violations), not #violations.
        $viol_at = array();
        foreach ($eras as $era) {
            foreach ((array) ($era['violations'] ?? array()) as $v) {
                if (is_array($v) && isset($v['id']) && !isset($viol_at[(int) $v['id']])) $viol_at[(int) $v['id']] = (string) $era['id'] . '-violations';
            }
        }
        $by_year = array();
        foreach ($lists['violations'] as $v) {
            $y = kop_ptl_date($v['date'] ?? '')[0];
            if ($y) $by_year[$y][] = $v;
        }
        foreach ($by_year as $y => $vs) {
            $labels = array_count_values(array_map(static function ($v) { return strtolower((string) $v['label']); }, $vs));
            arsort($labels);
            $bits = array();
            foreach ($labels as $l => $n) $bits[] = $n > 1 ? $n . ' ' . $l : $l;
            $detail = array();
            foreach (array_slice($vs, 0, 3) as $v) {
                $detail[] = ($v['date_label'] !== '' ? $v['date_label'] . ': ' : '') . (!empty($v['plain']) ? $v['plain'] : kop_ptl_short($v['short'] ?? $v['excerpt'], 260));
            }
            $n = count($vs);
            $grave = false;
            foreach ($vs as $v) {
                if (in_array($v['category'], array('death', 'sexual_abuse'), true)) $grave = true;
            }
            $events[] = kop_ptl_event((int) $y, 'finding', 'State inspectors recorded ' . ($n === 1 ? 'a serious finding' : $n . ' serious findings'), array(
                'lead'   => ucfirst(implode(', ', array_slice($bits, 0, 4))) . '.',
                'detail' => $detail,
                'more'   => array('label' => 'Read the findings', 'url' => '#' . ($viol_at[(int) ($vs[0]['id'] ?? 0)] ?? 'violations')),
                'major'  => $grave,
            ));
        }
        foreach ($lists['incidents'] as $inc) {
            $e = kop_ptl_incident_event($inc, $as($inc));
            if ($e) $events[] = $e;
        }
        foreach (kop_ptl_press_events($lists['news']) as $e) $events[] = $e;

        $years = kop_ptl_years($events);
        $n = 0;
        foreach ($years as $y) $n += count($y['events']);
        return ($n >= 3 && count($years) >= 2) ? $years : array();
    }
}

// ---------------------------------------------------------------------------
// Order
// ---------------------------------------------------------------------------

if (!function_exists('kop_ptl_years')) {
    /** Events into years, oldest first; in a year ownership first, then programs, harm, courts, the press. */
    function kop_ptl_years(array $events) {
        $order = array_flip(array('founded', 'deal', 'takeover', 'handover', 'operator', 'leadership', 'rename', 'opened', 'began', 'investigation',
            'death', 'incident', 'finding', 'lawsuit', 'settlement', 'scandal', 'story', 'featured', 'ended', 'closed'));
        $by = array();
        foreach ($events as $e) $by[(int) $e['year']][] = $e;
        ksort($by);
        $out = array();
        foreach ($by as $year => $list) {
            usort($list, static function ($a, $b) use ($order) {
                return (($order[$a['kind']] ?? 50) <=> ($order[$b['kind']] ?? 50)) ?: strcmp($a['date'], $b['date']);
            });
            $out[] = array('year' => (int) $year, 'events' => $list, 'text' => implode('; ', array_map(static function ($e) { return $e['title']; }, $list)));
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// Printing
// ---------------------------------------------------------------------------

if (!function_exists('kop_ptl_html')) {
    /**
     * The timeline. $opts: id (prefix for anchors), intro (a line above it),
     * show (years shown before "Show every year", 0 = all).
     */
    function kop_ptl_html(array $years, array $opts = array()) {
        if (!$years) return '';
        $id = sanitize_html_class((string) ($opts['id'] ?? 'tl'));
        $kinds = kop_ptl_kinds();
        $groups = kop_ptl_groups();
        $show = (int) ($opts['show'] ?? 14);
        $icon = static function ($name) { return function_exists('kop_icon') ? kop_icon($name) : ''; };

        $counts = array();
        $total = 0;
        foreach ($years as $y) {
            foreach ($y['events'] as $e) {
                $counts[$e['group']] = ($counts[$e['group']] ?? 0) + 1;
                $total++;
            }
        }
        $first = (int) $years[0]['year'];
        $last = (int) $years[count($years) - 1]['year'];

        $h = '<div class="kop-ptl" id="' . esc_attr($id) . '" data-kop-ptl>';
        if (!empty($opts['intro'])) $h .= '<p class="kop-ptl-intro">' . esc_html($opts['intro']) . '</p>';

        // Filters: only the kinds this timeline holds, and only when there is more than one.
        $present = array_intersect_key($groups, $counts);
        if (count($present) > 1) {
            $h .= '<div class="kop-ptl-filters" role="group" aria-label="Show on the timeline">';
            $h .= '<button type="button" class="kop-ptl-filter is-on" data-group="all" aria-pressed="true">Everything <span class="kop-ptl-n">' . (int) $total . '</span></button>';
            foreach ($present as $g => $label) {
                $h .= '<button type="button" class="kop-ptl-filter kop-ptl-g-' . esc_attr($g) . '" data-group="' . esc_attr($g) . '" aria-pressed="false">' . esc_html($label) . ' <span class="kop-ptl-n">' . (int) $counts[$g] . '</span></button>';
            }
            $h .= '</div>';
        }

        // A strip of the years: one mark per year with something in it, placed along the span.
        if ($last > $first && count($years) > 2) {
            $span = max(1, $last - $first);
            $h .= '<div class="kop-ptl-strip" aria-label="Jump to a year"><span class="kop-ptl-strip-line"></span>';
            foreach ($years as $y) {
                $pos = round(100 * ((int) $y['year'] - $first) / $span, 2);
                $major = false;
                $gs = array();
                foreach ($y['events'] as $e) {
                    if (!empty($e['major'])) $major = true;
                    $gs[$e['group']] = true;
                }
                $h .= '<a class="kop-ptl-tick' . ($major ? ' is-major' : '') . '" href="#' . esc_attr($id . '-' . (int) $y['year']) . '" style="left:' . esc_attr($pos) . '%" data-groups="' . esc_attr(implode(' ', array_keys($gs))) . '" title="' . esc_attr($y['year'] . ': ' . count($y['events']) . ' ' . (count($y['events']) === 1 ? 'event' : 'events')) . '"><span class="screen-reader-text">' . (int) $y['year'] . '</span></a>';
            }
            $h .= '<span class="kop-ptl-strip-end kop-ptl-strip-start">' . $first . '</span><span class="kop-ptl-strip-end kop-ptl-strip-last">' . $last . '</span></div>';
        }

        $h .= '<ol class="kop-ptl-years">';
        $card_n = 0;
        foreach ($years as $i => $y) {
            $gs = array_unique(array_map(static function ($e) { return $e['group']; }, $y['events']));
            $hidden = $show > 0 && $i >= $show;
            $h .= '<li class="kop-ptl-year' . ($hidden ? ' kop-ptl-later' : '') . '" id="' . esc_attr($id . '-' . (int) $y['year']) . '" data-groups="' . esc_attr(implode(' ', $gs)) . '">';
            $h .= '<h3 class="kop-ptl-yearmark"><span>' . (int) $y['year'] . '</span></h3><ol class="kop-ptl-events">';
            foreach ($y['events'] as $e) {
                $k = $kinds[$e['kind']] ?? array('dot', 'programs', '');
                $has_card = $e['detail'] || $e['facts'] || $e['sources'] || $e['more'] || count($e['items']) > 4 || !empty($e['image']);
                $cid = $id . '-c' . (++$card_n);
                $h .= '<li class="kop-ptl-ev kop-ptl-k-' . esc_attr($e['kind']) . ' kop-ptl-g-' . esc_attr($e['group']) . (!empty($e['major']) ? ' is-major' : '') . ($has_card ? ' has-card' : '') . '" data-group="' . esc_attr($e['group']) . '">';
                $h .= '<span class="kop-ptl-dot">' . $icon($k[0]) . '</span>';
                $h .= '<div class="kop-ptl-main">';
                $h .= '<p class="kop-ptl-meta"><span class="kop-ptl-tag">' . esc_html($k[2]) . '</span>' . ($e['when'] !== '' ? '<span class="kop-ptl-when">' . esc_html($e['when']) . '</span>' : '') . '</p>';
                $h .= '<p class="kop-ptl-title">' . esc_html($e['title']) . '</p>';
                if ($e['lead'] !== '') $h .= '<p class="kop-ptl-lead">' . esc_html($e['lead']) . '</p>';
                if ($e['items']) $h .= kop_ptl_items_html(array_slice($e['items'], 0, 4), count($e['items']) - 4);
                if ($has_card) $h .= '<button type="button" class="kop-ptl-more" aria-expanded="false" aria-controls="' . esc_attr($cid) . '">' . $icon('plus') . '<span>More about this</span></button>';
                $h .= '</div>';
                if (!empty($e['image']['src'])) {
                    $h .= '<span class="kop-ptl-thumb kop-ptl-thumb--' . esc_attr($e['image']['kind'] ?? 'photo') . '"><img src="' . esc_url($e['image']['src']) . '" alt="" loading="lazy" decoding="async"></span>';
                }
                if ($has_card) $h .= kop_ptl_card_html($e, $cid);
                $h .= '</li>';
            }
            $h .= '</ol></li>';
        }
        $h .= '</ol>';
        $later = $show > 0 ? count($years) - $show : 0;
        if ($later > 0) {
            $h .= '<button type="button" class="kop-ptl-all" hidden>Show ' . (int) $later . ' more ' . ($later === 1 ? 'year' : 'years') . ', to ' . $last . '</button>';
        }
        $h .= '<div class="kop-ptl-legend" aria-hidden="true"><span class="kop-ptl-legend-major"></span> Larger marks: deals, deaths, settlements and stories several outlets covered.</div>';
        $h .= '</div>';
        return $h;
    }
}

if (!function_exists('kop_ptl_items_html')) {
    function kop_ptl_items_html(array $items, $more = 0) {
        $h = '<ul class="kop-ptl-items">';
        foreach ($items as $it) {
            $label = esc_html($it['name']);
            $h .= '<li>' . ($it['url'] !== '' ? '<a href="' . esc_url($it['url']) . '">' . $label . '</a>' : $label)
                . (($it['meta'] ?? '') !== '' ? ' <span class="kop-ptl-item-meta">' . esc_html($it['meta']) . '</span>' : '') . '</li>';
        }
        if ($more > 0) $h .= '<li class="kop-ptl-items-more">and ' . (int) $more . ' more</li>';
        return $h . '</ul>';
    }
}

if (!function_exists('kop_ptl_card_html')) {
    /** The card with more on one event: the picture, the context, the facts, every item, the sources. */
    function kop_ptl_card_html(array $e, $cid) {
        $h = '<div class="kop-ptl-card" id="' . esc_attr($cid) . '">';
        if (!empty($e['image']['src'])) {
            $h .= '<span class="kop-ptl-card-img kop-ptl-card-img--' . esc_attr($e['image']['kind'] ?? 'photo') . '"><img src="' . esc_url($e['image']['src']) . '" alt="" loading="lazy" decoding="async"></span>';
        }
        $h .= '<p class="kop-ptl-card-title">' . esc_html($e['title']) . ($e['when'] !== '' ? ' <span class="kop-ptl-when">' . esc_html($e['when']) . '</span>' : '') . '</p>';
        foreach ($e['detail'] as $p) $h .= '<p>' . esc_html($p) . '</p>';
        if ($e['facts']) {
            $h .= '<dl class="kop-ptl-facts">';
            foreach ($e['facts'] as $f) $h .= '<div><dt>' . esc_html($f[0]) . '</dt><dd>' . esc_html($f[1]) . '</dd></div>';
            $h .= '</dl>';
        }
        if (count($e['items']) > 4) $h .= kop_ptl_items_html($e['items']);
        $foot = '';
        if ($e['more']) $foot .= '<a class="kop-ptl-card-link" href="' . esc_url($e['more']['url']) . '">' . esc_html($e['more']['label']) . '</a>';
        if ($e['sources'] && function_exists('kop_facility_pages_sources_html')) $foot .= kop_facility_pages_sources_html($e['sources'], 'span');
        if ($foot !== '') $h .= '<p class="kop-ptl-card-foot">' . $foot . '</p>';
        return $h . '</div>';
    }
}

if (!function_exists('kop_ptl_enqueue')) {
    /** The timeline's stylesheet and script, on company and facility pages. */
    function kop_ptl_enqueue() {
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        if (file_exists($dir . '/css/profile-timeline.css')) {
            wp_enqueue_style('kop-profile-timeline', $uri . '/css/profile-timeline.css', array('kop-facility-profile'), filemtime($dir . '/css/profile-timeline.css'));
        }
        if (file_exists($dir . '/js/profile-timeline.js')) {
            wp_enqueue_script('kop-profile-timeline', $uri . '/js/profile-timeline.js', array(), filemtime($dir . '/js/profile-timeline.js'), true);
        }
    }
}
