<?php
/**
 * Researcher upload links: trusted outside researchers send whole files of
 * records (the shapes on /researcher-kit/) through a personal link, with no
 * WordPress account.
 *
 * KOP Tools > Researcher Uploads makes a link for a name
 * (/researcher-upload/?key=<token>), good for a set number of days. Only a
 * hash of the token is stored, so a lost link is replaced ("New link"), never
 * shown again; "Turn off" stops one link without touching the others.
 *
 * The page takes many files at once (.json, .csv, .tsv, .xlsx, .zip), each as
 * a multipart file part (the host's firewall refuses markup in form fields,
 * never in files). Every file is checked on the way in: a JSON file must
 * parse, and its kit wrapper (kind, kit_version, the record list) is read so
 * the admin screen can say "facilities, 214 records"; a zip lists what it
 * holds. Files wait in uploads/kop-private/researcher/<link id>/ (no web
 * access) and one row each in {prefix}kop_researcher_uploads. Nothing is
 * loaded into the records from here: the admin downloads a file, loads it
 * (the kit loader in kop-pipeline, docs/plan/4.3), and marks it Loaded or Set
 * aside (both undone by "Back to new").
 *
 * Guardrails: the page is noindex, never cached, sends no referrer; a link
 * stops at its expiry date or when turned off; each link takes at most
 * KOP_RUP_HOURLY_FILES files an hour and KOP_RUP_LINK_BYTES in all; the form
 * carries a value derived from the token (kop_rup_csrf()).
 *
 * Tested by scripts/test-researcher-uploads.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_RUP_DB_VERSION = '1';
const KOP_RUP_PATH = 'researcher-upload';
const KOP_RUP_REWRITE_VERSION = '1';
const KOP_RUP_HOURLY_FILES = 200;
const KOP_RUP_LINK_BYTES = 1073741824; // 1 GB per link
const KOP_RUP_FILE_BYTES = 104857600;  // 100 MB per file, or less when PHP's own limit is lower

/* ---- Tables --------------------------------------------------------------- */

function kop_rup_links_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_researcher_links';
}

function kop_rup_uploads_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_researcher_uploads';
}

