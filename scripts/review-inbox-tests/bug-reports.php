<?php
/**
 * scripts/test-review-inbox.php checks for the 'bug-reports' source
 * (inc/review-inbox/bug-reports.php over inc/bug-report-notify.php). Mail is
 * caught, never sent, so the reporter email the old screen sends is checked.
 */

require_once dirname(__DIR__, 2) . '/inc/bug-report-notify.php';
require_once dirname(__DIR__, 2) . '/inc/bug-reports-claude.php';
if (!function_exists('wp_mail')) {
    function wp_mail($to, $subject, $body) { $GLOBALS['kop_test_mail'][] = array($to, $subject, $body); return true; }
}
if (!function_exists('is_email')) {
    function is_email($s) { return filter_var((string) $s, FILTER_VALIDATE_EMAIL) ? $s : false; }
}

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec('CREATE TABLE IF NOT EXISTS bug_reports (id INTEGER PRIMARY KEY, feature TEXT, category TEXT, description TEXT, steps TEXT, contact TEXT,
        notify_updates INTEGER, page_url TEXT, page_title TEXT, user_agent TEXT, viewport TEXT, console_errors TEXT, context_json TEXT, ip_hash TEXT,
        status TEXT, admin_note TEXT, claude_queued_at TEXT, created_at TEXT, updated_at TEXT)');
    // A mirror copied before the Claude Code queue existed has no claude_queued_at (MySQL adds it on first use).
    try {
        $pdo->exec('ALTER TABLE bug_reports ADD COLUMN claude_queued_at TEXT');
    } catch (PDOException $e) {
        // Already there.
    }
    $now = gmdate('Y-m-d H:i:s', time() + 60);
    $pdo->prepare("INSERT INTO bug_reports (feature, category, description, steps, contact, notify_updates, page_url, user_agent, viewport, console_errors, status, created_at, updated_at)
                   VALUES ('Inbox test', 'load-failed', 'The inbox test page did not load.', 'Open it.', 'reader@example.test', 1, 'https://example.test/page/',
                   'TestBrowser', '390x800', ?, 'new', ?, ?)")
        ->execute(array(json_encode(array(array('type' => 'error', 'message' => 'x is undefined'))), $now, $now));
})();

