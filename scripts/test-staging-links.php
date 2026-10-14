<?php
/**
 * Offline test for inc/staging-links.php.
 *
 *   php scripts/test-staging-links.php
 *
 * The staging install is gone (2026-10-14), so the file no longer asks crawlers to stay out of
 * /staging/: Google has to be able to recrawl the old addresses and see the 404s and redirects.
 * This checks the file hooks nothing. What a reader meets is (kop-pipeline) scripts/check-staging-links.py.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['kop_test_filters'] = array();
function add_filter($tag, $fn, $priority = 10, $args = 1) {
    $GLOBALS['kop_test_filters'][$tag][] = $fn;
}
function add_action($tag, $fn, $priority = 10, $args = 1) {
    $GLOBALS['kop_test_filters'][$tag][] = $fn;
}

require ABSPATH . 'inc/staging-links.php';

$failures = 0;
function ok($label, $got) {
    global $failures;
    if ($got) { echo "PASS $label\n"; return; }
    $failures++;
    printf("FAIL %s\n", $label);
}

ok('nothing is added to robots.txt', empty($GLOBALS['kop_test_filters']['robots_txt']));
ok('the file hooks nothing at all', count($GLOBALS['kop_test_filters']) === 0);
ok('the old robots function is gone', !function_exists('kop_staging_robots'));

echo $failures ? "\n$failures FAILURES\n" : "\nstaging links: PASS\n";
exit($failures ? 1 : 0);
