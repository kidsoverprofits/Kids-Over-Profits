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

    // 7. The AI step (d) with a stubbed AI (no network): what it answers, the engine checks and writes.
    kop_test_fact_fold_ai($check, $w, $get, $mkdoc, $F1);
    // 8. The owner's four decisions of 2026-11-03.
    kop_test_fact_fold_decisions($check, $w, $get, $mkdoc, $F1);

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

/**
 * The AI step (d) on made-up lines of record $F1, with $GLOBALS['kop_fold_ai_call'] answering instead of a provider:
 * same_as an on-hand line clears, same_as a waiting line folds, differs marks a conflict, a bad reply changes nothing,
 * the cache saves the second call, a spent budget stops only the AI, kop_fact_differs() overrules an AI "same", Undo.
 */
function kop_test_fact_fold_ai(callable $check, callable $w, callable $get, callable $mkdoc, $F1) {
    require_once dirname(__DIR__, 2) . '/api/ai-usage.php';
    $pdo = $GLOBALS['pdo'];
    $wt = 'wpdl_kop_woodbury_facts';
    $inc = 'criticalIncidents.customIncidents';
    $pdo->exec("DELETE FROM $wt WHERE facility_id = $F1");
    $doc = $mkdoc();
    $doc['criticalIncidents']['customIncidents'][] = '2024-03-10: Injury: A resident broke her wrist when staff pinned her to the gym floor during a restraint.';
    $pdo->prepare('UPDATE facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(json_encode($doc), $F1));
    unset($GLOBALS['kop_test_options'][kop_fold_ai_cache_name('r:' . $F1)]);

    $h1 = $w($F1, $inc, 'Injury: A resident\'s wrist was fractured when workers held her on the gymnasium floor.', '2024-04-02', 301);
    $s1 = $w($F1, $inc, '2025-02-11: Injury: Staff locked a boy in a seclusion room without food, state records show.', '2025-03-01', 302);
    $s2 = $w($F1, $inc, 'Injury: A boy was kept alone in a seclusion room with no meals, a state report found after an inspection.', '2025-03-05', 303);
    $d1 = $w($F1, $inc, 'Injury: Staff ignored a camper\'s asthma attack on a mountain hike.', '2025-07-01', 304);
    $d2 = $w($F1, $inc, 'Injury: Counselors waited before calling for help when a camper had an asthma attack at the lake.', '2025-07-03', 305);

    $calls = 0;
    $plan = 'bad';
    $GLOBALS['kop_fold_ai_call'] = function ($prompt) use (&$calls, &$plan) {
        $calls++;
        if ($plan === 'bad') return 'Sorry, here is my reading: line 1 looks like line 2.';
        $num = array();
        foreach (explode("\n", $prompt) as $l) if (preg_match('/^(\d+)\. (?:WAITING|ON HAND): (.*)$/', $l, $m)) $num[(int) $m[1]] = $m[2];
        preg_match('/^Judge: (.*)$/m', $prompt, $jm);
        $find = function ($frag) use ($num) { foreach ($num as $n => $t) if (strpos($t, $frag) !== false) return $n; return 0; };
        $out = array();
        foreach (array_map('intval', explode(',', $jm[1] ?? '')) as $n) {
            $ans = array('line' => $n, 'answer' => 'new');
            foreach ($plan as $frag => $v) {
                if (strpos($num[$n] ?? '', $frag) === false) continue;
                $ans = array('line' => $n, 'answer' => $v[0]);
                if (isset($v[1])) $ans['to'] = $find($v[1]);
            }
            $out[] = $ans;
        }
        return json_encode(array('verdicts' => $out));
    };
    $GLOBALS['kop_fold_ai_providers'] = array('groq', 'gemini');
    $GLOBALS['kop_ai_budget_counts'] = array('start' => kop_ai_budget_day_start());   // nothing spent today
    $run = function () use ($F1) { return kop_fold_run(true, 60, array('only' => array('r:' . $F1), 'ai' => 'live')); };
    $waiting = function () use ($pdo, $wt, $F1) { return (int) $pdo->query("SELECT COUNT(*) FROM $wt WHERE facility_id = $F1 AND status = 'pending'")->fetchColumn(); };

    try {
        // A reply that cannot be read changes nothing.
        $S = $run();
        $check('fact-fold AI: the close lines go to one call', $calls === 1 && ($S['ai_candidates'] ?? 0) >= 3, json_encode(array($calls, $S['ai_candidates'] ?? 0, $S['ai_errors'] ?? null)));
        $check('fact-fold AI: a bad reply changes nothing', $waiting() === 5 && ($S['ai_bad_replies'] ?? 0) === 1 && strpos((string) $get($d1)['extra'], 'ai_differs') === false);

        // A spent budget stops the AI part only: the rules still fold two word-for-word tellings.
        $r1 = $w($F1, $inc, '2022-05-04: Death: A 15-year-old resident died after staff restrained him face down in the dorm.', '2022-06-01', 306);
        $r2 = $w($F1, $inc, '2022-05-04: Death: A 15-year-old resident died after staff restrained him face down in the dorm.', '2022-06-09', 307);
        $GLOBALS['kop_ai_budget_counts'] = array('start' => kop_ai_budget_day_start(), 'groq' => 100000, 'gemini' => 100000);
        $S = $run();
        $check('fact-fold AI: a spent budget makes no call and says so', $calls === 1 && $S['ai_stopped'] === 'budget', json_encode(array($calls, $S['ai_stopped'])));
        $check('fact-fold AI: ...and the rules still fold', $get($r2)['status'] === 'merged' || $get($r1)['status'] === 'merged');
        $check('fact-fold AI: ...and nothing the AI would judge moved', $get($h1)['status'] === 'pending' && $get($s2)['status'] === 'pending');
        $GLOBALS['kop_ai_budget_counts'] = array('start' => kop_ai_budget_day_start());

        // The answers: h1 is the record's line in other words, s2 tells s1 again, d1 disagrees with d2.
        $plan = array('wrist was fractured' => array('same_as', 'broke her wrist'), 'kept alone in a seclusion' => array('same_as', 'locked a boy'),
            'mountain hike' => array('differs', 'at the lake'));
        $S = $run();
        $rh = $get($h1);
        $ah = json_decode((string) $rh['applied'], true);
        $check('fact-fold AI: same_as an on-hand line clears it (how ai, "Already on hand in other words")', $rh['status'] === 'rejected' && $rh['reviewed_by'] === 'auto'
            && ($ah['how'] ?? '') === 'ai' && strpos($ah['reason'] ?? '', 'Already on hand in other words: ') === 0 && ($ah['cleared'] ?? '') === 'on_hand', json_encode($ah));
        $m = $get($s1)['status'] === 'merged' ? $s1 : $s2;
        $k = $m === $s1 ? $s2 : $s1;
        $am = json_decode((string) $get($m)['applied'], true);
        $check('fact-fold AI: same_as a waiting line folds them (how ai), the kept card cites both', $get($m)['status'] === 'merged' && $get($k)['status'] === 'pending'
            && ($am['how'] ?? '') === 'ai' && count(kop_wbf_evidence($get($k))) === 2, json_encode(array($get($s1)['status'], $get($s2)['status'], $am)));
        $xd = json_decode((string) $get($d1)['extra'], true);
        $check('fact-fold AI: differs leaves it waiting, marked as a conflict', $get($d1)['status'] === 'pending' && $get($d2)['status'] === 'pending'
            && strpos($xd['ai_differs']['line'] ?? '', 'at the lake') !== false, json_encode($xd));
        $item = kop_rinbox_get_item('woodbury-facts', $d1);
        $check('fact-fold AI: ...and its card shows the Conflict mark', strpos((string) ($item['conflict'] ?? ''), 'AI check') !== false, (string) ($item['conflict'] ?? ''));
        $before = $calls;
        $S = $run();
        $check('fact-fold AI: an unchanged record costs no second call (cache)', $calls === $before && $get($d1)['status'] === 'pending'
            && strpos((string) $get($d1)['extra'], 'ai_differs') !== false, json_encode(array($before, $calls)));

        // Undo of an AI fold: back to waiting, its citation off the kept card, not folded again (a person has it now).
        kop_fold_undo_member('wbf', $get($m), 'tester');
        $check('fact-fold AI: Undo restores an AI fold', $get($m)['status'] === 'pending' && count(kop_wbf_evidence($get($k))) === 1);
        $run();
        $check('fact-fold AI: ...and it is not folded again', $get($m)['status'] === 'pending');

        // kop_fact_differs() overrules an AI "same": two tellings with other numbers never fold, whatever the answer.
        $o1 = $w($F1, $inc, '2023-09-14: Injury: 4 residents were hurt when staff restrained them in the dining hall.', '2023-10-01', 308);
        $o2 = $w($F1, $inc, '2023-09-14: Injury: 7 residents were hurt when staff restrained them in the dining hall.', '2023-10-02', 309);
        $GLOBALS['kop_test_filters']['kop_fold_ai_pairs'] = array($o2 => array('v' => 'same_as', 'to' => $o1), $o1 => array('v' => 'same_as', 'to' => $o2));
        $S = $run();
        unset($GLOBALS['kop_test_filters']['kop_fold_ai_pairs']);
        $check('fact-fold AI: an AI "same" over different numbers is overruled', $get($o1)['status'] === 'pending' && $get($o2)['status'] === 'pending' && ($S['ai_overruled'] ?? 0) >= 1,
            json_encode(array($get($o1)['status'], $get($o2)['status'], $S['ai_overruled'] ?? 0)));

        // The reply checks: out of range, pointing at itself, a line not asked.
        $p = kop_fold_ai_parse('{"verdicts": [{"line": 9, "answer": "new"}, {"line": 1, "answer": "same_as", "to": 1}, {"line": 2, "answer": "same_as", "to": 7},
            {"line": 3, "answer": "same_as", "to": 1}, {"line": 2, "answer": "differs 3"}]}', array('a', 'b', 'c'), array('a', 'b'));
        $check('fact-fold AI: the reply check drops numbers out of range, self-matches and lines not asked', $p === array('b' => array('v' => 'differs', 'to' => 'c')), json_encode($p));
        $cy = kop_fold_ai_drop_cycles(array('a' => array('v' => 'same_as', 'to' => 'b'), 'b' => array('v' => 'same_as', 'to' => 'c'), 'c' => array('v' => 'same_as', 'to' => 'a'),
            'd' => array('v' => 'same_as', 'to' => 'e'), 'e' => array('v' => 'same_as', 'to' => 'd')), array_fill_keys(array('a', 'b', 'c', 'd', 'e'), 'x'));
        $check('fact-fold AI: a loop of three answers is dropped, a pair naming each other stands', array_keys($cy) === array('d', 'e'), json_encode($cy));
    } finally {
        unset($GLOBALS['kop_fold_ai_call'], $GLOBALS['kop_fold_ai_providers'], $GLOBALS['kop_ai_budget_counts'], $GLOBALS['kop_test_filters']['kop_fold_ai_pairs']);
        $pdo->exec("DELETE FROM $wt WHERE facility_id = $F1");
    }
}

