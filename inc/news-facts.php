<?php
/**
 * Facts from news articles: an hourly reader goes through every approved
 * article, newest first, and proposes what the article says that belongs on
 * a record.
 *
 * What counts (owner, 2026-10-28; docs/plan/4.6 item 17): a fact that lands
 * in a field or a map link, is about a named program, company or person,
 * rests on the article's own words (the quote is checked against the text),
 * and the record does not hold yet. Every kind at once: harm, accountability
 * (with its standing: alleged, charged, convicted, found, settled), who runs
 * the program, its history, money, who sends youth there, its practices, its
 * connections and on-record statements. Closures are left to the closure
 * scan (inc/closure-reports.php). Survivors are never named unless they
 * already have a public profile: their names are taken out of every line.
 *
 * Where a fact goes:
 *  - about a program the article is linked to (news_facility_links): a row in
 *    {prefix}kop_woodbury_facts with extra.via = 'news', so it is reviewed,
 *    added, held for the write-up and undone on the Woodbury Facts cards
 *    (origin "News articles"). Staff, opening years, size, ages, gender and
 *    type are approved automatically when nothing on the record disagrees,
 *    once the owner has switched that on (option kop_news_facts_auto; off
 *    while the first articles are checked by hand). Everything else waits.
 *  - anything else (a company, a person, an agency, a program with no link):
 *    news_facts, listed at Review inbox > Facts from news until each kind of
 *    record has its own Add.
 *
 * Tables (records DB, kop_seed_pdo()): news_fact_scans (which articles were
 * read, in which form) and news_facts (every fact the reader proposed).
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_NEWS_FACTS_DB_VERSION', '1');
// Moves when the prompt or the routing changes, so every article is read again.
define('KOP_NEWS_FACTS_READER', 'v1');

function kop_nf_pdo() {
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if ($pdo) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

function kop_nf_ensure_tables(PDO $pdo) {
    if (get_option('kop_news_facts_db') === KOP_NEWS_FACTS_DB_VERSION) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_fact_scans (
        news_id INT NOT NULL,
        content_hash CHAR(32) NOT NULL,
        outcome VARCHAR(20) NOT NULL,
        attempts INT NOT NULL DEFAULT 1,
        facts INT NOT NULL DEFAULT 0,
        detail TEXT NULL,
        scanned_at DATETIME NOT NULL,
        PRIMARY KEY (news_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS news_facts (
        id INT NOT NULL AUTO_INCREMENT,
        news_id INT NOT NULL,
        fkey CHAR(32) NOT NULL,
        category VARCHAR(24) NOT NULL,
        subject_kind VARCHAR(12) NOT NULL DEFAULT '',
        subject_name VARCHAR(255) NOT NULL DEFAULT '',
        facility_id INT NULL,
        person VARCHAR(160) NOT NULL DEFAULT '',
        role VARCHAR(255) NOT NULL DEFAULT '',
        value TEXT NULL,
        summary TEXT NULL,
        standing VARCHAR(16) NOT NULL DEFAULT '',
        event_date VARCHAR(10) NOT NULL DEFAULT '',
        quote TEXT NULL,
        quote_found TINYINT(1) NOT NULL DEFAULT 0,
        names_removed TINYINT(1) NOT NULL DEFAULT 0,
        route VARCHAR(12) NOT NULL DEFAULT '',
        wbf_pkey VARCHAR(32) NOT NULL DEFAULT '',
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        reviewed_by VARCHAR(100) NULL,
        reviewed_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY news_fact (news_id, fkey),
        KEY route_status (route, status),
        KEY facility (facility_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_news_facts_db', KOP_NEWS_FACTS_DB_VERSION);
}

/* ---- What counts ------------------------------------------------------ */

/**
 * Every kind of fact the reader asks for: [key => [label, group, what the
 * model is told]]. Groups: harm and accountability become critical
 * incidents; staff the staff lists; history, details and the rest notes.
 */
