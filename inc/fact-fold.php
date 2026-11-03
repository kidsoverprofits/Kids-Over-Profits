<?php
/**
 * One card per fact, live (docs/plan/4.4 item 20, steps b to g; owner, 2026-11-02: "just fold everything").
 *
 * Runs on its own hourly event (kop_fold_hourly_event, half an hour after the Woodbury Facts hook and its kop_wbf_clear_on_record()), per record
 * (and per program key for Woodbury items with no record), time-boxed and resumable (option kop_fold_state):
 *
 *   b  Clear. A waiting item already on hand is filed as rejected / dropped by 'auto' with its reason: the record's
 *      own doc, the record's held items laid over a copy of it (kop_wbf_doc_apply()), the other queues' held and kept
 *      items. Reason "Already on the record: <line>" (applied.cleared = on_hand) or "Held for the write-up: <line>"
 *      (applied.cleared = held). A different value is a conflict and is never cleared.
 *   c  Group the waiting items of a record by the shape's block keys and the rules (kop_fact_blocks(),
 *      kop_fact_rule_same()). Groups never cross records; kop_fact_differs() keeps numbers, dates, names and
 *      campuses apart.
 *   e  Fold. One item stays per group (a Woodbury item first, then a Fornits item, then the longest wording). The
 *      others get status 'merged' and their citations go onto the kept item:
 *        - Woodbury member: extra.merged_into = kept pkey, applied {"merged_into": "woodbury:<pkey>"}.
 *        - Fornits member: applied {"merged_into": "<queue>:<pkey>"} (queue = woodbury | fornits).
 *        - news fact member: route 'merged', status 'merged', wbf_pkey = kept pkey (either queue), reviewed_by 'auto'.
 *          Its reason / target also sits in {prefix}kop_auto_connect (source news-facts-fold, no schema change).
 *      Citations: a kept Woodbury item's evidence list takes them, each tagged "folded_from": [member ids]; the card's
 *      extra.folded lists the members. A kept Fornits item keeps them in applied.folds (it has no extra column);
 *      kop_fornits_doc_apply() cites them. A group whose best item would be a news fact (no Woodbury or Fornits item
 *      in it) is NOT folded: a waiting news fact has no place to hold other citations.
 *   An item a person edited (extra.edited_by) or sent back (reviewed_by not empty and not 'auto'), and any item
 *   Undo put back (it gets a person as reviewed_by and extra.fold_undone), is never cleared or folded again.
 *   d  AI for "the same in other words" (kop_fold_ai_judge()): after the rules, one call a unit (split past
 *      KOP_FOLD_AI_MAX_LINES) through kop_ai_generate_alternating(), job "Fact fold: same in other words", within the
 *      daily budget and KOP_FOLD_AI_CALLS_PER_RUN. Per waiting line: same_as an on-hand line = cleared (how 'ai'),
 *      same_as a waiting line = folded (how 'ai'), differs = a Conflict mark on the card (kop_fold_write_differs()),
 *      new = nothing. kop_fact_differs() overrules an AI "same". Answers are remembered per line (option
 *      kop_fold_ai_<md5 unit>), so an unchanged record costs nothing; put-off units wait on kop_fold_state.ai_todo.
 *
 * Undo: kop_fold_undo_member() (Woodbury, Fornits), kop_nf_undo_auto() (news facts: folded, cleared, linked).
 * kop_nf_link_waiting() links waiting news facts to records by the exact-name rule (docs/claude/fact-shape.md 7.4).
 * docs/DATA-FLOWS.md has the write paths; scripts/fact-dedupe.php --engine and scripts/fold-dry-run.php count.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_normalize_urls') && is_file(dirname(__DIR__) . '/api/url-dedupe.php')) {
    require_once dirname(__DIR__) . '/api/url-dedupe.php';
}

/* ---- Small helpers ------------------------------------------------------------------------------------------ */

function kop_fold_pdo() {
    if (function_exists('kop_seed_pdo')) {
        $p = kop_seed_pdo();
        if ($p instanceof PDO) return $p;
    }
    return (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) ? $GLOBALS['pdo'] : null;
}

function kop_fold_cut($s, $n = 240) {
    $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
    return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
}

function kop_fold_ops() {
    return array('add_staff', 'add_list', 'set_if_empty', 'set_closed');
}

/** Whether the autoconn table exists (the mirror used by the dry run may not have it). */
function kop_fold_autoconn_ready() {
    global $wpdb;
    static $ok = null;
    if ($ok === null) $ok = (bool) $wpdb->get_var("SHOW TABLES LIKE '" . kop_autoconn_table() . "'");
    return $ok;
}

function kop_fold_tried($source) {
    return kop_fold_autoconn_ready() ? kop_autoconn_tried($source) : array();
}

/** The note kop_autoconn_mark() stored for a source and key, decoded; array() when there is none. */
function kop_fold_mark_note($source, $key) {
    global $wpdb;
    if (!kop_fold_autoconn_ready()) return array();
    $n = $wpdb->get_var($wpdb->prepare('SELECT note FROM ' . kop_autoconn_table() . ' WHERE source = %s AND item_key = %s', $source, (string) $key));
    $j = json_decode((string) $n, true);
    return is_array($j) ? $j : array();
}

/** A line in Review inbox > Recently done, with the queue's own Undo. */
function kop_fold_log($source, $key, $title, $message) {
    if (!function_exists('kop_rinbox_log_insert')) return;
    try {
        kop_rinbox_log_insert(array('source' => $source, 'item_key' => (string) $key, 'title' => (string) $title, 'action' => 'auto',
            'action_label' => 'Folded together automatically', 'style' => 'approve', 'message' => (string) $message, 'undo_action' => 'undo', 'user_login' => 'auto'));
    } catch (Throwable $e) {
        // The log never stops the fold.
    }
}

/* ---- Evidence of folded members ----------------------------------------------------------------------------- */

function kop_fold_same_cite(array $a, array $b) {
    $ua = (string) ($a['url'] ?? '');
    $ub = (string) ($b['url'] ?? '');
    if ($ua !== '' || $ub !== '') return $ua === $ub;
    return (string) ($a['cite'] ?? '') === (string) ($b['cite'] ?? '') && (string) ($a['label'] ?? '') === (string) ($b['label'] ?? '');
}

/** $new citations added to a kept item's evidence list, each tagged with the member id it came from; one already there is shared. */
function kop_fold_add_ev(array $ev, array $new, $mid) {
    foreach ($new as $n) {
        if (!is_array($n)) continue;
        $hit = false;
        foreach ($ev as $i => $e) {
            if (!is_array($e) || !kop_fold_same_cite($e, $n)) continue;
            if (!empty($e['folded_from'])) {
                $ev[$i]['folded_from'] = array_values(array_unique(array_merge((array) $e['folded_from'], array($mid))));
            }
            $hit = true;
            break;
        }
        if (!$hit) {
            $n['folded_from'] = array($mid);
            $ev[] = $n;
        }
    }
    return $ev;
}

/**
 * kop_wbf_sync() rewrites a waiting row from the build: the fold marks (extra.folded, extra.ai_differs) and the citations folded in
 * (evidence entries with "folded_from") are put back on the rewritten row. $row is the row about to be stored,
 * $extra the build's extra, $ev the build's evidence.
 */
function kop_fold_keep_on_sync(array $row, $pkey, array $extra, array $ev) {
    global $wpdb;
    $old = $wpdb->get_row($wpdb->prepare('SELECT extra, evidence FROM ' . kop_wbf_table() . ' WHERE pkey = %s', $pkey), ARRAY_A);
    if (!$old) return $row;
    $ox = json_decode((string) $old['extra'], true) ?: array();
    if (!empty($ox['folded']) || !empty($ox['ai_differs'])) {
        // The fold marks and the AI step's Conflict mark (kop_fold_write_differs()) outlive a reload.
        foreach (array('folded', 'ai_differs', 'found') as $k) if (!empty($ox[$k])) $extra[$k] = $ox[$k];
        if (!empty($ox['found'])) $extra['standing'] = (string) ($ox['standing'] ?? 'found');
        $row['extra'] = wp_json_encode($extra);
    }
    foreach (kop_wbf_evidence($old) as $e) {
        if (!is_array($e) || empty($e['folded_from'])) continue;
        $have = false;
        foreach ($ev as $n) if (is_array($n) && kop_fold_same_cite($n, $e)) { $have = true; break; }
        if (!$have) $ev[] = $e;
    }
    $row['evidence'] = wp_json_encode(array_values($ev));
    return $row;
}

/** The kept item's evidence without what only $mid brought. */
function kop_fold_remove_ev(array $ev, $mid) {
    $out = array();
    foreach ($ev as $e) {
        if (is_array($e) && !empty($e['folded_from'])) {
            $from = array_values(array_diff((array) $e['folded_from'], array($mid)));
            if (!$from) continue;
            $e['folded_from'] = $from;
        }
        $out[] = $e;
    }
    return $out;
}

/** A Fornits post as a Woodbury evidence entry. */
function kop_fold_fornits_ev(array $r) {
    $month = function_exists('kop_fornits_month') ? kop_fornits_month($r['post_date']) : '';
    return array('cite' => 'Fornits forum, post by ' . ((string) $r['author'] !== '' ? $r['author'] : 'a member') . ($month ? ', ' . $month : ''),
        'url' => kop_fornits_post_url($r['topic_id'], $r['post_n']), 'label' => '', 'page' => 0, 'quote' => mb_substr((string) ($r['quote'] ?? ''), 0, 600),
        'found' => !empty($r['quote_found']), 'pub' => 1, 'date' => substr((string) $r['post_date'], 0, 10));
}

/** A news fact's article as a Woodbury evidence entry (the one kop_nf_wbf_row() makes). */
function kop_fold_news_ev(array $r) {
    $news = array('id' => (int) $r['news_id'], 'publication_name' => (string) ($r['publication_name'] ?? ''), 'article_title' => (string) ($r['article_title'] ?? ''),
        'publication_date' => (string) ($r['publication_date'] ?? ''), 'article_url' => (string) ($r['article_url'] ?? ''));
    return kop_nf_evidence(array('quote' => (string) ($r['quote'] ?? '')), $news, !empty($r['quote_found']));
}

/* ---- The work: records, then program keys ------------------------------------------------------------------- */

/**
 * Work units in order: 'r:<facility id>' for every record with a waiting item in any of the three queues, then
 * 'p:<program key>' for Woodbury items with no record. $news_map (dry run only) adds news facts linked in memory.
 * Returns array(units, noreq) with noreq = key => [program names].
 */
