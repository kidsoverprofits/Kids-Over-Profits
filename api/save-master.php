<?php
/**
 * Save to Master Database API
 *
 * This script handles saving facility data directly to the master database.
 * It should only be accessible to administrators.
 *
 * Expected POST data:
 * - data: The facility data object to save
 * - projectName: The name of the project
 * - action: 'save' or 'delete'
 */

// Set JSON header
header('Content-Type: application/json');

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in JSON response

// Custom error handler to return JSON for FATAL error types only.
// WordPress core and plugins load below this handler and routinely raise
// notices/warnings/deprecations (especially on PHP 8.2) — aborting on those
// would break every admin save site-wide. Non-fatal errors are logged and
// deferred to PHP's normal handling; suppressed errors (@ / error_reporting
// masks) are respected.
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) {
        return false; // suppressed via @ or error_reporting mask
    }

    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
    if ($errno & $fatal) {
        echo json_encode([
            'success' => false,
            'error' => "PHP Error: $errstr in $errfile on line $errline"
        ]);
        exit;
    }

    error_log("save-master.php non-fatal PHP error ($errno): $errstr in $errfile on line $errline");
    return true; // handled; don't let display_errors leak into the JSON body
});

// Security: Check if user is admin (uncomment when WordPress is available)
// if (!current_user_can('administrator')) {
//     echo json_encode([
//         'success' => false,
//         'error' => 'Unauthorized: Admin access required'
//     ]);
//     exit;
// }

// Get POST data
$input = file_get_contents('php://input');
$request = json_decode($input, true);

if (!$request) {
    echo json_encode([
        'success' => false,
        'error' => 'Invalid JSON input'
    ]);
    exit;
}

// Use PDO connection from config.php
// Load this BEFORE extracting variables to avoid WordPress variable conflicts
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib-marketers.php';
require_once __DIR__ . '/lib-tradeorgs.php';

// --- Admin authentication ---
// This endpoint writes directly to the master tables, so it must be admin-only.
// Ensure WordPress is fully loaded so capability checks work, then require it.
if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

// v2 facility model: re-derive from the legacy tables right after this save
// (inc/facility-v2-sync.php), so pages already switched to v2 show the edit.
if (function_exists('kop_facility_v2_request_sync')) {
    kop_facility_v2_request_sync();
}

// Prefer the WordPress table prefix when available, but fall back to unprefixed tables.
$prefix = '';
if (isset($table_prefix) && is_string($table_prefix)) {
    $prefix = $table_prefix;
}

if (!function_exists('kop_resolve_table_name')) {
    function kop_resolve_table_name(PDO $pdo, $base, $prefix = '') {
        $candidates = [];
        if ($prefix) {
            $candidates[] = $prefix . $base;
        }
        $candidates[] = $base;

        foreach ($candidates as $candidate) {
            $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
            $stmt->execute([':table' => $candidate]);
            if ($stmt->fetchColumn()) {
                return $candidate;
            }
        }

        return $candidates[0];
    }
}

$facilities_table = kop_resolve_table_name($pdo, 'facilities_master', $prefix);
$referrers_table = kop_resolve_table_name($pdo, 'referrers_master', $prefix);
$transporters_table = kop_resolve_table_name($pdo, 'transporters_master', $prefix);
$providers_table = kop_resolve_table_name($pdo, 'providers_master', '');
$marketers_table = kop_resolve_table_name($pdo, 'marketers_master', '');
$tradeorgs_table = kop_resolve_table_name($pdo, 'tradeorgs_master', '');
$locations_table = kop_resolve_table_name($pdo, 'locations_master', $prefix);
$wiki_table = kop_resolve_table_name($pdo, 'wiki_master', $prefix);

