<?php
/**
 * /questions/: "We have questions about..." Readers who were in a program,
 * worked at one or sent a child to one answer one short question at a time
 * about what our records lack, instead of the full data form.
 *
 * Questions are made from the records as they are now (nothing is stored
 * until someone answers):
 *   facility  still open?, opening year, closing year, town, boys/girls,
 *             ages, until when an earlier name was used (no era yet), each
 *             practice the record does not tick (level system, restraint...),
 *             each staff member ("was X there when you were? which year"),
 *             and "what rules do you remember"
 *   company   founding year, headquarters, still operating?, owner now
 *             ({prefix}kop_operators, the Directory Editor's companies)
 *   conflict  sources disagree (Review inbox > Conflicts, years, sizes,
 *             types and closures only): pick one of their values, "I don't
 *             know", or type another
 * Keys: f<id>.<what>[.<sub>], o<id>.<what>, x<hash of the conflict card>.
 *
 * Answers: {prefix}kop_question_answers, one per person and question (a new
 * answer replaces that person's earlier one). A person is a keyed hash of
 * their address and browser, never stored in the clear; 60 answers an hour.
 *
 * Settling (owner, 2026-10-20: a clear majority settles it): when
 * KOP_Q_AGREE (3) different people give the same answer and nobody gives
 * another, the question settles on its own through the Review inbox's own
 * action path (Review inbox > Answers to our questions, 'settle'), so it is
 * in Recently done with Undo. Staff years and rules never settle on their
 * own: they wait for the owner. What settling writes:
 *   facility fields  a row in the Woodbury Facts table (extra.via
 *                    'questions', its source "Answers on the questions
 *                    page"), approved through kop_wbf_apply(): held for the
 *                    record's write-up like every approved fact, conflicts
 *                    marked and waiting like any other
 *   earlier names    kop_name_eras_save() (end year), Undo kop_name_eras_undo()
 *   companies        kop_dired_edit(), Undo kop_dired_undo()
 *   conflicts        each member through Review inbox > Conflicts
 *                    (kop_rinbox_conflicts_act(): keep / use / edit)
 * {prefix}kop_question_state holds a settled or dismissed question, what it
 * wrote ('done', for Undo) and its words. Undo deletes it, so the question
 * is asked again.
 *
 * Tested by scripts/test-questions.php.
 */

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

if (!defined('KOP_Q_DB_VERSION')) define('KOP_Q_DB_VERSION', '1');
if (!defined('KOP_Q_REWRITE_VERSION')) define('KOP_Q_REWRITE_VERSION', '1');
if (!defined('KOP_Q_AGREE')) define('KOP_Q_AGREE', 3);
if (!defined('KOP_Q_HOURLY_CAP')) define('KOP_Q_HOURLY_CAP', 60);
if (!defined('KOP_Q_BY')) define('KOP_Q_BY', 'questions page');

/* ---- Tables ------------------------------------------------------------- */

function kop_q_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_question_answers';
}

function kop_q_state_table() {
    global $wpdb;
    return $wpdb->prefix . 'kop_question_state';
}

function kop_q_ensure_tables() {
    if (get_option('kop_questions_db') === KOP_Q_DB_VERSION) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    dbDelta('CREATE TABLE ' . kop_q_table() . " (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        qkey VARCHAR(80) NOT NULL DEFAULT '',
        record_kind VARCHAR(10) NOT NULL DEFAULT '',
        record_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        answer_key VARCHAR(190) NOT NULL DEFAULT '',
        answer TEXT NULL,
        value LONGTEXT NULL,
        how VARCHAR(20) NOT NULL DEFAULT '',
        source_url TEXT NULL,
        note TEXT NULL,
        who CHAR(24) NOT NULL DEFAULT '',
        created_at DATETIME NULL,
        PRIMARY KEY  (id),
        KEY qkey (qkey),
        KEY who (who)
    ) {$charset};");
    dbDelta('CREATE TABLE ' . kop_q_state_table() . " (
        qkey VARCHAR(80) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT '',
        ask TEXT NULL,
        answer TEXT NULL,
        done LONGTEXT NULL,
        reviewed_by VARCHAR(60) NULL,
        reviewed_at DATETIME NULL,
        PRIMARY KEY  (qkey)
    ) {$charset};");
    update_option('kop_questions_db', KOP_Q_DB_VERSION, false);
}

/* ---- Words -------------------------------------------------------------- */

/** "How do you know?": value => label. */
function kop_q_hows() {
    return array(
        'was_there'  => 'I was there',
        'worked'     => 'I worked there',
        'parent'     => 'My child was there',
        'source'     => 'I read it somewhere (add the link)',
        'other'      => 'Another way',
    );
}

/** The practices a facility is asked about: checklist key => the question, %s = the program. */
function kop_q_practices() {
    return array(
        'hasLevelSystem'               => 'Did %s use a level system?',
        'hasRestraint'                 => 'Did staff at %s physically restrain kids?',
        'hasSeclusion'                 => 'Did %s put kids in seclusion or isolation?',
        'hasStripSearches'             => 'Did %s strip search kids?',
        'hasCommunicationRestrictions' => 'Did %s limit or read kids\' contact with their families?',
        'hasConfrontationGroups'       => 'Did %s run confrontation groups?',
        'hasBehaviorContracts'         => 'Did %s make kids sign behavior contracts?',
        'hasForcedMedication'          => 'Did %s force kids to take medication?',
    );
}

/** Gender values as the record holds them => the answer's words. */
function kop_q_genders() {
    return array('Male' => 'Boys', 'Female' => 'Girls', 'Co-ed' => 'Both');
}

function kop_q_company_statuses() {
    return array('Active' => 'Still operating', 'Defunct' => 'Closed down', 'Acquired' => 'Bought by another company');
}

function kop_q_url($key = '') {
    $base = function_exists('home_url') ? home_url('/questions/') : '/questions/';
    return $key === '' ? $base : $base . '#q=' . rawurlencode($key);
}

/* ---- Small helpers (no WordPress needed: the test loads these) ------------ */

/** A year from text, 1850 to next year, or 0. */
function kop_q_year($s) {
    if (!preg_match('/\b(1[89]\d\d|20\d\d)\b/', (string) $s, $m)) return 0;
    $y = (int) $m[1];
    return ($y >= 1850 && $y <= (int) gmdate('Y') + 1) ? $y : 0;
}

/** Text as an answer key: lower case, single spaces, no trailing dots. */
function kop_q_text_key($s) {
    $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s)));
    return mb_substr(rtrim($s, ' .'), 0, 180);
}

