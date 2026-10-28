<?php
/**
 * Researcher kit intake: where kop-pipeline's scripts/load-researcher-kit.py sends the records of files outside
 * researchers return (the shapes on /researcher-kit/, uploads at inc/researcher-uploads.php).
 *
 *   POST /wp-json/kop/v1/kit/submit      one record of a kit file, header X-KOP-Research-Key (the same key as
 *                                        kop/v1/mobile/submit, kop_msub_is_research()); without it: 403
 *   body: {kind, record, wrapper: {kit_version, submitted_by, collected_on, method}, dry_run?}
 *
 * Nothing is written to a live record. Where each kind goes:
 *   facilities, companies  suggested_edits 'pending' (Submissions Review, the review inbox's facility cards); a
 *                          facility that already exists (kop_facility_resolve_identity(): name + state + city) is
 *                          sent as an add-on to it (its id kept under facilityId, never facility_id), a new one as
 *                          a new record. A company's facilities are named by name + city + state and resolved the
 *                          same way; the answer is written into the reason line.
 *   lawsuits, bills, news  lawsuits / legislation 'pending', news_submissions 'submitted': the queues the
 *                          extension and the app fill, with every field the kit has.
 * Duplicates are 409, never a second row: a link or case/bill number the queues hold, or a kit key
 * (sha1 of kind + name + city + state, in the reason line) already pending in suggested_edits.
 * Inspections, source links and people are not handled here (the loader uses api/inspections-write.php,
 * kop/v1/mobile/submit, and a not-sent file for people).
 *
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_KIT_VERSION = 1;

function kop_kit_kinds() {
    return array('facilities', 'companies', 'lawsuits', 'bills', 'news');
}

/** The key of one record, the same in the loader's dry run: kind + name + city + state, folded. */
function kop_kit_key($kind, $name, $city = '', $state = '') {
    $fold = function ($s) { return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $s))); };
    return substr(sha1($kind . '|' . $fold($name) . '|' . $fold($city) . '|' . $fold($state)), 0, 20);
}

function kop_kit_str($v, $max = 2000) {
    return is_scalar($v) ? kop_ext_text($v, $max) : '';
}

function kop_kit_list($v, $max = 100) {
    if (!is_array($v)) return array();
    $out = array();
    foreach ($v as $item) {
        $item = kop_kit_str($item, 500);
        if ($item !== '') $out[] = $item;
    }
    return array_slice(array_values(array_unique($out)), 0, $max);
}

function kop_kit_json($v) {
    return wp_json_encode(array_values($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** The id of the facility a name + city + state is, 0 if none. */
function kop_kit_resolve($name, $city, $state) {
    if (!function_exists('kop_facility_resolve_identity')) return 0;
    try {
        return (int) kop_facility_resolve_identity($name, $state !== '' ? $state : null, $city !== '' ? $city : null);
    } catch (Throwable $e) {
        return 0;
    }
}

function kop_kit_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) throw new RuntimeException('The records database is not reachable.');
    return $pdo;
}

function kop_kit_duplicate_response($what) {
    return new WP_REST_Response(array('code' => 'kop_duplicate', 'message' => 'Already held: ' . $what), 409);
}

/** Who sent it, as the records tables write it. */
function kop_kit_submitter(array $wrapper) {
    $who = kop_kit_str($wrapper['submitted_by'] ?? '', 120);
    return mb_substr(($who !== '' ? $who . ' ' : '') . '(researcher kit)', 0, 255);
}

function kop_kit_note(array $wrapper) {
    $bits = array();
    foreach (array('collected_on' => 'collected', 'method' => 'method') as $k => $label) {
        $v = kop_kit_str($wrapper[$k] ?? '', 400);
        if ($v !== '') $bits[] = $label . ': ' . $v;
    }
    return implode('; ', $bits);
}

/* ---- facilities and companies -> suggested_edits ------------------------------ */

function kop_kit_pending_key_exists(PDO $pdo, $key) {
    $stmt = $pdo->prepare("SELECT id FROM suggested_edits WHERE status = 'pending' AND reason LIKE ? LIMIT 1");
    $stmt->execute(array('%Kit key: ' . $key . '%'));
    return (bool) $stmt->fetchColumn();
}

function kop_kit_insert_suggestion(PDO $pdo, $master, array $data, $reason) {
    $master_id = substr(preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $master), 0, 255);
    $stmt = $pdo->prepare(
        "INSERT INTO suggested_edits (master_id, edited_json_data, reason, submitter_ip, status, created_at)
         VALUES (?, ?, ?, ?, 'pending', NOW())"
    );
    $stmt->execute(array($master_id, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), mb_substr($reason, 0, 6000), 'researcher-kit'));
    $id = (int) $pdo->lastInsertId();
    if (function_exists('kop_notify_admins')) {
        kop_notify_admins('suggested_edit', $master_id, '', array('Reason' => $reason, 'Record' => $master_id, 'Reference' => '#' . $id));
    }
    return $id;
}

