<?php
/**
 * Investors: the private equity firms, lenders and other money behind the
 * companies and programs (inc/investors.php).
 *
 * Until now an investor was a line of free text on a company record
 * (operator.investors, "Altamont Capital Partners (majority owner from 2017)")
 * or a facility record (identification.investors). Here each investor is one
 * row, and each stake one link row:
 *
 *   {prefix}kop_investors       id, name, name_key, kind (Dropdown Lists 'investor-kinds'), website,
 *                               about (what the firm is, public), sources (JSON [{url, label}]),
 *                               created_by, created_at, updated_at
 *   {prefix}kop_investor_links  id, investor_id, operator_id | facility_id (one of the two), role
 *                               (Dropdown Lists 'investor-roles'), start_year, end_year, detail (the
 *                               words of the stake: "preferred units"), source_url, created_by, created_at
 *   {prefix}kop_investor_log    every add, edit, delete, link, link edit and take-off with the rows
 *                               before and after, so Undo puts back exactly what was there
 *
 * Company and facility pages print the linked investors in their "Investors"
 * line (kop_inv_fact_items()); a facility also names the investors of the
 * companies that ran it ("of Sequel TSI"). Free-text entries still on a record
 * print only while no linked investor has their name. The first admin load
 * after the deploy turns every free-text entry into an investor and a link
 * (kop_inv_import_all(), logged like any other change, so each can be undone);
 * an entry typed into the data forms later waits on KOP Tools > Investors >
 * Not linked yet.
 *
 * Edited at KOP Tools > Investors (kop_inv_admin_page()).
 *
 * @package KidsOverProfits
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_INV_DB_VERSION = '1';
const KOP_INV_IMPORT_VERSION = '1';

function kop_inv_table($which = 'investors') {
    global $wpdb;
    $names = array('investors' => 'kop_investors', 'links' => 'kop_investor_links', 'log' => 'kop_investor_log');
    return $wpdb->prefix . ($names[$which] ?? 'kop_investors');
}

function kop_inv_install($force = false) {
    if (!$force && get_option('kop_inv_db') === KOP_INV_DB_VERSION) return;
    global $wpdb;
    $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_inv_table('investors') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(200) NOT NULL,
        name_key VARCHAR(200) NOT NULL DEFAULT '',
        kind VARCHAR(80) NOT NULL DEFAULT '',
        website VARCHAR(500) NOT NULL DEFAULT '',
        about TEXT NULL,
        sources LONGTEXT NULL,
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY name_key (name_key(100))
    )" . $tail);
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_inv_table('links') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        investor_id BIGINT UNSIGNED NOT NULL,
        operator_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        role VARCHAR(80) NOT NULL DEFAULT '',
        start_year SMALLINT NULL,
        end_year SMALLINT NULL,
        detail VARCHAR(255) NOT NULL DEFAULT '',
        source_url VARCHAR(500) NOT NULL DEFAULT '',
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY investor_id (investor_id),
        KEY operator_id (operator_id),
        KEY facility_id (facility_id)
    )" . $tail);
    $wpdb->query('CREATE TABLE IF NOT EXISTS ' . kop_inv_table('log') . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        action VARCHAR(20) NOT NULL,
        investor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        link_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        before_json LONGTEXT NULL,
        after_json LONGTEXT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        done_by VARCHAR(60) NOT NULL DEFAULT '',
        done_at DATETIME NOT NULL,
        undone_by VARCHAR(60) NOT NULL DEFAULT '',
        undone_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY investor_id (investor_id)
    )" . $tail);
    update_option('kop_inv_db', KOP_INV_DB_VERSION, false);
}
add_action('admin_init', 'kop_inv_install', 5);

function kop_inv_ready() {
    static $ready = null;
    if ($ready === null) {
        global $wpdb;
        $ready = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', kop_inv_table('links'))) === kop_inv_table('links');
    }
    return $ready;
}

/* ---- Names and words ------------------------------------------------------ */

/** "Alaris Royalty Corp." and "Alaris Royalty" are one investor: lower case, no punctuation, no legal suffix. */
function kop_inv_name_key($name) {
    $s = strtolower(trim((string) $name));
    $s = str_replace('&', ' and ', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    $s = trim(preg_replace('/\s+/', ' ', $s));
    $s = preg_replace('/(?:\s+(?:inc|incorporated|corp|corporation|co|company|llc|lp|l p|llp|ltd|limited|plc))+$/', '', $s);
    return trim((string) $s);
}

function kop_inv_kinds($mode = 'all') {
    return function_exists('kop_choices') ? array_values(kop_choices('investor-kinds', null, $mode, false)) : array();
}

function kop_inv_roles($mode = 'all') {
    return function_exists('kop_choices') ? array_values(kop_choices('investor-roles', null, $mode, false)) : array();
}

/** "2009 to 2013", "from 2017", "until 2008", or ''. */
function kop_inv_years_label($start, $end) {
    $start = (int) $start;
    $end = (int) $end;
    if ($start && $end) return $start === $end ? (string) $start : $start . ' to ' . $end;
    if ($start) return 'from ' . $start;
    if ($end) return 'until ' . $end;
    return '';
}

function kop_inv_year($v) {
    $v = (int) $v;
    return $v >= 1900 && $v <= 2100 ? $v : null;
}

/**
 * A free-text entry read into its parts: "Levine Leichtman Capital Partners
 * (minority investor, 2009-2013)" -> {name, detail, role, start_year, end_year}.
 * The detail keeps the words as written; role and years are only what they say.
 */
function kop_inv_parse_entry($text) {
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    $name = $text;
    $detail = '';
    if (preg_match('/^(.+?)\s*\(([^()]*)\)\s*$/u', $text, $m)) {
        $name = trim($m[1]);
        $detail = trim($m[2]);
    }
    $d = strtolower($detail);
    $role = 'Investor';
    if (strpos($d, 'majority') !== false) $role = 'Majority owner';
    elseif (strpos($d, 'minority') !== false) $role = 'Minority investor';
    elseif (preg_match('/preferred|royalt/', $d)) $role = 'Preferred equity or royalty';
    elseif (preg_match('/financ|loan|lend|debt|mezzanine|credit/', $d)) $role = 'Lender or financing';
    $start = $end = null;
    if (preg_match('/\b((?:19|20)\d\d)\s*(?:-|–|to)\s*((?:19|20)\d\d)\b/u', $d, $m)) {
        $start = (int) $m[1];
        $end = (int) $m[2];
    } elseif (preg_match('/\b(?:exited|until|prior to|before|to|through|sold in)\s+(?:[a-z]+\s+)?((?:19|20)\d\d)\b/', $d, $m)) {
        $end = (int) $m[1];
    } elseif (preg_match('/\b((?:19|20)\d\d)\b/', $d, $m)) {
        // "from 2017", "since 2017", "(2019)": the year the stake began.
        $start = (int) $m[1];
    }
    return array('name' => $name, 'detail' => $detail, 'role' => $role, 'start_year' => $start, 'end_year' => $end);
}

/** A first guess at the kind from the name, for the import (the owner corrects it on the screen). */
function kop_inv_guess_kind($name) {
    $n = strtolower((string) $name);
    if (strpos($n, 'royalt') !== false) return 'Royalty or specialty finance';
    if (preg_match('/\b(bank|lending|credit|lender)\b/', $n)) return 'Lender';
    if (preg_match('/\b(venture|ventures)\b/', $n)) return 'Venture capital firm';
    if (preg_match('/\b(capital|equity|partners|holdings)\b/', $n)) return 'Private equity firm';
    return '';
}

/* ---- Reading --------------------------------------------------------------- */

function kop_inv_get($id) {
    if (!kop_inv_ready() || (int) $id <= 0) return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_inv_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    return is_array($row) ? $row : null;
}

function kop_inv_link_get($id) {
    if (!kop_inv_ready() || (int) $id <= 0) return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_inv_table('links') . ' WHERE id = %d', (int) $id), ARRAY_A);
    return is_array($row) ? $row : null;
}

