<?php
/**
 * The questions page's own logic (inc/questions.php), without WordPress:
 * which questions a record gets, how answers are read, when a clear majority
 * settles one, and every real record's questions from tmp/prod.sqlite
 * (keys unique, each one a key 'settle' knows how to write).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-questions.php [--db=tmp/prod.sqlite]
 */

require dirname(__DIR__) . '/inc/questions.php';

$fail = 0;
$pass = 0;
function check($name, $ok, $info = '') {
    global $fail, $pass;
    if ($ok) { $pass++; return; }
    $fail++;
    echo "FAIL $name" . ($info !== '' ? ": $info" : '') . "\n";
}
function keys_of(array $qs) { return array_column($qs, 'key'); }
function threw(callable $fn) { try { $fn(); return false; } catch (RuntimeException $e) { return true; } }

$ref = array('kind' => 'facility', 'id' => 7, 'name' => 'Test Ranch', 'url' => '', 'place' => 'Provo, UT');

// ---- An empty record gets every plain question ----
$empty = array('identification' => array('name' => 'Test Ranch', 'pastNames' => array('Old Ranch')),
    'operatingPeriod' => array('status' => 'Unknown', 'startYear' => null, 'endYear' => null),
    'location' => array('city' => '', 'state' => 'UT'), 'facilityDetails' => array('gender' => '', 'ageRange' => array('min' => null, 'max' => null)),
    'ttiPractices' => array('hasRestraint' => true), 'staff' => array('administrator' => array(array('name' => 'Jane Doe', 'role' => 'Director')),
    'notableStaff' => array(array('name' => 'John Roe', 'role' => 'Therapist (1998-2001)'))));
$qs = kop_q_facility_questions(7, $empty, array(), $ref);
$k = keys_of($qs);
foreach (array('f7.status', 'f7.opened', 'f7.city', 'f7.gender', 'f7.ages', 'f7.rules', 'f7.practice.hasLevelSystem') as $want) {
    check("empty record asks $want", in_array($want, $k, true), implode(', ', $k));
}
check('no closing year asked while the status is unknown', !in_array('f7.closed', $k, true));
check('a ticked practice is not asked', !in_array('f7.practice.hasRestraint', $k, true));
check('seven practices are asked', count(preg_grep('/\.practice\./', $k)) === count(kop_q_practices()) - 1);
check('an earlier name without years is asked', count(preg_grep('/\.renamed\./', $k)) === 1);
$staff = array_values(array_filter($qs, function ($q) { return strpos($q['key'], '.staff.') !== false; }));
check('one staff question: a role with years is not asked', count($staff) === 1 && $staff[0]['person']['name'] === 'Jane Doe');
check('staff questions never settle on their own', $staff && $staff[0]['auto'] === false && $staff[0]['followup'] === 'year');
check('the city question names the state', strpos($qs[array_search('f7.city', $k, true)]['ask'], '(UT)') !== false);

// ---- A filled record gets only what it lacks ----
$full = $empty;
$full['operatingPeriod'] = array('status' => 'Closed', 'startYear' => 1990, 'endYear' => null);
$full['location']['city'] = 'Provo';
$full['facilityDetails'] = array('gender' => 'Male', 'ageRange' => array('min' => 12, 'max' => 17));
$k = keys_of(kop_q_facility_questions(7, $full, array('old ranch' => array('end_year' => 2001)), $ref));
check('a closed record with no end year asks when it closed', in_array('f7.closed', $k, true));
foreach (array('f7.status', 'f7.opened', 'f7.city', 'f7.gender', 'f7.ages') as $gone) check("filled record does not ask $gone", !in_array($gone, $k, true));
check('an earlier name with an end year is not asked', !preg_grep('/\.renamed\./', $k));

