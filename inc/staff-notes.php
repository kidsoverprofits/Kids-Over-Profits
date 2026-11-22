<?php
/**
 * Staff notes: notes on a facility record for the owner and staff only (plan 4.4 item 25, Claude note #31, owner
 * 2026-11-19: "save the notes for my own use"). A sender's words that should not be published, a tip still to
 * check, who said what in person.
 *
 * They live in their own table, {prefix}kop_staff_notes, never in the record's document: the document reaches the
 * public through the data form, get-master-data, open data and the app, and a key in it would leak. Nothing public
 * reads this table. Merging two records moves the rows (kop_fmerge_ref_tables(), inc/facility-merge.php).
 *
 * Written from the data suggestion card ("Move to: Staff notes (private)", api/manage-submissions.php
 * 'edit_suggestion', undone by its "Undo last edit") and from KOP Tools > Staff Notes (this file), where they are
 * read, added and taken off, with Undo.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The move target the data card offers (kop_suggestion_move_targets()). */
const KOP_SNOTES_TARGET = 'staffNotes';
const KOP_SNOTES_PER_PAGE = 30;

function kop_snotes_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_staff_notes';
}

function kop_snotes_install() {
    global $wpdb;
    static $done = false;
    if ($done) return;
    $done = true;
    if ((int) get_option('kop_snotes_db', 0) >= 1) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . kop_snotes_table() . " (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        facility_id bigint(20) unsigned NOT NULL,
        note text NOT NULL,
        source varchar(255) NOT NULL DEFAULT '',
        created_by varchar(100) NOT NULL DEFAULT '',
        created_at datetime NOT NULL,
        deleted_at datetime NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY facility_id (facility_id)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('kop_snotes_db', 1, false);
}

/** Who is doing it, for the row. */
function kop_snotes_who() {
    if (!function_exists('wp_get_current_user')) return '';
    $u = wp_get_current_user();
    return (string) ($u->display_name ?: $u->user_login);
}

/** Adds a note to a record; returns its id. $source: where it came from ("Data suggestion #75"). */
function kop_snotes_add($facility_id, $note, $source = '', $who = null) {
    global $wpdb;
    $facility_id = (int) $facility_id;
    $note = trim((string) $note);
    if ($facility_id <= 0) throw new RuntimeException('Pick the record the note is about.');
    if ($note === '') throw new RuntimeException('The note is empty.');
    if (kop_snotes_record_name($facility_id) === '') throw new RuntimeException('There is no record #' . $facility_id . '.');
    kop_snotes_install();
    $ok = $wpdb->insert(kop_snotes_table(), array(
        'facility_id' => $facility_id,
        'note'        => $note,
        'source'      => mb_substr(trim((string) $source), 0, 255),
        'created_by'  => mb_substr($who === null ? kop_snotes_who() : (string) $who, 0, 100),
        'created_at'  => current_time('mysql', true),
    ));
    if (!$ok) throw new RuntimeException('The note could not be saved.');
    return (int) $wpdb->insert_id;
}

/** Takes a note off (kept, so Undo can bring it back). */
function kop_snotes_delete($id) {
    global $wpdb;
    kop_snotes_install();
    return (bool) $wpdb->update(kop_snotes_table(), array('deleted_at' => current_time('mysql', true)), array('id' => (int) $id, 'deleted_at' => null));
}

/** Undo of a delete. */
function kop_snotes_restore($id) {
    global $wpdb;
    kop_snotes_install();
    return (bool) $wpdb->query($wpdb->prepare('UPDATE ' . kop_snotes_table() . ' SET deleted_at = NULL WHERE id = %d', (int) $id));
}

/** Undo of an add (the card's "Undo last edit"): the row goes for good. */
function kop_snotes_purge($id) {
    global $wpdb;
    kop_snotes_install();
    return (bool) $wpdb->delete(kop_snotes_table(), array('id' => (int) $id));
}

/** A record's notes, newest first. */
function kop_snotes_for($facility_id) {
    global $wpdb;
    kop_snotes_install();
    return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_snotes_table() . ' WHERE facility_id = %d AND deleted_at IS NULL ORDER BY id DESC', (int) $facility_id), ARRAY_A) ?: array();
}

