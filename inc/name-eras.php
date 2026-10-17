<?php
/**
 * Which company ran a program under which of its names, and when: set by hand
 * in the directory's Alternate names table ("Run by then", admins), one row
 * per record and name in {prefix}kop_name_eras. A name's era set here wins
 * over the guesses inc/facility-eras.php makes from the map and from company
 * names inside a name:
 *   - the directory lists the record under that company by that name
 *     (kop_v2_get_facilities_projects(): nameUnderOperator), and every
 *     facility in the feed carries its eras ('nameEras') for the table;
 *   - the facility page's "Formerly" line gives the company and years.
 * Not yet read by the network map build (kop-pipeline; docs/PLAN.md 3.33).
 *
 *   POST kop/v1/name-eras/save  {facility_id, name, operator, operator_id?, start, end}   (all empty = clear)
 *   POST kop/v1/name-eras/undo  {token}
 * Every change logged with the row before and after (option kop_name_eras_log); Undo
 * puts the row back while nothing has changed it since.
 * Test: scripts/test-name-eras.php
 */

if (!defined('ABSPATH')) {
    exit;
}

function kop_name_eras_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_name_eras';
}

function kop_name_eras_install() {
    global $wpdb;
    if (get_option('kop_name_eras_db') === '1') return;
    $t = kop_name_eras_table();
    $wpdb->query("CREATE TABLE IF NOT EXISTS `{$t}` (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        facility_id BIGINT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        name_key VARCHAR(255) NOT NULL,
        operator_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        operator_name VARCHAR(255) NOT NULL DEFAULT '',
        start_year SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        end_year SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        updated_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY facility (facility_id),
        KEY operator (operator_id)
    ) " . $wpdb->get_charset_collate());
    update_option('kop_name_eras_db', '1', false);
}
add_action('admin_init', 'kop_name_eras_install');
add_action('rest_api_init', 'kop_name_eras_install');

/** A name for matching: no "Previously" lead-in, spacing and case aside. */
function kop_name_eras_key($name) {
    $s = trim(preg_replace('/\s+/u', ' ', (string) $name));
    $s = trim(preg_replace('/^\s*(?:previously|formerly|former|prior(?:\s+to)?)\b[\s:;,.\-–—]*/iu', '', $s));
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/** "Three Springs Inc., 1998 to 2008" (company, years; either may be missing). */
function kop_name_eras_label(array $era) {
    $years = function_exists('kop_facility_eras_years_label')
        ? kop_facility_eras_years_label((int) $era['start_year'] ?: null, (int) $era['end_year'] ?: null)
        : trim(((int) $era['start_year'] ?: '') . ' to ' . ((int) $era['end_year'] ?: ''), ' to');
    return implode(', ', array_filter(array(trim((string) $era['operator_name']), $years), 'strlen'));
}

/** facility id => [name key => era row], every era (memoised per request). */
function kop_name_eras_all($reset = false) {
    static $memo = null;
    if ($reset) $memo = null;
    if ($memo !== null) return $memo;
    global $wpdb;
    $memo = array();
    $t = kop_name_eras_table();
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) !== $t) return $memo;
    foreach ((array) $wpdb->get_results("SELECT * FROM `{$t}` ORDER BY id", ARRAY_A) as $row) {
        $memo[(int) $row['facility_id']][$row['name_key']] = $row;
    }
    return $memo;
}

function kop_name_eras_for($facility_id) {
    $all = kop_name_eras_all();
    return $all[(int) $facility_id] ?? array();
}

/** The feed's shape of a record's eras: [{name, operatorId, operator, start, end, label}]. */
function kop_name_eras_feed($facility_id) {
    $out = array();
    foreach (kop_name_eras_for($facility_id) as $e) {
        $out[] = array(
            'name' => $e['name'], 'operatorId' => (int) $e['operator_id'], 'operator' => $e['operator_name'],
            'start' => (int) $e['start_year'] ?: null, 'end' => (int) $e['end_year'] ?: null, 'label' => kop_name_eras_label($e),
        );
    }
    return $out;
}

/**
 * The name a record had under a company by its hand-set eras, shaped like
 * kop_facility_eras_name_under(): latest first, the rest in 'also'. null when
 * no era names the company. $company_names: the company's name and other names.
 */
