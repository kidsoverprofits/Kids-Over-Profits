<?php
/**
 * inc/news-clippings.php, offline: which link an article gets, the fields it is filed with, search snippets.
 * The import itself (file swap, text, article, undo) is checked on the server by its dry run:
 *   php api/import-clippings.php ~/kop-import/clippings
 *
 *   php scripts/test-news-clippings.php
 */

define('ABSPATH', __DIR__ . '/');
function wp_parse_url($url, $part = -1) { return parse_url($url, $part); }
function home_url($p = '') { return 'https://kidsoverprofits.org' . $p; }
function esc_url_raw($u) { return $u; }
require dirname(__DIR__) . '/inc/news-clippings.php';

$fail = 0;
$check = function ($label, $got, $want) use (&$fail) {
    if ($got === $want) {
        echo "ok   $label\n";
    } else {
        $fail++;
        echo "FAIL $label\n     got:  " . var_export($got, true) . "\n     want: " . var_export($want, true) . "\n";
    }
};

$scan = 'https://kidsoverprofits.org/wp-content/uploads/2025/07/Hyde-Times-Record.pdf';
$check('no printed link -> our scan', kop_clip_article_url('', $scan), $scan);
$check('printed link wins', kop_clip_article_url('http://alpenhornnews.com/print_this_story.asp?sdetail=388', $scan), 'http://alpenhornnews.com/print_this_story.asp?sdetail=388');
$check('a link back to us is not the article', kop_clip_article_url('https://www.kidsoverprofits.org/news/', $scan), $scan);
$check('not a web address', kop_clip_article_url('alpenhornnews.com/story', $scan), $scan);

$p = kop_clip_news_params(array('title' => '', 'site_name' => 'Times Record', 'published' => '2006-06-05', 'facilities' => array('Hyde School'), 'article_type' => 'general'), 'Hyde Times Record 06-05-2006pdf', $scan);
$check('headline falls back to the document title', $p['title'], 'Hyde Times Record 06-05-2006pdf');
$check('filed with the processor fields', array($p['type'], $p['url'], $p['site_name'], $p['full'], $p['facilities']), array('article', $scan, 'Times Record', 1, array('Hyde School')));
$p = kop_clip_news_params(array('title' => 'Hyde graduates face the future'), 'x', $scan);
$check('AI headline kept', $p['title'], 'Hyde graduates face the future');
$check('no unset processor fields invented', array_key_exists('staff', $p), false);

$text = "MAINE TIMES\n\nJoe Gauld teaches tough. Gauld promotes Bath's Hyde School and its character development program with the zeal of a salesman.";
$check('snippet finds the words', kop_clip_snippet($text, 'character development', 15), '... and its character development program with ...');
$check('snippet of missing words', kop_clip_snippet($text, 'wilderness', 15), '');
$check('instructions name the document', strpos(kop_clip_instructions('Maine Times 1974'), '"Maine Times 1974"') !== false, true);

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit($fail ? 1 : 0);
