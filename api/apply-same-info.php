<?php
/**
 * Waiting items an AI reader found to say only what KOP already holds, in
 * other words (each verdict quotes what it rests on, checked before it gets
 * here), for three review queues (Woodbury Facts has its own,
 * apply-woodbury-same-info.php):
 *
 *   fornits     Fornits staff, incidents and leads already on the facility record: filed as skipped,
 *               with the reason ("Back to review" undoes one)
 *   milestones  company milestones the company's timeline already shows: Left off ("Back to waiting")
 *   wiki        drafted wiki-update lines the entry already says: left out of the update
 *               (put back by editing the card, or by undo here)
 *
 * Input: ~/kop-import/same-info/<queue>.json. Each decision is applied only while the item waits as it was read.
 *
 * CLI only, on the server (ea-php82):
 *   php api/apply-same-info.php <queue> [apply]       dry run without apply
 *   php api/apply-same-info.php <queue> undo apply    put every applied one back
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$queue = $argv[1] ?? '';
$apply = in_array('apply', $argv, true);
$undo = in_array('undo', $argv, true);
if (!in_array($queue, array('fornits', 'milestones', 'wiki'), true)) {
    fwrite(STDERR, "Queue: fornits, milestones or wiki\n");
    exit(2);
}
set_time_limit(0);
$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH')) {
    fwrite(STDERR, "WordPress did not load\n");
    exit(2);
}
global $wpdb;
$home = rtrim((string) getenv('HOME'), '/');
$in_path = $home . '/kop-import/same-info/' . $queue . '.json';
$log_path = $home . '/kop-import/same-info/' . $queue . '-applied.json';
$log = is_file($log_path) ? (json_decode((string) file_get_contents($log_path), true) ?: array()) : array();
$now = current_time('mysql', true);
$n = array('applied' => 0, 'not waiting' => 0, 'changed' => 0, 'undone' => 0, 'error' => 0);

if ($queue === 'fornits') {
    kop_fornits_ensure_tables();
    $t = kop_fornits_items_table();
    if ($undo) {
        foreach ($log as $e) {
            $r = kop_fornits_rows(array($e['pkey']))[0] ?? null;
            $done = $r ? (json_decode((string) $r['applied'], true) ?: array()) : array();
            if ($r && $r['status'] === 'rejected' && ($done['cleared'] ?? '') === 'ai_same_info') {
                if ($apply) $wpdb->update($t, array('status' => 'pending', 'applied' => null, 'reviewed_by' => null, 'reviewed_at' => null), array('pkey' => $r['pkey']));
                $n['undone']++;
            } else {
                $n['changed']++;
            }
        }
    } else {
        foreach ((array) json_decode((string) @file_get_contents($in_path), true) as $d) {
            $r = kop_fornits_rows(array((string) $d['pkey']))[0] ?? null;
            if (!$r || $r['status'] !== 'pending' || trim((string) $r['reviewed_by']) !== '') { $n['not waiting']++; continue; }
            if (json_encode(json_decode((string) $r['value'], true)) !== json_encode($d['value'])) { $n['changed']++; continue; }
            $q = trim((string) (($d['quotes'] ?? array())[0] ?? ''));
            $reason = 'Already on the record, in other words (read by AI): ' . rtrim((string) $d['why'], '.') . '.' . ($q !== '' ? ' The record says: "' . mb_substr($q, 0, 200) . '"' : '');
            if ($apply) {
                $wpdb->update($t, array('status' => 'rejected', 'reviewed_by' => 'ai', 'reviewed_at' => $now,
                    'applied' => wp_json_encode(array('reason' => $reason, 'cleared' => 'ai_same_info'))), array('pkey' => $r['pkey'], 'status' => 'pending'));
                $log[] = array('pkey' => $r['pkey']);
            }
            $n['applied']++;
        }
    }
}

if ($queue === 'milestones') {
    $decisions = kop_ptl_ms_decisions();
    if ($undo) {
        foreach ($log as $e) {
            if (($decisions[$e['key']]['by'] ?? '') === 'ai' && ($decisions[$e['key']]['decision'] ?? '') === 'rejected') {
                unset($decisions[$e['key']]);
                $n['undone']++;
            } else {
                $n['changed']++;
            }
        }
    } else {
        foreach ((array) json_decode((string) @file_get_contents($in_path), true) as $d) {
            $key = (string) $d['key'];
            if (!kop_ptl_ms_get($key) || kop_rinbox_ms_view($key) !== 'pending') { $n['not waiting']++; continue; }
            $decisions[$key] = array('decision' => 'rejected', 'by' => 'ai', 'at' => current_time('mysql'),
                'why' => 'Already on the timeline (read by AI): ' . (string) $d['why']);
            if ($apply) $log[] = array('key' => $key);
            $n['applied']++;
        }
    }
    if ($apply) {
        update_option('kop_company_milestones_review', $decisions, false);
        kop_rinbox_ms_purge();
    }
}

if ($queue === 'wiki') {
    $rows = kop_rinbox_wchg_rows();
    if ($undo) {
        foreach ($log as $e) {
            $r = $rows[(int) $e['entry']] ?? null;
            $edits = $r ? kop_wiki_auto_edits($r['id']) : array();
            if ($r && $r['view'] === 'review' && array_key_exists($e['id'], $edits) && trim((string) $edits[$e['id']]) === '') {
                unset($edits[$e['id']]);
                if ($apply) kop_wiki_auto_save_edits($r['id'], $edits);
                $n['undone']++;
            } else {
                $n['changed']++;
            }
        }
    } else {
        $by = array();
        foreach ((array) json_decode((string) @file_get_contents($in_path), true) as $d) $by[(int) $d['entry']][] = $d;
        foreach ($by as $eid => $list) {
            $r = $rows[$eid] ?? null;
            if (!$r || $r['view'] !== 'review') { $n['not waiting'] += count($list); continue; }
            $edits = kop_wiki_auto_edits($r['id']);
            $ops = array();
            foreach ((array) ($r['draft']['ops'] ?? array()) as $op) $ops[(string) $op['id']] = $op;
            $done = array();
            foreach ($list as $d) {
                $op = $ops[(string) $d['id']] ?? null;
                $text = $op ? (($op['op'] ?? '') === 'set_header_years' ? (string) $op['years'] : (string) ($op['text'] ?? '')) : null;
                if ($op === null || array_key_exists($d['id'], $edits) || trim($text) !== trim((string) $d['line'])) { $n['changed']++; continue; }
                $edits[$d['id']] = '';
                $done[] = array('entry' => $eid, 'id' => (string) $d['id']);
            }
            if (!$done) continue;
            $b = kop_wiki_drafts_build_draft($r['draft'], $r['row']['markdown'], $edits);
            if ($b['problems']) { $n['error'] += count($done); fwrite(STDERR, "#$eid: " . implode('; ', $b['problems']) . "\n"); continue; }
            if ($apply) {
                kop_wiki_auto_save_edits($r['id'], $edits);
                $log = array_merge($log, $done);
            }
            $n['applied'] += count($done);
        }
    }
}

if ($apply) {
    @mkdir(dirname($log_path), 0755, true);
    file_put_contents($log_path, json_encode($undo ? array() : $log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
}
echo ($apply ? '' : 'Dry run: ') . $queue . ' ' . json_encode($n) . "\n";
