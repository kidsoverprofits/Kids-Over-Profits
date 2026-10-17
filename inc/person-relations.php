<?php
/**
 * Relationships between people (owner, 2026-10-17: "a place for relationships (family members, business
 * partners, etc)"), set on KOP Tools > People.
 *
 *   {prefix}kop_person_relations   one row per relationship: person_id (kop_people), other_id (the other
 *                                  person's id, 0 when they have none) or other_name (as written), kind
 *                                  (Dropdown Lists 'person-relations': what the other person is to this
 *                                  one), detail, source + source_url, hidden (taken off; kept for Undo).
 *   option kop_person_relations_log  every change, {n, at, by, id, person, before, after}, for Undo.
 *
 * A relationship with another id shows on both people: the other side reads the kind the other way round
 * (kop_prel_reverse(): Parent <-> Child). Ids are resolved through merges, so a merged person keeps theirs.
 *
 * Public (owner, 2026-10-17): facility and company pages print each listed person's relationships under their
 * name (kop_prel_public_lines()), and the network map build (kop-pipeline build-network-graph.js,
 * addPersonRelations()) draws a line between two people on the map. A relationship with someone in the harmed
 * or press pool (a plaintiff, a journalist) is never printed. Every save bumps kop_people_version, so the
 * pages' cached data follows, and the map's rebuild fingerprint (inc/network-rebuild.php) reads the table.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_PERSON_RELATIONS_DB_VERSION', '1');

if (!function_exists('kop_prel_table')) {
    function kop_prel_table(array $opts = array()) {
        $prefix = isset($opts['prefix']) ? (string) $opts['prefix'] : (isset($GLOBALS['wpdb']->prefix) ? $GLOBALS['wpdb']->prefix : '');
        return $prefix . 'kop_person_relations';
    }

    /** Creates the table (MySQL) once per KOP_PERSON_RELATIONS_DB_VERSION. */
    function kop_prel_install($force = false) {
        if (!$force && get_option('kop_person_relations_db') === KOP_PERSON_RELATIONS_DB_VERSION) return;
        global $wpdb;
        $t = kop_prel_table();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            person_id BIGINT UNSIGNED NOT NULL,
            other_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            other_name VARCHAR(255) NOT NULL DEFAULT '',
            kind VARCHAR(64) NOT NULL DEFAULT '',
            detail VARCHAR(500) NOT NULL DEFAULT '',
            source VARCHAR(255) NOT NULL DEFAULT '',
            source_url VARCHAR(500) NOT NULL DEFAULT '',
            hidden TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY person_id (person_id),
            KEY other_id (other_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        update_option('kop_person_relations_db', KOP_PERSON_RELATIONS_DB_VERSION, false);
    }
    if (function_exists('add_action')) add_action('admin_init', 'kop_prel_install');

    /** Is the table there (a site before its first admin load, a test without it)? */
    function kop_prel_ready(array $opts = array()) {
        static $ready = array();
        $t = kop_prel_table($opts);
        if (!isset($ready[$t])) {
            if (empty($opts['pdo']) && isset($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'get_var')) {
                $ready[$t] = $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SHOW TABLES LIKE %s', $t)) === $t;
            } else {
                try {
                    kop_facility_db_rows("SELECT id FROM {$t} LIMIT 1", array(), $opts);
                    $ready[$t] = true;
                } catch (Throwable $e) {
                    $ready[$t] = false;
                }
            }
        }
        return $ready[$t];
    }

    /** The kinds offered (Dropdown Lists 'person-relations'). */
    function kop_prel_kinds() {
        return function_exists('kop_choices') ? array_values((array) kop_choices('person-relations', null, 'offered')) : array('Spouse', 'Parent', 'Child', 'Sibling', 'Business partner', 'Other');
    }

    /** The kind read from the other person's side: Parent -> Child; a kind with no pair reads the same. */
    function kop_prel_reverse($kind) {
        $pairs = array('parent' => 'Child', 'child' => 'Parent', 'grandparent' => 'Grandchild', 'grandchild' => 'Grandparent',
            'employer' => 'Employee', 'employee' => 'Employer', 'mentor' => 'Protege', 'protege' => 'Mentor');
        return $pairs[strtolower(trim((string) $kind))] ?? (string) $kind;
    }
}

