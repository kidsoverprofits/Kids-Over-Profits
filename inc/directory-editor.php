<?php
/**
 * KOP Tools > Directory Editor: the parent company cards of the TTI Facility
 * Directory (/tti-program-index/, "By parent company") in one screen.
 *
 * A card on the directory is a company record ({prefix}kop_operators) with
 * the programs {prefix}kop_operator_facilities ties to it; the directory shows
 * a company only when it has a name and at least two named programs, and one
 * card per name (the lowest id wins). From here an admin can, per company:
 *
 *   - edit the fields the card shows (name, other names, headquarters,
 *     founded, status, websites, parent companies); "Every field" opens the
 *     site's own edit dialog (inc/inline-edit.php, operator:<id>:all);
 *   - take the card off the directory and put it back (operator.directoryHidden;
 *     the /operator/ page stays), or delete the company record;
 *   - merge it into another company: every row pointing at it
 *     (kop_record_field_company_refs(): programs, news, filings, investors)
 *     moves, its names become the other's other names, empty fields are filled,
 *     and its /operator/ address redirects;
 *   - add a program, take one off, set a link current / past / other, and move
 *     ticked programs to another company (optionally kept here as past); a
 *     moved program whose "Operator" field names the old company names the
 *     new one;
 *   - open a program's own edit dialog (facility:<id>:all).
 * And add a new company.
 *
 * Every change is a row of {prefix}kop_directory_edits with the rows as they
 * were and as they were left, so Undo puts back exactly that change while
 * nothing has changed them since (Recent changes, and the notice after each
 * action). Guarded with function_exists so scripts/test-directory-editor.php
 * can load it next to WordPress stubs.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (file_exists(__DIR__ . '/admin-kit.php')) require_once __DIR__ . '/admin-kit.php';

if (!defined('KOP_DIRED_DB_VERSION')) {
    define('KOP_DIRED_DB_VERSION', '1');
}

if (!function_exists('kop_dired_table')) {
    function kop_dired_table($which = 'log') {
        global $wpdb;
        $names = array(
            'log'   => 'kop_directory_edits',
            'ops'   => 'kop_operators',
            'links' => 'kop_operator_facilities',
        );
        return $wpdb->prefix . $names[$which];
    }
}

if (!function_exists('kop_dired_install')) {
    function kop_dired_install($force = false) {
        if (!$force && get_option('kop_directory_edits_db') === KOP_DIRED_DB_VERSION) return;
        global $wpdb;
        $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_dired_table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            action VARCHAR(32) NOT NULL,
            operator_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            other_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            before_json LONGTEXT NULL,
            after_json LONGTEXT NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            done_by VARCHAR(60) NOT NULL DEFAULT '',
            done_at DATETIME NOT NULL,
            undone_by VARCHAR(60) NOT NULL DEFAULT '',
            undone_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY operator_id (operator_id)
        ) " . $wpdb->get_charset_collate());
        update_option('kop_directory_edits_db', KOP_DIRED_DB_VERSION, false);
    }
    if (function_exists('add_action')) add_action('admin_init', 'kop_dired_install', 5);
}

/* ---- Reading ------------------------------------------------------------------- */

if (!function_exists('kop_dired_op_row')) {
    /** A company's row (id, unique_name, name, json_data, document_folder_id, ...), or null. */
    function kop_dired_op_row($id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . kop_dired_table('ops') . '` WHERE id = %d', (int) $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('kop_dired_json')) {
    /** The stored record of a row: {operator: {...}, legacy_blocks: {...}}. */
    function kop_dired_json(array $row) {
        $json = json_decode((string) ($row['json_data'] ?? ''), true);
        if (!is_array($json)) $json = array();
        if (!isset($json['operator']) || !is_array($json['operator'])) $json['operator'] = array();
        return $json;
    }
}

if (!function_exists('kop_dired_name')) {
    /** The name the card shows. */
    function kop_dired_name(array $row) {
        $op = kop_dired_json($row)['operator'];
        foreach (array($op['name'] ?? '', $op['currentName'] ?? '', $row['name'] ?? '', $row['unique_name'] ?? '') as $n) {
            if (is_string($n) && trim($n) !== '') return trim($n);
        }
        return 'Company #' . (int) ($row['id'] ?? 0);
    }
}

if (!function_exists('kop_dired_key')) {
    function kop_dired_key($name) {
        return function_exists('kop_facility_name_key') ? kop_facility_name_key((string) $name) : strtolower(trim((string) $name));
    }
}

if (!function_exists('kop_dired_text_key')) {
    /** Two names written the same but for case, spaces and punctuation ("Inc." and "Holdings" still count): for keeping lists of names free of repeats. */
    function kop_dired_text_key($name) {
        return preg_replace('/[^a-z0-9]+/', '', strtolower((string) $name));
    }
}

if (!function_exists('kop_dired_links')) {
    /** Link rows of one company (or of every company: 0), in the directory's order. */
    function kop_dired_links($operator_id = 0) {
        global $wpdb;
        $where = (int) $operator_id ? $wpdb->prepare(' WHERE operator_id = %d', (int) $operator_id) : '';
        $rows = $wpdb->get_results('SELECT operator_id, facility_id, relationship, sort_order FROM `' . kop_dired_table('links') . '`' . $where
            . ' ORDER BY operator_id, sort_order, facility_id', ARRAY_A);
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('kop_dired_link')) {
    function kop_dired_link($operator_id, $facility_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT operator_id, facility_id, relationship, sort_order FROM `' . kop_dired_table('links')
            . '` WHERE operator_id = %d AND facility_id = %d', (int) $operator_id, (int) $facility_id), ARRAY_A);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('kop_dired_facilities')) {
    /** id => {id, name, city, state, country, status, start_year, end_year} for the given ids. */
    function kop_dired_facilities(array $ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = array();
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $wpdb->get_results('SELECT id, name, city, state, country, status, start_year, end_year FROM facilities_v2 WHERE id IN (' . implode(',', $chunk) . ')', ARRAY_A);
            foreach ((array) $rows as $r) $out[(int) $r['id']] = $r;
        }
        return $out;
    }
}

if (!function_exists('kop_dired_companies')) {
    /**
     * Every company, by id: {row, name, op, links: [link + facility], hidden,
     * state, dupe_of}. state is what the directory does with it:
     *   shown   a card on the directory
     *   hidden  taken off by hand (operator.directoryHidden)
     *   few     fewer than two named programs, so no card
     *   dupe    another company of the same name has the card (dupe_of)
     *   noname  no name, so no card
     * The same order and rules as projectToGroup() in js/tti-program-index.js.
     */
    function kop_dired_companies() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM `' . kop_dired_table('ops') . '` ORDER BY id', ARRAY_A);
        $links = array();
        foreach (kop_dired_links() as $l) $links[(int) $l['operator_id']][] = $l;
        $all_ids = array();
        foreach ($links as $list) foreach ($list as $l) $all_ids[] = (int) $l['facility_id'];
        $facilities = kop_dired_facilities($all_ids);
        $out = array();
        $seen = array();
        foreach ((array) $rows as $row) {
            $id = (int) $row['id'];
            $json = kop_dired_json($row);
            $op = $json['operator'];
            $list = array();
            $named = 0;
            foreach ($links[$id] ?? array() as $l) {
                $f = $facilities[(int) $l['facility_id']] ?? null;
                $l['facility'] = $f;
                if ($f && trim((string) $f['name']) !== '') $named++;
                $list[] = $l;
            }
            $card_name = trim((string) (($op['name'] ?? '') ?: ($op['currentName'] ?? '')));
            $state = 'shown';
            $dupe_of = 0;
            if ($card_name === '') $state = 'noname';
            elseif (!empty($op['directoryHidden'])) $state = 'hidden';
            elseif ($named < 2) $state = 'few';
            else {
                $k = strtolower($card_name);
                if (isset($seen[$k])) { $state = 'dupe'; $dupe_of = $seen[$k]; }
                else $seen[$k] = $id;
            }
            $out[$id] = array('row' => $row, 'name' => kop_dired_name($row), 'op' => $op, 'links' => $list,
                'named' => $named, 'hidden' => !empty($op['directoryHidden']), 'state' => $state, 'dupe_of' => $dupe_of);
        }
        return $out;
    }
}

if (!function_exists('kop_dired_state_words')) {
    function kop_dired_state_words(array $c, array $all = array()) {
        switch ($c['state']) {
            case 'shown': return array('On the directory', 'ok');
            case 'hidden': return array('Taken off the directory', 'off');
            case 'few': return array($c['named'] === 1 ? 'Not shown: one program' : 'Not shown: no programs', 'off');
            case 'dupe':
                $other = $all[$c['dupe_of']]['name'] ?? ('company #' . $c['dupe_of']);
                return array('Not shown: ' . $other . ' (#' . $c['dupe_of'] . ') has the same name', 'warn');
            case 'noname': return array('Not shown: no name', 'warn');
        }
        return array('', '');
    }
}

if (!function_exists('kop_dired_company_url')) {
    function kop_dired_company_url($id) {
        return function_exists('kop_operator_page_url') ? (string) kop_operator_page_url((int) $id) : '';
    }
}

if (!function_exists('kop_dired_slug')) {
    /** The company's /operator/ slug now, '' when it has no page. */
    function kop_dired_slug($id) {
        if (!function_exists('kop_operator_pages_index')) return '';
        $index = kop_operator_pages_index();
        return (string) ($index['ids'][(int) $id]['slug'] ?? '');
    }
}

/* ---- Old /operator/ addresses -------------------------------------------------- */

if (!function_exists('kop_dired_remember_slug')) {
    /** /operator/<slug>/ of a renamed or merged company goes to company $to_id from now on. */
    function kop_dired_remember_slug($slug, $to_id) {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '' || (int) $to_id <= 0) return;
        $map = (array) get_option('kop_dired_redirects', array());
        $map[$slug] = (int) $to_id;
        update_option('kop_dired_redirects', $map, false);
    }
}