if (!function_exists('kop_ensure_master_table')) {
    /**
     * Auto-create a master table on demand if it doesn't exist yet.
     * Used by category branches that may run before the table has been provisioned.
     */
    function kop_ensure_master_table(PDO $pdo, $tableName) {
        $stmt = $pdo->prepare('SHOW TABLES LIKE :table');
        $stmt->execute([':table' => $tableName]);
        if ($stmt->fetchColumn()) {
            return;
        }
        $quoted = '`' . str_replace('`', '``', $tableName) . '`';
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$quoted} (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            unique_name VARCHAR(255) NOT NULL,
            json_data LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_name (unique_name),
            KEY updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

// Set defaults for all expected variables to prevent undefined errors
// These are defined AFTER config.php to prevent WordPress from overwriting them
$action = isset($request['action']) ? $request['action'] : 'save';
$projectName = isset($request['projectName']) ? $request['projectName'] : null;
$newProjectName = isset($request['newProjectName']) ? $request['newProjectName'] : null;
$data = isset($request['data']) ? $request['data'] : null;
$requestedCategory = isset($request['category']) ? $request['category'] : 'companies';
$currentFacilityIndex = isset($request['currentFacilityIndex']) ? intval($request['currentFacilityIndex']) : 0;
$timestamp = isset($request['timestamp']) ? $request['timestamp'] : date('c');

// Normalize incoming payloads that may include a full project wrapper or double-wrapped data.
if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
    // If a full project object was passed inside `data`, unwrap it.
    if (empty($projectName) && !empty($data['name']) && is_string($data['name'])) {
        $projectName = $data['name'];
    }
    if ((!isset($request['category']) || $request['category'] === '') && !empty($data['category']) && is_string($data['category'])) {
        $requestedCategory = $data['category'];
    }
    if ($currentFacilityIndex === 0 && isset($data['currentFacilityIndex'])) {
        $currentFacilityIndex = intval($data['currentFacilityIndex']);
    }
    if ((!isset($request['timestamp']) || $request['timestamp'] === '') && !empty($data['timestamp']) && is_string($data['timestamp'])) {
        $timestamp = $data['timestamp'];
    }
    $data = $data['data'];
}

$unwrap_guard = 0;
while (is_array($data)
    && !isset($data['operator'])
    && !isset($data['facilities'])
    && isset($data['data'])
    && is_array($data['data'])
    && $unwrap_guard < 2
) {
    $data = $data['data'];
    $unwrap_guard += 1;
}

// US State names for location project matching (used by multiple functions)
$US_STATE_NAMES = [
    'ALABAMA', 'ALASKA', 'ARIZONA', 'ARKANSAS', 'CALIFORNIA', 'COLORADO', 'CONNECTICUT',
    'DELAWARE', 'FLORIDA', 'GEORGIA', 'HAWAII', 'IDAHO', 'ILLINOIS', 'INDIANA', 'IOWA',
    'KANSAS', 'KENTUCKY', 'LOUISIANA', 'MAINE', 'MARYLAND', 'MASSACHUSETTS', 'MICHIGAN',
    'MINNESOTA', 'MISSISSIPPI', 'MISSOURI', 'MONTANA', 'NEBRASKA', 'NEVADA', 'NEW HAMPSHIRE',
    'NEW JERSEY', 'NEW MEXICO', 'NEW YORK', 'NORTH CAROLINA', 'NORTH DAKOTA', 'OHIO',
    'OKLAHOMA', 'OREGON', 'PENNSYLVANIA', 'RHODE ISLAND', 'SOUTH CAROLINA', 'SOUTH DAKOTA',
    'TENNESSEE', 'TEXAS', 'UTAH', 'VERMONT', 'VIRGINIA', 'WASHINGTON', 'WEST VIRGINIA',
    'WISCONSIN', 'WYOMING'
];

// Common countries for international facilities
$COUNTRY_NAMES = [
    'CANADA', 'MEXICO', 'UNITED KINGDOM', 'AUSTRALIA', 'JAMAICA', 'SAMOA', 'COSTA RICA',
    'BELIZE', 'BAHAMAS', 'DOMINICAN REPUBLIC', 'PUERTO RICO', 'FRANCE', 'GERMANY', 'ITALY',
    'SPAIN', 'NETHERLANDS', 'SWITZERLAND', 'SWEDEN', 'NORWAY', 'DENMARK', 'IRELAND',
    'NEW ZEALAND', 'SOUTH AFRICA', 'ISRAEL', 'JAPAN', 'CHINA', 'INDIA', 'BRAZIL', 'ARGENTINA'
];

// State abbreviation to full name mapping
$STATE_ABBREVIATIONS = [
    'AL' => 'ALABAMA', 'AK' => 'ALASKA', 'AZ' => 'ARIZONA', 'AR' => 'ARKANSAS',
    'CA' => 'CALIFORNIA', 'CO' => 'COLORADO', 'CT' => 'CONNECTICUT', 'DE' => 'DELAWARE',
    'FL' => 'FLORIDA', 'GA' => 'GEORGIA', 'HI' => 'HAWAII', 'ID' => 'IDAHO',
    'IL' => 'ILLINOIS', 'IN' => 'INDIANA', 'IA' => 'IOWA', 'KS' => 'KANSAS',
    'KY' => 'KENTUCKY', 'LA' => 'LOUISIANA', 'ME' => 'MAINE', 'MD' => 'MARYLAND',
    'MA' => 'MASSACHUSETTS', 'MI' => 'MICHIGAN', 'MN' => 'MINNESOTA', 'MS' => 'MISSISSIPPI',
    'MO' => 'MISSOURI', 'MT' => 'MONTANA', 'NE' => 'NEBRASKA', 'NV' => 'NEVADA',
    'NH' => 'NEW HAMPSHIRE', 'NJ' => 'NEW JERSEY', 'NM' => 'NEW MEXICO', 'NY' => 'NEW YORK',
    'NC' => 'NORTH CAROLINA', 'ND' => 'NORTH DAKOTA', 'OH' => 'OHIO', 'OK' => 'OKLAHOMA',
    'OR' => 'OREGON', 'PA' => 'PENNSYLVANIA', 'RI' => 'RHODE ISLAND', 'SC' => 'SOUTH CAROLINA',
    'SD' => 'SOUTH DAKOTA', 'TN' => 'TENNESSEE', 'TX' => 'TEXAS', 'UT' => 'UTAH',
    'VT' => 'VERMONT', 'VA' => 'VIRGINIA', 'WA' => 'WASHINGTON', 'WV' => 'WEST VIRGINIA',
    'WI' => 'WISCONSIN', 'WY' => 'WYOMING'
];

// SERVER-SIDE CATEGORY VALIDATION: Override category for state/country names
// This ensures location projects ALWAYS go to locations_master, regardless of what frontend sends
$category = $requestedCategory; // Start with requested category
if ($projectName) {
    $projectNameUpper = strtoupper(trim($projectName));
    if ($requestedCategory !== 'providers' && (in_array($projectNameUpper, $US_STATE_NAMES) || in_array($projectNameUpper, $COUNTRY_NAMES))) {
        $category = 'locations';
        $projectName = $projectNameUpper;
        // Log if frontend sent wrong category (helps debug issues)
        if ($requestedCategory !== 'locations') {
            error_log("Category override: '$projectName' is a state/country name - forcing category from '$requestedCategory' to 'locations'");
        }
    }
}

// If the payload looks like an operator project, force it to companies unless
// the caller tagged it as another known type. Every form payload carries empty
// operator/facilities blocks, so without this list referrer, transporter and
// provider saves were rewritten into the facility tables (matches the REST
// save in inc/rest-api.php).
$kop_known_non_company = ['locations', 'referrers', 'transporters', 'providers', 'marketers', 'tradeorgs', 'wiki'];
if (!in_array($category, $kop_known_non_company, true) && is_array($data)) {
    $has_operator_data = isset($data['operator']) || isset($data['facilities']);
    if ($has_operator_data && $category !== 'companies' && $category !== 'company') {
        $category = 'companies';
    }
}

// Validate project name (skip for rebuild-locations which processes all projects)
if (!$projectName && $action !== 'rebuild-locations') {
    echo json_encode([
        'success' => false,
        'error' => 'Project name is required'
    ]);
    exit;
}

// v2 facility model (docs/DATA-MODEL-MIGRATION.md phase 4 step 5): once the
// write switch is on, operator projects and location profiles are saved to the
// v2 tables through inc/facility-v2-writer.php and the legacy facility tables
// stay frozen. Referrer, transporter and wiki projects are unaffected.
require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
$kop_v2_prefix = (isset($GLOBALS['wpdb']->prefix) && $GLOBALS['wpdb']->prefix !== '') ? $GLOBALS['wpdb']->prefix : $prefix;
if (kop_v2_writes_active($pdo, $kop_v2_prefix)) {
    $kop_v2_facility_project = in_array($category, ['companies', 'company', 'locations'], true);
    try {
        if (in_array($action, ['rebuild-locations', 'cleanup-locations', 'merge-location-duplicates'], true)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => "'$action' rebuilds the old location tables, which are frozen now that saves go to the new facility tables."]);
            exit;
        }
        if ($action === 'save' && $kop_v2_facility_project) {
            if (!is_array($data)) {
                echo json_encode(['success' => false, 'error' => 'No data provided to save']);
                exit;
            }
            $result = kop_v2_save_form_project($pdo, $kop_v2_prefix, $projectName, $data, $category === 'locations' ? 'locations' : 'companies', [
                'timestamp' => $timestamp,
                'currentFacilityIndex' => $currentFacilityIndex,
            ]);
            echo json_encode([
                'success' => true,
                'message' => kop_v2_save_summary($projectName, $result),
                // index in data.facilities => facility id, so the form can stamp new facilities
                'facilityIds' => (object)$result['ids'],
                'result' => $result,
            ]);
            exit;
        }
        if ($action === 'rename') {
            if (!$projectName || !$newProjectName) {
                echo json_encode(['success' => false, 'error' => 'Old and new project names are required for rename.']);
                exit;
            }
            $result = kop_v2_rename_operator($pdo, $kop_v2_prefix, $projectName, $newProjectName);
            echo json_encode($result['renamed']
                ? ['success' => true, 'message' => "Project '$projectName' renamed to '$newProjectName'.", 'facilitiesUpdated' => $result['facilities_updated']]
                : ['success' => false, 'error' => $result['error']]);
            exit;
        }
        if ($action === 'delete') {
            $is_location = in_array(strtoupper($projectName), array_merge($US_STATE_NAMES, $COUNTRY_NAMES), true);
            $result = kop_v2_delete_form_project($pdo, $kop_v2_prefix, $projectName, $is_location);
            $deleted = $result['deleted'];
            $message = $deleted ? "Project '$projectName' deleted" : '';
            if ($deleted && !empty($result['facilities_kept'])) {
                $message .= ". Its {$result['facilities_kept']} facilities are kept and stay on their state pages";
            }
            if (!$is_location && !$deleted) {
                // Not an operator: a referrer, transporter or provider project of that name.
                foreach (['referrers_master', $transporters_table, $providers_table, $marketers_table, $tradeorgs_table] as $table) {
                    try {
                        $stmt = $pdo->prepare("DELETE FROM `$table` WHERE unique_name = :projectName");
                        $stmt->execute([':projectName' => $projectName]);
                        if ($stmt->rowCount() > 0) {
                            $deleted = true;
                            $message = "Project '$projectName' deleted from $table";
                        }
                    } catch (PDOException $e) {
                        // transporters_master / providers_master may not exist yet.
                    }
                }
            }
            echo json_encode($deleted
                ? ['success' => true, 'message' => $message, 'wasLocationProject' => $is_location]
                : ['success' => false, 'error' => $result['error'] ?? 'Project not found in any database table']);
            exit;
        }
    } catch (Throwable $e) {
        // Validation problems come back as a normal response so the form shows
        // the message instead of a truncated HTTP error.
        if (!($e instanceof RuntimeException)) http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save: ' . $e->getMessage()]);
        exit;
    }
}

