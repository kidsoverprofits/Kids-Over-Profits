<?php
/**
 * The news discovery's daily list (scripts/discover-articles.php): records
 * whose names Google News is searched for every day; every other name takes
 * its turn in the rotation. Google blocks the server after ~180 searches in
 * a row, so a run makes 140 at most and only so many names can be daily.
 *
 * Automatic (the owner's rule, 2026-10-15): open programs with an approved
 * news article dated in the last year, a published lawsuit (any age), or an
 * approved serious inspection finding dated in the last year. Three years
 * was 391 programs, 258 searches a day, more than the ~224 two runs allow;
 * one year is ~220 programs, ~155 searches.
 *
 * By hand: "Daily news search" in the admin toolbar of every facility and
 * company page (pencil source 'dsearch:f:<id>' / 'dsearch:o:<id>'): Automatic,
 * Daily, or Never daily. A company set to Daily puts all its current open
 * programs on the list; a program's own Never daily wins. Kept in the
 * kop_discovery_priority option, each change logged in kop_discovery_priority_log.
 *
 * Discovery reads the names at GET kop/v1/discovery/priority (header
 * X-KOP-Internal = kop_internal_token('discovery'), or a signed-in admin).
 * Tested by scripts/test-discovery-priority.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_dp_years() {
    return 1;
}

/** 'the last 12 months' / 'the last 2 years' */
function kop_dp_window_words() {
    return kop_dp_years() === 1 ? 'the last 12 months' : 'the last ' . kop_dp_years() . ' years';
}

/** ['f' => [id => 'on'|'off'], 'o' => [id => 'on'|'off']] */
function kop_dp_overrides() {
    $o = get_option('kop_discovery_priority', array());
    $o = is_array($o) ? $o : array();
    return array('f' => (array) ($o['f'] ?? array()), 'o' => (array) ($o['o'] ?? array()));
}

/** Set a record's or company's mode ('auto', 'on', 'off'). Returns the mode it had. */
function kop_dp_set($kind, $id, $mode, $who = '') {
    $kind = $kind === 'o' ? 'o' : 'f';
    $id = (int) $id;
    $all = kop_dp_overrides();
    $was = $all[$kind][$id] ?? 'auto';
    if ($mode === 'auto') unset($all[$kind][$id]);
    elseif (in_array($mode, array('on', 'off'), true)) $all[$kind][$id] = $mode;
    update_option('kop_discovery_priority', $all, false);
    $log = get_option('kop_discovery_priority_log', array());
    $log = is_array($log) ? $log : array();
    $log[] = array('at' => gmdate('c'), 'kind' => $kind, 'id' => $id, 'from' => $was, 'to' => $mode, 'who' => (string) $who);
    update_option('kop_discovery_priority_log', array_slice($log, -200), false);
    delete_transient('kop_dp_list');
    return $was;
}

/**
 * The automatic rule: [facility id => [reason, ...]] for open records.
 * $irl_records: the inspection rollup's records ([fid => ['sites' => [...]]]),
 * passed in by tests; read from kop_irl_get() otherwise.
 */
