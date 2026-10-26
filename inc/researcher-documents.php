<?php
/**
 * Researcher documents and chunked uploads, on the researcher upload links
 * (inc/researcher-uploads.php, loaded first).
 *
 * Chunked uploads: the upload page (js/researcher-upload.js) sends every file,
 * data or document, in pieces of kop_rup_chunk_bytes() (under the host's
 * per-request limit), so a file has no size cap. REST kop/v1/researcher-upload/
 * start -> chunk (as many as it takes, each a multipart file part at its
 * offset; a wrong offset answers 409 with what the server holds, so the page
 * picks up from there) -> finish. A finished data file goes through
 * kop_rup_store() like a form upload; a document through kop_rdoc_store().
 * Pieces wait in kop-private/researcher/<link id>/incoming/ and an unfinished
 * file is swept after two days (kop_rup_incoming_sweep, daily). The only
 * guard on size is the disk: a file is refused when it would leave less than
 * KOP_RUP_KEEP_FREE free.
 *
 * Documents (PDFs, Word files, spreadsheets, images, audio, video) carry what
 * they are about and where they came from, and wait in
 * {prefix}kop_researcher_docs and kop-private/researcher/<link id>/docs/ (no
 * web access). A document the media library already holds (same md5) is
 * marked "Already in the library" and not kept. KOP Tools > Researcher
 * Documents adds a waiting one to the media library: title, the facility
 * whose folder it goes in (or the "Researcher uploads" folder), its document
 * type, _kop_source_url. Undo takes it back out exactly (the file returns to
 * the waiting folder). Set aside / Back to waiting; a set-aside file can be
 * deleted for good.
 *
 * Tested by scripts/test-researcher-uploads.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_RDOC_DB_VERSION = '1';
const KOP_RDOC_HOURLY_FILES = 1000;
const KOP_RDOC_FOLDER = 'Researcher uploads';
const KOP_RUP_KEEP_FREE = 1073741824; // leave 1 GB of disk free

/* ---- Table -------------------------------------------------------------------- */

function kop_rdoc_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_researcher_docs';
}