function kop_nf_categories() {
    return array(
        // Harm to youth in the program's care.
        'death'           => array('Death', 'harm', 'a young person died in the program\'s care or soon after leaving it'),
        'injury'          => array('Injury', 'harm', 'a young person was hurt'),
        'abuse'           => array('Abuse', 'harm', 'physical or emotional abuse by staff or allowed by staff'),
        'sexual_abuse'    => array('Sexual abuse', 'harm', 'sexual abuse or misconduct'),
        'restraint'       => array('Restraint', 'harm', 'physical, mechanical or chemical restraint (a bivy sack, a body wrap or a hold is restraint)'),
        'seclusion'       => array('Seclusion', 'harm', 'isolation or seclusion'),
        'neglect'         => array('Neglect', 'harm', 'denied food, water, medical care, schooling or contact with family'),
        'runaway'         => array('Runaway', 'harm', 'a young person ran away or went missing'),
        // What courts, police and regulators did.
        'arrest'          => array('Arrest', 'accountability', 'someone tied to the program was arrested'),
        'criminal_charge' => array('Criminal charge', 'accountability', 'criminal charges were filed'),
        'conviction'      => array('Conviction', 'accountability', 'a guilty plea, conviction or sentence'),
        'license_action'  => array('License action', 'accountability', 'a license was suspended, revoked, put on probation or surrendered'),
        'citation'        => array('Citation', 'accountability', 'regulators cited or substantiated a violation'),
        'fine'            => array('Fine', 'accountability', 'a fine or penalty, with the amount'),
        'placement_ban'   => array('Placement ban', 'accountability', 'a state or agency stopped sending youth there or removed them'),
        'investigation'   => array('Investigation', 'accountability', 'police, a state agency or a legislature opened an investigation'),
        'lawsuit'         => array('Lawsuit', 'accountability', 'a lawsuit was filed (who sued whom, the court)'),
        'settlement'      => array('Settlement', 'accountability', 'a case was settled, with the amount when given'),
        'verdict'         => array('Verdict', 'accountability', 'a jury verdict or court ruling'),
        // Who runs it.
        'staff'           => array('Staff', 'staff', 'a named person who works or worked there and their job (founder, owner, director, therapist, staff member)'),
        'owner'           => array('Owner', 'history', 'who owns the program'),
        'operator'        => array('Operator', 'history', 'which company runs the program'),
        'acquired'        => array('Sale', 'history', 'the program or its company was bought or sold (buyer, seller, price, year)'),
        'investor'        => array('Investor', 'history', 'a private equity firm or other investor that owns or funds the company'),
        // History.
        'opened'          => array('Opened', 'history', 'the year the program opened (value = the year)'),
        'renamed'         => array('Name change', 'history', 'the program was renamed (value = "Old name -> New name")'),
        'moved'           => array('Move', 'history', 'the program moved (value = "from -> to")'),
        // What it is.
        'capacity'        => array('Capacity', 'details', 'how many youth it holds or held (value = the number)'),
        'ages'            => array('Ages', 'details', 'the ages it takes (value = "12-17")'),
        'gender'          => array('Gender', 'details', 'boys, girls or both'),
        'program_type'    => array('Described as', 'details', 'what kind of program it is (residential treatment center, wilderness program, boarding school, group home...)'),
        // Money and placements.
        'funding'         => array('Public money', 'money', 'a government agency, insurer or Medicaid pays it (payer, amount, years)'),
        'rate'            => array('Cost', 'money', 'what it charges (per day, per month, tuition)'),
        'revenue'         => array('Revenue', 'money', 'the program\'s or company\'s revenue or profit'),
        'placement'       => array('Placements', 'money', 'which states, counties or agencies send youth there, and how many'),
        // How it works and who it works with.
        'practice'        => array('Practice', 'practice', 'a named method or rule used on youth (level system, no contact with parents, escorts, forced labor, attack therapy...)'),
        'connection'      => array('Connection', 'practice', 'a tie to another program, company, educational consultant, transport company, trade group, lobbyist or official'),
        'statement'       => array('Statement', 'practice', 'an on-record statement by an official, a staff member or the company: a denial, an admission or a finding (value = the words)'),
    );
}

/** The kinds approved automatically when nothing disagrees (owner: "years of operation, staff names, etc"). */
function kop_nf_auto_kinds() {
    return array('staff', 'opened', 'capacity', 'ages', 'gender', 'program_type');
}

function kop_nf_standings() {
    return array(
        'alleged' => 'alleged', 'charged' => 'charged', 'convicted' => 'convicted', 'acquitted' => 'acquitted',
        'dismissed' => 'dismissed', 'found' => 'found by regulators', 'settled' => 'settled', 'ruled' => 'ruled by a court',
        'admitted' => 'admitted', 'reported' => '',
    );
}

function kop_nf_auto_on() {
    return (bool) get_option('kop_news_facts_auto', false);
}

/* ---- Which articles to read ------------------------------------------ */

function kop_nf_news_hash(array $news) {
    return md5(implode("\x1f", array(
        $news['article_title'] ?? '', $news['article_url'] ?? '', $news['publication_date'] ?? '', KOP_NEWS_FACTS_READER,
    )));
}

/**
 * Approved articles not read in their current form, newest first (owner,
 * 2026-10-28). An article whose read failed is tried again, three times.
 */
