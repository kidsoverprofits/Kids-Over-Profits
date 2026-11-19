<?php
/**
 * Local AI: the owner's own Qwen (Ollama on the owner's PC), the last resort after Groq and Gemini.
 *
 * The site runs on shared hosting and cannot reach the PC by itself, so the PC's worker
 * ((kop-pipeline) scripts/local-ai-worker.py) does two things:
 *
 *  1. Live. It opens a Cloudflare quick tunnel to a small proxy in front of Ollama and tells the site
 *     the tunnel's address and a fresh token every minute (POST /wp-json/kop/v1/local-ai/heartbeat,
 *     research key). While that is fresh, a prompt Groq and Gemini both failed goes there.
 *  2. Queue. When the PC is off, or the live call fails, the prompt is saved as a job. The worker
 *     fetches waiting jobs, answers them with Qwen and posts the answers back. The call that asked
 *     still fails as before (its item waits for the next run, as with a rate limit), and when the
 *     next run asks the same thing the saved answer is used without calling anyone.
 *
 * A job is keyed by a hash of the prompt (or the caller's own key: news articles use their link, since
 * a page fetched twice rarely gives the same text). Files, not a table: process-news-ai.php runs
 * without WordPress. The folder is uploads/kop-local-ai/, closed to the web by .htaccess, and every
 * file in it starts with "<?php exit; ?>" as well.
 */