function kop_rup_ensure_tables() {
    if (get_option('kop_researcher_uploads_db') === KOP_RUP_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $c = $wpdb->get_charset_collate();
    $l = kop_rup_links_table();
    $u = kop_rup_uploads_table();
    dbDelta("CREATE TABLE $l (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        note VARCHAR(255) NOT NULL DEFAULT '',
        token_hash CHAR(64) NOT NULL,
        days INT NOT NULL DEFAULT 30,
        created_by VARCHAR(60) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        link_at DATETIME NOT NULL,
        expires_at DATETIME NULL,
        last_used DATETIME NULL,
        revoked_at DATETIME NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY token_hash (token_hash)
    ) $c;");
    dbDelta("CREATE TABLE $u (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        link_id BIGINT UNSIGNED NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        stored_name VARCHAR(255) NOT NULL,
        bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sha256 CHAR(64) NOT NULL DEFAULT '',
        file_type VARCHAR(10) NOT NULL DEFAULT '',
        kind VARCHAR(40) NOT NULL DEFAULT '',
        kit_version VARCHAR(10) NOT NULL DEFAULT '',
        records INT NOT NULL DEFAULT 0,
        summary VARCHAR(255) NOT NULL DEFAULT '',
        note TEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'new',
        status_by VARCHAR(60) NOT NULL DEFAULT '',
        status_at DATETIME NULL,
        uploaded_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        KEY link_id (link_id),
        KEY status (status),
        KEY sha256 (sha256)
    ) $c;");
    update_option('kop_researcher_uploads_db', KOP_RUP_DB_VERSION);
}

/* ---- Links ------------------------------------------------------------------ */

function kop_rup_hash($token) {
    return hash_hmac('sha256', strtolower(trim((string) $token)), wp_salt('auth'));
}

function kop_rup_new_token() {
    return bin2hex(random_bytes(24));
}

/** The form's hidden value: ties a post to a page opened with this link. */
function kop_rup_csrf($token) {
    return substr(hash_hmac('sha256', 'kop-rup|' . strtolower(trim((string) $token)), wp_salt('nonce')), 0, 32);
}

function kop_rup_page_url() {
    return home_url('/' . KOP_RUP_PATH . '/');
}

function kop_rup_link_url($token) {
    return add_query_arg('key', $token, kop_rup_page_url());
}

/** How long a new link lasts: [days => label]; 0 = until turned off. */
function kop_rup_durations() {
    return array(7 => '1 week', 30 => '30 days', 90 => '90 days', 365 => '1 year', 0 => 'Until I turn it off');
}

function kop_rup_expiry_for($days, $from = null) {
    $days = (int) $days;
    if ($days <= 0) return null;
    return gmdate('Y-m-d H:i:s', ($from ?? time()) + $days * DAY_IN_SECONDS);
}

/**
 * Make a link for a researcher. Returns [id, url]; the url is shown once.
 */
function kop_rup_create($name, $note = '', $days = 30) {
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    if ($name === '') throw new RuntimeException('Give the researcher a name.');
    $days = (int) $days;
    if (!array_key_exists($days, kop_rup_durations())) $days = 30;
    global $wpdb;
    kop_rup_ensure_tables();
    $token = kop_rup_new_token();
    $now = current_time('mysql', true);
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $wpdb->insert(kop_rup_links_table(), array(
        'name' => mb_substr($name, 0, 100), 'note' => mb_substr(trim((string) $note), 0, 255),
        'token_hash' => kop_rup_hash($token), 'days' => $days,
        'created_by' => $u && $u->exists() ? mb_substr($u->user_login, 0, 60) : '',
        'created_at' => $now, 'link_at' => $now, 'expires_at' => kop_rup_expiry_for($days),
    ));
    return array((int) $wpdb->insert_id, kop_rup_link_url($token));
}

/** Replace a link (the old one stops working), turn it back on and start its days again. Returns the new url. */
function kop_rup_new_link($id) {
    global $wpdb;
    kop_rup_ensure_tables();
    $row = kop_rup_link((int) $id);
    if (!$row) throw new RuntimeException('No such link.');
    $token = kop_rup_new_token();
    $wpdb->update(kop_rup_links_table(), array(
        'token_hash' => kop_rup_hash($token), 'link_at' => current_time('mysql', true),
        'expires_at' => kop_rup_expiry_for((int) $row['days']), 'revoked_at' => null,
    ), array('id' => (int) $id));
    return kop_rup_link_url($token);
}

function kop_rup_revoke($id) {
    global $wpdb;
    kop_rup_ensure_tables();
    $wpdb->update(kop_rup_links_table(), array('revoked_at' => current_time('mysql', true)), array('id' => (int) $id));
}

/** Give a link more time from today, keeping the same address. */
function kop_rup_extend($id, $days) {
    global $wpdb;
    kop_rup_ensure_tables();
    $days = (int) $days;
    if (!array_key_exists($days, kop_rup_durations())) throw new RuntimeException('Pick how long.');
    $wpdb->update(kop_rup_links_table(), array('days' => $days, 'expires_at' => kop_rup_expiry_for($days), 'revoked_at' => null), array('id' => (int) $id));
}

function kop_rup_link($id) {
    global $wpdb;
    kop_rup_ensure_tables();
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_rup_links_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    return $row ?: null;
}

/** 'on', 'off' (turned off) or 'expired'. */
function kop_rup_link_state(array $row) {
    if (!empty($row['revoked_at'])) return 'off';
    if (!empty($row['expires_at']) && strtotime($row['expires_at'] . ' UTC') <= time()) return 'expired';
    return 'on';
}

/** The link a token opens, whatever its state, or null. */
function kop_rup_by_token($token) {
    $token = strtolower(trim((string) $token));
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) return null;
    global $wpdb;
    kop_rup_ensure_tables();
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_rup_links_table() . ' WHERE token_hash = %s', kop_rup_hash($token)), ARRAY_A);
    return $row ?: null;
}

/* ---- Files ------------------------------------------------------------------- */

