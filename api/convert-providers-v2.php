<?php
/**
 * Mental health provider records (providers_master) as v2 facility documents
 * (docs/FACILITY-SCHEMA.md; the provider's own fields in providerDetails). The
 * data form has edited and saved providers as v2 since 2026-10-25 and every
 * reader takes either shape; this converts the rows saved before, once.
 *
 * Each facility in a row's facilities list goes through kop_facility_normalize();
 * everything else in the row (operator, category, folder) stays as it is. The
 * rows' old JSON is kept in the option kop_providers_v2_backup, and undo puts
 * a row back only while it is still exactly what apply wrote.
 *
 * CLI only:
 *   php api/convert-providers-v2.php          dry run: what would change
 *   php api/convert-providers-v2.php apply
 *   php api/convert-providers-v2.php undo
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
$mode = in_array('apply', $argv, true) ? 'apply' : (in_array('undo', $argv, true) ? 'undo' : 'dry');

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
require_once get_stylesheet_directory() . '/inc/facility-store.php';
require_once __DIR__ . '/lib-suggested-edits.php';
$pdo = kop_seed_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$table = kop_resolve_table_name($pdo, 'providers_master', '');

/** The facilities list inside a stored row, by reference (the payload nests it under data, sometimes twice). */
function &kop_cpv2_facilities(array &$payload) {
    $node = &$payload;
    for ($depth = 0; $depth < 3 && is_array($node) && !isset($node['facilities']) && isset($node['data']) && is_array($node['data']); $depth++) {
        $node = &$node['data'];
    }
    if (!isset($node['facilities']) || !is_array($node['facilities'])) $node['facilities'] = array();
    return $node['facilities'];
}

if ($mode === 'undo') {
    $backup = get_option('kop_providers_v2_backup', array());
    if (!is_array($backup) || !$backup) {
        echo "Nothing to undo.\n";
        exit(0);
    }
    $done = 0;
    foreach ($backup as $id => $entry) {
        $st = $pdo->prepare("SELECT json_data FROM `{$table}` WHERE id = ?");
        $st->execute(array((int) $id));
        $now = $st->fetchColumn();
        if ($now !== $entry['after']) {
            echo "#{$id} {$entry['name']}: changed since the conversion, left as it is.\n";
            continue;
        }
        $pdo->prepare("UPDATE `{$table}` SET json_data = ? WHERE id = ?")->execute(array($entry['before'], (int) $id));
        unset($backup[$id]);
        $done++;
        echo "#{$id} {$entry['name']}: put back.\n";
    }
    $backup ? update_option('kop_providers_v2_backup', $backup, false) : delete_option('kop_providers_v2_backup');
    echo "Undone: {$done}.\n";
    exit(0);
}

$rows = $pdo->query("SELECT id, unique_name, json_data FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$backup = get_option('kop_providers_v2_backup', array());
$backup = is_array($backup) ? $backup : array();
$changed = 0;
foreach ($rows as $row) {
    $payload = json_decode((string) $row['json_data'], true);
    if (!is_array($payload)) {
        echo "#{$row['id']} {$row['unique_name']}: not JSON, skipped.\n";
        continue;
    }
    $facilities = &kop_cpv2_facilities($payload);
    $converted = 0;
    foreach ($facilities as $i => $facility) {
        if (!is_array($facility)) continue;
        $is_v2 = (int) ($facility['schema_version'] ?? 0) >= 2 && is_array($facility['location'] ?? null);
        $doc = json_decode(kop_facility_json_encode(kop_facility_normalize($facility)), true);
        if ($is_v2 && $doc == $facility) continue;
        $facilities[$i] = $doc;
        $converted++;
    }
    unset($facilities);
    if (!$converted) {
        echo "#{$row['id']} {$row['unique_name']}: already v2.\n";
        continue;
    }
    $after = wp_json_encode($payload);
    $changed++;
    echo "#{$row['id']} {$row['unique_name']}: {$converted} facilit" . ($converted === 1 ? 'y' : 'ies') . " to v2.\n";
    if ($mode !== 'apply') continue;
    $pdo->prepare("UPDATE `{$table}` SET json_data = ?, updated_at = NOW() WHERE id = ?")->execute(array($after, (int) $row['id']));
    $backup[(int) $row['id']] = array('name' => (string) $row['unique_name'], 'before' => (string) $row['json_data'], 'after' => $after, 'at' => gmdate('c'));
}
if ($mode === 'apply' && $changed) update_option('kop_providers_v2_backup', $backup, false);
echo ($mode === 'apply' ? 'Converted' : 'Would convert') . ": {$changed} row(s).\n";
