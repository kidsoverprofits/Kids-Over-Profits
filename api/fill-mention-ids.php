<?php
/**
 * Saves the facility ids into news articles' and cases' stored mentions (facilities_mentioned), 2026-11-04.
 * Since then every save fills them (kop_mentions_fill_ids(), api/facility-aliases.php); this does the rows saved
 * before. Only an id the row is already linked to is written (news_facility_links / lawsuit_facility_links), so no
 * page changes: the links are kept when the row is next saved, resynced or auto-connected. A bare name several
 * states' records share ("Lakeside Academy") gets the one record of that name the row links; two linked = left alone.
 * No link is added or removed. The old values go in option kop_fill_mention_ids_log.
 *
 * CLI only, on the server (ea-php82), from the theme directory:
 *   php api/fill-mention-ids.php                dry run (counts and examples)
 *   php api/fill-mention-ids.php apply
 *   php api/fill-mention-ids.php undo apply     puts back the stored mentions apply changed
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
$apply = in_array('apply', $argv, true);
$undo  = in_array('undo', $argv, true);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
require_once __DIR__ . '/lawsuit-facility-links.php';   // news-mentions.php + facility-aliases.php
if (!defined('ABSPATH') || !function_exists('kop_seed_pdo') || !function_exists('kop_mentions_fill_ids')) {
    fwrite(STDERR, "WordPress or the theme's mention code did not load\n");
    exit(2);
}
$pdo = kop_seed_pdo();
if (!$pdo) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$LOG = 'kop_fill_mention_ids_log';
$tables = array('news' => 'news_submissions', 'lawsuit' => 'lawsuits');

echo ($apply ? 'APPLY' : 'DRY RUN (add apply to write)') . ($undo ? ' UNDO' : '') . "\n";

if ($undo) {
    $log = get_option($LOG);
    if (!is_array($log)) { echo "nothing to undo\n"; exit(0); }
    $n = 0;
    foreach ($log['rows'] ?? array() as $kind => $rows) {
        foreach ($rows as $id => $old) {
            $n++;
            if ($apply) $pdo->prepare("UPDATE {$tables[$kind]} SET facilities_mentioned = ? WHERE id = ?")->execute(array($old, (int) $id));
        }
    }
    echo "$n rows " . ($apply ? 'put back' : 'would be put back') . " (log of {$log['at']})\n";
    if ($apply) delete_option($LOG);
    exit(0);
}

$index = kop_build_facility_alias_index($pdo);
$link_tables = array('news' => array('news_facility_links', 'news_id'), 'lawsuit' => array('lawsuit_facility_links', 'lawsuit_id'));
$log = array('at' => gmdate('c'), 'rows' => array('news' => array(), 'lawsuit' => array()));
$count = array('news' => array('rows' => 0, 'entries' => 0, 'shared' => 0), 'lawsuit' => array('rows' => 0, 'entries' => 0, 'shared' => 0));
$examples = array();

foreach ($tables as $kind => $table) {
    $where = $kind === 'news' ? "status <> 'deleted' AND " : '';
    $rows = $pdo->query("SELECT id, facilities_mentioned FROM {$table} WHERE {$where}facilities_mentioned IS NOT NULL AND facilities_mentioned NOT IN ('', '[]')")->fetchAll();
    list($lt, $lc) = $link_tables[$kind];
    $links = $pdo->prepare("SELECT facility_id FROM {$lt} WHERE {$lc} = ?");
    foreach ($rows as $row) {
        $before = kop_normalize_facility_mentions($row['facilities_mentioned']);
        $missing = array_filter($before, function ($m) { return empty($m['facility_id']); });
        if (!$missing) continue;
        $links->execute(array((int) $row['id']));
        $linked = array_flip(array_map('intval', $links->fetchAll(PDO::FETCH_COLUMN)));
        if (!$linked) continue;
        $after = kop_mentions_fill_ids($pdo, $kind, (int) $row['id'], $before, $index);
        $keep = array();
        foreach ($after as $i => $m) {
            if (!empty($before[$i]['facility_id']) || empty($m['facility_id'])) continue;
            // Only an id the row already links: nothing on any page moves.
            if (!isset($linked[(int) $m['facility_id']])) continue;
            $keep[] = $m;
            $count[$kind]['entries']++;
            if (isset($index['homonyms'][kop_normalize_name_key($m['name'])])) {
                $count[$kind]['shared']++;
                if (count($examples) < 25) $examples[] = "$kind {$row['id']}: \"{$m['name']}\" -> {$m['facility_id']}";
            }
        }
        if (!$keep) continue;
        $count[$kind]['rows']++;
        $log['rows'][$kind][(int) $row['id']] = (string) $row['facilities_mentioned'];
        if ($apply) kop_mentions_save_ids($pdo, $kind, (int) $row['id'], $keep);
    }
}

foreach ($count as $kind => $c) {
    echo "$kind: {$c['rows']} rows, {$c['entries']} mentions given the id they are linked to ({$c['shared']} of them a name several records share)\n";
}
echo "shared names, first " . count($examples) . ":\n  " . implode("\n  ", $examples) . "\n";
if ($apply) {
    update_option($LOG, $log, false);
    echo "saved; undo with: php api/fill-mention-ids.php undo apply\n";
}
