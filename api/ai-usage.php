<?php
/**
 * One line per AI request the site makes, so KOP Tools > AI Usage can say which
 * job spent the shared Groq and Gemini free quota (inc/ai-usage.php).
 *
 * Works without WordPress (api/process-news-ai.php runs without it): a tab
 * separated file per UTC day in wp-content/uploads/kop-ai-usage/, appended
 * under a lock, kept 14 days. Each line: time, provider, model, job, result
 * (ok / limit / error), the error's first words. Never a key or a prompt.
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
            'closure-reports.php'          => 'Closure scan',
            'facility-discovery.php'       => 'Facilities from News',
            'status-checks.php'            => 'Bill and lawsuit updates',
            'lawsuit-extraction-lib.php'   => 'Lawsuit extraction',
            'extract-wiki-from-prose.php'  => 'Wiki prose',
            'retitle-from-content.php'     => 'Titles from content',
            'review-inbox.php'             => 'Review inbox: fill with AI',
            'drive-docs.php'               => 'Drive Docs',
            'lib-record-enrich.php'        => 'Hourly enrich',
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

    function kop_ai_usage_record(string $provider, string $model, string $error = '', ?string $job = null): void {
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
                $clean($job ?? kop_ai_usage_job(), 60), kop_ai_usage_result($error), $clean($error, 200)]) . "\n";
            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Counting must never break the call it counts.
        }
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
