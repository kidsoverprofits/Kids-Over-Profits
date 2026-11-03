<?php
/**
 * Checks for the live fold engine (inc/fact-fold.php, docs/plan/4.4 item 20 g), on made-up fixtures only: records
 * #990001 and #990002 and their rows are inserted into the scratch copy; no mirror row is touched. The engine runs on
 * those two units only (kop_fold_run(..., array('only' => ...))).
 * kop_rinbox_test_woodbury_facts() calls kop_test_fact_fold().
 */

require_once dirname(__DIR__, 2) . '/inc/woodbury-facts.php';
$GLOBALS['kop_test_options']['kop_woodbury_facts_db'] = KOP_WOODBURY_FACTS_DB_VERSION;
require_once dirname(__DIR__, 2) . '/inc/fact-shape.php';

function kop_test_fact_fold(callable $check) {
    require_once dirname(__DIR__, 2) . '/inc/news-facts.php';
    require_once dirname(__DIR__, 2) . '/inc/fornits.php';
    require_once dirname(__DIR__, 2) . '/inc/auto-connect.php';
    require_once dirname(__DIR__, 2) . '/inc/fact-fold.php';
    global $wpdb;
    $pdo = $GLOBALS['pdo'];
    kop_rinbox_test_copy_tables(array('news_facts'));   // not in the scratch copy's table list
    $wt = 'wpdl_kop_woodbury_facts';
    $ft = 'wpdl_kop_fornits_items';
    $GLOBALS['kop_test_options']['kop_fornits_db'] = KOP_FORNITS_DB_VERSION;
    if (!$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'wpdl_kop_auto_connect'")->fetchColumn()) {
        $pdo->exec('CREATE TABLE wpdl_kop_auto_connect (source TEXT NOT NULL, item_key TEXT NOT NULL, target TEXT NOT NULL DEFAULT "", outcome TEXT NOT NULL DEFAULT "done",
            note TEXT NULL, created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))');
    }
    $GLOBALS['kop_test_options']['kop_auto_connect_db'] = KOP_AUTO_CONNECT_DB_VERSION;
    $GLOBALS['kop_test_transients'] = array();

    // Two made-up records.
    $F1 = 990001;
    $F2 = 990002;
    $pdo->exec("DELETE FROM facilities_v2 WHERE id IN ($F1, $F2)");
    $pdo->exec("DELETE FROM $wt WHERE facility_id IN ($F1, $F2)");
    $pdo->exec("DELETE FROM $ft WHERE facility_id IN ($F1, $F2)");
    $pdo->exec('DELETE FROM news_facts WHERE id >= 990000');
    $pdo->exec('DELETE FROM news_submissions WHERE id >= 990100');
    $pdo->exec("DELETE FROM wpdl_kop_auto_connect WHERE source LIKE 'news-facts%'");
    $mkdoc = function () {
        return array('identification' => array('currentName' => 'Fold Test School'), 'operatingPeriod' => array('status' => 'Open'),
            'staff' => array('administrator' => array(), 'notableStaff' => array()), 'criticalIncidents' => array('customIncidents' => array()), 'notes' => array(), 'resourceLinks' => array());
    };
    foreach (array($F1 => 'Fold Test School', $F2 => 'Fold Other School') as $id => $nm) {
        $pdo->prepare('INSERT INTO facilities_v2 (id, unique_name, name, json_data, schema_version) VALUES (?, ?, ?, ?, 2)')->execute(array($id, $nm . ' (XX)', $nm, json_encode($mkdoc())));
    }
    $ev = function ($n, $date) {
        return array('cite' => 'Paper ' . $n . ', "Headline ' . $n . '", ' . $date, 'url' => 'https://example.org/story-' . $n, 'label' => '', 'page' => 0, 'quote' => 'quote ' . $n,
            'found' => true, 'pub' => 1, 'date' => $date, 'news_id' => $n);
    };
    $seq = 0;
    $w = function ($fid, $path, $line, $date, $n, array $more = array()) use ($wpdb, $ev, &$seq, $wt) {
        $seq++;
        $pkey = 'ee' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        $extra = array('via' => 'news', 'news_id' => $n, 'category' => 'injury');
        $row = array('pkey' => $pkey, 'facility_id' => $fid, 'program' => 'Fold Test School', 'program_as_written' => 'Fold Test School', 'place' => '', 'match_kind' => 'id',
            'match_note' => '', 'alternatives' => '[]', 'grp' => 'incident', 'op' => 'add_list', 'path' => $path, 'value' => json_encode($line), 'label' => $line,
            'conflict' => '', 'current_val' => '', 'extra' => json_encode($extra), 'evidence' => json_encode(array($ev($n, $date))), 'found' => 1, 'preselect' => 1,
            'issue_date' => substr($date, 0, 7), 'status' => 'pending', 'applied' => null, 'applied_fid' => 0, 'reviewed_by' => null, 'created_at' => '2026-11-02 00:00:00', 'auto' => 0, 'ya' => 0, 'ya_why' => '');
        $wpdb->insert($wt, array_merge($row, $more));
        return $pkey;
    };
    $get = function ($pkey) { return kop_wbf_rows(array($pkey))[0]; };
    $inc = 'criticalIncidents.customIncidents';
    $run = function ($units) {
        return kop_fold_run(true, 60, array('only' => $units));
    };

    // 1. Provo Canyon: tellings of one injury. The rules join the dated pair; the AI hook (answered here) the three it cannot tell.
    $a = $w($F1, $inc, '2026-05-14: Injury: A 13-year-old resident suffered a fractured jaw and a traumatic brain injury when staff slammed him into the ground.', '2026-06-15', 207);
    $b = $w($F1, $inc, '2026-05-14: Injury: A teen resident suffered a fractured jaw and a traumatic brain injury in a fight at the Provo facility.', '2026-06-02', 208);
    $c = $w($F1, $inc, 'Injury: A 13-year-old suffered a broken jaw and a brain bleed after staff slammed him onto his head on the ground.', '2026-07-17', 209);
    $d = $w($F1, $inc, 'Injury: A 13-year-old was seriously injured in a fight and staff did not call 911 for his jaw injury.', '2026-06-23', 210);
    $e = $w($F1, $inc, '2026: Injury: A 13-year-old was beaten unconscious with life-threatening head injuries and a brain bleed.', '2026-08-04', 211);
    // The same month, a different date: another event, never joined.
    $other = $w($F1, $inc, '2026-07-06: Injury: A 14-year-old resident suffered a broken arm during a restraint at the Springville campus.', '2026-07-20', 212);
    // A held telling of another event, and a waiting telling of it.
    $held = $w($F1, $inc, '2026-03-02: Death: A 15-year-old resident died after staff restrained him face down at the school.', '2026-04-01', 213,
        array('status' => 'held', 'applied' => json_encode(array('held' => 1)), 'applied_fid' => $F1, 'reviewed_by' => 'tester'));
    $dup = $w($F1, $inc, '2026-03-02: Death: A 15-year-old resident died after staff restrained him face down at the school.', '2026-04-09', 214);
    // A person-edited item that would join the group is left alone.
    $edited = $w($F1, $inc, '2026-05-14: Injury: A 13-year-old resident suffered a fractured jaw and a traumatic brain injury when staff slammed him into the ground, edited.', '2026-06-20', 215,
        array('extra' => json_encode(array('via' => 'news', 'edited_by' => 'someone'))));
    // The AI step is a hook (kop_fold_ai_pairs()); the harness's apply_filters() returns this array for it.
    $GLOBALS['kop_test_filters']['kop_fold_ai_pairs'] = array($c => array('v' => 'same_as', 'to' => $a), $d => array('v' => 'same_as', 'to' => $a), $e => array('v' => 'same_as', 'to' => $a));
    $S = $run(array('r:' . $F1));
    unset($GLOBALS['kop_test_filters']['kop_fold_ai_pairs']);

    $ra = $get($a);
    $check('fact-fold: the held telling clears its waiting twin ("Held for the write-up")', $get($dup)['status'] === 'rejected' && $get($dup)['reviewed_by'] === 'auto'
        && strpos($get($dup)['applied'], 'Held for the write-up') !== false && strpos($get($dup)['applied'], '"cleared":"held"') !== false, $get($dup)['applied']);
    $check('fact-fold: the rules fold the pair of tellings of one day into one card (the longer line stays)', $get($b)['status'] === 'merged' && $ra['status'] === 'pending');
    $folded_ids = array_column((array) (json_decode($ra['extra'], true)['folded'] ?? array()), 'id');
    $check('fact-fold: the AI hook\'s three more join them: five tellings, one card', $get($c)['status'] === 'merged' && $get($d)['status'] === 'merged' && $get($e)['status'] === 'merged'
        && count($folded_ids) === 4, json_encode($folded_ids));
    $urls = array_column(kop_wbf_evidence($ra), 'url');
    $check('fact-fold: the kept card cites all five sources', count(array_unique($urls)) === 5 && in_array('https://example.org/story-211', $urls, true), json_encode($urls));
    $check('fact-fold: a different date in the same month stays a separate waiting card', $get($other)['status'] === 'pending');
    $check('fact-fold: an item a person edited is left alone', $get($edited)['status'] === 'pending' && count(kop_wbf_evidence($ra)) === 5);
    $check('fact-fold: a member records where it went', json_decode($get($b)['extra'], true)['merged_into'] === $a && strpos($get($b)['applied'], 'woodbury:' . $a) !== false);
    $check('fact-fold: the run counts what it did', ($S['folded']['woodbury']['incident'] ?? 0) === 4 && ($S['cleared']['woodbury']['held'] ?? 0) === 1, json_encode(array($S['folded'], $S['cleared'])));
    $check('fact-fold: a second run changes nothing', array_sum(array_map('array_sum', ($again = $run(array('r:' . $F1)))['folded'])) === 0 && array_sum(array_map('array_sum', $again['cleared'])) === 0);

    // The card shows its folded tellings; the member shows where it went.
    $item = kop_rinbox_get_item('woodbury-facts', $a);
    $check('fact-fold: the kept card lists the tellings folded into it', strpos($item['text'], 'Folded together: 4 other tellings') !== false);
    $mi = kop_rinbox_get_item('woodbury-facts', $b);
    $check('fact-fold: a folded item shows as Folded together with Undo', $mi['status'] === 'merged' && $mi['actions'][0]['id'] === 'undo', $mi['status_label']);
    $views = call_user_func(kop_rinbox_source('woodbury-facts')['view_counts'], array('search' => '', 'filters' => array()));
    $check('fact-fold: the Folded together view counts them and Waiting does not', ($views['merged'] ?? 0) >= 4 && $get($b)['status'] === 'merged');

    // Undo from the card (the Recently done button runs the same act): back to waiting, citations off, never folded again.
    $m = call_user_func(kop_rinbox_source('woodbury-facts')['act'], $c, 'undo', array());
    $rc = $get($c);
    $urls = array_column(kop_wbf_evidence($get($a)), 'url');
    $check('fact-fold: Undo puts the telling back to waiting', $rc['status'] === 'pending' && $rc['reviewed_by'] === 'inbox-test', $m['message']);
    $check('fact-fold: ...and takes its citation off the kept card', !in_array('https://example.org/story-209', $urls, true) && count($urls) === 4
        && count(json_decode($get($a)['extra'], true)['folded']) === 3, json_encode($urls));
    $run(array('r:' . $F1));
    $check('fact-fold: an undone telling is not folded again', $get($c)['status'] === 'pending');
    // Undo of the rest.
    foreach (array($b, $d, $e) as $pk) kop_fold_undo_member('wbf', $get($pk), 'tester');
    $check('fact-fold: Undo of all restores every card and the kept one cites only its own source', count(kop_wbf_evidence($get($a))) === 1
        && !array_key_exists('folded', json_decode($get($a)['extra'], true)) && $get($b)['status'] === 'pending' && $get($e)['status'] === 'pending');
    // The cleared item comes back with the existing "Back to review", and is not cleared again.
    kop_wbf_undo(array($get($dup)), 'tester');
    $run(array('r:' . $F1));
    $check('fact-fold: a cleared item put back stays waiting', $get($dup)['status'] === 'pending' && $get($dup)['reviewed_by'] === 'tester');

    // 2. Sync: a reload keeps folded citations on the kept card and never brings a merged item back.
    $pdo->exec("DELETE FROM $wt WHERE facility_id = $F1");
    $k = $w($F1, $inc, '2026-05-14: Injury: A 13-year-old resident suffered a fractured jaw and a traumatic brain injury when staff slammed him into the ground.', '2026-06-15', 207);
    $m1 = $w($F1, $inc, '2026-05-14: Injury: A teen resident suffered a fractured jaw and a traumatic brain injury in a fight at the Provo facility.', '2026-06-02', 208);
    $run(array('r:' . $F1));
    $check('fact-fold: (sync fixture) the pair folds', $get($m1)['status'] === 'merged' && count(kop_wbf_evidence($get($k))) === 2);
    $kr = $get($k);
    $proposal = function ($r, array $evidence) {
        return array('key' => $r['pkey'], 'facility_id' => (int) $r['facility_id'], 'program' => 'Fold Test School', 'match' => 'id', 'group' => 'incident', 'op' => 'add_list',
            'path' => $r['path'], 'value' => json_decode($r['value'], true), 'label' => $r['label'], 'evidence' => $evidence, 'found' => true, 'issue_date' => '2026-06');
    };
    $path = sys_get_temp_dir() . '/kop-fold-sync-' . getmypid();
    @mkdir($path);
    if (!defined('KOP_WOODBURY_PENDING_DIR')) define('KOP_WOODBURY_PENDING_DIR', $path);
    if (kop_wb_pending_dir() === $path) {
        file_put_contents($path . '/facts.json', json_encode(array('proposals' => array(
            $proposal($kr, array($ev(207, '2026-06-15'), $ev(299, '2026-06-30'))),   // the build now cites a second article of its own
            $proposal($get($m1), array($ev(208, '2026-06-02'))),
        ))));
        // The load marks every other waiting item "no longer proposed" (thousands of updates): park them for the check.
        $pdo->exec("UPDATE $wt SET status = 'zzpark' WHERE status = 'pending' AND pkey NOT IN ('$k', '$m1')");
        $pdo->exec("UPDATE $wt SET status = 'zzgone' WHERE status = 'gone'");
        try {
            $res = kop_wbf_sync(true);
            $k2 = $get($k);
            $urls = array_column(kop_wbf_evidence($k2), 'url');
            $check('fact-fold: a reload keeps the folded citations and takes the build\'s new one', in_array('https://example.org/story-208', $urls, true)
                && in_array('https://example.org/story-299', $urls, true) && count($urls) === 3 && !empty(json_decode($k2['extra'], true)['folded']), json_encode($urls));
            $check('fact-fold: a reload never brings a folded item back to waiting', $get($m1)['status'] === 'merged', $get($m1)['status']);
        } finally {
            $pdo->exec("UPDATE $wt SET status = 'pending' WHERE status = 'zzpark'");
            $pdo->exec("UPDATE $wt SET status = 'gone' WHERE status = 'zzgone'");
            @unlink($path . '/facts.json');
            @rmdir($path);
        }
    } else {
        echo "  (the facts file location is fixed in this run: the reload check is not run)\n";
        $row = kop_fold_keep_on_sync(array('extra' => '{}', 'evidence' => '[]'), $k, array(), array($ev(207, '2026-06-15')));
        $check('fact-fold: a reload keeps the folded citations', count(json_decode($row['evidence'], true)) === 2);
    }

    // 3. Fornits only: two forum posts about one person fold into one card; the kept one carries the other post's citation.
    $fr = function ($pk, $person, $role, $n, $date) use ($wpdb, $ft, $F2) {
        $wpdb->insert($ft, array('pkey' => $pk, 'topic_id' => 5000 + $n, 'kind' => 'staff', 'facility_id' => $F2, 'also' => '[]', 'how' => 'x', 'label' => 'Staff: ' . $person,
            'value' => json_encode(array('person' => $person, 'role' => $role, 'years' => '')), 'quote' => 'quote', 'quote_found' => 1, 'context' => '', 'post_n' => 1, 'author' => 'poster' . $n,
            'post_date' => $date, 'preselect' => 0, 'status' => 'pending', 'applied' => null, 'applied_fid' => 0, 'reviewed_by' => null, 'created_at' => '2026-11-02 00:00:00',
            'summary' => '', 'categories' => '', 'importance' => 0));
    };
    $fr('fe000001', 'Marion Ellsworth', 'director', 1, '2005-03-04');
    $fr('fe000002', 'Marion Ellsworth', 'school director and founder', 2, '2006-05-06');
    $S = $run(array('r:' . $F2));
    $rows = $pdo->query("SELECT pkey, status, applied FROM $ft WHERE facility_id = $F2 ORDER BY pkey")->fetchAll(PDO::FETCH_ASSOC);
    $merged = array_values(array_filter($rows, function ($r) { return $r['status'] === 'merged'; }));
    $kept = array_values(array_filter($rows, function ($r) { return $r['status'] === 'pending'; }));
    $check('fact-fold: two Fornits posts about one person fold into one Fornits card', count($merged) === 1 && count($kept) === 1, json_encode($rows));
    $folds = json_decode($kept[0]['applied'], true)['folds'] ?? array();
    $check('fact-fold: the kept Fornits card carries the folded post\'s citation (applied.folds)', count($folds) === 1 && strpos($folds[0]['cites'][0]['url'], 'fornits.com') !== false, json_encode($folds));
    $check('fact-fold: the folded Fornits item says where it went', strpos($merged[0]['applied'], '"merged_into":"fornits:' . $kept[0]['pkey'] . '"') !== false);
    $fitem = kop_rinbox_get_item('fornits', $kept[0]['pkey']);
    $check('fact-fold: the Fornits card lists what was folded into it', strpos($fitem['text'], 'Folded together: 1 other telling') !== false);
    $cites = array_column(kop_fornits_fold_cites(kop_fornits_rows(array($kept[0]['pkey']))[0]), 'url');
    $check('fact-fold: the Fornits write-up cites the folded post too', count($cites) === 1);
    $res = kop_fornits_undo(kop_fornits_rows(array($merged[0]['pkey'])), 'tester');
    $after = $pdo->query("SELECT pkey, status, applied, reviewed_by FROM $ft WHERE facility_id = $F2 ORDER BY pkey")->fetchAll(PDO::FETCH_ASSOC);
    $check('fact-fold: Fornits Undo restores it and takes the citation off the kept card', !empty($res[$merged[0]['pkey']]['ok']) && $after[0]['status'] === 'pending' && $after[1]['status'] === 'pending'
        && $after[0]['applied'] === null && $after[1]['applied'] === null, json_encode($after));
    $run(array('r:' . $F2));
    $check('fact-fold: an undone Fornits item is not folded again', $pdo->query("SELECT COUNT(*) FROM $ft WHERE facility_id = $F2 AND status = 'merged'")->fetchColumn() == 0);

    // 4. Across queues: a Woodbury staff card and a news fact about the same person fold into the Woodbury card.
    $pdo->exec("DELETE FROM $wt WHERE facility_id IN ($F1, $F2)");
    $wpdb->insert('news_submissions', array('id' => 990101, 'article_title' => 'The director', 'publication_name' => 'The Gazette', 'publication_date' => '2026-02-01',
        'article_url' => 'https://example.org/gazette-director', 'archive_url' => '', 'status' => 'approved'));
    $sid = $w($F2, 'staff.notableStaff', 'Staff: Jane Roe, Director', '2026-01-10', 220, array('grp' => 'staff', 'op' => 'add_staff',
        'value' => json_encode(array('name' => 'Jane Roe', 'role' => 'Director', 'pastJobs' => '')), 'label' => 'Staff: Jane Roe, Director'));
    $pdo->exec("INSERT INTO news_facts (id, news_id, fkey, category, subject_kind, subject_name, facility_id, person, role, value, summary, standing, event_date, quote, quote_found, names_removed, route, wbf_pkey, status, created_at)
        VALUES (990001, 990101, 'fk1', 'staff', 'facility', 'Fold Other School', $F2, 'Jane Roe', 'director', '', 'Jane Roe is the director', '', '', 'Jane Roe, director', 1, 0, 'waiting', '', 'pending', '2026-11-02 00:00:00')");
    $S = $run(array('r:' . $F2));
    $nf = $pdo->query('SELECT route, status, wbf_pkey, reviewed_by FROM news_facts WHERE id = 990001')->fetch(PDO::FETCH_ASSOC);
    $check('fact-fold: a news fact about the same person folds into the Woodbury card (route and status merged, wbf_pkey = the kept card)', $nf['route'] === 'merged'
        && $nf['status'] === 'merged' && $nf['wbf_pkey'] === $sid && $nf['reviewed_by'] === 'auto', json_encode($nf));
    $urls = array_column(kop_wbf_evidence($get($sid)), 'url');
    $check('fact-fold: the Woodbury card cites the article', in_array('https://example.org/gazette-director', $urls, true), json_encode($urls));
    $check('fact-fold: the news fact no longer counts as waiting', kop_nf_counts($pdo)['pending'] === ((int) $pdo->query("SELECT COUNT(*) FROM news_facts WHERE route = 'waiting' AND status = 'pending'")->fetchColumn())
        && kop_nf_counts($pdo)['merged'] >= 1);
    $nrow = kop_nf_row($pdo, 990001);
    $msg = kop_nf_undo_auto($pdo, $nrow, 'tester');
    $nf = $pdo->query('SELECT route, status, wbf_pkey, reviewed_by FROM news_facts WHERE id = 990001')->fetch(PDO::FETCH_ASSOC);
    $check('fact-fold: Undo puts the news fact back with the waiting facts and the article off the card', $nf['route'] === 'waiting' && $nf['status'] === 'pending' && $nf['reviewed_by'] === 'tester'
        && !in_array('https://example.org/gazette-director', array_column(kop_wbf_evidence($get($sid)), 'url'), true), $msg);
    $run(array('r:' . $F2));
    $check('fact-fold: an undone news fact is not folded again', $pdo->query('SELECT status FROM news_facts WHERE id = 990001')->fetchColumn() === 'pending');

    // 5. A news fact already on the record is cleared with its reason; Undo through the Facts from news card keeps it out for good.
    $rdoc = $mkdoc();
    $rdoc['staff']['notableStaff'][] = array('name' => 'Pat Doe', 'role' => 'Principal', 'pastJobs' => '');
    $pdo->prepare('UPDATE facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(json_encode($rdoc), $F2));
    $pdo->exec("INSERT INTO news_facts (id, news_id, fkey, category, subject_kind, subject_name, facility_id, person, role, value, summary, standing, event_date, quote, quote_found, names_removed, route, wbf_pkey, status, created_at)
        VALUES (990002, 990101, 'fk2', 'staff', 'facility', 'Fold Other School', $F2, 'Pat Doe', 'principal', '', 'Pat Doe is the principal', '', '', 'Pat Doe, principal', 1, 0, 'waiting', '', 'pending', '2026-11-02 00:00:00')");
    $run(array('r:' . $F2));
    $nf = $pdo->query('SELECT status, reviewed_by FROM news_facts WHERE id = 990002')->fetch(PDO::FETCH_ASSOC);
    $check('fact-fold: a news fact for a person already on the record is cleared (dropped by auto, reason kept)', $nf['status'] === 'dropped' && $nf['reviewed_by'] === 'auto'
        && strpos(kop_fold_mark_note('news-facts-fold', 990002)['reason'] ?? '', 'Already on the record') === 0, json_encode($nf));
    require_once dirname(__DIR__, 2) . '/inc/review-inbox/news-facts.php';
    kop_rinbox_nf_act('990002', 'undo', array());
    $nf = $pdo->query('SELECT status, reviewed_by FROM news_facts WHERE id = 990002')->fetch(PDO::FETCH_ASSOC);
    $run(array('r:' . $F2));
    $check('fact-fold: Undo on its card puts it back, and it is not cleared again', $nf['status'] === 'pending' && $nf['reviewed_by'] === 'inbox-test'
        && $pdo->query('SELECT status FROM news_facts WHERE id = 990002')->fetchColumn() === 'pending');

    // 6. Linking a waiting news fact by the exact-name rule, with Undo. The name index is built once per process, before the
    // fixtures: the fixture fact names a record the mirror has (one the rule links today), its own facts made up.
    $probe = kop_nf_link_waiting($pdo, false, 60)['map'];
    if (!$probe) { echo "  (no waiting news fact links in this mirror: the link checks are not run)\n"; goto cleanup; }
    $F3 = (int) reset($probe);
    $subject = (string) $pdo->query('SELECT subject_name FROM news_facts WHERE id = ' . (int) key($probe))->fetchColumn();
    $pdo->prepare("INSERT INTO news_facts (id, news_id, fkey, category, subject_kind, subject_name, facility_id, person, role, value, summary, standing, event_date, quote, quote_found, names_removed, route, wbf_pkey, status, created_at)
        VALUES (990003, 990101, 'fk3', 'staff', 'facility', ?, NULL, 'Sam Poe', 'assistant director', '', 'Sam Poe is the assistant director', '', '', 'Sam Poe, assistant director', 1, 0, 'waiting', '', 'pending', '2026-11-02 00:00:00')")->execute(array($subject));
    // The article is linked to that record, so a name two records share is still decided (the exact-name rule's tie-break).
    $pdo->exec("INSERT INTO news_facility_links (news_id, facility_id, link_type, created_at, created_by) VALUES (990101, $F3, 'test', '2026-11-02', 'test')");
    $lk = kop_nf_link_waiting($pdo, false, 30);
    $check('fact-fold: the dry link finds the fact and writes nothing', ($lk['map'][990003] ?? 0) === $F3 && $pdo->query('SELECT route FROM news_facts WHERE id = 990003')->fetchColumn() === 'waiting', json_encode($lk));
    $lk = kop_nf_link_waiting($pdo, true, 30);
    $nf = $pdo->query('SELECT facility_id, route, status, wbf_pkey FROM news_facts WHERE id = 990003')->fetch(PDO::FETCH_ASSOC);
    $check('fact-fold: the live link sets the record and files the card on Woodbury Facts', (int) $nf['facility_id'] === $F3 && $nf['route'] === 'facility' && $nf['status'] === 'filed'
        && $nf['wbf_pkey'] !== '' && $get($nf['wbf_pkey'])['status'] === 'pending' && strpos($get($nf['wbf_pkey'])['extra'], '"via":"news"') !== false, json_encode($nf));
    $made = $nf['wbf_pkey'];
    $check('fact-fold: the link is in the auto-connect memory (source news-facts)', isset(kop_autoconn_tried('news-facts')['990003']));
    $msg = kop_nf_undo_auto($pdo, kop_nf_row($pdo, 990003), 'tester');
    $nf = $pdo->query('SELECT facility_id, route, status, wbf_pkey, reviewed_by FROM news_facts WHERE id = 990003')->fetch(PDO::FETCH_ASSOC);
    $check('fact-fold: Undo unlinks it and deletes the card the link made', $nf['facility_id'] === null && $nf['route'] === 'waiting' && $nf['status'] === 'pending' && $nf['wbf_pkey'] === ''
        && $pdo->query("SELECT COUNT(*) FROM $wt WHERE pkey = " . $pdo->quote($made))->fetchColumn() == 0, $msg);
    $lk = kop_nf_link_waiting($pdo, true, 30);
    $check('fact-fold: an unlinked fact is never linked again', !isset($lk['map'][990003]) && $pdo->query('SELECT route FROM news_facts WHERE id = 990003')->fetchColumn() === 'waiting');

    cleanup:
    // Clean up the fixtures.
    $pdo->exec("DELETE FROM facilities_v2 WHERE id IN ($F1, $F2)");
    $pdo->exec("DELETE FROM $wt WHERE facility_id IN ($F1, $F2)");
    $pdo->exec("DELETE FROM $ft WHERE facility_id IN ($F1, $F2)");
    $pdo->exec('DELETE FROM news_facts WHERE id >= 990000');
    $pdo->exec('DELETE FROM news_submissions WHERE id >= 990100');
    $pdo->exec('DELETE FROM news_facility_links WHERE news_id = 990101');
    $pdo->exec("DELETE FROM wpdl_kop_auto_connect WHERE source LIKE 'news-facts%'");
}