function kop_kit_facility(PDO $pdo, array $rec, array $wrapper, $dry) {
    $ident = is_array($rec['identification'] ?? null) ? $rec['identification'] : array();
    $loc   = is_array($rec['location'] ?? null) ? $rec['location'] : array();
    $name  = kop_kit_str($ident['name'] ?? ($ident['currentName'] ?? ''), 255);
    if ($name === '') return new WP_Error('kop_no_name', 'identification.name is required.', array('status' => 400));
    $city  = kop_kit_str($loc['city'] ?? '', 120);
    $state = strtoupper(kop_kit_str($loc['state'] ?? '', 40));
    $key   = kop_kit_key('facility', $name, $city, $state);
    if (kop_kit_pending_key_exists($pdo, $key)) return kop_kit_duplicate_response('this facility is already waiting in review');
    $existing = kop_kit_resolve($name, $city, $state);
    $kind = $existing ? 'Add-on to facility #' . $existing : 'New facility';
    if ($dry) return new WP_REST_Response(array('ok' => true, 'dry_run' => true, 'would' => $kind), 200);

    if ($existing) $rec['facilityId'] = $existing;
    $data = array('projectName' => $name, 'name' => $name, 'facilities' => array($rec), 'source' => 'researcher kit');
    $op = kop_kit_str($ident['currentOperator'] ?? '', 255);
    if ($op !== '') $data['operator'] = array('name' => $op);
    if (!function_exists('kop_suggestion_normalize_facilities')) require_once get_stylesheet_directory() . '/api/lib-suggested-edits.php';
    $data = kop_suggestion_normalize_facilities($data, 'companies');
    if ($existing && isset($data['facilities'][0]) && is_array($data['facilities'][0])) $data['facilities'][0]['facilityId'] = $existing;

    $reason = $kind . ', from a researcher kit file.' . "\n\n" . kop_kit_note($wrapper)
        . "\n\nFrom: " . kop_kit_submitter($wrapper) . "\nKit key: " . $key;
    $id = kop_kit_insert_suggestion($pdo, $name, $data, $reason);
    return new WP_REST_Response(array('ok' => true, 'queue' => 'facility review', 'id' => $id, 'as' => $kind), 201);
}

