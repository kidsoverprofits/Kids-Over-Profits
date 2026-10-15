<?php
/**
 * KOP Tools > AI Usage (inc/ai-usage.php) and the request log under it
 * (api/ai-usage.php): job names from the call stack and X-KOP-Job, results,
 * keys scrubbed, 14 days kept, the summary, the screen. Offline, temp dir.
 *
 *   php -d extension=mbstring scripts/test-ai-usage.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

$theme = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/kop-ai-usage-test-' . getmypid();
@mkdir($tmp, 0755, true);
$GLOBALS['kop_ai_usage_dir'] = $tmp . '/log';

$fails = 0;
$check = function ($name, $ok, $detail = '') use (&$fails) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' -- ' . $detail) . "\n";
    if (!$ok) $fails++;
};

require_once $theme . '/api/ai-usage.php';
require_once $theme . '/api/ai-providers.php';

// Job from the first file outside the AI libraries: a stand-in doc-types.php.
@mkdir($tmp . '/inc', 0755, true);
file_put_contents($tmp . '/inc/doc-types.php', "<?php\nfunction kop_test_doc_call() { kop_ai_usage_record('gemini', 'gemini-x', 'Gemini rate limit exceeded (HTTP 429): You exceeded your current quota key=SECRET123'); }\n");
require $tmp . '/inc/doc-types.php';
kop_test_doc_call();
$rows = kop_ai_usage_read(time() - 60);
$check('a request is logged', count($rows) === 1, json_encode($rows));
$check('job named from the calling file', ($rows[0]['job'] ?? '') === 'Document types', $rows[0]['job'] ?? '');
$check('quota error is "limit"', ($rows[0]['result'] ?? '') === 'limit');
$check('key scrubbed from the error', strpos($rows[0]['error'] ?? '', 'SECRET123') === false && strpos($rows[0]['error'] ?? '', 'key=...') !== false, $rows[0]['error'] ?? '');

$GLOBALS['kop_ai_job'] = kop_ai_usage_header_jobs()['discovery'];
kop_ai_usage_record('groq', 'openai/gpt-oss-120b');
kop_ai_usage_record('groq', 'openai/gpt-oss-120b', "Could not parse AI response\tas JSON");
unset($GLOBALS['kop_ai_job']);
$rows = kop_ai_usage_read(time() - 60);
$check('a job naming itself wins', ($rows[1]['job'] ?? '') === 'News discovery (nightly)', $rows[1]['job'] ?? '');
$check('ok and error results', ($rows[1]['result'] ?? '') === 'ok' && ($rows[2]['result'] ?? '') === 'error');
$check('a tab in an error does not split the line', ($rows[2]['error'] ?? '') === 'Could not parse AI response as JSON', $rows[2]['error'] ?? '');
$check('Groq "Request too large" counts as a limit', kop_ai_usage_result('Groq API error (HTTP 413): Request too large for model') === 'limit');
$check('unreachable page is an error, not a limit', kop_ai_usage_result('Could not fetch article content from URL') === 'error');

$fn = 'kop_ai_generate';
$before = count(kop_ai_usage_read(time() - 60));
try { $fn('groq', array('groq' => ''), 'x'); } catch (Throwable $e) { }
$check('no key: nothing is logged (no request was made)', count(kop_ai_usage_read(time() - 60)) === $before);

$check('the log folder is closed to the web', is_file($GLOBALS['kop_ai_usage_dir'] . '/.htaccess'));
$old = $GLOBALS['kop_ai_usage_dir'] . '/' . gmdate('Y-m-d', time() - 20 * 86400) . '.tsv';
file_put_contents($old, "x\n");
unlink($GLOBALS['kop_ai_usage_dir'] . '/' . gmdate('Y-m-d') . '.tsv');
kop_ai_usage_record('groq', 'm');
$check('files older than 14 days are deleted with a new day', !file_exists($old));

// The daily budget: a background job stops at its share, discovery never does.
$day = kop_ai_budget_day_start(strtotime('2026-10-15 13:00 UTC')); // 06:00 Pacific
$check('the budget day starts at midnight Pacific', gmdate('Y-m-d H:i', $day) === '2026-10-15 07:00', gmdate('Y-m-d H:i', $day));
putenv('KOP_AI_DAILY_GEMINI=10');
putenv('KOP_AI_BACKGROUND_SHARE=0.5');
$l = kop_ai_budget_limits();
$check('limits from the environment', $l['gemini']['daily'] === 10 && $l['gemini']['background'] === 5 && $l['groq']['daily'] === 400, json_encode($l));
foreach (glob($GLOBALS['kop_ai_usage_dir'] . '/*.tsv') as $f) unlink($f);
unset($GLOBALS['kop_ai_budget_counts']);
$GLOBALS['kop_ai_job'] = 'Document types';
$held = null;
for ($i = 0; $i < 8; $i++) {
    try { kop_ai_budget_check('gemini', 'm'); kop_ai_usage_record('gemini', 'm'); } catch (Exception $e) { $held = $held ?? $i; }
}
$check('a background job is held back at its share', $held === 5, var_export($held, true));
$check('the held-back error says rate limit, so jobs try again later', isset($e) && kop_ai_is_rate_limit($e->getMessage()) && !($e instanceof RuntimeException));
$check('held-back requests are not counted as sent', kop_ai_budget_used('gemini') === 5);
$GLOBALS['kop_ai_job'] = 'News discovery (nightly)';
$ok = true;
try { kop_ai_budget_check('gemini', 'm'); } catch (Exception $e) { $ok = false; }
$check('news discovery is never held back', $ok);
$GLOBALS['kop_ai_job'] = 'Document types';
$ok = true;
try { kop_ai_budget_check('groq', 'm'); } catch (Exception $e) { $ok = false; }
$check('the other provider has its own count', $ok);
unset($GLOBALS['kop_ai_budget_counts']);
$check('the count is read back from the log', kop_ai_budget_used('gemini') === 5);
$rows = kop_ai_usage_read(time() - 60);
$check('held-back requests are logged as held', count(array_filter($rows, function ($r) { return $r['result'] === 'held'; })) === 3);
unset($GLOBALS['kop_ai_job']);
putenv('KOP_AI_DAILY_GEMINI');
putenv('KOP_AI_BACKGROUND_SHARE');
kop_ai_usage_record('groq', 'm'); // a request of this file's own, for the screen

// The screen: WordPress stubs only as far as inc/ai-usage.php and the admin kit go.
define('ABSPATH', $theme . '/');
define('DAY_IN_SECONDS', 86400);
function add_action() {}
function add_filter() {}
function apply_filters($t, $v) { return $v; }
function get_stylesheet_directory() { return dirname(__DIR__); }
function current_user_can() { return true; }
function wp_die($m) { throw new RuntimeException($m); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg($k, $v, $u) { return $u . (strpos($u, '?') === false ? '?' : '&') . $k . '=' . rawurlencode($v); }
function wp_create_nonce() { return 'nonce'; }
function wp_json_encode($v) { return json_encode($v); }
function wp_date($f, $t) { return date($f, $t); }
require_once $theme . '/inc/admin-kit.php';
require_once $theme . '/inc/ai-usage.php';

$now = 1760000000;
$rows = array();
for ($i = 0; $i < 50; $i++) $rows[] = array('t' => $now - 3 * 3600 + $i, 'provider' => $i % 2 ? 'groq' : 'gemini', 'model' => 'm', 'job' => 'Document types', 'result' => 'ok', 'error' => '');
$rows[] = array('t' => $now - 600, 'provider' => 'gemini', 'model' => 'm', 'job' => 'News discovery (nightly)', 'result' => 'limit', 'error' => 'quota');
$rows[] = array('t' => $now - 300, 'provider' => 'gemini', 'model' => 'm', 'job' => 'News discovery (nightly)', 'result' => 'limit', 'error' => 'quota');
$rows[] = array('t' => $now - 100, 'provider' => 'groq', 'model' => 'm', 'job' => 'Plain summaries', 'result' => 'ok', 'error' => '');
$s = kop_ai_usage_summary($rows, $now);
$check('busiest job first', $s['jobs'][0]['job'] === 'Document types' && $s['jobs'][0]['groq'] === 25 && $s['jobs'][0]['gemini'] === 25);
$check('out of quota since the first limit in a row', $s['providers']['gemini']['last_result'] === 'limit' && $s['providers']['gemini']['limit_since'] === $now - 600);
$check('a later success clears it', $s['providers']['groq']['last_result'] === 'ok' && $s['providers']['groq']['limit_since'] === 0);
$check('48 hourly bars', count($s['hours']) === 48);
$burst = $s['hours'][(int) (floor(($now - 3 * 3600) / 3600) * 3600)] ?? null;
$check('the burst hour holds its job', $burst && $burst['n'] === 50 && array_key_first($burst['jobs']) === 'Document types');

$_GET = array('period' => '7d');
ob_start();
kop_ai_usage_page();
$html = ob_get_clean();
$check('the screen renders the table', strpos($html, '<table class="widefat striped kop-aiu-table">') !== false && strpos($html, '<td>test-ai-usage</td>') !== false, substr($html, 0, 300));
$check('the screen has Check now', strpos($html, 'kop-aiu-check') !== false);
$check('the screen says Groq is answering', strpos($html, '<strong>Groq:</strong> last request') !== false);
file_put_contents($tmp . '/ai-usage.html', $html);
echo "Screen written to $tmp/ai-usage.html\n";

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