/**
 * The owner's four decisions of 2026-11-03 on made-up lines of record $F1: an undated incident folds with a dated one any
 * number of years apart; an "alleged" telling folds with a "found" one and the found wording is kept; a state's closure
 * order never folds or clears against a closure fact; a no-record Woodbury item moves onto the one record its program
 * name is exactly (two holders: it stays).
 */
function kop_test_fact_fold_decisions(callable $check, callable $w, callable $get, callable $mkdoc, $F1) {
    global $wpdb;
    $pdo = $GLOBALS['pdo'];
    $wt = 'wpdl_kop_woodbury_facts';
    $inc = 'criticalIncidents.customIncidents';
    $run = function () use ($F1) { return kop_fold_run(true, 60, array('only' => array('r:' . $F1))); };
    $pdo->exec("DELETE FROM $wt WHERE facility_id = $F1");
    $pdo->prepare('UPDATE facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(json_encode($mkdoc()), $F1));

    // 1. Undated with dated, seven years apart; two different dates never join.
    $line = 'A 13-year-old resident suffered a fractured jaw and a traumatic brain injury when staff slammed him into the ground at the school.';
    $d1 = $w($F1, $inc, '2019-05-14: Injury: ' . $line, '2019-06-15', 401);
    $u1 = $w($F1, $inc, 'Injury: ' . $line, '2026-07-17', 402);
    $d2 = $w($F1, $inc, '2021-02-03: Injury: ' . $line, '2021-03-01', 403);
    $run();
    $states = array_map(function ($pk) use ($get) { return $get($pk)['status']; }, array($d1, $u1, $d2));
    $check('fact-fold decisions: an undated incident folds with a dated one seven years apart', count(array_keys($states, 'merged', true)) === 1 && $get($u1)['status'] === 'merged', json_encode($states));
    $check('fact-fold decisions: ...and two different dates never join (the other dated telling stays)', $get($d1)['status'] === 'pending' && $get($d2)['status'] === 'pending');
    $fa = kop_fact_shape('wbf', $get($d1));
    $fb = kop_fact_shape('wbf', $get($u1));
    $check('fact-fold decisions: the shapes say so (dates fit, nothing differs, rule "text")', kop_fact_dates_fit($fa, $fb) && kop_fact_differs($fa, $fb) === '' && kop_fact_rule_same($fa, $fb) === 'text');
    $pdo->exec("DELETE FROM $wt WHERE facility_id = $F1");

    // 2. Alleged with found: one card, the found telling's wording kept, whatever the queue order or length.
    $al = $w($F1, $inc, '2021-03-04: Abuse: Staff repeatedly beat a 14-year-old resident at the school, according to two former students (alleged).', '2021-04-01', 411,
        array('extra' => json_encode(array('via' => 'news', 'news_id' => 411, 'category' => 'abuse', 'standing' => 'alleged'))));
    $fo = $w($F1, $inc, '2021-03-04: Abuse: Staff beat a 14-year-old resident at the school (found by regulators).', '2021-09-10', 412,
        array('extra' => json_encode(array('via' => 'news', 'news_id' => 412, 'category' => 'abuse', 'standing' => 'found'))));
    $sa = kop_fact_shape('wbf', $get($al));
    $sf = kop_fact_shape('wbf', $get($fo));
    $check('fact-fold decisions: standing ranks found 2, alleged 0, unstated 1', kop_fact_standing_rank($sf) === 2 && kop_fact_standing_rank($sa) === 0
        && kop_fact_standing_rank(array('standing' => '', 'text' => 'Staff beat a resident.')) === 1);
    $check('fact-fold decisions: standing never keeps them apart (no reason, rule "event")', kop_fact_differs($sa, $sf) === '' && kop_fact_rule_same($sa, $sf) === 'event');
    $sa['queue'] = $sf['queue'] = 'wbf';
    $check('fact-fold decisions: the keeper is the found telling even though the alleged one is longer and listed first', kop_fold_pick(array($sa, $sf), array(0, 1)) === 1
        && kop_fold_pick(array($sf, $sa), array(0, 1)) === 0);
    $fn = $sf;
    $fn['queue'] = 'news';
    $check('fact-fold decisions: a news fact is kept only when the group has no Woodbury or Fornits item', kop_fold_pick(array($sa, $fn), array(0, 1)) === 0
        && kop_fold_pick(array($fn, $fn), array(0, 1)) === 0);
    $run();
    $ka = $get($al);
    $kf = $get($fo);
    $check('fact-fold decisions: an alleged telling folds with the found one of the same event', $ka['status'] === 'merged' && $kf['status'] === 'pending', json_encode(array($ka['status'], $kf['status'])));
    $check('fact-fold decisions: ...and the card kept is the found telling (its wording), citing both', strpos($kf['label'], 'found by regulators') !== false && count(kop_wbf_evidence($kf)) === 2);
    $pdo->exec("DELETE FROM $wt WHERE facility_id = $F1");

    // 3. A closure order is not the closure.
    $co = $w($F1, $inc, '2019-06-01: Closure order: The state ordered the program closed after finding child abuse at the school.', '2019-06-20', 421,
        array('extra' => json_encode(array('via' => 'news', 'news_id' => 421, 'category' => 'closure_order'))));
    $lic = $w($F1, $inc, '2019-06-01: License action: The state revoked the school license after finding child abuse at the school.', '2019-06-20', 424,
        array('extra' => json_encode(array('via' => 'news', 'news_id' => 424, 'category' => 'license_action'))));
    $cl = $w($F1, 'operatingPeriod.status', 'Closed in 2019', '2019-07-01', 422, array('grp' => 'details', 'op' => 'set_closed', 'value' => json_encode(array('endYear' => '2019')),
        'label' => 'Closed in 2019: The state ordered the program closed after finding child abuse at the school.'));
    $sco = kop_fact_shape('wbf', $get($co));
    $slic = kop_fact_shape('wbf', $get($lic));
    $scl = kop_fact_shape('wbf', $get($cl));
    $check('fact-fold decisions: a closure order and a license revocation are not closures, a set_closed is', kop_fact_is_closure_order($sco) && kop_fact_is_closure_order($slic)
        && !kop_fact_is_closure_order($scl) && $scl['kind'] === 'closure');
    $check('fact-fold decisions: the rules never join them, either way round', kop_fact_differs($sco, $scl) === 'closure order' && kop_fact_differs($scl, $sco) === 'closure order'
        && kop_fact_rule_same($sco, $scl) === '' && kop_fact_rule_same($scl, $slic) === '' && !kop_fact_close($sco, $scl) && !kop_fact_close($scl, $slic));
    $check('fact-fold decisions: an AI "same" is overruled (closure order)', kop_fold_ai_apart($sco, $scl) === 'closure order' && kop_fold_ai_apart($scl, $slic) === 'closure order');
    $run();
    $check('fact-fold decisions: the run folds none of the three', $get($co)['status'] === 'pending' && $get($cl)['status'] === 'pending' && $get($lic)['status'] === 'pending');
    // The on-hand check: a record line "Closed (...) per news report" does not clear the order.
    $doc = $mkdoc();
    $doc['operatingPeriod']['notes'] = array('Closed (2019-06-01) per news report: The state ordered the program closed after finding child abuse at the school.');
    $row = $get($co);
    $covers = kop_wbf_list_covers($doc, $inc, kop_wbf_row_value($row));
    $check('fact-fold decisions: (fixture bites) the legacy text check alone would call the order covered', $covers !== '', $covers);
    $check('fact-fold decisions: the on-hand check does not clear a closure order against the closure note', kop_wbf_on_record($doc, $row) === '');
    $doc2 = $mkdoc();
    $doc2['criticalIncidents']['customIncidents'] = array('2019-06-01: Closure order: The state ordered the program closed after finding child abuse at the school.');
    $check('fact-fold decisions: an order against an order line still clears (the same kind of fact)', kop_wbf_on_record($doc2, $row) !== '');
    $pdo->exec("DELETE FROM $wt WHERE facility_id = $F1");

    // 4. A no-record Woodbury item takes the one record its program name is exactly.
    $one = $two = null;
    foreach ($pdo->query('SELECT id, name FROM facilities_v2 WHERE id < 900000 ORDER BY id LIMIT 6000')->fetchAll(PDO::FETCH_ASSOC) as $f) {
        list($id, $how) = kop_autoconn_exact_record($pdo, $f['name']);
        if ($how === 'exact' && !$one && $id === (int) $f['id']) $one = $f;
        if ($how === 'ambiguous' && !$two) $two = $f;
        if ($one && $two) break;
    }
    if (!$one || !$two) { echo "  (the scratch copy has no exact and no ambiguous record name: the Woodbury link checks are not run)\n"; return; }
    $pk = array();
    $n = 0;
    foreach (array('one' => $one['name'], 'two' => $two['name'], 'none' => 'Nonexistent Fixture Academy of Nowhere') as $k => $prog) {
        $n++;
        $pk[$k] = $w(0, 'staff.notableStaff', 'Staff: Pat Roe, Director', '2026-01-10', 430 + $n, array('program' => $prog, 'program_as_written' => $prog, 'match_kind' => 'none',
            'grp' => 'staff', 'op' => 'add_staff', 'value' => json_encode(array('name' => 'Pat Roe', 'role' => 'Director', 'pastJobs' => '')), 'label' => 'Staff: Pat Roe, Director'));
    }
    // Park the scratch copy's own no-record items so the pass touches only these.
    $keep = "'" . implode("','", $pk) . "'";
    $pdo->exec("UPDATE $wt SET status = 'zzpark' WHERE status = 'pending' AND facility_id = 0 AND pkey NOT IN ($keep)");
    try {
        $found = array_column(kop_autoconn_woodbury_facts_rows(), 2, 0);
        $check('fact-fold decisions: the link finds the item whose program is exactly one record', ($found[$pk['one']] ?? 0) === (int) $one['id'] && !isset($found[$pk['two']]) && !isset($found[$pk['none']]),
            json_encode(array($found, $one['id'])));
        $linked = kop_autoconn_woodbury_facts(microtime(true) + 30);
        $r1 = $get($pk['one']);
        $x1 = json_decode($r1['extra'], true);
        $check('fact-fold decisions: it moves onto that record (facility_id, match id) and nothing else moves', $linked === 1 && (int) $r1['facility_id'] === (int) $one['id'] && $r1['match_kind'] === 'id'
            && $r1['status'] === 'pending' && !empty($x1['auto_linked']) && (int) $get($pk['two'])['facility_id'] === 0 && (int) $get($pk['none'])['facility_id'] === 0, json_encode(array($linked, $r1['facility_id'], $x1)));
        $check('fact-fold decisions: two holders of the name (ambiguous) stay without a record', (int) $get($pk['two'])['facility_id'] === 0);
        $check('fact-fold decisions: the link is in the auto-connect memory (source woodbury-facts-link)', isset(kop_autoconn_tried('woodbury-facts-link')[$pk['one']]));
        $m = call_user_func(kop_rinbox_source('woodbury-facts')['act'], $pk['one'], 'undo', array());
        $r1 = $get($pk['one']);
        $check('fact-fold decisions: Undo puts it back without a record', (int) $r1['facility_id'] === 0 && $r1['match_kind'] === 'none' && $r1['status'] === 'pending'
            && empty(json_decode($r1['extra'], true)['auto_linked']), $m['message']);
        $check('fact-fold decisions: an unlinked item is never linked again', kop_autoconn_woodbury_facts(microtime(true) + 30) === 0 && (int) $get($pk['one'])['facility_id'] === 0);
    } finally {
        $pdo->exec("UPDATE $wt SET status = 'pending' WHERE status = 'zzpark'");
        $pdo->exec("DELETE FROM $wt WHERE pkey IN ($keep)");
        $pdo->exec("DELETE FROM wpdl_kop_auto_connect WHERE source = 'woodbury-facts-link'");
        $pdo->exec("DELETE FROM $wt WHERE facility_id = $F1");
    }
}