function kop_kit_company(PDO $pdo, array $rec, array $wrapper, $dry) {
    $op = is_array($rec['operator'] ?? null) ? $rec['operator'] : array();
    $name = kop_kit_str($rec['name'] ?? ($op['currentName'] ?? ''), 255);
    if ($name === '') return new WP_Error('kop_no_name', 'The company name is required.', array('status' => 400));
    $key = kop_kit_key('company', $name);
    if (kop_kit_pending_key_exists($pdo, $key)) return kop_kit_duplicate_response('this company is already waiting in review');

    $resolved = array();
    $lines = array();
    $facilities = array();
    foreach ((is_array($rec['facilities'] ?? null) ? $rec['facilities'] : array()) as $f) {
        if (!is_array($f)) continue;
        $fname = kop_kit_str($f['name'] ?? '', 255);
        if ($fname === '') continue;
        $fcity = kop_kit_str($f['city'] ?? '', 120);
        $fstate = strtoupper(kop_kit_str($f['state'] ?? '', 40));
        $id = kop_kit_resolve($fname, $fcity, $fstate);
        $lines[] = '- ' . $fname . ($fcity !== '' || $fstate !== '' ? ' (' . trim($fcity . ', ' . $fstate, ', ') . ')' : '')
            . ': ' . ($id ? 'existing facility #' . $id : 'no match, new record')
            . (kop_kit_str($f['relationship'] ?? '') !== '' ? '; ' . kop_kit_str($f['relationship']) : '')
            . (kop_kit_str($f['years'] ?? '') !== '' ? '; ' . kop_kit_str($f['years']) : '');
        $facility = array(
            'identification' => array('name' => $fname, 'currentOperator' => $name),
            'location' => array('city' => $fcity, 'state' => $fstate),
            'operatingPeriod' => array('notes' => array_filter(array(kop_kit_str($f['years'] ?? '', 120)))),
        );
        if ($id) $facility['facilityId'] = $id;
        $facilities[] = $facility;
        $resolved[] = $id;
    }
    if ($dry) {
        return new WP_REST_Response(array('ok' => true, 'dry_run' => true, 'would' => 'Company with ' . count($facilities) . ' facilities, '
            . count(array_filter($resolved)) . ' already on file'), 200);
    }

    $operator = $op;
    $operator['name'] = $name;
    $data = array('projectName' => $name, 'name' => $name, 'operator' => $operator, 'facilities' => $facilities, 'source' => 'researcher kit');
    if (!function_exists('kop_suggestion_normalize_facilities')) require_once get_stylesheet_directory() . '/api/lib-suggested-edits.php';
    $data = kop_suggestion_normalize_facilities($data, 'companies');
    foreach ($resolved as $i => $id) {
        if ($id && isset($data['facilities'][$i]) && is_array($data['facilities'][$i])) $data['facilities'][$i]['facilityId'] = $id;
    }
    $reason = 'New company, from a researcher kit file.' . "\n\n" . kop_kit_note($wrapper)
        . ($lines ? "\n\nFacilities:\n" . implode("\n", $lines) : '')
        . "\n\nFrom: " . kop_kit_submitter($wrapper) . "\nKit key: " . $key;
    $id = kop_kit_insert_suggestion($pdo, $name, $data, $reason);
    return new WP_REST_Response(array('ok' => true, 'queue' => 'facility review', 'id' => $id), 201);
}

/* ---- lawsuits, bills, news -> their pending queues ------------------------------- */

function kop_kit_pick($value, array $allowed, $fallback) {
    $value = sanitize_key((string) $value);
    return in_array($value, $allowed, true) ? $value : $fallback;
}

function kop_kit_statuses($fn, $fallback) {
    if (!function_exists($fn)) return array($fallback);
    $keys = array_map('strval', array_keys((array) $fn('all')));
    return $keys ?: array($fallback);
}