if (!function_exists('kop_dired_redirect_url')) {
    /** Where /operator/<slug>/ goes now ('' when this screen never moved it). Read only once the slug is no company's own. */
    function kop_dired_redirect_url($slug) {
        $slug = strtolower(trim((string) $slug));
        $map = function_exists('get_option') ? (array) get_option('kop_dired_redirects', array()) : array();
        $seen = array();
        // A company merged into one that was merged later: follow the chain.
        while ($slug !== '' && isset($map[$slug]) && !isset($seen[$slug])) {
            $seen[$slug] = true;
            $to = (int) $map[$slug];
            $url = kop_dired_company_url($to);
            if ($url !== '') return $url;
            $slug = '';
        }
        return '';
    }
}

/* ---- The log --------------------------------------------------------------------- */

if (!function_exists('kop_dired_log')) {
    function kop_dired_log($action, $operator_id, $other_id, $before, $after, $who, $note = '') {
        global $wpdb;
        $wpdb->insert(kop_dired_table(), array(
            'action' => $action, 'operator_id' => (int) $operator_id, 'other_id' => (int) $other_id,
            'before_json' => $before === null ? null : wp_json_encode($before),
            'after_json' => $after === null ? null : wp_json_encode($after),
            'note' => substr((string) $note, 0, 255), 'done_by' => substr((string) $who, 0, 60), 'done_at' => gmdate('Y-m-d H:i:s'),
        ));
        return (int) $wpdb->insert_id;
    }
}

if (!function_exists('kop_dired_log_rows')) {
    function kop_dired_log_rows($limit = 100, $operator_id = 0) {
        global $wpdb;
        $where = (int) $operator_id ? $wpdb->prepare(' WHERE operator_id = %d OR other_id = %d', (int) $operator_id, (int) $operator_id) : '';
        $rows = $wpdb->get_results('SELECT * FROM ' . kop_dired_table() . $where . ' ORDER BY id DESC LIMIT ' . max(1, (int) $limit), ARRAY_A);
        return is_array($rows) ? $rows : array();
    }
}

if (!function_exists('kop_dired_log_get')) {
    function kop_dired_log_get($id) {
        global $wpdb;
        $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_dired_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
        return is_array($e) ? $e : null;
    }
}

/* ---- Writing helpers ----------------------------------------------------------- */

if (!function_exists('kop_dired_same_row')) {
    /** The stored company fields match (updated_at aside). */
    function kop_dired_same_row(?array $a, ?array $b) {
        if (!$a || !$b) return false;
        foreach (array('unique_name', 'name', 'json_data', 'document_folder_id') as $f) {
            if ((string) ($a[$f] ?? '') !== (string) ($b[$f] ?? '')) return false;
        }
        return true;
    }
}

if (!function_exists('kop_dired_write_op')) {
    /** Write a company's name and record. updated_at moves forward, so the caches that read it move too. */
    function kop_dired_write_op($id, array $json, $name = null, $folder = false) {
        global $wpdb;
        $data = array('json_data' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => gmdate('Y-m-d H:i:s'));
        if ($name !== null) $data['name'] = (string) $name;
        if ($folder !== false) $data['document_folder_id'] = $folder;
        $wpdb->update(kop_dired_table('ops'), $data, array('id' => (int) $id));
        return kop_dired_op_row($id);
    }
}

if (!function_exists('kop_dired_put_row')) {
    /** Put a company row back as it was (Undo); updated_at is now. */
    function kop_dired_put_row(array $row) {
        global $wpdb;
        $row['updated_at'] = gmdate('Y-m-d H:i:s');
        if (kop_dired_op_row((int) $row['id'])) {
            $id = (int) $row['id'];
            unset($row['id']);
            $wpdb->update(kop_dired_table('ops'), $row, array('id' => $id));
        } else {
            $wpdb->insert(kop_dired_table('ops'), $row);
        }
    }
}

if (!function_exists('kop_dired_ref_tables')) {
    /** Tables that point at a company, [table => column], only those that exist. */
    function kop_dired_ref_tables() {
        global $wpdb;
        $refs = function_exists('kop_record_field_company_refs') ? kop_record_field_company_refs() : array(
            kop_dired_table('links') => 'operator_id',
            $wpdb->prefix . 'kop_operator_links' => 'operator_id',
        );
        $out = array();
        foreach ($refs as $t => $c) {
            $exists = function_exists('kop_facility_pages_table_exists') ? kop_facility_pages_table_exists($t)
                : (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t));
            if ($exists) $out[$t] = $c;
        }
        return $out;
    }
}

