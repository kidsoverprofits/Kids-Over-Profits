<?php
/**
 * Offline test for the browser extension intake (inc/source-submissions.php).
 *
 * Copies news_submissions, lawsuits and legislation from tmp/prod.sqlite into
 * memory, then sends the extension's payloads through the REST callbacks:
 * each type lands in its own table with the right columns, links already on
 * file come back as 409 with the matching record, and the fallback auth
 * header is only honoured on the extension's routes. Nothing is written to
 * the mirror.
 *
 *   php -d extension=pdo_sqlite scripts/test-source-submissions.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$root = dirname(__DIR__);
$mirror = $root . '/tmp/prod.sqlite';
if (!file_exists($mirror)) {
    exit("tmp/prod.sqlite not found (scripts/sync-prod-sqlite.py).\n");
}

define('ABSPATH', $root . '/');
define('HOUR_IN_SECONDS', 3600);

// ---- WordPress stubs -------------------------------------------------------

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code = '', $message = '', $data = null) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
class WP_REST_Response {
    public $data; public $status;
    public function __construct($data = null, $status = 200) { $this->data = $data; $this->status = $status; }
}
class WP_REST_Request implements ArrayAccess {
    private $params; private $json;
    public function __construct(array $params = array(), $json = null) { $this->params = $params; $this->json = $json; }
    public function get_json_params() { return $this->json; }
    public function offsetExists($k): bool { return isset($this->params[$k]); }
    #[\ReturnTypeWillChange] public function offsetGet($k) { return $this->params[$k] ?? null; }
    public function offsetSet($k, $v): void { $this->params[$k] = $v; }
    public function offsetUnset($k): void { unset($this->params[$k]); }
}
class KOP_Test_User {
    public $ID = 7; public $display_name = 'Test Admin'; public $user_login = 'testadmin';
    public function exists() { return true; }
}

$GLOBALS['kop_hooks'] = array();
function add_action($tag, $cb, $prio = 10) { $GLOBALS['kop_hooks'][$tag][] = $cb; }
function add_filter($tag, $cb, $prio = 10) { $GLOBALS['kop_hooks'][$tag][] = $cb; }
function add_meta_box() {}
function register_post_type() {}
function register_rest_route() {}
function get_stylesheet_directory() { return dirname(__DIR__); }
function is_wp_error($v) { return $v instanceof WP_Error; }
function sanitize_text_field($s) { return trim(preg_replace('/\s+/', ' ', strip_tags((string) $s))); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function esc_url_raw($u, $protocols = null) { $u = trim((string) $u); return preg_match('#^https?://#i', $u) ? $u : ''; }
function wp_http_validate_url($u) { return filter_var($u, FILTER_VALIDATE_URL) ? $u : false; }
function wp_json_encode($v, $flags = 0) { return json_encode($v, $flags); }
function admin_url($p = '') { return 'https://kidsoverprofits.org/wp-admin/' . $p; }
function get_current_user_id() { return 7; }
function wp_get_current_user() { return new KOP_Test_User(); }
// Publishing from the extension's panel needs manage_options; $GLOBALS['kop_is_admin'] = false plays an editor.
function current_user_can($cap = '') { return $cap !== 'manage_options' || ($GLOBALS['kop_is_admin'] ?? true); }
function current_time($type) { return gmdate('Y-m-d H:i:s'); }
function kop_rinbox_native_news_detail_keys() { return array('plaintiffs', 'defendants', 'closureDate'); }
function kop_rinbox_log_native($type, array $before, $action) { $GLOBALS['kop_rinbox_logged'][] = array($type, array_keys($before), $action); }
function kop_submission_review_url($type) { return 'https://kidsoverprofits.org/review/?type=' . $type; }
function kop_notify_admins($type, $title) { $GLOBALS['kop_notified'][] = $type; return true; }

// A tiny post store for the Websites queue.
$GLOBALS['kop_posts'] = array();
$GLOBALS['kop_meta'] = array();
function wp_insert_post($arr) { $id = 9000 + count($GLOBALS['kop_posts']); $GLOBALS['kop_posts'][$id] = $arr; return $id; }
function update_post_meta($id, $k, $v) { $GLOBALS['kop_meta'][$id][$k] = $v; }
function get_post_meta($id, $k, $single = false) { return $GLOBALS['kop_meta'][$id][$k] ?? ''; }
function get_post_status($id) { return $GLOBALS['kop_posts'][$id]['post_status'] ?? false; }
function get_the_title($id) { return $GLOBALS['kop_posts'][$id]['post_title'] ?? ''; }
function get_edit_post_link($id) { return 'https://kidsoverprofits.org/wp-admin/post.php?post=' . $id . '&action=edit'; }
function get_posts($args) {
    foreach ($GLOBALS['kop_meta'] as $id => $meta) {
        if (($meta[$args['meta_key']] ?? null) === $args['meta_value']) {
            return array($id);
        }
    }
    return array();
}

// ---- Records DB: an in-memory copy of the three tables ---------------------

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $mirror) . "?mode=ro' AS prod");
// The three queues, plus what the news/lawsuit follow-ups read and write.
foreach (array('news_submissions', 'lawsuits', 'legislation', 'news_facility_links', 'news_story_arcs',
    'lawsuit_news_links', 'lawsuit_facility_links', 'journalists', 'journalist_articles', 'facilities_master') as $t) {
    $sql = $pdo->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn();
    if (!$sql) {
        continue;
    }
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.\"$t\" SELECT * FROM prod.\"$t\"");
}
$pdo->exec('DETACH DATABASE prod');
$GLOBALS['pdo'] = $pdo;
function kop_seed_pdo() { return $GLOBALS['pdo']; }

require $root . '/inc/source-submissions.php';

// ---- Checks ----------------------------------------------------------------

$fails = 0;
function check($ok, $label) {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) { $fails++; }
}
function submit(array $payload) {
    return kop_ext_rest_submit(new WP_REST_Request(array(), $payload));
}
function row(PDO $pdo, $table, $id) {
    $s = $pdo->prepare("SELECT * FROM $table WHERE id = ?");
    $s->execute(array($id));
    return $s->fetch();
}

$news = $pdo->query("SELECT id, article_url, article_title, publication_name FROM news_submissions WHERE article_url LIKE 'http%' AND status <> 'deleted' ORDER BY id DESC LIMIT 1")->fetch();
$suit = $pdo->query("SELECT id, source_urls FROM lawsuits WHERE source_urls LIKE '[\"http%' LIMIT 1")->fetch();
$bill = $pdo->query("SELECT id, bill_number, jurisdiction FROM legislation WHERE bill_number <> '' AND jurisdiction = 'California' LIMIT 1")->fetch();

// Duplicates, including a tracking-parameter variant of a known article.
$r = submit(array('type' => 'article', 'url' => $news['article_url'] . (strpos($news['article_url'], '?') === false ? '?' : '&') . 'utm_source=x', 'title' => 'Anything'));
check($r instanceof WP_REST_Response && $r->status === 409 && $r->data['duplicates'][0]['type'] === 'news' && $r->data['duplicates'][0]['id'] === (int) $news['id'], 'article already on file (utm variant) -> 409 naming news #' . $news['id']);

$r = submit(array('type' => 'article', 'url' => 'https://example.org/some/other-url', 'title' => $news['article_title'], 'site_name' => $news['publication_name']));
check($r instanceof WP_REST_Response && $r->status === 409 && $r->data['duplicates'][0]['id'] === (int) $news['id'], 'same title on the same outlet -> 409');

$suitUrl = json_decode($suit['source_urls'], true)[0];
$r = submit(array('type' => 'website', 'url' => $suitUrl, 'title' => 'Sent as a website'));
check($r instanceof WP_REST_Response && $r->status === 409 && $r->data['duplicates'][0]['type'] === 'lawsuit', 'a lawsuit source link sent as a website -> 409 naming the lawsuit');

$r = submit(array('type' => 'legislation', 'url' => 'https://legiscan.com/CA/bill/X/2026', 'title' => 'x',
    'bill_number' => str_replace(' ', '', $bill['bill_number']), 'jurisdiction' => 'CA'));
check($r instanceof WP_REST_Response && $r->status === 409 && $r->data['duplicates'][0]['type'] === 'legislation' && $r->data['duplicates'][0]['id'] === (int) $bill['id'], 'same bill number, state as code -> 409 naming bill #' . $bill['id']);

$r = kop_ext_rest_check(new WP_REST_Request(array('url' => 'https://example.com/kop-connection-test')));
check(is_array($r) && $r['duplicate'] === false && $r['user'] === 'Test Admin', 'connection test URL is not a duplicate and names the user');

// New records, one per queue.
$r = submit(array('type' => 'article', 'url' => 'https://example-news.test/2026/09/30/story', 'title' => 'A new story <b>about</b> a program',
    'site_name' => 'Example News', 'author' => 'Jo Reporter', 'published' => '2026-09-29', 'facility' => 'Example Academy',
    'tags' => array('Abuse', 'abuse', ''), 'notes' => 'Check the second half.', 'selection' => 'a quoted line', 'description' => 'Summary text'));
check($r instanceof WP_REST_Response && $r->status === 201 && $r->data['type'] === 'news', 'new article -> 201 in news');
$n = row($pdo, 'news_submissions', $r->data['id']);
check($n['status'] === 'submitted' && $n['article_type'] === 'general' && $n['article_title'] === 'A new story about a program', 'article saved as submitted/general, tags stripped from title');
check($n['publication_name'] === 'Example News' && $n['publication_date'] === '2026-09-29' && $n['author'] === 'Jo Reporter', 'article outlet, date and author');
check($n['submitted_by'] === 'Test Admin (browser extension)', 'article submitted_by names the user and the extension');
check(strpos($n['submission_notes'], 'Check the second half.') === 0 && strpos($n['submission_notes'], 'Highlighted: "a quoted line"') !== false, 'article notes carry the note and the highlight');
check(strpos($n['facilities_mentioned'], 'Example Academy') !== false, 'article facility mention stored');
$r2 = submit(array('type' => 'article', 'url' => 'https://example-news.test/2026/09/30/story/', 'title' => 'Again'));
check($r2 instanceof WP_REST_Response && $r2->status === 409, 'sending the new article again -> 409');

// The real link and the archived copy (api/lib-news-archive.php). Its admin emails are set aside.
$notifiedBefore = $GLOBALS['kop_notified'] ?? array();
$r = submit(array('type' => 'article', 'url' => 'https://web.archive.org/web/20250101000000/https://example-news.test/2025/archived-story', 'title' => 'An archived story'));
$n = $r instanceof WP_REST_Response ? row($pdo, 'news_submissions', $r->data['id']) : array();
check(($n['article_url'] ?? '') === 'https://example-news.test/2025/archived-story'
    && ($n['archive_url'] ?? '') === 'https://web.archive.org/web/20250101000000/https://example-news.test/2025/archived-story',
    'a Wayback link sent as the article splits into article_url + archive_url');
$r2 = submit(array('type' => 'article', 'url' => 'https://example-news.test/2025/archived-story', 'title' => 'Same, by its real link'));
check($r2 instanceof WP_REST_Response && $r2->status === 409, 'its real link is then on file -> 409');
$r2 = submit(array('type' => 'article', 'url' => 'https://web.archive.org/web/2024/https://example-news.test/2025/archived-story', 'title' => 'Another copy'));
check($r2 instanceof WP_REST_Response && $r2->status === 409, 'another Wayback copy of it -> 409');

// The admin panel: the processor's fields, published at once.
$GLOBALS['kop_notified'] = array();
$full = array('type' => 'article', 'url' => 'https://example-news.test/2026/10/09/panel', 'archive_url' => 'https://archive.ph/AbCd1',
    'title' => 'Panel story', 'site_name' => 'Example News', 'published' => '2026-10-08', 'full' => 1, 'publish' => 1,
    'alternate_title' => 'A calmer title', 'article_type' => 'lawsuit', 'location' => 'Provo, UT',
    'facilities' => array('Example Academy', 'Other Ranch'), 'staff' => array('Pat Owner'), 'survivors' => array('J.D.'),
    'content_warnings' => array('Physical Restraint'), 'summary' => 'A factual summary.', 'tags' => array('Wilderness Therapy'),
    'details' => array('plaintiffs' => 'J.D.', 'closureDate' => '', 'evil' => 'x'));
$r = submit($full);
$n = $r instanceof WP_REST_Response ? row($pdo, 'news_submissions', $r->data['id']) : array();
check($r instanceof WP_REST_Response && $r->status === 201 && !empty($r->data['published']), 'panel publish -> 201 published');
check(($n['status'] ?? '') === 'approved' && ($n['reviewed_by'] ?? '') === 'Test Admin (browser extension)' && !empty($n['reviewed_at']), 'stored approved, reviewer stamped');
check(($n['archive_url'] ?? '') === 'https://archive.ph/AbCd1' && ($n['article_url'] ?? '') === 'https://example-news.test/2026/10/09/panel', 'both links stored');
check(($n['alternate_title'] ?? '') === 'A calmer title' && ($n['article_type'] ?? '') === 'lawsuit' && ($n['article_location'] ?? '') === 'Provo, UT'
    && ($n['summary'] ?? '') === 'A factual summary.', 'alternate title, type, location, summary');
check(strpos($n['facilities_mentioned'] ?? '', 'Other Ranch') !== false && strpos($n['staff_mentioned'] ?? '', 'Pat Owner') !== false
    && strpos($n['survivors_mentioned'] ?? '', 'J.D.') !== false && strpos($n['content_warnings'] ?? '', 'Physical Restraint') !== false, 'facilities, staff, survivors, warnings');
$j = json_decode($n['json_data'] ?? '', true);
check(($j['plaintiffs'] ?? '') === 'J.D.' && !isset($j['evil']) && !isset($j['closureDate']) && ($j['archiveUrl'] ?? '') === 'https://archive.ph/AbCd1', 'details kept by name only, json_data has the archive link');
check(in_array('news', $GLOBALS['kop_notified'], true) === false, 'a published article mails no "new submission"');
check(($GLOBALS['kop_rinbox_logged'][0] ?? null) === array('news', array((int) $n['id']), 'approve'), 'published article logged for Undo');

$GLOBALS['kop_is_admin'] = false;
$r = submit(array_merge($full, array('url' => 'https://example-news.test/2026/10/09/editor', 'archive_url' => '', 'title' => 'Editor story')));
$n = $r instanceof WP_REST_Response ? row($pdo, 'news_submissions', $r->data['id']) : array();
check(($n['status'] ?? '') === 'submitted' && empty($r->data['published']) && ($n['article_type'] ?? '') === 'lawsuit', 'an editor\'s "publish" waits in the queue, fields kept');
$GLOBALS['kop_is_admin'] = true;

$r = submit(array('type' => 'article', 'url' => 'https://example-news.test/2026/10/09/plain', 'title' => 'Plain send', 'summary' => 'Injected', 'publish' => 1,
    'article_type' => 'closure', 'description' => 'Page description'));
$n = $r instanceof WP_REST_Response ? row($pdo, 'news_submissions', $r->data['id']) : array();
check(($n['status'] ?? '') === 'submitted' && ($n['summary'] ?? '') === 'Page description' && ($n['article_type'] ?? '') === 'general', 'without full=1: no publish, no summary or type from the payload');
$GLOBALS['kop_notified'] = $notifiedBefore;

$r = submit(array('type' => 'lawsuit', 'url' => 'https://www.courtlistener.com/docket/999999/doe-v-example/', 'title' => 'Doe v. Example Academy',
    'case_number' => '2:26-cv-01234', 'court' => 'U.S. District Court for the District of Utah', 'jurisdiction' => 'UT', 'facility' => 'Example Academy'));
check($r instanceof WP_REST_Response && $r->status === 201 && $r->data['type'] === 'lawsuit', 'new lawsuit -> 201 in lawsuits');
$l = row($pdo, 'lawsuits', $r->data['id']);
check($l['publication_status'] === 'pending' && $l['case_number'] === '2:26-cv-01234' && $l['jurisdiction'] === 'Utah', 'lawsuit pending, case number, jurisdiction spelled out');
check(json_decode($l['source_urls'], true) === array('https://www.courtlistener.com/docket/999999/doe-v-example/'), 'lawsuit link in source_urls');

// Lawsuit Processor (full=1): every field written; publish needs manage_options; Undo logged.
$notifiedBefore = $GLOBALS['kop_notified'] ?? array();
$full_lawsuit = array('type' => 'lawsuit', 'full' => 1, 'url' => 'https://www.courtlistener.com/docket/888888/roe-v-sample/',
    'case_name' => 'Roe v. Sample Ranch', 'case_number' => '1:25-cv-00042', 'court' => 'U.S. District Court for the District of Idaho',
    'jurisdiction' => 'Idaho', 'filing_date' => '2025-03-04', 'status' => 'settled', 'outcome' => 'Settled; dismissed with prejudice',
    'settlement_amount' => '$1.5 million', 'summary' => 'Former residents allege abuse.',
    'plaintiffs' => array('Jane Roe', 'John Roe'), 'defendants' => array('Sample Ranch LLC'), 'facilities_mentioned' => array('Sample Ranch'),
    'staff_mentioned' => array('Pat Doe'), 'organizations_mentioned' => array('Sample Holdings'), 'claims' => array('negligence', 'physical_abuse'),
    'tags' => array('restraint'), 'document_urls' => array('https://example-court.test/complaint.pdf', 'javascript:alert(1)'), 'notes' => 'from the app');
$GLOBALS['kop_rinbox_logged'] = array();
$GLOBALS['kop_is_admin'] = false;
$r = submit($full_lawsuit + array('publish' => 1));
check($r instanceof WP_REST_Response && $r->status === 201 && empty($r->data['published']) && $r->data['type'] === 'lawsuit', 'full lawsuit + publish without manage_options -> queued, not published');
$l = row($pdo, 'lawsuits', $r->data['id']);
check($l['publication_status'] === 'pending' && empty($GLOBALS['kop_rinbox_logged']), 'it stays pending, nothing in Recently done');
check($l['case_name'] === 'Roe v. Sample Ranch' && $l['case_number'] === '1:25-cv-00042' && $l['court'] === 'U.S. District Court for the District of Idaho'
    && $l['jurisdiction'] === 'Idaho' && $l['filing_date'] === '2025-03-04' && $l['status'] === 'settled' && $l['outcome'] === 'Settled; dismissed with prejudice'
    && $l['settlement_amount'] === '$1.5 million' && $l['summary'] === 'Former residents allege abuse.', 'full lawsuit: scalar columns written');
check(json_decode($l['plaintiffs'], true) === array('Jane Roe', 'John Roe') && json_decode($l['defendants'], true) === array('Sample Ranch LLC')
    && json_decode($l['facilities_mentioned'], true) === array('Sample Ranch') && json_decode($l['staff_mentioned'], true) === array('Pat Doe')
    && json_decode($l['organizations_mentioned'], true) === array('Sample Holdings') && json_decode($l['claims'], true) === array('negligence', 'physical_abuse')
    && json_decode($l['tags'], true) === array('restraint'), 'full lawsuit: list columns JSON-encoded');
check(json_decode($l['document_urls'], true) === array('https://example-court.test/complaint.pdf')
    && json_decode($l['source_urls'], true) === array('https://www.courtlistener.com/docket/888888/roe-v-sample/'), 'document_urls kept (bad scheme dropped), source_urls = the link');

$GLOBALS['kop_is_admin'] = true;
$r = submit(array_merge($full_lawsuit, array('url' => 'https://www.courtlistener.com/docket/777777/poe-v-sample/', 'publish' => 1, 'case_name' => 'Poe v. Sample Ranch', 'status' => 'bogus', 'filing_date' => 'not a date')));
check($r instanceof WP_REST_Response && $r->status === 201 && !empty($r->data['published']) && $r->data['type'] === 'lawsuit' && $r->data['queue'] === 'the published lawsuits', 'full lawsuit + publish as admin -> published response');
$l = row($pdo, 'lawsuits', $r->data['id']);
check($l['publication_status'] === 'published' && !empty($l['published_at']) && $l['status'] === 'unknown' && ($l['filing_date'] === null || $l['filing_date'] === ''), 'published row: publication_status, published_at, bad status -> unknown, bad date blank');
check(($GLOBALS['kop_rinbox_logged'][0] ?? null) === array('lawsuit', array((int) $r->data['id']), 'approve'), 'published lawsuit is logged for Recently done (Undo restores pending)');
$r = submit(array_merge($full_lawsuit, array('url' => 'https://www.courtlistener.com/docket/666666/no-publish/')));
$l = row($pdo, 'lawsuits', $r->data['id']);
check($l['publication_status'] === 'pending' && empty($r->data['published']), 'full without publish stays pending');
$r = submit(array_merge($full_lawsuit, array('url' => 'https://example-court.test/roe-complaint.pdf',
    'source_urls' => array('https://www.courtlistener.com/docket/555555/roe/', 'https://example-court.test/roe-complaint.pdf', 'javascript:alert(1)'))));
$l = row($pdo, 'lawsuits', $r->data['id']);
check(json_decode($l['source_urls'], true) === array('https://example-court.test/roe-complaint.pdf', 'https://www.courtlistener.com/docket/555555/roe/'), 'full lawsuit: extra source_urls after the link, deduped, bad scheme dropped');
$GLOBALS['kop_notified'] = $notifiedBefore;

// Lawsuit Processor text read with a stubbed AI: field shaping.
if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
// api/ai-providers.php skips its whole body when kop_ai_api_keys exists: stub the three calls the lawsuit library uses.
function kop_ai_api_keys() { return array(); }
function kop_ai_extract_json(string $t): ?array { $d = json_decode($t, true); return is_array($d) ? $d : null; }
function kop_ai_generate_alternating(string $prompt, array $opts = []): string {
    $GLOBALS['kop_ai_calls'][] = $prompt;
    return json_encode(array('case_name' => 'Doe v. Stub', 'case_number' => '3:24-cv-1', 'court' => 'Stub Court', 'jurisdiction' => 'federal',
        'filing_date' => 'March 4, 2025', 'status' => 'Settled', 'outcome' => 'null', 'settlement_amount' => '$2 million', 'summary' => 'A summary.',
        'plaintiffs' => array('A', ' B '), 'defendants' => array('X', 'X', ' '), 'facilities_mentioned' => array('Stub Academy'), 'tags' => array('class action'),
        'junk' => 'dropped'));
}
require_once $root . '/inc/extension-lawsuit-processor.php';
$GLOBALS['kop_ai_calls'] = array();
$f = kop_ext_lawsuit_read_text(str_repeat('The complaint alleges things. ', 1500), 'https://example.test/doc.pdf', 'focus on the staff');
check(is_array($f) && array_keys($f) === array('case_name', 'case_number', 'court', 'jurisdiction', 'filing_date', 'status', 'outcome', 'settlement_amount', 'summary',
    'plaintiffs', 'defendants', 'facilities_mentioned', 'staff_mentioned', 'organizations_mentioned', 'claims', 'tags'), 'lawsuit-process: exactly the contract fields');
check(($f['filing_date'] ?? '') === '2025-03-04' && ($f['status'] ?? '') === 'settled' && ($f['jurisdiction'] ?? '') === 'Federal' && ($f['outcome'] ?? 'x') === ''
    && ($f['plaintiffs'] ?? null) === array('A', 'B') && ($f['defendants'] ?? null) === array('X') && ($f['staff_mentioned'] ?? null) === array(), 'lawsuit-process: date, status, jurisdiction normalised; lists cleaned');
check(count($GLOBALS['kop_ai_calls']) === 4 && strpos($GLOBALS['kop_ai_calls'][0], 'focus on the staff') !== false && strpos($GLOBALS['kop_ai_calls'][1], 'focus on the staff') === false, 'lawsuit-process: capped at 4 chunks, instructions on the first');

// CourtListener prefill (api/lib-courtlistener.php) with a stubbed API.
require_once $root . '/api/lib-courtlistener.php';
if (!function_exists('wp_strip_all_tags')) { function wp_strip_all_tags($s) { return strip_tags((string) $s); } }
$GLOBALS['kop_cl_requests'] = array();
$GLOBALS['kop_cl_http_stub'] = function ($url) {
    $GLOBALS['kop_cl_requests'][] = $url;
    $docket = array('id' => 4242, 'court_id' => 'utd', 'docket_number' => '2:24-cv-00077', 'case_name' => 'Roe v. Stub Academy, LLC',
        'date_filed' => '2024-02-01', 'date_terminated' => null, 'cause' => '42:1983 Civil Rights Act', 'absolute_url' => '/docket/4242/roe-v-stub-academy/');
    if (preg_match('#/dockets/4242/$#', $url)) return $docket;
    if (strpos($url, '/dockets/?') !== false && (strpos($url, 'docket_number=3%3A24-cv-1') !== false || strpos($url, 'pacer_case_id=777') !== false)) return array('results' => array($docket));
    if (strpos($url, '/dockets/?') !== false) return array('results' => array());
    if (strpos($url, '/courts/utd/') !== false) return array('full_name' => 'District Court, D. Utah');
    if (strpos($url, '/parties/?') !== false) return array('results' => array(
        array('name' => 'Jane Roe', 'party_types' => array(array('name' => 'Plaintiff'))),
        array('name' => 'Stub Academy, LLC', 'party_types' => array(array('name' => 'Defendant'))),
        array('name' => 'Interested Person', 'party_types' => array(array('name' => 'Interested Party'))),
    ));
    return null;
};
check(kop_cl_docket_ref_from_url('https://www.courtlistener.com/docket/4242/12/roe-v-stub-academy/') === array('id' => '4242')
    && kop_cl_docket_ref_from_url('https://storage.courtlistener.com/recap/gov.uscourts.utd.777/gov.uscourts.utd.777.1.0.pdf') === array('court' => 'utd', 'pacer_case_id' => '777')
    && kop_cl_docket_ref_from_url('https://example.test/docket/4242/') === null && kop_cl_docket_ref_from_url('') === null, 'courtlistener: docket, filing and RECAP PDF links read; others not');
$lk = kop_cl_lookup_case('3:24-cv-1', '', 'tok');
check(!empty($lk['success']) && $lk['data']['court'] === 'District Court, D. Utah' && $lk['data']['plaintiffs'] === array('Jane Roe') && $lk['data']['defendants'] === array('Stub Academy, LLC')
    && $lk['data']['status'] === 'in_progress' && $lk['data']['source_urls'] === array('https://www.courtlistener.com/docket/4242/roe-v-stub-academy/'), 'courtlistener: the admin auto-fill payload is unchanged');
$lk = kop_cl_lookup_case('9:99-cv-9', '', 'tok');
check(empty($lk['success']) && strpos($lk['error'], "No federal docket found matching case number '9:99-cv-9'") === 0, 'courtlistener: no match -> the old message');

// The AI read a federal case number: the docket's facts win, its parties first.
$GLOBALS['kop_ai_calls'] = array();
$p = kop_ext_rest_lawsuit_process(new WP_REST_Request(array(), array('url' => 'https://example.test/complaint', 'text' => str_repeat('The complaint alleges things. ', 40))));
check(is_array($p) && $p['fields']['case_name'] === 'Roe v. Stub Academy, LLC' && $p['fields']['case_number'] === '2:24-cv-00077' && $p['fields']['court'] === 'District Court, D. Utah'
    && $p['fields']['filing_date'] === '2024-02-01' && $p['fields']['status'] === 'settled' && $p['fields']['summary'] === 'A summary.'
    && $p['fields']['plaintiffs'] === array('Jane Roe', 'A', 'B') && $p['fields']['defendants'] === array('Stub Academy, LLC', 'X')
    && $p['courtlistener'] === array('url' => 'https://www.courtlistener.com/docket/4242/roe-v-stub-academy/', 'by' => 'case_number'), 'lawsuit-process: federal case number -> docket merged, AI status and summary kept');
check(array_keys($p['fields']) === array_keys($f), 'lawsuit-process: merged answer keeps exactly the contract fields');

// A CourtListener link with no readable text: the docket alone, no AI call.
$GLOBALS['kop_ai_calls'] = array();
$p = kop_ext_rest_lawsuit_process(new WP_REST_Request(array(), array('url' => 'https://www.courtlistener.com/docket/4242/roe-v-stub-academy/', 'text' => 'short')));
check(is_array($p) && $p['fields']['case_name'] === 'Roe v. Stub Academy, LLC' && $p['fields']['status'] === 'in_progress' && $p['fields']['summary'] === '42:1983 Civil Rights Act'
    && $p['fields']['facilities_mentioned'] === array() && $p['courtlistener']['by'] === 'link' && $GLOBALS['kop_ai_calls'] === array(), 'lawsuit-process: CourtListener link + too little text -> filled from the docket');

// A state case: no lookup at all; nothing found: the AI fields as before.
$GLOBALS['kop_cl_requests'] = array();
$state = kop_ext_lawsuit_courtlistener('', array('case_number' => '3:24-cv-1', 'jurisdiction' => 'Utah'));
check($state === null && $GLOBALS['kop_cl_requests'] === array(), 'lawsuit-process: state case -> CourtListener not asked');
$p = kop_ext_lawsuit_answer($f, kop_ext_lawsuit_courtlistener('', array('case_number' => '9:99-cv-9', 'jurisdiction' => 'Federal')));
check($p === array('fields' => $f), 'lawsuit-process: no docket -> the AI fields unchanged, no courtlistener key');
$GLOBALS['kop_cl_http_stub'] = null;

$r = submit(array('type' => 'legislation', 'url' => 'https://www.congress.gov/bill/119th-congress/house-bill/9999', 'title' => 'Stop Institutional Child Abuse Act',
    'bill_number' => 'HR 9999', 'jurisdiction' => 'US', 'session' => '119th Congress'));
check($r instanceof WP_REST_Response && $r->status === 201 && $r->data['type'] === 'legislation', 'new bill -> 201 in legislation');
$b = row($pdo, 'legislation', $r->data['id']);
check($b['publication_status'] === 'pending' && $b['jurisdiction'] === 'Federal' && $b['session_year'] === '119th Congress' && $b['official_url'] === 'https://www.congress.gov/bill/119th-congress/house-bill/9999', 'bill pending, Federal, session, official_url');
$r2 = submit(array('type' => 'legislation', 'url' => 'https://www.govtrack.us/congress/bills/119/hr9999', 'title' => 'x', 'bill_number' => 'H.R. 9999', 'jurisdiction' => 'US'));
check($r2 instanceof WP_REST_Response && $r2->status === 409, 'the same bill from another tracker -> 409');

$r = submit(array('type' => 'website', 'url' => 'https://www.example-academy.test/', 'title' => 'Example Academy home', 'facility' => 'Example Academy'));
check($r instanceof WP_REST_Response && $r->status === 201 && $r->data['type'] === 'website', 'new website -> 201 in the Websites queue');
check(($GLOBALS['kop_posts'][$r->data['id']]['post_status'] ?? '') === 'pending' && get_post_meta($r->data['id'], '_kop_submitted_by') === 'Test Admin (browser extension)', 'website pending with sender');
$r2 = submit(array('type' => 'website', 'url' => 'http://example-academy.test', 'title' => 'x'));
check($r2 instanceof WP_REST_Response && $r2->status === 409 && $r2->data['duplicates'][0]['type'] === 'website', 'same website without www or slash -> 409');

$r = submit(array('type' => 'article', 'url' => 'javascript:alert(1)'));
check($r instanceof WP_Error && $r->data['status'] === 400, 'non-http link -> 400');
check(($GLOBALS['kop_notified'] ?? array()) === array('news', 'lawsuit', 'legislation', 'website'), 'one admin email per new record');

// Fallback auth header: only on the extension's routes.
$cred = 'Basic ' . base64_encode('someone:abcd efgh');
$_SERVER = array('REQUEST_URI' => '/wp-json/kop/v1/extension/check?url=x', 'HTTP_X_KOP_AUTHORIZATION' => $cred);
kop_ext_restore_basic_auth();
check(($_SERVER['PHP_AUTH_USER'] ?? '') === 'someone' && ($_SERVER['PHP_AUTH_PW'] ?? '') === 'abcd efgh', 'X-KOP-Authorization restored on an extension route');
$_SERVER = array('REQUEST_URI' => '/wp-json/wp/v2/users', 'HTTP_X_KOP_AUTHORIZATION' => $cred);
kop_ext_restore_basic_auth();
check(!isset($_SERVER['PHP_AUTH_USER']), 'X-KOP-Authorization ignored elsewhere');
$_SERVER = array('REQUEST_URI' => '/?rest_route=/kop/v1/extension/submit', 'HTTP_X_KOP_AUTHORIZATION' => $cred);
kop_ext_restore_basic_auth();
check(($_SERVER['PHP_AUTH_USER'] ?? '') === 'someone', 'restored on the ?rest_route= form too');

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