/** Where uploads wait: no web access (an .htaccess denies it all). */
function kop_rup_private_dir($link_id = 0) {
    $base = rtrim((string) wp_upload_dir()['basedir'], '/\\') . '/kop-private';
    if (!is_dir($base)) @mkdir($base, 0755, true);
    if (!is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_file($base . '/index.php')) @file_put_contents($base . '/index.php', "<?php\n// Silence.\n");
    $dir = $base . '/researcher' . ($link_id ? '/' . (int) $link_id : '');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function kop_rup_allowed_types() {
    return array('json', 'csv', 'tsv', 'xlsx', 'zip');
}

function kop_rup_file_max() {
    $php = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
    return $php > 0 ? min($php, KOP_RUP_FILE_BYTES) : KOP_RUP_FILE_BYTES;
}

/** The kit's record list for each kind: [kind => key holding the records]. */
function kop_rup_kit_lists() {
    return array(
        'facilities' => 'facilities', 'companies' => 'companies', 'people' => 'people', 'lawsuits' => 'lawsuits',
        'bills' => 'legislation', 'news' => 'articles', 'inspections' => 'facilities',
    );
}

/**
 * Read a JSON file the way the kit writes it. Returns ['kind', 'kit_version', 'records', 'summary']
 * or throws with a message the researcher can act on.
 */
function kop_rup_read_json($bytes) {
    $bytes = preg_replace('/^\xEF\xBB\xBF/', '', (string) $bytes);
    $data = json_decode($bytes, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('This is not valid JSON (' . json_last_error_msg() . '). Check it at jsonlint.com or open it in a code editor.');
    }
    if (is_array($data) && array_is_list($data)) {
        // A plain list: the source-links template.
        $looks_links = $data && is_array($data[0]) && isset($data[0]['url']);
        return array('kind' => $looks_links ? 'source-links' : '', 'kit_version' => '', 'records' => count($data),
            'summary' => count($data) . ($looks_links ? ' links' : ' entries'));
    }
    if (!is_array($data)) throw new RuntimeException('This JSON file holds a single value, not records.');
    $kind = isset($data['kind']) && is_scalar($data['kind']) ? strtolower(trim((string) $data['kind'])) : '';
    $ver = isset($data['kit_version']) && is_scalar($data['kit_version']) ? (string) $data['kit_version'] : '';
    $lists = kop_rup_kit_lists();
    $records = 0;
    $key = $lists[$kind] ?? '';
    if ($key !== '' && isset($data[$key]) && is_array($data[$key])) {
        $records = count($data[$key]);
        if ($kind === 'inspections') {
            $reports = 0;
            foreach ($data[$key] as $f) $reports += is_array($f['reports'] ?? null) ? count($f['reports']) : 0;
            return array('kind' => $kind, 'kit_version' => $ver, 'records' => $records, 'summary' => "$records facilities, $reports reports");
        }
    } else {
        // Not a kit file, or a kind we do not know: count the longest list in it.
        foreach ($data as $v) if (is_array($v) && array_is_list($v)) $records = max($records, count($v));
    }
    $label = $kind !== '' ? $kind : 'no kind given';
    return array('kind' => mb_substr($kind, 0, 40), 'kit_version' => mb_substr($ver, 0, 10), 'records' => $records,
        'summary' => "$label, $records records");
}

/** Count the data rows of a CSV or TSV file (records with line breaks inside quotes count once). */
function kop_rup_count_rows($path, $sep) {
    $fh = @fopen($path, 'r');
    if (!$fh) return 0;
    $rows = -1; // the heading row
    while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
        if ($r !== array(null)) $rows++;
    }
    fclose($fh);
    return max(0, $rows);
}

/**
 * Check one uploaded file. Returns ['type', 'kind', 'kit_version', 'records', 'summary'] or throws.
 */
function kop_rup_inspect($path, $name) {
    $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
    if (!in_array($ext, kop_rup_allowed_types(), true)) {
        throw new RuntimeException('Only .json, .csv, .tsv, .xlsx and .zip files are taken.');
    }
    $base = array('type' => $ext, 'kind' => '', 'kit_version' => '', 'records' => 0, 'summary' => '');
    if ($ext === 'json') {
        return array('type' => 'json') + kop_rup_read_json(file_get_contents($path));
    }
    if ($ext === 'csv' || $ext === 'tsv') {
        $head = (string) file_get_contents($path, false, null, 0, 4096);
        if (strpos($head, "\0") !== false) throw new RuntimeException('This does not look like a text spreadsheet file.');
        $n = kop_rup_count_rows($path, $ext === 'tsv' ? "\t" : ',');
        if (preg_match('/^[\w-]*?(facilities|companies|people|lawsuits|bills|news|inspections|source-links)/i', (string) $name, $m)) $base['kind'] = strtolower($m[1]);
        return array_merge($base, array('records' => $n, 'summary' => "$n rows"));
    }
    $sig = (string) file_get_contents($path, false, null, 0, 4);
    if ($sig !== "PK\x03\x04") throw new RuntimeException('This file is not really a ' . ($ext === 'xlsx' ? 'spreadsheet' : 'zip') . '.');
    if ($ext === 'xlsx') return array_merge($base, array('summary' => 'spreadsheet'));
    if (!class_exists('ZipArchive')) return array_merge($base, array('summary' => 'zip'));
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('This zip will not open.');
    $count = array();
    $bad = array();
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string) $zip->getNameIndex($i);
        if (substr($entry, -1) === '/' || preg_match('#(^|/)(__MACOSX|\.)#', $entry)) continue;
        $e = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        $count[$e] = ($count[$e] ?? 0) + 1;
        if ($e === 'json' && $zip->statIndex($i)['size'] <= 50 * 1048576) {
            try {
                kop_rup_read_json((string) $zip->getFromIndex($i));
            } catch (RuntimeException $ex) {
                $bad[] = basename($entry);
            }
        }
    }
    $zip->close();
    if ($bad) throw new RuntimeException('Not valid JSON inside the zip: ' . implode(', ', array_slice($bad, 0, 5)) . (count($bad) > 5 ? ' and ' . (count($bad) - 5) . ' more' : '') . '.');
    if (!$count) throw new RuntimeException('This zip is empty.');
    $parts = array();
    foreach ($count as $e => $n) $parts[] = $n . ' ' . ($e !== '' ? ".$e" : 'other');
    return array_merge($base, array('records' => array_sum($count), 'summary' => 'zip: ' . implode(', ', $parts)));
}

