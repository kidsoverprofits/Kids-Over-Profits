<?php
/**
 * The facility page's "Names and eras" pencil (inc/facility-eras-editor.php),
 * against a scratch copy of tmp/prod.sqlite (the mirror is never written).
 *
 *   php -d extension=mbstring -d extension=pdo_sqlite scripts/test-eras-editor.php
 *
 * Three kinds of record: one with past names only (cut by the hand-set eras
 * alone), one merged as a rename (Canyon State Academy, its rename entry
 * written), one the network map joins to another name (the Map Renames
 * decision written). Each save is checked on the page's name sections and
 * undone exactly; then the refusals.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$prod = dirname(__DIR__) . '/tmp/prod.sqlite';
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
$db_path = sys_get_temp_dir() . '/kop-eras-editor-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec('ATTACH DATABASE ' . $copy->quote($prod) . ' AS prod');
foreach (array('facilities_v2', 'wpdl_kop_facility_identity', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_locations', 'wpdl_kop_operators', 'wpdl_kop_name_eras') as $t) {
    $sql = $copy->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) {
        fwrite(STDERR, "The mirror has no $t (sync it).\n");
        exit(2);
    }
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.$t SELECT * FROM prod.$t");
}
$decisions_raw = $copy->query("SELECT option_value FROM prod.wpdl_options WHERE option_name = 'kop_network_rename_review'")->fetchColumn();
$copy->exec('DETACH DATABASE prod');
$copy->exec("CREATE TRIGGER facilities_v2_cols AFTER UPDATE OF json_data ON facilities_v2 BEGIN
    UPDATE facilities_v2 SET name = json_extract(NEW.json_data, '$.identification.name') WHERE id = NEW.id; END");
$copy = null;

$GLOBALS['kop_test_wpdb_writes'] = true;
function kop_v2_writes_active($pdo, $prefix, $refresh = false) { return true; }
if (!function_exists('wp_doing_ajax')) { function wp_doing_ajax() { return false; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new class { public $user_login = 'test'; public $display_name = 'Test'; public function exists() { return true; } }; }
}
if (!function_exists('wp_generate_password')) { function wp_generate_password($n = 12) { return substr(md5(uniqid('', true)), 0, $n); } }
require __DIR__ . '/kop-test-harness.php';
$pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
    if (!is_string($v)) return $v;
    $d = json_decode($v);
    return is_string($d) ? $d : $v;
}, 1);
if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }
$GLOBALS['pdo'] = $pdo;
$GLOBALS['kop_test_options']['kop_name_eras_db'] = '1';
$GLOBALS['kop_test_options']['kop_network_rename_review'] = ($d = @unserialize((string) $decisions_raw)) && is_array($d) ? $d : array();
require_once dirname(__DIR__) . '/inc/inline-edit.php';
require_once dirname(__DIR__) . '/inc/directory-names.php';
require_once dirname(__DIR__) . '/inc/name-eras.php';
require_once dirname(__DIR__) . '/inc/network-renames.php';
require_once dirname(__DIR__) . '/inc/facility-eras-editor.php';
// The filter the file registers, as WordPress would run it.
$GLOBALS['kop_test_filters']['kop_inline_edit_sources'] = array_merge(
    array('eras' => array('load' => 'kop_eras_ed_load', 'save' => 'kop_eras_ed_save'), 'erasundo' => array('load' => 'kop_eras_ed_undo_load', 'save' => 'kop_eras_ed_undo_save')),
    array()
);

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' && !$ok ? "  ($detail)" : '') . "\n";
};
$opts = array('pdo' => $pdo, 'prefix' => 'wpdl_');
// A record as data: a save writes its keys in the normalizer's order, and stamps when it was saved.
$canon = function ($v) use (&$canon) {
    if (!is_array($v)) return $v;
    $assoc = array_keys($v) !== range(0, count($v) - 1);
    if ($assoc) ksort($v);
    foreach ($v as $k => $x) $v[$k] = $canon($x);
    return $v;
};
$snapshot = function () use ($pdo, $canon) {
    $docs = array();
    // The records under test as data; every other row as it is stored, so a stray write shows too.
    foreach ($pdo->query('SELECT id, json_data FROM facilities_v2 ORDER BY id')->fetchAll(PDO::FETCH_NUM) as list($id, $json)) {
        if (!isset($GLOBALS['kop_test_watch'][(int) $id])) {
            $docs[$id] = md5((string) $json);
            continue;
        }
        $d = json_decode((string) $json, true);
        if (is_array($d)) unset($d['provenance']['updatedAt'], $d['provenance']['savedAt'], $d['provenance']['lastSaved']);
        $docs[$id] = md5(json_encode($canon($d)));
    }
    return md5(json_encode(array(
        $docs,
        $pdo->query('SELECT facility_id, name, name_key, operator_id, operator_name, start_year, end_year FROM wpdl_kop_name_eras ORDER BY facility_id, name_key')->fetchAll(PDO::FETCH_NUM),
        $GLOBALS['kop_test_options']['kop_network_rename_review'] ?? array(),
    )));
};
$fresh = function () {
    $GLOBALS['kop_test_transients'] = array();
    kop_name_eras_all(true);
    kop_facility_eras_map(true);
};
$doc_of = function ($id) use ($pdo) {
    return json_decode((string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $id)->fetchColumn(), true);
};
/** The dialog's rows, as the browser posts them back untouched. */
$load = function ($fid) {
    $f = kop_eras_ed_load(array((string) $fid))['fields'][0];
    $out = array();
    foreach ($f['value'] as $r) $out[] = array('__i' => $r['__i'], 'name' => $r['name'], 'company' => $r['company'], 'from' => $r['from'], 'to' => $r['to']);
    return $out;
};
$save = function ($fid, array $rows) use ($fresh) {
    $res = kop_eras_ed_save(array((string) $fid), array('eras' => $rows));
    $fresh();
    return $res;
};
$undo = function ($res) use ($fresh) {
    list($src, $parts) = kop_ie_resolve($res['undo']);
    $out = call_user_func($src['save'], $parts, array());
    $fresh();
    return $out;
};
$refused = function ($fn) {
    try {
        $fn();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return '';
};
$names = function ($chain) { return array_map(function ($e) { return $e['name']; }, $chain); };

// ---------------------------------------------------------------------------
echo "-- A record with past names only --\n";
$plain = 0;
foreach ($pdo->query('SELECT id, json_data FROM facilities_v2 ORDER BY id')->fetchAll(PDO::FETCH_NUM) as list($id, $json)) {
    $doc = json_decode($json, true);
    $past = (array) ($doc['identification']['pastNames'] ?? array());
    if (count($past) !== 1 || !is_string($past[0]) || strpos($past[0], ',') !== false || !empty($doc['legacy']['mergedFacilities'])) continue;
    if (kop_name_eras_for((int) $id) || kop_facility_eras_chain((int) $id, $doc)) continue;
    if (!empty(kop_facility_eras_map()['facility'][(int) $id])) continue;
    $plain = (int) $id;
    break;
}
$check('found one', $plain > 0);
$GLOBALS['kop_test_watch'][$plain] = true;
$doc = $doc_of($plain);
$own = $doc['identification']['name'];
$old = $doc['identification']['pastNames'][0];
echo "  #$plain $own, formerly $old\n";
$rows = $load($plain);
$check('the dialog lists the past name, then the record\'s own name', array_column($rows, 'name') === array($old, $own), json_encode(array_column($rows, 'name')));
$start = $snapshot();
$check('saved untouched, nothing changes', ($save($plain, $rows)['message'] ?? '') === 'Nothing to change.' && $snapshot() === $start);

$rows[0]['company'] = 'Test Company Inc.';
$rows[0]['from'] = '1990';
$rows[0]['to'] = '2005';
$rows[1]['from'] = '2005';
$res = $save($plain, $rows);
$check('a save offers Undo', strpos((string) ($res['undo'] ?? ''), 'erasundo:') === 0, json_encode($res));
$chain = kop_facility_eras_chain($plain, $doc_of($plain));
$check('the page is cut into the two names at 2005', $names($chain) === array($old, $own) && (int) $chain[1]['cut'] === 2005, json_encode($chain));
$check('the earlier name carries its company and years', $chain[0]['operators'] === array('Test Company Inc.') && $chain[0]['start'] === 1990 && $chain[0]['end'] === 2005);
$label = kop_name_eras_label(kop_name_eras_for($plain)[kop_name_eras_key($old)]);
$check('"Formerly" reads "Test Company Inc., 1990 to 2005"', $label === 'Test Company Inc., 1990 to 2005', $label);
$rows = $load($plain);
$check('the dialog reopens with what was saved', $rows[0]['company'] === 'Test Company Inc.' && $rows[0]['from'] === '1990' && $rows[1]['from'] === '2005', json_encode($rows));
$undo($res);
$check('Undo puts everything back', $snapshot() === $start);
$check('...and the page is whole again', kop_facility_eras_chain($plain, $doc_of($plain)) === array());

// A name the record did not have, and taking one off.
$rows = $load($plain);
$rows[] = array('__i' => '', 'name' => 'Test Earliest Name', 'company' => '', 'from' => '1980', 'to' => '1989');
$res = $save($plain, $rows);
$past = $doc_of($plain)['identification']['pastNames'];
$check('a new row is added to the past names', in_array('Test Earliest Name', $past, true) && in_array($old, $past, true), json_encode($past));
$rows = $load($plain);
$check('...and opens first, by its years', $rows[0]['name'] === 'Test Earliest Name', json_encode(array_column($rows, 'name')));
$undo($res);
$check('Undo takes it off again', $snapshot() === $start);
$rows = array_values(array_filter($load($plain), function ($r) use ($old) { return $r['name'] !== $old; }));
$res = $save($plain, $rows);
$check('Remove takes a past name off the record', !in_array($old, (array) ($doc_of($plain)['identification']['pastNames'] ?? array()), true));
$undo($res);
$check('Undo puts it back', $snapshot() === $start);
// Renaming a past name in its row.
$rows = $load($plain);
$rows[0]['name'] = $old . ' Typo Fixed';
$res = $save($plain, $rows);
$past = $doc_of($plain)['identification']['pastNames'];
$check('a past name renamed in its row is renamed on the record', $past === array($old . ' Typo Fixed'), json_encode($past));
$undo($res);
$check('Undo renames it back', $snapshot() === $start);

// Refusals.
$rows = $load($plain);
$msg = $refused(function () use ($save, $plain, $rows) { $save($plain, array($rows[0])); });
$check('the record\'s own name cannot be removed', strpos($msg, 'own name') !== false, $msg);
$rows2 = $rows;
$rows2[0]['from'] = '2010';
$rows2[0]['to'] = '2001';
$msg = $refused(function () use ($save, $plain, $rows2) { $save($plain, $rows2); });
$check('a first year after the last is refused', strpos($msg, 'after the last') !== false, $msg);
$rows2 = $rows;
$rows2[] = array('__i' => '', 'name' => $own, 'company' => '', 'from' => '', 'to' => '');
$msg = $refused(function () use ($save, $plain, $rows2) { $save($plain, $rows2); });
$check('a name on two rows is refused', strpos($msg, 'two rows') !== false, $msg);
$rows2 = $rows;
$rows2[0]['from'] = '19x0';
$msg = $refused(function () use ($save, $plain, $rows2) { $save($plain, $rows2); });
$check('a year that is not a year is refused', $msg !== '', $msg);
$check('nothing refused was written', $snapshot() === $start);

// ---------------------------------------------------------------------------
echo "\n-- A program merged as a rename --\n";
$merged = 0;
foreach ($pdo->query("SELECT id FROM facilities_v2 WHERE name = 'Canyon State Academy'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    if (count(kop_facility_eras_doc_chain($doc_of($id))) >= 2) $merged = (int) $id;
}
$check('found Canyon State Academy (Arizona Boys Ranch)', $merged > 0);
if ($merged) {
    $GLOBALS['kop_test_watch'][$merged] = true;
    $rows = $load($merged);
    $check('the dialog lists the earlier name first', ($rows[0]['name'] ?? '') === 'Arizona Boys Ranch', json_encode(array_column($rows, 'name')));
    $start = $snapshot();
    $i = array_search('Canyon State Academy', array_column($rows, 'name'), true);
    $rows[0]['from'] = '1951';
    $rows[0]['to'] = '2011';
    $rows[$i]['from'] = '2011';
    $res = $save($merged, $rows);
    $m = null;
    foreach ($doc_of($merged)['legacy']['mergedFacilities'] as $x) if (($x['name'] ?? '') === 'Arizona Boys Ranch') $m = $x['rename'];
    $check('its rename entry has the years and the rename year', $m && (int) $m['startYear'] === 1951 && (int) $m['endYear'] === 2011 && (int) $m['year'] === 2011, json_encode($m));
    $chain = kop_facility_eras_chain($merged, $doc_of($merged));
    $check('the page cuts at 2011', count($chain) >= 2 && (int) $chain[1]['cut'] === 2011, json_encode($chain));
    $undo($res);
    $check('Undo puts the record and its eras back', $snapshot() === $start);
}

// ---------------------------------------------------------------------------
echo "\n-- Names the map joins --\n";
$mapped = 0;
foreach (kop_facility_eras_map()['facility'] as $fid => $nodes) {
    $chain = kop_facility_eras_found_chain((int) $fid, $doc_of($fid));
    if (count($chain) === 2 && $chain[0]['node'] !== '' && empty($doc_of($fid)['legacy']['mergedFacilities'])) {
        $mapped = (int) $fid;
        break;
    }
}
$check('found a record on a map rename line', $mapped > 0);
if ($mapped) {
    $GLOBALS['kop_test_watch'][$mapped] = true;
    $rows = $load($mapped);
    $chain = kop_facility_eras_found_chain($mapped, $doc_of($mapped));
    echo '  #' . $mapped . ' ' . implode(' -> ', $names($chain)) . "\n";
    $start = $snapshot();
    $at = array_flip(array_column($rows, 'name'));
    $a = $at[$chain[0]['name']];
    $b = $at[$chain[1]['name']];
    // Turn the line round: the later name first.
    $rows[$b]['from'] = '1990';
    $rows[$b]['to'] = '1999';
    $rows[$a]['from'] = '1999';
    $rows[$a]['to'] = '';
    $res = $save($mapped, $rows);
    $key = $chain[0]['node'] . '>' . $chain[1]['node'];
    $d = kop_network_renames_decisions();
    $saved = $d[$key] ?? ($d[$chain[1]['node'] . '>' . $chain[0]['node']] ?? null);
    $check('the Map Renames decision is saved with the year', $saved && $saved['decision'] === 'saved' && (int) $saved['year'] === 1999, json_encode($saved));
    $after = kop_facility_eras_chain($mapped, $doc_of($mapped));
    $check('...turned round, so the page reads the names the other way', $names($after) === array($chain[1]['name'], $chain[0]['name']), json_encode($names($after)));
    $undo($res);
    $check('Undo puts the decision and the eras back', $snapshot() === $start);
}

@unlink($db_path);
echo $failures ? "\n{$failures} failed.\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
