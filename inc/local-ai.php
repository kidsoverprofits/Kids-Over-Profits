<?php
/**
 * The routes the owner's PC worker ((kop-pipeline) scripts/local-ai-worker.py) talks to; the
 * fallback itself is api/local-ai.php. All need the header X-KOP-Research-Key (kop_msub_is_research()).
 *
 *   POST /wp-json/kop/v1/local-ai/heartbeat    {url, token, model} every minute, or {stop: true}
 *   GET  /wp-json/kop/v1/local-ai/jobs?limit=N  the waiting jobs, oldest first, with their prompts
 *   POST /wp-json/kop/v1/local-ai/jobs/<key>    {result, model}: Qwen's answer to one
 */

if (!defined('ABSPATH')) exit;

require_once get_stylesheet_directory() . '/api/local-ai.php';

add_action('rest_api_init', function () {
    $is_research = function (WP_REST_Request $req) {
        return function_exists('kop_msub_is_research') && kop_msub_is_research($req);
    };
    $no_store = function ($data) {
        $resp = new WP_REST_Response($data);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    };

    register_rest_route('kop/v1', '/local-ai/heartbeat', array(
        'methods'             => 'POST',
        'permission_callback' => $is_research,
        'callback'            => function (WP_REST_Request $req) use ($no_store) {
            if ($req->get_param('stop')) {
                kop_lai_clear_endpoint();
                return $no_store(array('success' => true, 'stopped' => true));
            }
            try {
                kop_lai_set_endpoint((string) $req->get_param('url'), (string) $req->get_param('token'), (string) $req->get_param('model'));
            } catch (Throwable $e) {
                return new WP_Error('kop_lai', $e->getMessage(), array('status' => 400));
            }
            $s = kop_lai_status();
            return $no_store(array('success' => true, 'waiting' => $s['waiting']));
        },
    ));

    register_rest_route('kop/v1', '/local-ai/jobs', array(
        'methods'             => 'GET',
        'permission_callback' => $is_research,
        'callback'            => function (WP_REST_Request $req) use ($no_store) {
            kop_lai_prune();
            $limit = max(1, min(20, (int) ($req->get_param('limit') ?: 5)));
            return $no_store(array('success' => true, 'jobs' => kop_lai_jobs('waiting', $limit, true)));
        },
    ));

    register_rest_route('kop/v1', '/local-ai/jobs/(?P<key>[a-f0-9]{40})', array(
        'methods'             => 'POST',
        'permission_callback' => $is_research,
        'callback'            => function (WP_REST_Request $req) use ($no_store) {
            $ok = kop_lai_answer((string) $req['key'], (string) $req->get_param('result'), (string) $req->get_param('model'));
            if (!$ok) return new WP_Error('kop_lai', 'No such waiting job, or an empty answer.', array('status' => 404));
            return $no_store(array('success' => true));
        },
    ));
});

/** The AI Usage screen's line about the owner's local Qwen. */
function kop_lai_status_html() {
    $s = kop_lai_status();
    $seen = $s['seen'] ? wp_date('M j, g:i a', $s['seen']) : '';
    $line = '<strong>Local Qwen:</strong> ';
    if ($s['live']) {
        $line .= 'on (' . esc_html($s['model']) . ', checked in ' . esc_html($seen) . ')';
    } else {
        $line .= 'off' . ($seen ? ' (last seen ' . esc_html($seen) . ')' : ' (never connected)');
    }
    $line .= '; ' . (int) $s['waiting'] . ' waiting for it, ' . (int) $s['answered'] . ' answered and not used yet';
    return $line;
}
