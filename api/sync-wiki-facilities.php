<?php
/**
 * Wiki ↔ Facilities Sync API
 *
 * Bidirectional sync between wiki_master / wiki_submissions and facilities_master.
 * Can be required as a library (exposes kop_merge_wiki_into_facility and
 * kop_facility_to_wiki_json) or called directly as an HTTP endpoint.
 *
 * Directions:
 *   facility_to_wiki   – Push a facility record into wiki_submissions for review.
 *   wiki_to_facility   – Merge an approved wiki_master entry into the matching
 *                        facilities_master record (blank-field-only, no overwrite).
 *
 * Actions:
 *   GET  ?action=status                               Overview of sync coverage.
 *   POST {action:"facility_to_wiki", unique_name:"…"} Push one facility.
 *   POST {action:"bulk_facility_to_wiki",limit:N}     Push up to N facilities that
 *                                                     have no pending/published wiki
 *                                                     entry yet.
 *   POST {action:"wiki_to_facility", wiki_id:N}       Merge one wiki_master row.
 *   POST {action:"bulk_wiki_to_facility"}              Merge all approved wiki rows.
 */

// When included by another script the helper functions are all that's needed.
// Only run the HTTP endpoint logic when called directly.
define('KOP_SYNC_WIKI_FACILITIES_LOADED', true);

if (!defined('KOP_DB_CONFIG_LOADED')) {
    // config.php was not loaded by the including file yet
    require_once __DIR__ . '/config.php';
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a wiki_submissions json_data blob from a facility sub-array.
 */
function kop_facility_to_wiki_json(array $facility, array $operator): array {
    $ident  = $facility['identification'] ?? [];
    $period = $facility['operatingPeriod'] ?? [];
    $det    = $facility['facilityDetails'] ?? [];

    // years_active string: prefer text, fall back to startYear–endYear
    $yearsActive = $period['text'] ?? '';
    if (!$yearsActive && !empty($period['startYear'])) {
        $yearsActive = $period['startYear'];
        if (!empty($period['endYear'])) {
            $yearsActive .= '–' . $period['endYear'];
        }
    }

    return [
        'programName'   => $ident['name'] ?? $ident['currentName'] ?? '',
        'cityState'     => $facility['location'] ?? '',
        'organization'  => $operator['name'] ?? '',
        'programType'   => $det['type'] ?? '',
        'yearsActive'   => $yearsActive,
        'status'        => $period['status'] ?? '',
        'capacity'      => $det['capacity'] ?? '',
        'ageRange'      => $det['ageRange'] ?? [],
        'gender'        => $det['gender'] ?? '',
        'tuition'       => $det['tuition'] ?? '',
        'avgStay'       => $det['avgStay'] ?? '',
        'address'       => $facility['address'] ?? '',
        'accreditations'=> $facility['accreditations'] ?? [],
        'staffMembers'  => $facility['staffMembers'] ?? [],
        'pastNames'     => array_merge(
            $ident['pastNames'] ?? [],
            $ident['otherNames'] ?? []
        ),
        '_source'       => 'facility_sync',
    ];
}

/**
 * An approved wiki submission onto its facility record, read from the
 * submission itself (wiki_master, which approval also writes, does not exist
 * on the live database: before 2026-10-13 this merge never ran there). Its
 * facility link, its Reddit page as the staff citation. Staff go on once per
 * submission (logged; kop_wiki_staff_undo() takes them off).
 *
 * @return array{merged:bool, reason:string, staff_added?:int}
 */
function kop_wiki_merge_submission(PDO $pdo, int $id): array {
    $st = $pdo->prepare('SELECT id, program_name, city_state, organization, program_type, years_active, json_data,
        submission_notes, facility_unique_name FROM wiki_submissions WHERE id = ?');
    $st->execute([$id]);
    $sub = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sub) return ['merged' => false, 'reason' => 'submission gone'];
    $entry = $sub;
    $entry['__submission_id'] = $id;
    $entry['__source_url'] = kop_wiki_staff_source_url($sub);
    $result = kop_merge_wiki_into_facility($pdo, $entry);
    if ($result['merged']) error_log("Wiki->Facility merge for '{$sub['program_name']}': {$result['reason']}");
    return $result;
}

/** The wiki entry's people as the record lists them (inc/facility-store.php kop_facility_person_list()). */
function kop_wiki_staff_entries(array $staffMembers, string $sourceUrl = ''): array {
    $out = [];
    foreach ($staffMembers as $s) {
        if (!is_array($s)) continue;
        $name = trim(preg_replace('/\s+/', ' ', (string)($s['name'] ?? '')));
        if ($name === '') continue;
        $role = trim(preg_replace('/\s+/', ' ', (string)($s['role'] ?? '')));
        // Some wiki "roles" are a sentence of the write-up ("reported to feel no remorse ...
        // ([source](...))"): not a title, so the person goes on with no role.
        if (mb_strlen($role) > 60 || preg_match('/\]\(|https?:\/\//', $role)) $role = '';
        // The record has no "former" field; its entries say so in the role, as "Former ...".
        if (!empty($s['isFormer']) && $role !== '' && stripos($role, 'former') === false) $role = 'Former ' . $role;
        $past = [];
        foreach ((array)($s['previousRoles'] ?? []) as $p) {
            $t = is_array($p) ? implode(', ', array_filter([trim((string)($p['role'] ?? '')), trim((string)($p['employer'] ?? ''))])) : trim((string)$p);
            if ($t !== '') $past[] = $t;
        }
        $entry = ['name' => $name, 'role' => $role, 'pastJobs' => implode('; ', $past), 'source' => 'r/troubledteens wiki'];
        if ($sourceUrl !== '') $entry['sourceUrl'] = $sourceUrl;
        $out[] = $entry;
    }
    return $out;
}

/** A name's letters and digits, for "already listed" (same rule as kop_facility_staff_dedupe()). */
function kop_wiki_staff_fold($name): string {
    $v = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower((string)$name));
    return trim(preg_replace('/\s+/', ' ', (string)$v));
}

