<?php
/**
 * "Pick a record, or make a new one": one field for any action that ties
 * something to a company, program, consultant, provider, transporter,
 * marketing company or trade organization.
 *
 * A queue (or any admin screen) puts the field in an action's params:
 *
 *   kop_record_field_param('company', 'Company', array('kinds' => array('company'), 'value' => 'company:45'))
 *
 * The browser draws the record finder (inc/facility-finder.php) limited to
 * those kinds, with a last line "New company named <what was typed>". The
 * action gets back one value, and turns it into a record with
 *
 *   $rec = kop_record_field_resolve($params['company'], array('kinds' => array('company'),
 *       'cite' => 'the IRS listing of "Acme Youth Services"', 'url' => 'https://...'));
 *   // {kind, id, name, created}: created = it was made just now, citing 'cite'
 *
 * A new record is made by kop_wbc_create() (inc/woodbury-create.php), the
 * same create Woodbury Reports, Wiki links and Woodbury Facts use: its notes
 * say where it came from, a name already on file is refused.
 *
 * The queue's Undo calls kop_record_field_unmake($rec['kind'], $rec['id'])
 * after taking off its own link: a company made here is deleted again when
 * nothing has changed or linked it since (its empty folder too); anything
 * else stays and the message says so.
 *
 * Values: '12' (a facility id), 'company:45' (any other record, as the
 * finder's any-record mode gives it), 'new:company:Acme Youth Services'.
 * Tested by scripts/test-record-field.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** What each kind is called in a sentence ("New company named ..."). */
function kop_record_field_kind_words() {
    return array(
        'facility'    => 'program',
        'company'     => 'company',
        'consultant'  => 'educational consultant',
        'provider'    => 'mental health provider',
        'transporter' => 'transporter',
        'marketer'    => 'marketing company',
        'tradeorg'    => 'trade organization',
    );
}

/** 'operator' (the filings' word) and 'company' are one kind. */
function kop_record_field_kind($kind) {
    $kind = strtolower(trim((string) $kind));
    return $kind === 'operator' ? 'company' : $kind;
}

/** The value the field holds for a record: a facility's id, else "<kind>:<id>". */
function kop_record_field_token($kind, $id) {
    $kind = kop_record_field_kind($kind);
    if ((int) $id <= 0) return '';
    return $kind === 'facility' ? (string) (int) $id : $kind . ':' . (int) $id;
}

/**
 * The param for an action. $o: kinds (default company), create (the kinds a
 * new record may be made of; default the same as kinds, false for none),
 * value (a token or a facility id), optional.
 */
function kop_record_field_param($name, $label, array $o = array()) {
    $words = kop_record_field_kind_words();
    $kinds = array_values(array_intersect(array_map('kop_record_field_kind', (array) ($o['kinds'] ?? array('company'))), array_keys($words)));
    if (!$kinds) $kinds = array('company');
    $create = array_key_exists('create', $o) ? $o['create'] : $kinds;
    $create = $create === false ? array() : array_values(array_intersect(array_map('kop_record_field_kind', (array) $create), $kinds));
    $value = (string) ($o['value'] ?? '');
    $param = array('name' => $name, 'label' => $label, 'type' => 'record', 'kinds' => $kinds, 'create' => $create,
        'value' => $value, 'value_label' => $value !== '' ? kop_record_field_label($value) : '');
    if (!empty($o['optional'])) $param['optional'] = true;
    return $param;
}

/** [kind, id, new name] from a field value; kind '' when it holds nothing usable. */
function kop_record_field_parse($value) {
    $value = trim((string) $value);
    if ($value === '') return array('', 0, '');
    if (preg_match('/^new:([a-z]+):(.+)$/s', $value, $m)) {
        return array(kop_record_field_kind($m[1]), 0, trim(preg_replace('/\s+/', ' ', $m[2])));
    }
    if (ctype_digit($value)) return array('facility', (int) $value, '');
    if (preg_match('/^([a-z]+):(\d+)$/', $value, $m)) return array(kop_record_field_kind($m[1]), (int) $m[2], '');
    return array('', 0, '');
}