/** Bytes this link has sent so far, and files in the last hour. */
function kop_rup_usage($link_id) {
    global $wpdb;
    kop_rup_ensure_tables();
    $t = kop_rup_uploads_table();
    $bytes = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(bytes), 0) FROM $t WHERE link_id = %d", (int) $link_id));
    $hour = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE link_id = %d AND uploaded_at > %s", (int) $link_id, gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)));
    return array($bytes, $hour);
}

function kop_rup_safe_name($name) {
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $name);
    $name = trim(preg_replace('/-+/', '-', $name), '-.');
    return mb_substr($name !== '' ? $name : 'file', 0, 120);
}

/**
 * Keep one file for a link. $tmp is a file on disk (an upload's tmp_name, or any path in tests);
 * $move = true moves an HTTP upload. Returns the stored row, or throws.
 */
function kop_rup_store(array $link, $tmp, $name, $note = '', $move = true) {
    if (kop_rup_link_state($link) !== 'on') throw new RuntimeException('This link has stopped working.');
    $name = mb_substr(wp_basename((string) $name), 0, 255);
    $size = (int) @filesize($tmp);
    if ($size <= 0) throw new RuntimeException('This file is empty.');
    if ($size > kop_rup_file_max()) throw new RuntimeException('This file is larger than ' . size_format(kop_rup_file_max()) . '. Split it, or zip it.');
    list($used, $hour) = kop_rup_usage((int) $link['id']);
    if ($hour >= KOP_RUP_HOURLY_FILES) throw new RuntimeException('This link has sent ' . KOP_RUP_HOURLY_FILES . ' files in the last hour. Try again later.');
    if ($used + $size > KOP_RUP_LINK_BYTES) throw new RuntimeException('This link has used its ' . size_format(KOP_RUP_LINK_BYTES) . '. Ask whoever sent it for more room.');
    $info = kop_rup_inspect($tmp, $name);
    $sha = hash_file('sha256', $tmp);
    global $wpdb;
    $t = kop_rup_uploads_table();
    $dup = $wpdb->get_var($wpdb->prepare("SELECT file_name FROM $t WHERE link_id = %d AND sha256 = %s LIMIT 1", (int) $link['id'], $sha));
    if ($dup !== null) throw new RuntimeException('You already sent this file (as ' . $dup . ').');
    $stored = gmdate('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '-' . kop_rup_safe_name($name);
    $dest = kop_rup_private_dir((int) $link['id']) . '/' . $stored;
    $ok = $move && is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
    if (!$ok) throw new RuntimeException('The file could not be saved. Try again in a minute.');
    $now = current_time('mysql', true);
    $row = array(
        'link_id' => (int) $link['id'], 'file_name' => $name, 'stored_name' => $stored, 'bytes' => $size, 'sha256' => $sha,
        'file_type' => $info['type'], 'kind' => (string) $info['kind'], 'kit_version' => (string) $info['kit_version'],
        'records' => (int) $info['records'], 'summary' => mb_substr((string) $info['summary'], 0, 255),
        'note' => mb_substr(trim((string) $note), 0, 2000), 'status' => 'new', 'uploaded_at' => $now,
    );
    $wpdb->insert($t, $row);
    $row['id'] = (int) $wpdb->insert_id;
    $wpdb->update(kop_rup_links_table(), array('last_used' => $now), array('id' => (int) $link['id']));
    return $row;
}

/** This link's files, newest first. */
function kop_rup_uploads_for($link_id, $limit = 200) {
    global $wpdb;
    kop_rup_ensure_tables();
    return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_rup_uploads_table() . ' WHERE link_id = %d ORDER BY id DESC LIMIT %d', (int) $link_id, (int) $limit), ARRAY_A);
}

