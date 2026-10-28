<?php
/**
 * CourtListener docket lookup (federal RECAP coverage), shared by the admin
 * auto-fill script (api/fetch-lawsuit-details.php) and the app's Lawsuit
 * Processor (inc/extension-lawsuit-processor.php).
 *
 * Federal only: CourtListener's state-court coverage is patchy, and parties /
 * docket data are largely federal.
 *
 * Tests set $GLOBALS['kop_cl_http_stub'] = function ($url) { return array|null; }
 * to answer the API without a network.
 */

/**
 * The CourtListener token: KOP_COURTLISTENER_TOKEN, else COURTLISTENER_API_KEY, as a
 * constant, in the environment, or in the .env file api/config.php loads. '' = none.
 */
function kop_cl_token() {
    $names = array('KOP_COURTLISTENER_TOKEN', 'COURTLISTENER_API_KEY');
    foreach ($names as $name) {
        $v = defined($name) ? (string) constant($name) : (string) getenv($name);
        if ($v === '' && isset($_ENV[$name])) $v = (string) $_ENV[$name];
        if ($v === '' && isset($_SERVER[$name])) $v = (string) $_SERVER[$name];
        $v = trim($v);
        if ($v !== '' && $v !== 'your_courtlistener_api_key_here') return $v;
    }
    $dir = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $dir = dirname($dir);
        $candidate = $dir . '/.env';
        if (!is_readable($candidate)) continue;
        $found = array();
        foreach (file($candidate, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
            list($k, $v) = explode('=', $line, 2);
            $k = trim($k);
            if (!in_array($k, $names, true)) continue;
            $found[$k] = trim(trim($v), '"\'');
        }
        foreach ($names as $name) {
            if (!empty($found[$name]) && $found[$name] !== 'your_courtlistener_api_key_here') return $found[$name];
        }
        break;
    }
    return '';
}

/**
 * Run an authenticated GET against the CourtListener v4 REST API and return the
 * decoded JSON body, or null on transport / HTTP failure (with the error
 * stashed in $err_out for the caller to surface).
 */
function kop_cl_get($url, $token, &$err_out = null, $timeout = 20) {
    if (!empty($GLOBALS['kop_cl_http_stub']) && is_callable($GLOBALS['kop_cl_http_stub'])) {
        $data = call_user_func($GLOBALS['kop_cl_http_stub'], $url);
        if (!is_array($data)) $err_out = 'CourtListener returned HTTP 404: (stub)';
        return is_array($data) ? $data : null;
    }
    $resp = wp_remote_get($url, array(
        'headers' => $token !== '' ? array('Authorization' => 'Token ' . $token) : array(),
        'timeout' => $timeout,
    ));
    if (is_wp_error($resp)) {
        $err_out = $resp->get_error_message();
        return null;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code !== 200) {
        $err_out = "CourtListener returned HTTP {$code}: " . substr($body, 0, 300);
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        $err_out = 'CourtListener returned non-JSON response.';
        return null;
    }
    return $data;
}

/**
 * Map a CourtListener party_types entry to one of our two buckets.
 * Plaintiff-side and defense-side terms vary across criminal, civil, appellate,
 * and bankruptcy dockets, so we normalize aggressively.
 */
function kop_cl_classify_party($party_type_name) {
    $t = strtolower($party_type_name);
    $plaintiff_terms = array('plaintiff', 'petitioner', 'appellant', 'movant', 'claimant', 'complainant', 'debtor');
    $defendant_terms = array('defendant', 'respondent', 'appellee', 'creditor');
    foreach ($plaintiff_terms as $term) {
        if (strpos($t, $term) !== false) return 'plaintiff';
    }
    foreach ($defendant_terms as $term) {
        if (strpos($t, $term) !== false) return 'defendant';
    }
    return 'other';
}

/**
 * Which docket a CourtListener address points at: a docket or filing page
 * (courtlistener.com/docket/<id>/...) gives ['id' => ...]; a RECAP PDF
 * (storage.courtlistener.com/recap/gov.uscourts.<court>.<pacer id>/...) gives
 * ['court' => ..., 'pacer_case_id' => ...]. Anything else: null.
 */
function kop_cl_docket_ref_from_url($url) {
    $url = (string) $url;
    if (preg_match('#^https?://(?:www\.)?courtlistener\.com/docket/(\d+)(?:/|$|\?)#i', $url, $m)) {
        return array('id' => $m[1]);
    }
    if (preg_match('#^https?://storage\.courtlistener\.com/recap/gov\.uscourts\.([a-z0-9]+)\.(\d+)/#i', $url, $m)) {
        return array('court' => strtolower($m[1]), 'pacer_case_id' => $m[2]);
    }
    return null;
}

/** The docket a kop_cl_docket_ref_from_url() ref names, or null ($err_out says why). */
function kop_cl_fetch_docket_ref(array $ref, $token, &$err_out = null, $timeout = 20) {
    $base = 'https://www.courtlistener.com/api/rest/v4/dockets/';
    if (!empty($ref['id'])) {
        return kop_cl_get($base . rawurlencode($ref['id']) . '/', $token, $err_out, $timeout);
    }
    $search = kop_cl_get($base . '?' . http_build_query(array(
        'court' => $ref['court'] ?? '', 'pacer_case_id' => $ref['pacer_case_id'] ?? '', 'page_size' => 5,
    )), $token, $err_out, $timeout);
    if ($search === null) return null;
    if (empty($search['results'][0])) {
        $err_out = 'No CourtListener docket for that filing.';
        return null;
    }
    return $search['results'][0];
}

