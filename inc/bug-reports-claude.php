<?php
/**
 * Bug reports sent to Claude Code.
 *
 * The owner clicks "Send to Claude Code" on a Bug reports card in the Review
 * inbox (inc/review-inbox/bug-reports.php). That stamps bug_reports.claude_queued_at
 * and moves the report to In progress. A local Claude Code session running the
 * /fix-bugs skill (.claude/skills/fix-bugs/SKILL.md) reads the queue, fixes what
 * it can, pushes, and closes each report out here:
 *
 *   GET  /wp-json/kop/v1/claude/bug-reports           the queue (no reporter email, no IP hash)
 *   POST /wp-json/kop/v1/claude/bug-reports/<id>      {"outcome": "resolved"|"returned", "note": "...", "commit": "<sha>"}
 *
 * Both need the header X-KOP-Research-Key (kop_msub_is_research(), inc/mobile-submit.php).
 * "resolved" marks the report Resolved and emails a reporter who asked for updates,
 * with the note (so the note is written for the reader). "returned" leaves the status
 * at In progress, keeps Claude's note for the owner and sends no email.
 * Nothing reaches the queue without the owner's click: reports are untrusted public text.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Adds claude_queued_at to an older bug_reports table. Idempotent; MySQL only, so callers catch. */
function kop_bug_ensure_claude_column() {
    global $wpdb;
    if (!$wpdb || $wpdb->get_var("SHOW TABLES LIKE 'bug_reports'") !== 'bug_reports') {
        return;
    }
    if (!$wpdb->get_var("SHOW COLUMNS FROM bug_reports LIKE 'claude_queued_at'")) {
        $wpdb->query('ALTER TABLE bug_reports ADD COLUMN claude_queued_at DATETIME NULL DEFAULT NULL AFTER admin_note');
    }
}

function kop_bug_claude_ensure_quietly() {
    try {
        kop_bug_ensure_claude_column();
    } catch (Throwable $e) {
        // The column is there already, or this database cannot say (the SQLite tests).
    }
}

/** What the session gets for one report: everything needed to reproduce it, never the reporter's address. */
function kop_bug_claude_payload($r) {
    $r = (object) $r;
    return array(
        'id'             => (int) $r->id,
        'feature'        => (string) ($r->feature ?? ''),
        'category'       => (string) ($r->category ?? ''),
        'description'    => (string) ($r->description ?? ''),
        'steps'          => (string) ($r->steps ?? ''),
        'page_url'       => (string) ($r->page_url ?? ''),
        'page_title'     => (string) ($r->page_title ?? ''),
        'user_agent'     => (string) ($r->user_agent ?? ''),
        'viewport'       => (string) ($r->viewport ?? ''),
        'console_errors' => json_decode((string) ($r->console_errors ?? 'null'), true),
        'context'        => json_decode((string) ($r->context_json ?? 'null'), true),
        'owner_note'     => (string) ($r->admin_note ?? ''),
        'reporter_wants_updates' => !empty($r->notify_updates),
        'created_at'     => (string) ($r->created_at ?? ''),
        'queued_at'      => (string) ($r->claude_queued_at ?? ''),
    );
}

/** The waiting reports, oldest first. */
function kop_bug_claude_queue() {
    global $wpdb;
    if ($wpdb->get_var("SHOW TABLES LIKE 'bug_reports'") !== 'bug_reports') return array();
    $rows = $wpdb->get_results("SELECT * FROM bug_reports WHERE claude_queued_at IS NOT NULL AND status = 'in_progress' ORDER BY claude_queued_at ASC, id ASC LIMIT 50");
    return array_map('kop_bug_claude_payload', (array) $rows);
}

