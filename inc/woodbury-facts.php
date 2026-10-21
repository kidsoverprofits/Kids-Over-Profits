<?php
/**
 * Woodbury Reports facts: what the newsletter says about each program, added
 * to its facility record after review.
 *
 * scripts/woodbury-facts.py reads the facts pulled from every issue (staff and
 * their roles over the years, incidents, openings, closings, renames, owners,
 * moves, size, ages), checks each quote against the issue text, matches the
 * program to facilities_v2 and leaves out what the record already holds. Its
 * facts.json is copied to ~/kop-import/woodbury/ beside the mention
 * candidates (inc/woodbury-mentions.php).
 *
 * KOP Tools > Woodbury Facts shows one card per facility with every
 * proposed addition, the quote it rests on and a link to the issue page.
 * "Add checked to record" writes them into facilities_v2 at once (the
 * /facility/ page shows them, and the network map picks up new staff, past
 * operators and past names on its next build). Each addition cites the issue.
 * Undo takes back exactly what was added. Programs with no record can be
 * pointed at an existing record or created from the card.
 *
 * Proposals live in {prefix}kop_woodbury_facts, keyed by the build's key, so
 * a rebuild adds new ones and never undoes a decision.
 */

if (!defined('ABSPATH')) {
    exit;
}

// The KOP Tools look (inc/admin-kit.php): its CSS and JS load on this screen.
require_once __DIR__ . '/admin-kit.php';
require_once __DIR__ . '/staff-former.php';   // kop_staff_role_with_former(): "Former" only when the source says they left, never "current"
require_once __DIR__ . '/record-writeups.php'; // approved items are held for the record's write-up (kop_fact_hold_on())
require_once dirname(__DIR__) . '/api/lib-record-dedupe.php'; // one person, name and event per record, whichever source says it
add_filter('kop_admin_kit_pages', function ($pages) { $pages[] = 'kop-woodbury-facts'; return $pages; });

define('KOP_WOODBURY_FACTS_DB_VERSION', '3');

function kop_wbf_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_woodbury_facts';
}

function kop_wbf_ensure_table() {
    if (get_option('kop_woodbury_facts_db') === KOP_WOODBURY_FACTS_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = kop_wbf_table();
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pkey VARCHAR(32) NOT NULL,
        facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        program VARCHAR(255) NOT NULL DEFAULT '',
        program_as_written VARCHAR(255) NOT NULL DEFAULT '',
        place VARCHAR(120) NOT NULL DEFAULT '',
        match_kind VARCHAR(12) NOT NULL DEFAULT '',
        match_note TEXT NULL,
        alternatives TEXT NULL,
        grp VARCHAR(12) NOT NULL DEFAULT '',
        op VARCHAR(16) NOT NULL DEFAULT '',
        path VARCHAR(64) NOT NULL DEFAULT '',
        value LONGTEXT NULL,
        label TEXT NULL,
        conflict TEXT NULL,
        current_val TEXT NULL,
        extra LONGTEXT NULL,
        evidence LONGTEXT NULL,
        found TINYINT(1) NOT NULL DEFAULT 0,
        preselect TINYINT(1) NOT NULL DEFAULT 0,
        auto TINYINT(1) NOT NULL DEFAULT 0,
        issue_date VARCHAR(7) NOT NULL DEFAULT '',
        ya TINYINT(1) NOT NULL DEFAULT 0,
        ya_why TEXT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        applied LONGTEXT NULL,
        applied_fid BIGINT UNSIGNED NOT NULL DEFAULT 0,
        reviewed_by VARCHAR(60) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY pkey (pkey),
        KEY status_fac (status, facility_id),
        KEY program (program(80))
    ) {$charset};");
    update_option('kop_woodbury_facts_db', KOP_WOODBURY_FACTS_DB_VERSION);
}

function kop_wbf_path() {
    return (function_exists('kop_wb_pending_dir') ? kop_wb_pending_dir() : dirname(rtrim(ABSPATH, '/')) . '/kop-import/woodbury') . '/facts.json';
}

/**
 * Load a new facts.json. New proposals are added, waiting ones take the
 * build's latest match and text, decided ones are left alone, and waiting
 * ones the build no longer makes are marked 'gone'.
 */
function kop_wbf_sync($force = false) {
    $path = kop_wbf_path();
    if (!is_readable($path)) {
        return null;
    }
    $md5 = md5_file($path);
    if (!$force && get_option('kop_woodbury_facts_md5') === $md5) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data) || !isset($data['proposals']) || !is_array($data['proposals'])) {
        return null;
    }
    global $wpdb;
    $table = kop_wbf_table();
    $existing = array();
    $edited = array();
    $manual = array();
    foreach ((array) $wpdb->get_results("SELECT pkey, status, extra FROM {$table}", ARRAY_A) as $r) {
        $existing[$r['pkey']] = $r['status'];
        if (strpos((string) $r['extra'], '"edited_by"') !== false) {
            $edited[$r['pkey']] = true;
        }
        // A person added by hand (kop_wbf_add_person), or an item put back from "No longer proposed".
        if (strpos((string) $r['extra'], '"manual"') !== false || strpos((string) $r['extra'], '"kept_by"') !== false) {
            $manual[$r['pkey']] = true;
        }
    }
    $now = current_time('mysql', true);
    $seen = array();
    $added = $updated = 0;
    foreach ($data['proposals'] as $p) {
        $pkey = preg_replace('/[^a-f0-9]/', '', (string) ($p['key'] ?? ''));
        if ($pkey === '') {
            continue;
        }
        $seen[$pkey] = true;
        $extra = array();
        foreach (array('person', 'career', 'note_line') as $k) {
            if (isset($p[$k])) {
                $extra[$k] = $p[$k];
            }
        }
        $row = array(
            'facility_id'        => (int) ($p['facility_id'] ?? 0),
            'program'            => mb_substr((string) ($p['program'] ?? ''), 0, 255),
            'program_as_written' => mb_substr((string) ($p['program_as_written'] ?? ''), 0, 255),
            'place'              => mb_substr((string) ($p['place'] ?? ''), 0, 120),
            'match_kind'         => substr(sanitize_key($p['match'] ?? ''), 0, 12),
            'match_note'         => (string) ($p['match_note'] ?? ''),
            'alternatives'       => wp_json_encode(array_values((array) ($p['alternatives'] ?? array()))),
            'grp'                => substr(sanitize_key($p['group'] ?? ''), 0, 12),
            'op'                 => substr(sanitize_key($p['op'] ?? ''), 0, 16),
            'path'               => substr(preg_replace('/[^A-Za-z.]/', '', (string) ($p['path'] ?? '')), 0, 64),
            'value'              => wp_json_encode($p['value'] ?? null),
            'label'              => (string) ($p['label'] ?? ''),
            'conflict'           => (string) ($p['conflict'] ?? ''),
            'current_val'        => (string) ($p['current'] ?? ''),
            'extra'              => wp_json_encode($extra),
            'evidence'           => wp_json_encode(array_values((array) ($p['evidence'] ?? array()))),
            'found'              => !empty($p['found']) ? 1 : 0,
            'preselect'          => !empty($p['preselect']) ? 1 : 0,
            'auto'               => !empty($p['auto']) ? 1 : 0,
            'issue_date'         => substr((string) ($p['issue_date'] ?? ''), 0, 7),
            'ya'                 => kop_wbf_ya_for((string) ($p['program'] ?? ''), !empty($p['young_adult'])),
            'ya_why'             => (string) ($p['young_adult_why'] ?? ''),
        );
        if (!isset($existing[$pkey])) {
            $row['pkey'] = $pkey;
            $row['status'] = 'pending';
            $row['created_at'] = $now;
            if ($wpdb->insert($table, $row) !== false) {
                $added++;
            }
        } elseif (isset($edited[$pkey])) {
            // Corrected by hand: the rebuild's reading does not replace it.
            continue;
        } elseif (in_array($existing[$pkey], array('pending', 'gone'), true)) {
            $row['status'] = 'pending';
            $wpdb->update($table, $row, array('pkey' => $pkey));
            $updated++;
        }
    }
    $gone = 0;
    foreach ($existing as $pkey => $status) {
        // A person added by hand (kop_wbf_add_person) is never in the build, and one put back stays put back: they stay.
        if ($status === 'pending' && !isset($seen[$pkey]) && !isset($manual[$pkey])) {
            $gone += (int) $wpdb->update($table, array('status' => 'gone'), array('pkey' => $pkey));
        }
    }
    update_option('kop_woodbury_facts_md5', $md5, false);
    return array('added' => $added, 'updated' => $updated, 'gone' => $gone);
}

/* ---- Changing a document ---------------------------------------------- */

/** "Dr. Jane M. Doe, PhD" -> "jane doe", as scripts/woodbury-facts.py keys people. */
function kop_wbf_person_key($name) {
    $s = strtolower(remove_accents((string) $name));
    $s = preg_replace('/\b(dr|mr|mrs|ms|rev|jr|sr|ii|iii|iv|phd|md|lcsw|lpc|ma|med|edd|psyd)\b\.?/', ' ', $s);
    $s = preg_replace('/[^a-z\s-]/', ' ', $s);
    $words = array_values(array_filter(preg_split('/[\s-]+/', $s), function ($w) { return strlen($w) > 1; }));
    return count($words) < 2 ? '' : $words[0] . ' ' . $words[count($words) - 1];
}

function &kop_wbf_ref(array &$doc, $path) {
    $ref = &$doc;
    foreach (explode('.', $path) as $part) {
        if (!isset($ref[$part]) || !is_array($ref)) {
            $ref[$part] = null;
        }
        $ref = &$ref[$part];
    }
    return $ref;
}

/** The value at a dotted path, or null, without creating anything. */
function kop_wbf_get(array $doc, $path) {
    foreach (explode('.', $path) as $part) {
        if (!is_array($doc) || !array_key_exists($part, $doc)) {
            return null;
        }
        $doc = $doc[$part];
    }
    return $doc;
}

function kop_wbf_row_value(array $r) {
    return json_decode((string) $r['value'], true);
}

function kop_wbf_evidence(array $r) {
    $e = json_decode((string) $r['evidence'], true);
    return is_array($e) ? $e : array();
}

/** "Woodbury Reports, May 2007 (#153), p. 20: <url>", the first source. */
function kop_wbf_cite(array $r) {
    $e = kop_wbf_evidence($r);
    if (!$e) {
        return 'Woodbury Reports';
    }
    $first = $e[0];
    if (!empty($first['cite'])) {
        return $first['cite'] . ': ' . $first['url'];
    }
    return 'Woodbury Reports, ' . $first['label'] . (!empty($first['number']) ? ' (' . $first['number'] . ')' : '')
        . ', p. ' . (int) $first['page'] . ': ' . $first['url'];
}

/**
 * One piece of evidence as the screen names it: "May 2007, p. 20", or another
 * source's own citation ("HEAL, staff list for X (archived 2012-05-17)").
 */
function kop_wbf_ev_label(array $e) {
    return !empty($e['cite']) ? (string) $e['cite'] : $e['label'] . ', p. ' . (int) $e['page'];
}

/**
 * Where a row came from: 'questions' for values settled on the questions page
 * (kop_q_wbf_write(), extra.via), else 'woodbury'. [key => label] in kop_wbf_origins().
 */
function kop_wbf_origin(array $r) {
    $extra = is_array($r['extra'] ?? null) ? $r['extra'] : (json_decode((string) ($r['extra'] ?? ''), true) ?: array());
    return ($extra['via'] ?? '') === 'questions' ? 'questions' : 'woodbury';
}

function kop_wbf_origins() {
    return array('woodbury' => 'Woodbury Reports', 'questions' => 'Questions page');
}

/** SQL for one origin's rows (the questions page writes extra as {"manual":1,"via":"questions",...}). */
function kop_wbf_origin_where($origin) {
    if ($origin === 'questions') return " AND extra LIKE '%\"via\":\"questions\"%'";
    if ($origin === 'woodbury') return " AND (extra IS NULL OR extra NOT LIKE '%\"via\":\"questions\"%')";
    return '';
}

/** The line a hand-added row carries: who added it, or the questions page's readers. */
function kop_wbf_manual_line(array $r, array $extra) {
    if (kop_wbf_origin($r) === 'questions') return 'From the questions page: readers\' answers, settled there.';
    return 'Added by hand by ' . ($extra['edited_by'] ?? '') . '.';
}

/** The source a record created from these items names (kop_wbc_source_note()). */
function kop_wbf_create_source(array $e) {
    $first = $e ? $e[0] : array('issue_id' => 0, 'label' => '', 'number' => '', 'page' => 0);
    $s = array('issue_id' => (int) ($first['issue_id'] ?? 0), 'issue_label' => (string) $first['label'],
        'issue_number' => (string) ($first['number'] ?? ''), 'pages' => (string) (int) $first['page']);
    if (!empty($first['cite'])) {
        $s['cite'] = (string) $first['cite'];
        $s['url'] = (string) $first['url'];
    }
    return $s;
}

/**
 * The first source as a staff entry keeps it: {source: "r/troubledteens wiki, page "X" (as of 2025-12-18)",
 * sourceUrl: "https://..."}; the Woodbury issue page as "Woodbury Reports, May 2007, p. 20".
 */
function kop_wbf_source_ref(array $r) {
    $e = kop_wbf_evidence($r);
    if (!$e) {
        return array();
    }
    $first = $e[0];
    $cite = !empty($first['cite']) ? (string) $first['cite']
        : 'Woodbury Reports, ' . $first['label'] . (!empty($first['number']) ? ' (' . $first['number'] . ')' : '') . ', p. ' . (int) $first['page'];
    return array('source' => $cite, 'sourceUrl' => (string) ($first['url'] ?? ''));
}

/** The line a structured change leaves in the notes, so the record says where it came from. */
function kop_wbf_source_line(array $r) {
    return $r['label'] . ' (' . kop_wbf_cite($r) . ')';
}

function kop_wbf_list_has(array $list, $value) {
    foreach ($list as $item) {
        if (is_string($value) && is_string($item) && strcasecmp(trim($item), trim($value)) === 0) {
            return true;
        }
        if (is_array($value) && is_array($item) && isset($value['raw'], $item['raw']) && strcasecmp($item['raw'], $value['raw']) === 0) {
            return true;
        }
    }
    return false;
}

/*
 * The same information in other words. Sources word one fact many ways
 * ("Cost: $5,400 per month plus $6,000 enrollment deposit (wiki)" and "Cost:
 * $5,400 per month tuition, plus a $6,000 deposit (HEAL)"; "Ages: boys 12-17"
 * on a record whose age range is 12-17 and gender Male; "Founded by: John
 * Santa" with John Santa on the staff list). Such an item would add nothing:
 * Add refuses it as already on the record and the waiting list files it
 * there (kop_wbf_on_record(), kop_wbf_clear_on_record()).
 */

/** A list line without its citation, link, label or date: [text, year or null]. */
function kop_wbf_text_core($s, $incident = false) {
    $s = trim((string) $s);
    $s = rtrim(preg_replace('/[:\s]+https?:\/\/\S+\s*$/', '', $s), " .");
    // The last balanced "( ... )" is the citation ("(HEAL, staff list for X (archived 2016))").
    if (substr($s, -1) === ')') {
        $depth = 0;
        for ($i = strlen($s) - 1; $i >= 0; $i--) {
            if ($s[$i] === ')') $depth++;
            elseif ($s[$i] === '(' && --$depth === 0) { $s = substr($s, 0, $i); break; }
        }
    }
    $s = trim($s, " .;");
    $year = null;
    if (preg_match('/^(?:Reported\s+)?((?:19|20)\d\d)(?:-\d\d){0,2}:\s*(?:[A-Z][\w \/-]{0,30}:\s*)?/', $s, $m)) {
        $year = (int) $m[1];
        $s = substr($s, strlen($m[0]));
    } elseif (!$incident) {
        $s = preg_replace('/^[A-Z][\w \/\'-]{0,30}:\s*/', '', $s);
    }
    return array(trim($s), $year);
}

function kop_wbf_words($s) {
    static $stop = array('the', 'a', 'an', 'of', 'and', 'to', 'in', 'at', 'for', 'on', 'with', 'by', 'was', 'were', 'is', 'are', 'its',
        'it', 'as', 'from', 'that', 'this', 'has', 'had', 'have', 'be', 'been', 'also', 'into', 'after', 'their', 'his', 'her');
    preg_match_all('/[a-z0-9]+/', strtolower(str_replace(',', '', (string) $s)), $m);
    return array_values(array_unique(array_diff($m[0], $stop)));
}

/** Whether two lines say the same: nearly the same words, and every number of the first in the second. */
function kop_wbf_text_same($a, $b) {
    $x = kop_wbf_words($a);
    $y = kop_wbf_words($b);
    if (count($x) < 5 || count($y) < 5) return false;
    preg_match_all('/\d+(?:\.\d+)?/', str_replace(',', '', (string) $a), $na);
    preg_match_all('/\d+(?:\.\d+)?/', str_replace(',', '', (string) $b), $nb);
    if (array_diff($na[0], $nb[0])) return false;
    $both = count(array_intersect($x, $y));
    $small = min(count($x), count($y));
    if ($both === count($x)) return true;
    return $both / $small >= 0.85 && $both / max(count($x), count($y)) >= 0.7;
}