if (!function_exists('kop_prel_for_person')) {
    /**
     * One person's relationships (their id resolved through merges), from both sides, shown ones first:
     * [{id, other_id, other_name, kind, detail, source, source_url, hidden, mine}] where kind is what the
     * other person is to this one and mine = false for a row written on the other person. Null without the table.
     */
    function kop_prel_for_person($person_id, array $opts = array()) {
        if (!kop_prel_ready($opts)) return null;
        $state = kop_people_load($opts);
        $me = kop_people_resolve($state, (int) $person_id);
        if ($me === 0) return array();
        // Every id merged into this person counts as this person.
        $mine = array();
        foreach ($state['rows'] as $r) if (kop_people_resolve($state, $r['id']) === $me) $mine[] = (int) $r['id'];
        $in = implode(',', array_fill(0, count($mine), '?'));
        $rows = kop_facility_db_rows('SELECT * FROM ' . kop_prel_table($opts) . " WHERE person_id IN ({$in}) OR other_id IN ({$in}) ORDER BY hidden, id",
            array_merge($mine, $mine), $opts);
        $out = array();
        foreach ($rows as $r) {
            $from = kop_people_resolve($state, (int) $r['person_id']);
            $to = (int) $r['other_id'] > 0 ? kop_people_resolve($state, (int) $r['other_id']) : 0;
            if ($from === $me) {
                $out[] = $r + array('mine' => true, 'other_resolved' => $to);
            } elseif ($to === $me) {
                $flip = $r;
                $flip['kind'] = kop_prel_reverse($r['kind']);
                $flip['other_name'] = $state['rows'][$from]['name'] ?? '';
                $out[] = $flip + array('mine' => false, 'other_resolved' => $from);
            }
        }
        foreach ($out as &$r) {
            $r['other_label'] = $r['other_resolved'] > 0 ? (string) ($state['rows'][$r['other_resolved']]['name'] ?? $r['other_name']) : (string) $r['other_name'];
        }
        unset($r);
        return $out;
    }
}

if (!function_exists('kop_prel_save')) {
    /**
     * Add ($id 0), change or take off ($fields['hidden'] = 1) one relationship, logged for Undo.
     * $fields: other_id, other_name, kind, detail, source, source_url. Returns the row id.
     */
    function kop_prel_save($person_id, $id, array $fields, array $opts = array()) {
        $t = kop_prel_table($opts);
        $clean = array();
        foreach (array('other_name' => 255, 'kind' => 64, 'detail' => 500, 'source' => 255, 'source_url' => 500) as $k => $max) {
            if (array_key_exists($k, $fields)) $clean[$k] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $fields[$k])), 0, $max);
        }
        if (array_key_exists('other_id', $fields)) $clean['other_id'] = max(0, (int) $fields['other_id']);
        if (array_key_exists('hidden', $fields)) $clean['hidden'] = $fields['hidden'] ? 1 : 0;
        if (isset($clean['source_url']) && $clean['source_url'] !== '' && !preg_match('#^https?://#i', $clean['source_url'])) throw new RuntimeException('The source link must start with http:// or https://.');
        $state = kop_people_load($opts);
        $me = kop_people_resolve($state, (int) $person_id);
        if (!empty($clean['other_id']) && kop_people_resolve($state, $clean['other_id']) === $me) throw new RuntimeException('That is the same person.');
        $before = null;
        if ($id) {
            $before = kop_facility_db_rows("SELECT * FROM {$t} WHERE id = ?", array((int) $id), $opts)[0] ?? null;
            if (!$before) throw new RuntimeException('That relationship is gone.');
            $ends = array(kop_people_resolve($state, (int) $before['person_id']), (int) $before['other_id'] > 0 ? kop_people_resolve($state, (int) $before['other_id']) : -1);
            if (!in_array($me, $ends, true)) throw new RuntimeException('That relationship is someone else\'s.');
            if (!$clean) return (int) $id;
            $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($clean)));
            kop_facility_db_exec("UPDATE {$t} SET {$set} WHERE id = ?", array_merge(array_values($clean), array((int) $id)), $opts);
        } else {
            $clean += array('other_id' => 0, 'other_name' => '', 'kind' => '', 'detail' => '', 'source' => '', 'source_url' => '');
            if ($clean['other_id'] > 0) $clean['other_name'] = (string) ($state['rows'][kop_people_resolve($state, $clean['other_id'])]['name'] ?? $clean['other_name']);
            if ($clean['other_id'] === 0 && $clean['other_name'] === '') throw new RuntimeException('Name the other person.');
            if ($clean['kind'] === '') throw new RuntimeException('Pick what the other person is to this one.');
            kop_facility_db_exec("INSERT INTO {$t} (person_id, other_id, other_name, kind, detail, source, source_url) VALUES (?, ?, ?, ?, ?, ?, ?)",
                array($me, $clean['other_id'], $clean['other_name'], $clean['kind'], $clean['detail'], $clean['source'], $clean['source_url']), $opts);
            $id = (int) (kop_facility_db_rows("SELECT MAX(id) AS id FROM {$t} WHERE person_id = ?", array($me), $opts)[0]['id'] ?? 0);
        }
        $after = kop_facility_db_rows("SELECT * FROM {$t} WHERE id = ?", array((int) $id), $opts)[0] ?? null;
        $people = array_values(array_unique(array_filter(array((int) $after['person_id'], (int) $after['other_id']))));
        kop_prel_log(array('id' => (int) $id, 'people' => $people, 'before' => $before, 'after' => $after));
        $GLOBALS['kop_prel_public'] = null;
        if (function_exists('kop_people_version_bump')) kop_people_version_bump();
        return (int) $id;
    }

    function kop_prel_log_all() {
        return function_exists('get_option') ? (array) get_option('kop_person_relations_log', array()) : (array) ($GLOBALS['kop_person_relations_log'] ?? array());
    }

    function kop_prel_log_save(array $log) {
        if (function_exists('update_option')) update_option('kop_person_relations_log', $log, false);
        else $GLOBALS['kop_person_relations_log'] = $log;
    }

    function kop_prel_log(array $entry) {
        $log = kop_prel_log_all();
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        $entry += array('at' => gmdate('Y-m-d H:i:s'), 'by' => $user ? (string) $user->user_login : '', 'undone' => false);
        $entry['n'] = (int) (end($log)['n'] ?? 0) + 1;
        $log[] = $entry;
        kop_prel_log_save(array_slice($log, -500));
        return $entry['n'];
    }

    /** The changes touching one person (either side), newest first. */
    function kop_prel_log_for($person_id) {
        return array_reverse(array_values(array_filter(kop_prel_log_all(), function ($l) use ($person_id) {
            return in_array((int) $person_id, array_map('intval', (array) ($l['people'] ?? array())), true);
        })));
    }

    /** Undo change $n: the relationship back as it was (a new one deleted), while nobody changed it since. */
    function kop_prel_undo($n, array $opts = array()) {
        $log = kop_prel_log_all();
        $at = null;
        foreach ($log as $i => $l) if ((int) $l['n'] === (int) $n) $at = $i;
        if ($at === null || !empty($log[$at]['undone'])) throw new RuntimeException('Nothing to undo there.');
        $l = $log[$at];
        $t = kop_prel_table($opts);
        $now = kop_facility_db_rows("SELECT * FROM {$t} WHERE id = ?", array((int) $l['id']), $opts)[0] ?? null;
        $cols = array('person_id', 'other_id', 'other_name', 'kind', 'detail', 'source', 'source_url', 'hidden');
        foreach ($cols as $c) {
            if (!$now || (string) $now[$c] !== (string) $l['after'][$c]) throw new RuntimeException('That relationship changed since; undo the later change first.');
        }
        if ($l['before'] === null) {
            kop_facility_db_exec("DELETE FROM {$t} WHERE id = ?", array((int) $l['id']), $opts);
        } else {
            $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, $cols));
            kop_facility_db_exec("UPDATE {$t} SET {$set} WHERE id = ?", array_merge(array_map(function ($c) use ($l) { return (string) $l['before'][$c]; }, $cols), array((int) $l['id'])), $opts);
        }
        $log[$at]['undone'] = true;
        kop_prel_log_save($log);
        $GLOBALS['kop_prel_public'] = null;
        if (function_exists('kop_people_version_bump')) kop_people_version_bump();
    }
}

