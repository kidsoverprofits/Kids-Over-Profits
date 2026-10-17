<?php
/**
 * Names set to the company that ran them (inc/name-eras.php): keys, labels, which
 * names a record has, and the name the directory lists a record by under a company.
 * Offline, no WordPress.
 *   php -d extension=mbstring scripts/test-name-eras.php
 */

define('ABSPATH', __DIR__ . '/');
function add_action() {}
function add_filter() {}
require __DIR__ . '/../inc/facility-eras.php';
require __DIR__ . '/../inc/directory-names.php';
require __DIR__ . '/../inc/name-eras.php';

$fails = 0;
function check($ok, $label, $detail = '') {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n";
    if (!$ok) $fails++;
}

check(kop_name_eras_key(' Formerly  Three Springs of Marion ') === 'three springs of marion', 'key drops "Formerly", spacing, case');
$era = array('name' => 'Three Springs of Marion', 'operator_id' => 7, 'operator_name' => 'Three Springs Inc.', 'start_year' => 1998, 'end_year' => 2008);
check(kop_name_eras_label($era) === 'Three Springs Inc., 1998 to 2008', 'label: company and years', kop_name_eras_label($era));
check(kop_name_eras_label(array('operator_name' => '', 'start_year' => 0, 'end_year' => 2008) + $era) === '' . kop_facility_eras_years_label(null, 2008), 'label: years only');

$doc = array('identification' => array('name' => 'Marion Youth Academy', 'pastNames' => array('Three Springs of Marion, Sequel TSI Marion'), 'otherNames' => array('MYA')),
    'legacy' => array('mergedFacilities' => array(array('name' => 'Old Marion'))));
$names = kop_name_eras_record_names($doc);
check(array_values($names) === array('Marion Youth Academy', 'Three Springs of Marion', 'Sequel TSI Marion', 'MYA', 'Old Marion'), 'record names: own, split past names, other, merged', json_encode(array_values($names)));

$eras = array(
    'three springs of marion' => $era,
    'sequel tsi marion' => array('name' => 'Sequel TSI Marion', 'operator_id' => 9, 'operator_name' => 'Sequel TSI', 'start_year' => 2008, 'end_year' => 2014),
    'old marion' => array('name' => 'Old Marion', 'operator_id' => 0, 'operator_name' => 'Three Springs, Inc', 'start_year' => 1990, 'end_year' => 1997),
);
$u = kop_name_eras_name_under(1, 7, array('Three Springs Inc.'), $eras);
check($u['name'] === 'Three Springs of Marion' && $u['years'] === kop_facility_eras_years_label(1998, 2008), 'under its company: the latest name it set', json_encode($u));
check($u['also'] === array('Old Marion'), 'an era with no id matches by company name', json_encode($u['also']));
$u = kop_name_eras_name_under(1, 9, array('Sequel TSI'), $eras);
check($u['name'] === 'Sequel TSI Marion', 'by company id', json_encode($u));
check(kop_name_eras_name_under(1, 99, array('Acadia Healthcare'), $eras) === null, 'no era for the company: null (the guesses decide)');

echo $fails ? "\n{$fails} failed.\n" : "\nAll passed.\n";
exit($fails ? 1 : 0);