/** Every investor, A to Z. */
function kop_inv_all() {
    if (!kop_inv_ready()) return array();
    global $wpdb;
    $rows = $wpdb->get_results('SELECT * FROM ' . kop_inv_table() . ' ORDER BY name', ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/** The investor with this name (by name key), or null. */
function kop_inv_find_by_name($name) {
    $key = kop_inv_name_key($name);
    if ($key === '' || !kop_inv_ready()) return null;
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_inv_table() . ' WHERE name_key = %s ORDER BY id LIMIT 1', $key), ARRAY_A);
    return is_array($row) ? $row : null;
}

/** Link rows, oldest stake first: ['investor_id' => n] or ['operator_id' => n] or ['facility_id' => n]. */
function kop_inv_links(array $where) {
    if (!kop_inv_ready()) return array();
    global $wpdb;
    $col = key($where);
    if (!in_array($col, array('investor_id', 'operator_id', 'facility_id'), true)) return array();
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_inv_table('links') . " WHERE {$col} = %d ORDER BY COALESCE(start_year, end_year, 9999), id", (int) current($where)), ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/** 'company' or 'facility' and the record id of a link row. */
function kop_inv_link_record(array $l) {
    return (int) $l['operator_id'] ? array('company', (int) $l['operator_id']) : array('facility', (int) $l['facility_id']);
}

function kop_inv_record_name($kind, $id) {
    if (function_exists('kop_record_field_name')) {
        $n = kop_record_field_name($kind === 'company' ? 'company' : 'facility', (int) $id);
        if ($n !== '') return $n;
    }
    global $wpdb;
    $n = $kind === 'company'
        ? $wpdb->get_var($wpdb->prepare('SELECT name FROM ' . $wpdb->prefix . 'kop_operators WHERE id = %d', (int) $id))
        : $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', (int) $id));
    return $n ? (string) $n : '';
}

function kop_inv_record_url($kind, $id) {
    if ($kind === 'company') return function_exists('kop_operator_page_url') ? (string) kop_operator_page_url((int) $id) : '';
    return function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $id) : '';
}

/* ---- Pages ----------------------------------------------------------------- */

/** "Altamont Capital Partners (majority owner, from 2017)"; $of names the company a facility's investor put money in. */
function kop_inv_link_text(array $inv, array $l, $of = '') {
    $role = trim((string) $l['role']);
    $bits = array();
    if ($role !== '' && $role !== 'Investor') {
        $r = strtolower($role);
        if (function_exists('kop_proper_names_apply')) $r = kop_proper_names_apply($r);
        $bits[] = $r . ($of !== '' ? ' of ' . $of : '');
    } elseif ($of !== '') {
        $bits[] = 'in ' . $of;
    }
    $years = kop_inv_years_label($l['start_year'], $l['end_year']);
    if ($years !== '') $bits[] = $years;
    return trim((string) $inv['name']) . ($bits ? ' (' . implode(', ', $bits) . ')' : '');
}

/**
 * The "Investors" line of a company page ($kind 'company', $ids = the page's
 * company rows) or a facility page ($kind 'facility'): linked investors first,
 * then the record's own free-text entries ($free) that name no linked investor.
 */
function kop_inv_fact_items($kind, $ids, array $free = array()) {
    $ids = array_values(array_filter(array_map('intval', (array) $ids)));
    $items = array();
    $keys = array();
    $memo = array();
    $inv = function ($id) use (&$memo) {
        if (!array_key_exists($id, $memo)) $memo[$id] = kop_inv_get($id);
        return $memo[$id];
    };
    $add = function (array $l, $of = '') use (&$items, &$keys, $inv) {
        $i = $inv((int) $l['investor_id']);
        if (!$i) return;
        $text = kop_inv_link_text($i, $l, $of);
        if (!in_array($text, $items, true)) $items[] = $text;
        $keys[(string) $i['name_key']] = true;
    };
    if (kop_inv_ready()) {
        if ($kind === 'company') {
            foreach ($ids as $id) foreach (kop_inv_links(array('operator_id' => $id)) as $l) $add($l);
        } else {
            foreach ($ids as $id) {
                foreach (kop_inv_links(array('facility_id' => $id)) as $l) $add($l);
                // The companies that ran it: their investors, named with the company.
                global $wpdb;
                $ops = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT operator_id FROM ' . $wpdb->prefix . 'kop_operator_facilities WHERE facility_id = %d', $id));
                foreach ((array) $ops as $oid) {
                    $links = kop_inv_links(array('operator_id' => (int) $oid));
                    if (!$links) continue;
                    $company = kop_inv_record_name('company', (int) $oid);
                    if (function_exists('kop_operator_pages_display_name')) $company = kop_operator_pages_display_name($company);
                    foreach ($links as $l) $add($l, $company);
                }
            }
        }
    }
    foreach ($free as $text) {
        $text = trim((string) $text);
        if ($text === '') continue;
        $p = kop_inv_parse_entry($text);
        if (isset($keys[kop_inv_name_key($p['name'])])) continue;
        if (!in_array($text, $items, true)) $items[] = $text;
    }
    return $items;
}