function kop_q_clean($s, $max = 400) {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $s)));
    return mb_substr($s, 0, $max);
}

/** No information: empty, null, "Unknown", 0. */
function kop_q_empty($v) {
    if (function_exists('kop_wbf_no_info')) return kop_wbf_no_info($v);
    if ($v === null || $v === false || $v === '' || $v === 0) return true;
    return is_string($v) && in_array(strtolower(trim($v)), array('unknown', '?', 'n/a', 'none'), true);
}

/** "City, ST" -> [city, ST]; a lone state is a state. */
function kop_q_split_place($s) {
    $s = kop_q_clean($s, 120);
    if (preg_match('/^(.+?),\s*([A-Za-z]{2}|[A-Za-z][A-Za-z ]+)$/', $s, $m)) return array(trim($m[1]), trim($m[2]));
    return array($s, '');
}

/**
 * The tally of a question's answers: one per person (their latest), grouped
 * by answer key, most first. Each {key, label, value, count, hows, sources, notes}.
 */
function kop_q_tally(array $rows) {
    $latest = array();
    foreach ($rows as $r) {
        $who = (string) $r['who'];
        if (!isset($latest[$who]) || (int) $r['id'] > (int) $latest[$who]['id']) $latest[$who] = $r;
    }
    $groups = array();
    foreach ($latest as $r) {
        $k = (string) $r['answer_key'];
        if (!isset($groups[$k])) {
            $groups[$k] = array('key' => $k, 'label' => (string) $r['answer'], 'value' => json_decode((string) $r['value'], true),
                'count' => 0, 'hows' => array(), 'sources' => array(), 'notes' => array(), 'ids' => array());
        }
        $g = &$groups[$k];
        $g['count']++;
        $g['ids'][] = (int) $r['id'];
        if ($r['how'] !== '') $g['hows'][$r['how']] = ($g['hows'][$r['how']] ?? 0) + 1;
        if (!empty($r['source_url'])) $g['sources'][] = (string) $r['source_url'];
        if (!empty($r['note'])) $g['notes'][] = (string) $r['note'];
        unset($g);
    }
    usort($groups, function ($a, $b) { return $b['count'] <=> $a['count'] ?: strcmp($a['key'], $b['key']); });
    return $groups;
}

/** The answer a clear majority gave (KOP_Q_AGREE people or more, nobody saying otherwise), or null. */
function kop_q_agreed(array $tally) {
    if (count($tally) !== 1) return null;
    return $tally[0]['count'] >= KOP_Q_AGREE ? $tally[0] : null;
}

/* ---- Questions ---------------------------------------------------------- */

/** One question: the shape the page draws. */
function kop_q_make($key, $type, $ask, array $record, array $more = array()) {
    return $more + array(
        'key' => $key, 'type' => $type, 'ask' => $ask, 'record' => $record,
        'sub' => '', 'choices' => array(), 'other' => '', 'auto' => true, 'followup' => '',
        'placeholder' => '', 'field' => '',
    );
}

/** The record as a question names it: {kind, id, name, url, place}. */
function kop_q_facility_ref($fid, array $doc) {
    $name = trim((string) ($doc['identification']['name'] ?? ''));
    $loc = (array) ($doc['location'] ?? array());
    $place = implode(', ', array_filter(array(trim((string) ($loc['city'] ?? '')), trim((string) ($loc['state'] ?? '')))));
    $url = '';
    if (function_exists('kop_rinbox_facility')) {
        $f = kop_rinbox_facility((int) $fid);
        $url = (string) ($f['url'] ?? '');
    }
    return array('kind' => 'facility', 'id' => (int) $fid, 'name' => $name !== '' ? $name : 'Program #' . (int) $fid, 'url' => $url, 'place' => $place);
}

/**
 * Every open question about a facility record, from its document. $eras:
 * the record's name eras (name key => row). Pure: the test calls it.
 */
function kop_q_facility_questions($fid, array $doc, array $eras = array(), array $ref = null) {
    $ref = $ref ?: kop_q_facility_ref($fid, $doc);
    $n = $ref['name'];
    $f = 'f' . (int) $fid;
    $op = (array) ($doc['operatingPeriod'] ?? array());
    $status = (string) ($op['status'] ?? '');
    $out = array();

    if (kop_q_empty($status)) {
        $out[] = kop_q_make("$f.status", 'swipe', "Is $n still open?", $ref,
            array('choices' => array(array('value' => 'Open', 'label' => 'Yes, still open'), array('value' => 'Closed', 'label' => 'No, it closed')), 'field' => 'Still open?'));
    }
    if (kop_q_empty($op['startYear'] ?? null)) {
        $out[] = kop_q_make("$f.opened", 'year', "What year did $n open?", $ref, array('field' => 'Year it opened', 'sub' => 'Your best guess is fine if you say so in the note.'));
    }
    if (strcasecmp($status, 'Closed') === 0 && kop_q_empty($op['endYear'] ?? null)) {
        $out[] = kop_q_make("$f.closed", 'year', "What year did $n close?", $ref, array('field' => 'Year it closed'));
    }
    $loc = (array) ($doc['location'] ?? array());
    if (kop_q_empty($loc['city'] ?? null)) {
        $state = trim((string) ($loc['state'] ?? ''));
        $out[] = kop_q_make("$f.city", 'text', "What town or city is $n in" . ($state !== '' ? " ($state)" : '') . '?', $ref,
            array('field' => 'Town or city', 'placeholder' => 'Town or city'));
    }
    $det = (array) ($doc['facilityDetails'] ?? array());
    if (kop_q_empty($det['gender'] ?? null)) {
        $choices = array();
        foreach (kop_q_genders() as $v => $l) $choices[] = array('value' => $v, 'label' => $l);
        $out[] = kop_q_make("$f.gender", 'choice', "Did $n take boys, girls or both?", $ref, array('choices' => $choices, 'field' => 'Boys, girls or both'));
    }
    $ages = (array) ($det['ageRange'] ?? array());
    if (kop_q_empty($ages['min'] ?? null) && kop_q_empty($ages['max'] ?? null)) {
        $out[] = kop_q_make("$f.ages", 'ages', "What ages did $n take?", $ref, array('field' => 'Ages'));
    }
    foreach ((array) ($doc['identification']['pastNames'] ?? array()) as $past) {
        $past = trim((string) $past);
        if ($past === '') continue;
        $nk = function_exists('kop_name_eras_key') ? kop_name_eras_key($past) : kop_q_text_key($past);
        if (isset($eras[$nk]) && (int) ($eras[$nk]['end_year'] ?? 0) > 0) continue;
        $out[] = kop_q_make("$f.renamed." . substr(md5(kop_q_text_key($past)), 0, 8), 'year', "Until what year was $n called \"$past\"?", $ref,
            array('field' => 'Last year under an earlier name', 'name' => $past, 'sub' => 'The year it started using a new name.'));
    }
    $ticks = (array) ($doc['ttiPractices'] ?? array());
    foreach (kop_q_practices() as $k => $q) {
        if (!empty($ticks[$k])) continue;
        $out[] = kop_q_make("$f.practice.$k", 'swipe', sprintf($q, $n), $ref,
            array('choices' => array(array('value' => 'yes', 'label' => 'Yes'), array('value' => 'no', 'label' => 'No')), 'field' => 'Practice: ' . $k, 'practice' => $k));
    }
    $staff = array();
    foreach (array('administrator', 'notableStaff') as $list) {
        foreach ((array) ($doc['staff'][$list] ?? array()) as $s) {
            if (!is_array($s) || trim((string) ($s['name'] ?? '')) === '') continue;
            $role = trim((string) ($s['role'] ?? ''));
            // A role that already says when ("Director (1998-2003)") is not asked about.
            if (preg_match('/\b(1[89]\d\d|20\d\d)\b/', $role)) continue;
            $staff[] = array('name' => trim((string) $s['name']), 'role' => $role);
        }
    }
    foreach (array_slice($staff, 0, 8) as $s) {
        $who = $s['name'] . ($s['role'] !== '' ? ' (' . $s['role'] . ')' : '');
        $out[] = kop_q_make("$f.staff." . substr(md5(kop_q_text_key($s['name'])), 0, 8), 'swipe', "Was $who at $n when you were?", $ref,
            array('choices' => array(array('value' => 'yes', 'label' => 'Yes'), array('value' => 'no', 'label' => 'No')),
                  'followup' => 'year', 'auto' => false, 'field' => 'Staff: ' . $s['name'], 'person' => $s));
    }
    $out[] = kop_q_make("$f.rules", 'text', "What rules do you remember at $n?", $ref,
        array('auto' => false, 'long' => true, 'field' => 'Rules', 'placeholder' => 'Levels, punishments, what you could and could not do...',
              'sub' => 'Only what you are comfortable sharing. It is read by a person before anything goes on the site.'));
    // A person on both staff lists, or a name listed twice, is asked about once.
    $seen = array();
    return array_values(array_filter($out, function ($q) use (&$seen) {
        if (isset($seen[$q['key']])) return false;
        return $seen[$q['key']] = true;
    }));
}