function kop_rup_statuses() {
    return array('new' => 'New', 'loaded' => 'Loaded', 'aside' => 'Set aside');
}

/** Mark a file; returns the status it had (for Undo). */
function kop_rup_set_status($id, $status) {
    if (!isset(kop_rup_statuses()[$status])) throw new RuntimeException('Unknown status.');
    global $wpdb;
    kop_rup_ensure_tables();
    $t = kop_rup_uploads_table();
    $old = $wpdb->get_var($wpdb->prepare("SELECT status FROM $t WHERE id = %d", (int) $id));
    if ($old === null) throw new RuntimeException('No such file.');
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $wpdb->update($t, array('status' => $status, 'status_by' => $u && $u->exists() ? mb_substr($u->user_login, 0, 60) : '',
        'status_at' => current_time('mysql', true)), array('id' => (int) $id));
    return (string) $old;
}

/** Turn PHP's per-file upload arrays (name[], tmp_name[], ...) into one list. */
function kop_rup_files_from_request($field = 'kop_rup_files') {
    $f = $_FILES[$field] ?? null;
    if (!$f || !isset($f['name'])) return array();
    $out = array();
    foreach ((array) $f['name'] as $i => $name) {
        if ((string) $name === '') continue;
        $out[] = array('name' => (string) $name, 'tmp_name' => (string) ($f['tmp_name'][$i] ?? ''), 'error' => (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE), 'size' => (int) ($f['size'][$i] ?? 0));
    }
    return $out;
}

function kop_rup_upload_error($code) {
    switch ((int) $code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'This file is larger than ' . size_format(kop_rup_file_max()) . '. Split it, or zip it.';
        case UPLOAD_ERR_PARTIAL: return 'Only part of this file arrived. Try again.';
        case UPLOAD_ERR_NO_FILE: return 'No file arrived.';
        default: return 'The server could not take this file (error ' . (int) $code . ').';
    }
}

/* ---- The page: /researcher-upload/?key=<token> --------------------------------- */

add_action('init', function () {
    add_rewrite_rule('^' . KOP_RUP_PATH . '/?$', 'index.php?kop_rupload=1', 'top');
    if (get_option('kop_rupload_rewrite') !== KOP_RUP_REWRITE_VERSION) {
        flush_rewrite_rules(false);
        update_option('kop_rupload_rewrite', KOP_RUP_REWRITE_VERSION);
    }
});

add_filter('query_vars', function ($vars) {
    $vars[] = 'kop_rupload';
    return $vars;
});

function kop_rup_is_page() {
    return function_exists('get_query_var') && get_query_var('kop_rupload') === '1';
}

add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query() || $query->get('kop_rupload') !== '1') return;
    $query->is_home = false;
    $query->set('posts_per_page', 1);
    $query->set('no_found_rows', true);
});
add_filter('posts_pre_query', function ($posts, $query) {
    return (!is_admin() && $query->is_main_query() && $query->get('kop_rupload') === '1') ? array() : $posts;
}, 10, 2);
add_filter('pre_handle_404', function ($preempt, $query) {
    return $query->get('kop_rupload') === '1' ? true : $preempt;
}, 10, 2);

/** The page's state for the template: link, token, results of a post. */
function kop_rup_page_state() {
    return $GLOBALS['kop_rup_state'] ?? array('link' => null, 'token' => '', 'results' => array(), 'error' => '');
}

