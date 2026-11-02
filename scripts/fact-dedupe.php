<?php
/**
 * One card per fact (docs/plan/4.4 item 20), step (a): a READ-ONLY measurement.
 * What each planned step would clear or fold among the waiting facts, with examples.
 * Writes nothing (no database write, no AI call); it only reads tmp/prod.sqlite.
 * Every item, waiting and on hand, comes from inc/fact-shape.php (docs/claude/fact-shape.md).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/fact-dedupe.php --db=tmp/prod.sqlite
 *       [--examples=20] [--json=<path>] [--facility=10371] [--shapes] [--noexamples]
 *   --facility=N        only that record (the groups of the record are listed; with --shapes every shape of it, one per line)
 *   --shapes            with --facility: print every shape of the record to check the mapping by eye
 *   --recheck-cleared   instead of the measurement: re-check every Woodbury item the hourly clear filed as "already on the
 *                       record" against its record with the current kop_wbf_on_record() and count those no longer on it.
 *
 * Buckets (queues: woodbury = wpdl_kop_woodbury_facts, fornits = wpdl_kop_fornits_items, news = news_facts):
 *   0  baseline   what kop_wbf_on_record() (the hourly clear) already says is on the record; Fornits: its on-file rules
 *   b  held       the record's HELD Woodbury items laid over a copy of its doc (kop_wbf_doc_apply()), then the same check;
 *                 Fornits staff/incidents via kop_wbf_on_record(); news facts by shape rule; Fornits links by URL
 *                 (resourceLinks + news links). A held-doc hit that is also a kop_wbf_conflict_parts() hit = "conflict".
 *   c  fold       waiting items among themselves, per record (or per program key when there is no record), grouped by
 *                 kop_fact_blocks() with one union-find per record and joined by kop_fact_rule_same(), so kinds that meet
 *                 (a founder note and a staff entry, a Fornits closure lead and a set_closed row) join.
 *   d  ai         pairs left after the rules that kop_fact_close() calls close.
 * News facts with no record are linked in memory by kop_nf_record_for() (the exact-name rule, spec 7.2); nothing is written.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$opt = array();
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? true;
}
$db_path = (string) ($opt['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite'));
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (python ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
$N_EX = isset($opt['examples']) ? max(0, (int) $opt['examples']) : 20;
$ONLY = isset($opt['facility']) ? (int) $opt['facility'] : 0;
ini_set('memory_limit', '2G');
$t0 = microtime(true);

const TOKENS_PER_CHAR = 0.25;   // chars / 4
const PROMPT_OVERHEAD_CHARS = 1200;
const BIG_BLOCK = 400;          // a bucket over this many facts falls back to candidate_pairs()

require __DIR__ . '/kop-test-harness.php';   // WordPress stubs + $wpdb over PDO; $pdo is the mirror, opened for reading only
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
if (!function_exists('kop_rinbox_register')) { function kop_rinbox_register() {} }       // the file registers its screen on load; nothing to register here
require_once dirname(__DIR__) . '/inc/review-inbox/woodbury-facts.php';   // kop_wbf_on_record()
require_once dirname(__DIR__) . '/api/url-dedupe.php';                    // kop_normalize_urls()
require_once dirname(__DIR__) . '/inc/auto-connect.php';                  // kop_autoconn_exact_record()
require_once dirname(__DIR__) . '/inc/fornits.php';                       // kop_fornits_incident_line(), kop_fornits_lead_line()
require_once dirname(__DIR__) . '/inc/news-facts.php';                    // kop_nf_record_for()
require_once dirname(__DIR__) . '/inc/fact-shape.php';

/* ------------------------------------------------------------------------------------------------ helpers */

$S = array();    // counts: $S[bucket][queue][kind]
$EX = array();   // examples: $EX["bucket|queue|kind"][] = array
$SKIP = array('no_doc' => 0, 'apply_failed' => 0, 'left_alone' => 0, 'news_no_record' => 0, 'no_record_key' => 0);
function bump($bucket, $queue, $kind, $n = 1) { global $S; $S[$bucket][$queue][$kind] = ($S[$bucket][$queue][$kind] ?? 0) + $n; }
function ex($bucket, $queue, $kind, array $row) { global $EX; $EX[$bucket . '|' . $queue . '|' . $kind][] = $row; }
function cut($s, $n = 230) { $s = trim(preg_replace('/\s+/', ' ', (string) $s)); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }
/** The queue a shape came from, as the totals table names it. */
function qn(array $f) { return $f['queue'] === 'wbf' ? 'woodbury' : ($f['queue'] === 'doc' ? 'record' : $f['queue']); }

/** Union-find over item indexes. */
class UF {
    public $p = array();
    function __construct($n) { $this->p = range(0, max(0, $n - 1)); }
    function find($x) { while ($this->p[$x] !== $x) { $this->p[$x] = $this->p[$this->p[$x]]; $x = $this->p[$x]; } return $x; }
    function union($a, $b) { $a = $this->find($a); $b = $this->find($b); if ($a !== $b) $this->p[$b] = $a; }
}

