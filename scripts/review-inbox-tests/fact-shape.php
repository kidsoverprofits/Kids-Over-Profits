<?php
/**
 * Checks for inc/fact-shape.php (docs/claude/fact-shape.md section 10), on made-up fixtures: no mirror rows are shaped.
 * kop_rinbox_test_woodbury_facts() calls kop_test_fact_shape(); the end-to-end fold is checked in scripts/review-inbox-tests/fact-fold.php (it needs the scratch copy's $wpdb writes, which test-review-on-file.php does not have).
 */

require_once dirname(__DIR__, 2) . '/inc/woodbury-facts.php';
$GLOBALS['kop_test_options']['kop_woodbury_facts_db'] = KOP_WOODBURY_FACTS_DB_VERSION;
require_once dirname(__DIR__, 2) . '/inc/fact-shape.php';

function kop_test_fact_shape(callable $check) {
    require_once dirname(__DIR__, 2) . '/inc/news-facts.php';   // kop_nf_record_for(), kop_nf_parse_reply()
    $pdo = $GLOBALS['pdo'];
    $fid = 10371;
    $n = 0;
    // A Woodbury Facts row.
    $w = function ($op, $path, $value, array $extra = array(), array $ev = array(), $fidv = null) use (&$n, $fid) {
        return array('pkey' => 'fx' . (++$n), 'op' => $op, 'path' => $path, 'value' => json_encode($value), 'label' => is_string($value) ? $value : '',
            'extra' => json_encode($extra), 'evidence' => json_encode($ev), 'facility_id' => $fidv ?? $fid, 'program' => 'Provo Canyon School', 'status' => 'pending',
            'issue_date' => '');
    };
    $news_ev = function ($id, $date) { return array(array('cite' => 'Paper ' . $id . ', "Headline", ' . $date, 'url' => 'https://example.org/' . $id, 'news_id' => $id, 'date' => $date, 'pub' => 1)); };
    $inc = function ($line, $cat, $id, $date) use ($w, $news_ev) {
        return kop_fact_shape('wbf', $w('add_list', 'criticalIncidents.customIncidents', $line, array('via' => 'news', 'news_id' => $id, 'category' => $cat), $news_ev($id, $date)));
    };
    $note = function ($line, array $extra = array()) use ($w) {
        return kop_fact_shape('wbf', $w('add_list', 'notes', $line, $extra));
    };
    $staff = function ($name, $role) use ($w) {
        return kop_fact_shape('wbf', $w('add_staff', 'staff.notableStaff', array('name' => $name, 'role' => $role, 'pastJobs' => '')));
    };

    // 1. The Provo Canyon jaw injury told five ways.
    $a = $inc('2026-05-14: Injury: A 13-year-old resident suffered a fractured jaw and a traumatic brain injury when staff slammed him into the ground.', 'injury', 207, '2026-06-15');
    $b = $inc('2026-05: Injury: A teen suffered a jaw injury and a traumatic brain bleed in a fight at the Provo facility.', 'injury', 208, '2026-06-02');
    $c = $inc('Injury: A 13-year-old suffered a broken jaw and a brain bleed after staff slammed him onto his head on the ground.', 'injury', 209, '2026-07-17');
    $d = $inc('Injury: A 13-year-old was seriously injured in a fight and the Provo facility staff did not call 911 for his jaw injury.', 'injury', 210, '2026-06-23');
    $e = $inc('2026: Injury: A 13-year-old was beaten unconscious with life-threatening head injuries and a brain bleed.', 'injury', 211, '2026-08-04');
    $five = array($a, $b, $c, $d, $e);
    $check('fact-shape: the five tellings are incident/injury on record 10371', count(array_filter($five, function ($f) { return $f['kind'] === 'incident' && $f['type'] === 'injury' && $f['record'] === '10371'; })) === 5);
    $check('fact-shape: dates and told read (day, month, none, none, year)', array($a['date'], $b['date'], $c['date'], $d['date'], $e['date']) === array('2026-05-14', '2026-05', '', '', '2026')
        && $c['told'] === '2026-07-17' && $a['src'] === 'news' && $a['told_by'] === array('news:207'));
    $fits = kop_fact_dates_fit($a, $b) && kop_fact_dates_fit($a, $c) && kop_fact_dates_fit($a, $d) && kop_fact_dates_fit($a, $e);
    $check('fact-shape: 28458 fits the month, the undated tellings and the year', $fits);
    $by_rule = array();
    foreach ($five as $i => $x) foreach ($five as $j => $y) if ($i < $j && kop_fact_rule_same($x, $y) !== '') $by_rule[] = $i . '-' . $j . ' ' . kop_fact_rule_same($x, $y);
    $check('fact-shape: only the dated pair the rules can tell is joined by rule (28458 and 28504, same month and words)', !array_diff($by_rule, array('0-1 event')), implode(', ', $by_rule));
    $uf = array(0, 1, 2, 3, 4);
    $find = function ($i) use (&$uf, &$find) { return $uf[$i] === $i ? $i : ($uf[$i] = $find($uf[$i])); };
    foreach ($five as $i => $x) foreach ($five as $j => $y) if ($i < $j && kop_fact_close($x, $y)) $uf[$find($j)] = $find($i);
    $check('fact-shape: all five land in one AI candidate group', count(array_unique(array_map($find, array(0, 1, 2, 3, 4)))) === 1);
    $f6 = $inc('2026-07: Injury: A 13-year-old resident suffered a fractured jaw and a brain injury.', 'injury', 212, '2026-08-20');
    $check('fact-shape: a sixth telling dated 2026-07 does not fit 28458 and never joins', !kop_fact_dates_fit($a, $f6) && kop_fact_differs($a, $f6) === 'dates' && !kop_fact_close($a, $f6));
    $neg = $inc('2026-05-14: Neglect: Care for the 13-year-old with the fractured jaw was delayed an hour.', 'neglect', 207, '2026-06-15');
    $check('fact-shape: the neglect telling of the same event is another block', !array_intersect(kop_fact_blocks($a), kop_fact_blocks($neg)) && kop_fact_rule_same($a, $neg) === '');

    // 2. Two campus revocations in one month.
    $s1 = $inc('2026-07: License action: Utah regulators revoked the license of the Springville campus in July 2026.', 'license_action', 301, '2026-07-10');
    $p1 = $inc('2026-07-06: License action: Utah officials revoked the license of the Provo campus.', 'license_action', 302, '2026-07-10');
    $check('fact-shape: Springville and Provo campus revocations are kept apart (places)', kop_fact_differs($s1, $p1) === 'places' && kop_fact_rule_same($s1, $p1) === '' && !kop_fact_close($s1, $p1));

    // 3. A closure three ways.
    $c1 = kop_fact_shape('wbf', $w('set_closed', 'operatingPeriod.status', array('endYear' => 2017), array(), array(), $fid));
    $row = array('pkey' => 'fl1', 'topic_id' => 5, 'kind' => 'lead', 'facility_id' => $fid, 'post_n' => 3, 'post_date' => '2005-03-04', 'author' => 'Sam',
        'value' => json_encode(array('type' => 'closure', 'year' => '2017', 'summary' => 'The school closed in 2017.', 'url' => '')));
    $c2 = kop_fact_shape('fornits', $row);
    $c3 = kop_fact_shape('doc_line', array('path' => 'operatingPeriod.notes', 'line' => 'Closed in 2017 (sets status Closed, Woodbury Reports, May 2009, p. 12)', 'i' => 0), array('fid' => $fid));
    $check('fact-shape: three closure fields are closure facts of 2017', $c1['kind'] === 'closure' && $c2['kind'] === 'closure' && $c3['kind'] === 'closure'
        && $c1['date'] === '2017' && $c2['date'] === '2017' && $c3['date'] === '2017');
    $check('fact-shape: the three closures are one fact', kop_fact_rule_same($c1, $c2) !== '' && kop_fact_rule_same($c1, $c3) !== '' && kop_fact_rule_same($c2, $c3) !== ''
        && array_intersect(kop_fact_blocks($c1), kop_fact_blocks($c3)));
    $row['value'] = json_encode(array('type' => 'closure', 'year' => '2018', 'summary' => 'The school closed in 2018.', 'url' => ''));
    $c2b = kop_fact_shape('fornits', $row);
    $check('fact-shape: closures of 2017 and 2018 differ', kop_fact_differs($c1, $c2b) === 'dates' && kop_fact_rule_same($c1, $c2b) === '');
    $on = kop_fact_onhand(array('operatingPeriod' => array('status' => 'Closed', 'endYear' => 2017)), $fid);
    $check('fact-shape: a waiting 2018 closure against a record closed in 2017 is a conflict (differs)', $on && $on[0]['kind'] === 'closure' && kop_fact_differs($c2b, $on[0]) === 'dates' && kop_fact_rule_same($c1, $on[0]) !== '');

    // 4. Founder in a note and a staff entry.
    $fn = $note('Founded by: Arthur Robert Barker (HEAL, staff list for Example Academy (archived 2012-05-17))');
    $st = $staff('Art Barker', 'Founder');
    $check('fact-shape: "Founded by" is a founder fact with its person', $fn['kind'] === 'founder' && $fn['type'] === 'person' && $fn['keys']['persons'][0][0] === 'Arthur Robert Barker');
    $check('fact-shape: the founder note and the staff entry "Founder" join', kop_fact_rule_same($fn, $st) !== '' && array_intersect(kop_fact_blocks($fn), kop_fact_blocks($st)));
    $st2 = $staff('Art Barker', 'Teacher');
    $check('fact-shape: a staff entry that does not say founder does not join the note', kop_fact_rule_same($fn, $st2) === '');
    $since = $note('Founded by: in business since 2006');
    $check('fact-shape: "in business since 2006" has no person, no date and joins nothing', $since['kind'] === 'founder' && !$since['keys']['persons'] && !$since['keys']['org']
        && $since['date'] === '' && kop_fact_blocks($since) === array() && kop_fact_rule_same($since, $fn) === '');

    // 5. A name in a note and in pastNames.
    $nn = $note('Note: also known as Benchmark Transitions (HEAL, staff list for X (archived 2012))');
    $pn = kop_fact_shape('wbf', $w('add_list', 'identification.pastNames', 'Benchmark Transitions'));
    $check('fact-shape: the "also known as" note is a name fact', $nn['kind'] === 'name' && $nn['keys']['name'] === array('benchmark transitions'));
    $check('fact-shape: the note name and the pastNames name are one fact', kop_fact_rule_same($nn, $pn) === 'name key');

    // 6. Owner and operator: one company is one fact (owner decision 2026-11-02).
    $ow = $note('Owner: Universal Health Services is the parent company');
    $op = $note('Operator: Universal Health Services');
    $check('fact-shape: Owner and Operator naming one company fold by rule', $ow['kind'] === 'owner' && $op['kind'] === 'operator' && kop_fact_rule_same($ow, $op) === 'same company');
    $other = $note('Operator: Acadia Healthcare');
    $check('fact-shape: another company does not', kop_fact_rule_same($ow, $other) === '');

    // 7. Numbers.
    $cap1 = $note('Capacity: 22 (as of 2010)');
    $cap2 = $note('Capacity: 32');
    $check('fact-shape: capacity 22 (as of 2010) and 32 differ', $cap1['date'] === '2010' && kop_fact_differs($cap1, $cap2) === 'numbers' && kop_fact_rule_same($cap1, $cap2) === '');
    $ag = $note('Ages: 12-17');
    $ag2 = kop_fact_shape('wbf', $w('set_if_empty', 'facilityDetails.ageRange', array('min' => 12, 'max' => 17)));
    $check('fact-shape: "Ages: 12-17" and ageRange 12 to 17 are one fact', $ag['kind'] === 'ages' && kop_fact_rule_same($ag, $ag2) === 'ages');

    // 8. Suffixes and nicknames.
    $js = $staff('John Smith Jr.', 'Director');
    $jp = $staff('John Smith', 'Director');
    $check('fact-shape: John Smith Jr. and John Smith never join', kop_fact_differs($js, $jp) === 'suffix' && kop_fact_rule_same($js, $jp) === '');
    $check('fact-shape: Jim Omer and James Omer join', kop_fact_rule_same($staff('Jim Omer', 'Owner'), $staff('James Omer', 'Director')) !== ''
        && array_intersect(kop_fact_blocks($staff('Jim Omer', 'Owner')), kop_fact_blocks($staff('James Omer', 'Director'))));

    // 9. The note label beats keywords inside the note.
    $lf = $note('Note: lowered fees on transports for families relocated due to a school closure');
    $check('fact-shape: a "Note:" about a school closure stays a note', $lf['kind'] === 'note' && kop_fact_note_kind('Note: lowered fees ... due to a school closure')[0] === 'note');
    $kinds = array('Operator: X' => 'operator', 'Ages: 12-17' => 'ages', 'Cost: $5,400 per month' => 'cost', 'Campus: 12 Main Street, Provo' => 'place',
        'Accreditation: NATSAP 2010' => 'membership', 'Name change: A -> B' => 'name', 'Practice: x' => 'practice', 'Lead: x' => 'note');
    $bad = array();
    foreach ($kinds as $line => $kind) if (kop_fact_note_kind($line)[0] !== $kind) $bad[] = $line;
    $check('fact-shape: the label map', !$bad, implode('; ', $bad));

    // 10. Dates.
    $check('fact-shape: dates read (ISO, Reported, month, month day, slash, decade)', kop_fact_date('2026-05-14: Injury: x') === '2026-05-14'
        && kop_fact_date('Reported 2007: Death: x') === '' && kop_fact_lead('Reported 2007: Death: x')[1] === '2007'
        && kop_fact_date('June 2026: sanctions') === '2026-06' && kop_fact_date('July 17, 2026: sanctions') === '2026-07-17'
        && kop_fact_date('12/6/1869: born') === '1869-12-06' && kop_fact_date('Opened: 1970s (HEAL)') === '' && kop_fact_date('Opened: 1976 per HEAL') === '1976');

    // 11. The exact-name rule, on a made-up index.
    kop_fact_need('kop_autoconn_key', 'inc/auto-connect.php');
    $key = 'kop_autoconn_key';
    $index = array($key('Zephyrus Ranch') => array(11 => true), $key('Quillon Academy') => array(21 => true, 22 => true), $key('Brannock House') => array(31 => true, 32 => true),
        $key('Youth Care') => array(41 => true), $key('Child and Family Services') => array(51 => true));
    $r = 'kop_autoconn_exact_record';
    $check('fact-shape: one holder of the exact name links', $r($pdo, 'The Zephyrus Ranch, Inc.', array(), $index) === array(11, 'exact'));
    $check('fact-shape: two holders and an article linked to one links by the article', $r($pdo, 'Quillon Academy', array(22, 99), $index) === array(22, 'article'));
    $check('fact-shape: two holders and no article link is ambiguous', $r($pdo, 'Brannock House', array(99), $index) === array(0, 'ambiguous'));
    $check('fact-shape: a generic name is generic, an unknown one none', $r($pdo, 'Youth Care', array(), $index) === array(0, 'generic') && $r($pdo, 'Nowhere Place', array(), $index) === array(0, 'none'));
    $agency = array('news_id' => 1, 'subject_kind' => 'agency', 'subject_name' => 'Zephyrus Ranch', 'facility_id' => null, 'category' => 'lawsuit', 'summary' => 'x');
    $check('fact-shape: an agency subject never links by name and is not a record fact', kop_nf_record_for($pdo, $agency) === array(0, 'agency') && kop_fact_shape('news', $agency) === null
        && kop_nf_record_for($pdo, array('subject_kind' => 'person', 'subject_name' => 'X') + $agency)[1] === 'person');
    // kop_nf_parse_reply(): an empty facilityId whose subject is a linked record's past name gets that id (a real record of the scratch copy).
    $found = null;
    foreach ($pdo->query('SELECT id, json_data FROM facilities_v2 LIMIT 6000') as $rec) {
        $d = json_decode((string) $rec['json_data'], true);
        foreach ((array) ($d['identification']['pastNames'] ?? array()) as $pname) {
            if (is_string($pname) && kop_autoconn_names_only($pdo, $pname, (int) $rec['id'])) { $found = array((int) $rec['id'], $pname); break 2; }
        }
    }
    if ($found) {
        $linked = array(array('id' => $found[0], 'unique_name' => 'Current Name', 'city' => 'X', 'state' => 'UT', 'status' => 'Open'));
        $reply = json_encode(array('facts' => array(
            array('category' => 'staff', 'subject' => $found[1], 'subjectKind' => 'facility', 'facilityId' => null, 'person' => 'Jane Roe', 'role' => 'Director', 'summary' => 'Jane Roe is director.', 'quote' => 'q'),
            array('category' => 'staff', 'subject' => $found[1], 'subjectKind' => 'agency', 'facilityId' => null, 'person' => 'Joe Roe', 'role' => 'Director', 'summary' => 'Joe Roe is director.', 'quote' => 'q'),
        )));
        $facts = kop_nf_parse_reply($reply, $linked, $pdo);
        $check('fact-shape: the news reader takes a linked record\'s past name as that record', $facts[0]['facility_id'] === $found[0] && $facts[1]['facility_id'] === 0, $found[1]);
        $check('fact-shape: without a linked article the name alone links nothing', kop_nf_parse_reply($reply, array(), $pdo)[0]['facility_id'] === 0);
    } else {
        $check('fact-shape: (no unique past name in the copy; reader fix not exercised)', true);
    }

    // 12. On hand: a fact about another record is not on hand here.
    $mine = array('id' => 1, 'news_id' => 5, 'category' => 'death', 'subject_kind' => 'facility', 'facility_id' => 4, 'summary' => 'A boy died at the school.', 'event_date' => '2026-01-02');
    $theirs = array('id' => 2, 'news_id' => 5, 'category' => 'death', 'subject_kind' => 'facility', 'facility_id' => 7, 'summary' => 'A girl died at Maple Lake.', 'event_date' => '2026-01-03');
    $oh = kop_fact_onhand(array('identification' => array('name' => 'A')), 4, array(), array($mine, $theirs));
    $check('fact-shape: a kept fact about record B is not on hand for record A', count($oh) === 1 && $oh[0]['state'] === 'kept' && $oh[0]['record'] === '4');

    // 13. Testimony never forms a group.
    $t = function ($pkey) { return kop_fact_shape('fornits', array('pkey' => $pkey, 'topic_id' => 9, 'kind' => 'testimony', 'facility_id' => 10371, 'post_n' => 1, 'post_date' => '2005-01-01',
        'author' => 'x', 'value' => json_encode(array('who' => 'A', 'years' => '', 'summary' => 'They locked me in a room for days on end without food.')))); };
    $t1 = $t('t1');
    $t2 = $t('t2');
    $check('fact-shape: two identical testimonies never group', kop_fact_blocks($t1) === array() && kop_fact_rule_same($t1, $t2) === '' && kop_fact_differs($t1, $t2) === 'testimony' && !kop_fact_close($t1, $t2));

    // The moved lines are the lines they were.
    $fr = array('pkey' => 'fi', 'topic_id' => 5, 'kind' => 'incident', 'facility_id' => 1, 'post_n' => 3, 'post_date' => '2005-03-04', 'author' => 'Sam',
        'value' => json_encode(array('category' => 'abuse', 'year' => '', 'summary' => 'Kids were hit.')));
    $check('fact-shape: kop_fornits_incident_line() keeps the line kop_fornits_doc_apply() wrote',
        kop_fornits_incident_line($fr) === 'Reported ' . kop_fornits_month('2005-03-04') . ': Abuse: Kids were hit. (' . kop_fornits_cite($fr) . ')');
    $fl = array('kind' => 'lead', 'value' => json_encode(array('type' => 'news', 'year' => '', 'summary' => 'An article.', 'url' => 'https://example.org/a'))) + $fr;
    $check('fact-shape: kop_fornits_lead_line() keeps the lead line', kop_fornits_lead_line($fl) === 'Fornits lead (news): An article. https://example.org/a (' . kop_fornits_cite($fl) . ')');
    $doc = array('staff' => array('notableStaff' => array(array('name' => 'Dana Quinn', 'role' => 'Co-Founder'))));
    $check('fact-shape: kop_wbf_person_role_on_record() reads a founder and an owner',
        kop_wbf_person_role_on_record($doc, 'Dana Quinn', 'founder') && kop_wbf_person_role_on_record($doc, 'Dana Quinn', 'owner') && !kop_wbf_person_role_on_record($doc, 'Pat Lee', 'founder'));
}
