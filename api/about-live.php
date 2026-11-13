<?php
/**
 * About live check (public, read-only).
 *
 * What a facility or company page prints right now as its "About", for pages outside the site that compare it with
 * a draft (the About rewrite pilot preview on claude.ai, docs/kop-about-rewrite-pilot/). It is the same data as the
 * app's kop/v1/facility/<slug> and kop/v1/operator/<slug> (inc/mobile-api.php), cut to the About and the quotes, and
 * sent with an open CORS header from the theme's .htaccess: the site's root .htaccess pins REST answers to one origin.
 *
 * Request: GET about-live.php?page=facility/<slug>  (or operator/<slug>; up to 30 with pages=a,b,c)
 * Response: {fetched_at, pages: {"facility/<slug>": {ok, name, url, updated_label, writeup,
 *            paragraphs: [[{text, sources: [{url, cite}]}]], testimony: [{text, credit, url, note, date_label}]}}}
 * A page that is not found comes back as {ok: false, status}.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(array('error' => 'GET only'));
    exit;
}

$raw = isset($_GET['pages']) ? (string) $_GET['pages'] : (string) ($_GET['page'] ?? '');
$pages = array();
foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $p) {
    $p = trim($p, '/');
    if (preg_match('#^(facility|operator)/[A-Za-z0-9_-]{1,120}$#', $p)) $pages[$p] = true;
}
$pages = array_slice(array_keys($pages), 0, 30);
if (!$pages) {
    http_response_code(400);
    echo json_encode(array('error' => 'Give page=facility/<slug> or operator/<slug>'));
    exit;
}

$dir = __DIR__;
for ($i = 0; $i < 6 && !defined('ABSPATH'); $i++) {
    $dir = dirname($dir);
    if (file_exists($dir . '/wp-load.php')) require_once $dir . '/wp-load.php';
}
if (!defined('ABSPATH') || !function_exists('rest_do_request')) {
    http_response_code(503);
    echo json_encode(array('error' => 'WordPress did not load'));
    exit;
}

$out = array();
foreach ($pages as $p) {
    $res = rest_do_request(new WP_REST_Request('GET', '/kop/v1/' . $p));
    $data = $res->get_data();
    if ($res->is_error() || !is_array($data) || $res->get_status() !== 200) {
        $out[$p] = array('ok' => false, 'status' => $res->get_status());
        continue;
    }
    $about = is_array($data['about'] ?? null) ? $data['about'] : array();
    $paras = array();
    foreach ((array) ($about['paragraphs'] ?? array()) as $para) {
        $row = array();
        foreach ((array) $para as $s) {
            if (!is_array($s) || trim((string) ($s['text'] ?? '')) === '') continue;
            $src = array();
            foreach ((array) ($s['sources'] ?? array()) as $c) {
                if (!is_array($c)) continue;
                $src[] = array('url' => (string) ($c['url'] ?? ''), 'cite' => (string) ($c['cite'] ?? ''));
            }
            $row[] = array('text' => (string) $s['text'], 'sources' => $src);
        }
        if ($row) $paras[] = $row;
    }
    $quotes = array();
    foreach ((array) ($data['testimony'] ?? array()) as $t) {
        if (!is_array($t)) continue;
        $quotes[] = array(
            'text'       => (string) ($t['text'] ?? ''),
            'credit'     => (string) ($t['credit'] ?? ''),
            'url'        => (string) ($t['url'] ?? ''),
            'note'       => (string) ($t['note'] ?? ''),
            'date_label' => (string) ($t['date_label'] ?? ''),
        );
    }
    $out[$p] = array(
        'ok'            => true,
        'name'          => (string) ($data['name'] ?? ''),
        'url'           => (string) ($data['url'] ?? ''),
        'updated_label' => (string) ($data['updated_label'] ?? ''),
        'writeup'       => !empty($about['writeup']),
        'paragraphs'    => $paras,
        'testimony'     => $quotes,
    );
}

echo json_encode(array('fetched_at' => gmdate('c'), 'pages' => $out), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