/** Candidate pairs (i<j) of a big bucket: they share >= 2 distinctive words, or are two dated facts of one type in one year. */
function candidate_pairs(array $items) {
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

/**
 * Candidate pairs of a record's facts: facts sharing a block key (kop_fact_blocks()); a block over BIG_BLOCK facts falls
 * back to candidate_pairs(). Returns [i, j] with i < j, each once.
 */
function block_pairs(array $items) {
    $blocks = array();
    foreach ($items as $i => $f) foreach (kop_fact_blocks($f) as $b) $blocks[$b][] = $i;
    $pairs = array();
    foreach ($blocks as $idx) {
        $n = count($idx);
        if ($n < 2) continue;
        if ($n > BIG_BLOCK) {
            $sub = array();
            foreach ($idx as $i) $sub[] = $items[$i];
            foreach (candidate_pairs($sub) as $pr) {
                $a = $idx[$pr[0]]; $b = $idx[$pr[1]];
                $pairs[min($a, $b) . ',' . max($a, $b)] = array(min($a, $b), max($a, $b));
            }
            continue;
        }
        for ($x = 0; $x < $n; $x++) for ($y = $x + 1; $y < $n; $y++) {
            $a = min($idx[$x], $idx[$y]); $b = max($idx[$x], $idx[$y]);
            $pairs[$a . ',' . $b] = array($a, $b);
        }
    }
    return $pairs;
}

/* ------------------------------------------------------------------------------------------------ --recheck-cleared */

if (!empty($opt['recheck-cleared'])) {
    // kop_wbf_clear_on_record(): status 'rejected', reviewed_by 'auto', applied {"reason": "Already on the record: ...", "cleared": "on_record"}
    // (older passes wrote the reason without "cleared"). Only add_list is re-checked by text; the rest are listed as counts.
    $rows = $pdo->query("SELECT id, pkey, facility_id, op, path, value, label, applied FROM wpdl_kop_woodbury_facts
        WHERE status = 'rejected' AND reviewed_by = 'auto' AND op = 'add_list'" . ($ONLY ? " AND facility_id = $ONLY" : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $stDoc = $pdo->prepare('SELECT name, json_data FROM facilities_v2 WHERE id = ?');
    $docs = array();
    $by = array();
    $ex = array();
    $tot = array('rows' => 0, 'marked' => 0, 'no_doc' => 0, 'wrong' => 0, 'wrong_marked' => 0, 'link_in_bracket' => 0);
    foreach ($rows as $r) {
        $tot['rows']++;
        $ap = json_decode((string) $r['applied'], true) ?: array();
        $marked = ($ap['cleared'] ?? '') === 'on_record';
        if ($marked) $tot['marked']++;
        $fid = (int) $r['facility_id'];
        if (!array_key_exists($fid, $docs)) {
            $stDoc->execute(array($fid));
            $rec = $stDoc->fetch(PDO::FETCH_ASSOC);
            $docs[$fid] = $rec ? array('name' => (string) $rec['name'], 'doc' => json_decode((string) $rec['json_data'], true)) : null;
        }
        if (!$docs[$fid] || !is_array($docs[$fid]['doc'])) { $tot['no_doc']++; continue; }
        if (kop_wbf_on_record($docs[$fid]['doc'], $r) !== '') continue;
        $tot['wrong']++;
        if ($marked) $tot['wrong_marked']++;
        $by[$r['path']] = ($by[$r['path']] ?? 0) + 1;
        $val = (string) kop_wbf_row_value($r);
        $matched = preg_replace('/^Already on the record:\s*/', '', (string) ($ap['reason'] ?? ''));
        if (preg_match('/:\s*https?:\/\/\S+\)/', $val . ' ' . $matched)) $tot['link_in_bracket']++;
        $ex[] = array('id' => (int) $r['id'], 'marked' => $marked, 'fid' => $fid, 'name' => $docs[$fid]['name'], 'path' => $r['path'], 'item' => $val, 'matched' => $matched);
    }
    echo "=== Auto-cleared add_list items re-checked with the current kop_wbf_on_record() (read only) ===\n";
    echo "Auto-rejected add_list rows: {$tot['rows']} (with cleared=on_record: {$tot['marked']}; record missing: {$tot['no_doc']})\n";
    echo "No longer on the record (wrongly cleared, or the record changed since): {$tot['wrong']} (marked: {$tot['wrong_marked']}); with a link inside the citation bracket: {$tot['link_in_bracket']}\n";
    arsort($by);
    foreach ($by as $path => $n) echo sprintf("  %-40s %d\n", $path, $n);
    $step = max(1, (int) floor(count($ex) / max(1, $N_EX)));
    foreach ($ex as $i => $e) {
        if ($i % $step !== 0 || $i / $step >= $N_EX) continue;
        echo sprintf("\n  row %d%s  #%d %s [%s]\n     item:    %s\n     matched: %s\n", $e['id'], $e['marked'] ? '' : ' (unmarked)', $e['fid'], cut($e['name'], 50), $e['path'], cut($e['item'], 260), cut($e['matched'], 260));
    }
    echo "\nRow ids: " . implode(',', array_column($ex, 'id')) . "\n";
    exit(0);
}

/* ------------------------------------------------------------------------------------------------ load queues */

$W = $pdo->query("SELECT id, pkey, facility_id, program, op, path, value, label, conflict, current_val, extra, evidence, status, applied_fid, reviewed_by, issue_date
    FROM wpdl_kop_woodbury_facts WHERE status IN ('pending','held')" . ($ONLY ? " AND (facility_id = $ONLY OR applied_fid = $ONLY)" : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$F = $pdo->query("SELECT id, pkey, topic_id, kind, facility_id, label, value, post_n, post_date, author FROM wpdl_kop_fornits_items
    WHERE status = 'pending'" . ($ONLY ? " AND facility_id = $ONLY" : ''))->fetchAll(PDO::FETCH_ASSOC);
$NF = $pdo->query("SELECT n.id, n.news_id, n.category, n.subject_kind, n.subject_name, n.facility_id, n.person, n.role, n.value, n.summary, n.standing, n.event_date, s.publication_date
    FROM news_facts n LEFT JOIN news_submissions s ON s.id = n.news_id
    WHERE n.status = 'pending' AND n.route = 'waiting'")->fetchAll(PDO::FETCH_ASSOC);

/* ---- News facts with no record: the exact-name rule (spec 7.2), measured in memory ---- */
$NEWS_LINK = array();      // outcome => count
$NEWS_LINK_EX = array();   // outcome => examples
$op_keys = array();
foreach ($pdo->query('SELECT name FROM wpdl_kop_operators') as $o) { $k = kop_autoconn_key($o['name']); if ($k !== '') $op_keys[$k] = ($op_keys[$k] ?? 0) + 1; }
$no_record_total = 0;
foreach ($NF as $i => $r) {
    if ((int) $r['facility_id'] > 0) continue;
    $no_record_total++;
    list($fid, $how) = kop_nf_record_for($pdo, $r);
    $out = $how;
    if ($how === 'person') $out = 'person subjects (never linked)';
    elseif ($how === 'agency') {
        $would = kop_autoconn_exact_record($pdo, (string) $r['subject_name'], array());
        $out = 'agency subjects (never linked)' . ($would[0] > 0 ? ' [would have matched a record by name]' : '');
    } elseif ($how === 'exact') $out = 'facility/company subject, one record holds the exact key: LINKED';
    elseif ($how === 'article') $out = 'several records hold the key, the article picks one: LINKED';
    elseif ($how === 'ambiguous') $out = 'several records hold the key, no tie-break: ambiguous';
    elseif ($how === 'generic') $out = 'one or more records hold the key, but the name is generic';
    else {
        $out = 'facility or company subject, no record of that exact name (' . ($r['subject_kind'] === 'company' ? 'company' : 'facility') . ')'
            . (isset($op_keys[kop_autoconn_key($r['subject_name'])]) && $op_keys[kop_autoconn_key($r['subject_name'])] === 1 ? ' [exactly one company of that name in kop_operators]' : '');
    }
    $NEWS_LINK[$out] = ($NEWS_LINK[$out] ?? 0) + 1;
    $NEWS_LINK_EX[$out][] = '#' . ($fid ?: '-') . ' n' . $r['id'] . ' ' . cut($r['subject_name'], 60) . ' [' . $r['category'] . '] ' . cut($r['summary'], 100);
    if ($fid > 0 && ($how === 'exact' || $how === 'article')) $NF[$i]['facility_id'] = $fid;
}
if ($ONLY) $NF = array_values(array_filter($NF, function ($r) use ($ONLY) { return (int) $r['facility_id'] === $ONLY; }));

$tot = array('woodbury' => 0, 'fornits' => 0, 'news' => count($NF));
$held_by = array();      // fid => [rows]
$pend_by = array();      // fid => [rows]
$noreq = array();        // record key (p:...) => [rows] (facility_id = 0)
foreach ($W as $r) {
    if ($r['status'] === 'held') {
        $fid = (int) ($r['applied_fid'] ?: $r['facility_id']);
        if ($fid > 0) $held_by[$fid][] = $r;
        continue;
    }
    $tot['woodbury']++;
    if ((int) $r['facility_id'] > 0) $pend_by[(int) $r['facility_id']][] = $r;
    else {
        $rk = kop_fact_record_key(0, (string) $r['program']);
        if ($rk === '') { $SKIP['no_record_key']++; continue; }
        $noreq[$rk][] = $r;
    }
}
$tot['fornits'] = count($F);
$tot['woodbury_held'] = array_sum(array_map('count', $held_by));
$f_by = array();
foreach ($F as $r) $f_by[(int) $r['facility_id']][] = $r;
$n_by = array();
$kept_by = array();
foreach ($NF as $r) {
    if ((int) $r['facility_id'] > 0) $n_by[(int) $r['facility_id']][] = $r;
    else { $SKIP['news_no_record']++; }
}
foreach ($pdo->query("SELECT n.id, n.news_id, n.category, n.subject_kind, n.facility_id, n.person, n.role, n.value, n.summary, n.standing, n.event_date, s.publication_date
    FROM news_facts n LEFT JOIN news_submissions s ON s.id = n.news_id WHERE n.status = 'kept' AND n.facility_id > 0") as $r) $kept_by[(int) $r['facility_id']][] = $r;
$fids = array_values(array_unique(array_merge(array_keys($pend_by), array_keys($f_by), array_keys($n_by))));
sort($fids);
fwrite(STDERR, sprintf("loaded: woodbury pending %d, held %d, fornits %d, news %d, %d records (%.1fs)\n", $tot['woodbury'], $tot['woodbury_held'], $tot['fornits'], $tot['news'], count($fids), microtime(true) - $t0));

// News links by facility: normalized article urls.
$news_urls = array();
foreach ($pdo->query("SELECT l.facility_id, s.article_url, s.archive_url FROM news_facility_links l JOIN news_submissions s ON s.id = l.news_id") as $r) {
    foreach (kop_normalize_urls(array($r['article_url'], $r['archive_url'])) as $u) $news_urls[(int) $r['facility_id']][$u] = true;
}

/* ------------------------------------------------------------------------------------------------ per record */

$AI = array();          // per record and group: ai candidate summary
$GROUPS = array();      // for --facility
$SHAPES = array();      // for --facility --shapes
$comp_stats = array();  // fold stats: group sizes
$largest = array();
$upper = array('woodbury' => 0, 'fornits' => 0, 'news' => 0);   // cleared if every AI candidate matched
$MIX = array('combo' => array(), 'combo_ex' => array(), 'combo_kind' => array(), 'one_origin' => array(), 'one_origin_ex' => array(), 'cross_kind' => array(), 'cross_ex' => array(), 'groups' => 0);
$QS = array();          // waiting items at start: [queue][src] => count
$NOTES = array();       // notes_specific

function member_row(array $f, $how) {
    return array('src' => $f['src'], 'srcs' => $f['srcs'], 'queue' => $f['queue'], 'kind' => $f['kind'], 'type' => $f['type'], 'state' => $f['state'], 'id' => $f['id'],
        'told_by' => $f['told_by'], 'text' => $f['text'], 'how' => $how);
}

/** Records the source-mix tables for one group of two or more facts (waiting, plus the record's own line it matched). */
function record_mix($rec, $name, array $members) {
    global $MIX, $N_EX;
    $fam = array();
    $kinds = array();
    $told = array();
    foreach ($members as $m) {
        foreach ($m['srcs'] as $s) $fam[$s] = true;
        $kinds[$m['kind']] = true;
        foreach ($m['told_by'] as $t) $told[$t] = true;
    }
    ksort($fam);
    ksort($kinds);
    $combo = implode('+', array_keys($fam));
    $kk = implode('+', array_keys($kinds));
    $MIX['groups']++;
    $row = array('rec' => $rec, 'name' => $name, 'combo' => $combo, 'kinds' => $kk, 'tellings' => count($told), 'members' => $members);
    if (count($fam) > 1) {
        $MIX['combo'][$combo] = ($MIX['combo'][$combo] ?? 0) + 1;
        $MIX['combo_kind'][$combo][$kk] = ($MIX['combo_kind'][$combo][$kk] ?? 0) + 1;
        $MIX['combo_tell'][$combo] = ($MIX['combo_tell'][$combo] ?? 0) + count($told);
        if (count($MIX['combo_ex'][$combo] ?? array()) < max(1, $N_EX)) $MIX['combo_ex'][$combo][] = $row;
    } elseif (count($told) > 1) {
        $key = $combo . ' / ' . $kk;
        $MIX['one_origin'][$key] = ($MIX['one_origin'][$key] ?? 0) + 1;
        if (count($MIX['one_origin_ex'][$key] ?? array()) < max(1, $N_EX)) $MIX['one_origin_ex'][$key][] = $row;
    }
    if (count($kinds) > 1) {
        $MIX['cross_kind'][$kk] = ($MIX['cross_kind'][$kk] ?? 0) + 1;
        if (count($MIX['cross_ex'][$kk] ?? array()) < max(1, $N_EX)) $MIX['cross_ex'][$kk][] = $row;
    }
}

/**
 * Steps c and d on the waiting items of one record (or program key). $items are shapes; $onhand are the record's own
 * facts (kop_fact_onhand(), incl. held). Fills $S, $EX and the mix tables.
 */
function fold_and_candidates($rec, $name, array $items, array $onhand) {
    global $comp_stats, $largest, $AI, $GROUPS, $ONLY, $upper;
    $n = count($items);
    $uf = new UF($n);
    $how = array();
    foreach (block_pairs($items) as $pr) {
        list($i, $j) = $pr;
        if ($uf->find($i) === $uf->find($j)) continue;
        $why = kop_fact_rule_same($items[$i], $items[$j]);
        if ($why !== '') { $uf->union($i, $j); $how[$j] = $why; }
    }
    $comps = array();
    foreach ($items as $i => $it) $comps[$uf->find($i)][] = $i;
    $reps = array();
    foreach ($comps as $members) {
        // Keep: the Woodbury item with the longest wording, else the longest wording overall.
        usort($members, function ($a, $b) use ($items) {
            $wa = $items[$a]['queue'] === 'wbf' ? 1 : 0;
            $wb = $items[$b]['queue'] === 'wbf' ? 1 : 0;
            return $wb <=> $wa ?: strlen($items[$b]['core'] ?: $items[$b]['text']) <=> strlen($items[$a]['core'] ?: $items[$a]['text']) ?: $a <=> $b;
        });
        $keep = $members[0];
        $reps[] = $keep;
        if (count($members) < 2) continue;
        $g = kop_fact_group($items[$keep]);
        $queues = array();
        foreach ($members as $m) $queues[qn($items[$m])] = true;
        $diff_role = 0;
        if ($g === 'person') {
            $roles = array();
            foreach ($members as $m) $roles[strtolower(trim($items[$m]['type']))] = true;
            $diff_role = count($roles) > 1 ? 1 : 0;
        }
        $grp = array('fid' => $rec, 'name' => $name, 'class' => $items[$keep]['kind'], 'size' => count($members), 'queues' => implode('+', array_keys($queues)),
            'diff_role' => $diff_role, 'keep' => $items[$keep]['text'], 'members' => array());
        $mix_members = array();
        foreach ($members as $m) {
            $f = $items[$m];
            $grp['members'][] = array('src' => $f['src'], 'kind' => $f['kind'], 'id' => $f['id'], 'text' => $f['text'], 'how' => $how[$m] ?? '');
            $mix_members[] = member_row($f, $how[$m] ?? '');
            if ($m !== $keep) {
                $kind = $f['kind'] . ($g === 'person' ? ($diff_role ? ' (other role)' : ' (same role)') : '');
                bump('c_fold', qn($f), $kind);
                bump('c_fold_src', $f['src'], $kind);
                ex('c_fold', qn($f), $kind, array('fid' => $rec, 'name' => $name, 'wait' => cut($f['text']), 'match' => cut($items[$keep]['text']), 'id' => $f['id'], 'how' => $how[$m] ?? ($how[$keep] ?? '')));
            }
        }
        // The record's own fact the group's keeper matches, when there is one (waiting plus on hand).
        foreach ($onhand as $o) {
            if (kop_fact_rule_same($items[$keep], $o) !== '') { $mix_members[] = member_row($o, 'on hand'); $grp['members'][] = array('src' => 'record', 'kind' => $o['kind'], 'id' => $o['id'], 'text' => $o['text'], 'how' => 'on hand'); break; }
        }
        if (count($queues) > 1) bump('c_cross_queue_groups', 'all', $g);
        bump('c_groups', 'all', $g);
        $comp_stats[$g][] = count($members);
        $largest[] = array('size' => count($members), 'fid' => $rec, 'name' => $name, 'class' => $g, 'keep' => cut($items[$keep]['text'], 120));
        record_mix($rec, $name, $mix_members);
        if ($ONLY) $GROUPS[] = $grp;
    }

    // Step d: close pairs among the representatives, and between a representative and the facts already on hand.
    $all = array();
    foreach ($reps as $k) $all[] = $items[$k];
    $nrep = count($all);
    foreach ($onhand as $o) $all[] = $o;
    $by_group = array();
    $uf = new UF(count($all) + 1);
    $sentinel = count($all);
    $in_pair = array();
    $pairs_found = array();
    foreach (block_pairs($all) as $pr) {
        list($i, $j) = $pr;
        if ($i >= $nrep && $j >= $nrep) continue;                    // two facts already on hand
        if (!kop_fact_close($all[$i], $all[$j])) continue;
        if (kop_fact_rule_same($all[$i], $all[$j]) !== '') continue;
        if ($i < $nrep) { $rep = $i; $other = $j; } else { $rep = $j; $other = $i; }
        $in_pair[$rep] = true;
        if ($other >= $nrep) { $uf->union($rep, $sentinel); } else { $in_pair[$other] = true; $uf->union($rep, $other); }
        $pairs_found[] = array($rep, $other);
    }
    if (!$pairs_found) return;
    $groups = array();
    foreach ($in_pair as $i => $_) $groups[kop_fact_group($all[$i])][] = $i;
    foreach ($groups as $g => $lines) {
        $oh = array();
        foreach ($onhand as $o) if (kop_fact_group($o) === $g) $oh[] = $o;
        $chars = PROMPT_OVERHEAD_CHARS;
        foreach ($oh as $o) $chars += strlen($o['text']) + 6;
        foreach ($lines as $i) $chars += strlen($all[$i]['text']) + 6;
        $chars_trim = PROMPT_OVERHEAD_CHARS;
        $needed = array();
        foreach ($pairs_found as $pr) if ($pr[1] >= $nrep && kop_fact_group($all[$pr[0]]) === $g) $needed[$pr[1]] = true;
        foreach ($needed as $i => $_) $chars_trim += strlen($all[$i]['text']) + 6;
        foreach ($lines as $i) $chars_trim += strlen($all[$i]['text']) + 6;
        $AI[$rec . '|' . $g] = array('fid' => $rec, 'name' => $name, 'class' => $g, 'lines' => count($lines), 'onhand' => count($oh), 'chars' => $chars, 'chars_trim' => $chars_trim,
            'pairs' => count($pairs_found));
    }
    foreach ($in_pair as $i => $_) bump('d_ai_lines', qn($all[$i]), $all[$i]['kind']);
    $comps = array();
    foreach ($in_pair as $i => $_) $comps[$uf->find($i)][] = $i;
    $sroot = $uf->find($sentinel);
    foreach ($comps as $root => $members) {
        if ($root === $sroot) {
            foreach ($members as $i) { bump('d_upper_clear', qn($all[$i]), $all[$i]['kind']); $upper[qn($all[$i])]++; }
        } elseif (count($members) > 1) {
            usort($members, function ($a, $b) use ($all) { return strlen($all[$b]['text']) <=> strlen($all[$a]['text']); });
            for ($k = 1; $k < count($members); $k++) { bump('d_upper_clear', qn($all[$members[$k]]), $all[$members[$k]]['kind']); $upper[qn($all[$members[$k]])]++; }
        }
    }
    foreach ($pairs_found as $pr) {
        list($i, $j) = $pr;
        ex('d_ai', qn($all[$i]), $all[$i]['kind'], array('fid' => $rec, 'name' => $name, 'wait' => cut($all[$i]['text']),
            'match' => cut($all[$j]['text']) . ($j >= $nrep ? '   [on hand]' : '   [also waiting]'), 'id' => $all[$i]['id'], 'how' => ''));
    }
    if ($ONLY) {
        $GROUPS[] = array('fid' => $rec, 'name' => $name, 'class' => 'AI pairs', 'size' => count($pairs_found), 'queues' => '', 'diff_role' => 0, 'keep' => '',
            'members' => array_map(function ($pr) use ($all, $nrep) {
                return array('src' => $all[$pr[0]]['src'], 'kind' => 'pair', 'id' => $all[$pr[0]]['id'], 'text' => $all[$pr[0]]['text'] . "\n        ~ " . $all[$pr[1]]['text'] . ($pr[1] >= $nrep ? '  [on hand]' : ''), 'how' => '');
            }, $pairs_found));
    }
}

/** Counts a waiting item by queue and src (the queue split). */
function tally_start(array $f) {
    global $QS;
    $QS[qn($f)][$f['src']] = ($QS[qn($f)][$f['src']] ?? 0) + 1;
}

$stmtDoc = $pdo->prepare('SELECT name, json_data FROM facilities_v2 WHERE id = ?');
$ops_ok = array('add_staff', 'add_list', 'set_if_empty', 'set_closed');
$done = 0;

foreach ($fids as $fid) {
    $stmtDoc->execute(array($fid));
    $rec = $stmtDoc->fetch(PDO::FETCH_ASSOC);
    $doc = $rec ? json_decode((string) $rec['json_data'], true) : null;
    $name = $rec ? (string) $rec['name'] : '';
    $P = $pend_by[$fid] ?? array();
    $FR = $f_by[$fid] ?? array();
    $NR = $n_by[$fid] ?? array();
    if (!is_array($doc)) {
        $SKIP['no_doc'] += count($P) + count($FR) + count($NR);
        continue;
    }
    // Lay the held items over a copy of the doc.
    $docH = $doc;
    foreach ($held_by[$fid] ?? array() as $h) {
        if (!in_array($h['op'], $ops_ok, true)) continue;
        try { kop_wbf_doc_apply($docH, $h); } catch (Throwable $e) { $SKIP['apply_failed']++; }
    }
    // The record's own facts: on record, and held (the held rows as shapes, not laid over the doc).
    $onhand_all = kop_fact_onhand($doc, $fid, $held_by[$fid] ?? array(), $kept_by[$fid] ?? array(), $news_urls[$fid] ?? array());
    $onhand_doc = array_values(array_filter($onhand_all, function ($f) { return $f['state'] === 'on_record'; }));
    $onhand_held = array_values(array_filter($onhand_all, function ($f) { return $f['state'] !== 'on_record'; }));
    $match_line = function (array $it, array $pool) {
        foreach ($pool as $o) {
            if (kop_fact_rule_same($it, $o) !== '') return $o;
        }
        return null;
    };
    $ctx = array('fid' => $fid, 'record_name' => $name);

    $items = array();   // waiting items still alive after baseline + b

    /* ---- Woodbury ---- */
    foreach ($P as $r) {
        $k = $r['path'];
        if (!in_array($r['op'], $ops_ok, true)) { bump('other_ops', 'woodbury', $r['op']); continue; }
        if ((string) $r['reviewed_by'] !== '' || strpos((string) $r['extra'], '"edited_by"') !== false) { $SKIP['left_alone']++; bump('left_alone', 'woodbury', $k); continue; }
        $f = kop_fact_shape('wbf', $r, $ctx);
        $text = $f ? $f['text'] : (string) $r['label'];
        $why = kop_wbf_on_record($doc, $r);
        if ($why !== '') {
            if (kop_wbf_conflict_parts($doc, $r)) { bump('b0_conflict', 'woodbury', $k); }
            else { bump('b0_baseline', 'woodbury', $k); ex('b0_baseline', 'woodbury', $k, array('fid' => $fid, 'name' => $name, 'wait' => cut($text), 'match' => cut($why), 'id' => $r['pkey'], 'how' => '')); continue; }
        }
        $why = kop_wbf_on_record($docH, $r);
        if ($why !== '') {
            if (kop_wbf_conflict_parts($docH, $r)) { bump('b1_conflict', 'woodbury', $k); }
            else { bump('b1_held_covered', 'woodbury', $k); ex('b1_held_covered', 'woodbury', $k, array('fid' => $fid, 'name' => $name, 'wait' => cut($text), 'match' => cut($why), 'id' => $r['pkey'], 'how' => '')); continue; }
        }
        if ($f) $items[] = $f;
    }

    /* ---- Fornits ---- */
    foreach ($FR as $r) {
        $kind = $r['kind'];
        $v = json_decode((string) $r['value'], true) ?: array();
        $f = kop_fact_shape('fornits', $r, $ctx);
        if ($kind === 'link') {
            $url = (string) ($v['url'] ?? '');
            $keys = kop_normalize_urls($url);
            $key = $keys ? $keys[0] : '';
            $have = array();
            foreach ((array) ($doc['resourceLinks'] ?? array()) as $l) if (is_array($l)) foreach (kop_normalize_urls($l['url'] ?? '') as $u) $have[$u] = true;
            if ($key !== '' && isset($have[$key])) { bump('b0_baseline', 'fornits', 'link'); ex('b0_baseline', 'fornits', 'link', array('fid' => $fid, 'name' => $name, 'wait' => cut($url), 'match' => 'in Materials and links', 'id' => $r['pkey'], 'how' => '')); continue; }
            if ($key !== '' && isset($news_urls[$fid][$key])) { bump('b1_held_covered', 'fornits', 'link (news link)'); ex('b1_held_covered', 'fornits', 'link (news link)', array('fid' => $fid, 'name' => $name, 'wait' => cut($url), 'match' => 'an article linked to this record', 'id' => $r['pkey'], 'how' => '')); continue; }
            if ($f) $items[] = $f;
        } elseif ($kind === 'staff') {
            $pn = (string) ($v['person'] ?? '');
            $role = trim(($v['role'] ?? '') . (!empty($v['years']) ? ' (' . $v['years'] . ')' : ''));
            $row = array('op' => 'add_staff', 'path' => 'staff.notableStaff', 'label' => 'Staff: ' . $pn, 'extra' => '', 'evidence' => '[]',
                'value' => wp_json_encode(array('name' => $pn, 'role' => $role, 'pastJobs' => '')), 'facility_id' => $fid, 'conflict' => '', 'current_val' => '');
            // Baseline = the existing Fornits rule: same person key listed with a role.
            $key = kop_wbf_person_key($pn);
            $base = false;
            foreach (array('staff.administrator', 'staff.notableStaff') as $p) foreach ((array) kop_wbf_get($doc, $p) as $s) {
                if ($key !== '' && is_array($s) && kop_wbf_person_key($s['name'] ?? '') === $key && trim((string) ($s['role'] ?? '')) !== '') $base = $s['name'] . ' (' . $s['role'] . ')';
            }
            if ($base) { bump('b0_baseline', 'fornits', 'staff'); ex('b0_baseline', 'fornits', 'staff', array('fid' => $fid, 'name' => $name, 'wait' => cut($pn . ' / ' . $role), 'match' => cut($base), 'id' => $r['pkey'], 'how' => '')); continue; }
            $why = kop_wbf_on_record($docH, $row);
            if ($why !== '') {
                if (kop_wbf_conflict_parts($docH, $row)) bump('b1_conflict', 'fornits', 'staff');
                else { bump('b1_held_covered', 'fornits', 'staff'); ex('b1_held_covered', 'fornits', 'staff', array('fid' => $fid, 'name' => $name, 'wait' => cut($pn . ' / ' . $role), 'match' => cut($why), 'id' => $r['pkey'], 'how' => '')); continue; }
            }
            // Same person listed (on the doc or held) in another role: informational, not cleared.
            foreach (array('staff.administrator', 'staff.notableStaff') as $p) foreach ((array) kop_wbf_get($docH, $p) as $s) {
                if (is_array($s) && kop_dd_same_person($pn, (string) ($s['name'] ?? ''))) { bump('b1_person_listed_other_role', 'fornits', 'staff'); break 2; }
            }
            if ($f) $items[] = $f;
        } elseif ($kind === 'incident' && $f) {
            $hit = $match_line($f, $onhand_doc);
            if ($hit) { bump('b0_baseline', 'fornits', 'incident (rules, not the live check)'); ex('b0_baseline', 'fornits', 'incident (rules, not the live check)', array('fid' => $fid, 'name' => $name, 'wait' => cut($f['text']), 'match' => cut($hit['text']), 'id' => $r['pkey'], 'how' => '')); continue; }
            $hit = $match_line($f, $onhand_held);
            if ($hit) { bump('b1_held_covered', 'fornits', 'incident', 1); ex('b1_held_covered', 'fornits', 'incident', array('fid' => $fid, 'name' => $name, 'wait' => cut($f['text']), 'match' => cut($hit['text']), 'id' => $r['pkey'], 'how' => '')); continue; }
            $items[] = $f;
        } elseif ($kind === 'lead' && $f) {
            $hit = $match_line($f, $onhand_held);
            if ($hit) { bump('b1_held_covered', 'fornits', 'lead', 1); ex('b1_held_covered', 'fornits', 'lead', array('fid' => $fid, 'name' => $name, 'wait' => cut($f['text']), 'match' => cut($hit['text']), 'id' => $r['pkey'], 'how' => '')); continue; }
            $items[] = $f;
        } elseif ($kind === 'testimony') {
            $id = 'fornits-' . (int) $r['topic_id'] . '-' . (int) $r['post_n'];
            $has = false;
            foreach ((array) ($doc['survivorTestimony'] ?? array()) as $t) if (is_array($t) && ($t['id'] ?? '') === $id) $has = true;
            if ($has) { bump('b0_baseline', 'fornits', 'testimony'); continue; }
            bump('not_measured', 'fornits', 'testimony');
            if ($f) $items[] = $f;   // never grouped: its blocks are empty
        }
    }

    /* ---- News facts (record known, or linked by the exact-name rule) ---- */
    foreach ($NR as $r) {
        $f = kop_fact_shape('news', $r, $ctx);
        if (!$f) { bump('not_measured', 'news', 'person/agency subject'); continue; }
        $cat = (string) $r['category'];
        $hit = $match_line($f, $onhand_doc);
        if ($hit) { bump('b0_baseline', 'news', $cat); ex('b0_baseline', 'news', $cat, array('fid' => $fid, 'name' => $name, 'wait' => cut($f['text']), 'match' => cut($hit['text']), 'id' => $f['id'], 'how' => '')); continue; }
        $hit = $match_line($f, $onhand_held);
        if ($hit) { bump('b1_held_covered', 'news', $cat); ex('b1_held_covered', 'news', $cat, array('fid' => $fid, 'name' => $name, 'wait' => cut($f['text']), 'match' => cut($hit['text']), 'id' => $f['id'], 'how' => '')); continue; }
        $items[] = $f;
    }

    foreach ($items as $f) tally_start($f);
    if ($ONLY && !empty($opt['shapes'])) $SHAPES = array_merge($SHAPES, $items, $onhand_all);
    if ($items) fold_and_candidates((string) $fid, $name, $items, $onhand_all);
    if ((++$done % 250) === 0) fwrite(STDERR, sprintf("  %d/%d records (%.0fs)\n", $done, count($fids), microtime(true) - $t0));
}

/* ---- Woodbury items with no record: grouped by program key ---- */
if (!$ONLY) {
    foreach ($noreq as $rk => $rows) {
        $items = array();
        $pname = (string) $rows[0]['program'];
        foreach ($rows as $r) {
            if (!in_array($r['op'], $ops_ok, true)) { bump('other_ops', 'woodbury', $r['op']); continue; }
            $f = kop_fact_shape('wbf', $r, array());
            if ($f) { $f['record'] = $rk; $items[] = $f; }
        }
        foreach ($items as $f) tally_start($f);
        if ($items) fold_and_candidates($rk, $pname, $items, array());
    }
}

/* ---- notes_specific: waiting and held notes by the kind kop_fact_note_kind() gives ---- */
foreach ($W as $r) {
    if ($r['op'] !== 'add_list' || $r['path'] !== 'notes') continue;
    $v = kop_wbf_row_value($r);
    $text = is_array($v) ? (string) ($v['raw'] ?? '') : (string) $v;
    $extra = json_decode((string) $r['extra'], true) ?: array();
    $nk = kop_fact_note_kind($text, (string) ($extra['category'] ?? ''));
    $NOTES[$r['status']][$nk[0]] = ($NOTES[$r['status']][$nk[0]] ?? 0) + 1;
}

/* ------------------------------------------------------------------------------------------------ report */

$run = microtime(true) - $t0;
$sumq = function ($bucket, $queue = null) use ($S) {
    $n = 0;
    foreach ($S[$bucket] ?? array() as $q => $kinds) if ($queue === null || $q === $queue) $n += array_sum($kinds);
    return $n;
};
$out = array('db' => $db_path, 'generated' => gmdate('c'), 'runtime_s' => round($run, 1), 'constants' => array('BIG_BLOCK' => BIG_BLOCK), 'summary' => array(), 'counts' => $S,
    'skipped' => $SKIP, 'news_linking' => $NEWS_LINK);

echo "\n=== Fact dedupe measurement (read only) ===  db={$db_path}  runtime " . round($run, 1) . "s" . ($ONLY ? "  facility=$ONLY" : '') . "\n";
echo "Waiting now: woodbury {$tot['woodbury']} (held, not on record yet: {$tot['woodbury_held']}), fornits {$tot['fornits']}, news facts {$tot['news']}\n";
echo "Skipped: " . json_encode($SKIP) . "   (left_alone = edited/sent back; news_no_record = still no record after the exact-name rule, so not grouped)\n\n";

$print_bucket = function ($title, $bucket) use ($S) {
    echo "--- $title ---\n";
    if (empty($S[$bucket])) { echo "  (none)\n\n"; return; }
    foreach ($S[$bucket] as $q => $kinds) {
        arsort($kinds);
        echo sprintf("  %-9s total %6d\n", $q, array_sum($kinds));
        foreach ($kinds as $k => $n) echo sprintf("      %-48s %6d\n", $k, $n);
    }
    echo "\n";
};

// 7.2: news linking.
echo "--- News facts with no record: the exact-name rule (spec 7.2), measured only; nothing is written ($no_record_total facts) ---\n";
arsort($NEWS_LINK);
foreach ($NEWS_LINK as $o => $n) echo sprintf("  %5d  %s\n", $n, $o);
$linked_n = 0;
foreach ($NEWS_LINK as $o => $n) if (strpos($o, 'LINKED') !== false) $linked_n += $n;
echo "  => the rule links $linked_n of $no_record_total\n";
if ($N_EX > 0 && !isset($opt['noexamples'])) {
    foreach ($NEWS_LINK_EX as $o => $list) {
        echo "  ## $o\n";
        foreach (array_slice($list, 0, $N_EX) as $line) echo "      $line\n";
    }
}
echo "\n";

// The queue split, by queue and by src.
echo "--- Waiting items alive after the baseline and held checks, by queue and by src (origin family) ---\n";
foreach ($QS as $q => $srcs) {
    arsort($srcs);
    echo sprintf("  %-9s %6d   %s\n", $q, array_sum($srcs), implode(', ', array_map(function ($s, $n) { return "$s $n"; }, array_keys($srcs), $srcs)));
}
$bysrc = array();
foreach ($QS as $srcs) foreach ($srcs as $s => $n) $bysrc[$s] = ($bysrc[$s] ?? 0) + $n;
arsort($bysrc);
echo "  by src:   " . implode(', ', array_map(function ($s, $n) { return "$s $n"; }, array_keys($bysrc), $bysrc)) . "\n\n";
$out['queue_split'] = $QS;

$print_bucket('0. Baseline: already on the record (what the hourly clear takes; Fornits by its own on-file rules)', 'b0_baseline');
$print_bucket('0b. On the record but a CONFLICT (stays waiting)', 'b0_conflict');
$print_bucket('b. Covered by HELD items / news links (new vs baseline)', 'b1_held_covered');
$print_bucket('b. ...would be covered but conflicts (stays, Conflicts tab)', 'b1_conflict');
$print_bucket('b. info: Fornits staff already listed under another role/name (not cleared)', 'b1_person_listed_other_role');
$print_bucket('c. FOLD among waiting items (items that go away, group size - 1), by queue', 'c_fold');
$print_bucket('c. FOLD by src (origin family of the folded item)', 'c_fold_src');
echo "--- c. groups ---\n";
foreach ($S['c_groups']['all'] ?? array() as $c => $n) {
    $sizes = $comp_stats[$c] ?? array();
    rsort($sizes);
    echo sprintf("  %-18s groups %5d   cross-queue groups %5d   largest %s\n", $c, $n, $S['c_cross_queue_groups']['all'][$c] ?? 0, implode(',', array_slice($sizes, 0, 8)));
}
usort($largest, function ($a, $b) { return $b['size'] <=> $a['size']; });
echo "  largest groups:\n";
foreach (array_slice($largest, 0, 10) as $g) echo sprintf("    %4d  #%s %s [%s]  keep: %s\n", $g['size'], $g['fid'], cut($g['name'], 40), $g['class'], $g['keep']);
echo "\n";

// Groups that mix sources.
$print_members = function (array $row) {
    echo sprintf("    #%s %s  [%s]  kinds %s, %d tellings\n", $row['rec'], cut($row['name'], 40), $row['combo'], $row['kinds'], $row['tellings']);
    foreach ($row['members'] as $m) echo sprintf("       %-9s %-24s %s  %s%s\n", $m['src'], cut(implode(',', array_slice($m['told_by'], 0, 3)), 24), cut($m['text'], 150), $m['how'] !== '' ? '<' . $m['how'] . '>' : '', '');
};
echo "--- Groups that mix sources (two or more origin families; waiting, plus the record's line when one matches) ---\n";
$mixed_total = array_sum($MIX['combo']);
echo "  groups of two or more: {$MIX['groups']}; mixing sources: $mixed_total\n";
arsort($MIX['combo']);
foreach ($MIX['combo'] as $combo => $n) {
    echo sprintf("  %5d  %-28s tellings %d;  kinds: %s\n", $n, $combo, $MIX['combo_tell'][$combo] ?? 0,
        implode(', ', array_map(function ($k, $c) { return "$k $c"; }, array_keys(array_slice($MIX['combo_kind'][$combo], 0, 6, true)), array_slice($MIX['combo_kind'][$combo], 0, 6, true))));
}
echo "\n--- One origin, several tellings (groups of one origin family told by two or more articles or pages) ---\n";
arsort($MIX['one_origin']);
foreach ($MIX['one_origin'] as $k => $n) echo sprintf("  %5d  %s\n", $n, $k);
echo "\n--- Joined across kinds (spec 6.2) ---\n";
arsort($MIX['cross_kind']);
foreach ($MIX['cross_kind'] as $k => $n) echo sprintf("  %5d  %s\n", $n, $k);
echo "\n";
if ($N_EX > 0 && !isset($opt['noexamples'])) {
    foreach ($MIX['combo_ex'] as $combo => $list) {
        echo "=== EXAMPLES: mixed sources $combo ===\n";
        foreach (array_slice($list, 0, $N_EX) as $row) $print_members($row);
    }
    foreach ($MIX['one_origin_ex'] as $k => $list) {
        echo "=== EXAMPLES: one origin, several tellings $k ===\n";
        foreach (array_slice($list, 0, min($N_EX, 5)) as $row) $print_members($row);
    }
    foreach ($MIX['cross_ex'] as $k => $list) {
        echo "=== EXAMPLES: joined across kinds $k ===\n";
        foreach (array_slice($list, 0, $N_EX) as $row) $print_members($row);
    }
    echo "\n";
}
$out['mix'] = array('groups' => $MIX['groups'], 'combo' => $MIX['combo'], 'one_origin' => $MIX['one_origin'], 'cross_kind' => $MIX['cross_kind']);

$print_bucket('d. AI candidate lines (waiting lines that are in a close pair)', 'd_ai_lines');
$print_bucket('d. upper bound: would clear/fold if every AI candidate were a match', 'd_upper_clear');
$print_bucket('not measured (no rule for this kind)', 'not_measured');
$print_bucket('Woodbury items with an op outside the four known', 'other_ops');
$print_bucket('Edited / sent back (left alone by every step)', 'left_alone');

// notes_specific.
echo "--- notes_specific: waiting and held notes by the kind kop_fact_note_kind() gives ---\n";
foreach (array('pending', 'held') as $st) {
    $k = $NOTES[$st] ?? array();
    $all = array_sum($k);
    $spec = $all - ($k['note'] ?? 0);
    arsort($k);
    echo sprintf("  %-8s %5d notes, %5d with a kind more specific than note (%d%%):  %s\n", $st, $all, $spec, $all ? round(100 * $spec / $all) : 0,
        implode(', ', array_map(function ($a, $b) { return "$a $b"; }, array_keys($k), $k)));
}
echo "\n";
$out['notes_specific'] = $NOTES;

// AI estimate.
$ai_records = array();
$chars = $chars_trim = $ai_calls = 0;
foreach ($AI as $a) {
    $ai_records[$a['fid']] = true;
    $chars += $a['chars'];
    $chars_trim += $a['chars_trim'];
    $ai_calls++;
}
$ai_lines = $sumq('d_ai_lines');
echo "--- d. AI estimate (one call per record and fact group; tokens = chars/4) ---\n";
echo sprintf("  records %d (with a record: %d; program names without one: %d), calls %d, waiting lines %d\n", count($ai_records),
    count(array_filter(array_keys($ai_records), function ($k) { return strpos((string) $k, 'p:') !== 0; })), count(array_filter(array_keys($ai_records), function ($k) { return strpos((string) $k, 'p:') === 0; })), $ai_calls, $ai_lines);
echo sprintf("  prompt tokens, full (every on-hand fact of the group + waiting lines): ~%s;  trimmed (only on-hand facts that are in a pair): ~%s\n",
    number_format((int) ($chars * TOKENS_PER_CHAR)), number_format((int) ($chars_trim * TOKENS_PER_CHAR)));
$big = $AI;
uasort($big, function ($a, $b) { return $b['chars'] <=> $a['chars']; });
echo "  biggest single calls: ";
foreach (array_slice($big, 0, 5) as $a) echo sprintf("#%s %s %s ~%dk tok (%d waiting, %d on hand);  ", $a['fid'], cut($a['name'], 25), $a['class'], (int) ($a['chars'] * TOKENS_PER_CHAR / 1000), $a['lines'], $a['onhand']);
echo "\n\n";

// Totals.
echo "--- Totals: waiting now -> after steps ---\n";
echo sprintf("  %-9s %8s %10s %10s %10s %12s %12s\n", 'queue', 'now', 'baseline', 'b held', 'c fold', 'after b+c', 'after d(max)');
$totrows = array();
foreach (array('woodbury', 'fornits', 'news') as $q) {
    $now = $tot[$q];
    $b0 = $sumq('b0_baseline', $q);
    $b1 = $sumq('b1_held_covered', $q);
    $c = $sumq('c_fold', $q);
    $afterbc = $now - $b0 - $b1 - $c;
    $afterd = $afterbc - $upper[$q];
    $totrows[$q] = compact('now', 'b0', 'b1', 'c', 'afterbc', 'afterd');
    echo sprintf("  %-9s %8d %10d %10d %10d %12d %12d\n", $q, $now, $b0, $b1, $c, $afterbc, $afterd);
}
$sum = array_fill_keys(array('now', 'b0', 'b1', 'c', 'afterbc', 'afterd'), 0);
foreach ($totrows as $r) foreach ($r as $k => $v) $sum[$k] += $v;
echo sprintf("  %-9s %8d %10d %10d %10d %12d %12d\n", 'all', $sum['now'], $sum['b0'], $sum['b1'], $sum['c'], $sum['afterbc'], $sum['afterd']);
echo "  (news facts with no record, even after the exact-name rule, are counted in 'now' and never cleared here; Fornits leads/testimony/other kinds with no rule stay in 'after'.)\n";
echo "  (c fold counts each folded item once; folded items include cross-queue ones attributed to their own queue.)\n\n";
$out['summary'] = $totrows + array('ai' => array('records' => count($ai_records), 'calls' => $ai_calls, 'lines' => $ai_lines, 'prompt_tokens_full' => (int) ($chars * TOKENS_PER_CHAR), 'prompt_tokens_trimmed' => (int) ($chars_trim * TOKENS_PER_CHAR)));

// Examples: evenly spaced picks.
$pick = function (array $list, $n) {
    $c = count($list);
    if ($c <= $n) return $list;
    $o = array();
    for ($i = 0; $i < $n; $i++) $o[] = $list[(int) floor($i * $c / $n)];
    return $o;
};
$exout = array();
if ($N_EX > 0 && !isset($opt['noexamples'])) {
    ksort($EX);
    foreach ($EX as $key => $list) {
        $exout[$key] = array('total' => count($list), 'examples' => $pick($list, $N_EX));
    }
    $bk = array();
    foreach ($exout as $key => $e) $bk[explode('|', $key)[0]][$key] = $e;
    foreach ($bk as $bucket => $keys) {
        echo "=== EXAMPLES: $bucket ===\n";
        foreach ($keys as $key => $e) {
            echo "## " . substr($key, strlen($bucket) + 1) . "  (" . $e['total'] . " items)\n";
            foreach ($e['examples'] as $x) {
                echo sprintf("  #%s %s%s\n     waiting: %s\n     matches: %s\n", $x['fid'], cut($x['name'], 50), $x['how'] ? '  [' . $x['how'] . ']' : '', $x['wait'], $x['match']);
            }
        }
        echo "\n";
    }
}
$out['examples'] = $exout;

if ($ONLY) {
    echo "=== Groups on facility #$ONLY ===\n";
    foreach ($GROUPS as $g) {
        echo "\n[{$g['class']}] size {$g['size']}" . ($g['queues'] ? " queues {$g['queues']}" : '') . ($g['diff_role'] ? ' (roles differ)' : '') . "\n";
        foreach ($g['members'] as $m) echo sprintf("   %-8s %-34s %s%s\n", $m['src'], cut($m['kind'], 34), cut($m['text'], 260), $m['how'] ? '   <' . $m['how'] . '>' : '');
    }
    $out['groups'] = $GROUPS;
    if (!empty($opt['shapes'])) {
        echo "\n=== Shapes of facility #$ONLY (kind | type | date | told | place | keys | src | state | text) ===\n";
        foreach ($SHAPES as $s) {
            $k = $s['keys'];
            $keys = array();
            if ($k['person'] !== '') $keys[] = 'person=' . $k['person'] . ($k['suffix'] !== '' ? '/' . $k['suffix'] : '');
            if ($k['org']) $keys[] = 'org=' . implode(',', $k['org']);
            if ($k['name']) $keys[] = 'name=' . implode(',', $k['name']);
            if ($k['url'] !== '') $keys[] = 'url=' . $k['url'];
            if ($k['nums']) $keys[] = 'nums=' . implode(',', $k['nums']);
            if ($k['ages']) $keys[] = 'ages=' . implode(',', $k['ages']);
            if ($k['people']) $keys[] = 'people=' . implode(',', $k['people']);
            echo sprintf("%-10s %-16s %-10s %-10s %-14s %-30s %-9s %-9s %s\n", $s['kind'], $s['type'], $s['date'], $s['told'], implode(',', $s['place']), implode(' ', $keys), $s['src'], $s['state'], cut($s['text'], 160));
        }
        $out['shapes'] = $SHAPES;
    }
}
if (!empty($opt['json']) && $opt['json'] !== true) {
    file_put_contents($opt['json'], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    echo "JSON written to {$opt['json']}\n";
}
echo "Runtime " . round(microtime(true) - $t0, 1) . "s, peak memory " . round(memory_get_peak_usage(true) / 1048576) . " MB\n";
