<?php
/**
 * The news discovery's daily list (inc/discovery-priority.php) against
 * tmp/prod.sqlite, read only: the automatic rule, the switches (record and
 * company), the names discovery gets, the pencil.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-discovery-priority.php [--list]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

$theme = dirname(__DIR__);
$db = $theme . '/tmp/prod.sqlite';
if (!is_file($db)) exit("No tmp/prod.sqlite (python ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
$pdo = new PDO('sqlite:' . $db, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$list = in_array('--list', $argv, true);

define('ABSPATH', $theme . '/');
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['opts'] = array();
function add_action() {}
function add_filter() {}
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['opts'][$k] = $v; return true; }
function get_transient() { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function kop_ie_field($name, $label, $type, $value, $extra = array()) { return array_merge(compact('name', 'label', 'type', 'value'), $extra); }
function kop_seed_pdo() { return $GLOBALS['pdo']; }
$GLOBALS['pdo'] = $pdo;
$GLOBALS['wpdb'] = (object) array('prefix' => 'wpdl_');
require $theme . '/inc/discovery-priority.php';

$fails = 0;
$check = function ($name, $ok, $detail = '') use (&$fails) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' -- ' . $detail) . "\n";
    if (!$ok) $fails++;
};
$status = function ($id) use ($pdo) { $s = $pdo->prepare('SELECT status FROM facilities_v2 WHERE id = ?'); $s->execute(array($id)); return $s->fetchColumn(); };

$auto = kop_dp_auto($pdo, 'wpdl_', null, array());
$why = array();
foreach ($auto as $r) foreach ($r as $w) $why[$w] = ($why[$w] ?? 0) + 1;
echo 'automatic: ' . count($auto) . ' records ' . json_encode($why) . "\n";
$check('the rule picks a few hundred records at most, not thousands', count($auto) > 50 && count($auto) < 400, (string) count($auto));
$check('every pick is open', !array_filter(array_keys($auto), function ($id) use ($status) { return $status($id) !== 'Open'; }));
$check('news and lawsuits both count', !empty($why['news']) && !empty($why['lawsuit']));

// An approved serious finding counts through the rollup's record -> sites map.
$st = $pdo->query("SELECT facility_id FROM inspection_highlights WHERE status = 'approved' AND finding_date >= '" . gmdate('Y-m-d', strtotime('-' . kop_dp_years() . ' years')) . "' LIMIT 1");
$site = (int) $st->fetchColumn();
$closedOnly = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE status = 'Open' ORDER BY id LIMIT 1 OFFSET 2000")->fetchColumn();
$withFinding = kop_dp_auto($pdo, 'wpdl_', null, array($closedOnly => array('sites' => array($site))));
$check('a serious finding at one of its inspection sites puts a record on', $site && in_array('finding', $withFinding[$closedOnly] ?? array(), true));
$old = $pdo->query("SELECT facility_id FROM inspection_highlights WHERE status = 'approved' AND finding_date < '2015-01-01' AND finding_date != '' LIMIT 1")->fetchColumn();
if ($old) {
    $noFinding = kop_dp_auto($pdo, 'wpdl_', null, array($closedOnly => array('sites' => array((int) $old))));
    $check('an old finding does not', !in_array('finding', $noFinding[$closedOnly] ?? array(), true));
}

// Switches.
$offId = (int) array_key_first($auto);
$closed = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE status = 'Closed' LIMIT 1")->fetchColumn();
kop_dp_set('f', $offId, 'off', 'test');
kop_dp_set('f', $closed, 'on', 'test');
$ids = kop_dp_ids($pdo, 'wpdl_', array());
$check('Never daily takes a picked record off', !isset($ids[$offId]));
$check('Daily puts any record on, closed too', ($ids[$closed] ?? '') === 'by hand');
$op = $pdo->query("SELECT l.operator_id, COUNT(*) n FROM wpdl_kop_operator_facilities l JOIN facilities_v2 f ON f.id = l.facility_id
    WHERE l.relationship = 'current' AND f.status = 'Open' GROUP BY l.operator_id ORDER BY n DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
kop_dp_set('o', (int) $op['operator_id'], 'on', 'test');
$ids2 = kop_dp_ids($pdo, 'wpdl_', array());
$progs = $pdo->query("SELECT l.facility_id FROM wpdl_kop_operator_facilities l JOIN facilities_v2 f ON f.id = l.facility_id
    WHERE l.operator_id = " . (int) $op['operator_id'] . " AND l.relationship = 'current' AND f.status = 'Open'")->fetchAll(PDO::FETCH_COLUMN);
$check('a company set to Daily puts its current open programs on', !array_diff(array_map('intval', $progs), array_diff(array_keys($ids2), array($offId))), count($progs) . ' programs');
kop_dp_set('f', (int) $progs[0], 'off', 'test');
$check("a program's own Never daily wins over its company", !isset(kop_dp_ids($pdo, 'wpdl_', array())[(int) $progs[0]]));
$names = kop_dp_names($pdo, $ids2, 'wpdl_');
$opName = $pdo->query('SELECT name FROM wpdl_kop_operators WHERE id = ' . (int) $op['operator_id'])->fetchColumn();
$check("the company's own name is searched too", in_array($opName, $names, true), (string) $opName);
$past = $pdo->query("SELECT id, json_data FROM facilities_v2 WHERE json_data LIKE '%\"pastNames\":[\"%' AND status = 'Open'")->fetchAll(PDO::FETCH_ASSOC);
$pastRow = null;
foreach ($past as $r) { if (isset($ids2[(int) $r['id']])) { $pastRow = $r; break; } }
if ($pastRow) {
    $pn = json_decode($pastRow['json_data'], true)['identification']['pastNames'][0] ?? '';
    $check('a past name is searched too', $pn !== '' && in_array(trim($pn), $names, true), $pn);
}
$log = get_option('kop_discovery_priority_log');
$check('every switch is logged with what it was', count($log) === 4 && $log[0]['from'] === 'auto' && $log[0]['to'] === 'off');
$check('Automatic clears a switch', kop_dp_set('f', $offId, 'auto') === 'off' && !isset(kop_dp_overrides()['f'][$offId]));

// The pencil.
$form = kop_dp_ie_load(array('f', $closed));
$check('the pencil shows the record as on, by hand', $form['fields'][0]['value'] === 'on' && strpos($form['help'], 'set by hand') !== false, $form['help']);
$saved = kop_dp_ie_save(array('f', $closed), array('mode' => 'auto'));
$check('the pencil saves', strpos($saved['message'], 'Set to Automatic') === 0 && !isset(kop_dp_overrides()['f'][$closed]));
$threw = false;
try { kop_dp_ie_save(array('o', 5), array('mode' => 'off')); } catch (RuntimeException $e) { $threw = true; }
$check('a company has no Never daily', $threw);

// What discovery gets with no switches.
$GLOBALS['opts'] = array();
$all = kop_dp_names($pdo, kop_dp_ids($pdo, 'wpdl_', array()), 'wpdl_');
echo 'names on the daily list: ' . count($all) . "\n";
$check('the daily list fits the day (~155 searches: at most ~350 names; the mirror has no findings map)', count($all) < 350, (string) count($all));
if ($list) foreach ($all as $n) echo "  $n\n";

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