function kop_rdoc_ensure_table() {
    if (get_option('kop_researcher_docs_db') === KOP_RDOC_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $t = kop_rdoc_table();
    dbDelta("CREATE TABLE $t (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        link_id BIGINT UNSIGNED NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        rel_path VARCHAR(500) NOT NULL DEFAULT '',
        stored_name VARCHAR(255) NOT NULL DEFAULT '',
        bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sha256 CHAR(64) NOT NULL DEFAULT '',
        md5 CHAR(32) NOT NULL DEFAULT '',
        file_type VARCHAR(10) NOT NULL DEFAULT '',
        source_url VARCHAR(1000) NOT NULL DEFAULT '',
        about VARCHAR(500) NOT NULL DEFAULT '',
        note TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        att_made TINYINT(1) NOT NULL DEFAULT 0,
        folder_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        rel_added TINYINT(1) NOT NULL DEFAULT 0,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        doc_type VARCHAR(60) NOT NULL DEFAULT '',
        title VARCHAR(255) NOT NULL DEFAULT '',
        uploaded_at DATETIME NOT NULL,
        status_by VARCHAR(60) NOT NULL DEFAULT '',
        status_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY link_id (link_id),
        KEY status (status),
        KEY sha256 (sha256),
        KEY facility_id (facility_id)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('kop_researcher_docs_db', KOP_RDOC_DB_VERSION);
}

// Merging two facilities moves the documents filed under the old one.
add_filter('kop_facility_merge_ref_tables', function ($tables) {
    global $wpdb;
    $tables[] = array('t' => $wpdb->prefix . 'kop_researcher_docs', 'c' => 'facility_id', 'k' => 'id');
    return $tables;
});

/* ---- Limits --------------------------------------------------------------------- */

/** File types taken as documents (the rest of the page takes data files). */
function kop_rdoc_types() {
    return array(
        'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt',
        'xls', 'xlsx', 'ods', 'ppt', 'pptx', 'odp',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'heic',
        'mp3', 'm4a', 'wav', 'ogg', 'mp4', 'm4v', 'mov', 'webm',
    );
}

/** Bytes per piece: under what PHP takes in one request, 8 MB at most, 512 KB at least. */
function kop_rup_chunk_bytes() {
    $php = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
    $max = 8 * 1048576;
    if ($php <= 0) return $max;
    return max(524288, min($max, $php - 262144));
}

/** Refuse a file that would leave the disk with less than KOP_RUP_KEEP_FREE. The only size guard. */
function kop_rup_room($bytes, $dir = '') {
    $dir = $dir !== '' ? $dir : kop_rup_private_dir();
    $free = function_exists('disk_free_space') ? @disk_free_space($dir) : false;
    if ($free === false || $free === null) return;
    if ($free - (float) $bytes < KOP_RUP_KEEP_FREE) {
        throw new RuntimeException('The server is short of room for a file this size right now. Send it later, or tell whoever sent you the link.');
    }
}

/* ---- Chunked uploads -------------------------------------------------------------- */

function kop_rup_incoming_dir($link_id) {
    $dir = kop_rup_private_dir((int) $link_id) . '/incoming';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Start a file. $a: kind (data|doc), name, size, note, about, source_url, rel_path. Returns [upload id, piece size]. */
function kop_rup_chunk_start(array $link, array $a) {
    if (kop_rup_link_state($link) !== 'on') throw new RuntimeException('This link has stopped working.');
    $kind = ($a['kind'] ?? '') === 'doc' ? 'doc' : 'data';
    $name = mb_substr(wp_basename(trim((string) ($a['name'] ?? ''))), 0, 255);
    if ($name === '') throw new RuntimeException('The file has no name.');
    $size = (int) ($a['size'] ?? 0);
    if ($size <= 0) throw new RuntimeException('This file is empty.');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($kind === 'data' && !in_array($ext, kop_rup_allowed_types(), true)) {
        throw new RuntimeException('Only .json, .csv, .tsv, .xlsx and .zip files go here. Send documents under "Send documents".');
    }
    if ($kind === 'doc' && !in_array($ext, kop_rdoc_types(), true)) {
        throw new RuntimeException('This kind of file is not taken as a document (.' . ($ext !== '' ? $ext : '?') . '). PDFs, Word files, spreadsheets, pictures, audio and video are.');
    }
    if ($kind === 'data') {
        list(, $hour) = kop_rup_usage((int) $link['id']);
        if ($hour >= KOP_RUP_HOURLY_FILES) throw new RuntimeException('This link has sent ' . KOP_RUP_HOURLY_FILES . ' data files in the last hour. Try again later.');
    } elseif (kop_rdoc_hour_count((int) $link['id']) >= KOP_RDOC_HOURLY_FILES) {
        throw new RuntimeException('This link has sent ' . KOP_RDOC_HOURLY_FILES . ' documents in the last hour. Try again later.');
    }
    $dir = kop_rup_incoming_dir((int) $link['id']);
    kop_rup_room($size, $dir);
    $uid = bin2hex(random_bytes(16));
    $meta = array(
        'kind' => $kind, 'name' => $name, 'size' => $size, 'started' => time(),
        'note' => mb_substr(trim((string) ($a['note'] ?? '')), 0, 2000),
        'about' => mb_substr(trim((string) ($a['about'] ?? '')), 0, 500),
        'source_url' => kop_rdoc_clean_url($a['source_url'] ?? ''),
        'rel_path' => mb_substr(trim(str_replace('\\', '/', (string) ($a['rel_path'] ?? '')), '/'), 0, 500),
    );
    if (@file_put_contents("$dir/$uid.json", wp_json_encode($meta)) === false || @file_put_contents("$dir/$uid.part", '') === false) {
        throw new RuntimeException('The server could not start this file. Try again in a minute.');
    }
    return array($uid, kop_rup_chunk_bytes());
}

/** [meta, part path] for an upload of this link, or throws. */
function kop_rup_chunk_meta(array $link, $uid) {
    $uid = strtolower((string) $uid);
    if (!preg_match('/^[a-f0-9]{32}$/', $uid)) throw new RuntimeException('Unknown upload.');
    $dir = kop_rup_incoming_dir((int) $link['id']);
    $meta = is_file("$dir/$uid.json") ? json_decode((string) file_get_contents("$dir/$uid.json"), true) : null;
    if (!is_array($meta) || !is_file("$dir/$uid.part")) throw new RuntimeException('This upload is gone (it was more than two days old, or finished). Send the file again.');
    return array($meta, "$dir/$uid.part");
}

/**
 * Add one piece at $offset from the file at $tmp. Returns the bytes now held;
 * when $offset is not where the file stands, nothing is added and the caller
 * answers with what is held.
 */
function kop_rup_chunk_append(array $link, $uid, $offset, $tmp) {
    if (kop_rup_link_state($link) !== 'on') throw new RuntimeException('This link has stopped working.');
    list($meta, $part) = kop_rup_chunk_meta($link, $uid);
    clearstatcache(true, $part);
    $have = (int) filesize($part);
    if ((int) $offset !== $have) return $have;
    $len = (int) @filesize($tmp);
    if ($len <= 0) throw new RuntimeException('An empty piece arrived.');
    if ($have + $len > (int) $meta['size']) throw new RuntimeException('More arrived than the file holds. Send it again.');
    $in = @fopen($tmp, 'rb');
    $out = @fopen($part, 'ab');
    if (!$in || !$out) throw new RuntimeException('The server could not save this piece. Trying again.');
    $n = stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);
    if ($n !== $len) {
        // Put the file back to where it stood, so the piece can be sent again.
        $fh = @fopen($part, 'r+');
        if ($fh) { ftruncate($fh, $have); fclose($fh); }
        throw new RuntimeException('Only part of this piece was saved. Trying again.');
    }
    return $have + $len;
}

/** The last piece is in: keep the file. Returns ['kind', 'row', 'message']. */
function kop_rup_chunk_finish(array $link, $uid) {
    list($meta, $part) = kop_rup_chunk_meta($link, $uid);
    clearstatcache(true, $part);
    $have = (int) filesize($part);
    if ($have !== (int) $meta['size']) {
        throw new RuntimeException('Only ' . $have . ' of ' . (int) $meta['size'] . ' bytes arrived.');
    }
    $dir = dirname($part);
    try {
        if ($meta['kind'] === 'doc') {
            $row = kop_rdoc_store($link, $part, $meta);
            $message = $row['status'] === 'held' ? 'already in the KOP library, so it was not kept twice' : 'received, waiting for an editor';
        } else {
            $row = kop_rup_store($link, $part, $meta['name'], $meta['note'], 'rename');
            $message = 'received (' . $row['summary'] . ')';
        }
    } finally {
        @unlink($part);
        @unlink("$dir/$uid.json");
    }
    return array('kind' => $meta['kind'], 'row' => $row, 'message' => $message);
}

function kop_rup_chunk_cancel(array $link, $uid) {
    try {
        list(, $part) = kop_rup_chunk_meta($link, $uid);
    } catch (RuntimeException $e) {
        return;
    }
    @unlink($part);
    @unlink(substr($part, 0, -5) . '.json');
}

/** Delete pieces of files nobody finished. Returns how many files were dropped. */
function kop_rup_incoming_sweep($older_than = 172800) {
    $base = kop_rup_private_dir();
    $n = 0;
    foreach ((array) glob($base . '/*/incoming/*.json') as $meta) {
        $part = substr($meta, 0, -5) . '.part';
        $last = max((int) @filemtime($meta), is_file($part) ? (int) @filemtime($part) : 0);
        if ($last && $last < time() - $older_than) {
            @unlink($part);
            @unlink($meta);
            $n++;
        }
    }
    foreach ((array) glob($base . '/*/incoming/*.part') as $part) {
        if (!is_file(substr($part, 0, -5) . '.json')) @unlink($part);
    }
    return $n;
}

add_action('init', function () {
    if (function_exists('wp_next_scheduled') && !wp_next_scheduled('kop_rup_incoming_sweep')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'kop_rup_incoming_sweep');
    }
});
add_action('kop_rup_incoming_sweep', function () {
    kop_rup_incoming_sweep();
});

/* ---- Documents -------------------------------------------------------------------- */

function kop_rdoc_clean_url($url) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . ltrim($url, '/');
    $url = function_exists('esc_url_raw') ? esc_url_raw($url, array('http', 'https')) : $url;
    return mb_substr((string) $url, 0, 1000);
}

