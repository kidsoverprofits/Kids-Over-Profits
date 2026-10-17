<?php
/**
 * The directory's Alternate names fold (inc/directory-names.php): names matched as the
 * page lists them, removed from pastNames/otherNames, the kept one reworded, nothing
 * else touched. Offline, no WordPress.
 *   php scripts/test-directory-names.php
 */

define('ABSPATH', __DIR__ . '/');
function add_action() {}
require __DIR__ . '/../inc/directory-names.php';

$fails = 0;
function check($ok, $label, $detail = '') {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? '  (' . $detail . ')' : '') . "\n";
    if (!$ok) $fails++;
}

check(kop_dirnames_key('  Previously  Island View RTC (') === 'island view rtc', 'key drops "Previously", a trailing "(", spacing, case');
check(kop_dirnames_split('Foo, Inc., Bar; Baz') === array('Foo, Inc.', 'Bar', 'Baz'), 'an entry splits as the page splits it');

$lists = array(
    'pastNames'  => array('Academy at Cedar Mountain', 'Academy at Cedar Mountain after CERTS purchased it', 'Academy at Cedar Mountain (1999-2004)'),
    'otherNames' => array('Cedar Mtn, Academy at Cedar Mountain after purchase by CERTS', 'Kept Other'),
);
list($out, $missing) = kop_dirnames_fold_lists($lists,
    array('Academy at Cedar Mountain after CERTS purchased it', 'Academy at Cedar Mountain (1999-2004)', 'Academy at Cedar Mountain after purchase by CERTS'),
    'Academy at Cedar Mountain', '');
check($missing === array(), 'every asked name found');
check($out['pastNames'] === array('Academy at Cedar Mountain'), 'past names folded to the kept one', json_encode($out['pastNames']));
check($out['otherNames'] === array('Cedar Mtn', 'Kept Other'), 'a packed entry loses only the folded part', json_encode($out['otherNames']));

list($out) = kop_dirnames_fold_lists($lists, array('Academy at Cedar Mountain (1999-2004)'), 'Academy at Cedar Mountain', 'Academy at Cedar Mountain (Utah)');
check($out['pastNames'][0] === 'Academy at Cedar Mountain (Utah)', 'keep_as rewords the kept name', json_encode($out['pastNames']));
check(count($out['pastNames']) === 2, 'the rest stays');

list($out) = kop_dirnames_fold_lists(array('pastNames' => array('Previously Foo', 'Foo'), 'otherNames' => array()), array('Foo dup'), 'Foo', '');
list(, $missing) = kop_dirnames_fold_lists(array('pastNames' => array('Foo'), 'otherNames' => array()), array('Not There'), 'Foo', '');
check($missing === array('Not There'), 'a name the lists do not hold is reported, not guessed');

list($out) = kop_dirnames_fold_lists(array('pastNames' => array('A', 'B'), 'otherNames' => array('b')), array('B'), 'A', '');
check($out === array('pastNames' => array('A'), 'otherNames' => array()), 'a name is taken out of both lists', json_encode($out));

list($out) = kop_dirnames_fold_lists(array('pastNames' => array('A', 'B'), 'otherNames' => array()), array('B'), 'A', 'B');
check($out['pastNames'] === array('B'), 'keep_as equal to a folded name leaves it once', json_encode($out));

check(kop_dirnames_same(array('pastNames' => array(' A '), 'otherNames' => array('')), array('pastNames' => array('A'), 'otherNames' => array())), 'same ignores blanks and spacing');

echo $fails ? "\n{$fails} failed.\n" : "\nAll passed.\n";
exit($fails ? 1 : 0);
