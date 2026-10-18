<?php
/**
 * Tidy every facility record at once (owner, 2026-10-18; docs/PLAN.md 3.36): the copies earlier approvals and
 * merges left (one person twice, roles carrying their source, a name twice, one event told twice), with the rules
 * of api/lib-record-dedupe.php, each record through kop_rc_apply() (inc/record-cleanup.php), so every one keeps
 * its own Undo on Review inbox > Record cleanup > Tidied. Before writing, every record it touches is saved to
 * ~/kop-backups/record-tidy-<time>.json.
 *
 * CLI only, on the server (ea-php82), from the theme directory:
 *   php api/tidy-records.php              dry run: how many records and changes
 *   php api/tidy-records.php --list       ...every change
 *   php api/tidy-records.php apply        tidy them (reviewer "tidy-records")
 *   php api/tidy-records.php undo apply   put back every record this script tidied that nobody changed since
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$apply = in_array('apply', $argv, true);
$undo = in_array('undo', $argv, true);
$list = in_array('--list', $argv, true);
set_time_limit(0);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_rc_build')) {
    fwrite(STDERR, "WordPress or inc/record-cleanup.php did not load\n");
    exit(2);
}
$who = 'tidy-records';

if ($undo) {
    $done = $refused = 0;
    $seen = array();
    foreach (kop_rc_log() as $e) {
        $fid = (int) $e['fid'];
        if (isset($seen[$fid]) || ($e['by'] ?? '') !== $who || !empty($e['undone'])) continue;
        $seen[$fid] = true;
        if (!$apply) { $done++; continue; }
        try {
            kop_rc_undo($fid, $who);
            $done++;
        } catch (Throwable $ex) {
            $refused++;
            echo "#$fid {$e['name']}: " . $ex->getMessage() . "\n";
        }
    }
    echo ($apply ? 'Put back ' : 'Would put back ') . $done . ' records' . ($refused ? ", $refused refused (changed since)" : '') . ".\n";
    exit(0);
}

$store = kop_rc_build();
$cards = (array) $store['cards'];
$steps = 0;
$kinds = array();
foreach ($cards as $fid => $c) {
    foreach ($c['steps'] as $s) {
        $steps++;
        $kinds[$s['kind']] = ($kinds[$s['kind']] ?? 0) + 1;
        if ($list) echo "#$fid {$c['name']} [{$s['kind']}] {$s['text']}\n";
    }
}
echo count($cards) . " records, $steps changes (" . implode(', ', array_map(function ($k, $n) { return "$k $n"; }, array_keys($kinds), $kinds)) . ")\n";
if (!$apply) {
    echo "Dry run. Add 'apply' to tidy them.\n";
    exit(0);
}

// Every record it touches, as it is now.
$pdo = kop_rinbox_pdo();
$home = getenv('HOME') ?: dirname(rtrim(ABSPATH, '/'));
$dir = rtrim($home, '/') . '/kop-backups';
if (!is_dir($dir)) mkdir($dir, 0700, true);
$file = $dir . '/record-tidy-' . gmdate('Ymd-His') . '.json';
$backup = array();
$st = $pdo->prepare('SELECT id, json_data, updated_at FROM facilities_v2 WHERE id = ?');
foreach (array_keys($cards) as $fid) {
    $st->execute(array((int) $fid));
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) $backup[] = $r;
}
if (!file_put_contents($file, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE))) {
    echo "Could not write the backup to $file; nothing changed.\n";
    exit(1);
}
echo "Backup: $file (" . count($backup) . " records)\n";

$ok = $failed = 0;
foreach ($cards as $fid => $c) {
    try {
        kop_rc_apply((int) $fid, array_column($c['steps'], 'key'), $who);
        $ok++;
    } catch (Throwable $ex) {
        $failed++;
        echo "#$fid {$c['name']}: " . $ex->getMessage() . "\n";
    }
}
echo "Tidied $ok records" . ($failed ? ", $failed failed" : '') . ". Undo: php api/tidy-records.php undo apply (or per record at Review inbox > Record cleanup > Tidied).\n";