function kop_dp_auto(PDO $pdo, $prefix = 'wpdl_', $now = null, $irl_records = null) {
    $since = gmdate('Y-m-d', strtotime('-' . kop_dp_years() . ' years', $now ?? time()));
    $open = array();
    foreach ($pdo->query("SELECT id FROM facilities_v2 WHERE status = 'Open'")->fetchAll(PDO::FETCH_COLUMN) as $id) $open[(int) $id] = true;
    $out = array();
    $add = function ($id, $why) use (&$out, $open) {
        $id = (int) $id;
        if (isset($open[$id])) $out[$id][$why] = true;
    };
    $st = $pdo->prepare("SELECT DISTINCT l.facility_id FROM news_facility_links l JOIN news_submissions n ON n.id = l.news_id
        WHERE n.status = 'approved' AND n.publication_date >= ?");
    $st->execute(array($since));
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $add($id, 'news');
    foreach ($pdo->query("SELECT DISTINCT l.facility_id FROM lawsuit_facility_links l JOIN lawsuits s ON s.id = l.lawsuit_id
        WHERE s.publication_status = 'published'")->fetchAll(PDO::FETCH_COLUMN) as $id) $add($id, 'lawsuit');
    $st = $pdo->prepare("SELECT DISTINCT facility_id FROM inspection_highlights WHERE status = 'approved' AND finding_date >= ?");
    $st->execute(array($since));
    $sites = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    if ($irl_records === null && $sites && function_exists('kop_irl_get')) {
        try {
            $irl_records = kop_irl_get($pdo, $prefix)['records'] ?? array();
        } catch (Throwable $e) {
            $irl_records = array();
        }
    }
    foreach ((array) $irl_records as $fid => $r) {
        foreach ((array) ($r['sites'] ?? array()) as $sid) {
            if (isset($sites[(int) $sid])) { $add($fid, 'finding'); break; }
        }
    }
    return array_map('array_keys', $out);
}

/** Every record on the list: [facility id => reason ('news', 'lawsuit', 'finding', 'by hand', 'company')]. */
function kop_dp_ids(PDO $pdo, $prefix = 'wpdl_', $irl_records = null) {
    $ov = kop_dp_overrides();
    $ids = array();
    foreach (kop_dp_auto($pdo, $prefix, null, $irl_records) as $id => $why) $ids[$id] = $why[0];
    $on_ops = array_keys(array_filter($ov['o'], function ($m) { return $m === 'on'; }));
    if ($on_ops) {
        $in = implode(',', array_map('intval', $on_ops));
        foreach ($pdo->query("SELECT l.facility_id FROM {$prefix}kop_operator_facilities l JOIN facilities_v2 f ON f.id = l.facility_id
            WHERE l.operator_id IN ($in) AND l.relationship = 'current' AND f.status = 'Open'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[(int) $id] = $ids[(int) $id] ?? 'company';
        }
    }
    foreach ($ov['f'] as $id => $m) {
        if ($m === 'on') $ids[(int) $id] = 'by hand';
        if ($m === 'off') unset($ids[(int) $id]);
    }
    return $ids;
}

/** The names discovery searches for: each record's names, and the companies set to Daily. */
function kop_dp_names(PDO $pdo, array $ids, $prefix = 'wpdl_') {
    $names = array();
    foreach (array_chunk(array_keys($ids), 400) as $chunk) {
        $in = implode(',', array_map('intval', $chunk));
        foreach ($pdo->query("SELECT name, json_data FROM facilities_v2 WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $doc = json_decode((string) $r['json_data'], true);
            $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
            foreach (array_merge(array($r['name'], $ident['name'] ?? '', $ident['currentName'] ?? ''),
                (array) ($ident['otherNames'] ?? array()), (array) ($ident['pastNames'] ?? array())) as $n) {
                if (is_string($n) && trim($n) !== '') $names[trim($n)] = true;
            }
        }
    }
    $on_ops = array_keys(array_filter(kop_dp_overrides()['o'], function ($m) { return $m === 'on'; }));
    if ($on_ops) {
        $in = implode(',', array_map('intval', $on_ops));
        foreach ($pdo->query("SELECT name, json_data FROM {$prefix}kop_operators WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $doc = json_decode((string) $r['json_data'], true);
            foreach (array_merge(array($r['name']), (array) ($doc['otherNames'] ?? array())) as $n) {
                if (is_string($n) && trim($n) !== '') $names[trim($n)] = true;
            }
        }
    }
    return array_keys($names);
}

/** {names, records, reasons, generated}, cached an hour (a switch clears it). */
function kop_dp_list() {
    $c = get_transient('kop_dp_list');
    if (is_array($c)) return $c;
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) throw new RuntimeException('No database connection.');
    global $wpdb;
    $ids = kop_dp_ids($pdo, $wpdb->prefix);
    $reasons = array_count_values(array_values($ids));
    $out = array('names' => kop_dp_names($pdo, $ids, $wpdb->prefix), 'records' => count($ids), 'reasons' => $reasons, 'generated' => gmdate('c'));
    set_transient('kop_dp_list', $out, HOUR_IN_SECONDS);
    return $out;
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/discovery/priority', array(
        'methods'  => 'GET',
        'permission_callback' => function () {
            require_once get_stylesheet_directory() . '/api/lib-internal-token.php';
            return kop_internal_token_ok('discovery') || current_user_can('manage_options');
        },
        'callback' => function () {
            try {
                return rest_ensure_response(kop_dp_list());
            } catch (Throwable $e) {
                return new WP_Error('kop_dp', $e->getMessage(), array('status' => 500));
            }
        },
    ));
});

/* ---- The switch: an entry in the admin toolbar of facility and company pages ---- */

/** 'auto' / 'on' / 'off' and whether the record is on the list now, with why. */
function kop_dp_state($kind, $id) {
    $ov = kop_dp_overrides();
    $mode = $ov[$kind === 'o' ? 'o' : 'f'][(int) $id] ?? 'auto';
    $why = '';
    if ($kind !== 'o') {
        try {
            $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
            global $wpdb;
            $ids = $pdo ? kop_dp_ids($pdo, $wpdb->prefix) : array();
            $why = $ids[(int) $id] ?? '';
        } catch (Throwable $e) {
            $why = '';
        }
    }
    return array($mode, $why);
}

function kop_dp_why_words($why) {
    $w = array('news' => 'news in ' . kop_dp_window_words(), 'lawsuit' => 'a lawsuit',
        'finding' => 'a serious inspection finding in ' . kop_dp_window_words(), 'by hand' => 'set by hand', 'company' => 'its company is set to Daily');
    return $w[$why] ?? $why;
}

add_filter('kop_ie_page_refs', function ($refs) {
    if (!empty($GLOBALS['kop_facility_page']['id'])) {
        list($mode, $why) = kop_dp_state('f', (int) $GLOBALS['kop_facility_page']['id']);
        $refs[] = array('ref' => 'dsearch:f:' . (int) $GLOBALS['kop_facility_page']['id'],
            'label' => 'Daily news search: ' . ($why !== '' ? 'on (' . kop_dp_why_words($why) . ')' : 'off') . ($mode !== 'auto' ? ', set by hand' : ''));
    } elseif (!empty($GLOBALS['kop_operator_page']['id'])) {
        list($mode) = kop_dp_state('o', (int) $GLOBALS['kop_operator_page']['id']);
        $refs[] = array('ref' => 'dsearch:o:' . (int) $GLOBALS['kop_operator_page']['id'],
            'label' => 'Daily news search for its programs: ' . array('auto' => 'automatic', 'on' => 'daily', 'off' => 'automatic')[$mode]);
    }
    return $refs;
});

function kop_dp_ie_load(array $p) {
    $kind = ($p[0] ?? '') === 'o' ? 'o' : 'f';
    $id = (int) ($p[1] ?? 0);
    if ($id <= 0) throw new RuntimeException('No record.');
    list($mode, $why) = kop_dp_state($kind, $id);
    $options = $kind === 'o'
        ? array(array('value' => 'auto', 'label' => 'Automatic: each program by the rule'),
            array('value' => 'on', 'label' => 'Daily: search every current open program every day'))
        : array(array('value' => 'auto', 'label' => 'Automatic (the rule decides)'),
            array('value' => 'on', 'label' => 'Daily, always'),
            array('value' => 'off', 'label' => 'Never daily (it still comes around in the rotation)'));
    $help = 'The news discovery searches Google News for the names on its daily list every day; every other name comes around in the rotation, about every five or six weeks. '
        . 'Automatic puts an open program on the daily list when it has news in ' . kop_dp_window_words() . ', a lawsuit, or a serious inspection finding in ' . kop_dp_window_words() . '. '
        . ($kind === 'f' ? 'Now: ' . ($why !== '' ? 'on the daily list (' . kop_dp_why_words($why) . ').' : 'not on the daily list.') : '')
        . ' A change reaches the next discovery run.';
    return array(
        'title'  => 'Daily news search',
        'help'   => $help,
        'fields' => array(kop_ie_field('mode', 'Search the news', 'select', $mode === 'off' && $kind === 'o' ? 'auto' : $mode, array('options' => $options))),
    );
}

function kop_dp_ie_save(array $p, array $v) {
    $kind = ($p[0] ?? '') === 'o' ? 'o' : 'f';
    $id = (int) ($p[1] ?? 0);
    $mode = (string) ($v['mode'] ?? 'auto');
    if (!in_array($mode, $kind === 'o' ? array('auto', 'on') : array('auto', 'on', 'off'), true)) throw new RuntimeException('Pick one of the choices.');
    $was = kop_dp_set($kind, $id, $mode, function_exists('kop_ie_who') ? kop_ie_who() : '');
    if ($was === $mode) return array('message' => 'No change.');
    $words = array('auto' => 'Automatic', 'on' => 'Daily', 'off' => 'Never daily');
    return array('message' => 'Set to ' . $words[$mode] . ' (was ' . $words[$was] . '). The next discovery run uses it.');
}

add_filter('kop_inline_edit_sources', function ($sources) {
    $sources['dsearch'] = array('load' => 'kop_dp_ie_load', 'save' => 'kop_dp_ie_save');
    return $sources;
});
