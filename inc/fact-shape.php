<?php
/**
 * One shape for every fact (docs/claude/fact-shape.md, spec 2026-11-02).
 *
 * Every row from every queue (Woodbury Facts, Fornits, news facts) and every field and line of a record becomes one
 * array, so grouping, "on hand" and the AI step compare like with like. Nothing here writes: it is the shape, the
 * block keys, the "never the same" check and the rule that says two facts are one. scripts/fact-dedupe.php measures
 * with it; the live folding passes (plan item 20, steps b to e) will use it as their one input.
 *
 * Plain PHP, loaded by functions.php after inc/woodbury-facts.php and by scripts/fact-dedupe.php. It reuses the
 * kop_wbf_* and kop_dd_* helpers, kop_autoconn_key() and kop_normalize_urls(); it holds no copy of them.
 */

if (!defined('ABSPATH')) {
    exit;
}

foreach (array('kop_wbf_words', 'kop_wbf_text_core', 'kop_wbf_org_key', 'kop_wbf_person_key', 'kop_wbf_street_key', 'kop_wbf_evidence') as $kop_fact_fn) {
    if (!function_exists($kop_fact_fn)) {
        exit("inc/fact-shape.php needs inc/woodbury-facts.php loaded first ($kop_fact_fn is missing).\n");
    }
}
unset($kop_fact_fn);
require_once dirname(__DIR__) . '/api/lib-record-dedupe.php';

/** Loads the file that holds a function when it is not loaded yet (these are loaded after this file in WordPress). */
function kop_fact_need($fn, $file) {
    if (!function_exists($fn) && is_file(dirname(__DIR__) . '/' . $file)) {
        require_once dirname(__DIR__) . '/' . $file;
    }
}

/** kind => [label, single_valued, groupable]. */
function kop_fact_kinds(): array {
    return array(
        'staff' => array('Staff', false, true), 'founder' => array('Founder', false, true), 'incident' => array('Incident', false, true),
        'closure' => array('Closure', true, true), 'opening' => array('Opening', true, true), 'name' => array('Name', false, true),
        'operator' => array('Operator', false, true), 'owner' => array('Owner', false, true), 'sale' => array('Sale', false, true),
        'investor' => array('Investor', false, true), 'size' => array('Size', true, true), 'ages' => array('Ages', true, true),
        'gender' => array('Gender', true, true), 'type' => array('Type', false, true), 'cost' => array('Cost', false, true),
        'detail' => array('Detail', false, true), 'license' => array('License', false, true), 'membership' => array('Membership', false, true),
        'place' => array('Place', false, true), 'money' => array('Money', false, true), 'practice' => array('Practice', false, true),
        'connection' => array('Connection', false, true), 'statement' => array('Statement', false, true), 'link' => array('Link', false, true),
        'testimony' => array('Testimony', false, false), 'note' => array('Note', false, true),
    );
}

/** Incident categories (spec 4) and the label a line gives them. */
function kop_fact_incident_types() {
    return array('death', 'injury', 'abuse', 'sexual_abuse', 'restraint', 'seclusion', 'neglect', 'runaway', 'arrest', 'criminal_charge', 'conviction',
        'license_action', 'citation', 'fine', 'placement_ban', 'investigation', 'lawsuit', 'settlement', 'verdict', 'closure_order', 'legislation');
}

/** A news category (kop_nf_categories()) as [kind, type]. */
function kop_fact_category_kind($cat) {
    $cat = strtolower(trim((string) $cat));
    if (in_array($cat, kop_fact_incident_types(), true)) return array('incident', $cat);
    $map = array('staff' => array('staff', ''), 'owner' => array('owner', 'owner'), 'operator' => array('operator', 'current'),
        'acquired' => array('sale', 'acquired'), 'investor' => array('investor', 'investor'), 'opened' => array('opening', 'opened'),
        'renamed' => array('name', 'renamed'), 'moved' => array('place', 'move'), 'capacity' => array('size', 'capacity'), 'ages' => array('ages', 'ages'),
        'gender' => array('gender', 'gender'), 'program_type' => array('type', 'type'), 'funding' => array('money', 'public_money'),
        'revenue' => array('money', 'revenue'), 'placement' => array('money', 'placements'), 'rate' => array('cost', 'cost'),
        'practice' => array('practice', 'practice'), 'connection' => array('connection', 'connection'), 'statement' => array('statement', 'statement'));
    return $map[$cat] ?? array('note', 'note');
}

/** "Label: body" -> [kind, type, body]; the one label map (5.2). A news category, when given, decides instead of the label. */
function kop_fact_note_kind($line, $news_category = '') {
    $line = trim((string) $line);
    $label = '';
    $body = $line;
    if (preg_match('/^([A-Z][\w \/\'-]{0,30}):\s*(.*)$/s', $line, $m)) {
        $label = strtolower(trim($m[1]));
        $body = $m[2];
    }
    if ($news_category !== '') {
        list($k, $t) = kop_fact_category_kind($news_category);
        if ($k !== 'note' || $label === '') return array($k, $t, $body);
    }
    if ($label === '') return array('note', 'note', $line);
    $aka = '/\b(?:also known as|formerly|renamed|rebranded|now called|a\.?k\.?a\.?)\b/i';
    $map = array('name change' => array('name', 'renamed'), 'described as' => array('type', 'type'), 'type' => array('type', 'type'),
        'ages' => array('ages', 'ages'), 'gender' => array('gender', 'gender'), 'capacity' => array('size', 'capacity'),
        'operator' => array('operator', 'current'), 'owner' => array('owner', 'owner'), 'acquired' => array('sale', 'acquired'),
        'sale' => array('sale', 'sold'), 'merger' => array('sale', 'merger'), 'investor' => array('investor', 'investor'),
        'license' => array('license', 'license'), 'accreditation' => array('membership', 'accreditation'), 'membership' => array('membership', 'membership'),
        'campus' => array('place', 'campus'), 'address' => array('place', 'address'), 'move' => array('place', 'move'),
        'opened' => array('opening', 'opened'), 'start year' => array('opening', 'opened'), 'founded by' => array('founder', 'person'),
        'length of stay' => array('detail', 'length_of_stay'), 'cost' => array('cost', 'cost'), 'public money' => array('money', 'public_money'),
        'revenue' => array('money', 'revenue'), 'placements' => array('money', 'placements'), 'statement' => array('statement', 'statement'),
        'practice' => array('practice', 'practice'), 'connection' => array('connection', 'connection'), 'serves' => array('gender', 'gender'));
    if ($label === 'note') {
        return preg_match($aka, $body) ? array('name', 'other', $body) : array('note', 'note', $body);
    }
    if (isset($map[$label])) return array($map[$label][0], $map[$label][1], $body);
    return array('note', 'note', $line);
}

/* ---- Dates ------------------------------------------------------------------------------------------ */