function kop_rdoc_statuses() {
    return array('new' => 'Waiting', 'added' => 'Added to the library', 'aside' => 'Set aside', 'held' => 'Already in the library', 'gone' => 'Deleted');
}

function kop_rdoc_hour_count($link_id) {
    global $wpdb;
    kop_rdoc_ensure_table();
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . kop_rdoc_table() . ' WHERE link_id = %d AND uploaded_at > %s',
        (int) $link_id, gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)));
}

function kop_rdoc_dir($link_id) {
    $dir = kop_rup_private_dir((int) $link_id) . '/docs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function kop_rdoc_path(array $row) {
    return $row['stored_name'] !== '' ? kop_rdoc_dir((int) $row['link_id']) . '/' . basename((string) $row['stored_name']) : '';
}

/** The media library attachment holding a file with this md5 (0 when none). */
function kop_rdoc_library_match($md5) {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'attachment'
         WHERE m.meta_key IN ('_kop_import_md5', 'mdd_hash') AND m.meta_value = %s ORDER BY m.post_id ASC LIMIT 1", $md5));
}

/** Does the file's content look like its extension says? Markup and scripts never pass. */
function kop_rdoc_sniff($path, $name) {
    $head = (string) @file_get_contents($path, false, null, 0, 1024);
    if (preg_match('/<\?php|<script\b|<html\b|<!doctype html/i', $head)) return false;
    if (function_exists('wp_check_filetype_and_ext')) {
        $ft = wp_check_filetype_and_ext($path, $name);
        if (empty($ft['ext']) || empty($ft['type'])) return false;
    }
    return true;
}

/** Keep one finished document. $meta: name, note, about, source_url, rel_path. Returns the row, or throws. */
function kop_rdoc_store(array $link, $path, array $meta) {
    if (kop_rup_link_state($link) !== 'on') throw new RuntimeException('This link has stopped working.');
    kop_rdoc_ensure_table();
    $name = mb_substr(wp_basename((string) $meta['name']), 0, 255);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, kop_rdoc_types(), true)) throw new RuntimeException('This kind of file is not taken as a document.');
    $size = (int) @filesize($path);
    if ($size <= 0) throw new RuntimeException('This file is empty.');
    if (!kop_rdoc_sniff($path, $name)) throw new RuntimeException('This file is not really a .' . $ext . ' file.');
    $sha = hash_file('sha256', $path);
    $md5 = md5_file($path);
    global $wpdb;
    $t = kop_rdoc_table();
    $dup = $wpdb->get_row($wpdb->prepare("SELECT link_id, file_name FROM $t WHERE sha256 = %s AND status <> 'gone' ORDER BY id LIMIT 1", $sha), ARRAY_A);
    if ($dup) {
        throw new RuntimeException((int) $dup['link_id'] === (int) $link['id'] ? 'You already sent this file (as ' . $dup['file_name'] . ').' : 'Someone already sent us this file.');
    }
    $now = current_time('mysql', true);
    $row = array(
        'link_id' => (int) $link['id'], 'file_name' => $name, 'rel_path' => (string) ($meta['rel_path'] ?? ''),
        'stored_name' => '', 'bytes' => $size, 'sha256' => $sha, 'md5' => $md5, 'file_type' => $ext,
        'source_url' => kop_rdoc_clean_url($meta['source_url'] ?? ''), 'about' => mb_substr((string) ($meta['about'] ?? ''), 0, 500),
        'note' => mb_substr((string) ($meta['note'] ?? ''), 0, 2000), 'status' => 'new', 'uploaded_at' => $now,
    );
    $held = kop_rdoc_library_match($md5);
    if ($held) {
        $row['status'] = 'held';
        $row['attachment_id'] = $held;
    } else {
        $stored = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . kop_rup_safe_name($name);
        if (!@rename($path, kop_rdoc_dir((int) $link['id']) . '/' . $stored)) throw new RuntimeException('The file could not be saved. Try again in a minute.');
        $row['stored_name'] = $stored;
    }
    $wpdb->insert($t, $row);
    $row['id'] = (int) $wpdb->insert_id;
    $wpdb->update(kop_rup_links_table(), array('last_used' => $now), array('id' => (int) $link['id']));
    return $row;
}

function kop_rdoc_row($id) {
    global $wpdb;
    kop_rdoc_ensure_table();
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_rdoc_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    return $row ?: null;
}

function kop_rdoc_for_link($link_id, $limit = 200) {
    global $wpdb;
    kop_rdoc_ensure_table();
    return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_rdoc_table() . ' WHERE link_id = %d ORDER BY id DESC LIMIT %d', (int) $link_id, (int) $limit), ARRAY_A);
}