// The v2 branch above handles every facility save, rename and delete. The old
// facilities_master / locations_master paths were retired with data model
// phase 5 (2026-10-25), so with the write switch off these refuse.
if ($action === 'rename' || $action === 'delete' || ($action === 'save' && in_array($category, ['companies', 'company', 'locations'], true))) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => "'$action' of an operator or location project needs the new facility tables (writes switch), which is off."]);
    exit;
}

// ============================================
// LOCATION PROJECT AUTO-DUPLICATION
// ============================================

/**
 * Parse city/state from a location string like "Salt Lake City, UT" or "Denver, Colorado"
 */
function parseCityState($location) {
    if (!$location || !is_string($location)) {
        return ['city' => '', 'state' => ''];
    }
    
    // State abbreviations mapping
    $stateAbbreviations = [
        'AL' => 'ALABAMA', 'AK' => 'ALASKA', 'AZ' => 'ARIZONA', 'AR' => 'ARKANSAS',
        'CA' => 'CALIFORNIA', 'CO' => 'COLORADO', 'CT' => 'CONNECTICUT', 'DE' => 'DELAWARE',
        'FL' => 'FLORIDA', 'GA' => 'GEORGIA', 'HI' => 'HAWAII', 'ID' => 'IDAHO',
        'IL' => 'ILLINOIS', 'IN' => 'INDIANA', 'IA' => 'IOWA', 'KS' => 'KANSAS',
        'KY' => 'KENTUCKY', 'LA' => 'LOUISIANA', 'ME' => 'MAINE', 'MD' => 'MARYLAND',
        'MA' => 'MASSACHUSETTS', 'MI' => 'MICHIGAN', 'MN' => 'MINNESOTA', 'MS' => 'MISSISSIPPI',
        'MO' => 'MISSOURI', 'MT' => 'MONTANA', 'NE' => 'NEBRASKA', 'NV' => 'NEVADA',
        'NH' => 'NEW HAMPSHIRE', 'NJ' => 'NEW JERSEY', 'NM' => 'NEW MEXICO', 'NY' => 'NEW YORK',
        'NC' => 'NORTH CAROLINA', 'ND' => 'NORTH DAKOTA', 'OH' => 'OHIO', 'OK' => 'OKLAHOMA',
        'OR' => 'OREGON', 'PA' => 'PENNSYLVANIA', 'RI' => 'RHODE ISLAND', 'SC' => 'SOUTH CAROLINA',
        'SD' => 'SOUTH DAKOTA', 'TN' => 'TENNESSEE', 'TX' => 'TEXAS', 'UT' => 'UTAH',
        'VT' => 'VERMONT', 'VA' => 'VIRGINIA', 'WA' => 'WASHINGTON', 'WV' => 'WEST VIRGINIA',
        'WI' => 'WISCONSIN', 'WY' => 'WYOMING'
    ];
    
    // Try splitting by comma
    $parts = array_map('trim', explode(',', $location));
    if (count($parts) >= 2) {
        $city = $parts[0];
        $statePart = strtoupper(trim($parts[count($parts) - 1]));
        
        // Check if it's an abbreviation
        if (isset($stateAbbreviations[$statePart])) {
            return ['city' => $city, 'state' => $stateAbbreviations[$statePart]];
        }
        
        // Check if it's a full state name
        $stateUpper = strtoupper($statePart);
        if (in_array($stateUpper, array_values($stateAbbreviations))) {
            return ['city' => $city, 'state' => $stateUpper];
        }
    }
    
    return ['city' => $location, 'state' => ''];
}

