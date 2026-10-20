<?php
/**
 * Facility closures reported in the news.
 *
 * Every saved article (nightly discovery, the news processor, imports) is
 * scanned once by an hourly WP-Cron job: articles whose title, summary or type
 * talk about a closure go to the AI (Groq and Gemini in turn) with the article text and the facilities the
 * article is already linked to, and each program the article says closed, is
 * closing, was ordered closed or was suspended becomes a closure report in
 * facility_closure_reports (records DB, beside news_submissions).
 *
 * Nothing changes on the site until an admin confirms a report under KOP Data
 * Tools > Closure Reports. Confirming sets the facility's operatingPeriod
 * status in facilities_v2 (so its /facility/ page changes at once), fills the
 * end year from the closure date when the record has none, records the article
 * as the source in operatingPeriod.notes and links the article to the
 * facility. The network map follows facilities_v2 at serve time
 * (kop_network_map_status_overrides() in inc/network-map.php), so the same
 * confirm recolours the map. Undo puts the old status, end year and notes back.
 *
 * Server CLI: php api/scan-closure-reports.php (dry run unless "apply").
 */

// Dropdown lists (kop_choices(); KOP Tools > Dropdown Lists)
require_once __DIR__ . '/choice-lists.php';


if (!defined('ABSPATH')) {
    exit;
}

define('KOP_CLOSURE_REPORTS_DB_VERSION', '1');

/** Report statuses, as the review screen names them. */
function kop_closure_report_statuses() {
    return array(
        'pending'   => 'To review',
        'unmatched' => 'No facility match',
        'applied'   => 'Confirmed',
        'dismissed' => 'Dismissed',
        'already'   => 'Already recorded',
        'duplicate' => 'Duplicate',
        'vague'     => 'No program named',
        'old'       => 'Old news',
    );
}

/**
 * Report statuses that wait for a person. 'vague' (the article gives no
 * program's name: "youth facility", "Missouri boarding school") and 'old'
 * (a suspension years ago) are kept for the record but never queued.
 */
function kop_closure_open_statuses() {
    return array('pending', 'unmatched');
}

/**
 * A suspension older than this many days is history, not news; so is an
 * announced closing ('closing') older than KOP_CLOSURE_OLD_CLOSING_DAYS of a
 * record that is still open (Lincoln Hills has been "closing" since 2018).
 */
define('KOP_CLOSURE_OLD_SUSPENSION_DAYS', 365);
define('KOP_CLOSURE_OLD_CLOSING_DAYS', 730);

/**
 * Words that say what kind of place a program is and never which one. A
 * report whose name is only these, stopwords and a state ("juvenile boot
 * camps", "Group Home", "mental health center", "South Dakota boot camp")
 * names no program. Narrower than discovery's genericAliasWords on purpose:
 * "Milestones", "Ascent" and "Sequel" are real programs.
 */
