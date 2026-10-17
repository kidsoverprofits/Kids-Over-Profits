<?php
/**
 * Woodbury Facts items an AI reader found to say only what the record already
 * says, in other words or several facts run together (a reading of every
 * waiting item next to its record; each verdict quotes the record lines it
 * rests on, checked before it gets here). From
 * ~/kop-import/woodbury/same-info.json, a list of decisions:
 *
 *   clear  everything it says is on the record: filed as rejected "Already on
 *          the record", with the quote ("Back to review" undoes one)
 *   trim   part is new: the item is cut to that part and keeps waiting
 *          ("Back to what Woodbury said" undoes one)
 *   dup    says nothing another waiting item on the record does not
 *
 * Each decision is applied only while the item is still waiting exactly as it
 * was read, and every quote is still on the record.
 *
 * CLI only, on the server (ea-php82):
 *   php api/apply-woodbury-same-info.php [file]          dry run: counts
 *   php api/apply-woodbury-same-info.php [file] apply    apply (log: ~/kop-import/woodbury/same-info-applied.json)
 *   php api/apply-woodbury-same-info.php undo apply      put every applied one back as it was
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$args = array_slice($argv, 1);
$apply = in_array('apply', $args, true);
$undo = in_array('undo', $args, true);
$files = array_values(array_filter($args, function ($a) { return !in_array($a, array('apply', 'undo'), true); }));
set_time_limit(0);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_wbf_edit')) {
    fwrite(STDERR, "WordPress did not load\n");
    exit(2);
}
global $wpdb;
kop_wbf_ensure_table();
$home = rtrim((string) getenv('HOME'), '/');
$log_path = $home . '/kop-import/woodbury/same-info-applied.json';
$table = kop_wbf_table();
$now = current_time('mysql', true);

if ($undo) {
    $log = is_file($log_path) ? (json_decode((string) file_get_contents($log_path), true) ?: array()) : array();
    $n = array('cleared back' => 0, 'trim reset' => 0, 'changed since' => 0);
    foreach ($log as $e) {
        $r = kop_wbf_rows(array($e['pkey']))[0] ?? null;
        $extra = $r ? (json_decode((string) $r['extra'], true) ?: array()) : array();
        $done = $r ? (json_decode((string) $r['applied'], true) ?: array()) : array();
        if ($r && $e['action'] !== 'trim' && $r['status'] === 'rejected' && ($done['cleared'] ?? '') === 'ai_same_info') {
            if ($apply) $wpdb->update($table, array('status' => 'pending', 'applied' => null, 'reviewed_by' => null, 'reviewed_at' => null), array('pkey' => $r['pkey']));
            $n['cleared back']++;
        } elseif ($r && $e['action'] === 'trim' && $r['status'] === 'pending' && ($extra['edited_by'] ?? '') === 'ai') {
            if ($apply) kop_wbf_edit_reset($r);
            $n['trim reset']++;
        } else {
            $n['changed since']++;
        }
    }
    echo ($apply ? 'Undone: ' : 'Would undo: ') . json_encode($n) . "\n";
    exit(0);
}

$file = $files[0] ?? $home . '/kop-import/woodbury/same-info.json';
$decisions = json_decode((string) @file_get_contents($file), true);
if (!is_array($decisions)) {
    fwrite(STDERR, "No decisions in $file\n");
    exit(2);
}

$alnum = function ($s) { return preg_replace('/[^a-z0-9]+/', '', strtolower(remove_accents((string) $s))); };
$docs = array();
$doc_for = function ($fid) use (&$docs) {
    if (!array_key_exists($fid, $docs)) {
        $stored = kop_facility_load((int) $fid, kop_wbf_opts());
        $docs[$fid] = $stored ? $stored['doc'] : null;
    }
    return $docs[$fid];
};
$labels = array();
foreach ($decisions as $d) {
    if (($d['action'] ?? '') === 'dup') $labels[$d['of']] = true;
}
if ($labels) {
    foreach (kop_wbf_rows(array_keys($labels)) as $o) $labels[$o['pkey']] = $o;
}

$n = array('clear' => 0, 'trim' => 0, 'dup' => 0, 'not waiting' => 0, 'changed' => 0, 'quote gone' => 0, 'no entry' => 0, 'error' => 0);
$log = $apply && is_file($log_path) ? (json_decode((string) file_get_contents($log_path), true) ?: array()) : array();
foreach ($decisions as $d) {
    $r = kop_wbf_rows(array((string) $d['pkey']))[0] ?? null;
    $extra = $r ? (json_decode((string) $r['extra'], true) ?: array()) : array();
    if (!$r || $r['status'] !== 'pending' || trim((string) $r['reviewed_by']) !== '' || !empty($extra['edited_by'])) {
        $n['not waiting']++;
        continue;
    }
    if (json_encode(kop_wbf_row_value($r)) !== json_encode($d['value'])) {
        $n['changed']++;
        continue;
    }
    $doc = $doc_for((int) $r['facility_id']);
    if (!$doc) {
        $n['changed']++;
        continue;
    }
    // Every quote is still on the record (its field name left out: the batch named fields in words).
    $text = $alnum(json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    // A staff line was shown as "name | role | past jobs": each part is its own field on the record.
    foreach ((array) ($d['quotes'] ?? array()) as $quote_line) {
        foreach (explode('|', (string) $quote_line) as $q) {
            $q = $alnum(preg_replace('/^\s*"[^"]+"\s*:\s*/', '', $q));
            if ($q !== '' && strpos($text, $q) === false) {
                $n['quote gone']++;
                if (getenv('KOP_DEBUG')) fwrite(STDERR, $r['pkey'] . ' quote gone: ' . $quote_line . "\n");
                continue 3;
            }
        }
    }
    $quote = trim((string) (($d['quotes'] ?? array())[0] ?? ''));
    try {
        if ($d['action'] === 'clear' || $d['action'] === 'dup') {
            $reason = $d['action'] === 'clear'
                ? 'Already on the record, in other words (read by AI): ' . rtrim((string) $d['why'], '.') . '.' . ($quote !== '' ? ' The record says: "' . mb_substr($quote, 0, 200) . '"' : '')
                : 'Says nothing that "' . (is_array($labels[$d['of']] ?? null) ? $labels[$d['of']]['label'] : 'another waiting item') . '" (still waiting) does not.';
            if ($apply) {
                $wpdb->update($table, array('status' => 'rejected', 'auto' => 0, 'reviewed_by' => 'ai', 'reviewed_at' => $now,
                    'applied' => wp_json_encode(array('reason' => $reason, 'cleared' => 'ai_same_info', 'quotes' => $d['quotes'] ?? array()))),
                    array('pkey' => $r['pkey'], 'status' => 'pending'));
            }
        } elseif ($d['action'] === 'trim') {
            $form = array();
            foreach (kop_wbf_edit_fields($r) as $fd) $form[$fd[0]] = (string) $fd[3];
            if ($r['op'] === 'add_staff') {
                $v = kop_wbf_row_value($r);
                $entry = null;
                foreach (kop_wbf_staff_all($doc) as $s) {
                    if (kop_wbf_same_person((string) ($v['name'] ?? ''), (string) ($s['name'] ?? ''))) { $entry = $s; break; }
                }
                if (!$entry) {
                    $n['no entry']++;
                    continue;
                }
                // The role stays only when it says something new; else the entry's own role, which Add leaves as it is.
                if (empty($d['role_new'])) $form['role'] = (string) ($entry['role'] ?? '');
                $form['pastJobs'] = implode('; ', (array) $d['new_jobs']);
            } else {
                $form['text'] = (string) $d['new_text'];
            }
            if ($apply) kop_wbf_edit($r, $form, 'ai');
        }
        $n[$d['action']]++;
        if ($apply) $log[] = array('pkey' => $r['pkey'], 'action' => $d['action'], 'at' => $now);
    } catch (Throwable $e) {
        $n['error']++;
        fwrite(STDERR, $r['pkey'] . ': ' . $e->getMessage() . "\n");
    }
}
if ($apply) {
    @mkdir(dirname($log_path), 0755, true);
    file_put_contents($log_path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
}
echo ($apply ? 'Applied: ' : 'Would apply: ') . json_encode($n) . "\n";