function kop_fact_month_number($name) {
    static $m = array('jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12);
    return $m[strtolower(substr((string) $name, 0, 3))] ?? 0;
}

/** A date at the start of $s: array(normalized date, length used), or null. $needs_colon: the date must be followed by ":". */
function kop_fact_lead_date($s, $needs_colon) {
    $end = $needs_colon ? '\s*:\s*' : '(?![\ds])';
    $months = '(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sept?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
    if (preg_match('/^((?:19|20)\d\d)(?:-(\d\d))?(?:-(\d\d))?(?:\s*-\s*(?:19|20)\d\d)?' . $end . '/', $s, $m)) {
        $d = $m[1] . (($m[2] ?? '') !== '' ? '-' . $m[2] : '') . (($m[2] ?? '') !== '' && ($m[3] ?? '') !== '' ? '-' . $m[3] : '');
        return array($d, strlen($m[0]));
    }
    if (preg_match('/^' . $months . '\.?\s+(?:(\d{1,2})(?:st|nd|rd|th)?,?\s+)?((?:19|20)\d\d)' . $end . '/i', $s, $m)) {
        $mo = kop_fact_month_number($m[1]);
        $d = $m[3] . '-' . sprintf('%02d', $mo) . ($m[2] !== '' ? '-' . sprintf('%02d', (int) $m[2]) : '');
        return array($d, strlen($m[0]));
    }
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/((?:1[89]|20)\d\d)' . $end . '/', $s, $m)) {
        return array($m[3] . '-' . sprintf('%02d', (int) $m[1]) . '-' . sprintf('%02d', (int) $m[2]), strlen($m[0]));
    }
    return null;
}

/**
 * The lead of a list line: array(date, told, label, body). "2026-05-14: Injury: ..." -> date 2026-05-14, label "injury";
 * "Reported 2007: ..." -> date '' and told 2007; "June 2026:", "July 17, 2026:" and "12/6/1869:" are read too.
 */
function kop_fact_lead($line) {
    $s = ltrim((string) $line);
    $date = '';
    $told = '';
    if (preg_match('/^Reported\s+((?:19|20)\d\d(?:-\d\d){0,2})\s*:\s*/', $s, $m)) {
        $told = $m[1];
        $s = substr($s, strlen($m[0]));
    } elseif (($d = kop_fact_lead_date($s, true))) {
        $date = $d[0];
        $s = substr($s, $d[1]);
    }
    $label = '';
    if (preg_match('/^([A-Z][\w \/\'-]{0,30}):\s*/', $s, $m) && ($date !== '' || $told !== '' || kop_fact_incident_type($m[1]) !== 'incident')) {
        $label = strtolower(trim($m[1]));
        $s = substr($s, strlen($m[0]));
    }
    return array($date, $told, $label, $s);
}

/** Normalized date ('YYYY' | 'YYYY-MM' | 'YYYY-MM-DD' | '') of a line's lead or of a value; reads ISO, "June 2026", "July 17, 2026", "12/6/1869". */
function kop_fact_date($s) {
    $s = trim((string) $s);
    if (preg_match('/^Reported\s/', $s)) return '';
    $d = kop_fact_lead_date($s, true);
    if ($d) return $d[0];
    // "Opened: 1976 per HEAL", "Start year: 1998": the date is the body's lead; "1970s" and "since 2006" give none.
    $s = preg_replace('/^(?:Opened|Start year|Founded|Closed|Closed in):?\s*/i', '', $s);
    $d = kop_fact_lead_date($s, false);
    return $d ? $d[0] : '';
}

/** The category an incident label names ("License action" -> license_action), 'incident' for none. */
function kop_fact_incident_type($label) {
    $t = str_replace(' ', '_', strtolower(trim((string) $label)));
    return in_array($t, kop_fact_incident_types(), true) ? $t : 'incident';
}

function kop_fact_pad_date($d) {
    $d = (string) $d;
    return $d === '' ? '' : substr($d . '-01-01', 0, 10);
}

/** Whether two shapes' dates allow one event (6.3), told dates included. */
function kop_fact_dates_fit(array $a, array $b) {
    $x = (string) $a['date'];
    $y = (string) $b['date'];
    if ($x !== '' && $y !== '') {
        if (substr($x, 0, 4) !== substr($y, 0, 4)) return false;
        if (strlen($x) >= 7 && strlen($y) >= 7 && substr($x, 0, 7) !== substr($y, 0, 7)) return false;
        return !(strlen($x) === 10 && strlen($y) === 10 && $x !== $y);
    }
    if ($x === '' && $y === '') return true;
    // One is undated: the dated event is no later than the day the undated one was told.
    $dated = $x !== '' ? $x : $y;
    $told = (string) ($x !== '' ? $b['told'] : $a['told']);
    if ($told === '') return true;
    return kop_fact_pad_date($dated) <= kop_fact_pad_date($told);
}

/* ---- Names ------------------------------------------------------------------------------------------ */

/** The suffix of a name ("jr", "sr", "ii", "iii", "iv") before the person key drops it. */
function kop_fact_suffix($name) {
    return preg_match('/\b(jr|sr|ii|iii|iv)\b\.?/i', (string) $name, $m) ? strtolower($m[1]) : '';
}

/** The first three letters of the first name, nickname folded: the block a person's facts share ("Jim"/"James", "Art"/"Arthur" meet). */
function kop_fact_person_block($name) {
    $p = kop_dd_person_parts($name);
    return $p ? substr($p['first'], 0, 3) : '';
}

/** Persons and companies a body names (section 3): ['persons' => [[name, key, suffix]], 'orgs' => [key]]. */
function kop_fact_names($body) {
    kop_fact_need('kop_staff_name_not_person', 'inc/staff-former.php');
    $s = preg_replace('/\s*\([^()]*\)/', ' ', (string) $body);
    $s = preg_replace('/\s+per\s+.*$/i', '', $s);
    $s = preg_replace('/\b(?:is|are|was|were)\s+(?:the\s+)?(?:its\s+)?(?:parent|umbrella|owner|operator|current|former)\b.*$/i', '', $s);
    $s = preg_replace('/\b(?:umbrella corporation(?: is)?|operated by|owned by|run by|parent company:?|parent:|operator:|owner:|acquired by|sold to|purchased by|bought by|founded by|co-?founded by)\b:?/i', ',', $s);
    $persons = array();
    $orgs = array();
    foreach (preg_split('/\s*(?:,|;|&|\band\b)\s*/i', $s) as $part) {
        $part = trim($part, " .\t\"'");
        if ($part === '' || preg_match('/^(?:in business|since|about|around|circa|approximately|unknown|n\/?a|none)\b/i', $part) || !preg_match('/[A-Za-z]{2}/', $part)) continue;
        $company = (bool) preg_match('/\b(?:Inc|LLC|Schools?|Services|Office|Center|Centers|Academy|Group|Health|Ministries|Family|Corp|Corporation|Company|Foundation|Association|Church|Healthcare|Treatment)\b/', $part);
        $key = kop_wbf_person_key($part);
        if (!$company && $key !== '' && !kop_staff_name_not_person($part)) {
            $persons[] = array($part, $key, kop_fact_suffix($part));
        } else {
            $k = kop_wbf_org_key($part);
            if ($k !== '') $orgs[$k] = true;
        }
    }
    return array('persons' => $persons, 'orgs' => array_keys($orgs));
}

/** Record key: (string) $fid, or 'p:' . kop_autoconn_key(name without its trailing "(...)"). '' when there is neither. */
function kop_fact_record_key($fid, $program) {
    if ((int) $fid > 0) return (string) (int) $fid;
    kop_fact_need('kop_autoconn_key', 'inc/auto-connect.php');
    $k = kop_autoconn_key(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $program));
    return $k === '' ? '' : 'p:' . $k;
}

/* ---- Building a shape ------------------------------------------------------------------------------- */