/* ---- Search ---------------------------------------------------------------- */

/**
 * Investors whose name holds $phrase, for the header dropdown, the search
 * popup and the search page: [{investor_id, title, url, meta}]. An investor
 * has no page of its own, so the result opens the page of the company or
 * program it holds (or held last) a stake in; one with no stake on a page is
 * left out.
 */
function kop_inv_search($phrase, $limit = 4) {
    $phrase = trim((string) $phrase);
    if (mb_strlen($phrase) < 2 || !kop_inv_ready()) return array();
    global $wpdb;
    $key = kop_inv_name_key($phrase);
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . kop_inv_table() . ' WHERE name LIKE %s' . ($key !== '' ? ' OR name_key LIKE %s' : '') . ' ORDER BY name LIMIT %d',
        ...array_merge(array('%' . $wpdb->esc_like($phrase) . '%'), $key !== '' ? array('%' . $wpdb->esc_like($key) . '%') : array(), array(max(1, (int) $limit) * 3))
    ), ARRAY_A);
    $out = array();
    foreach ((array) $rows as $i) {
        if (count($out) >= $limit) break;
        $links = kop_inv_links(array('investor_id' => (int) $i['id']));
        // The stake still held first, then the most recent.
        usort($links, function ($a, $b) {
            $ao = $a['end_year'] === null ? 1 : 0;
            $bo = $b['end_year'] === null ? 1 : 0;
            if ($ao !== $bo) return $bo <=> $ao;
            return ((int) ($b['start_year'] ?? $b['end_year'])) <=> ((int) ($a['start_year'] ?? $a['end_year']));
        });
        $url = '';
        $names = array();
        foreach ($links as $l) {
            list($kind, $rid) = kop_inv_link_record($l);
            $u = kop_inv_record_url($kind, $rid);
            $n = kop_inv_record_name($kind, $rid);
            if ($kind === 'company' && $n !== '' && function_exists('kop_operator_pages_display_name')) $n = kop_operator_pages_display_name($n);
            if ($url === '' && $u !== '') $url = $u;
            if ($n !== '' && !in_array($n, $names, true)) $names[] = $n;
        }
        if ($url === '') continue;
        $first = $links[0];
        $role = trim((string) $first['role']);
        if (count($names) === 1 && $role !== '' && $role !== 'Investor') {
            $r = strtolower($role);
            if (function_exists('kop_proper_names_apply')) $r = kop_proper_names_apply($r);
            $meta = 'Investor - ' . $r . ' of ' . $names[0];
        } else {
            $meta = 'Investor in ' . implode(', ', array_slice($names, 0, 2)) . (count($names) > 2 ? ' and ' . (count($names) - 2) . ' more' : '');
        }
        $out[] = array('investor_id' => (int) $i['id'], 'title' => (string) $i['name'], 'url' => $url, 'meta' => $meta);
    }
    return $out;
}

/* ---- The log --------------------------------------------------------------- */

function kop_inv_log($action, $investor_id, $link_id, $before, $after, $who, $note = '') {
    global $wpdb;
    $wpdb->insert(kop_inv_table('log'), array(
        'action' => $action, 'investor_id' => (int) $investor_id, 'link_id' => (int) $link_id,
        'before_json' => $before === null ? null : wp_json_encode($before),
        'after_json' => $after === null ? null : wp_json_encode($after),
        'note' => substr((string) $note, 0, 255), 'done_by' => substr((string) $who, 0, 60), 'done_at' => gmdate('Y-m-d H:i:s'),
    ));
    return (int) $wpdb->insert_id;
}

function kop_inv_log_rows($limit = 50, $investor_id = 0) {
    if (!kop_inv_ready()) return array();
    global $wpdb;
    $where = (int) $investor_id ? $wpdb->prepare(' WHERE investor_id = %d', (int) $investor_id) : '';
    $rows = $wpdb->get_results('SELECT * FROM ' . kop_inv_table('log') . $where . ' ORDER BY id DESC LIMIT ' . max(1, (int) $limit), ARRAY_A);
    return is_array($rows) ? $rows : array();
}

/** Move the pages that print the record: the page caches' fingerprints and LiteSpeed. */
function kop_inv_touch($kind, $id) {
    if (!(int) $id) return;
    if (function_exists('kop_record_changed')) kop_record_changed($kind === 'company' ? 'operator' : 'facility', array((int) $id));
    if ($kind === 'company') {
        $url = kop_inv_record_url('company', $id);
        if ($url !== '' && function_exists('do_action')) do_action('litespeed_purge_url', $url);
    }
}

/** The stored fields of a row, as strings, for "has it changed since?". */
function kop_inv_same(?array $a, ?array $b, array $fields = array()) {
    if (!$a || !$b) return false;
    foreach ($fields as $f) {
        if ((string) ($a[$f] ?? '') !== (string) ($b[$f] ?? '')) return false;
    }
    return true;
}

function kop_inv_fields() {
    return array('name', 'kind', 'website', 'about', 'sources');
}

function kop_inv_link_fields() {
    return array('investor_id', 'operator_id', 'facility_id', 'role', 'start_year', 'end_year', 'detail', 'source_url');
}

/* ---- Writing ---------------------------------------------------------------- */

/** Clean the investor fields from a form or the import. */
function kop_inv_clean(array $in) {
    $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
    if ($name === '') throw new RuntimeException('Give the investor a name.');
    $sources = array();
    $raw = $in['sources'] ?? array();
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : preg_split('/\R/', $raw);
    }
    foreach ((array) $raw as $s) {
        $url = trim(is_array($s) ? (string) ($s['url'] ?? '') : (string) $s);
        $label = is_array($s) ? trim((string) ($s['label'] ?? '')) : '';
        if ($url === '') continue;
        if (!preg_match('#^https?://#i', $url)) throw new RuntimeException('A source must be a web address starting with http: ' . $url);
        $sources[] = array('url' => $url, 'label' => $label);
    }
    $website = trim((string) ($in['website'] ?? ''));
    if ($website !== '' && !preg_match('#^https?://#i', $website)) $website = 'https://' . $website;
    return array(
        'name' => substr($name, 0, 200),
        'name_key' => kop_inv_name_key($name),
        'kind' => substr(trim((string) ($in['kind'] ?? '')), 0, 80),
        'website' => substr($website, 0, 500),
        'about' => trim((string) ($in['about'] ?? '')),
        'sources' => $sources ? wp_json_encode($sources) : '',
    );
}