function kop_rinbox_test_bug_reports(array $src, array $item, callable $check) {
    global $wpdb;
    $row = function () use ($wpdb, $item) {
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM bug_reports WHERE id = %d', (int) $item['key']));
    };
    $check('bug-reports: the waiting item is the new test report', $item['title'] === 'The inbox test page did not load.' && strpos($item['text'], 'x is undefined') !== false);

    call_user_func($src['save'], $item['key'], array('category' => 'broken-ui', 'feature' => 'Inbox'));
    $r = $row();
    $check('bug-reports: save changes category and feature', $r->category === 'broken-ui' && $r->feature === 'Inbox');
    try {
        call_user_func($src['save'], $item['key'], array('category' => 'typo'));
        $check('bug-reports: an unknown category is refused', false);
    } catch (RuntimeException $e) {
        $check('bug-reports: an unknown category is refused', true, $e->getMessage());
    }

    $GLOBALS['kop_test_mail'] = array();
    $res = call_user_func($src['act'], $item['key'], 'in_progress', array());
    $mail = $GLOBALS['kop_test_mail'];
    $check('bug-reports: start working sets the status', $row()->status === 'in_progress', $res['message']);
    $check('bug-reports: the reporter who asked for updates is emailed, without their own text',
        count($mail) === 1 && $mail[0][0] === 'reader@example.test' && strpos($mail[0][2], 'did not load') === false && strpos($res['message'], 'emailed') !== false);
    $res = call_user_func($src['act'], $item['key'], 'resolved', array());
    $check('bug-reports: resolved', $row()->status === 'resolved', $res['message']);
    call_user_func($src['act'], $item['key'], 'new', array());
    $check('bug-reports: back to new', $row()->status === 'new');
    $again = kop_rinbox_get_item('bug-reports', $item['key']);
    $check('bug-reports: a new report offers the three other statuses and Send to Claude Code', array_column($again['actions'], 'id') === array('in_progress', 'resolved', 'dismissed', 'claude'));
    $tech = array_column($again['details'], 'value', 'label');
    $check('bug-reports: the technical details list the browser and every error', isset($tech['Browser']) && in_array('[error] x is undefined', (array) ($tech['Errors (1)'] ?? array()), true), json_encode($tech));
    $counts = call_user_func($src['view_counts'], array());
    $check('bug-reports: every tab has a count', array_keys($counts) === array_keys($src['views']) && $counts['new'] >= 1, json_encode($counts));
    $check('bug-reports: All lists every report', call_user_func($src['list'], array('view' => 'all', 'search' => '', 'offset' => 0, 'limit' => 1))['total'] === $counts['all']);
    // Claude Code's queue (inc/bug-reports-claude.php).
    $GLOBALS['kop_test_mail'] = array();
    $res = call_user_func($src['act'], $item['key'], 'claude', array());
    $r = $row();
    $check('bug-reports: Send to Claude Code queues it and moves it to In progress', !empty($r->claude_queued_at) && $r->status === 'in_progress', $res['message']);
    $check('bug-reports: sending a new report emails the reporter it is in progress', count($GLOBALS['kop_test_mail']) === 1);
    $queued = kop_rinbox_get_item('bug-reports', $item['key']);
    $check('bug-reports: a queued report offers Take back from Claude, not Send', in_array('claude_cancel', array_column($queued['actions'], 'id'), true) && !in_array('claude', array_column($queued['actions'], 'id'), true));
    $q = array_values(array_filter(kop_bug_claude_queue(), function ($p) use ($item) { return $p['id'] === (int) $item['key']; }));
    $check('bug-reports: the queue lists it, without the reporter email', count($q) === 1 && strpos(json_encode($q[0]), 'reader@example.test') === false
        && $q[0]['console_errors'][0]['message'] === 'x is undefined', json_encode($q));

    $GLOBALS['kop_test_mail'] = array();
    $out = kop_bug_claude_finish($item['key'], 'returned', 'Could not reproduce on a phone width; needs the owner.');
    $r = $row();
    $check('bug-reports: returned leaves it In progress, out of the queue, with the note, no email',
        $out['status'] === 'in_progress' && empty($r->claude_queued_at) && $r->status === 'in_progress' && strpos($r->admin_note, 'Could not reproduce') !== false && !$GLOBALS['kop_test_mail']);
    try {
        kop_bug_claude_finish($item['key'], 'resolved', 'x');
        $check('bug-reports: a report not in the queue cannot be closed out by Claude', false);
    } catch (RuntimeException $e) {
        $check('bug-reports: a report not in the queue cannot be closed out by Claude', true, $e->getMessage());
    }

    call_user_func($src['act'], $item['key'], 'claude', array());
    try {
        kop_bug_claude_finish($item['key'], 'resolved', '   ');
        $check('bug-reports: Claude must leave a note', false);
    } catch (RuntimeException $e) {
        $check('bug-reports: Claude must leave a note', true);
    }
    $GLOBALS['kop_test_mail'] = array();
    $out = kop_bug_claude_finish($item['key'], 'resolved', 'The page loads again: a missing script dependency was added.', 'abcdef1234567');
    $r = $row();
    $mail = $GLOBALS['kop_test_mail'];
    $check('bug-reports: resolved marks it Resolved, keeps both notes and the commit',
        $r->status === 'resolved' && empty($r->claude_queued_at) && strpos($r->admin_note, 'Could not reproduce') !== false && strpos($r->admin_note, 'commit abcdef123456') !== false, (string) $r->admin_note);
    $check('bug-reports: resolved emails the reporter with the note', $out['reporterNotified'] && count($mail) === 1 && strpos($mail[0][2], 'missing script dependency') !== false);

    call_user_func($src['act'], $item['key'], 'new', array());
    call_user_func($src['act'], $item['key'], 'claude', array());
    call_user_func($src['act'], $item['key'], 'claude_cancel', array());
    $check('bug-reports: Take back from Claude empties the queue and keeps In progress', empty($row()->claude_queued_at) && $row()->status === 'in_progress');
    call_user_func($src['act'], $item['key'], 'claude', array());
    call_user_func($src['act'], $item['key'], 'dismissed', array());
    $check('bug-reports: dismissing by hand takes it out of the queue', empty($row()->claude_queued_at) && !kop_bug_claude_queue());
    call_user_func($src['act'], $item['key'], 'new', array());

    try {
        call_user_func($src['act'], $item['key'], 'delete', array());
        $check('bug-reports: an unknown action is refused', false);
    } catch (RuntimeException $e) {
        $check('bug-reports: an unknown action is refused', true, $e->getMessage());
    }
}
