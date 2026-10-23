<?php
/**
 * One line per AI request the site makes, so KOP Tools > AI Usage can say which
 * job spent the shared Groq and Gemini free quota (inc/ai-usage.php).
 *
 * Works without WordPress (api/process-news-ai.php runs without it): a tab
 * separated file per UTC day in wp-content/uploads/kop-ai-usage/, appended
 * under a lock, kept 14 days. Each line: time, provider, model, job, result
 * (ok / limit / error / held = kept back by the daily budget, never sent),
 * the error's first words. Never a key or a prompt.
 *
 * The job is $GLOBALS['kop_ai_job'] when a caller sets it, else the first file
 * on the call stack outside the AI libraries, named by kop_ai_usage_jobs().
 */

if (!function_exists('kop_ai_usage_record')) {

    function kop_ai_usage_dir(): string {
        if (!empty($GLOBALS['kop_ai_usage_dir'])) return (string) $GLOBALS['kop_ai_usage_dir']; // tests
        if (function_exists('wp_upload_dir')) {
            $u = wp_upload_dir(null, false);
            if (!empty($u['basedir'])) return rtrim($u['basedir'], '/\\') . '/kop-ai-usage';
        }
        return dirname(__DIR__, 3) . '/uploads/kop-ai-usage';
    }

    /** File name => job name as the screen prints it. */
    function kop_ai_usage_jobs(): array {
        return [
            'process-news-ai.php'          => 'News processor (by hand)',
            'extension-news-processor.php' => 'News processor (browser extension)',
            'doc-types.php'                => 'Document types',
            'doc-types-admin.php'          => 'Document types',
            'fornits.php'                  => 'Fornits reading',
            'highlight-summaries.php'      => 'Plain summaries',
            'reader-accounts.php'          => 'Readers\' answers read into lines',
            'closure-reports.php'          => 'Closure scan',
            'facility-discovery.php'       => 'Facilities from News',
            'status-checks.php'            => 'Bill and lawsuit updates',
            'lawsuit-extraction-lib.php'   => 'Lawsuit extraction',
            'extract-wiki-from-prose.php'  => 'Wiki prose',
            'retitle-from-content.php'     => 'Titles from content',
            'review-inbox.php'             => 'Review inbox: fill with AI',
            'drive-docs.php'               => 'Drive Docs',
            'lib-record-enrich.php'        => 'Hourly enrich',
            'news-clippings.php'           => 'News clippings',
        ];
    }

    /** The jobs that call process-news-ai.php over HTTP name themselves in X-KOP-Job. */
    function kop_ai_usage_header_jobs(): array {
        return ['discovery' => 'News discovery (nightly)', 'enrich' => 'Hourly enrich'];
    }

    function kop_ai_usage_job(): string {
        if (!empty($GLOBALS['kop_ai_job'])) return (string) $GLOBALS['kop_ai_job'];
        $lib = ['ai-providers.php', 'lib-news-ai.php'];
        $names = kop_ai_usage_jobs();
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $f) {
            $b = basename((string) ($f['file'] ?? ''));
            if ($b === '' || in_array($b, $lib, true) || $b === 'ai-usage.php') continue;
            return $names[$b] ?? preg_replace('/\.php$/', '', $b);
        }
        return 'Other';
    }

    /** 'ok', 'limit' (out of quota or rate limited) or 'error', from an error message ('' = ok). */
    function kop_ai_usage_result(string $error): string {
        if ($error === '') return 'ok';
        foreach (['rate limit', 'quota', 'HTTP 429', 'RESOURCE_EXHAUSTED', 'Request too large'] as $n) {
            if (stripos($error, $n) !== false) return 'limit';
        }
        return 'error';
    }

    /** $result: 'held' for a request the budget kept back (never sent); else from $error. */
    function kop_ai_usage_record(string $provider, string $model, string $error = '', ?string $job = null, ?string $result = null): void {
        $result = $result ?? kop_ai_usage_result($error);
        $c = &$GLOBALS['kop_ai_budget_counts'];
        if ($result !== 'held' && is_array($c) && ($c['start'] ?? 0) === kop_ai_budget_day_start()) {
            $c[$provider] = ($c[$provider] ?? 0) + 1;
        }
        try {
            $dir = kop_ai_usage_dir();
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) return;
                @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
                @file_put_contents($dir . '/index.php', "<?php\n");
            }
            $file = $dir . '/' . gmdate('Y-m-d') . '.tsv';
            if (!file_exists($file)) {
                foreach (glob($dir . '/*.tsv') ?: [] as $old) {
                    if (basename($old, '.tsv') < gmdate('Y-m-d', time() - 14 * 86400)) @unlink($old);
                }
            }
            $clean = function ($s, $n) {
                $s = preg_replace('/key=[^&\s]+/', 'key=...', (string) $s);
                return mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, $n);
            };
            $line = implode("\t", [gmdate('Y-m-d\TH:i:s\Z'), $clean($provider, 20), $clean($model, 60),
                $clean($job ?? kop_ai_usage_job(), 60), $result, $clean($error, 200)]) . "\n";
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Counting must never break the call it counts.
        }
    }

    /**
     * The daily budget. Gemini's free quota resets at midnight Pacific, so the
     * budget's day does too (Groq's limits are per day as well; one day for
     * both keeps it simple). Background jobs (everything not in
     * kop_ai_budget_protected_jobs()) stop at 'background' requests a day per
     * provider, so the rest is left for the nightly news discovery and for
     * people using the tools by hand. Raise with KOP_AI_DAILY_GEMINI /
     * KOP_AI_DAILY_GROQ (the provider's daily requests) and
     * KOP_AI_BACKGROUND_SHARE (0 to 1) in api/config.local.php or .env.
     */
    function kop_ai_budget_day_start(?int $now = null): int {
        $d = new DateTime('@' . ($now ?? time()));
        $d->setTimezone(new DateTimeZone('America/Los_Angeles'));
        $d->setTime(0, 0, 0);
        return $d->getTimestamp();
    }

    function kop_ai_budget_limits(): array {
        $num = function ($name, $default) {
            $v = function_exists('kop_ai_secret') ? kop_ai_secret($name) : (string) getenv($name);
            return is_numeric($v) && $v > 0 ? (float) $v : $default;
        };
        // Gemini: ran out after about 500 requests on 2026-10-14. Groq: 1,000 requests and
        // 200,000 tokens a day per model; at a few thousand tokens a request the tokens go first.
        $share = min(1.0, $num('KOP_AI_BACKGROUND_SHARE', 0.7));
        $out = [];
        foreach (['gemini' => $num('KOP_AI_DAILY_GEMINI', 500), 'groq' => $num('KOP_AI_DAILY_GROQ', 400)] as $p => $daily) {
            $out[$p] = ['daily' => (int) $daily, 'background' => (int) floor($daily * $share)];
        }
        return $out;
    }

    /** Jobs the budget never holds back: the nightly discovery and what a person starts by hand. */
    function kop_ai_budget_protected_jobs(): array {
        return ['News discovery (nightly)', 'News processor (by hand)', 'News processor (browser extension)',
            'Review inbox: fill with AI', 'Check now (AI Usage)', 'News clippings'];
    }

    /** Requests sent to $provider since the budget's day began, every job (held-back ones are not sent). */
    function kop_ai_budget_used(string $provider): int {
        $c = &$GLOBALS['kop_ai_budget_counts'];
        $start = kop_ai_budget_day_start();
        if (!is_array($c) || ($c['start'] ?? 0) !== $start) {
            $c = ['start' => $start];
            foreach (kop_ai_usage_read($start) as $r) {
                if ($r['result'] !== 'held') $c[$r['provider']] = ($c[$r['provider']] ?? 0) + 1;
            }
        }
        return (int) ($c[$provider] ?? 0);
    }

    /**
     * Throws (a plain Exception that says "rate limit", so every job waits for its
     * next run instead of marking the item failed) when a background job would
     * go past its share of $provider today. Logs the held-back request.
     */
    function kop_ai_budget_check(string $provider, string $model = ''): void {
        $limits = kop_ai_budget_limits();
        if (!isset($limits[$provider])) return;
        $job = kop_ai_usage_job();
        if (in_array($job, kop_ai_budget_protected_jobs(), true)) return;
        $used = kop_ai_budget_used($provider);
        if ($used < $limits[$provider]['background']) return;
        $msg = ucfirst($provider) . ' rate limit (AI budget): background jobs have used ' . $used . ' of today\'s '
            . $limits[$provider]['background'] . '; the rest is kept for the news discovery. Tried again after midnight Pacific.';
        kop_ai_usage_record($provider, $model, $msg, $job, 'held');
        throw new Exception($msg);
    }

    /** Lines since $since (unix time), oldest first: [{t, provider, model, job, result, error}]. */
    function kop_ai_usage_read(int $since): array {
        $dir = kop_ai_usage_dir();
        $out = [];
        for ($d = $since - 86400; $d <= time() + 86400; $d += 86400) {
            $file = $dir . '/' . gmdate('Y-m-d', $d) . '.tsv';
            if (!is_readable($file)) continue;
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
                $p = explode("\t", $l);
                if (count($p) < 5) continue;
                $t = strtotime($p[0]);
                if ($t === false || $t < $since) continue;
                $out[] = ['t' => $t, 'provider' => $p[1], 'model' => $p[2], 'job' => $p[3], 'result' => $p[4], 'error' => $p[5] ?? ''];
            }
        }
        usort($out, function ($a, $b) { return $a['t'] <=> $b['t']; });
        return $out;
    }
}