function kop_fold_units(PDO $pdo, array $news_map = array()) {
    global $wpdb;
    $ids = array();
    $ops = "'" . implode("','", kop_fold_ops()) . "'";
    foreach ((array) $wpdb->get_col('SELECT DISTINCT facility_id FROM ' . kop_wbf_table() . " WHERE status = 'pending' AND facility_id > 0 AND op IN ({$ops})") as $f) $ids[(int) $f] = true;
    if (function_exists('kop_fornits_items_table')) {
        foreach ((array) $wpdb->get_col('SELECT DISTINCT facility_id FROM ' . kop_fornits_items_table() . " WHERE status = 'pending' AND facility_id > 0") as $f) $ids[(int) $f] = true;
    }
    try {
        foreach ($pdo->query("SELECT DISTINCT facility_id FROM news_facts WHERE status = 'pending' AND route = 'waiting' AND facility_id > 0")->fetchAll(PDO::FETCH_COLUMN) as $f) $ids[(int) $f] = true;
    } catch (Throwable $e) {
        // No news facts table yet.
    }
    foreach ($news_map as $f) $ids[(int) $f] = true;
    ksort($ids, SORT_NUMERIC);
    $units = array();
    foreach (array_keys($ids) as $f) $units[] = 'r:' . $f;
    $noreq = array();
    foreach ((array) $wpdb->get_col('SELECT DISTINCT program FROM ' . kop_wbf_table() . " WHERE status = 'pending' AND facility_id = 0 AND grp <> 'consultant' AND op IN ({$ops})") as $p) {
        $k = kop_fact_record_key(0, (string) $p);
        if ($k !== '') $noreq[$k][] = (string) $p;
    }
    ksort($noreq);
    foreach (array_keys($noreq) as $k) $units[] = 'p:' . $k;
    return array($units, $noreq);
}

/** Whether $unit comes after $cursor in the order above (records numerically, then program keys). */
function kop_fold_unit_after($unit, $cursor) {
    if ($cursor === '') return true;
    $a = $unit[0] === 'r';
    $b = $cursor[0] === 'r';
    if ($a !== $b) return !$a;     // a record comes before every program key
    if ($a) return (int) substr($unit, 2) > (int) substr($cursor, 2);
    return strcmp($unit, $cursor) > 0;
}

function kop_fold_w_eligible(array $r) {
    if (!in_array($r['op'], kop_fold_ops(), true)) return false;
    if (($r['grp'] ?? '') === 'consultant') return false;
    if (trim((string) ($r['reviewed_by'] ?? '')) !== '') return false;
    $x = (string) ($r['extra'] ?? '');
    return strpos($x, '"edited_by"') === false && strpos($x, '"fold_undone"') === false;
}

function kop_fold_record(PDO $pdo, $fid) {
    $st = $pdo->prepare('SELECT name, json_data FROM facilities_v2 WHERE id = ?');
    $st->execute(array((int) $fid));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $doc = json_decode((string) $row['json_data'], true);
    return is_array($doc) ? array('name' => (string) $row['name'], 'doc' => $doc) : null;
}