/** submission id => {record, program, staff[], at}: what each approval put on a record. */
function kop_wiki_staff_log(): array {
    // Outside WordPress, or in a dry run (api/backfill-wiki-staff.php), the log lives in memory.
    if (!function_exists('get_option') || !empty($GLOBALS['kop_wiki_staff_dry'])) return $GLOBALS['kop_wiki_staff_log'] ?? [];
    $log = get_option('kop_wiki_staff_log', []);
    return is_array($log) ? $log : [];
}

function kop_wiki_staff_log_save(array $log): void {
    if (!function_exists('update_option') || !empty($GLOBALS['kop_wiki_staff_dry'])) {
        $GLOBALS['kop_wiki_staff_log'] = $log;
        return;
    }
    update_option('kop_wiki_staff_log', $log, false);
}

/** The Reddit page a submission came from, for the citation ('' when unknown). */
function kop_wiki_staff_source_url(array $submission): string {
    if (!function_exists('kop_wiki_upd_page_of') && function_exists('get_stylesheet_directory')) {
        $f = dirname(__DIR__) . '/inc/wiki-updates.php';
        if (is_readable($f)) require_once $f;
    }
    $page = function_exists('kop_wiki_upd_page_of') ? (string)kop_wiki_upd_page_of($submission) : '';
    return $page !== '' ? 'https://www.reddit.com/r/troubledteens/wiki/' . ltrim($page, '/') : '';
}

/**
 * Take back the staff an approval put on a record: the entries it logged
 * that are still there as it wrote them (same name, role and wiki source).
 * Entries someone has edited since stay. Returns how many were taken off.
 */
