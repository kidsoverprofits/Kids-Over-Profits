<?php
/**
 * A facility's most popular name (identification.popularName, the name people
 * know it by) and the search previews' other names and places, against a
 * scratch copy of tmp/prod.sqlite (the mirror is never written).
 *
 *   php -d extension=mbstring -d extension=pdo_sqlite scripts/test-popular-name.php
 *
 * Checks: the store keeps the pick only while it is one of the record's names
 * (PHP and the data form's JS normalizer alike); the page's name lines with a
 * past, current or other name picked; the names pencil and the "Names and eras"
 * dialog save it (exact Undo on the dialog); the facility page, the directory
 * feed's slim copy, the site search and kop_facility_shown_name() show it; the
 * search results carry other names and places (kop_v2_search_preview_lines()).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$prod = dirname(__DIR__) . '/tmp/prod.sqlite';
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
// The tables a save writes are copied; every other table is read from the mirror, attached below.
$db_path = sys_get_temp_dir() . '/kop-popular-name-' . getmypid() . '.sqlite';
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
$copy->exec('DETACH DATABASE prod');
$copy->exec("CREATE TRIGGER facilities_v2_cols AFTER UPDATE OF json_data ON facilities_v2 BEGIN
    UPDATE facilities_v2 SET name = json_extract(NEW.json_data, '$.identification.name') WHERE id = NEW.id; END");
$copy = null;
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; @unlink($db_path); });

$GLOBALS['kop_test_wpdb_writes'] = true;
function kop_v2_writes_active($pdo, $prefix, $refresh = false) { return true; }
if (!function_exists('wp_doing_ajax')) { function wp_doing_ajax() { return false; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new class { public $user_login = 'test'; public $display_name = 'Test'; public function exists() { return true; } }; }
}
if (!function_exists('wp_generate_password')) { function wp_generate_password($n = 12) { return substr(md5(uniqid('', true)), 0, $n); } }
require __DIR__ . '/kop-test-harness.php';
// Unqualified names not in the scratch copy (news, lawsuits, the options...) resolve to the mirror.
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($prod) . ' AS prod');
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
require_once dirname(__DIR__) . '/inc/inline-edit.php';
require_once dirname(__DIR__) . '/inc/directory-names.php';
require_once dirname(__DIR__) . '/inc/name-eras.php';
require_once dirname(__DIR__) . '/inc/network-renames.php';
require_once dirname(__DIR__) . '/inc/facility-eras-editor.php';
require_once dirname(__DIR__) . '/inc/directory-feed.php';
require_once dirname(__DIR__) . '/inc/ajax-search-lite.php';
require_once dirname(__DIR__) . '/inc/global-search.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' && !$ok ? "  ($detail)" : '') . "\n";
};
$opts = array('pdo' => $pdo, 'prefix' => 'wpdl_');
$doc_of = function ($fid) use ($opts) { return kop_facility_load((int) $fid, $opts)['doc']; };
// Caches the saves leave behind in this one process (production gets a new request).
$fresh = function () {
    $GLOBALS['kop_test_transients'] = array();
    if (function_exists('kop_facility_pages_flush_index')) kop_facility_pages_flush_index();
};
$search_hit = function ($phrase, $fid) {
    foreach (kop_v2_search($phrase, 10, 0, 0)['facilities'] as $f) {
        if ((int) $f['id'] === (int) $fid) return $f;
    }
    return null;
};

echo "-- The store keeps only one of the record's own names --\n";
$ident = array('name' => 'Sunrise Academy', 'currentName' => 'Sunrise RTC', 'pastNames' => array('Old Sunrise School'), 'otherNames' => array('SA'));
$check('a past name is kept, spelled as the record spells it', kop_facility_popular_name_clean('old sunrise school', $ident) === 'Old Sunrise School');
$check('the name it uses now is kept', kop_facility_popular_name_clean('Sunrise RTC', $ident) === 'Sunrise RTC');
$check('an also-known-as name is kept', kop_facility_popular_name_clean('SA', $ident) === 'SA');
$check('the record\'s own name is the default, stored as nothing', kop_facility_popular_name_clean('Sunrise Academy', $ident) === '');
$check('a name the record does not have is dropped', kop_facility_popular_name_clean('Somewhere Else', $ident) === '');
$n = kop_facility_normalize(array('identification' => $ident + array('popularName' => 'Old Sunrise School')));
$check('the normalizer keeps a valid pick', ($n['identification']['popularName'] ?? null) === 'Old Sunrise School');
$n = kop_facility_normalize(array('identification' => $ident + array('popularName' => 'Nope')));
$check('...and leaves the key out otherwise (stored shape unchanged)', !array_key_exists('popularName', $n['identification']));
$n = kop_facility_normalize(array('identification' => $ident));
$check('a record with none has no popularName key', !array_key_exists('popularName', $n['identification']));
$legacy = kop_facility_to_legacy(kop_facility_normalize(array('identification' => $ident + array('popularName' => 'SA'))));
$check('the legacy copy (directory feed, forms) carries it', ($legacy['identification']['popularName'] ?? null) === 'SA');
$check('kop_facility_display_name() prefers it', kop_facility_display_name(array('name' => 'A', 'popularName' => 'B')) === 'B' && kop_facility_display_name(array('name' => 'A')) === 'A');

// The data form's JS normalizer must agree (js/data-form-modules/data-normalizer.js facilityToV2()).
$cases = array(
    array('identification' => $ident + array('popularName' => 'old sunrise school')),
    array('identification' => $ident + array('popularName' => 'Nope')),
    array('identification' => $ident + array('popularName' => 'Sunrise Academy')),
    array('identification' => $ident),
);
$php_out = array();
foreach ($cases as $c) {
    $d = kop_facility_normalize($c);
    $php_out[] = $d['identification']['popularName'] ?? null;
}
$js = <<<'JS'
const fs = require('fs'), vm = require('vm'), path = require('path');
const sandbox = { window: {}, console };
sandbox.window.window = sandbox.window;
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(process.argv[2], 'js', 'data-form-modules', 'data-normalizer.js'), 'utf8'), sandbox);
const cases = JSON.parse(fs.readFileSync(0, 'utf8'));
const out = cases.map(c => { const d = sandbox.window.KOP_DataNormalizer.facilityToV2(c); return Object.prototype.hasOwnProperty.call(d.identification, 'popularName') ? d.identification.popularName : null; });
process.stdout.write(JSON.stringify(out));
JS;
$js_file = sys_get_temp_dir() . '/kop-popular-name-' . getmypid() . '.js';
file_put_contents($js_file, $js);
$proc = proc_open(array('node', $js_file, dirname(__DIR__)), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
if (is_resource($proc)) {
    fwrite($pipes[0], json_encode($cases));
    fclose($pipes[0]);
    $js_out = json_decode(stream_get_contents($pipes[1]), true);
    $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $check('the JS normalizer keeps and drops the same picks', $js_out === $php_out, json_encode($js_out) . ' vs ' . json_encode($php_out) . ' ' . trim($err));
} else {
    echo "(node not found: JS normalizer check skipped)\n";
}
@unlink($js_file);

// The network map labels a node with it (js/network-map/store.js labelByPopularName(); the build stamps popularName).
$map_js = <<<'JS'
const S = require(process.argv[2] + '/js/network-map/store.js');
const mk = (o) => Object.assign({ name: 'Board Name', formerNames: [], otherNames: [], currentName: '' }, o);
const a = mk({ formerNames: ['Old Name', 'Older'] }); S.labelByPopularName(a, 'Old Name');
const b = mk({ otherNames: ['Nick'] }); S.labelByPopularName(b, 'Nick');
const c = mk({ currentName: 'New Name' }); S.labelByPopularName(c, 'New Name');
const d = mk({}); S.labelByPopularName(d, '');
process.stdout.write(JSON.stringify([a, b, c, d]));
JS;
$map_file = sys_get_temp_dir() . '/kop-popular-map-' . getmypid() . '.js';
file_put_contents($map_file, $map_js);
$out = json_decode((string) shell_exec('node ' . escapeshellarg($map_file) . ' ' . escapeshellarg(str_replace('\\', '/', dirname(__DIR__)))), true);
@unlink($map_file);
if (is_array($out)) {
    list($a, $b, $c, $d) = $out;
    $check('map: a past name picked labels the node; the board name is what it is called now', $a['name'] === 'Old Name' && $a['currentName'] === 'Board Name' && $a['formerNames'] === array('Older'), json_encode($a));
    $check('map: an other name picked; the board name joins its other names', $b['name'] === 'Nick' && $b['otherNames'] === array('Board Name') && $b['currentName'] === '', json_encode($b));
    $check('map: the name it uses now picked; the board name becomes a former name', $c['name'] === 'New Name' && $c['currentName'] === '' && $c['formerNames'] === array('Board Name'), json_encode($c));
    $check('map: no pick leaves the node alone', $d['name'] === 'Board Name' && !isset($d['boardName']), json_encode($d));
} else {
    echo "(node not found: map label check skipped)\n";
}

echo "-- The page's name lines --\n";
list($t, $now, $was, $later, $aka) = kop_facility_pages_popular_name_lists('', 'Sunrise Academy', '', array('Old Sunrise School'), array(), array('SA'));
$check('no pick: the lines are as they were', $t === 'Sunrise Academy' && $now === '' && $was === array('Old Sunrise School') && $aka === array('SA'));
list($t, $now, $was, $later, $aka) = kop_facility_pages_popular_name_lists('Old Sunrise School', 'Sunrise Academy', '', array('Old Sunrise School', 'Older'), array(), array('SA'));
$check('a past name picked: it is the title, the record\'s name is "Now known as"', $t === 'Old Sunrise School' && $now === 'Sunrise Academy' && $was === array('Older') && $aka === array('SA'), json_encode(array($t, $now, $was, $aka)));
list($t, $now, $was, $later, $aka) = kop_facility_pages_popular_name_lists('Old Sunrise School', 'Sunrise Academy', 'Sunrise RTC', array('Old Sunrise School'), array(), array());
$check('...with a name it uses now, that stays "Now known as" and the record\'s name is "Formerly"', $t === 'Old Sunrise School' && $now === 'Sunrise RTC' && $was === array('Sunrise Academy'), json_encode(array($t, $now, $was)));
list($t, $now, $was, $later, $aka) = kop_facility_pages_popular_name_lists('Sunrise RTC', 'Sunrise Academy', 'Sunrise RTC', array('Old'), array(), array());
$check('the name it uses now picked: the record\'s name joins "Formerly"', $t === 'Sunrise RTC' && $now === '' && $was === array('Sunrise Academy', 'Old'), json_encode(array($t, $now, $was)));
list($t, $now, $was, $later, $aka) = kop_facility_pages_popular_name_lists('SA', 'Sunrise Academy', '', array('Old'), array(), array('SA', 'Sunny'));
$check('an also-known-as name picked: the record\'s name is "Also known as"', $t === 'SA' && $now === '' && $was === array('Old') && $aka === array('Sunrise Academy', 'Sunny'), json_encode(array($t, $now, $was, $aka)));

echo "-- A real record: Chrysalis Therapeutic Boarding School (#12684), past name Chrysalis Academy --\n";
$fid = 12684;
$doc = $doc_of($fid);
$own = (string) $doc['identification']['name'];
$past = 'Chrysalis Academy';
$check('the mirror still has the record and its past name', $own === 'Chrysalis Therapeutic Boarding School' && in_array($past, (array) $doc['identification']['pastNames'], true), $own);
$start = $doc;

// The names pencil (facility:<id>:names).
$form = kop_ie_facility_load(array($fid, 'names'));
$field = null;
foreach ($form['fields'] as $f) if ($f['name'] === 'identification.popularName') $field = $f;
$values = $field ? array_column($field['options'], 'value') : array();
$check('the names pencil offers the record\'s own name (default) and its past name', $field && $values[0] === '' && in_array($past, $values, true), json_encode($values));
$threw = false;
try { kop_ie_facility_save(array($fid, 'names'), array('identification.popularName' => 'Not One Of Its Names')); } catch (RuntimeException $e) { $threw = true; }
$check('...and refuses a name that is not one of them', $threw);
kop_ie_facility_save(array($fid, 'names'), array('identification.popularName' => $past));
$fresh();
$check('saved through the names pencil', ($doc_of($fid)['identification']['popularName'] ?? '') === $past);
// Its names as stored (any save also fills fields an older record lacks, such as legalName).
$names_of = function (array $ident) {
    return array_intersect_key($ident, array_flip(array('name', 'currentName', 'pastNames', 'otherNames', 'popularName')));
};
$check('...its other names are as they were', array_diff_key($names_of($doc_of($fid)['identification']), array('popularName' => 1)) == $names_of($start['identification']));

$page = kop_facility_page_data($fid);
$check('the facility page is titled by the popular name', $page['name'] === $past && $page['record_name'] === $own, $page['name']);
$check('...the record\'s name reads "Now known as"', $page['current_name'] === $own, $page['current_name']);
$check('...and the popular name is not repeated under "Formerly"', !in_array($past, $page['formerly'], true), json_encode($page['formerly']));
$check('...the browser title starts with it', strpos($page['seo_title'], $past) === 0, $page['seo_title']);
$check('kop_facility_shown_name() (lists that read only the name column)', kop_facility_shown_name($fid, $own) === $past && kop_facility_shown_name(10271, 'Tobinworld') === 'Tobinworld');
$slim = kop_directory_feed_slim_facility(kop_facility_to_legacy($doc_of($fid)));
$check('the directory feed\'s slim copy carries it', ($slim['identification']['popularName'] ?? '') === $past);

$hit = $search_hit('Chrysalis Academy', $fid);
$check('searching the popular name finds it under that name', $hit && $hit['display'] === $past, $hit ? $hit['display'] : 'no hit');
$check('...with no "Formerly" hint for the name in its own title', $hit && $hit['matched_name'] === null, $hit ? json_encode($hit['matched_name']) : '');
$check('...its preview names the record\'s own name', $hit && in_array('Now known as ' . $own, $hit['names'], true), $hit ? json_encode($hit['names']) : '');
$hit = $search_hit('Chrysalis Therapeutic', $fid);
$check('searching the record\'s own name still finds it, under the popular name', $hit && $hit['display'] === $past, $hit ? $hit['display'] : 'no hit');
$check('...and says which name matched', $hit && $hit['matched_name'] === $own && kop_v2_search_alias_hint($hit) === 'Now known as ' . $own, $hit ? json_encode(array($hit['matched_name'], $hit['matched_kind'])) : '');

// The "Names and eras" dialog (eras:<id>): clear it, Undo puts it back.
$form = kop_eras_ed_load(array($fid));
$pop = null;
foreach ($form['fields'] as $f) if ($f['name'] === 'popular') $pop = $f;
$check('the "Names and eras" dialog has the pick, the stored name selected', $pop && $pop['value'] === $past, $pop ? json_encode($pop['value']) : 'missing');
$rows = $form['fields'][0]['value'];
$res = kop_eras_ed_save(array($fid), array('eras' => $rows, 'popular' => ''));
$fresh();
$check('the dialog clears it', !isset($doc_of($fid)['identification']['popularName']) && strpos((string) ($res['undo'] ?? ''), 'erasundo:') === 0, json_encode($res));
$check('...and the page is titled by the record\'s name again', kop_facility_page_data($fid)['name'] === $own);
kop_eras_ed_undo_save(array(substr($res['undo'], strlen('erasundo:'))), array());
$fresh();
$check('Undo puts the pick back', ($doc_of($fid)['identification']['popularName'] ?? '') === $past);
$res = kop_eras_ed_save(array($fid), array('eras' => $rows));
$check('a dialog that does not send the pick leaves it alone', ($doc_of($fid)['identification']['popularName'] ?? '') === $past);
$threw = false;
try { kop_eras_ed_save(array($fid), array('eras' => $rows, 'popular' => 'Somewhere Else')); } catch (RuntimeException $e) { $threw = true; }
$check('...and refuses a name that is not one of its names', $threw);
// A past name renamed in its row takes the pick along.
$renamed = array();
foreach ($rows as $r) {
    if ($r['name'] === $past) $r['name'] = 'Chrysalis Academy of Montana';
    $renamed[] = $r;
}
$res = kop_eras_ed_save(array($fid), array('eras' => $renamed, 'popular' => $past));
$fresh();
$check('a renamed past name keeps its place as the popular name', ($doc_of($fid)['identification']['popularName'] ?? '') === 'Chrysalis Academy of Montana', json_encode($doc_of($fid)['identification']));
kop_eras_ed_undo_save(array(substr($res['undo'], strlen('erasundo:'))), array());
kop_ie_facility_save(array($fid, 'names'), array('identification.popularName' => ''));
$fresh();
$check('cleared with the names pencil: its names are as they were', $names_of($doc_of($fid)['identification']) == $names_of($start['identification']), json_encode($doc_of($fid)['identification']));

echo "-- Search previews: other names and places --\n";
$hit = $search_hit('Chrysalis Therapeutic', $fid);
$check('a facility result lists its past names', $hit && in_array('Formerly ' . $past, $hit['names'], true), $hit ? json_encode($hit['names']) : 'no hit');
$check('...and its former locations', $hit && (bool) array_filter($hit['places'], function ($p) { return strpos($p, 'Formerly in Billings') === 0; }), $hit ? json_encode($hit['places']) : '');
$lines = $hit ? kop_v2_search_preview_lines($hit) : array();
$check('...as two short lines', count($lines) === 2 && strpos($lines[0], 'Formerly ' . $past) !== false, json_encode($lines));
$hit = $search_hit('Stone Mountain School', 11656);
$check('an old name hit puts the name that matched first', $hit && ($hit['names'][0] ?? '') === 'Formerly Stone Mountain School', $hit ? json_encode($hit['names']) : 'no hit');
$check('...and lists its other names after', $hit && in_array('Also known as Talisman School', $hit['names'], true), $hit ? json_encode($hit['names']) : '');
$capped = kop_v2_search_preview_lines(array('names' => array('a', 'b', 'c', 'd', 'e', 'f'), 'places' => array('p')), 4, 3);
$check('long name lists end "and N more"', $capped === array('a; b; c; d; and 2 more', 'p'), json_encode($capped));
$check('a city that is a sentence fragment falls back to the state', kop_v2_search_place_line(array('city' => 'OH in 1993', 'state' => 'OH')) === 'OH');
$op = kop_v2_search('Eckerd', 0, 5, 0)['operators'];
$eck = null;
foreach ($op as $o) if (stripos($o['display'], 'Eckerd') !== false) $eck = $o;
$check('a company result lists its other names', $eck && in_array('Also known as Eckerd Youth Alternatives', $eck['names'], true), $eck ? json_encode($eck['names']) : 'no hit');
$opv = kop_v2_search_operator_preview(array('headquarters' => 'Nashville, TN', 'pastNames' => array('Old Co')), 'New Co');
$check('...and its headquarters', $opv['places'] === array('Based in Nashville, TN') && $opv['names'] === array('Formerly Old Co'), json_encode($opv));
$check('a company\'s "Based in" line is left out where its location is printed already', kop_v2_search_preview_lines(array('kind' => 'operator', 'names' => array(), 'places' => array('Based in X')), 4, 3, true) === array());

// The three renderers.
$items = kop_asl_collect_database_matches('Stone Mountain School');
$row = null;
foreach ($items as $it) if (strpos($it['meta'], 'Formerly Stone Mountain School') !== false) $row = $it;
$check('the header dropdown\'s line carries the names and places', $row !== null, json_encode(array_column($items, 'meta')));
$groups = kop_global_search_collect('Stone Mountain School');
$item = null;
foreach ($groups as $g) foreach ($g['items'] as $it) if (!empty($it['names']) && in_array('Formerly Stone Mountain School', $it['names'], true)) $item = $it;
$check('the popup gets the names and places as lists', $item !== null && isset($item['places']));

echo $failures ? "\n{$failures} failed.\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