// ---- Companies ----
$cq = keys_of(kop_q_company_questions(3, array('founded' => '', 'status' => '', 'parentCompanies' => array()), 'Acme Youth'));
check('an empty company gets four questions', $cq === array('o3.founded', 'o3.hq', 'o3.status', 'o3.owner'), implode(', ', $cq));
$cq = keys_of(kop_q_company_questions(3, array('founded' => 'about 1988', 'headquarters' => 'Provo, UT', 'status' => 'Active', 'parentCompanies' => array('Big Co')), 'Acme Youth'));
check('a full company gets none', $cq === array(), implode(', ', $cq));

// ---- Reading answers ----
$by = function ($key, $list) { foreach ($list as $q) if ($q['key'] === $key) return $q; return null; };
$qs = kop_q_facility_questions(7, $empty, array(), $ref);
$year = $by('f7.opened', $qs);
check('a year is read out of words', kop_q_normalize($year, array('value' => 'around 1998 I think'))['value'] === 1998);
check('no year is an error', threw(function () use ($year) { kop_q_normalize($year, array('value' => 'long ago')); }));
check('a year after next year is an error', threw(function () use ($year) { kop_q_normalize($year, array('value' => '2099')); }));
check('"I don\'t know" stores nothing', kop_q_normalize($year, array('value' => 'unknown')) === null && kop_q_normalize($year, array('value' => '')) === null);
$ages = $by('f7.ages', $qs);
check('ages as from-to', kop_q_normalize($ages, array('value' => '12-17'))['value'] === array('min' => 12, 'max' => 17));
check('ages out of range are an error', threw(function () use ($ages) { kop_q_normalize($ages, array('value' => '3-30')); }));
$gender = $by('f7.gender', $qs);
check('a choice keeps the record\'s word', kop_q_normalize($gender, array('value' => 'Co-ed'))['value'] === 'Co-ed');
check('a choice not offered is an error', threw(function () use ($gender) { kop_q_normalize($gender, array('value' => 'Adults')); }));
$a = kop_q_normalize($staff[0], array('value' => 'yes', 'year' => '1999'));
check('staff yes keeps the year', $a['value'] === array('yes' => true, 'year' => 1999) && $a['key'] === 'yes' && $a['label'] === 'Yes, in 1999');
$a = kop_q_normalize($staff[0], array('value' => 'yes'));
check('staff yes without a year still counts as yes', $a['key'] === 'yes' && $a['value']['year'] === null);
$rules = $by('f7.rules', $qs);
$a = kop_q_normalize($rules, array('value' => "  No talking\n at meals.  "));
check('remembered rules keep the words, each its own answer', $a['label'] === 'No talking at meals.' && strlen($a['key']) === 16);
$city = $by('f7.city', $qs);
check('town answers group whatever the case', kop_q_normalize($city, array('value' => 'provo'))['key'] === kop_q_normalize($city, array('value' => 'Provo.'))['key']);
$conflict = kop_q_make('xabc', 'choice', 'What year did Test Ranch open?', $ref,
    array('choices' => array(array('value' => '1996', 'label' => '1996'), array('value' => '1998', 'label' => '1998')), 'other' => 'A different year', 'conflict' => true));
check('a conflict\'s other year is read as a year', kop_q_normalize($conflict, array('value' => 'other', 'other' => 'in 1997'))['label'] === '1997');
check('a conflict\'s other answer must be a year', threw(function () use ($conflict) { kop_q_normalize($conflict, array('value' => 'other', 'other' => 'no idea')); }));
check('a conflict choice groups with the same typed year', kop_q_normalize($conflict, array('value' => '1998'))['key'] === kop_q_normalize($conflict, array('value' => 'other', 'other' => '1998'))['key']);
$owner = kop_q_company_questions(3, array(), 'Acme Youth')[3];
check('"nobody else owns it" is an answer', kop_q_normalize($owner, array('value' => 'other'))['key'] === 'none');