function kop_nf_articles_to_read(PDO $pdo, $limit, array $only_ids = array()) {
    $where = "n.status IN ('approved','published') AND n.article_url LIKE 'http%'";
    $params = array();
    if ($only_ids) {
        $where = 'n.id IN (' . implode(',', array_fill(0, count($only_ids), '?')) . ')';
        $params = array_map('intval', $only_ids);
    }
    // A dry run on a site that has never read an article has no scans table yet.
    $has_scans = true;
    try {
        $pdo->query('SELECT 1 FROM news_fact_scans LIMIT 1');
    } catch (Throwable $e) {
        $has_scans = false;
    }
    $join = $has_scans
        ? 's.content_hash, s.outcome, s.attempts FROM news_submissions n LEFT JOIN news_fact_scans s ON s.news_id = n.id'
        : 'NULL AS content_hash, NULL AS outcome, 0 AS attempts FROM news_submissions n';
    $stmt = $pdo->prepare("SELECT n.id, n.article_title, n.publication_name, n.publication_date, n.article_url, n.archive_url,
                                  n.article_type, n.summary, n.survivors_mentioned, n.status, {$join}
                            WHERE {$where}
                         ORDER BY n.publication_date DESC, n.id DESC");
    $stmt->execute($params);
    $out = array();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['hash'] = kop_nf_news_hash($row);
        if (!$only_ids && $row['content_hash'] === $row['hash'] && ($row['outcome'] !== 'error' || (int) $row['attempts'] >= 3)) {
            continue;
        }
        $out[] = $row;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function kop_nf_record_scan(PDO $pdo, $news_id, $hash, $outcome, $facts, $detail = '') {
    $pdo->prepare("INSERT INTO news_fact_scans (news_id, content_hash, outcome, attempts, facts, detail, scanned_at)
                   VALUES (?, ?, ?, 1, ?, ?, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE
                       attempts = IF(content_hash = VALUES(content_hash) AND outcome = 'error', attempts + 1, 1),
                       content_hash = VALUES(content_hash), outcome = VALUES(outcome), facts = VALUES(facts),
                       detail = VALUES(detail), scanned_at = VALUES(scanned_at)")
        ->execute(array((int) $news_id, $hash, $outcome, (int) $facts, mb_substr((string) $detail, 0, 2000)));
}

/** The programs the article is linked to, with what their records already say. */
function kop_nf_linked(PDO $pdo, $news_id) {
    $stmt = $pdo->prepare("SELECT f.id, f.unique_name, f.city, f.state, f.status
                             FROM news_facility_links l
                             JOIN facilities_v2 f ON f.id = l.facility_id
                            WHERE l.news_id = ?
                            LIMIT 40");
    $stmt->execute(array((int) $news_id));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ---- Asking the AI --------------------------------------------------- */

function kop_nf_build_prompt(array $news, array $linked, $text) {
    $p  = "You read news articles about youth residential programs (the troubled teen industry: residential treatment centers, therapeutic boarding schools, wilderness programs, group homes, youth ranches, juvenile facilities, shelters for migrant children) for a public database of these programs. List every fact in the article that belongs on a program's, company's or person's record.\n\n";
    $p .= 'Title: ' . $news['article_title'] . "\n";
    $p .= 'Publication: ' . ($news['publication_name'] ?: 'unknown') . "\n";
    $p .= 'Published: ' . ($news['publication_date'] ?: 'unknown') . "\n\n";
    if ($linked) {
        $p .= "Programs this article is already linked to (use their id as facilityId when a fact is about one):\n";
        foreach ($linked as $f) {
            $p .= '- id ' . (int) $f['id'] . ': ' . $f['unique_name'] . ' (' . trim($f['city'] . ', ' . $f['state'], ', ') . ")\n";
        }
        $p .= "\n";
    }
    $p .= "Kinds of fact (category: what it covers):\n";
    foreach (kop_nf_categories() as $k => $c) {
        $p .= "- {$k}: {$c[2]}\n";
    }
    $p .= "\nA fact counts only when ALL of these hold:\n";
    $p .= "1. It is about a named program, company or person. Background about the industry in general, statistics about many programs, and the reporter's opinion do not count.\n";
    $p .= "2. The article states it. Copy the sentence it rests on word for word into quote (no paraphrase, no ellipsis).\n";
    $p .= "3. It is specific: a name, a year, an amount, an event. \"Concerns were raised\" is not a fact.\n";
    $p .= "Leave out closures (another reader handles them) and anything you would have to guess.\n\n";
    $p .= "Survivors and victims: NEVER give the name of a young person or former resident who was harmed, or of their family, in any field. Write \"a 15-year-old girl\" or \"a former resident\" instead. The only names allowed are staff, owners, companies, officials, lawyers and the name of a young person who died.\n";
    $p .= "Restraint is restraint: never call a death in restraint, a bivy sack or a hold an accident or a medical event unless the article's own finding says so, and then say who found it.\n\n";
    $p .= "standing (for harm and accountability facts): alleged (someone says so), charged, convicted, acquitted, dismissed, found (a regulator or investigator substantiated it), settled, ruled (a court decided), admitted (the program admits it), reported (the article states it as fact).\n\n";
    $p .= "Return ONLY JSON, no other text:\n";
    $p .= "{\"facts\": [{\"category\": \"one key from the list\", \"subject\": \"the program, company or person the fact is about, as the article names it\", "
        . "\"subjectKind\": \"facility|company|person|agency\", \"facilityId\": null, \"person\": \"for staff, arrests, charges, statements: the person's full name\", "
        . "\"role\": \"their job or title, as the article gives it\", \"value\": \"the year, number, amount, name change or words, when the category asks for one\", "
        . "\"date\": \"YYYY-MM-DD, YYYY-MM or YYYY when the event happened (not the article's date), or empty\", \"standing\": \"\", "
        . "\"summary\": \"one plain sentence stating the fact, with no survivor's name\", \"quote\": \"the article's exact words\"}]}\n";
    $p .= "An article with nothing that counts returns {\"facts\": []}.\n\n";
    $p .= "Article text:\n" . ($text !== '' ? $text : '(The article text could not be fetched. Use only the title and summary below, and return no facts unless they state one.)') . "\n\n";
    $p .= 'Summary on file: ' . ($news['summary'] ?? '') . "\n";
    return $p;
}

function kop_nf_ai($prompt) {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $GLOBALS['kop_ai_job'] = 'News facts';
    try {
        return kop_ai_generate_alternating($prompt, array('maxTokens' => 6000, 'temperature' => 0.1));
    } finally {
        unset($GLOBALS['kop_ai_job']);
    }
}

function kop_nf_clean_date($raw) {
    $raw = trim((string) $raw);
    if (preg_match('/^(1[89]\d\d|20\d\d)(-(0[1-9]|1[0-2])(-(0[1-9]|[12]\d|3[01]))?)?$/', $raw)) {
        return $raw;
    }
    return preg_match('/\b(1[89]\d\d|20\d\d)\b/', $raw, $m) ? $m[1] : '';
}

/** The model's reply as clean facts, or null when it cannot be read. */
function kop_nf_parse_reply($reply, array $linked) {
    $reply = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim((string) $reply));
    $data = json_decode($reply, true);
    if (!is_array($data) && preg_match('/\{.*\}/s', $reply, $m)) {
        $data = json_decode($m[0], true);
    }
    if (!is_array($data) || !isset($data['facts']) || !is_array($data['facts'])) {
        return null;
    }
    $cats = kop_nf_categories();
    $ids = array();
    foreach ($linked as $f) {
        $ids[(int) $f['id']] = $f;
    }
    $standings = kop_nf_standings();
    $out = array();
    $seen = array();
    foreach ($data['facts'] as $f) {
        if (!is_array($f)) continue;
        $cat = strtolower(trim((string) ($f['category'] ?? '')));
        if (!isset($cats[$cat])) continue;
        $subject = trim((string) ($f['subject'] ?? ''));
        $summary = trim(preg_replace('/\s+/', ' ', (string) ($f['summary'] ?? '')));
        if ($subject === '' || $summary === '') continue;
        $fid = (int) ($f['facilityId'] ?? 0);
        $kind = strtolower(trim((string) ($f['subjectKind'] ?? '')));
        $standing = strtolower(trim((string) ($f['standing'] ?? '')));
        $fact = array(
            'category'     => $cat,
            'subject'      => mb_substr($subject, 0, 255),
            'subject_kind' => in_array($kind, array('facility', 'company', 'person', 'agency'), true) ? $kind : 'facility',
            // Only a program the article is linked to: an id the model made up is no id.
            'facility_id'  => isset($ids[$fid]) ? $fid : 0,
            'person'       => mb_substr(trim((string) ($f['person'] ?? '')), 0, 160),
            'role'         => mb_substr(trim((string) ($f['role'] ?? '')), 0, 255),
            'value'        => trim(preg_replace('/\s+/', ' ', (string) ($f['value'] ?? ''))),
            'date'         => kop_nf_clean_date($f['date'] ?? ''),
            'standing'     => isset($standings[$standing]) ? $standing : '',
            'summary'      => $summary,
            'quote'        => trim(preg_replace('/\s+/', ' ', (string) ($f['quote'] ?? ''))),
        );
        $fact['fkey'] = kop_nf_fact_key($fact);
        if (isset($seen[$fact['fkey']])) continue;
        $seen[$fact['fkey']] = true;
        $out[] = $fact;
    }
    return $out;
}

/** One fact per article: the same kind, subject and person/value/date is one. */
function kop_nf_fact_key(array $f) {
    $norm = function ($s) {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $s)));
    };
    $what = $f['category'] === 'staff' ? $norm($f['person'])
        : ($f['value'] !== '' ? $norm($f['value']) : $norm($f['date']) . '|' . substr($norm($f['summary']), 0, 60));
    return md5(implode('|', array($f['category'], $f['facility_id'] ?: $norm($f['subject']), $norm($f['person']), $what)));
}

/* ---- Survivors' names ------------------------------------------------- */

/**
 * The survivors and victims the news processor found in the article
 * (news_submissions.survivors_mentioned), as full names and surnames.
 */
function kop_nf_survivor_names($json) {
    $list = json_decode((string) $json, true);
    $names = array();
    foreach ((array) $list as $n) {
        $n = trim(is_array($n) ? (string) ($n['name'] ?? '') : (string) $n);
        // Initials and pseudonyms ("J.D.", "Jane Doe") name nobody.
        if ($n === '' || !preg_match('/[a-z]{2,}\s+[A-Z][a-z]+/u', $n) || preg_match('/\bdoe\b/i', $n)) continue;
        $names[] = $n;
    }
    return $names;
}

/**
 * Takes survivors' names out of a fact's text fields. A young person who
 * died keeps their name (the memorial names them); the person field of a
 * staff fact is never a survivor's. Returns true when anything came out.
 */
function kop_nf_scrub_survivors(array &$fact, array $names) {
    if (!$names || $fact['category'] === 'death') return false;
    $removed = false;
    foreach (array('summary', 'value', 'quote', 'subject', 'person') as $field) {
        foreach ($names as $n) {
            $parts = preg_split('/\s+/', $n);
            $pattern = '/\b' . preg_quote($n, '/') . '\b/iu';
            if (preg_match($pattern, $fact[$field])) {
                $fact[$field] = preg_replace($pattern, $field === 'quote' ? '[name removed]' : 'a former resident', $fact[$field]);
                $removed = true;
            }
            // The surname alone, after the article has named them once ("Smith said..."), but not
            // another person's name ("Jane Doe-Smith", "Dr. Ann Smith"): never in the person field.
            $last = end($parts);
            if ($field === 'person' || mb_strlen($last) < 4) continue;
            $fact[$field] = preg_replace_callback('/(?<![\p{L}-])((\p{Lu}[\p{Ll}\'.]+)\s+)?' . preg_quote($last, '/') . '\b/u',
                function ($m) use (&$removed) {
                    $before = $m[2] ?? '';
                    if ($before !== '' && !preg_match('/^(Mr|Mrs|Ms|Miss)\.?$/', $before)) return $m[0];
                    $removed = true;
                    return '[name removed]';
                }, $fact[$field]);
        }
    }
    if ($removed && in_array($fact['person'], array('a former resident', '[name removed]'), true)) {
        $fact['person'] = '';
    }
    return $removed;
}

/* ---- Where each fact goes -------------------------------------------- */

function kop_nf_cite(array $news) {
    $date = (string) ($news['publication_date'] ?? '');
    return trim(($news['publication_name'] ?: 'News article') . ', "' . trim((string) $news['article_title']) . '"' . ($date !== '' ? ', ' . $date : ''));
}

function kop_nf_leadership($role) {
    return (bool) preg_match('/\b(founder|co-?founder|owner|president|ceo|chief executive|executive director|head ?master|head of school|principal|administrator|chairman|managing director|general manager|program director|campus director|site director|clinical director)\b/i', (string) $role)
        || preg_match('/^director$/i', trim((string) $role));
}

/**
 * The Woodbury Facts row for a fact about a linked program, or null when the
 * fact has no place on a facility record (it waits at Facts from news).
 * Same shapes as scripts/woodbury-facts.py makes: add_staff, set_if_empty,
 * add_list on criticalIncidents.customIncidents and notes.
 */
function kop_nf_wbf_row(array $fact, array $news, $quote_found) {
    $fid = (int) $fact['facility_id'];
    if ($fid <= 0) return null;
    $cats = kop_nf_categories();
    list($label, $group) = $cats[$fact['category']];
    $cite = kop_nf_cite($news);
    $value = null;
    $grp = $group;
    $vkey = '';
    switch ($group) {
        case 'harm':
        case 'accountability':
            $standing = kop_nf_standings()[$fact['standing']] ?? '';
            $line = ($fact['date'] !== '' ? $fact['date'] . ': ' : '') . $label . ': ' . rtrim($fact['summary'], '.')
                . ($standing !== '' && stripos($fact['summary'], $standing) === false ? ' (' . $standing . ')' : '') . '. (' . $cite . ')';
            $op = 'add_list';
            $path = 'criticalIncidents.customIncidents';
            $value = $line;
            $grp = 'incident';
            $vkey = $fact['category'] . '|' . ($fact['date'] ?: strtolower(substr($fact['summary'], 0, 80)));
            $title = $label . ': ' . $fact['summary'];
            break;
        case 'staff':
            if ($fact['person'] === '' || preg_match('/\d/', $fact['person']) || count(preg_split('/\s+/', $fact['person'])) < 2) return null;
            $op = 'add_staff';
            $path = kop_nf_leadership($fact['role']) ? 'staff.administrator' : 'staff.notableStaff';
            $value = array('name' => $fact['person'], 'role' => $fact['role'], 'pastJobs' => '');
            $vkey = strtolower(preg_replace('/[^a-z ]/i', '', $fact['person']));
            $title = $fact['person'] . ($fact['role'] !== '' ? ', ' . $fact['role'] : '');
            break;
        default:
            if ($fact['category'] === 'opened' && preg_match('/^(1[89]\d\d|20\d\d)$/', $fact['value'] ?: $fact['date'])) {
                $year = (int) ($fact['value'] ?: $fact['date']);
                $op = 'set_if_empty';
                $path = 'operatingPeriod.startYear';
                $value = $year;
                $vkey = 'start|' . $year;
                $title = 'Start year: ' . $year;
                break;
            }
            $what = $fact['value'] !== '' && !in_array($fact['category'], array('practice', 'connection', 'statement', 'funding', 'placement'), true)
                ? $fact['value'] : rtrim($fact['summary'], '.');
            $op = 'add_list';
            $path = 'notes';
            $value = $label . ': ' . $what . ' (' . $cite . ': ' . $news['article_url'] . ')';
            $grp = $group === 'history' ? 'history' : 'details';
            $vkey = $fact['category'] . '|' . strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $what));
            $title = $label . ': ' . $what;
    }
    $auto = kop_nf_auto_on() && in_array($fact['category'], kop_nf_auto_kinds(), true) && $quote_found && empty($fact['names_removed']);
    $pub = (string) ($news['publication_date'] ?? '');
    return array(
        'pkey'               => substr(md5('news|' . $fid . '|' . $op . '|' . $path . '|' . $vkey), 0, 32),
        'facility_id'        => $fid,
        'program'            => (string) $fact['linked_name'],
        'program_as_written' => mb_substr($fact['subject'], 0, 255),
        'place'              => '',
        // 'id': the article's own link names the record, so no "close name" warning; never the exact-name auto pass.
        'match_kind'         => 'id',
        'match_note'         => '',
        'alternatives'       => '[]',
        'grp'                => $grp,
        'op'                 => $op,
        'path'               => $path,
        'value'              => wp_json_encode($value),
        'label'              => mb_substr($title, 0, 2000),
        'conflict'           => '',
        'current_val'        => '',
        'extra'              => wp_json_encode(array('via' => 'news', 'news_id' => (int) $news['id'], 'category' => $fact['category'],
            'standing' => $fact['standing'], 'names_removed' => !empty($fact['names_removed']))),
        'evidence'           => wp_json_encode(array(kop_nf_evidence($fact, $news, $quote_found))),
        'found'              => $quote_found ? 1 : 0,
        'preselect'          => $quote_found ? 1 : 0,
        'auto'               => $auto ? 1 : 0,
        'issue_date'         => substr($pub, 0, 7),
        'ya'                 => 0,
        'ya_why'             => '',
    );
}