/** Add an investor. Returns its id; an investor of the same name is refused. */
function kop_inv_add(array $in, $who) {
    if (!kop_inv_ready()) throw new RuntimeException('The investors tables are not set up yet: open any admin page once and try again.');
    $row = kop_inv_clean($in);
    $same = kop_inv_find_by_name($row['name']);
    if ($same) throw new RuntimeException('"' . $same['name'] . '" is already on file.');
    global $wpdb;
    $now = gmdate('Y-m-d H:i:s');
    $wpdb->insert(kop_inv_table(), $row + array('created_by' => substr((string) $who, 0, 60), 'created_at' => $now, 'updated_at' => $now));
    $id = (int) $wpdb->insert_id;
    if (!$id) throw new RuntimeException('The investor could not be saved.');
    kop_inv_log('add', $id, 0, null, kop_inv_get($id), $who);
    return $id;
}

function kop_inv_edit($id, array $in, $who) {
    $before = kop_inv_get($id);
    if (!$before) throw new RuntimeException('That investor is not on file any more.');
    $row = kop_inv_clean($in);
    $same = kop_inv_find_by_name($row['name']);
    if ($same && (int) $same['id'] !== (int) $id) throw new RuntimeException('"' . $same['name'] . '" is already on file.');
    if (kop_inv_same($before, $row, kop_inv_fields())) return 'Nothing changed.';
    global $wpdb;
    $wpdb->update(kop_inv_table(), $row + array('updated_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $id));
    kop_inv_log('edit', $id, 0, $before, kop_inv_get($id), $who);
    foreach (kop_inv_links(array('investor_id' => $id)) as $l) kop_inv_touch(...kop_inv_link_record($l));
    return 'Saved ' . $row['name'] . '.';
}

/** Delete an investor and its links (all kept in the log for Undo). */
function kop_inv_delete($id, $who) {
    $inv = kop_inv_get($id);
    if (!$inv) throw new RuntimeException('That investor is not on file any more.');
    $links = kop_inv_links(array('investor_id' => $id));
    global $wpdb;
    foreach ($links as $l) {
        $wpdb->delete(kop_inv_table('links'), array('id' => (int) $l['id']));
        kop_inv_touch(...kop_inv_link_record($l));
    }
    $wpdb->delete(kop_inv_table(), array('id' => (int) $id));
    kop_inv_log('delete', $id, 0, array('investor' => $inv, 'links' => $links), null, $who);
    return 'Deleted ' . $inv['name'] . (count($links) ? ' and its ' . count($links) . ' link' . (count($links) === 1 ? '' : 's') : '') . '. Undo is under Recent changes.';
}

/** Clean link fields: $record is a finder value ("123" = facility, "company:20"). */
function kop_inv_link_clean($investor_id, $record, array $in) {
    if (!kop_inv_get($investor_id)) throw new RuntimeException('That investor is not on file any more.');
    $record = trim((string) $record);
    if (ctype_digit($record)) $kind = 'facility';
    elseif (preg_match('/^(company|operator):(\d+)$/', $record, $m)) { $kind = 'company'; $record = $m[2]; }
    else throw new RuntimeException('Pick a company or a program in the finder.');
    $rid = (int) $record;
    if ($rid <= 0 || kop_inv_record_name($kind, $rid) === '') throw new RuntimeException(($kind === 'company' ? 'Company' : 'Record') . ' #' . $rid . ' is not on file.');
    $start = kop_inv_year($in['start_year'] ?? 0);
    $end = kop_inv_year($in['end_year'] ?? 0);
    if ($start && $end && $end < $start) throw new RuntimeException('The last year comes before the first.');
    $src = trim((string) ($in['source_url'] ?? ''));
    if ($src !== '' && !preg_match('#^https?://#i', $src)) throw new RuntimeException('The source must be a web address starting with http.');
    return array(
        'investor_id' => (int) $investor_id,
        'operator_id' => $kind === 'company' ? $rid : 0,
        'facility_id' => $kind === 'facility' ? $rid : 0,
        'role' => substr(trim((string) ($in['role'] ?? '')) ?: 'Investor', 0, 80),
        'start_year' => $start, 'end_year' => $end,
        'detail' => substr(trim((string) ($in['detail'] ?? '')), 0, 255),
        'source_url' => substr($src, 0, 500),
    );
}

/** Link an investor to a company or program. Returns the link id. */
function kop_inv_link_add($investor_id, $record, array $in, $who, $note = '') {
    $row = kop_inv_link_clean($investor_id, $record, $in);
    foreach (kop_inv_links(array('investor_id' => $investor_id)) as $l) {
        if ((int) $l['operator_id'] === $row['operator_id'] && (int) $l['facility_id'] === $row['facility_id']
            && (string) $l['role'] === $row['role'] && (string) $l['start_year'] === (string) $row['start_year']) {
            throw new RuntimeException('That stake is already listed.');
        }
    }
    global $wpdb;
    $wpdb->insert(kop_inv_table('links'), $row + array('created_by' => substr((string) $who, 0, 60), 'created_at' => gmdate('Y-m-d H:i:s')));
    $lid = (int) $wpdb->insert_id;
    if (!$lid) throw new RuntimeException('The link could not be saved.');
    kop_inv_log('link', $investor_id, $lid, null, kop_inv_link_get($lid), $who, $note);
    kop_inv_touch(...kop_inv_link_record($row));
    return $lid;
}

function kop_inv_link_edit($link_id, array $in, $who) {
    $before = kop_inv_link_get($link_id);
    if (!$before) throw new RuntimeException('That link is not on file any more.');
    list($kind, $rid) = kop_inv_link_record($before);
    $row = kop_inv_link_clean((int) $before['investor_id'], $kind === 'company' ? 'company:' . $rid : (string) $rid, $in);
    if (kop_inv_same($before, $row, kop_inv_link_fields())) return 'Nothing changed.';
    global $wpdb;
    $wpdb->update(kop_inv_table('links'), $row, array('id' => (int) $link_id));
    kop_inv_log('relink', (int) $before['investor_id'], $link_id, $before, kop_inv_link_get($link_id), $who);
    kop_inv_touch($kind, $rid);
    return 'Saved the stake in ' . kop_inv_record_name($kind, $rid) . '.';
}

function kop_inv_link_remove($link_id, $who) {
    $before = kop_inv_link_get($link_id);
    if (!$before) throw new RuntimeException('That link is not on file any more.');
    global $wpdb;
    $wpdb->delete(kop_inv_table('links'), array('id' => (int) $link_id));
    kop_inv_log('unlink', (int) $before['investor_id'], $link_id, $before, null, $who);
    list($kind, $rid) = kop_inv_link_record($before);
    kop_inv_touch($kind, $rid);
    return 'Taken off ' . kop_inv_record_name($kind, $rid) . '. Undo is under Recent changes.';
}

/** Put back exactly what log entry $log_id changed, while nothing has changed it since. */
function kop_inv_undo($log_id, $who) {
    global $wpdb;
    $e = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_inv_table('log') . ' WHERE id = %d', (int) $log_id), ARRAY_A);
    if (!$e) throw new RuntimeException('That change is not in the log.');
    if (!empty($e['undone_at'])) throw new RuntimeException('That change was undone already.');
    $before = $e['before_json'] !== null && $e['before_json'] !== '' ? json_decode($e['before_json'], true) : null;
    $after = $e['after_json'] !== null && $e['after_json'] !== '' ? json_decode($e['after_json'], true) : null;
    $iid = (int) $e['investor_id'];
    $lid = (int) $e['link_id'];
    $changed = 'It has changed since; undo the later changes first.';
    switch ($e['action']) {
        case 'add':
            if (!kop_inv_same(kop_inv_get($iid), $after, kop_inv_fields())) throw new RuntimeException($changed);
            if (kop_inv_links(array('investor_id' => $iid))) throw new RuntimeException('It has links now: take them off (or undo them) first.');
            $wpdb->delete(kop_inv_table(), array('id' => $iid));
            $msg = 'Undone: ' . $after['name'] . ' is off the list.';
            break;
        case 'edit':
            if (!kop_inv_same(kop_inv_get($iid), $after, kop_inv_fields())) throw new RuntimeException($changed);
            $back = array_intersect_key($before, array_flip(array_merge(kop_inv_fields(), array('name_key', 'updated_at'))));
            $wpdb->update(kop_inv_table(), $back, array('id' => $iid));
            foreach (kop_inv_links(array('investor_id' => $iid)) as $l) kop_inv_touch(...kop_inv_link_record($l));
            $msg = 'Undone: ' . $before['name'] . ' is as it was.';
            break;
        case 'delete':
            if (kop_inv_get($iid)) throw new RuntimeException('An investor with that id is on file again.');
            $wpdb->insert(kop_inv_table(), $before['investor']);
            foreach ((array) $before['links'] as $l) {
                $wpdb->insert(kop_inv_table('links'), $l);
                kop_inv_touch(...kop_inv_link_record($l));
            }
            $msg = 'Undone: ' . $before['investor']['name'] . ' is back' . ($before['links'] ? ' with its links' : '') . '.';
            break;
        case 'link':
            if (!kop_inv_same(kop_inv_link_get($lid), $after, kop_inv_link_fields())) throw new RuntimeException($changed);
            $wpdb->delete(kop_inv_table('links'), array('id' => $lid));
            kop_inv_touch(...kop_inv_link_record($after));
            $msg = 'Undone: the link is off.';
            break;
        case 'relink':
            if (!kop_inv_same(kop_inv_link_get($lid), $after, kop_inv_link_fields())) throw new RuntimeException($changed);
            $wpdb->update(kop_inv_table('links'), array_intersect_key($before, array_flip(kop_inv_link_fields())), array('id' => $lid));
            kop_inv_touch(...kop_inv_link_record($before));
            $msg = 'Undone: the stake is as it was.';
            break;
        case 'unlink':
            if (kop_inv_link_get($lid)) throw new RuntimeException('A link with that id is on file again.');
            if (!kop_inv_get((int) $before['investor_id'])) throw new RuntimeException('Its investor is gone: undo that delete first.');
            $wpdb->insert(kop_inv_table('links'), $before);
            kop_inv_touch(...kop_inv_link_record($before));
            $msg = 'Undone: the link is back.';
            break;
        default:
            throw new RuntimeException('That change cannot be undone.');
    }
    $wpdb->update(kop_inv_table('log'), array('undone_by' => substr((string) $who, 0, 60), 'undone_at' => gmdate('Y-m-d H:i:s')), array('id' => (int) $log_id));
    return $msg;
}