/** A company or program name as a key: "UHS, Inc." -> "uhs". */
function kop_wbf_org_key($s) {
    $s = strtolower(preg_replace('/\([^)]*\)/', ' ', (string) $s));
    $s = preg_replace('/\b(?:inc|llc|l\.l\.c|corp|corporation|co|company|ltd|the|lp|pllc)\b\.?/', ' ', $s);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/** Whether a short phrase names this company: the same name, its acronym in brackets, or the whole of its name. */
function kop_wbf_org_is($phrase, $name) {
    $p = kop_wbf_org_key($phrase);
    $n = kop_wbf_org_key($name);
    if ($p === '' || $n === '') return false;
    if ($p === $n) return true;
    if (preg_match_all('/\(([^)]+)\)/', (string) $name, $m)) {
        foreach ($m[1] as $a) if (kop_wbf_org_key($a) === $p) return true;
    }
    if (preg_match_all('/\(([^)]+)\)/', (string) $phrase, $m)) {
        foreach ($m[1] as $a) if (kop_wbf_org_key($a) === $n) return true;
    }
    return false;
}

/** A street address as a key: "1329 Brownsferry Road, Madison" == "1329 Browns Ferry Rd, Madison, AL". */
function kop_wbf_street_key($s) {
    $s = strtolower(trim(explode(',', (string) $s)[0]));
    if (!preg_match('/^\d+\s+\S/', $s)) return '';
    $map = array('west' => 'w', 'east' => 'e', 'north' => 'n', 'south' => 's', 'street' => 'st', 'road' => 'rd', 'avenue' => 'ave',
        'drive' => 'dr', 'lane' => 'ln', 'boulevard' => 'blvd', 'highway' => 'hwy', 'court' => 'ct', 'place' => 'pl', 'circle' => 'cir',
        'parkway' => 'pkwy', 'trail' => 'trl', 'route' => 'rte', 'mountain' => 'mtn', 'suite' => 'ste');
    $w = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($w as $i => $t) $w[$i] = $map[$t] ?? $t;
    return implode('', $w);
}

/** Boys, girls or both, as a text says it: 'm', 'f', 'c' or ''. */
function kop_wbf_gender_of($s) {
    $s = strtolower((string) $s);
    $m = preg_match('/\b(?:boys?|men|male|males|young men|sons?)\b/', $s);
    $f = preg_match('/\b(?:girls?|women|female|females|young women|daughters?)\b/', $s);
    if (preg_match('/\b(?:co-?ed|coeducational|mixed|all genders)\b/', $s) || ($m && $f)) return 'c';
    return $m ? 'm' : ($f ? 'f' : '');
}

/** Everyone on the record's staff lists. */
function kop_wbf_staff_all(array $doc) {
    $out = array();
    foreach (array('staff.administrator', 'staff.notableStaff') as $path) {
        foreach ((array) kop_wbf_get($doc, $path) as $s) $out[] = is_array($s) ? $s : array('name' => (string) $s);
    }
    return $out;
}

/** Whether two names are one person: the same first and last name, or a nickname of it ("Matt Hendry", "Matthew Hendry"). */
function kop_wbf_same_person($a, $b) {
    $x = kop_wbf_person_key($a);
    if ($x !== '' && $x === kop_wbf_person_key($b)) return true;
    if (!function_exists('kop_facility_pages_person_key')) return false;
    $x = kop_facility_pages_person_key($a);
    return $x !== '' && $x === kop_facility_pages_person_key($b);
}

/** The record's names, current and earlier. */
function kop_wbf_record_names(array $doc) {
    $id = (array) ($doc['identification'] ?? array());
    $out = array((string) ($id['name'] ?? ''), (string) ($id['currentName'] ?? ''));
    foreach (array('pastNames', 'otherNames') as $k) {
        foreach ((array) ($id[$k] ?? array()) as $n) $out[] = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
    }
    return array_values(array_filter(array_map('trim', $out), 'strlen'));
}

/** The companies the record names: operator, past and other operators, owners. */
function kop_wbf_record_orgs(array $doc) {
    $id = (array) ($doc['identification'] ?? array());
    $out = array((string) ($id['currentOperator'] ?? ''));
    foreach (array('pastOperators', 'otherOperators', 'currentOwners') as $k) {
        foreach ((array) ($id[$k] ?? array()) as $n) $out[] = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
    }
    return array_values(array_filter(array_map('trim', $out), 'strlen'));
}

/**
 * Why a list item adds nothing the record does not hold already, in other
 * words ('' when it adds something): a line that says the same, or a note
 * whose fact is in the record's own fields.
 */
function kop_wbf_list_covers(array $doc, $path, $value) {
    $text = is_array($value) ? (string) ($value['raw'] ?? '') : (string) $value;
    if (trim($text) === '') return '';
    $incident = $path === 'criticalIncidents.customIncidents';

    if ($path === 'identification.pastNames' || $path === 'identification.otherNames') {
        // "Red River Academy (2006-2015)", "Sequel TSI of Tuskegee", "X (AYA)" are names the record has already.
        $k = kop_wbf_org_key($text);
        $dk = kop_dd_name_key($text);
        foreach (kop_wbf_record_names($doc) as $n) {
            if (($k !== '' && kop_wbf_org_key($n) === $k) || ($dk !== '' && kop_dd_name_key($n) === $dk)) return 'the record already has the name ' . $n;
        }
        return '';
    }
    if ($path === 'location.formerLocations') {
        // "Havre, MT" and "Havre, Montana": one town, whichever way a source writes the state.
        $place = function ($l) {
            $l = is_array($l) ? $l : array('raw' => (string) $l);
            $city = trim((string) ($l['city'] ?? ''));
            $state = trim((string) ($l['state'] ?? ''));
            if ($city === '' && preg_match('/^([^,]+),\s*([^,(]+)/', (string) ($l['raw'] ?? ''), $m)) { $city = $m[1]; $state = $m[2]; }
            if (function_exists('kop_facility_state_code')) $state = (string) kop_facility_state_code(trim($state));
            $city = strtolower(trim(preg_replace('/[^a-z ]+/i', ' ', $city)));
            return $city === '' ? '' : $city . '|' . strtolower(trim($state));
        };
        $want = $place($value);
        if ($want === '') return '';
        $loc = (array) ($doc['location'] ?? array());
        foreach (array_merge(array($loc), (array) ($loc['formerLocations'] ?? array()), (array) ($loc['additionalLocations'] ?? array())) as $l) {
            if ($place($l) === $want) return 'the record already lists ' . (is_array($l) ? trim((string) ($l['raw'] ?? (($l['city'] ?? '') . ', ' . ($l['state'] ?? ''))), ', ') : $l);
        }
        return '';
    }
    if ($incident) {
        // The same event told by another source (the same kind in the same month, mostly the same words).
        foreach ((array) kop_wbf_get($doc, 'criticalIncidents.customIncidents') as $line) {
            if (is_string($line) && kop_dd_same_event($line, $text)) return 'the record has this event: ' . mb_substr(trim($line), 0, 160);
        }
    }
    if ($path !== 'notes' && !$incident) return '';

    list($body, $year) = kop_wbf_text_core($text, $incident);
    if ($body === '') return '';
    $pool = array();
    foreach (array('notes', 'operatingPeriod.notes', 'criticalIncidents.customIncidents', 'memberships', 'licensing', 'accreditations.current', 'accreditations.past', 'certifications') as $p) {
        foreach ((array) kop_wbf_get($doc, $p) as $line) {
            $line = is_array($line) ? (string) ($line['raw'] ?? ($line['name'] ?? '')) : (string) $line;
            if (trim($line) !== '') $pool[] = $line;
        }
    }
    foreach ($pool as $line) {
        list($b, $y) = kop_wbf_text_core($line, (bool) preg_match('/^(?:Reported\s+)?(?:19|20)\d\d\b/', $line));
        if ($incident && $year && $y && $year !== $y) continue;
        if (kop_wbf_text_same($body, $b)) return 'the record says the same: ' . mb_substr(trim($line), 0, 160);
    }
    if ($incident) return '';

    $label = preg_match('/^([A-Z][\w \/\'-]{0,30}):/', trim($text), $m) ? strtolower($m[1]) : '';
    $fd = (array) ($doc['facilityDetails'] ?? array());
    $op = (array) ($doc['operatingPeriod'] ?? array());
    preg_match_all('/\d+/', $body, $nums);
    $nums = array_map('intval', $nums[0]);
    $gender_ok = function ($s) use ($fd) {
        $g = kop_wbf_gender_of($s);
        return $g === '' || $g === kop_wbf_gender_of((string) ($fd['gender'] ?? ''));
    };
    $ages_ok = function () use ($fd, $nums, $body) {
        $min = $fd['ageRange']['min'] ?? null;
        $max = $fd['ageRange']['max'] ?? null;
        if (count($nums) === 2) return (int) $min === $nums[0] && (int) $max === $nums[1];
        if (count($nums) === 1 && preg_match('/\b' . $nums[0] . '\s*\+|\b' . $nums[0] . ' and (?:older|over|up)/', $body)) return (int) $min === $nums[0] && kop_wbf_no_info($max);
        return false;
    };
    // Everything but the names: the words that are left say nothing more.
    $only = function ($rest, array $extra = array()) {
        $filler = array_merge(array('also', 'known', 'formerly', 'renamed', 'rebranded', 'rebranding', 'now', 'called', 'became', 'changed', 'name', 'to',
            'from', 'as', 'aka', 'a', 'k', 'the', 'and', 'or', 'its', 'new', 'previously', 'later', 'operating', 'under', 'reportedly', 'by', 'founded',
            'co', 'founder', 'founders', 'cofounder', 'with', 'is', 'was', 'were', 'owned', 'operated', 'run', 'owner', 'owners', 'program', 'of', 'a'), $extra);
        return !array_diff(preg_split('/[^a-z0-9]+/', strtolower($rest), -1, PREG_SPLIT_NO_EMPTY), $filler);
    };
    // Ages, gender and size say nothing more than their numbers and boys/girls ("Ages: youth, ages 12 to 17 at admission").
    $plain_size = function () use ($body, $only) {
        return $only(preg_replace('/\d+/', ' ', $body), array('ages', 'age', 'aged', 'years', 'year', 'old', 'olds', 'youth', 'youths', 'teens', 'teen',
            'teenage', 'teenagers', 'adolescent', 'adolescents', 'at', 'admission', 'upon', 'through', 'between', 'students', 'student', 'kids',
            'children', 'plus', 'up', 'over', 'older', 'only', 'boys', 'boy', 'girls', 'girl', 'men', 'women', 'young', 'male', 'female', 'males',
            'females', 'coed', 'ed', 'beds', 'bed', 'capacity', 'maximum', 'max', 'licensed', 'for', 'serves', 'serving', 'residents', 'clients', 'about'));
    };

    switch ($label) {
        case 'ages':
            if ($nums && $ages_ok() && $gender_ok($body) && $plain_size()) return 'the record\'s ages are ' . kop_wbf_show_value($fd['ageRange']);
            return '';
        case 'gender':
            if (kop_wbf_gender_of($body) !== '' && $gender_ok($body) && (!$nums || $ages_ok()) && $plain_size()) return 'the record says ' . $fd['gender'] . ($nums ? ', ages ' . kop_wbf_show_value($fd['ageRange']) : '');
            return '';
        case 'capacity':
            if (count($nums) === 1 && !kop_wbf_no_info($fd['capacity'] ?? null) && (int) $fd['capacity'] === $nums[0] && $gender_ok($body) && $plain_size()) return 'the record\'s capacity is ' . (int) $fd['capacity'];
            return '';
        case 'opened':
        case 'start year':
            $b = preg_replace('/\s+per\s+.*$/i', '', $body);
            if (preg_match('/^(?:in\s+)?((?:19|20)\d\d)$/', trim($b), $y) && (int) ($op['startYear'] ?? 0) === (int) $y[1]) return 'the record opened in ' . (int) $y[1];
            return '';
        case 'founded by':
        case 'owner':
        case 'operator':
        case 'acquired':
            // Words in brackets count ("Aspen Education Group (parent CRC Health Group)" says more than the operator's name).
            $rest = $body;
            $said = false;
            foreach (kop_wbf_record_orgs($doc) as $o) {
                foreach (array_unique(array($o, preg_replace('/\s*\([^)]*\)/', '', $o))) as $form) {
                    if ($form !== '' && stripos($rest, $form) !== false) { $rest = str_ireplace($form, ' ', $rest); $said = true; }
                }
                if (preg_match_all('/\(([A-Z][A-Za-z&]{1,10})\)/', $o, $ac)) {
                    foreach ($ac[1] as $a) {
                        if (preg_match('/\b' . preg_quote($a, '/') . '\b/', $rest)) { $rest = preg_replace('/\b' . preg_quote($a, '/') . '\b/', ' ', $rest); $said = true; }
                    }
                }
            }
            // A person counts only when the record has them in that part: a founder as a founder, an owner as an owner.
            $as = $label === 'founded by' ? '/found/i' : '/\bown|found|ceo|president/i';
            $staff = array_values(array_filter(kop_wbf_staff_all($doc), function ($s) use ($as) {
                return (bool) preg_match($as, (string) ($s['role'] ?? '') . ' ' . (string) ($s['pastJobs'] ?? ''));
            }));
            foreach (preg_split('/\s*(?:,|;|&|\band\b|\bwith\b)\s*/', trim(preg_replace('/\s*\([^)]*\)/', '', $rest))) as $part) {
                if (!preg_match('/^(?:(?:Dr|Rev|Mr|Mrs|Ms)\.?\s+)?[A-Z][\w\'.-]*(?:\s+[A-Z][\w\'.-]*){1,3}$/', trim($part))) continue;
                foreach ($staff as $s) {
                    if (kop_wbf_same_person($part, $s['name'] ?? '')) { $rest = str_replace($part, ' ', $rest); $said = true; break; }
                }
            }
            $rest = preg_replace('/\b(?:Inc|LLC|Corp)\b\.?/i', ' ', $rest);
            if ($said && $only($rest, array('acquired', 'bought', 'purchased', 'sold', 'taken', 'over', 'since', 'in', 'president', 'director', 'ceo', 'partnered'))) {
                return 'the record names them already';
            }
            return '';
        case 'name change':
        case 'note':
            if ($label === 'note' && !preg_match('/\b(?:also known as|formerly|renamed|rebranded|now called|a\.?k\.?a\.?)\b/i', $body)) return '';
            $rest = $body;
            $names = kop_wbf_record_names($doc);
            usort($names, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });
            $hit = 0;
            foreach ($names as $n) {
                if (stripos($rest, $n) !== false) { $rest = str_ireplace($n, ' ', $rest); $hit++; }
            }
            if ($hit && $only($rest)) return 'the record has these names: ' . implode(', ', array_slice($names, 0, 6));
            return '';
        case 'address':
        case 'campus':
        case 'move':
            $k = kop_wbf_street_key($body);
            if ($k === '') return '';
            $loc = (array) ($doc['location'] ?? array());
            $have = array((string) ($loc['street'] ?? ''), (string) ($loc['raw'] ?? ''));
            foreach (array('formerLocations', 'additionalLocations') as $p) {
                foreach ((array) ($loc[$p] ?? array()) as $l) $have[] = is_array($l) ? (string) ($l['street'] ?? ($l['raw'] ?? '')) : (string) $l;
            }
            foreach ($have as $h) {
                if ($h !== '' && kop_wbf_street_key($h) === $k && $only(preg_replace('/^[^,]*/', '', $body), array_merge(kop_wbf_words(implode(' ', $have)),
                    array('saint', 'st', 'usa', 'us', 'united', 'states')))) return 'the record\'s address is ' . $h;
            }
            return '';
        case 'membership':
        case 'accreditation':
            // The group named (its acronym) on the record's list, with no year the record's line lacks.
            preg_match_all('/\b[A-Z]{3,}\b/', $body, $ac);
            preg_match_all('/\b(?:19|20)\d\d\b/', $body, $yrs);
            if (!$ac[0]) return '';
            foreach ($pool as $line) {
                $ok = true;
                foreach (array_unique($ac[0]) as $a) if (!preg_match('/\b' . preg_quote($a, '/') . '\b/', $line)) $ok = false;
                // "since 2014" is the line's first year ("member from 2014, last listed 2020-21"), never a later one.
                preg_match_all('/\b(?:19|20)\d\d\b/', $line, $ly);
                if ($yrs[0] && ($ly[0][0] ?? '') !== $yrs[0][0]) $ok = false;
                foreach ($yrs[0] as $y) if (strpos($line, $y) === false) $ok = false;
                $rest = preg_replace('/\b[A-Z]{3,}\b|\b(?:19|20)\d\d\b/', ' ', $body);
                if ($ok && $only($rest, array('member', 'membership', 'since', 'accredited', 'accreditation', 'joined', 'full', 'listed', 'about', 'until', 'through'))) {
                    return 'the record says ' . mb_substr($line, 0, 120);
                }
            }
            return '';
    }
    return '';
}

/** One role in plain words: "Director (2006, Woodbury Reports)" -> "director". */
function kop_wbf_role_plain($s) {
    return trim(preg_replace('/\s+/', ' ', preg_replace('/\([^)]*\)/', '', strtolower((string) $s))));
}

/** A role's titles: "current Co-Founder and Executive Director (2015)" -> ["co-founder", "executive director"]. */
function kop_wbf_role_parts($s) {
    $out = array();
    foreach (preg_split('/\s*(?:[,;\/&]|\band\b)\s*/', kop_wbf_role_plain($s)) as $part) {
        $part = trim(preg_replace('/^(?:(?:current|currently|also|the|a|an|former|formerly|previous|previously|first|later)\s+)+/', '', $part));
        if (mb_strlen($part) >= 3) $out[] = $part;
    }
    return $out;
}

/** Whether a role names a job at all ("one", "wife", "reported to have worked" do not). */
function kop_wbf_role_is_title($s) {
    return (bool) preg_match('/\b(?:director|founder|owner|ceo|cfo|coo|president|chair\w*|head\w*|principal|dean|therapist|psycho\w+|psychiatrist'
        . '|physician|doctor|counsel+or|manager|coordinator|supervisor|staff|nurse|teacher|instructor|mentor|guide|sergeant|agent|secretary|treasurer'
        . '|advis[eo]r|partner|superintendent|administrator|specialist|chief|officer|consultant|pastor|chaplain|minister|parent|creator|board|trustee'
        . '|clinician|social worker|liaison|admissions|marketing|operations|executive|guard|cook|worker|employee|volunteer|intern)s?\b/i', (string) $s);
}

/** Whether two roles say the same thing (a title of one is, or holds, a title of the other), or either says nothing. */
function kop_wbf_role_same($a, $b) {
    if (kop_wbf_no_info(kop_wbf_role_plain($a)) || kop_wbf_no_info(kop_wbf_role_plain($b))) return true;
    foreach (kop_wbf_role_parts($a) as $x) {
        foreach (kop_wbf_role_parts($b) as $y) {
            if (strpos($x, $y) !== false || strpos($y, $x) !== false) return true;
        }
    }
    return false;
}

/**
 * Whether the person's entry already says this job in other words: its
 * employer named in one of their lines ("Teacher - Cherokee Creek Boys School
 * (2008-2020)" and "a Teacher at [Cherokee Creek Boys School](...)") with the
 * same title there, or a title that says nothing more ("Staff", "Unnamed
 * position"). Years are when a source saw them, not more information.
 */
function kop_wbf_job_held($job, array $lines) {
    $job = trim(preg_replace('/^(?:later|then|previously|formerly|former)\s+/i', '', trim((string) $job)));
    $job = trim(preg_replace('/\s*(?:\([^()]*\)|\[[^\]]*\])\s*/', ' ', $job));
    if ($job === '') return false;
    $parts = preg_split('/\s+-\s+/', $job, 2);
    $title = trim($parts[0]);
    $org = trim($parts[1] ?? '');
    $plain = function ($s) {
        $s = strtolower(preg_replace('/\]\([^)]*\)?|[\[\]*]/', ' ', (string) $s));
        $s = preg_replace('/\brtc\b/', 'residential treatment center', $s);
        return ' ' . trim(preg_replace('/[^a-z0-9]+/', ' ', $s)) . ' ';
    };
    $words = array_values(array_diff(preg_split('/\s+/', trim($plain($org)), -1, PREG_SPLIT_NO_EMPTY), array('the', 'of', 'and', 'inc', 'llc', 'at')));
    $generic = kop_wbf_no_info($title) || preg_match('/^(?:staff|staff member|employee|worked|unnamed position|position|member|unknown role|role unknown)$/i', $title);
    $titles = kop_wbf_role_parts($title);
    foreach ($lines as $line) {
        $l = $plain($line);
        if (trim($l) === '') continue;
        foreach ($words as $w) {
            if (strpos($l, ' ' . $w . ' ') === false) continue 2;
        }
        if (!$words && $generic) continue;
        if ($generic) return true;
        if (!$titles) continue;
        $all = true;
        foreach ($titles as $t) {
            if (strpos($l, ' ' . trim($plain($t)) . ' ') === false) { $all = false; break; }
        }
        if ($all) return true;
    }
    return false;
}

/**
 * A staff item merged into the entry the record has for that person: [role,
 * pastJobs]. A different role is the person's history, never a disagreement:
 * the record's role stays (a role that names no job, "one" or "wife" from a
 * wiki import, is replaced) and the item's role joins the past jobs, as do
 * past jobs the entry does not list yet. Unchanged = nothing to add.
 */
function kop_wbf_staff_merge(array $s, array $value, array $doc) {
    $role = trim((string) ($s['role'] ?? ''));
    $jobs = trim((string) ($s['pastJobs'] ?? ''));
    $new = trim((string) ($value['role'] ?? ''));
    $list = array_values(array_filter(array_map('trim', explode(';', $jobs)), 'strlen'));
    $has = function ($text) use (&$list, &$role) {
        $p = kop_wbf_role_plain($text);
        foreach ($list as $j) {
            $q = kop_wbf_role_plain($j);
            if ($p !== '' && (strpos($q, $p) !== false || strpos($p, $q) !== false)) return true;
        }
        return kop_wbf_job_held($text, array_merge(array($role), $list));
    };
    if ($new !== '' && !kop_wbf_no_info($new)) {
        if ($role === '' || kop_wbf_no_info($role) || !kop_wbf_role_is_title($role)) {
            $role = $new;
        } elseif (!kop_wbf_role_same($role, $new) && !$has(preg_replace('/\s*\([^)]*\)\s*$/', '', $new))) {
            $list[] = kop_wbf_role_job($new, $doc);
        }
    }
    foreach (array_map('trim', explode(';', (string) ($value['pastJobs'] ?? ''))) as $j) {
        if ($j !== '' && !$has($j)) $list[] = $j;
    }
    return array($role, implode('; ', $list));
}

/**
 * What an item says, whichever source says it: the record, the kind of change
 * and the fact without its citation ("Opened: 2005 (Woodbury Reports, May
 * 2009, p. 12)" and "... (HEAL ...)" are one fact; a person is one fact at a
 * record whatever role each source gives). '' for an item with no record.
 */
function kop_wbf_fact_key(array $r) {
    if ((int) ($r['facility_id'] ?? 0) <= 0) return '';
    $v = kop_wbf_row_value($r);
    switch ($r['op']) {
        case 'add_staff':
            $what = kop_wbf_person_key(is_array($v) ? ($v['name'] ?? '') : '');
            break;
        case 'set_closed':
            $what = (string) (int) (is_array($v) ? ($v['endYear'] ?? 0) : 0);
            break;
        case 'set_if_empty':
            $what = strtolower(is_array($v) ? wp_json_encode($v) : trim((string) $v));
            break;
        default:
            $s = is_array($v) ? (string) ($v['raw'] ?? wp_json_encode($v)) : (string) $v;
            $s = preg_replace('/\s*\([^()]*\)\s*\.?\s*$/', '', $s);
            $what = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
    }
    return $what === '' ? '' : (int) $r['facility_id'] . '|' . $r['op'] . '|' . $r['path'] . '|' . $what;
}

