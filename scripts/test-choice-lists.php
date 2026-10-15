<?php
/**
 * Dropdown lists (inc/choice-lists.php): merging, the screen's form, Undo,
 * and every registered list's built-in choices read through kop_choices().
 *
 *   php -d extension=mbstring scripts/test-choice-lists.php [--list]
 *
 * Runs without WordPress: the store and log are kept in memory.
 */

$root = dirname(__DIR__);
$GLOBALS['kop_choice_test_store'] = array();
$GLOBALS['kop_choice_test_log'] = array();
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v); } }
if (!defined('ABSPATH')) define('ABSPATH', $root . '/');

require_once $root . '/inc/choice-lists.php';

$fail = 0;
$n = 0;
function ok($cond, $what) {
    global $fail, $n;
    $n++;
    if (!$cond) { $fail++; echo "FAIL: $what\n"; }
}

// --- keyed list, strings -----------------------------------------------------
kop_choice_register('t-keyed', array('label' => 'Test keyed', 'source' => 't_keyed'));
function t_keyed($mode = 'all') {
    return kop_choices('t-keyed', array('a' => 'Apple', 'b' => 'Banana', 'other' => 'Other'), $mode);
}
ok(t_keyed() === array('a' => 'Apple', 'b' => 'Banana', 'other' => 'Other'), 'untouched list is the defaults');
ok(kop_choice_defaults('t-keyed') === array('a' => 'Apple', 'b' => 'Banana', 'other' => 'Other'), 'defaults captured');

list($ch, $words, $err) = kop_choice_apply_form('t-keyed',
    array('a' => array('label' => 'Apples'), 'b' => array('label' => 'Banana', 'hidden' => 1), 'other' => array('label' => 'Other')),
    array(array('label' => 'Cherry pie'), array('label' => 'apples')));
ok(count($err) === 1 && strpos($err[0], 'already on the list') !== false, 'a label already on the list is refused');
$id = kop_choice_set_items('t-keyed', $ch, $words);
ok($id !== '', 'saved');
ok(t_keyed() === array('a' => 'Apples', 'b' => 'Banana', 'cherry_pie' => 'Cherry pie', 'other' => 'Other'), 'rename, add before Other');
ok(t_keyed('offered') === array('a' => 'Apples', 'cherry_pie' => 'Cherry pie', 'other' => 'Other'), 'hidden left out of offered');
ok(kop_choice_hidden('t-keyed') === array('b'), 'hidden list');

// Renaming back to the built-in label drops the override.
list($ch, $words) = kop_choice_apply_form('t-keyed', array('a' => array('label' => 'Apple')), array());
$id2 = kop_choice_set_items('t-keyed', $ch, $words);
ok(!isset($GLOBALS['kop_choice_test_store']['t-keyed']['items']['a']), 'built-in label again = no override');

$r = kop_choice_undo($id2);
ok($r['ok'] && t_keyed()['a'] === 'Apples', 'undo the rename back');
$r = kop_choice_undo($id);
ok($r['ok'] && t_keyed() === array('a' => 'Apple', 'b' => 'Banana', 'other' => 'Other'), 'undo the first save: all back');
ok(empty($GLOBALS['kop_choice_test_store']['t-keyed']), 'store empty again');
ok(!kop_choice_undo($id)['ok'], 'undo twice refused');

// --- keyed list with fields --------------------------------------------------
kop_choice_register('t-fields', array('label' => 'Test fields', 'source' => 't_fields',
    'fields' => array('status' => array('label' => 'Status', 'type' => 'select', 'options' => array('Closed', 'Open'), 'required' => true),
        'done' => array('label' => 'Finished', 'type' => 'check'))));
function t_fields($mode = 'all') {
    return kop_choices('t-fields', array(
        'x' => array('label' => 'X', 'status' => 'Closed', 'done' => true),
        'y' => array('label' => 'Y', 'status' => 'Open', 'done' => false),
    ), $mode);
}
list($ch, $words, $err) = kop_choice_apply_form('t-fields',
    array('x' => array('label' => 'X', 'status' => 'Closed'), 'y' => array('label' => 'Y', 'status' => 'Open', 'done' => 1)),
    array(array('label' => 'Zed'), array('label' => 'Wye', 'status' => 'Open')));
ok(count($err) === 1, 'a required field missing is refused');
kop_choice_set_items('t-fields', $ch, $words);
$l = t_fields();
ok($l['x']['done'] === 0, 'unticking a built-in check is kept');
ok($l['y']['done'] === 1, 'ticking a built-in check is kept');
ok(isset($l['wye']) && $l['wye']['status'] === 'Open' && $l['wye']['label'] === 'Wye', 'added choice carries its fields');

// --- plain list ---------------------------------------------------------------
kop_choice_register('t-plain', array('label' => 'Test plain', 'source' => 't_plain', 'plain' => true));
function t_plain($mode = 'all') {
    return kop_choices('t-plain', array('RTC', 'Wilderness', 'Other'), $mode);
}
list($ch, $words, $err) = kop_choice_apply_form('t-plain', array('Wilderness' => array('keep' => 1, 'hidden' => 1)),
    array(array('label' => 'Group Home'), array('label' => 'rtc')));
ok(count($err) === 1, 'plain duplicate ignoring case refused');
kop_choice_set_items('t-plain', $ch, $words);
ok(t_plain() === array('RTC', 'Wilderness', 'Group Home', 'Other'), 'plain add before Other');
ok(t_plain('offered') === array('RTC', 'Group Home', 'Other'), 'plain hidden');

// --- slugs --------------------------------------------------------------------
ok(kop_choice_slug('Court order (state)') === 'court_order_state', 'slug _');
ok(kop_choice_slug('Court order (state)', '-') === 'court-order-state', 'slug -');

// --- every registered list ----------------------------------------------------
$GLOBALS['kop_choice_test_store'] = array();
$list = in_array('--list', $argv, true);
foreach (kop_choice_registry() as $key => $def) {
    if (strpos($key, 't-') === 0) continue;
    $d = kop_choice_defaults($key);
    ok(count($d) > 0, "$key: built-in choices read from {$def['source']}");
    ok(count(kop_choice_current($key)) === count($d), "$key: untouched = built-in");
    if ($list) {
        echo "$key ({$def['group']} / {$def['label']}): " . count($d) . "\n";
        foreach ($d as $v => $c) echo '    ' . $v . (empty($def['plain']) ? ' = ' . kop_choice_label($c) : '') . "\n";
    }
}

echo ($fail ? "$fail of $n checks FAILED\n" : "All $n checks passed.\n");
exit($fail ? 1 : 0);