function kop_fact_empty_keys() {
    return array('person' => '', 'pname' => '', 'suffix' => '', 'persons' => array(), 'org' => array(), 'name' => array(), 'url' => '',
        'nums' => array(), 'ages' => array(), 'people' => array(), 'acr' => array());
}

/** Numbers of a core: dates, "x-year-old" ages and "as of YYYY" years taken out. */
function kop_fact_numbers($core) {
    $s = (string) $core;
    $months = '(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|June?|July?|Aug(?:ust)?|Sept?(?:ember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)';
    $s = preg_replace('/\b\d{1,2}[- ]year[- ]olds?\b/i', ' ', $s);
    $s = preg_replace('/\b' . $months . '\.?\s+(?:\d{1,2}(?:st|nd|rd|th)?,?\s+)?(?:19|20)\d\d\b/i', ' ', $s);
    $s = preg_replace('/\b(?:19|20)\d\d-\d\d(?:-\d\d)?\b/', ' ', $s);
    $s = preg_replace('/\(?as of (?:19|20)\d\d\)?/i', ' ', $s);
    preg_match_all('/\d+(?:\.\d+)?/', str_replace(',', '', $s), $m);
    $n = array_values(array_unique($m[0]));
    sort($n);
    return $n;
}

/** Head counts in a core ("17 former students", "nine plaintiffs"), as kop_dd_same_event() reads them. */
function kop_fact_head_counts($s) {
    static $words = array('one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9,
        'ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17,
        'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20);
    preg_match_all('/\b(\d+|' . implode('|', array_keys($words)) . ')\s+(?:former\s+|other\s+)?(?:students|plaintiffs|residents|girls|boys|youths|teens|children|kids|staff|employees|people|women|men)\b/i', (string) $s, $m);
    return array_map(function ($n) use ($words) { return is_numeric($n) ? (int) $n : $words[strtolower($n)]; }, $m[1]);
}

/**
 * Fills a shape from what a builder knows: needs kind, type, record, core (the line without its date, label and
 * citation), text, src, queue, id, state; the rest has defaults. Words, distinctive words and capitalized names come
 * from the core.
 */
function kop_fact_make(array $f) {
    static $extra_stop = array('school', 'facility', 'program', 'students', 'student', 'staff', 'resident', 'residents', 'campus', 'boys', 'girls',
        'alleged', 'found', 'said', 'according', 'reported', 'former', 'children', 'child', 'teen', 'teens', 'youth', 'while', 'which', 'were', 'than',
        'more', 'other', 'when', 'them', 'they', 'would', 'could', 'about', 'there', 'being', 'during', 'over', 'such', 'also', 'state', 'center');
    $f += array('kind' => 'note', 'type' => 'note', 'record' => '', 'subject' => '', 'keys' => array(), 'date' => '', 'told' => '', 'place' => array(),
        'standing' => '', 'src' => 'woodbury', 'srcs' => array(), 'told_by' => array(), 'queue' => 'wbf', 'id' => '', 'state' => 'waiting', 'text' => '',
        'evidence' => array(), 'op' => '', 'path' => '', 'core' => '');
    $f['keys'] = $f['keys'] + kop_fact_empty_keys();
    $core = (string) $f['core'];
    if ($f['subject'] === '') $f['subject'] = $core;
    $f['words'] = kop_wbf_words($core);
    $d = array();
    foreach ($f['words'] as $w) {
        if ((strlen($w) >= 4 || preg_match('/\d/', $w)) && !in_array($w, $extra_stop, true)) $d[$w] = true;
    }
    $f['dwords'] = $d;
    $caps = array();
    if (preg_match_all('/(?<=[a-z,;] )[A-Z][a-z]{2,}/', ' ' . $core, $cm)) {
        foreach ($cm[0] as $w) $caps[strtolower($w)] = true;
    }
    $f['caps'] = $caps;
    if (!$f['keys']['nums'] && !in_array($f['kind'], array('staff', 'name', 'link', 'place', 'testimony'), true)) $f['keys']['nums'] = kop_fact_numbers($core);
    if (preg_match_all('/\b(\d{1,2})-year-old/', $core, $am)) $f['keys']['ages'] = array_values(array_unique($am[1]));
    $f['keys']['people'] = kop_fact_head_counts($core);
    if (!$f['place'] && $f['kind'] !== 'place') $f['place'] = kop_dd_campuses($core);
    if (!$f['srcs']) $f['srcs'] = array($f['src']);
    return $f;
}

/** Sets the persons and companies of a body on a shape's keys. */
function kop_fact_set_names(array &$keys, $body) {
    $n = kop_fact_names($body);
    $keys['persons'] = $n['persons'];
    $keys['org'] = $n['orgs'];
    if ($n['persons']) {
        $keys['person'] = $n['persons'][0][1];
        $keys['pname'] = $n['persons'][0][0];
        $keys['suffix'] = $n['persons'][0][2];
    }
}

/**
 * The shape of one fact from its kind: the shared part of every source. $base carries src, queue, id, record, state,
 * told, told_by, evidence, op, path, text; $body is the line after its label, citation included.
 */
function kop_fact_build(array $base, $kind, $type, $body, $date = '') {
    $body = trim((string) $body);
    $core = kop_wbf_text_core($body, true)[0];
    $keys = kop_fact_empty_keys();
    $f = $base + array('text' => $body);
    $f['kind'] = $kind;
    $f['type'] = $type;
    $f['core'] = $core;
    $f['date'] = $date;
    switch ($kind) {
        case 'founder':
        case 'owner':
        case 'operator':
        case 'sale':
        case 'investor':
        case 'connection':
            kop_fact_set_names($keys, $core);
            if ($kind === 'founder') $f['type'] = $keys['persons'] ? 'person' : 'company';
            if ($kind === 'owner' && $keys['persons'] && !$keys['org']) $f['type'] = 'owner';
            if ($f['date'] === '' && in_array($kind, array('sale', 'founder'), true) && preg_match('/\b((?:19|20)\d\d)\b/', $core, $m)) $f['date'] = $kind === 'sale' ? $m[1] : '';
            break;
        case 'statement':
            kop_fact_set_names($keys, '');
            if (preg_match('/\b([A-Z][a-z]+(?:\s+[A-Z][a-z.]+){1,2})\b/', $core, $m) && kop_wbf_person_key($m[1]) !== '') {
                $keys['persons'] = array(array($m[1], kop_wbf_person_key($m[1]), kop_fact_suffix($m[1])));
                $keys['person'] = $keys['persons'][0][1];
                $keys['pname'] = $m[1];
            }
            break;
        case 'ages':
            $keys['nums'] = array_slice(kop_fact_numbers($core), 0, 2);
            sort($keys['nums'], SORT_NUMERIC);
            break;
        case 'size':
            $n = kop_fact_numbers($core);
            $keys['nums'] = array_slice($n, 0, 1);
            if ($f['date'] === '' && preg_match('/as of ((?:19|20)\d\d)/i', $body, $m)) $f['date'] = $m[1];
            break;
        case 'gender':
            $f['type'] = kop_wbf_gender_of($core) ?: 'gender';
            break;
        case 'opening':
            if ($f['date'] === '') $f['date'] = kop_fact_date($core);
            break;
        case 'name':
            $names = array();
            if ($type === 'renamed' && preg_match('/^(.+?)\s*(?:->|=>|\x{2192}|\bto\b)\s*(.+)$/u', $core, $m)) {
                $names = array(trim($m[1]), trim($m[2]));
                $f['subject'] = trim($m[2]);
            } elseif (preg_match('/\b(?:also known as|formerly|renamed|rebranded|now called|a\.?k\.?a\.?)\s+(.+)$/i', $core, $m)) {
                $names = preg_split('/\s*(?:;|,|\bor\b)\s*/i', $m[1]);
            } else {
                $names = array($core);
            }
            foreach ($names as $n) {
                $k = kop_dd_name_key(preg_replace('/\s*\([^)]*\)/', '', (string) $n));
                if ($k !== '') $keys['name'][$k] = $k;
            }
            $keys['name'] = array_values($keys['name']);
            break;
        case 'license':
        case 'membership':
            preg_match_all('/\b[A-Z]{3,}\b/', $body, $ac);
            $keys['acr'] = array_values(array_unique($ac[0]));
            break;
        case 'link':
            $u = kop_normalize_urls($core !== '' ? $core : $body);
            $keys['url'] = $u ? $u[0] : '';
            break;
        case 'place':
            $keys += array();
            $pl = array();
            $sk = kop_wbf_street_key($core);
            if ($sk !== '') $pl[] = 'st:' . $sk;
            if (preg_match('/^(?:[^,]+,\s*)?([^,]+),\s*([A-Za-z]{2,})\b/', $core, $m) && $sk !== '') {
                $pl[] = 'cs:' . strtolower(trim(preg_replace('/[^a-z ]+/i', ' ', $m[1]))) . '|' . strtolower($m[2]);
            } elseif ($sk === '' && preg_match('/^([^,]+),\s*([A-Za-z]{2,})\b/', $core, $m)) {
                $pl[] = 'cs:' . strtolower(trim(preg_replace('/[^a-z ]+/i', ' ', $m[1]))) . '|' . strtolower($m[2]);
            }
            foreach (kop_dd_campuses($core) as $c) $pl[] = 'campus:' . $c;
            $f['place'] = $pl;
            break;
        case 'incident':
            break;
    }
    $f['keys'] = $keys;
    return kop_fact_make($f);
}

/** The origin family of one piece of evidence: heal, wiki, news or woodbury. */
function kop_fact_ev_family(array $e) {
    $issue = (string) ($e['issue'] ?? '');
    if (strpos($issue, 'heal-') === 0) return 'heal';
    if (strpos($issue, 'wiki-') === 0) return 'wiki';
    if (!empty($e['news_id'])) return 'news';
    return 'woodbury';
}

/** One id per telling of an evidence entry: woodbury:<issue>:<page>, heal:<issue>, wiki:<issue>, news:<news_id>. */
function kop_fact_ev_id(array $e) {
    $fam = kop_fact_ev_family($e);
    if ($fam === 'news') return 'news:' . (int) $e['news_id'];
    if ($fam === 'woodbury') return 'woodbury:' . ($e['issue'] ?? '') . ':' . (int) ($e['page'] ?? 0);
    return $fam . ':' . ($e['issue'] ?? '');
}

/** A role's start year: "Clinical Director (1999-2012, Woodbury Reports)" -> "1999". */
function kop_fact_role_year($role) {
    return preg_match('/\b((?:19|20)\d\d)\b/', (string) $role, $m) ? $m[1] : '';
}

function kop_fact_staff(array $base, $name, $role) {
    $name = trim((string) $name);
    $keys = kop_fact_empty_keys();
    $key = kop_wbf_person_key($name);
    if ($key !== '') {
        $keys['persons'] = array(array($name, $key, kop_fact_suffix($name)));
        $keys['person'] = $key;
        $keys['pname'] = $name;
        $keys['suffix'] = kop_fact_suffix($name);
    }
    return kop_fact_make($base + array('text' => $name . ($role !== '' ? ', ' . $role : '')) + array('kind' => 'staff', 'type' => trim((string) $role),
        'subject' => $name, 'core' => $name . ' - ' . $role, 'date' => kop_fact_role_year($role), 'keys' => $keys));
}

/** An incident line (or a news fact's) as a shape: lead, label and citation read. $type_hint: a category that beats the label. */
function kop_fact_incident(array $base, $line, $type_hint = '') {
    list($date, $told, $label, $body) = kop_fact_lead($line);
    $core = kop_wbf_text_core($body, true)[0];
    $type = $type_hint !== '' ? $type_hint : kop_fact_incident_type($label);
    $base['told'] = ($base['told'] ?? '') !== '' ? $base['told'] : $told;
    return kop_fact_make($base + array('text' => (string) $line) + array('kind' => 'incident', 'type' => $type, 'core' => $core, 'date' => $date));
}

/** A note (or operatingPeriod note) line as a shape, by its label; the three forms only operatingPeriod.notes holds first. */
function kop_fact_note_line(array $base, $line, $news_category = '', $in_period = false) {
    $line = trim((string) $line);
    if ($in_period) {
        // "Closed (2025-02-14) per news report: ..." (kop_closure_source_note()), "Closed in 2008 (sets status Closed, ...)", "Reported closed on Fornits: ..."
        if (preg_match('/^(Closed|Suspended|Reopened|Opened)\s*(?:\(((?:19|20)\d\d(?:-\d\d){0,2})\))?\s*per news report:?\s*(.*)$/i', $line, $m)) {
            $what = strtolower($m[1]);
            return kop_fact_build($base + array('text' => $line), $what === 'opened' ? 'opening' : 'closure', $what === 'opened' ? 'opened' : $what, $m[3], $m[2] ?? '');
        }
        if (preg_match('/^Closed in ((?:19|20)\d\d)\b/i', $line, $m)) {
            return kop_fact_build($base + array('text' => $line), 'closure', 'closed', $line, $m[1]);
        }
        if (preg_match('/^Reported closed on Fornits:?\s*(.*)$/i', $line, $m)) {
            return kop_fact_build($base + array('text' => $line), 'closure', 'closed', $m[1], '');
        }
        if (preg_match('/^Start year:\s*((?:19|20)\d\d)\b/i', $line, $m)) {
            return kop_fact_build($base + array('text' => $line), 'opening', 'opened', $line, $m[1]);
        }
        if (preg_match('/^Ages\s+(\d.*)$/i', $line)) {
            return kop_fact_build($base + array('text' => $line), 'ages', 'ages', preg_replace('/^Ages\s+/i', '', $line));
        }
    }
    list($kind, $type, $body) = kop_fact_note_kind($line, $news_category);
    $date = '';
    if ($kind === 'opening') $date = kop_fact_date($body);
    return kop_fact_build($base + array('text' => $line), $kind, $type, $body, $date);
}

/* ---- Shapes of rows --------------------------------------------------------------------------------- */

/**
 * One fact in the common shape, or null when the row is not a record fact.
 * $src: 'wbf' | 'fornits' | 'news' (a news_facts row; add publication_date for told) | 'doc_line' ($row = ['path','line','i']).
 * $ctx: ['fid' => int, 'record_name' => string, 'state' => 'waiting'|'held'|'on_record'|'kept'].
 */
function kop_fact_shape($src, array $row, array $ctx = array()) {
    switch ($src) {
        case 'wbf':
            return kop_fact_from_wbf($row, $ctx);
        case 'fornits':
            return kop_fact_from_fornits($row, $ctx);
        case 'news':
            return kop_fact_from_news($row, $ctx);
        case 'doc_line':
            return kop_fact_from_doc_line($row, $ctx);
    }
    return null;
}

function kop_fact_from_wbf(array $r, array $ctx) {
    $op = (string) $r['op'];
    $path = (string) $r['path'];
    if (!in_array($op, array('add_staff', 'add_list', 'set_if_empty', 'set_closed'), true)) return null;
    $extra = is_array($r['extra'] ?? null) ? $r['extra'] : (json_decode((string) ($r['extra'] ?? ''), true) ?: array());
    $ev = kop_wbf_evidence($r);
    $held = ($r['status'] ?? '') === 'held';
    $fid = (int) ($ctx['fid'] ?? 0) ?: ($held ? (int) ($r['applied_fid'] ?? 0) : 0) ?: (int) ($r['facility_id'] ?? 0);
    $via = (string) ($extra['via'] ?? '');
    $srcs = array();
    $told_by = array();
    foreach ($ev as $e) {
        if (!is_array($e)) continue;
        $srcs[kop_fact_ev_family($e)] = true;
        $told_by[kop_fact_ev_id($e)] = true;
    }
    $src = $via === 'news' ? 'news' : ($via === 'questions' ? 'questions' : ($srcs ? array_key_first($srcs) : 'woodbury'));
    if ($via === 'questions') $told_by['questions:' . (string) ($r['pkey'] ?? '')] = true;
    if ($via === 'news') $srcs = array('news' => true) + $srcs;
    if (!$srcs) $srcs = array($src => true);
    $told = '';
    foreach ($ev as $e) if (is_array($e) && !empty($e['date'])) { $told = (string) $e['date']; break; }
    if ($told === '') $told = (string) ($r['issue_date'] ?? '');
    $base = array('src' => $src, 'srcs' => array_keys($srcs), 'told_by' => array_keys($told_by), 'queue' => 'wbf', 'id' => (string) ($r['pkey'] ?? ''),
        'record' => kop_fact_record_key($fid, (string) ($r['program'] ?? '')), 'state' => (string) ($ctx['state'] ?? ($held ? 'held' : 'waiting')),
        'told' => $told, 'standing' => (string) ($extra['standing'] ?? ''), 'evidence' => $ev, 'op' => $op, 'path' => $path);
    $v = kop_wbf_row_value($r);
    $label = (string) ($r['label'] ?? '');
    if ($op === 'add_staff') {
        $v = is_array($v) ? $v : array();
        return kop_fact_staff($base, $v['name'] ?? '', (string) ($v['role'] ?? ''));
    }
    if ($op === 'set_closed') {
        $y = is_array($v) ? (string) ($v['endYear'] ?? '') : '';
        return kop_fact_build($base + array('text' => $label), 'closure', 'closed', $label, preg_match('/^(?:19|20)\d\d$/', $y) ? $y : '');
    }
    if ($op === 'set_if_empty') {
        $text = $label !== '' ? $label : (is_array($v) ? wp_json_encode($v) : (string) $v);
        if ($path === 'operatingPeriod.startYear') {
            $y = is_array($v) ? '' : (string) $v;
            return kop_fact_build($base + array('text' => $text), 'opening', 'opened', $text, preg_match('/^(?:19|20)\d\d$/', $y) ? $y : '');
        }
        $plain = is_array($v) ? (isset($v['min']) || isset($v['max']) ? ((string) ($v['min'] ?? '') . '-' . (string) ($v['max'] ?? '')) : wp_json_encode($v)) : (string) $v;
        if ($path === 'facilityDetails.ageRange') return kop_fact_build($base + array('text' => $text), 'ages', 'ages', $plain);
        if ($path === 'facilityDetails.capacity') return kop_fact_build($base + array('text' => $text), 'size', 'capacity', $plain);
        if ($path === 'facilityDetails.type') return kop_fact_build($base + array('text' => $text), 'type', 'type', $plain);
        if ($path === 'facilityDetails.gender') return kop_fact_build($base + array('text' => $text), 'gender', 'gender', $plain);
        return kop_fact_build($base + array('text' => $text), 'note', 'note', $plain);
    }
    // add_list
    $text = is_array($v) ? (string) ($v['raw'] ?? ($v['name'] ?? wp_json_encode($v))) : (string) $v;
    if ($path === 'criticalIncidents.customIncidents') {
        return kop_fact_incident($base, $text, (string) ($extra['category'] ?? '') !== '' ? kop_fact_category_kind($extra['category'])[1] : '');
    }
    if ($path === 'identification.pastNames' || $path === 'identification.otherNames') {
        return kop_fact_build($base + array('text' => $text), 'name', $path === 'identification.pastNames' ? 'past' : 'other', $text);
    }
    if ($path === 'identification.pastOperators' || $path === 'identification.otherOperators') {
        return kop_fact_build($base + array('text' => $text), 'operator', 'past', $text);
    }
    if ($path === 'location.formerLocations') {
        $place = is_array($v) ? trim((string) ($v['raw'] ?? '') !== '' ? $v['raw'] : (($v['city'] ?? '') . ', ' . ($v['state'] ?? '')), ', ') : $text;
        return kop_fact_build($base + array('text' => $place), 'place', 'former_location', $place);
    }
    return kop_fact_note_line($base, $text, (string) ($extra['category'] ?? ''), $path === 'operatingPeriod.notes');
}

function kop_fact_from_fornits(array $r, array $ctx) {
    $kind = (string) $r['kind'];
    $v = json_decode((string) ($r['value'] ?? ''), true) ?: array();
    $fid = (int) ($ctx['fid'] ?? 0) ?: (int) ($r['facility_id'] ?? 0);
    $told_by = array('fornits:' . (int) $r['topic_id'] . '-' . (int) ($r['post_n'] ?? 0));
    $post = (string) ($r['post_date'] ?? '');
    $base = array('src' => 'fornits', 'srcs' => array('fornits'), 'told_by' => $told_by, 'queue' => 'fornits', 'id' => (string) ($r['pkey'] ?? ''),
        'record' => $fid > 0 ? (string) $fid : '', 'state' => (string) ($ctx['state'] ?? 'waiting'), 'told' => substr($post, 0, 10), 'op' => 'fornits_' . $kind,
        'path' => '');
    kop_fact_need('kop_fornits_incident_line', 'inc/fornits.php');
    switch ($kind) {
        case 'link':
            $url = (string) ($v['url'] ?? '');
            return kop_fact_build($base + array('text' => $url), 'link', 'forum', $url);
        case 'staff':
            $role = trim(($v['role'] ?? '') . (!empty($v['years']) ? ' (' . $v['years'] . ')' : ''));
            $f = kop_fact_staff($base, $v['person'] ?? '', $role);
            $f['date'] = kop_fact_role_year((string) ($v['years'] ?? ''));
            return $f;
        case 'incident':
            $cat = (string) ($v['category'] ?? 'other');
            $year = (string) ($v['year'] ?? '');
            $line = kop_fornits_incident_line($r);
            $f = kop_fact_incident($base, $line, $cat === 'other' ? 'incident' : kop_fact_incident_type($cat));
            $f['date'] = preg_match('/^(?:19|20)\d\d$/', $year) ? $year : '';
            return $f;
        case 'testimony':
            return kop_fact_make($base + array('text' => (string) ($v['summary'] ?? '')) + array('kind' => 'testimony', 'type' => 'survivor', 'core' => (string) ($v['summary'] ?? '')));
        case 'lead':
            $v += array('type' => '', 'year' => '', 'summary' => '', 'url' => '');
            $line = kop_fornits_lead_line($r);
            $year = preg_match('/^(?:19|20)\d\d$/', (string) $v['year']) ? (string) $v['year'] : '';
            if ($v['type'] === 'closure') {
                return kop_fact_make($base + array('text' => $line) + array('kind' => 'closure', 'type' => 'closed', 'core' => $v['summary'], 'date' => $year));
            }
            if (in_array($v['type'], array('investigation', 'lawsuit'), true)) {
                $f = kop_fact_make($base + array('text' => $line) + array('kind' => 'incident', 'type' => $v['type'], 'core' => $v['summary'], 'date' => $year));
                if ($v['url'] !== '') { $u = kop_normalize_urls($v['url']); $f['keys']['url'] = $u ? $u[0] : ''; }
                return $f;
            }
            if ($v['type'] === 'news' && $v['url'] !== '') return kop_fact_build($base + array('text' => $line), 'link', 'article', $v['url']);
            // As the line sits in the record's notes, so a held or filed copy of it reads the same.
            return kop_fact_make($base + array('text' => $line) + array('kind' => 'note', 'type' => 'note', 'core' => kop_wbf_text_core($line, true)[0]));
    }
    return null;
}

function kop_fact_from_news(array $r, array $ctx) {
    $sk = (string) ($r['subject_kind'] ?? 'facility');
    if (in_array($sk, array('person', 'agency'), true)) return null;
    $cat = (string) ($r['category'] ?? '');
    $fid = (int) ($ctx['fid'] ?? 0) ?: (int) ($r['facility_id'] ?? 0);
    list($kind, $type) = kop_fact_category_kind($cat);
    $date = (string) ($r['event_date'] ?? '');
    $date = preg_match('/^(?:19|20)\d\d(?:-\d\d){0,2}$/', $date) ? $date : '';
    $summary = trim((string) ($r['summary'] ?? ''));
    $base = array('src' => 'news', 'srcs' => array('news'), 'told_by' => array('news:' . (int) ($r['news_id'] ?? 0)), 'queue' => 'news', 'id' => 'n' . ($r['id'] ?? ''),
        'record' => $fid > 0 ? (string) $fid : '', 'state' => (string) ($ctx['state'] ?? 'waiting'), 'told' => substr((string) ($r['publication_date'] ?? ''), 0, 10),
        'standing' => (string) ($r['standing'] ?? ''), 'op' => 'news_fact', 'path' => '');
    if ($kind === 'incident') {
        $line = ($date !== '' ? $date . ': ' : '') . ucfirst(str_replace('_', ' ', $cat)) . ': ' . rtrim($summary, '.') . '.';
        $f = kop_fact_incident($base, $line, $type);
        if (!empty($r['person'])) {
            $f['keys']['pname'] = (string) $r['person'];
            $f['keys']['person'] = kop_wbf_person_key($r['person']);
        }
        return $f;
    }
    if ($kind === 'staff') {
        return kop_fact_staff($base, $r['person'] ?? '', (string) ($r['role'] ?? ''));
    }
    $value = trim((string) ($r['value'] ?? ''));
    $body = in_array($kind, array('practice', 'connection', 'statement', 'money'), true) || $value === '' ? $summary : $value;
    if ($kind === 'statement' && !empty($r['person'])) $body = $r['person'] . ': ' . $summary;
    $f = kop_fact_build($base + array('text' => $summary), $kind, $type, $body, $kind === 'opening' ? kop_fact_date($value) : '');
    if ($kind === 'statement' && !empty($r['person'])) {
        $f['keys']['persons'] = array(array((string) $r['person'], kop_wbf_person_key($r['person']), kop_fact_suffix($r['person'])));
        $f['keys']['person'] = kop_wbf_person_key($r['person']);
        $f['keys']['pname'] = (string) $r['person'];
    }
    return $f;
}

function kop_fact_from_doc_line(array $r, array $ctx) {
    $path = (string) $r['path'];
    $base = array('src' => 'record', 'srcs' => array('record'), 'told_by' => array('record:' . $path), 'queue' => 'doc',
        'id' => 'doc:' . $path . '#' . (int) ($r['i'] ?? 0), 'record' => (string) (int) ($ctx['fid'] ?? 0), 'state' => (string) ($ctx['state'] ?? 'on_record'),
        'op' => 'doc_line', 'path' => $path);
    $line = (string) ($r['line'] ?? '');
    if ($path === 'criticalIncidents.customIncidents') return kop_fact_incident($base, $line);
    return kop_fact_note_line($base, $line, '', $path === 'operatingPeriod.notes');
}

/** Whether a record value says something (kop_wbf_no_info() when it is loaded). */
function kop_fact_blank($v) {
    if ($v === null || $v === '' || $v === array()) return true;
    return is_string($v) && function_exists('kop_wbf_no_info') && kop_wbf_no_info($v);
}

/** The record's own facts: fields, lines, news links (section 5.5), with held rows and kept news facts. */
function kop_fact_onhand(array $doc, $fid, array $held_rows = array(), array $kept_news = array(), array $news_urls = array()) {
    $out = array();
    $fid = (int) $fid;
    $id = (array) ($doc['identification'] ?? array());
    $ctx = array('fid' => $fid, 'state' => 'on_record');
    $mk = function ($path, $i, $kind, $type, $text, $date = '') use (&$out, $fid) {
        $out[] = kop_fact_build(array('src' => 'record', 'srcs' => array('record'), 'told_by' => array('record:' . $path), 'queue' => 'doc',
            'id' => 'doc:' . $path . '#' . $i, 'record' => (string) $fid, 'state' => 'on_record', 'op' => 'doc_field', 'path' => $path, 'text' => (string) $text),
            $kind, $type, (string) $text, $date);
    };
    $op = (array) ($doc['operatingPeriod'] ?? array());
    if (stripos((string) ($op['status'] ?? ''), 'closed') !== false) {
        $y = (string) ($op['endYear'] ?? '');
        $mk('operatingPeriod.status', 0, 'closure', 'closed', 'Closed' . ($y !== '' ? ' ' . $y : ''), preg_match('/^(?:19|20)\d\d$/', $y) ? $y : '');
    }
    if (!kop_fact_blank($op['startYear'] ?? null)) {
        $y = (string) $op['startYear'];
        $mk('operatingPeriod.startYear', 0, 'opening', 'opened', 'Start year: ' . $y, preg_match('/^(?:19|20)\d\d$/', $y) ? $y : '');
    }
    $fd = (array) ($doc['facilityDetails'] ?? array());
    if (!kop_fact_blank($fd['capacity'] ?? null) && !is_array($fd['capacity'])) $mk('facilityDetails.capacity', 0, 'size', 'capacity', 'Capacity: ' . $fd['capacity']);
    $ar = $fd['ageRange'] ?? null;
    if (is_array($ar) && (!kop_fact_blank($ar['min'] ?? null) || !kop_fact_blank($ar['max'] ?? null))) {
        $mk('facilityDetails.ageRange', 0, 'ages', 'ages', 'Ages: ' . (string) ($ar['min'] ?? '') . '-' . (string) ($ar['max'] ?? ''));
    }
    if (!kop_fact_blank($fd['gender'] ?? null) && is_string($fd['gender'])) $mk('facilityDetails.gender', 0, 'gender', 'gender', $fd['gender']);
    if (!kop_fact_blank($fd['type'] ?? null) && is_string($fd['type'])) $mk('facilityDetails.type', 0, 'type', 'type', $fd['type']);
    foreach (kop_wbf_staff_all($doc) as $i => $s) {
        $out[] = kop_fact_staff(array('src' => 'record', 'srcs' => array('record'), 'told_by' => array('record:staff'), 'queue' => 'doc', 'id' => 'doc:staff#' . $i,
            'record' => (string) $fid, 'state' => 'on_record', 'op' => 'doc_field', 'path' => 'staff'), (string) ($s['name'] ?? ''), (string) ($s['role'] ?? ''));
    }
    foreach (array('pastNames' => array('name', 'past'), 'otherNames' => array('name', 'other'), 'pastOperators' => array('operator', 'past'),
        'otherOperators' => array('operator', 'past')) as $k => $kt) {
        foreach ((array) ($id[$k] ?? array()) as $i => $n) {
            $n = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
            if (trim($n) !== '') $mk('identification.' . $k, $i, $kt[0], $kt[1], $n);
        }
    }
    if (!kop_fact_blank($id['currentOperator'] ?? null) && is_string($id['currentOperator'])) $mk('identification.currentOperator', 0, 'operator', 'current', $id['currentOperator']);
    foreach ((array) ($id['currentOwners'] ?? array()) as $i => $n) {
        $n = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
        if (trim($n) !== '') $mk('identification.currentOwners', $i, 'owner', 'owner', $n);
    }
    foreach ((array) ($doc['resourceLinks'] ?? array()) as $i => $l) {
        if (is_array($l) && !empty($l['url'])) $mk('resourceLinks', $i, 'link', 'forum', $l['url']);
    }
    $j = 0;
    foreach ($news_urls as $u => $_) {
        $u = is_int($u) ? (string) $_ : (string) $u;
        $mk('news_links', $j++, 'link', 'article', $u);
    }
    foreach (array('criticalIncidents.customIncidents', 'notes', 'operatingPeriod.notes') as $p) {
        foreach ((array) kop_wbf_get($doc, $p) as $i => $line) {
            $line = is_array($line) ? (string) ($line['raw'] ?? ($line['name'] ?? '')) : (string) $line;
            if (trim($line) === '') continue;
            $f = kop_fact_from_doc_line(array('path' => $p, 'line' => $line, 'i' => $i), $ctx);
            if ($f) $out[] = $f;
        }
    }
    foreach ($held_rows as $r) {
        $f = kop_fact_shape('wbf', $r, array('fid' => $fid, 'state' => 'held'));
        if ($f) $out[] = $f;
    }
    foreach ($kept_news as $r) {
        // An article linked to this record can tell of another one: that fact is on hand for the other, not here.
        if ((int) ($r['facility_id'] ?? 0) > 0 && (int) $r['facility_id'] !== $fid) continue;
        $f = kop_fact_shape('news', $r, array('fid' => $fid, 'state' => 'kept'));
        if ($f) $out[] = $f;
    }
    return $out;
}

/* ---- Grouping --------------------------------------------------------------------------------------- */

/** The group of kinds a shape compares in: kinds in one group meet (6.2). */
function kop_fact_group(array $f) {
    $k = $f['kind'];
    $kk = $f['keys'];
    if ($k === 'staff') return 'person';
    if ($k === 'founder' || $k === 'owner') return ($kk['persons'] && !$kk['org']) || ($k === 'founder' && $kk['persons']) ? 'person' : 'company';
    if ($k === 'operator' || $k === 'sale') return 'company';
    if ($k === 'connection') return $kk['persons'] && !$kk['org'] ? 'connection-person' : 'connection-company';
    return $k;
}

/** Block keys (6.1): two facts of one record are candidates when they share one. */
function kop_fact_blocks(array $f) {
    $k = $f['kind'];
    $kk = $f['keys'];
    $b = array();
    $persons = function () use ($kk) {
        $o = array();
        foreach ($kk['persons'] as $p) {
            $first = kop_fact_person_block($p[0]);
            if ($first !== '') $o['person:' . $first] = true;
        }
        if (!$o && $kk['pname'] !== '') {
            $first = kop_fact_person_block($kk['pname']);
            if ($first !== '') $o['person:' . $first] = true;
        }
        return array_keys($o);
    };
    $orgs = function () use ($kk) {
        return array_map(function ($o) { return 'org:' . $o; }, $kk['org']);
    };
    switch ($k) {
        case 'staff':
            return $persons();
        case 'founder':
        case 'owner':
        case 'connection':
            return array_merge($persons(), $orgs());
        case 'operator':
        case 'sale':
        case 'investor':
            return $orgs();
        case 'incident':
            if ($f['date'] !== '') return array('incident:' . $f['type'] . ':' . substr($f['date'], 0, 4));
            if ($f['told'] === '') return array('incident:' . $f['type'] . ':?');
            $y = (int) substr($f['told'], 0, 4);
            return array('incident:' . $f['type'] . ':' . $y, 'incident:' . $f['type'] . ':' . ($y - 1));
        case 'name':
            return array_map(function ($n) { return 'name:' . $n; }, $kk['name']);
        case 'link':
            return $kk['url'] !== '' ? array('url:' . $kk['url']) : array();
        case 'license':
        case 'membership':
            return $kk['acr'] ? array_map(function ($a) use ($k) { return $k . ':' . $a; }, $kk['acr']) : array($k);
        case 'place':
            return array_map(function ($p) { return 'place:' . $p; }, $f['place']);
        case 'statement':
            $p = $kk['person'] !== '' ? array('said:' . $kk['person']) : array();
            return $p ?: $orgs();
        case 'testimony':
            return array();
    }
    return array($k);
}

/**
 * The persons of two facts that are one person, or null. $loose (a note's founder against a staff entry that says so):
 * the same last name and a first name that begins the other ("Art Barker", "Arthur Robert Barker").
 */
function kop_fact_person_pair(array $a, array $b, $loose = false) {
    $pa = $a['keys']['persons'] ?: ($a['keys']['pname'] !== '' ? array(array($a['keys']['pname'], $a['keys']['person'], $a['keys']['suffix'])) : array());
    $pb = $b['keys']['persons'] ?: ($b['keys']['pname'] !== '' ? array(array($b['keys']['pname'], $b['keys']['person'], $b['keys']['suffix'])) : array());
    static $same = array();
    foreach ($pa as $x) foreach ($pb as $y) {
        $ck = $x[0] . "\x1f" . $y[0];
        if (!isset($same[$ck])) $same[$ck] = kop_dd_same_person($x[0], $y[0]);
        if ($same[$ck] || ($x[1] !== '' && $x[1] === $y[1])) return array($x, $y);
        if ($loose) {
            $p = kop_dd_person_parts($x[0]);
            $q = kop_dd_person_parts($y[0]);
            if ($p && $q && $p['last'] === $q['last'] && strlen(min($p['first'], $q['first'])) >= 3
                && (strpos($p['first'], $q['first']) === 0 || strpos($q['first'], $p['first']) === 0)) return array($x, $y);
        }
    }
    return null;
}

/** Why two facts are never one ('' when nothing keeps them apart) (6.3). */
function kop_fact_differs(array $a, array $b) {
    if ($a['kind'] === 'testimony' || $b['kind'] === 'testimony') return 'testimony';
    $g = kop_fact_group($a);
    if ($g !== kop_fact_group($b)) return '';
    $kind = $a['kind'];
    if (in_array($kind, array('incident', 'closure', 'opening', 'sale', 'size'), true) && $a['kind'] === $b['kind'] && !kop_fact_dates_fit($a, $b)) return 'dates';
    if (in_array($kind, array('incident', 'note', 'size', 'cost', 'detail', 'money', 'practice', 'license', 'membership', 'ages', 'statement'), true)
        && $a['keys']['nums'] && $b['keys']['nums'] && $a['keys']['nums'] !== $b['keys']['nums']) return 'numbers';
    if (in_array($kind, array('incident', 'note', 'statement'), true)) {
        if ($a['keys']['ages'] && $b['keys']['ages'] && !array_intersect($a['keys']['ages'], $b['keys']['ages'])) return 'ages';
        if ($a['keys']['people'] && $b['keys']['people'] && !array_intersect($a['keys']['people'], $b['keys']['people'])) return 'head counts';
    }
    if ($kind === 'place') {
        foreach (array('st:', 'cs:', 'campus:') as $pre) {
            $pa = array_filter($a['place'], function ($p) use ($pre) { return strpos($p, $pre) === 0; });
            $pb = array_filter($b['place'], function ($p) use ($pre) { return strpos($p, $pre) === 0; });
            if ($pa && $pb && !array_intersect($pa, $pb)) return 'places';
        }
    } elseif ($a['place'] && $b['place'] && !array_intersect($a['place'], $b['place'])) {
        return 'places';
    }
    if ($g === 'person' || $g === 'connection-person') {
        $hasa = $a['keys']['persons'] || $a['keys']['pname'] !== '';
        $hasb = $b['keys']['persons'] || $b['keys']['pname'] !== '';
        if ($hasa && $hasb) {
            $pair = kop_fact_person_pair($a, $b, $a['kind'] !== $b['kind']);
            if (!$pair) return 'people';
            if ($pair[0][2] !== $pair[1][2]) return 'suffix';
        }
    }
    if ($kind === 'gender' && $a['type'] !== '' && $b['type'] !== '' && $a['type'] !== $b['type'] && $a['kind'] === $b['kind']) return 'gender';
    return '';
}

/** How two facts are one by rule ('' when not) (6.4); calls kop_fact_differs() first. */
function kop_fact_rule_same(array $a, array $b) {
    if ($a['record'] !== $b['record'] || $a['record'] === '') return '';
    if (kop_fact_differs($a, $b) !== '') return '';
    $g = kop_fact_group($a);
    if ($g !== kop_fact_group($b)) return '';
    $ka = $a['kind'];
    $kb = $b['kind'];
    $text = function () use ($a, $b) {
        $x = $a['core'];
        $y = $b['core'];
        return $x !== '' && $y !== '' && (kop_wbf_text_same($x, $y) || kop_wbf_text_same($y, $x)) && $a['keys']['nums'] === $b['keys']['nums'] && kop_fact_dates_fit($a, $b);
    };
    switch ($g) {
        case 'person':
            if (!kop_fact_person_pair($a, $b, $ka !== $kb)) return '';
            if ($ka === 'staff' && $kb === 'staff') return 'same person';
            if ($ka === $kb) return 'same person';
            // A note's founder or owner is a staff entry that says so (the rule inside kop_wbf_list_covers()).
            $staff = $ka === 'staff' ? $a : ($kb === 'staff' ? $b : null);
            $other = $staff === $a ? $b : $a;
            if (!$staff || $other['kind'] === 'staff') return '';
            $doc = array('staff' => array('notableStaff' => array(array('name' => $staff['keys']['pname'], 'role' => $staff['type'], 'pastJobs' => ''))));
            // The person is already matched above (nicknames too); the helper decides whether the staff entry's role says so.
            return kop_wbf_person_role_on_record($doc, $staff['keys']['pname'], $other['kind'] === 'founder' ? 'founder' : 'owner') ? 'person and role' : '';
        case 'company':
            if (!array_intersect($a['keys']['org'], $b['keys']['org'])) return '';
            // Owner decision 2026-11-02: "Owner: X" and "Operator: X" for one company are one fact.
            $oo = array('owner', 'operator');
            if ($ka === $kb || (in_array($ka, $oo, true) && in_array($kb, $oo, true))) return 'same company';
            return '';
        case 'name':
            return array_intersect($a['keys']['name'], $b['keys']['name']) ? 'name key' : '';
        case 'closure':
            if ($a['type'] !== $b['type']) return '';
            $ya = substr($a['date'], 0, 4);
            $yb = substr($b['date'], 0, 4);
            return ($ya === $yb || $ya === '' || $yb === '') ? 'closure year' : '';
        case 'opening':
            return $a['date'] !== '' && substr($a['date'], 0, 4) === substr($b['date'], 0, 4) ? 'start year' : '';
        case 'size':
            return $a['keys']['nums'] && $a['keys']['nums'] === $b['keys']['nums'] && kop_fact_dates_fit($a, $b) ? 'size' : '';
        case 'ages':
            return $a['keys']['nums'] && $a['keys']['nums'] === $b['keys']['nums'] ? 'ages' : '';
        case 'gender':
            return $a['type'] !== '' && $a['type'] === $b['type'] ? 'gender' : '';
        case 'type':
            $x = strtolower(trim($a['core']));
            $y = strtolower(trim($b['core']));
            return $x !== '' && ($x === $y || kop_wbf_value_same('facilityDetails.type', $a['core'], $b['core'])) ? 'type' : '';
        case 'link':
            return $a['keys']['url'] !== '' && $a['keys']['url'] === $b['keys']['url'] ? 'same url' : '';
        case 'place':
            return array_intersect($a['place'], $b['place']) ? 'place' : '';
        case 'testimony':
            return '';
        case 'incident':
            if ($a['type'] !== $b['type']) return '';
            if ($a['date'] !== '' && $b['date'] !== '') {
                $mk = function ($f) { return $f['date'] . ': ' . ucfirst(str_replace('_', ' ', $f['type'])) . ': ' . $f['core']; };
                if (kop_dd_same_event($mk($a), $mk($b))) return 'event';
            }
            return $text() ? 'text' : '';
        case 'license':
        case 'membership':
            if (!array_intersect($a['keys']['acr'], $b['keys']['acr'])) return $text() ? 'text' : '';
            return $text() || strtolower($a['core']) === strtolower($b['core']) ? 'text' : '';
        case 'statement':
            if ($a['keys']['person'] === '' || $a['keys']['person'] !== $b['keys']['person']) return '';
            return $text() ? 'text' : '';
    }
    return $text() ? 'text' : '';
}

/** Close enough for the AI step (d): dates fit, 3 distinctive words in common and Jaccard 0.30 (two for undated incidents of one category). */
function kop_fact_close(array $a, array $b) {
    if ($a['record'] !== $b['record'] || kop_fact_group($a) !== kop_fact_group($b)) return false;
    if (kop_fact_differs($a, $b) !== '') return false;
    if (!kop_fact_dates_fit($a, $b)) return false;
    $shared = count(array_intersect_key($a['dwords'], $b['dwords']));
    $union = count($a['dwords']) + count($b['dwords']) - $shared;
    if ($shared >= 3 && $union > 0 && $shared / $union >= 0.30) return true;
    return $a['kind'] === 'incident' && $a['type'] === $b['type'] && ($a['date'] === '' || $b['date'] === '') && $shared >= 2;
}
