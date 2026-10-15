<?php
/**
 * Offline checks for inc/defer-folds.php (closed folds sent as <template>).
 *
 *   php scripts/test-defer-folds.php [--pages=<dir of saved .html pages>]
 *
 * Fixtures: which folds move (closed, top level, big enough, no script) and
 * that every moved fold's contents come back exactly. --pages: the same on
 * whole saved pages (e.g. tmp/facility-pages from test-facility-pages.php, or
 * live pages saved with curl), with how many elements each page sheds.
 */

define('ABSPATH', __DIR__ . '/');
require dirname(__DIR__) . '/inc/defer-folds.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$args = getopt('', array('pages::'));

/** The page with every deferred template put back in place: must equal the original. */
$undo = function ($html) {
    return preg_replace('#<template data-kop-defer>(.*?)</template>#s', '$1', $html);
};
$items = static function ($n) { return str_repeat('<li><a href="#">x</a></li>', $n); };

echo "-- Fixtures --\n";
$page = '<html><body><nav><details><summary>Menu</summary>' . $items(50) . '</details></nav><main>'
    . '<details class="big"><summary>More</summary><ul>' . $items(30) . '</ul></details>'
    . '<details class="small"><summary>Few</summary><ul>' . $items(3) . '</ul></details>'
    . '<details class="opened" open><summary>Open</summary><ul>' . $items(30) . '</ul></details>'
    . '<details class="scripted"><summary>S</summary><ul>' . $items(30) . '</ul><script>var a = "<details>";</script></details>'
    . '<details class="outer"><summary>Outer</summary><div><details class="inner"><summary>Inner</summary><ul>' . $items(30) . '</ul></details></div></details>'
    . '<details class="optout" data-kop-nodefer><summary>No</summary><ul>' . $items(30) . '</ul></details>'
    . '<script type="application/json">{"x":"<details><summary>a</summary>' . $items(40) . '</details>"}</script>'
    . '<!-- <details><summary>c</summary>' . $items(40) . '</details> -->'
    . '</main><footer><details><summary>F</summary>' . $items(50) . '</details></footer></body></html>';
$out = kop_defer_folds($page);
$check('the page comes back exactly when the templates are undone', $undo($out) === $page);
$check('a big closed fold moves', strpos($out, '<details class="big"><summary>More</summary><template data-kop-defer><ul>') !== false);
$check('a small fold stays', strpos($out, '<details class="small"><summary>Few</summary><ul>') !== false);
$check('an open fold stays', strpos($out, '<details class="opened" open><summary>Open</summary><ul>') !== false);
$check('a fold holding a script stays', strpos($out, '<details class="scripted"><summary>S</summary><ul>') !== false);
$check('only the outer of two nested folds moves', strpos($out, '<details class="outer"><summary>Outer</summary><template data-kop-defer><div><details class="inner"><summary>Inner</summary><ul>') !== false
    && substr_count($out, '<template data-kop-defer>') === 2);
$check('data-kop-nodefer keeps a fold', strpos($out, '<details class="optout" data-kop-nodefer><summary>No</summary><ul>') !== false);
$check('folds inside scripts and comments are not touched', strpos($out, '{"x":"<details><summary>a</summary><li>') !== false && strpos($out, '<!-- <details><summary>c</summary><li>') !== false);
$check('nothing outside <main> is touched', strpos($out, '<nav><details><summary>Menu</summary><li>') !== false && strpos($out, '<footer><details><summary>F</summary><li>') !== false);
$check('a page with no <main> is left alone', kop_defer_folds('<div><details><summary>a</summary>' . $items(50) . '</details></div>') === '<div><details><summary>a</summary>' . $items(50) . '</details></div>');
$check('an attribute merely containing "open" is still closed', strpos(kop_defer_folds('<main><details data-reopen="1"><summary>a</summary>' . $items(50) . '</details></main>'), '<template data-kop-defer>') !== false);

if (!empty($args['pages'])) {
    echo "-- Pages in {$args['pages']} --\n";
    $files = glob(rtrim($args['pages'], '/\\') . '/*.html') ?: array();
    $bad = array();
    foreach ($files as $f) {
        $html = file_get_contents($f);
        $t = microtime(true);
        $out = kop_defer_folds($html);
        $ms = round((microtime(true) - $t) * 1000);
        if ($undo($out) !== $html) $bad[] = basename($f);
        $live = preg_replace('#<template data-kop-defer>.*?</template>#s', '', $out);
        $a = stripos($live, '<main');
        $b = strripos($live, '</main>');
        $before = preg_match_all('/<[a-z]/i', substr($html, (int) stripos($html, '<main'), (int) strripos($html, '</main>') - (int) stripos($html, '<main')));
        $after = $a !== false ? preg_match_all('/<[a-z]/i', substr($live, $a, $b - $a)) : 0;
        printf("  %-48s %6d -> %6d elements in <main>, %d folds deferred, %d ms\n", basename($f), $before, $after, substr_count($out, '<template data-kop-defer>'), $ms);
    }
    $check('every page comes back exactly when its templates are undone', !$bad, implode(', ', $bad));
}

echo $failures ? "\n{$failures} FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
