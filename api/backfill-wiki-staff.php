<?php
/**
 * Put the staff of wiki entries approved before 2026-10-13 on their facility
 * records, as approving them now would (kop_wiki_merge_submission() in
 * api/sync-wiki-facilities.php): blank fields filled, each staff member the
 * record does not list yet added with source "r/troubledteens wiki" linked to
 * the Reddit page, every addition logged per submission so taking that
 * approval back (reject, Undo) takes it off again.
 *
 * One entry per wiki page: the newest approved or published submission, the
 * one wiki_master holds.
 *
 * CLI only, on the server (ea-php82):
 *   php api/backfill-wiki-staff.php              dry run: the real merge in a transaction, rolled back
 *   php api/backfill-wiki-staff.php --list       ...and one line per entry
 *   php api/backfill-wiki-staff.php apply        write it
 *   php api/backfill-wiki-staff.php apply --ids=70,792
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$apply = in_array('apply', $argv, true);
$list = in_array('--list', $argv, true);
$only = array();
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--ids=(.*)$/', $arg, $m)) $only = array_filter(array_map('intval', preg_split('/[\s,]+/', $m[1])));
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
if (!defined('ABSPATH') || !function_exists('kop_seed_pdo')) {
    fwrite(STDERR, "WordPress did not load\n");
    exit(2);
}
require_once __DIR__ . '/sync-wiki-facilities.php';
require_once __DIR__ . '/lib-record-resync.php';
$pdo = kop_seed_pdo();
if (!$pdo) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// The newest approved/published submission of each page (slug), with staff.
$rows = $pdo->query("SELECT id, program_name, json_data FROM wiki_submissions
    WHERE status IN ('approved', 'published') AND json_data LIKE '%staffMembers%' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$pick = array();
foreach ($rows as $r) {
    $j = json_decode((string) $r['json_data'], true);
    if (!is_array($j) || empty($j['staffMembers'])) continue;
    $slug = !empty($j['slug']) ? $j['slug'] : strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $r['program_name'])));
    if (isset($pick[$slug])) continue;
    if ($only && !in_array((int) $r['id'], $only, true)) continue;
    $pick[$slug] = $r;
}

$GLOBALS['kop_wiki_staff_dry'] = !$apply;
if (!$apply) $pdo->beginTransaction();   // kop_v2_with_write_lock() joins it; rolled back below

$added = 0;
$entries = 0;
$why = array();
$t0 = microtime(true);
foreach ($pick as $slug => $r) {
    $id = (int) $r['id'];
    try {
        $res = kop_wiki_merge_submission($pdo, $id);
    } catch (Throwable $e) {
        $res = array('merged' => false, 'reason' => 'error: ' . $e->getMessage());
    }
    $n = (int) ($res['staff_added'] ?? 0);
    $added += $n;
    if ($n) $entries++;
    else {
        $k = preg_replace("/'[^']*'/", "'..'", (string) $res['reason']);
        $why[$k] = ($why[$k] ?? 0) + 1;
    }
    if ($list) echo "#$id {$r['program_name']}: " . ($n ? "$n staff" : $res['reason']) . "\n";
}

if (!$apply) {
    $pdo->rollBack();
} elseif ($added) {
    kop_record_changed('wiki', array_map(function ($r) { return (int) $r['id']; }, array_values($pick)));
}

printf("%s: %d pages, %d entries %s %d staff (%.0fs)\n", $apply ? 'APPLIED' : 'DRY RUN (nothing written)',
    count($pick), $entries, $apply ? 'added' : 'would add', $added, microtime(true) - $t0);
arsort($why);
foreach ($why as $k => $v) echo "  $v nothing added: $k\n";
if (!$apply) echo "Run again with 'apply' to write it. Each entry's additions are logged (option kop_wiki_staff_log) and come off when its approval is taken back.\n";