function kop_name_eras_name_under($facility_id, $operator_id, array $company_names, array $eras = null) {
    $keys = array();
    foreach ($company_names as $n) {
        $k = function_exists('kop_facility_eras_company_key') ? kop_facility_eras_company_key($n) : strtolower(trim((string) $n));
        if ($k !== '') $keys[$k] = true;
    }
    $hits = array();
    foreach ($eras ?? kop_name_eras_for($facility_id) as $e) {
        $ok = function_exists('kop_facility_eras_company_key') ? kop_facility_eras_company_key($e['operator_name']) : strtolower(trim($e['operator_name']));
        if (((int) $e['operator_id'] && (int) $e['operator_id'] === (int) $operator_id) || ($ok !== '' && isset($keys[$ok]))) $hits[] = $e;
    }
    if (!$hits) return null;
    usort($hits, function ($a, $b) {
        return ((int) $b['end_year'] ?: 9999) <=> ((int) $a['end_year'] ?: 9999) ?: ((int) $b['start_year'] <=> (int) $a['start_year']);
    });
    $first = array_shift($hits);
    $years = function_exists('kop_facility_eras_years_label') ? kop_facility_eras_years_label((int) $first['start_year'] ?: null, (int) $first['end_year'] ?: null) : '';
    return array('name' => $first['name'], 'years' => $years, 'from' => 'past', 'also' => array_map(function ($e) { return $e['name']; }, $hits));
}

/** The record's names an era can be set on: its name, current name, past and other names, merged names. */
function kop_name_eras_record_names(array $doc) {
    $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
    $names = array((string) ($ident['name'] ?? ''), (string) ($ident['currentName'] ?? ''));
    foreach (array('pastNames', 'otherNames') as $f) {
        foreach ((array) ($ident[$f] ?? array()) as $n) {
            if (!is_string($n)) continue;
            foreach (function_exists('kop_dirnames_split') ? kop_dirnames_split($n) : array($n) as $part) $names[] = $part;
        }
    }
    foreach ((array) ($doc['legacy']['mergedFacilities'] ?? array()) as $m) {
        if (is_array($m) && is_string($m['name'] ?? null)) $names[] = $m['name'];
    }
    $out = array();
    foreach ($names as $n) {
        $k = kop_name_eras_key($n);
        if ($k !== '' && !isset($out[$k])) $out[$k] = trim($n);
    }
    return $out;
}

/** A company id by its exact name or other name ('' / 0 when none or more than one). */
function kop_name_eras_find_operator($name) {
    global $wpdb;
    $key = function_exists('kop_facility_eras_company_key') ? kop_facility_eras_company_key($name) : strtolower(trim($name));
    if ($key === '') return 0;
    $found = array();
    foreach ((array) $wpdb->get_results("SELECT id, name, json_data FROM `{$wpdb->prefix}kop_operators`", ARRAY_A) as $row) {
        $json = json_decode((string) $row['json_data'], true);
        $op = is_array($json['operator'] ?? null) ? $json['operator'] : array();
        $names = array_merge(array($row['name'], (string) ($op['name'] ?? ''), (string) ($op['currentName'] ?? '')), array_filter((array) ($op['otherNames'] ?? array()), 'is_string'));
        foreach ($names as $n) {
            if ($n !== '' && kop_facility_eras_company_key($n) === $key) {
                $found[(int) $row['id']] = true;
                break;
            }
        }
    }
    return count($found) === 1 ? (int) key($found) : 0;
}

function kop_name_eras_year($v, $label) {
    $v = trim((string) $v);
    if ($v === '') return 0;
    $max = (int) gmdate('Y') + 1;
    if (!preg_match('/^\d{4}$/', $v) || (int) $v < 1900 || (int) $v > $max) {
        throw new RuntimeException("{$label} must be a year from 1900 to {$max}.");
    }
    return (int) $v;
}