add_action('template_redirect', function () {
    if (!kop_rup_is_page()) return;
    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    do_action('litespeed_control_set_nocache', 'kop researcher upload: per-link page');
    nocache_headers();
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Referrer-Policy: no-referrer', true);
    status_header(200);

    $token = strtolower(trim((string) wp_unslash($_POST['kop_rup_key'] ?? $_GET['key'] ?? '')));
    $link = $token !== '' ? kop_rup_by_token($token) : null;
    $state = array('link' => $link, 'token' => $link ? $token : '', 'results' => array(), 'error' => '');

    if ($link && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $files = kop_rup_files_from_request();
        if (!hash_equals(kop_rup_csrf($token), (string) wp_unslash($_POST['kop_rup_check'] ?? ''))) {
            $state['error'] = 'This form is out of date. Open your link again and send the files from there.';
        } elseif (kop_rup_link_state($link) !== 'on') {
            $state['error'] = 'This link has stopped working.';
        } elseif (!$files) {
            // A post bigger than post_max_size arrives with no files at all.
            $state['error'] = empty($_POST) ? 'That was more than the server takes in one go (' . size_format(kop_rup_file_max()) . '). Send fewer files at a time.' : 'Choose at least one file.';
        } else {
            $note = trim((string) wp_unslash($_POST['kop_rup_note'] ?? ''));
            $sent = array();
            foreach ($files as $f) {
                if ($f['error'] !== UPLOAD_ERR_OK) {
                    $state['results'][] = array('name' => $f['name'], 'ok' => false, 'message' => kop_rup_upload_error($f['error']));
                    continue;
                }
                try {
                    $row = kop_rup_store($link, $f['tmp_name'], $f['name'], $note);
                    $state['results'][] = array('name' => $f['name'], 'ok' => true, 'message' => $row['summary']);
                    $sent[] = $row;
                } catch (Throwable $e) {
                    $state['results'][] = array('name' => $f['name'], 'ok' => false, 'message' => $e->getMessage());
                }
            }
            if ($sent && function_exists('kop_notify_admins')) {
                $GLOBALS['kop_notify_sender'] = 'person';
                kop_notify_admins('researcher_upload', count($sent) . ' file' . (count($sent) === 1 ? '' : 's') . ' from ' . $link['name'], admin_url('admin.php?page=kop-researcher-uploads'), array(
                    'Submitted by' => $link['name'],
                    'Files' => implode('; ', array_map(static function ($r) { return $r['file_name'] . ' (' . $r['summary'] . ')'; }, $sent)),
                    'Note' => $note,
                ));
                unset($GLOBALS['kop_notify_sender']);
            }
            $link = kop_rup_link((int) $link['id']);
            $state['link'] = $link;
        }
    }
    $GLOBALS['kop_rup_state'] = $state;
}, 5);

add_filter('template_include', function ($template) {
    if (!kop_rup_is_page()) return $template;
    $t = get_stylesheet_directory() . '/templates/researcher-upload.php';
    return file_exists($t) ? $t : $template;
}, 99);

add_filter('pre_get_document_title', function ($title) {
    return kop_rup_is_page() ? 'Send research files | Kids Over Profits' : $title;
}, 20);

add_filter('wp_robots', function ($robots) {
    if (kop_rup_is_page()) { $robots['noindex'] = true; $robots['nofollow'] = true; }
    return $robots;
});

add_action('wp_enqueue_scripts', function () {
    if (!kop_rup_is_page()) return;
    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();
    foreach (array('open-data', 'researcher-kit', 'researcher-upload') as $css) {
        if (file_exists("$dir/css/$css.css")) {
            wp_enqueue_style("kop-$css", "$uri/css/$css.css", array('kop-colors'), filemtime("$dir/css/$css.css"));
        }
    }
}, 20);

/* ---- KOP Tools > Researcher Uploads -------------------------------------------- */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'Researcher Uploads', 'Researcher Uploads', 'manage_options', 'kop-researcher-uploads', 'kop_rup_admin_page');
}, 22);

/** Download one file (admin only). */
add_action('admin_post_kop_rup_download', function () {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $id = (int) ($_GET['id'] ?? 0);
    check_admin_referer('kop_rup_download_' . $id);
    global $wpdb;
    kop_rup_ensure_tables();
    $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_rup_uploads_table() . ' WHERE id = %d', $id), ARRAY_A);
    if (!$row) wp_die('No such file.', 'Not found', array('response' => 404));
    $path = kop_rup_private_dir((int) $row['link_id']) . '/' . basename((string) $row['stored_name']);
    if (!is_file($path)) wp_die('The file is missing from the server.', 'Not found', array('response' => 404));
    nocache_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', kop_rup_safe_name($row['file_name'])) . '"');
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
});

function kop_rup_admin_url($args = array()) {
    return add_query_arg($args, admin_url('admin.php?page=kop-researcher-uploads'));
}