/**
 * Find the docket for a case number:
 * ['ok' => true, 'docket' => ...] or ['ok' => false, 'error' => ..., 'diagnostic' => ...].
 */
function kop_cl_find_docket($case_number, $court_hint, $token, $timeout = 20) {
    // CourtListener accepts `docket_number` as a substring filter. We don't try
    // to translate the user's free-text court name into a CL court_id slug
    // because the mapping is brittle (90+ federal courts, several aliases each);
    // the docket_number alone is usually selective enough.
    $search_url = 'https://www.courtlistener.com/api/rest/v4/dockets/?' . http_build_query(array(
        'docket_number' => $case_number,
        'page_size'     => 20,
    ));
    $err = null;
    $search = kop_cl_get($search_url, $token, $err, $timeout);
    if ($search === null) {
        return array('ok' => false, 'error' => $err, 'diagnostic' => array('queried_url' => $search_url));
    }
    $results = $search['results'] ?? array();
    if (empty($results)) {
        return array(
            'ok'    => false,
            'error' => "No federal docket found matching case number '{$case_number}'. CourtListener mainly indexes federal RECAP dockets — district court bankruptcy or some sealed cases may be missing.",
            'diagnostic' => array('queried_url' => $search_url, 'result_count' => 0),
        );
    }
    // If the user provided a court hint, prefer a result whose `court_id` slug
    // or court name substring matches. Otherwise take the first result.
    $docket = $results[0];
    if ($court_hint !== '' && count($results) > 1) {
        $hint_lower = strtolower($court_hint);
        foreach ($results as $candidate) {
            $candidate_court_id = strtolower((string) ($candidate['court_id'] ?? ''));
            if ($candidate_court_id !== '' && strpos($hint_lower, $candidate_court_id) !== false) {
                $docket = $candidate;
                break;
            }
        }
    }
    return array('ok' => true, 'docket' => $docket);
}

/**
 * A docket as the lawsuit form's fields: the court's full name and the parties
 * looked up, the docket page as the source.
 */
function kop_cl_docket_fields(array $docket, $token, $case_number = '', $court_hint = '', $timeout = 20) {
    // CourtListener returns `court` as a URL ("…/courts/utd/") and `court_id`
    // as the slug. Hit /courts/<id>/ to get the full name, falling back to
    // whatever the user typed if the lookup fails.
    $err = null;
    $court_full_name = $court_hint;
    if (!empty($docket['court_id'])) {
        $court_url = 'https://www.courtlistener.com/api/rest/v4/courts/' . urlencode($docket['court_id']) . '/';
        $court_data = kop_cl_get($court_url, $token, $err, $timeout);
        if (is_array($court_data) && !empty($court_data['full_name'])) {
            $court_full_name = $court_data['full_name'];
        }
    }

    // The parties endpoint is paginated; for the typical case (< 50 parties)
    // a single page is enough. We don't follow `next` here to keep the
    // round-trip count down; very large MDL cases will be truncated.
    $plaintiffs = array();
    $defendants = array();
    if (!empty($docket['id'])) {
        $parties_url = 'https://www.courtlistener.com/api/rest/v4/parties/?' . http_build_query(array(
            'docket'    => $docket['id'],
            'page_size' => 100,
        ));
        $parties_data = kop_cl_get($parties_url, $token, $err, $timeout);
        if (is_array($parties_data) && !empty($parties_data['results'])) {
            foreach ($parties_data['results'] as $party) {
                $name = trim((string) ($party['name'] ?? ''));
                if ($name === '') continue;
                $bucket = 'other';
                foreach ($party['party_types'] ?? array() as $pt) {
                    $candidate = kop_cl_classify_party($pt['name'] ?? '');
                    if ($candidate !== 'other') { $bucket = $candidate; break; }
                }
                if ($bucket === 'plaintiff') {
                    $plaintiffs[] = $name;
                } elseif ($bucket === 'defendant') {
                    $defendants[] = $name;
                }
            }
            $plaintiffs = array_values(array_unique($plaintiffs));
            $defendants = array_values(array_unique($defendants));
        }
    }

    $absolute_url = '';
    if (!empty($docket['absolute_url'])) {
        $absolute_url = 'https://www.courtlistener.com' . $docket['absolute_url'];
    }

    return array(
        'case_name'          => $docket['case_name'] ?? ($docket['case_name_short'] ?? ''),
        'case_number'        => $docket['docket_number'] ?? $case_number,
        'court'              => $court_full_name,
        'jurisdiction'       => 'Federal',
        'filing_date'        => $docket['date_filed'] ?? '',
        'status'             => !empty($docket['date_terminated']) ? 'closed' : 'in_progress',
        'plaintiffs'         => $plaintiffs,
        'defendants'         => $defendants,
        'summary'            => trim((string) ($docket['cause'] ?? '')),  // "cause" is the IRS-style cause-of-action string CL exposes
        'source_urls'        => $absolute_url ? array($absolute_url) : array(),
        'document_urls'      => array(),
        'publication_status' => 'draft',
    );
}

/**
 * Look a case number up: ['success' => true, 'data' => fields] or
 * ['success' => false, 'error' => ..., 'diagnostic' => ...].
 */
function kop_cl_lookup_case($case_number, $court_hint, $token, $timeout = 20) {
    $found = kop_cl_find_docket($case_number, $court_hint, $token, $timeout);
    if (!$found['ok']) {
        return array('success' => false, 'error' => $found['error'], 'diagnostic' => $found['diagnostic']);
    }
    return array('success' => true, 'data' => kop_cl_docket_fields($found['docket'], $token, $case_number, $court_hint, $timeout));
}