/** Every open question about a company record ({operator: {...}} JSON). */
function kop_q_company_questions($oid, array $op, $name, $url = '') {
    $ref = array('kind' => 'operator', 'id' => (int) $oid, 'name' => $name, 'url' => $url, 'place' => '');
    $o = 'o' . (int) $oid;
    $out = array();
    if (kop_q_year($op['founded'] ?? '') === 0) {
        $out[] = kop_q_make("$o.founded", 'year', "What year was $name founded?", $ref, array('field' => 'Year founded'));
    }
    if (trim((string) ($op['headquartersCity'] ?? '')) === '' && trim((string) ($op['headquarters'] ?? '')) === '') {
        $out[] = kop_q_make("$o.hq", 'text', "Where is $name based?", $ref, array('field' => 'Headquarters', 'placeholder' => 'City, state'));
    }
    if (kop_q_empty($op['status'] ?? null)) {
        $choices = array();
        foreach (kop_q_company_statuses() as $v => $l) $choices[] = array('value' => $v, 'label' => $l);
        $out[] = kop_q_make("$o.status", 'choice', "Is $name still operating?", $ref, array('choices' => $choices, 'field' => 'Status'));
    }
    $parents = array_filter(array_map('trim', array_map('strval', (array) ($op['parentCompanies'] ?? array()))));
    if (!$parents) {
        $out[] = kop_q_make("$o.owner", 'text', "Does another company own $name now? Which one?", $ref,
            array('field' => 'Owned by', 'placeholder' => 'Company name', 'other' => 'No, nobody else owns it'));
    }
    return $out;
}

/* ---- Conflicts ---------------------------------------------------------- */

/** How a conflict is asked, from the field it is about (the Woodbury Facts row's path) or its kind. */
function kop_q_conflict_ask($what, $path, $name) {
    $by_path = array(
        'operatingPeriod.startYear' => "What year did $name open?",
        'operatingPeriod.endYear'   => "What year did $name close?",
        'operatingPeriod.status'    => "Is $name still open?",
        'facilityDetails.ageRange'  => "What ages did $name take?",
        'facilityDetails.capacity'  => "How many kids could $name hold?",
        'facilityDetails.type'      => "What kind of program is $name?",
        'facilityDetails.gender'    => "Did $name take boys, girls or both?",
    );
    if (isset($by_path[$path])) return $by_path[$path];
    $by_what = array(
        'dates'   => "Which year is right for $name?",
        'closure' => "When did $name close?",
        'size'    => "What size or ages is right for $name?",
        'type'    => "What kind of program is $name?",
    );
    return $by_what[$what] ?? "Which is right about $name?";
}

/**
 * Open conflict questions, [qkey => {unit, members [conflict key => {record, item}], choices,
 * what, path, facility_id}], from Review inbox > Conflicts. Cached 30 minutes.
 */
function kop_q_conflicts($fresh = false) {
    if (!$fresh) {
        $c = get_transient('kop_q_conflicts');
        if (is_array($c)) return $c;
    }
    $out = array();
    if (function_exists('kop_rinbox_conflicts_units')) {
        try {
            $single = function_exists('kop_rinbox_conflicts_single_kinds') ? kop_rinbox_conflicts_single_kinds() : array('dates', 'size', 'type', 'closure');
            foreach (kop_rinbox_conflicts_units(array()) as $unit => $members) {
                $first = reset($members);
                $fid = (int) ($first['facility_id'] ?? 0);
                if ($fid <= 0 || !in_array($first['what'] ?? '', $single, true)) continue;
                $queue = explode('|', $unit, 2)[0];
                $choices = array();
                $mem = array();
                foreach ($members as $k => $c) {
                    $mem[$queue . '|' . $k] = array('record' => (string) ($c['record'] ?? ''), 'item' => (string) ($c['item'] ?? ''));
                    foreach (array($c['record'] ?? '', $c['item'] ?? '') as $v) {
                        $v = trim((string) $v);
                        if ($v !== '' && !kop_q_empty($v)) $choices[kop_q_text_key($v)] = $v;
                    }
                }
                if (count($choices) < 2) continue;
                $path = '';
                if ($queue === 'woodbury-facts' && function_exists('kop_wbf_rows')) {
                    $rows = kop_wbf_rows(array((string) array_keys($members)[0]));
                    $path = (string) ($rows[0]['path'] ?? '');
                }
                $out['x' . substr(md5($unit), 0, 12)] = array('unit' => $unit, 'members' => $mem, 'choices' => array_values($choices),
                    'what' => (string) $first['what'], 'path' => $path, 'facility_id' => $fid);
            }
        } catch (Throwable $e) {
            // No conflict questions this time.
        }
    }
    set_transient('kop_q_conflicts', $out, 30 * MINUTE_IN_SECONDS);
    return $out;
}