function kop_rup_admin_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    kop_rup_ensure_tables();
    global $wpdb;
    $notice = '';
    $error = '';
    $made = '';
    $undo = null;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['kop_rup_do'])) {
        check_admin_referer('kop_rup_admin');
        $do = sanitize_key($_POST['kop_rup_do']);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            if ($do === 'create') {
                list(, $made) = kop_rup_create(wp_unslash($_POST['name'] ?? ''), wp_unslash($_POST['note'] ?? ''), (int) ($_POST['days'] ?? 30));
                $notice = 'Link made. Copy it now: it is shown only this once.';
            } elseif ($do === 'newlink') {
                $made = kop_rup_new_link($id);
                $notice = 'New link made; the old one no longer works. Copy it now: it is shown only this once.';
            } elseif ($do === 'revoke') {
                kop_rup_revoke($id);
                $notice = 'Link turned off. "New link" or "More time" turns it back on.';
            } elseif ($do === 'extend') {
                kop_rup_extend($id, (int) ($_POST['days'] ?? 30));
                $notice = 'Link given more time; the address stays the same.';
            } elseif ($do === 'status') {
                $status = sanitize_key($_POST['status'] ?? '');
                $old = kop_rup_set_status($id, $status);
                $notice = 'Marked ' . kop_rup_statuses()[$status] . '.';
                if ($old !== $status) $undo = array('id' => $id, 'status' => $old);
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }

    $links = (array) $wpdb->get_results('SELECT l.*, (SELECT COUNT(*) FROM ' . kop_rup_uploads_table() . ' u WHERE u.link_id = l.id) AS files,
        (SELECT COALESCE(SUM(bytes), 0) FROM ' . kop_rup_uploads_table() . ' u WHERE u.link_id = l.id) AS bytes
        FROM ' . kop_rup_links_table() . ' l ORDER BY (l.revoked_at IS NULL) DESC, l.id DESC', ARRAY_A);
    $names = array();
    foreach ($links as $l) $names[(int) $l['id']] = (string) $l['name'];
    $show = sanitize_key($_GET['show'] ?? 'new');
    if (!in_array($show, array('new', 'loaded', 'aside', 'all'), true)) $show = 'new';
    $where = $show === 'all' ? '' : $wpdb->prepare(' WHERE status = %s', $show);
    $uploads = (array) $wpdb->get_results('SELECT * FROM ' . kop_rup_uploads_table() . $where . ' ORDER BY id DESC LIMIT 300', ARRAY_A);
    $counts = array();
    foreach ((array) $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . kop_rup_uploads_table() . ' GROUP BY status', ARRAY_A) as $r) $counts[$r['status']] = (int) $r['n'];

    $btn = static function ($do, $label, $fields = array(), $class = 'button button-small') {
        $out = '<form method="post" style="display:inline">' . wp_nonce_field('kop_rup_admin', '_wpnonce', true, false)
            . '<input type="hidden" name="kop_rup_do" value="' . esc_attr($do) . '">';
        foreach ($fields as $k => $v) $out .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        return $out . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form> ';
    };
    $when = static function ($utc) {
        return $utc ? esc_html(get_date_from_gmt($utc, 'M j, Y')) : '';
    };
    ?>
    <div class="wrap kop-rup-admin">
        <h1>Researcher Uploads</h1>
        <p>Make a personal upload link for a researcher you trust. They open it, choose their files (the shapes on the
            <a href="<?php echo esc_url(home_url('/researcher-kit/')); ?>" target="_blank" rel="noopener">Researcher Data Kit</a>, a spreadsheet or a zip) and send them.
            Nothing goes into the records from here: download a file, load it, then mark it Loaded.</p>

        <?php if ($notice) : ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?>
            <?php if ($undo) echo $btn('status', 'Undo', array('id' => $undo['id'], 'status' => $undo['status'])); ?></p></div><?php endif; ?>
        <?php if ($error) : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
        <?php if ($made) : ?>
            <div class="notice notice-info" style="padding:12px">
                <p><strong>Send this link to the researcher</strong> (by email or a private message, never in public):</p>
                <p><input type="text" readonly id="kop-rup-made" value="<?php echo esc_attr($made); ?>" style="width:100%;max-width:46rem;font-family:monospace" onclick="this.select()">
                    <button type="button" class="button" onclick="var i=document.getElementById('kop-rup-made');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='Copied';">Copy</button></p>
            </div>
        <?php endif; ?>

        <h2>Make a link</h2>
        <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">
            <?php wp_nonce_field('kop_rup_admin'); ?>
            <input type="hidden" name="kop_rup_do" value="create">
            <label>Researcher or group<br><input type="text" name="name" required maxlength="100" style="width:16rem"></label>
            <label>What they are collecting (only you see this)<br><input type="text" name="note" maxlength="255" style="width:22rem"></label>
            <label>Works for<br><select name="days">
                <?php foreach (kop_rup_durations() as $d => $label) : ?>
                    <option value="<?php echo (int) $d; ?>"<?php selected($d, 30); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select></label>
            <button type="submit" class="button button-primary">Make link</button>
        </form>

        <h2>Links</h2>
        <?php if (!$links) : ?>
            <p>No links yet.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>Researcher</th><th>Note</th><th>State</th><th>Made</th><th>Works until</th><th>Last upload</th><th>Files</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($links as $l) :
                    $st = kop_rup_link_state($l);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($l['name']); ?></strong></td>
                        <td><?php echo esc_html($l['note']); ?></td>
                        <td><?php echo esc_html(array('on' => 'Working', 'off' => 'Turned off', 'expired' => 'Ran out')[$st]); ?></td>
                        <td><?php echo $when($l['created_at']); ?></td>
                        <td><?php echo $l['expires_at'] ? $when($l['expires_at']) : 'Until turned off'; ?></td>
                        <td><?php echo $when($l['last_used']); ?></td>
                        <td><?php echo (int) $l['files']; ?><?php if ((int) $l['bytes']) echo ' (' . esc_html(size_format((int) $l['bytes'])) . ')'; ?></td>
                        <td>
                            <?php echo $btn('newlink', 'New link', array('id' => $l['id'])); ?>
                            <?php if ($st !== 'off') echo $btn('revoke', 'Turn off', array('id' => $l['id'])); ?>
                            <form method="post" style="display:inline">
                                <?php wp_nonce_field('kop_rup_admin'); ?>
                                <input type="hidden" name="kop_rup_do" value="extend"><input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>">
                                <select name="days" aria-label="More time for <?php echo esc_attr($l['name']); ?>">
                                    <?php foreach (kop_rup_durations() as $d => $label) : ?><option value="<?php echo (int) $d; ?>"<?php selected($d, 30); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                                </select>
                                <button type="submit" class="button button-small">More time</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>Files received</h2>
        <p>
            <?php foreach (array('new' => 'New', 'loaded' => 'Loaded', 'aside' => 'Set aside', 'all' => 'All') as $k => $label) :
                $n = $k === 'all' ? array_sum($counts) : ($counts[$k] ?? 0);
                ?>
                <a href="<?php echo esc_url(kop_rup_admin_url(array('show' => $k))); ?>" class="button<?php echo $show === $k ? ' button-primary' : ''; ?>"><?php echo esc_html("$label ($n)"); ?></a>
            <?php endforeach; ?>
        </p>
        <?php if (!$uploads) : ?>
            <p>None here.</p>
        <?php else : ?>
            <table class="widefat striped">
                <thead><tr><th>Received</th><th>From</th><th>File</th><th>What it holds</th><th>Size</th><th>Their note</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($uploads as $u) :
                    $dl = wp_nonce_url(admin_url('admin-post.php?action=kop_rup_download&id=' . (int) $u['id']), 'kop_rup_download_' . (int) $u['id']);
                    ?>
                    <tr>
                        <td><?php echo esc_html(get_date_from_gmt($u['uploaded_at'], 'M j, Y g:i a')); ?></td>
                        <td><?php echo esc_html($names[(int) $u['link_id']] ?? ''); ?></td>
                        <td><a href="<?php echo esc_url($dl); ?>"><?php echo esc_html($u['file_name']); ?></a></td>
                        <td><?php echo esc_html($u['summary']); ?><?php if ($u['kit_version'] !== '') echo ' <span class="description">(kit ' . esc_html($u['kit_version']) . ')</span>'; ?></td>
                        <td><?php echo esc_html(size_format((int) $u['bytes'])); ?></td>
                        <td><?php echo esc_html(mb_strimwidth((string) $u['note'], 0, 200, '...')); ?></td>
                        <td><?php echo esc_html(kop_rup_statuses()[$u['status']] ?? $u['status']); ?></td>
                        <td>
                            <a class="button button-small" href="<?php echo esc_url($dl); ?>">Download</a>
                            <?php foreach (kop_rup_statuses() as $sk => $slabel) {
                                if ($sk === $u['status']) continue;
                                echo $btn('status', $sk === 'new' ? 'Back to new' : $slabel, array('id' => $u['id'], 'status' => $sk));
                            } ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
