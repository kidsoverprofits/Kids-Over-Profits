<?php
/**
 * Offline checks for submitted-news AI backfill selection and title handling.
 *
 *   php scripts/test-record-enrich.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', __DIR__ . '/../');
define('DAY_IN_SECONDS', 86400);
function get_option($name, $default = false) { return $default; }
function update_option($name, $value, $autoload = null) { return true; }
function get_stylesheet_directory_uri() { return 'https://kidsoverprofits.org/wp-content/themes/child'; }
function wp_json_encode($value) { return json_encode($value); }
function is_wp_error($value) { return false; }
function wp_remote_retrieve_body($response) { return $response['body'] ?? ''; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code'] ?? 200; }
function wp_remote_post($url, $args) {
    if (strpos($url, 'process-news-ai.php') !== false && !empty($GLOBALS['kop_enrich_ai_error_page'])) {
        // An old reader that let an error page through (plan 4.6 #16).
        return array('body' => json_encode(array('success' => true, 'data' => array(
            'title' => 'Page Not Found | The Bulletin',
            'summary' => "The requested article URL returned a 'Page Not Found' error on the publication's website.",
        ))));
    }
    if (strpos($url, 'process-news-ai.php') !== false) {
        return array('body' => json_encode(array(
            'success' => true,
            'data' => array(
                'title' => 'Correct published headline',
                'author' => 'AI Reporter',
                'publicationName' => 'AI News',
                'publicationDate' => '2026-05-04',
                'articleType' => 'expose',
                'location' => 'Portland, Oregon',
                'tags' => array('Education'),
                'facilities' => array('Example Academy'),
                'staff' => array('A. Person'),
                'survivors' => array('J. Doe'),
                'contentWarnings' => array('Seclusion'),
                'summary' => 'A factual summary.',
            ),
        )));
    }
    $GLOBALS['kop_enrich_saved_payload'] = json_decode($args['body'], true);
    return array('body' => json_encode(array('success' => true)));
}

require_once dirname(__DIR__) . '/api/lib-record-enrich.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE news_submissions (
    id INTEGER PRIMARY KEY,
    status TEXT,
    article_title TEXT,
    article_url TEXT,
    author TEXT,
    publication_name TEXT,
    publication_date TEXT,
    article_type TEXT,
    article_location TEXT,
    tags TEXT,
    facilities_mentioned TEXT,
    staff_mentioned TEXT,
    survivors_mentioned TEXT,
    content_warnings TEXT,
    summary TEXT,
    json_data TEXT,
    alternate_title TEXT,
    generated_output TEXT,
    submitted_by TEXT,
    submission_notes TEXT
)");
$complete = array(
    'status' => 'submitted',
    'article_title' => 'Published article headline',
    'article_url' => 'https://news.example/story',
    'author' => 'Reporter',
    'publication_name' => 'News Example',
    'publication_date' => '2026-01-02',
    'article_type' => 'expose',
    'article_location' => 'Austin, Texas',
    'tags' => '["Education"]',
    'facilities_mentioned' => '["Example Academy"]',
    'staff_mentioned' => '["A. Person"]',
    'survivors_mentioned' => '["J. Doe"]',
    'content_warnings' => '["Seclusion"]',
    'summary' => 'A factual summary.',
    'json_data' => '{}',
    'alternate_title' => '',
    'generated_output' => '',
    'submitted_by' => 'Test submitter',
    'submission_notes' => 'Keep these notes.',
);
$insert = $pdo->prepare('INSERT INTO news_submissions
    (id, status, article_title, article_url, author, publication_name, publication_date,
     article_type, article_location, tags, facilities_mentioned, staff_mentioned,
     survivors_mentioned, content_warnings, summary, json_data, alternate_title,
     generated_output, submitted_by, submission_notes)
    VALUES (:id, :status, :article_title, :article_url, :author, :publication_name, :publication_date,
     :article_type, :article_location, :tags, :facilities_mentioned, :staff_mentioned,
     :survivors_mentioned, :content_warnings, :summary, :json_data, :alternate_title,
     :generated_output, :submitted_by, :submission_notes)');
$add = function ($id, array $overrides = array()) use ($insert, $complete) {
    $row = array_merge(array('id' => $id), $complete, $overrides);
    $insert->execute($row);
};
$add(1, array('article_title' => 'https://news.example/story', 'author' => '', 'summary' => ''));
$add(2);
$add(3, array('status' => 'approved', 'summary' => ''));
$add(4, array('json_data' => '{"_kop_ai_backfilled_at":"2026-10-01T00:00:00+00:00"}', 'summary' => ''));
$add(5, array('article_title' => 'news.example'));
$add(6, array('article_type' => 'general'));

$ids = kop_enrich_news_ids($pdo, 20);
$expected = array(1, 5, 6);
if ($ids !== $expected) {
    fwrite(STDERR, 'FAIL candidate selection: expected ' . implode(',', $expected) . ', got ' . implode(',', $ids) . "\n");
    exit(1);
}
echo "PASS incomplete submitted rows are selected, completed/non-queue rows are skipped\n";

$forced = kop_enrich_news_ids($pdo, 20, array(4));
if ($forced !== array(4)) {
    fwrite(STDERR, 'FAIL explicit rerun: expected completed row 4, got ' . implode(',', $forced) . "\n");
    exit(1);
}
echo "PASS explicit IDs can rerun a completed submitted row\n";

if (kop_enrich_news_title('Old or incorrect title', 'Correct published headline') !== 'Correct published headline'
    || kop_enrich_news_title('Keep this title', '') !== 'Keep this title') {
    fwrite(STDERR, "FAIL extracted headline selection\n");
    exit(1);
}
echo "PASS extracted headline replaces the old title, but an empty extraction preserves it\n";

$saved = kop_enrich_news_row($pdo, 1, true);
$payload = $GLOBALS['kop_enrich_saved_payload'] ?? array();
if (empty($saved['ok']) || ($payload['title'] ?? '') !== 'Correct published headline'
    || empty($payload['_kop_ai_backfilled_at']) || ($payload['submissionNotes'] ?? '') !== 'Keep these notes.') {
    fwrite(STDERR, "FAIL enriched payload saves the extracted title, completion marker and original notes\n");
    exit(1);
}
echo "PASS save payload includes the corrected title, one-time marker and preserved reviewer notes\n";

// Error pages (plan 4.6 #16): a row titled with one is read again, and a read that comes back as one is never saved.
if (!kop_enrich_placeholder_title('Page not found - KTVZ') || kop_enrich_placeholder_title('Published article headline')) {
    fwrite(STDERR, "FAIL an error-page title counts as a placeholder
");
    exit(1);
}
echo "PASS a row titled \"Page not found - KTVZ\" is read again
";

$GLOBALS['kop_enrich_saved_payload'] = null;
$GLOBALS['kop_enrich_ai_error_page'] = true;
$err = kop_enrich_news_row($pdo, 5, true);
$GLOBALS['kop_enrich_ai_error_page'] = false;
if (!empty($err['ok']) || $GLOBALS['kop_enrich_saved_payload'] !== null || stripos((string) ($err['error'] ?? ''), 'error page') === false
    || !kop_enrich_due(array('n' => 2, 'why' => $err['error'], 'at' => gmdate('c', time() - 2 * 86400)))) {
    fwrite(STDERR, 'FAIL an error-page read is not saved and is retried once a day: ' . json_encode($err) . "
");
    exit(1);
}
$still = $pdo->query('SELECT status, article_title FROM news_submissions WHERE id = 5')->fetch(PDO::FETCH_ASSOC);
if ($still['status'] !== 'submitted' || $still['article_title'] !== 'news.example') {
    fwrite(STDERR, "FAIL the row keeps waiting with its old title
");
    exit(1);
}
echo "PASS an error-page read leaves the row waiting with its title, on the daily retry
";

// A row an older read left with an error-page summary gets the article's summary on a rerun.
$add(7, array('status' => 'approved', 'submitted_by' => 'admin (SCIAD NET import)',
    'summary' => 'The requested page returned a 404 error and no article content was available.'));
$GLOBALS['kop_enrich_saved_payload'] = null;
$re = kop_enrich_news_row($pdo, 7, true);
if (empty($re['ok']) || ($GLOBALS['kop_enrich_saved_payload']['summary'] ?? '') !== 'A factual summary.'
    || ($GLOBALS['kop_enrich_saved_payload']['status'] ?? '') !== 'approved') {
    fwrite(STDERR, 'FAIL an error-page summary is replaced on a rerun: ' . json_encode($re) . "
");
    exit(1);
}
echo "PASS an older error-page summary is replaced by the article's
";
