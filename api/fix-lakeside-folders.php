<?php
/**
 * Lakeside Academy document folders, 2026-11-04. Three programs share the name, and the directory's location tab matched
 * Minnesota's by name to Michigan's folder 240 ("Sequel TSI" > "Lakeside Academy"). The code no longer name-matches or
 * merges folders of a shared name (kop_get_homonym_folder_names()); this script gives the Michigan record (#100098) its
 * folder explicitly (documentFolderId 240) and drops the wrong folder link 240 <-> 4027 (Utah's Youth Health Associates).
 * The old values go in option kop_fix_lakeside_folders_log for undo.
 *
 * CLI only, on the server (ea-php82), from the theme directory:
 *   php api/fix-lakeside-folders.php              dry run
 *   php api/fix-lakeside-folders.php apply
 *   php api/fix-lakeside-folders.php undo apply
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
if (!defined('ABSPATH') || !function_exists('kop_facility_load') || !function_exists('kop_facility_save')) {
    fwrite(STDERR, "WordPress or the theme's facility store did not load\n");
    exit(2);
}
global $wpdb;
$p = $wpdb->prefix;
$FID = 100098; $FOLDER = 240; $UT_FOLDER = 4027;
$LOG = 'kop_fix_lakeside_folders_log';
$links = "{$p}kop_folder_links";

echo ($apply ? 'APPLY' : 'DRY RUN (add apply to write)') . ($undo ? ' UNDO' : '') . "\n";

$loaded = kop_facility_load($FID);
$doc = is_array($loaded) ? ($loaded['doc'] ?? null) : null;
if (!$doc || ($doc['identification']['name'] ?? '') !== 'Lakeside Academy' || ($doc['location']['state'] ?? '') !== 'MI') {
    fwrite(STDERR, "#$FID is not Lakeside Academy, MI; stopping\n");
    exit(1);
}

if ($undo) {
    $log = get_option($LOG);
    if (!is_array($log)) { echo "nothing to undo\n"; exit(0); }
    echo 'log: ' . wp_json_encode($log) . "\n";
    if (!$apply) exit(0);
    if (array_key_exists('documentFolderId', $log)) {
        if ($log['documentFolderId'] === null) unset($doc['documentFolderId']);
        else $doc['documentFolderId'] = $log['documentFolderId'];
        kop_facility_save($doc);
    }
    if (!empty($log['link'])) $wpdb->insert($links, $log['link']);
    delete_option($LOG);
    echo "done\n";
    exit(0);
}

$log = array('at' => gmdate('c'));
$name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}fbv WHERE id = %d", $FOLDER));
if ($name !== 'Lakeside Academy') {
    fwrite(STDERR, "folder $FOLDER is not Lakeside Academy; stopping\n");
    exit(1);
}
$log['documentFolderId'] = $doc['documentFolderId'] ?? null;
echo 'documentFolderId: ' . wp_json_encode($log['documentFolderId']) . " -> $FOLDER\n";
$doc['documentFolderId'] = $FOLDER;

$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$links} WHERE folder_a = %d AND folder_b = %d", min($FOLDER, $UT_FOLDER), max($FOLDER, $UT_FOLDER)), ARRAY_A);
echo $row ? 'folder link: ' . wp_json_encode($row) . " -> deleted\n" : "folder link $FOLDER <-> $UT_FOLDER already gone\n";
if ($row) $log['link'] = $row;

if ($apply) {
    kop_facility_save($doc);
    if ($row) $wpdb->delete($links, array('folder_a' => $row['folder_a'], 'folder_b' => $row['folder_b']));
    update_option($LOG, $log, false);
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array($FID));
    echo "done\n";
}
