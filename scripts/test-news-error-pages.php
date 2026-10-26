<?php
/**
 * Error pages are never a news headline (plan 4.6 #16): kop_news_error_page()
 * in api/lib-article-fetch.php, which api/process-news-ai.php and the hourly
 * reader (api/lib-record-enrich.php) use to keep a "Page Not Found" read off
 * the row. The cases are the titles and summaries the SCIAD NET and Google
 * Docs imports put on /news/ before 2026-10-25, and real headlines that share
 * their words.
 *
 *   php -d extension=mbstring scripts/test-news-error-pages.php
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-news-error-pages.php --mirror
 *       (also lists approved and waiting news in tmp/prod.sqlite that read as error pages)
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/api/lib-article-fetch.php';

$fail = 0;
$check = function ($label, $got, $want) use (&$fail) {
    if ($got !== $want) {
        $fail++;
        fwrite(STDERR, 'FAIL ' . $label . ': expected ' . var_export($want, true) . "\n");
    }
};

// The 14 rejected on 2026-10-25, and others of the same kind.
foreach (array(
    'Page Not Found', 'Page not found - Phoenix Training Group', '404 - Page not found - Alternative Press Magazine',
    'Page Not Found (404) | The Providence Journal', "Page not found \u{FFFD} Lewiston Sun Journal",
    'Page not found 2paragraph News: US Politics, influential sources Follow @2Paragraphs Daily',
    "Page not found \u{2013} WTOP News", 'Page Not Found - The Journal', 'aambookclub.com | 522: Connection timed out',
    'Page Not Found | The Bulletin', 'Page not found - KTVZ', '404 / Page Not Found', 'Wayback Machine',
    '404 Not Found', 'Error 404', '403 Forbidden', 'Just a moment...', 'Attention Required! | Cloudflare',
    'example.com | 502: Bad gateway', 'Access Denied', 'Not Found',
) as $t) {
    $check('error title "' . $t . '"', kop_news_error_page_title($t), true);
}
// Real headlines on /news/ that share their words.
foreach (array(
    'Lawsuit against Hyde School at risk after attorney errors', 'A License Suspended',
    'Florida youth detention contractor will no longer operate four youth facilities',
    'Gov. Whitmer issues statement on Lakeside Academy death | WKZO | Everything Kalamazoo | 590 AM',
    'Forbidden love: a teen program romance', 'Access denied to records of Utah youth facility',
    'Missing teen not found after leaving wilderness program', 'Fortune 500 company buys treatment chain',
    'Two Tobinworld employees in Antioch suspended after beating video surfaces', '',
) as $t) {
    $check('real headline "' . $t . '"', kop_news_error_page_title($t), false);
}

foreach (array(
    'The provided article text consists of an error page indicating the requested content was not found.',
    "The requested article was not found on the publication's website as the URL returned an error.",
    'The requested web page could not be displayed due to a server connection timeout.',
    'The requested article could not be retrieved because the page is unavailable, and no content was provided.',
    'The requested webpage could not be accessed because the Wayback Machine returned a block message indicating suspected abusive bot traffic.',
    'The requested article URL redirected to an automated traffic verification page due to security protocols.',
    "The retrieved webpage is a generic '404 Missing Page' error notice from the Grande Prairie Daily Herald Tribune website.",
    'The requested page returned a 404 error and no article content was available.',
    'This webpage is a "Page Not Found" error page for KTVZ and does not contain any article.',
    'This article references archived content regarding public testimony provided by Paris Hilton. Specific details regarding allegations or facility practices are not contained within the archived text.',
) as $s) {
    $check('error summary "' . mb_substr($s, 0, 50) . '"', kop_news_error_page_summary($s), true);
}
foreach (array(
    'Prosecutors in Connecticut announced they would not pursue a second trial against Michael C. Skakel.',
    'The boy could not be found for two days after he ran from the wilderness program.',
    'The article describes a lawsuit; the facility page was not found in state records until 2019.',
    'This document appears to be a court or legal file related to a case settlement.',
    '',
) as $s) {
    $check('real summary "' . mb_substr($s, 0, 50) . '"', kop_news_error_page_summary($s), false);
}

// A dead link's stripped page: only a short page that opens on the error.
$check('short 200 "Page not found" page is dead', kopLooksDeadLink('Page Not Found | The Bulletin Skip to content Home News', 200), true);
$check('404 is dead', kopLooksDeadLink(str_repeat('words ', 900), 404), true);
$check('522 is dead', kopLooksDeadLink('', 522), true);
$check('a long article is not dead', kopLooksDeadLink(str_repeat('The program closed in 2019. ', 200), 200), false);

if ($fail) {
    fwrite(STDERR, "$fail failed\n");
    exit(1);
}
echo "PASS error-page titles, summaries and dead links are told from real articles\n";

if (in_array('--mirror', $argv, true)) {
    $db = dirname(__DIR__) . '/tmp/prod.sqlite';
    $pdo = new PDO('sqlite:' . $db);
    $n = 0;
    foreach ($pdo->query("SELECT id, status, submitted_by, article_title, summary FROM news_submissions WHERE status IN ('approved', 'submitted') ORDER BY id") as $r) {
        if (kop_news_error_page($r['article_title'], $r['summary'])) {
            $n++;
            echo '#' . $r['id'] . ' ' . $r['status'] . ' [' . $r['submitted_by'] . '] ' . mb_substr((string) $r['article_title'], 0, 70)
                . (kop_news_error_page_title($r['article_title']) ? '' : '  (summary: ' . mb_substr((string) $r['summary'], 0, 90) . ')') . "\n";
        }
    }
    echo $n . ' approved or waiting rows read as error pages in ' . $db . ' (as of ' . date('Y-m-d H:i', filemtime($db)) . ")\n";
}