function kop_nf_evidence(array $fact, array $news, $quote_found) {
    return array('cite' => kop_nf_cite($news), 'url' => (string) $news['article_url'], 'label' => '', 'page' => 0,
        'quote' => mb_substr($fact['quote'], 0, 600), 'found' => (bool) $quote_found, 'pub' => 1,
        'date' => (string) ($news['publication_date'] ?? ''), 'news_id' => (int) $news['id']);
}

/**
 * Store a facility fact on the Woodbury Facts cards. The same fact from a
 * second article joins the waiting row as more evidence; a decided row is
 * left as it is.
 */
function kop_nf_file_wbf(array $row) {
    global $wpdb;
    if (!function_exists('kop_wbf_table')) return '';
    kop_wbf_ensure_table();
    $table = kop_wbf_table();
    $have = $wpdb->get_row($wpdb->prepare("SELECT pkey, status, evidence, found FROM {$table} WHERE pkey = %s", $row['pkey']), ARRAY_A);
    if (!$have) {
        $row['status'] = 'pending';
        $row['created_at'] = current_time('mysql', true);
        $wpdb->insert($table, $row);
        return $row['pkey'];
    }
    if ($have['status'] === 'pending') {
        $ev = json_decode((string) $have['evidence'], true) ?: array();
        $new = json_decode($row['evidence'], true)[0];
        foreach ($ev as $e) {
            if (($e['url'] ?? '') === $new['url']) return $have['pkey'];
        }
        $ev[] = $new;
        $wpdb->update($table, array('evidence' => wp_json_encode($ev), 'found' => max((int) $have['found'], (int) $row['found'])),
            array('pkey' => $have['pkey']));
    }
    return $have['pkey'];
}