/** A conflict question as the page draws it. */
function kop_q_conflict_question($key, array $c) {
    $doc = function_exists('kop_on_file_doc') ? kop_on_file_doc((int) $c['facility_id']) : null;
    if (!$doc) return null;
    $ref = kop_q_facility_ref((int) $c['facility_id'], (array) $doc['doc']);
    $choices = array();
    foreach ($c['choices'] as $v) $choices[] = array('value' => $v, 'label' => $v);
    $other = in_array($c['what'], array('dates', 'closure'), true) ? 'A different year' : 'Something else';
    return kop_q_make($key, 'choice', kop_q_conflict_ask($c['what'], $c['path'], $ref['name']), $ref,
        array('choices' => $choices, 'other' => $other, 'field' => 'Sources disagree', 'conflict' => true,
              'sub' => 'Our sources disagree about this.'));
}

/* ---- Finding questions -------------------------------------------------- */

/** The settled or dismissed keys, [qkey => status]. */
function kop_q_closed_keys() {
    // Read once a request; every change to the state table clears it (kop_q_state_changed()).
    if (isset($GLOBALS['kop_q_closed'])) return $GLOBALS['kop_q_closed'];
    kop_q_ensure_tables();
    global $wpdb;
    $memo = array();
    foreach ((array) $wpdb->get_results('SELECT qkey, status FROM ' . kop_q_state_table(), ARRAY_A) as $r) $memo[$r['qkey']] = $r['status'];
    return $GLOBALS['kop_q_closed'] = $memo;
}

function kop_q_state_changed() {
    unset($GLOBALS['kop_q_closed']);
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
}

/** Open questions about one facility, conflicts first. */
function kop_q_for_facility($fid) {
    $doc = function_exists('kop_on_file_doc') ? kop_on_file_doc((int) $fid) : null;
    if (!$doc) return array();
    $eras = function_exists('kop_name_eras_for') ? kop_name_eras_for((int) $fid) : array();
    $out = array();
    foreach (kop_q_conflicts() as $key => $c) {
        if ((int) $c['facility_id'] === (int) $fid && ($q = kop_q_conflict_question($key, $c))) $out[] = $q;
    }
    $out = array_merge($out, kop_q_facility_questions((int) $fid, (array) $doc['doc'], $eras));
    $closed = kop_q_closed_keys();
    return array_values(array_filter($out, function ($q) use ($closed) { return !isset($closed[$q['key']]); }));
}

