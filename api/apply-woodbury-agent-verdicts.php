<?php
/**
 * Woodbury Facts decisions made by AI readers (owner, 2026-10-25: agents work
 * through the waiting items). From ~/kop-import/woodbury/agent-verdicts.json, a
 * list of {pkey, action, fid?, why, value?}:
 *
 *   match   an item with no record is that record (fid): it moves onto it and
 *           keeps waiting, "Matched by the AI reader: <why>" as its close-name note
 *   note    a recommendation for the owner, shown on the card ("AI reader: ...");
 *           the item keeps waiting
 *   add     added to its record as the owner's Add does (held for the write-up
 *           when holding is on); a conflict keeps waiting, marked
 *   reject  filed as rejected, "Rejected by the AI reader: <why>"
 *
 * Each decision is applied only while the item is waiting and nobody has
 * touched it, and (when the decision carries `value`) still says what it said
 * when it was read. Clear / trim / duplicate verdicts go through
 * api/apply-woodbury-same-info.php instead.
 *
 * CLI only, on the server (ea-php82):
 *   php api/apply-woodbury-agent-verdicts.php [file]          dry run: counts
 *   php api/apply-woodbury-agent-verdicts.php [file] apply    apply (log: ~/kop-import/woodbury/agent-verdicts-applied.json)
 *   php api/apply-woodbury-agent-verdicts.php undo apply      put every applied one back as it was
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
if (!defined('ABSPATH') || !function_exists('kop_wbf_apply')) {
    fwrite(STDERR, "WordPress did not load\n");
    exit(2);
}
global $wpdb;
kop_wbf_ensure_table();
$home = rtrim((string) getenv('HOME'), '/');
$log_path = $home . '/kop-import/woodbury/agent-verdicts-applied.json';
$table = kop_wbf_table();
$now = current_time('mysql', true);

$set_note = function (array $r, $note) use ($wpdb, $table) {
    $extra = json_decode((string) $r['extra'], true) ?: array();
    if ($note === null) unset($extra['ai_note']);
    else $extra['ai_note'] = (string) $note;
    $wpdb->update($table, array('extra' => $extra ? wp_json_encode($extra) : null), array('pkey' => $r['pkey']));
};

if ($undo) {
    $log = is_file($log_path) ? (json_decode((string) file_get_contents($log_path), true) ?: array()) : array();
    $n = array('match' => 0, 'note' => 0, 'add' => 0, 'reject' => 0, 'changed since' => 0);
    foreach (array_reverse($log) as $e) {
        $r = kop_wbf_rows(array($e['pkey']))[0] ?? null;
        if (!$r) {
            $n['changed since']++;
            continue;
        }
        if ($e['action'] === 'match' && $r['status'] === 'pending' && (int) $r['facility_id'] === (int) $e['fid'] && $r['match_kind'] === 'ai') {
            if ($apply) {
                $wpdb->update($table, array('facility_id' => 0, 'match_kind' => $e['old']['match_kind'], 'match_note' => $e['old']['match_note'],
                    'alternatives' => $e['old']['alternatives']), array('pkey' => $r['pkey']));
                $set_note($r, null);
            }
            $n['match']++;
        } elseif ($e['action'] === 'note' && $r['status'] === 'pending') {
            if ($apply) $set_note($r, null);
            $n['note']++;
        } elseif (in_array($e['action'], array('add', 'reject'), true) && $r['reviewed_by'] === 'ai' && in_array($r['status'], array('held', 'applied', 'rejected'), true)) {
            if ($apply) {
                kop_wbf_undo(array($r), 'ai');
                $wpdb->update($table, array('reviewed_by' => null, 'reviewed_at' => null, 'auto' => (int) ($e['old']['auto'] ?? 0)), array('pkey' => $r['pkey']));
                $set_note(kop_wbf_rows(array($r['pkey']))[0], null);
            }
            $n[$e['action']]++;
        } else {
            $n['changed since']++;
        }
    }
    if ($apply) {
        @rename($log_path, $log_path . '.undone-' . gmdate('Ymd-His'));
        if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
    }
    echo ($apply ? 'Undone: ' : 'Would undo: ') . json_encode($n) . "\n";
    exit(0);
}

$file = $files[0] ?? $home . '/kop-import/woodbury/agent-verdicts.json';
$decisions = json_decode((string) @file_get_contents($file), true);
if (!is_array($decisions)) {
    fwrite(STDERR, "No decisions in $file\n");
    exit(2);
}

$n = array('match' => 0, 'note' => 0, 'add' => 0, 'reject' => 0, 'add: conflict' => 0, 'add: already there' => 0,
    'not waiting' => 0, 'changed' => 0, 'no record' => 0, 'error' => 0);
$log = $apply && is_file($log_path) ? (json_decode((string) file_get_contents($log_path), true) ?: array()) : array();
$adds = array();
foreach ($decisions as $d) {
    $r = kop_wbf_rows(array((string) ($d['pkey'] ?? '')))[0] ?? null;
    $extra = $r ? (json_decode((string) $r['extra'], true) ?: array()) : array();
    if (!$r || $r['status'] !== 'pending' || trim((string) $r['reviewed_by']) !== '' || !empty($extra['edited_by'])) {
        $n['not waiting']++;
        continue;
    }
    if (array_key_exists('value', $d) && json_encode(kop_wbf_row_value($r)) !== json_encode($d['value'])) {
        $n['changed']++;
        continue;
    }
    $why = rtrim(trim((string) ($d['why'] ?? '')), '.');
    $old = array('match_kind' => $r['match_kind'], 'match_note' => $r['match_note'], 'alternatives' => $r['alternatives'], 'auto' => (int) $r['auto']);
    try {
        switch ($d['action'] ?? '') {
            case 'match':
                $fid = (int) ($d['fid'] ?? 0);
                if ((int) $r['facility_id'] !== 0 || $fid <= 0 || !kop_facility_load($fid, kop_wbf_opts())) {
                    $n[(int) $r['facility_id'] !== 0 ? 'changed' : 'no record']++;
                    continue 2;
                }
                if ($apply) {
                    $wpdb->update($table, array('facility_id' => $fid, 'match_kind' => 'ai', 'alternatives' => null,
                        'match_note' => 'Matched by the AI reader: ' . $why . '.'), array('pkey' => $r['pkey'], 'status' => 'pending'));
                    $log[] = array('pkey' => $r['pkey'], 'action' => 'match', 'fid' => $fid, 'old' => $old, 'at' => $now);
                }
                $n['match']++;
                break;
            case 'note':
                if ($apply) {
                    $set_note($r, $why . '.');
                    $log[] = array('pkey' => $r['pkey'], 'action' => 'note', 'old' => $old, 'at' => $now);
                }
                $n['note']++;
                break;
            case 'reject':
                if ($apply) {
                    $wpdb->update($table, array('status' => 'rejected', 'auto' => 0, 'reviewed_by' => 'ai', 'reviewed_at' => $now,
                        'applied' => wp_json_encode(array('reason' => 'Rejected by the AI reader: ' . $why . '.', 'cleared' => 'ai_verdict'))),
                        array('pkey' => $r['pkey'], 'status' => 'pending'));
                    $log[] = array('pkey' => $r['pkey'], 'action' => 'reject', 'old' => $old, 'at' => $now);
                }
                $n['reject']++;
                break;
            case 'add':
                // Only onto the record the item names: moving it is a match first.
                if ((int) $r['facility_id'] <= 0 || (isset($d['fid']) && (int) $d['fid'] !== (int) $r['facility_id'])) {
                    $n['changed']++;
                    continue 2;
                }
                $adds[(int) $r['facility_id']][] = array($r, $why, $old);
                break;
            default:
                $n['error']++;
        }
    } catch (Throwable $e) {
        $n['error']++;
        fwrite(STDERR, $r['pkey'] . ': ' . $e->getMessage() . "\n");
    }
}
foreach ($adds as $fid => $list) {
    if (!$apply) {
        $n['add'] += count($list);
        continue;
    }
    try {
        $results = kop_wbf_apply(array_map(function ($x) { return $x[0]; }, $list), $fid, 'ai');
    } catch (Throwable $e) {
        $n['error'] += count($list);
        fwrite(STDERR, "#$fid: " . $e->getMessage() . "\n");
        continue;
    }
    foreach ($list as $x) {
        list($r, $why, $old) = $x;
        $res = $results[$r['pkey']] ?? array();
        if (!empty($res['ok'])) {
            $set_note(kop_wbf_rows(array($r['pkey']))[0], 'Added by the AI reader: ' . $why . '.');
            $log[] = array('pkey' => $r['pkey'], 'action' => 'add', 'old' => $old, 'at' => $now);
            $n['add']++;
        } elseif (!empty($res['conflict'])) {
            $n['add: conflict']++;
        } else {
            // "Already there": kop_wbf_apply filed it as rejected under the reviewer 'ai'; Undo puts it back.
            $log[] = array('pkey' => $r['pkey'], 'action' => 'reject', 'old' => $old, 'at' => $now);
            $n['add: already there']++;
        }
    }
}
if ($apply) {
    @mkdir(dirname($log_path), 0755, true);
    file_put_contents($log_path, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
}
echo ($apply ? 'Applied: ' : 'Would apply: ') . json_encode($n) . "\n";
