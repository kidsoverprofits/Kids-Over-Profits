<?php
/**
 * Past and other names cut off at an open bracket ("Arizona Boys Ranch (March 2000",
 * "Integrity House (Cedar City, UT"): woodbury-facts.py's clean_name() stripped the
 * closing ")" before it looked for a dated bracket, so a HEAL "renamed from X (March
 * 2000)" went on the record as "X (March 2000" (fixed in kop-pipeline 2026-10-15).
 * Each such name becomes the name before the bracket, or comes off when the record
 * already lists that name; the "Past name: ..." note line it left gets its ")" back.
 *
 * CLI only, on the server (ea-php82):
 *   php api/fix-cut-names.php              dry run: what would change
 *   php api/fix-cut-names.php apply        write it (each record as it was kept in the option kop_fix_cut_names_log)
 *   php api/fix-cut-names.php undo apply   put those records back, when nothing has changed them since
 *   php api/fix-cut-names.php --selftest   the name rule on made-up names
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

/** A name with more "(" than ")": the name before the last open bracket. '' when it is not cut. */
function kop_fcn_clean($name) {
    $name = (string) $name;
    if (substr_count($name, '(') <= substr_count($name, ')')) return '';
    return trim(substr($name, 0, strrpos($name, '(')), " \t,;:-");
}

function kop_fcn_key($name) {
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', ' ', (string) $name)));
}

/** The document with its cut names fixed: [doc, [[list, from, to|null], ...]]. */
function kop_fcn_fix(array $doc) {
    $changes = array();
    foreach (array('pastNames', 'otherNames') as $list) {
        $names = $doc['identification'][$list] ?? null;
        if (!is_array($names)) continue;
        $keys = array();
        foreach ($names as $n) if (is_string($n) && kop_fcn_clean($n) === '') $keys[kop_fcn_key($n)] = true;
        $keys[kop_fcn_key($doc['identification']['name'] ?? '')] = true;
        $out = array();
        foreach ($names as $n) {
            $clean = is_string($n) ? kop_fcn_clean($n) : '';
            if ($clean === '') { $out[] = $n; continue; }
            if ($clean === '' || isset($keys[kop_fcn_key($clean)])) {
                $changes[] = array($list, $n, null);
                continue;
            }
            $keys[kop_fcn_key($clean)] = true;
            $out[] = $clean;
            $changes[] = array($list, $n, $clean);
        }
        $doc['identification'][$list] = $out;
    }
    if ($changes) {
        // The note line Woodbury Facts wrote for it: "Past name: X (March 2000 (HEAL, ...)" -> "Past name: X (March 2000) (HEAL, ...)".
        $cut = array_column($changes, 1);
        array_walk_recursive($doc, function (&$s) use ($cut) {
            if (!is_string($s)) return;
            foreach ($cut as $n) {
                foreach (array('Past name: ', 'Other name: ') as $label) {
                    $p = strpos($s, $label . $n);
                    if ($p === false) continue;
                    $end = $p + strlen($label . $n);
                    if (substr($s, $end, 1) !== ')') $s = substr($s, 0, $end) . ')' . substr($s, $end);
                }
            }
        });
    }
    return array($doc, $changes);
}

if (in_array('--selftest', $argv, true)) {
    $fails = 0;
    $t = function ($name, $ok) use (&$fails) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) $fails++; };
    $t('a dated bracket is cut', kop_fcn_clean('Arizona Boys Ranch (March 2000') === 'Arizona Boys Ranch');
    $t('a place bracket is cut', kop_fcn_clean('Integrity House (Cedar City, UT') === 'Integrity House');
    $t('a closed bracket is left', kop_fcn_clean('Solstice RTC (2008-2012)') === '');
    $t('no bracket is left', kop_fcn_clean('Turn-About Ranch') === '');
    $doc = array('identification' => array('name' => 'Canyon State Academy', 'pastNames' => array('Arizona Boys Ranch', 'Arizona Boys Ranch (March 2000'),
        'otherNames' => array('KIDS of Greater Salt Lake (KIDS Centers of America')),
        'notes' => array('Past name: Arizona Boys Ranch (March 2000 (HEAL, staff list: https://x)', 'Other note'));
    list($d, $c) = kop_fcn_fix($doc);
    $t('a cut name already listed comes off', $d['identification']['pastNames'] === array('Arizona Boys Ranch'));
    $t('a cut name not listed becomes the name', $d['identification']['otherNames'] === array('KIDS of Greater Salt Lake'));
    $t('the note line gets its bracket back', $d['notes'][0] === 'Past name: Arizona Boys Ranch (March 2000) (HEAL, staff list: https://x)');
    $t('other notes untouched', $d['notes'][1] === 'Other note' && count($c) === 2);
    list($d2, $c2) = kop_fcn_fix($d);
    $t('running twice changes nothing more', $c2 === array() && $d2 === $d);
    exit($fails ? 1 : 0);
}

$apply = in_array('apply', $argv, true);
$undo = in_array('undo', $argv, true);
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
$pdo = kop_seed_pdo();
$prefix = $pdo ? kop_seed_v2_prefix($pdo) : null;
if (!$pdo || $prefix === null) {
    fwrite(STDERR, "No records database connection, or facilities_v2 is not written\n");
    exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$opts = array('pdo' => $pdo, 'prefix' => $prefix);

if ($undo) {
    $log = get_option('kop_fix_cut_names_log', array());
    $n = 0;
    foreach ((array) $log as $id => $entry) {
        $now = kop_facility_load((int) $id, $opts);
        if (!$now) { echo "  #$id gone, skipped\n"; continue; }
        if (json_encode($now['doc']) !== $entry['after']) { echo "  #$id changed since the fix, skipped (put it back by hand)\n"; continue; }
        echo "  #$id {$now['unique_name']}: put back\n";
        if ($apply) kop_facility_save(json_decode($entry['before'], true), $opts);
        $n++;
    }
    if ($apply) delete_option('kop_fix_cut_names_log');
    echo ($apply ? '' : 'Dry run: ') . "$n records put back\n";
    exit(0);
}

$ids = $pdo->query("SELECT id FROM facilities_v2 WHERE json_data LIKE '%(%'")->fetchAll(PDO::FETCH_COLUMN);
$log = (array) get_option('kop_fix_cut_names_log', array());
$total = 0;
foreach ($ids as $id) {
    $rec = kop_facility_load((int) $id, $opts);
    if (!$rec) continue;
    list($doc, $changes) = kop_fcn_fix($rec['doc']);
    if (!$changes) continue;
    foreach ($changes as $c) {
        echo "  #{$rec['id']} {$rec['unique_name']}: {$c[0]} \"{$c[1]}\" -> " . ($c[2] === null ? 'taken off (already listed)' : "\"{$c[2]}\"") . "\n";
        $total++;
    }
    if ($apply) {
        kop_facility_save($doc, $opts);
        $saved = kop_facility_load((int) $id, $opts);
        $log[(int) $id] = array('before' => json_encode($rec['doc']), 'after' => json_encode($saved['doc']), 'at' => gmdate('c'));
        update_option('kop_fix_cut_names_log', $log, false);
    }
}
echo ($apply ? '' : 'Dry run: ') . "$total cut names fixed\n";