if (!function_exists('kop_dired_ref_rows')) {
    /** [table => rows] of every row pointing at company $id. */
    function kop_dired_ref_rows($id) {
        global $wpdb;
        $out = array();
        foreach (kop_dired_ref_tables() as $t => $c) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$t}` WHERE `{$c}` = %d", (int) $id), ARRAY_A);
            if ($rows) $out[$t] = $rows;
        }
        return $out;
    }
}

if (!function_exists('kop_dired_ref_words')) {
    /** "12 programs, 3 news articles" for what points at a company. */
    function kop_dired_ref_words(array $refs) {
        global $wpdb;
        $names = array(
            kop_dired_table('links') => array('program', 'programs'),
            $wpdb->prefix . 'kop_operator_links' => array('article or case', 'articles and cases'),
            $wpdb->prefix . 'kop_nonprofit_links' => array('tax filing link', 'tax filing links'),
            $wpdb->prefix . 'kop_business_links' => array('business filing link', 'business filing links'),
            $wpdb->prefix . 'kop_investor_links' => array('investor stake', 'investor stakes'),
        );
        $parts = array();
        foreach ($refs as $t => $rows) {
            $n = count($rows);
            if (!$n) continue;
            $w = $names[$t] ?? array('row in ' . $t, 'rows in ' . $t);
            $parts[] = $n . ' ' . $w[$n === 1 ? 0 : 1];
        }
        return implode(', ', $parts);
    }
}

if (!function_exists('kop_dired_where')) {
    /** A row as a WHERE for $wpdb->delete(): NULL columns left out. */
    function kop_dired_where(array $row) {
        return array_filter($row, function ($v) { return $v !== null; });
    }
}

if (!function_exists('kop_dired_after')) {
    /** Move the caches and pages that print these companies and programs. */
    function kop_dired_after(array $operator_ids, array $facility_ids = array()) {
        $operator_ids = array_values(array_unique(array_filter(array_map('intval', $operator_ids))));
        $facility_ids = array_values(array_unique(array_filter(array_map('intval', $facility_ids))));
        if (function_exists('kop_operator_pages_flush_index')) kop_operator_pages_flush_index();
        if (function_exists('kop_record_changed')) {
            if ($operator_ids) kop_record_changed('operator', $operator_ids);
            if ($facility_ids) kop_record_changed('facility', $facility_ids);
        }
        if (function_exists('do_action')) {
            foreach ($operator_ids as $oid) {
                $url = kop_dired_company_url($oid);
                if ($url !== '') do_action('litespeed_purge_url', $url);
            }
        }
    }
}

if (!function_exists('kop_dired_lines')) {
    /** Text lines as a list: trimmed, empty ones and repeats dropped. */
    function kop_dired_lines($text) {
        $out = array();
        foreach (preg_split('/\R/', (string) $text) as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $out, true)) $out[] = $line;
        }
        return $out;
    }
}

if (!function_exists('kop_dired_list_text')) {
    /** A stored list as the lines the form shows (a link object shows its address). */
    function kop_dired_list_text($list) {
        $lines = array();
        foreach ((array) $list as $v) {
            if (is_array($v)) $v = $v['url'] ?? ($v['name'] ?? '');
            if (is_string($v) && trim($v) !== '') $lines[] = trim($v);
        }
        return implode("\n", $lines);
    }
}

/* ---- Companies ------------------------------------------------------------------- */

if (!function_exists('kop_dired_fields')) {
    /** The card's fields this screen edits: key => [label, 'text'|'lines']. */
    function kop_dired_fields() {
        return array(
            'name'              => array('Name', 'text'),
            'otherNames'        => array('Other names (one a line)', 'lines'),
            'headquartersCity'  => array('Headquarters city', 'text'),
            'headquartersState' => array('Headquarters state', 'text'),
            'founded'           => array('Founded', 'text'),
            'status'            => array('Status (e.g. Active, Defunct, Acquired)', 'text'),
            'websites'          => array('Websites (one a line)', 'lines'),
            'parentCompanies'   => array('Parent companies (one a line)', 'lines'),
        );
    }
}

if (!function_exists('kop_dired_name_taken')) {
    /** Another company (not $except) whose name is $name's, or null. */
    function kop_dired_name_taken($name, $except = 0) {
        $key = kop_dired_key($name);
        if ($key === '') return null;
        global $wpdb;
        foreach ((array) $wpdb->get_results('SELECT * FROM `' . kop_dired_table('ops') . '`', ARRAY_A) as $row) {
            if ((int) $row['id'] === (int) $except) continue;
            if (kop_dired_key(kop_dired_name($row)) === $key || kop_dired_key((string) $row['name']) === $key) return $row;
        }
        return null;
    }
}

if (!function_exists('kop_dired_edit')) {
    /**
     * Save the card's fields of company $id from the form ($in: the fields of
     * kop_dired_fields(), lists as text; keep_old = the old name stays as an
     * other name when the name changes). Only fields that changed are written,
     * so a list held as link objects stays as it is when its lines are unchanged.
     * Returns [message, log id].
     */
    function kop_dired_edit($id, array $in, $who) {
        $before = kop_dired_op_row($id);
        if (!$before) throw new RuntimeException('That company is not on file any more.');
        $json = kop_dired_json($before);
        $op = $json['operator'];
        $old_name = kop_dired_name($before);
        $changed = array();
        foreach (kop_dired_fields() as $k => $f) {
            if (!array_key_exists($k, $in)) continue;
            if ($f[1] === 'lines') {
                $new = kop_dired_lines($in[$k]);
                if (implode("\n", $new) === kop_dired_list_text($op[$k] ?? array())) continue;
                $op[$k] = $new;
            } else {
                $new = trim(preg_replace('/\s+/', ' ', (string) $in[$k]));
                if ($new === trim((string) ($op[$k] ?? ''))) continue;
                $op[$k] = $new;
            }
            $changed[] = $k;
        }
        $name = trim((string) ($op['name'] ?? ''));
        $renamed = in_array('name', $changed, true);
        if ($renamed) {
            if ($name === '') throw new RuntimeException('A company needs a name.');
            $other = kop_dired_name_taken($name, $id);
            if ($other) {
                throw new RuntimeException('"' . kop_dired_name($other) . '" (#' . (int) $other['id'] . ') already has that name. To make them one company, use "Merge into another company".');
            }
            if (!empty($in['keep_old']) && kop_dired_text_key($old_name) !== kop_dired_text_key($name)) {
                $names = array_values((array) ($op['otherNames'] ?? array()));
                $have = array();
                foreach ($names as $n) if (is_string($n)) $have[kop_dired_text_key($n)] = true;
                if (!isset($have[kop_dired_text_key($old_name)])) $names[] = $old_name;
                $op['otherNames'] = $names;
            }
        }
        if (!$changed) return array('Nothing changed.', 0);
        $json['operator'] = $op;
        if ($renamed && isset($json['legacy_blocks']) && is_array($json['legacy_blocks']) && array_key_exists('name', $json['legacy_blocks'])) {
            // The project name the directory falls back to; the record key (unique_name) stays.
            $json['legacy_blocks']['name'] = $name;
        }
        $slug = $renamed ? kop_dired_slug($id) : '';
        $after = kop_dired_write_op($id, $json, $renamed ? $name : null);
        $log = kop_dired_log('edit', $id, 0, array('row' => $before), array('row' => $after, 'slug' => $slug), $who, implode(', ', $changed));
        if ($renamed && $slug !== '') kop_dired_remember_slug($slug, $id);
        kop_dired_after(array($id), kop_dired_link_ids($id));
        return array('Saved ' . kop_dired_name($after) . '.', $log);
    }
}

if (!function_exists('kop_dired_link_ids')) {
    function kop_dired_link_ids($operator_id) {
        return array_map(function ($l) { return (int) $l['facility_id']; }, kop_dired_links($operator_id));
    }
}

if (!function_exists('kop_dired_set_hidden')) {
    /** Take company $id's card off the directory ($hide) or put it back. Returns [message, log id]. */
    function kop_dired_set_hidden($id, $hide, $who) {
        $before = kop_dired_op_row($id);
        if (!$before) throw new RuntimeException('That company is not on file any more.');
        $json = kop_dired_json($before);
        if ((bool) $hide === !empty($json['operator']['directoryHidden'])) {
            return array($hide ? 'It is off the directory already.' : 'It is on the directory already.', 0);
        }
        if ($hide) $json['operator']['directoryHidden'] = true;
        else unset($json['operator']['directoryHidden']);
        $after = kop_dired_write_op($id, $json);
        $log = kop_dired_log($hide ? 'hide' : 'show', $id, 0, array('row' => $before), array('row' => $after), $who);
        kop_dired_after(array($id));
        $name = kop_dired_name($after);
        return array($hide ? $name . ' is off the directory; its company page stays.' : $name . ' is back on the directory (when it has two or more programs).', $log);
    }
}

if (!function_exists('kop_dired_create')) {
    /** A new company named $name, with no programs yet. Returns [id, log id]. */
    function kop_dired_create($name, $who) {
        global $wpdb;
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '') throw new RuntimeException('Give the company a name.');
        $other = kop_dired_name_taken($name);
        if ($other) throw new RuntimeException('"' . kop_dired_name($other) . '" (#' . (int) $other['id'] . ') is already on file.');
        // The record key must not be a program's (kop_v2_name_taken): "Three Points Center (company)".
        $key = $name;
        $taken = function ($k) use ($wpdb) {
            foreach (array('facilities_v2', kop_dired_table('ops')) as $t) {
                if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$t}` WHERE LOWER(unique_name) = LOWER(%s)", $k))) return true;
            }
            return false;
        };
        if ($taken($key)) $key = $name . ' (company)';
        if ($taken($key)) throw new RuntimeException('The record key "' . $key . '" is taken; pick a different name.');
        $operator = function_exists('kop_facility_operator_block') ? (kop_facility_operator_block(array('name' => $name)) ?? array('name' => $name)) : array('name' => $name);
        $json = array('operator' => $operator, 'legacy_blocks' => array('name' => $key, 'category' => 'companies', 'timestamp' => gmdate('c')));
        $max = (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM `' . kop_dired_table('ops') . '`');
        $id = max(defined('KOP_V2_FIRST_NEW_OPERATOR_ID') ? (int) KOP_V2_FIRST_NEW_OPERATOR_ID : 500000, $max + 1);
        $now = gmdate('Y-m-d H:i:s');
        $wpdb->insert(kop_dired_table('ops'), array('id' => $id, 'unique_name' => $key, 'name' => $name,
            'json_data' => json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'created_at' => $now, 'updated_at' => $now));
        $row = kop_dired_op_row($id);
        if (!$row) throw new RuntimeException('The company could not be saved.');
        $log = kop_dired_log('create', $id, 0, null, array('row' => $row), $who);
        kop_dired_after(array($id));
        return array($id, $log);
    }
}

