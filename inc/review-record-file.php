<?php
/**
 * Filing a review inbox item on any kind of record, not only a facility.
 *
 * A queue whose picker offers every kind (its 'record_kinds', KOP Tools >
 * Card Fields) gets "12" for a program or "<kind>:<id>" for anything else.
 * For a program it keeps its own path (it knows what a facility page holds).
 * For every other kind it calls kop_rinbox_file_on_record(), which puts the
 * item where that kind of record keeps it:
 *
 *   company      'link'    -> operator.resourceLinks ("Materials and links" on /operator/)
 *                'website' -> operator.websites
 *                'news'    -> {prefix}kop_operator_links (link_kind news): the
 *                             article is listed on the company page
 *                'note'    -> operator.notes
 *   ya           'link' / 'website' -> the program's links (kop_rdest_put_young_adult())
 *                'note' / 'news'    -> a fact citing the item's link (kop_ya_add_fact())
 *   consultant, transporter, provider, marketer, tradeorg
 *                anything  -> one line in the record's notes ("<what>: <link>"),
 *                             kop_wbc_add_notes()
 *
 * $p: what ('link', 'website', 'news' or 'note'), url, title, text (the line
 * for a note), kind (a "Materials and links" heading for a company link),
 * source (where it came from: "Sent in for review", "Fornits thread"...),
 * news_id (for 'news'), key (a stable key for the item, so a fact is not
 * added twice). Returns $done {kind, id, name, what, message, ...}, which
 * kop_rinbox_unfile_from_record() takes back exactly (the queue's Undo).
 * Throws with a sentence for the reviewer.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** [kind, id] of a picker value; a bare number is a program. kind '' for nothing usable. */
function kop_rinbox_record_of($value) {
    if (function_exists('kop_record_field_parse')) {
        list($kind, $id, $new) = kop_record_field_parse($value);
        if ($new !== '') return array('', 0);
        return array($kind, (int) $id);
    }
    $value = trim((string) $value);
    if (ctype_digit($value)) return array('facility', (int) $value);
    return preg_match('/^([a-z]+):(\d+)$/', $value, $m) ? array($m[1], (int) $m[2]) : array('', 0);
}

/** The page a record is shown on ('' when it has none of its own). */
function kop_rinbox_record_url($kind, $id) {
    $id = (int) $id;
    if ($kind === 'facility') return function_exists('kop_facility_page_url') ? (string) kop_facility_page_url($id) : '';
    if ($kind === 'company') return function_exists('kop_operator_page_url') ? (string) kop_operator_page_url($id) : '';
    if ($kind === 'ya') return home_url('/young-adult-programs/#kop-ya-program-' . $id);
    $pages = array('consultant' => '/referrers-educational-consultants/', 'transporter' => '/youth-transport-companies/', 'provider' => '/mental-health-providers/',
        'marketer' => '/marketing-companies/', 'tradeorg' => '/trade-organizations/');
    return isset($pages[$kind]) ? home_url($pages[$kind]) : '';
}

/** "the company "Acme"" for a result line. */
function kop_rinbox_record_words($kind, $name) {
    $words = function_exists('kop_record_field_kind_words') ? kop_record_field_kind_words() : array();
    return 'the ' . ($words[$kind] ?? $kind) . ' "' . $name . '"';
}

/** The note line an item becomes on a record that keeps notes only. */
function kop_rinbox_record_note_line(array $p) {
    $text = trim(preg_replace('/\s+/u', ' ', (string) ($p['text'] ?? '')));
    $title = trim(preg_replace('/\s+/u', ' ', (string) ($p['title'] ?? '')));
    $url = trim((string) ($p['url'] ?? ''));
    $line = $text !== '' ? $text : $title;
    if ($line === '' && $url === '') throw new RuntimeException('There is nothing to put on the record: no text and no link.');
    if ($url !== '' && strpos($line, $url) === false) $line = ($line !== '' ? $line . ': ' : '') . $url;
    $source = trim((string) ($p['source'] ?? ''));
    if ($source !== '') $line .= ' (' . $source . ')';
    return mb_substr($line, 0, 2000);
}

