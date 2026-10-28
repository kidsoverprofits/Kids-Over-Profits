<?php
/**
 * The Lawsuit Processor for signed-in reviewers (the app's admin mode), the
 * lawsuit twin of inc/extension-news-processor.php:
 *
 *   GET  /wp-json/kop/v1/extension/lawsuit-form
 *        The case statuses the form offers and whether this account may publish at once.
 *   POST /wp-json/kop/v1/extension/lawsuit-process  {url, text, instructions}
 *        The lawsuit AI read (api/lawsuit-extraction-lib.php), Groq and Gemini in
 *        turn (kop_ai_generate_alternating), over the page or PDF text; returns {fields}.
 *        Federal dockets are then looked up on CourtListener (api/lib-courtlistener.php),
 *        from a CourtListener link or the case number the AI read: the docket's name,
 *        number, court and filing date replace the AI's, its parties come first, and
 *        {courtlistener: {url, by}} says it was used. A CourtListener link with too
 *        little text, or one the AI cannot read, still fills from the docket alone.
 *
 * Saving goes through /extension/submit with type=lawsuit and full=1
 * (inc/source-submissions.php, kop_ext_lawsuit_full_fields()); publish=1 files
 * it published, admins only.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The form's case statuses as {value,label}: the lists on KOP Tools > Dropdown Lists. */
function kop_ext_lawsuit_form_statuses() {
    require_once get_stylesheet_directory() . '/api/lawsuit-extraction-lib.php';
    $labels = function_exists('kop_lawsuit_statuses') ? kop_lawsuit_statuses('offered') : array();
    $out = array();
    foreach (kop_lawsuit_extract_statuses() as $value) {
        $label = $labels[$value] ?? ucfirst(str_replace('_', ' ', (string) $value));
        $out[] = array('value' => (string) $value, 'label' => (string) $label);
    }
    return $out;
}

function kop_ext_rest_lawsuit_form() {
    return array(
        'statuses'    => kop_ext_lawsuit_form_statuses(),
        'can_publish' => current_user_can('manage_options'),
        'user'        => wp_get_current_user()->display_name,
    );
}

/** The form's exact field set from a normalized extraction (kop_normalize_lawsuit_extraction()). */
function kop_ext_lawsuit_ai_fields(array $n) {
    $str = function ($k) use ($n) {
        $v = isset($n[$k]) && is_scalar($n[$k]) ? trim((string) $n[$k]) : '';
        return strcasecmp($v, 'null') === 0 ? '' : $v;
    };
    $list = function ($k) use ($n) {
        $v = isset($n[$k]) && is_array($n[$k]) ? $n[$k] : array();
        $out = array();
        foreach ($v as $item) {
            $item = is_scalar($item) ? trim((string) $item) : '';
            if ($item !== '' && strcasecmp($item, 'null') !== 0) $out[] = $item;
        }
        return array_values(array_unique($out));
    };
    $date = $str('filing_date');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
    return array(
        'case_name'               => $str('case_name'),
        'case_number'             => $str('case_number'),
        'court'                   => $str('court'),
        'jurisdiction'            => $str('jurisdiction'),
        'filing_date'             => $date,
        'status'                  => $str('status'),
        'outcome'                 => $str('outcome'),
        'settlement_amount'       => $str('settlement_amount'),
        'summary'                 => $str('summary'),
        'plaintiffs'              => $list('plaintiffs'),
        'defendants'              => $list('defendants'),
        'facilities_mentioned'    => $list('facilities_mentioned'),
        'staff_mentioned'         => $list('staff_mentioned'),
        'organizations_mentioned' => $list('organizations_mentioned'),
        'claims'                  => $list('claims'),
        'tags'                    => $list('tags'),
    );
}

/**
 * The text behind $url when it is a PDF (content-type or %PDF magic), else ''.
 * $is_pdf says whether it was one, so the caller does not fall back to the HTML reader.
 */
