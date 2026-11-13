<?php
/**
 * "Tell Claude" (inc/claude-feedback.php): saving a note, Claude's queue (waiting only, oldest first), Claude's
 * close-out (note required, commit kept only when it is a sha, never twice), the owner taking a note back, the
 * panel's list, and that nobody but an admin is sent the script or can write a note.
 * Runs on an empty scratch database; no mirror needed.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-claude-feedback.php
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$db_path = sys_get_temp_dir() . '/kop-claude-feedback-' . getmypid() . '.sqlite';
@unlink($db_path);
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
require_once dirname(__DIR__) . '/inc/claude-feedback.php';

$pdo->exec("CREATE TABLE wpdl_kop_claude_feedback (
    id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'waiting', message TEXT NOT NULL,
    page_url TEXT NOT NULL DEFAULT '', page_title TEXT NOT NULL DEFAULT '', feature TEXT NOT NULL DEFAULT '',
    feature_label TEXT NOT NULL DEFAULT '', target TEXT NULL, selection TEXT NULL, context TEXT NULL,
    user_id INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, closed_at TEXT NULL, claude_note TEXT NULL,
    commit_sha TEXT NOT NULL DEFAULT '')");
$GLOBALS['kop_test_options']['kop_cfb_db'] = KOP_CFB_DB_VERSION;

$fails = 0;
$checks = 0;
function ok($cond, $what) {
    global $fails, $checks;
    $checks++;
    if (!$cond) { $fails++; echo "FAIL: $what\n"; }
}
function throws($fn, $what) {
    try { $fn(); ok(false, "$what (no error)"); } catch (RuntimeException $e) { ok(true, $what); }
}

// ---- Saving ----
throws(function () { kop_cfb_add(array('message' => "  \n ")); }, 'an empty note is refused');

$a = kop_cfb_add(array(
    'message' => 'The timeline overlaps the map on phones.',
    'pageUrl' => 'https://kidsoverprofits.org/facility/example/',
    'pageTitle' => 'Example Ranch',
    'feature' => 'facility/timeline',
    'featureLabel' => 'Timeline',
    'target' => array('selector' => '#timeline > li:nth-of-type(2)', 'tag' => 'li', 'text' => '1998 opened'),
    'selection' => 'opened',
    'context' => array('viewport' => '390x844', 'errors' => array(array('type' => 'js-error', 'message' => 'x is undefined'))),
));
ok($a > 0, 'a note is saved');
$GLOBALS['wpdb']->update('wpdl_kop_claude_feedback', array('created_at' => '2026-11-13 10:00:00'), array('id' => $a));

$b = kop_cfb_add(array(
    'message' => str_repeat('long ', 3000),
    'pageUrl' => str_repeat('u', 900),
    'context' => array('blob' => str_repeat('z', 50000)),
    'target' => 'not an array',
));
$GLOBALS['wpdb']->update('wpdl_kop_claude_feedback', array('created_at' => '2026-11-13 11:00:00'), array('id' => $b));
$row = $GLOBALS['wpdb']->get_row('SELECT * FROM wpdl_kop_claude_feedback WHERE id = ' . (int) $b);
ok(mb_strlen($row->message) <= 10000, 'the message is capped');
ok(strlen($row->page_url) === 500, 'the page address is capped at 500');
ok($row->context === null, 'an oversized context is dropped, not cut into broken JSON');
ok($row->target === null, 'a target that is not an object is dropped');

// ---- Claude's queue ----
$q = kop_cfb_queue();
ok(count($q) === 2 && $q[0]['id'] === $a && $q[1]['id'] === $b, 'the queue is the waiting notes, oldest first');
ok($q[0]['target']['selector'] === '#timeline > li:nth-of-type(2)', 'the pointed-at element comes back as an object');
ok($q[0]['context']['errors'][0]['message'] === 'x is undefined', 'the page errors come back');
ok($q[0]['feature'] === 'facility/timeline' && $q[0]['feature_label'] === 'Timeline', 'the feature comes back');

// ---- Claude closes a note ----
throws(function () use ($a) { kop_cfb_close($a, 'done', '   '); }, 'Claude must leave a note');
throws(function () use ($a) { kop_cfb_close($a, 'withdrawn', 'x'); }, 'Claude cannot take a note back');
throws(function () use ($a) { kop_cfb_close($a, 'resolved', 'x'); }, 'an unknown outcome is refused');
$done = kop_cfb_close($a, 'done', 'Moved the map <b>below</b> the timeline under 640px.', 'd1adc158a1b2');
ok($done['status'] === 'done' && $done['commit'] === 'd1adc158a1b2', 'done keeps the commit');
ok($done['claude_note'] === 'Moved the map below the timeline under 640px.', 'tags are stripped from the note');
ok($done['closed_at'] !== '', 'closing stamps closed_at');
throws(function () use ($a) { kop_cfb_close($a, 'returned', 'again'); }, 'a closed note cannot be closed again');

$ret = kop_cfb_close($b, 'returned', 'Which page did you mean?', 'not-a-sha; rm -rf');
ok($ret['status'] === 'returned' && $ret['commit'] === '', 'a commit that is not a sha is dropped');
ok(count(kop_cfb_queue()) === 0, 'closed notes leave the queue');

// ---- The owner takes one back ----
$c = kop_cfb_add(array('message' => 'Never mind this one.'));
throws(function () use ($c) { kop_cfb_close($c, 'done', 'x', '', true); }, 'the owner can only take a note back');
$w = kop_cfb_close($c, 'withdrawn', '', '', true);
ok($w['status'] === 'withdrawn' && $w['claude_note'] === '', 'the owner takes a waiting note back');
throws(function () use ($a) { kop_cfb_close($a, 'withdrawn', '', '', true); }, 'a note Claude closed cannot be taken back');

// ---- The panel's list ----
$recent = kop_cfb_recent();
ok(count($recent) === 3 && $recent[0]['id'] === $c, 'the list is newest first, every state');
ok(!array_key_exists('context', $recent[0]) && !array_key_exists('target', $recent[0]), 'the list leaves out the captured context');

// ---- Admins only ----
$src = file_get_contents(dirname(__DIR__) . '/inc/claude-feedback.php');
ok(substr_count($src, 'kop_cfb_enqueue(') === 3, 'the script is enqueued from exactly two hooks (plus its definition)');
ok(preg_match_all('/!kop_cfb_can\(\)\) return;\s*\n\s*kop_cfb_enqueue\(/', $src) === 2, 'both hooks check kop_cfb_can() first');
ok(preg_match("/'\\/claude\\/feedback', array\\(\\s*array\\(\\s*'methods'\\s*=> 'GET',\\s*'permission_callback' => \\\$is_research/", $src) === 1, "Claude's queue needs the research key");
ok(preg_match("/'methods'\\s*=> 'POST',\\s*'permission_callback' => 'kop_cfb_can'/", $src) === 1, 'writing a note needs an admin');
ok(KOP_CFB_CAP === 'manage_options', 'admin means manage_options');
ok(kop_cfb_can() === false, 'a visitor is not an admin');

$bug = file_get_contents(dirname(__DIR__) . '/js/bug-reporter.js');
ok(strpos($bug, ':scope > .kop-feature-actions > .kop-bug-report-link') !== false, 'the bug reporter knows a flag moved into the Claude row is already attached');
ok(strpos($bug, "setAttribute('data-kop-feature', feature)") !== false, 'every flag carries its feature for the Claude button');

echo $fails ? "\n$fails of $checks checks failed.\n" : "All $checks checks passed.\n";
exit($fails ? 1 : 0);