/* ---- Free-text entries on records ------------------------------------------- */

/**
 * Every free-text investor entry on a company or facility record that no link
 * holds yet: [{key: "company:20:2", kind, id, record, text, parsed}].
 * Facility entries are identification.investors only (provenance copies of
 * a company's list are not the facility's own).
 */
function kop_inv_unlinked_entries() {
    global $wpdb;
    $out = array();
    $scan = array();
    foreach ((array) $wpdb->get_results('SELECT id, name, json_data FROM ' . $wpdb->prefix . "kop_operators WHERE json_data LIKE '%investors%'", ARRAY_A) as $r) {
        $doc = json_decode((string) $r['json_data'], true);
        $list = $doc['operator']['investors'] ?? ($doc['investors'] ?? array());
        $scan[] = array('company', (int) $r['id'], (string) $r['name'], $list);
    }
    foreach ((array) $wpdb->get_results("SELECT id, name, json_data FROM facilities_v2 WHERE json_data LIKE '%investors%'", ARRAY_A) as $r) {
        $doc = json_decode((string) $r['json_data'], true);
        $scan[] = array('facility', (int) $r['id'], (string) $r['name'], $doc['identification']['investors'] ?? array());
    }
    foreach ($scan as list($kind, $id, $record, $list)) {
        if (!is_array($list) || !$list) continue;
        $held = array();
        foreach (kop_inv_links(array($kind === 'company' ? 'operator_id' : 'facility_id' => $id)) as $l) {
            $i = kop_inv_get((int) $l['investor_id']);
            if ($i) $held[(string) $i['name_key']] = true;
        }
        foreach (array_values($list) as $n => $text) {
            if (!is_string($text) || trim($text) === '') continue;
            $p = kop_inv_parse_entry($text);
            if (isset($held[kop_inv_name_key($p['name'])])) continue;
            $out[] = array('key' => $kind . ':' . $id . ':' . $n, 'kind' => $kind, 'id' => $id, 'record' => $record, 'text' => trim($text), 'parsed' => $p);
        }
    }
    return $out;
}