/**
 * Normalize a state value - convert abbreviation to full name if needed
 */
function normalizeStateName($state) {
    global $US_STATE_NAMES, $STATE_ABBREVIATIONS;
    
    if (!$state || !is_string($state)) {
        return null;
    }
    
    $state = strtoupper(trim($state));
    
    // Check if it's already a full state name
    if (in_array($state, $US_STATE_NAMES)) {
        return $state;
    }
    
    // Check if it's an abbreviation
    if (isset($STATE_ABBREVIATIONS[$state])) {
        return $STATE_ABBREVIATIONS[$state];
    }
    
    return null;
}

/**
 * Normalize a country value
 */
function normalizeCountryName($country) {
    global $COUNTRY_NAMES;
    
    if (!$country || !is_string($country)) {
        return null;
    }
    
    $country = strtoupper(trim($country));
    
    // Check if it's a known country
    if (in_array($country, $COUNTRY_NAMES)) {
        return $country;
    }
    
    // For unknown countries, still return the uppercased value
    // This allows new countries to be added dynamically
    if (strlen($country) > 1) {
        return $country;
    }
    
    return null;
}

/**
 * Extract state or country from a facility record for location project grouping
 * Returns the state/country name that will be used as the location project name
 * This function checks ALL possible field locations where state/country might be stored
 */
