<?php
/**
 * Two record fixes the owner settled on 2026-11-04:
 *   - Catherine Freer Wilderness Program had no Nevada program. #100681 ("Catherine Freer Wilderness Program (NV)",
 *     made by the news scan from a 2002 strugglingteens.com article) merges into #12341 (Albany, OR) through the
 *     Merge Duplicates path (kop_fmerge_do_merge(): links move, the page 301s, exact Undo on its Merged tab); the
 *     "Catherine Freer of Nevada" name it carried comes off the Oregon record.
 *   - Hyde School in Bath, ME (#11021) and Woodstock, CT (#100107) are one school on two campuses: #100107 is tied
 *     to #11021 as its other campus (Program Homes, wording "campuses"; undo on its Grouped tab).
 *
 * CLI only, on the server (ea-php82), from the theme directory:
 *   php api/fix-freer-hyde.php          dry run
 *   php api/fix-freer-hyde.php apply
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
if (!defined('ABSPATH') || !function_exists('kop_seed_pdo') || !function_exists('kop_fmerge_do_merge') || !function_exists('kop_program_homes_group')) {
    fwrite(STDERR, "WordPress or the theme's merge / program homes code did not load\n");
    exit(2);
}
$pdo = kop_seed_pdo();
$prefix = $pdo ? kop_seed_v2_prefix($pdo) : null;
if (!$pdo || $prefix === null) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$opts = array('pdo' => $pdo, 'prefix' => $prefix);

// ---- Catherine Freer ------------------------------------------------------------
$keep = kop_facility_load(12341, $opts);
$drop = kop_facility_load(100681, $opts);
if (!$keep) {
    echo "Catherine Freer: #12341 not found, skipped\n";
} elseif (!$drop) {
    echo "Catherine Freer: #100681 already gone (merged?)\n";
} else {
    echo "Catherine Freer: merge #100681 \"{$drop['unique_name']}\" into #12341 \"{$keep['unique_name']}\"\n";
    if ($apply) echo '  ' . kop_fmerge_do_merge($pdo, $prefix, 12341, 100681, 'Claude (owner, 2026-11-04)') . "\n";
}
$keep = kop_facility_load(12341, $opts);
if ($keep) {
    $names = (array) ($keep['doc']['identification']['otherNames'] ?? array());
    $left = array_values(array_filter($names, static function ($n) { return !is_string($n) || stripos($n, 'Nevada') === false; }));
    if (count($left) !== count($names)) {
        echo '  other names off #12341: ' . implode(', ', array_diff($names, $left)) . "\n";
        if ($apply) {
            $doc = $keep['doc'];
            $doc['identification']['otherNames'] = $left;
            kop_facility_save(kop_facility_normalize($doc, array('facility_id' => 12341, 'unique_name' => $keep['unique_name'])), $opts);
        }
    } elseif (!$apply && $drop) {
        $names = (array) ($drop['doc']['identification']['otherNames'] ?? array());
        foreach ($names as $n) if (is_string($n) && stripos($n, 'Nevada') !== false) echo "  after the merge, other name off #12341: $n\n";
    }
}

// ---- Hyde School ----------------------------------------------------------------
$ct = kop_program_homes_program_of(100107);
if ($ct && (int) $ct[0] === 11021 && kop_program_homes_is_campus(11021)) {
    echo "Hyde School: #100107 is already a campus of #11021\n";
} else {
    echo "Hyde School: #100107 (Woodstock, CT) tied to #11021 (Bath, ME) as its other campus\n";
    if ($apply) {
        kop_program_homes_group(array(100107 => 'Woodstock, CT'), 11021, 'Hyde School', array('satellite' => 'campus'));
    }
}

if ($apply) {
    if (function_exists('kop_record_changed')) kop_record_changed('facility', array(12341, 11021, 100107));
    delete_transient('kop_facility_pages_index');
    do_action('litespeed_purge_all');
}
echo $apply ? "Applied.\n" : "Dry run.\n";