/** Whether a slot holds nothing worth keeping: no information, or a sentence where a type belongs (the wiki's "If you attended..."). */
function kop_wbf_slot_empty($path, $slot) {
    if ($slot === null || $slot === '' || kop_wbf_no_info($slot)) return true;
    return in_array($path, array('facilityDetails.type', 'facilityDetails.gender'), true) && is_string($slot)
        && (mb_strlen($slot) > 60 || preg_match('/\b(?:please|contribute|if you|this program)\b/i', $slot));
}

/**
 * Whether two values say the same thing. Numbers and ranges must match; a
 * type or gender is the same when one's words hold the other's ("Christian
 * Therapeutic Boarding School", "Therapeutic Boarding School") or it is a
 * typo of it ("Therpeutic").
 */
function kop_wbf_value_same($path, $a, $b) {
    if (is_array($a) || is_array($b) || is_numeric($a) || is_numeric($b)) return false;
    $words = function ($s) {
        $w = preg_split('/[^a-z0-9]+/', strtolower((string) $s), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_diff($w, array('and', 'the', 'of', 'for', 'a', 'an')));
    };
    $x = $words($a);
    $y = $words($b);
    if (!$x || !$y) return false;
    if (!array_diff($x, $y) || !array_diff($y, $x)) return true;
    $s = implode(' ', $x);
    $t = implode(' ', $y);
    return mb_strlen($s) >= 8 && mb_strlen($t) >= 8 && levenshtein($s, $t) <= 2;
}

/**
 * A role at this program written as a past job: "Founder (2021, HEAL)" ->
 * "Founder - Canyon State Academy (2021)", the shape the builds give pastJobs.
 */
function kop_wbf_role_job($role, array $doc) {
    $role = trim((string) $role);
    $when = '';
    if (preg_match('/\(([^)]*)\)\s*$/', $role, $m)) {
        $role = trim(substr($role, 0, -strlen($m[0])));
        $first = trim(explode(',', $m[1])[0]);
        if (preg_match('/\d{4}/', $first)) $when = ' (' . $first . ')';
    }
    $name = trim((string) ($doc['identification']['name'] ?? ''));
    return $role . ($name !== '' ? ' - ' . $name : '') . $when;
}

/**
 * Apply one proposal to a document. Returns what was done, for Undo.
 * Throws when the record already holds a different value.
 */
function kop_wbf_doc_apply(array &$doc, array $r) {
    $value = kop_wbf_row_value($r);
    $done = array('op' => $r['op'], 'path' => $r['path'], 'notes' => array());
    $add_note = function ($path, $line) use (&$doc, &$done) {
        $list = &kop_wbf_ref($doc, $path);
        $list = is_array($list) ? $list : array();
        if (!in_array($line, $list, true)) {
            $list[] = $line;
            $done['notes'][] = array('path' => $path, 'line' => $line);
        }
    };

    if ($r['op'] === 'add_staff') {
        if (kop_staff_name_not_person($value['name'] ?? '')) {
            throw new RuntimeException('That is not a person\'s name (a phase sheet or a headline read as a staff list).');
        }
        $key = kop_wbf_person_key($value['name'] ?? '');
        foreach (array('staff.administrator', 'staff.notableStaff') as $path) {
            $list = &kop_wbf_ref($doc, $path);
            $list = is_array($list) ? $list : array();
            foreach ($list as $i => $s) {
                $s = is_array($s) ? $s : array('name' => (string) $s, 'role' => '', 'pastJobs' => '');
                // Another source's spelling of someone listed ("Jim Omer", "Adam McClain", "Angela Agundez (Smith)") is that person.
                if (($key !== '' && kop_wbf_person_key($s['name'] ?? '') === $key) || kop_wbf_same_person((string) ($value['name'] ?? ''), (string) ($s['name'] ?? ''))
                    || kop_dd_same_person((string) ($value['name'] ?? ''), (string) ($s['name'] ?? ''))) {
                    $before = array('role' => (string) ($s['role'] ?? ''), 'pastJobs' => (string) ($s['pastJobs'] ?? ''),
                        'source' => $s['source'] ?? null, 'sourceUrl' => $s['sourceUrl'] ?? null);
                    list($role, $jobs) = kop_wbf_staff_merge($s, (array) $value, $doc);
                    // Each title once, no source name in the role (the entry's source field says it).
                    $role = kop_dd_role_tidy(kop_staff_role_with_former($role, kop_staff_doc_closed($doc)));
                    $jobs = kop_dd_join_jobs($jobs, '');
                    if ($role === kop_dd_role_tidy(trim($before['role'])) && $jobs === kop_dd_join_jobs(trim($before['pastJobs']), '')) {
                        throw new RuntimeException('Already on the record\'s staff list.');
                    }
                    $s['role'] = $role;
                    $s['pastJobs'] = $jobs;
                    if (empty($s['source'])) {
                        $s = array_merge($s, kop_wbf_source_ref($r));
                    }
                    $list[$i] = $s;
                    $done += array('mode' => 'merged', 'at' => $path, 'name' => $s['name'], 'before' => $before);
                    return $done;
                }
            }
            unset($list);
        }
        $list = &kop_wbf_ref($doc, $r['path']);
        $list = is_array($list) ? $list : array();
        $list[] = array('name' => (string) $value['name'], 'role' => kop_dd_role_tidy(kop_staff_role_with_former((string) $value['role'], kop_staff_doc_closed($doc))), 'pastJobs' => kop_dd_join_jobs((string) $value['pastJobs'], ''))
            + kop_wbf_source_ref($r);
        $done += array('mode' => 'added', 'at' => $r['path'], 'name' => (string) $value['name']);
        return $done;
    }

    if ($r['op'] === 'add_list') {
        $done['created'] = kop_wbf_get($doc, $r['path']) === null;
        $list = &kop_wbf_ref($doc, $r['path']);
        $list = is_array($list) ? $list : array();
        if (kop_wbf_list_has($list, $value)) {
            throw new RuntimeException('Already on the record.');
        }
        if (($why = kop_wbf_list_covers($doc, $r['path'], $value)) !== '') {
            throw new RuntimeException('Already on the record in other words: ' . $why . '.');
        }
        $list[] = $value;
        unset($list);
        $done['value'] = $value;
        if (in_array($r['path'], array('identification.pastNames', 'identification.pastOperators', 'location.formerLocations'), true)) {
            $extra = json_decode((string) $r['extra'], true);
            $add_note('notes', !empty($extra['note_line']) ? $extra['note_line'] . ' ' . kop_wbf_evidence($r)[0]['url'] : kop_wbf_source_line($r));
        }
        return $done;
    }

    if ($r['op'] === 'set_if_empty') {
        // A type is only ever the agreed types (one or several, "A, B"), the list the discovery form and "Create a record" use.
        if ($r['path'] === 'facilityDetails.type' && function_exists('kop_facdisc_types_value')) {
            $value = kop_facdisc_types_value($value);
            if ($value === '') throw new RuntimeException('No facility type to add.');
        }
        $slot = &kop_wbf_ref($doc, $r['path']);
        if (!kop_wbf_slot_empty($r['path'], $slot)) {
            throw new RuntimeException('The record already has ' . (is_array($slot) ? wp_json_encode($slot) : $slot) . ' there.');
        }
        $done['before'] = $slot;
        $slot = $value;
        unset($slot);
        $done['value'] = $value;
        $add_note('operatingPeriod.notes', kop_wbf_source_line($r));
        return $done;
    }

    if ($r['op'] === 'set_closed') {
        $done['before_status'] = $doc['operatingPeriod']['status'] ?? 'Unknown';
        $done['before_end'] = $doc['operatingPeriod']['endYear'] ?? null;
        $doc['operatingPeriod']['status'] = 'Closed';
        if (!empty($value['endYear']) && $done['before_end'] === null) {
            $doc['operatingPeriod']['endYear'] = (int) $value['endYear'];
        }
        $add_note('operatingPeriod.notes', kop_wbf_source_line($r));
        return $done;
    }
    throw new RuntimeException('Unknown change.');
}

/**
 * True when a value says nothing: empty, "?", "Unknown", "(no role)", 0, a
 * range with no ends. Having no information never disagrees with anything.
 */
function kop_wbf_no_info($v) {
    if ($v === null || $v === false) return true;
    if (is_array($v)) {
        if (array_key_exists('min', $v) || array_key_exists('max', $v)) {
            return kop_wbf_no_info($v['min'] ?? null) && kop_wbf_no_info($v['max'] ?? null);
        }
        foreach ($v as $x) {
            if (!kop_wbf_no_info($x)) return false;
        }
        return true;
    }
    if (is_int($v) || is_float($v)) return $v == 0;
    $s = strtolower(trim((string) $v, " \t\n\r\0\x0B.()"));
    return in_array($s, array('', '?', '0', 'unknown', 'not known', 'n/a', 'na', 'none', 'no role', 'null', '-'), true);
}

/**
 * What the record says that disagrees with this item ('' when nothing does):
 * a field holding another value, another end year or "Open" for a closure,
 * another role for the same person. Add never overwrites any of these, so the
 * item keeps waiting with the conflict marked. A record that says nothing
 * (kop_wbf_no_info()) never disagrees.
 */
function kop_wbf_conflict(array $doc, array $r) {
    $c = kop_wbf_conflict_parts($doc, $r);
    return $c ? $c['text'] : '';
}

/**
 * The conflict as its two sides, or null: ['what' (dates, size, type, staff,
 * closure, other), 'record' (what the record says), 'item' (what this says),
 * 'text' (one sentence), 'slot' (where "Use this value" writes)].
 */
function kop_wbf_conflict_parts(array $doc, array $r) {
    $value = kop_wbf_row_value($r);
    $show = 'kop_wbf_show_value';
    $same = function ($a, $b) use ($show) {
        if (is_array($a) || is_array($b)) {
            $a = (array) $a;
            $b = (array) $b;
            if (array_key_exists('min', $a) || array_key_exists('max', $a)) return ($a['min'] ?? null) == ($b['min'] ?? null) && ($a['max'] ?? null) == ($b['max'] ?? null);
        }
        return strcasecmp(trim($show($a)), trim($show($b))) === 0;
    };
    $what = kop_wbf_conflict_what($r);
    switch ($r['op']) {
        case 'set_if_empty':
            $slot = kop_wbf_get($doc, $r['path']);
            if (kop_wbf_slot_empty($r['path'], $slot) || kop_wbf_no_info($value)) return null;
            if ($same($slot, $value) || kop_wbf_value_same($r['path'], $slot, $value)) return null;
            return array('what' => $what, 'record' => $show($slot), 'item' => $show($value),
                'text' => 'The record has ' . $show($slot) . '; this says ' . $show($value) . '.', 'slot' => $r['path']);
        case 'set_closed':
            $status = (string) ($doc['operatingPeriod']['status'] ?? '');
            $end = $doc['operatingPeriod']['endYear'] ?? null;
            $want = is_array($value) ? ($value['endYear'] ?? null) : null;
            if (strcasecmp($status, 'Open') === 0) {
                return array('what' => 'closure', 'record' => 'Open' . ($end ? ', ended ' . (int) $end : ''), 'item' => 'Closed' . ($want ? ' in ' . (int) $want : ''),
                    'text' => 'The record says it is open' . ($want ? '; this says it closed in ' . (int) $want : '') . '.', 'slot' => 'operatingPeriod.status');
            }
            if (!kop_wbf_no_info($want) && !kop_wbf_no_info($end) && (int) $end !== (int) $want) {
                return array('what' => 'closure', 'record' => 'Ended ' . (int) $end, 'item' => 'Ended ' . (int) $want,
                    'text' => 'The record says it ended in ' . (int) $end . '; this says ' . (int) $want . '.', 'slot' => 'operatingPeriod.endYear');
            }
            return null;
        case 'add_staff':
            // A person's other role is their history, never a disagreement: Add keeps the record's role (replacing one that
            // names no job) and puts the other into the past jobs (kop_wbf_staff_merge()).
            return null;
    }
    return null;
}

/** The kind of disagreement, for the Conflicts filter. */
function kop_wbf_conflict_what(array $r) {
    $path = strtolower((string) ($r['path'] ?? ''));
    if ($r['op'] === 'add_staff' || strpos($path, 'staff') === 0) return 'staff';
    if ($r['op'] === 'set_closed') return 'closure';
    if (strpos($path, 'year') !== false) return 'dates';
    if (strpos($path, 'capacity') !== false || strpos($path, 'age') !== false || strpos($path, 'size') !== false) return 'size';
    if (strpos($path, 'type') !== false || strpos($path, 'gender') !== false) return 'type';
    return 'other';
}

/**
 * "Use this value" on a conflict: write the item's value (or $value, a
 * reviewer's correction) over what the record holds, citing the item's
 * source. Returns what was done for kop_wbf_doc_undo() (op 'overwrite': each
 * slot puts back its old value unless someone changed it since).
 */
function kop_wbf_doc_overwrite(array &$doc, array $r, $value = null) {
    $value = $value === null ? kop_wbf_row_value($r) : $value;
    $done = array('op' => 'overwrite', 'slots' => array(), 'notes' => array());
    $set = function ($path, $v) use (&$doc, &$done) {
        $slot = &kop_wbf_ref($doc, $path);
        $done['slots'][] = array('path' => $path, 'before' => $slot, 'value' => $v);
        $slot = $v;
        unset($slot);
    };
    if ($r['op'] === 'set_if_empty') {
        $set($r['path'], $value);
    } elseif ($r['op'] === 'set_closed') {
        $set('operatingPeriod.status', 'Closed');
        $end = is_array($value) ? ($value['endYear'] ?? null) : $value;
        if ($end) $set('operatingPeriod.endYear', (int) $end);
    } elseif ($r['op'] === 'add_staff') {
        $role = is_array($value) ? trim((string) ($value['role'] ?? '')) : trim((string) $value);
        $key = kop_wbf_person_key((kop_wbf_row_value($r)['name'] ?? ''));
        foreach (array('staff.administrator', 'staff.notableStaff') as $path) {
            $list = &kop_wbf_ref($doc, $path);
            foreach ((array) $list as $i => $s) {
                if (!is_array($s) || kop_wbf_person_key($s['name'] ?? '') !== $key) continue;
                $ref = kop_wbf_source_ref($r);
                $done['slots'][] = array('at' => $path, 'name' => (string) $s['name'], 'value' => $role,
                    'before' => array('role' => $s['role'] ?? null, 'source' => $s['source'] ?? null, 'sourceUrl' => $s['sourceUrl'] ?? null));
                $list[$i] = array_merge($s, array('role' => $role), $ref);
                unset($list);
                break 2;
            }
            unset($list);
        }
        if (!$done['slots']) throw new RuntimeException('That person is not on the record any more.');
    } else {
        throw new RuntimeException('This kind of item is added, not written over a value.');
    }
    $line = kop_wbf_source_line($r);
    $notes = &kop_wbf_ref($doc, 'operatingPeriod.notes');
    $notes = is_array($notes) ? $notes : array();
    if (!in_array($line, $notes, true)) {
        $notes[] = $line;
        $done['notes'][] = array('path' => 'operatingPeriod.notes', 'line' => $line);
    }
    unset($notes);
    return $done;
}

/** A reviewer's typed value in the item's shape: a range from "12 to 18", a year or number, else the text. */
function kop_wbf_parse_value(array $r, $text) {
    $text = trim((string) $text);
    if ($text === '') throw new RuntimeException('Type the value first.');
    $old = kop_wbf_row_value($r);
    if ($r['op'] === 'set_closed') {
        if (!preg_match('/\b(1[89]\d\d|20\d\d)\b/', $text, $m)) throw new RuntimeException('Type the year it closed.');
        return array('endYear' => (int) $m[1]);
    }
    if ($r['op'] === 'add_staff') return array('role' => $text);
    if (is_array($old) && (array_key_exists('min', $old) || array_key_exists('max', $old))) {
        if (preg_match('/^(\d+)\s*(?:-|to|–)\s*(\d+)$/u', $text, $m)) return array('min' => (int) $m[1], 'max' => (int) $m[2]);
        if (preg_match('/^(\d+)\s*(?:\+|or more)$/', $text, $m)) return array('min' => (int) $m[1], 'max' => null);
        if (preg_match('/^(\d+)$/', $text, $m)) return array('min' => (int) $m[1], 'max' => (int) $m[1]);
        throw new RuntimeException('Type a range like 12 to 18.');
    }
    if (is_int($old) || (is_numeric($old) && !is_string($old))) {
        if (!preg_match('/^\d+$/', $text)) throw new RuntimeException('Type a number.');
        return (int) $text;
    }
    // A program's type: one or several ("A, B"). The reviewer's own pick, so a type the record already spells its own way stays.
    if (($r['path'] ?? '') === 'facilityDetails.type' && function_exists('kop_facility_types_join')) {
        return kop_facility_type(kop_facility_types_join(kop_facility_types_split($text)));
    }
    return $text;
}

/**
 * Settle a conflict: 'keep' files the item as rejected, the record as it is;
 * 'use' writes the item's value over the record's, 'edit' writes $typed
 * instead. With no conflict left, 'use' is a plain Add. Undo: kop_wbf_undo().
 */
function kop_wbf_resolve(array $r, $how, $typed, $reviewer) {
    global $wpdb;
    if ($r['status'] !== 'pending') throw new RuntimeException('This item was already handled (' . $r['status'] . '). Undo it first.');
    $fid = (int) $r['facility_id'];
    $now = current_time('mysql', true);
    if ($how === 'keep') {
        $wpdb->update(kop_wbf_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => 'Conflict settled: kept the record as it is.')),
            'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
        return 'Kept the record as it is; the item is under Rejected.';
    }
    if ($fid <= 0) throw new RuntimeException('This item has no record to write to.');
    if (kop_fact_hold_on()) {
        // Held for the write-up like any approval; approving the write-up writes the value (kop_wu_publish()).
        $held = array('held' => 1, 'how' => $how === 'edit' ? 'edit' : 'use');
        if ($how === 'edit') $held['value'] = kop_wbf_parse_value($r, $typed);
        $wpdb->update(kop_wbf_table(), array('status' => 'held', 'applied' => wp_json_encode($held), 'applied_fid' => $fid,
            'reviewed_by' => $reviewer, 'reviewed_at' => $now, 'auto' => 0), array('pkey' => $r['pkey']));
        return ($how === 'edit' ? 'Your value is' : 'This value is') . ' held for the record\'s write-up (Review inbox > Write-ups to approve): the page changes only when the write-up is approved. Undo sends it back to review.';
    }
    $opts = kop_wbf_opts();
    $msg = '';
    kop_v2_with_write_lock($opts['pdo'], function () use ($r, $how, $typed, $fid, $opts, $reviewer, $now, $wpdb, &$msg) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) throw new RuntimeException("Facility #{$fid} does not exist.");
        $doc = $stored['doc'];
        $value = $how === 'edit' ? kop_wbf_parse_value($r, $typed) : null;
        if ($how === 'use' && !kop_wbf_conflict_parts($doc, $r)) {
            $done = kop_wbf_doc_apply($doc, $r);
        } else {
            $done = kop_wbf_doc_overwrite($doc, $r, $value);
        }
        kop_wbf_save($doc, $opts);
        $wpdb->update(kop_wbf_table(), array('status' => 'applied', 'applied' => wp_json_encode($done), 'applied_fid' => $fid, 'facility_id' => $fid,
            'reviewed_by' => $reviewer, 'reviewed_at' => $now, 'auto' => 0), array('pkey' => $r['pkey']));
        $msg = ($how === 'edit' ? 'Wrote your value' : 'Wrote this value') . ' over the record\'s, citing the source. Undo puts the old value back.';
    });
    if ($r['op'] === 'set_closed') do_action('kop_facility_status_changed', $fid);
    return $msg;
}

/** A value as words: a range {min, max} as "12 to 18", a raw entry as written, a list joined. */
function kop_wbf_show_value($v) {
    if (!is_array($v)) return (string) $v;
    if (isset($v['raw'])) return (string) $v['raw'];
    if (array_key_exists('min', $v) || array_key_exists('max', $v)) {
        $min = $v['min'] ?? null;
        $max = $v['max'] ?? null;
        if ($min !== null && $max !== null) return $min == $max ? (string) $min : $min . ' to ' . $max;
        return $min !== null ? $min . ' or more' : 'up to ' . $max;
    }
    return implode(', ', array_map('kop_wbf_show_value', $v));
}