function extractFacilityState($facility) {
    global $US_STATE_NAMES, $COUNTRY_NAMES, $STATE_ABBREVIATIONS;
    
    if (!is_array($facility)) {
        return null;
    }
    
    // Check if facility is marked as international
    $isInternational = !empty($facility['isInternational']);
    
    // Helper to try normalizing a value as state first, then country
    $tryNormalize = function($value) use ($isInternational) {
        if (empty($value) || !is_string($value)) {
            return null;
        }
        
        // Try as US state first
        $state = normalizeStateName($value);
        if ($state) {
            return $state;
        }
        
        // If international or state not found, try as country
        $country = normalizeCountryName($value);
        if ($country) {
            return $country;
        }
        
        return null;
    };
    
    // 1. Check the nested locationDetails structure (new form format)
    if (!empty($facility['locationDetails']) && is_array($facility['locationDetails'])) {
        $details = $facility['locationDetails'];
        
        // Check state in locationDetails
        if (!empty($details['state'])) {
            $result = $tryNormalize($details['state']);
            if ($result) return $result;
        }
        
        // Check country in locationDetails (for international)
        if (!empty($details['country'])) {
            $result = $tryNormalize($details['country']);
            if ($result) return $result;
        }
    }
    
    // 2. Check flat locationState field
    if (!empty($facility['locationState'])) {
        $result = $tryNormalize($facility['locationState']);
        if ($result) return $result;
    }
    
    // 3. Check flat state field
    if (!empty($facility['state'])) {
        $result = $tryNormalize($facility['state']);
        if ($result) return $result;
    }
    
    // 4. Check flat locationCountry field
    if (!empty($facility['locationCountry'])) {
        $result = $tryNormalize($facility['locationCountry']);
        if ($result) return $result;
    }
    
    // 5. Check flat country field
    if (!empty($facility['country'])) {
        $result = $tryNormalize($facility['country']);
        if ($result) return $result;
    }
    
    // 6. Try parsing from "location" string field (e.g., "Salt Lake City, UT")
    if (!empty($facility['location']) && is_string($facility['location'])) {
        $parsed = parseCityState($facility['location']);
        if (!empty($parsed['state'])) {
            $result = $tryNormalize($parsed['state']);
            if ($result) return $result;
        }
    }
    
    // 7. Check identification section for location hints
    if (!empty($facility['identification']) && is_array($facility['identification'])) {
        if (!empty($facility['identification']['state'])) {
            $result = $tryNormalize($facility['identification']['state']);
            if ($result) return $result;
        }
        if (!empty($facility['identification']['locationState'])) {
            $result = $tryNormalize($facility['identification']['locationState']);
            if ($result) return $result;
        }
        if (!empty($facility['identification']['country'])) {
            $result = $tryNormalize($facility['identification']['country']);
            if ($result) return $result;
        }
    }
    
    // 8. Check address field if it contains state info
    if (!empty($facility['address']) && is_string($facility['address'])) {
        // Try to extract state from end of address
        $parsed = parseCityState($facility['address']);
        if (!empty($parsed['state'])) {
            $result = $tryNormalize($parsed['state']);
            if ($result) return $result;
        }
    }
    
    return null;
}

/**
 * Extract state from a referrer/consultant record
 */
function extractReferrerState($referrer) {
    if (!is_array($referrer)) {
        return null;
    }
    
    // Check state field
    if (!empty($referrer['state'])) {
        $state = normalizeStateName($referrer['state']);
        if ($state) {
            return $state;
        }
        // Try as country
        $country = normalizeCountryName($referrer['state']);
        if ($country) {
            return $country;
        }
    }
    
    // Check location field (City, State format)
    if (!empty($referrer['location']) && is_string($referrer['location'])) {
        $parsed = parseCityState($referrer['location']);
        if (!empty($parsed['state'])) {
            $state = normalizeStateName($parsed['state']);
            if ($state) {
                return $state;
            }
        }
    }
    
    // Check city field - sometimes state is stored incorrectly
    if (!empty($referrer['city']) && is_string($referrer['city'])) {
        // Some records have "City, State" in city field
        $parsed = parseCityState($referrer['city']);
        if (!empty($parsed['state'])) {
            $state = normalizeStateName($parsed['state']);
            if ($state) {
                return $state;
            }
        }
    }
    
    return null;
}