/** Turn one free-text entry into an investor (found by name or added) and a link. Returns the message. */
function kop_inv_import_entry(array $e, $who) {
    $p = $e['parsed'];
    $inv = kop_inv_find_by_name($p['name']);
    $made = false;
    if (!$inv) {
        $id = kop_inv_add(array('name' => $p['name'], 'kind' => kop_inv_guess_kind($p['name'])), $who);
        $inv = kop_inv_get($id);
        $made = true;
    }
    kop_inv_link_add((int) $inv['id'], $e['kind'] === 'company' ? 'company:' . $e['id'] : (string) $e['id'],
        array('role' => $p['role'], 'start_year' => $p['start_year'], 'end_year' => $p['end_year'], 'detail' => $p['detail']),
        $who, 'From the record\'s text: ' . $e['text']);
    return ($made ? 'Added ' : 'Linked ') . $inv['name'] . ' to ' . $e['record'] . '.';
}

/** Every unlinked entry at once. Returns [done, failed messages]. */
function kop_inv_import_all($who) {
    $done = 0;
    $failed = array();
    foreach (kop_inv_unlinked_entries() as $e) {
        try {
            kop_inv_import_entry($e, $who);
            $done++;
        } catch (RuntimeException $ex) {
            $failed[] = $e['text'] . ': ' . $ex->getMessage();
        }
    }
    return array($done, $failed);
}

/** The first admin load after the deploy moves the free-text entries into the tables, once. */
function kop_inv_maybe_import() {
    if (get_option('kop_inv_import') === KOP_INV_IMPORT_VERSION || !kop_inv_ready()) return;
    update_option('kop_inv_import', KOP_INV_IMPORT_VERSION, false);
    try {
        list($done, $failed) = kop_inv_import_all('import');
        if ($failed) error_log('kop_inv_maybe_import: ' . implode(' | ', $failed));
    } catch (Throwable $ex) {
        error_log('kop_inv_maybe_import: ' . $ex->getMessage());
    }
}
add_action('admin_init', 'kop_inv_maybe_import', 20);

/* ---- KOP Tools > Investors --------------------------------------------------- */

function kop_inv_admin_menu() {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Investors', 'Investors', 'manage_options', 'kop-investors', 'kop_inv_admin_page');
}
add_action('admin_menu', 'kop_inv_admin_menu', 30);

add_filter('kop_admin_kit_pages', function ($p) {
    $p[] = 'kop-investors';
    return $p;
});

function kop_inv_admin_url(array $args = array()) {
    return add_query_arg(array_merge(array('page' => 'kop-investors'), $args), admin_url('admin.php'));
}

function kop_inv_who() {
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    return $u && !empty($u->user_login) ? (string) $u->user_login : 'admin';
}

/** The screen's forms post here; every action redirects back with its message. */
function kop_inv_handle_post() {
    if (($_GET['page'] ?? '') !== 'kop-investors' || empty($_POST['kop_inv_act'])) return;
    if (!current_user_can('manage_options')) wp_die('Not allowed.', 403);
    check_admin_referer('kop_investors');
    $p = wp_unslash($_POST);
    $who = kop_inv_who();
    $back = array('tab' => sanitize_key($p['tab'] ?? 'list'));
    try {
        switch ($p['kop_inv_act']) {
            case 'add':
                $id = kop_inv_add($p, $who);
                $msg = 'Added ' . kop_inv_get($id)['name'] . '. Link it to the companies it put money in below.';
                $back['open'] = $id;
                break;
            case 'edit':
                $msg = kop_inv_edit((int) $p['id'], $p, $who);
                $back['open'] = (int) $p['id'];
                break;
            case 'delete':
                $msg = kop_inv_delete((int) $p['id'], $who);
                break;
            case 'link':
                kop_inv_link_add((int) $p['id'], (string) ($p['record'] ?? ''), $p, $who);
                $msg = 'Linked. The page lists the investor now.';
                $back['open'] = (int) $p['id'];
                break;
            case 'relink':
                $msg = kop_inv_link_edit((int) $p['link'], $p, $who);
                $back['open'] = (int) $p['id'];
                break;
            case 'unlink':
                $msg = kop_inv_link_remove((int) $p['link'], $who);
                $back['open'] = (int) $p['id'];
                break;
            case 'undo':
                $msg = kop_inv_undo((int) $p['log'], $who);
                break;
            case 'import':
                $msg = 'Nothing to add: that entry is linked already.';
                foreach (kop_inv_unlinked_entries() as $e) {
                    if ($e['key'] === (string) $p['key']) { $msg = kop_inv_import_entry($e, $who); break; }
                }
                break;
            case 'import_all':
                list($done, $failed) = kop_inv_import_all($who);
                $msg = $done . ' entr' . ($done === 1 ? 'y' : 'ies') . ' linked.' . ($failed ? ' Not done: ' . implode('; ', $failed) : '');
                break;
            default:
                $msg = 'Unknown action.';
        }
        $back['kop_inv_msg'] = rawurlencode($msg);
    } catch (RuntimeException $ex) {
        $back['kop_inv_err'] = rawurlencode($ex->getMessage());
    }
    wp_safe_redirect(kop_inv_admin_url($back));
    exit;
}
add_action('admin_init', 'kop_inv_handle_post', 30);

function kop_inv_submit($label, $variant = 'secondary', $confirm = '') {
    return '<button type="submit" class="button kop-ak-btn kop-ak-btn--' . esc_attr($variant) . '"'
        . ($confirm !== '' ? ' onclick="return confirm(' . esc_attr(wp_json_encode($confirm)) . ')"' : '') . '>' . esc_html($label) . '</button>';
}

/** A form posting $act with the nonce and $hidden fields around $body. */
function kop_inv_form($act, array $hidden, $body, $class = '') {
    $h = '<form method="post" class="kop-inv-form' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">' . wp_nonce_field('kop_investors', '_wpnonce', true, false)
        . '<input type="hidden" name="kop_inv_act" value="' . esc_attr($act) . '">';
    foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
    return $h . $body . '</form>';
}

function kop_inv_select($name, array $choices, $value, $blank = '') {
    $h = '<select name="' . esc_attr($name) . '">';
    if ($blank !== '') $h .= '<option value="">' . esc_html($blank) . '</option>';
    if ($value !== '' && !in_array($value, $choices, true)) $choices[] = $value;
    foreach ($choices as $c) $h .= '<option value="' . esc_attr($c) . '"' . selected($value, $c, false) . '>' . esc_html($c) . '</option>';
    return $h . '</select>';
}