/* ---- Reading one article --------------------------------------------- */

function kop_nf_quote_found($quote, $haystack) {
    if (function_exists('kop_closure_quote_found')) {
        return kop_closure_quote_found($quote, $haystack);
    }
    return $quote !== '' && stripos($haystack, $quote) !== false;
}

/**
 * Read one article. Returns array(outcome, facts[], detail). With $write
 * false nothing is stored (the dry run). $ai replaces the model in tests.
 */
function kop_nf_read_article(PDO $pdo, array $news, $write, $ai = null) {
    $text = function_exists('kop_closure_article_text') ? kop_closure_article_text($news['article_url'] ?? '', 14000) : '';
    if ($text === '' && !empty($news['archive_url'])) {
        $text = kop_closure_article_text($news['archive_url'], 14000);
    }
    $linked = kop_nf_linked($pdo, $news['id']);
    try {
        $raw = $ai ? $ai(kop_nf_build_prompt($news, $linked, $text)) : kop_nf_ai(kop_nf_build_prompt($news, $linked, $text));
    } catch (Throwable $e) {
        // A rate limit says nothing about the article: it is read again on a later run without spending a try.
        if ($write && stripos($e->getMessage(), 'rate limit') === false) {
            kop_nf_record_scan($pdo, $news['id'], $news['hash'], 'error', 0, $e->getMessage());
        }
        return array('error', array(), $e->getMessage());
    }
    $facts = kop_nf_parse_reply($raw, $linked);
    if ($facts === null) {
        if ($write) {
            kop_nf_record_scan($pdo, $news['id'], $news['hash'], 'error', 0, 'Unreadable reply: ' . mb_substr((string) $raw, 0, 300));
        }
        return array('error', array(), 'Unreadable reply');
    }
    $names = kop_nf_survivor_names($news['survivors_mentioned'] ?? '');
    $haystack = $text . ' ' . $news['article_title'] . ' ' . ($news['summary'] ?? '');
    $names_by_id = array();
    foreach ($linked as $f) {
        $names_by_id[(int) $f['id']] = $f['unique_name'];
    }
    foreach ($facts as &$f) {
        // The quote is checked before names come out of it: it must be the article's words.
        $f['quote_found'] = kop_nf_quote_found($f['quote'], $haystack);
        $f['names_removed'] = kop_nf_scrub_survivors($f, $names);
        $f['linked_name'] = $names_by_id[$f['facility_id']] ?? '';
        $f['wbf'] = kop_nf_wbf_row($f, $news, $f['quote_found']);
        $f['route'] = $f['wbf'] ? 'facility' : 'waiting';
    }
    unset($f);

    if ($write) {
        $ins = $pdo->prepare("INSERT IGNORE INTO news_facts
            (news_id, fkey, category, subject_kind, subject_name, facility_id, person, role, value, summary, standing, event_date,
             quote, quote_found, names_removed, route, wbf_pkey, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
        foreach ($facts as &$f) {
            $f['wbf_pkey'] = $f['wbf'] ? kop_nf_file_wbf($f['wbf']) : '';
            $ins->execute(array((int) $news['id'], $f['fkey'], $f['category'], $f['subject_kind'], $f['subject'],
                $f['facility_id'] ?: null, $f['person'], $f['role'], $f['value'], $f['summary'], $f['standing'], $f['date'],
                $f['quote'], $f['quote_found'] ? 1 : 0, $f['names_removed'] ? 1 : 0, $f['route'], $f['wbf_pkey'],
                $f['route'] === 'facility' ? 'filed' : 'pending'));
        }
        unset($f);
        kop_nf_record_scan($pdo, $news['id'], $news['hash'], $facts ? 'found' : 'none', count($facts), $text === '' ? 'no article text' : '');
    }
    return array($facts ? 'found' : 'none', $facts, $text === '' ? 'no article text' : '');
}

/** Read up to $limit articles; stops on a rate limit or when $seconds run out. */
function kop_nf_read_batch(PDO $pdo, $limit, $seconds, $write = true, array $only_ids = array(), $log = null) {
    if ($write) {
        kop_nf_ensure_tables($pdo);
    }
    $started = time();
    $counts = array('read' => 0, 'none' => 0, 'found' => 0, 'error' => 0, 'facts' => 0, 'to_records' => 0);
    foreach (kop_nf_articles_to_read($pdo, $limit, $only_ids) as $news) {
        list($outcome, $facts, $detail) = kop_nf_read_article($pdo, $news, $write);
        $counts['read']++;
        $counts[$outcome]++;
        $counts['facts'] += count($facts);
        foreach ($facts as $f) {
            $counts['to_records'] += $f['route'] === 'facility' ? 1 : 0;
        }
        if ($log) {
            $log($news, $outcome, $facts, $detail);
        }
        if ($outcome === 'error' && stripos($detail, 'rate limit') !== false) break;
        if (time() - $started > $seconds) break;
    }
    return $counts;
}

/**
 * Turning automatic approval on (Review inbox > Facts from news > tools)
 * marks the waiting staff, year, size, age, gender and type rows whose quote
 * was found; the Woodbury Facts hourly pass then adds them, and a record
 * that disagrees leaves the row waiting as a conflict.
 */
function kop_nf_set_auto($on) {
    global $wpdb;
    update_option('kop_news_facts_auto', $on ? 1 : 0, false);
    if (!function_exists('kop_wbf_table')) return 0;
    $table = kop_wbf_table();
    $cats = array_map('esc_sql', kop_nf_auto_kinds());
    $like = array();
    foreach ($cats as $c) {
        $like[] = "extra LIKE '%\"category\":\"{$c}\"%'";
    }
    return (int) $wpdb->query("UPDATE {$table} SET auto = " . ($on ? 1 : 0) . "
        WHERE status = 'pending' AND extra LIKE '%\"via\":\"news\"%' AND found = 1
          AND extra NOT LIKE '%\"names_removed\":true%' AND (" . implode(' OR ', $like) . ')'
        . " AND (reviewed_by IS NULL OR reviewed_by = '') AND INSTR(COALESCE(extra, ''), '\"edited_by\"') = 0");
}

/* ---- Hourly run ------------------------------------------------------ */

add_action('init', function () {
    if (!wp_next_scheduled('kop_news_facts_hourly')) {
        wp_schedule_event(time() + 900, 'hourly', 'kop_news_facts_hourly');
    }
});

add_action('kop_news_facts_hourly', 'kop_nf_cron');

function kop_nf_cron() {
    if (get_option('kop_news_facts_paused') || get_transient('kop_news_facts_lock')) {
        return;
    }
    set_transient('kop_news_facts_lock', 1, 10 * MINUTE_IN_SECONDS);
    try {
        $pdo = kop_nf_pdo();
        if ($pdo) {
            // Six articles an hour (~140 a day) keeps within the background share of the daily AI budget.
            $c = kop_nf_read_batch($pdo, 6, 150);
            if ($c['read']) error_log('kop news facts: ' . wp_json_encode($c));
        }
    } catch (Throwable $e) {
        error_log('kop news facts: ' . $e->getMessage());
    } finally {
        delete_transient('kop_news_facts_lock');
    }
}

/* ---- The waiting facts (Review inbox > Facts from news) --------------- */

function kop_nf_statuses() {
    return array('pending' => 'Waiting', 'kept' => 'Kept for the record', 'dropped' => 'Dropped');
}

function kop_nf_counts(PDO $pdo) {
    $out = array_fill_keys(array_keys(kop_nf_statuses()), 0);
    foreach ($pdo->query("SELECT status, COUNT(*) FROM news_facts WHERE route = 'waiting' GROUP BY status")->fetchAll(PDO::FETCH_NUM) as $r) {
        if (isset($out[$r[0]])) $out[$r[0]] = (int) $r[1];
    }
    return $out;
}

function kop_nf_rows(PDO $pdo, $status, $search, $offset, $limit, $category = '') {
    $where = "f.route = 'waiting' AND f.status = ?";
    $params = array($status);
    if ($category !== '') {
        $where .= ' AND f.category = ?';
        $params[] = $category;
    }
    if ($search !== '') {
        $where .= ' AND (f.subject_name LIKE ? OR f.person LIKE ? OR f.summary LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }
    $total = $pdo->prepare("SELECT COUNT(*) FROM news_facts f WHERE {$where}");
    $total->execute($params);
    $stmt = $pdo->prepare("SELECT f.*, n.article_title, n.publication_name, n.publication_date, n.article_url
                             FROM news_facts f JOIN news_submissions n ON n.id = f.news_id
                            WHERE {$where}
                         ORDER BY n.publication_date DESC, f.news_id DESC, f.id
                            LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset);
    $stmt->execute($params);
    return array('rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int) $total->fetchColumn());
}

function kop_nf_row(PDO $pdo, $id) {
    $stmt = $pdo->prepare("SELECT f.*, n.article_title, n.publication_name, n.publication_date, n.article_url
                             FROM news_facts f JOIN news_submissions n ON n.id = f.news_id WHERE f.id = ?");
    $stmt->execute(array((int) $id));
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kop_nf_decide(PDO $pdo, $id, $status, $who) {
    $row = kop_nf_row($pdo, $id);
    if (!$row || $row['route'] !== 'waiting') throw new RuntimeException('No such fact.');
    if ($status !== 'pending' && $row['status'] !== 'pending') throw new RuntimeException('Already ' . $row['status'] . '.');
    $pdo->prepare('UPDATE news_facts SET status = ?, reviewed_by = ?, reviewed_at = ' . ($status === 'pending' ? 'NULL' : 'UTC_TIMESTAMP()') . ' WHERE id = ?')
        ->execute(array($status, $status === 'pending' ? null : $who, (int) $id));
    return $status === 'kept' ? 'Kept: it goes onto the record when that kind of record gets its Add.'
        : ($status === 'dropped' ? 'Dropped.' : 'Back with the waiting facts.');
}
