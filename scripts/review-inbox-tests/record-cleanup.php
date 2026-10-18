<?php
/**
 * scripts/test-review-inbox.php checks for the 'record-cleanup' source (inc/review-inbox/record-cleanup.php over
 * inc/record-cleanup.php and api/lib-record-dedupe.php), on the mirror's records: the rules on the owner's own
 * examples (one person under two spellings, two people in one entry, father and son, one death told twice, two
 * deaths in one month, a name listed as the record's own), cards for the records holding copies, a card ticked
 * in part tidies only the ticked lines through the store's save path and leaves the rest waiting, Undo gives the
 * record back exactly, Undo is refused once the record changed since, "Leave as it is" files the lines away and
 * "Back to the queue" brings them back, and every button says what it does.
 */

require_once dirname(__DIR__, 2) . '/inc/record-cleanup.php';
require_once dirname(__DIR__, 2) . '/api/lib-merge-facility-docs.php';

function kop_rinbox_test_record_cleanup(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];

    // ---- The rules, on the cases that went wrong on the live records -----------------------------------------
    $same = array(array('Jim Omer', 'James Omer'), array('Angela Agundez (Smith)', 'Angela Agundez'), array('Marc Clark', 'Marc Clarke'),
        array('Adam McLain', 'Adam McClain'), array('Jami Sturges-Ullrich', 'Jami Sturges'), array('Chris McRoberts', 'Chris H. McRoberts'),
        array('Andy Sapp', 'Andrew D. Sapp'));
    $two = array(array('Robert W. Lichfield', 'Robert B. Lichfield'), array('Mark and Jan Gregston', 'Mark Gregston'), array('Mark Clark', 'Marc Clark'),
        array('John Smith', 'Jane Smith'));
    $bad = array();
    foreach ($same as $p) if (!kop_dd_same_person($p[0], $p[1])) $bad[] = 'two: ' . implode(' / ', $p);
    foreach ($two as $p) if (kop_dd_same_person($p[0], $p[1])) $bad[] = 'one: ' . implode(' / ', $p);
    $check('record-cleanup: one person under another spelling is one; father and son, two people in one entry, two first names stay two', !$bad, implode(' | ', $bad));

    $check('record-cleanup: a role keeps each title once and drops the source name',
        kop_dd_role_tidy('Founder/Clinical Director, Owner, Founder, Clinical Director (2006-2011, Woodbury Reports)') === 'Founder/Clinical Director, Owner (2006-2011)'
        && kop_dd_role_tidy('Former Program Director (2009, Woodbury Reports)') === 'Former Program Director (2009)'
        && kop_dd_role_tidy('McElwrath** took over operation') === 'McElwrath** took over operation',
        kop_dd_role_tidy('Founder/Clinical Director, Owner, Founder, Clinical Director (2006-2011, Woodbury Reports)'));

    $death_a = '2007-05: Death: A 15-year-old died of a staph infection on May 2, 2007 on an Alternative Youth Adventures outing in Montrose County, CO (HEAL)';
    $death_b = '2007: Death: A teen died of a staph infection at Alternative Youth Adventures in 2007. (r/troubledteens wiki, page "Alternative Youth Adventures")';
    $girl_a = '2025-05-03: Death: On May 3, 2025 a 13-year-old girl died by suicide at Asheville Academy and NCDHHS suspended admissions. (wiki)';
    $girl_b = '2025-05-29: Death: On May 29, 2025 a 12-year-old girl died by suicide at Asheville Academy. (wiki)';
    $check('record-cleanup: one death told by two sources is one event; two deaths in one month are two',
        kop_dd_same_event($death_a, $death_b) && !kop_dd_same_event($girl_a, $girl_b)
        && !kop_dd_same_event('1994: Death: In 1994 a 17-year-old resident drowned in a canal (wiki)', '1994-06-27: Death: A youth died on June 27, 1994 at Arizona Boys Ranch (HEAL)'));

    $check('record-cleanup: "X (AYA)", "X (2006-2015)", "X, LLC", "X of Y" / "X Y" are the same name',
        kop_dd_name_key('Alternative Youth Adventures (AYA)') === kop_dd_name_key('Alternative Youth Adventures')
        && kop_dd_name_key('Red River Academy (2006-2015)') === kop_dd_name_key('Red River Academy')
        && kop_dd_name_key('TurnAround Ranch, LLC') === kop_dd_name_key('TurnAround Ranch')
        && kop_dd_name_key('Sequel TSI of Tuskegee') === kop_dd_name_key('Sequel TSI Tuskegee')
        && kop_dd_name_key('Havenwood Academy') !== kop_dd_name_key('Havenwood North'));

    // A merge of two copies of one record keeps one of each.
    $base = array('identification' => array('name' => 'Test Ranch', 'pastNames' => array(), 'otherNames' => array()),
        'staff' => array('administrator' => array(array('name' => 'James Omer', 'role' => 'Program Director (2009, Woodbury Reports)')), 'notableStaff' => array()),
        'criticalIncidents' => array('customIncidents' => array($death_a)));
    $copy = $base;
    $copy['identification']['name'] = 'Test Ranch (TR)';
    $copy['staff']['administrator'] = array(array('name' => 'Jim Omer', 'role' => 'Camp director (2007, HEAL)'));
    $copy['criticalIncidents']['customIncidents'] = array($death_b);
    {
        $m = kop_mfd_merge_docs($base + array('facility_id' => 1, 'schema_version' => 3), $copy, array('id' => 2, 'unique_name' => 'Test Ranch (TR)'));
        $check('record-cleanup: Merge Duplicates keeps one entry per person, one line per event, no "(TR)" other name',
            count($m['staff']['administrator']) === 1 && count($m['criticalIncidents']['customIncidents']) === 1
            && !in_array('Test Ranch (TR)', (array) $m['identification']['otherNames'], true),
            json_encode(array($m['staff']['administrator'], count($m['criticalIncidents']['customIncidents']), $m['identification']['otherNames'])));
    }

    // ---- The queue on the mirror's records -------------------------------------------------------------------
    if (!kop_rinbox_test_options_persist()) {
        kop_rinbox_test_skip('record-cleanup: cards, tidy and undo', 'update_option does not store in this harness');
        return;
    }
    $tables = array('facilities_v2', 'wpdl_kop_facility_identity', 'wpdl_kop_facility_locations', 'wpdl_kop_migration_state', 'wpdl_kop_operators',
        'wpdl_kop_operator_facilities', 'wpdl_kop_addresses', 'wpdl_kop_facility_addresses');
    kop_rinbox_test_copy_tables($tables);
    delete_option('kop_record_cleanup_cards');
    $cards = kop_rc_cards();
    $check('record-cleanup: cards are worked out for the records holding copies', count($cards) > 50, count($cards) . ' cards');

    // A card with a person twice and at least one more line.
    $fid = 0;
    foreach ($cards as $id => $c) {
        $kinds = array_column($c['steps'], 'kind');
        if (in_array('staff', $kinds, true) && count($c['steps']) >= 2) { $fid = (int) $id; break; }
    }
    if (!$fid) {
        $check('record-cleanup: a card with a person twice and another line to test with', false);
        return;
    }
    $card = kop_rinbox_rc_item($fid);
    $check('record-cleanup: a waiting card lists its changes ticked and offers Tidy the ticked / Leave as it is, each with its help',
        $card && count($card['checklist']) === count($cards[$fid]['steps']) && !array_filter($card['checklist'], function ($e) { return !$e['checked']; })
        && array_column($card['actions'], 'id') === array('tidy', 'leave') && !array_filter($card['actions'], function ($a) { return empty($a['help']); }),
        json_encode(array_column($card['actions'] ?? array(), 'id')));

    $row = function ($id) use ($pdo) { return (string) $pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $id)->fetchColumn(); };
    $before = $row($fid);
    $first = $cards[$fid]['steps'][0];
    try {
        $res = kop_rinbox_rc_act((string) $fid, 'tidy', array('picked' => array($first['key'])));
    } catch (Throwable $e) {
        $check('record-cleanup: Tidy the ticked saves through the facility store', false, $e->getMessage());
        return;
    }
    $after = json_decode($row($fid), true);
    list(, $left_steps) = kop_dd_tidy_doc($after);
    $keys_left = array_column($left_steps, 'key');
    $check('record-cleanup: Tidy makes only the ticked change; the unticked lines still wait on the card',
        $row($fid) !== $before && !in_array($first['key'], $keys_left, true) && count($keys_left) === count($cards[$fid]['steps']) - 1
        && isset(kop_rc_cards()[$fid]), $res['message']);
    $got = kop_rinbox_rc_item($fid);
    $check('record-cleanup: the tidied card offers its Undo and lists what was tidied', in_array('undo', array_column($got['actions'], 'id'), true)
        && $got['details'] && $got['details'][0]['label'] === 'Tidied');

    kop_rinbox_rc_act((string) $fid, 'undo', array());
    // The same record, field for field (the mirror keeps MySQL's spacing of the JSON, the store writes it compact).
    $check('record-cleanup: Undo gives the record back exactly', json_decode($row($fid), true) === json_decode($before, true));

    // Undo is refused once someone changed the record after the tidy.
    kop_rinbox_rc_act((string) $fid, 'tidy', array('picked' => array($first['key'])));
    $doc = json_decode($row($fid), true);
    $doc['identification']['otherNames'][] = 'A name added by hand after the tidy';
    kop_wbf_save($doc, kop_wbf_opts());
    $refused = false;
    try {
        kop_rinbox_rc_act((string) $fid, 'undo', array());
    } catch (RuntimeException $e) {
        $refused = strpos($e->getMessage(), 'changed after') !== false;
    }
    $check('record-cleanup: Undo is refused when the record changed since, so a later edit is never thrown away', $refused);

    // Leave as it is, and back to the queue.
    $other = 0;
    foreach (kop_rc_cards() as $id => $c) if ((int) $id !== $fid) { $other = (int) $id; break; }
    $before_other = $row($other);
    kop_rinbox_rc_act((string) $other, 'leave', array());
    $check('record-cleanup: Leave as it is changes nothing and the card leaves the waiting list', $row($other) === $before_other
        && !isset(kop_rc_cards()[$other]) && isset(kop_rc_left()[$other]));
    $got = kop_rinbox_rc_item($other);
    $check('record-cleanup: a card left as it is offers Back to the queue', $got && in_array('unleave', array_column($got['actions'], 'id'), true));
    kop_rinbox_rc_act((string) $other, 'unleave', array());
    $check('record-cleanup: Back to the queue brings its lines back', isset(kop_rc_cards()[$other]));
}