if (!function_exists('kop_prel_public_lines')) {
    /**
     * A person's relationships as the public pages print them: [{kind, name, url, detail, source, source_url}],
     * shown ones only, never one with a harmed or press person. url = a page that lists the other person
     * (their first facility, else company), '' when none. Cached per request.
     */
    function kop_prel_public_lines($person_id, array $opts = array()) {
        if ((int) $person_id <= 0) return array();
        $cache = empty($opts);
        if ($cache && isset($GLOBALS['kop_prel_public'][(int) $person_id])) return $GLOBALS['kop_prel_public'][(int) $person_id];
        $rels = kop_prel_ready($opts) ? kop_prel_for_person((int) $person_id, $opts) : null;
        $out = array();
        if ($rels) {
            $state = kop_people_load($opts);
            foreach ($rels as $r) {
                if (!empty($r['hidden'])) continue;
                $other = (int) $r['other_resolved'];
                if ($other > 0 && ($state['rows'][$other]['pool'] ?? 'industry') !== 'industry') continue;
                $out[] = array('kind' => (string) $r['kind'], 'name' => (string) $r['other_label'], 'url' => $other > 0 ? kop_prel_person_url($other, $opts) : '',
                    'detail' => (string) $r['detail'], 'source' => (string) $r['source'], 'source_url' => (string) $r['source_url']);
            }
        }
        if ($cache) $GLOBALS['kop_prel_public'][(int) $person_id] = $out;
        return $out;
    }

    /** A public page that lists this person: the first facility naming them, else the first company; ''. */
    function kop_prel_person_url($person_id, array $opts = array()) {
        if (!function_exists('kop_people_roles_of')) return '';
        $first = array('facility' => 0, 'operator' => 0);
        foreach (kop_people_roles_of((int) $person_id, $opts) as $r) {
            if (isset($first[$r['record_kind']]) && !$first[$r['record_kind']]) $first[$r['record_kind']] = (int) $r['record_id'];
        }
        if ($first['facility'] && function_exists('kop_facility_page_url')) return (string) kop_facility_page_url($first['facility']);
        if ($first['operator'] && function_exists('kop_operator_page_url')) return (string) kop_operator_page_url($first['operator']);
        return '';
    }
}
