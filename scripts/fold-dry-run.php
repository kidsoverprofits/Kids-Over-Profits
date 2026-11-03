<?php
/**
 * The live fold engine (inc/fact-fold.php, docs/plan/4.4 item 20) in count mode: kop_fold_run(false) against the
 * production mirror. Writes nothing (the harness's $wpdb refuses writes; the PDO is only read). Prints what the hourly
 * pass would clear (already on the record, or held for the write-up) and fold, per queue and kind, with examples, and
 * the news facts the exact-name rule would link. Compare with scripts/fact-dedupe.php (the measurement it replaces).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring -d memory_limit=2G scripts/fold-dry-run.php [--db=tmp/prod.sqlite]
 *       [--facility=N] [--examples=15] [--json=path] [--ai-estimate | --ai --facility=N]
 *   (scripts/fact-dedupe.php --engine runs the same thing.)
 *
 * The AI step (d) makes no call by default. --ai-estimate counts the calls, lines and tokens a full pass would make
 * (nothing cached: every close pair asked). --ai --facility=N makes the real calls for that one record (provider keys
 * from .env / api/config.local.php, logged as AI usage) and prints the verdicts; nothing is written or remembered.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$opt = array();
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
}
$db_path = (string) ($opt['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite'));
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (python ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
ini_set('memory_limit', '2G');

require_once __DIR__ . '/kop-test-harness.php';   // WordPress stubs + $wpdb over PDO; $pdo is the mirror
if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
if (!function_exists('kop_rinbox_register')) { function kop_rinbox_register() {} }
require_once dirname(__DIR__) . '/inc/review-inbox/woodbury-facts.php';   // kop_wbf_on_record()
require_once dirname(__DIR__) . '/api/url-dedupe.php';
require_once dirname(__DIR__) . '/inc/auto-connect.php';
require_once dirname(__DIR__) . '/inc/fornits.php';
require_once dirname(__DIR__) . '/inc/news-facts.php';
require_once dirname(__DIR__) . '/inc/fact-shape.php';
require_once dirname(__DIR__) . '/inc/fact-fold.php';

$only = isset($opt['facility']) ? array('r:' . (int) $opt['facility']) : null;
$ai = !empty($opt['ai-estimate']) ? 'estimate' : (!empty($opt['ai']) ? 'live' : '');
if ($ai === 'live' && !$only) {
    fwrite(STDERR, "--ai makes real calls: name one record with --facility=N.\n");
    exit(2);
}
$t0 = microtime(true);
$run_opts = array('ai' => $ai, 'ai_cap' => 1000);
if ($only) $run_opts['only'] = $only;
$S = kop_fold_run(false, 100000, $run_opts);
$n_ex = isset($opt['examples']) ? max(0, (int) $opt['examples']) : 15;

echo "\n=== Fold engine, count mode (writes nothing) ===  db={$db_path}  " . round(microtime(true) - $t0, 1) . "s  units " . $S['units_done'] . '/' . $S['units_total'] . "\n";
if (!empty($S['error'])) { echo 'ERROR: ' . $S['error'] . "\n"; exit(1); }
echo 'Checked: ' . json_encode($S['checked']) . "\n";
echo 'Left alone (edited, sent back, un-folded): ' . json_encode(array_map('array_sum', $S['left_alone'])) . "\n";
echo 'Skipped: ' . json_encode($S['skipped']) . "\n";
echo 'News facts the exact-name rule would link (treated as linked here): ' . $S['news_linked_in_memory'] . "\n\n";
echo "--- Cleared (b) ---\n";
$tot = 0;
foreach ($S['cleared'] as $q => $by) {
    echo sprintf("  %-9s %6d   %s\n", $q, array_sum($by), json_encode($by));
    $tot += array_sum($by);
    arsort($S['cleared_kind'][$q]);
    foreach (array_slice($S['cleared_kind'][$q], 0, 8, true) as $k => $n) echo sprintf("      %-30s %5d\n", $k, $n);
}
echo "  total cleared: $tot\n\n--- Folded (c, e) ---\n";
$ft = 0;
foreach ($S['folded'] as $q => $by) {
    arsort($by);
    echo sprintf("  %-9s %6d\n", $q, array_sum($by));
    $ft += array_sum($by);
    foreach (array_slice($by, 0, 8, true) as $k => $n) echo sprintf("      %-30s %5d\n", $k, $n);
}
echo "  total folded: $ft in " . $S['groups'] . ' groups (' . ($S['groups_cross_queue'] ?? 0) . ' mix queues); '
    . ($S['groups_news_only'] ?? 0) . ' groups of news facts alone left unfolded (' . ($S['news_only_members'] ?? 0) . " items; no place for their citations)\n";
echo 'AI candidate pairs (for the AI step): ' . ($S['ai_candidates'] ?? 0) . "\n";
if ($ai === 'estimate') {
    $e = $S['ai_est'] ?? array();
    $calls = (int) ($e['calls'] ?? 0);
    echo sprintf("AI estimate, full pass with nothing cached: %d calls, %d lines sent (%d waiting lines asked), about %d tokens; %d hourly runs at %d calls a run\n",
        $calls, $e['lines'] ?? 0, $e['asked'] ?? 0, $e['tokens'] ?? 0, (int) ceil($calls / KOP_FOLD_AI_CALLS_PER_RUN), KOP_FOLD_AI_CALLS_PER_RUN);
}
if ($ai === 'live') {
    echo 'AI calls made: ' . $S['ai_calls'] . ($S['ai_stopped'] !== '' ? ' (stopped: ' . $S['ai_stopped'] . ')' : '') . '; lines sent ' . ($S['ai_lines'] ?? 0)
        . '; unreadable replies ' . ($S['ai_bad_replies'] ?? 0) . '; AI joins ' . ($S['ai_joins'] ?? 0) . '; AI "same" overruled by the rules ' . ($S['ai_overruled'] ?? 0) . "\n";
    foreach ($S['ai_errors'] ?? array() as $err) echo '  error: ' . preg_replace('/key=[^&\s]+/', 'key=...', $err) . "\n";
    echo "\n--- AI verdicts (nothing written) ---\n";
    foreach ($S['ai_verdicts'] ?? array() as $v) {
        echo sprintf("  %-8s %s\n%s", $v['v'], $v['line'], $v['to'] !== '' ? '           -> ' . $v['to'] . "\n" : '');
    }
}
if (!empty($S['ai_conflicts'])) echo 'AI conflicts (differs): ' . json_encode($S['ai_conflicts']) . "\n";
if (!empty($S['errors'])) echo 'Errors: ' . json_encode(array_slice($S['errors'], 0, 5)) . "\n";
foreach (array('cleared', 'folded') as $b) {
    echo "\n--- Examples: $b ---\n";
    $k = 0;
    foreach ($S['examples'][$b] ?? array() as $e) {
        if ($k++ >= $n_ex) break;
        echo sprintf("  #%s %s [%s]\n      %s\n      %s%s\n", $e['record'], mb_substr($e['name'], 0, 40), $e['queue'], $e['line'],
            $b === 'cleared' ? '-> ' . $e['reason'] : '=> ' . $e['into'], $b === 'folded' && $e['how'] !== '' ? '   (' . $e['how'] . ')' : '');
    }
}
if (!empty($opt['json'])) {
    file_put_contents($opt['json'], json_encode($S, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nWrote {$opt['json']}\n";
}