/** The investor's own fields. */
function kop_inv_fields_html(array $i = array()) {
    $sources = array();
    foreach ((array) json_decode((string) ($i['sources'] ?? ''), true) as $s) $sources[] = $s['url'] ?? '';
    return '<p><label>Name<br><input type="text" name="name" required class="regular-text" value="' . esc_attr($i['name'] ?? '') . '"></label></p>'
        . '<p><label>Kind<br>' . kop_inv_select('kind', kop_inv_kinds('offered'), (string) ($i['kind'] ?? ''), 'Not known') . '</label></p>'
        . '<p><label>Website<br><input type="url" name="website" class="regular-text" value="' . esc_attr($i['website'] ?? '') . '"></label></p>'
        . '<p><label>About (printed nowhere yet; what the firm is and does)<br><textarea name="about" rows="3" class="large-text">' . esc_textarea($i['about'] ?? '') . '</textarea></label></p>'
        . '<p><label>Sources, one web address a line<br><textarea name="sources" rows="2" class="large-text">' . esc_textarea(implode("\n", $sources)) . '</textarea></label></p>';
}

/** The stake fields of a link. */
function kop_inv_link_fields_html(array $l = array()) {
    return '<p><label>Role<br>' . kop_inv_select('role', kop_inv_roles('offered'), (string) ($l['role'] ?? 'Investor')) . '</label> '
        . '<label>First year <input type="number" name="start_year" min="1900" max="2100" style="width:90px" value="' . esc_attr($l['start_year'] ?? '') . '"></label> '
        . '<label>Last year <input type="number" name="end_year" min="1900" max="2100" style="width:90px" value="' . esc_attr($l['end_year'] ?? '') . '"></label></p>'
        . '<p><label>The stake in its own words (optional)<br><input type="text" name="detail" class="regular-text" value="' . esc_attr($l['detail'] ?? '') . '" placeholder="preferred units"></label></p>'
        . '<p><label>Source<br><input type="url" name="source_url" class="regular-text" value="' . esc_attr($l['source_url'] ?? '') . '"></label></p>';
}

/** What a log entry did, in words. */
function kop_inv_log_words(array $e) {
    $before = $e['before_json'] ? json_decode($e['before_json'], true) : null;
    $after = $e['after_json'] ? json_decode($e['after_json'], true) : null;
    $inv = kop_inv_get((int) $e['investor_id']);
    $name = $inv['name'] ?? ($after['name'] ?? ($before['name'] ?? ($before['investor']['name'] ?? 'investor #' . (int) $e['investor_id'])));
    $rec = function ($l) {
        if (!is_array($l)) return '';
        list($kind, $rid) = kop_inv_link_record($l);
        return kop_inv_record_name($kind, $rid) ?: ($kind . ' #' . $rid);
    };
    switch ($e['action']) {
        case 'add': return 'Added ' . $name;
        case 'edit': return 'Edited ' . $name;
        case 'delete': return 'Deleted ' . $name;
        case 'link': return 'Linked ' . $name . ' to ' . $rec($after);
        case 'relink': return 'Changed ' . $name . '\'s stake in ' . $rec($after);
        case 'unlink': return 'Took ' . $name . ' off ' . $rec($before);
    }
    return $e['action'];
}