/**
 * Extract state from operator record  
 */
function extractOperatorState($operator) {
    if (!is_array($operator)) {
        return null;
    }
    
    // Check state field
    if (!empty($operator['state'])) {
        $state = normalizeStateName($operator['state']);
        if ($state) {
            return $state;
        }
    }
    
    // Check locationState field
    if (!empty($operator['locationState'])) {
        $state = normalizeStateName($operator['locationState']);
        if ($state) {
            return $state;
        }
    }
    
    // Check headquarters location
    if (!empty($operator['location']) && is_string($operator['location'])) {
        $parsed = parseCityState($operator['location']);
        if (!empty($parsed['state'])) {
            $state = normalizeStateName($parsed['state']);
            if ($state) {
                return $state;
            }
        }
    }
    
    // Check hqState
    if (!empty($operator['hqState'])) {
        $state = normalizeStateName($operator['hqState']);
        if ($state) {
            return $state;
        }
    }
    
    return null;
}

/**
 * Update location projects based on saved company/referrer data
 * This duplicates facilities and referrers to their respective state/country location projects
 */
function updateLocationProjectsFromSave($pdo, $sourceProjectName, $data, $sourceCategory) {
    global $US_STATE_NAMES, $COUNTRY_NAMES;

    $updatedLocations = [];
    $locationBuckets = [];

    // Get the source project's operator info to attach to cloned facilities
    $sourceOperator = isset($data['operator']) ? $data['operator'] : null;

    // Only TTI company projects had a facilities_master row to back-link to; they save through v2 now.
    $sourceProjectId = null;

    // Local helper: extract a facility's display name (preferring identification.name)
    $facilityName = function($facility) {
        if (!is_array($facility)) return '';
        if (!empty($facility['identification']) && is_array($facility['identification'])) {
            $n = trim((string)($facility['identification']['name'] ?? ''));
            if ($n !== '') return $n;
            $n = trim((string)($facility['identification']['currentName'] ?? ''));
            if ($n !== '') return $n;
        }
        return trim((string)($facility['name'] ?? ''));
    };
    
    // Initialize buckets for facilities and referrers by location (state or country)
    $initBucket = function($location) use (&$locationBuckets) {
        if (!isset($locationBuckets[$location])) {
            $locationBuckets[$location] = [
                'facilities' => [],
                'referrers' => []
            ];
        }
    };
    
    // Process facilities
    if (!empty($data['facilities']) && is_array($data['facilities'])) {
        foreach ($data['facilities'] as $facility) {
            $location = extractFacilityState($facility);
            if ($location) {
                $initBucket($location);
                // Clone the facility and add source project info
                $clone = $facility;
                $clone['sourceProject'] = $sourceProjectName;
                $clone['sourceCategory'] = $sourceCategory;
                if ($sourceProjectId !== null) {
                    $clone['sourceProjectId'] = $sourceProjectId;
                }
                // Include source operator info so facility knows its parent company
                if ($sourceOperator) {
                    $clone['sourceOperator'] = $sourceOperator;
                    // Also set identification.currentOperator if not already set
                    if (!isset($clone['identification'])) {
                        $clone['identification'] = [];
                    }
                    if (empty($clone['identification']['currentOperator']) && !empty($sourceOperator['name'])) {
                        $clone['identification']['currentOperator'] = $sourceOperator['name'];
                    }
                }
                $locationBuckets[$location]['facilities'][] = $clone;
            }
        }
    }
    
    // Process referrer consultants
    if (!empty($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $consultant) {
            $location = extractReferrerState($consultant);
            if ($location) {
                $initBucket($location);
                $clone = $consultant;
                $clone['sourceProject'] = $sourceProjectName;
                $clone['sourceCategory'] = $sourceCategory;
                $locationBuckets[$location]['referrers'][] = $clone;
            }
        }
    }
    
    // Process individual referrer
    if (!empty($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        $location = extractReferrerState($data['referrerIndividual']);
        if ($location) {
            $initBucket($location);
            $clone = $data['referrerIndividual'];
            $clone['sourceProject'] = $sourceProjectName;
            $clone['sourceCategory'] = $sourceCategory;
            $locationBuckets[$location]['referrers'][] = $clone;
        }
    }
    
    // Process referrer agency
    if (!empty($data['referrerAgency']) && !empty($data['referrerAgency']['state'])) {
        $location = normalizeStateName($data['referrerAgency']['state']);
        if ($location) {
            $initBucket($location);
            $clone = $data['referrerAgency'];
            $clone['sourceProject'] = $sourceProjectName;
            $clone['sourceCategory'] = $sourceCategory;
            // Store agency separately if needed
            if (!isset($locationBuckets[$location]['agencies'])) {
                $locationBuckets[$location]['agencies'] = [];
            }
            $locationBuckets[$location]['agencies'][] = $clone;
        }
    }
    
    // Now update each affected location's project
    foreach ($locationBuckets as $locationName => $bucket) {
        try {
            // First, load existing location project from locations_master (if any)
            $existingProject = null;
            $stmt = $pdo->prepare("SELECT json_data FROM locations_master WHERE unique_name = :locationName");
            $stmt->execute([':locationName' => $locationName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($row && !empty($row['json_data'])) {
                $existingProject = json_decode($row['json_data'], true);
            }
            
            // Initialize or update the location project data
            $locationData = [];
            if ($existingProject && isset($existingProject['data'])) {
                $locationData = $existingProject['data'];
            }
            
            // Ensure arrays exist
            if (!isset($locationData['facilities']) || !is_array($locationData['facilities'])) {
                $locationData['facilities'] = [];
            }
            if (!isset($locationData['referrerConsultants']) || !is_array($locationData['referrerConsultants'])) {
                $locationData['referrerConsultants'] = [];
            }
            
            // Remove old entries from this source project (to avoid duplicates)
            $locationData['facilities'] = array_filter($locationData['facilities'], function($f) use ($sourceProjectName) {
                return !isset($f['sourceProject']) || $f['sourceProject'] !== $sourceProjectName;
            });
            $locationData['referrerConsultants'] = array_filter($locationData['referrerConsultants'], function($r) use ($sourceProjectName) {
                return !isset($r['sourceProject']) || $r['sourceProject'] !== $sourceProjectName;
            });
            
            // Re-index arrays after filtering
            $locationData['facilities'] = array_values($locationData['facilities']);
            $locationData['referrerConsultants'] = array_values($locationData['referrerConsultants']);
            
            // Add new entries from this source project
            foreach ($bucket['facilities'] as $facility) {
                $locationData['facilities'][] = $facility;
            }
            foreach ($bucket['referrers'] as $referrer) {
                $locationData['referrerConsultants'][] = $referrer;
            }
            
            // Create the project structure
            $locationProject = [
                'name' => $locationName,
                'data' => $locationData,
                'category' => 'locations',
                'currentFacilityIndex' => 0,
                'timestamp' => date('c')
            ];
            
            $jsonData = json_encode($locationProject);
            
            // Upsert the location project to locations_master table
            $sql = "INSERT INTO locations_master (unique_name, json_data, updated_at)
                    VALUES (:unique_name, :json_data_insert, NOW())
                    ON DUPLICATE KEY UPDATE json_data = :json_data_update, updated_at = NOW()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':unique_name', $locationName);
            $stmt->bindValue(':json_data_insert', $jsonData);
            $stmt->bindValue(':json_data_update', $jsonData);
            $stmt->execute();
            
            $updatedLocations[] = $locationName;
            
        } catch (PDOException $e) {
            // Log the error but continue with other locations
            error_log("Failed to update location project '$locationName': " . $e->getMessage());
        }
    }
    
    return $updatedLocations;
}

// Handle save action
if ($action === 'save') {
    if (!$data) {
        echo json_encode([
            'success' => false,
            'error' => 'No data provided to save'
        ]);
        exit;
    }

    // Facilities are saved through the v2 branch above (companies, locations); the other kinds saved
    // here (referrers, transporters, ...) no longer promote nested facilities into facilities_master,
    // which data model phase 5 retires.

    // Create the complete project structure with metadata
    $projectStructure = [
        'name' => $projectName,
        'data' => $data,
        'category' => $category,
        'currentFacilityIndex' => $currentFacilityIndex,
        'timestamp' => $timestamp
    ];

    // Encode the complete project structure as a JSON string
    $jsonData = json_encode($projectStructure);

    // Determine which table to use based on category
    if ($category === 'referrers') {
        $tableName = $referrers_table;
    } elseif ($category === 'transporters') {
        $tableName = $transporters_table;
        kop_ensure_master_table($pdo, $tableName);
    } elseif ($category === 'providers') {
        $tableName = $providers_table;
        kop_ensure_master_table($pdo, $tableName);
    } elseif ($category === 'marketers') {
        // Marketing companies (js/data-form/marketer-form.js): only the company block is kept
        $tableName = $marketers_table;
        kop_ensure_master_table($pdo, $tableName);
        $projectStructure['data'] = kop_marketer_keep_data($data);
        $jsonData = json_encode($projectStructure);
    } elseif ($category === 'tradeorgs') {
        // Trade organizations (js/data-form/tradeorg-form.js): only the tradeOrg block is kept
        $tableName = $tradeorgs_table;
        kop_ensure_master_table($pdo, $tableName);
        $projectStructure['data'] = kop_tradeorg_keep_data($data);
        $jsonData = json_encode($projectStructure);
    } elseif ($category === 'wiki') {
        $tableName = $wiki_table;
    } else {
        // Companies and locations were handled by the v2 branch above; an unknown kind has no table.
        echo json_encode(['success' => false, 'error' => "Unknown project category: $category"]);
        exit;
    }

    try {
        if ($category === 'wiki') {
            // Specialized logic for wiki_master
            $wikiData = is_string($data) ? json_decode($data, true) : $data;
            $slug = $wikiData['slug'] ?? strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $projectName)));
            
            $sql = "INSERT INTO wiki_master 
                        (slug, program_name, city_state, organization, program_type, years_active, json_data, markdown, last_updated_by, updated_at)
                    VALUES (:slug, :program_name, :city_state, :organization, :program_type, :years_active, :json_data, :markdown, :last_updated_by, NOW())
                    ON DUPLICATE KEY UPDATE 
                        program_name = VALUES(program_name),
                        city_state = VALUES(city_state),
                        organization = VALUES(organization),
                        program_type = VALUES(program_type),
                        years_active = VALUES(years_active),
                        json_data = VALUES(json_data),
                        markdown = VALUES(markdown),
                        last_updated_by = VALUES(last_updated_by),
                        updated_at = NOW()";
            
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':slug', $slug);
            $stmt->bindValue(':program_name', $projectName);
            $stmt->bindValue(':city_state', $wikiData['cityState'] ?? null);
            $stmt->bindValue(':organization', $wikiData['ownerName'] ?? $wikiData['organization'] ?? null);
            $stmt->bindValue(':program_type', $wikiData['programType'] ?? null);
            $stmt->bindValue(':years_active', $wikiData['yearsActive'] ?? null);
            $stmt->bindValue(':json_data', json_encode($wikiData));
            $stmt->bindValue(':markdown', $wikiData['markdown'] ?? null);
            $stmt->bindValue(':last_updated_by', 'admin');
            $stmt->execute();
        } else {
            // Standard logic for facilities, referrers, locations
            $sql = "INSERT INTO {$tableName} (unique_name, json_data, updated_at)
                    VALUES (:unique_name, :json_data_insert, NOW())
                    ON DUPLICATE KEY UPDATE json_data = :json_data_update, updated_at = NOW()";

            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(':unique_name', $projectName);
            $stmt->bindValue(':json_data_insert', $jsonData);
            $stmt->bindValue(':json_data_update', $jsonData);
            $stmt->execute();
        }
        
        // Copy a referrer project's consultants into their states' location profiles (state-level
        // referrer data, kept in locations_master). Its facilities are not copied: places' facilities
        // are memberships in the v2 tables.
        $locationUpdates = [];
        if ($category === 'referrers') {
            $locationUpdates = updateLocationProjectsFromSave($pdo, $projectName, array_diff_key($data, ['facilities' => true]), $category);
        }

        $message = "Project '$projectName' saved to {$tableName}";
        if (!empty($locationUpdates)) {
            $message .= ". Location projects updated: " . implode(', ', $locationUpdates);
        }
        echo json_encode([
            'success' => true,
            'message' => $message,
            'locationProjectsUpdated' => $locationUpdates,
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to save to database: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Handle inspect-project action - returns raw database contents for debugging
if ($action === 'inspect-project') {
    try {
        if (!$projectName) {
            echo json_encode(['success' => false, 'error' => 'Project name required']);
            exit;
        }
        
        // Try case-insensitive match: the record in its old row shape, from the v2 tables
        require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
        $row = kop_v2_pdo_master_row_by_name($pdo, kop_v2_detect_prefix($pdo), $projectName);
        if ($row) $row['updated_at'] = null;
        
        if (!$row) {
            // Try referrers table
            $stmt = $pdo->prepare("SELECT unique_name, json_data, updated_at FROM referrers_master WHERE UPPER(unique_name) = UPPER(:projectName)");
            $stmt->execute([':projectName' => $projectName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($row) {
            $jsonData = json_decode($row['json_data'], true);
            echo json_encode([
                'success' => true,
                'storedName' => $row['unique_name'],
                'updatedAt' => $row['updated_at'],
                'jsonDataKeys' => $jsonData ? array_keys($jsonData) : null,
                'hasDataKey' => isset($jsonData['data']),
                'dataKeys' => isset($jsonData['data']) ? array_keys($jsonData['data']) : null,
                'facilitiesCount' => isset($jsonData['data']['facilities']) ? count($jsonData['data']['facilities']) : (isset($jsonData['facilities']) ? count($jsonData['facilities']) : 0),
                'referrersCount' => isset($jsonData['data']['referrerConsultants']) ? count($jsonData['data']['referrerConsultants']) : 0,
                'category' => $jsonData['category'] ?? 'unknown',
                'rawJson' => $jsonData
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Project not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Unknown action
echo json_encode([
    'success' => false,
    'error' => "Unknown action: $action"
]);