function kop_rdoc_who() {
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    return $u && $u->exists() ? mb_substr($u->user_login, 0, 60) : '';
}

/** A title from a file name: "NATSAP_directory-1999.pdf" -> "NATSAP directory 1999". */
function kop_rdoc_default_title($file_name) {
    $t = preg_replace('/\.[^.]+$/', '', (string) $file_name);
    return trim(preg_replace('/\s+/', ' ', str_replace(array('_', '-'), ' ', $t)));
}

/** Waiting <-> set aside. Returns the old status (for Undo). */
function kop_rdoc_set_status($id, $status) {
    if (!in_array($status, array('new', 'aside'), true)) throw new RuntimeException('Unknown status.');
    $row = kop_rdoc_row($id);
    if (!$row) throw new RuntimeException('No such document.');
    if (!in_array($row['status'], array('new', 'aside'), true)) throw new RuntimeException('This document is ' . strtolower(kop_rdoc_statuses()[$row['status']] ?? $row['status']) . '.');
    global $wpdb;
    $wpdb->update(kop_rdoc_table(), array('status' => $status, 'status_by' => kop_rdoc_who(), 'status_at' => current_time('mysql', true)), array('id' => (int) $id));
    return (string) $row['status'];
}

/** Delete a set-aside document's file for good. */
function kop_rdoc_delete_file($id) {
    $row = kop_rdoc_row($id);
    if (!$row || $row['status'] !== 'aside') throw new RuntimeException('Only a set-aside document can be deleted.');
    $path = kop_rdoc_path($row);
    if ($path !== '' && is_file($path)) @unlink($path);
    global $wpdb;
    $wpdb->update(kop_rdoc_table(), array('status' => 'gone', 'stored_name' => '', 'status_by' => kop_rdoc_who(), 'status_at' => current_time('mysql', true)), array('id' => (int) $id));
}

/** [folder id, label] the document goes in: the facility's folder, else "Researcher uploads". */
function kop_rdoc_folder_for($facility_id) {
    if ($facility_id > 0) {
        if (!function_exists('kop_dp_target')) throw new RuntimeException('Facility folders cannot be found here.');
        list($folder, $label) = kop_dp_target('f:' . (int) $facility_id);
        return array((int) $folder, $label);
    }
    if (!function_exists('kop_dp_child_folder')) throw new RuntimeException('Folder helpers are not loaded.');
    return array((int) kop_dp_child_folder(KOP_RDOC_FOLDER, 0), KOP_RDOC_FOLDER);
}

/**
 * Add a waiting document to the media library. $o: title, facility_id,
 * doc_type. Returns the updated row.
 */
function kop_rdoc_add($id, array $o) {
    $row = kop_rdoc_row($id);
    if (!$row) throw new RuntimeException('No such document.');
    if ($row['status'] !== 'new') throw new RuntimeException('Only a waiting document can be added.');
    $path = kop_rdoc_path($row);
    if ($path === '' || !is_file($path)) throw new RuntimeException('The file is missing from the server.');
    if (function_exists('set_time_limit')) @set_time_limit(0);
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    global $wpdb;

    $fid = max(0, (int) ($o['facility_id'] ?? 0));
    list($folder_id) = kop_rdoc_folder_for($fid);
    $title = trim((string) ($o['title'] ?? ''));
    if ($title === '') $title = kop_rdoc_default_title($row['file_name']);
    $type = sanitize_key((string) ($o['doc_type'] ?? ''));
    if ($type !== '' && function_exists('kop_dt_types') && !isset(kop_dt_types()[$type])) $type = '';

    $att = kop_rdoc_library_match($row['md5']);
    $made = 0;
    if (!$att) {
        $up = wp_upload_dir();
        if (!empty($up['error'])) throw new RuntimeException('The media library folder is not writable: ' . $up['error']);
        $fname = wp_unique_filename($up['path'], sanitize_file_name($row['file_name']));
        $dest = rtrim($up['path'], '/\\') . '/' . $fname;
        if (!@rename($path, $dest)) {
            if (!@copy($path, $dest)) throw new RuntimeException('The file could not be moved into the media library.');
            @unlink($path);
        }
        $ft = wp_check_filetype($fname);
        $att = wp_insert_attachment(array(
            'post_mime_type' => $ft['type'] ?: 'application/octet-stream',
            'post_title' => $title, 'post_content' => '', 'post_status' => 'inherit',
        ), $dest);
        if (is_wp_error($att) || !$att) {
            @rename($dest, $path);
            throw new RuntimeException('The media library would not take this file.');
        }
        $made = 1;
        try {
            wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $dest));
        } catch (Throwable $e) {
            // A preview that will not render leaves the document itself in place.
        }
        update_post_meta($att, '_kop_import_md5', $row['md5']);
        update_post_meta($att, '_kop_researcher_doc', (int) $row['id']);
        if ($row['source_url'] !== '') update_post_meta($att, '_kop_source_url', $row['source_url']);
    }

    $rel = $wpdb->prefix . 'fbv_attachment_folder';
    $rel_added = 0;
    if ($folder_id > 0 && !(int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $rel WHERE attachment_id = %d AND folder_id = %d", $att, $folder_id))) {
        $wpdb->insert($rel, array('folder_id' => $folder_id, 'attachment_id' => (int) $att), array('%d', '%d'));
        $rel_added = 1;
    }
    if ($type !== '' && function_exists('kop_dt_change') && function_exists('kop_dt_types_for')) {
        $types = kop_dt_types_for((int) $att);
        if (!in_array($type, $types, true)) {
            $types[] = $type;
            kop_dt_change(array((int) $att => $types), 'researcher-upload', 'Researcher document: ' . $title);
        }
    }
    $wpdb->update(kop_rdoc_table(), array(
        'status' => 'added', 'attachment_id' => (int) $att, 'att_made' => $made, 'folder_id' => $folder_id, 'rel_added' => $rel_added,
        'facility_id' => $fid, 'doc_type' => $type, 'title' => mb_substr($title, 0, 255),
        'stored_name' => $made ? '' : $row['stored_name'],
        'status_by' => kop_rdoc_who(), 'status_at' => current_time('mysql', true),
    ), array('id' => (int) $row['id']));
    if (!$made) @unlink($path); // the library already had it; the waiting copy is not needed
    if (function_exists('kop_dp_after')) kop_dp_after();
    return kop_rdoc_row($row['id']);
}