function kop_inv_admin_page() {
    if (!current_user_can('manage_options')) return;
    if (function_exists('kop_facility_finder_print_assets') && !has_action('admin_footer', 'kop_facility_finder_print_assets')) {
        add_action('admin_footer', 'kop_facility_finder_print_assets');
    }
    $tab = sanitize_key($_GET['tab'] ?? 'list');
    $open = (int) ($_GET['open'] ?? 0);
    $notices = '';
    if (isset($_GET['kop_inv_msg'])) $notices .= '<div class="notice notice-success"><p>' . esc_html(rawurldecode(wp_unslash($_GET['kop_inv_msg']))) . '</p></div>';
    if (isset($_GET['kop_inv_err'])) $notices .= '<div class="notice notice-error"><p>' . esc_html(rawurldecode(wp_unslash($_GET['kop_inv_err']))) . '</p></div>';
    if (!kop_inv_ready()) {
        echo '<div class="wrap"><h1>Investors</h1><p>The tables are being set up: reload this page.</p></div>';
        return;
    }
    $all = kop_inv_all();
    $unlinked = kop_inv_unlinked_entries();
    $header = function_exists('kop_ak_header')
        ? kop_ak_header('Investors', array('lede' => 'The private equity firms, lenders and other money behind the companies and programs. Each investor is linked to the companies or programs it put money in; their pages list it in the Investors line.', 'notices' => $notices))
        : '<h1>Investors</h1>' . $notices;
    $tabs = array(
        'list' => array('label' => 'Investors', 'count' => count($all)),
        'unlinked' => array('label' => 'Not linked yet', 'count' => count($unlinked), 'title' => 'Investors typed as text on a record that no link holds'),
        'log' => array('label' => 'Recent changes'),
    );
    echo '<div class="wrap kop-ak kop-inv">' . $header;
    echo function_exists('kop_ak_tabs') ? kop_ak_tabs($tabs, $tab, function ($k) { return kop_inv_admin_url(array('tab' => $k)); }) : '';
    echo '<style>.kop-inv-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:12px 16px;margin:12px 0}.kop-inv-card h2{margin:.2em 0}.kop-inv-meta{color:#4A5568;margin:.2em 0 .6em}.kop-inv-links{border-collapse:collapse;width:100%;margin:.5em 0}.kop-inv-links th,.kop-inv-links td{text-align:left;padding:6px 8px;border-top:1px solid #dcdcde;vertical-align:top}.kop-inv-form{display:inline}.kop-inv-form.kop-inv-block{display:block}.kop-inv-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:8px}@media(max-width:767px){.kop-inv-links,.kop-inv-links tbody,.kop-inv-links tr,.kop-inv-links td,.kop-inv-links th{display:block;width:100%}.kop-inv input.regular-text{width:100%}}</style>';

    if ($tab === 'unlinked') {
        echo '<p>Each line is an investor typed as text on a record. Link turns it into an investor (found by its name, or added) and a link with the role and years the words give; check them after.</p>';
        if (!$unlinked) {
            echo '<p><strong>Every investor typed on a record is linked.</strong></p>';
        } else {
            echo '<p>' . kop_inv_form('import_all', array('tab' => 'unlinked'), kop_inv_submit('Link all ' . count($unlinked), 'primary')) . '</p>';
            echo '<table class="kop-inv-links"><thead><tr><th>Record</th><th>As typed</th><th>Will be</th><th></th></tr></thead><tbody>';
            foreach ($unlinked as $e) {
                $p = $e['parsed'];
                $url = kop_inv_record_url($e['kind'], $e['id']);
                echo '<tr><td>' . ($e['kind'] === 'company' ? 'Company: ' : 'Program: ') . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($e['record']) . '</a>' : esc_html($e['record'])) . '</td>'
                    . '<td>' . esc_html($e['text']) . '</td>'
                    . '<td>' . esc_html($p['name'] . ': ' . strtolower($p['role']) . (kop_inv_years_label($p['start_year'], $p['end_year']) !== '' ? ', ' . kop_inv_years_label($p['start_year'], $p['end_year']) : ''))
                    . (kop_inv_find_by_name($p['name']) ? '' : ' <em>(new investor)</em>') . '</td>'
                    . '<td>' . kop_inv_form('import', array('tab' => 'unlinked', 'key' => $e['key']), kop_inv_submit('Link', 'primary')) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        return;
    }

    if ($tab === 'log') {
        $rows = kop_inv_log_rows(100);
        if (!$rows) echo '<p>No changes yet.</p>';
        else {
            echo '<table class="kop-inv-links"><thead><tr><th>When (UTC)</th><th>Who</th><th>What</th><th></th></tr></thead><tbody>';
            foreach ($rows as $e) {
                echo '<tr><td>' . esc_html($e['done_at']) . '</td><td>' . esc_html($e['done_by']) . '</td><td>' . esc_html(kop_inv_log_words($e))
                    . ($e['note'] !== '' ? '<br><small>' . esc_html($e['note']) . '</small>' : '') . '</td><td>'
                    . (!empty($e['undone_at']) ? 'Undone ' . esc_html($e['undone_at']) . ($e['undone_by'] !== '' ? ' by ' . esc_html($e['undone_by']) : '')
                        : kop_inv_form('undo', array('tab' => 'log', 'log' => (int) $e['id']), kop_inv_submit('Undo')))
                    . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
        return;
    }

    $add = kop_inv_form('add', array('tab' => 'list'), kop_inv_fields_html() . '<p>' . kop_inv_submit('Add investor', 'primary') . '</p>', 'kop-inv-block');
    echo function_exists('kop_ak_fold') ? kop_ak_fold('Add an investor', $add, !$all) : $add;
    if (!$all) echo '<p>No investors yet.</p>';
    foreach ($all as $i) {
        $id = (int) $i['id'];
        $links = kop_inv_links(array('investor_id' => $id));
        echo '<section class="kop-inv-card" id="investor-' . $id . '"><h2>' . esc_html($i['name']) . '</h2>';
        $meta = array_filter(array($i['kind'], $i['website'] !== '' ? '<a href="' . esc_url($i['website']) . '" target="_blank" rel="noopener">website</a>' : ''));
        echo '<p class="kop-inv-meta">' . ($meta ? implode(' &middot; ', array_map(function ($m) { return strpos($m, '<a ') === 0 ? $m : esc_html($m); }, $meta)) : 'Kind not set') . '</p>';
        if ($i['about'] !== '' && $i['about'] !== null) echo '<p>' . esc_html($i['about']) . '</p>';
        if ($links) {
            echo '<table class="kop-inv-links"><thead><tr><th>Company or program</th><th>Stake</th><th>Source</th><th></th></tr></thead><tbody>';
            foreach ($links as $l) {
                list($kind, $rid) = kop_inv_link_record($l);
                $rname = kop_inv_record_name($kind, $rid) ?: ($kind . ' #' . $rid . ' (not on file)');
                $url = kop_inv_record_url($kind, $rid);
                $stake = trim($l['role'] . (kop_inv_years_label($l['start_year'], $l['end_year']) !== '' ? ', ' . kop_inv_years_label($l['start_year'], $l['end_year']) : '') . ($l['detail'] !== '' ? ' (' . $l['detail'] . ')' : ''));
                $edit = kop_inv_form('relink', array('tab' => 'list', 'id' => $id, 'link' => (int) $l['id']), kop_inv_link_fields_html($l) . '<p>' . kop_inv_submit('Save stake', 'primary') . '</p>', 'kop-inv-block');
                echo '<tr><td>' . ($kind === 'company' ? 'Company: ' : 'Program: ') . ($url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($rname) . '</a>' : esc_html($rname)) . '</td>'
                    . '<td>' . esc_html($stake) . (function_exists('kop_ak_fold') ? kop_ak_fold('Edit', $edit) : '') . '</td>'
                    . '<td>' . ($l['source_url'] !== '' ? '<a href="' . esc_url($l['source_url']) . '" target="_blank" rel="noopener">source</a>' : '') . '</td>'
                    . '<td>' . kop_inv_form('unlink', array('tab' => 'list', 'id' => $id, 'link' => (int) $l['id']), kop_inv_submit('Take off', 'danger')) . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p>Not linked to any company or program yet.</p>';
        }
        $finder = '<input type="text" name="record" data-kop-facility-finder="1" data-kop-record-kinds="facility,company" placeholder="company or program" style="width:160px" required>';
        $link = kop_inv_form('link', array('tab' => 'list', 'id' => $id), '<p><label>Company or program<br>' . $finder . '</label></p>' . kop_inv_link_fields_html() . '<p>' . kop_inv_submit('Link', 'primary') . '</p>', 'kop-inv-block');
        $edit = kop_inv_form('edit', array('tab' => 'list', 'id' => $id), kop_inv_fields_html($i) . '<p>' . kop_inv_submit('Save', 'primary') . '</p>', 'kop-inv-block');
        echo '<div class="kop-inv-actions">';
        echo function_exists('kop_ak_fold') ? kop_ak_fold('Link to a company or program', $link, $open === $id && !$links) . kop_ak_fold('Edit ' . esc_html($i['name']), $edit) : $link . $edit;
        echo kop_inv_form('delete', array('tab' => 'list', 'id' => $id), kop_inv_submit('Delete', 'danger', 'Delete ' . $i['name'] . ($links ? ' and its ' . count($links) . ' link(s)' : '') . '? Undo is under Recent changes.'));
        echo '</div></section>';
    }
    echo '</div>';
}