if (!function_exists('kop_lai_dir')) {

    define('KOP_LAI_FRESH_SECONDS', 180);  // the worker says hello every 60 s
    define('KOP_LAI_KEEP_SECONDS', 7 * 86400);
    define('KOP_LAI_MAX_JOBS', 300);
    define('KOP_LAI_MAX_PROMPT', 200000); // bytes
    define('KOP_LAI_FILE_HEAD', "<?php exit; ?>\n");

    function kop_lai_dir(): string {
        if (!empty($GLOBALS['kop_lai_dir'])) return (string) $GLOBALS['kop_lai_dir']; // tests
        if (function_exists('wp_upload_dir')) {
            $u = wp_upload_dir(null, false);
            if (!empty($u['basedir'])) return rtrim($u['basedir'], '/\\') . '/kop-local-ai';
        }
        return dirname(__DIR__, 3) . '/uploads/kop-local-ai';
    }

    /** The folder (and jobs/ inside it), made and closed to the web on first use. '' when it can't be made. */
    function kop_lai_ready_dir(): string {
        $dir = kop_lai_dir();
        if (!is_dir($dir . '/jobs')) {
            if (!@mkdir($dir . '/jobs', 0755, true) && !is_dir($dir . '/jobs')) return '';
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            @file_put_contents($dir . '/index.php', "<?php\n");
            @file_put_contents($dir . '/jobs/index.php', "<?php\n");
        }
        return $dir;
    }

    function kop_lai_read(string $path): ?array {
        if (!is_file($path)) return null;
        $raw = @file_get_contents($path);
        if ($raw === false || strpos($raw, KOP_LAI_FILE_HEAD) !== 0) return null;
        $d = json_decode(substr($raw, strlen(KOP_LAI_FILE_HEAD)), true);
        return is_array($d) ? $d : null;
    }

    function kop_lai_write(string $path, array $data): bool {
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, KOP_LAI_FILE_HEAD . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) === false) return false;
        if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
        return true;
    }

    /** The job key: the caller's own key, else the prompt's hash. */
    function kop_lai_key(string $prompt, ?string $own = null): string {
        return sha1($own !== null && $own !== '' ? 'own:' . $own : 'prompt:' . $prompt);
    }

    function kop_lai_job_path(string $key): string {
        return kop_lai_dir() . '/jobs/' . preg_replace('/[^a-f0-9]/', '', $key) . '.php';
    }

    // ---- the live tunnel ----------------------------------------------------------------------

    /** Where the worker said it is; ['url','token','model','seen'] or null. */
    function kop_lai_endpoint_info(): ?array {
        return kop_lai_read(kop_lai_dir() . '/endpoint.php');
    }

    /** The worker's address while it is fresh, else null. */
    function kop_lai_endpoint(?int $now = null): ?array {
        $e = kop_lai_endpoint_info();
        if (!$e || empty($e['url']) || empty($e['token'])) return null;
        return (($now ?? time()) - (int) ($e['seen'] ?? 0)) <= KOP_LAI_FRESH_SECONDS ? $e : null;
    }

    /** The worker's heartbeat. Only https addresses; throws on a bad one. */
    function kop_lai_set_endpoint(string $url, string $token, string $model): void {
        $url = rtrim(trim($url), '/');
        if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $url)) throw new InvalidArgumentException('url must be https://host');
        if (!preg_match('/^[A-Za-z0-9_-]{24,128}$/', $token)) throw new InvalidArgumentException('bad token');
        $dir = kop_lai_ready_dir();
        if ($dir === '') throw new RuntimeException('Could not make the local AI folder.');
        kop_lai_write($dir . '/endpoint.php', array('url' => $url, 'token' => $token,
            'model' => mb_substr(trim($model), 0, 80), 'seen' => time()));
    }

    /** The worker's last words before it stops: the site stops trying the tunnel at once. */
    function kop_lai_clear_endpoint(): void {
        @unlink(kop_lai_dir() . '/endpoint.php');
    }

    /** One prompt through the tunnel; throws on any failure. */
    function kop_lai_call_live(array $endpoint, string $prompt, array $opts = []): string {
        $timeout = (int) ($opts['localTimeout'] ?? 75);
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $endpoint['url'] . '/generate',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(array(
                'prompt'      => $prompt,
                'maxTokens'   => (int) ($opts['maxTokens'] ?? 4096),
                'temperature' => (float) ($opts['temperature'] ?? 0.1),
            ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            CURLOPT_HTTPHEADER     => array('Content-Type: application/json', 'X-KOP-Local-Token: ' . $endpoint['token']),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
        ));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) throw new Exception('Local AI not reachable: ' . $err);
        $d = json_decode((string) $body, true);
        if ($code !== 200 || !is_array($d) || !isset($d['text'])) {
            throw new Exception('Local AI error (HTTP ' . $code . '): ' . mb_substr((string) ($d['error'] ?? $body), 0, 160));
        }
        $text = trim((string) $d['text']);
        if ($text === '') throw new Exception('Local AI gave an empty answer.');
        return $text;
    }

    // ---- the queue ----------------------------------------------------------------------------

    /** A saved answer for this key, removed as it is handed out (null = none). */
    function kop_lai_take_answer(string $key): ?string {
        $path = kop_lai_job_path($key);
        if (!is_file($path)) return null;
        $job = kop_lai_read($path);
        if (!$job || ($job['status'] ?? '') !== 'done' || !isset($job['result'])) return null;
        @unlink($path);
        return (string) $job['result'];
    }

    /** Save a prompt for the worker. False when it is already waiting, too big, or the queue is full. */
    function kop_lai_enqueue(string $key, string $prompt, array $opts = [], string $job = ''): bool {
        if (strlen($prompt) > KOP_LAI_MAX_PROMPT) return false;
        $dir = kop_lai_ready_dir();
        if ($dir === '') return false;
        $path = kop_lai_job_path($key);
        if (is_file($path)) return false;
        kop_lai_prune();
        if (count(glob($dir . '/jobs/*.php') ?: array()) - 1 >= KOP_LAI_MAX_JOBS) return false; // -1: index.php
        return kop_lai_write($path, array(
            'key' => $key, 'status' => 'waiting', 'job' => mb_substr($job, 0, 80), 'created' => time(),
            'prompt' => $prompt,
            'maxTokens' => (int) ($opts['maxTokens'] ?? 4096), 'temperature' => (float) ($opts['temperature'] ?? 0.1),
        ));
    }

    /** Drop jobs older than a week, answered or not. */
    function kop_lai_prune(?int $now = null): void {
        $cut = ($now ?? time()) - KOP_LAI_KEEP_SECONDS;
        foreach (glob(kop_lai_dir() . '/jobs/*.php') ?: array() as $f) {
            if (basename($f) === 'index.php') continue;
            if (@filemtime($f) < $cut) @unlink($f);
        }
    }

    /** Every job (waiting first, oldest first), without the prompts unless $with_prompt. */
    function kop_lai_jobs(?string $status = null, int $limit = 0, bool $with_prompt = false): array {
        $out = array();
        foreach (glob(kop_lai_dir() . '/jobs/*.php') ?: array() as $f) {
            if (basename($f) === 'index.php') continue;
            $j = kop_lai_read($f);
            if (!$j || ($status !== null && ($j['status'] ?? '') !== $status)) continue;
            if (!$with_prompt) unset($j['prompt'], $j['result']);
            $out[] = $j;
        }
        usort($out, function ($a, $b) {
            return array(($a['status'] ?? '') !== 'waiting', $a['created'] ?? 0) <=> array(($b['status'] ?? '') !== 'waiting', $b['created'] ?? 0);
        });
        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /** The worker's answer for a job. False when there is no such waiting job. */
    function kop_lai_answer(string $key, string $result, string $model = ''): bool {
        $path = kop_lai_job_path($key);
        $job = kop_lai_read($path);
        if (!$job || ($job['status'] ?? '') !== 'waiting') return false;
        $result = trim($result);
        if ($result === '') return false;
        unset($job['prompt']);
        $job['status'] = 'done';
        $job['result'] = $result;
        $job['model'] = mb_substr($model, 0, 80);
        $job['answered'] = time();
        return kop_lai_write($path, $job);
    }

    /** ['live' => bool, 'seen' => int, 'model' => string, 'waiting' => n, 'answered' => n] for the AI Usage screen. */
    function kop_lai_status(): array {
        $e = kop_lai_endpoint_info();
        $s = array('live' => kop_lai_endpoint() !== null, 'seen' => (int) ($e['seen'] ?? 0), 'model' => (string) ($e['model'] ?? ''),
            'waiting' => 0, 'answered' => 0);
        foreach (kop_lai_jobs() as $j) {
            if (($j['status'] ?? '') === 'waiting') $s['waiting']++; else $s['answered']++;
        }
        return $s;
    }

    // ---- the fallback every alternating caller uses -------------------------------------------

    /**
     * After Groq and Gemini both failed: the live tunnel if the PC is on, else (or when that fails
     * too) a saved job. Returns the answer, or throws the earlier errors with the local one after
     * them, so a message that said "rate limit" still does (discovery defers on that).
     */
    function kop_lai_fallback(string $prompt, array $opts, array $errors, ?string $own_key = null): string {
        if (!empty($opts['noLocal']) || getenv('KOP_LOCAL_AI') === 'off') throw new Exception(implode(' | ', $errors));
        $key = kop_lai_key($prompt, $own_key);
        if (($saved = kop_lai_take_answer($key)) !== null) {
            $GLOBALS['kop_ai_last_provider'] = 'local';
            return $saved;
        }
        $endpoint = kop_lai_endpoint();
        if ($endpoint) {
            $model = 'local:' . ($endpoint['model'] ?: 'ollama');
            try {
                $text = kop_lai_call_live($endpoint, $prompt, $opts);
                if (function_exists('kop_ai_usage_record')) kop_ai_usage_record('local', $model);
                $GLOBALS['kop_ai_last_provider'] = 'local';
                return $text;
            } catch (Throwable $e) {
                if (function_exists('kop_ai_usage_record')) kop_ai_usage_record('local', $model, $e->getMessage());
                $errors[] = $e->getMessage();
            }
        } else {
            $errors[] = 'Local AI offline';
        }
        $job = function_exists('kop_ai_usage_job') ? kop_ai_usage_job() : '';
        if (kop_lai_enqueue($key, $prompt, $opts, $job)) $errors[] = 'saved for the local AI';
        throw new Exception(implode(' | ', $errors));
    }
}