// ---- When it settles ----
$row = function ($id, $who, $key) { return array('id' => $id, 'who' => $who, 'answer_key' => $key, 'answer' => $key, 'value' => json_encode($key), 'how' => 'was_there', 'source_url' => '', 'note' => ''); };
check('three people agreeing settle it', kop_q_agreed(kop_q_tally(array($row(1, 'a', '1998'), $row(2, 'b', '1998'), $row(3, 'c', '1998'))))['key'] === '1998');
check('two people are not enough', kop_q_agreed(kop_q_tally(array($row(1, 'a', '1998'), $row(2, 'b', '1998')))) === null);
check('one other answer stops it', kop_q_agreed(kop_q_tally(array($row(1, 'a', '1998'), $row(2, 'b', '1998'), $row(3, 'c', '1998'), $row(4, 'd', '1996')))) === null);
check('one person answering three times counts once', kop_q_agreed(kop_q_tally(array($row(1, 'a', '1998'), $row(2, 'a', '1998'), $row(3, 'a', '1998')))) === null);
$t = kop_q_tally(array($row(1, 'a', '1996'), $row(2, 'a', '1998'), $row(3, 'b', '1998'), $row(4, 'c', '1998')));
check('a person\'s latest answer replaces their earlier one', count($t) === 1 && $t[0]['count'] === 3 && kop_q_agreed($t) !== null);

// ---- Words ----
check('a conflict about an opening year is asked as one', kop_q_conflict_ask('dates', 'operatingPeriod.startYear', 'X') === 'What year did X open?');
check('a conflict with no known field is asked by its kind', kop_q_conflict_ask('closure', '', 'X') === 'Is X still open, or did it close?');
check('"City, ST" splits', kop_q_split_place('Provo, UT') === array('Provo', 'UT') && kop_q_split_place('Provo') === array('Provo', ''));

// ---- Every real record ----
$db = 'tmp/prod.sqlite';
foreach ($argv as $arg) if (strpos($arg, '--db=') === 0) $db = substr($arg, 5);
$path = dirname(__DIR__) . '/' . $db;
if (!is_readable($path)) $path = $db;
if (is_readable($path)) {
    $pdo = new PDO('sqlite:' . $path);
    $settle = '/^f\d+\.(status|opened|closed|city|gender|ages|practice\.\w+|staff\.\w+|rules|renamed\.\w+)$/';
    $kinds = array();
    $records = $dupes = $bad = 0;
    foreach ($pdo->query('SELECT id, json_data FROM facilities_v2') as $r) {
        $doc = json_decode($r['json_data'], true);
        if (!is_array($doc)) continue;
        $records++;
        $qs = kop_q_facility_questions((int) $r['id'], $doc, array(), array('kind' => 'facility', 'id' => (int) $r['id'], 'name' => 'X', 'url' => '', 'place' => ''));
        $k = keys_of($qs);
        if (count($k) !== count(array_unique($k))) $dupes++;
        foreach ($qs as $q) {
            if (!preg_match($settle, $q['key']) || strlen($q['key']) > 80) $bad++;
            $kind = preg_replace('/^f\d+\.(\w+).*$/', '$1', $q['key']);
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }
    }
    check('every real record\'s questions have their own keys', $dupes === 0, "$dupes records with a repeated key");
    check('every real question is one settle knows', $bad === 0, "$bad keys");
    arsort($kinds);
    echo "Facilities: $records. Open questions by kind: " . implode(', ', array_map(function ($k, $n) { return "$k $n"; }, array_keys($kinds), $kinds)) . "\n";
    $ops = 0;
    $okinds = array();
    foreach ($pdo->query('SELECT id, name, json_data FROM wpdl_kop_operators') as $r) {
        $j = json_decode($r['json_data'], true);
        foreach (kop_q_company_questions((int) $r['id'], (array) ($j['operator'] ?? array()), (string) $r['name']) as $q) {
            $okinds[explode('.', $q['key'])[1]] = ($okinds[explode('.', $q['key'])[1]] ?? 0) + 1;
        }
        $ops++;
    }
    echo "Companies: $ops. Open questions: " . json_encode($okinds) . "\n";
} else {
    echo "(no $db: real records not checked)\n";
}

echo ($fail ? "$fail failed, " : '') . "$pass passed\n";
exit($fail ? 1 : 0);