/** Undo an add: the attachment it made is deleted and its file goes back to waiting; a folder entry it added is taken off. */
function kop_rdoc_undo_add($id) {
    $row = kop_rdoc_row($id);
    if (!$row || $row['status'] !== 'added') throw new RuntimeException('This document is not in the library from here.');
    global $wpdb;
    $att = (int) $row['attachment_id'];
    $stored = $row['stored_name'];
    if ((int) $row['rel_added'] && $att) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'fbv_attachment_folder WHERE attachment_id = %d AND folder_id = %d', $att, (int) $row['folder_id']));
    }
    if ((int) $row['att_made'] && $att) {
        $file = function_exists('get_attached_file') ? (string) get_attached_file($att) : '';
        if ($file === '' || !is_file($file)) throw new RuntimeException('The library file is gone, so it cannot be moved back.');
        $stored = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . kop_rup_safe_name($row['file_name']);
        if (!@copy($file, kop_rdoc_dir((int) $row['link_id']) . '/' . $stored)) throw new RuntimeException('The file could not be moved back.');
        if (function_exists('kop_dt_change') && function_exists('kop_dt_types_for') && kop_dt_types_for($att)) {
            kop_dt_change(array($att => array()), 'researcher-upload', 'Undo researcher document: ' . $row['title']);
        }
        wp_delete_attachment($att, true);
    }
    $wpdb->update(kop_rdoc_table(), array(
        'status' => 'new', 'attachment_id' => 0, 'att_made' => 0, 'folder_id' => 0, 'rel_added' => 0, 'stored_name' => $stored,
        'status_by' => kop_rdoc_who(), 'status_at' => current_time('mysql', true),
    ), array('id' => (int) $row['id']));
    if (function_exists('kop_dp_after')) kop_dp_after();
}

/* ---- REST: kop/v1/researcher-upload/* ------------------------------------------------ */

/** The working link a request carries (kop_rup_key + kop_rup_check), or a WP_Error. */
function kop_rup_rest_link(WP_REST_Request $req) {
    $token = strtolower(trim((string) $req->get_param('kop_rup_key')));
    $link = $token !== '' ? kop_rup_by_token($token) : null;
    if (!$link || !hash_equals(kop_rup_csrf($token), (string) $req->get_param('kop_rup_check'))) {
        return new WP_Error('kop_rup_link', 'This page is out of date. Open your link again.', array('status' => 403));
    }
    if (kop_rup_link_state($link) !== 'on') {
        return new WP_Error('kop_rup_link', 'This link has stopped working.', array('status' => 403));
    }
    return $link;
}

function kop_rup_rest_nocache() {
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    do_action('litespeed_control_set_nocache', 'kop researcher upload');
}

add_action('rest_api_init', function () {
    $routes = array(
        'start' => function (WP_REST_Request $req, array $link) {
            list($uid, $chunk) = kop_rup_chunk_start($link, array(
                'kind' => $req->get_param('kind'), 'name' => $req->get_param('name'), 'size' => $req->get_param('size'),
                'note' => $req->get_param('note'), 'about' => $req->get_param('about'),
                'source_url' => $req->get_param('source_url'), 'rel_path' => $req->get_param('rel_path'),
            ));
            return array('upload' => $uid, 'chunk' => $chunk);
        },
        'chunk' => function (WP_REST_Request $req, array $link) {
            $f = $req->get_file_params()['chunk'] ?? null;
            if (!$f || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                throw new RuntimeException('A piece did not arrive. Trying again.');
            }
            $offset = (int) $req->get_param('offset');
            $have = kop_rup_chunk_append($link, (string) $req->get_param('upload'), $offset, (string) $f['tmp_name']);
            if ($have !== $offset + (int) $f['size']) {
                return new WP_REST_Response(array('received' => $have, 'message' => 'Picking up where the file stands.'), 409);
            }
            return array('received' => $have);
        },
        'finish' => function (WP_REST_Request $req, array $link) {
            $r = kop_rup_chunk_finish($link, (string) $req->get_param('upload'));
            return array('kind' => $r['kind'], 'id' => (int) $r['row']['id'], 'message' => $r['message']);
        },
        'cancel' => function (WP_REST_Request $req, array $link) {
            kop_rup_chunk_cancel($link, (string) $req->get_param('upload'));
            return array('ok' => true);
        },
        // The page is done with a batch: one email for all of it.
        'sent' => function (WP_REST_Request $req, array $link) {
            global $wpdb;
            $ids = static function ($v) {
                return array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $v)))), 0, 2000);
            };
            $data = $ids($req->get_param('data'));
            $docs = $ids($req->get_param('docs'));
            $lines = array();
            if ($data) {
                $rows = $wpdb->get_results($wpdb->prepare('SELECT file_name, summary FROM ' . kop_rup_uploads_table() . ' WHERE link_id = %d AND id IN (' . implode(',', $data) . ')', (int) $link['id']), ARRAY_A);
                foreach ((array) $rows as $r) $lines[] = $r['file_name'] . ' (' . $r['summary'] . ')';
            }
            $doc_rows = array();
            if ($docs) {
                kop_rdoc_ensure_table();
                $doc_rows = (array) $wpdb->get_results($wpdb->prepare('SELECT file_name, about, status FROM ' . kop_rdoc_table() . ' WHERE link_id = %d AND id IN (' . implode(',', $docs) . ')', (int) $link['id']), ARRAY_A);
            }
            $waiting = array_filter($doc_rows, static function ($r) { return $r['status'] === 'new'; });
            foreach ($waiting as $r) $lines[] = $r['file_name'] . ($r['about'] !== '' ? ' (about ' . $r['about'] . ')' : '');
            if ($lines && function_exists('kop_notify_admins')) {
                $n = count($lines);
                $GLOBALS['kop_notify_sender'] = 'person';
                kop_notify_admins('researcher_upload', $n . ' file' . ($n === 1 ? '' : 's') . ' from ' . $link['name'],
                    admin_url('admin.php?page=' . ($waiting && !$data ? 'kop-researcher-docs' : 'kop-researcher-uploads')), array(
                        'Submitted by' => $link['name'],
                        'Files' => implode('; ', array_slice($lines, 0, 60)) . ($n > 60 ? '; and ' . ($n - 60) . ' more' : ''),
                    ));
                unset($GLOBALS['kop_notify_sender']);
            }
            return array('ok' => true);
        },
    );
    foreach ($routes as $route => $fn) {
        register_rest_route('kop/v1', '/researcher-upload/' . $route, array(
            'methods' => 'POST',
            'permission_callback' => '__return_true', // the link in the request is the permission, checked below
            'callback' => function (WP_REST_Request $req) use ($fn) {
                kop_rup_rest_nocache();
                $link = kop_rup_rest_link($req);
                if (is_wp_error($link)) return $link;
                try {
                    return $fn($req, $link);
                } catch (Throwable $e) {
                    return new WP_Error('kop_rup', $e->getMessage(), array('status' => 400));
                }
            },
        ));
    }
});