if (!function_exists('kop_dired_delete')) {
    /** Delete company $id and every row pointing at it (all kept in the log for Undo). Returns [message, log id]. */
    function kop_dired_delete($id, $who) {
        global $wpdb;
        $row = kop_dired_op_row($id);
        if (!$row) throw new RuntimeException('That company is not on file any more.');
        $refs = kop_dired_ref_rows($id);
        $fids = array_map(function ($l) { return (int) $l['facility_id']; }, $refs[kop_dired_table('links')] ?? array());
        $tables = kop_dired_ref_tables();
        foreach ($refs as $t => $rows) {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$t}` WHERE `{$tables[$t]}` = %d", (int) $id));
        }
        $wpdb->delete(kop_dired_table('ops'), array('id' => (int) $id));
        $log = kop_dired_log('delete', $id, 0, array('row' => $row, 'refs' => $refs, 'slug' => kop_dired_slug($id)), null, $who);
        kop_dired_after(array($id), $fids);
        $words = kop_dired_ref_words($refs);
        return array('Deleted ' . kop_dired_name($row) . ($words !== '' ? ' (it had ' . $words . '; the programs stay)' : '') . '.', $log);
    }
}

if (!function_exists('kop_dired_merge_json')) {
    /**
     * $target's record with $source's folded in: the source's names become other
     * names, lists are joined, empty fields are filled; nothing the target holds
     * is changed.
     */
    function kop_dired_merge_json(array $target, array $source, $source_name) {
        $t = $target['operator'];
        $s = $source['operator'];
        $names = array_values((array) ($t['otherNames'] ?? array()));
        $have = array(kop_dired_text_key($t['name'] ?? '') => true);
        foreach ($names as $n) if (is_string($n)) $have[kop_dired_text_key($n)] = true;
        $add = array_merge(array($source_name, $s['name'] ?? '', $s['currentName'] ?? ''), (array) ($s['otherNames'] ?? array()), (array) ($s['pastNames'] ?? array()));
        foreach ($add as $n) {
            $n = trim(is_string($n) ? $n : '');
            $k = kop_dired_text_key($n);
            if ($n === '' || $k === '' || isset($have[$k])) continue;
            $have[$k] = true;
            $names[] = $n;
        }
        $t['otherNames'] = $names;
        $join = function ($a, $b) {
            $out = array_values((array) $a);
            $seen = array();
            foreach ($out as $v) $seen[json_encode($v)] = true;
            foreach ((array) $b as $v) {
                $k = json_encode($v);
                if ($v === '' || $v === null || isset($seen[$k])) continue;
                $seen[$k] = true;
                $out[] = $v;
            }
            return $out;
        };
        foreach (array('websites', 'parentCompanies', 'owners', 'investors', 'notes', 'resourceLinks', 'fieldNotes') as $k) {
            if (!empty($s[$k])) $t[$k] = $join($t[$k] ?? array(), $s[$k]);
        }
        if (!empty($s['keyStaff']) && is_array($s['keyStaff'])) {
            $ks = isset($t['keyStaff']) && is_array($t['keyStaff']) ? $t['keyStaff'] : array();
            foreach ($s['keyStaff'] as $k => $v) {
                if (is_array($v)) $ks[$k] = $join($ks[$k] ?? array(), $v);
                elseif (trim((string) ($ks[$k] ?? '')) === '' && trim((string) $v) !== '') $ks[$k] = $v;
            }
            $t['keyStaff'] = $ks;
        }
        // Everything else: the target's own value when it has one.
        foreach ($s as $k => $v) {
            if (in_array($k, array('name', 'currentName', 'otherNames', 'pastNames', 'directoryHidden', 'keyStaff'), true)) continue;
            $empty = !isset($t[$k]) || $t[$k] === '' || $t[$k] === array() || $t[$k] === null;
            if ($empty && $v !== '' && $v !== array() && $v !== null) $t[$k] = $v;
        }
        $target['operator'] = $t;
        return $target;
    }
}

if (!function_exists('kop_dired_merge')) {
    /**
     * Merge company $source into company $target: every row pointing at the
     * source points at the target (a row the target already has is left out, and
     * a program the source ran now that the target lists as past becomes
     * current), the target's record takes the source's names and fills its empty
     * fields, the source is deleted and /operator/<its slug>/ goes to the target.
     * Returns [message, log id].
     */
    function kop_dired_merge($source, $target, $who) {
        global $wpdb;
        $source = (int) $source;
        $target = (int) $target;
        if ($source === $target) throw new RuntimeException('Pick a different company to merge into.');
        $src = kop_dired_op_row($source);
        $dst = kop_dired_op_row($target);
        if (!$src) throw new RuntimeException('That company is not on file any more.');
        if (!$dst) throw new RuntimeException('The company to merge into is not on file.');
        $tables = kop_dired_ref_tables();
        $links_t = kop_dired_table('links');
        $refs = kop_dired_ref_rows($source);
        $moved = array();
        $skipped = array();
        $raised = array();
        $suppress = $wpdb->suppress_errors(true);
        foreach ($refs as $t => $rows) {
            $c = $tables[$t];
            $wpdb->query($wpdb->prepare("DELETE FROM `{$t}` WHERE `{$c}` = %d", $source));
            foreach ($rows as $r) {
                $new = $r;
                $new[$c] = $target;
                if ($t === $links_t) {
                    $have = kop_dired_link($target, (int) $r['facility_id']);
                    if ($have) {
                        if ($r['relationship'] === 'current' && $have['relationship'] !== 'current') {
                            $wpdb->update($t, array('relationship' => 'current'), array('operator_id' => $target, 'facility_id' => (int) $r['facility_id']));
                            $raised[] = array('before' => $have, 'after' => kop_dired_link($target, (int) $r['facility_id']));
                        }
                        $skipped[$t][] = $r;
                        continue;
                    }
                    $new['sort_order'] = 1000 + (int) $r['sort_order'];   // after the target's own programs
                }
                if ($wpdb->insert($t, $new)) {
                    if (isset($new['id']) && !$new['id'] && $wpdb->insert_id) $new['id'] = $wpdb->insert_id;
                    $moved[$t][] = $new;
                } else {
                    $skipped[$t][] = $r;
                }
            }
        }
        $wpdb->suppress_errors($suppress);
        $merged = kop_dired_merge_json(kop_dired_json($dst), kop_dired_json($src), kop_dired_name($src));
        $folder = ($dst['document_folder_id'] === null || $dst['document_folder_id'] === '') && $src['document_folder_id'] !== null && $src['document_folder_id'] !== ''
            ? (int) $src['document_folder_id'] : false;
        $dst_after = kop_dired_write_op($target, $merged, null, $folder);
        $slug = kop_dired_slug($source);
        $wpdb->delete(kop_dired_table('ops'), array('id' => $source));
        $log = kop_dired_log('merge', $source, $target,
            array('source' => $src, 'target' => $dst, 'refs' => $refs),
            array('target' => $dst_after, 'moved' => $moved, 'skipped' => $skipped, 'raised' => $raised, 'slug' => $slug), $who);
        if ($slug !== '') kop_dired_remember_slug($slug, $target);
        $fids = array_map(function ($l) { return (int) $l['facility_id']; }, $refs[$links_t] ?? array());
        kop_dired_after(array($source, $target), $fids);
        $words = kop_dired_ref_words($moved);
        return array('Merged ' . kop_dired_name($src) . ' into ' . kop_dired_name($dst_after) . ($words !== '' ? ': moved ' . $words : '') . '.', $log);
    }
}

/* ---- Programs ---------------------------------------------------------------------- */

if (!function_exists('kop_dired_relationships')) {
    function kop_dired_relationships() {
        return array('current' => 'Runs it now', 'past' => 'Ran it before', 'other' => 'Other tie');
    }
}

if (!function_exists('kop_dired_facility_name')) {
    function kop_dired_facility_name($fid) {
        $f = kop_dired_facilities(array((int) $fid));
        return isset($f[(int) $fid]) ? (string) $f[(int) $fid]['name'] : '';
    }
}

if (!function_exists('kop_dired_link_add')) {
    /** Put program $fid under company $oid. Returns [message, log id]. */
    function kop_dired_link_add($oid, $fid, $relationship, $who) {
        global $wpdb;
        $oid = (int) $oid;
        $fid = (int) $fid;
        $op = kop_dired_op_row($oid);
        if (!$op) throw new RuntimeException('That company is not on file any more.');
        $fname = kop_dired_facility_name($fid);
        if ($fid <= 0 || $fname === '') throw new RuntimeException('Pick a program with the finder.');
        if (kop_dired_link($oid, $fid)) throw new RuntimeException($fname . ' is listed under ' . kop_dired_name($op) . ' already.');
        $relationship = isset(kop_dired_relationships()[$relationship]) ? $relationship : 'current';
        $sort = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM `' . kop_dired_table('links') . '` WHERE operator_id = %d', $oid));
        $wpdb->insert(kop_dired_table('links'), array('operator_id' => $oid, 'facility_id' => $fid, 'relationship' => $relationship, 'sort_order' => $sort));
        $after = kop_dired_link($oid, $fid);
        if (!$after) throw new RuntimeException('The link could not be saved.');
        $log = kop_dired_log('link', $oid, $fid, null, array('link' => $after), $who);
        kop_dired_after(array($oid), array($fid));
        return array('Listed ' . $fname . ' under ' . kop_dired_name($op) . '.', $log);
    }
}

if (!function_exists('kop_dired_link_remove')) {
    /** Take program $fid off company $oid (the program stays). Returns [message, log id]. */
    function kop_dired_link_remove($oid, $fid, $who) {
        global $wpdb;
        $before = kop_dired_link($oid, $fid);
        if (!$before) throw new RuntimeException('That program is not listed under that company any more.');
        $wpdb->delete(kop_dired_table('links'), array('operator_id' => (int) $oid, 'facility_id' => (int) $fid));
        $log = kop_dired_log('unlink', $oid, $fid, array('link' => $before), null, $who);
        kop_dired_after(array($oid), array($fid));
        $op = kop_dired_op_row($oid);
        return array('Took ' . kop_dired_facility_name($fid) . ' off ' . ($op ? kop_dired_name($op) : 'the company') . '.', $log);
    }
}

if (!function_exists('kop_dired_link_relationship')) {
    /** Set the tie of program $fid to company $oid: current, past or other. Returns [message, log id]. */
    function kop_dired_link_relationship($oid, $fid, $relationship, $who) {
        global $wpdb;
        $before = kop_dired_link($oid, $fid);
        if (!$before) throw new RuntimeException('That program is not listed under that company any more.');
        if (!isset(kop_dired_relationships()[$relationship])) throw new RuntimeException('Pick current, past or other.');
        if ($before['relationship'] === $relationship) return array('Nothing changed.', 0);
        $wpdb->update(kop_dired_table('links'), array('relationship' => $relationship), array('operator_id' => (int) $oid, 'facility_id' => (int) $fid));
        $after = kop_dired_link($oid, $fid);
        $log = kop_dired_log('relationship', $oid, $fid, array('link' => $before), array('link' => $after), $who);
        kop_dired_after(array($oid), array($fid));
        return array(kop_dired_facility_name($fid) . ': ' . strtolower(kop_dired_relationships()[$relationship]) . '.', $log);
    }
}

if (!function_exists('kop_dired_company_keys')) {
    /** Name keys of every name company row $row goes by. */
    function kop_dired_company_keys(array $row) {
        $op = kop_dired_json($row)['operator'];
        $names = array_merge(array($row['name'] ?? '', $row['unique_name'] ?? '', $op['name'] ?? '', $op['currentName'] ?? ''), (array) ($op['otherNames'] ?? array()));
        if (function_exists('kop_operator_pages_display_name')) $names[] = kop_operator_pages_display_name((string) ($row['name'] ?? ''));
        $keys = array();
        foreach ($names as $n) {
            $k = is_string($n) ? kop_dired_key($n) : '';
            if ($k !== '') $keys[$k] = true;
        }
        return $keys;
    }
}

if (!function_exists('kop_dired_transfer')) {
    /**
     * Move programs $fids from company $from to company $to, tied as
     * $relationship there; $keep_past leaves each listed under $from as a past
     * program. A program whose "Operator" field (identification.currentOperator)
     * names the old company names the new one when it goes as current.
     * $opts: kop_facility_save()'s (pdo, prefix). Returns [message, log id].
     */
    function kop_dired_transfer($from, $to, array $fids, $relationship, $keep_past, $who, array $opts) {
        global $wpdb;
        $from = (int) $from;
        $to = (int) $to;
        if ($from === $to) throw new RuntimeException('Pick a different company to move them to.');
        $src = kop_dired_op_row($from);
        $dst = kop_dired_op_row($to);
        if (!$src) throw new RuntimeException('That company is not on file any more.');
        if (!$dst) throw new RuntimeException('The company to move them to is not on file.');
        $relationship = isset(kop_dired_relationships()[$relationship]) ? $relationship : 'current';
        $fids = array_values(array_unique(array_filter(array_map('intval', $fids))));
        $fids = array_values(array_filter($fids, function ($fid) use ($from) { return (bool) kop_dired_link($from, $fid); }));
        if (!$fids) throw new RuntimeException('Tick the programs to move first.');
        $snap = function () use ($from, $to, $fids) {
            $out = array();
            foreach ($fids as $fid) foreach (array($from, $to) as $oid) {
                $l = kop_dired_link($oid, $fid);
                if ($l) $out[] = $l;
            }
            return $out;
        };
        $before = $snap();
        $t = kop_dired_table('links');
        $sort = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM `{$t}` WHERE operator_id = %d", $to));
        foreach ($fids as $fid) {
            $here = kop_dired_link($to, $fid);
            if ($here) {
                if ($here['relationship'] !== $relationship) $wpdb->update($t, array('relationship' => $relationship), array('operator_id' => $to, 'facility_id' => $fid));
            } else {
                $wpdb->insert($t, array('operator_id' => $to, 'facility_id' => $fid, 'relationship' => $relationship, 'sort_order' => $sort++));
            }
            if ($keep_past) $wpdb->update($t, array('relationship' => 'past'), array('operator_id' => $from, 'facility_id' => $fid));
            else $wpdb->delete($t, array('operator_id' => $from, 'facility_id' => $fid));
        }
        // The program's own "Operator" line, when it names the old company.
        $docs = array();
        if ($relationship === 'current' && function_exists('kop_facility_load')) {
            $old_keys = kop_dired_company_keys($src);
            $new_name = kop_dired_name($dst);
            foreach ($fids as $fid) {
                $rec = kop_facility_load($fid, $opts);
                if (!$rec) continue;
                $cur = trim((string) ($rec['doc']['identification']['currentOperator'] ?? ''));
                if ($cur === '' || !isset($old_keys[kop_dired_key($cur)])) continue;
                $doc = $rec['doc'];
                $doc['identification']['currentOperator'] = $new_name;
                kop_facility_save($doc, $opts + array('force' => true));
                $now = kop_facility_load($fid, $opts);
                $docs[$fid] = array('before' => $rec['doc'], 'after' => $now ? $now['doc'] : $doc);
            }
        }
        $after = $snap();
        $log = kop_dired_log('transfer', $from, $to, array('links' => $before, 'fids' => $fids),
            array('links' => $after, 'docs' => $docs, 'keep_past' => (bool) $keep_past), $who);
        kop_dired_after(array($from, $to), $fids);
        $n = count($fids);
        return array('Moved ' . ($n === 1 ? kop_dired_facility_name($fids[0]) : $n . ' programs') . ' to ' . kop_dired_name($dst)
            . ($keep_past ? ' (still listed under ' . kop_dired_name($src) . ' as past)' : '')
            . ($docs ? '; ' . count($docs) . ' record' . (count($docs) === 1 ? '' : 's') . ' now name' . (count($docs) === 1 ? 's' : '') . ' it as the operator' : '') . '.', $log);
    }
}

/* ---- Undo ---------------------------------------------------------------------------- */

if (!function_exists('kop_dired_undo')) {
    /** Put back exactly what log entry $log_id changed, while nothing has changed it since. Returns the message. */
    function kop_dired_undo($log_id, $who, array $opts = array()) {
        global $wpdb;
        $e = kop_dired_log_get($log_id);
        if (!$e) throw new RuntimeException('That change is not in the log.');
        if (!empty($e['undone_at'])) throw new RuntimeException('That change was undone already.');
        $before = $e['before_json'] !== null && $e['before_json'] !== '' ? json_decode($e['before_json'], true) : null;
        $after = $e['after_json'] !== null && $e['after_json'] !== '' ? json_decode($e['after_json'], true) : null;
        $oid = (int) $e['operator_id'];
        $other = (int) $e['other_id'];
        $changed = 'It has changed since; undo the later changes first.';
        $ops = array($oid);
        $fids = array();
        $notes = array();
        $links_t = kop_dired_table('links');
        switch ($e['action']) {
            case 'edit':
            case 'hide':
            case 'show':
                if (!kop_dired_same_row(kop_dired_op_row($oid), $after['row'])) throw new RuntimeException($changed);
                kop_dired_put_row($before['row']);
                $fids = kop_dired_link_ids($oid);
                $msg = 'Undone: ' . kop_dired_name($before['row']) . ' is as it was.';
                break;
            case 'create':
                if (!kop_dired_same_row(kop_dired_op_row($oid), $after['row'])) throw new RuntimeException($changed);
                if (kop_dired_ref_rows($oid)) throw new RuntimeException('It has programs or links now: take them off (or undo them) first.');
                $wpdb->delete(kop_dired_table('ops'), array('id' => $oid));
                $msg = 'Undone: ' . kop_dired_name($after['row']) . ' is off the list.';
                break;
            case 'delete':
                if (kop_dired_op_row($oid)) throw new RuntimeException('A company with that id is on file again.');
                kop_dired_put_row($before['row']);
                $suppress = $wpdb->suppress_errors(true);
                foreach ((array) $before['refs'] as $t => $rows) {
                    foreach ($rows as $r) {
                        if ($t === $links_t && !kop_dired_facility_name((int) $r['facility_id'])) { $notes[] = 'program #' . (int) $r['facility_id'] . ' is gone'; continue; }
                        if (!$wpdb->insert($t, $r)) $notes[] = 'a row of ' . $t . ' could not be put back';
                        if ($t === $links_t) $fids[] = (int) $r['facility_id'];
                    }
                }
                $wpdb->suppress_errors($suppress);
                $msg = 'Undone: ' . kop_dired_name($before['row']) . ' is back with its links.';
                break;
            case 'merge':
                $ops[] = $other;
                if (kop_dired_op_row($oid)) throw new RuntimeException('A company with that id is on file again.');
                if (!kop_dired_same_row(kop_dired_op_row($other), $after['target'])) throw new RuntimeException('The company it was merged into has changed since; undo the later changes first.');
                foreach ((array) $after['raised'] as $r) {
                    if (kop_dired_link($other, (int) $r['after']['facility_id']) !== $r['after']) throw new RuntimeException($changed);
                }
                foreach ((array) $after['moved'] as $t => $rows) {
                    foreach ($rows as $r) {
                        $now = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM `' . $t . '` WHERE ' . implode(' AND ', array_map(function ($k) { return "`$k` = %s"; }, array_keys(kop_dired_where($r)))), array_values(kop_dired_where($r))));
                        if (!(int) $now) throw new RuntimeException('A link it moved has changed since (' . $t . '); undo the later changes first.');
                    }
                }
                kop_dired_put_row($before['target']);
                kop_dired_put_row($before['source']);
                foreach ((array) $after['moved'] as $t => $rows) {
                    foreach ($rows as $r) $wpdb->delete($t, kop_dired_where($r));
                }
                foreach ((array) $after['raised'] as $r) {
                    $wpdb->update($links_t, array('relationship' => $r['before']['relationship']), array('operator_id' => $other, 'facility_id' => (int) $r['before']['facility_id']));
                }
                $suppress = $wpdb->suppress_errors(true);
                foreach ((array) $before['refs'] as $t => $rows) {
                    foreach ($rows as $r) {
                        if (!$wpdb->insert($t, $r)) $notes[] = 'a row of ' . $t . ' could not be put back';
                        if ($t === $links_t) $fids[] = (int) $r['facility_id'];
                    }
                }
                $wpdb->suppress_errors($suppress);
                $msg = 'Undone: ' . kop_dired_name($before['source']) . ' is its own company again, and ' . kop_dired_name($before['target']) . ' is as it was.';
                break;
            case 'link':
                if (kop_dired_link($oid, $other) != $after['link']) throw new RuntimeException($changed);
                $wpdb->delete($links_t, array('operator_id' => $oid, 'facility_id' => $other));
                $fids = array($other);
                $msg = 'Undone: ' . kop_dired_facility_name($other) . ' is off that company again.';
                break;
            case 'unlink':
                if (kop_dired_link($oid, $other)) throw new RuntimeException('It is listed under that company again.');
                if (!kop_dired_op_row($oid)) throw new RuntimeException('The company is gone: undo that first.');
                $wpdb->insert($links_t, $before['link']);
                $fids = array($other);
                $msg = 'Undone: ' . kop_dired_facility_name($other) . ' is listed again.';
                break;
            case 'relationship':
                if (kop_dired_link($oid, $other) != $after['link']) throw new RuntimeException($changed);
                $wpdb->update($links_t, array('relationship' => $before['link']['relationship']), array('operator_id' => $oid, 'facility_id' => $other));
                $fids = array($other);
                $msg = 'Undone: the tie is as it was.';
                break;
            case 'transfer':
                $ops[] = $other;
                $fids = array_map('intval', (array) $before['fids']);
                $now = array();
                foreach ($fids as $fid) foreach (array($oid, $other) as $o) { $l = kop_dired_link($o, $fid); if ($l) $now[] = $l; }
                if ($now != $after['links']) throw new RuntimeException($changed);
                foreach ($fids as $fid) foreach (array($oid, $other) as $o) $wpdb->delete($links_t, array('operator_id' => $o, 'facility_id' => $fid));
                foreach ((array) $before['links'] as $l) $wpdb->insert($links_t, $l);
                foreach ((array) $after['docs'] as $fid => $d) {
                    $rec = function_exists('kop_facility_load') ? kop_facility_load((int) $fid, $opts) : null;
                    if (!$rec) continue;
                    if (kop_facility_same_document($d['after'], $rec['doc'])) {
                        kop_facility_save($d['before'], $opts + array('force' => true));
                    } else {
                        $notes[] = 'record #' . (int) $fid . ' has been edited since, so its Operator line stays as it is now';
                    }
                }
                $msg = 'Undone: the programs are back where they were.';
                break;
            default:
                throw new RuntimeException('That change cannot be undone.');
        }
        $wpdb->update(kop_dired_table(), array('undone_by' => substr((string) $who, 0, 60), 'undone_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $log_id));
        kop_dired_after($ops, $fids);
        return $msg . ($notes ? ' (' . implode('; ', array_unique($notes)) . '.)' : '');
    }
}

if (!function_exists('kop_dired_log_words')) {
    /** What a log entry did, in words. */
    function kop_dired_log_words(array $e) {
        $before = $e['before_json'] ? json_decode($e['before_json'], true) : null;
        $after = $e['after_json'] ? json_decode($e['after_json'], true) : null;
        $oid = (int) $e['operator_id'];
        $now = kop_dired_op_row($oid);
        $name = $now ? kop_dired_name($now) : (isset($after['row']) ? kop_dired_name($after['row']) : (isset($before['row']) ? kop_dired_name($before['row'])
            : (isset($before['source']) ? kop_dired_name($before['source']) : 'company #' . $oid)));
        $other = (int) $e['other_id'];
        $other_co = function () use ($other) { $r = kop_dired_op_row($other); return $r ? kop_dired_name($r) : 'company #' . $other; };
        switch ($e['action']) {
            case 'edit': return 'Edited ' . $name . ($e['note'] !== '' ? ' (' . $e['note'] . ')' : '');
            case 'hide': return 'Took ' . $name . ' off the directory';
            case 'show': return 'Put ' . $name . ' back on the directory';
            case 'create': return 'Added the company ' . $name;
            case 'delete': return 'Deleted ' . $name . (($w = kop_dired_ref_words((array) ($before['refs'] ?? array()))) !== '' ? ' (' . $w . ')' : '');
            case 'merge': return 'Merged ' . $name . ' into ' . $other_co();
            case 'link': return 'Listed ' . kop_dired_facility_name($other) . ' under ' . $name;
            case 'unlink': return 'Took ' . kop_dired_facility_name($other) . ' off ' . $name;
            case 'relationship':
                return kop_dired_facility_name($other) . ' under ' . $name . ': ' . strtolower(kop_dired_relationships()[$after['link']['relationship'] ?? ''] ?? '');
            case 'transfer':
                $n = count((array) ($before['fids'] ?? array()));
                return 'Moved ' . ($n === 1 ? kop_dired_facility_name((int) $before['fids'][0]) : $n . ' programs') . ' from ' . $name . ' to ' . $other_co();
        }
        return $e['action'];
    }
}

/* ---- KOP Tools > Directory Editor ------------------------------------------------------ */

if (!function_exists('kop_dired_admin_menu')) {
    function kop_dired_admin_menu() {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Directory Editor', 'Directory Editor', 'manage_options', 'kop-directory-editor', 'kop_dired_admin_page');
    }
    if (function_exists('add_action')) add_action('admin_menu', 'kop_dired_admin_menu', 30);
}

if (function_exists('add_filter')) {
    add_filter('kop_admin_kit_pages', function ($p) {
        $p[] = 'kop-directory-editor';
        return $p;
    });
}

if (!function_exists('kop_dired_admin_url')) {
    function kop_dired_admin_url(array $args = array()) {
        return add_query_arg(array_merge(array('page' => 'kop-directory-editor'), $args), admin_url('admin.php'));
    }
}

if (!function_exists('kop_dired_who')) {
    function kop_dired_who() {
        $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        return $u && !empty($u->user_login) ? (string) $u->user_login : 'admin';
    }
}

if (!function_exists('kop_dired_opts')) {
    /** kop_facility_save()'s options on the site (the records database, v2 writes on). */
    function kop_dired_opts() {
        global $wpdb;
        $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
        if (!$pdo) throw new RuntimeException('No database connection.');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
    }
}

if (!function_exists('kop_dired_handle_post')) {
    /** The screen's forms post here; every action redirects back with its message and an Undo. */
    function kop_dired_handle_post() {
        if (($_GET['page'] ?? '') !== 'kop-directory-editor' || empty($_POST['kop_dired_act'])) return;
        if (!current_user_can('manage_options')) wp_die('Not allowed.', 403);
        check_admin_referer('kop_directory_editor');
        $p = wp_unslash($_POST);
        $who = kop_dired_who();
        $back = array();
        foreach (array('tab', 'q') as $k) if (isset($p['back_' . $k]) && $p['back_' . $k] !== '') $back[$k] = sanitize_text_field($p['back_' . $k]);
        $id = (int) ($p['id'] ?? 0);
        $log = 0;
        try {
            switch ($p['kop_dired_act']) {
                case 'edit':
                    list($msg, $log) = kop_dired_edit($id, $p, $who);
                    $back['open'] = $id;
                    break;
                case 'hide':
                case 'show':
                    list($msg, $log) = kop_dired_set_hidden($id, $p['kop_dired_act'] === 'hide', $who);
                    $back['open'] = $id;
                    break;
                case 'create':
                    list($new, $log) = kop_dired_create((string) ($p['name'] ?? ''), $who);
                    $msg = 'Added ' . kop_dired_name(kop_dired_op_row($new)) . '. Add its programs below.';
                    $back = array('open' => $new, 'q' => kop_dired_name(kop_dired_op_row($new)));
                    break;
                case 'delete':
                    list($msg, $log) = kop_dired_delete($id, $who);
                    break;
                case 'merge':
                    $to = (int) ($p['target'] ?? 0);
                    list($msg, $log) = kop_dired_merge($id, $to, $who);
                    $back['open'] = $to;
                    unset($back['q']);
                    break;
                case 'link':
                    list($msg, $log) = kop_dired_link_add($id, (int) ($p['facility_id'] ?? 0), (string) ($p['relationship'] ?? 'current'), $who);
                    $back['open'] = $id;
                    break;
                case 'unlink':
                    list($msg, $log) = kop_dired_link_remove($id, (int) ($p['fid'] ?? 0), $who);
                    $back['open'] = $id;
                    break;
                case 'relationship':
                    list($msg, $log) = kop_dired_link_relationship($id, (int) ($p['fid'] ?? 0), (string) ($p['relationship'] ?? ''), $who);
                    $back['open'] = $id;
                    break;
                case 'transfer':
                    list($msg, $log) = kop_dired_transfer($id, (int) ($p['target'] ?? 0), (array) ($p['fids'] ?? array()), (string) ($p['relationship'] ?? 'current'),
                        !empty($p['keep_past']), $who, kop_dired_opts());
                    $back['open'] = $id;
                    break;
                case 'undo':
                    $msg = kop_dired_undo((int) ($p['log'] ?? 0), $who, kop_dired_opts());
                    break;
                default:
                    $msg = 'Unknown action.';
            }
            $back['kop_dired_msg'] = rawurlencode($msg);
            if ($log) $back['kop_dired_log'] = $log;
        } catch (Throwable $ex) {
            $back['kop_dired_err'] = rawurlencode($ex->getMessage());
            if ($id) $back['open'] = $id;
        }
        $url = kop_dired_admin_url($back);
        if (!empty($back['open'])) $url .= '#company-' . (int) $back['open'];
        wp_safe_redirect($url);
        exit;
    }
    if (function_exists('add_action')) add_action('admin_init', 'kop_dired_handle_post', 30);
}

if (function_exists('add_action')) {
    // The site's own edit dialogs ("Every field") on this screen, opened from buttons.
    add_action('admin_enqueue_scripts', function () {
        if (($_GET['page'] ?? '') !== 'kop-directory-editor' || !current_user_can('manage_options')) return;
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        if (file_exists($dir . '/js/icons.js')) wp_enqueue_script('kop-icons', $uri . '/js/icons.js', array(), filemtime($dir . '/js/icons.js'), true);
        wp_enqueue_style('kop-inline-edit', $uri . '/css/inline-edit.css', array(), filemtime($dir . '/css/inline-edit.css'));
        wp_enqueue_script('kop-inline-edit', $uri . '/js/inline-edit.js', array(), filemtime($dir . '/js/inline-edit.js'), true);
        wp_add_inline_script('kop-inline-edit', 'window.KOP_INLINE_EDIT = ' . wp_json_encode(array(
            'rest' => esc_url_raw(rest_url('kop/v1/inline-edit')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pageRefs' => array(),
            'noBar' => true,
        )) . ';', 'before');
    });
}

if (!function_exists('kop_dired_form')) {
    /** A form posting $act; $id = the form's id (buttons and fields elsewhere join it with form="..."). */
    function kop_dired_form($act, array $hidden, $body = '', $id = '', $class = '') {
        $h = '<form method="post" class="kop-dired-form' . ($class !== '' ? ' ' . esc_attr($class) : '') . '"' . ($id !== '' ? ' id="' . esc_attr($id) . '"' : '') . '>'
            . wp_nonce_field('kop_directory_editor', '_wpnonce', true, false)
            . '<input type="hidden" name="kop_dired_act" value="' . esc_attr($act) . '">';
        foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
        return $h . $body . '</form>';
    }
}

if (!function_exists('kop_dired_submit')) {
    function kop_dired_submit($label, $variant = 'secondary', $confirm = '', $form = '') {
        return '<button type="submit" class="button kop-ak-btn kop-ak-btn--' . esc_attr($variant) . '"' . ($form !== '' ? ' form="' . esc_attr($form) . '"' : '')
            . ($confirm !== '' ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"' : '') . '>' . esc_html($label) . '</button>';
    }
}

if (!function_exists('kop_dired_select')) {
    function kop_dired_select($name, array $choices, $value, $attrs = '') {
        $h = '<select name="' . esc_attr($name) . '"' . ($attrs !== '' ? ' ' . $attrs : '') . '>';
        foreach ($choices as $k => $l) $h .= '<option value="' . esc_attr($k) . '"' . selected((string) $value, (string) $k, false) . '>' . esc_html($l) . '</option>';
        return $h . '</select>';
    }
}

if (!function_exists('kop_dired_edit_button')) {
    /** A button that opens the site's edit dialog for $ref (inc/inline-edit.php). */
    function kop_dired_edit_button($ref, $label) {
        return '<button type="button" class="button kop-ak-btn kop-ak-btn--quiet" data-kop-edit-open="' . esc_attr($ref) . '">' . esc_html($label) . '</button>';
    }
}

if (!function_exists('kop_dired_matches')) {
    /** Does company $c match the search: its names or a program's name. */
    function kop_dired_matches(array $c, $q) {
        $q = strtolower(trim((string) $q));
        if ($q === '') return true;
        if (ctype_digit(ltrim($q, '#')) && (int) ltrim($q, '#') === (int) $c['row']['id']) return true;
        $hay = array($c['name'], (string) $c['row']['name'], (string) $c['row']['unique_name']);
        foreach ((array) ($c['op']['otherNames'] ?? array()) as $n) if (is_string($n)) $hay[] = $n;
        foreach ($c['links'] as $l) if (!empty($l['facility']['name'])) $hay[] = (string) $l['facility']['name'];
        foreach ($hay as $h) if (strpos(strtolower($h), $q) !== false) return true;
        return false;
    }
}

if (!function_exists('kop_dired_years')) {
    function kop_dired_years(array $f) {
        $a = (int) ($f['start_year'] ?? 0);
        $b = (int) ($f['end_year'] ?? 0);
        if ($a && $b) return $a . ' to ' . $b;
        if ($a) return 'from ' . $a;
        if ($b) return 'until ' . $b;
        return '';
    }
}

if (!function_exists('kop_dired_card')) {
    /** One company's card: what the directory does with it, its fields, its programs, and every action. */
    function kop_dired_card(array $c, array $all, $open, array $back) {
        $id = (int) $c['row']['id'];
        $op = $c['op'];
        list($state, $tone) = kop_dired_state_words($c, $all);
        $current = count(array_filter($c['links'], function ($l) { return $l['relationship'] === 'current'; }));
        $count = count($c['links']);
        $url = kop_dired_company_url($id);
        $hb = array();
        foreach ($back as $k => $v) $hb['back_' . $k] = $v;

        $h = '<details class="kop-dired-card" id="company-' . $id . '"' . ($open ? ' open' : '') . '>';
        $h .= '<summary><span class="kop-dired-name">' . esc_html($c['name']) . '</span>'
            . '<span class="kop-dired-pill kop-dired-pill--' . esc_attr($tone) . '">' . esc_html($state) . '</span>'
            . '<span class="kop-dired-count">' . $count . ' program' . ($count === 1 ? '' : 's') . ($count && $current !== $count ? ', ' . $current . ' now' : '') . '</span></summary>';
        $h .= '<div class="kop-dired-body">';

        // What the card says.
        $meta = array();
        if (!empty($op['otherNames'])) $meta[] = 'Also: ' . esc_html(implode(', ', array_filter((array) $op['otherNames'], 'is_string')));
        $hq = trim(implode(', ', array_filter(array($op['headquartersCity'] ?? '', $op['headquartersState'] ?? ''))) ?: (string) ($op['headquarters'] ?? ''));
        if ($hq !== '') $meta[] = 'Headquarters: ' . esc_html($hq);
        if (!empty($op['founded'])) $meta[] = 'Founded ' . esc_html((string) $op['founded']);
        if (!empty($op['status'])) $meta[] = esc_html((string) $op['status']);
        $meta[] = 'Record #' . $id;
        $h .= '<p class="kop-dired-meta">' . implode(' &middot; ', $meta) . '</p>';
        $links = array();
        if ($url !== '') $links[] = '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">Company page</a>';
        $links[] = '<a href="' . esc_url(add_query_arg('search', $c['name'], home_url('/tti-program-index/'))) . '" target="_blank" rel="noopener">The directory</a>';
        $h .= '<p class="kop-dired-meta">' . implode(' &middot; ', $links) . '</p>';

        // The company's actions.
        $h .= '<div class="kop-dired-actions">';
        $fields = '';
        foreach (kop_dired_fields() as $k => $f) {
            $v = $f[1] === 'lines' ? kop_dired_list_text($op[$k] ?? array()) : (string) ($op[$k] ?? '');
            $fields .= '<p><label>' . esc_html($f[0]) . '<br>' . ($f[1] === 'lines'
                ? '<textarea name="' . esc_attr($k) . '" rows="3" class="large-text">' . esc_textarea($v) . '</textarea>'
                : '<input type="text" name="' . esc_attr($k) . '" class="regular-text" value="' . esc_attr($v) . '"' . ($k === 'name' ? ' required' : '') . '>') . '</label></p>';
        }
        $fields .= '<p><label><input type="checkbox" name="keep_old" value="1" checked> When the name changes, keep the old one as an other name (and send its old company page address to the new one)</label></p>';
        $edit = kop_dired_form('edit', array('id' => $id) + $hb, $fields . '<p>' . kop_dired_submit('Save', 'primary') . ' '
            . kop_dired_edit_button('operator:' . $id . ':all', 'Every field (history, people, notes...)') . '</p>');
        $h .= kop_ak_fold('Edit details', $edit);

        $finder = function_exists('kop_facility_finder_field') ? kop_facility_finder_field('facility_id', '', ' required') : '<input type="number" name="facility_id" required>';
        $add = kop_dired_form('link', array('id' => $id) + $hb, '<p><label>Program ' . $finder . '</label></p><p><label>Tie '
            . kop_dired_select('relationship', kop_dired_relationships(), 'current') . '</label></p><p>' . kop_dired_submit('Add the program', 'primary') . '</p>');
        $h .= kop_ak_fold('Add a program', $add);

        $choices = array('' => 'Pick a company');
        foreach ($all as $oid => $o) if ($oid !== $id) $choices[$oid] = $o['name'] . ' (#' . $oid . ')';
        $merge = kop_dired_form('merge', array('id' => $id) + $hb,
            '<p>Everything filed under ' . esc_html($c['name']) . ' (its programs, news, filings, investors) moves to the company you pick; its names become that company\'s other names, empty fields there are filled from it, and its company page sends readers there. Then this record is deleted. Undo puts both back.</p>'
            . '<p><label>Merge into ' . kop_dired_select('target', $choices, '', 'required') . '</label></p><p>'
            . kop_dired_submit('Merge', 'primary', 'Merge ' . $c['name'] . ' into the company picked? Undo is under Recent changes.') . '</p>');
        $h .= kop_ak_fold('Merge into another company', $merge);

        $h .= kop_dired_form($c['hidden'] ? 'show' : 'hide', array('id' => $id) + $hb,
            kop_dired_submit($c['hidden'] ? 'Put back on the directory' : 'Take off the directory', 'secondary'));
        $refs = kop_dired_ref_words(kop_dired_ref_rows($id));
        $h .= kop_dired_form('delete', array('id' => $id) + $hb, kop_dired_submit('Delete company', 'danger',
            'Delete the company record ' . $c['name'] . ($refs !== '' ? ' and its ' . $refs : '') . '? The programs themselves stay. Undo is under Recent changes.'));
        $h .= '</div>';

        // Its programs.
        if (!$c['links']) {
            $h .= '<p class="kop-dired-empty">No programs are listed under this company. Add one above, or merge or delete the company.</p>';
        } else {
            $move_id = 'kop-dired-move-' . $id;
            $h .= '<table class="kop-dired-programs"><thead><tr><th class="kop-dired-tick"><span class="screen-reader-text">Tick</span></th><th>Program</th><th>Tie to ' . esc_html($c['name']) . '</th><th></th></tr></thead><tbody>';
            $forms = '';
            foreach ($c['links'] as $l) {
                $fid = (int) $l['facility_id'];
                $f = $l['facility'];
                $rel_id = 'kop-dired-rel-' . $id . '-' . $fid;
                $off_id = 'kop-dired-off-' . $id . '-' . $fid;
                $forms .= kop_dired_form('relationship', array('id' => $id, 'fid' => $fid) + $hb, '', $rel_id)
                    . kop_dired_form('unlink', array('id' => $id, 'fid' => $fid) + $hb, '', $off_id);
                if (!$f) {
                    $prog = '<em>Record #' . $fid . ' is not on file</em>';
                } else {
                    $page = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($fid) : '';
                    $name = trim((string) $f['name']) !== '' ? (string) $f['name'] : 'Record #' . $fid . ' (no name)';
                    $where = implode(', ', array_filter(array($f['city'], $f['state'] ?: $f['country'])));
                    $sub = array_filter(array($where, kop_dired_years($f), (string) $f['status'], '#' . $fid));
                    $prog = ($page !== '' ? '<a href="' . esc_url($page) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a>' : esc_html($name))
                        . '<br><small>' . esc_html(implode(' · ', $sub)) . '</small>';
                }
                $h .= '<tr><td class="kop-dired-tick"><input type="checkbox" name="fids[]" value="' . $fid . '" form="' . esc_attr($move_id) . '" aria-label="Tick ' . esc_attr($f['name'] ?? ('#' . $fid)) . '"></td>'
                    . '<td>' . $prog . '</td>'
                    . '<td class="kop-dired-rel">' . kop_dired_select('relationship', kop_dired_relationships(), $l['relationship'], 'form="' . esc_attr($rel_id) . '" aria-label="Tie"')
                    . ' ' . kop_dired_submit('Save', 'secondary', '', $rel_id) . '</td>'
                    . '<td class="kop-dired-row-actions">' . ($f ? kop_dired_edit_button('facility:' . $fid . ':all', 'Edit program') . ' ' : '')
                    . kop_dired_submit('Take off', 'danger', '', $off_id) . '</td></tr>';
            }
            $h .= '</tbody></table>' . $forms;
            $h .= kop_dired_form('transfer', array('id' => $id) + $hb,
                '<span class="kop-dired-move-label">Ticked programs:</span> <label>move to ' . kop_dired_select('target', $choices, '', 'required') . '</label> '
                . '<label>as ' . kop_dired_select('relationship', kop_dired_relationships(), 'current') . '</label> '
                . '<label><input type="checkbox" name="keep_past" value="1"> keep them here as past programs</label> '
                . kop_dired_submit('Move', 'primary'), $move_id, 'kop-dired-move');
        }
        return $h . '</div></details>';
    }
}

if (!function_exists('kop_dired_admin_page')) {
    function kop_dired_admin_page() {
        if (!current_user_can('manage_options')) return;
        kop_dired_install();
        if (function_exists('kop_facility_finder_print_assets') && !has_action('admin_footer', 'kop_facility_finder_print_assets')) {
            add_action('admin_footer', 'kop_facility_finder_print_assets');
        }
        kop_dired_page_body();
    }
}

if (!function_exists('kop_dired_page_body')) {
    /** The screen from the request's tab, search and open company (scripts/preview-admin-kit.php draws it offline). */
    function kop_dired_page_body() {
        $tab = sanitize_key($_GET['tab'] ?? 'all');
        $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
        $open = (int) ($_GET['open'] ?? 0);
        $back = array_filter(array('tab' => $tab === 'all' ? '' : $tab, 'q' => $q));

        $notices = '';
        if (isset($_GET['kop_dired_msg'])) {
            $log = (int) ($_GET['kop_dired_log'] ?? 0);
            $undo = $log ? ' ' . kop_dired_form('undo', array('log' => $log, 'back_tab' => $back['tab'] ?? '', 'back_q' => $back['q'] ?? ''), kop_dired_submit('Undo', 'secondary'), '', 'kop-dired-inline') : '';
            $notices .= '<div class="notice notice-success"><p>' . esc_html(rawurldecode(wp_unslash($_GET['kop_dired_msg']))) . $undo . '</p></div>';
        }
        if (isset($_GET['kop_dired_err'])) $notices .= '<div class="notice notice-error"><p>' . esc_html(rawurldecode(wp_unslash($_GET['kop_dired_err']))) . '</p></div>';

        $all = kop_dired_companies();
        $counts = array('all' => count($all), 'shown' => 0, 'hidden' => 0, 'few' => 0, 'dupe' => 0);
        foreach ($all as $c) {
            if (isset($counts[$c['state']])) $counts[$c['state']]++;
            if ($c['state'] === 'noname') $counts['dupe']++;
        }
        $lede = 'The parent company cards of the <a href="' . esc_url(home_url('/tti-program-index/')) . '" target="_blank" rel="noopener">TTI Facility Directory</a>: '
            . 'edit a company, take its card off, merge it into another, and add, take off or move its programs. Every change has an Undo under Recent changes.';
        echo '<div class="wrap kop-ak kop-dired">' . kop_ak_header('Directory Editor', array(
            'lede' => $lede,
            'notices' => $notices,
            'steps' => array(
                'A company gets a card on the directory when it has a name and two or more named programs. One card per name: when two company records share a name, only the first is shown, so merge them.',
                '<strong>Take off the directory</strong> hides the card but keeps the company and its /operator/ page. <strong>Delete company</strong> removes the record and its links (the programs stay).',
                '<strong>Merge</strong> moves everything filed under one company to another and deletes the first; its old company page address sends readers to the one kept.',
                'Tick programs and press <strong>Move</strong> to give them to another company. "Keep them here as past programs" leaves them under this company as ones it ran before (a sale).',
                '<strong>Edit program</strong> and <strong>Every field</strong> open the same edit boxes as the pencils on the site. The directory catches up within a few minutes of a change.',
            ),
        ));
        $tabs = array(
            'all' => array('label' => 'All companies', 'count' => $counts['all']),
            'shown' => array('label' => 'On the directory', 'count' => $counts['shown']),
            'few' => array('label' => 'Fewer than 2 programs', 'count' => $counts['few'], 'title' => 'No card on the directory until a second program is listed'),
            'dupe' => array('label' => 'Same name or no name', 'count' => $counts['dupe'], 'title' => 'Another company record has the card for this name'),
            'hidden' => array('label' => 'Taken off', 'count' => $counts['hidden']),
            'log' => array('label' => 'Recent changes'),
        );
        echo kop_ak_tabs($tabs, $tab, function ($k) use ($q) { return kop_dired_admin_url(array_filter(array('tab' => $k === 'all' ? '' : $k, 'q' => $k === 'log' ? '' : $q))); });
        echo '<style>
            .kop-dired-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;margin:10px 0}
            .kop-dired-card>summary{cursor:pointer;padding:12px 16px;display:flex;flex-wrap:wrap;gap:8px 12px;align-items:center}
            .kop-dired-name{font-size:15px;font-weight:600;color:#1d2327}
            .kop-dired-count,.kop-dired-meta,.kop-dired-card small{color:#4A5568}
            .kop-dired-pill{border-radius:999px;padding:2px 10px;font-size:12px;border:1px solid #c3c4c7;color:#1d2327;background:#f6f7f7}
            .kop-dired-pill--ok{background:#e7f5ea;border-color:#68b17a;color:#14451f}
            .kop-dired-pill--warn{background:#fcf0e3;border-color:#d39a5c;color:#5c3205}
            .kop-dired-body{padding:0 16px 14px;border-top:1px solid #dcdcde}
            .kop-dired-meta{margin:.6em 0}
            .kop-dired-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start;margin:10px 0}
            .kop-dired-actions>.kop-ak-fold{flex:1 1 100%}
            .kop-dired-form{display:inline}
            .kop-ak-fold .kop-dired-form,.kop-dired-move{display:block}
            .kop-dired-programs{border-collapse:collapse;width:100%;margin:.5em 0}
            .kop-dired-programs th,.kop-dired-programs td{text-align:left;padding:6px 8px;border-top:1px solid #dcdcde;vertical-align:top}
            .kop-dired-tick{width:28px}
            .kop-dired-rel,.kop-dired-row-actions{white-space:nowrap}
            .kop-dired-move{background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;padding:8px 10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center}
            .kop-dired-move select{max-width:100%}
            .kop-dired-inline{display:inline;margin-left:8px}
            @media(max-width:767px){
                .kop-dired-programs,.kop-dired-programs tbody,.kop-dired-programs tr,.kop-dired-programs td{display:block;width:100%}
                .kop-dired-programs thead{display:none}
                .kop-dired-programs tr{border-top:1px solid #dcdcde;padding:6px 0}
                .kop-dired-programs td{border:0;padding:3px 0;white-space:normal}
                .kop-dired input.regular-text,.kop-dired select{width:100%;max-width:100%}
            }
        </style>';

        if ($tab === 'log') {
            $rows = kop_dired_log_rows(150);
            if (!$rows) echo kop_ak_empty('No changes yet.');
            else {
                echo '<table class="kop-dired-programs"><thead><tr><th>When (UTC)</th><th>Who</th><th>What</th><th></th></tr></thead><tbody>';
                foreach ($rows as $e) {
                    echo '<tr><td>' . esc_html($e['done_at']) . '</td><td>' . esc_html($e['done_by']) . '</td><td>' . esc_html(kop_dired_log_words($e)) . '</td><td>'
                        . (!empty($e['undone_at']) ? 'Undone ' . esc_html($e['undone_at']) . ($e['undone_by'] !== '' ? ' by ' . esc_html($e['undone_by']) : '')
                            : kop_dired_form('undo', array('log' => (int) $e['id'], 'back_tab' => 'log'), kop_dired_submit('Undo')))
                        . '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div>';
            return;
        }

        echo kop_ak_filters(array('page' => 'kop-directory-editor', 'tab' => $tab === 'all' ? '' : $tab), array(
            array('type' => 'search', 'name' => 'q', 'value' => $q, 'placeholder' => 'Company, other name or program', 'label' => 'Search'),
        ), kop_dired_admin_url(array_filter(array('tab' => $tab === 'all' ? '' : $tab))));

        $create = kop_dired_form('create', array(), '<p><label>Name<br><input type="text" name="name" required class="regular-text"></label></p>'
            . '<p>' . kop_dired_submit('Add the company', 'primary') . '</p>');
        echo kop_ak_fold('Add a company', $create);

        $list = array_filter($all, function ($c) use ($tab, $q) {
            if ($tab === 'dupe' && !in_array($c['state'], array('dupe', 'noname'), true)) return false;
            if (in_array($tab, array('shown', 'few', 'hidden'), true) && $c['state'] !== $tab) return false;
            return kop_dired_matches($c, $q);
        });
        uasort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
        echo '<p class="kop-dired-count">' . count($list) . ' compan' . (count($list) === 1 ? 'y' : 'ies') . ($q !== '' ? ' matching "' . esc_html($q) . '"' : '') . '. Open one to edit it.</p>';
        if (!$list) echo kop_ak_empty('No company here.');
        $one = count($list) === 1;
        foreach ($list as $oid => $c) {
            echo kop_dired_card($c, $all, $one || $open === (int) $oid, $back);
        }
        echo '</div>';
    }
}
