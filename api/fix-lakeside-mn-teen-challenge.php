<?php
/**
 * Lakeside Academy, Buffalo, MN (#100099), 2026-11-04 (owner: "connected to Teen Challenge as a company and everything
 * that entails"; nothing of the Kansas, Utah or Michigan schools of that name tied to it):
 *   1. Company link to Teen Challenge (operator 22, 'current'), as its other 27 programs have. Sources: the national
 *      office's program finder (https://teenchallengeusa.org/centers/tcmn103/, via the Teen Challenge Research Map,
 *      SL-722) and the MN DHS licence, held by "Minnesota Adult & Teen Challenge - Lakeside Academy" (licence 1078609).
 *   2. identification.currentOperator = "Teen Challenge".
 *   3. provenance.legacyIds [10459] (Utah's record, left by the 2026-09-16 split) -> [].
 *   4. The empty FileBird folder 6101 "Lakeside Academy of Kansas" under WISCONSIN is deleted (KANSAS has 6958).
 * Each step checks the record is as expected first. The old values go in option kop_fix_lakeside_mn_log for undo.
 *
 * CLI only, on the server (ea-php82), from the theme directory:
 *   php api/fix-lakeside-mn-teen-challenge.php              dry run
 *   php api/fix-lakeside-mn-teen-challenge.php apply
 *   php api/fix-lakeside-mn-teen-challenge.php undo apply   puts back what apply changed
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
$FID = 100099; $OP = 22; $FOLDER = 6101; $KS_FOLDER = 6958;
$LOG = 'kop_fix_lakeside_mn_log';

echo ($apply ? 'APPLY' : 'DRY RUN (add apply to write)') . ($undo ? ' UNDO' : '') . "\n";

$doc = kop_facility_load($FID);
if (!$doc || ($doc['identification']['name'] ?? '') !== 'Lakeside Academy' || ($doc['location']['state'] ?? '') !== 'MN') {
    fwrite(STDERR, "#$FID is not Lakeside Academy, MN; stopping\n");
    exit(1);
}
$links_of = function () use ($wpdb, $p, $FID) {
    return $wpdb->get_results($wpdb->prepare("SELECT operator_id, relationship, sort_order FROM {$p}kop_operator_facilities WHERE facility_id = %d", $FID), ARRAY_A);
};
$changed = function () use ($OP, $FID) {
    if (function_exists('kop_record_changed')) {
        kop_record_changed('operator', array($OP));
        kop_record_changed('facility', array($FID));
    }
};

if ($undo) {
    $log = get_option($LOG);
    if (!is_array($log)) { echo "nothing to undo\n"; exit(0); }
    echo 'log: ' . wp_json_encode($log) . "\n";
    if (!$apply) exit(0);
    if (!empty($log['link_added'])) $wpdb->delete("{$p}kop_operator_facilities", array('operator_id' => $OP, 'facility_id' => $FID));
    if (array_key_exists('currentOperator', $log)) $doc['identification']['currentOperator'] = $log['currentOperator'];
    if (array_key_exists('legacyIds', $log)) $doc['provenance']['legacyIds'] = $log['legacyIds'];
    kop_facility_save($doc);
    if (!empty($log['folder'])) $wpdb->insert("{$p}fbv", $log['folder']);
    delete_option($LOG);
    $changed();
    echo 'links now: ' . wp_json_encode($links_of()) . "\ndone\n";
    exit(0);
}

$op = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM {$p}kop_operators WHERE id = %d", $OP), ARRAY_A);
if (!$op || $op['name'] !== 'Teen Challenge') {
    fwrite(STDERR, "operator $OP is not Teen Challenge; stopping\n");
    exit(1);
}
$log = array('at' => gmdate('c'));

// 1. Company link.
$links = $links_of();
echo 'links before: ' . wp_json_encode($links) . "\n";
$has = false;
foreach ($links as $l) if ((int) $l['operator_id'] === $OP) $has = true;
if (!$has) {
    $sort = 1 + (int) $wpdb->get_var($wpdb->prepare("SELECT MAX(sort_order) FROM {$p}kop_operator_facilities WHERE operator_id = %d", $OP));
    echo "add link: Teen Challenge ($OP) -> #$FID current, sort_order $sort\n";
    $log['link_added'] = true;
    if ($apply) $wpdb->insert("{$p}kop_operator_facilities", array('operator_id' => $OP, 'facility_id' => $FID, 'relationship' => 'current', 'sort_order' => $sort));
} else {
    echo "link already there\n";
}

// 2 + 3. The document.
$log['currentOperator'] = $doc['identification']['currentOperator'] ?? '';
$log['legacyIds'] = $doc['provenance']['legacyIds'] ?? array();
echo 'currentOperator: ' . wp_json_encode($log['currentOperator']) . " -> \"Teen Challenge\"\n";
echo 'legacyIds: ' . wp_json_encode($log['legacyIds']) . " -> []\n";
$doc['identification']['currentOperator'] = 'Teen Challenge';
$doc['provenance']['legacyIds'] = array();

// 4. The stray folder.
$f = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$p}fbv WHERE id = %d", $FOLDER), ARRAY_A);
$parent = $f ? $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}fbv WHERE id = %d", (int) $f['parent'])) : null;
$files = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}fbv_attachment_folder WHERE folder_id = %d", $FOLDER));
$kids = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}fbv WHERE parent = %d", $FOLDER));
$ks = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$p}fbv WHERE id = %d", $KS_FOLDER));
echo 'folder ' . $FOLDER . ': ' . wp_json_encode($f) . " under " . wp_json_encode($parent) . ", files $files, subfolders $kids; KANSAS folder $KS_FOLDER: " . wp_json_encode($ks) . "\n";
$drop_folder = $f && $f['name'] === 'Lakeside Academy of Kansas' && $parent === 'WISCONSIN' && $files === 0 && $kids === 0 && $ks === 'Lakeside Academy of Kansas';
echo $drop_folder ? "delete folder $FOLDER\n" : "folder left alone (not as expected)\n";
if ($drop_folder) $log['folder'] = $f;

if (function_exists('kop_facility_validate')) {
    $errors = array_filter(kop_facility_validate($doc), function ($v) { return $v['severity'] === 'error'; });
    echo $errors ? 'VALIDATION ERRORS: ' . wp_json_encode(array_values($errors)) . "\n" : "document validates\n";
    if ($errors) exit(1);
}

if (!$apply) exit(0);
update_option($LOG, $log, false);
kop_facility_save($doc);
if ($drop_folder) $wpdb->delete("{$p}fbv", array('id' => $FOLDER));
$changed();
$after = kop_facility_load($FID);
echo 'after: currentOperator=' . wp_json_encode($after['identification']['currentOperator'] ?? null)
    . ' legacyIds=' . wp_json_encode($after['provenance']['legacyIds'] ?? null)
    . ' links=' . wp_json_encode($links_of()) . "\ndone\n";