function kop_rinbox_file_on_record($value, array $p, $reviewer = '') {
    list($kind, $id) = kop_rinbox_record_of($value);
    if ($kind === '' || $id <= 0) throw new RuntimeException('Pick a record in the finder first.');
    if ($kind === 'facility') throw new RuntimeException('A program is filed by the queue itself.');
    $what = (string) ($p['what'] ?? 'note');
    $url = trim((string) ($p['url'] ?? ''));
    $title = trim((string) ($p['title'] ?? ''));
    $name = function_exists('kop_record_field_name') ? kop_record_field_name($kind, $id) : '';
    if ($name === '') throw new RuntimeException('That record is not on file any more.');

    if ($kind === 'company') {
        if (($what === 'link' || $what === 'website') && $url !== '') {
            $json = kop_gdl_company_load($id);
            $key = kop_gdl_url_key($url);
            $have = array_merge(array_map(function ($w) { return is_array($w) ? ($w['url'] ?? '') : $w; }, (array) ($json['operator']['websites'] ?? array())),
                array_column(array_filter((array) ($json['operator']['resourceLinks'] ?? array()), 'is_array'), 'url'));
            foreach ($have as $h) {
                if (is_string($h) && kop_gdl_url_key($h) === $key) throw new RuntimeException('That link is already on ' . kop_rinbox_record_words($kind, $name) . '.');
            }
            if ($what === 'website') {
                $json['operator']['websites'][] = $url;
            } else {
                $kinds = function_exists('kop_facility_resource_link_kinds') ? kop_facility_resource_link_kinds() : array('other' => 'Other links');
                $rk = (string) ($p['kind'] ?? 'other');
                $json['operator']['resourceLinks'][] = array('url' => $url, 'label' => $title !== '' ? $title : $url,
                    'kind' => isset($kinds[$rk]) ? $rk : 'other', 'source' => (string) ($p['source'] ?? 'Sent in for review'));
            }
            kop_gdl_company_save($id, $json);
            kop_rinbox_record_changed('company', $id);
            return array('kind' => 'company', 'id' => $id, 'name' => $name, 'what' => $what, 'url' => $url,
                'message' => 'Put the link on ' . kop_rinbox_record_words($kind, $name) . ($what === 'website' ? ' (its websites).' : ' ("Materials and links").'));
        }
        if ($what === 'news' && (int) ($p['news_id'] ?? 0) > 0) {
            global $wpdb;
            $table = $wpdb->prefix . 'kop_operator_links';
            $nid = (int) $p['news_id'];
            $there = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM `{$table}` WHERE operator_id = %d AND link_kind = 'news' AND link_id = %d", $id, $nid));
            if ($there) throw new RuntimeException('That article is already on ' . kop_rinbox_record_words($kind, $name) . '.');
            $ok = $wpdb->insert($table, array('operator_id' => $id, 'link_kind' => 'news', 'link_id' => $nid, 'link_type' => 'primary',
                'created_by' => $reviewer !== '' ? $reviewer : 'review inbox', 'created_at' => current_time('mysql', true)));
            if (!$ok) throw new RuntimeException('Could not link the article to the company: ' . $wpdb->last_error);
            kop_rinbox_record_changed('company', $id);
            return array('kind' => 'company', 'id' => $id, 'name' => $name, 'what' => 'news', 'news_id' => $nid,
                'message' => 'Listed the article on ' . kop_rinbox_record_words($kind, $name) . '.');
        }
        $what = 'note';
    }

    if ($kind === 'ya') {
        $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
        if (!$pdo) throw new RuntimeException('The young adult programs records are not available.');
        if (($what === 'link' || $what === 'website') && $url !== '') {
            $done = kop_rdest_put_young_adult(array('url' => $url, 'title' => $title, 'ya_id' => $id), $reviewer);
            return array('kind' => 'ya', 'id' => $id, 'name' => $name, 'what' => 'link', 'ya_done' => $done,
                'message' => 'Put the link on ' . kop_rinbox_record_words($kind, $name) . '.');
        }
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($p['text'] ?? ''))) ?: $title;
        if ($text === '') throw new RuntimeException('Write the line to put on the program.');
        $cites = $url !== '' ? array(array('label' => '', 'number' => '', 'page' => 0, 'url' => $url,
            'cite' => mb_substr($title !== '' && $title !== $text ? $title : (function_exists('kop_url_label') ? kop_url_label($url) : $url), 0, 200))) : array();
        $fact = array('key' => 'rinbox-' . substr(md5((string) ($p['key'] ?? ($url . '|' . $text))), 0, 16),
            'group' => $what === 'news' ? 'history' : 'details', 'label' => mb_substr($text, 0, 2000), 'cites' => $cites,
            'by' => $reviewer, 'at' => current_time('mysql', true));
        $done = kop_ya_add_fact($pdo, $id, $fact);
        return array('kind' => 'ya', 'id' => $id, 'name' => $name, 'what' => 'fact', 'ya_fact' => $done,
            'message' => 'Put it on ' . kop_rinbox_record_words($kind, $name) . '.');
    }

    // A website of a consultant, transporter, marketer or trade organization: its websites list.
    if ($what === 'website' && $url !== '' && in_array($kind, kop_rinbox_record_website_kinds(), true)) {
        $done = kop_rinbox_record_websites($kind, $id, $url, true);
        if (!$done) throw new RuntimeException('That link is already on ' . kop_rinbox_record_words($kind, $name) . '.');
        return array('kind' => $kind, 'id' => $id, 'name' => $name, 'what' => 'website', 'url' => $url,
            'message' => 'Put the link on ' . kop_rinbox_record_words($kind, $name) . ' (its websites).');
    }

    // Companies (notes) and every other kind: one line in the record's notes.
    $line = kop_rinbox_record_note_line($p);
    $done = kop_wbc_add_notes($kind, $id, array($line));
    if (empty($done['lines'])) throw new RuntimeException('That is already in the notes of ' . kop_rinbox_record_words($kind, $name) . '.');
    if ($kind === 'company') kop_rinbox_record_changed('company', $id);
    return array('kind' => $kind, 'id' => $id, 'name' => $name, 'what' => 'note', 'lines' => array_values((array) $done['lines']),
        'message' => 'Added a line to the notes of ' . kop_rinbox_record_words($kind, $name) . '.');
}