/** A record's name as the finder shows it, '' when it is not on file. */
function kop_record_field_name($kind, $id) {
    global $wpdb;
    $kind = kop_record_field_kind($kind);
    $id = (int) $id;
    if ($id <= 0) return '';
    if ($kind === 'facility') return (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', $id));
    if ($kind === 'company') {
        return (string) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(NULLIF(name, \'\'), unique_name) FROM ' . $wpdb->prefix . 'kop_operators WHERE id = %d', $id));
    }
    if (function_exists('kop_wbc_record_tables') && function_exists('kop_wbc_pdo')) {
        $spec = kop_wbc_record_tables()[$kind] ?? null;
        $pdo = kop_wbc_pdo();
        $table = $spec && function_exists('kop_wbc_table_if_there') ? kop_wbc_table_if_there($pdo, $spec['table']) : '';
        if ($table !== '') {
            $st = $pdo->prepare("SELECT unique_name FROM `{$table}` WHERE id = ?");
            $st->execute(array($id));
            return (string) $st->fetchColumn();
        }
    }
    return '';
}

/** What the field shows as picked for a value ("Acme Youth Services", "New company: Acme"). */
function kop_record_field_label($value) {
    list($kind, $id, $new) = kop_record_field_parse($value);
    if ($kind === '') return '';
    if ($new !== '') return 'New ' . (kop_record_field_kind_words()[$kind] ?? $kind) . ': ' . $new;
    $name = kop_record_field_name($kind, $id);
    return $name !== '' ? $name : ucfirst(kop_record_field_kind_words()[$kind] ?? $kind) . ' #' . $id . ' (not on file)';
}

/** The record made by this field: [kind:id => {at, by, json}], for Undo. */
function kop_record_field_made() {
    $v = get_option('kop_record_field_made', array());
    return is_array($v) ? $v : array();
}

/**
 * The record a field value names, made now when it is a new one.
 * $o: kinds (allowed; default company), create (kinds that may be made;
 * default kinds), cite and url (where the new record's notes say it came
 * from), city, state, country (for the new record), by (who).
 * Returns {kind, id, name, created}. Throws with a sentence for the reviewer.
 */
function kop_record_field_resolve($value, array $o = array()) {
    $kinds = array_map('kop_record_field_kind', (array) ($o['kinds'] ?? array('company')));
    $create = array_key_exists('create', $o) ? ($o['create'] === false ? array() : array_map('kop_record_field_kind', (array) $o['create'])) : $kinds;
    $words = kop_record_field_kind_words();
    $want = implode(' or ', array_map(function ($k) use ($words) { return 'a ' . ($words[$k] ?? $k); }, $kinds));
    list($kind, $id, $new) = kop_record_field_parse($value);
    if ($kind === '') throw new RuntimeException('Pick ' . $want . ' in the finder, or type a name and pick the "New" line.');
    if (!in_array($kind, $kinds, true)) throw new RuntimeException('This needs ' . $want . ', not a ' . ($words[$kind] ?? $kind) . '.');

    if ($new === '') {
        $name = kop_record_field_name($kind, $id);
        if ($name === '') throw new RuntimeException(ucfirst($words[$kind] ?? $kind) . ' #' . $id . ' is not on file any more.');
        return array('kind' => $kind, 'id' => $id, 'name' => $name, 'created' => false);
    }

    if (!in_array($kind, $create, true)) throw new RuntimeException('A new ' . ($words[$kind] ?? $kind) . ' cannot be made here: pick one on file.');
    if (!function_exists('kop_wbc_create')) throw new RuntimeException('Making a new record is not available here.');
    if (mb_strlen($new) > 160) throw new RuntimeException('That name is too long for a record.');
    $cite = trim((string) ($o['cite'] ?? ''));
    $source = array('cite' => $cite !== '' ? $cite : 'the Review Inbox', 'url' => (string) ($o['url'] ?? ''));
    $f = array('kind' => $kind, 'name' => $new,
        'city' => (string) ($o['city'] ?? ''), 'state' => (string) ($o['state'] ?? ''), 'country' => (string) ($o['country'] ?? ''),
        'type' => '', 'who' => 'firm');
    $made = kop_wbc_create($source, $f);
    $id = (int) $made['id'];
    kop_record_field_remember($kind, $id, (int) ($made['folder'] ?? 0), (string) ($o['by'] ?? ''));
    return array('kind' => $kind, 'id' => $id, 'name' => (string) $made['name'], 'created' => true, 'url' => (string) ($made['url'] ?? ''));
}

/** Keep a record just made for Undo: as it was made (to tell whether anyone changed it since) and its new folder. */
function kop_record_field_remember($kind, $id, $folder, $by) {
    $kind = kop_record_field_kind($kind);
    $all = kop_record_field_made();
    $all[$kind . ':' . (int) $id] = array('at' => gmdate('Y-m-d H:i:s'), 'by' => (string) $by,
        'json' => kop_record_field_json_hash($kind, $id), 'folder' => (int) $folder);
    update_option('kop_record_field_made', $all, false);
}

/**
 * Resolve the field, then $link($id) makes the caller's own link (a filing to
 * a company page...). A record made here is deleted again when $link fails.
 * $o as for kop_record_field_resolve(). Returns $link's message, after
 * "Made the company ..." when it was made now.
 */
function kop_record_field_link($value, array $o, callable $link) {
    $rec = kop_record_field_resolve($value, $o);
    try {
        $msg = (string) $link((int) $rec['id']);
    } catch (Throwable $e) {
        if ($rec['created']) kop_record_field_unmake($rec['kind'], $rec['id']);
        throw $e;
    }
    if (!$rec['created']) return $msg;
    return 'Made the ' . (kop_record_field_kind_words()[$rec['kind']] ?? $rec['kind']) . ' "' . $rec['name'] . '"'
        . (!empty($o['cite']) ? ' (its notes cite ' . $o['cite'] . ')' : '') . '. ' . $msg;
}

/** md5 of a company's stored record, '' for other kinds or when it is gone. */
function kop_record_field_json_hash($kind, $id) {
    global $wpdb;
    if (kop_record_field_kind($kind) !== 'company') return '';
    $json = $wpdb->get_var($wpdb->prepare('SELECT json_data FROM ' . $wpdb->prefix . 'kop_operators WHERE id = %d', (int) $id));
    return $json === null ? '' : md5((string) $json);
}

/** Tables that may point at a company: [table => column]. A new one goes here so Undo never deletes a company in use. */
function kop_record_field_company_refs() {
    global $wpdb;
    return array(
        $wpdb->prefix . 'kop_operator_facilities' => 'operator_id',
        $wpdb->prefix . 'kop_nonprofit_links'     => 'operator_id',
        $wpdb->prefix . 'kop_business_links'      => 'operator_id',
    );
}

/**
 * After a queue's Undo has taken off its own link: delete the record this
 * field made, when it is a company nobody has changed or linked since.
 * Returns a sentence for the Undo message ('' when it was not made here).
 */
function kop_record_field_unmake($kind, $id) {
    global $wpdb;
    $kind = kop_record_field_kind($kind);
    $id = (int) $id;
    $all = kop_record_field_made();
    $k = $kind . ':' . $id;
    if (!isset($all[$k])) return '';
    $name = kop_record_field_name($kind, $id);
    $forget = function () use ($k) {
        $all = kop_record_field_made();
        unset($all[$k]);
        update_option('kop_record_field_made', $all, false);
    };
    if ($name === '') {
        $forget();
        return '';
    }
    if ($kind !== 'company') return ' The ' . (kop_record_field_kind_words()[$kind] ?? $kind) . ' record "' . $name . '" it made stays.';
    if (kop_record_field_json_hash($kind, $id) !== $all[$k]['json']) {
        $forget();
        return ' The company "' . $name . '" it made stays: it was edited since.';
    }
    foreach (kop_record_field_company_refs() as $table => $col) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) continue;
        if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$col} = %d", $id))) {
            return ' The company "' . $name . '" it made stays: something else is linked to it now.';
        }
    }
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    $pdo = function_exists('kop_wbc_pdo') ? kop_wbc_pdo() : null;
    $unique = (string) $wpdb->get_var($wpdb->prepare('SELECT unique_name FROM ' . $wpdb->prefix . 'kop_operators WHERE id = %d', $id));
    $res = $pdo ? kop_v2_delete_form_project($pdo, $wpdb->prefix, $unique, false) : array('deleted' => false);
    if (empty($res['deleted'])) return ' The company "' . $name . '" it made stays: it could not be deleted.';
    kop_record_field_drop_folder((int) $all[$k]['folder']);
    $forget();
    if (function_exists('kop_operator_pages_index')) kop_operator_pages_index(true);
    return ' The company "' . $name . '" it made is deleted again.';
}

/** The new record's FileBird folder, only while nothing is in it. */
function kop_record_field_drop_folder($folder) {
    global $wpdb;
    if ($folder <= 0) return;
    $fbv = $wpdb->prefix . 'fbv';
    $rel = $wpdb->prefix . 'fbv_attachment_folder';
    if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$rel} WHERE folder_id = %d", $folder))) return;
    if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$fbv} WHERE parent = %d", $folder))) return;
    $wpdb->delete($fbv, array('id' => $folder));
}