/** Take back what kop_wbf_doc_apply() did, leaving anything edited since. */
function kop_wbf_doc_undo(array &$doc, array $done) {
    foreach ((array) ($done['notes'] ?? array()) as $n) {
        $list = &kop_wbf_ref($doc, $n['path']);
        $list = array_values(array_filter(is_array($list) ? $list : array(), function ($x) use ($n) { return $x !== $n['line']; }));
        unset($list);
    }
    if ($done['op'] === 'add_staff') {
        $list = &kop_wbf_ref($doc, $done['at']);
        $list = is_array($list) ? $list : array();
        $key = kop_wbf_person_key($done['name']);
        foreach ($list as $i => $s) {
            if (!is_array($s) || kop_wbf_person_key($s['name'] ?? '') !== $key) {
                continue;
            }
            if ($done['mode'] === 'added') {
                array_splice($list, $i, 1);
            } else {
                $list[$i]['role'] = $done['before']['role'];
                $list[$i]['pastJobs'] = $done['before']['pastJobs'];
                // Applied before entries kept their source: those records have no 'source' key in 'before'.
                foreach (array('source', 'sourceUrl') as $k) {
                    if (!array_key_exists($k, $done['before'])) {
                        continue;
                    }
                    if ($done['before'][$k] === null) {
                        unset($list[$i][$k]);
                    } else {
                        $list[$i][$k] = $done['before'][$k];
                    }
                }
            }
            break;
        }
    } elseif ($done['op'] === 'add_list') {
        $list = &kop_wbf_ref($doc, $done['path']);
        // Matched as kop_wbf_list_has() does: the save fills in a location's city and country, so by its raw text.
        $list = array_values(array_filter(is_array($list) ? $list : array(), function ($x) use ($done) {
            return !kop_wbf_list_has(array($x), $done['value']);
        }));
        $empty = !$list;
        unset($list);
        if ($empty && !empty($done['created'])) {
            // The list was made for this addition ("customIncidents" on an empty map): take it away again.
            $parts = explode('.', $done['path']);
            $leaf = array_pop($parts);
            $parent = &kop_wbf_ref($doc, implode('.', $parts));
            if (is_array($parent)) {
                unset($parent[$leaf]);
            }
            unset($parent);
        }
    } elseif ($done['op'] === 'overwrite') {
        // Each slot gets its old value back unless someone changed it since.
        foreach (array_reverse((array) ($done['slots'] ?? array())) as $sl) {
            if (isset($sl['at'])) {
                $list = &kop_wbf_ref($doc, $sl['at']);
                foreach ((array) $list as $i => $s) {
                    if (!is_array($s) || kop_wbf_person_key($s['name'] ?? '') !== kop_wbf_person_key($sl['name'])) continue;
                    if (($s['role'] ?? null) === $sl['value']) {
                        foreach ((array) $sl['before'] as $k => $v) {
                            if ($v === null) unset($list[$i][$k]); else $list[$i][$k] = $v;
                        }
                    }
                    break;
                }
                unset($list);
            } else {
                $slot = &kop_wbf_ref($doc, $sl['path']);
                if ($slot === $sl['value']) $slot = $sl['before'];
                unset($slot);
            }
        }
    } elseif ($done['op'] === 'set_if_empty') {
        $slot = &kop_wbf_ref($doc, $done['path']);
        if ($slot === $done['value']) {
            $slot = $done['before'];
        }
    } elseif ($done['op'] === 'set_closed') {
        if (($doc['operatingPeriod']['status'] ?? '') === 'Closed') {
            $doc['operatingPeriod']['status'] = $done['before_status'] ?: 'Unknown';
        }
        if ($done['before_end'] === null) {
            $doc['operatingPeriod']['endYear'] = null;
        }
    }
}

function kop_wbf_opts() {
    global $wpdb;
    $pdo = function_exists('kop_closure_pdo') ? kop_closure_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet.');
    }
    return array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
}

/** Save a changed document the way the closure confirm does: through the legacy shape, so it comes out current. */
function kop_wbf_save(array $doc, array $opts) {
    $out = kop_facility_normalize(kop_facility_to_legacy($doc), array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    kop_facility_save($out, $opts);
}

function kop_wbf_rows(array $pkeys) {
    global $wpdb;
    $pkeys = array_values(array_filter(array_map(function ($k) { return preg_replace('/[^a-f0-9]/', '', (string) $k); }, $pkeys)));
    if (!$pkeys) {
        return array();
    }
    $in = implode(',', array_fill(0, count($pkeys), '%s'));
    return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_wbf_table() . " WHERE pkey IN ({$in})", $pkeys), ARRAY_A);
}

/**
 * Approve proposals for one facility's record. $fid overrides the build's
 * match. Returns [pkey => result].
 *
 * Since 2026-10-15 an approved item is not written onto the public record:
 * it is held ('held') with the record until the record's write-up is
 * approved (inc/record-writeups.php), which writes every held item on in one
 * save. Each item is still checked against the record as it will be (the
 * record plus the items already held), so "already there" and conflicts are
 * caught now. $hold false writes at once (the old way; tests).
 */