/** Every note, newest first, optionally of one record: {rows, total}. */
function kop_snotes_list($facility_id = 0, $paged = 1) {
    global $wpdb;
    kop_snotes_install();
    $where = 'deleted_at IS NULL' . ($facility_id ? $wpdb->prepare(' AND facility_id = %d', (int) $facility_id) : '');
    $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_snotes_table() . ' WHERE ' . $where);
    $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_snotes_table() . ' WHERE ' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d',
        KOP_SNOTES_PER_PAGE, (max(1, (int) $paged) - 1) * KOP_SNOTES_PER_PAGE), ARRAY_A) ?: array();
    return array('rows' => $rows, 'total' => $total);
}

/** A record's name, or '' when there is no such record. */
function kop_snotes_record_name($facility_id) {
    global $wpdb;
    static $names = array();
    $facility_id = (int) $facility_id;
    if (!isset($names[$facility_id])) {
        $names[$facility_id] = (string) $wpdb->get_var($wpdb->prepare('SELECT name FROM facilities_v2 WHERE id = %d', $facility_id));
    }
    return $names[$facility_id];
}

/* ---- KOP Tools > Staff Notes ---------------------------------------------------------------------------------- */

add_filter('kop_admin_kit_pages', function ($pages) {
    $pages[] = 'kop-staff-notes';
    return $pages;
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Staff Notes', 'Staff Notes', 'manage_options', 'kop-staff-notes', 'kop_snotes_page');
}, 30);

function kop_snotes_url(array $args = array()) {
    return add_query_arg(array_merge(array('page' => 'kop-staff-notes'), $args), admin_url('admin.php'));
}

/** A small form posting one action to admin-post.php. */
function kop_snotes_form($act, array $fields, $button, $variant = 'secondary', $body = '') {
    $out = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="kop-snotes-form">'
        . '<input type="hidden" name="action" value="kop_staff_notes">'
        . '<input type="hidden" name="act" value="' . esc_attr($act) . '">'
        . wp_nonce_field('kop_staff_notes', '_wpnonce', true, false);
    foreach ($fields as $k => $v) $out .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
    return $out . $body . '<button type="submit" class="button kop-ak-btn kop-ak-btn--' . esc_attr($variant) . '">' . esc_html($button) . '</button></form>';
}