/** A company row of {prefix}kop_operators, or null. */
function kop_q_company_row($oid) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$wpdb->prefix}kop_operators` WHERE id = %d", (int) $oid), ARRAY_A);
    return is_array($row) ? $row : null;
}

function kop_q_for_company($oid) {
    $row = kop_q_company_row($oid);
    if (!$row) return array();
    $json = json_decode((string) $row['json_data'], true);
    $op = is_array($json['operator'] ?? null) ? $json['operator'] : array();
    $name = function_exists('kop_dired_name') ? kop_dired_name($row) : (string) $row['name'];
    $url = function_exists('kop_dired_company_url') ? (string) kop_dired_company_url((int) $oid) : '';
    $closed = kop_q_closed_keys();
    return array_values(array_filter(kop_q_company_questions((int) $oid, $op, $name, $url), function ($q) use ($closed) { return !isset($closed[$q['key']]); }));
}

/** One open question by its key, or null (answered on the record since, settled, dismissed, unknown). */
function kop_q_get($key) {
    $key = (string) $key;
    if (isset(kop_q_closed_keys()[$key])) return null;
    if (preg_match('/^x[a-f0-9]{12}$/', $key)) {
        $c = kop_q_conflicts()[$key] ?? null;
        return $c ? kop_q_conflict_question($key, $c) : null;
    }
    if (preg_match('/^f(\d+)\./', $key, $m)) $list = kop_q_for_facility((int) $m[1]);
    elseif (preg_match('/^o(\d+)\./', $key, $m)) $list = kop_q_for_company((int) $m[1]);
    else return null;
    foreach ($list as $q) if ($q['key'] === $key) return $q;
    return null;
}

/** A US state code from anything a reader sends, or ''. */
function kop_q_state_code($s) {
    $s = strtoupper(trim((string) $s));
    return preg_match('/^[A-Z]{2}$/', $s) ? $s : '';
}

/**
 * A deck to answer: conflicts, questions people have started on, then
 * questions about records with gaps, a few per record. $seen: keys already shown.
 */
function kop_q_deck($state = '', array $seen = array(), $size = 14) {
    $seen = array_flip($seen);
    $closed = kop_q_closed_keys();
    $out = array();
    $add = function ($q) use (&$out, &$seen, $closed) {
        if (!$q || isset($seen[$q['key']]) || isset($closed[$q['key']])) return;
        $seen[$q['key']] = true;
        $out[] = $q;
    };
    $in_state = function ($q) use ($state) {
        if ($state === '') return true;
        return (bool) preg_match('/(^|, )' . preg_quote($state, '/') . '$/', (string) $q['record']['place']);
    };

    // Sources disagree: the most useful answers.
    $conf = kop_q_conflicts();
    $keys = array_keys($conf);
    shuffle($keys);
    $n = 0;
    foreach ($keys as $k) {
        if ($n >= 3) break;
        $q = kop_q_conflict_question($k, $conf[$k]);
        if ($q && $in_state($q) && !isset($seen[$k])) { $add($q); $n++; }
    }

    // Started by others and not settled: the ones people disagree on, or nearly agree on, need more answers.
    kop_q_ensure_tables();
    global $wpdb;
    $started = (array) $wpdb->get_col('SELECT qkey FROM ' . kop_q_table() . ' GROUP BY qkey ORDER BY COUNT(DISTINCT who) DESC, MAX(id) DESC LIMIT 60');
    $n = 0;
    foreach ($started as $k) {
        if ($n >= 4 || isset($seen[$k]) || isset($closed[$k])) continue;
        $q = kop_q_get($k);
        if ($q && $q['auto'] && $in_state($q)) { $add($q); $n++; }
    }

    // Records with gaps.
    try {
        $pdo = kop_rinbox_pdo();
        $rand = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'RANDOM()' : 'RAND()';
        $sql = "SELECT id FROM facilities_v2 WHERE (start_year IS NULL OR start_year = 0 OR city IS NULL OR city = ''
                OR status IS NULL OR status IN ('', 'Unknown') OR (status = 'Closed' AND (end_year IS NULL OR end_year = 0)))";
        $args = array();
        if ($state !== '') {
            $sql .= ' AND state = ?';
            $args[] = $state;
        }
        $st = $pdo->prepare($sql . " ORDER BY $rand LIMIT 12");
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            if (count($out) >= $size) break;
            // Two of a record's plain facts (no staff, rules or conflicts: those come with the record's own questions), then one practice.
            $facts = $prac = array();
            foreach (kop_q_for_facility((int) $fid) as $q) {
                if (!empty($q['conflict']) || preg_match('/\.(staff\.\w+|rules)$/', $q['key'])) continue;
                if (strpos($q['key'], '.practice.') !== false) $prac[] = $q;
                else $facts[] = $q;
            }
            foreach (array_slice($facts, 0, 2) as $q) $add($q);
            if ($prac) $add($prac[array_rand($prac)]);
        }
    } catch (Throwable $e) {
        // The deck is shorter.
    }
    return array_slice($out, 0, $size);
}

/* ---- Answers ------------------------------------------------------------ */

/** Who is answering: a keyed hash of their address and browser. */
function kop_q_who() {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return substr(hash_hmac('sha256', $ip . '|' . $ua, wp_salt('nonce')), 0, 24);
}

/**
 * A reader's answer in the question's shape: ['key', 'label', 'value'], or
 * null for "I don't know". Throws on an answer that does not fit.
 */
function kop_q_normalize(array $q, array $in) {
    $v = trim((string) ($in['value'] ?? ''));
    if ($v === '' || $v === 'unknown') return null;
    switch ($q['type']) {
        case 'year':
            $y = kop_q_year($v);
            if (!$y) throw new RuntimeException('Type a year like 1998.');
            return array('key' => (string) $y, 'label' => (string) $y, 'value' => $y);
        case 'ages':
            $min = (int) ($in['min'] ?? 0);
            $max = (int) ($in['max'] ?? 0);
            if ($v !== '' && preg_match('/^(\d{1,2})\s*(?:-|to|–)\s*(\d{1,2})$/u', $v, $m)) { $min = (int) $m[1]; $max = (int) $m[2]; }
            if ($min < 5 || $max > 25 || $min > $max) throw new RuntimeException('Give the youngest and oldest age, like 12 to 17.');
            return array('key' => $min . '-' . $max, 'label' => $min . ' to ' . $max, 'value' => array('min' => $min, 'max' => $max));
        case 'swipe':
        case 'choice':
            if ($v === 'other') {
                $text = kop_q_clean($in['other'] ?? '', 200);
                if ($text === '') throw new RuntimeException('Type your answer in the box.');
                if (!empty($q['conflict']) && $q['other'] === 'A different year') {
                    $y = kop_q_year($text);
                    if (!$y) throw new RuntimeException('Type a year like 1998.');
                    $text = (string) $y;
                }
                return array('key' => kop_q_text_key($text), 'label' => $text, 'value' => $text);
            }
            foreach ($q['choices'] as $c) {
                if ((string) $c['value'] !== $v) continue;
                $label = (string) $c['label'];
                $value = $c['value'];
                if (($q['followup'] ?? '') === 'year' && $v === 'yes') {
                    $y = kop_q_year($in['year'] ?? '');
                    // The year a reader saw them there; "yes" without one still counts.
                    $value = array('yes' => true, 'year' => $y ?: null);
                    $label = 'Yes' . ($y ? ', in ' . $y : '');
                }
                return array('key' => (string) $c['value'], 'label' => $label, 'value' => $value);
            }
            throw new RuntimeException('Pick one of the answers.');
        case 'text':
            if ($v === 'other' && $q['other'] !== '') return array('key' => 'none', 'label' => $q['other'], 'value' => '');
            $text = kop_q_clean($v, !empty($q['long']) ? 4000 : 200);
            if (mb_strlen($text) < 2) throw new RuntimeException('Type your answer first.');
            return array('key' => !empty($q['long']) ? substr(md5($text), 0, 16) : kop_q_text_key($text), 'label' => $text, 'value' => $text);
    }
    throw new RuntimeException('Unknown question.');
}

function kop_q_rows($qkey) {
    kop_q_ensure_tables();
    global $wpdb;
    return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_q_table() . ' WHERE qkey = %s ORDER BY id', (string) $qkey), ARRAY_A);
}

function kop_q_rate_key() {
    return 'kop_q_rate_' . kop_q_who();
}

/**
 * Store a reader's answer (replacing their earlier one to the same question)
 * and settle the question when a clear majority now agrees. Returns
 * {message, settled}.
 */
function kop_q_answer(array $p) {
    $key = (string) ($p['key'] ?? '');
    $q = kop_q_get($key);
    if (!$q) return array('message' => 'Someone has answered that one already. Thank you.', 'settled' => false, 'gone' => true);
    $a = kop_q_normalize($q, (array) ($p['answer'] ?? array()));
    if ($a === null) return array('message' => 'Skipped.', 'settled' => false);
    if ((int) get_transient(kop_q_rate_key()) >= KOP_Q_HOURLY_CAP) {
        throw new RuntimeException('That is a lot of answers in one hour. Thank you! Please come back in a little while.');
    }
    $how = sanitize_key((string) ($p['how'] ?? ''));
    if (!isset(kop_q_hows()[$how])) $how = '';
    $src = esc_url_raw(trim((string) ($p['source'] ?? '')));
    if ($src !== '' && !preg_match('#^https?://#i', $src)) $src = '';
    global $wpdb;
    kop_q_ensure_tables();
    $who = kop_q_who();
    $wpdb->delete(kop_q_table(), array('qkey' => $key, 'who' => $who));
    $wpdb->insert(kop_q_table(), array(
        'qkey' => $key, 'record_kind' => $q['record']['kind'], 'record_id' => (int) $q['record']['id'],
        'answer_key' => $a['key'], 'answer' => mb_substr($a['label'], 0, 4000), 'value' => wp_json_encode($a['value']),
        'how' => $how, 'source_url' => $src, 'note' => kop_q_clean($p['note'] ?? '', 1000),
        'who' => $who, 'created_at' => current_time('mysql', true),
    ));
    set_transient(kop_q_rate_key(), (int) get_transient(kop_q_rate_key()) + 1, HOUR_IN_SECONDS);
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();

    $agreed = $q['auto'] ? kop_q_agreed(kop_q_tally(kop_q_rows($key))) : null;
    if ($agreed && function_exists('kop_rinbox_apply_action')) {
        $GLOBALS['kop_rinbox_reviewer_as'] = KOP_Q_BY;
        try {
            kop_rinbox_apply_action('questions', $key, 'settle', array('answer' => $agreed['key']));
            return array('message' => 'Thank you. That makes ' . $agreed['count'] . ' people who agree, so it goes to the record.', 'settled' => true);
        } catch (Throwable $e) {
            // It waits for the owner in the Review inbox.
        } finally {
            unset($GLOBALS['kop_rinbox_reviewer_as']);
        }
    }
    return array('message' => 'Thank you. Your answer is saved and will be checked.', 'settled' => false);
}

/* ---- Settling ----------------------------------------------------------- */

function kop_q_state($qkey) {
    kop_q_ensure_tables();
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_q_state_table() . ' WHERE qkey = %s', (string) $qkey), ARRAY_A);
    return is_array($r) ? $r : null;
}

function kop_q_set_state($qkey, $status, $ask, $answer, $done, $by) {
    global $wpdb;
    $wpdb->delete(kop_q_state_table(), array('qkey' => (string) $qkey));
    $wpdb->insert(kop_q_state_table(), array('qkey' => (string) $qkey, 'status' => $status, 'ask' => (string) $ask, 'answer' => (string) $answer,
        'done' => wp_json_encode($done), 'reviewed_by' => (string) $by, 'reviewed_at' => current_time('mysql', true)));
    kop_q_state_changed();
}

/**
 * Write one facility value the way an approved Woodbury fact is written: a
 * row in that table citing the questions page, approved through
 * kop_wbf_apply() (held for the record's write-up). Returns what Undo needs.
 */
function kop_q_wbf_write(array $q, $op, $path, $value, $label, $count, $by) {
    if (!function_exists('kop_wbf_apply')) throw new RuntimeException('Facility facts cannot be written here.');
    kop_wbf_ensure_table();
    global $wpdb;
    $fid = (int) $q['record']['id'];
    $pkey = md5('questions|' . $q['key'] . '|' . microtime(true));
    $cite = 'Answers on Kids Over Profits\' questions page' . ($count > 1 ? ' (' . (int) $count . ' people agreed)' : '');
    $grp = in_array($path, array('facilityDetails.gender', 'facilityDetails.ageRange'), true) || strpos($path, 'ttiPractices.') === 0 ? 'details' : 'history';
    $wpdb->insert(kop_wbf_table(), array(
        'pkey' => $pkey, 'facility_id' => $fid, 'program' => mb_substr($q['record']['name'], 0, 255), 'place' => mb_substr($q['record']['place'], 0, 120),
        'match_kind' => 'id', 'grp' => $grp, 'op' => $op, 'path' => $path, 'value' => wp_json_encode($value), 'label' => $label,
        'extra' => wp_json_encode(array('manual' => 1, 'via' => 'questions', 'qkey' => $q['key'])),
        'evidence' => wp_json_encode(array(array('cite' => $cite, 'url' => kop_q_url($q['key'])))),
        'status' => 'pending', 'created_at' => current_time('mysql', true),
    ));
    $rows = kop_wbf_rows(array($pkey));
    if (!$rows) throw new RuntimeException('Could not save the answer as a fact.');
    $res = kop_wbf_apply($rows, $fid, $by)[$pkey] ?? array();
    if (!empty($res['conflict'])) return array('wbf' => $pkey, 'conflict' => (string) $res['conflict']);
    if (empty($res['ok'])) {
        $wpdb->delete(kop_wbf_table(), array('pkey' => $pkey));
        throw new RuntimeException((string) ($res['error'] ?? 'The record already says this.'));
    }
    return array('wbf' => $pkey);
}

/**
 * Settle a question with one of its answers (by answer key, or $typed for
 * the owner's own value). Writes it to the record; returns a message.
 */
function kop_q_settle($qkey, $answer_key, $by, $typed = '') {
    $q = kop_q_get($qkey);
    if (!$q) throw new RuntimeException('This question is closed already, or the record has the answer now.');
    $tally = kop_q_tally(kop_q_rows($qkey));
    $pick = null;
    foreach ($tally as $t) if ($t['key'] === (string) $answer_key) $pick = $t;
    if ($typed !== '') {
        $a = kop_q_normalize($q, array('value' => $q['type'] === 'choice' || $q['type'] === 'swipe' ? 'other' : $typed, 'other' => $typed));
        $pick = array('key' => $a['key'], 'label' => $a['label'], 'value' => $a['value'], 'count' => 0);
    }
    if (!$pick) throw new RuntimeException('Pick one of the answers.');
    $value = $pick['value'];
    $count = (int) $pick['count'];
    $key = $q['key'];
    $n = $q['record']['name'];
    $done = array();
    $msg = '';

    if (!empty($q['conflict'])) {
        $c = kop_q_conflicts()[$key] ?? null;
        if (!$c) throw new RuntimeException('That conflict is settled already.');
        // The answer is the record's value: keep it everywhere. One source's value: use that item, keep the
        // record against the rest. Neither (a typed year): write it through the first item, keep the rest.
        $want = kop_q_text_key($pick['label']);
        $named = array();
        foreach ($c['members'] as $m) {
            $named[kop_q_text_key($m['record'])] = true;
            $named[kop_q_text_key($m['item'])] = true;
        }
        $done['conflicts'] = array();
        $used = false;
        foreach ($c['members'] as $mk => $m) {
            $how = 'resolve_keep';
            if (!$used && kop_q_text_key($m['record']) !== $want && (kop_q_text_key($m['item']) === $want || !isset($named[$want]))) {
                $how = kop_q_text_key($m['item']) === $want ? 'resolve_use' : 'resolve_edit';
                $used = true;
            }
            kop_rinbox_conflicts_act($mk, $how, array('value' => $pick['label']));
            $done['conflicts'][] = $mk;
        }
        delete_transient('kop_q_conflicts');
        $msg = 'Settled: ' . $pick['label'] . '.';
    } elseif (preg_match('/^f\d+\.(status|opened|closed|city|gender|ages|practice\.\w+|staff\.\w+|rules|renamed\.\w+)$/', $key, $m)) {
        $what = $m[1];
        if ($what === 'status') {
            $done = $value === 'Closed'
                ? kop_q_wbf_write($q, 'set_closed', 'operatingPeriod.status', array('endYear' => null), 'Closed', $count, $by)
                : kop_q_wbf_write($q, 'set_if_empty', 'operatingPeriod.status', 'Open', 'Open', $count, $by);
        } elseif ($what === 'opened') {
            $done = kop_q_wbf_write($q, 'set_if_empty', 'operatingPeriod.startYear', (int) $value, 'Opened in ' . (int) $value, $count, $by);
        } elseif ($what === 'closed') {
            $done = kop_q_wbf_write($q, 'set_if_empty', 'operatingPeriod.endYear', (int) $value, 'Closed in ' . (int) $value, $count, $by);
        } elseif ($what === 'city') {
            $done = kop_q_wbf_write($q, 'set_if_empty', 'location.city', (string) $value, 'In ' . $value, $count, $by);
        } elseif ($what === 'gender') {
            $done = kop_q_wbf_write($q, 'set_if_empty', 'facilityDetails.gender', (string) $value, 'Took ' . strtolower(kop_q_genders()[$value] ?? (string) $value), $count, $by);
        } elseif ($what === 'ages') {
            $done = kop_q_wbf_write($q, 'set_if_empty', 'facilityDetails.ageRange', (array) $value, 'Ages ' . $pick['label'], $count, $by);
        } elseif (strpos($what, 'practice.') === 0) {
            if ($value === 'yes') {
                $label = function_exists('kop_facility_checklist_labels') ? (kop_facility_checklist_labels()['ttiPractices.' . $q['practice']] ?? $q['practice']) : $q['practice'];
                $done = kop_q_wbf_write($q, 'set_if_empty', 'ttiPractices.' . $q['practice'], true, $label, $count, $by);
            } else {
                $done = array('nothing' => 1);
            }
        } elseif (strpos($what, 'staff.') === 0) {
            $years = array();
            foreach ($tally as $t) if (($t['value']['yes'] ?? false) && !empty($t['value']['year'])) $years[] = (int) $t['value']['year'];
            $yes = array_sum(array_map(function ($t) { return !empty($t['value']['yes']) ? $t['count'] : 0; }, $tally));
            if ($value === 'no' || empty($value['yes'])) {
                $done = array('nothing' => 1);
            } else {
                $who = $q['person']['name'] . ($q['person']['role'] !== '' ? ' (' . $q['person']['role'] . ')' : '');
                $years = array_values(array_unique($years));
                sort($years);
                $line = sprintf('%d %s who %s at %s say %s was there%s.', $yes, $yes === 1 ? 'person' : 'people', $yes === 1 ? 'was' : 'were', $n, $who,
                    $years ? ' in ' . implode(', ', $years) : '');
                $done = kop_q_wbf_write($q, 'add_list', 'notes', $line, $line, $yes, $by);
            }
        } elseif ($what === 'rules') {
            $line = 'Someone who was at ' . $n . ' remembers: "' . $pick['label'] . '"';
            $done = kop_q_wbf_write($q, 'add_list', 'notes', $line, 'Rules remembered', 1, $by);
        } elseif (strpos($what, 'renamed.') === 0) {
            if (!function_exists('kop_name_eras_save')) throw new RuntimeException('Name years cannot be written here.');
            $era = kop_name_eras_for((int) $q['record']['id'])[kop_name_eras_key($q['name'])] ?? null;
            $r = kop_name_eras_save(array('facility_id' => (int) $q['record']['id'], 'name' => $q['name'],
                'operator' => (string) ($era['operator_name'] ?? ''), 'operator_id' => (int) ($era['operator_id'] ?? 0),
                'start' => (int) ($era['start_year'] ?? 0) ?: '', 'end' => (int) $value));
            $done = array('era_token' => (string) $r['token']);
        }
        $msg = !empty($done['conflict']) ? 'Saved, but the record says something else now: it waits in Conflicts. ' . $done['conflict']
            : (!empty($done['nothing']) ? 'Settled: ' . $pick['label'] . ' (nothing to add to the record).' : 'Settled: ' . $pick['label'] . '. It goes on ' . $n . ' with its next write-up.');
    } elseif (preg_match('/^o(\d+)\.(founded|hq|status|owner)$/', $key, $m)) {
        if (!function_exists('kop_dired_edit')) throw new RuntimeException('Companies cannot be edited here.');
        $in = array();
        if ($m[2] === 'founded') $in['founded'] = (string) (int) $value;
        if ($m[2] === 'status') $in['status'] = (string) $value;
        if ($m[2] === 'hq') {
            list($city, $st) = kop_q_split_place((string) $value);
            $in['headquartersCity'] = $city;
            if ($st !== '') $in['headquartersState'] = $st;
        }
        if ($m[2] === 'owner') {
            if ((string) $value === '') {
                $done = array('nothing' => 1);
            } else {
                $in['parentCompanies'] = (string) $value;
            }
        }
        if ($in) {
            list($text, $log) = kop_dired_edit((int) $m[1], $in, $by);
            $done = array('dired' => (int) $log);
        }
        $msg = 'Settled: ' . $pick['label'] . '.';
    } else {
        throw new RuntimeException('Unknown question.');
    }
    kop_q_set_state($key, 'settled', $q['ask'], $pick['label'], $done, $by);
    return $msg;
}

/** Take back what settling wrote; the question is asked again. */
function kop_q_undo($qkey, $by) {
    $s = kop_q_state($qkey);
    if (!$s) throw new RuntimeException('Nothing to undo.');
    $done = json_decode((string) $s['done'], true) ?: array();
    global $wpdb;
    if (!empty($done['wbf']) && function_exists('kop_wbf_rows')) {
        $rows = kop_wbf_rows(array($done['wbf']));
        if ($rows && in_array($rows[0]['status'], array('applied', 'held'), true)) kop_wbf_undo($rows, $by);
        $wpdb->delete(kop_wbf_table(), array('pkey' => $done['wbf']));
    }
    if (!empty($done['era_token']) && function_exists('kop_name_eras_undo')) kop_name_eras_undo($done['era_token']);
    if (!empty($done['dired']) && function_exists('kop_dired_undo')) kop_dired_undo((int) $done['dired'], $by);
    foreach ((array) ($done['conflicts'] ?? array()) as $mk) {
        kop_rinbox_conflicts_act($mk, 'undo', array());
    }
    if (!empty($done['conflicts'])) delete_transient('kop_q_conflicts');
    $wpdb->delete(kop_q_state_table(), array('qkey' => (string) $qkey));
    kop_q_state_changed();
    return 'Undone. The question is asked again.';
}

function kop_q_dismiss($qkey, $ask, $by) {
    kop_q_ensure_tables();
    kop_q_set_state($qkey, 'dismissed', $ask, '', array(), $by);
    return 'Dismissed. Nothing changed and the question is no longer asked.';
}

/* ---- Route -------------------------------------------------------------- */

if (function_exists('add_action')) {
    add_action('init', function () {
        add_rewrite_rule('^questions/?$', 'index.php?kop_questions=1', 'top');
        if (get_option('kop_questions_rewrite') !== KOP_Q_REWRITE_VERSION) {
            flush_rewrite_rules(false);
            update_option('kop_questions_rewrite', KOP_Q_REWRITE_VERSION);
        }
    }, 30);
    add_filter('query_vars', function ($vars) { $vars[] = 'kop_questions'; return $vars; });
    add_action('pre_get_posts', function ($query) {
        if (is_admin() || !$query->is_main_query() || (string) $query->get('kop_questions') !== '1') return;
        $query->is_home = false;
        $query->is_posts_page = false;
        $query->set('posts_per_page', 1);
        $query->set('no_found_rows', true);
    });
    add_filter('posts_pre_query', function ($posts, $query) {
        return (!is_admin() && $query->is_main_query() && (string) $query->get('kop_questions') === '1') ? array() : $posts;
    }, 10, 2);
    add_filter('pre_handle_404', function ($pre, $query) { return (string) $query->get('kop_questions') === '1' ? true : $pre; }, 10, 2);
    add_action('template_redirect', function () {
        if (!kop_q_is_page()) return;
        status_header(200);
        nocache_headers();
    }, 0);
    add_filter('template_include', function ($t) {
        if (!kop_q_is_page()) return $t;
        $own = get_stylesheet_directory() . '/templates/questions.php';
        return file_exists($own) ? $own : $t;
    }, 99);
    add_filter('body_class', function ($c) { if (kop_q_is_page()) $c[] = 'kop-questions-page'; return $c; });
    add_filter('kop_redirect_map', function ($map) {
        foreach (array('we-have-questions', 'answer-questions') as $alias) if (!isset($map[$alias])) $map[$alias] = '/questions/';
        return $map;
    });
    $title = function ($t) { return kop_q_is_page() ? 'We have questions | ' . get_bloginfo('name') : $t; };
    add_filter('pre_get_document_title', $title, 20);
    add_filter('wpseo_title', $title, 20);
    add_filter('wpseo_opengraph_title', $title, 20);
    $desc = function ($d) { return kop_q_is_page() ? kop_q_summary() : $d; };
    add_filter('wpseo_metadesc', $desc, 20);
    add_filter('wpseo_opengraph_desc', $desc, 20);
    add_filter('wpseo_canonical', function ($u) { return kop_q_is_page() ? kop_q_url() : $u; }, 20);

    add_action('wp_enqueue_scripts', function () {
        if (!kop_q_is_page()) return;
        $dir = get_stylesheet_directory();
        $uri = get_stylesheet_directory_uri();
        wp_enqueue_style('kop-questions', $uri . '/css/questions.css', array('kop-colors'), filemtime($dir . '/css/questions.css'));
        wp_enqueue_script('kop-questions', $uri . '/js/questions.js', array(), filemtime($dir . '/js/questions.js'), true);
        wp_localize_script('kop-questions', 'KOP_QUESTIONS', array(
            'rest'   => esc_url_raw(rest_url('kop/v1/questions/')),
            'hows'   => kop_q_hows(),
            'agree'  => KOP_Q_AGREE,
            'form'   => home_url('/tti-data-submission/'),
            'states' => kop_q_states(),
        ));
    }, 20);

    add_action('rest_api_init', function () {
        $open = '__return_true';
        $wrap = function (callable $fn) {
            return function (WP_REST_Request $r) use ($fn) {
                try {
                    $res = rest_ensure_response($fn($r));
                    $res->header('Cache-Control', 'no-store');
                    return $res;
                } catch (Throwable $e) {
                    return new WP_Error('kop_questions', $e->getMessage(), array('status' => 400));
                }
            };
        };
        register_rest_route('kop/v1', '/questions/deck', array('methods' => 'GET', 'permission_callback' => $open,
            'callback' => $wrap(function (WP_REST_Request $r) {
                $seen = array_slice(array_filter(explode(',', (string) $r->get_param('seen'))), 0, 400);
                return array('questions' => kop_q_deck(kop_q_state_code($r->get_param('state')), $seen));
            })));
        register_rest_route('kop/v1', '/questions/record', array('methods' => 'GET', 'permission_callback' => $open,
            'callback' => $wrap(function (WP_REST_Request $r) {
                $id = (int) $r->get_param('id');
                $qs = $r->get_param('kind') === 'operator' ? kop_q_for_company($id) : kop_q_for_facility($id);
                return array('questions' => $qs);
            })));
        register_rest_route('kop/v1', '/questions/find', array('methods' => 'GET', 'permission_callback' => $open,
            'callback' => $wrap(function (WP_REST_Request $r) {
                return array('results' => function_exists('kop_mobile_suggest') ? kop_mobile_suggest((string) $r->get_param('q'), array('facility', 'operator'), 8) : array());
            })));
        register_rest_route('kop/v1', '/questions/answer', array('methods' => 'POST', 'permission_callback' => $open,
            'callback' => $wrap(function (WP_REST_Request $r) {
                return kop_q_answer((array) $r->get_json_params() + (array) $r->get_body_params());
            })));
    });
}

function kop_q_is_page() {
    return function_exists('get_query_var') && (string) get_query_var('kop_questions') === '1';
}

function kop_q_summary() {
    return 'Were you in a troubled teen program, did you work at one, or send your child to one? Answer short questions about what our records are missing.';
}

/** State code => name, for the "Programs in" picker. */
function kop_q_states() {
    return array('AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California', 'CO' => 'Colorado',
        'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
        'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
        'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi',
        'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
        'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma',
        'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
        'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming');
}
