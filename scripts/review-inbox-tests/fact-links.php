<?php
/**
 * Checks for the open linking passes of docs/plan/4.4 item 20 (docs/claude/fact-shape.md 7.5), on made-up fixtures only: records
 * #990011-#990016, operators #990021-#990023, news #990111-#990118, facts #990201+ and one Woodbury Reports page are inserted into the scratch
 * copy and taken out again; no mirror row is touched.
 *   - Woodbury Reports pages with no record that match one record by exact name (kop_autoconn_woodbury_nomatch_rows()): one holder, not two,
 *     not another state, not once tried. The filing itself is kop_wb_file_many() (needs the extract PDF; checked in woodbury-reports.php).
 *   - A company named exactly by one operator (kop_nf_link_companies()), Undo (kop_nf_undo_link()).
 *   - A person on the staff of one record the article is linked to or the fact names (kop_nf_link_people()), Undo (kop_nf_unlink()).
 *   - The state rule of kop_autoconn_exact_record() / kop_nf_record_for().
 * Run alone with --source=fact-links; the full run calls kop_test_fact_links() too.
 */

function kop_test_fact_links(callable $check) {
    $root = dirname(__DIR__, 2);
    require_once $root . '/inc/woodbury-facts.php';
    $GLOBALS['kop_test_options']['kop_woodbury_facts_db'] = KOP_WOODBURY_FACTS_DB_VERSION;
    require_once $root . '/inc/woodbury-mentions.php';
    require_once $root . '/inc/woodbury-create.php';
    require_once $root . '/api/lib-record-dedupe.php';
    require_once $root . '/inc/news-facts.php';
    require_once $root . '/inc/auto-connect.php';
    require_once $root . '/inc/fact-fold.php';
    global $wpdb;
    $pdo = $GLOBALS['pdo'];
    kop_rinbox_test_copy_tables(array('news_facts', 'wpdl_kop_auto_connect'));
    if (!$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'wpdl_kop_auto_connect'")->fetchColumn()) {
        $pdo->exec('CREATE TABLE wpdl_kop_auto_connect (source TEXT NOT NULL, item_key TEXT NOT NULL, target TEXT NOT NULL DEFAULT "", outcome TEXT NOT NULL DEFAULT "done",
            note TEXT NULL, created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))');
    }
    $GLOBALS['kop_test_options']['kop_auto_connect_db'] = KOP_AUTO_CONNECT_DB_VERSION;
    $GLOBALS['kop_test_transients'] = array();
    $wt = 'wpdl_kop_woodbury_facts';
    $ot = 'wpdl_kop_operators';

    $clean = function () use ($pdo, $wt, $ot) {
        $pdo->exec('DELETE FROM facilities_v2 WHERE id BETWEEN 990011 AND 990016');
        $pdo->exec("DELETE FROM $ot WHERE id BETWEEN 990021 AND 990023");
        $pdo->exec('DELETE FROM news_facts WHERE id >= 990200');
        $pdo->exec('DELETE FROM news_submissions WHERE id BETWEEN 990111 AND 990118');
        $pdo->exec('DELETE FROM news_facility_links WHERE news_id BETWEEN 990111 AND 990118');
        $pdo->exec("DELETE FROM $wt WHERE facility_id BETWEEN 990011 AND 990016");
        $pdo->exec("DELETE FROM wpdl_kop_auto_connect WHERE source LIKE 'news-facts%' OR source = 'woodbury-reports'");
        $pdo->exec("DELETE FROM wpdl_kop_woodbury_mentions WHERE ckey LIKE 'fltest%'");
    };
    $clean();

    // Records. 990011 and 990012 are one-of-a-kind names; 990013 and 990014 share a name; 990015 is in New York, 990016 in Utah.
    $staff = function (array $names) {
        $list = array();
        foreach ($names as $n) $list[] = array('name' => $n, 'role' => 'Counselor', 'pastJobs' => '');
        return array('administrator' => array(), 'notableStaff' => $list);
    };
    $mk = function ($id, $name, $state, array $names = array()) use ($pdo, $staff) {
        $doc = array('identification' => array('currentName' => $name), 'operatingPeriod' => array('status' => 'Open'), 'staff' => $staff($names),
            'criticalIncidents' => array('customIncidents' => array()), 'notes' => array(), 'resourceLinks' => array());
        $pdo->prepare('INSERT INTO facilities_v2 (id, unique_name, name, state, json_data, schema_version) VALUES (?, ?, ?, ?, ?, 2)')
            ->execute(array($id, $name . ' (' . $state . ')', $name, $state, json_encode($doc)));
        // In the full run the column is null after the insert (something earlier in the run derives it from the json): set it again.
        $pdo->prepare('UPDATE facilities_v2 SET state = ? WHERE id = ?')->execute(array($state, $id));
    };
    $mk(990011, 'Quillfeather Academy', 'UT', array('Zed Quillfeather', 'Pat Doublename'));
    $mk(990012, 'Marrowgate Ranch', 'UT', array('Pat Doublename', 'Ida Marrowgate'));
    $mk(990013, 'Twinbrook Ranch', 'UT');
    $mk(990014, 'Twinbrook Ranch', 'TX');
    $mk(990015, 'Hollowmere Youth Home', 'NY');
    $mk(990016, 'Snowberry Lodge', 'UT', array('Ora Snowberry'));
    kop_autoconn_name_index($pdo, true);

    // Operators: 990021 and 990022 share a name, 990023 is alone.
    $mkop = function ($id, $name, array $other = array()) use ($pdo, $ot) {
        $pdo->prepare("INSERT INTO $ot (id, unique_name, name, json_data, created_at, updated_at) VALUES (?, ?, ?, ?, '2026-11-03 00:00:00', '2026-11-03 00:00:00')")
            ->execute(array($id, $name . ' ' . $id, $name, json_encode(array('operator' => array('name' => $name, 'notes' => array(), 'otherNames' => $other)))));
    };
    $mkop(990021, 'Zorbolt Holdings');
    $mkop(990022, 'Zorbolt Holdings');
    $mkop(990023, 'Plumeria Care Group', array('Plumeria Youth Services'));

    // News: 990111 about Utah and linked to Quillfeather; 990112 about Utah, linked to nothing; 990113 in Manitoba; 990114 for the agency check.
    $art = function ($id, $title, $loc) use ($wpdb) {
        $wpdb->insert('news_submissions', array('id' => $id, 'article_title' => $title, 'publication_name' => 'The Fixture Times', 'publication_date' => '2026-10-01',
            'article_url' => 'https://example.org/fl-' . $id, 'archive_url' => '', 'article_location' => $loc, 'status' => 'approved'));
    };
    $art(990111, 'Quillfeather school', 'Provo, Utah');
    $art(990112, 'A counselor charged', 'Salt Lake City, Utah');
    $art(990113, 'Inquiry called for', 'Winnipeg, Manitoba');
    $art(990114, 'Agency report', 'Provo, Utah');
    $art(990115, 'Company news', 'Provo, Utah');
    $pdo->exec("INSERT INTO news_facility_links (news_id, facility_id, link_type, created_at, created_by) VALUES (990111, 990011, 'primary', '2026-11-03 00:00:00', 'test')");
    $seq = 990200;
    $nf = function ($news, $kind, $subject, $summary, $cat = 'statement', $person = '') use ($pdo, &$seq) {
        $seq++;
        $pdo->prepare("INSERT INTO news_facts (id, news_id, fkey, category, subject_kind, subject_name, facility_id, person, role, value, summary, standing, event_date, quote, quote_found, names_removed, route, wbf_pkey, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NULL, ?, '', '', ?, '', '', ?, 1, 0, 'waiting', '', 'pending', '2026-11-03 00:00:00')")
            ->execute(array($seq, $news, 'flk' . $seq, $cat, $kind, $subject, $person, $summary, $summary));
        return $seq;
    };
    $status = function ($id) use ($pdo) { return $pdo->query("SELECT route, status, facility_id FROM news_facts WHERE id = $id")->fetch(PDO::FETCH_ASSOC); };
    $note_lines = function ($oid) use ($pdo, $ot) {
        $d = json_decode((string) $pdo->query("SELECT json_data FROM $ot WHERE id = $oid")->fetchColumn(), true);
        return (array) ($d['operator']['notes'] ?? array());
    };

    // 1. Woodbury Reports pages with no record.
    $page = function ($ckey, $header, $state) use ($wpdb) {
        $wpdb->insert('wpdl_kop_woodbury_mentions', array('ckey' => $ckey, 'kind' => 'unmatched', 'status' => 'pending', 'issue_id' => 0, 'issue_label' => 'January 2006',
            'issue_number' => '', 'issue_date' => '2006-01', 'pages' => '3,4', 'header' => $header, 'place' => '', 'matched_name' => '', 'facility_id' => 0, 'facility_name' => '',
            'facility_state' => $state, 'target_kind' => 'facility', 'target_id' => 0, 'alternatives' => '[]', 'note' => '', 'snippet' => '', 'file' => '', 'md5' => '',
            'attachment_id' => 0, 'folder_id' => 0, 'reviewed_by' => '', 'created_at' => '2026-11-03 00:00:00'));
    };
    $page('fltest01', 'QUILLFEATHER ACADEMY', 'UT');
    $page('fltest02', 'TWINBROOK RANCH', 'UT');
    $page('fltest03', 'HOLLOWMERE YOUTH HOME', 'UT');
    $page('fltest04', 'SNOWBERRY LODGE', '');
    $page('fltest05', 'THE MARROWGATE RANCH, INC.', 'UT');
    $pick = function () { $out = array(); foreach (kop_autoconn_woodbury_nomatch_rows(1000) as $row) if (strpos($row[0]['ckey'], 'fltest') === 0) $out[$row[0]['ckey']] = $row[1]; ksort($out); return $out; };
    $got = $pick();
    $check('fact-links: a page whose header is exactly one record is matched to it (case, "The" and "Inc" do not count; no state on the page is fine)',
        ($got['fltest01'] ?? 0) === 990011 && ($got['fltest04'] ?? 0) === 990016 && ($got['fltest05'] ?? 0) === 990012, json_encode($got));
    $check('fact-links: a page whose header two records share is left', !isset($got['fltest02']), json_encode($got));
    $check('fact-links: a page in Utah is not filed under a New York record of that exact name (different state refuses)', !isset($got['fltest03']), json_encode($got));
    kop_autoconn_mark('woodbury-reports', 'fltest01', '990011', 'undone');
    $got = $pick();
    $check('fact-links: a page a person undid is never matched again', !isset($got['fltest01']) && isset($got['fltest04']), json_encode($got));

    // 2. The state rule on exact names.
    $idx = array('hollowmere youth home' => array(990015 => true));
    $check('fact-links: exact name, the source says Utah, the record is in New York: refused', kop_autoconn_exact_record($pdo, 'Hollowmere Youth Home', array(), $idx, array('UT')) === array(0, 'state'), json_encode(kop_autoconn_exact_record($pdo, 'Hollowmere Youth Home', array(), $idx, array('UT'))) . ' state=' . kop_autoconn_record_state($pdo, 990015) . ' row=' . json_encode($pdo->query('SELECT id, state FROM facilities_v2 WHERE id = 990015')->fetchAll(PDO::FETCH_ASSOC)));
    $check('fact-links: exact name, the source says New York: linked', kop_autoconn_exact_record($pdo, 'Hollowmere Youth Home', array(), $idx, array('UT', 'NY')) === array(990015, 'exact'));
    $check('fact-links: exact name, no state known: linked as before', kop_autoconn_exact_record($pdo, 'Hollowmere Youth Home', array(), $idx) === array(990015, 'exact'));
    $a = $nf(990113, 'facility', 'Hollowmere Youth Home', 'Manitoba PCs called for an inquiry into Hollowmere Youth Home.');
    $b = $nf(990112, 'facility', 'Hollowmere Youth Home', 'A counselor at Hollowmere Youth Home was charged in New York.');
    $c = $nf(990112, 'facility', 'Hollowmere Youth Home', 'Utah police charged a counselor.');
    $how = function ($id) use ($pdo) { return kop_nf_record_for($pdo, kop_nf_row($pdo, $id)); };
    $check('fact-links: kop_nf_record_for refuses the New York record for a Manitoba article', $how($a) === array(0, 'state'), json_encode($how($a)));
    $check('fact-links: kop_nf_record_for refuses it for a Utah article', $how($c) === array(0, 'state'), json_encode($how($c)));
    $check('fact-links: kop_nf_record_for links it when the fact says New York', $how($b) === array(990015, 'exact'), json_encode($how($b)));
    $pdo->exec("DELETE FROM news_facts WHERE id IN ($a, $b, $c)");

    // 3. Company link.
    $base_c = kop_nf_link_companies($pdo, false)['linked'];   // the mirror's own facts count too
    $f1 = $nf(990115, 'company', 'Zorbolt Holdings', 'Zorbolt Holdings bought the school.', 'owner');
    $f2 = $nf(990115, 'company', 'Plumeria Care Group', 'Plumeria Care Group closed a campus.', 'moved');
    $f3 = $nf(990115, 'facility', 'Plumeria Youth Services', 'Plumeria Youth Services was fined.', 'statement');
    $f4 = $nf(990115, 'agency', 'Plumeria Care Group', 'An agency named Plumeria Care Group.', 'statement');
    $f5 = $nf(990115, 'facility', 'Quillfeather Academy', 'Quillfeather Academy is a school.', 'statement');
    $dry = kop_nf_link_companies($pdo, false);
    $check('fact-links: the company measurement counts the fixtures and writes nothing', $dry['linked'] - $base_c === 2 && $status($f2)['route'] === 'waiting' && $note_lines(990023) === array(), $dry['linked'] . ' vs ' . $base_c);
    $res = kop_nf_link_companies($pdo, true, 60);
    $check('fact-links: a fact naming exactly one operator (or its other name) is filed on that company as a cited note',
        $status($f2)['route'] === 'company' && $status($f2)['status'] === 'filed' && $status($f3)['route'] === 'company' && count($note_lines(990023)) === 2
        && strpos($note_lines(990023)[0], 'https://example.org/fl-990115') !== false, json_encode(array($res['linked'], $note_lines(990023))));
    $check('fact-links: a name two operators share is not linked', $status($f1)['route'] === 'waiting' && $res['ambiguous'] >= 1 && $note_lines(990021) === array());
    $check('fact-links: an agency subject is never linked to a company', $status($f4)['route'] === 'waiting');
    $check('fact-links: a facility subject that is a program name is left to the program link', $status($f5)['route'] === 'waiting');
    $mark = kop_autoconn_tried('news-facts-company');
    $check('fact-links: the link is marked (never linked again once undone)', isset($mark[(string) $f2]) && isset($mark[(string) $f3]));
    require_once $root . '/inc/review-inbox/news-facts.php';
    $msg = kop_nf_undo_link($pdo, kop_nf_row($pdo, $f2), 'tester');
    $check('fact-links: Undo takes the note off the company and the fact waits again', $status($f2)['route'] === 'waiting' && $status($f2)['status'] === 'pending'
        && count($note_lines(990023)) === 1 && $msg !== '', $msg . ' ' . json_encode($note_lines(990023)));
    kop_nf_link_companies($pdo, true, 60);
    $check('fact-links: an undone company link is not made again', $status($f2)['route'] === 'waiting' && count($note_lines(990023)) === 1);

    // 4. Person pass.
    $base_p = kop_nf_link_people($pdo, false)['linked'];
    $p1 = $nf(990111, 'person', 'Zed Quillfeather', 'Zed Quillfeather was the director of the school.', 'staff', 'Zed Quillfeather');
    $p2 = $nf(990112, 'person', 'Ora Snowberry', 'Ora Snowberry was charged with assault.', 'arrest');
    $p3 = $nf(990112, 'person', 'Ora Snowberry', 'Ora Snowberry, a counselor at Snowberry Lodge, was charged.', 'arrest');
    $p4 = $nf(990111, 'person', 'Pat Doublename', 'Pat Doublename said the school was fine.', 'statement');
    $p5 = $nf(990111, 'agency', 'Zed Quillfeather', 'An agency of that name.', 'statement');
    $p6 = $nf(990113, 'person', 'Ida Marrowgate', 'Ida Marrowgate, of Marrowgate Ranch, spoke.', 'statement');   // the fact names a Utah record, the article is in Manitoba
    $dry = kop_nf_link_people($pdo, false);
    $check('fact-links: the person measurement counts and writes nothing', $dry['linked'] - $base_p === 2 && $status($p1)['route'] === 'waiting', $dry['linked'] . ' vs ' . $base_p);
    kop_nf_link_people($pdo, true, 60);
    $s1 = $status($p1);
    $check('fact-links: a staff member of exactly one record, the article linked to it: linked and filed on Woodbury Facts', (int) $s1['facility_id'] === 990011 && $s1['route'] === 'facility'
        && $s1['status'] === 'filed' && (int) $pdo->query("SELECT COUNT(*) FROM $wt WHERE facility_id = 990011")->fetchColumn() === 1, json_encode($s1));
    $check('fact-links: a person whose article is not linked and whose fact does not name the record waits', $status($p2)['route'] === 'waiting');
    $check('fact-links: the fact naming the record exactly links it', (int) $status($p3)['facility_id'] === 990016 && $status($p3)['route'] === 'facility', json_encode($status($p3)));
    $check('fact-links: a name on the staff of two records is not linked', $status($p4)['route'] === 'waiting');
    $check('fact-links: an agency subject is never linked as a person', $status($p5)['route'] === 'waiting');
    $check('fact-links: a person whose record is in another state than the article (Manitoba) waits even when the fact names the record', $status($p6)['route'] === 'waiting', json_encode($status($p6)));
    $mk_note = kop_autoconn_tried('news-facts');
    $check('fact-links: the person link is marked as a news-facts link (so its Undo is the engine\'s)', isset($mk_note[(string) $p1]) && isset($mk_note[(string) $p3]));
    $msg = kop_nf_undo_auto($pdo, kop_nf_row($pdo, $p1), 'tester');
    $s1 = $status($p1);
    $check('fact-links: Undo unlinks the person fact and takes its Woodbury row off', $s1['route'] === 'waiting' && $s1['status'] === 'pending' && (int) $s1['facility_id'] === 0
        && (int) $pdo->query("SELECT COUNT(*) FROM $wt WHERE facility_id = 990011")->fetchColumn() === 0, $msg);
    kop_nf_link_people($pdo, true, 60);
    $check('fact-links: an undone person link is not made again', $status($p1)['route'] === 'waiting');

    $clean();
    kop_autoconn_name_index($pdo, true);
}
