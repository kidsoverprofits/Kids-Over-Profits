<?php
/**
 * API - Fetch Lawsuit Details
 *
 * Looks up a docket on CourtListener (federal RECAP coverage) and returns a
 * payload shaped like the lawsuit form so the admin UI can auto-populate.
 * The lookup itself is api/lib-courtlistener.php (the app's Lawsuit Processor uses it too).
 *
 * Federal only. State cases are not supported via this endpoint; CourtListener's
 * state-court coverage is patchy, and parties/docket data are largely federal.
 * For state cases the admin should enter manually or use the complaint-upload
 * extractor.
 *
 * Query params:
 *   case_number  - required, e.g. "2:21-cv-00001"
 *   jurisdiction - required ("Federal" returns data; other values return a
 *                  "manual entry" message)
 *   court        - optional, free-text; used only to disambiguate when
 *                  CourtListener returns multiple matches
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

if (!defined('ABSPATH')) {
    $current = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $current = dirname($current);
        if (file_exists($current . '/wp-load.php')) {
            require_once $current . '/wp-load.php';
            break;
        }
    }
}

require_once __DIR__ . '/lib-courtlistener.php';

$case_number  = trim((string)($_GET['case_number'] ?? ''));
$jurisdiction = trim((string)($_GET['jurisdiction'] ?? ''));
$court_hint   = trim((string)($_GET['court'] ?? ''));

if ($case_number === '' || $jurisdiction === '') {
    echo json_encode(['success' => false, 'error' => 'Missing required parameters (case_number, jurisdiction).']);
    exit;
}

if (strcasecmp($jurisdiction, 'Federal') !== 0) {
    echo json_encode([
        'success' => false,
        'error'   => "Auto-lookup currently supports Federal cases only. For {$jurisdiction} state-court cases, enter details manually or upload the complaint PDF for automatic extraction.",
    ]);
    exit;
}

$token = kop_cl_token();
if ($token === '') {
    echo json_encode([
        'success' => false,
        'error'   => 'CourtListener API token is not configured. Add COURTLISTENER_API_KEY to your .env file. Free signup at https://www.courtlistener.com/help/api/rest/',
    ]);
    exit;
}

try {
    echo json_encode(kop_cl_lookup_case($case_number, $court_hint, $token));
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
