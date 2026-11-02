<?php
/**
 * Proper citations for the Sources list (inc/citation-lookup.php, POST kop/v1/citations):
 * Woodbury issues from the file name, media library copies, approved news, published
 * lawsuits' court filings and published sources sent in, read from the production mirror.
 *
 *   php -d extension=mbstring -d extension=pdo_sqlite scripts/test-citation-lookup.php [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/citation-lookup.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// Functions alone.
$check('date, full', kop_citation_date('2024-09-20') === 'September 20, 2024');
$check('date, month', kop_citation_date('2009-02') === 'February 2009');
$check('date, empty', kop_citation_date('0000-00-00') === '');
$w = kop_citation_woodbury('https://example.test/wp-content/uploads/2024/12/woodbury-0209.pdf#page=20');
$check('woodbury issue and page', $w && $w['date'] === 'February 2009' && $w['detail'] === 'p. 20' && $w['title'] === 'Woodbury Reports', json_encode($w));
$w = kop_citation_woodbury('https://example.test/wp-content/uploads/2024/12/woodbury-1298.pdf');
$check('woodbury 1990s, no page', $w && $w['date'] === 'December 1998' && $w['detail'] === '', json_encode($w));
$check('not woodbury', kop_citation_woodbury('https://example.test/wp-content/uploads/x.pdf') === null);
$check('upload path only on this site', kop_citation_upload_path('https://example.test/wp-content/uploads/2025/07/a.pdf') === '2025/07/a.pdf'
    && kop_citation_upload_path('https://other.org/wp-content/uploads/2025/07/a.pdf') === '');

// Real records in the mirror.
$news   = 'https://www.nbcnews.com/news/us-news/utah-elevations-rtc-injuries-troubled-teen-facility-rcna159783';
$copy   = 'https://example.test/wp-content/uploads/Desert-Hills-to-stop-housing-disturbed-kids-Arizona-Daily-Star-1999-05-07.pdf';
$filing = 'https://example.test/wp-content/uploads/2025/07/Fuller-vs.-Hyde-Complaint-With-Docket-.pdf';
$sent   = 'https://dssmanuals.mo.gov/wp-content/uploads/2023/01/cd23-02-shelterwood-closing.pdf';
$none   = 'https://example.org/nothing-held-here';
$got = kop_citation_lookup(array($news, $copy, $filing, $sent, $none));

$n = $got[$news] ?? array();
$check('news: title, author, date, publication', ($n['title'] ?? '') === 'Former students report injuries and isolation at Elevations RTC'
    && ($n['author'] ?? '') === 'Tyler Kingkade, Elizabeth Chuck' && ($n['date'] ?? '') === 'September 2, 2024' && ($n['publisher'] ?? '') === 'NBC News', json_encode($n));
$n = $got[str_replace('https://www.', 'http://', $news)] ?? null;
$check('news: matched with http and without www', isset(kop_citation_lookup(array(str_replace('https://www.', 'http://', $news)))[str_replace('https://www.', 'http://', $news)]));
$c = $got[$copy] ?? array();
$check('media copy: title, and the original\'s site past the archive', ($c['title'] ?? '') === 'Desert Hills to stop housing disturbed kids (Arizona Daily Star, 1999-05-07)'
    && ($c['note'] ?? '') === 'Our copy of the original at nospank.net', json_encode($c));
$f = $got[$filing] ?? array();
$check('court filing: its title and the case', ($f['kind'] ?? '') === 'case-document'
    && strpos($f['detail'] ?? '', 'In Fuller v. Hyde School, No. 2:25-cv-00354-SDN (U.S. District Court for the District of Maine, filed July 11, 2025)') === 0, json_encode($f));
$s = $got[$sent] ?? array();
$check('published source sent in', ($s['title'] ?? '') === "Missouri Children's Division memo CD23-02: Shelterwood closing" && ($s['publisher'] ?? '') === 'dssmanuals.mo.gov', json_encode($s));
$check('no record: left out', !isset($got[$none]));

echo $failures ? "\n$failures failure(s)\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
