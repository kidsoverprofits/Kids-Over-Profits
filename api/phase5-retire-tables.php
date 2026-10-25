<?php
/**
 * Data model phase 5, the database step (docs/DATA-MODEL-MIGRATION.md):
 *   - locations_master keeps each place's state-level data (referrerConsultants,
 *     fieldNotes, ...) and loses its frozen facility arrays: a place's
 *     facilities are memberships in the v2 tables since the 2026-09-18 write switch;
 *   - facilities_master, frozen since then, is renamed
 *     retired_facilities_master_20261025 (dropped later; RENAME it back to undo);
 *   - the backup copies from the migration (*_bak_20260916, *_bak_20260917) are dropped.
 * Backup first: ~/backups/phase5-legacy-tables-20261025.sql.gz holds every table
 * this touches as it was (zcat it into mysql to restore one).
 *
 * CLI only:
 *   php api/phase5-retire-tables.php          dry run: what would change
 *   php api/phase5-retire-tables.php apply
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
$apply = in_array('apply', $argv, true);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH')) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(2);
}
require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
$pdo = kop_seed_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
global $wpdb;
if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
    fwrite(STDERR, "Saves are not on the v2 tables; phase 5 needs the write switch on.\n");
    exit(2);
}
$exists = function ($table) use ($pdo) {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute(array($table));
    return (int) $st->fetchColumn() > 0;
};
$log = array();

// 1. locations_master: empty the facility arrays, keep the rest of each row.
$stripped = 0;
$entries = 0;
foreach ($pdo->query('SELECT id, unique_name, json_data FROM locations_master ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $json = json_decode((string) $row['json_data'], true);
    if (!is_array($json)) continue;
    $node = &$json;
    for ($depth = 0; $depth < 3 && is_array($node) && !isset($node['facilities']) && isset($node['data']) && is_array($node['data']); $depth++) {
        $node = &$node['data'];
    }
    $n = is_array($node['facilities'] ?? null) ? count($node['facilities']) : 0;
    unset($node);
    if (!$n) continue;
    $node = &$json;
    for ($depth = 0; $depth < 3 && is_array($node) && !isset($node['facilities']) && isset($node['data']) && is_array($node['data']); $depth++) {
        $node = &$node['data'];
    }
    $node['facilities'] = array();
    unset($node);
    $stripped++;
    $entries += $n;
    echo "  locations_master #{$row['id']} {$row['unique_name']}: {$n} facility entries out\n";
    if ($apply) {
        $pdo->prepare('UPDATE locations_master SET json_data = ?, updated_at = NOW() WHERE id = ?')
            ->execute(array(wp_json_encode($json), (int) $row['id']));
    }
}
$log[] = "locations_master: {$entries} facility entries out of {$stripped} rows";

// 2. facilities_master: retired under another name.
$retired = 'retired_facilities_master_20261025';
if ($exists('facilities_master')) {
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM facilities_master')->fetchColumn();
    if ($exists($retired)) {
        fwrite(STDERR, "{$retired} already exists; facilities_master left as it is.\n");
    } else {
        $log[] = "facilities_master ({$rows} rows) renamed {$retired}";
        if ($apply) $pdo->exec("RENAME TABLE facilities_master TO `{$retired}`");
    }
} else {
    $log[] = 'facilities_master: already gone';
}

// 3. The migration's backup copies.
foreach ($pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()
    AND (table_name LIKE '%\\_bak\\_20260916' OR table_name LIKE '%\\_bak\\_20260917') ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN) as $bak) {
    $log[] = "dropped {$bak}";
    if ($apply) $pdo->exec("DROP TABLE `{$bak}`");
}

foreach ($log as $line) echo ($apply ? '' : 'would: ') . $line . "\n";
if ($apply) {
    update_option('kop_phase5_log', array('at' => gmdate('c'), 'done' => $log), false);
    // Caches built from the old rows.
    delete_transient('kop_rtc_program_names');
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array());
}
echo $apply ? "Done.\n" : "Dry run. Run with apply to do it.\n";