/** Take back what kop_rinbox_file_on_record() did. */
function kop_rinbox_unfile_from_record(array $done) {
    $kind = (string) ($done['kind'] ?? '');
    $id = (int) ($done['id'] ?? 0);
    $what = (string) ($done['what'] ?? '');
    if ($kind === 'company' && ($what === 'link' || $what === 'website')) {
        kop_gdl_company_remove($id, array(array(array(), array('url' => (string) $done['url']))));
        kop_rinbox_record_changed('company', $id);
        return;
    }
    if ($kind === 'company' && $what === 'news') {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'kop_operator_links', array('operator_id' => $id, 'link_kind' => 'news', 'link_id' => (int) $done['news_id']));
        kop_rinbox_record_changed('company', $id);
        return;
    }
    if ($what === 'website' && in_array($kind, kop_rinbox_record_website_kinds(), true)) {
        kop_rinbox_record_websites($kind, $id, (string) $done['url'], false);
        return;
    }
    if ($kind === 'ya' && !empty($done['ya_done'])) {
        kop_rdest_take_back_young_adult((array) $done['ya_done']);
        return;
    }
    if ($kind === 'ya' && !empty($done['ya_fact'])) {
        kop_ya_remove_fact(kop_ya_pdo(), (array) $done['ya_fact']);
        return;
    }
    if ($what === 'note' && !empty($done['lines'])) {
        kop_wbc_remove_notes($kind, $id, (array) $done['lines']);
        if ($kind === 'company') kop_rinbox_record_changed('company', $id);
    }
}

/** The kinds besides companies and young adult programs that keep a websites list. */
function kop_rinbox_record_website_kinds() {
    return array('consultant', 'transporter', 'marketer', 'tradeorg');
}

/**
 * Add $url to (or take it off) the websites list of a consultant, transporter,
 * marketer or trade organization: the list beside the record's notes
 * (kop_wbc_note_paths()). Consultants and transporters keep {url, displayText},
 * marketers and trade organizations plain addresses. True when it changed.
 */
function kop_rinbox_record_websites($kind, $id, $url, $add) {
    $pdo = kop_wbc_pdo();
    list($table, $id, , $payload) = kop_wbc_load_record($pdo, $kind, $id);
    $key = kop_gdl_url_key($url);
    $changed = false;
    foreach (kop_wbc_note_paths($kind, $payload) as $path) {
        array_pop($path);
        $ref = &$payload;
        foreach ($path as $k) {
            if (!is_array($ref)) $ref = array();
            if (!array_key_exists($k, $ref)) $ref[$k] = array();
            $ref = &$ref[$k];
        }
        if (!is_array($ref)) $ref = array();
        $list = is_array($ref['websites'] ?? null) ? array_values($ref['websites']) : array();
        $url_of = function ($w) { return is_array($w) ? (string) ($w['url'] ?? '') : (string) $w; };
        $at = false;
        foreach ($list as $i => $w) {
            if ($url_of($w) !== '' && kop_gdl_url_key($url_of($w)) === $key) { $at = $i; break; }
        }
        $single = trim((string) ($ref['website'] ?? ''));
        if ($add) {
            if ($at !== false || ($single !== '' && kop_gdl_url_key($single) === $key)) { unset($ref); continue; }
            $list[] = in_array($kind, array('consultant', 'transporter'), true) ? array('url' => $url, 'displayText' => '') : $url;
            if ($single === '' && in_array($kind, array('consultant', 'transporter'), true)) $ref['website'] = $url;
        } else {
            if ($at === false) { unset($ref); continue; }
            array_splice($list, $at, 1);
            if ($single !== '' && kop_gdl_url_key($single) === $key) $ref['website'] = $list ? $url_of($list[0]) : '';
        }
        $ref['websites'] = $list;
        $changed = true;
        unset($ref);
    }
    if ($changed) {
        $pdo->prepare("UPDATE `{$table}` SET json_data = ?, updated_at = NOW() WHERE id = ?")->execute(array(wp_json_encode($payload), $id));
    }
    return $changed;
}

/** A company page's caches follow a change (inc/record-events.php). */
function kop_rinbox_record_changed($kind, $id) {
    if ($kind === 'company' && function_exists('kop_record_changed')) {
        try {
            kop_record_changed('operator', array((int) $id));
        } catch (Throwable $e) {
            // The page catches up on its next rebuild.
        }
    }
}
