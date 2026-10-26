<?php
/**
 * Researcher upload links (inc/researcher-uploads.php): making, replacing,
 * turning off and running out of links; what each kind of file is read as;
 * the refusals (bad JSON, wrong type, duplicates, a dead link); statuses with
 * Undo. Chunked uploads and documents (inc/researcher-documents.php): pieces
 * put back together, a wrong offset, the sweep, documents kept / refused /
 * already in the library, adding one to the library and Undo.
 * Runs on an in-memory SQLite database and a scratch folder, no mirror.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-researcher-uploads.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($str, 0, $no, $file, $line);
});

define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$scratch = sys_get_temp_dir() . '/kop-rup-test-' . getmypid();
@mkdir($scratch, 0755, true);
$GLOBALS['kop_test_options'] = array();

function add_action() {}
function add_filter() {}
function add_rewrite_rule() {}
function get_option($k, $d = false) { return $GLOBALS['kop_test_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function wp_salt() { return 'test-salt'; }
function home_url($p = '') { return 'https://example.test' . ($p === '' ? '' : '/' . ltrim($p, '/')); }
function add_query_arg($k, $v, $url) { return $url . (strpos($url, '?') === false ? '?' : '&') . $k . '=' . rawurlencode($v); }
function current_time() { return gmdate('Y-m-d H:i:s'); }
function wp_upload_dir() { @mkdir($GLOBALS['scratch'] . '/2026/10', 0755, true); return array('basedir' => $GLOBALS['scratch'], 'path' => $GLOBALS['scratch'] . '/2026/10', 'error' => false); }
function wp_json_encode($v) { return json_encode($v); }
function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
function sanitize_file_name($n) { return preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $n); }
function wp_unique_filename($dir, $n) { return is_file("$dir/$n") ? 'x-' . $n : $n; }
function wp_check_filetype($n) { $e = strtolower(pathinfo((string) $n, PATHINFO_EXTENSION)); return array('ext' => $e, 'type' => $e === 'pdf' ? 'application/pdf' : ($e === 'png' ? 'image/png' : false)); }
function is_wp_error($v) { return false; }
function wp_insert_attachment($a, $file) { global $wpdb; $wpdb->insert('wpdl_posts', array('post_type' => 'attachment', 'post_title' => $a['post_title'], 'file' => $file)); return $wpdb->insert_id; }
function wp_generate_attachment_metadata($id, $file) { return array(); }
function wp_update_attachment_metadata($id, $m) { return true; }
function update_post_meta($id, $k, $v) { global $wpdb; $wpdb->insert('wpdl_postmeta', array('post_id' => $id, 'meta_key' => $k, 'meta_value' => (string) $v)); }
function get_attached_file($id) { global $wpdb; return (string) $wpdb->get_var('SELECT file FROM wpdl_posts WHERE ID = ' . (int) $id); }
function wp_delete_attachment($id, $force) { global $wpdb; $f = get_attached_file($id); @unlink($f); $wpdb->pdo->exec('DELETE FROM wpdl_posts WHERE ID = ' . (int) $id); $wpdb->pdo->exec('DELETE FROM wpdl_postmeta WHERE post_id = ' . (int) $id); }
function kop_dp_child_folder($name, $parent) { return 900; }
function kop_dp_target($v) { return array(500 + (int) substr($v, 2), 'Facility', true); }
function wp_basename($p) { return basename(str_replace('\\', '/', (string) $p)); }
function size_format($b) { return round($b / 1048576) . ' MB'; }
function wp_max_upload_size() { return 0; }
function wp_get_current_user() { return new class { public $user_login = 'tester'; public function exists() { return true; } }; }

/** Just enough of $wpdb over SQLite for the module. */
class KOP_Test_WPDB {
    public $prefix = 'wpdl_';
    public $posts = 'wpdl_posts';
    public $postmeta = 'wpdl_postmeta';
    public $insert_id = 0;
    public $pdo;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[dsf]/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : ($m[0] === '%f' ? (string) (float) $v : $this->pdo->quote((string) $v));
        }, $sql);
    }
    public function insert($t, array $row) {
        $cols = array_keys($row);
        $st = $this->pdo->prepare("INSERT INTO $t (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        $st->execute(array_values($row));
        $this->insert_id = (int) $this->pdo->lastInsertId();
        return 1;
    }
    public function update($t, array $set, array $where) {
        $s = implode(',', array_map(function ($c) { return "$c = ?"; }, array_keys($set)));
        $w = implode(' AND ', array_map(function ($c) { return "$c = ?"; }, array_keys($where)));
        $st = $this->pdo->prepare("UPDATE $t SET $s WHERE $w");
        $st->execute(array_merge(array_values($set), array_values($where)));
        return $st->rowCount();
    }
    public function query($sql) { return $this->pdo->exec($sql); }
    public function get_var($sql) { $v = $this->pdo->query($sql)->fetchColumn(); return $v === false ? null : $v; }
    public function get_row($sql) { $r = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
    public function get_results($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
}
$wpdb = new KOP_Test_WPDB();
$wpdb->pdo->exec("CREATE TABLE wpdl_kop_researcher_links (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT NOT NULL DEFAULT '',
    token_hash TEXT NOT NULL UNIQUE, days INTEGER NOT NULL DEFAULT 30, created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL,
    link_at TEXT NOT NULL, expires_at TEXT, last_used TEXT, revoked_at TEXT)");
$wpdb->pdo->exec("CREATE TABLE wpdl_kop_researcher_uploads (id INTEGER PRIMARY KEY AUTOINCREMENT, link_id INTEGER NOT NULL, file_name TEXT NOT NULL,
    stored_name TEXT NOT NULL, bytes INTEGER NOT NULL DEFAULT 0, sha256 TEXT NOT NULL DEFAULT '', file_type TEXT NOT NULL DEFAULT '',
    kind TEXT NOT NULL DEFAULT '', kit_version TEXT NOT NULL DEFAULT '', records INTEGER NOT NULL DEFAULT 0, summary TEXT NOT NULL DEFAULT '',
    note TEXT, status TEXT NOT NULL DEFAULT 'new', status_by TEXT NOT NULL DEFAULT '', status_at TEXT, uploaded_at TEXT NOT NULL)");

$wpdb->pdo->exec("CREATE TABLE wpdl_kop_researcher_docs (id INTEGER PRIMARY KEY AUTOINCREMENT, link_id INTEGER NOT NULL, file_name TEXT NOT NULL,
    rel_path TEXT NOT NULL DEFAULT '', stored_name TEXT NOT NULL DEFAULT '', bytes INTEGER NOT NULL DEFAULT 0, sha256 TEXT NOT NULL DEFAULT '',
    md5 TEXT NOT NULL DEFAULT '', file_type TEXT NOT NULL DEFAULT '', source_url TEXT NOT NULL DEFAULT '', about TEXT NOT NULL DEFAULT '', note TEXT,
    status TEXT NOT NULL DEFAULT 'new', attachment_id INTEGER NOT NULL DEFAULT 0, att_made INTEGER NOT NULL DEFAULT 0, folder_id INTEGER NOT NULL DEFAULT 0,
    rel_added INTEGER NOT NULL DEFAULT 0, facility_id INTEGER NOT NULL DEFAULT 0, doc_type TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '',
    uploaded_at TEXT NOT NULL, status_by TEXT NOT NULL DEFAULT '', status_at TEXT)");
$wpdb->pdo->exec("CREATE TABLE wpdl_posts (ID INTEGER PRIMARY KEY AUTOINCREMENT, post_type TEXT, post_title TEXT, file TEXT)");
$wpdb->pdo->exec("CREATE TABLE wpdl_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)");
$wpdb->pdo->exec("CREATE TABLE wpdl_fbv_attachment_folder (folder_id INTEGER, attachment_id INTEGER)");

require ABSPATH . 'inc/researcher-uploads.php';
require ABSPATH . 'inc/researcher-documents.php';
$GLOBALS['kop_test_options']['kop_researcher_uploads_db'] = KOP_RUP_DB_VERSION;
$GLOBALS['kop_test_options']['kop_researcher_docs_db'] = KOP_RDOC_DB_VERSION;

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
};
$throws = function (callable $fn) {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};
$file = function ($name, $body) use ($scratch) {
    $p = "$scratch/in-" . bin2hex(random_bytes(3)) . '-' . $name;
    file_put_contents($p, $body);
    return $p;
};
$token_of = function ($url) { parse_str((string) parse_url($url, PHP_URL_QUERY), $q); return (string) ($q['key'] ?? ''); };

echo "Links\n";
list($id, $url) = kop_rup_create('Pat Researcher', 'Utah licensing scrape', 30);
$token = $token_of($url);
$check('link carries a 48-character token', strlen($token) === 48, $url);
$check('the link opens the upload page', strpos($url, 'https://example.test/researcher-upload/?key=') === 0);
$check('only the hash is stored', !$wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_researcher_links WHERE token_hash = ' . $wpdb->pdo->quote($token)));
$link = kop_rup_by_token($token);
$check('the token finds the link', (int) ($link['id'] ?? 0) === $id);
$check('upper case works too', (int) (kop_rup_by_token(strtoupper($token))['id'] ?? 0) === $id);
$check('a wrong token finds nothing', kop_rup_by_token(str_repeat('a', 48)) === null && kop_rup_by_token('x') === null);
$check('working, runs out in 30 days', kop_rup_link_state($link) === 'on' && abs(strtotime($link['expires_at'] . ' UTC') - (time() + 30 * DAY_IN_SECONDS)) < 5);
$check('the form value depends on the token', kop_rup_csrf($token) !== kop_rup_csrf(str_repeat('b', 48)) && strlen(kop_rup_csrf($token)) === 32);
$check('no name is refused', $throws(function () { kop_rup_create('  '); }) !== '');
list($id_forever) = kop_rup_create('Group', '', 0);
$check('"until I turn it off" has no end date', kop_rup_link($id_forever)['expires_at'] === null);

echo "\nWhat files are read as\n";
$kit = json_encode(array('kit_version' => 1, 'kind' => 'facilities', 'submitted_by' => 'Pat', 'facilities' => array(array('name' => 'A'), array('name' => 'B'), array('name' => 'C'))));
$r = kop_rup_store($link, $file('facilities-ut.json', "\xEF\xBB\xBF" . $kit), 'facilities-ut.json', 'first batch', false);
$check('kit JSON: kind, version and record count', $r['kind'] === 'facilities' && $r['kit_version'] === '1' && $r['records'] === 3, $r['summary']);
$check('kept in the private folder', is_file($scratch . '/kop-private/researcher/' . $id . '/' . $r['stored_name']));
$check('the private folder denies the web', strpos((string) @file_get_contents($scratch . '/kop-private/.htaccess'), 'Require all denied') !== false);
$check('the note is kept', $r['note'] === 'first batch');
$insp = json_encode(array('kit_version' => 1, 'kind' => 'inspections', 'facilities' => array(array('reports' => array(1, 2)), array('reports' => array(3)))));
$r = kop_rup_store($link, $file('insp.json', $insp), 'inspections-tx.json', '', false);
$check('inspections count reports too', $r['summary'] === '2 facilities, 3 reports', $r['summary']);
$r = kop_rup_store($link, $file('links.json', json_encode(array(array('type' => 'article', 'url' => 'https://a'), array('type' => 'website', 'url' => 'https://b')))), 'links.json', '', false);
$check('a plain list of links is source-links', $r['kind'] === 'source-links' && $r['records'] === 2, $r['summary']);
$r = kop_rup_store($link, $file('f.csv', "name,location.city\n\"Red Rock, Ranch\",Kanab\n\"Two\nlines\",Provo\n"), 'facilities-sheet.csv', '', false);
$check('CSV: rows counted, quoted line breaks once', $r['records'] === 2 && $r['kind'] === 'facilities', $r['summary']);
if (class_exists('ZipArchive')) {
    $zp = "$scratch/batch.zip";
    $z = new ZipArchive();
    $z->open($zp, ZipArchive::CREATE);
    $z->addFromString('a/companies.json', json_encode(array('kind' => 'companies', 'companies' => array())));
    $z->addFromString('a/people.csv', "name\nX\n");
    $z->addFromString('__MACOSX/._junk', 'x');
    $z->close();
    $r = kop_rup_store($link, $zp, 'batch.zip', '', false);
    $check('zip lists what it holds, skips Mac junk', $r['summary'] === 'zip: 1 .json, 1 .csv', $r['summary']);
    $zb = "$scratch/bad.zip";
    $z = new ZipArchive();
    $z->open($zb, ZipArchive::CREATE);
    $z->addFromString('broken.json', '{"kind": ');
    $z->close();
    $check('bad JSON inside a zip is refused', strpos($throws(function () use ($link, $zb) { kop_rup_store($link, $zb, 'bad.zip', '', false); }), 'broken.json') !== false);
} else {
    echo "  skip zip checks (no ZipArchive)\n";
}

echo "\nRefusals\n";
$m = $throws(function () use ($link, $file) { kop_rup_store($link, $file('x.json', '{"kind": "facilities",'), 'x.json', '', false); });
$check('bad JSON says so', strpos($m, 'not valid JSON') !== false, $m);
$check('wrong file type', strpos($throws(function () use ($link, $file) { kop_rup_store($link, $file('x.exe', 'MZ'), 'x.exe', '', false); }), 'Only .json') !== false);
$check('a .php file is refused', $throws(function () use ($link, $file) { kop_rup_store($link, $file('x.php', '<?php'), 'x.php', '', false); }) !== '');
$check('an .xlsx that is not a zip', strpos($throws(function () use ($link, $file) { kop_rup_store($link, $file('x.xlsx', 'hello'), 'x.xlsx', '', false); }), 'not really') !== false);
$check('an empty file', strpos($throws(function () use ($link, $file) { kop_rup_store($link, $file('e.json', ''), 'e.json', '', false); }), 'empty') !== false);
$m = $throws(function () use ($link, $file, $kit) { kop_rup_store($link, $file('again.json', "\xEF\xBB\xBF" . $kit), 'again.json', '', false); });
$check('the same file twice', strpos($m, 'already sent') !== false && strpos($m, 'facilities-ut.json') !== false, $m);
$check('a stored name cannot climb out of its folder', strpos(kop_rup_safe_name('../../wp-config.php'), '/') === false && strpos(kop_rup_safe_name('../../wp-config.php'), '..') === false, kop_rup_safe_name('../../wp-config.php'));

echo "\nTurning off, renewing, running out\n";
kop_rup_revoke($id);
$off = kop_rup_by_token($token);
$check('turned off: found, but not working', $off && kop_rup_link_state($off) === 'off');
$check('a turned-off link takes nothing', strpos($throws(function () use ($off, $file) { kop_rup_store($off, $file('n.json', '[]'), 'n.json', '', false); }), 'stopped') !== false);
$url2 = kop_rup_new_link($id);
$token2 = $token_of($url2);
$check('a new link works', kop_rup_link_state(kop_rup_by_token($token2)) === 'on');
$check('the old link is gone', kop_rup_by_token($token) === null);
$wpdb->update('wpdl_kop_researcher_links', array('expires_at' => gmdate('Y-m-d H:i:s', time() - 60)), array('id' => $id));
$check('past its date: ran out', kop_rup_link_state(kop_rup_link($id)) === 'expired');
kop_rup_extend($id, 7);
$check('more time: working again, same address', kop_rup_link_state(kop_rup_by_token($token2)) === 'on');
$check('the files stay with the link', count(kop_rup_uploads_for($id)) >= 5);

echo "\nStatuses\n";
$fid = (int) kop_rup_uploads_for($id)[0]['id'];
$old = kop_rup_set_status($fid, 'loaded');
$check('mark loaded returns the old status for Undo', $old === 'new' && $wpdb->get_var("SELECT status FROM wpdl_kop_researcher_uploads WHERE id = $fid") === 'loaded');
kop_rup_set_status($fid, $old);
$check('Undo puts it back', $wpdb->get_var("SELECT status FROM wpdl_kop_researcher_uploads WHERE id = $fid") === 'new');
$check('unknown status refused', $throws(function () use ($fid) { kop_rup_set_status($fid, 'gone'); }) !== '');

echo "\nChunked uploads (no size cap)\n";
$link = kop_rup_by_token($token2);
$big = json_encode(array('kit_version' => 1, 'kind' => 'people', 'people' => array_fill(0, 400, array('name' => str_repeat('x', 40)))));
list($uid, $piece) = kop_rup_chunk_start($link, array('kind' => 'data', 'name' => 'people-big.json', 'size' => strlen($big), 'note' => 'in pieces'));
$check('start gives an upload id and a piece size', strlen($uid) === 32 && $piece >= 524288);
$parts = str_split($big, 5000);
$off = 0;
foreach ($parts as $i => $p) {
    if ($i === 1) {
        $check('a wrong offset adds nothing and says where the file stands', kop_rup_chunk_append($link, $uid, $off + 7, $file('p', $p)) === $off);
    }
    $off = kop_rup_chunk_append($link, $uid, $off, $file('p', $p));
}
$check('a piece sent twice (lost answer) is not added twice', kop_rup_chunk_append($link, $uid, $off - strlen(end($parts)), $file('p', end($parts))) === $off);
$check('all pieces in', $off === strlen($big));
$r = kop_rup_chunk_finish($link, $uid);
$check('a finished data file goes through the data checks', $r['kind'] === 'data' && $r['row']['records'] === 400 && $r['row']['note'] === 'in pieces', $r['message']);
$check('the pieces are gone after finishing', !glob($scratch . '/kop-private/researcher/' . $id . '/incoming/*'));
$check('a finished upload cannot be finished twice', strpos($throws(function () use ($link, $uid) { kop_rup_chunk_finish($link, $uid); }), 'gone') !== false);
list($uid2) = kop_rup_chunk_start($link, array('kind' => 'data', 'name' => 'short.json', 'size' => 100));
kop_rup_chunk_append($link, $uid2, 0, $file('p', '[1,2]'));
$check('finishing short is refused', strpos($throws(function () use ($link, $uid2) { kop_rup_chunk_finish($link, $uid2); }), 'Only 5 of 100') !== false);
$check('more than the file holds is refused', $throws(function () use ($link, $uid2, $file) { kop_rup_chunk_append($link, $uid2, 5, $file('p', str_repeat('y', 200))); }) !== '');
$m = $throws(function () use ($link) { kop_rup_chunk_start($link, array('kind' => 'doc', 'name' => 'scan.pdf', 'size' => 300 * 1048576)); });
$check('a 300 MB document starts (no cap; only a full disk stops it)', $m === '' || strpos($m, 'short of room') !== false, $m);
$check('a document in the data form is refused', strpos($throws(function () use ($link) { kop_rup_chunk_start($link, array('kind' => 'data', 'name' => 'a.pdf', 'size' => 10)); }), 'Send documents') !== false);
$check('a script as a document is refused', $throws(function () use ($link) { kop_rup_chunk_start($link, array('kind' => 'doc', 'name' => 'a.php', 'size' => 10)); }) !== '');
$check('a bad upload id is refused', $throws(function () use ($link) { kop_rup_chunk_append($link, '../../x', 0, __FILE__); }) !== '');
foreach (glob($scratch . '/kop-private/researcher/*/incoming/*') as $f) touch($f, time() - 3 * 86400);
$check('the sweep drops files nobody finished', kop_rup_incoming_sweep() >= 1 && !glob($scratch . '/kop-private/researcher/*/incoming/*'));

echo "\nDocuments\n";
$pdf = "%PDF-1.4\n" . str_repeat('scan ', 3000);
$send_doc = function ($name, $body, $meta = array()) use ($link, $file) {
    list($u) = kop_rup_chunk_start($link, array('kind' => 'doc', 'name' => $name, 'size' => strlen($body)) + $meta);
    kop_rup_chunk_append($link, $u, 0, $file('d', $body));
    return kop_rup_chunk_finish($link, $u);
};
$r = $send_doc('Red_Rock-handbook_1998.pdf', $pdf, array('about' => 'Red Rock Ranch, Kanab UT', 'source_url' => 'example.org/handbook.pdf', 'rel_path' => 'Utah/Red_Rock-handbook_1998.pdf'));
$d = $r['row'];
$check('a document waits, with what it is about and its source', $r['kind'] === 'doc' && $d['status'] === 'new' && $d['about'] === 'Red Rock Ranch, Kanab UT' && $d['source_url'] === 'https://example.org/handbook.pdf', $d['source_url']);
$check('kept in the private docs folder', is_file(kop_rdoc_path($d)) && strpos(kop_rdoc_path($d), '/kop-private/researcher/' . $id . '/docs/') !== false);
$check('the folder path is kept', $d['rel_path'] === 'Utah/Red_Rock-handbook_1998.pdf');
$check('the same document twice is refused', strpos($throws(function () use ($send_doc, $pdf) { $send_doc('copy.pdf', $pdf); }), 'already sent') !== false);
$check('markup named .pdf is refused', strpos($throws(function () use ($send_doc) { $send_doc('x.pdf', '<html><script>alert(1)</script>'); }), 'not really') !== false);
$check('a title from the file name', kop_rdoc_default_title('Red_Rock-handbook_1998.pdf') === 'Red Rock handbook 1998');

$a = kop_rdoc_add($d['id'], array('title' => 'Red Rock Ranch student handbook (1998)', 'facility_id' => 12, 'doc_type' => ''));
$att = (int) $a['attachment_id'];
$check('added: an attachment in the facility folder', $a['status'] === 'added' && $att > 0 && (int) $a['folder_id'] === 512 && (int) $a['att_made'] === 1
    && (int) $wpdb->get_var("SELECT COUNT(*) FROM wpdl_fbv_attachment_folder WHERE attachment_id = $att AND folder_id = 512") === 1);
$check('the file moved into the library', is_file(get_attached_file($att)) && !is_file(kop_rdoc_dir($id) . '/' . $d['stored_name']));
$check('source and md5 on the attachment', $wpdb->get_var("SELECT meta_value FROM wpdl_postmeta WHERE post_id = $att AND meta_key = '_kop_source_url'") === 'https://example.org/handbook.pdf'
    && $wpdb->get_var("SELECT meta_value FROM wpdl_postmeta WHERE post_id = $att AND meta_key = '_kop_import_md5'") === md5($pdf));
$check('added twice is refused', $throws(function () use ($d) { kop_rdoc_add($d['id'], array()); }) !== '');
kop_rdoc_undo_add($d['id']);
$u = kop_rdoc_row($d['id']);
$check('Undo: attachment gone, folder entry gone, file waiting again', $u['status'] === 'new' && !$wpdb->get_var("SELECT COUNT(*) FROM wpdl_posts WHERE ID = $att")
    && !$wpdb->get_var("SELECT COUNT(*) FROM wpdl_fbv_attachment_folder WHERE attachment_id = $att") && is_file(kop_rdoc_path($u)));
$a = kop_rdoc_add($d['id'], array('facility_id' => 0));
$check('no facility: the Researcher uploads folder, title from the file', (int) $a['folder_id'] === 900 && $a['title'] === 'Red Rock handbook 1998');
$wpdb->pdo->exec('DELETE FROM wpdl_kop_researcher_docs WHERE id = ' . (int) $d['id']); // forget the row; the library copy stays
$r3 = $send_doc('same scan, new name.pdf', $pdf);
$check('a document the library holds is marked, not kept twice', $r3['row']['status'] === 'held' && (int) $r3['row']['attachment_id'] === (int) $a['attachment_id'] && $r3['row']['stored_name'] === '');

$r4 = $send_doc('photo.png', "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64));
$old = kop_rdoc_set_status($r4['row']['id'], 'aside');
$check('set aside returns the old status', $old === 'new' && kop_rdoc_row($r4['row']['id'])['status'] === 'aside');
$check('an already-held document cannot be set aside', $throws(function () use ($r3) { kop_rdoc_set_status($r3['row']['id'], 'aside'); }) !== '');
$p4 = kop_rdoc_path(kop_rdoc_row($r4['row']['id']));
kop_rdoc_delete_file($r4['row']['id']);
$check('delete for good removes the file', !is_file($p4) && kop_rdoc_row($r4['row']['id'])['status'] === 'gone');
$check('only a set-aside document can be deleted', $throws(function () use ($r3) { kop_rdoc_delete_file($r3['row']['id']); }) !== '');
kop_rup_revoke($id);
$check('a turned-off link starts nothing', strpos($throws(function () use ($id) { kop_rup_chunk_start(kop_rup_link($id), array('kind' => 'doc', 'name' => 'a.pdf', 'size' => 9)); }), 'stopped') !== false);

// Clean up the scratch folder.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
@rmdir($scratch);

echo "\n" . ($failures ? "$failures FAILED\n" : "All passed.\n");
exit($failures ? 1 : 0);