function kop_closure_descriptor_words() {
    static $words = null;
    if ($words === null) {
        $words = array_flip(preg_split('/\s+/', trim('
            a an the of in at on for and to its their this that former controversial troubled embattled shuttered closed
            unlicensed licensed private nonprofit for-profit christian religious faith based catholic state county city local regional
            youth juvenile juveniles teen teens teenage teenaged adolescent adolescents child children childrens kid kids boy boys girl girls
            young people adult adults women men mothers student students minors migrant
            facility facilities center centers centre home homes group house houses program programs school schools academy academies
            institute institution hospital hospitals clinic clinics unit units campus campuses shelter shelters camp camps boot ranch ranches
            residential treatment mental health behavioral behavioural psychiatric therapy therapeutic counseling care day drug addiction
            detention correctional corrections prison prisons jail jails reform training rehabilitation rehab services service development
            boarding charter military style wilderness facility operator provider agency rtc rtf strtp
            street avenue road ave st
        ')));
    }
    return $words;
}

/** Lowercase US state names and two-letter codes => code. */
function kop_closure_state_words() {
    static $map = null;
    if ($map === null) {
        $map = array();
        $states = array(
            'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
            'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia',
            'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas',
            'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan',
            'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
            'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina',
            'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
            'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont',
            'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
        );
        foreach ($states as $code => $name) {
            $map[strtolower($name)] = $code;
            $map[strtolower($code)] = $code;
        }
    }
    return $map;
}

/** The two-letter state a report's location names, or ''. */
function kop_closure_location_state($location) {
    $text = ' ' . kop_closure_norm($location) . ' ';
    $names = kop_closure_state_words();
    uksort($names, static function ($a, $b) { return strlen($b) - strlen($a); });   // West Virginia before Virginia
    foreach ($names as $word => $code) {
        if (strlen($word) === 2) {
            // A code only as its own upper-case token ("Macon, GA"), never "in" or "me".
            if (preg_match('/(^|[\s,])' . strtoupper($word) . '($|[\s,.])/', (string) $location)) return $code;
        } elseif (strpos($text, ' ' . $word . ' ') !== false) {
            return $code;
        }
    }
    return '';
}

/**
 * Does the report's name fail to name a program? True when nothing is left
 * once descriptor words and stopwords are taken off, along with a trailing
 * "in/at/near <place>" after words written in lower case ("treatment center
 * in Raeford", "girls' boot camp at Plankinton"); or when only a state is
 * left and the rest is lower case ("Missouri boarding school",
 * "Massachusetts psychiatric hospital"). Words written as a title keep their
 * place: "Academy at Ivy Ridge", "Youth Center at Beloit" and "Iowa Juvenile
 * Home" are names.
 */
function kop_closure_is_vague_name($name) {
    $name = trim((string) $name);
    $head = preg_split('/\s+(?:in|at|near|outside|on)\s+/i', $name, 2)[0];
    // A title: no word of three letters or more starts in lower case, but for the small ones.
    if ($head !== $name && !preg_match('/(?<![\p{L}\'’])(?!(?:and|of|for|the)\b)[a-z]{3,}/u', $head)) {
        $head = $name;
    }
    $words = array_values(array_filter(explode(' ', kop_closure_norm(str_replace(array("'", '’'), '', $head))), 'strlen'));
    if (!$words) return true;
    $descriptors = kop_closure_descriptor_words();
    $states = kop_closure_state_words();
    $left = array();
    foreach ($words as $w) {
        if (isset($descriptors[$w]) || preg_match('/^\d+(st|nd|rd|th)?$/', $w)) continue;
        $left[] = $w;
    }
    if (!$left) return true;
    $rest = trim(preg_replace('/\s+/', ' ', ' ' . implode(' ', $left) . ' '));
    foreach (array_keys($states) as $s) {
        if (strlen($s) > 2) $rest = trim(str_replace(' ' . $s . ' ', ' ', ' ' . $rest . ' '));
    }
    if ($rest !== '') return false;
    // Only a state is left: a name when its other words are capitalised.
    preg_match_all('/[A-Za-z]+/', $head, $m);
    foreach ($m[0] as $w) {
        if (isset($descriptors[strtolower($w)]) && strlen($w) > 3 && ctype_lower($w[0])) return true;
    }
    return false;
}

/**
 * What the article says happened, and the facility status each one sets:
 * stage => {label, status, ai_hint}. The stages are in
 * inc/choice-lists/sorting.php (KOP Tools > Dropdown Lists); $mode 'offered'
 * leaves out hidden ones (the AI's list).
 */
function kop_closure_report_stages($mode = 'all') {
    return kop_choices('closure-stages', null, $mode);
}

/**
 * $reported: the closure date the article gives, else its publication date
 * ('YYYY', 'YYYY-MM' or 'YYYY-MM-DD'; '' when neither is known). Only used to
 * set a suspension long past aside.
 */
function kop_closure_classify_report($facility_id, $current_status, $target_status, $reported = '', $stage = '') {
    if (!$facility_id) {
        return 'unmatched';
    }
    if ($current_status === 'Closed' || $current_status === $target_status) {
        return 'already';
    }
    if ($target_status === 'Suspended' && kop_closure_days_ago($reported) > KOP_CLOSURE_OLD_SUSPENSION_DAYS) {
        return 'old';
    }
    if ($stage === 'closing' && kop_closure_days_ago($reported) > KOP_CLOSURE_OLD_CLOSING_DAYS) {
        return 'old';
    }
    return 'pending';
}

/** Days since a 'YYYY[-MM[-DD]]' date (its last day for a year or month), or -1. */
function kop_closure_days_ago($date) {
    $date = trim((string) $date);
    if (!preg_match('/^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?/', $date, $m)) {
        return -1;
    }
    $y = (int) $m[1];
    $mo = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 12;
    $d = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) gmdate('t', gmmktime(0, 0, 0, $mo, 1, $y));
    $ts = gmmktime(0, 0, 0, $mo, $d, $y);
    return $ts ? (int) floor((time() - $ts) / 86400) : -1;
}

/**
 * A name's words split in two: [distinctive words, kinds of place], each as
 * word => true. Lake, Hills, Ridge stay distinctive here ("Copper Lake" is
 * not "Copper Hills"), unlike kop_news_stopwords().
 */
function kop_closure_name_words($text) {
    static $audience = null;
    if ($audience === null) {
        $audience = array_flip(explode(' ', 'youth juvenile juveniles teen teens teenage teenaged adolescent adolescents child children childrens '
            . 'kid kids boy boys girl girls young people adult adults women men mothers student students minors migrant '
            . 'christian catholic religious faith based private nonprofit state county city local regional former'));
    }
    $descriptors = kop_closure_descriptor_words();
    $states = kop_closure_state_words();
    $distinct = array();
    $kind = array();
    foreach (explode(' ', kop_normalize_name_key((string) $text)) as $t) {
        if ($t === '' || ctype_digit($t) || in_array($t, array('inc', 'llc', 'ltd', 'corp', 'co', 'system', 'systems', 'formerly', 'former'), true)) continue;
        if (isset($descriptors[$t])) {
            // Who a program serves is no kind of place: "Okaloosa Youth
            // Development Center" is not Okaloosa Youth Academy.
            if (!isset($audience[$t])) $kind[rtrim($t, 's')] = true;
        } elseif (strlen($t) >= 3 && !isset($states[$t])) {
            $distinct[$t] = true;
        }
    }
    return array($distinct, $kind);
}

/**
 * A report's name as [name, place]: "Youth Rehabilitation and Treatment
 * Center in Geneva" -> [Youth Rehabilitation and Treatment Center, Geneva].
 * A trailing acronym is dropped ("... Center (YRTC)"): it names no place.
 */
function kop_closure_name_head($program) {
    $name = preg_replace('/\s*\(\s*[A-Z]{2,8}\s*\)\s*$/', '', trim((string) $program));
    $parts = preg_split('/\s+(?:in|at|near)\s+/i', $name, 2);
    return array($parts[0], trim($parts[1] ?? ''));
}

/**
 * Facilities a report's name may mean when it matches no name exactly: a
 * record whose name (or another name) holds every distinctive word of the
 * report's, or whose distinctive words all appear in the report's
 * ("Dundee Ranch" -> Academy at Dundee Ranch, "Ivy Ridge" -> Academy at Ivy
 * Ridge, "Straight Inc." in Tampa Bay -> Straight – Tampa Bay). Kept to the
 * report's state when it names one. A name of descriptor words alone goes
 * by its place ("Youth Rehabilitation and Treatment Center in Geneva" ->
 * Geneva Youth Treatment and Rehabilitation Center). Returns ['loose' =>
 * [id => status], 'strict' => [id => confirmed], 'own' => [id => true] for
 * the records matched by their own name].
 */
function kop_closure_name_candidates(PDO $pdo, $program, $location, array $alias_index) {
    static $facilities = null;
    if ($facilities === null) {
        $facilities = array();
        foreach ($pdo->query('SELECT id, state, status FROM facilities_v2') as $f) {
            $facilities[(int) $f['id']] = array('state' => strtoupper(trim((string) $f['state'])), 'status' => (string) $f['status']);
        }
    }
    list($head, $where) = kop_closure_name_head($program);
    list($mine, $my_kind) = kop_closure_name_words($head);
    if (!$mine && $my_kind) {
        // Only the place tells it apart: "in Geneva", else the location's town.
        list($mine) = kop_closure_name_words($where !== '' ? $where : explode(',', (string) $location)[0]);
    }
    if (!$mine) {
        return array();
    }
    $place = array_flip(explode(' ', kop_normalize_name_key((string) $location)));
    $state = kop_closure_location_state($location);
    $out = array('loose' => array(), 'strict' => array(), 'own' => array());
    foreach ($alias_index['exact'] ?? array() as $key => $fid) {
        $fid = (int) $fid;
        if (!isset($facilities[$fid])) continue;
        if ($state !== '' && $facilities[$fid]['state'] !== '' && $facilities[$fid]['state'] !== $state) continue;
        list($theirs, $their_kind) = kop_closure_name_words((string) $key);
        // Loose: every distinctive word of the report's name is in the record's.
        if (array_diff_key($mine, $theirs)) continue;
        // "Gulf Coast Youth Academy" is not "Gulf Coast Treatment Center".
        if ($my_kind && $their_kind && !array_intersect_key($my_kind, $their_kind)) continue;
        // One distinctive word is a weak match: it needs the same kind of
        // place too ("Bay County boot camp" is not Straight – Tampa Bay,
        // "Eckerd wilderness camp" not Eckerd Connects E-Nini-Hassee).
        if (count($mine) === 1 && $my_kind && !array_intersect_key($my_kind, $their_kind)) continue;
        $out['loose'][$fid] = $facilities[$fid]['status'];
        if ($key === kop_normalize_name_key((string) ($alias_index['names'][$fid] ?? ''))) $out['own'][$fid] = true;
        // Strict: the record's other words are the report's place ("Straight
        // Inc." in Tampa Bay -> Straight – Tampa Bay). Confirmed by a shared
        // kind of place or the same state, or it is only a guess.
        if (!array_diff_key(array_diff_key($theirs, $mine), $place)) {
            $confirmed = ($my_kind && array_intersect_key($my_kind, $their_kind)) || ($state !== '' && $facilities[$fid]['state'] === $state);
            $out['strict'][$fid] = !empty($out['strict'][$fid]) || $confirmed;
        }
    }
    return $out;
}

/**
 * The record a report's name means by kop_closure_name_candidates(), or 0:
 * the only loose match; else the only strict one, when confirmed; else the
 * only confirmed strict one matched by its own name (Academy at Dundee Ranch,
 * not Pillars of Hope, which lists it among its old names). Several matches
 * that are all Closed (or already at the report's status) return the first,
 * so the report is filed as already recorded.
 */
function kop_closure_pick_candidate(array $cands, $target_status) {
    $loose = $cands['loose'] ?? array();
    if (count($loose) === 1) {
        return (int) array_key_first($loose);
    }
    $strict = $cands['strict'] ?? array();
    if (count($strict) === 1 && reset($strict)) {
        return (int) array_key_first($strict);
    }
    $own = array_intersect_key(array_filter($strict), $cands['own'] ?? array());
    if (count($own) === 1) {
        return (int) array_key_first($own);
    }
    $done = array_filter($loose, static function ($s) use ($target_status) {
        return $s === 'Closed' || $s === $target_status;
    });
    return $loose && count($done) === count($loose) ? (int) array_key_first($loose) : 0;
}

/** The records DB (news_submissions lives there, not in $wpdb on prod). */
function kop_closure_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if ($pdo) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function kop_closure_ensure_tables(PDO $pdo) {
    if (get_option('kop_closure_reports_db') === KOP_CLOSURE_REPORTS_DB_VERSION) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_closure_scans (
        news_id INT NOT NULL,
        content_hash CHAR(32) NOT NULL,
        outcome VARCHAR(20) NOT NULL,
        attempts INT NOT NULL DEFAULT 1,
        detail TEXT NULL,
        scanned_at DATETIME NOT NULL,
        PRIMARY KEY (news_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS facility_closure_reports (
        id INT NOT NULL AUTO_INCREMENT,
        news_id INT NOT NULL,
        facility_id INT NULL,
        program_name VARCHAR(255) NOT NULL,
        location VARCHAR(255) NULL,
        stage VARCHAR(20) NOT NULL,
        target_status VARCHAR(20) NOT NULL,
        closure_date VARCHAR(10) NULL,
        quote TEXT NULL,
        quote_found TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        previous_status VARCHAR(20) NULL,
        previous_end_year INT NULL,
        applied_note TEXT NULL,
        reviewed_by VARCHAR(100) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY news_program (news_id, program_name(150)),
        KEY status (status),
        KEY facility (facility_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_closure_reports_db', KOP_CLOSURE_REPORTS_DB_VERSION);
}

/* ---- Which articles to scan ----------------------------------------- */

/**
 * Does the article talk about a closure at all? Cheap gate before Groq: most
 * articles are lawsuits and arrests and never need the call.
 */
function kop_closure_article_mentions_closure(array $news) {
    if (($news['article_type'] ?? '') === 'closure') {
        return true;
    }
    $text = ($news['article_title'] ?? '') . ' ' . ($news['summary'] ?? '');
    return (bool) preg_match(
        '/\b(clos(e|ed|es|ing|ure|ures)|shut(s|ting)?\s+(down|its doors)|shut\s*down|shutter(ed|s|ing)?|ceas(e|ed|es|ing)\s+operat|wind(s|ing)?\s+down|wound\s+down|revok(e|ed|es|ing)|revocation|surrender(ed|s|ing)?\s+(its|their|the)?\s*licen[cs]e|no longer (operat|accept|serv)|suspend(ed|s|ing)?|suspension)\b/i',
        $text
    );
}

/** Changes when anything the scan reads from the row changes. */
function kop_closure_news_hash(array $news) {
    return md5(implode("\x1f", array(
        $news['article_title'] ?? '', $news['summary'] ?? '', $news['article_type'] ?? '', $news['article_url'] ?? '', 'v1',
    )));
}

/**
 * Articles not yet scanned in their current form. Rejected, deleted and
 * promotional rows are never scanned. A row that failed is retried on later
 * runs, three times at most.
 */
function kop_closure_articles_to_scan(PDO $pdo, $limit, array $only_ids = array()) {
    $where = "n.status NOT IN ('rejected','deleted','promotional')";
    $params = array();
    if ($only_ids) {
        $where .= ' AND n.id IN (' . implode(',', array_fill(0, count($only_ids), '?')) . ')';
        $params = array_map('intval', $only_ids);
    }
    // A dry run on a site that has never scanned has no scans table yet.
    $scans = $pdo->query("SHOW TABLES LIKE 'news_closure_scans'")->fetchColumn()
        ? 's.content_hash, s.outcome, s.attempts FROM news_submissions n LEFT JOIN news_closure_scans s ON s.news_id = n.id'
        : 'NULL AS content_hash, NULL AS outcome, 0 AS attempts FROM news_submissions n';
    $stmt = $pdo->prepare("SELECT n.id, n.article_title, n.publication_name, n.publication_date, n.article_url,
                                  n.article_type, n.summary, n.status, n.json_data, {$scans}
                            WHERE {$where}
                         ORDER BY n.id DESC");
    $stmt->execute($params);
    $out = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $hash = kop_closure_news_hash($row);
        $fresh = $row['content_hash'] === $hash;
        if (!$only_ids && $fresh && ($row['outcome'] !== 'error' || (int) $row['attempts'] >= 3)) {
            continue;
        }
        $row['hash'] = $hash;
        $out[] = $row;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function kop_closure_record_scan(PDO $pdo, $news_id, $hash, $outcome, $detail = '') {
    $pdo->prepare("INSERT INTO news_closure_scans (news_id, content_hash, outcome, attempts, detail, scanned_at)
                   VALUES (?, ?, ?, 1, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE
                       attempts = IF(content_hash = VALUES(content_hash) AND outcome = 'error', attempts + 1, 1),
                       content_hash = VALUES(content_hash), outcome = VALUES(outcome),
                       detail = VALUES(detail), scanned_at = VALUES(scanned_at)")
        ->execute(array((int) $news_id, $hash, $outcome, mb_substr((string) $detail, 0, 2000)));
}

/* ---- Asking the AI ------------------------------------------------- */

/** Facilities the article is already linked to, for the model to pick from. */
function kop_closure_linked_facilities(PDO $pdo, $news_id) {
    $stmt = $pdo->prepare("SELECT f.id, f.unique_name, f.city, f.state, f.status
                             FROM news_facility_links l
                             JOIN facilities_v2 f ON f.id = l.facility_id
                            WHERE l.news_id = ?
                            LIMIT 40");
    $stmt->execute(array((int) $news_id));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function kop_closure_build_prompt(array $news, array $linked, $text) {
    $p  = "You read news articles about youth residential programs (troubled teen industry: residential treatment centers, therapeutic boarding schools, wilderness programs, group homes, juvenile facilities) and report which programs the article says have closed.\n\n";
    $p .= 'Title: ' . $news['article_title'] . "\n";
    $p .= 'Publication: ' . ($news['publication_name'] ?: 'unknown') . "\n";
    $p .= 'Published: ' . ($news['publication_date'] ?: 'unknown') . "\n";
    $p .= 'URL: ' . $news['article_url'] . "\n";
    if (trim((string) $news['summary']) !== '') {
        $p .= 'Summary: ' . trim($news['summary']) . "\n";
    }
    if ($linked) {
        $p .= "\nPrograms in our database that this article is linked to (id | name | place | status we have):\n";
        foreach ($linked as $f) {
            $place = trim(implode(', ', array_filter(array($f['city'], $f['state']))));
            $p .= $f['id'] . ' | ' . $f['unique_name'] . ' | ' . ($place ?: '-') . ' | ' . ($f['status'] ?: 'Unknown') . "\n";
        }
    }
    $p .= "\nArticle text:\n" . ($text !== '' ? $text : '(could not be fetched; use the title and summary)') . "\n\n";
    $p .= "Return ONLY a JSON object:\n";
    $stages = kop_closure_report_stages('offered');
    $p .= '{"closures":[{"program":"name as the article gives it","facilityId":null,"location":"City, State","stage":"' . implode('|', array_keys($stages)) . '","date":"YYYY-MM-DD, YYYY-MM or YYYY, or empty","quote":"one sentence copied word for word from the article that says it"}]}' . "\n\n";
    $p .= "Stages:\n";
    foreach ($stages as $k => $st) {
        $p .= '- ' . $k . ': ' . trim(preg_replace('/\s+/', ' ', (string) ($st['ai_hint'] ?? $st['label']))) . "\n";
    }
    $p .= "Leave out:\n";
    $p .= "- attempts, threats, calls, petitions or proposals to close that did not happen, and closures that were reversed;\n";
    $p .= "- one unit, wing, campus building or service closing while the program stays open;\n";
    $p .= "- a program renamed or sold that kept operating under a new name;\n";
    $p .= "- companies, agencies, bank branches and anything that is not a youth program. When a company closed its programs, list each program the article names.\n";
    $p .= "- a program the article does not name (\"a youth facility\", \"the group home\", \"Missouri boarding school\"): program is always the name the article gives it.\n";
    $p .= "facilityId: the id from the list above when the program is one of them, otherwise null. Never guess an id.\n";
    $p .= "If the article reports no closure, return {\"closures\":[]}.\n";
    return $p;
}

/** Lowercase letters and digits only, single-spaced: for matching quotes. */
function kop_closure_norm($s) {
    $s = html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($s)));
}

/**
 * Is the quote really in the article? The model is told to copy it word for
 * word; one it made up is the clearest sign the whole report is wrong. Checks
 * the first twelve words, so a trimmed or re-punctuated ending still counts.
 */
function kop_closure_quote_found($quote, $haystack) {
    $words = explode(' ', kop_closure_norm($quote));
    if (count($words) < 4) {
        return false;
    }
    $needle = implode(' ', array_slice($words, 0, 12));
    return strpos(' ' . kop_closure_norm($haystack) . ' ', ' ' . $needle . ' ') !== false;
}

/** 'YYYY-MM-DD', 'YYYY-MM', 'YYYY' or '' from whatever the model wrote. */
function kop_closure_clean_date($raw) {
    $raw = trim((string) $raw);
    if (preg_match('/^(\d{4})(-(0[1-9]|1[0-2]))?(-(0[1-9]|[12]\d|3[01]))?$/', $raw, $m)) {
        $year = (int) $m[1];
        if ($year >= 1950 && $year <= (int) gmdate('Y') + 2) {
            return $raw;
        }
    }
    return '';
}

/**
 * Turn the model's answer into clean report rows. Unknown stages, empty names
 * and ids that were not in the list it was given are dropped or cleared.
 */
function kop_closure_parse_reply($reply, array $linked) {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $data = is_array($reply) ? $reply : kop_ai_extract_json((string) $reply);
    if (!is_array($data) || !isset($data['closures']) || !is_array($data['closures'])) {
        return null;
    }
    $stages = kop_closure_report_stages();
    $allowed_ids = array();
    foreach ($linked as $f) {
        $allowed_ids[(int) $f['id']] = true;
    }
    $out = array();
    $seen = array();
    foreach ($data['closures'] as $c) {
        if (!is_array($c)) {
            continue;
        }
        $name = trim(preg_replace('/\s+/', ' ', (string) ($c['program'] ?? '')));
        $stage = strtolower(trim((string) ($c['stage'] ?? '')));
        if ($name === '' || !isset($stages[$stage])) {
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $fid = isset($c['facilityId']) && is_numeric($c['facilityId']) ? (int) $c['facilityId'] : 0;
        $out[] = array(
            'program'     => mb_substr($name, 0, 255),
            'facility_id' => isset($allowed_ids[$fid]) ? $fid : null,
            'location'    => mb_substr(trim((string) ($c['location'] ?? '')), 0, 255),
            'stage'       => $stage,
            'date'        => kop_closure_clean_date($c['date'] ?? ''),
            'quote'       => mb_substr(trim((string) ($c['quote'] ?? '')), 0, 1000),
        );
    }
    return $out;
}

/** A facility id from the article's own links, then the name/alias index. */
function kop_closure_resolve_facility(PDO $pdo, array $report, &$alias_index) {
    if (!empty($report['facility_id'])) {
        return (int) $report['facility_id'];
    }
    if ($alias_index === null) {
        require_once get_stylesheet_directory() . '/api/facility-aliases.php';
        $alias_index = kop_build_facility_alias_index($pdo);
    }
    $fid = kop_resolve_mention_to_facility($report['program'], $alias_index);
    if ($fid === null) {
        return null;
    }
    // The index also holds operator projects; only a facility can close here.
    $stmt = $pdo->prepare('SELECT 1 FROM facilities_v2 WHERE id = ?');
    $stmt->execute(array($fid));
    return $stmt->fetchColumn() ? (int) $fid : null;
}

/**
 * Where a report goes: array(facility_id|null, status, current facility
 * status|null). In order: the exact name (or the article's own link); else
 * the looser name match, taken when it finds one record, or several that are
 * all already Closed; else 'vague' when the name names no program. A matched
 * one is 'already' when the record says so, 'old' for a suspension long past,
 * and 'duplicate' when the same change to the same facility is already
 * waiting, or was dismissed from an article at least as recent.
 *
 * $report: program, location, facility_id, target_status, date; $published:
 * the article's publication date; $id: the report's own row when settling
 * one that is stored (so it is not its own duplicate).
 */
function kop_closure_settle_report(PDO $pdo, array $report, $published, &$alias_index, $id = 0) {
    // "Embark Behavioral Health (formerly Shelterwood Academy)": the program
    // that closed is the former one, whatever id the model picked.
    if (preg_match('/\(\s*(?:formerly|previously|once)\s+([^()]+)\)\s*$/i', (string) $report['program'], $m)) {
        $report['program'] = trim($m[1]);
        $report['facility_id'] = null;
    }
    if (kop_closure_is_vague_name($report['program'])) {
        return array(null, 'vague', null);
    }
    $fid = kop_closure_resolve_facility($pdo, $report, $alias_index);
    if ($fid && empty($report['facility_id']) && !kop_closure_name_words(kop_closure_name_head($report['program'])[0])[0]) {
        // A name of descriptor words alone is one record's name but many
        // places': "Youth Rehabilitation and Treatment Center" (Kearney) in
        // Geneva, Nebraska is the Geneva one. It stands unless the report's
        // place names another town than the record's.
        $city = $pdo->prepare('SELECT city FROM facilities_v2 WHERE id = ?');
        $city->execute(array($fid));
        $city = kop_normalize_name_key((string) $city->fetchColumn());
        $place = implode(' ', array(kop_closure_name_head($report['program'])[1], (string) ($report['location'] ?? '')));
        $towns = kop_closure_name_words($place)[0];   // a state alone names no town
        if ($city !== '' && $towns && strpos(' ' . kop_normalize_name_key($place) . ' ', " $city ") === false) {
            $fid = null;
        }
    }
    $current = null;
    $status_of = static function ($fid) use ($pdo) {
        $stmt = $pdo->prepare('SELECT status FROM facilities_v2 WHERE id = ?');
        $stmt->execute(array((int) $fid));
        $s = $stmt->fetchColumn();
        return $s === false ? null : (string) $s;
    };
    if (!$fid) {
        $fid = kop_closure_pick_candidate(
            kop_closure_name_candidates($pdo, $report['program'], $report['location'] ?? '', $alias_index),
            $report['target_status']
        ) ?: null;
    }
    if (!$fid) {
        return array(null, 'unmatched', null);
    }
    $current = $status_of($fid);
    $reported = ($report['date'] ?? '') !== '' ? $report['date'] : (string) $published;
    $status = kop_closure_classify_report($fid, $current, $report['target_status'], $reported, (string) ($report['stage'] ?? ''));
    if ($status === 'pending') {
        $waiting = $pdo->prepare("SELECT COUNT(*) FROM facility_closure_reports
                                   WHERE facility_id = ? AND target_status = ? AND status = 'pending' AND id <> ?");
        $waiting->execute(array($fid, $report['target_status'], (int) $id));
        if ((int) $waiting->fetchColumn() > 0) {
            $status = 'duplicate';
        } else {
            $dismissed = $pdo->prepare("SELECT n.publication_date FROM facility_closure_reports r
                                     LEFT JOIN news_submissions n ON n.id = r.news_id
                                         WHERE r.facility_id = ? AND r.target_status = ? AND r.status = 'dismissed' AND r.id <> ?");
            $dismissed->execute(array($fid, $report['target_status'], (int) $id));
            foreach ($dismissed->fetchAll(PDO::FETCH_COLUMN) as $then) {
                // Not newer news than a report the owner already turned down.
                if ((string) $published === '' || ((string) $then !== '' && strcmp((string) $then, (string) $published) >= 0)) {
                    $status = 'duplicate';
                    break;
                }
            }
        }
    }
    return array($fid, $status, $current);
}

/**
 * Settle the stored reports again, with today's rules and records: the
 * queue as it stood before 2026-10-20 held generic names and programs the
 * records already had as closed under another spelling. Every status the
 * scan set itself (waiting, already, duplicate, old, vague), never one the
 * owner gave (reviewed_at), so a looser earlier match is undone too. Oldest
 * first, so the first report of a change stays and later ones are its
 * duplicates. Returns [status => count] of the rows that moved.
 */
function kop_closure_resettle_open(PDO $pdo) {
    $alias_index = null;
    $moved = array();
    $set = $pdo->prepare('UPDATE facility_closure_reports SET facility_id = ?, status = ? WHERE id = ? AND status = ?');
    $rows = $pdo->query("SELECT r.id, r.news_id, r.program_name, r.location, r.facility_id, r.stage, r.target_status, r.closure_date, r.status, n.publication_date
                           FROM facility_closure_reports r LEFT JOIN news_submissions n ON n.id = r.news_id
                          WHERE r.status IN ('pending','unmatched','already','duplicate','old','vague')
                            AND r.reviewed_at IS NULL ORDER BY r.id")->fetchAll(PDO::FETCH_ASSOC);
    $linked = $pdo->prepare('SELECT 1 FROM news_facility_links WHERE news_id = ? AND facility_id = ?');
    foreach ($rows as $r) {
        // The model only picks among the article's own links: a stored id that
        // is not one of them came from a name match, which is made again.
        $kept = null;
        if ($r['facility_id'] !== null) {
            $linked->execute(array((int) $r['news_id'], (int) $r['facility_id']));
            $kept = $linked->fetchColumn() ? (int) $r['facility_id'] : null;
        }
        list($fid, $status) = kop_closure_settle_report($pdo, array(
            'program' => $r['program_name'], 'location' => (string) $r['location'],
            'facility_id' => $kept,
            'stage' => $r['stage'], 'target_status' => $r['target_status'], 'date' => (string) $r['closure_date'],
        ), (string) $r['publication_date'], $alias_index, (int) $r['id']);
        if ($status === $r['status'] && (int) $fid === (int) $r['facility_id']) continue;
        $set->execute(array($fid, $status, (int) $r['id'], $r['status']));
        $moved[$status] = ($moved[$status] ?? 0) + 1;
    }
    return $moved;
}

/**
 * An article's text for a Groq prompt, or '' when it cannot be fetched.
 * Groq's free tier counts tokens per minute, and what the scans look for is
 * nearly always in the opening paragraphs, so it is cut at $max bytes.
 */
function kop_closure_article_text($url, $max = 9000) {
    if (!$url || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    require_once get_stylesheet_directory() . '/api/lib-article-fetch.php';
    $text = (string) fetchArticleContent($url);
    if (strlen($text) > $max) {
        $text = mb_strcut($text, 0, $max, 'UTF-8') . ' [truncated]';
    }
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    return $text;
}

/**
 * Ask the AI: Groq and Gemini take turns (api/ai-providers.php), each with
 * its own fallbacks, and the other answers when one fails. Throws the joined
 * errors, whose message says "rate limit" when a provider was out of calls.
 */
function kop_closure_ai($prompt, $max_tokens = 2048) {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    return kop_ai_generate_alternating($prompt, array('maxTokens' => $max_tokens));
}

/**
 * Scan one article. Returns array(outcome, reports[], detail). With $write
 * false nothing is stored (the CLI dry run).
 */
function kop_closure_scan_article(PDO $pdo, array $news, $write, &$alias_index) {
    if (!kop_closure_article_mentions_closure($news)) {
        if ($write) {
            kop_closure_record_scan($pdo, $news['id'], $news['hash'], 'skipped');
        }
        return array('skipped', array(), '');
    }

    $text = kop_closure_article_text($news['article_url'] ?? '');
    $linked = kop_closure_linked_facilities($pdo, $news['id']);

    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    try {
        $raw = kop_closure_ai(kop_closure_build_prompt($news, $linked, $text));
    } catch (Throwable $e) {
        // A rate limit says nothing about the article: leave it unscanned so
        // the next run tries again without spending one of its three tries.
        if ($write && stripos($e->getMessage(), 'rate limit') === false) {
            kop_closure_record_scan($pdo, $news['id'], $news['hash'], 'error', $e->getMessage());
        }
        return array('error', array(), $e->getMessage());
    }
    $reports = kop_closure_parse_reply($raw, $linked);
    if ($reports === null) {
        if ($write) {
            kop_closure_record_scan($pdo, $news['id'], $news['hash'], 'error', 'Unreadable reply: ' . mb_substr((string) $raw, 0, 300));
        }
        return array('error', array(), 'Unreadable reply');
    }

    $stages = kop_closure_report_stages();
    $haystack = $text . ' ' . $news['article_title'] . ' ' . $news['summary'];
    foreach ($reports as &$r) {
        $r['target_status'] = $stages[$r['stage']]['status'];
        $r['quote_found'] = kop_closure_quote_found($r['quote'], $haystack);
        list($r['facility_id'], $r['report_status'], $r['current_status']) =
            kop_closure_settle_report($pdo, $r, (string) ($news['publication_date'] ?? ''), $alias_index);
    }
    unset($r);

    if ($write) {
        $ins = $pdo->prepare("INSERT IGNORE INTO facility_closure_reports
            (news_id, facility_id, program_name, location, stage, target_status, closure_date, quote, quote_found, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
        foreach ($reports as $r) {
            $ins->execute(array(
                (int) $news['id'], $r['facility_id'], $r['program'], $r['location'], $r['stage'], $r['target_status'],
                $r['date'] !== '' ? $r['date'] : null, $r['quote'], $r['quote_found'] ? 1 : 0, $r['report_status'],
            ));
        }
        kop_closure_record_scan($pdo, $news['id'], $news['hash'], $reports ? 'found' : 'none', $text === '' ? 'no article text' : '');
    }
    return array($reports ? 'found' : 'none', $reports, $text === '' ? 'no article text' : '');
}

/**
 * Scan up to $limit articles. Stops early on an AI rate limit or when
 * $seconds run out. Returns counts and the new reports that need a person.
 */
function kop_closure_scan_batch(PDO $pdo, $limit, $seconds, $write = true, array $only_ids = array(), $log = null) {
    if ($write) {
        kop_closure_ensure_tables($pdo);
    }
    $started = time();
    $alias_index = null;
    $counts = array('scanned' => 0, 'skipped' => 0, 'none' => 0, 'found' => 0, 'error' => 0);
    $new = array();
    foreach (kop_closure_articles_to_scan($pdo, $limit, $only_ids) as $news) {
        list($outcome, $reports, $detail) = kop_closure_scan_article($pdo, $news, $write, $alias_index);
        $counts['scanned']++;
        $counts[$outcome]++;
        if ($log) {
            $log($news, $outcome, $reports, $detail);
        }
        foreach ($reports as $r) {
            if ($r['report_status'] === 'pending' || $r['report_status'] === 'unmatched') {
                $new[] = array('news' => $news, 'report' => $r);
            }
        }
        if ($outcome === 'error' && stripos($detail, 'rate limit') !== false) {
            break;
        }
        if (time() - $started > $seconds) {
            break;
        }
    }
    return array('counts' => $counts, 'new' => $new);
}

/* ---- Confirming, dismissing, undoing -------------------------------- */

function kop_closure_get_report(PDO $pdo, $id) {
    $stmt = $pdo->prepare("SELECT r.*, n.article_title, n.publication_name, n.publication_date, n.article_url, n.status AS news_status
                             FROM facility_closure_reports r
                        LEFT JOIN news_submissions n ON n.id = r.news_id
                            WHERE r.id = ?");
    $stmt->execute(array((int) $id));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The line written into operatingPeriod.notes, naming the article. */
function kop_closure_source_note(array $report) {
    $stages = kop_closure_report_stages();
    $what = $stages[$report['stage']]['label'] ?? $report['target_status'];
    $bits = array_filter(array(
        $report['publication_name'] ?: '',
        $report['article_title'] ? '"' . $report['article_title'] . '"' : '',
        $report['publication_date'] ?: '',
    ));
    $note = $what . ($report['closure_date'] ? ' (' . $report['closure_date'] . ')' : '')
        . ' per news report: ' . implode(', ', $bits);
    if ($report['article_url']) {
        $note .= ' ' . $report['article_url'];
    }
    return $note;
}

/**
 * The document rebuilt with $change applied to its operatingPeriod, the way
 * the deploy seeds write (kop_v2_apply_facility_record_seed()): through the
 * legacy shape and kop_facility_normalize(), so a document saved under an
 * older schema version comes out current and passes kop_facility_save().
 */
function kop_closure_rewrite_period(array $doc, callable $change) {
    $legacy = kop_facility_to_legacy($doc);
    $legacy['operatingPeriod'] = $change(is_array($legacy['operatingPeriod'] ?? null) ? $legacy['operatingPeriod'] : array());
    $out = kop_facility_normalize($legacy, array(
        'facility_id' => $doc['facility_id'] ?? null,
        'unique_name' => $doc['provenance']['uniqueName'] ?? '',
    ));
    $out['provenance'] = $doc['provenance'] ?? $out['provenance'];
    return $out;
}

/**
 * A facility document with the report's status set. Returns array(doc,
 * previous status, previous end year). The end year is only filled when the
 * record has none: a year someone entered by hand wins over the article.
 */
function kop_closure_doc_apply(array $doc, $target_status, $end_year, $note) {
    $period = is_array($doc['operatingPeriod'] ?? null) ? $doc['operatingPeriod'] : array();
    $previous_status = $period['status'] ?? 'Unknown';
    $previous_end = isset($period['endYear']) && $period['endYear'] !== null ? (int) $period['endYear'] : null;
    $out = kop_closure_rewrite_period($doc, static function (array $op) use ($target_status, $end_year, $note, $previous_end) {
        $op['status'] = $target_status;
        if ($end_year && $previous_end === null) {
            $op['endYear'] = (int) $end_year;
        }
        $notes = is_array($op['notes'] ?? null) ? $op['notes'] : array();
        if (!in_array($note, $notes, true)) {
            $notes[] = $note;
        }
        $op['notes'] = $notes;
        return $op;
    });
    return array($out, $previous_status, $previous_end);
}

/** The document with a confirmed report taken back out. */
function kop_closure_doc_undo(array $doc, array $report) {
    return kop_closure_rewrite_period($doc, static function (array $op) use ($report) {
        // Only undo what is still ours: a status someone has since changed stays.
        if (($op['status'] ?? '') === $report['target_status']) {
            $op['status'] = $report['previous_status'] ?: 'Unknown';
        }
        // A confirm only fills an empty end year, so an empty one before
        // means the year there now came from the confirm.
        if ($report['previous_end_year'] === null) {
            $op['endYear'] = null;
        }
        $op['notes'] = array_values(array_filter((array) ($op['notes'] ?? array()), static function ($n) use ($report) {
            return $n !== $report['applied_note'];
        }));
        return $op;
    });
}

function kop_closure_v2_opts(PDO $pdo) {
    global $wpdb;
    require_once get_stylesheet_directory() . '/inc/facility-v2-writer.php';
    if (!kop_v2_writes_active($pdo, $wpdb->prefix)) {
        throw new RuntimeException('Facility saves are not on facilities_v2 yet; change the status in the data form.');
    }
    return array('pdo' => $pdo, 'prefix' => $wpdb->prefix);
}

/**
 * Set the facility's status from a report. $facility_id overrides the match
 * (for an unmatched report, or a wrong one); $end_year overrides the year
 * taken from the closure date.
 */
function kop_closure_apply(PDO $pdo, $id, $reviewer, $facility_id = 0, $end_year = null) {
    $report = kop_closure_get_report($pdo, $id);
    if (!$report) {
        throw new RuntimeException('No such report.');
    }
    if ($report['status'] === 'applied') {
        throw new RuntimeException('Already confirmed.');
    }
    $fid = (int) $facility_id ?: (int) $report['facility_id'];
    if ($fid <= 0) {
        throw new RuntimeException('Pick the facility first (its id from the facility page or the data form).');
    }
    $opts = kop_closure_v2_opts($pdo);
    $stored = kop_facility_load($fid, $opts);
    if (!$stored) {
        throw new RuntimeException("Facility #{$fid} does not exist.");
    }
    if ($end_year === null && $report['target_status'] === 'Closed' && $report['closure_date']) {
        $end_year = (int) substr($report['closure_date'], 0, 4);
    }
    $note = kop_closure_source_note($report);
    // A renamed program merged into one record: a closure dated in an earlier
    // name's years is that name ending, and the place kept operating.
    if ($report['target_status'] === 'Closed' && $end_year && function_exists('kop_facility_eras_doc_name_at')) {
        $then = kop_facility_eras_doc_name_at($stored['doc'], $end_year);
        if ($then !== '') {
            throw new RuntimeException('In ' . (int) $end_year . ' this program was still called ' . $then
                . ', which was renamed and kept operating: the record stays as it is. Reject the report, or give a later closure year if it closed again.');
        }
    }

    kop_v2_with_write_lock($pdo, function () use ($pdo, $opts, $stored, $report, $end_year, $note, $reviewer, $fid, $id) {
        list($doc, $previous_status, $previous_end) = kop_closure_doc_apply($stored['doc'], $report['target_status'], $end_year, $note);
        kop_facility_save($doc, $opts);

        $pdo->prepare("UPDATE facility_closure_reports
                          SET status = 'applied', facility_id = ?, previous_status = ?, previous_end_year = ?,
                              applied_note = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                        WHERE id = ?")
            ->execute(array($fid, $previous_status, $previous_end, $note, $reviewer, (int) $id));
        // Other open reports of the same change to the same facility are done too.
        $pdo->prepare("UPDATE facility_closure_reports
                          SET status = 'duplicate', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                        WHERE facility_id = ? AND target_status = ? AND status IN ('pending','unmatched') AND id <> ?")
            ->execute(array($reviewer, $fid, $report['target_status'], (int) $id));
    });

    // The article belongs on the facility's page as its source.
    $pdo->prepare("INSERT IGNORE INTO news_facility_links (news_id, facility_id, link_type, created_by) VALUES (?, ?, 'primary', ?)")
        ->execute(array((int) $report['news_id'], $fid, $reviewer));
    do_action('kop_facility_status_changed', $fid);
    return $fid;
}

/** Put back what a confirm changed, and reopen the report. */
function kop_closure_undo(PDO $pdo, $id, $reviewer) {
    $report = kop_closure_get_report($pdo, $id);
    if (!$report || $report['status'] !== 'applied') {
        throw new RuntimeException('Only a confirmed report can be undone.');
    }
    $fid = (int) $report['facility_id'];
    $opts = kop_closure_v2_opts($pdo);
    $stored = kop_facility_load($fid, $opts);
    if (!$stored) {
        throw new RuntimeException("Facility #{$fid} does not exist.");
    }
    kop_v2_with_write_lock($pdo, function () use ($pdo, $opts, $stored, $report, $id) {
        kop_facility_save(kop_closure_doc_undo($stored['doc'], $report), $opts);
        $pdo->prepare("UPDATE facility_closure_reports
                          SET status = 'pending', previous_status = NULL, previous_end_year = NULL, applied_note = NULL,
                              reviewed_by = NULL, reviewed_at = NULL
                        WHERE id = ?")
            ->execute(array((int) $id));
    });
    do_action('kop_facility_status_changed', $fid);
    return $fid;
}

function kop_closure_set_status(PDO $pdo, $id, $status, $reviewer) {
    $pdo->prepare("UPDATE facility_closure_reports SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
                    WHERE id = ? AND status <> 'applied'")
        ->execute(array($status, $reviewer, (int) $id));
}

/* ---- Hourly scan ---------------------------------------------------- */

add_action('init', function () {
    if (!wp_next_scheduled('kop_closure_scan_hourly')) {
        wp_schedule_event(time() + 600, 'hourly', 'kop_closure_scan_hourly');
    }
});

add_action('kop_closure_scan_hourly', 'kop_closure_scan_cron');

function kop_closure_scan_cron() {
    if (get_transient('kop_closure_scan_lock')) {
        return;
    }
    set_transient('kop_closure_scan_lock', 1, 10 * MINUTE_IN_SECONDS);
    try {
        $pdo = kop_closure_pdo();
        if (!$pdo) {
            return;
        }
        // The queue as it stood before the 2026-10-20 rules, settled again once
        // ('b': the name match no longer counts "youth" or "systems"; 'c': rows
        // an earlier pass moved out of the queue are settled again too).
        if (get_option('kop_closure_resettled') !== '2026-10-20d') {
            $moved = kop_closure_resettle_open($pdo);
            update_option('kop_closure_resettled', '2026-10-20d', false);
            error_log('kop closure resettle: ' . json_encode($moved));
        }
        // Past the keyword gate most rows cost nothing, so a run can look at
        // many; Groq calls are what the time budget is for.
        $result = kop_closure_scan_batch($pdo, 40, 150);
        if ($result['new'] && function_exists('kop_notify_admins')) {
            $fields = array();
            foreach (array_slice($result['new'], 0, 20) as $i => $item) {
                $stages = kop_closure_report_stages();
                $fields['Report ' . ($i + 1)] = $item['report']['program'] . ' - '
                    . $stages[$item['report']['stage']]['label'] . ' - ' . $item['news']['article_title'];
            }
            $n = count($result['new']);
            kop_notify_admins('closure_report', $n . ' facility closure ' . ($n === 1 ? 'report' : 'reports') . ' to review', '', $fields);
        }
    } catch (Throwable $e) {
        error_log('kop closure scan: ' . $e->getMessage());
    } finally {
        delete_transient('kop_closure_scan_lock');
    }
}

/* ---- Review screen -------------------------------------------------- */

add_action('admin_menu', function () {
    if (!function_exists('kop_tools_parent_slug')) {
        return;
    }
    add_submenu_page(
        kop_tools_parent_slug(),
        'Closure Reports',
        'Closure Reports',
        'manage_options',
        'kop-closure-reports',
        'kop_render_closure_reports_page'
    );
}, 21);

function kop_closure_facility_cell(PDO $pdo, $fid) {
    static $cache = array();
    if (!$fid) {
        return '<em>No match</em>';
    }
    if (!isset($cache[$fid])) {
        $stmt = $pdo->prepare('SELECT unique_name, city, state, status, end_year FROM facilities_v2 WHERE id = ?');
        $stmt->execute(array((int) $fid));
        $cache[$fid] = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $f = $cache[$fid];
    if (!$f) {
        return '#' . (int) $fid . ' <em>(gone)</em>';
    }
    $url = function_exists('kop_facility_page_url') ? kop_facility_page_url((int) $fid) : '';
    $name = esc_html($f['unique_name']);
    $html = $url ? '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"><strong>' . $name . '</strong></a>' : '<strong>' . $name . '</strong>';
    $place = trim(implode(', ', array_filter(array($f['city'], $f['state']))));
    return $html . ' <span style="color:#666">#' . (int) $fid . ($place ? ' &middot; ' . esc_html($place) : '') . '</span>'
        . '<br>Now: <strong>' . esc_html($f['status'] ?: 'Unknown') . '</strong>' . ($f['end_year'] ? ' (ended ' . (int) $f['end_year'] . ')' : '');
}

function kop_render_closure_reports_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Not authorized', 'Access Denied', array('response' => 403));
    }
    $pdo = kop_closure_pdo();
    if (!$pdo) {
        echo '<div class="wrap"><h1>Closure Reports</h1><p>The records database is not reachable.</p></div>';
        return;
    }
    kop_closure_ensure_tables($pdo);
    $statuses = kop_closure_report_statuses();
    $stages = kop_closure_report_stages();
    $user = wp_get_current_user()->user_login;
    $base = admin_url('admin.php?page=kop-closure-reports');

    echo '<div class="wrap"><h1>Closure Reports</h1>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kop_cr_action'])) {
        check_admin_referer('kop_closure_reports');
        $action = sanitize_key($_POST['kop_cr_action']);
        $id = (int) ($_POST['kop_cr_id'] ?? 0);
        $report = $id ? kop_closure_get_report($pdo, $id) : null;
        $about = $report ? '&ldquo;' . esc_html($report['program_name']) . '&rdquo;' : 'the report';
        $status_now = static function ($fid) use ($pdo) {
            $stmt = $pdo->prepare('SELECT status, end_year FROM facilities_v2 WHERE id = ?');
            $stmt->execute(array((int) $fid));
            $f = $stmt->fetch(PDO::FETCH_ASSOC) ?: array('status' => '', 'end_year' => null);
            return '<strong>' . esc_html($f['status'] ?: 'Unknown') . '</strong>' . ($f['end_year'] ? ', ended ' . (int) $f['end_year'] : '');
        };
        try {
            // Messages are built from escaped parts, so they are printed as HTML.
            if ($action === 'apply') {
                // A cleared box is 0: set no end year. No box at all (a
                // Suspended report) leaves it to the closure date, if any.
                $end = isset($_POST['kop_cr_end_year']) ? (int) $_POST['kop_cr_end_year'] : null;
                $fid = kop_closure_apply($pdo, $id, $user, (int) ($_POST['kop_cr_facility'] ?? 0), $end);
                $msg = 'Confirmed. ' . kop_facility_finder_label($pdo, $fid) . ' is now marked ' . $status_now($fid)
                    . '. Its facility page and the network map already show this. The report moved to the <em>Confirmed</em> tab, where Undo puts it back.';
            } elseif ($action === 'undo') {
                $fid = kop_closure_undo($pdo, $id, $user);
                $msg = 'Undone. ' . kop_facility_finder_label($pdo, $fid) . ' is back to ' . $status_now($fid) . ', and the report is waiting again.';
            } elseif ($action === 'dismiss') {
                kop_closure_set_status($pdo, $id, 'dismissed', $user);
                $msg = 'Dismissed the report about ' . $about . '. No facility was changed.';
            } elseif ($action === 'reopen') {
                kop_closure_set_status($pdo, $id, 'pending', $user);
                $msg = 'The report about ' . $about . ' is waiting for review again.';
            } elseif ($action === 'scan') {
                $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', (string) ($_POST['kop_cr_news'] ?? ''))));
                $result = kop_closure_scan_batch($pdo, $ids ? count($ids) : 10, 90, true, $ids);
                $c = $result['counts'];
                $msg = esc_html(sprintf('Scanned %d articles: %d with a closure, %d without, %d not about closures, %d failed.',
                    $c['scanned'], $c['found'], $c['none'], $c['skipped'], $c['error']));
            } else {
                $msg = '';
            }
            if ($msg !== '') {
                echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
            }
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
    }

    $filter = isset($_GET['cr_status']) ? sanitize_key($_GET['cr_status']) : 'pending';
    if ($filter !== 'all' && !isset($statuses[$filter])) {
        $filter = 'pending';
    }
    $counts = array();
    foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM facility_closure_reports GROUP BY status') as $row) {
        $counts[$row['status']] = (int) $row['n'];
    }
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM news_submissions n LEFT JOIN news_closure_scans s ON s.news_id = n.id
                                   WHERE s.news_id IS NULL AND n.status NOT IN ('rejected','deleted','promotional')")->fetchColumn();

    echo '<p>Articles that say a facility closed, found by the hourly scan of saved news. Nothing changes on the site until you confirm a report: '
        . 'confirming sets the facility\'s status, fills its end year from the closure date if it has none, cites the article in its operating notes '
        . 'and links the article to it. The facility page and the network map follow at once. Undo puts everything back.</p>';
    echo '<p style="color:#666">' . (int) $waiting . ' saved articles not scanned yet. Check the quote against the article before confirming: '
        . 'a quote marked <strong>not found</strong> could not be matched to the article text.</p>';

    echo '<ul class="subsubsub">';
    $tabs = array_merge($statuses, array('all' => 'All'));
    $i = 0;
    foreach ($tabs as $key => $label) {
        $n = $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0);
        echo '<li><a href="' . esc_url(add_query_arg('cr_status', $key, $base)) . '"' . ($filter === $key ? ' class="current"' : '') . '>'
            . esc_html($label) . ' (' . (int) $n . ')</a>' . (++$i < count($tabs) ? ' | ' : '') . '</li>';
    }
    echo '</ul><div style="clear:both"></div>';

    echo '<form method="post" style="margin:8px 0 16px">';
    wp_nonce_field('kop_closure_reports');
    echo '<input type="hidden" name="kop_cr_action" value="scan">'
        . '<button type="submit" class="button">Scan the next 10 articles now</button> '
        . '<label style="color:#666">or only these article numbers (optional) <input type="text" name="kop_cr_news" style="width:160px"></label></form>';

    $sql = "SELECT r.*, n.article_title, n.publication_name, n.publication_date, n.article_url, n.status AS news_status
              FROM facility_closure_reports r
         LEFT JOIN news_submissions n ON n.id = r.news_id";
    if ($filter === 'all') {
        $rows = $pdo->query($sql . ' ORDER BY r.id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare($sql . ' WHERE r.status = ? ORDER BY r.id DESC LIMIT 300');
        $stmt->execute(array($filter));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$rows) {
        echo '<p>Nothing here.</p></div>';
        return;
    }

    echo '<table class="widefat striped"><thead><tr>'
        . '<th style="width:40px">#</th><th style="width:230px">Facility</th><th style="width:150px">Reported</th>'
        . '<th>Article and quote</th><th style="width:250px">Action</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $stage = $stages[$r['stage']]['label'] ?? $r['stage'];
        echo '<tr>';
        echo '<td>' . (int) $r['id'] . '</td>';
        echo '<td>' . kop_closure_facility_cell($pdo, (int) $r['facility_id'])
            . '<br><span style="color:#666">Article names: ' . esc_html($r['program_name']) . ($r['location'] ? ' (' . esc_html($r['location']) . ')' : '') . '</span></td>';
        echo '<td><strong>' . esc_html($stage) . '</strong>' . ($r['closure_date'] ? '<br>' . esc_html($r['closure_date']) : '')
            . '<br>Sets: ' . esc_html($r['target_status'])
            . '<br><span style="color:#666">' . esc_html($statuses[$r['status']] ?? $r['status'])
            . ($r['reviewed_by'] ? ' by ' . esc_html($r['reviewed_by']) : '') . '</span></td>';
        echo '<td><a href="' . esc_url($r['article_url']) . '" target="_blank" rel="noopener">' . esc_html($r['article_title'] ?: '(article #' . (int) $r['news_id'] . ')') . '</a>'
            . '<br><span style="color:#666">' . esc_html(trim($r['publication_name'] . ' ' . $r['publication_date'])) . ' &middot; article #' . (int) $r['news_id']
            . ' &middot; ' . esc_html($r['news_status'] ?: 'missing') . '</span>';
        if ($r['news_status'] && !in_array($r['news_status'], array('approved', 'published'), true)) {
            echo '<br><em>This article is not approved yet, so it will not show on the facility page until it is.</em>';
        }
        if ($r['quote']) {
            echo '<blockquote style="margin:6px 0;padding-left:8px;border-left:3px solid ' . ($r['quote_found'] ? '#33A7B5' : '#d63638') . '">'
                . esc_html($r['quote']) . '</blockquote>';
            if (!$r['quote_found']) {
                echo '<strong style="color:#d63638">Quote not found in the article text.</strong>';
            }
        }
        echo '</td><td>';
        $form = static function ($action, $label, $extra = '', $primary = false) use ($r) {
            echo '<form method="post" style="margin:0 0 6px">';
            wp_nonce_field('kop_closure_reports');
            echo '<input type="hidden" name="kop_cr_action" value="' . esc_attr($action) . '">'
                . '<input type="hidden" name="kop_cr_id" value="' . (int) $r['id'] . '">' . $extra
                . '<button type="submit" class="button' . ($primary ? ' button-primary' : ' button-small') . '">' . esc_html($label) . '</button></form>';
        };
        if (in_array($r['status'], array('pending', 'unmatched'), true)) {
            $year = $r['closure_date'] && $r['target_status'] === 'Closed' ? substr($r['closure_date'], 0, 4) : '';
            $form('apply', 'Confirm: mark ' . $r['target_status'],
                '<div style="margin-bottom:6px"><strong>Which facility closed?</strong><br>' . kop_facility_finder_field('kop_cr_facility', $r['facility_id'])
                . '<br><span style="color:#666;font-size:12px">' . ($r['facility_id'] ? 'Filled in with the scan\'s match (left column). Search to change it.' : 'The scan found no match: search for it by name.') . '</span></div>'
                . ($r['target_status'] === 'Closed' ? '<label style="display:block;margin-bottom:4px">End year <input type="number" name="kop_cr_end_year" value="' . esc_attr($year) . '" style="width:70px"></label>' : ''),
                true);
            $form('dismiss', 'Dismiss: not a closure');
        } elseif ($r['status'] === 'applied') {
            $form('undo', 'Undo: restore the old status');
        } else {
            $form('reopen', 'Put back to review');
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}