function kop_ext_lawsuit_fetch_pdf_text($url, &$is_pdf) {
    $is_pdf = false;
    $res = wp_remote_get($url, array(
        'timeout' => 25, 'redirection' => 4, 'limit_response_size' => 25 * 1024 * 1024,
        'user-agent' => 'Mozilla/5.0 (compatible; KidsOverProfits/1.0; +https://kidsoverprofits.org)',
    ));
    if (is_wp_error($res) || (int) wp_remote_retrieve_response_code($res) >= 400) return '';
    $body = (string) wp_remote_retrieve_body($res);
    $type = strtolower((string) wp_remote_retrieve_header($res, 'content-type'));
    if (strpos($type, 'application/pdf') === false && strncmp($body, '%PDF', 4) !== 0) return '';
    $is_pdf = true;
    $tmp = function_exists('wp_tempnam') ? wp_tempnam('kop-lawsuit.pdf') : tempnam(sys_get_temp_dir(), 'kop');
    if (!$tmp) return '';
    try {
        file_put_contents($tmp, $body);
        return trim((string) kop_extract_pdf_text($tmp, 0, 60000));
    } finally {
        @unlink($tmp);
    }
}

/**
 * Read $text with the lawsuit AI: at most 4 chunks, so a request stays under
 * about a minute. Returns the form fields, or a WP_Error (502).
 */
function kop_ext_lawsuit_read_text($text, $url, $instructions) {
    require_once get_stylesheet_directory() . '/api/lawsuit-extraction-lib.php';
    $prefix = '';
    if ($url !== '') $prefix .= 'SOURCE ADDRESS: ' . $url . "\n";
    if ($instructions !== '') $prefix .= 'REVIEWER NOTES (follow these): ' . $instructions . "\n";
    $parts = kop_lawsuit_chunk_text($text, 4);
    $total = count($parts['chunks']);
    $extractions = array();
    $last_error = '';
    foreach ($parts['chunks'] as $i => $chunk) {
        $call = kop_lawsuit_ai_call(($i === 0 && $prefix !== '' ? $prefix . "\n" : '') . $chunk, $i + 1, $total);
        if (!empty($call['ok'])) $extractions[] = $call['data'];
        else $last_error = (string) $call['error'];
    }
    if (!$extractions) {
        error_log('kop extension lawsuit AI failed: ' . $last_error);
        $msg = stripos($last_error, 'rate limit') !== false
            ? 'The AI services are out of calls for now. Try again in a few minutes, or fill the fields by hand.'
            : 'The AI could not read this case. Fill the fields by hand, or try again.';
        return new WP_Error('kop_ai_failed', $msg, array('status' => 502));
    }
    return kop_ext_lawsuit_ai_fields(kop_normalize_lawsuit_extraction(kop_merge_chunk_extractions($extractions)));
}

/** The form's fields, all empty. */
function kop_ext_lawsuit_blank_fields() {
    return kop_ext_lawsuit_ai_fields(array());
}

/**
 * The CourtListener docket for this case, as {fields, url, by}, or null: from the
 * address when it is a CourtListener docket or RECAP filing, else from a federal
 * case number. Short timeouts, since the AI read already takes most of a minute.
 */
function kop_ext_lawsuit_courtlistener($url, array $fields = array()) {
    require_once get_stylesheet_directory() . '/api/lib-courtlistener.php';
    $token = kop_cl_token();
    $err = null;
    $ref = $url !== '' ? kop_cl_docket_ref_from_url($url) : null;
    if ($ref) {
        $docket = kop_cl_fetch_docket_ref($ref, $token, $err, 10);
        $by = 'link';
    } else {
        $number = trim((string) ($fields['case_number'] ?? ''));
        if ($number === '' || strcasecmp((string) ($fields['jurisdiction'] ?? ''), 'Federal') !== 0) return null;
        $found = kop_cl_find_docket($number, (string) ($fields['court'] ?? ''), $token, 10);
        $docket = $found['ok'] ? $found['docket'] : null;
        $by = 'case_number';
    }
    if (!is_array($docket) || empty($docket['id'])) return null;
    $data = kop_cl_docket_fields($docket, $token, '', (string) ($fields['court'] ?? ''), 10);
    return array('fields' => $data, 'url' => (string) ($data['source_urls'][0] ?? ''), 'by' => $by);
}

/**
 * The AI's fields with the docket's: the court record's name, number, court,
 * jurisdiction and filing date win; status and summary fill only blanks; the
 * docket's parties come first, then any the AI read that it lacks.
 */
