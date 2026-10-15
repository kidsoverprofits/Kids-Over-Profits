<?php
/**
 * KOP Tools > AI Usage: which job spent the shared Groq and Gemini free quota,
 * read from the request log api/ai-usage.php writes (every AI request the site
 * makes, by job), and "Check now" (one tiny request to each provider).
 *
 * Both providers are free tiers every job shares, so a backfill in one job
 * leaves none for the others: on 2026-10-14 Document Types made 741 calls in
 * an hour and the nightly news discovery got nothing (docs/PLAN.md 3.16).
 * Tested by scripts/test-ai-usage.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once get_stylesheet_directory() . '/api/ai-usage.php';

add_filter('kop_admin_kit_pages', function ($pages) {
    $pages[] = 'kop-ai-usage';
    return $pages;
});

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) return;
    add_submenu_page(kop_tools_parent_slug(), 'AI Usage', 'AI Usage', 'manage_options', 'kop-ai-usage', 'kop_ai_usage_page');
}, 30);

/**
 * One tiny request to each provider through the site's own code:
 * [provider => [ok, message]]. Never shows a key.
 */
function kop_ai_usage_check() {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $GLOBALS['kop_ai_job'] = 'Check now (AI Usage)';
    $keys = kop_ai_api_keys();
    $prompt = 'Return JSON only: {"facility": "<the name>"} for this sentence: I was sent to Thayer Learning Center in 2004.';
    $out = array();
    foreach (array('gemini' => 'GEMINI_API_KEY', 'groq' => 'GROQ_API_KEY') as $p => $env) {
        if (empty($keys[$p])) {
            $out[$p] = array(false, $env . ' is not set: the site cannot see it in .env (check the line is in the same .env as the other keys, spelled exactly, with no spaces around =).');
            continue;
        }
        try {
            $reply = $p === 'gemini' ? kop_ai_gemini($prompt, array('maxTokens' => 200))
                : kop_ai_groq($prompt, array('maxTokens' => 200));
            $data = kop_ai_extract_json((string) $reply);
            $ok = is_array($data) && stripos((string) ($data['facility'] ?? ''), 'thayer') !== false;
            $model = $p === 'gemini' ? (getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash-lite') : (getenv('GROQ_MODEL') ?: 'openai/gpt-oss-120b');
            $out[$p] = array($ok, ($ok ? 'Works' : 'Answered, but not as expected: ' . mb_substr((string) $reply, 0, 160)) . ' (model ' . $model . ').');
        } catch (Throwable $e) {
            $msg = preg_replace('/key=[^&\s]+/', 'key=...', $e->getMessage());
            $out[$p] = array(false, (kop_ai_usage_result($msg) === 'limit' ? 'Out of quota or rate limited: ' : '') . mb_substr($msg, 0, 300));
        }
    }
    unset($GLOBALS['kop_ai_job']);
    return $out;
}

add_action('wp_ajax_kop_ai_usage_check', function () {
    if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Not allowed.'), 403);
    check_ajax_referer('kop_ai_usage', 'nonce');
    $lines = array();
    foreach (kop_ai_usage_check() as $p => list($ok, $msg)) {
        $lines[] = array('provider' => $p === 'gemini' ? 'Gemini' : 'Groq', 'ok' => $ok, 'message' => $msg);
    }
    wp_send_json_success(array('lines' => $lines));
});

/**
 * The log summed for the screen: per job {job, groq, gemini, limit, error, last},
 * busiest first; per provider {calls, limit, last, last_result, limit_since};
 * hours [unix hour => {n, jobs: [job => n]}] for the last 48 hours.
 */
function kop_ai_usage_summary(array $rows, $now = null) {
    $now = $now ?? time();
    $jobs = array();
    $prov = array();
    $hours = array();
    $first_hour = (int) (floor($now / 3600) * 3600) - 47 * 3600;
    for ($h = $first_hour; $h <= $now; $h += 3600) $hours[$h] = array('n' => 0, 'jobs' => array());
    foreach ($rows as $r) {
        $j = $r['job'];
        if (!isset($jobs[$j])) $jobs[$j] = array('job' => $j, 'groq' => 0, 'gemini' => 0, 'other' => 0, 'limit' => 0, 'error' => 0, 'last' => 0);
        $p = in_array($r['provider'], array('groq', 'gemini'), true) ? $r['provider'] : 'other';
        $jobs[$j][$p]++;
        if ($r['result'] === 'limit') $jobs[$j]['limit']++;
        if ($r['result'] === 'error') $jobs[$j]['error']++;
        $jobs[$j]['last'] = max($jobs[$j]['last'], $r['t']);
        if (!isset($prov[$p])) $prov[$p] = array('calls' => 0, 'limit' => 0, 'last' => 0, 'last_result' => '', 'last_error' => '', 'limit_since' => 0);
        $prov[$p]['calls']++;
        if ($r['result'] === 'limit') {
            $prov[$p]['limit']++;
            if ($prov[$p]['last_result'] !== 'limit') $prov[$p]['limit_since'] = $r['t'];
        } else {
            $prov[$p]['limit_since'] = 0;
        }
        $prov[$p]['last'] = $r['t'];
        $prov[$p]['last_result'] = $r['result'];
        $prov[$p]['last_error'] = $r['error'];
        $h = (int) (floor($r['t'] / 3600) * 3600);
        if (isset($hours[$h])) {
            $hours[$h]['n']++;
            $hours[$h]['jobs'][$j] = ($hours[$h]['jobs'][$j] ?? 0) + 1;
        }
    }
    uasort($jobs, function ($a, $b) {
        return ($b['groq'] + $b['gemini'] + $b['other']) <=> ($a['groq'] + $a['gemini'] + $a['other']);
    });
    foreach ($hours as &$h) arsort($h['jobs']);
    unset($h);
    return array('jobs' => array_values($jobs), 'providers' => $prov, 'hours' => $hours);
}

function kop_ai_usage_page() {
    if (!current_user_can('manage_options')) wp_die('Not authorized', 'Access Denied', array('response' => 403));
    $period = sanitize_key($_GET['period'] ?? '24h');
    $periods = array('24h' => array('Last 24 hours', DAY_IN_SECONDS), '7d' => array('Last 7 days', 7 * DAY_IN_SECONDS));
    if (!isset($periods[$period])) $period = '24h';
    $now = time();
    $rows = kop_ai_usage_read($now - max($periods[$period][1], 2 * DAY_IN_SECONDS));
    $hours_sum = kop_ai_usage_summary($rows, $now);
    $in_period = array_values(array_filter($rows, function ($r) use ($now, $periods, $period) { return $r['t'] >= $now - $periods[$period][1]; }));
    $sum = kop_ai_usage_summary($in_period, $now);
    $when = function ($t) { return $t ? wp_date('M j, g:i a', $t) : ''; };
    $base = admin_url('admin.php?page=kop-ai-usage');

    $status = array();
    foreach (array('groq' => 'Groq', 'gemini' => 'Gemini') as $p => $label) {
        $s = $hours_sum['providers'][$p] ?? null;
        if (!$s) {
            $status[] = '<strong>' . $label . ':</strong> no requests in the last 48 hours';
        } elseif ($s['last_result'] === 'limit') {
            $status[] = '<strong>' . $label . ': out of quota</strong> since ' . esc_html($when($s['limit_since']));
        } else {
            $status[] = '<strong>' . $label . ':</strong> last request ' . esc_html($when($s['last'])) . ($s['last_result'] === 'ok' ? ', worked' : ', failed');
        }
    }

    echo '<div class="wrap kop-ak kop-aiu">';
    echo kop_ak_header('AI Usage', array(
        'lede' => 'Every request the site sends to Groq and Gemini, by the job that sent it. Both are free tiers that every job shares: when one job uses up a day\'s calls, the others get none, the nightly news discovery included.',
        'steps' => array(
            'The status line says whether each provider is answering now. <em>Check now</em> sends each one a tiny request and says what came back.',
            'The table shows which job made the requests in the period. A job far above the rest is the one spending the quota.',
            'The bars are the last 48 hours, one per hour; hover a bar for the jobs in it.',
        ),
        'help' => '<p>Gemini\'s day starts at midnight Pacific (2:00 am Central), so the 1:30 am discovery run is in the last half hour of Gemini\'s day. Groq limits requests and tokens per minute and per day. The log keeps 14 days.</p>',
    ));
    echo kop_ak_status(implode(' &nbsp;&middot;&nbsp; ', $status) . '<div class="kop-aiu-check-out" role="status" aria-live="polite"></div>',
        kop_ak_button('Check now', 'secondary', 'data-nonce="' . esc_attr(wp_create_nonce('kop_ai_usage')) . '"', 'kop-aiu-check'));

    // The last 48 hours, one bar an hour.
    $max = max(1, max(array_map(function ($h) { return $h['n']; }, $hours_sum['hours'])));
    echo '<h2>Last 48 hours</h2><div class="kop-aiu-bars" role="img" aria-label="Requests per hour, last 48 hours">';
    foreach ($hours_sum['hours'] as $t => $h) {
        $tip = wp_date('M j, g a', $t) . ': ' . $h['n'] . ' request' . ($h['n'] === 1 ? '' : 's');
        foreach (array_slice($h['jobs'], 0, 4, true) as $j => $n) $tip .= "\n" . $j . ': ' . $n;
        echo '<span class="kop-aiu-bar" title="' . esc_attr($tip) . '"><span style="height:' . round(100 * $h['n'] / $max, 1) . '%"></span></span>';
    }
    echo '</div><div class="kop-aiu-axis"><span>' . esc_html(wp_date('M j, g a', array_key_first($hours_sum['hours']))) . '</span><span>now</span></div>';
    $busy = array_filter($hours_sum['hours'], function ($h) { return $h['n'] > 0; });
    uasort($busy, function ($a, $b) { return $b['n'] <=> $a['n']; });
    if ($busy) {
        echo '<p class="kop-aiu-busy">Busiest hours: ';
        $parts = array();
        foreach (array_slice($busy, 0, 3, true) as $t => $h) {
            $parts[] = esc_html(wp_date('M j, g a', $t)) . ' (' . (int) $h['n'] . ', mostly ' . esc_html((string) array_key_first($h['jobs'])) . ')';
        }
        echo implode('; ', $parts) . '.</p>';
    }

    // Requests by job in the period.
    echo kop_ak_tabs(array_map(function ($p) { return $p[0]; }, $periods), $period, function ($k) use ($base) { return add_query_arg('period', $k, $base); });
    if (!$sum['jobs']) {
        echo kop_ak_empty('No AI requests in this period.');
    } else {
        echo '<table class="widefat striped kop-aiu-table"><thead><tr><th scope="col">Job</th><th scope="col" class="num">Groq</th><th scope="col" class="num">Gemini</th>'
            . '<th scope="col" class="num">Out of quota</th><th scope="col" class="num">Failed</th><th scope="col">Last request</th></tr></thead><tbody>';
        foreach ($sum['jobs'] as $j) {
            echo '<tr><td>' . esc_html($j['job']) . '</td><td class="num">' . (int) $j['groq'] . '</td><td class="num">' . (int) $j['gemini'] . '</td>'
                . '<td class="num">' . ($j['limit'] ? (int) $j['limit'] : '') . '</td><td class="num">' . ($j['error'] ? (int) $j['error'] : '') . '</td>'
                . '<td>' . esc_html($when($j['last'])) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // The last failures, words as the provider gave them.
    $fails = array_reverse(array_values(array_filter($in_period, function ($r) { return $r['result'] !== 'ok'; })));
    if ($fails) {
        $list = '<ul class="kop-aiu-fails">';
        foreach (array_slice($fails, 0, 40) as $r) {
            $list .= '<li><span class="kop-aiu-when">' . esc_html($when($r['t'])) . '</span> ' . esc_html(ucfirst($r['provider'])) . ', ' . esc_html($r['job'])
                . ': ' . esc_html($r['error']) . '</li>';
        }
        $list .= '</ul>';
        echo kop_ak_fold('Last failed requests (' . count($fails) . ')', $list);
    }
    ?>
    <style>
        .kop-aiu-bars { display:flex; align-items:flex-end; gap:2px; height:120px; max-width:1100px; padding:6px; background:var(--kop-white, #fff); border:1px solid #c3c4c7; }
        .kop-aiu-bar { flex:1 1 0; min-width:0; height:100%; display:flex; align-items:flex-end; }
        .kop-aiu-bar > span { display:block; width:100%; min-height:1px; background:var(--kop-teal-fill, #1d7480); }
        .kop-aiu-axis { display:flex; justify-content:space-between; max-width:1100px; color:var(--kop-text-muted, #4A5568); font-size:12px; margin:2px 0 8px; }
        .kop-aiu-busy { color:var(--kop-text-muted, #4A5568); max-width:1100px; }
        .kop-aiu-table { max-width:1100px; margin-top:8px; }
        .kop-aiu-table .num { text-align:right; }
        .kop-aiu-fails { max-width:1100px; }
        .kop-aiu-when { color:var(--kop-text-muted, #4A5568); }
        .kop-aiu-check-out:empty { display:none; }
        .kop-aiu-check-out { margin-top:6px; }
        .kop-aiu-check-out .is-bad { font-weight:600; }
    </style>
    <script>
    (function () {
        var btn = document.querySelector('.kop-aiu-check'), out = document.querySelector('.kop-aiu-check-out');
        if (!btn || !out) return;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            out.textContent = 'Asking each provider...';
            var body = new FormData();
            body.append('action', 'kop_ai_usage_check');
            body.append('nonce', btn.getAttribute('data-nonce'));
            fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    out.textContent = '';
                    if (!d || !d.success) { out.textContent = (d && d.data && d.data.message) || 'The check failed.'; return; }
                    d.data.lines.forEach(function (l) {
                        var div = document.createElement('div');
                        if (!l.ok) div.className = 'is-bad';
                        div.textContent = l.provider + ': ' + (l.ok ? '' : 'NOT WORKING. ') + l.message;
                        out.appendChild(div);
                    });
                })
                .catch(function () { out.textContent = 'The check failed.'; })
                .then(function () { btn.disabled = false; });
        });
    })();
    </script>
    <?php
    echo '</div>';
}