/* ---- The page's script ------------------------------------------------------------------ */

add_action('wp_enqueue_scripts', function () {
    if (!function_exists('kop_rup_is_page') || !kop_rup_is_page()) return;
    $state = kop_rup_page_state();
    if (empty($state['link']) || kop_rup_link_state($state['link']) !== 'on') return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    wp_enqueue_script('kop-researcher-upload', "$uri/js/researcher-upload.js", array(), filemtime("$dir/js/researcher-upload.js"), true);
    wp_localize_script('kop-researcher-upload', 'KOP_RUP', array(
        'rest' => esc_url_raw(rest_url('kop/v1/researcher-upload/')),
        'key' => $state['token'],
        'check' => kop_rup_csrf($state['token']),
        'docTypes' => kop_rdoc_types(),
        'dataTypes' => kop_rup_allowed_types(),
    ));
}, 21);

/* ---- KOP Tools > Researcher Documents ------------------------------------------------------ */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Researcher Documents', 'Researcher Documents', 'manage_options', 'kop-researcher-docs', 'kop_rdoc_admin_page');
}, 22);

/** Open or download one waiting document (admin only). */
add_action('admin_post_kop_rdoc_file', function () {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $id = (int) ($_GET['id'] ?? 0);
    check_admin_referer('kop_rdoc_file_' . $id);
    $row = kop_rdoc_row($id);
    $path = $row ? kop_rdoc_path($row) : '';
    if ($path === '' || !is_file($path)) wp_die('The file is not waiting here (it may be in the media library now).', 'Not found', array('response' => 404));
    $ft = wp_check_filetype($row['file_name']);
    $inline = !empty($_GET['view']) && preg_match('#^(application/pdf|image/(jpeg|png|gif|webp)|text/plain|audio/|video/)#', (string) $ft['type']);
    nocache_headers();
    header('Content-Type: ' . ($inline ? $ft['type'] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', kop_rup_safe_name($row['file_name'])) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; plugin-types application/pdf");
    while (ob_get_level()) ob_end_clean();
    readfile($path);
    exit;
});

function kop_rdoc_admin_url($args = array()) {
    return add_query_arg($args, admin_url('admin.php?page=kop-researcher-docs'));
}

function kop_rdoc_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    kop_rdoc_ensure_table();
    kop_rup_ensure_tables();
    global $wpdb;
    $notice = '';
    $errors = array();
    $undo = array();   // [do, ids, status]
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['kop_rdoc_do'])) {
        check_admin_referer('kop_rdoc_admin');
        $do = sanitize_key($_POST['kop_rdoc_do']);
        $rows = (array) wp_unslash($_POST['rows'] ?? array());
        if (!empty($_POST['one'])) {
            $ids = array((int) $_POST['one']);
        } else {
            $ids = array_map('intval', (array) ($_POST['pick'] ?? array()));
            if (!$ids && isset($_POST['ids'])) $ids = array_map('intval', explode(',', (string) $_POST['ids']));
        }
        $ids = array_values(array_filter($ids));
        if (!$ids) $errors[] = 'Tick at least one document.';
        $done = array();
        foreach ($ids as $id) {
            try {
                if ($do === 'add') {
                    $r = (array) ($rows[$id] ?? array());
                    kop_rdoc_add($id, array('title' => $r['title'] ?? '', 'facility_id' => (int) ($r['facility'] ?? 0), 'doc_type' => $r['type'] ?? ''));
                } elseif ($do === 'undoadd') {
                    kop_rdoc_undo_add($id);
                } elseif ($do === 'aside' || $do === 'new') {
                    kop_rdoc_set_status($id, $do);
                } elseif ($do === 'delete') {
                    kop_rdoc_delete_file($id);
                }
                $done[] = $id;
            } catch (Throwable $e) {
                $row = kop_rdoc_row($id);
                $errors[] = ($row ? $row['file_name'] : '#' . $id) . ': ' . $e->getMessage();
            }
        }
        $n = count($done);
        $what = $n . ' document' . ($n === 1 ? '' : 's');
        if ($n) {
            $labels = array('add' => "$what added to the media library.", 'undoadd' => "$what taken back out of the library and waiting again.",
                'aside' => "$what set aside.", 'new' => "$what waiting again.", 'delete' => "$what deleted for good.");
            $notice = $labels[$do] ?? '';
            $back = array('add' => 'undoadd', 'aside' => 'new', 'new' => 'aside');
            if (isset($back[$do])) $undo = array($back[$do], $done);
        }
    }

    $show = sanitize_key($_GET['show'] ?? 'new');
    $statuses = kop_rdoc_statuses();
    if (!isset($statuses[$show]) && $show !== 'all') $show = 'new';
    $where = $show === 'all' ? '' : $wpdb->prepare(' WHERE status = %s', $show);
    $docs = (array) $wpdb->get_results('SELECT * FROM ' . kop_rdoc_table() . $where . ' ORDER BY id DESC LIMIT 300', ARRAY_A);
    $counts = array();
    foreach ((array) $wpdb->get_results('SELECT status, COUNT(*) AS n, COALESCE(SUM(bytes), 0) AS b FROM ' . kop_rdoc_table() . ' GROUP BY status', ARRAY_A) as $r) {
        $counts[$r['status']] = array((int) $r['n'], (int) $r['b']);
    }
    $names = array();
    foreach ((array) $wpdb->get_results('SELECT id, name FROM ' . kop_rup_links_table(), ARRAY_A) as $l) $names[(int) $l['id']] = (string) $l['name'];
    $types = function_exists('kop_dt_types') ? kop_dt_types('offered') : array();
    $fac_names = array();
    $fids = array_filter(array_map('intval', array_column($docs, 'facility_id')));
    if ($fids) {
        foreach ((array) $wpdb->get_results('SELECT id, name FROM facilities_v2 WHERE id IN (' . implode(',', array_unique($fids)) . ')', ARRAY_A) as $f) $fac_names[(int) $f['id']] = (string) $f['name'];
    }
    $file_url = static function (array $d, $view) {
        return wp_nonce_url(admin_url('admin-post.php?action=kop_rdoc_file&id=' . (int) $d['id'] . ($view ? '&view=1' : '')), 'kop_rdoc_file_' . (int) $d['id']);
    };
    $editable = $show === 'new';
    ?>
    <div class="wrap kop-rdoc-admin">
        <h1>Researcher Documents</h1>
        <p>Documents researchers sent through their <a href="<?php echo esc_url(admin_url('admin.php?page=kop-researcher-uploads')); ?>">upload links</a>.
            Each waits here until you add it to the media library: give it a title, pick the facility whose folder it goes in
            (none = the "<?php echo esc_html(KOP_RDOC_FOLDER); ?>" folder, to move later with the pencil on its tile) and its document type.
            Files the library already holds are marked "Already in the library" and not kept twice.</p>

        <?php if ($notice) : ?>
            <div class="notice notice-success"><p><?php echo esc_html($notice); ?>
                <?php if ($undo) : ?>
                    <form method="post" style="display:inline"><?php wp_nonce_field('kop_rdoc_admin'); ?>
                        <input type="hidden" name="kop_rdoc_do" value="<?php echo esc_attr($undo[0]); ?>">
                        <input type="hidden" name="ids" value="<?php echo esc_attr(implode(',', $undo[1])); ?>">
                        <button type="submit" class="button button-small">Undo</button></form>
                <?php endif; ?></p></div>
        <?php endif; ?>
        <?php if ($errors) : ?><div class="notice notice-error"><?php foreach ($errors as $e) echo '<p>' . esc_html($e) . '</p>'; ?></div><?php endif; ?>

        <p>
            <?php foreach (array_merge($statuses, array('all' => 'All')) as $k => $label) :
                if ($k === 'gone' && empty($counts['gone'])) continue;
                $n = $k === 'all' ? array_sum(array_column($counts, 0)) : ($counts[$k][0] ?? 0);
                ?>
                <a href="<?php echo esc_url(kop_rdoc_admin_url(array('show' => $k))); ?>" class="button<?php echo $show === $k ? ' button-primary' : ''; ?>"><?php echo esc_html("$label ($n)"); ?></a>
            <?php endforeach; ?>
            <?php if (!empty($counts['new'][1])) echo '<span class="description">' . esc_html(size_format($counts['new'][1])) . ' waiting</span>'; ?>
        </p>

        <?php if (!$docs) : ?>
            <p>None here.</p>
        <?php else : ?>
            <form method="post">
                <?php wp_nonce_field('kop_rdoc_admin'); ?>
                <table class="widefat striped kop-rdoc-table">
                    <thead><tr>
                        <td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.kop-rdoc-pick').forEach(function(c){c.checked=this.checked}, this)" aria-label="Tick all"></td>
                        <th>Document</th><th>From the researcher</th><th><?php echo $editable ? 'Add as' : 'Where it went'; ?></th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($docs as $d) :
                        $id = (int) $d['id'];
                        $waiting = $d['stored_name'] !== '' && in_array($d['status'], array('new', 'aside'), true);
                        ?>
                        <tr>
                            <th scope="row" class="check-column"><input type="checkbox" class="kop-rdoc-pick" name="pick[]" value="<?php echo $id; ?>" aria-label="Tick <?php echo esc_attr($d['file_name']); ?>"></th>
                            <td>
                                <strong><?php if ($waiting) : ?><a href="<?php echo esc_url($file_url($d, true)); ?>" target="_blank" rel="noopener"><?php echo esc_html($d['file_name']); ?></a><?php else : echo esc_html($d['file_name']); endif; ?></strong><br>
                                <span class="description"><?php echo esc_html(strtoupper($d['file_type']) . ', ' . size_format((int) $d['bytes']) . ', ' . get_date_from_gmt($d['uploaded_at'], 'M j, Y g:i a')); ?></span>
                                <?php if ($d['rel_path'] !== '' && $d['rel_path'] !== $d['file_name']) : ?><br><span class="description">In their folder: <?php echo esc_html($d['rel_path']); ?></span><?php endif; ?>
                                <?php if ($waiting) : ?><br><a href="<?php echo esc_url($file_url($d, false)); ?>">Download</a><?php endif; ?>
                            </td>
                            <td>
                                <?php echo esc_html($names[(int) $d['link_id']] ?? ''); ?>
                                <?php if ($d['about'] !== '') : ?><br>About: <?php echo esc_html($d['about']); ?><?php endif; ?>
                                <?php if ($d['source_url'] !== '') : ?><br>Source: <a href="<?php echo esc_url($d['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(function_exists('kop_url_label') ? kop_url_label($d['source_url']) : wp_parse_url($d['source_url'], PHP_URL_HOST)); ?></a><?php endif; ?>
                                <?php if ((string) $d['note'] !== '') : ?><br><span class="description"><?php echo esc_html(mb_strimwidth((string) $d['note'], 0, 300, '...')); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($editable) : ?>
                                    <label>Title<br><input type="text" name="rows[<?php echo $id; ?>][title]" value="<?php echo esc_attr(kop_rdoc_default_title($d['file_name'])); ?>" style="width:100%;max-width:22rem"></label><br>
                                    <label>Facility folder<br><?php echo function_exists('kop_facility_finder_field') ? kop_facility_finder_field("rows[$id][facility]") : ''; ?></label><br>
                                    <label>Document type<br><select name="rows[<?php echo $id; ?>][type]"<?php if (function_exists('kop_choice_select_attr')) echo kop_choice_select_attr('doc-types'); ?>>
                                        <option value="">(none yet)</option>
                                        <?php foreach ($types as $slug => $t) : ?><option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($t['label'] ?? $slug); ?></option><?php endforeach; ?>
                                    </select></label>
                                <?php elseif ($d['status'] === 'added' || $d['status'] === 'held') : ?>
                                    <?php if ((int) $d['attachment_id']) : ?>
                                        <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $d['attachment_id'] . '&action=edit')); ?>"><?php echo esc_html($d['title'] !== '' ? $d['title'] : get_the_title((int) $d['attachment_id'])); ?></a>
                                    <?php endif; ?>
                                    <?php if ((int) $d['facility_id']) :
                                        $furl = function_exists('kop_facility_page_url') ? (string) kop_facility_page_url((int) $d['facility_id']) : ''; ?>
                                        <br>Filed under <?php echo $furl ? '<a href="' . esc_url($furl) . '">' . esc_html($fac_names[(int) $d['facility_id']] ?? 'facility #' . (int) $d['facility_id']) . '</a>' : esc_html($fac_names[(int) $d['facility_id']] ?? ''); ?>
                                    <?php elseif ($d['status'] === 'added') : ?>
                                        <br>In the "<?php echo esc_html(KOP_RDOC_FOLDER); ?>" folder
                                    <?php endif; ?>
                                    <?php if ($d['doc_type'] !== '') echo '<br>' . esc_html(function_exists('kop_dt_label') ? kop_dt_label($d['doc_type']) : $d['doc_type']); ?>
                                <?php else : ?>
                                    <?php echo esc_html($statuses[$d['status']] ?? $d['status']); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($d['status'] === 'new') : ?>
                                    <button type="submit" class="button button-primary button-small" name="kop_rdoc_do" value="add" onclick="this.form.one.value='<?php echo $id; ?>'">Add to library</button>
                                    <button type="submit" class="button button-small" name="kop_rdoc_do" value="aside" onclick="this.form.one.value='<?php echo $id; ?>'">Set aside</button>
                                <?php elseif ($d['status'] === 'aside') : ?>
                                    <button type="submit" class="button button-small" name="kop_rdoc_do" value="new" onclick="this.form.one.value='<?php echo $id; ?>'">Back to waiting</button>
                                    <button type="submit" class="button button-small button-link-delete" name="kop_rdoc_do" value="delete" onclick="if(!confirm('Delete this file for good?'))return false;this.form.one.value='<?php echo $id; ?>'">Delete file</button>
                                <?php elseif ($d['status'] === 'added') : ?>
                                    <button type="submit" class="button button-small" name="kop_rdoc_do" value="undoadd" onclick="this.form.one.value='<?php echo $id; ?>'">Take back out</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <input type="hidden" name="one" value="">
                <p class="kop-rdoc-bulk">
                    <?php if ($show === 'new') : ?>
                        <button type="submit" class="button button-primary" name="kop_rdoc_do" value="add" onclick="this.form.one.disabled=true">Add ticked to library</button>
                        <button type="submit" class="button" name="kop_rdoc_do" value="aside" onclick="this.form.one.disabled=true">Set ticked aside</button>
                    <?php elseif ($show === 'aside') : ?>
                        <button type="submit" class="button" name="kop_rdoc_do" value="new" onclick="this.form.one.disabled=true">Ticked back to waiting</button>
                    <?php elseif ($show === 'added') : ?>
                        <button type="submit" class="button" name="kop_rdoc_do" value="undoadd" onclick="this.form.one.disabled=true">Take ticked back out</button>
                    <?php endif; ?>
                </p>
            </form>
        <?php endif; ?>
    </div>
    <?php
}