function kop_wiki_staff_undo(PDO $pdo, int $submissionId): int {
    $log = kop_wiki_staff_log();
    if (empty($log[$submissionId])) return 0;
    $u = $log[$submissionId];
    require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
    $prefix = kop_v2_detect_prefix($pdo);
    $row = kop_v2_pdo_master_row_by_name($pdo, $prefix, (string)$u['record']);
    $removed = 0;
    if ($row) {
        $project = json_decode($row['json_data'], true);
        $data = &$project['data'];
        $keys = [];
        foreach ((array)$u['staff'] as $e) $keys[kop_wiki_staff_fold($e['name']) . '|' . kop_wiki_staff_fold($e['role'])] = true;
        $strip = function (array &$fac) use (&$keys, &$removed) {
            $list = (array)($fac['staff']['notableStaff'] ?? []);
            $keep = [];
            foreach ($list as $e) {
                $k = is_array($e) ? kop_wiki_staff_fold($e['name'] ?? '') . '|' . kop_wiki_staff_fold($e['role'] ?? '') : '';
                if ($k !== '' && isset($keys[$k]) && ($e['source'] ?? '') === 'r/troubledteens wiki') {
                    unset($keys[$k]);
                    $removed++;
                    continue;
                }
                $keep[] = $e;
            }
            if (count($keep) !== count($list)) $fac['staff']['notableStaff'] = $keep;
        };
        if (!empty($data['facilities']) && is_array($data['facilities'])) {
            foreach (array_keys($data['facilities']) as $i) $strip($data['facilities'][$i]);
        } elseif (isset($data['facility']) && is_array($data['facility'])) {
            $strip($data['facility']);
        }
        unset($data);
        if ($removed) kop_v2_save_legacy_row($pdo, $prefix, (string)$row['unique_name'], $project);
    }
    unset($log[$submissionId]);
    kop_wiki_staff_log_save($log);
    return $removed;
}

/**
 * Merge wiki data into the matching facility in facilities_master.
 * Only fills fields that are blank / null / empty – never overwrites.
 *
 * Target resolution:
 *   1. If the wiki row carries a facility_unique_name link (set via the Data
 *      Manager / picker / auto-link), resolve the target by that link.
 *   2. Otherwise (no link yet), fall back to matching the organization name
 *      against facilities_master.unique_name (legacy behavior).
 *
 * Within the matched record the fill target is:
 *   - the sub-facility matching program_name (or the only one) for operator
 *     records that carry data.facilities[]; or
 *   - data.facility for a single-facility record (e.g. a promoted
 *     __facility_ref linked at the facility level).
 *
 * @return array{merged:bool, reason:string}
 */