function kop_kit_lawsuit(PDO $pdo, array $rec, array $wrapper, $dry) {
    $name = kop_kit_str($rec['case_name'] ?? '', 500);
    $urls = kop_kit_list($rec['source_urls'] ?? array());
    $docs = kop_kit_list($rec['document_urls'] ?? array());
    if ($name === '') return new WP_Error('kop_no_name', 'case_name is required.', array('status' => 400));
    if (!$urls && !$docs) return new WP_Error('kop_no_url', 'A lawsuit needs at least one source link.', array('status' => 400));
    kop_ext_load_record_libs();
    $dupes = kop_check_url_duplicates($pdo, 'lawsuit', array('source_urls' => $urls, 'document_urls' => array_merge($urls, $docs)));
    if ($dupes) return kop_kit_duplicate_response('a lawsuit with this link');
    if ($dry) return new WP_REST_Response(array('ok' => true, 'dry_run' => true, 'would' => 'Lawsuit draft'), 200);

    $submitter = kop_kit_submitter($wrapper);
    $note = kop_kit_note($wrapper);
    $fields = array(
        'case_name' => $name,
        'case_number' => kop_kit_str($rec['case_number'] ?? '', 255),
        'court' => kop_kit_str($rec['court'] ?? '', 255),
        'jurisdiction' => kop_ext_jurisdiction_name($rec['jurisdiction'] ?? ''),
        'filing_date' => kop_ext_date($rec['filing_date'] ?? ''),
        'status' => kop_kit_pick($rec['status'] ?? '', kop_kit_statuses('kop_lawsuit_statuses', 'unknown'), 'unknown'),
        'plaintiffs' => kop_kit_json(kop_kit_list($rec['plaintiffs'] ?? array())),
        'defendants' => kop_kit_json(kop_kit_list($rec['defendants'] ?? array())),
        'facilities_mentioned' => kop_kit_json(kop_kit_list($rec['facilities_mentioned'] ?? array())),
        'staff_mentioned' => kop_kit_json(kop_kit_list($rec['staff_mentioned'] ?? array())),
        'organizations_mentioned' => kop_kit_json(kop_kit_list($rec['organizations_mentioned'] ?? array())),
        'claims' => kop_kit_json(kop_kit_list($rec['claims'] ?? array())),
        'outcome' => kop_kit_str($rec['outcome'] ?? '', 2000),
        'settlement_amount' => kop_kit_str($rec['settlement_amount'] ?? '', 255),
        'summary' => kop_ext_textarea($rec['summary'] ?? '', 4000),
        'source_urls' => kop_kit_json($urls),
        'document_urls' => kop_kit_json($docs),
        'tags' => kop_kit_json(kop_kit_list($rec['tags'] ?? array(), 20)),
        'publication_status' => 'pending',
        'submitted_by' => $submitter,
        'reviewer_notes' => $note !== '' ? '[submitter] ' . $note : '',
    );
    $cols = array_keys($fields);
    $pdo->prepare('INSERT INTO lawsuits (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($fields));
    $id = (int) $pdo->lastInsertId();
    try {
        kop_sync_lawsuit_facility_links($pdo, $id, $fields['facilities_mentioned'], 'researcher-kit');
    } catch (Throwable $e) {
        error_log('kop kit: lawsuit facility links failed: ' . $e->getMessage());
    }
    if (function_exists('kop_notify_admins')) {
        kop_notify_admins('lawsuit', $name, '', array('Case number' => $fields['case_number'], 'Submitted by' => $submitter, 'Reference' => '#' . $id));
    }
    return new WP_REST_Response(array('ok' => true, 'queue' => 'lawsuit review', 'id' => $id), 201);
}

function kop_kit_bill(PDO $pdo, array $rec, array $wrapper, $dry) {
    $title = kop_kit_str($rec['bill_title'] ?? '', 500);
    $official = esc_url_raw(kop_kit_str($rec['official_url'] ?? '', 500), array('http', 'https'));
    $fulltext = esc_url_raw(kop_kit_str($rec['full_text_url'] ?? '', 500), array('http', 'https'));
    if ($title === '') return new WP_Error('kop_no_name', 'bill_title is required.', array('status' => 400));
    if ($official === '' && $fulltext === '') return new WP_Error('kop_no_url', 'A bill needs an official or full-text link.', array('status' => 400));
    kop_ext_load_record_libs();
    $dupes = kop_ext_legislation_duplicates($pdo, $fulltext !== '' ? $fulltext : $official, $rec['bill_number'] ?? '', $rec['jurisdiction'] ?? '');
    if ($dupes) return kop_kit_duplicate_response('this bill');
    if ($dry) return new WP_REST_Response(array('ok' => true, 'dry_run' => true, 'would' => 'Bill draft'), 200);

    $submitter = kop_kit_submitter($wrapper);
    $note = kop_kit_note($wrapper);
    $fields = array(
        'bill_number' => kop_kit_str($rec['bill_number'] ?? '', 100),
        'bill_title' => $title,
        'jurisdiction' => kop_ext_jurisdiction_name($rec['jurisdiction'] ?? ''),
        'chamber' => kop_kit_pick($rec['chamber'] ?? '', array('house', 'senate', 'assembly', 'joint', 'federal_house', 'federal_senate', 'other', 'unknown'), 'unknown'),
        'session_year' => kop_kit_str($rec['session_year'] ?? '', 50),
        'bill_type' => kop_kit_str($rec['bill_type'] ?? '', 100),
        'sponsors' => kop_kit_json(kop_kit_list($rec['sponsors'] ?? array())),
        'status' => kop_kit_pick($rec['status'] ?? '', kop_kit_statuses('kop_bill_statuses', 'unknown'), 'unknown'),
        'introduced_date' => kop_ext_date($rec['introduced_date'] ?? ''),
        'last_action_date' => kop_ext_date($rec['last_action_date'] ?? ''),
        'last_action_text' => kop_kit_str($rec['last_action_text'] ?? '', 1000),
        'subject_tags' => kop_kit_json(kop_kit_list($rec['subject_tags'] ?? array(), 20)),
        'summary' => kop_ext_textarea($rec['summary'] ?? '', 4000),
        'full_text_url' => $fulltext,
        'official_url' => $official,
        'position' => 'unknown',
        'facilities_affected' => kop_kit_json(kop_kit_list($rec['facilities_affected'] ?? array())),
        'tags' => kop_kit_json(kop_kit_list($rec['tags'] ?? array(), 20)),
        'publication_status' => 'pending',
        'submitted_by' => $submitter,
        'reviewer_notes' => $note !== '' ? '[submitter] ' . $note : '',
    );
    $cols = array_keys($fields);
    $pdo->prepare('INSERT INTO legislation (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($fields));
    $id = (int) $pdo->lastInsertId();
    if (function_exists('kop_notify_admins')) {
        kop_notify_admins('legislation', $title, '', array('Bill number' => $fields['bill_number'], 'Submitted by' => $submitter, 'Reference' => '#' . $id));
    }
    return new WP_REST_Response(array('ok' => true, 'queue' => 'legislation review', 'id' => $id), 201);
}

function kop_kit_article(PDO $pdo, array $rec, array $wrapper, $dry) {
    $url = esc_url_raw(kop_kit_str($rec['article_url'] ?? '', 1000), array('http', 'https'));
    $title = kop_kit_str($rec['article_title'] ?? '', 500);
    if ($url === '') return new WP_Error('kop_bad_url', 'article_url must be an http or https link.', array('status' => 400));
    if ($title === '') $title = $url;
    kop_ext_load_record_libs();
    $archive = esc_url_raw(kop_kit_str($rec['archive_url'] ?? '', 1000), array('http', 'https'));
    $dupes = kop_ext_find_duplicates($pdo, array('url' => $url, 'archive_url' => $archive, 'title' => $title, 'type' => 'article',
        'site_name' => kop_kit_str($rec['publication_name'] ?? '', 255)));
    if ($dupes) return kop_kit_duplicate_response('this article');
    if ($dry) return new WP_REST_Response(array('ok' => true, 'dry_run' => true, 'would' => 'News draft'), 200);

    $facilities = kop_kit_list($rec['facilities_mentioned'] ?? array());
    $p = array(
        'url' => $url, 'archive_url' => $archive, 'title' => $title,
        'site_name' => kop_kit_str($rec['publication_name'] ?? '', 255),
        'author' => kop_kit_str($rec['author'] ?? '', 255),
        'published' => kop_kit_str($rec['publication_date'] ?? ''),
        'tags' => kop_kit_list($rec['tags'] ?? array(), 20),
        'description' => kop_ext_textarea($rec['summary'] ?? '', 2000),
        'facility' => $facilities ? $facilities[0] : '',
        'staff' => kop_kit_list($rec['staff_mentioned'] ?? array()),
        'content_warnings' => kop_kit_list($rec['content_warnings'] ?? array()),
        'article_type' => kop_kit_str($rec['article_type'] ?? 'general', 40),
        'facilities' => $facilities,
    );
    $submitter = kop_kit_submitter($wrapper);
    $id = kop_ext_insert_news($pdo, $p, $submitter, kop_kit_note($wrapper));
    if (is_wp_error($id)) return $id;
    // The basic insert keeps no staff, warnings or type: add them to the row it just made, still 'submitted'.
    try {
        $types = function_exists('kop_news_article_type_values') ? kop_news_article_type_values('all') : array('general');
        $type = in_array($p['article_type'], $types, true) ? $p['article_type'] : 'general';
        $pdo->prepare('UPDATE news_submissions SET article_type = ?, staff_mentioned = ?, content_warnings = ? WHERE id = ? AND status = "submitted"')
            ->execute(array($type, kop_kit_json($p['staff']), kop_kit_json($p['content_warnings']), (int) $id));
    } catch (Throwable $e) {
        error_log('kop kit: news #' . $id . ' extra fields failed: ' . $e->getMessage());
    }
    return new WP_REST_Response(array('ok' => true, 'queue' => 'news review', 'id' => (int) $id), 201);
}

/* ---- the route ------------------------------------------------------------------- */

function kop_kit_rest_submit(WP_REST_Request $req) {
    if (!function_exists('kop_msub_is_research') || !kop_msub_is_research($req)) {
        return new WP_Error('kop_forbidden', 'The research key is required.', array('status' => 403));
    }
    $GLOBALS['kop_notify_sender'] = 'system'; // into the daily digest, not one mail each
    $p = $req->get_json_params();
    $kind = is_array($p) ? sanitize_key((string) ($p['kind'] ?? '')) : '';
    if (!in_array($kind, kop_kit_kinds(), true)) {
        return new WP_Error('kop_bad_kind', 'Unknown kind.', array('status' => 400));
    }
    $rec = $p['record'] ?? null;
    if (!is_array($rec)) return new WP_Error('kop_bad_record', 'record must be an object.', array('status' => 400));
    $wrapper = is_array($p['wrapper'] ?? null) ? $p['wrapper'] : array();
    if ((int) ($wrapper['kit_version'] ?? 0) !== KOP_KIT_VERSION) {
        return new WP_Error('kop_bad_version', 'This server takes kit_version ' . KOP_KIT_VERSION . '.', array('status' => 400));
    }
    $dry = !empty($p['dry_run']);
    try {
        $pdo = kop_kit_pdo();
        switch ($kind) {
            case 'facilities': return kop_kit_facility($pdo, $rec, $wrapper, $dry);
            case 'companies':  return kop_kit_company($pdo, $rec, $wrapper, $dry);
            case 'lawsuits':   return kop_kit_lawsuit($pdo, $rec, $wrapper, $dry);
            case 'bills':      return kop_kit_bill($pdo, $rec, $wrapper, $dry);
            default:           return kop_kit_article($pdo, $rec, $wrapper, $dry);
        }
    } catch (Throwable $e) {
        error_log('kop kit submit failed: ' . $e->getMessage());
        return new WP_Error('kop_save_failed', 'The site could not save this record.', array('status' => 500));
    }
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/kit/submit', array(
        'methods'             => 'POST',
        'permission_callback' => '__return_true', // the research key is checked in the callback
        'callback'            => 'kop_kit_rest_submit',
    ));
});