function kop_snotes_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $fid = max(0, (int) ($_GET['fid'] ?? 0));
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $notices = '';
    $done = sanitize_key($_GET['done'] ?? '');
    $did = (int) ($_GET['note'] ?? 0);
    if ($done === 'added') $notices .= '<div class="notice notice-success"><p>Note saved.</p></div>';
    if ($done === 'restored') $notices .= '<div class="notice notice-success"><p>Note put back.</p></div>';
    if ($done === 'deleted') {
        $notices .= '<div class="notice notice-success kop-snotes-undo"><p>Note taken off. </p>'
            . kop_snotes_form('restore', array('id' => $did, 'fid' => $fid), 'Undo', 'quiet') . '</div>';
    }
    if (!empty($_GET['err'])) $notices .= '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['err'])) . '</p></div>';

    echo '<div class="wrap kop-ak kop-snotes">';
    echo '<style>.kop-snotes-form{display:inline-block;margin:0}.kop-snotes-undo{display:flex;align-items:center;gap:8px}'
        . '.kop-snotes-undo p{margin:.5em 0}.kop-snotes-note{white-space:pre-wrap;margin:.25em 0 .5em}.kop-snotes-meta{color:#50575e;font-size:12px}'
        . '.kop-snotes-add textarea{width:100%;max-width:720px}.kop-snotes-add label{display:block;font-weight:600;margin:.75em 0 .25em}'
        . '.kop-snotes-row{border-bottom:1px solid #dcdcde;padding:10px 0}.kop-snotes-row h2{font-size:15px;margin:0}</style>';
    echo kop_ak_header('Staff Notes', array(
        'lede' => 'Notes on a record for you and staff only: never on its page, in the app, the data form or open data.',
        'steps' => array(
            'On a data suggestion card, <strong>Move</strong> a piece of text to <strong>Staff notes (private)</strong>: it is saved here under that record at once and leaves the suggestion. The card\'s <strong>Undo last edit</strong> takes it back.',
            'Add a note of your own below: pick the record, write the note, and say where it came from.',
            '<strong>Take off</strong> removes a note, with Undo.',
        ),
        'notices' => $notices,
    ));

    // Filter by record.
    echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="kop-snotes-filter"><input type="hidden" name="page" value="kop-staff-notes">'
        . '<label for="kop-snotes-fid">Show one record\'s notes</label> '
        . (function_exists('kop_facility_finder_field') ? kop_facility_finder_field('fid', $fid ?: '', ' id="kop-snotes-fid"') : '<input type="number" name="fid" id="kop-snotes-fid" value="' . esc_attr($fid ?: '') . '">')
        . ' <button type="submit" class="button">Show</button>'
        . ($fid ? ' <a class="button-link" href="' . esc_url(kop_snotes_url()) . '">Show every record</a>' : '') . '</form>';

    $add = '<div class="kop-snotes-add">'
        . '<label for="kop-snotes-add-fid">Record</label>'
        . (function_exists('kop_facility_finder_field') ? kop_facility_finder_field('fid', $fid ?: '', ' id="kop-snotes-add-fid" required') : '<input type="number" name="fid" id="kop-snotes-add-fid" required value="' . esc_attr($fid ?: '') . '">')
        . '<label for="kop-snotes-add-note">Note</label><textarea name="note" id="kop-snotes-add-note" rows="4" required></textarea>'
        . '<label for="kop-snotes-add-source">Where it came from (optional)</label><input type="text" name="source" id="kop-snotes-add-source" class="regular-text" placeholder="e.g. a call with a parent, 2026-11-20"> '
        . '<p></p></div>';
    echo kop_ak_fold('Add a note', kop_snotes_form('add', array(), 'Save note', 'primary', $add), $fid > 0 && !kop_snotes_for($fid));

    $list = kop_snotes_list($fid, $paged);
    if (!$list['rows']) {
        echo kop_ak_empty($fid ? 'No staff notes on ' . (kop_snotes_record_name($fid) ?: 'this record') . ' yet.' : 'No staff notes yet.');
    }
    foreach ($list['rows'] as $r) {
        $rid = (int) $r['facility_id'];
        $name = kop_snotes_record_name($rid) ?: 'Record #' . $rid;
        $page = function_exists('kop_facility_page_url_quick') ? kop_facility_page_url_quick($rid) : '';
        $meta = array_filter(array(
            $r['source'] !== '' ? esc_html($r['source']) : '',
            'added ' . esc_html(mysql2date('M j, Y', get_date_from_gmt($r['created_at']))) . ($r['created_by'] !== '' ? ' by ' . esc_html($r['created_by']) : ''),
            '<a href="' . esc_url(kop_snotes_url(array('fid' => $rid))) . '">this record\'s notes</a>',
        ));
        echo '<div class="kop-snotes-row"><h2>' . ($page !== '' ? '<a href="' . esc_url($page) . '" target="_blank" rel="noopener">' . esc_html($name) . '</a>' : esc_html($name))
            . ' <span class="kop-snotes-meta">#' . $rid . '</span></h2>'
            . '<p class="kop-snotes-note">' . esc_html($r['note']) . '</p>'
            . '<p class="kop-snotes-meta">' . implode(' &middot; ', $meta) . '</p>'
            . kop_snotes_form('delete', array('id' => (int) $r['id'], 'fid' => $fid), 'Take off', 'quiet') . '</div>';
    }
    $pages = max(1, (int) ceil($list['total'] / KOP_SNOTES_PER_PAGE));
    if (function_exists('kop_ak_pager')) {
        echo kop_ak_pager(min($paged, $pages), $pages, $list['total'], 'notes', function ($n) use ($fid) { return kop_snotes_url(array('fid' => $fid ?: null, 'paged' => $n > 1 ? $n : null)); });
    }
    echo '</div>';
}

add_action('admin_post_kop_staff_notes', function () {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    check_admin_referer('kop_staff_notes');
    $act = sanitize_key($_POST['act'] ?? '');
    $fid = (int) ($_POST['fid'] ?? 0);
    $back = array('fid' => $fid ?: null);
    try {
        if ($act === 'add') {
            $id = kop_snotes_add($fid, wp_unslash($_POST['note'] ?? ''), sanitize_text_field(wp_unslash($_POST['source'] ?? '')));
            $back += array('done' => 'added', 'note' => $id);
        } elseif ($act === 'delete') {
            kop_snotes_delete((int) ($_POST['id'] ?? 0));
            $back += array('done' => 'deleted', 'note' => (int) ($_POST['id'] ?? 0));
        } elseif ($act === 'restore') {
            kop_snotes_restore((int) ($_POST['id'] ?? 0));
            $back += array('done' => 'restored');
        }
    } catch (Throwable $e) {
        $back += array('err' => rawurlencode($e->getMessage()));
    }
    wp_safe_redirect(kop_snotes_url($back));
    exit;
});