function kop_ext_lawsuit_merge_docket(array $fields, array $docket) {
    foreach (array('case_name', 'case_number', 'court', 'jurisdiction', 'filing_date') as $k) {
        $v = trim((string) ($docket[$k] ?? ''));
        if ($v !== '') $fields[$k] = $v;
    }
    foreach (array('status', 'summary') as $k) {
        $v = trim((string) ($docket[$k] ?? ''));
        if ($v !== '' && trim((string) ($fields[$k] ?? '')) === '') $fields[$k] = $v;
    }
    foreach (array('plaintiffs', 'defendants') as $k) {
        $out = array();
        $seen = array();
        foreach (array_merge((array) ($docket[$k] ?? array()), (array) ($fields[$k] ?? array())) as $name) {
            $name = trim((string) $name);
            $key = strtolower(preg_replace('/\s+/', ' ', $name));
            if ($name === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $name;
        }
        $fields[$k] = $out;
    }
    return $fields;
}

/** The process answer: the fields, with the docket merged in when there is one. */
function kop_ext_lawsuit_answer(array $fields, $cl) {
    if (!$cl) return array('fields' => $fields);
    return array(
        'fields'        => kop_ext_lawsuit_merge_docket($fields, $cl['fields']),
        'courtlistener' => array('url' => $cl['url'], 'by' => $cl['by']),
    );
}

function kop_ext_rest_lawsuit_process(WP_REST_Request $req) {
    $p = $req->get_json_params();
    if (!is_array($p)) $p = array();
    $url  = esc_url_raw(trim((string) ($p['url'] ?? '')));
    $text = trim(wp_strip_all_tags((string) ($p['text'] ?? '')));
    $instructions = sanitize_textarea_field((string) ($p['instructions'] ?? ''));

    $dir = get_stylesheet_directory() . '/api/';
    require_once $dir . 'ai-providers.php';
    require_once $dir . 'lawsuit-extraction-lib.php';

    if (mb_strlen($text) < 300 && $url !== '') {
        // Too little from the screen: read the address, as a PDF if it is one, else as a web page.
        try {
            $is_pdf = false;
            $fetched = kop_ext_lawsuit_fetch_pdf_text($url, $is_pdf);
            if (!$is_pdf) {
                require_once $dir . 'lib-article-fetch.php';
                $fetched = trim((string) fetchArticleContent($url));
            }
            if (mb_strlen($fetched) > mb_strlen($text)) $text = $fetched;
        } catch (Throwable $e) {
            // fall through to the length check
        }
    }
    // A CourtListener link names its docket outright: look it up before the AI, so
    // a page the reader could not get (or the AI could not read) still fills.
    require_once $dir . 'lib-courtlistener.php';
    $cl = kop_cl_docket_ref_from_url($url) ? kop_ext_lawsuit_courtlistener($url) : null;
    if (mb_strlen($text) < 300) {
        if ($cl) return kop_ext_lawsuit_answer(kop_ext_lawsuit_blank_fields(), $cl);
        return new WP_Error('kop_no_text', 'The page has too little text to read (' . mb_strlen($text) . ' characters). Open the filing itself, or paste its text into the notes box and try again.', array('status' => 422));
    }
    $fields = kop_ext_lawsuit_read_text($text, $url, $instructions);
    if (is_wp_error($fields)) {
        if ($cl) return kop_ext_lawsuit_answer(kop_ext_lawsuit_blank_fields(), $cl);
        return $fields;
    }
    if (!$cl) $cl = kop_ext_lawsuit_courtlistener('', $fields);
    return kop_ext_lawsuit_answer($fields, $cl);
}

add_action('rest_api_init', function () {
    $can = function () {
        return current_user_can('edit_posts');
    };
    register_rest_route('kop/v1', '/extension/lawsuit-form', array(
        'methods' => 'GET', 'permission_callback' => $can, 'callback' => 'kop_ext_rest_lawsuit_form',
    ));
    register_rest_route('kop/v1', '/extension/lawsuit-process', array(
        'methods' => 'POST', 'permission_callback' => $can, 'callback' => 'kop_ext_rest_lawsuit_process',
    ));
});
