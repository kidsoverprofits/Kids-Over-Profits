<?php
/**
 * The owner's local Qwen after Groq and Gemini (api/local-ai.php): the queue, the heartbeat's
 * freshness, the fallback's errors, and a saved answer handed out once.
 *
 *   php -d extension=mbstring scripts/test-local-ai.php
 *   KOP_LAI_LIVE=http://127.0.0.1:11435 KOP_LAI_TOKEN=... php ... also asks a running worker's proxy
 */

$tmp = sys_get_temp_dir() . '/kop-lai-test-' . getmypid();
$GLOBALS['kop_lai_dir'] = $tmp;
$GLOBALS['kop_ai_usage_dir'] = $tmp . '/usage';
require __DIR__ . '/../api/ai-usage.php';
require __DIR__ . '/../api/local-ai.php';

$fails = 0;
$check = function (string $what, bool $ok) use (&$fails) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) $fails++;
};

// Keys
$check('a prompt keys by its text', kop_lai_key('a') === kop_lai_key('a') && kop_lai_key('a') !== kop_lai_key('b'));
$check('an own key wins over the text', kop_lai_key('a', 'news:x') === kop_lai_key('b', 'news:x'));

// Offline: the fallback keeps every earlier error, says offline, and saves the prompt
$caught = '';
try {
    kop_lai_fallback('{"q":1}', array('maxTokens' => 100), array('Groq rate limit exceeded.', 'Gemini rate limit exceeded (HTTP 429): quota'));
} catch (Exception $e) {
    $caught = $e->getMessage();
}
$check('offline: the error still says rate limit', stripos($caught, 'rate limit') !== false);
$check('offline: says so and that it saved the prompt', strpos($caught, 'Local AI offline') !== false && strpos($caught, 'saved for the local AI') !== false);
$check('the folder is closed to the web', is_file($tmp . '/.htaccess'));
$jobs = kop_lai_jobs('waiting', 0, true);
$check('one job waiting, with its prompt and settings', count($jobs) === 1 && $jobs[0]['prompt'] === '{"q":1}' && $jobs[0]['maxTokens'] === 100);
$raw = file_get_contents(kop_lai_job_path($jobs[0]['key']));
$check('a job file shows nothing if served', strpos($raw, "<?php exit; ?>\n") === 0);

// The same prompt again: not saved twice
try { kop_lai_fallback('{"q":1}', array(), array('x')); } catch (Exception $e) { $caught = $e->getMessage(); }
$check('saved once only', count(kop_lai_jobs('waiting')) === 1 && strpos($caught, 'saved for') === false);

// noLocal / off
try { kop_lai_fallback('{"q":2}', array('noLocal' => true), array('only this')); } catch (Exception $e) { $caught = $e->getMessage(); }
$check('noLocal: the earlier errors only, nothing saved', $caught === 'only this' && count(kop_lai_jobs()) === 1);

// The worker answers; the next call gets it once
$key = $jobs[0]['key'];
$check('an empty answer is refused', !kop_lai_answer($key, '  '));
$check('the answer is kept', kop_lai_answer($key, '{"a":1}', 'qwen3.5:4b'));
$check('a job answered twice is refused', !kop_lai_answer($key, '{"a":2}'));
$check('nothing waiting now', count(kop_lai_jobs('waiting')) === 0);
$st = kop_lai_status();
$check('status: off, 0 waiting, 1 answered', !$st['live'] && $st['waiting'] === 0 && $st['answered'] === 1);
$check('the next fallback hands out the saved answer', kop_lai_fallback('{"q":1}', array(), array('x')) === '{"a":1}');
$check('...and only once', kop_lai_take_answer($key) === null);

// The heartbeat
$threw = false;
try { kop_lai_set_endpoint('http://evil.example', str_repeat('a', 32), 'm'); } catch (InvalidArgumentException $e) { $threw = true; }
$check('only https addresses', $threw);
$threw = false;
try { kop_lai_set_endpoint('https://x.trycloudflare.com', 'short', 'm'); } catch (InvalidArgumentException $e) { $threw = true; }
$check('only long tokens', $threw);
kop_lai_set_endpoint('https://abc-def.trycloudflare.com/', str_repeat('t', 40), 'qwen3.5:4b');
$check('fresh after a heartbeat', kop_lai_endpoint() !== null && kop_lai_endpoint()['url'] === 'https://abc-def.trycloudflare.com');
$check('stale after three minutes', kop_lai_endpoint(time() + 181) === null);
$check('status: on', kop_lai_status()['live']);
kop_lai_clear_endpoint();
$check('off after stop', kop_lai_endpoint() === null);

// Pruning
kop_lai_enqueue(kop_lai_key('old'), 'old', array(), 'test');
touch(kop_lai_job_path(kop_lai_key('old')), time() - 8 * 86400);
kop_lai_prune();
$check('jobs older than a week are dropped', !is_file(kop_lai_job_path(kop_lai_key('old'))));
$check('a prompt too big is not saved', !kop_lai_enqueue(kop_lai_key('big'), str_repeat('x', KOP_LAI_MAX_PROMPT + 1)));

// Live, against a running worker's proxy (optional)
if (getenv('KOP_LAI_LIVE')) {
    $t0 = microtime(true);
    try {
        $text = kop_lai_call_live(array('url' => getenv('KOP_LAI_LIVE'), 'token' => getenv('KOP_LAI_TOKEN')),
            'Reply with JSON {"title": "..."} giving a short title for: The state closed the ranch after two inspections found restraint injuries.', array('maxTokens' => 200));
        $check(sprintf('live: JSON back in %.1fs: %s', microtime(true) - $t0, mb_substr($text, 0, 80)), is_array(json_decode($text, true)));
    } catch (Exception $e) {
        $check('live: ' . $e->getMessage(), false);
    }
    $bad = '';
    try { kop_lai_call_live(array('url' => getenv('KOP_LAI_LIVE'), 'token' => 'wrong'), '{}'); } catch (Exception $e) { $bad = $e->getMessage(); }
    $check('live: a wrong token is refused', strpos($bad, '403') !== false);
}

// Clean up
$rm = function ($d) use (&$rm) {
    foreach (array_merge(glob($d . '/*') ?: array(), glob($d . '/.ht*') ?: array()) as $f) is_dir($f) ? $rm($f) : unlink($f);
    @rmdir($d);
};
$rm($tmp);
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