function kop_name_eras_row($facility_id, $key) {
    global $wpdb;
    $t = kop_name_eras_table();
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$t}` WHERE facility_id = %d AND name_key = %s ORDER BY id LIMIT 1", (int) $facility_id, $key), ARRAY_A) ?: null;
}

/** Puts a row as given (null = none), for save and undo. */
function kop_name_eras_put($facility_id, $key, $row) {
    global $wpdb;
    $t = kop_name_eras_table();
    $wpdb->query($wpdb->prepare("DELETE FROM `{$t}` WHERE facility_id = %d AND name_key = %s", (int) $facility_id, $key));
    if ($row) {
        $wpdb->insert($t, array(
            'facility_id' => (int) $facility_id, 'name' => (string) $row['name'], 'name_key' => $key,
            'operator_id' => (int) $row['operator_id'], 'operator_name' => (string) $row['operator_name'],
            'start_year' => (int) $row['start_year'], 'end_year' => (int) $row['end_year'],
            'updated_by' => get_current_user_id(), 'updated_at' => current_time('mysql'),
        ));
    }
    kop_name_eras_all(true);
    if (function_exists('kop_facility_pages_flush_index')) kop_facility_pages_flush_index();
    if (function_exists('kop_facility_page_url')) {
        $url = (string) kop_facility_page_url((int) $facility_id);
        if ($url !== '') do_action('litespeed_purge_url', $url);
    }
}

function kop_name_eras_same($a, $b) {
    $pick = function ($r) {
        return $r ? array((string) $r['name'], (int) $r['operator_id'], (string) $r['operator_name'], (int) $r['start_year'], (int) $r['end_year']) : null;
    };
    return $pick($a) === $pick($b);
}

function kop_name_eras_save(array $p) {
    kop_name_eras_install();
    $fid = (int) ($p['facility_id'] ?? 0);
    $stored = kop_facility_load($fid, kop_ie_facility_opts());
    if (!$stored) throw new RuntimeException("Facility #{$fid} does not exist.");
    $names = kop_name_eras_record_names($stored['doc']);
    $key = kop_name_eras_key($p['name'] ?? '');
    if ($key === '' || !isset($names[$key])) {
        throw new RuntimeException('That name is not one of this record\'s names; add it with Edit names first.');
    }
    $operator = trim(preg_replace('/\s+/u', ' ', (string) ($p['operator'] ?? '')));
    $start = kop_name_eras_year($p['start'] ?? '', 'The first year');
    $end = kop_name_eras_year($p['end'] ?? '', 'The last year');
    if ($start && $end && $start > $end) throw new RuntimeException('The first year is after the last year.');
    $before = kop_name_eras_row($fid, $key);
    $after = null;
    if ($operator !== '' || $start || $end) {
        $oid = (int) ($p['operator_id'] ?? 0);
        if ($oid) {
            global $wpdb;
            $real = $wpdb->get_var($wpdb->prepare("SELECT name FROM `{$wpdb->prefix}kop_operators` WHERE id = %d", $oid));
            if ($real === null) $oid = 0;
            elseif ($operator === '') $operator = (string) $real;
        }
        if (!$oid && $operator !== '') $oid = kop_name_eras_find_operator($operator);
        $after = array('name' => $names[$key], 'operator_id' => $oid, 'operator_name' => $operator, 'start_year' => $start, 'end_year' => $end);
    }
    if (kop_name_eras_same($before, $after)) {
        return array('message' => 'Nothing to change.', 'token' => '', 'era' => $after ? kop_name_eras_feed_row($after) : null);
    }
    kop_name_eras_put($fid, $key, $after);
    $token = wp_generate_password(16, false);
    $log = get_option('kop_name_eras_log', array());
    if (!is_array($log)) $log = array();
    $log[] = array('token' => $token, 'facility_id' => $fid, 'key' => $key, 'before' => $before, 'after' => kop_name_eras_row($fid, $key),
        'by' => get_current_user_id(), 'at' => current_time('mysql'), 'undone' => false);
    update_option('kop_name_eras_log', array_slice($log, -300), false);
    $label = $after ? kop_name_eras_label($after) : '';
    return array(
        'message' => $after ? sprintf('"%s": %s.', $names[$key], $label !== '' ? $label : 'saved') : sprintf('"%s": company and years cleared.', $names[$key]),
        'token'   => $token,
        'era'     => $after ? kop_name_eras_feed_row($after) : null,
    );
}

function kop_name_eras_feed_row(array $e) {
    return array('name' => $e['name'], 'operatorId' => (int) $e['operator_id'], 'operator' => $e['operator_name'],
        'start' => (int) $e['start_year'] ?: null, 'end' => (int) $e['end_year'] ?: null, 'label' => kop_name_eras_label($e));
}

function kop_name_eras_undo($token) {
    $log = get_option('kop_name_eras_log', array());
    foreach ((array) $log as $i => $entry) {
        if ($token === '' || ($entry['token'] ?? '') !== (string) $token) continue;
        if (!empty($entry['undone'])) throw new RuntimeException('Already undone.');
        $now = kop_name_eras_row($entry['facility_id'], $entry['key']);
        if (!kop_name_eras_same($now, $entry['after'])) throw new RuntimeException('It has changed since; set it again by hand.');
        kop_name_eras_put($entry['facility_id'], $entry['key'], $entry['before']);
        $log[$i]['undone'] = true;
        update_option('kop_name_eras_log', $log, false);
        return array('message' => 'Undone.', 'era' => $entry['before'] ? kop_name_eras_feed_row($entry['before']) : null);
    }
    throw new RuntimeException('That change is not in the log.');
}

add_action('rest_api_init', function () {
    $can = function () {
        return current_user_can('manage_options');
    };
    foreach (array('save' => function (WP_REST_Request $r) { return kop_name_eras_save($r->get_params()); },
                   'undo' => function (WP_REST_Request $r) { return kop_name_eras_undo((string) $r->get_param('token')); }) as $route => $fn) {
        register_rest_route('kop/v1', '/name-eras/' . $route, array(
            'methods'             => 'POST',
            'permission_callback' => $can,
            'callback'            => function (WP_REST_Request $r) use ($fn) {
                try {
                    return rest_ensure_response($fn($r));
                } catch (Throwable $e) {
                    return new WP_Error('kop_name_eras', $e->getMessage(), array('status' => 400));
                }
            },
        ));
    }
});

// Merge Duplicates moves a dropped record's eras to the kept one.
add_filter('kop_facility_merge_ref_tables', function ($tables, $prefix = null) {
    global $wpdb;
    $tables[] = array('t' => ($prefix ?: $wpdb->prefix) . 'kop_name_eras', 'c' => 'facility_id', 'k' => 'id');
    return $tables;
}, 10, 2);