function kop_wbf_apply(array $rows, $fid, $reviewer, $hold = null) {
    global $wpdb;
    $fid = (int) $fid;
    if ($fid <= 0) {
        throw new RuntimeException('Pick the facility first.');
    }
    $hold = $hold === null ? kop_fact_hold_on() : (bool) $hold;
    $opts = kop_wbf_opts();
    $results = array();
    kop_v2_with_write_lock($opts['pdo'], function () use ($rows, $fid, $opts, $reviewer, $hold, &$results, $wpdb) {
        $stored = kop_facility_load($fid, $opts);
        if (!$stored) {
            throw new RuntimeException("Facility #{$fid} does not exist.");
        }
        $doc = $stored['doc'];
        if ($hold && function_exists('kop_wu_doc_with_held')) {
            $doc = kop_wu_doc_with_held($doc, $fid);
        }
        $applied = array();
        foreach ($rows as $r) {
            if ($r['status'] !== 'pending') {
                $results[$r['pkey']] = array('ok' => false, 'error' => 'Already ' . $r['status'] . '.');
                continue;
            }
            try {
                $trial = $doc;
                $done = kop_wbf_doc_apply($trial, $r);
                // Added to another record than the one it named: Undo puts it back under that one.
                if (is_array($done) && (int) $r['facility_id'] !== $fid) $done['from_fid'] = (int) $r['facility_id'];
                $doc = $trial;
                $applied[$r['pkey']] = $done;
                $results[$r['pkey']] = array('ok' => true);
            } catch (RuntimeException $e) {
                // A different value on the record is a conflict: it keeps waiting, marked. Only "the same thing
                // is there already" files it as rejected.
                $conflict = kop_wbf_conflict($doc, $r);
                $results[$r['pkey']] = $conflict !== ''
                    ? array('ok' => false, 'error' => 'Conflict: ' . $conflict, 'conflict' => $conflict)
                    : array('ok' => false, 'error' => $e->getMessage(), 'already' => true);
            }
        }
        if ($applied && !$hold) {
            kop_wbf_save($doc, $opts);
        }
        $now = current_time('mysql', true);
        foreach ($rows as $r) {
            if (isset($applied[$r['pkey']]) && $hold) {
                // Held for the write-up: the record is unchanged. 'from_fid' lets Undo put it back under the record it named.
                $held = array('held' => 1);
                if ((int) $r['facility_id'] !== $fid) $held['from_fid'] = (int) $r['facility_id'];
                $wpdb->update(kop_wbf_table(), array('status' => 'held', 'applied' => wp_json_encode($held),
                    'applied_fid' => $fid, 'facility_id' => $fid, 'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
            } elseif (isset($applied[$r['pkey']])) {
                $wpdb->update(kop_wbf_table(), array('status' => 'applied', 'applied' => wp_json_encode($applied[$r['pkey']]),
                    'applied_fid' => $fid, 'facility_id' => $fid, 'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
            } elseif (!empty($results[$r['pkey']]['conflict'])) {
                $wpdb->update(kop_wbf_table(), array('conflict' => $results[$r['pkey']]['conflict'], 'auto' => 0), array('pkey' => $r['pkey']));
            } elseif (!empty($results[$r['pkey']]['already'])) {
                $wpdb->update(kop_wbf_table(), array('status' => 'rejected', 'applied' => wp_json_encode(array('reason' => $results[$r['pkey']]['error'])),
                    'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $r['pkey']));
            }
        }
    });
    foreach ($hold ? array() : $rows as $r) {
        if (($r['op'] ?? '') === 'set_closed' && !empty($results[$r['pkey']]['ok'])) {
            do_action('kop_facility_status_changed', $fid);
            break;
        }
    }
    return $results;
}

/**
 * Staff added before entries kept their source (Woodbury Facts and Fornits
 * staff applied before 2026-10-01): give each its source and link from the
 * row that added it, and record in the row that Undo takes them off again.
 * $apply false only counts. Returns {rows, facilities, sourced, already, missing}.
 */
function kop_wbf_backfill_staff_sources($apply = false) {
    global $wpdb;
    $todo = array();
    foreach ((array) $wpdb->get_results('SELECT pkey, evidence, applied, applied_fid FROM ' . kop_wbf_table()
        . " WHERE op = 'add_staff' AND status = 'applied' AND applied_fid > 0", ARRAY_A) as $r) {
        $todo[(int) $r['applied_fid']][] = array('table' => kop_wbf_table(), 'pkey' => $r['pkey'], 'evidence' => $r['evidence'], 'applied' => $r['applied']);
    }
    if (function_exists('kop_fornits_items_table') && function_exists('kop_fornits_post_url')) {
        foreach ((array) $wpdb->get_results('SELECT pkey, topic_id, post_n, author, post_date, applied, applied_fid FROM ' . kop_fornits_items_table()
            . " WHERE kind = 'staff' AND status = 'applied' AND applied_fid > 0", ARRAY_A) as $r) {
            // The citation kop_fornits_doc_apply() hands Woodbury Facts.
            $evidence = wp_json_encode(array(array('cite' => 'Fornits forum, post by ' . ($r['author'] !== '' ? $r['author'] : 'a member')
                . (kop_fornits_month($r['post_date']) ? ', ' . kop_fornits_month($r['post_date']) : ''),
                'url' => kop_fornits_post_url($r['topic_id'], $r['post_n']))));
            $todo[(int) $r['applied_fid']][] = array('table' => kop_fornits_items_table(), 'pkey' => $r['pkey'], 'evidence' => $evidence, 'applied' => $r['applied']);
        }
    }
    $stats = array('rows' => 0, 'facilities' => 0, 'sourced' => 0, 'already' => 0, 'missing' => 0);
    $opts = kop_wbf_opts();
    foreach ($todo as $fid => $rows) {
        $stats['rows'] += count($rows);
        $work = function () use ($fid, $rows, $opts, $apply, &$stats, $wpdb) {
            $stored = kop_facility_load($fid, $opts);
            if (!$stored) {
                $stats['missing'] += count($rows);
                return;
            }
            $doc = $stored['doc'];
            $changed = array();
            foreach ($rows as $row) {
                $done = json_decode((string) $row['applied'], true);
                $at = (string) ($done['at'] ?? '');
                $key = kop_wbf_person_key($done['name'] ?? '');
                $list = &kop_wbf_ref($doc, $at);
                $found = false;
                foreach ((array) $list as $i => $s) {
                    if (!is_array($s) || $key === '' || kop_wbf_person_key($s['name'] ?? '') !== $key) {
                        continue;
                    }
                    $found = true;
                    if (!empty($s['source'])) {
                        $stats['already']++;
                    } else {
                        $list[$i] = array_merge($s, kop_wbf_source_ref(array('evidence' => $row['evidence'])));
                        if (($done['mode'] ?? '') === 'merged') {
                            $done['before'] += array('source' => null, 'sourceUrl' => null);
                        }
                        $changed[] = array($row, $done);
                        $stats['sourced']++;
                    }
                    break;
                }
                unset($list);
                if (!$found) {
                    $stats['missing']++;
                }
            }
            if ($changed && $apply) {
                kop_wbf_save($doc, $opts);
                foreach ($changed as list($row, $done)) {
                    $wpdb->update($row['table'], array('applied' => wp_json_encode($done)), array('pkey' => $row['pkey']));
                }
            }
            if ($changed) {
                $stats['facilities']++;
            }
        };
        $apply ? kop_v2_with_write_lock($opts['pdo'], $work) : $work();
    }
    return $stats;
}

/* ---- Young adult programs (inc/young-adult-programs.php) ----------------- */

/** Programs moved into or out of the young adult tab by hand: program => 1 or 0. */
function kop_wbf_ya_overrides() {
    $o = get_option('kop_wbf_ya_overrides');
    return is_array($o) ? $o : array();
}

/** Whether a program's items go on the young adult tab: a choice made by hand wins over the build's. */
function kop_wbf_ya_for($program, $build) {
    $o = kop_wbf_ya_overrides();
    return isset($o[$program]) ? (int) $o[$program] : ($build ? 1 : 0);
}

/** Move a program's waiting items onto the young adult tab (1) or off it (0), and remember it for the next build. */
function kop_wbf_ya_override($program, $on) {
    global $wpdb;
    $o = kop_wbf_ya_overrides();
    $o[(string) $program] = $on ? 1 : 0;
    update_option('kop_wbf_ya_overrides', $o, false);
    $wpdb->query($wpdb->prepare('UPDATE ' . kop_wbf_table() . " SET ya = %d WHERE program = %s AND facility_id = 0 AND status IN ('pending', 'gone')",
        $on ? 1 : 0, (string) $program));
}

/**
 * A waiting item as a young adult program's fact, and the empty fields it
 * may fill: [fact, [column => value]].
 */
function kop_wbf_ya_fact(array $r) {
    $cites = array();
    foreach (array_slice(kop_wbf_evidence($r), 0, 3) as $e) {
        $cites[] = array('label' => $e['label'], 'number' => $e['number'] ?? '', 'page' => (int) $e['page'], 'url' => $e['url'])
            + (!empty($e['cite']) ? array('cite' => $e['cite']) : array());
    }
    $v = kop_wbf_row_value($r);
    $label = $r['label'];
    if ($r['op'] === 'add_staff' && trim((string) ($v['pastJobs'] ?? '')) !== '') {
        $label .= '. Earlier: ' . trim($v['pastJobs']);
    }
    $fact = array('key' => $r['pkey'], 'group' => $r['grp'] ?: 'details', 'label' => $label, 'cites' => $cites);
    $fill = array();
    switch ($r['op'] . ' ' . $r['path']) {
        case 'set_if_empty operatingPeriod.startYear':
            $fill['opened'] = (int) $v;
            break;
        case 'set_closed operatingPeriod':
            $fill['status'] = 'Closed';
            if (!empty($v['endYear'])) {
                $fill['closed'] = (int) $v['endYear'];
            }
            break;
        case 'set_if_empty facilityDetails.ageRange':
            $fill['ages'] = (int) $v['min'] . '-' . (int) $v['max'];
            break;
        case 'set_if_empty facilityDetails.type':
            $fill['program_type'] = (string) $v;
            break;
        case 'add_list identification.pastNames':
            $fill['other_names'] = (string) $v;
            break;
        case 'add_list identification.pastOperators':
            $fill['run_by'] = (string) $v;
            break;
    }
    return array($fact, $fill);
}

/** Add checked items to a young adult program, each as a fact citing its page. Returns [pkey => result]. */
function kop_wbf_ya_file(PDO $pdo, array $rows, $yid, $reviewer) {
    global $wpdb;
    $p = $yid ? kop_ya_get($pdo, $yid) : null;
    if (!$p) {
        throw new RuntimeException('Pick the young adult program first.');
    }
    $results = array();
    $now = current_time('mysql', true);
    foreach ($rows as $r) {
        if ($r['status'] !== 'pending' || $r['grp'] === 'consultant') {
            $results[$r['pkey']] = array('ok' => false, 'error' => 'Already ' . $r['status'] . '.');
            continue;
        }
        list($fact, $fill) = kop_wbf_ya_fact($r);
        $fact['by'] = $reviewer;
        $fact['at'] = $now;
        try {
            $done = kop_ya_add_fact($pdo, (int) $yid, $fact, $fill);
        } catch (RuntimeException $e) {
            $results[$r['pkey']] = array('ok' => false, 'error' => $e->getMessage());
            continue;
        }
        $wpdb->update(kop_wbf_table(), array('status' => 'applied', 'applied_fid' => 0, 'reviewed_by' => $reviewer, 'reviewed_at' => $now,
            'applied' => wp_json_encode(array('filed' => 'young_adult', 'id' => (int) $yid, 'name' => $p['name'], 'done' => $done))),
            array('pkey' => $r['pkey']));
        $results[$r['pkey']] = array('ok' => true);
    }
    return $results;
}

/** A young adult card's "which program is it" box: the existing records, or a new one. */
function kop_wbf_ya_panel(array $first) {
    $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
    $all = $pdo && kop_ya_ready($pdo) ? kop_ya_all($pdo, null) : array();
    $match = $pdo && $all ? (kop_ya_find_by_name($pdo, $first['program']) ?: kop_ya_find_by_name($pdo, $first['program_as_written'])) : null;
    $html = '<div class="kop-wbf-pick"><select class="kop-wbf-yaid" aria-label="Young adult program"><option value="">Pick a young adult program...</option>';
    foreach ($all as $p) {
        $html .= '<option value="' . (int) $p['id'] . '"' . ($match && (int) $match['id'] === (int) $p['id'] ? ' selected' : '') . '>'
            . esc_html($p['name'] . ($p['state'] !== '' ? ' (' . $p['state'] . ')' : '')) . '</option>';
    }
    $html .= '</select> ' . kop_ak_button('Add checked to that program', 'primary', 'data-act="ya_apply"') . '</div>';
    $city = '';
    $state = $first['place'];
    if (preg_match('/^(.*?),\s*([A-Za-z .]+)$/', $first['place'], $m)) {
        $city = trim($m[1]);
        $state = trim($m[2]);
    }
    $why = (string) $first['ya_why'];
    $ages = preg_match('/^Ages:\s*(.*)$/', $why, $m) ? $m[1] : '';
    $type = $why !== '' && !preg_match('/^(Ages|Name):/', $why) ? $why : '';
    return $html . '<div class="kop-wbf-create"><strong>Not on the list yet? Create it:</strong><div class="kop-wbf-fields">'
        . '<label class="kop-wbf-wide">Name <input type="text" class="kop-wbf-yname" value="' . esc_attr($first['program']) . '"></label>'
        . '<label>City <input type="text" class="kop-wbf-ycity" value="' . esc_attr($city) . '"></label>'
        . '<label>State <input type="text" class="kop-wbf-ystate" value="' . esc_attr($state) . '"></label>'
        . '<label>or country <input type="text" class="kop-wbf-ycountry"></label>'
        . '<label>Ages <input type="text" class="kop-wbf-yages" value="' . esc_attr($ages) . '"></label>'
        . '<label class="kop-wbf-wide">Described as <input type="text" class="kop-wbf-ytype" value="' . esc_attr(mb_substr($type, 0, 255)) . '"></label>'
        . '</div>' . kop_ak_button('Create the young adult program and add checked', 'secondary', 'data-act="ya_create"') . '</div>';
}

/** The kinds of record a card can create: a program, or what Woodbury wrote about that is not one. */
function kop_wbf_create_kinds() {
    return array(
        'facility'   => 'Program (TTI facility)',
        'consultant' => 'Educational consultant: a firm',
        'person'     => 'Educational consultant: one person',
        'company'    => 'Parent company (operator)',
        'transporter' => 'Transporter (youth transport)',
        'provider'   => 'Mental health provider',
        'marketer'   => 'Marketing company',
        'tradeorg'   => 'Trade organization',
    );
}

/**
 * Create an educational consultant or mental health provider record
 * (inc/woodbury-create.php) for a card that is about one, and file the
 * checked items in its notes, each with its issue page. Undo puts the items
 * back to review; the record stays, as it does for a filed mention.
 */
function kop_wbf_file_items(array $rows, $kind, array $f, $reviewer) {
    global $wpdb;
    if (!function_exists('kop_wbc_create')) {
        throw new RuntimeException('Record creation is not available.');
    }
    $rows = array_values(array_filter($rows, function ($r) { return $r['status'] === 'pending' && $r['grp'] !== 'consultant'; }));
    if (!$rows) {
        throw new RuntimeException('Tick at least one waiting item.');
    }
    $source = kop_wbf_create_source(kop_wbf_evidence($rows[0]));
    $f['kind'] =$kind === 'person' ? 'consultant' : $kind;
    $f['who'] = $kind === 'person' ? 'person' : 'firm';
    $f['notes'] = array_map('kop_wbf_source_line', $rows);
    $target = kop_wbc_create($source, $f);
    $now = current_time('mysql', true);
    $results = array();
    foreach ($rows as $r) {
        $wpdb->update(kop_wbf_table(), array('status' => 'applied', 'applied_fid' => 0, 'reviewed_by' => $reviewer, 'reviewed_at' => $now,
            'applied' => wp_json_encode(array('filed' => $target['kind'], 'who' => $f['who'], 'id' => (int) $target['id'], 'name' => $target['name']))),
            array('pkey' => $r['pkey']));
        $results[$r['pkey']] = array('ok' => true);
    }
    return array('results' => $results, 'target' => $target);
}

/** "the educational consultant record Jane Doe": where a filed item went, or ''. */
function kop_wbf_filed_on(array $r) {
    $done = json_decode((string) $r['applied'], true);
    if (!is_array($done) || empty($done['filed'])) {
        return '';
    }
    $kinds = array('consultant' => 'educational consultant', 'provider' => 'mental health provider', 'young_adult' => 'young adult program',
        'company' => 'parent company', 'transporter' => 'transporter', 'marketer' => 'marketing company', 'tradeorg' => 'trade organization');
    if (!empty($done['existing'])) return 'the notes of the ' . ($kinds[$done['filed']] ?? $done['filed']) . ' record "' . $done['name'] . '" (#' . (int) $done['id'] . ')';
    return 'the ' . ($kinds[$done['filed']] ?? $done['filed']) . ' record "' . $done['name'] . '" (#' . (int) $done['id'] . ')';
}

function kop_wbf_undo(array $rows, $reviewer) {
    global $wpdb;
    $opts = kop_wbf_opts();
    $by = array();
    foreach ($rows as $r) {
        // Something taken back by hand is never added automatically again.
        $wpdb->update(kop_wbf_table(), array('auto' => 0), array('pkey' => $r['pkey']));
        if ($r['status'] === 'applied' && kop_wbf_filed_on($r) !== '') {
            // Filed in a consultant or provider record's notes: back to review, the record and its notes stay.
            // Filed on a record that was there already: the note it added comes off again.
            $done = json_decode((string) $r['applied'], true);
            if (!empty($done['existing']) && !empty($done['lines']) && function_exists('kop_wbc_remove_notes')) {
                kop_wbc_remove_notes((string) $done['filed'], (int) $done['id'], (array) $done['lines']);
            }
            // On a young adult program: the fact comes off it again, with any field it filled.
            $done = json_decode((string) $r['applied'], true);
            if (($done['filed'] ?? '') === 'young_adult' && !empty($done['done']) && function_exists('kop_ya_remove_fact') && kop_ya_pdo()) {
                kop_ya_remove_fact(kop_ya_pdo(), $done['done']);
            }
            $wpdb->update(kop_wbf_table(), array('status' => 'pending', 'applied' => null, 'reviewed_by' => $reviewer,
                'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
        } elseif ($r['status'] === 'applied' && $r['grp'] === 'consultant') {
            kop_wbf_consultant_undo($r);
            $wpdb->update(kop_wbf_table(), array('status' => 'pending', 'applied' => null, 'reviewed_by' => $reviewer,
                'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
        } elseif ($r['status'] === 'applied') {
            $by[(int) $r['applied_fid']][] = $r;
        } elseif ($r['status'] === 'rejected') {
            $wpdb->update(kop_wbf_table(), array('status' => 'pending', 'applied' => null, 'reviewed_by' => $reviewer,
                'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
        } elseif ($r['status'] === 'held') {
            // Held for a write-up: never on the record, so only the item goes back.
            $held = json_decode((string) $r['applied'], true);
            $back = array('status' => 'pending', 'applied' => null, 'applied_fid' => 0, 'reviewed_by' => $reviewer,
                'reviewed_at' => current_time('mysql', true));
            if (is_array($held) && !empty($held['from_fid'])) $back['facility_id'] = (int) $held['from_fid'];
            $wpdb->update(kop_wbf_table(), $back, array('pkey' => $r['pkey']));
        }
    }
    foreach ($by as $fid => $list) {
        kop_v2_with_write_lock($opts['pdo'], function () use ($fid, $list, $opts, $reviewer, $wpdb) {
            $stored = kop_facility_load($fid, $opts);
            if (!$stored) {
                throw new RuntimeException("Facility #{$fid} does not exist.");
            }
            $doc = $stored['doc'];
            foreach (array_reverse($list) as $r) {
                $done = json_decode((string) $r['applied'], true);
                if (is_array($done) && isset($done['op'])) {
                    kop_wbf_doc_undo($doc, $done);
                }
            }
            kop_wbf_save($doc, $opts);
            foreach ($list as $r) {
                $done = json_decode((string) $r['applied'], true);
                $back = array('status' => 'pending', 'applied' => null, 'applied_fid' => 0,
                    'reviewed_by' => $reviewer, 'reviewed_at' => current_time('mysql', true));
                if (is_array($done) && isset($done['from_fid'])) $back['facility_id'] = (int) $done['from_fid'];
                $wpdb->update(kop_wbf_table(), $back, array('pkey' => $r['pkey']));
            }
        });
        do_action('kop_facility_status_changed', $fid);
    }
}

/* ---- Automatic additions --------------------------------------------------- */

/**
 * SQL for the items added with no click (owner, 2026-10-12, restated
 * 2026-10-14: an exact name is approved automatically). The build's plainly
 * stated items (auto = 1), and every item whose program is exactly one
 * record's name (or a past name of it, with no other doubt noted), quote found
 * in the source, nothing else it could be, no conflict, not a young adult
 * program, and never one a person has sent back, edited or settled. From any
 * source (Woodbury, HEAL, the wiki). Each lands on the "Added automatically"
 * tab with Undo; an undone item keeps its reviewer and is never added again.
 */
function kop_wbf_auto_where() {
    return "status = 'pending' AND facility_id > 0 AND (auto = 1 OR (found = 1 AND ya = 0
        AND (conflict IS NULL OR conflict = '') AND (alternatives IS NULL OR alternatives = '' OR alternatives = '[]')
        AND (reviewed_by IS NULL OR reviewed_by = '') AND INSTR(COALESCE(extra, ''), '\"edited_by\"') = 0
        AND ((match_kind = 'exact' AND match_note = '')
            OR (match_kind = 'past_name' AND match_note = 'Header uses a past name of this record (a different name era).'))))";
}

/**
 * Add those items, one facility at a time until $seconds run out. Runs hourly
 * and for a moment whenever the screen opens.
 */
function kop_wbf_auto_run($seconds = 40) {
    global $wpdb;
    if (get_transient('kop_wbf_auto_lock')) {
        return 0;
    }
    set_transient('kop_wbf_auto_lock', 1, 5 * MINUTE_IN_SECONDS);
    $table = kop_wbf_table();
    $start = microtime(true);
    $added = 0;
    try {
        $fids = $wpdb->get_col("SELECT DISTINCT facility_id FROM {$table} WHERE " . kop_wbf_auto_where() . ' LIMIT 200');
        foreach ($fids as $fid) {
            if (microtime(true) - $start > $seconds) {
                break;
            }
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE " . kop_wbf_auto_where() . ' AND facility_id = %d', (int) $fid), ARRAY_A);
            try {
                foreach (kop_wbf_apply($rows, (int) $fid, 'auto') as $res) {
                    $added += !empty($res['ok']) ? 1 : 0;
                }
            } catch (Throwable $e) {
                // Leave them for a person, with the reason.
                error_log('kop woodbury facts auto #' . (int) $fid . ': ' . $e->getMessage());
                foreach ($rows as $r) {
                    $wpdb->update($table, array('auto' => 0, 'conflict' => 'Could not be added automatically: ' . $e->getMessage()), array('pkey' => $r['pkey']));
                }
            }
        }
    } finally {
        delete_transient('kop_wbf_auto_lock');
    }
    return $added;
}

/**
 * File every waiting item that would add nothing to its record as "already on
 * the record": Add would refuse it, word for word or in other words
 * (kop_wbf_on_record(), kop_wbf_list_covers(), kop_wbf_job_held()). A record
 * holding a different value is a conflict and keeps waiting; an item a
 * person sent back or edited is left for them. Each lands on the Rejected tab
 * with its reason; "Back to review" there (Undo) brings it back, and it is
 * never cleared again. $apply false only counts.
 * Returns {checked, cleared, by_kind: {op => n}, examples: [label => why], done: bool}.
 */
function kop_wbf_clear_on_record($apply = false, $seconds = 60) {
    global $wpdb;
    $out = array('checked' => 0, 'cleared' => 0, 'by_kind' => array(), 'examples' => array(), 'done' => true);
    if (!function_exists('kop_wbf_on_record')) return $out;
    $table = kop_wbf_table();
    $start = microtime(true);
    $opts = kop_wbf_opts();
    $fids = $wpdb->get_col("SELECT DISTINCT facility_id FROM {$table} WHERE status = 'pending' AND facility_id > 0
        AND op IN ('add_staff', 'add_list', 'set_if_empty', 'set_closed') AND (reviewed_by IS NULL OR reviewed_by = '')
        AND INSTR(COALESCE(extra, ''), '\"edited_by\"') = 0 ORDER BY facility_id");
    $now = current_time('mysql', true);
    foreach ($fids as $fid) {
        if (microtime(true) - $start > $seconds) {
            $out['done'] = false;
            break;
        }
        $stored = kop_facility_load((int) $fid, $opts);
        if (!$stored) continue;
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = 'pending' AND facility_id = %d
            AND op IN ('add_staff', 'add_list', 'set_if_empty', 'set_closed') AND (reviewed_by IS NULL OR reviewed_by = '')
            AND INSTR(COALESCE(extra, ''), '\"edited_by\"') = 0", (int) $fid), ARRAY_A);
        foreach ($rows as $r) {
            $out['checked']++;
            $why = kop_wbf_on_record($stored['doc'], $r);
            if ($why === '' || kop_wbf_conflict_parts($stored['doc'], $r)) continue;
            $out['cleared']++;
            $out['by_kind'][$r['op']] = ($out['by_kind'][$r['op']] ?? 0) + 1;
            if (count($out['examples']) < 5000) $out['examples'][] = '#' . (int) $fid . ' ' . $r['label'] . '  ->  ' . $why;
            if ($apply) {
                $wpdb->update($table, array('status' => 'rejected', 'auto' => 0, 'reviewed_by' => 'auto', 'reviewed_at' => $now,
                    'applied' => wp_json_encode(array('reason' => 'Already on the record: ' . $why . '.', 'cleared' => 'on_record'))),
                    array('pkey' => $r['pkey'], 'status' => 'pending'));
            }
        }
    }
    if ($apply && $out['cleared'] && function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
    return $out;
}

add_action('init', function () {
    if (!wp_next_scheduled('kop_wbf_auto_hourly')) {
        wp_schedule_event(time() + 300, 'hourly', 'kop_wbf_auto_hourly');
    }
});
add_action('kop_wbf_auto_hourly', function () {
    kop_wbf_ensure_table();
    kop_wbf_sync();
    kop_wbf_moved_to_ya();
    kop_wbf_auto_run(120);
    // What a new load or another source's Add made redundant leaves the waiting list.
    kop_wbf_clear_on_record(true, 90);
});

/* ---- Educational consultants with an industry past ----------------------- */

function kop_wbf_referrer_table(PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/api/lib-suggested-edits.php';
    $table = kop_resolve_table_name($pdo, 'referrers_master', $wpdb->prefix);
    kop_ensure_master_table($pdo, $table);
    return $table;
}

/** The person entries of a consultant record that are this person: referrerIndividual and referrerConsultants[]. */
function kop_wbf_consultant_entries(array &$data, $key) {
    $refs = array();
    if (isset($data['referrerIndividual']) && is_array($data['referrerIndividual'])) {
        $c = $data['referrerIndividual'];
        $name = $c['fullName'] ?? trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''));
        if (kop_wbf_person_key($name) === $key) {
            $refs[] = &$data['referrerIndividual'];
        }
    }
    if (isset($data['referrerConsultants']) && is_array($data['referrerConsultants'])) {
        foreach ($data['referrerConsultants'] as $i => $c) {
            $name = is_array($c) ? ($c['fullName'] ?? trim(($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? ''))) : '';
            if (kop_wbf_person_key($name) === $key) {
                $refs[] = &$data['referrerConsultants'][$i];
            }
        }
    }
    return $refs;
}

/**
 * Flag a consultant as former industry staff: their programs go into
 * pastTTIJobs (the directory's "Career History"), formerIndustryStaff is set
 * and the issue is cited in their notes. A consultant with no record gets one
 * (kop_wbc_create_consultant, which also files it under Educational
 * Consultants). Returns what Undo needs.
 */
function kop_wbf_consultant_apply(array $r) {
    $v = kop_wbf_row_value($r);
    $pdo = function_exists('kop_closure_pdo') ? kop_closure_pdo() : null;
    if (!$pdo) {
        throw new RuntimeException('No database connection.');
    }
    $table = kop_wbf_referrer_table($pdo);
    $key = kop_wbf_person_key($v['name'] ?? '');
    if ($key === '') {
        throw new RuntimeException('No name to flag.');
    }
    $rid = (int) ($v['referrer_id'] ?? 0);
    $created = false;
    if ($rid <= 0) {
        // A record made since the build may name them already.
        $find = $pdo->prepare("SELECT id FROM `{$table}` WHERE LOWER(unique_name) = LOWER(?) OR json_data LIKE ? LIMIT 1");
        $find->execute(array($v['name'], '%"' . str_replace(array('%', '_'), array('\%', '\_'), $v['name']) . '"%'));
        $rid = (int) $find->fetchColumn();
    }
    if ($rid <= 0) {
        if (!function_exists('kop_wbc_create_consultant')) {
            throw new RuntimeException('Record creation is not available.');
        }
        $state = ($v['state'] ?? '') !== '' ? (string) kop_facility_state_code($v['state']) : '';
        $made = kop_wbc_create_consultant(
            kop_wbf_create_source(kop_wbf_evidence($r)),
            array('name' => $v['name'], 'who' => 'person', 'city' => (string) ($v['city'] ?? ''), 'state' => $state, 'country' => ''),
            $pdo
        );
        $rid = (int) $made['id'];
        $created = true;
    }
    $row = $pdo->prepare("SELECT unique_name, json_data FROM `{$table}` WHERE id = ?");
    $row->execute(array($rid));
    $rec = $row->fetch(PDO::FETCH_ASSOC);
    if (!$rec) {
        throw new RuntimeException("Consultant record #{$rid} does not exist.");
    }
    $before = (string) $rec['json_data'];
    $payload = json_decode($before, true);
    if (!is_array($payload)) {
        throw new RuntimeException('The consultant record could not be read.');
    }
    if (!isset($payload['data']) || !is_array($payload['data'])) {
        $payload['data'] = array();
    }
    $data = &$payload['data'];
    $entries = kop_wbf_consultant_entries($data, $key);
    if (!$entries) {
        // A firm's record without this person on it: add them to its consultants.
        $bits = explode(' ', trim($v['name']));
        $last = count($bits) > 1 ? array_pop($bits) : '';
        $data['referrerConsultants'] = isset($data['referrerConsultants']) && is_array($data['referrerConsultants']) ? $data['referrerConsultants'] : array();
        $data['referrerConsultants'][] = array('firstName' => implode(' ', $bits), 'lastName' => $last, 'fullName' => trim($v['name']),
            'role' => 'Educational Consultant', 'credentials' => (string) ($v['credentials'] ?? ''), 'pastTTIJobs' => array(), 'notes' => '');
        $entries = kop_wbf_consultant_entries($data, $key);
    }
    $cite = kop_wbf_cite($r);
    $added = 0;
    foreach ($entries as &$entry) {
        $jobs = isset($entry['pastTTIJobs']) && is_array($entry['pastTTIJobs']) ? $entry['pastTTIJobs'] : array();
        $have = array();
        foreach ($jobs as $j) {
            $have[] = strtolower(trim(is_array($j) ? ($j['organization'] ?? $j['employer'] ?? '') : (string) $j));
        }
        foreach ((array) ($v['jobs'] ?? array()) as $j) {
            $org = trim((string) ($j['organization'] ?? ''));
            if ($org === '' || in_array(strtolower($org), $have, true)) {
                continue;
            }
            $role = trim((string) ($j['role'] ?? '')) . (($j['when'] ?? '') !== '' ? ' (' . $j['when'] . ')' : '');
            $jobs[] = array('role' => $role, 'organization' => $org, 'employer' => $org, 'source' => 'Woodbury Reports');
            $have[] = strtolower($org);
            $added++;
        }
        $entry['pastTTIJobs'] = $jobs;
        $entry['formerIndustryStaff'] = true;
        $line = 'Worked in the troubled teen industry before or while consulting, per ' . $cite;
        $notes = (string) ($entry['notes'] ?? '');
        if (strpos($notes, $line) === false) {
            $entry['notes'] = trim($notes . ($notes !== '' ? "\n" : '') . $line);
        }
    }
    unset($entry);
    unset($data);
    $after = wp_json_encode($payload);
    $pdo->prepare("UPDATE `{$table}` SET json_data = ?, updated_at = NOW() WHERE id = ?")->execute(array($after, $rid));
    kop_wbf_consultant_mirror($pdo, $rec['unique_name'], $payload, $key);
    return array('op' => 'consultant_jobs', 'referrer_id' => $rid, 'created' => $created, 'added' => $added,
        'before' => $before, 'after_md5' => md5($after), 'unique_name' => $rec['unique_name']);
}

/** State pages list consultants from their state's locations_master row: keep that copy in step. */
function kop_wbf_consultant_mirror(PDO $pdo, $project, array $payload, $key) {
    global $wpdb;
    if (!function_exists('kop_wbc_mirror_consultant')) {
        return;
    }
    $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();
    foreach (kop_wbf_consultant_entries($data, $key) as $entry) {
        $state = (string) ($entry['state'] ?? '');
        $code = $state !== '' ? (string) kop_facility_state_code($state) : '';
        if ($code !== '') {
            kop_wbc_mirror_consultant($pdo, $wpdb->prefix, $project, $code, $entry);
        }
        break;
    }
}

function kop_wbf_consultant_undo(array $r) {
    $done = json_decode((string) $r['applied'], true);
    if (!is_array($done) || empty($done['referrer_id'])) {
        return;
    }
    $pdo = kop_closure_pdo();
    $table = kop_wbf_referrer_table($pdo);
    $row = $pdo->prepare("SELECT json_data FROM `{$table}` WHERE id = ?");
    $row->execute(array((int) $done['referrer_id']));
    $now = (string) $row->fetchColumn();
    if ($now !== '' && md5($now) !== $done['after_md5']) {
        throw new RuntimeException('The consultant record was edited since; change it in the data form instead.');
    }
    $pdo->prepare("UPDATE `{$table}` SET json_data = ?, updated_at = NOW() WHERE id = ?")->execute(array($done['before'], (int) $done['referrer_id']));
    $payload = json_decode($done['before'], true);
    if (is_array($payload)) {
        kop_wbf_consultant_mirror($pdo, $done['unique_name'], $payload, kop_wbf_person_key(kop_wbf_row_value($r)['name'] ?? ''));
    }
}

/* ---- AJAX --------------------------------------------------------------- */

/* ---- Correcting an item before it is added ----------------------------- */

/** "Owner: ... (Woodbury Reports, May 2007, p. 20)" -> "Owner: ...": the words a list item shows, without its date and citation. */
function kop_wbf_plain_text($text) {
    $text = preg_replace('/\s*\((?:Woodbury Reports|HEAL|r\/troubledteens wiki)[^()]*(?:\([^()]*\)[^()]*)?\)\s*$/', '', trim((string) $text));
    return trim(preg_replace('/^(\d{4}(-\d\d){0,2}|Reported [A-Z][a-z]+ \d{4}):\s*/', '', $text));
}

/** The label an item shows, rebuilt from a corrected value in the build's words. */
function kop_wbf_label_for(array $r, $v) {
    $old = (string) $r['label'];
    // Unchanged: the build's label, which can say more ("the record says 2001").
    if (json_encode(kop_wbf_row_value($r)) === json_encode($v)) {
        return $old;
    }
    switch ($r['op'] . ' ' . $r['path']) {
        case 'set_if_empty operatingPeriod.startYear':
            return 'Start year: ' . $v;
        case 'set_if_empty facilityDetails.capacity':
            return 'Capacity: ' . $v . (preg_match('/ \(as of \d{4}\)$/', $old, $m) ? $m[0] : '');
        case 'set_if_empty facilityDetails.ageRange':
            return 'Ages ' . $v['min'] . '-' . $v['max'];
        case 'set_if_empty facilityDetails.gender':
            return 'Serves: ' . $v;
        case 'set_if_empty facilityDetails.type':
            return 'Type: ' . $v;
        case 'set_closed operatingPeriod':
            return !empty($v['endYear']) ? 'Closed in ' . $v['endYear'] . ' (sets status Closed, end year ' . $v['endYear'] . ')' : 'Closed (sets status Closed)';
        case 'add_list identification.pastNames':
            return 'Past name: ' . $v;
        case 'add_list identification.pastOperators':
            return 'Operator/owner: ' . $v . (preg_match('/ \([^()]*\d{4}[^()]*\)$/', $old, $m) ? $m[0] : '');
        case 'add_list location.formerLocations':
            // The build's label: "Former location: Denver (moved 1968)".
            return 'Former location: ' . $v['raw'] . (!empty($v['toYear']) ? ' (moved ' . (int) $v['toYear'] . ')' : '');
    }
    if ($r['op'] === 'add_staff') {
        return $v['name'] . ($v['role'] !== '' ? ', ' . $v['role'] : '') . (preg_match('/ \(died [^()]*\)$/', $old, $m) ? $m[0] : '');
    }
    if ($r['op'] === 'consultant_jobs') {
        $jobs = array();
        foreach ($v['jobs'] as $j) {
            $jobs[] = $j['role'] . ' at ' . $j['organization'] . ($j['when'] !== '' ? ' (' . $j['when'] . ')' : '');
        }
        return $v['name'] . ', educational consultant' . ($v['practice'] !== '' ? ' (' . $v['practice'] . ')' : '') . ': ' . implode('; ', $jobs);
    }
    return kop_wbf_plain_text(is_string($v) ? $v : $old);
}

/** One field of the edit form: [key, label, kind (text|area|num|select), value, options]. */
function kop_wbf_edit_fields(array $r) {
    $v = kop_wbf_row_value($r);
    $years = function ($y) { return $y === null || $y === '' ? '' : (string) (int) $y; };
    switch ($r['op'] . ' ' . $r['path']) {
        case 'set_if_empty operatingPeriod.startYear':
            return array(array('year', 'Start year', 'num', $years($v)));
        case 'set_if_empty facilityDetails.capacity':
            return array(array('capacity', 'Capacity', 'num', (string) (int) $v));
        case 'set_if_empty facilityDetails.ageRange':
            return array(array('min', 'Youngest age', 'num', (string) ($v['min'] ?? '')), array('max', 'Oldest age', 'num', (string) ($v['max'] ?? '')));
        case 'set_if_empty facilityDetails.gender':
            return array(array('gender', 'Serves', 'select', (string) $v, array('Male', 'Female', 'Co-ed')));
        case 'set_if_empty facilityDetails.type':
            // A program can be several types: 'multi' is a box per type (the value "A, B").
            return array(array('type', 'Type (one or several)', 'multi', (string) $v, function_exists('kop_facdisc_types') ? kop_facdisc_types() : array((string) $v)));
        case 'set_closed operatingPeriod':
            return array(array('endYear', 'Closed in (year, may be empty)', 'num', $years($v['endYear'] ?? '')));
        case 'add_list location.formerLocations':
            return array(array('raw', 'Place as written', 'text', (string) ($v['raw'] ?? '')),
                array('city', 'City', 'text', (string) ($v['city'] ?? '')), array('state', 'State', 'text', (string) ($v['state'] ?? '')),
                array('country', 'Country, outside the US', 'text', (string) ($v['country'] ?? '')),
                array('fromYear', 'From (year)', 'num', $years($v['fromYear'] ?? '')), array('toYear', 'To (year)', 'num', $years($v['toYear'] ?? '')));
    }
    if ($r['op'] === 'add_staff') {
        return array(
            array('name', 'Name', 'text', (string) ($v['name'] ?? '')),
            array('role', 'Role', 'text', (string) ($v['role'] ?? '')),
            array('pastJobs', 'Past jobs', 'area', (string) ($v['pastJobs'] ?? '')),
            array('where', 'List', 'select', $r['path'], array('staff.administrator' => 'Administrators', 'staff.notableStaff' => 'Notable staff')),
        );
    }
    if ($r['op'] === 'consultant_jobs') {
        $lines = array();
        foreach ((array) ($v['jobs'] ?? array()) as $j) {
            $lines[] = trim($j['role'] ?? '') . ' | ' . trim($j['organization'] ?? '') . ' | ' . trim($j['when'] ?? '');
        }
        return array(
            array('name', 'Name', 'text', (string) ($v['name'] ?? '')),
            array('credentials', 'Credentials', 'text', (string) ($v['credentials'] ?? '')),
            array('practice', 'Consulting practice', 'text', (string) ($v['practice'] ?? '')),
            array('city', 'City', 'text', (string) ($v['city'] ?? '')),
            array('state', 'State', 'text', (string) ($v['state'] ?? '')),
            array('jobs', 'Industry jobs, one a line: Role | Program | Year', 'area', implode("\n", $lines)),
        );
    }
    if ($r['op'] === 'add_list' && is_string($v)) {
        return array(array('text', 'Text', in_array($r['path'], array('identification.pastNames', 'identification.pastOperators'), true) ? 'text' : 'area', $v));
    }
    return array();
}

/**
 * The corrected value from the edit form's fields, checked as the record
 * would check it. Returns [value, path].
 */
function kop_wbf_edited_value(array $r, array $f) {
    $old = kop_wbf_row_value($r);
    $t = function ($k) use ($f) { return trim(preg_replace('/[ \t]+/', ' ', (string) ($f[$k] ?? ''))); };
    $year = function ($k, $blank_ok) use ($t) {
        $s = $t($k);
        if ($s === '' && $blank_ok) {
            return null;
        }
        if (!preg_match('/^\d{4}$/', $s) || (int) $s < 1850 || (int) $s > (int) gmdate('Y') + 1) {
            throw new RuntimeException('"' . $s . '" is not a year.');
        }
        return (int) $s;
    };
    $path = $r['path'];
    switch ($r['op'] . ' ' . $r['path']) {
        case 'set_if_empty operatingPeriod.startYear':
            return array($year('year', false), $path);
        case 'set_if_empty facilityDetails.capacity':
            $n = $t('capacity');
            if (!preg_match('/^\d+$/', $n) || (int) $n < 1 || (int) $n > 5000) {
                throw new RuntimeException('Capacity must be a number of beds or students.');
            }
            return array((int) $n, $path);
        case 'set_if_empty facilityDetails.ageRange':
            $min = $t('min');
            $max = $t('max');
            if (!preg_match('/^\d+$/', $min) || !preg_match('/^\d+$/', $max) || (int) $min > (int) $max || (int) $max > 30) {
                throw new RuntimeException('Ages must be two numbers, youngest first.');
            }
            return array(array('min' => (int) $min, 'max' => (int) $max), $path);
        case 'set_if_empty facilityDetails.gender':
            if (!in_array($t('gender'), array('Male', 'Female', 'Co-ed'), true)) {
                throw new RuntimeException('Choose Male, Female or Co-ed.');
            }
            return array($t('gender'), $path);
        case 'set_if_empty facilityDetails.type':
            $type = function_exists('kop_facdisc_types_value') ? kop_facdisc_types_value($f['type'] ?? '') : $t('type');
            if ($type === '') throw new RuntimeException('Choose one or more of the agreed facility types.');
            return array($type, $path);
        case 'set_closed operatingPeriod':
            return array(array('endYear' => $year('endYear', true)), $path);
        case 'add_list location.formerLocations':
            $state = $t('state');
            if ($state !== '') {
                $code = function_exists('kop_facility_state_code') ? (string) kop_facility_state_code($state) : strtoupper($state);
                if ($code === '') {
                    throw new RuntimeException('"' . $state . '" is not a US state. Leave it empty and give the country instead.');
                }
                $state = $code;
            }
            $raw = $t('raw') !== '' ? $t('raw') : implode(', ', array_filter(array($t('city'), $state !== '' ? $state : $t('country'))));
            $v = array('raw' => $raw, 'city' => $t('city'),
                'state' => $state !== '' ? $state : null, 'country' => $t('country') !== '' ? $t('country') : null,
                'fromYear' => $year('fromYear', true), 'toYear' => $year('toYear', true));
            if ($v['raw'] === '') {
                throw new RuntimeException('Give a city, a state or a country.');
            }
            return array($v, $path);
    }
    if ($r['op'] === 'add_staff') {
        if ($t('name') === '') {
            throw new RuntimeException('A name is needed.');
        }
        $where = in_array($f['where'] ?? '', array('staff.administrator', 'staff.notableStaff'), true) ? $f['where'] : $path;
        return array(array('name' => $t('name'), 'role' => $t('role'), 'pastJobs' => $t('pastJobs')), $where);
    }
    if ($r['op'] === 'consultant_jobs') {
        if ($t('name') === '') {
            throw new RuntimeException('A name is needed.');
        }
        $known = array();
        foreach ((array) ($old['jobs'] ?? array()) as $j) {
            $known[strtolower(trim($j['organization'] ?? ''))] = (int) ($j['facility_id'] ?? 0);
        }
        $jobs = array();
        foreach (preg_split('/\R/', (string) ($f['jobs'] ?? '')) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $bits = array_map('trim', explode('|', $line));
            if (count($bits) < 2 || $bits[1] === '') {
                throw new RuntimeException('Each job line needs Role | Program | Year: "' . trim($line) . '"');
            }
            $jobs[] = array('role' => $bits[0], 'organization' => $bits[1], 'when' => $bits[2] ?? '',
                'facility_id' => $known[strtolower($bits[1])] ?? 0);
        }
        if (!$jobs) {
            throw new RuntimeException('Give at least one job.');
        }
        $v = is_array($old) ? $old : array();
        foreach (array('name', 'credentials', 'practice', 'city', 'state') as $k) {
            $v[$k] = $t($k);
        }
        $v['jobs'] = $jobs;
        return array($v, $path);
    }
    if ($r['op'] === 'add_list' && is_string($old)) {
        $text = trim((string) ($f['text'] ?? ''));
        if ($text === '') {
            throw new RuntimeException('The text is empty. Reject the item instead.');
        }
        return array($text, $path);
    }
    throw new RuntimeException('This item cannot be edited.');
}

/**
 * Save a correction on a waiting item: its value, where it goes and its
 * label. The build's own version is kept in extra.original (Reset puts it
 * back), a corrected item is never added automatically, and a rebuild leaves
 * it alone.
 */
function kop_wbf_edit(array $r, array $f, $reviewer) {
    global $wpdb;
    if ($r['status'] !== 'pending') {
        throw new RuntimeException('Only waiting items can be edited. Undo it first.');
    }
    $extra = json_decode((string) $r['extra'], true) ?: array();
    list($value, $path) = kop_wbf_edited_value($r, $f);
    $old_value = kop_wbf_row_value($r);
    if (!isset($extra['original'])) {
        $extra['original'] = array('value' => $old_value, 'label' => $r['label'], 'path' => $r['path']);
    }
    // A rename, owner or move also leaves a line in the notes: keep it naming the corrected value.
    if (!empty($extra['note_line']) && is_string($old_value) && is_string($value) && $old_value !== '') {
        $extra['note_line'] = str_replace($old_value, $value, $extra['note_line']);
    }
    $extra['edited_by'] = $reviewer;
    $extra['edited_at'] = current_time('mysql', true);
    $r['path'] = $path;
    $label = kop_wbf_label_for($r, $value);
    $wpdb->update(kop_wbf_table(), array('value' => wp_json_encode($value), 'path' => $path, 'label' => $label,
        'extra' => wp_json_encode($extra), 'auto' => 0), array('pkey' => $r['pkey']));
    $r['value'] = wp_json_encode($value);
    $r['label'] = $label;
    $r['extra'] = wp_json_encode($extra);
    return $r;
}

/** Put a corrected item back as the build read it. */
function kop_wbf_edit_reset(array $r) {
    global $wpdb;
    $extra = json_decode((string) $r['extra'], true) ?: array();
    if ($r['status'] !== 'pending' || !isset($extra['original'])) {
        throw new RuntimeException('Nothing to reset.');
    }
    $o = $extra['original'];
    if (!empty($extra['note_line']) && is_string($o['value']) && is_string(kop_wbf_row_value($r))) {
        $extra['note_line'] = str_replace(kop_wbf_row_value($r), $o['value'], $extra['note_line']);
    }
    unset($extra['original'], $extra['edited_by'], $extra['edited_at']);
    $wpdb->update(kop_wbf_table(), array('value' => wp_json_encode($o['value']), 'path' => $o['path'], 'label' => $o['label'],
        'extra' => wp_json_encode($extra)), array('pkey' => $r['pkey']));
    $r['value'] = wp_json_encode($o['value']);
    $r['path'] = $o['path'];
    $r['label'] = $o['label'];
    $r['extra'] = wp_json_encode($extra);
    return $r;
}

/** The Edit box under a waiting item. */
function kop_wbf_edit_form(array $r) {
    $fields = kop_wbf_edit_fields($r);
    if (!$fields) {
        return '';
    }
    $extra = json_decode((string) $r['extra'], true) ?: array();
    $html = '<details class="kop-wbf-edit"><summary>Edit' . (isset($extra['original']) ? ' (corrected)' : '') . '</summary><div class="kop-wbf-edit-box">';
    foreach ($fields as $fd) {
        list($key, $label, $kind, $value) = $fd;
        $html .= '<label>' . esc_html($label) . ' ';
        if ($kind === 'area') {
            $html .= '<textarea data-f="' . esc_attr($key) . '" rows="' . ($key === 'text' ? 4 : 3) . '">' . esc_textarea($value) . '</textarea>';
        } elseif ($kind === 'multi') {
            // Several at once (a program's types): Ctrl/Cmd-click; the script sends the picked ones as "A, B".
            $have = function_exists('kop_facility_types_split') ? kop_facility_types_split($value) : array($value);
            $html .= '<select data-f="' . esc_attr($key) . '" multiple size="6">';
            foreach ($fd[4] as $ok => $ol) {
                $ov = is_int($ok) ? $ol : $ok;
                $html .= '<option value="' . esc_attr($ov) . '"' . (in_array((string) $ov, $have, true) ? ' selected' : '') . '>' . esc_html($ol) . '</option>';
            }
            $html .= '</select>';
        } elseif ($kind === 'select') {
            $html .= '<select data-f="' . esc_attr($key) . '">';
            $opts = $fd[4];
            if (!in_array($value, array_keys($opts), true) && !in_array($value, $opts, true)) {
                $html .= '<option value="' . esc_attr($value) . '" selected>' . esc_html($value) . '</option>';
            }
            foreach ($opts as $ok => $ol) {
                $ov = is_int($ok) ? $ol : $ok;
                $html .= '<option value="' . esc_attr($ov) . '"' . ((string) $ov === (string) $value ? ' selected' : '') . '>' . esc_html($ol) . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<input type="' . ($kind === 'num' ? 'number' : 'text') . '" data-f="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
        }
        $html .= '</label>';
    }
    $html .= kop_ak_button('Save correction', 'secondary', '', 'kop-wbf-save') . ' ';
    if (isset($extra['original'])) {
        $html .= kop_ak_button('Back to what Woodbury said', 'quiet', '', 'kop-wbf-reset') . ' ';
    }
    return $html . '<span class="kop-wbf-edit-msg" aria-live="polite"></span></div></details>';
}

/* ---- A person the build missed ----------------------------------------- */

/**
 * Add a staff item by hand from another item's quote, when the words name
 * more people than the build read. It joins the same card as a waiting item
 * citing that quote (only the quotes that name the person, when any do), and
 * is added, edited, rejected and undone like any other. A rebuild never
 * touches it.
 */
function kop_wbf_add_person(array $from, array $f, $reviewer) {
    global $wpdb;
    if ($from['status'] !== 'pending' || $from['grp'] === 'consultant') {
        throw new RuntimeException('Add people from a waiting item.');
    }
    $shape = array('op' => 'add_staff', 'path' => 'staff.notableStaff', 'value' => 'null', 'label' => '');
    list($value, $path) = kop_wbf_edited_value($shape, $f);
    $key = kop_wbf_person_key($value['name']);
    if ($key === '') {
        throw new RuntimeException('Give a first and last name.');
    }
    $pkey = md5('manual|' . $from['facility_id'] . '|' . $from['program'] . '|' . $key);
    $taken = $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . kop_wbf_table() . ' WHERE pkey = %s', $pkey));
    if ($taken !== null) {
        throw new RuntimeException($value['name'] . ' was already added by hand on this card (' . $taken . ').');
    }
    // Only the quotes that name them, so the record cites the right page.
    $last = strtolower(substr($key, strrpos($key, ' ') + 1));
    $evidence = array_values(array_filter(kop_wbf_evidence($from), function ($e) use ($last) {
        return strpos(strtolower(remove_accents((string) ($e['quote'] ?? ''))), $last) !== false;
    })) ?: kop_wbf_evidence($from);
    $found = 1;
    foreach ($evidence as $e) {
        $found = $found && !empty($e['found']) ? 1 : 0;
    }
    $shape['path'] = $path;
    $now = current_time('mysql', true);
    $row = array(
        'pkey'               => $pkey,
        'facility_id'        => (int) $from['facility_id'],
        'program'            => $from['program'],
        'program_as_written' => $from['program_as_written'],
        'place'              => $from['place'],
        'match_kind'         => $from['match_kind'],
        'match_note'         => $from['match_note'],
        'alternatives'       => $from['alternatives'],
        'grp'                => 'staff',
        'op'                 => 'add_staff',
        'path'               => $path,
        'value'              => wp_json_encode($value),
        'label'              => kop_wbf_label_for($shape, $value),
        'conflict'           => '',
        'current_val'        => '',
        'extra'              => wp_json_encode(array('manual' => 1, 'from' => $from['pkey'], 'person' => $value['name'],
            'edited_by' => $reviewer, 'edited_at' => $now)),
        'evidence'           => wp_json_encode($evidence),
        'found'              => $found,
        'preselect'          => 1,
        'auto'               => 0,
        'issue_date'         => $from['issue_date'],
        'status'             => 'pending',
        'created_at'         => $now,
    );
    if ($wpdb->insert(kop_wbf_table(), $row) === false) {
        throw new RuntimeException('Could not save: ' . $wpdb->last_error);
    }
    return kop_wbf_rows(array($pkey))[0];
}

/** The "Another person in these words" box under a waiting item. */
function kop_wbf_person_form(array $r) {
    if ($r['grp'] === 'consultant' || !kop_wbf_evidence($r)) {
        return '';
    }
    return '<details class="kop-wbf-edit kop-wbf-more"><summary>Another person in these words</summary><div class="kop-wbf-edit-box">'
        . '<label>Name <input type="text" data-f="name"></label>'
        . '<label>Role at this program <input type="text" data-f="role"></label>'
        . '<label>Past jobs <textarea data-f="pastJobs" rows="2"></textarea></label>'
        . '<label>List <select data-f="where"><option value="staff.notableStaff">Notable staff</option>'
        . '<option value="staff.administrator">Administrators</option></select></label>'
        . kop_ak_button('Add to this card', 'secondary', '', 'kop-wbf-addperson')
        . '<span class="kop-wbf-edit-msg" aria-live="polite"></span></div></details>';
}

add_action('wp_ajax_kop_wbf_person', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }
    check_ajax_referer('kop_woodbury_facts', 'nonce');
    $rows = kop_wbf_rows(array((string) ($_POST['key'] ?? '')));
    try {
        if (!$rows) {
            throw new RuntimeException('Not found.');
        }
        $f = array();
        foreach ((array) ($_POST['f'] ?? array()) as $k => $v) {
            $f[preg_replace('/[^A-Za-z]/', '', (string) $k)] = sanitize_textarea_field(wp_unslash((string) $v));
        }
        $r = kop_wbf_add_person($rows[0], $f, wp_get_current_user()->user_login);
        ob_start();
        kop_wbf_render_row($r, 'records');
        wp_send_json_success(array('row' => ob_get_clean()));
    } catch (Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});

add_action('wp_ajax_kop_wbf_edit', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }
    check_ajax_referer('kop_woodbury_facts', 'nonce');
    $rows = kop_wbf_rows(array((string) ($_POST['key'] ?? '')));
    try {
        if (!$rows) {
            throw new RuntimeException('Not found.');
        }
        if (!empty($_POST['reset'])) {
            $r = kop_wbf_edit_reset($rows[0]);
        } else {
            $f = array();
            foreach ((array) ($_POST['f'] ?? array()) as $k => $v) {
                $f[preg_replace('/[^A-Za-z]/', '', (string) $k)] = sanitize_textarea_field(wp_unslash((string) $v));
            }
            $r = kop_wbf_edit($rows[0], $f, wp_get_current_user()->user_login);
        }
        wp_send_json_success(array('label' => $r['label'], 'goes' => kop_wbf_where_it_goes($r), 'form' => kop_wbf_edit_form($r)));
    } catch (Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});

add_action('wp_ajax_kop_wbf_act', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Not allowed.', 403);
    }
    check_ajax_referer('kop_woodbury_facts', 'nonce');
    $act = sanitize_key($_POST['act'] ?? '');
    $keys = isset($_POST['keys']) && is_array($_POST['keys']) ? array_slice($_POST['keys'], 0, 400) : array();
    $rows = kop_wbf_rows($keys);
    $user = wp_get_current_user()->user_login;
    try {
        if (!$rows) {
            throw new RuntimeException('Nothing selected.');
        }
        if ($act === 'ya_on' || $act === 'ya_off') {
            kop_wbf_ya_override($rows[0]['program'], $act === 'ya_on' ? 1 : 0);
            wp_send_json_success(array('results' => array_fill_keys(array_column($rows, 'pkey'), array('ok' => true))));
        }
        if ($act === 'ya_apply' || $act === 'ya_create') {
            $pdo = function_exists('kop_ya_pdo') ? kop_ya_pdo() : null;
            if (!$pdo) {
                throw new RuntimeException('The records database is not reachable.');
            }
            kop_ya_install($pdo);
            if ($act === 'ya_create') {
                $f = array();
                foreach (array('name', 'city', 'state', 'country', 'ages', 'program_type') as $k) {
                    $f[$k] = sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
                }
                $e = kop_wbf_evidence($rows[0]);
                $f['source'] = !empty($e[0]['cite']) ? (string) $e[0]['cite']
                    : 'Woodbury Reports' . ($e ? ', ' . $e[0]['label'] . ', p. ' . (int) $e[0]['page'] : '');
                $f['review'] = 'approved';
                $yid = kop_ya_save($pdo, $f, 0, $user);
            } else {
                $yid = (int) ($_POST['ya'] ?? 0);
            }
            $results = kop_wbf_ya_file($pdo, $rows, $yid, $user);
            $p = kop_ya_get($pdo, $yid);
            wp_send_json_success(array('results' => $results, 'url' => admin_url('admin.php?page=kop-young-adult-programs&edit=' . $yid),
                'label' => 'the young adult program "' . $p['name'] . '"'));
        }
        $kind = sanitize_key($_POST['kind'] ?? 'facility');
        if ($act === 'create' && $kind !== 'facility') {
            if (!isset(kop_wbf_create_kinds()[$kind])) {
                throw new RuntimeException('Choose what kind of record to create.');
            }
            $f = array();
            foreach (array('name', 'city', 'state', 'country') as $k) {
                $f[$k] = sanitize_text_field(wp_unslash($_POST[$k] ?? ''));
            }
            $filed = kop_wbf_file_items($rows, $kind, $f, $user);
            $t = $filed['target'];
            wp_send_json_success(array('results' => $filed['results'], 'url' => '',
                'label' => 'the new ' . strtolower(kop_wbf_create_kinds()[$kind]) . ' record "' . $t['name'] . '" (#' . (int) $t['id'] . '), in its notes'));
        }
        if ($act === 'apply' || $act === 'create') {
            $fid = (int) ($_POST['fid'] ?? 0);
            if ($act === 'create') {
                if (!function_exists('kop_wbc_create_facility')) {
                    throw new RuntimeException('Record creation is not available.');
                }
                $source = kop_wbf_create_source(kop_wbf_evidence($rows[0]));
                $f = array(
                    'name'  => trim(preg_replace('/\s+/', ' ', sanitize_text_field(wp_unslash($_POST['name'] ?? '')))),
                    'city'  => sanitize_text_field(wp_unslash($_POST['city'] ?? '')),
                    'country' => sanitize_text_field(wp_unslash($_POST['country'] ?? '')),
                    'type'  => sanitize_text_field(wp_unslash($_POST['type'] ?? '')),
                    'force' => !empty($_POST['force']),
                );
                $raw_state = sanitize_text_field(wp_unslash($_POST['state'] ?? ''));
                $f['state'] = $raw_state !== '' ? (string) kop_facility_state_code($raw_state) : '';
                if ($raw_state !== '' && $f['state'] === '') {
                    throw new RuntimeException('"' . $raw_state . '" is not a US state. Leave it empty and give the country instead.');
                }
                if ($f['name'] === '') {
                    throw new RuntimeException('A name is needed.');
                }
                $target = kop_wbc_create_facility($source, $f, kop_closure_pdo());
                $fid = (int) $target['id'];
            }
            $results = kop_wbf_apply($rows, $fid, $user);
            $url = function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '';
            wp_send_json_success(array('results' => $results, 'fid' => $fid, 'url' => $url,
                'label' => wp_strip_all_tags(kop_facility_finder_label(kop_closure_pdo(), $fid))));
        } elseif ($act === 'consultant') {
            $results = array();
            foreach ($rows as $r) {
                if ($r['status'] !== 'pending' || $r['grp'] !== 'consultant') {
                    $results[$r['pkey']] = array('ok' => false, 'error' => 'Not a waiting consultant.');
                    continue;
                }
                $done = kop_wbf_consultant_apply($r);
                $GLOBALS['wpdb']->update(kop_wbf_table(), array('status' => 'applied', 'applied' => wp_json_encode($done),
                    'reviewed_by' => $user, 'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
                $results[$r['pkey']] = array('ok' => true, 'created' => $done['created'], 'added' => $done['added']);
            }
            wp_send_json_success(array('results' => $results, 'url' => home_url('/referrers-educational-consultants/')));
        } elseif ($act === 'reject') {
            foreach ($rows as $r) {
                if ($r['status'] === 'pending') {
                    $GLOBALS['wpdb']->update(kop_wbf_table(), array('status' => 'rejected', 'reviewed_by' => $user,
                        'reviewed_at' => current_time('mysql', true)), array('pkey' => $r['pkey']));
                }
            }
            wp_send_json_success(array('results' => array_fill_keys(array_column($rows, 'pkey'), array('ok' => true))));
        } elseif ($act === 'undo') {
            kop_wbf_undo($rows, $user);
            wp_send_json_success(array('results' => array_fill_keys(array_column($rows, 'pkey'), array('ok' => true))));
        }
        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        wp_send_json_error($e->getMessage());
    }
});

/* ---- Review screen ------------------------------------------------------ */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(kop_tools_parent_slug(), 'Woodbury Facts', 'Woodbury Facts', 'manage_options',
        'kop-woodbury-facts', 'kop_render_woodbury_facts_page');
}, 21);

function kop_wbf_tabs() {
    return array(
        'records'  => array('label' => 'For existing records', 'where' => "status = 'pending' AND facility_id > 0"),
        'norecord' => array('label' => 'Programs with no record', 'where' => "status = 'pending' AND facility_id = 0 AND grp <> 'consultant' AND ya = 0"),
        'youngadult' => array('label' => 'Young adult programs (18+)', 'where' => "status = 'pending' AND facility_id = 0 AND grp <> 'consultant' AND ya = 1"),
        'consultants' => array('label' => 'Ed cons who worked in the industry', 'where' => "status = 'pending' AND grp = 'consultant'"),
        'held'     => array('label' => 'Approved, held for the write-up', 'where' => "status = 'held'"),
        'applied'  => array('label' => 'Added', 'where' => "status = 'applied'"),
        'auto'     => array('label' => 'Approved automatically', 'where' => "status IN ('applied', 'held') AND reviewed_by = 'auto'"),
        'rejected' => array('label' => 'Rejected', 'where' => "status = 'rejected'"),
        // Items the latest build no longer makes (kop_wbf_sync()); "Back to review" keeps one waiting.
        'gone'     => array('label' => 'No longer proposed', 'where' => "status = 'gone'"),
    );
}

function kop_wbf_groups() {
    return array(
        'staff'    => 'Staff and careers',
        'incident' => 'Incidents',
        'history'  => 'History (openings, closings, names, owners, moves)',
        'details'  => 'Details (size, ages, type, memberships, other)',
        'consultant' => 'Educational consultant who worked in the industry',
    );
}

function kop_wbf_where_it_goes(array $r) {
    $map = array(
        'staff.administrator'               => 'Staff: administrators',
        'staff.notableStaff'                => 'Staff: notable staff',
        'criticalIncidents.customIncidents' => 'Critical incidents',
        'notes'                             => 'Notes',
        'identification.pastNames'          => 'Past names',
        'identification.pastOperators'      => 'Past operators',
        'location.formerLocations'          => 'Former locations',
        'operatingPeriod.startYear'         => 'Start year',
        'operatingPeriod'                   => 'Status and end year',
        'facilityDetails.capacity'          => 'Capacity',
        'facilityDetails.ageRange'          => 'Age range',
        'facilityDetails.gender'            => 'Gender',
        'facilityDetails.type'              => 'Type',
        'referrer'                          => 'Consultant record: Career History, flagged former industry staff',
    );
    return $map[$r['path']] ?? $r['path'];
}

function kop_render_woodbury_facts_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    global $wpdb;
    kop_wbf_ensure_table();
    $sync = kop_wbf_sync(isset($_GET['wbf_resync']));
    $auto_now = kop_wbf_auto_run(15);
    $auto_left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wbf_table() . ' WHERE ' . kop_wbf_auto_where());
    $table = kop_wbf_table();
    $tabs = kop_wbf_tabs();
    $groups = kop_wbf_groups();
    $tab = isset($_GET['wbf_tab'], $tabs[$_GET['wbf_tab']]) ? $_GET['wbf_tab'] : 'records';
    $grp = isset($_GET['wbf_grp'], $groups[$_GET['wbf_grp']]) ? $_GET['wbf_grp'] : '';
    $q = isset($_GET['wbf_q']) ? trim(sanitize_text_field(wp_unslash($_GET['wbf_q']))) : '';
    $paged = max(1, (int) ($_GET['wbf_page'] ?? 1));
    $per = 20;
    $base = admin_url('admin.php?page=kop-woodbury-facts');

    $notices = '';
    if ($sync) {
        $notices .= '<div class="notice notice-info"><p>Loaded a new build: ' . (int) $sync['added'] . ' new, ' . (int) $sync['updated']
            . ' updated, ' . (int) $sync['gone'] . ' no longer proposed.</p></div>';
    }
    if (!is_readable(kop_wbf_path())) {
        $notices .= '<div class="notice notice-warning"><p>No facts uploaded yet. Run <code>python scripts/woodbury-facts.py</code> and copy '
            . '<code>facts.json</code> to <code>' . esc_html(dirname(kop_wbf_path())) . '</code>.</p></div>';
    }
    echo '<div class="wrap kop-ak kop-wbf">';
    echo kop_ak_header('Woodbury Facts', array(
        'lede' => 'Everything the Woodbury Reports newsletter (2006-2014) says about a program that its record does not have yet: '
            . 'the people who worked there and where else they worked, incidents, when it opened or closed, past names, owners, moves, size and ages. '
            . 'Each item shows the words it comes from and links to the page of the issue.',
        'notices' => $notices,
        'steps' => array(
            '<strong>Read down a card.</strong> Items with a checked quote and a sure match start ticked. Untick anything wrong.',
            '<strong>Read wrong?</strong> Click <em>Edit</em> under the item to fix the name, role, year or text (or move a person between administrators and notable staff), '
                . 'then <em>Save correction</em> and add it as usual. A corrected item is never added automatically, and a new scan does not overwrite it. '
                . 'Something already added automatically: Undo it on the <em>Added automatically</em> tab, then edit it here.',
            '<strong>The words name someone the item missed?</strong> Click <em>Another person in these words</em> under it, '
                . 'give the name and role, and they join the card as a ticked item citing the same page.',
            '<strong>Wrong program, or one with no record?</strong> Open <em>Wrong program?</em> under the card to put the checked items on another record, '
                . 'or create a new record from the name and place Woodbury gives: a program, an educational consultant (firm or person) or a mental health provider. '
                . 'A consultant or provider record gets the checked items in its notes.',
            '<strong>A program for people 18 and older?</strong> Those are on the <em>Young adult programs (18+)</em> tab and never become facility records: '
                . 'each card there creates or adds to a young adult program. A card on <em>Programs with no record</em> that is one: <em>More</em> &gt; <em>It is a young adult program (18+)</em>.',
            '<strong>Click <em>Add checked to record</em></strong> on the card, or <em>Add everything ticked on this page</em> at the top. '
                . 'The facility page shows the additions at once, each citing the issue; new staff, past operators and past names reach the network map on its next build.',
            '<strong>Changed your mind?</strong> <em>Undo</em> shows beside the result of every action, and the <em>Added</em> tab has Undo too: it takes back exactly what was added.',
        ),
    ));
    $auto_done = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_wbf_table() . " WHERE status = 'applied' AND reviewed_by = 'auto'");
    echo kop_ak_status('<strong>Exact-name items are added for you</strong> when the program is exactly one record\'s name (or past name), the quote is found '
        . 'and nothing on the record is in the way: ' . number_format($auto_done) . ' so far' . ($auto_now ? ' (' . (int) $auto_now . ' just now)' : '')
        . ($auto_left ? ', ' . number_format($auto_left) . ' more being added in the background' : '')
        . '. Close names, other possible records, conflicts and programs with no record wait for you. Anything you undo on <em>Added automatically</em> stays manual.');

    $where = $tabs[$tab]['where'];
    if ($grp !== '') {
        $where .= $wpdb->prepare(' AND grp = %s', $grp);
    }
    if ($q !== '') {
        $like = '%' . $wpdb->esc_like($q) . '%';
        $where .= $wpdb->prepare(' AND (program LIKE %s OR program_as_written LIKE %s OR label LIKE %s)', $like, $like, $like);
    }

    $tab_items = array();
    foreach ($tabs as $k => $t) {
        $tab_items[$k] = array('label' => $t['label'], 'count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE " . $t['where']));
    }
    echo kop_ak_tabs($tab_items, $tab, function ($k) use ($base) { return add_query_arg('wbf_tab', $k, $base); });
    echo kop_ak_filters(array('page' => 'kop-woodbury-facts', 'wbf_tab' => $tab), array(
        array('type' => 'search', 'name' => 'wbf_q', 'value' => $q, 'placeholder' => 'Program or person', 'label' => 'Search'),
        array('type' => 'select', 'name' => 'wbf_grp', 'value' => $grp, 'label' => 'Kind', 'all' => 'Every kind', 'options' => $groups),
    ), add_query_arg('wbf_tab', $tab, $base));

    // Items filed on a consultant or provider record have no facility: one card per program.
    $card_col = in_array($tab, array('norecord', 'youngadult', 'consultants'), true) ? 'program'
        : (in_array($tab, array('applied', 'auto'), true) ? "IF(applied_fid > 0, CAST(applied_fid AS CHAR), CONCAT('p:', program))" : 'facility_id');
    $total = (int) $wpdb->get_var("SELECT COUNT(DISTINCT {$card_col}) FROM {$table} WHERE {$where}");
    $order = in_array($tab, array('norecord', 'youngadult'), true) ? 'COUNT(*) DESC, program' : 'MIN(program)';
    $cards = $wpdb->get_col("SELECT {$card_col} FROM {$table} WHERE {$where} GROUP BY {$card_col} ORDER BY {$order} LIMIT "
        . (($paged - 1) * $per) . ", {$per}");
    if (!$cards) {
        echo kop_ak_empty('Nothing here' . ($q !== '' || $grp !== '' ? ' under these filters.' : '.'));
        kop_wbf_render_assets();
        echo '</div>';
        return;
    }
    $in = implode(',', array_fill(0, count($cards), '%s'));
    $rows = $wpdb->get_results($wpdb->prepare("SELECT *, {$card_col} AS ck FROM {$table} WHERE {$where} AND {$card_col} IN ({$in}) "
        . "ORDER BY FIELD(grp,'staff','incident','history','details'), issue_date, label", $cards), ARRAY_A);
    $by = array();
    foreach ($rows as $r) {
        $by[(string) $r['ck']][] = $r;
    }

    if ($tab === 'youngadult') {
        echo '<div class="notice notice-info inline"><p>Programs for people 18 and older. They are kept apart from the troubled teen programs: '
            . 'a card here becomes (or adds to) a record on the <a href="' . esc_url(admin_url('admin.php?page=kop-young-adult-programs')) . '">Young Adult Programs</a> list, '
            . 'never a facility record. The build puts a program here when Woodbury gives its ages as 17 or older or calls it a young adult program; '
            . 'anything else can be moved here from <em>Programs with no record</em>, and back.</p></div>';
    }
    if ($tab === 'records') {
        echo kop_ak_bar(kop_ak_button('Add everything ticked on this page', 'primary', 'data-kop-ak-all="apply"'));
    }

    foreach ($cards as $c) {
        if (!empty($by[(string) $c])) {
            kop_wbf_render_card($by[(string) $c], $tab);
        }
    }

    echo kop_ak_pager($paged, (int) ceil($total / $per), $total, in_array($tab, array('norecord', 'youngadult', 'consultants'), true) ? 'programs' : 'facilities',
        function ($p) use ($base, $tab, $q, $grp) {
            return add_query_arg(array('wbf_tab' => $tab, 'wbf_page' => $p, 'wbf_q' => $q !== '' ? $q : null, 'wbf_grp' => $grp !== '' ? $grp : null), $base);
        });
    kop_wbf_render_assets();
    echo '</div>';
}

/**
 * Put a card's checked items on a record other than the build's match: the
 * build's close names, the facility finder, or a new program record made
 * from the name and place Woodbury gives.
 */
function kop_wbf_other_record(array $first, $no_record) {
    $html = '<div class="kop-wbf-pick">';
    $alts = '';
    foreach (array_slice(json_decode((string) $first['alternatives'], true) ?: array(), 0, 3) as $a) {
        if ((int) $a['id'] === (int) $first['facility_id']) {
            continue;
        }
        $alts .= kop_ak_button($a['name'] . ($a['state'] ? ' (' . $a['state'] . ')' : ''), 'quiet', 'data-fid="' . (int) $a['id'] . '"', 'kop-wbf-alt');
    }
    if ($alts !== '') {
        $html .= '<div class="kop-wbf-alts"><span class="kop-ak-muted">Close names:</span> ' . $alts . '</div>';
    }
    $html .= kop_facility_finder_field('', '', ' class="kop-ak-fid"') . ' '
        . kop_ak_button('Add checked to that record', 'primary', 'data-act="apply" data-other="1"') . '</div>';
    $state = '';
    $city = '';
    if (preg_match('/^(.*?),\s*([A-Za-z .]+)$/', $first['place'], $m)) {
        $city = trim($m[1]);
        $state = trim($m[2]);
    } elseif ($first['place'] !== '') {
        $state = $first['place'];
    }
    $name = $no_record ? $first['program'] : ($first['program_as_written'] !== '' ? $first['program_as_written'] : $first['program']);
    $types = '';
    foreach (function_exists('kop_facdisc_types') ? kop_facdisc_types('offered') : array() as $t) {
        $types .= '<option value="' . esc_attr($t) . '">' . esc_html($t) . '</option>';
    }
    $kinds = '';
    foreach (kop_wbf_create_kinds() as $k => $label) {
        $kinds .= '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
    }
    return $html . '<div class="kop-wbf-create"><strong>No record yet? Create one:</strong>'
        . '<div class="kop-wbf-fields">'
        . '<label>What is it? <select class="kop-wbf-ckind">' . $kinds . '</select></label>'
        . '<label class="kop-wbf-wide">Name <input type="text" class="kop-wbf-cname" value="' . esc_attr($name) . '"></label>'
        . '<label>City <input type="text" class="kop-wbf-ccity" value="' . esc_attr($city) . '"></label>'
        . '<label>State <input type="text" class="kop-wbf-cstate" value="' . esc_attr($state) . '"></label>'
        . '<label>or country <input type="text" class="kop-wbf-ccountry"></label>'
        . '<span class="kop-wbf-conly"><label>Type (none if not sure; Ctrl-click for several) <select class="kop-wbf-ctype" multiple size="4"' . kop_choice_select_attr('facility-types') . '>' . $types . '</select></label>'
        . '<label class="kop-wbf-tick"><input type="checkbox" class="kop-wbf-cforce"> It is a different place from a close match</label></span>'
        . '</div>'
        . kop_ak_button('Create the record and add checked', 'secondary', 'data-act="create"')
        . '<div class="kop-ak-muted kop-wbf-cnote" hidden>A consultant or provider record gets the checked items in its notes, each citing its issue page. '
        . 'Its Woodbury pages can be filed under it at KOP Tools &gt; Woodbury Reports.</div></div>';
}

function kop_wbf_render_card(array $rows, $tab) {
    $first = $rows[0];
    $pending = in_array($tab, array('records', 'norecord', 'youngadult', 'consultants'), true);
    $fid = in_array($tab, array('applied', 'auto'), true) ? (int) $first['applied_fid'] : (int) $first['facility_id'];
    echo '<section class="kop-ak-card kop-wbf-card" data-fid="' . $fid . '">';
    $sub = array();
    if ($fid) {
        $title = function_exists('kop_facility_finder_label') && function_exists('kop_closure_pdo') && kop_closure_pdo()
            ? kop_facility_finder_label(kop_closure_pdo(), $fid) : esc_html($first['program']);
        $page = function_exists('kop_facility_page_url') ? kop_facility_page_url($fid) : '';
        if ($page) {
            $sub[] = '<a href="' . esc_url($page) . '" target="_blank" rel="noopener">Facility page</a>';
        }
        // 'id': the questions page names the record itself, so there is no Woodbury name to check.
        if (!in_array($first['match_kind'], array('exact', 'id'), true) && $pending) {
            $sub[] = '<span class="kop-ak-warn">Woodbury calls it &ldquo;' . esc_html($first['program_as_written'])
                . ($first['place'] !== '' ? ', ' . esc_html($first['place']) : '') . '&rdquo;. '
                . esc_html($first['match_note'] ?: 'Close name: check it is the same program.') . ' Items start unticked.</span>';
        }
    } else {
        $title = esc_html($first['program']);
        if ($first['place'] !== '') {
            $sub[] = esc_html($first['place']);
        }
        if ($tab === 'youngadult' && $first['ya_why'] !== '') {
            $sub[] = 'Why it is here: ' . esc_html($first['ya_why']);
        } elseif ($tab === 'norecord' && (string) $first['match_note'] !== '') {
            // A record that became a parent company (inc/record-to-company.php) says so.
            $sub[] = '<span class="kop-ak-warn">' . esc_html($first['match_note']) . '</span>';
        }
    }
    echo kop_ak_card_head($title, implode('<br>', $sub));

    echo '<table class="widefat kop-wbf-table"><tbody>';
    $groups = kop_wbf_groups();
    $last = '';
    foreach ($rows as $r) {
        if ($r['grp'] !== $last) {
            echo '<tr class="kop-wbf-grp"><th colspan="3">' . esc_html($groups[$r['grp']] ?? $r['grp']) . '</th></tr>';
            $last = $r['grp'];
        }
        kop_wbf_render_row($r, $tab);
    }
    echo '</tbody></table>';

    if ($tab === 'records') {
        $buttons = kop_ak_button('Add checked to record', 'primary', 'data-act="apply"')
            . kop_ak_button('Reject checked', 'danger', 'data-act="reject"')
            . kop_ak_fold('Wrong program? Put the checked items on another record, or create a new program, educational consultant or mental health provider record',
                kop_wbf_other_record($first, false));
    } elseif ($tab === 'consultants') {
        $v = kop_wbf_row_value($first);
        $buttons = kop_ak_button('Flag as former industry staff', 'primary', 'data-act="consultant"')
            . kop_ak_button('Reject', 'danger', 'data-act="reject"')
            . '<div class="kop-ak-muted kop-wbf-note">' . (!empty($v['referrer_id'])
                ? 'Adds these jobs to the Career History on the consultant record ' . esc_html($v['referrer_name']) . '.'
                : 'No consultant record yet: flagging creates one (filed under Educational Consultants) with this Career History.') . '</div>';
    } elseif ($tab === 'norecord') {
        $buttons = kop_ak_fold('Which record is it? Pick one or create it', kop_wbf_other_record($first, true), true)
            . kop_ak_button('Reject checked', 'danger', 'data-act="reject"')
            . kop_ak_more(array(kop_ak_button('It is a young adult program (18+)', 'quiet', 'data-act="ya_on"')));
    } elseif ($tab === 'youngadult') {
        $buttons = kop_ak_fold('Which young adult program? Pick one or create it', kop_wbf_ya_panel($first), true)
            . kop_ak_button('Reject checked', 'danger', 'data-act="reject"')
            . kop_ak_more(array(kop_ak_button('Not a young adult program: move it back', 'quiet', 'data-act="ya_off"')));
    } elseif (in_array($tab, array('applied', 'auto'), true)) {
        $buttons = kop_ak_button('Undo checked', 'secondary', 'data-act="undo"');
    } else {
        $buttons = kop_ak_button('Put checked back to review', 'secondary', 'data-act="undo"');
    }
    echo kop_ak_card_actions($buttons);
    echo '</section>';
}

function kop_wbf_render_row(array $r, $tab) {
    $pending = in_array($tab, array('records', 'norecord', 'youngadult', 'consultants'), true);
    $checked = $pending ? ($r['preselect'] && in_array($tab, array('records', 'consultants'), true)) : false;
    $extra = json_decode((string) $r['extra'], true) ?: array();
    echo '<tr data-key="' . esc_attr($r['pkey']) . '">' . kop_ak_pick($checked);
    echo '<td class="kop-wbf-what"><strong class="kop-wbf-label">' . esc_html($r['label']) . '</strong>'
        . '<div class="kop-ak-muted">Goes to: <span class="kop-wbf-goes">' . esc_html(kop_wbf_where_it_goes($r)) . '</span></div>';
    if ($r['status'] === 'pending') {
        echo kop_wbf_edit_form($r) . kop_wbf_person_form($r);
    }
    if ($r['status'] === 'applied' && kop_wbf_filed_on($r) !== '') {
        $done = json_decode((string) $r['applied'], true);
        echo '<div class="kop-ak-muted">' . (($done['filed'] ?? '') === 'young_adult' ? 'Added to ' : 'Filed in the notes of ')
            . esc_html(kop_wbf_filed_on($r)) . '</div>';
    }
    if (!empty($extra['manual'])) {
        echo '<div class="kop-ak-muted">' . esc_html(kop_wbf_manual_line($r, $extra)) . '</div>';
    }
    if ($r['conflict'] !== '') {
        echo '<div class="kop-ak-warn">' . esc_html($r['conflict']) . '</div>';
    }
    if ($r['current_val'] !== '') {
        echo '<div class="kop-ak-muted">On the record now: ' . esc_html($r['current_val']) . '</div>';
    }
    if (!empty($extra['career']) && count($extra['career']) > 1) {
        echo '<div class="kop-wbf-career"><span class="kop-ak-muted">Where Woodbury places ' . esc_html($extra['person'] ?? 'them') . ':</span><ul>';
        foreach ($extra['career'] as $line) {
            echo '<li>' . esc_html($line) . '</li>';
        }
        echo '</ul></div>';
    }
    if ($r['status'] === 'rejected') {
        $why = json_decode((string) $r['applied'], true);
        if (!empty($why['reason'])) {
            echo '<div class="kop-ak-muted">Skipped: ' . esc_html($why['reason']) . '</div>';
        }
    }
    echo '</td><td class="kop-wbf-ev">';
    $ev = kop_wbf_evidence($r);
    $source = function ($e, $warn) {
        $html = '<div class="kop-wbf-src"><a href="' . esc_url($e['url']) . '" target="_blank" rel="noopener">' . esc_html(kop_wbf_ev_label($e)) . '</a>';
        if (($e['quote'] ?? '') !== '') {
            $html .= '<blockquote class="kop-ak-quote' . ($e['found'] ? '' : ' is-unfound') . '">' . esc_html($e['quote']) . '</blockquote>';
        }
        if ($warn && empty($e['found']) && ($e['quote'] ?? '') !== '') {
            $html .= '<div class="kop-ak-warn">These words were not found in the ' . (!empty($e['pub']) ? 'archived page' : 'issue text') . ': check the page before adding.</div>';
        }
        return $html . '</div>';
    };
    foreach (array_slice($ev, 0, 3) as $e) {
        echo $source($e, true);
    }
    if (count($ev) > 3) {
        echo kop_ak_fold((count($ev) - 3) . ' more', implode('', array_map(function ($e) use ($source) { return $source($e, false); }, array_slice($ev, 3))));
    }
    echo '</td></tr>';
}

/** What only Woodbury Facts needs on top of the kit: its actions' extra fields, Edit, Another person, the close-name picks. */
function kop_wbf_render_assets() {
    $nonce = wp_create_nonce('kop_woodbury_facts');
    ?>
    <style>
        .kop-wbf-grp th { background: var(--kop-sand, #F2EEDF); color: var(--kop-midnight-blue, #000435); font-weight: 600; padding: 4px 8px; }
        .kop-wbf-what { width: 40%; }
        .kop-wbf-career ul { margin: 2px 0 0 18px; list-style: disc; font-size: 12px; }
        .kop-wbf-alts { margin-bottom: 8px; }
        .kop-wbf-pick { margin-bottom: 10px; }
        .kop-wbf-create { padding-top: 10px; border-top: 1px solid #dcdcde; }
        .kop-wbf-fields { display: flex; flex-wrap: wrap; gap: 6px 12px; margin: 6px 0 8px; }
        .kop-wbf-fields label { display: flex; flex-direction: column; font-size: 12px; color: var(--kop-text-muted, #4A5568); min-width: 0; }
        .kop-wbf-fields label.kop-wbf-wide { flex: 1 1 260px; }
        .kop-wbf-fields label.kop-wbf-tick { flex-direction: row; align-items: center; gap: 4px; }
        .kop-wbf-fields input[type=text], .kop-wbf-fields select { max-width: 100%; }
        .kop-wbf-conly { display: contents; }
        .kop-wbf-conly[hidden] { display: none; }
        .kop-wbf-note { flex-basis: 100%; }
        .kop-wbf-edit { margin-top: 4px; }
        .kop-wbf-edit summary { cursor: pointer; color: var(--kop-navy, #000080); font-size: 12px; }
        .kop-wbf-edit-box { margin-top: 4px; padding: 8px; background: #f6f7f7; border: 1px solid #dcdcde; }
        .kop-wbf-edit-box label { display: block; margin: 0 0 6px; font-size: 12px; color: var(--kop-text-muted, #4A5568); }
        .kop-wbf-edit-box input, .kop-wbf-edit-box textarea, .kop-wbf-edit-box select { display: block; width: 100%; max-width: 100%; }
        .kop-wbf-edit-msg { font-size: 12px; margin-left: 6px; }
        .kop-wbf-edit-msg.ok { color: #007017; }
        .kop-wbf-edit-msg.err { color: #b32d2e; }
        @media (max-width: 782px) { .kop-wbf-fields label { flex: 1 1 100%; } }
    </style>
    <script>
    (function () {
        if (!window.KOPAdminKit) return;
        var ajax = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
        var nonce = <?php echo wp_json_encode($nonce); ?>;
        var to = function (n, d) { return d && d.label ? ' to ' + d.label + '.' : '.'; };

        KOPAdminKit.cards({
            action: 'kop_wbf_act',
            nonce: nonce,
            // Moving a card on or off the young adult tab takes all of it, ticked or not.
            whole: ['ya_on', 'ya_off'],
            undoable: ['apply', 'create', 'reject', 'consultant', 'ya_apply', 'ya_create'],
            words: {
                apply: function (n, d) { return 'Added ' + n + to(n, d); },
                create: function (n, d) { return 'Created the record and added ' + n + to(n, d); },
                ya_apply: function (n, d) { return 'Added ' + n + to(n, d); },
                ya_create: function (n, d) { return 'Created and added ' + n + to(n, d); },
                reject: function (n) { return 'Rejected ' + n + '.'; },
                consultant: function () { return 'Flagged as former industry staff; the jobs are in their Career History on the consultants directory.'; },
                ya_on: function () { return 'Moved to Young adult programs (18+).'; },
                ya_off: function () { return 'Moved back to Programs with no record.'; }
            },
            before: function (card, act, btn, data) {
                var v = function (cls) {
                    var el = card.querySelector(cls);
                    if (el && el.multiple) return Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; }).join(', ');
                    return el ? el.value : '';
                };
                if (act === 'apply' && !(+data.fid)) return 'Pick the record first.';
                if (act === 'create') {
                    data.name = v('.kop-wbf-cname'); data.city = v('.kop-wbf-ccity'); data.state = v('.kop-wbf-cstate');
                    data.country = v('.kop-wbf-ccountry'); data.type = v('.kop-wbf-ctype'); data.kind = v('.kop-wbf-ckind');
                    var force = card.querySelector('.kop-wbf-cforce');
                    data.force = force && force.checked ? 1 : '';
                }
                if (act === 'ya_apply') {
                    data.ya = v('.kop-wbf-yaid');
                    if (!(+data.ya)) return 'Pick the young adult program first.';
                }
                if (act === 'ya_create') {
                    ['name', 'city', 'state', 'country', 'ages', 'type'].forEach(function (k) { data[k === 'type' ? 'program_type' : k] = v('.kop-wbf-y' + k); });
                }
                return '';
            }
        });

        function send(action, fields) {
            var body = new URLSearchParams();
            body.append('action', action);
            body.append('nonce', nonce);
            Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
            return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
        }

        function boxFields(box) {
            var f = {};
            box.querySelectorAll('[data-f]').forEach(function (el) {
                f['f[' + el.dataset.f + ']'] = el.multiple ? Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; }).join(', ') : el.value;
            });
            return f;
        }

        // Edit: correct what the build read before adding it.
        function saveEdit(box, reset) {
            var tr = box.closest('tr[data-key]');
            var msg = box.querySelector('.kop-wbf-edit-msg');
            var fields = reset ? { reset: '1' } : boxFields(box);
            fields.key = tr.dataset.key;
            msg.className = 'kop-wbf-edit-msg'; msg.textContent = 'Saving...';
            send('kop_wbf_edit', fields).then(function (res) {
                if (!res.success) { msg.className = 'kop-wbf-edit-msg err'; msg.textContent = res.data || 'Failed.'; return; }
                tr.querySelector('.kop-wbf-label').textContent = res.data.label;
                tr.querySelector('.kop-wbf-goes').textContent = res.data.goes;
                var wrap = document.createElement('div');
                wrap.innerHTML = res.data.form;
                var fresh = wrap.firstElementChild;
                fresh.open = !reset;
                box.closest('.kop-wbf-edit').replaceWith(fresh);
                var m = fresh.querySelector('.kop-wbf-edit-msg');
                m.className = 'kop-wbf-edit-msg ok';
                m.textContent = reset ? '' : 'Saved. Tick it and add it as usual.';
                var pick = tr.querySelector('.kop-ak-pick');
                if (pick && !reset) { pick.checked = true; pick.dispatchEvent(new Event('change', { bubbles: true })); }
            }).catch(function () { msg.className = 'kop-wbf-edit-msg err'; msg.textContent = 'Network error; try again.'; });
        }

        // Another person in the same words: a new ticked item right under this one.
        function addPerson(box) {
            var tr = box.closest('tr[data-key]');
            var msg = box.querySelector('.kop-wbf-edit-msg');
            var fields = boxFields(box);
            fields.key = tr.dataset.key;
            msg.className = 'kop-wbf-edit-msg'; msg.textContent = 'Saving...';
            send('kop_wbf_person', fields).then(function (res) {
                if (!res.success) { msg.className = 'kop-wbf-edit-msg err'; msg.textContent = res.data || 'Failed.'; return; }
                var t = document.createElement('tbody');
                t.innerHTML = res.data.row;
                var row = t.firstElementChild;
                row.querySelector('.kop-ak-pick').checked = true;
                tr.insertAdjacentElement('afterend', row);
                row.querySelector('.kop-ak-pick').dispatchEvent(new Event('change', { bubbles: true }));
                box.querySelectorAll('input[data-f], textarea[data-f]').forEach(function (f) { f.value = ''; });
                msg.className = 'kop-wbf-edit-msg ok'; msg.textContent = 'Added below, ticked. Another?';
            }).catch(function () { msg.className = 'kop-wbf-edit-msg err'; msg.textContent = 'Network error; try again.'; });
        }

        // Type and "a different place" are for programs only.
        document.addEventListener('change', function (e) {
            if (!e.target.classList.contains('kop-wbf-ckind')) return;
            var box = e.target.closest('.kop-wbf-create');
            var program = e.target.value === 'facility';
            box.querySelector('.kop-wbf-conly').hidden = !program;
            box.querySelector('.kop-wbf-cnote').hidden = program;
        });

        document.addEventListener('click', function (e) {
            var save = e.target.closest('.kop-wbf-save, .kop-wbf-reset');
            if (save) { saveEdit(save.closest('.kop-wbf-edit-box'), save.classList.contains('kop-wbf-reset')); return; }
            var person = e.target.closest('.kop-wbf-addperson');
            if (person) { addPerson(person.closest('.kop-wbf-edit-box')); return; }
            // A close name the build offered: it fills the finder beside it.
            var alt = e.target.closest('.kop-wbf-alt');
            if (alt) {
                var card = alt.closest('.kop-ak-card');
                var box = card.querySelector('.kop-ak-fid');
                if (box) { box.value = alt.dataset.fid; box.dispatchEvent(new Event('change', { bubbles: true })); }
                card.querySelectorAll('.kop-wbf-alt').forEach(function (b) {
                    b.classList.toggle('kop-ak-btn--primary', b === alt);
                    b.classList.toggle('kop-ak-btn--quiet', b !== alt);
                });
            }
        });
    })();
    </script>
    <?php
}

/**
 * Waiting items whose record moved to the young adult programs (the record is
 * gone): onto the "Young adult programs (18+)" tab with that program picked,
 * no longer "Facility #N does not exist". Run after every load of the facts
 * file (which writes the build's record ids back) and when the queue opens.
 * Returns how many items moved.
 */
function kop_wbf_moved_to_ya() {
    global $wpdb;
    if (!function_exists('kop_ya_moved_from_map')) return 0;
    $map = kop_ya_moved_from_map();
    if (!$map) return 0;
    $n = 0;
    foreach ($map as $fid => $p) {
        $n += (int) $wpdb->query($wpdb->prepare('UPDATE ' . kop_wbf_table() . " SET facility_id = 0, ya = 1, ya_why = %s,
            conflict = CASE WHEN conflict LIKE '%%does not exist%%' THEN '' ELSE conflict END
            WHERE facility_id = %d AND status IN ('pending', 'gone')",
            'Its record moved to the young adult programs: ' . $p['name'] . ' (young adult program #' . (int) $p['id'] . ').', (int) $fid));
    }
    return $n;
}

/**
 * File waiting items on an existing company, consultant, provider or
 * transporter: each item's source line goes in the record's notes
 * (kop_wbc_add_notes()); Undo takes exactly those lines off again.
 * Returns [pkey => {ok}] like kop_wbf_apply().
 */
function kop_wbf_file_existing(array $rows, $kind, $id, $reviewer) {
    global $wpdb;
    $rows = array_values(array_filter($rows, function ($r) { return $r['status'] === 'pending'; }));
    if (!$rows) throw new RuntimeException('Those items were already handled. Reload the list.');
    $results = array();
    $now = current_time('mysql', true);
    foreach ($rows as $r) {
        $added = kop_wbc_add_notes($kind, $id, array(kop_wbf_source_line($r)));
        $wpdb->update(kop_wbf_table(), array('status' => 'applied', 'applied_fid' => 0, 'reviewed_by' => $reviewer, 'reviewed_at' => $now,
            'applied' => wp_json_encode(array('filed' => $kind, 'who' => 'firm', 'id' => (int) $added['id'], 'name' => $added['name'],
                'existing' => 1, 'lines' => $added['lines']))), array('pkey' => $r['pkey']));
        $results[$r['pkey']] = array('ok' => true, 'name' => $added['name']);
    }
    return $results;
}
