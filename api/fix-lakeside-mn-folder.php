<?php
/**
 * Lakeside Academy, Buffalo, MN (#100099), 2026-11-04 (owner: connect it to Teen Challenge, by the facility id, no name
 * matching): makes the FileBird folder "Lakeside Academy" under "Teen Challenge" (folder 18) when it is not there, and sets
 * documentFolderId on the record to it. The folder starts empty; documents of the Minnesota program are filed into it.
 * Michigan's folder 240 and Utah's 4027 are theirs by documentFolderId, so the name is never matched. The old values go in
 * option kop_fix_lakeside_mn_folder_log for undo (the folder is deleted only if apply made it and it is still empty).
 *
 * CLI only, on the server (ea-php82), from the theme directory:
 *   php api/fix-lakeside-mn-folder.php              dry run
 *   php api/fix-lakeside-mn-folder.php apply
 *   php api/fix-lakeside-mn-folder.php undo apply
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
$FID = 100099; $PARENT = 18; $NAME = 'Lakeside Academy';
$LOG = 'kop_fix_lakeside_mn_folder_log';
$fbv = "{$p}fbv";

echo ($apply ? 'APPLY' : 'DRY RUN (add apply to write)') . ($undo ? ' UNDO' : '') . "\n";

$loaded = kop_facility_load($FID);
$doc = is_array($loaded) ? ($loaded['doc'] ?? null) : null;
if (!$doc || ($doc['identification']['name'] ?? '') !== 'Lakeside Academy' || ($doc['location']['state'] ?? '') !== 'MN') {
    fwrite(STDERR, "#$FID is not Lakeside Academy, MN; stopping\n");
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
    if (!empty($log['created_folder'])) {
        $id = (int) $log['created_folder'];
        $files = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}fbv_attachment_folder WHERE folder_id = %d", $id));
        $kids = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$fbv} WHERE parent = %d", $id));
        if ($files || $kids) echo "folder $id now holds files or subfolders; left in place\n";
        else $wpdb->delete($fbv, array('id' => $id));
    }
    delete_option($LOG);
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array($FID));
    echo "done\n";
    exit(0);
}

$parent = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM {$fbv} WHERE id = %d", $PARENT), ARRAY_A);
if (!$parent || $parent['name'] !== 'Teen Challenge') {
    fwrite(STDERR, "folder $PARENT is not Teen Challenge; stopping\n");
    exit(1);
}
$log = array('at' => gmdate('c'), 'documentFolderId' => $doc['documentFolderId'] ?? null);

$folder = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$fbv} WHERE parent = %d AND name = %s AND type = 0 LIMIT 1", $PARENT, $NAME));
if ($folder) {
    echo "folder $folder already under Teen Challenge\n";
} else {
    $template = $wpdb->get_row("SELECT * FROM {$fbv} WHERE parent = {$PARENT} AND type = 0 ORDER BY id DESC LIMIT 1", ARRAY_A);
    if (!$template) {
        fwrite(STDERR, "no sibling folder to copy FileBird's columns from; stopping\n");
        exit(1);
    }
    $row = $template;
    unset($row['id']);
    $row['name'] = $NAME;
    $row['parent'] = $PARENT;
    $row['ord'] = 1 + (int) $wpdb->get_var("SELECT MAX(ord) FROM {$fbv} WHERE parent = {$PARENT}");
    echo "create folder \"$NAME\" under Teen Challenge ($PARENT), ord {$row['ord']}\n";
    if ($apply) {
        $wpdb->insert($fbv, $row);
        $folder = (int) $wpdb->insert_id;
        $log['created_folder'] = $folder;
    }
}
echo 'documentFolderId: ' . wp_json_encode($log['documentFolderId']) . ' -> ' . ($folder ?: '(new folder)') . "\n";

if ($apply) {
    if ($folder <= 0) { fwrite(STDERR, "folder was not created\n"); exit(1); }
    $doc['documentFolderId'] = $folder;
    kop_facility_save($doc);
    update_option($LOG, $log, false);
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array($FID));
    echo "done: #$FID -> folder $folder\n";
}
