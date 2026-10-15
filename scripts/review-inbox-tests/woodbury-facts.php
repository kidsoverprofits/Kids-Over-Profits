<?php
/**
 * scripts/test-review-inbox.php checks for the 'woodbury-facts' source
 * (inc/review-inbox/woodbury-facts.php over inc/woodbury-facts.php), on the
 * mirror's real proposals. The mirror's copy of the table predates the young
 * adult columns, so they are added here and one program with no record is
 * put on the young adult tab. Adding to a facility record, and Undo (which
 * opens the facility store first), need MySQL and are not run.
 */

require_once dirname(__DIR__, 2) . '/inc/closure-reports.php';
require_once dirname(__DIR__, 2) . '/inc/woodbury-facts.php';
$GLOBALS['kop_test_options']['kop_woodbury_facts_db'] = KOP_WOODBURY_FACTS_DB_VERSION;
$GLOBALS['kop_test_options']['kop_closure_reports_db'] = KOP_CLOSURE_REPORTS_DB_VERSION;

if (!function_exists('sanitize_key')) {
    function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
}

(function () {
    $pdo = $GLOBALS['pdo'];
    $t = 'wpdl_kop_woodbury_facts';
    if (!$pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn()) return;
    $cols = array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('ya', $cols, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN ya INTEGER NOT NULL DEFAULT 0");
    if (!in_array('ya_why', $cols, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN ya_why TEXT NULL");
    $program = $pdo->query("SELECT program FROM $t WHERE status = 'pending' AND facility_id = 0 AND grp <> 'consultant' ORDER BY program DESC LIMIT 1")->fetchColumn();
    if ($program !== false && !(int) $pdo->query("SELECT COUNT(*) FROM $t WHERE ya = 1")->fetchColumn()) {
        $pdo->prepare("UPDATE $t SET ya = 1, ya_why = 'Ages: 18-25' WHERE program = ? AND facility_id = 0 AND status = 'pending'")->execute(array($program));
    }
})();

function kop_rinbox_test_woodbury_facts(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $t = 'wpdl_kop_woodbury_facts';
    $row = function ($k) { return kop_wbf_rows(array($k))[0] ?? null; };
    $pick = function ($sql) use ($pdo) { return (string) $pdo->query($sql)->fetchColumn(); };

    // A staff item: the List choice is the category; a correction keeps the build's version for Reset.
    $staff = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND op = 'add_staff' AND path = 'staff.notableStaff' AND facility_id > 0 ORDER BY id LIMIT 1");
    $before = $row($staff);
    $it = kop_rinbox_get_item('woodbury-facts', $staff);
    $cat = array_values(array_filter($it['fields'], function ($f) { return !empty($f['category']); }));
    $check('woodbury-facts: a staff item\'s list is its category field', count($cat) === 1 && $cat[0]['name'] === 'where' && isset($cat[0]['options']['staff.administrator']));
    $check('woodbury-facts: a matched item adds to its record', $it['actions'][0]['id'] === 'apply' && (int) $it['actions'][0]['params'][0]['value'] === (int) $before['facility_id']);
    $v0 = kop_wbf_row_value($before);
    call_user_func($src['save'], $staff, array('where' => 'staff.administrator'));
    $r = $row($staff);
    $v = kop_wbf_row_value($r);
    $check('woodbury-facts: changing only the list keeps the name and role', $r['path'] === 'staff.administrator' && $v['name'] === $v0['name'] && $v['role'] === $v0['role'], $r['label']);
    call_user_func($src['save'], $staff, array('role' => 'Clinical Director (test)'));
    $r = $row($staff);
    $check('woodbury-facts: save corrects the role and relabels', kop_wbf_row_value($r)['role'] === 'Clinical Director (test)' && strpos($r['label'], 'Clinical Director (test)') !== false, $r['label']);
    $it = kop_rinbox_get_item('woodbury-facts', $staff);
    $check('woodbury-facts: a corrected item offers Reset', in_array('reset', array_column($it['actions'], 'id'), true));
    call_user_func($src['act'], $staff, 'reset', array());
    $r = $row($staff);
    $check('woodbury-facts: Reset puts back what Woodbury said', $r['path'] === $before['path'] && $r['label'] === $before['label'] && $r['value'] === $before['value']);
    try {
        call_user_func($src['save'], $staff, array('name' => ''));
        $check('woodbury-facts: an empty name is refused', false);
    } catch (RuntimeException $e) {
        $check('woodbury-facts: an empty name is refused', true, $e->getMessage());
    }
    $year = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND op = 'set_if_empty' AND path = 'operatingPeriod.startYear' ORDER BY id LIMIT 1");
    if ($year !== '') {
        try {
            call_user_func($src['save'], $year, array('year' => 'nineteen'));
            $check('woodbury-facts: a start year that is not a year is refused', false);
        } catch (RuntimeException $e) {
            $check('woodbury-facts: a start year that is not a year is refused', true, $e->getMessage());
        }
    }

    // No record: add needs a record; create and young adult are offered.
    $none = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND facility_id = 0 AND grp <> 'consultant' AND ya = 0 ORDER BY id LIMIT 1");
    $it = kop_rinbox_get_item('woodbury-facts', $none);
    $ids = array_column($it['actions'], 'id');
    $check('woodbury-facts: a program with no record can be pointed at one, created, or moved to young adult', array_values(array_diff($ids, array('add_person'))) === array('apply', 'create', 'ya_on', 'reject'), implode(',', $ids));
    try {
        call_user_func($src['act'], $none, 'apply', array('facility' => ''));
        $check('woodbury-facts: adding with no record picked is refused', false);
    } catch (RuntimeException $e) {
        $check('woodbury-facts: adding with no record picked is refused', true, $e->getMessage());
    }
    $ya = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND ya = 1 ORDER BY id LIMIT 1");
    $it = kop_rinbox_get_item('woodbury-facts', $ya);
    $check('woodbury-facts: a young adult item is created or filed as one, never a facility', in_array('ya_create', array_column($it['actions'], 'id'), true)
        && !in_array('apply', array_column($it['actions'], 'id'), true));

    // Reject; Undo needs the facility store (MySQL), so it is put back by hand here.
    $m = call_user_func($src['act'], $staff, 'reject', array());
    $check('woodbury-facts: reject', $row($staff)['status'] === 'rejected', $m['message']);
    $it = kop_rinbox_get_item('woodbury-facts', $staff);
    $check('woodbury-facts: a rejected item offers Back to review and no edits', $it['actions'][0]['id'] === 'undo' && !empty($it['fields'][0]['readonly']));
    try {
        call_user_func($src['save'], $staff, array('role' => 'x'));
        $check('woodbury-facts: a rejected item cannot be edited', false);
    } catch (RuntimeException $e) {
        $check('woodbury-facts: a rejected item cannot be edited', true, $e->getMessage());
    }
    try {
        $m = call_user_func($src['act'], $staff, 'undo', array());
        $check('woodbury-facts: back to review', $row($staff)['status'] === 'pending', $m['message']);
    } catch (RuntimeException $e) {
        echo '  (Undo not run offline: ' . $e->getMessage() . ")\n";
    }
    $pdo->prepare("UPDATE $t SET status = ?, applied = ?, reviewed_by = ?, reviewed_at = ?, extra = ?, auto = ? WHERE pkey = ?")
        ->execute(array($before['status'], $before['applied'], $before['reviewed_by'], $before['reviewed_at'], $before['extra'], $before['auto'], $staff));
    $applied = $pick("SELECT pkey FROM $t WHERE status = 'applied' AND applied_fid > 0 ORDER BY id LIMIT 1");
    if ($applied !== '') {
        $it = kop_rinbox_get_item('woodbury-facts', $applied);
        $check('woodbury-facts: an added item offers Undo', kop_rinbox_test_own_actions($it) === array('undo') && $it['facility']['id'] > 0);
    }

    // What the old screen also did.
    $res = call_user_func($src['list'], array('view' => 'records', 'search' => '', 'offset' => 0, 'limit' => 25, 'filters' => array('grp' => 'incident')));
    $grps = array_unique(array_map(function ($it) { return kop_wbf_rows(array($it['key']))[0]['grp']; }, $res['items']));
    $check('woodbury-facts: the kind filter shows only that kind', !$res['items'] || $grps === array('incident'), $res['total'] . ' incidents');
    $counts = call_user_func($src['view_counts'], array('search' => '', 'filters' => array()));
    // Items the record holds already wait under "Already on file", out of every waiting tab.
    $on_file = kop_rinbox_on_file_keys('woodbury-facts');
    $all_pending = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending'")->fetchColumn();
    $check('woodbury-facts: every tab has its count', count($counts) === 8 && $counts['pending'] + count($on_file) + count(kop_rinbox_conflict_keys('woodbury-facts')) === $all_pending,
        json_encode($counts) . ' + ' . count($on_file) . ' on file + ' . count(kop_rinbox_conflict_keys('woodbury-facts')) . ' in conflict');
    $keys = array_keys($on_file);
    $states = $keys ? $pdo->query("SELECT DISTINCT status FROM $t WHERE pkey IN ('" . implode("','", array_slice($keys, 0, 500)) . "')")->fetchAll(PDO::FETCH_COLUMN) : array();
    $check('woodbury-facts: items the record already holds are found, all waiting ones', $on_file && $states === array('pending'), count($on_file) . ' of ' . $all_pending . ', e.g. ' . json_encode(array_slice($on_file, 0, 3)));
    $page = call_user_func($src['list'], array('view' => 'records', 'search' => '', 'offset' => 0, 'limit' => 100, 'filters' => array()));
    $check('woodbury-facts: they are out of the waiting tabs', !array_intersect(array_column($page['items'], 'key'), $keys)
        && $page['total'] === $counts['records']);
    // Conflicts are always marked: what the record says that disagrees, never ticked, and Add keeps them waiting.
    $doc = array('facilityDetails' => array('type' => 'Wilderness Therapy'), 'operatingPeriod' => array('status' => 'Open', 'endYear' => null),
        'identification' => array('name' => 'Roe Ranch'),
        'staff' => array('administrator' => array(array('name' => 'Jane Q. Roe', 'role' => 'Executive Director (2001)', 'pastJobs' => 'Teacher - Elm School (1995)'),
            array('name' => 'Sam Poe', 'role' => 'Therapist', 'pastJobs' => ''))));
    $row = function ($op, $path, $value) { return array('op' => $op, 'path' => $path, 'value' => json_encode($value), 'label' => '', 'evidence' => '[]', 'issue_date' => '', 'pkey' => 'x', 'extra' => ''); };
    // Another role for someone listed with no work history is history, not a conflict: Add writes it into their past jobs, Undo takes it back.
    $poe = $row('add_staff', 'staff.notableStaff', array('name' => 'Sam Poe', 'role' => 'Founder (2021, HEAL)', 'pastJobs' => 'Intake Coordinator - Pathway (2019)')) + array('pkey' => 'poe');
    $d2 = $doc;
    $done = kop_wbf_doc_apply($d2, $poe);
    $after = $d2['staff']['administrator'][1];
    kop_wbf_doc_undo($d2, $done);
    $check('woodbury-facts: another role on an entry with no work history goes into its past jobs, no conflict, exact Undo',
        kop_wbf_conflict($doc, $poe) === '' && kop_wbf_on_record($doc, $poe) === ''
        && $after['role'] === 'Therapist' && $after['pastJobs'] === 'Founder - Roe Ranch (2021); Intake Coordinator - Pathway (2019)'
        && $d2['staff']['administrator'][1]['pastJobs'] === '' && $d2['staff']['administrator'][1]['role'] === 'Therapist', json_encode($after));
    $check('woodbury-facts: conflict rules (other value, open record) and none for the same thing',
        kop_wbf_conflict($doc, $row('set_if_empty', 'facilityDetails.type', 'Boot Camp')) === 'The record has Wilderness Therapy; this says Boot Camp.'
        && kop_wbf_conflict($doc, $row('set_if_empty', 'facilityDetails.type', 'wilderness therapy')) === ''
        && kop_wbf_conflict($doc, $row('set_if_empty', 'facilityDetails.capacity', 40)) === ''
        && strpos(kop_wbf_conflict($doc, $row('set_closed', 'operatingPeriod.status', array('endYear' => 2009))), 'The record says it is open') === 0
        && kop_wbf_conflict($doc, $row('add_staff', 'staff.notableStaff', array('name' => 'Jane Roe', 'role' => 'executive director'))) === '');
    // Not conflicts: a type holding the other, a typo, a sentence where the type belongs, another staff role (it joins the history).
    $types = function ($have, $says) use ($row) {
        $d = array('facilityDetails' => array('type' => $have));
        return kop_wbf_conflict($d, $row('set_if_empty', 'facilityDetails.type', $says));
    };
    $jane = $row('add_staff', 'staff.notableStaff', array('name' => 'Dr. Jane Roe', 'role' => 'Admissions (2005, Woodbury Reports)', 'pastJobs' => 'Teacher - Elm School (1995)'))
        + array('pkey' => 'jane');
    $d3 = $doc;
    kop_wbf_doc_apply($d3, $jane);
    $junk = $doc;
    $junk['staff']['administrator'][1]['role'] = 'one';
    $junk['facilityDetails']['type'] = 'If you attended this program and would like to contribute information, please write to us.';
    $d4 = $junk;
    $done4 = kop_wbf_doc_apply($d4, $poe);
    $d5 = $junk;
    $done5 = kop_wbf_doc_apply($d5, $row('set_if_empty', 'facilityDetails.type', 'Therapeutic Boarding School') + array('pkey' => 't'));
    $roles_back = $d4;
    kop_wbf_doc_undo($roles_back, $done4);
    $check('woodbury-facts: not conflicts: a type that holds the other or a typo of it, a placeholder sentence as the type, another staff role',
        $types('Christian Therapeutic Boarding School', 'Therapeutic Boarding School') === '' && $types('Therapeutic Boarding/Day School', 'Therapeutic Boarding School') === ''
        && $types('Therpeutic Boarding School', 'Therapeutic Boarding School') === '' && $types('Residential Treatment Center', 'Wilderness Therapy') !== ''
        && kop_wbf_conflict($junk, $row('set_if_empty', 'facilityDetails.type', 'Therapeutic Boarding School')) === ''
        && kop_wbf_conflict($doc, $jane) === '' && kop_wbf_on_record($doc, $jane) === ''
        && $d3['staff']['administrator'][0]['role'] === 'Executive Director (2001)'
        && $d3['staff']['administrator'][0]['pastJobs'] === 'Teacher - Elm School (1995); Admissions - Roe Ranch (2005)'
        && kop_wbf_on_record($d3, $jane) !== ''
        && $d4['staff']['administrator'][1]['role'] === 'Founder (2021, HEAL)' && $roles_back['staff']['administrator'][1]['role'] === 'one'
        && $d5['facilityDetails']['type'] === 'Therapeutic Boarding School',
        json_encode(array($d3['staff']['administrator'][0], $d4['staff']['administrator'][1])));
    // A note never conflicts, whatever the build noted ("Another issue gives 1989; this one is kept as a note").
    $check('woodbury-facts: a dated note is not a conflict', !kop_rinbox_wbf_built_conflict(array('op' => 'add_list', 'path' => 'notes',
        'conflict' => 'Another issue gives 1989; this one is kept as a note.', 'current_val' => '', 'value' => '"Start year: 1977"'), $doc));
    // The same information in other words is on the record already: Add refuses it, the hourly pass files it, Undo brings it back.
    $rec = array('identification' => array('name' => 'Evoke Entrada', 'pastNames' => array('Second Nature Entrada'), 'currentOperator' => 'Universal Health Services (UHS)'),
        'facilityDetails' => array('ageRange' => array('min' => 12, 'max' => 17), 'gender' => 'Male', 'capacity' => 40),
        'location' => array('street' => '1329 Browns Ferry Rd', 'raw' => '1329 Browns Ferry Rd, Madison, AL 35758'),
        'memberships' => array('NATSAP (former member; member from 2006, last listed 2020-21)'),
        'notes' => array('Cost: $5,400 per month plus $6,000 enrollment deposit (r/troubledteens wiki, page "Academy at Sisters" (as of 2025-12-18))'),
        'staff' => array('administrator' => array(array('name' => 'John Santa', 'role' => 'Co-Founder', 'pastJobs' => ''),
            array('name' => 'Matt Hendry', 'role' => 'Clinical Director', 'pastJobs' => 'a Teacher at the reportedly abusive [Cherokee Creek Boys School](https://www'),
            array('name' => 'Dace Goulding', 'role' => 'Operations Manager', 'pastJobs' => ''))));
    $covers = function ($note) use ($rec) { return kop_wbf_list_covers($rec, 'notes', $note . ' (HEAL, staff list (archived 2016))') !== ''; };
    $check('woodbury-facts: same information in other words is on the record; anything more is not',
        $covers('Ages: boys 12-17') && $covers('Ages: youth, ages 12 to 17 at admission') && !$covers('Ages: boys 12-17; ranch-style boarding school') && !$covers('Ages: girls 12-17')
        && $covers('Capacity: 40 boys') && !$covers('Capacity: 45')
        && $covers('Founded by: John Santa (Co-Founder)') && !$covers('Founded by: Dace Goulding')
        && $covers('Operator: UHS, Inc.') && !$covers('Operator: UHS (parent CRC Health Group, owned by Bain Capital)')
        && $covers('Name change: Second Nature Entrada renamed Evoke Entrada') && !$covers('Name change: reopened as Evoke Entrada after the 1990 closure')
        && $covers('Address: 1329 Brownsferry Road, Madison') && !$covers('Address: 1330 Browns Ferry Rd, Madison')
        && $covers('Membership: NATSAP member since 2006') && !$covers('Membership: NATSAP since 2020') && !$covers('Membership: NATSAP (executive director sits on its board)')
        && $covers('Cost: $5,400 per month tuition, plus a $6,000 deposit') && !$covers('Cost: $5,400 per month plus a $7,000 deposit'));
    $hendry = function ($jobs) use ($row) { return $row('add_staff', 'staff.notableStaff', array('name' => 'Matthew Hendry', 'role' => 'Clinical Director (2003, r/troubledteens wiki)', 'pastJobs' => $jobs)); };
    $check('woodbury-facts: a nickname is the same person and a past job its entry names is no news; a new one is',
        kop_wbf_on_record($rec, $hendry('Teacher - Cherokee Creek Boys School (2008-2020)')) !== ''
        && kop_wbf_on_record($rec, $hendry('Staff - Cherokee Creek Boys School (before 2025)')) !== ''
        && kop_wbf_on_record($rec, $hendry('Field Instructor - Cherokee Creek Boys School (2006)')) === ''
        && kop_wbf_on_record($rec, $hendry('Teacher - Ironwood School and RTC (2021)')) === '');
    $refused = '';
    try { $tmp = $rec; kop_wbf_doc_apply($tmp, $row('add_list', 'notes', 'Ages: boys 12-17 (HEAL)')); } catch (RuntimeException $e) { $refused = $e->getMessage(); }
    $check('woodbury-facts: Add refuses it as already on the record', strpos($refused, 'Already on the record') === 0, $refused);
    // On the scratch copy, rolled back after: the later checks see the queue as it was.
    $pdo->beginTransaction();
    try {
        kop_rinbox_test_with_wpdb_writes(function () use ($pdo, $t, $check) {
            $dry = kop_wbf_clear_on_record(false, 300);
            $before = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending'")->fetchColumn();
            $wet = kop_wbf_clear_on_record(true, 300);
            $cleared = $pdo->query("SELECT * FROM $t WHERE status = 'rejected' AND applied LIKE '%\"cleared\":\"on_record\"%'")->fetchAll(PDO::FETCH_ASSOC);
            $check('woodbury-facts: the pass files what adds nothing, counted first, nothing else',
                $dry['cleared'] > 0 && $dry['done'] && $wet['cleared'] === $dry['cleared'] && count($cleared) === $wet['cleared']
                && (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending'")->fetchColumn() === $before - $wet['cleared'],
                $wet['cleared'] . ' of ' . $wet['checked'] . ' ' . json_encode($wet['by_kind']) . ', e.g. ' . json_encode(array_slice($wet['examples'], 0, 3)));
            if (!$cleared) return;
            kop_wbf_undo(array($cleared[0]), 'tester');
            $again = kop_wbf_clear_on_record(true, 300);
            $back = $pdo->query("SELECT status FROM $t WHERE pkey = " . $pdo->quote($cleared[0]['pkey']))->fetchColumn();
            $check('woodbury-facts: Undo brings one back and the pass leaves it for the person', $back === 'pending' && $again['cleared'] === 0, $back . ' / ' . $again['cleared']);
        });
    } finally {
        $pdo->rollBack();
    }
    // Reject takes the same fact from the other sources with it; its Undo brings them all back.
    $twins = $pdo->query("SELECT facility_id, op, path FROM $t WHERE status = 'pending' AND facility_id > 0 AND op = 'set_if_empty'
        GROUP BY facility_id, op, path, value HAVING COUNT(*) > 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($twins) {
        $st = $pdo->prepare("SELECT pkey FROM $t WHERE status = 'pending' AND facility_id = ? AND op = ? AND path = ? ORDER BY id");
        $st->execute(array($twins['facility_id'], $twins['op'], $twins['path']));
        $first = $st->fetchColumn();
        $same = kop_rinbox_wbf_same_fact(kop_wbf_rows(array($first))[0]);
        call_user_func(kop_rinbox_source('woodbury-facts')['act'], $first, 'reject', array());
        $gone = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'rejected' AND pkey IN ('" . implode("','", array_column($same, 'pkey')) . "')")->fetchColumn();
        call_user_func(kop_rinbox_source('woodbury-facts')['act'], $first, 'undo', array());
        $back = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending' AND pkey IN ('" . implode("','", array_merge(array($first), array_column($same, 'pkey'))) . "')")->fetchColumn();
        $check('woodbury-facts: Reject takes the same fact from the other sources with it, Undo brings them all back',
            $same && $gone === count($same) && $back === count($same) + 1, count($same) . ' twins, ' . $gone . ' rejected, ' . $back . ' back');
    }
    // A real waiting item whose record now holds another value.
    $live = null;
    foreach ($pdo->query("SELECT * FROM $t WHERE status = 'pending' AND op = 'set_if_empty' AND facility_id > 0 ORDER BY id LIMIT 4000")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $f = kop_on_file_doc($r['facility_id']);
        if ($f && kop_wbf_conflict($f['doc'], $r) !== '') { $live = $r; break; }
    }
    if ($live) {
        $card = kop_rinbox_get_item('woodbury-facts', $live['pkey']);
        $check('woodbury-facts: a card whose record disagrees says Conflict and is not ticked', strpos($card['conflict'], 'The record has') !== false && $card['selected'] === false, $card['conflict']);
        $res = kop_wbf_apply(array($live), (int) $live['facility_id'], 'tester');
        $after = kop_wbf_rows(array($live['pkey']))[0];
        $check('woodbury-facts: Add on a conflict keeps it waiting, the conflict stored, never filed as rejected', $after['status'] === 'pending'
            && strpos((string) $after['conflict'], 'The record has') === 0 && strpos((string) ($res[$live['pkey']]['error'] ?? ''), 'Conflict: ') === 0, json_encode($res));
    } else {
        $check('woodbury-facts: a waiting item with a live conflict exists in the mirror', false);
    }
    $first = kop_rinbox_on_file_list($src, 'woodbury-facts', array('offset' => 0, 'limit' => 5));
    $check('woodbury-facts: the Already on file view lists them, saying what the record has', $first['total'] === count($on_file) && $first['items']
        && $first['items'][0]['details'][0]['label'] === 'Already on file', json_encode($first['items'][0]['details'][0] ?? null));
    $pre = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND preselect = 1 AND facility_id > 0 ORDER BY id LIMIT 1");
    $notpre = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND preselect = 0 AND facility_id > 0 ORDER BY id LIMIT 1");
    $check('woodbury-facts: a checked quote at a sure match starts ticked, others do not',
        ($pre === '' || kop_rinbox_get_item('woodbury-facts', $pre)['selected'] === true) && ($notpre === '' || kop_rinbox_get_item('woodbury-facts', $notpre)['selected'] === false));
    $alt = $pick("SELECT pkey FROM $t WHERE status = 'pending' AND facility_id > 0 AND alternatives LIKE '%\"id\"%' ORDER BY id LIMIT 1");
    if ($alt !== '') {
        $ids = array_column(kop_rinbox_get_item('woodbury-facts', $alt)['actions'], 'id');
        $check('woodbury-facts: the other close names are one-click adds', (bool) preg_grep('/^apply_to_\d+$/', $ids), implode(',', $ids));
    }
    $it = kop_rinbox_get_item('woodbury-facts', $staff);
    $create = array_values(array_filter($it['actions'], function ($a) { return $a['id'] === 'create'; }))[0] ?? array();
    $check('woodbury-facts: a matched item can still go on a new record, with its type and "different place"',
        in_array('type', array_column($create['params'] ?? array(), 'name'), true) && in_array('force', array_column($create['params'] ?? array(), 'name'), true));
    try {
        call_user_func($src['act'], $staff, 'add_person', array('name' => '', 'role' => 'x'));
        $check('woodbury-facts: adding a person needs a name', false);
    } catch (RuntimeException $e) {
        $check('woodbury-facts: adding a person needs a name', true, $e->getMessage());
    }
    try {
        $m = call_user_func($src['act'], $staff, 'add_person', array('name' => 'Testy McTestface', 'role' => 'Night staff', 'pastJobs' => '', 'where' => 'staff.notableStaff'));
        $new = $pdo->query("SELECT * FROM $t WHERE label LIKE 'Testy McTestface%'")->fetch(PDO::FETCH_ASSOC);
        $check('woodbury-facts: "Add this person" adds a waiting staff item citing the same page', $new && $new['status'] === 'pending' && $new['grp'] === 'staff'
            && (int) $new['facility_id'] === (int) $before['facility_id'], $m['message']);
        if ($new) $pdo->prepare("DELETE FROM $t WHERE pkey = ?")->execute(array($new['pkey']));
    } catch (Throwable $e) {
        $check('woodbury-facts: "Add this person"', false, get_class($e) . ': ' . $e->getMessage());
    }
    $check('woodbury-facts: queue tools are "Add the exact-name items now" and "Load again"', array_column($src['tools'], 'id') === array('auto', 'resync'));

    // Exact names go in with no click (owner, 2026-10-14); anything in doubt, or touched by a person, waits.
    $auto = $pdo->query("SELECT * FROM $t WHERE " . kop_wbf_auto_where())->fetchAll(PDO::FETCH_ASSOC);
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending' AND facility_id > 0")->fetchColumn();
    $check('woodbury-facts: exact-name items are added automatically, the rest wait', $auto && count($auto) < $pending, count($auto) . ' of ' . $pending);
    $bad = array_filter($auto, function ($r) {
        if ((int) $r['auto'] === 1) return false;
        $exact = ($r['match_kind'] === 'exact' && $r['match_note'] === '')
            || ($r['match_kind'] === 'past_name' && strpos($r['match_note'], 'Record is in') === false);
        return !$exact || !(int) $r['found'] || trim((string) $r['conflict']) !== '' || trim((string) $r['alternatives'], '[] ') !== ''
            || (string) $r['reviewed_by'] !== '' || (int) $r['ya'] || strpos((string) $r['extra'], '"edited_by"') !== false;
    });
    $check('woodbury-facts: never a close name, another possible record, a conflict, a state mismatch or an item a person touched', !$bad, count($bad) . ' bad');
    $one = $auto[0]['pkey'] ?? '';
    if ($one !== '') {
        $before = $pdo->query("SELECT reviewed_by, extra FROM $t WHERE pkey = " . $pdo->quote($one))->fetch(PDO::FETCH_ASSOC);
        $in = function () use ($pdo, $t, $one) { return (bool) $pdo->query("SELECT COUNT(*) FROM $t WHERE pkey = " . $pdo->quote($one) . ' AND ' . kop_wbf_auto_where())->fetchColumn(); };
        $pdo->exec("UPDATE $t SET reviewed_by = 'owner' WHERE pkey = " . $pdo->quote($one));
        $check('woodbury-facts: an item a person undid or sent back is never added again', !$in());
        $pdo->exec("UPDATE $t SET reviewed_by = '', extra = '{\"edited_by\":\"owner\"}' WHERE pkey = " . $pdo->quote($one));
        $check('woodbury-facts: an item a person corrected waits for them', !$in());
        $pdo->prepare("UPDATE $t SET reviewed_by = ?, extra = ? WHERE pkey = ?")->execute(array($before['reviewed_by'], $before['extra'], $one));
        $check('woodbury-facts: put back, it is picked again', $in());
    }
    $m = call_user_func($src['tool'], 'resync', array());
    $check('woodbury-facts: "Load the facts file again" answers', $m['message'] !== '', $m['message']);
}