/** Puts a report in the queue (owner's click). Returns whether the reporter was emailed. */
function kop_bug_claude_send($id) {
    global $wpdb;
    kop_bug_claude_ensure_quietly();
    $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM bug_reports WHERE id = %d', (int) $id));
    if (!$existing) throw new RuntimeException('That report is gone.');
    if (!in_array((string) $existing->status, array('new', 'in_progress'), true)) {
        throw new RuntimeException('Only a new or in-progress report can go to Claude Code.');
    }
    $wpdb->update('bug_reports', array('claude_queued_at' => gmdate('Y-m-d H:i:s'), 'status' => 'in_progress'), array('id' => (int) $id));
    return kop_bug_report_notify_status_change($existing, 'in_progress', (string) $existing->status);
}

/** Takes a report out of the queue without changing its status. */
function kop_bug_claude_cancel($id) {
    global $wpdb;
    kop_bug_claude_ensure_quietly();
    $wpdb->update('bug_reports', array('claude_queued_at' => null), array('id' => (int) $id));
}

/**
 * Claude's close-out of one queued report.
 *
 * @return array {id, status, reporterNotified}
 */
function kop_bug_claude_finish($id, $outcome, $note, $commit = '') {
    global $wpdb;
    $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM bug_reports WHERE id = %d', (int) $id));
    if (!$existing) throw new RuntimeException('Report not found.');
    if (empty($existing->claude_queued_at) || (string) $existing->status !== 'in_progress') {
        throw new RuntimeException('That report is not waiting for Claude Code.');
    }
    $note = trim(mb_substr(wp_strip_all_tags((string) $note), 0, 3000));
    if ($note === '') throw new RuntimeException('A note is required.');
    $commit = preg_match('/^[0-9a-f]{7,40}$/', (string) $commit) ? (string) $commit : '';
    $stamp = 'Claude Code, ' . gmdate('Y-m-d') . ($commit !== '' ? ' (commit ' . substr($commit, 0, 12) . ')' : '') . ': ';
    $prior = trim((string) $existing->admin_note);
    $admin_note = ($prior !== '' ? $prior . "\n\n" : '') . $stamp . $note;

    if ($outcome === 'resolved') {
        $wpdb->update('bug_reports', array('status' => 'resolved', 'admin_note' => $admin_note, 'claude_queued_at' => null), array('id' => (int) $id));
        $notified = kop_bug_report_notify_status_change($existing, 'resolved', 'in_progress', $note);
        return array('id' => (int) $id, 'status' => 'resolved', 'reporterNotified' => (bool) $notified);
    }
    if ($outcome === 'returned') {
        $wpdb->update('bug_reports', array('admin_note' => $admin_note, 'claude_queued_at' => null), array('id' => (int) $id));
        return array('id' => (int) $id, 'status' => 'in_progress', 'reporterNotified' => false);
    }
    throw new RuntimeException('outcome must be "resolved" or "returned".');
}

add_action('rest_api_init', function () {
    $allowed = function (WP_REST_Request $req) {
        return function_exists('kop_msub_is_research') && kop_msub_is_research($req);
    };
    register_rest_route('kop/v1', '/claude/bug-reports', array(
        'methods'             => 'GET',
        'permission_callback' => $allowed,
        'callback'            => function () {
            kop_bug_claude_ensure_quietly();
            $resp = new WP_REST_Response(array('success' => true, 'reports' => kop_bug_claude_queue()));
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        },
    ));
    register_rest_route('kop/v1', '/claude/bug-reports/(?P<id>\d+)', array(
        'methods'             => 'POST',
        'permission_callback' => $allowed,
        'callback'            => function (WP_REST_Request $req) {
            kop_bug_claude_ensure_quietly();
            try {
                $out = kop_bug_claude_finish((int) $req['id'], (string) $req->get_param('outcome'), (string) $req->get_param('note'), (string) $req->get_param('commit'));
            } catch (RuntimeException $e) {
                return new WP_Error('kop_bug_claude', $e->getMessage(), array('status' => 400));
            }
            if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
            return array('success' => true) + $out;
        },
    ));
});