function kop_merge_wiki_into_facility(PDO $pdo, array $wiki): array {
    $orgName     = trim($wiki['organization'] ?? '');
    $programName = trim($wiki['program_name'] ?? '');
    $linkName    = trim($wiki['facility_unique_name'] ?? '');

    // 1. Resolve the target row — prefer the explicit link. Once admin saves
    // write the v2 tables, the row comes from there and is saved back there.
    require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
    $v2_prefix = kop_v2_detect_prefix($pdo);
    $v2_writes = kop_v2_writes_active($pdo, $v2_prefix);
    $row = null;
    $matchedBy = '';
    if ($linkName !== '') {
        if ($v2_writes) {
            $row = kop_v2_pdo_master_row_by_name($pdo, $v2_prefix, $linkName);
        } else {
            $stmt = $pdo->prepare(
                "SELECT id, unique_name, json_data FROM facilities_master
                 WHERE LOWER(unique_name) = LOWER(:u) LIMIT 1"
            );
            $stmt->execute([':u' => $linkName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row) {
            // A link is set but points nowhere — don't silently name-match to a
            // different record; surface it instead.
            return ['merged' => false, 'reason' => "linked record '$linkName' not found in facilities_master"];
        }
        $matchedBy = 'link';
    } else {
        // 2. No link yet — fall back to matching by organization name.
        if (!$orgName || !$programName) {
            return ['merged' => false, 'reason' => 'no facility link, and missing organization/program_name to match by name'];
        }
        if ($v2_writes) {
            $row = kop_v2_pdo_master_row_by_name($pdo, $v2_prefix, $orgName);
        } else {
            $stmt = $pdo->prepare(
                "SELECT id, unique_name, json_data FROM facilities_master
                 WHERE LOWER(unique_name) = LOWER(:org) LIMIT 1"
            );
            $stmt->execute([':org' => $orgName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row) {
            return ['merged' => false, 'reason' => "no facilities_master row for organization '$orgName' (and no link)"];
        }
        $matchedBy = 'name';
    }

    $project = json_decode($row['json_data'], true);
    if (!is_array($project)) {
        return ['merged' => false, 'reason' => 'facilities_master json_data malformed'];
    }
    if (!isset($project['data']) || !is_array($project['data'])) {
        $project['data'] = [];
    }

    $wikiJson = is_string($wiki['json_data'] ?? '')
        ? json_decode($wiki['json_data'], true)
        : ($wiki['json_data'] ?? []);
    $wikiJson = is_array($wikiJson) ? $wikiJson : [];

    // 2b. Resolve which node inside the record to fill.
    $data = &$project['data'];
    if (isset($data['facilities']) && is_array($data['facilities']) && count($data['facilities']) > 0) {
        // Operator with sub-facilities: match by program_name, or use the only one.
        $targetIndex = null;
        foreach ($data['facilities'] as $idx => $f) {
            $facName = $f['identification']['name'] ?? $f['identification']['currentName'] ?? '';
            if ($programName !== '' && strcasecmp(trim($facName), $programName) === 0) {
                $targetIndex = $idx;
                break;
            }
        }
        if ($targetIndex === null && count($data['facilities']) === 1) {
            $targetIndex = 0;
        }
        if ($targetIndex === null) {
            return ['merged' => false,
                'reason' => "matched '{$row['unique_name']}' by $matchedBy, but sub-facility '$programName' not found inside it"];
        }
        $fac = &$data['facilities'][$targetIndex];
    } elseif (isset($data['facility']) && is_array($data['facility'])) {
        // Single-facility record (promoted __facility_ref linked at facility level).
        $fac = &$data['facility'];
    } else {
        return ['merged' => false, 'reason' => "matched '{$row['unique_name']}' by $matchedBy, but it has no facility data to fill"];
    }

    // Ensure sub-structures exist.
    if (!isset($fac['identification']) || !is_array($fac['identification']))   $fac['identification'] = [];
    if (!isset($fac['facilityDetails']) || !is_array($fac['facilityDetails'])) $fac['facilityDetails'] = [];
    if (!isset($fac['operatingPeriod']) || !is_array($fac['operatingPeriod'])) $fac['operatingPeriod'] = [];
    if (!array_key_exists('location', $fac)) $fac['location'] = '';

    $det    = &$fac['facilityDetails'];
    $period = &$fac['operatingPeriod'];
    $ident  = &$fac['identification'];

    $changed = false;
    $fill = function(&$target, $value) use (&$changed) {
        if (($target === null || $target === '' || $target === [])
            && $value !== null && $value !== '' && $value !== []) {
            $target = $value;
            $changed = true;
        }
    };

    // --- location / identification
    $fill($fac['location'], $wiki['city_state'] ?? '');
    $fill($ident['name'],   $programName);

    // --- operator (only when the record actually carries an operator node)
    if (isset($data['operator']) && is_array($data['operator'])) {
        $fill($data['operator']['name'], $orgName);
    }

    // --- facility details
    $fill($det['type'],     $wiki['program_type'] ?? $wikiJson['programType'] ?? '');
    $fill($det['capacity'], $wikiJson['capacity'] ?? '');
    $fill($det['gender'],   $wikiJson['gender'] ?? '');
    $fill($det['tuition'],  $wikiJson['tuition'] ?? '');
    $fill($det['avgStay'],  $wikiJson['avgStay'] ?? '');

    if (empty($det['ageRange']) && !empty($wikiJson['ageRange'])) {
        $det['ageRange'] = $wikiJson['ageRange'];
        $changed = true;
    }

    // --- operating period
    $fill($period['text'],   $wiki['years_active'] ?? '');
    $fill($period['status'], $wikiJson['status'] ?? '');

    if (empty($period['startYear']) && !empty($wiki['years_active'])
        && preg_match('/(\d{4})/', $wiki['years_active'], $m)) {
        $period['startYear'] = (int)$m[1];
        $changed = true;
    }

    // --- staff: onto the record, cited to the wiki page; a name the record
    // already lists (any role) is left as it is. What was added is logged per
    // submission, so taking the approval back takes it off (kop_wiki_staff_undo()).
    $addedStaff = [];
    $subId = (int)($wiki['__submission_id'] ?? 0);
    if (!empty($wikiJson['staffMembers']) && is_array($wikiJson['staffMembers'])
        && !($subId && isset(kop_wiki_staff_log()[$subId]))) {
        if (!isset($fac['staff']) || !is_array($fac['staff'])) $fac['staff'] = [];
        foreach (['administrator', 'notableStaff'] as $l) {
            if (!isset($fac['staff'][$l]) || !is_array($fac['staff'][$l])) $fac['staff'][$l] = [];
        }
        $have = [];
        foreach (['administrator', 'notableStaff'] as $l) {
            foreach ($fac['staff'][$l] as $e) {
                $k = kop_wiki_staff_fold(is_array($e) ? ($e['name'] ?? '') : $e);
                if ($k !== '') $have[$k] = true;
            }
        }
        foreach (kop_wiki_staff_entries($wikiJson['staffMembers'], (string)($wiki['__source_url'] ?? '')) as $entry) {
            $k = kop_wiki_staff_fold($entry['name']);
            if ($k === '' || isset($have[$k])) continue;
            $have[$k] = true;
            $fac['staff']['notableStaff'][] = $entry;
            $addedStaff[] = $entry;
            $changed = true;
        }
    }
    // --- accreditations: the record keeps them as {current, past}
    if (!empty($wikiJson['accreditations']) && is_array($wikiJson['accreditations'])) {
        if (!isset($fac['accreditations']) || !is_array($fac['accreditations'])) $fac['accreditations'] = [];
        if (!isset($fac['accreditations']['current']) || !is_array($fac['accreditations']['current'])) $fac['accreditations']['current'] = [];
        $existing = array_map('strtolower', array_merge($fac['accreditations']['current'], (array)($fac['accreditations']['past'] ?? [])));
        foreach ($wikiJson['accreditations'] as $acc) {
            if (is_string($acc) && trim($acc) !== '' && !in_array(strtolower(trim($acc)), $existing, true)) {
                $fac['accreditations']['current'][] = trim($acc);
                $existing[] = strtolower(trim($acc));
                $changed = true;
            }
        }
    }

    if (!$changed) {
        return ['merged' => false, 'reason' => "matched '{$row['unique_name']}' by $matchedBy; no blank fields to fill"];
    }

    if ($v2_writes) {
        kop_v2_save_legacy_row($pdo, $v2_prefix, (string)$row['unique_name'], $project);
        if ($subId && $addedStaff) {
            $log = kop_wiki_staff_log();
            $log[$subId] = ['record' => (string)$row['unique_name'], 'program' => $programName, 'staff' => $addedStaff, 'at' => gmdate('c')];
            kop_wiki_staff_log_save($log);
        }
        return ['merged' => true, 'staff_added' => count($addedStaff), 'reason' => "blank fields filled, " . count($addedStaff) . " staff added (matched by $matchedBy)"];
    }

    $updateStmt = $pdo->prepare(
        "UPDATE facilities_master SET json_data = :json, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
    );
    $updateStmt->execute([
        ':json' => json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':id'   => $row['id'],
    ]);
    if (function_exists('kop_facility_v2_request_sync')) {
        kop_facility_v2_request_sync();
    }

    return ['merged' => true, 'reason' => "blank fields filled (matched by $matchedBy)"];
}

// ---------------------------------------------------------------------------
// HTTP endpoint — only active when this file is the entry point
// ---------------------------------------------------------------------------
if (basename(__FILE__) !== basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    return; // loaded as a library; skip HTTP handling
}

header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---------------------------------------------------------------------------
// GET – status overview
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'status';

    if ($action === 'status') {
        try {
            $wikiTotal     = (int)$pdo->query("SELECT COUNT(*) FROM wiki_master")->fetchColumn();
            $wikiPublished = (int)$pdo->query(
                "SELECT COUNT(*) FROM wiki_submissions WHERE status IN ('approved','published')"
            )->fetchColumn();
            $facTotal      = (int)$pdo->query(
                "SELECT COUNT(*) FROM facilities_master WHERE JSON_SEARCH(json_data,'one','__facility_ref') IS NULL"
            )->fetchColumn();

            // Facilities that already have a matching (non-rejected/deleted) wiki submission
            $covered = (int)$pdo->query(
                "SELECT COUNT(DISTINCT ws.organization)
                 FROM wiki_submissions ws
                 JOIN facilities_master fm ON LOWER(fm.unique_name) = LOWER(ws.organization)
                 WHERE ws.status NOT IN ('rejected','deleted')"
            )->fetchColumn();

            echo json_encode([
                'success'             => true,
                'wiki_master_total'   => $wikiTotal,
                'wiki_approved_total' => $wikiPublished,
                'facilities_total'    => $facTotal,
                'facilities_with_wiki'=> $covered,
                'facilities_without_wiki' => max(0, $facTotal - $covered),
            ]);
        } catch (PDOException $e) {
            error_log('sync-wiki-facilities error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Unknown GET action '$action'. Use ?action=status"]);
    exit;
}

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Every POST action mass-writes wiki_submissions and/or facilities_master —
// strictly an admin tool. (config.php boots WordPress, so the check works;
// fail closed if it somehow didn't.)
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$action = $input['action'] ?? '';

try {

    // -----------------------------------------------------------------------
    // facility_to_wiki – push one facility's sub-facilities into wiki_submissions
    // -----------------------------------------------------------------------
    if ($action === 'facility_to_wiki') {
        $uniqueName = trim($input['unique_name'] ?? '');
        if (!$uniqueName) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'unique_name is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT json_data FROM facilities_master WHERE unique_name = ? LIMIT 1");
        $stmt->execute([$uniqueName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Facility '$uniqueName' not found"]);
            exit;
        }

        $project  = json_decode($row['json_data'], true);
        $operator = $project['data']['operator'] ?? [];
        $facs     = $project['data']['facilities'] ?? [];

        if (empty($facs)) {
            echo json_encode(['success' => true, 'created' => 0, 'skipped' => 0, 'message' => 'No sub-facilities found']);
            exit;
        }

        $created = 0;
        $skipped = 0;
        $details = [];

        foreach ($facs as $fac) {
            // Skip __facility_ref stubs
            if (!empty($fac['__facility_ref'])) { $skipped++; continue; }

            $facName = trim(
                $fac['identification']['name']
                ?? $fac['identification']['currentName']
                ?? ''
            );
            if (!$facName) { $skipped++; continue; }

            // Check for existing non-rejected/deleted wiki entry
            $existCheck = $pdo->prepare(
                "SELECT id FROM wiki_submissions
                 WHERE LOWER(program_name) = LOWER(?)
                   AND status NOT IN ('rejected','deleted')
                 LIMIT 1"
            );
            $existCheck->execute([$facName]);
            if ($existCheck->fetchColumn()) {
                $skipped++;
                $details[] = "skipped (existing): $facName";
                continue;
            }

            $wikiJson = kop_facility_to_wiki_json($fac, $operator);

            $period      = $fac['operatingPeriod'] ?? [];
            $det         = $fac['facilityDetails'] ?? [];
            $yearsActive = $wikiJson['yearsActive'];
            $cityState   = $fac['location'] ?? '';
            $orgName     = $operator['name'] ?? $uniqueName;
            $progType    = $det['type'] ?? '';

            $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $facName)));

            $insertStmt = $pdo->prepare(
                "INSERT INTO wiki_submissions
                    (program_name, city_state, organization, program_type, years_active,
                     json_data, status, submitted_by, submission_notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'draft', 'facility_sync',
                         'Auto-generated from facilities_master record.',
                         CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
            );
            $insertStmt->execute([
                $facName,
                $cityState,
                $orgName,
                $progType,
                $yearsActive,
                json_encode($wikiJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $created++;
            $details[] = "created: $facName";
        }

        echo json_encode([
            'success' => true,
            'created' => $created,
            'skipped' => $skipped,
            'details' => $details,
        ]);
        exit;
    }

    // -----------------------------------------------------------------------
    // bulk_facility_to_wiki – push all facilities that lack a wiki entry
    // -----------------------------------------------------------------------
    if ($action === 'bulk_facility_to_wiki') {
        $limit = min((int)($input['limit'] ?? 50), 200);

        // Fetch facilities whose unique_name has no matching wiki_submissions org
        $stmt = $pdo->prepare(
            "SELECT unique_name, json_data
             FROM facilities_master
             WHERE JSON_SEARCH(json_data, 'one', '__facility_ref') IS NULL
               AND unique_name NOT IN (
                   SELECT DISTINCT organization FROM wiki_submissions
                   WHERE status NOT IN ('rejected','deleted')
               )
             LIMIT :lim"
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalCreated = 0;
        $totalSkipped = 0;
        $processed    = [];

        foreach ($rows as $row) {
            $project  = json_decode($row['json_data'], true);
            if (!is_array($project)) { $totalSkipped++; continue; }

            $operator = $project['data']['operator'] ?? [];
            $facs     = $project['data']['facilities'] ?? [];

            foreach ($facs as $fac) {
                if (!empty($fac['__facility_ref'])) { $totalSkipped++; continue; }

                $facName = trim(
                    $fac['identification']['name']
                    ?? $fac['identification']['currentName']
                    ?? ''
                );
                if (!$facName) { $totalSkipped++; continue; }

                $existCheck = $pdo->prepare(
                    "SELECT id FROM wiki_submissions
                     WHERE LOWER(program_name) = LOWER(?)
                       AND status NOT IN ('rejected','deleted') LIMIT 1"
                );
                $existCheck->execute([$facName]);
                if ($existCheck->fetchColumn()) { $totalSkipped++; continue; }

                $wikiJson    = kop_facility_to_wiki_json($fac, $operator);
                $det         = $fac['facilityDetails'] ?? [];
                $yearsActive = $wikiJson['yearsActive'];
                $cityState   = $fac['location'] ?? '';
                $orgName     = $operator['name'] ?? $row['unique_name'];
                $progType    = $det['type'] ?? '';

                $insertStmt = $pdo->prepare(
                    "INSERT INTO wiki_submissions
                        (program_name, city_state, organization, program_type, years_active,
                         json_data, status, submitted_by, submission_notes, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'draft', 'facility_sync',
                             'Auto-generated from facilities_master record.',
                             CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
                );
                $insertStmt->execute([
                    $facName, $cityState, $orgName, $progType, $yearsActive,
                    json_encode($wikiJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

                $totalCreated++;
                $processed[] = $facName;
            }
        }

        echo json_encode([
            'success'       => true,
            'facilities_scanned' => count($rows),
            'created'       => $totalCreated,
            'skipped'       => $totalSkipped,
            'created_names' => $processed,
        ]);
        exit;
    }

    // -----------------------------------------------------------------------
    // wiki_to_facility – merge one wiki_master entry into facilities_master
    // -----------------------------------------------------------------------
    if ($action === 'wiki_to_facility') {
        $wikiId = (int)($input['wiki_id'] ?? 0);
        if (!$wikiId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'wiki_id is required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM wiki_master WHERE id = ? LIMIT 1");
        $stmt->execute([$wikiId]);
        $wiki = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wiki) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "wiki_master row $wikiId not found"]);
            exit;
        }

        $result = kop_merge_wiki_into_facility($pdo, $wiki);
        echo json_encode(['success' => true] + $result);
        exit;
    }

    // -----------------------------------------------------------------------
    // bulk_wiki_to_facility – merge all wiki_master entries
    // -----------------------------------------------------------------------
    if ($action === 'bulk_wiki_to_facility') {
        $stmt = $pdo->query("SELECT * FROM wiki_master ORDER BY updated_at DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $merged  = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($rows as $wiki) {
            $result = kop_merge_wiki_into_facility($pdo, $wiki);
            if ($result['merged']) {
                $merged++;
            } else {
                $skipped++;
                if (strpos($result['reason'], 'not found') !== false
                    || strpos($result['reason'], 'malformed') !== false) {
                    $errors[] = ($wiki['program_name'] ?? '?') . ': ' . $result['reason'];
                }
            }
        }

        echo json_encode([
            'success'       => true,
            'wiki_rows'     => count($rows),
            'merged'        => $merged,
            'skipped'       => $skipped,
            'no_match_errors' => array_slice($errors, 0, 50),
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => "Unknown action '$action'. Valid: facility_to_wiki, bulk_facility_to_wiki, wiki_to_facility, bulk_wiki_to_facility",
    ]);

} catch (PDOException $e) {
    error_log('sync-wiki-facilities error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
}
