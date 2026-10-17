<?php
/**
 * KOP Tools > Drive Sync (inc/drive-sync.php): where a run stands from its saved
 * status and the lock, the report's lines and groups, the lock seen while
 * another process holds it. Offline, WordPress stubbed.
 *
 *   php scripts/test-drive-sync.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

$theme = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kop-drive-sync-test-' . getmypid();
@mkdir($tmp . '/api', 0755, true);

define('ABSPATH', $tmp . '/');
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['opts'] = array();
function add_action() {}
function add_filter() {}
function get_stylesheet_directory() { return $GLOBALS['tmp']; }
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['opts'][$k] = $v; return true; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function admin_url($p = '') { return 'https://example.org/wp-admin/' . $p; }
function wp_date($f, $t = null) { return date($f, $t ?? time()); }
function get_userdata($id) { return $id == 7 ? (object) array('display_name' => 'Dani') : false; }
$GLOBALS['tmp'] = $tmp;

require $theme . '/inc/drive-sync.php';

$fails = 0;
$check = function ($name, $ok, $detail = '') use (&$fails) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' -- ' . $detail) . "\n";
    if (!$ok) $fails++;
};

$now = time();
$check('nothing yet is idle', kop_drive_sync_phase(array(), false, $now) === 'idle');
$check('a cron run with no status is running', kop_drive_sync_phase(array(), true, $now) === 'running');
$check('just started is starting', kop_drive_sync_phase(array('state' => 'starting', 'started' => $now - 5), false, $now) === 'starting');
$check('started and never took the lock', kop_drive_sync_phase(array('state' => 'starting', 'started' => $now - 120), false, $now) === 'nostart');
$check('started and holding the lock', kop_drive_sync_phase(array('state' => 'starting', 'started' => $now - 120), true, $now) === 'running');
$check('running with the lock', kop_drive_sync_phase(array('state' => 'running'), true, $now) === 'running');
$check('running without the lock died', kop_drive_sync_phase(array('state' => 'listing'), false, $now) === 'died');
$check('done', kop_drive_sync_phase(array('state' => 'done'), false, $now) === 'done');
$check('failed', kop_drive_sync_phase(array('state' => 'failed'), false, $now) === 'failed');

// The lock: free, then held by another process, then free again.
$check('no lock file is not running', !kop_drive_sync_running());
$lock = $tmp . '/api/.kop-sync-drive-documents.lock';
$h = fopen($lock, 'c');
flock($h, LOCK_EX);
$php = escapeshellarg(PHP_BINARY);
$probe = $tmp . '/probe.php';
file_put_contents($probe, '<?php $h = fopen($argv[1], "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "free" : "held";');
$seen = trim((string) shell_exec($php . ' -n ' . escapeshellarg($probe) . ' ' . escapeshellarg($lock)));
$check('another process sees the lock held', $seen === 'held', $seen);
flock($h, LOCK_UN);
fclose($h);
$check('lock released is not running', !kop_drive_sync_running());

// The report.
$s = array(
    'state' => 'done', 'started' => $now - 400, 'finished' => $now - 100, 'by' => 7, 'only' => 'Hyde Schools',
    'counts' => array('imported' => 2, 'not placed' => 1, 'already in the library' => 900, 'guids fixed' => 3),
    'items' => array(
        array('file' => '/star academy.pdf', 'result' => 'not placed: file sits at the top of the Drive folder'),
        array('file' => 'Hyde Schools/Handbook 1998.pdf', 'result' => 'imported: #4321 (same path)'),
        array('file' => 'Hyde Schools/Brochure.pdf', 'result' => 'imported: #4322 (new subfolder)'),
    ),
);
$html = kop_drive_sync_report_html($s, false);
$check('says who and which folder', strpos($html, 'started by Dani') !== false && strpos($html, 'only &quot;Hyde Schools&quot;') !== false, $html);
$check('says how long', strpos($html, 'took 5 minutes') !== false);
$check('counts shown, guids left out', strpos($html, '<strong>900</strong> already in the library') !== false && strpos($html, 'guids') === false);
$check('imported listed before not placed', strpos($html, 'Imported (2)') !== false && strpos($html, 'Imported (2)') < strpos($html, 'Not placed (not imported) (1)'));
$check('new attachment linked', strpos($html, 'post.php?post=4321&amp;action=edit') !== false, $html);
$check('imported group open, not placed closed', (bool) preg_match('/<details class="kop-ak-fold" open><summary>Imported/', $html)
    && (bool) preg_match('/<details class="kop-ak-fold"><summary>Not placed/', $html));
$check('leading slash dropped', strpos($html, '>star academy.pdf<') !== false);

$html = kop_drive_sync_report_html(array('state' => 'running', 'started' => $now - 30, 'by' => 0, 'listed' => 4210,
    'counts' => array('imported' => 3), 'current' => 'NATSAP/x.pdf'), true);
$check('running: nightly run, progress, current file', strpos($html, 'the nightly run') !== false && strpos($html, '3 imported so far of 4,210') !== false
    && strpos($html, 'Now: NATSAP/x.pdf') !== false, $html);
$html = kop_drive_sync_report_html(array('state' => 'failed', 'started' => $now, 'error' => 'Google refused'), false);
$check('failed says why', strpos($html, 'is-bad') !== false && strpos($html, 'Google refused') !== false);
$check('payload not active when idle', kop_drive_sync_payload()['active'] === false);

@unlink($lock);
@unlink($probe);
@rmdir($tmp . '/api');
@rmdir($tmp);
echo $fails ? "\n{$fails} FAILED\n" : "\nAll passed.\n";
exit($fails ? 1 : 0);
