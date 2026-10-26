<?php
/**
 * Researcher upload links (inc/researcher-uploads.php): making, replacing,
 * turning off and running out of links; what each kind of file is read as;
 * the refusals (bad JSON, wrong type, duplicates, a dead link); statuses with
 * Undo. Runs on an in-memory SQLite database and a scratch folder, no mirror.
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
function wp_upload_dir() { return array('basedir' => $GLOBALS['scratch']); }
function wp_basename($p) { return basename(str_replace('\\', '/', (string) $p)); }
function size_format($b) { return round($b / 1048576) . ' MB'; }
function wp_max_upload_size() { return 0; }
function wp_get_current_user() { return new class { public $user_login = 'tester'; public function exists() { return true; } }; }

/** Just enough of $wpdb over SQLite for the module. */
class KOP_Test_WPDB {
    public $prefix = 'wpdl_';
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

require ABSPATH . 'inc/researcher-uploads.php';
$GLOBALS['kop_test_options']['kop_researcher_uploads_db'] = KOP_RUP_DB_VERSION;

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

// Clean up the scratch folder.
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
@rmdir($scratch);

echo "\n" . ($failures ? "$failures FAILED\n" : "All passed.\n");
exit($failures ? 1 : 0);
