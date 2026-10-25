<?php
/**
 * The sensitivity reader survey's checks (inc/sensitivity-survey.php kop_sens_clean()), without WordPress,
 * and that every community the page offers is a registered choice.
 *
 *   php -d extension=mbstring scripts/test-sensitivity-survey.php
 */

require dirname(__DIR__) . '/inc/sensitivity-survey.php';

$fail = 0;
$pass = 0;
function check($name, $ok, $info = '') {
    global $fail, $pass;
    if ($ok) { $pass++; return; }
    $fail++;
    echo "FAIL $name" . ($info !== '' ? ": $info" : '') . "\n";
}
function refused(array $in, array $groups) {
    try { kop_sens_clean($in, $groups); return ''; } catch (RuntimeException $e) { return $e->getMessage(); }
}

$groups = array('black' => 'Black survivors', 'adoptee' => 'Adoptees');
$ok = array('groups' => array('black'), 'gaps' => 'You have nothing on the programs in Georgia.');

$row = kop_sens_clean($ok, $groups);
check('a plain answer passes', $row['groups'] === array('black') && $row['read_later'] === 0);
check('every written answer is in the row', !array_diff(array_keys(kop_sens_questions()), array_keys($row)));
check('an unknown community is dropped', kop_sens_clean(array('groups' => array('black', 'nope', 'black')) + $ok, $groups)['groups'] === array('black'));
check('no community and nothing written in is refused', strpos(refused(array('gaps' => 'Something long enough'), $groups), 'community') !== false);
check('another community written in passes', kop_sens_clean(array('group_other' => 'Deaf survivors', 'gaps' => 'Something long enough'), $groups)['group_other'] === 'Deaf survivors');
check('no written answer is refused', strpos(refused(array('groups' => array('adoptee')), $groups), 'question') !== false);
check('read drafts later needs an email', strpos(refused(array('read_later' => 1) + $ok, $groups), 'email') !== false);
check('read drafts later with an email passes', kop_sens_clean(array('read_later' => 1, 'email' => 'a@example.org') + $ok, $groups)['read_later'] === 1);
check('a bad email is refused', refused(array('email' => 'not an email') + $ok, $groups) !== '');
check('a web address as a name is refused', refused(array('name' => 'see https://spam.example') + $ok, $groups) !== '');
check('long answers are cut', mb_strlen(kop_sens_clean(array('gaps' => str_repeat('a', KOP_SENS_MAX + 50)) + $ok, $groups)['gaps']) === KOP_SENS_MAX);
check('runs of blank lines are squeezed', kop_sens_clean(array('gaps' => "first line\r\n\r\n\r\n\r\nsecond") + $ok, $groups)['gaps'] === "first line\n\nsecond");
check('statuses are new, read, deleted', array_keys(kop_sens_statuses()) === array('new', 'read', 'deleted'));

// The page's communities come from the registered list.
$registered = array();
function kop_choice_register($key, array $def) { global $registered; $registered[$key] = $def; }
function kop_choice_def($key) { global $registered; return $registered[$key] ?? array(); }
function kop_choice_label($c) { return is_array($c) ? (string) ($c['label'] ?? '') : (string) $c; }
require dirname(__DIR__) . '/inc/choice-lists/statuses.php';
$list = $registered['sensitivity-groups']['defaults'] ?? array();
check('the communities list is registered', count($list) >= 7, (string) count($list));
foreach (array('black', 'indigenous', 'foster_care', 'adoptee', 'maternity_home', 'immigrant', 'religious_minority') as $want) {
    check("community $want is offered", isset($list[$want]));
}
$tpl = file_get_contents(dirname(__DIR__) . '/templates/page-volunteer.php');
check('the page reads the list, not its own copy', strpos($tpl, "kop_sensitivity_groups('offered')") !== false && strpos($tpl, '<li>Adoptees</li>') === false);
$js = file_get_contents(dirname(__DIR__) . '/js/sensitivity-survey.js');
foreach (array_keys(kop_sens_questions()) as $k) {
    check("the page has a $k box", strpos($tpl, 'name="' . $k . '"') !== false);
    check("the script sends $k", strpos($js, "$k: el.$k.value") !== false);
}

echo $fail ? "$fail failed, $pass passed\n" : "All $pass passed\n";
exit($fail ? 1 : 0);