/** What one unit holds: the record, its waiting rows of each queue, and what is on hand besides the doc. */
function kop_fold_load($unit, array $x) {
    global $wpdb;
    $pdo = $x['pdo'];
    $wt = kop_wbf_table();
    $ft = function_exists('kop_fornits_items_table') ? kop_fornits_items_table() : '';
    $L = array('unit' => $unit, 'fid' => 0, 'rk' => '', 'name' => '', 'doc' => null, 'W' => array(), 'held_w' => array(), 'F' => array(), 'held_f' => array(),
        'N' => array(), 'kept_n' => array(), 'news_urls' => array());
    if ($unit[0] === 'p') {
        $L['rk'] = substr($unit, 2);
        $progs = $x['noreq'][$L['rk']] ?? array();
        if (!$progs) return $L;
        $in = implode(',', array_fill(0, count($progs), '%s'));
        $L['W'] = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wt} WHERE status = 'pending' AND facility_id = 0 AND program IN ({$in}) ORDER BY id", $progs), ARRAY_A);
        $L['name'] = $progs[0];
        return $L;
    }
    $fid = (int) substr($unit, 2);
    $L['fid'] = $fid;
    $L['rk'] = (string) $fid;
    $rec = kop_fold_record($pdo, $fid);
    if (!$rec) return $L;
    $L['name'] = $rec['name'];
    $L['doc'] = $rec['doc'];
    $L['W'] = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wt} WHERE status = 'pending' AND facility_id = %d ORDER BY id", $fid), ARRAY_A);
    $L['held_w'] = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wt} WHERE status = 'held' AND (applied_fid = %d OR (applied_fid = 0 AND facility_id = %d)) ORDER BY id", $fid, $fid), ARRAY_A);
    if ($ft !== '') {
        // kind IN (...) lets MySQL use KEY kind_status (kind, status, facility_id).
        $kinds = "'" . implode("','", array_keys(kop_fornits_kinds())) . "'";
        $L['F'] = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$ft} WHERE kind IN ({$kinds}) AND status = 'pending' AND facility_id = %d ORDER BY id", $fid), ARRAY_A);
        $L['held_f'] = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$ft} WHERE kind IN ({$kinds}) AND status = 'held' AND applied_fid = %d ORDER BY id", $fid), ARRAY_A);
    }
    $cols = 'n.*, s.publication_name, s.article_title, s.publication_date, s.article_url';
    try {
        $st = $pdo->prepare("SELECT {$cols} FROM news_facts n LEFT JOIN news_submissions s ON s.id = n.news_id
            WHERE n.status = 'pending' AND n.route = 'waiting' AND n.facility_id = ? ORDER BY n.id");
        $st->execute(array($fid));
        $L['N'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $mapped = array_keys(array_filter($x['news_map'] ?? array(), function ($f) use ($fid) { return (int) $f === $fid; }));
        if ($mapped) {
            $in = implode(',', array_map('intval', $mapped));
            foreach ($pdo->query("SELECT {$cols} FROM news_facts n LEFT JOIN news_submissions s ON s.id = n.news_id WHERE n.id IN ({$in}) ORDER BY n.id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['facility_id'] = $fid;
                $L['N'][] = $r;
            }
        }
        $st = $pdo->prepare("SELECT {$cols} FROM news_facts n LEFT JOIN news_submissions s ON s.id = n.news_id WHERE n.status = 'kept' AND n.facility_id = ?");
        $st->execute(array($fid));
        $L['kept_n'] = $st->fetchAll(PDO::FETCH_ASSOC);
        $st = $pdo->prepare('SELECT s.article_url, s.archive_url FROM news_facility_links l JOIN news_submissions s ON s.id = l.news_id WHERE l.facility_id = ?');
        $st->execute(array($fid));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            foreach (kop_normalize_urls(array($r['article_url'], $r['archive_url'])) as $u) $L['news_urls'][$u] = true;
        }
    } catch (Throwable $e) {
        // A mirror without news tables: only Woodbury and Fornits fold.
    }
    return $L;
}

/* ---- Writes ------------------------------------------------------------------------------------------------- */

/** The member's own evidence entries (Woodbury list, a Fornits post, a news article). */
function kop_fold_member_ev($queue, array $row) {
    if ($queue === 'wbf') return kop_wbf_evidence($row);
    if ($queue === 'fornits') return array(kop_fold_fornits_ev($row));
    return array(kop_fold_news_ev($row));
}

/** A staff member's {name, role, pastJobs} of a folded row (a Woodbury add_staff, a Fornits staff item, a news staff fact), or null. */
function kop_fold_member_staff($queue, array $row) {
    if ($queue === 'wbf') {
        if (($row['op'] ?? '') !== 'add_staff') return null;
        $v = json_decode((string) ($row['value'] ?? ''), true);
        return is_array($v) && trim((string) ($v['name'] ?? '')) !== '' ? array('name' => (string) $v['name'], 'role' => (string) ($v['role'] ?? ''), 'pastJobs' => (string) ($v['pastJobs'] ?? '')) : null;
    }
    if ($queue === 'fornits') {
        if (($row['kind'] ?? '') !== 'staff') return null;
        $v = json_decode((string) ($row['value'] ?? ''), true);
        if (!is_array($v) || trim((string) ($v['person'] ?? '')) === '') return null;
        return array('name' => (string) $v['person'], 'role' => trim((string) ($v['role'] ?? '') . (!empty($v['years']) ? ' (' . $v['years'] . ')' : '')), 'pastJobs' => '');
    }
    if (($row['category'] ?? '') !== 'staff' || trim((string) ($row['person'] ?? '')) === '') return null;
    return array('name' => (string) $row['person'], 'role' => (string) ($row['role'] ?? ''), 'pastJobs' => '');
}

/**
 * The news fact a kept card cannot be: when the group's strongest telling (found, convicted, ruled, admitted) is a waiting news
 * fact and the kept Woodbury or Fornits item is not as strong ("alleged" or unstated), that news shape; else null.
 */
function kop_fold_found_news(array $items, array $members, $keep) {
    if (kop_fact_standing_rank($items[$keep]) >= 2) return null;
    foreach ($members as $m) {
        if ($m !== $keep && $items[$m]['queue'] === 'news' && kop_fact_standing_rank($items[$m]) >= 2) return $items[$m];
    }
    return null;
}

/** "woodbury:<pkey>", "fornits:<pkey>" or "news:<id>": a member's id in folded lists. */
function kop_fold_mid($queue, array $row) {
    return $queue === 'wbf' ? 'woodbury:' . $row['pkey'] : ($queue === 'fornits' ? 'fornits:' . $row['pkey'] : 'news:' . (int) $row['id']);
}

function kop_fold_write_clear($queue, array $row, $reason, $cleared, $how = '') {
    global $wpdb;
    $applied = array('reason' => $reason, 'cleared' => $cleared);
    if ($how !== '') $applied['how'] = $how;
    $now = current_time('mysql', true);
    if ($queue === 'wbf') {
        return (int) $wpdb->update(kop_wbf_table(), array('status' => 'rejected', 'auto' => 0, 'reviewed_by' => 'auto', 'reviewed_at' => $now, 'applied' => wp_json_encode($applied)),
            array('pkey' => $row['pkey'], 'status' => 'pending')) > 0;
    }
    if ($queue === 'fornits') {
        return (int) $wpdb->update(kop_fornits_items_table(), array('status' => 'rejected', 'reviewed_by' => 'auto', 'reviewed_at' => $now, 'applied' => wp_json_encode($applied)),
            array('pkey' => $row['pkey'], 'status' => 'pending')) > 0;
    }
    $pdo = kop_fold_pdo();
    $st = $pdo->prepare("UPDATE news_facts SET status = 'dropped', reviewed_by = 'auto', reviewed_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pending' AND route = 'waiting'");
    $st->execute(array((int) $row['id']));
    if ($st->rowCount() < 1) return false;
    kop_autoconn_mark('news-facts-fold', (int) $row['id'], 'cleared', 'done', wp_json_encode($applied));
    return true;
}

/** Fold the members into the kept item: members first, then the kept item takes the citations of those that went through. */
function kop_fold_write_group(array $kept, array $members, array $how, ?array $found = null) {
    global $wpdb;
    $now = current_time('mysql', true);
    $pdo = kop_fold_pdo();
    $kq = $kept['queue'];
    $krow = $kept['row'];
    $kname = $kq === 'wbf' ? 'woodbury' : 'fornits';
    $kline = kop_fold_cut($kq === 'wbf' ? $krow['label'] : ($kept['shape']['text'] ?? ''), 200);
    $done = array();
    foreach ($members as $i => $m) {
        $q = $m['queue'];
        $row = $m['row'];
        $reason = 'Folded into: ' . $kline;
        $applied = array('merged_into' => $kname . ':' . $krow['pkey'], 'reason' => $reason, 'how' => $how[$m['shape']['id']] ?? '');
        $ok = false;
        if ($q === 'wbf') {
            $extra = json_decode((string) $row['extra'], true) ?: array();
            $extra['merged_into'] = $krow['pkey'];
            $extra['merged_into_queue'] = $kname;
            $ok = (int) $wpdb->update(kop_wbf_table(), array('status' => 'merged', 'auto' => 0, 'reviewed_by' => 'auto', 'reviewed_at' => $now,
                'applied' => wp_json_encode($applied), 'extra' => wp_json_encode($extra)), array('pkey' => $row['pkey'], 'status' => 'pending')) > 0;
        } elseif ($q === 'fornits') {
            $ok = (int) $wpdb->update(kop_fornits_items_table(), array('status' => 'merged', 'reviewed_by' => 'auto', 'reviewed_at' => $now, 'applied' => wp_json_encode($applied)),
                array('pkey' => $row['pkey'], 'status' => 'pending')) > 0;
        } else {
            $st = $pdo->prepare("UPDATE news_facts SET route = 'merged', status = 'merged', wbf_pkey = ?, reviewed_by = 'auto', reviewed_at = UTC_TIMESTAMP()
                WHERE id = ? AND status = 'pending' AND route = 'waiting'");
            $st->execute(array((string) $krow['pkey'], (int) $row['id']));
            $ok = $st->rowCount() > 0;
            if ($ok) kop_autoconn_mark('news-facts-fold', (int) $row['id'], $kname . ':' . $krow['pkey'], 'done', wp_json_encode($applied));
        }
        if (!$ok) continue;
        $done[] = $m;
        kop_fold_log($q === 'wbf' ? 'woodbury-facts' : ($q === 'fornits' ? 'fornits' : 'news-facts'), $q === 'news' ? (int) $row['id'] : $row['pkey'],
            kop_fold_cut($m['shape']['text'], 120), $reason . ' (' . ($how[$m['shape']['id']] ?? 'same fact') . ').');
    }
    if (!$done) return 0;
    // A found telling that exists only as a waiting news fact cannot be kept: the card stays and takes its standing and its line ("Found: ...").
    $found_note = null;
    if ($found) {
        foreach ($done as $m) {
            if ($m['queue'] === 'news' && kop_fold_mid('news', $m['row']) === ($found['id'] ?? '')) $found_note = $found;
        }
    }
    if ($kq === 'wbf') {
        $extra = json_decode((string) $krow['extra'], true) ?: array();
        $ev = kop_wbf_evidence($krow);
        $folded = (array) ($extra['folded'] ?? array());
        foreach ($done as $m) {
            $mid = kop_fold_mid($m['queue'], $m['row']);
            $ev = kop_fold_add_ev($ev, kop_fold_member_ev($m['queue'], $m['row']), $mid);
            $entry = array('id' => $mid, 'text' => kop_fold_cut($m['shape']['text'], 240), 'how' => $how[$m['shape']['id']] ?? '');
            // A staff member's role and years stay with the kept card, so its record entry takes the role history when it is written.
            if ($st = kop_fold_member_staff($m['queue'], $m['row'])) $entry['staff'] = $st;
            $folded[] = $entry;
        }
        $extra['folded'] = $folded;
        if ($found_note) {
            $found_note['prev'] = (string) ($extra['standing'] ?? '');
            $extra['found'] = $found_note;
            $extra['standing'] = 'found';
        }
        $wpdb->update(kop_wbf_table(), array('evidence' => wp_json_encode(array_values($ev)), 'extra' => wp_json_encode($extra)), array('pkey' => $krow['pkey']));
    } else {
        $a = json_decode((string) $krow['applied'], true);
        $a = is_array($a) ? $a : array();
        $folds = (array) ($a['folds'] ?? array());
        foreach ($done as $m) {
            $cites = array();
            foreach (kop_fold_member_ev($m['queue'], $m['row']) as $e) $cites[] = array('cite' => (string) ($e['cite'] ?? kop_wbf_ev_label($e)), 'url' => (string) ($e['url'] ?? ''));
            $entry = array('id' => kop_fold_mid($m['queue'], $m['row']), 'text' => kop_fold_cut($m['shape']['text'], 240), 'cites' => $cites, 'how' => $how[$m['shape']['id']] ?? '');
            if ($st = kop_fold_member_staff($m['queue'], $m['row'])) $entry['staff'] = $st;
            $folds[] = $entry;
        }
        $a['folds'] = $folds;
        if ($found_note) $a['found'] = $found_note;
        $wpdb->update(kop_fornits_items_table(), array('applied' => wp_json_encode($a)), array('pkey' => $krow['pkey']));
    }
    return count($done);
}

/* ---- Is it on hand? ----------------------------------------------------------------------------------------- */

function kop_fold_match(array $f, array $pool) {
    foreach ($pool as $o) {
        if (kop_fact_rule_same($f, $o) !== '') return $o;
    }
    return null;
}

/**
 * array(reason, cleared, how) when a waiting Woodbury row is on hand, else null. $docH is the doc with the record's held
 * items laid over it; $other the shapes of held Fornits and kept news items.
 */
function kop_fold_w_on_hand(array $r, ?array $f, array $doc, array $docH, array $other) {
    $why = kop_wbf_on_record($doc, $r);
    if ($why !== '' && !kop_wbf_conflict_parts($doc, $r)) return array('Already on the record: ' . kop_fold_cut($why, 300), 'on_hand', 'record');
    $why = kop_wbf_on_record($docH, $r);
    if ($why !== '' && !kop_wbf_conflict_parts($docH, $r)) return array('Held for the write-up: ' . kop_fold_cut($why, 300), 'held', 'held');
    if ($f && ($hit = kop_fold_match($f, $other))) return array('Held for the write-up: ' . kop_fold_cut($hit['text'], 300), 'held', $hit['queue'] . ' ' . $hit['state']);
    return null;
}

function kop_fold_f_on_hand(array $r, ?array $f, array $doc, array $docH, array $on_doc, array $on_held, array $news_urls) {
    $kind = $r['kind'];
    $v = json_decode((string) $r['value'], true) ?: array();
    if ($kind === 'link') {
        $keys = kop_normalize_urls((string) ($v['url'] ?? ''));
        $key = $keys ? $keys[0] : '';
        if ($key === '') return null;
        foreach ((array) ($doc['resourceLinks'] ?? array()) as $l) {
            if (is_array($l) && in_array($key, kop_normalize_urls($l['url'] ?? ''), true)) return array('Already on the record: in Materials and links', 'on_hand', 'link');
        }
        if (isset($news_urls[$key])) return array('Already on the record: an article linked to this record', 'on_hand', 'news link');
        return null;
    }
    if ($kind === 'staff') {
        $pn = (string) ($v['person'] ?? '');
        $role = trim(($v['role'] ?? '') . (!empty($v['years']) ? ' (' . $v['years'] . ')' : ''));
        $key = kop_wbf_person_key($pn);
        foreach (array('staff.administrator', 'staff.notableStaff') as $p) {
            foreach ((array) kop_wbf_get($doc, $p) as $s) {
                if ($key !== '' && is_array($s) && kop_wbf_person_key($s['name'] ?? '') === $key && trim((string) ($s['role'] ?? '')) !== '') {
                    return array('Already on the record: ' . $s['name'] . ' (' . $s['role'] . ')', 'on_hand', 'staff listed');
                }
            }
        }
        $row = array('op' => 'add_staff', 'path' => 'staff.notableStaff', 'label' => 'Staff: ' . $pn, 'extra' => '', 'evidence' => '[]',
            'value' => wp_json_encode(array('name' => $pn, 'role' => $role, 'pastJobs' => '')), 'facility_id' => (int) $r['facility_id'], 'conflict' => '', 'current_val' => '');
        $why = kop_wbf_on_record($docH, $row);
        if ($why !== '' && !kop_wbf_conflict_parts($docH, $row)) return array('Held for the write-up: ' . kop_fold_cut($why, 300), 'held', 'held staff');
        return null;
    }
    if ($kind === 'incident' && $f) {
        if ($hit = kop_fold_match($f, $on_doc)) return array('Already on the record: ' . kop_fold_cut($hit['text'], 300), 'on_hand', 'record');
        if ($hit = kop_fold_match($f, $on_held)) return array('Held for the write-up: ' . kop_fold_cut($hit['text'], 300), 'held', $hit['queue'] . ' ' . $hit['state']);
        return null;
    }
    if ($kind === 'lead' && $f) {
        if ($hit = kop_fold_match($f, $on_held)) return array('Held for the write-up: ' . kop_fold_cut($hit['text'], 300), 'held', $hit['queue'] . ' ' . $hit['state']);
        return null;
    }
    if ($kind === 'testimony') {
        $id = 'fornits-' . (int) $r['topic_id'] . '-' . (int) $r['post_n'];
        foreach ((array) ($doc['survivorTestimony'] ?? array()) as $t) {
            if (is_array($t) && ($t['id'] ?? '') === $id) return array('Already on the record: this account is on the record', 'on_hand', 'testimony');
        }
    }
    return null;
}

/* ---- Grouping ----------------------------------------------------------------------------------------------- */

function kop_fold_find(array &$p, $x) {
    while ($p[$x] !== $x) { $p[$x] = $p[$p[$x]]; $x = $p[$x]; }
    return $x;
}

function kop_fold_union(array &$p, $a, $b) {
    $a = kop_fold_find($p, $a);
    $b = kop_fold_find($p, $b);
    if ($a !== $b) $p[$b] = $a;
}

/** Pairs (i<j) of a big block: two shared distinctive words, or two dated facts of one type in a year. */
function kop_fold_big_pairs(array $items) {
    $idx = array();
    $pairs = array();
    $dated = array();
    foreach ($items as $i => $it) {
        $cnt = array();
        foreach ($it['dwords'] as $w => $_) {
            foreach ($idx[$w] ?? array() as $j) $cnt[$j] = ($cnt[$j] ?? 0) + 1;
            $idx[$w][] = $i;
        }
        foreach ($cnt as $j => $c) if ($c >= 2) $pairs[$j . ',' . $i] = array($j, $i);
        if ($it['date'] !== '') {
            $k = $it['kind'] . '|' . $it['type'] . '|' . substr($it['date'], 0, 4);
            foreach ($dated[$k] ?? array() as $j) $pairs[$j . ',' . $i] = array($j, $i);
            if (count($dated[$k] ?? array()) < 400) $dated[$k][] = $i;
        }
    }
    return $pairs;
}

/** Candidate pairs of a record's shapes: those sharing a block key (kop_fact_blocks()). */
function kop_fold_block_pairs(array $items) {
    $blocks = array();
    foreach ($items as $i => $f) foreach (kop_fact_blocks($f) as $b) $blocks[$b][] = $i;
    $pairs = array();
    foreach ($blocks as $idx) {
        $n = count($idx);
        if ($n < 2) continue;
        if ($n > 400) {
            $sub = array();
            foreach ($idx as $i) $sub[] = $items[$i];
            foreach (kop_fold_big_pairs($sub) as $pr) {
                $a = $idx[$pr[0]];
                $b = $idx[$pr[1]];
                $pairs[min($a, $b) . ',' . max($a, $b)] = array(min($a, $b), max($a, $b));
            }
            continue;
        }
        for ($x = 0; $x < $n; $x++) for ($y = $x + 1; $y < $n; $y++) {
            $a = min($idx[$x], $idx[$y]);
            $b = max($idx[$x], $idx[$y]);
            $pairs[$a . ',' . $b] = array($a, $b);
        }
    }
    return $pairs;
}

/* ---- The AI step (d): the same fact in other words ---------------------------------------------------------- */

// Lines in one prompt (the asked waiting lines and the lines they are close to). 40 lines of at most 260 characters
// is about 3,500 tokens with the answer, under Groq's free per-minute token limit; a record with more is split.
const KOP_FOLD_AI_MAX_LINES = 40;
const KOP_FOLD_AI_LINE_CHARS = 260;
// Calls per hourly run. The 90 s time box fits about this many (Gemini is paced 4.5 s apart, a call takes 3-10 s),
// and 10 an hour (240 a day at most, while a backlog lasts) leaves the background share of the daily budget
// (0.7 x (500 + 400) = 630) room for the news facts reader (6 an hour) and the hourly enrich.
const KOP_FOLD_AI_CALLS_PER_RUN = 10;
// Part of every line key: a new prompt wording asks again.
const KOP_FOLD_AI_VERSION = 'v2';   // v2 (2026-11-03): the prompt says an undated telling and an alleged / found pair can be one event, a closure order is not a closure

/** The job name the AI usage log and budget know this step by (kop_ai_usage_jobs() names the file the same). */
function kop_fold_ai_job() {
    return 'Fact fold: same in other words';
}

/** The line as the AI reads it: its date (or when it was told, for an undated one) and its words. */
function kop_fold_ai_line(array $f) {
    $when = $f['date'] !== '' ? '(' . $f['date'] . ') ' : (($f['told'] ?? '') !== '' ? '(no date given; told ' . substr((string) $f['told'], 0, 10) . ') ' : '');
    return $when . kop_fold_cut($f['text'], KOP_FOLD_AI_LINE_CHARS);
}

/** A line's cache key: its id and the words sent, so a changed line is asked again. */
function kop_fold_ai_lkey($id, $line) {
    return substr(md5(KOP_FOLD_AI_VERSION . "\n" . $id . "\n" . $line), 0, 16);
}

/** The option that remembers a unit's AI answers (autoload off): {seen: {lkey: 1}, v: {lkey: [verdict, to lkey]}, bad: {chunk: n}, t}. */
function kop_fold_ai_cache_name($unit) {
    return 'kop_fold_ai_' . md5((string) $unit);
}

function kop_fold_ai_prompt($name, array $ids, array $ask, array $rg) {
    $p = 'These are facts about one program, "' . $name . '", from news articles, a newsletter (Woodbury Reports), a survivor forum (Fornits) and the program\'s own record. '
        . "Lines marked ON HAND are already on the record or approved; lines marked WAITING wait for review.\n\n"
        . "For each line listed under \"Judge\", say whether another line tells the same fact:\n"
        . "- same_as <n>: line n tells the same event or fact. Only when who, what, when and where agree. A shorter or less detailed telling of the same event is the same. If an ON HAND line is the same, point to it.\n"
        . "- differs <n>: line n is clearly about the same event, but the two disagree on a date, a number, a person or a place. Whether a telling says \"alleged\" or says the event was found, substantiated, confirmed or ended in a conviction is not a disagreement: they are one event at different stages, so that is same_as.\n"
        . "- new: no other line tells it.\n"
        . "Different dates, numbers, people or places are not the same fact, but a line with no date can be the same event as a dated line, however many years apart; judge it by who, what and where. "
        . "A state's order to close a program (ordered closed, license revoked, cease and desist) is not the program's closing: they are two facts, never same_as. Two events of one kind (two injuries, two inspections, two lawsuits) are not the same; "
        . "two lawsuits are the same only when they are clearly one case (the same parties, case name or filing). When unsure, answer new.\n"
        . "Only judge; never rewrite a line.\n\n"
        . "Return ONLY JSON, no other text: {\"verdicts\": [{\"line\": 3, \"answer\": \"same_as\", \"to\": 1}, {\"line\": 4, \"answer\": \"new\"}, {\"line\": 5, \"answer\": \"differs\", \"to\": 2}]}\n\nLines:\n";
    $num = array();
    foreach ($ids as $i => $id) {
        $num[$id] = $i + 1;
        $p .= ($i + 1) . '. ' . (isset($rg['onhand'][$id]) ? 'ON HAND' : 'WAITING') . ': ' . $rg['lines'][$id] . "\n";
    }
    $p .= "\nJudge: " . implode(', ', array_map(function ($id) use ($num) { return $num[$id]; }, $ask)) . "\n";
    return $p;
}

/**
 * The reply as verdicts [id => {v, to?}] for the asked lines only, or null when it cannot be read. A line number out
 * of range, a line pointing at itself, an answer for a line not asked, or an unknown answer is left out.
 */
function kop_fold_ai_parse($reply, array $ids, array $ask) {
    $reply = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/i', '', trim((string) $reply));
    $data = json_decode($reply, true);
    if (!is_array($data) && preg_match('/\{.*\}/s', $reply, $m)) $data = json_decode($m[0], true);
    if (!is_array($data) || !isset($data['verdicts']) || !is_array($data['verdicts'])) return null;
    $n = count($ids);
    $asked = array_flip($ask);
    $out = array();
    foreach ($data['verdicts'] as $v) {
        if (!is_array($v)) continue;
        $line = (int) ($v['line'] ?? 0);
        $ans = strtolower(trim((string) ($v['answer'] ?? '')));
        $to = (int) ($v['to'] ?? 0);
        if (preg_match('/^(same_as|differs)\s*(\d+)$/', $ans, $m)) { $ans = $m[1]; $to = (int) $m[2]; }
        if ($line < 1 || $line > $n || !isset($asked[$ids[$line - 1]])) continue;
        $id = $ids[$line - 1];
        if ($ans === 'new') { $out[$id] = array('v' => 'new'); continue; }
        if (!in_array($ans, array('same_as', 'differs'), true) || $to < 1 || $to > $n || $to === $line) continue;
        $out[$id] = array('v' => $ans, 'to' => $ids[$to - 1]);
    }
    return $out;
}

/** The AI call (a test sets $GLOBALS['kop_fold_ai_call'] to answer without the network). Throws what the providers threw. */
function kop_fold_ai_call($prompt) {
    if (!empty($GLOBALS['kop_fold_ai_call']) && is_callable($GLOBALS['kop_fold_ai_call'])) return (string) call_user_func($GLOBALS['kop_fold_ai_call'], $prompt);
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    $GLOBALS['kop_ai_job'] = kop_fold_ai_job();
    try {
        return kop_ai_generate_alternating($prompt, array('maxTokens' => 4000, 'temperature' => 0.1));
    } finally {
        unset($GLOBALS['kop_ai_job']);
    }
}

/** Whether any provider still has room in today's background share (api/ai-usage.php) for this job. */
function kop_fold_ai_budget_ok() {
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    require_once get_stylesheet_directory() . '/api/ai-usage.php';
    if (in_array(kop_fold_ai_job(), kop_ai_budget_protected_jobs(), true)) return true;
    $limits = kop_ai_budget_limits();
    $ps = isset($GLOBALS['kop_fold_ai_providers']) ? (array) $GLOBALS['kop_fold_ai_providers'] : kop_ai_alternating_providers();   // tests name them
    foreach ($ps as $p) {
        if (isset($limits[$p]) && kop_ai_budget_used($p) < $limits[$p]['background']) return true;
    }
    return false;
}

/**
 * Verdicts for one unit's close candidates: array(verdicts [id => {v: same_as|new|differs, to?}], state) with state
 * 'none' (nothing to ask), 'done' (every pair judged, now or before), 'deferred' (some left for a later run: the call
 * cap, the budget, the time box or a failed call), 'estimate' (counted only). $rg: record, name, waiting [id => text],
 * onhand [id => text], state [id => ...], lines [id => line as sent], pairs [[id, id], ...], unit.
 * $x: ai ('' | 'estimate' | 'live'), apply, start, seconds, ai_cap. The filter kop_fold_ai_pairs (tests) answers instead.
 */
function kop_fold_ai_judge(array $rg, array &$S, array $x) {
    $forced = apply_filters('kop_fold_ai_pairs', null, $rg);
    if (is_array($forced)) return array($forced, 'done');
    $mode = (string) ($x['ai'] ?? '');
    if ($mode === '' || !$rg['pairs']) return array(array(), 'none');
    $name = kop_fold_ai_cache_name($rg['unit']);
    $cache = $mode === 'live' ? get_option($name, array()) : array();
    $cache = is_array($cache) ? $cache : array();
    $seen = (array) ($cache['seen'] ?? array());
    $cv = (array) ($cache['v'] ?? array());
    $lk = array();
    foreach ($rg['lines'] as $id => $line) $lk[$id] = kop_fold_ai_lkey($id, $line);
    $id_of = array_flip($lk);
    $partners = array();
    foreach ($rg['pairs'] as $pr) {
        list($a, $b) = $pr;
        if (isset($rg['waiting'][$a])) $partners[$a][$b] = true;
        if (isset($rg['waiting'][$b])) $partners[$b][$a] = true;
    }
    // Asked: a waiting line with a partner it has not been sent with before. The rest take their remembered verdicts.
    $ask = array();
    $out = array();
    foreach ($partners as $id => $ps) {
        $judged = isset($seen[$lk[$id]]);
        foreach ($ps as $p => $_) if (!isset($seen[$lk[$p]])) $judged = false;
        if (!$judged) { $ask[] = $id; continue; }
        $v = $cv[$lk[$id]] ?? null;
        if (is_array($v) && isset($v[0])) {
            if ($v[0] === 'new') $out[$id] = array('v' => 'new');
            elseif (isset($v[1], $id_of[$v[1]])) $out[$id] = array('v' => $v[0], 'to' => $id_of[$v[1]]);
        }
    }
    if (!$ask) {
        $S['ai_cached_units'] = ($S['ai_cached_units'] ?? 0) + 1;
        return array($out, 'done');
    }
    // Chunks of at most KOP_FOLD_AI_MAX_LINES lines: each asked line with its partners.
    $chunks = array();
    $cur = array('ids' => array(), 'ask' => array());
    foreach ($ask as $id) {
        $need = array_merge(array($id), array_keys($partners[$id]));
        $add = array_values(array_diff($need, $cur['ids']));
        if ($cur['ask'] && count($cur['ids']) + count($add) > KOP_FOLD_AI_MAX_LINES) {
            $chunks[] = $cur;
            $cur = array('ids' => array(), 'ask' => array());
            $add = $need;
        }
        $cur['ids'] = array_merge($cur['ids'], array_slice($add, 0, max(1, KOP_FOLD_AI_MAX_LINES - count($cur['ids']))));
        if (in_array($id, $cur['ids'], true)) $cur['ask'][] = $id;
    }
    if ($cur['ask']) $chunks[] = $cur;

    $state = 'done';
    $dirty = false;
    foreach ($chunks as $ch) {
        $prompt = kop_fold_ai_prompt($rg['name'], $ch['ids'], $ch['ask'], $rg);
        if ($mode === 'estimate') {
            $S['ai_est']['calls'] = ($S['ai_est']['calls'] ?? 0) + 1;
            $S['ai_est']['lines'] = ($S['ai_est']['lines'] ?? 0) + count($ch['ids']);
            $S['ai_est']['asked'] = ($S['ai_est']['asked'] ?? 0) + count($ch['ask']);
            // About 4 characters a token in, about 20 tokens an answer out.
            $S['ai_est']['tokens'] = ($S['ai_est']['tokens'] ?? 0) + (int) ceil(strlen($prompt) / 4) + 20 * count($ch['ask']);
            $state = 'estimate';
            continue;
        }
        if (!empty($S['ai_stopped'])) { $state = 'deferred'; break; }
        if (($S['ai_calls'] ?? 0) >= (int) ($x['ai_cap'] ?? KOP_FOLD_AI_CALLS_PER_RUN)) { $S['ai_stopped'] = 'call cap'; $state = 'deferred'; break; }
        if (microtime(true) - (float) $x['start'] > (float) $x['seconds'] - 20) { $S['ai_stopped'] = 'time'; $state = 'deferred'; break; }
        if (!kop_fold_ai_budget_ok()) { $S['ai_stopped'] = 'budget'; $state = 'deferred'; break; }
        $chunk_key = substr(md5(implode('|', array_map(function ($id) use ($lk) { return $lk[$id]; }, $ch['ids']))), 0, 12);
        try {
            $S['ai_calls'] = ($S['ai_calls'] ?? 0) + 1;
            $reply = kop_fold_ai_call($prompt);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $S['ai_errors'][] = $rg['unit'] . ': ' . mb_substr($msg, 0, 200);
            // Out of calls (a provider's limit or the budget): no more AI this run; the rules go on.
            if (stripos($msg, 'rate limit') !== false || stripos($msg, 'quota') !== false || stripos($msg, 'No AI key') !== false) $S['ai_stopped'] = 'provider limit';
            $state = 'deferred';
            break;
        }
        $S['ai_lines'] = ($S['ai_lines'] ?? 0) + count($ch['ids']);
        $got = kop_fold_ai_parse($reply, $ch['ids'], $ch['ask']);
        if ($got === null) {
            // A reply that cannot be read changes nothing. Asked again once on a later run, then let go.
            $S['ai_bad_replies'] = ($S['ai_bad_replies'] ?? 0) + 1;
            $bad = (int) ($cache['bad'][$chunk_key] ?? 0) + 1;
            $cache['bad'][$chunk_key] = $bad;
            $dirty = true;
            if ($bad < 2) { $state = 'deferred'; continue; }
            $got = array();
        }
        foreach ($ch['ids'] as $id) $seen[$lk[$id]] = 1;
        foreach ($ch['ask'] as $id) {
            if (!isset($got[$id])) { $cv[$lk[$id]] = array('new'); continue; }
            $v = $got[$id];
            $cv[$lk[$id]] = $v['v'] === 'new' ? array('new') : array($v['v'], $lk[$v['to']]);
            $out[$id] = $v;
            $S['ai_verdicts'][] = array('unit' => $rg['unit'], 'line' => $rg['lines'][$id], 'v' => $v['v'],
                'to' => isset($v['to']) ? (isset($rg['onhand'][$v['to']]) ? '[on hand] ' : '[waiting] ') . $rg['lines'][$v['to']] : '');
        }
        $dirty = true;
    }
    if ($dirty && $mode === 'live' && !empty($x['apply'])) {
        // Keep the newest 3,000 line keys a unit: a record's lines change slowly, so this is every line it still has.
        if (count($seen) > 3000) $seen = array_slice($seen, -3000, null, true);
        if (count($cv) > 3000) $cv = array_slice($cv, -3000, null, true);
        update_option($name, array('seen' => $seen, 'v' => $cv, 'bad' => (array) ($cache['bad'] ?? array()), 't' => time()), false);
    }
    return array($out, $state);
}

/**
 * Why an AI "same" between two facts is not taken ('' = nothing against it): other kinds, kop_fact_differs() (dates,
 * numbers, ages, head counts, places, people), or two incidents of other types (an injury and the neglect or the
 * investigation that followed it are other facts, docs/claude/fact-shape.md 6.5), or a closure order against a closure fact
 * (owner, 2026-11-03: a state's order to close is not the closure). A line with no type or the generic
 * 'incident' (a record line with no label) meets any type.
 */
function kop_fold_ai_apart(array $a, array $b) {
    if (kop_fact_closure_clash($a, $b)) return 'closure order';
    if (kop_fact_group($a) !== kop_fact_group($b)) return 'kind';
    $why = kop_fact_differs($a, $b);
    if ($why !== '') return $why;
    $loose = array('', 'incident');
    if ($a['kind'] === 'incident' && $b['kind'] === 'incident' && !in_array($a['type'], $loose, true) && !in_array($b['type'], $loose, true) && $a['type'] !== $b['type']) return 'type';
    return '';
}

/** Drops same_as loops of three or more waiting lines (a -> b -> c -> a): the answer contradicts itself. A pair naming each other stands. */
function kop_fold_ai_drop_cycles(array $verdicts, array $waiting) {
    $next = array();
    foreach ($verdicts as $id => $v) {
        if (($v['v'] ?? '') === 'same_as' && isset($waiting[$id], $waiting[$v['to'] ?? ''])) $next[$id] = (string) $v['to'];
    }
    foreach (array_keys($next) as $start) {
        $path = array((string) $start);
        $at = (string) $start;
        while (isset($next[$at])) {
            $at = $next[$at];
            $k = array_search($at, $path, true);
            if ($k !== false) {
                $loop = array_slice($path, $k);
                if (count($loop) >= 3) foreach ($loop as $id) unset($verdicts[$id]);
                break;
            }
            $path[] = $at;
        }
    }
    return $verdicts;
}

/** The Conflict mark an AI "differs" leaves on a waiting card ($note: {with, line, state}). */
function kop_fold_ai_conflict_text($note) {
    if (!is_array($note) || empty($note['line'])) return '';
    $where = array('on_record' => 'on the record', 'held' => 'held for the write-up', 'kept' => 'kept from the news', 'waiting' => 'waiting');
    return 'The AI check reads this as the same event as another telling that disagrees on a detail (' . ($where[$note['state'] ?? ''] ?? 'another card') . '): "'
        . kop_fold_cut($note['line'], 200) . '". Check which is right.';
}

/**
 * Sets ($note array) or takes off ($note null) the AI conflict mark on a waiting item: Woodbury extra.ai_differs,
 * Fornits applied.ai_differs (it has no extra column; a waiting kept card already keeps applied.folds there), a news
 * fact a kop_auto_connect row (source news-facts-ai). Returns the row as it is now.
 */
function kop_fold_write_differs($queue, array $row, $note) {
    global $wpdb;
    if ($queue === 'wbf') {
        $extra = json_decode((string) $row['extra'], true) ?: array();
        if ($note === null && !isset($extra['ai_differs'])) return $row;
        if ($note !== null && ($extra['ai_differs'] ?? null) == $note) return $row;
        if ($note === null) unset($extra['ai_differs']); else $extra['ai_differs'] = $note;
        $row['extra'] = wp_json_encode($extra);
        $wpdb->update(kop_wbf_table(), array('extra' => $row['extra']), array('pkey' => $row['pkey'], 'status' => 'pending'));
        return $row;
    }
    if ($queue === 'fornits') {
        $a = json_decode((string) $row['applied'], true);
        $a = is_array($a) ? $a : array();
        if ($note === null && !isset($a['ai_differs'])) return $row;
        if ($note !== null && ($a['ai_differs'] ?? null) == $note) return $row;
        if ($note === null) unset($a['ai_differs']); else $a['ai_differs'] = $note;
        $row['applied'] = $a ? wp_json_encode($a) : null;
        $wpdb->update(kop_fornits_items_table(), array('applied' => $row['applied']), array('pkey' => $row['pkey'], 'status' => 'pending'));
        return $row;
    }
    if (!kop_fold_autoconn_ready()) return $row;
    if ($note === null) {
        if (kop_fold_mark_note('news-facts-ai', (int) $row['id'])) $wpdb->delete(kop_autoconn_table(), array('source' => 'news-facts-ai', 'item_key' => (string) (int) $row['id']));
        return $row;
    }
    kop_autoconn_mark('news-facts-ai', (int) $row['id'], (string) ($note['with'] ?? ''), 'differs', wp_json_encode($note));
    return $row;
}

/** The AI conflict mark of a waiting row of $queue ('wbf' | 'fornits' | 'news'), or null. */
function kop_fold_ai_mark($queue, array $row) {
    if ($queue === 'wbf') { $x = json_decode((string) ($row['extra'] ?? ''), true); return is_array($x) && !empty($x['ai_differs']) ? $x['ai_differs'] : null; }
    if ($queue === 'fornits') { $x = json_decode((string) ($row['applied'] ?? ''), true); return is_array($x) && !empty($x['ai_differs']) ? $x['ai_differs'] : null; }
    if (!function_exists('kop_autoconn_table')) return null;
    $n = kop_fold_mark_note('news-facts-ai', (int) ($row['id'] ?? 0));
    return $n ? $n : null;
}

/* ---- One unit ----------------------------------------------------------------------------------------------- */

function kop_fold_tally(array &$S, $bucket, $queue, $kind) {
    $S[$bucket][$queue][$kind] = ($S[$bucket][$queue][$kind] ?? 0) + 1;
}

function kop_fold_example(array &$S, $bucket, array $e) {
    if (count($S['examples'][$bucket] ?? array()) < 60) $S['examples'][$bucket][] = $e;
}

function kop_fold_qn($queue) {
    return $queue === 'wbf' ? 'woodbury' : $queue;
}

/**
 * Clear, group and fold one unit. $apply false counts only. Counts go into $S. Returns the AI step's state for the unit:
 * 'none', 'done', 'deferred' (judge it again on a later run) or 'estimate' (kop_fold_ai_judge()).
 */
function kop_fold_unit($unit, $apply, array &$S, array $x) {
    global $wpdb;
    $L = kop_fold_load($unit, $x);
    $fid = $L['fid'];
    $rk = $L['rk'];
    $name = $L['name'];
    $doc = $L['doc'];
    // The record's own name is what a city is read against ("The Cleveland Seed"): the table's, else the document's.
    if (trim((string) $name) === '' && is_array($doc)) $name = (string) ($doc['identification']['name'] ?? ($doc['identification']['currentName'] ?? ''));
    $is_rec = $unit[0] === 'r';
    if ($is_rec && !$doc) {
        $S['skipped']['no_doc'] = ($S['skipped']['no_doc'] ?? 0) + count($L['W']) + count($L['F']) + count($L['N']);
        return 'none';
    }
    $ctx = $is_rec ? array('fid' => $fid, 'record_name' => $name) : ($name !== '' ? array('record_name' => $name) : array());

    // The doc with the held items laid over it; the held and kept items of every queue as shapes.
    $docH = $doc;
    if ($doc) {
        foreach ($L['held_w'] as $h) {
            if (!in_array($h['op'], kop_fold_ops(), true)) continue;
            try { kop_wbf_doc_apply($docH, $h); } catch (Throwable $e) { $S['skipped']['apply_failed'] = ($S['skipped']['apply_failed'] ?? 0) + 1; }
        }
    }
    $onhand = $doc ? kop_fact_onhand($doc, $fid, $L['held_w'], $L['kept_n'], $L['news_urls']) : array();
    $other = array();    // held Fornits items: on hand for the Woodbury and news items
    foreach ($L['held_f'] as $h) {
        $f = kop_fact_shape('fornits', $h, array('fid' => $fid, 'state' => 'held', 'record_name' => $name));
        if ($f) { $onhand[] = $f; $other[] = $f; }
    }
    foreach ($onhand as $o) {
        if ($o['state'] === 'kept') $other[] = $o;
    }
    $on_doc = array();
    $on_held = array();
    foreach ($onhand as $o) {
        if ($o['state'] === 'on_record') $on_doc[] = $o; else $on_held[] = $o;
    }

    $items = array();    // shapes of the waiting items still alive
    $ref = array();      // 'queue:id' => array(queue, row, shape)
    $clears = array();   // array(queue, row, shape, reason, cleared, how)
    $clear = function ($queue, array $row, $f, array $hit) use (&$clears) {
        $clears[] = array('queue' => $queue, 'row' => $row, 'shape' => $f, 'reason' => $hit[0], 'cleared' => $hit[1], 'how' => $hit[2]);
    };

    foreach ($L['W'] as $r) {
        $S['checked']['woodbury'] = ($S['checked']['woodbury'] ?? 0) + 1;
        if (!kop_fold_w_eligible($r)) { kop_fold_tally($S, 'left_alone', 'woodbury', $r['path']); continue; }
        $f = kop_fact_shape('wbf', $r, $ctx);
        if ($f && !$is_rec) $f['record'] = $rk;
        if ($doc && ($hit = kop_fold_w_on_hand($r, $f, $doc, $docH, $other))) { $clear('wbf', $r, $f, $hit); continue; }
        if ($f) { $items[] = $f; $ref['wbf:' . $f['id']] = array('queue' => 'wbf', 'row' => $r, 'shape' => $f); }
    }
    foreach ($L['F'] as $r) {
        $S['checked']['fornits'] = ($S['checked']['fornits'] ?? 0) + 1;
        if (trim((string) ($r['reviewed_by'] ?? '')) !== '') { kop_fold_tally($S, 'left_alone', 'fornits', $r['kind']); continue; }
        $f = kop_fact_shape('fornits', $r, $ctx);
        if ($doc && ($hit = kop_fold_f_on_hand($r, $f, $doc, $docH, $on_doc, $on_held, $L['news_urls']))) { $clear('fornits', $r, $f, $hit); continue; }
        if ($f && $r['kind'] !== 'testimony') { $items[] = $f; $ref['fornits:' . $f['id']] = array('queue' => 'fornits', 'row' => $r, 'shape' => $f); }
    }
    foreach ($L['N'] as $r) {
        $S['checked']['news'] = ($S['checked']['news'] ?? 0) + 1;
        if (trim((string) ($r['reviewed_by'] ?? '')) !== '') { kop_fold_tally($S, 'left_alone', 'news', $r['category']); continue; }
        $f = kop_fact_shape('news', $r, $ctx);
        if (!$f) continue;
        if ($hit = kop_fold_match($f, $on_doc)) { $clear('news', $r, $f, array('Already on the record: ' . kop_fold_cut($hit['text'], 300), 'on_hand', 'record')); continue; }
        if ($hit = kop_fold_match($f, $on_held)) { $clear('news', $r, $f, array('Held for the write-up: ' . kop_fold_cut($hit['text'], 300), 'held', $hit['queue'] . ' ' . $hit['state'])); continue; }
        $items[] = $f;
        $ref['news:' . $f['id']] = array('queue' => 'news', 'row' => $r, 'shape' => $f);
    }

    // c: the rules join items of one record.
    $n = count($items);
    $uf = $n ? range(0, $n - 1) : array();
    $how = array();   // shape id => how it matched
    $mem = array();   // group root => its member indexes (to refuse a join that would put two different dates in one group)
    foreach ($items as $i => $_) $mem[$i] = array($i);
    foreach (kop_fold_block_pairs($items) as $pr) {
        list($i, $j) = $pr;
        $ri = kop_fold_find($uf, $i);
        $rj = kop_fold_find($uf, $j);
        if ($ri === $rj) continue;
        $why = kop_fact_rule_same($items[$i], $items[$j]);
        if ($why === '') continue;
        // An undated telling fits every dated one (owner, 2026-11-03), so it must not bring two dated ones that differ into one group.
        $clash = false;
        foreach ($mem[$ri] as $mx) foreach ($mem[$rj] as $my) if (kop_fact_differs($items[$mx], $items[$my]) !== '') { $clash = true; break 2; }
        if ($clash) { $S['rule_clash'] = ($S['rule_clash'] ?? 0) + 1; continue; }
        kop_fold_union($uf, $i, $j);
        $mem[$ri] = array_merge($mem[$ri], $mem[$rj]);
        unset($mem[$rj]);
        $how[$items[$j]['id']] = $why;
    }

    // d: close candidates for the AI step: waiting group reps against each other and against on-hand lines.
    $reps = array();
    foreach ($items as $i => $f) $reps[kop_fold_find($uf, $i)][] = $i;
    $keep_of = array();
    foreach ($reps as $root => $members) $keep_of[$root] = kop_fold_pick($items, $members);
    $dead = array();
    $all = array();
    foreach ($keep_of as $k) $all[] = $items[$k];
    $nrep = count($all);
    foreach ($onhand as $o) $all[] = $o;
    $pairs = array();
    foreach (kop_fold_block_pairs($all) as $pr) {
        list($i, $j) = $pr;
        if ($i >= $nrep && $j >= $nrep) continue;
        if (!kop_fact_close($all[$i], $all[$j]) || kop_fact_rule_same($all[$i], $all[$j]) !== '') continue;
        $pairs[] = array($all[$i]['id'], $all[$j]['id']);
        $S['ai_candidates'] = ($S['ai_candidates'] ?? 0) + 1;
    }
    $ai_state = 'none';
    if ($pairs) {
        $rg = array('unit' => $unit, 'record' => $rk, 'name' => $name, 'waiting' => array(), 'onhand' => array(), 'state' => array(), 'lines' => array(), 'pairs' => $pairs);
        foreach ($all as $k => $f) {
            $bucket = $k < $nrep ? 'waiting' : 'onhand';
            $rg[$bucket][$f['id']] = $f['text'];
            $rg['state'][$f['id']] = $f['state'];
            $rg['lines'][$f['id']] = kop_fold_ai_line($f);
        }
        $by_id = array();
        foreach ($all as $f) $by_id[$f['id']] = $f;
        $item_at = array();
        foreach ($items as $i => $f) $item_at[$f['id']] = $i;
        // The members of a waiting rep's group (the rules may have joined others to it already).
        $members_of = function ($id) use (&$uf, $items, $item_at) {
            $out = array();
            if (!isset($item_at[$id])) return $out;
            $root = kop_fold_find($uf, $item_at[$id]);
            foreach ($items as $i => $f) if (kop_fold_find($uf, $i) === $root) $out[] = $i;
            return $out;
        };
        list($verdicts, $ai_state) = kop_fold_ai_judge($rg, $S, $x);
        $verdicts = kop_fold_ai_drop_cycles((array) $verdicts, $rg['waiting']);
        $marks = array();   // waiting rep id => conflict note | null (take an old mark off)
        foreach ($verdicts as $id => $v) {
            if (!isset($by_id[$id], $rg['waiting'][$id]) || !is_array($v)) continue;
            $vv = (string) ($v['v'] ?? '');
            $a = $by_id[$id];
            $b = isset($v['to']) && isset($by_id[$v['to']]) ? $by_id[$v['to']] : null;
            if ($vv === 'differs' && $b) {
                $marks[$id] = array('with' => $b['id'], 'line' => kop_fold_cut($b['text'], 300), 'state' => $b['state'], 'at' => gmdate('Y-m-d'));
                $S['ai_conflicts'][kop_fold_qn($a['queue'])] = ($S['ai_conflicts'][kop_fold_qn($a['queue'])] ?? 0) + 1;
                kop_fold_example($S, 'ai_conflict', array('record' => $rk, 'name' => $name, 'queue' => kop_fold_qn($a['queue']), 'line' => kop_fold_cut($a['text'], 160), 'into' => kop_fold_cut($b['text'], 160), 'how' => 'differs'));
                continue;
            }
            $marks[$id] = null;
            if ($vv !== 'same_as' || !$b || $b['id'] === $a['id']) continue;
            // An AI "same" over different numbers, dates, names or places, or across kinds, is dropped.
            if (kop_fold_ai_apart($a, $b) !== '') { $S['ai_overruled'] = ($S['ai_overruled'] ?? 0) + 1; continue; }
            if (isset($rg['onhand'][$b['id']])) {
                // On hand in other words: the rep and every item the rules joined to it are cleared.
                $held = $b['state'] !== 'on_record';
                foreach ($members_of($a['id']) as $i) {
                    $m = $items[$i];
                    $key = $m['queue'] . ':' . $m['id'];
                    if (isset($dead[$key]) || !isset($ref[$key]) || kop_fold_ai_apart($m, $b) !== '') continue;
                    $clear($m['queue'], $ref[$key]['row'], $m, array('Already on hand in other words: ' . kop_fold_cut($b['text'], 300), $held ? 'held' : 'on_hand', 'ai'));
                    $dead[$key] = true;
                }
                continue;
            }
            $ma = $members_of($a['id']);
            $mb = $members_of($b['id']);
            if (!$ma || !$mb || kop_fold_find($uf, $ma[0]) === kop_fold_find($uf, $mb[0])) continue;
            // Never join two groups when any pair across them differs.
            $clash = false;
            foreach ($ma as $i) foreach ($mb as $j) if (kop_fold_ai_apart($items[$i], $items[$j]) !== '') { $clash = true; break 2; }
            if ($clash) { $S['ai_overruled'] = ($S['ai_overruled'] ?? 0) + 1; continue; }
            kop_fold_union($uf, $ma[0], $mb[0]);
            foreach (array($a['id'], $b['id']) as $hid) if (empty($how[$hid])) $how[$hid] = 'ai';
            $S['ai_joins'] = ($S['ai_joins'] ?? 0) + 1;
        }
        if ($apply) {
            foreach ($marks as $id => $note) {
                $a = $by_id[$id];
                $r = $ref[$a['queue'] . ':' . $a['id']] ?? null;
                if (!$r || isset($dead[$a['queue'] . ':' . $a['id']])) continue;
                if ($note === null && !kop_fold_ai_mark($r['queue'], $r['row'])) continue;
                // The row in memory takes the change too: a kept card's later fold writes its extra / applied from it.
                $ref[$a['queue'] . ':' . $a['id']]['row'] = kop_fold_write_differs($r['queue'], $r['row'], $note);
            }
        }
    }

    // b: write the clears.
    foreach ($clears as $c) {
        $q = kop_fold_qn($c['queue']);
        $S['cleared'][$q][$c['cleared']] = ($S['cleared'][$q][$c['cleared']] ?? 0) + 1;
        kop_fold_tally($S, 'cleared_kind', $q, $c['shape'] ? $c['shape']['kind'] : ($c['row']['kind'] ?? $c['row']['path'] ?? '?'));
        kop_fold_example($S, 'cleared', array('record' => $rk, 'name' => $name, 'queue' => $q, 'line' => kop_fold_cut($c['shape'] ? $c['shape']['text'] : ($c['row']['label'] ?? ''), 160), 'reason' => $c['reason']));
        if ($apply) kop_fold_write_clear($c['queue'], $c['row'], $c['reason'], $c['cleared'], $c['how']);
    }

    // e: fold each group.
    $groups = array();
    foreach ($items as $i => $f) {
        if (isset($dead[$f['queue'] . ':' . $f['id']])) continue;
        $groups[kop_fold_find($uf, $i)][] = $i;
    }
    foreach ($groups as $members) {
        if (count($members) < 2) continue;
        $keep = kop_fold_pick($items, $members);
        $kf = $items[$keep];
        $S['groups'] = ($S['groups'] ?? 0) + 1;
        $queues = array();
        foreach ($members as $m) $queues[kop_fold_qn($items[$m]['queue'])] = true;
        if (count($queues) > 1) $S['groups_cross_queue'] = ($S['groups_cross_queue'] ?? 0) + 1;
        if ($kf['queue'] === 'news') {
            // No Woodbury or Fornits item in the group: a waiting news fact has no place for other citations.
            $S['groups_news_only'] = ($S['groups_news_only'] ?? 0) + 1;
            $S['news_only_members'] = ($S['news_only_members'] ?? 0) + count($members) - 1;
            continue;
        }
        $kept = $ref[$kf['queue'] . ':' . $kf['id']];
        $mem = array();
        foreach ($members as $m) {
            if ($m === $keep) continue;
            $mem[] = $ref[$items[$m]['queue'] . ':' . $items[$m]['id']];
            $q = kop_fold_qn($items[$m]['queue']);
            kop_fold_tally($S, 'folded', $q, $items[$m]['kind']);
            kop_fold_example($S, 'folded', array('record' => $rk, 'name' => $name, 'queue' => $q, 'line' => kop_fold_cut($items[$m]['text'], 160),
                'into' => kop_fold_cut($kf['text'], 160), 'how' => $how[$items[$m]['id']] ?? ''));
        }
        $fnews = kop_fold_found_news($items, $members, $keep);
        $found = null;
        if ($fnews) {
            $fr = $ref['news:' . $fnews['id']];
            $found = array('id' => kop_fold_mid('news', $fr['row']), 'text' => kop_fold_cut($fnews['core'] !== '' ? $fnews['core'] : $fnews['text'], 300), 'standing' => 'found');
            $S['found_marked'] = ($S['found_marked'] ?? 0) + 1;
        }
        if ($apply) kop_fold_write_group($kept, $mem, $how, $found);
    }
    return $ai_state;
}

/**
 * The item a group keeps (owner, 2026-11-03: an "alleged" telling folds with a "found / substantiated / confirmed /
 * convicted" telling of the same event, and the found telling's wording is the one kept). The order:
 * 1. a Woodbury or Fornits item before a news fact (a waiting news fact has no place for other citations), when the group has one;
 * 2. the strongest standing first (found, convicted, ruled, admitted; then unstated; "alleged" last), whatever the queue order;
 * 3. a Woodbury item, then a Fornits item;
 * 4. the longest wording; then the first in the list.
 */
function kop_fold_pick(array $items, array $members) {
    $rank = array('wbf' => 0, 'fornits' => 1, 'news' => 2);
    $holder = false;
    foreach ($members as $m) if ($items[$m]['queue'] !== 'news') $holder = true;
    if ($holder) $members = array_values(array_filter($members, function ($m) use ($items) { return $items[$m]['queue'] !== 'news'; }));
    usort($members, function ($a, $b) use ($items, $rank) {
        return kop_fact_standing_rank($items[$b]) <=> kop_fact_standing_rank($items[$a])
            ?: ($rank[$items[$a]['queue']] ?? 3) <=> ($rank[$items[$b]['queue']] ?? 3)
            ?: strlen($items[$b]['core'] ?: $items[$b]['text']) <=> strlen($items[$a]['core'] ?: $items[$a]['text']) ?: $a <=> $b;
    });
    return $members[0];
}

/* ---- The run ------------------------------------------------------------------------------------------------ */

/**
 * Clear and fold the waiting facts, one unit (record or program key) at a time until $seconds run out; the next run
 * continues after the last unit done (option kop_fold_state; a finished pass waits 3 hours before it starts over).
 * $apply false writes nothing (the dry run: news facts the exact-name rule would link are treated as linked).
 * $opts: only => [unit, ...] (tests, --facility), ignore_cursor => true, ai => '' (default: no AI) | 'estimate' (count
 * the calls a pass would make) | 'live' (call; the hourly run), ai_cap => calls this run (KOP_FOLD_AI_CALLS_PER_RUN).
 * A unit whose AI part was put off (call cap, budget, time box, a failed call) goes on state.ai_todo; the next live
 * run judges those first, even while the pass itself waits its 3 hours, so the cursor never skips them.
 * Returns the counts: checked, cleared[queue][on_hand|held], folded[queue][kind], groups, ..., examples, units_done, done,
 * ai_calls, ai_stopped, ai_todo, ai_est.
 */
function kop_fold_run($apply = false, $seconds = 90, array $opts = array()) {
    $S = array('apply' => (bool) $apply, 'checked' => array(), 'cleared' => array(), 'cleared_kind' => array(), 'folded' => array(), 'left_alone' => array(),
        'conflicts_kept' => array(), 'skipped' => array(), 'examples' => array(), 'groups' => 0, 'units_done' => 0, 'units_total' => 0, 'done' => true, 'news_linked_in_memory' => 0,
        'ai_calls' => 0, 'ai_stopped' => '');
    $ai = $opts['ai'] ?? '';
    $ai = $ai === true ? 'live' : (in_array($ai, array('estimate', 'live'), true) ? $ai : '');
    foreach (array('kop_fact_shape', 'kop_wbf_on_record', 'kop_wbf_conflict_parts', 'kop_nf_record_for') as $fn) {
        if (!function_exists($fn)) { $S['error'] = "Missing $fn"; return $S; }
    }
    $pdo = kop_fold_pdo();
    if (!$pdo) { $S['error'] = 'No database connection.'; return $S; }
    $start = microtime(true);
    $news_map = array();
    if (!$apply) {
        $lk = kop_nf_link_waiting($pdo, false, $seconds);
        $news_map = $lk['map'];
        $S['news_linked_in_memory'] = count($news_map);
    }
    list($units, $noreq) = kop_fold_units($pdo, $news_map);
    $only = isset($opts['only']) ? (array) $opts['only'] : null;
    $state = (array) get_option('kop_fold_state', array());
    $live = $apply && $only === null;
    $cursor = ($live && empty($opts['ignore_cursor'])) ? (string) ($state['cursor'] ?? '') : '';
    $todo = ($live && $ai === 'live') ? array_values(array_unique(array_map('strval', (array) ($state['ai_todo'] ?? array())))) : array();
    $idle = $live && $cursor === '' && !empty($state['finished']) && time() - (int) $state['finished'] < 3 * HOUR_IN_SECONDS && empty($opts['ignore_cursor']);
    if ($idle && !$todo) {
        $S['idle'] = 'The last pass finished ' . gmdate('Y-m-d H:i', (int) $state['finished']) . ' UTC; the next starts after 3 hours.';
        return $S;
    }
    if ($only !== null) $units = array_values(array_intersect($units, $only));
    $S['units_total'] = count($units);
    $x = array('pdo' => $pdo, 'noreq' => $noreq, 'news_map' => $news_map, 'ai' => $ai, 'apply' => (bool) $apply, 'start' => $start, 'seconds' => (float) $seconds,
        'ai_cap' => (int) ($opts['ai_cap'] ?? KOP_FOLD_AI_CALLS_PER_RUN));
    $one = function ($u) use ($apply, &$S, $x) {
        try {
            return kop_fold_unit($u, $apply, $S, $x);
        } catch (Throwable $e) {
            $S['errors'][] = $u . ': ' . $e->getMessage();
            error_log('kop fold ' . $u . ': ' . $e->getMessage());
            return 'none';
        }
    };
    // First the units whose AI part a run put off: judged before the pass goes on (the rules run on them again; that is a no-op).
    $known = array_flip($units);
    $did = array();
    $left = array();
    foreach ($todo as $u) {
        if (!isset($known[$u])) continue;                 // nothing waits there any more
        if ($S['ai_stopped'] !== '' || microtime(true) - $start > $seconds) { $left[] = $u; continue; }
        $did[$u] = true;
        if ($one($u) === 'deferred') $left[] = $u;
    }
    $todo = $left;
    $last = $cursor;
    if (!$idle) {
        foreach ($units as $u) {
            if (!kop_fold_unit_after($u, $cursor)) continue;
            if (microtime(true) - $start > $seconds) { $S['done'] = false; break; }
            if (!isset($did[$u])) {
                if ($one($u) === 'deferred' && !in_array($u, $todo, true)) $todo[] = $u;
                $S['units_done']++;
            }
            $last = $u;
            if ($live) update_option('kop_fold_state', array('cursor' => $u, 'ai_todo' => $todo) + $state, false);
        }
    } else {
        $S['idle'] = 'The last pass finished ' . gmdate('Y-m-d H:i', (int) $state['finished']) . ' UTC; only the AI step of ' . count($did) . ' put-off units ran.';
    }
    $S['ai_todo'] = count($todo);
    if ($live) {
        $finished = $idle ? (int) $state['finished'] : ($S['done'] ? time() : (int) ($state['finished'] ?? 0));
        $new = array('cursor' => ($idle || $S['done']) ? '' : $last, 'finished' => $finished, 'last_run' => time(), 'ai_todo' => $todo,
            'last' => array('units' => $S['units_done'], 'cleared' => $S['cleared'], 'folded' => $S['folded'], 'groups' => $S['groups'],
                'ai_calls' => $S['ai_calls'], 'ai_stopped' => $S['ai_stopped'], 'ai_todo' => count($todo)));
        update_option('kop_fold_state', $new, false);
        if (($S['cleared'] || $S['folded']) && function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
    }
    $S['seconds'] = round(microtime(true) - $start, 1);
    return $S;
}

/** The hourly step (kop_fold_hourly_event): link waiting news facts, then clear and fold. */
function kop_fold_hourly() {
    if (get_transient('kop_fold_lock')) return null;
    set_transient('kop_fold_lock', 1, 10 * MINUTE_IN_SECONDS);
    try {
        if (function_exists('kop_autoconn_ensure_table')) kop_autoconn_ensure_table();
        $pdo = kop_fold_pdo();
        if ($pdo && function_exists('kop_nf_ensure_tables')) {
            kop_nf_ensure_tables($pdo);
            kop_nf_link_waiting($pdo, true, 30);
        }
        // The rules, then the AI step (d) within its call cap and the daily budget.
        $S = kop_fold_run(true, 90, array('ai' => 'live'));
        if (!empty($S['errors'])) error_log('kop fold: ' . wp_json_encode(array_slice($S['errors'], 0, 5)));
        return $S;
    } catch (Throwable $e) {
        error_log('kop fold: ' . $e->getMessage());
        return null;
    } finally {
        delete_transient('kop_fold_lock');
    }
}

// Its own hourly event, half an hour after kop_wbf_auto_hourly (which clears word-for-word copies first), so neither
// run's time box eats into the other's PHP time limit.
add_action('init', function () {
    if (!wp_next_scheduled('kop_fold_hourly_event')) {
        wp_schedule_event(time() + 1800, 'hourly', 'kop_fold_hourly_event');
    }
});
add_action('kop_fold_hourly_event', 'kop_fold_hourly');

/* ---- Undo --------------------------------------------------------------------------------------------------- */

/**
 * A folded Woodbury or Fornits item goes back to waiting: its citations come off the kept item (while that is still
 * waiting or held), and it is never folded or cleared again (reviewed_by = the person, extra.fold_undone).
 * $queue 'wbf' | 'fornits'. $row is the member's row as stored.
 */
function kop_fold_undo_member($queue, array $row, $reviewer) {
    global $wpdb;
    $now = current_time('mysql', true);
    $applied = json_decode((string) $row['applied'], true);
    $applied = is_array($applied) ? $applied : array();
    $mid = kop_fold_mid($queue, $row);
    $into = (string) ($applied['merged_into'] ?? '');
    if (strpos($into, ':') !== false) {
        list($kq, $kpkey) = explode(':', $into, 2);
        kop_fold_unfold_kept($kq, $kpkey, $mid);
    }
    if ($queue === 'wbf') {
        $extra = json_decode((string) $row['extra'], true) ?: array();
        unset($extra['merged_into'], $extra['merged_into_queue']);
        $extra['fold_undone'] = $reviewer;
        $wpdb->update(kop_wbf_table(), array('status' => 'pending', 'applied' => null, 'extra' => wp_json_encode($extra), 'reviewed_by' => $reviewer, 'reviewed_at' => $now),
            array('pkey' => $row['pkey']));
    } else {
        $wpdb->update(kop_fornits_items_table(), array('status' => 'pending', 'applied' => null, 'reviewed_by' => $reviewer, 'reviewed_at' => $now), array('pkey' => $row['pkey']));
    }
}

/** Takes member $mid's citations and list entry off the kept item $kpkey of queue $kq ('woodbury' | 'fornits'). */
function kop_fold_unfold_kept($kq, $kpkey, $mid) {
    global $wpdb;
    if ($kq === 'woodbury') {
        $k = $wpdb->get_row($wpdb->prepare('SELECT pkey, status, extra, evidence FROM ' . kop_wbf_table() . ' WHERE pkey = %s', $kpkey), ARRAY_A);
        if (!$k || !in_array($k['status'], array('pending', 'held'), true)) return;
        $extra = json_decode((string) $k['extra'], true) ?: array();
        $extra['folded'] = array_values(array_filter((array) ($extra['folded'] ?? array()), function ($f) use ($mid) { return ($f['id'] ?? '') !== $mid; }));
        if (!$extra['folded']) unset($extra['folded']);
        if (!empty($extra['found']) && ($extra['found']['id'] ?? '') === $mid) {
            $prev = (string) ($extra['found']['prev'] ?? '');
            unset($extra['found']);
            if ($prev === '') unset($extra['standing']); else $extra['standing'] = $prev;
        }
        $wpdb->update(kop_wbf_table(), array('extra' => wp_json_encode($extra), 'evidence' => wp_json_encode(array_values(kop_fold_remove_ev(kop_wbf_evidence($k), $mid)))), array('pkey' => $kpkey));
        return;
    }
    if ($kq === 'fornits') {
        $k = $wpdb->get_row($wpdb->prepare('SELECT pkey, status, applied FROM ' . kop_fornits_items_table() . ' WHERE pkey = %s', $kpkey), ARRAY_A);
        if (!$k || !in_array($k['status'], array('pending', 'held'), true)) return;
        $a = json_decode((string) $k['applied'], true);
        $a = is_array($a) ? $a : array();
        $folds = array_values(array_filter((array) ($a['folds'] ?? array()), function ($f) use ($mid) { return ($f['id'] ?? '') !== $mid; }));
        if ($folds) $a['folds'] = $folds; else unset($a['folds']);
        if (!empty($a['found']) && ($a['found']['id'] ?? '') === $mid) unset($a['found']);
        $wpdb->update(kop_fornits_items_table(), array('applied' => $a ? wp_json_encode($a) : null), array('pkey' => $kpkey));
    }
}

/**
 * Undo of a news fact the engine touched (Facts from news, Recently done): linked to a record (status filed),
 * folded (merged) or cleared (dropped by 'auto'). Returns the message, or '' when the fact is none of these.
 */
function kop_nf_undo_auto(PDO $pdo, array $row, $who) {
    $id = (int) $row['id'];
    if ($row['status'] === 'merged') {
        $note = kop_fold_mark_note('news-facts-fold', $id);
        $into = (string) ($note['merged_into'] ?? '');
        if (strpos($into, ':') !== false) {
            list($kq, $kpkey) = explode(':', $into, 2);
            kop_fold_unfold_kept($kq, $kpkey, 'news:' . $id);
        }
        $pdo->prepare("UPDATE news_facts SET route = 'waiting', status = 'pending', wbf_pkey = '', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?")->execute(array($who, $id));
        kop_autoconn_mark('news-facts-fold', $id, '', 'undone', '');
        return 'Back with the waiting facts; it is not folded again.';
    }
    if ($row['status'] === 'dropped' && (string) $row['reviewed_by'] === 'auto') {
        $pdo->prepare("UPDATE news_facts SET status = 'pending', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?")->execute(array($who, $id));
        kop_autoconn_mark('news-facts-fold', $id, '', 'undone', '');
        return 'Back with the waiting facts; it is not cleared again.';
    }
    if ($row['status'] === 'filed' && kop_fold_mark_note('news-facts', $id)) {
        return kop_nf_unlink($pdo, $row, $who);
    }
    return '';
}

/* ---- Linking waiting news facts to records (docs/claude/fact-shape.md 7.4) ---------------------------------- */

/**
 * Link waiting news facts that have no record to the record their subject names exactly (kop_nf_record_for(): the
 * exact-name rule; a person or agency subject, a name two records share without the article picking one, and a generic
 * name never link). A linked fact gets facility_id; when kop_nf_wbf_row() makes a Woodbury Facts row for it, the row is
 * filed with kop_nf_file_wbf() (a second article's telling joins a waiting row as evidence) and the fact becomes
 * route 'facility', status 'filed', wbf_pkey. kop_autoconn_mark() (source news-facts, note = what Undo needs) means one a
 * person undoes is never linked again; kop_autoconn_log() puts it in Recently done. $write false only counts.
 * Returns array(checked, linked, filed, ambiguous, map => [news fact id => record id], done).
 */
function kop_nf_link_waiting(PDO $pdo, $write = true, $seconds = 30) {
    global $wpdb;
    $out = array('checked' => 0, 'linked' => 0, 'filed' => 0, 'ambiguous' => 0, 'map' => array(), 'done' => true);
    if (!function_exists('kop_nf_record_for') || !function_exists('kop_autoconn_exact_record')) return $out;
    $start = microtime(true);
    $tried = kop_fold_tried('news-facts');
    try {
        $rows = $pdo->query("SELECT n.*, s.publication_name, s.article_title, s.publication_date, s.article_url
            FROM news_facts n JOIN news_submissions s ON s.id = n.news_id
            WHERE n.status = 'pending' AND n.route = 'waiting' AND (n.facility_id IS NULL OR n.facility_id = 0) AND n.subject_kind IN ('facility', 'company')
              AND (n.reviewed_by IS NULL OR n.reviewed_by = '') ORDER BY n.id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $out;
    }
    $cats = kop_nf_categories();
    $st_name = $pdo->prepare('SELECT unique_name FROM facilities_v2 WHERE id = ?');
    foreach ($rows as $r) {
        if (isset($tried[(string) $r['id']])) continue;
        if ($write && microtime(true) - $start > $seconds) { $out['done'] = false; break; }
        $out['checked']++;
        list($fid, $how) = kop_nf_record_for($pdo, $r);
        if ($how === 'ambiguous') { $out['ambiguous']++; continue; }
        if (!in_array($how, array('exact', 'article'), true) || $fid <= 0) continue;
        $out['linked']++;
        $out['map'][(int) $r['id']] = (int) $fid;
        $st_name->execute(array($fid));
        $rec_name = (string) $st_name->fetchColumn();
        $fact = array('facility_id' => $fid, 'category' => (string) $r['category'], 'date' => (string) $r['event_date'], 'summary' => (string) $r['summary'],
            'standing' => (string) $r['standing'], 'person' => (string) $r['person'], 'role' => (string) $r['role'], 'value' => (string) $r['value'],
            'quote' => (string) $r['quote'], 'names_removed' => (int) $r['names_removed'], 'subject' => (string) $r['subject_name'], 'linked_name' => $rec_name);
        $news = array('id' => (int) $r['news_id'], 'publication_name' => (string) $r['publication_name'], 'article_title' => (string) $r['article_title'],
            'publication_date' => (string) $r['publication_date'], 'article_url' => (string) $r['article_url']);
        $wbf = isset($cats[$fact['category']]) ? kop_nf_wbf_row($fact, $news, !empty($r['quote_found'])) : null;
        if ($wbf) $out['filed']++;
        if (!$write) continue;
        $note = array('fid' => (int) $fid, 'how' => $how);
        $pkey = '';
        if ($wbf) {
            $have = $wpdb->get_row($wpdb->prepare('SELECT status, evidence FROM ' . kop_wbf_table() . ' WHERE pkey = %s', $wbf['pkey']), ARRAY_A);
            $url = (string) $news['article_url'];
            $had_url = false;
            if ($have) foreach (kop_wbf_evidence($have) as $e) if (($e['url'] ?? '') === $url) $had_url = true;
            $pkey = kop_nf_file_wbf($wbf);
            $note += array('pkey' => $pkey, 'created' => !$have, 'added_ev' => !$had_url, 'news_id' => (int) $r['news_id'], 'url' => $url);
        }
        $up = $pdo->prepare("UPDATE news_facts SET facility_id = ?, route = ?, wbf_pkey = ?, status = ? WHERE id = ? AND status = 'pending' AND route = 'waiting'");
        $up->execute(array($fid, $wbf ? 'facility' : 'waiting', $pkey, $wbf ? 'filed' : 'pending', (int) $r['id']));
        kop_autoconn_mark('news-facts', (int) $r['id'], (string) $fid, 'done', wp_json_encode($note));
        kop_autoconn_log('news-facts', (int) $r['id'], kop_fold_cut($r['summary'], 120),
            'Linked to ' . $rec_name . ' (#' . $fid . '): the fact names it exactly' . ($how === 'article' ? ' and the article is linked to it' : '')
            . ($wbf ? '; filed on Woodbury Facts (From: News articles).' : '; it has no card of its own yet, so it waits here with its record.'));
    }
    return $out;
}

/**
 * Undo of kop_nf_link_waiting(): the evidence the link added comes off the Woodbury row (the row is deleted when the link
 * created it and nothing else rests on it), and the fact is waiting again with no record; never linked again.
 */
function kop_nf_unlink(PDO $pdo, array $row, $who) {
    global $wpdb;
    $id = (int) $row['id'];
    $note = kop_fold_mark_note('news-facts', $id);
    $pkey = (string) ($note['pkey'] ?? ($row['wbf_pkey'] ?? ''));
    if ($pkey !== '') {
        $w = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_wbf_table() . ' WHERE pkey = %s', $pkey), ARRAY_A);
        if ($w && $w['status'] === 'pending') {
            $ev = kop_wbf_evidence($w);
            $url = (string) ($note['url'] ?? '');
            $left = array_values(array_filter($ev, function ($e) use ($url, $note) { return !(is_array($e) && $url !== '' && ($e['url'] ?? '') === $url && !empty($note['added_ev'])); }));
            $extra = json_decode((string) $w['extra'], true) ?: array();
            if (!empty($note['created']) && !$left && empty($extra['folded']) && trim((string) $w['reviewed_by']) === '') {
                $wpdb->delete(kop_wbf_table(), array('pkey' => $pkey));
            } elseif (!empty($note['added_ev'])) {
                $wpdb->update(kop_wbf_table(), array('evidence' => wp_json_encode($left)), array('pkey' => $pkey));
            }
        }
    }
    $pdo->prepare("UPDATE news_facts SET facility_id = NULL, route = 'waiting', wbf_pkey = '', status = 'pending', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?")
        ->execute(array($who, $id));
    kop_autoconn_mark('news-facts', $id, '', 'undone', '');
    return 'Unlinked: the fact waits again with no record, and is not linked again.';
}
