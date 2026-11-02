<?php
/**
 * One card per fact (docs/plan/4.4 item 20), step (a): a READ-ONLY measurement.
 * What each planned step would clear or fold among the waiting facts, with examples.
 * Writes nothing (no database write, no AI call); it only reads tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/fact-dedupe.php --db=tmp/prod.sqlite
 *       [--examples=20] [--json=<path>] [--facility=10371] [--noexamples]
 *   --recheck-cleared   instead of the measurement: re-check every Woodbury item the hourly clear filed as "already on the
 *                       record" against its record with the current kop_wbf_on_record() and count those no longer on it.
 *
 * Buckets (queues: woodbury = wpdl_kop_woodbury_facts, fornits = wpdl_kop_fornits_items, news = news_facts):
 *   0  baseline   what kop_wbf_on_record() (the hourly clear) already says is on the record; Fornits: its on-file rules
 *   b  held       the record's HELD Woodbury items laid over a copy of its doc (kop_wbf_doc_apply()), then the same check;
 *                 Fornits staff/incidents via kop_wbf_on_record(); news facts by text/event match; Fornits links by URL
 *                 (resourceLinks + news links). A held-doc hit that is also a kop_wbf_conflict_parts() hit = "conflict".
 *   c  fold       waiting items among themselves, per record (or per normalized program name when there is no record):
 *                 text (kop_wbf_text_same on the citation-stripped lines, numbers equal both ways, dates not clashing)
 *                 or kop_dd_same_event; staff by person key / kop_dd_same_person; other facts by kop_wbf_fact_key.
 *                 Includes cross-queue (Fornits incidents/staff/leads and news facts join the Woodbury groups).
 *   d  ai         pairs left after the rules that are "close" (see the constants below).
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

// Close-pair rule for step d (pairs the rules did not join): compatible dates (no different year, no different
// month when both give one) and at least D_SHARED distinctive words in common and Jaccard of the distinctive words >= D_JACCARD.
const D_SHARED = 3;
const D_JACCARD = 0.30;
const TOKENS_PER_CHAR = 0.25;   // chars / 4
const PROMPT_OVERHEAD_CHARS = 1200;

require __DIR__ . '/kop-test-harness.php';   // WordPress stubs + $wpdb over PDO; $pdo is the mirror, opened for reading only
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
if (!function_exists('kop_rinbox_register')) { function kop_rinbox_register() {} }       // the file registers its screen on load; nothing to register here
require_once dirname(__DIR__) . '/inc/review-inbox/woodbury-facts.php';   // kop_wbf_on_record()
require_once dirname(__DIR__) . '/api/url-dedupe.php';                    // kop_normalize_urls()

/* ------------------------------------------------------------------------------------------------ helpers */

$S = array();    // counts: $S[bucket][queue][kind]
$EX = array();   // examples: $EX["bucket|queue|kind"][] = array
$SKIP = array('no_doc' => 0, 'apply_failed' => 0, 'left_alone' => 0, 'news_no_record' => 0);
function bump($bucket, $queue, $kind, $n = 1) { global $S; $S[$bucket][$queue][$kind] = ($S[$bucket][$queue][$kind] ?? 0) + $n; }
function ex($bucket, $queue, $kind, array $row) { global $EX; $EX[$bucket . '|' . $queue . '|' . $kind][] = $row; }
function cut($s, $n = 230) { $s = trim(preg_replace('/\s+/', ' ', (string) $s)); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1) . '…' : $s; }

function row_text(array $r) {
    $v = kop_wbf_row_value($r);
    if (is_array($v)) return (string) ($v['raw'] ?? ($v['name'] ?? wp_json_encode($v)));
    return (string) $v;
}
function doc_lines(array $doc, array $paths) {
    $out = array();
    foreach ($paths as $p) {
        foreach ((array) kop_wbf_get($doc, $p) as $line) {
            $line = is_array($line) ? (string) ($line['raw'] ?? ($line['name'] ?? '')) : (string) $line;
            if (trim($line) !== '') $out[] = array('path' => $p, 'text' => $line);
        }
    }
    return $out;
}
function path_class($path) {
    if ($path === 'criticalIncidents.customIncidents') return 'incident';
    if ($path === 'notes') return 'notes';
    return 'other';
}
const INCIDENT_NEWS = array('death', 'injury', 'abuse', 'neglect', 'arrest', 'conviction', 'criminal_charge', 'citation', 'fine', 'investigation',
    'lawsuit', 'license_action', 'settlement', 'restraint', 'seclusion', 'runaway', 'placement_ban', 'sexual_abuse');

/** A comparable item: {src, kind, id, fid, name, text, core, ev, words, dwords, nums, rel}. */
function make_item($src, $kind, $id, $fid, $name, $text, $incident, array $extra = array()) {
    static $extra_stop = array('school', 'facility', 'program', 'students', 'student', 'staff', 'resident', 'residents', 'campus', 'boys', 'girls',
        'alleged', 'found', 'said', 'according', 'reported', 'former', 'children', 'child', 'teen', 'teens', 'youth', 'while', 'which', 'were', 'than',
        'more', 'other', 'when', 'them', 'they', 'would', 'could', 'about', 'there', 'being', 'during', 'over', 'such', 'also', 'state', 'center');
    list($core, $year) = kop_wbf_text_core(trim($text), $incident);
    $caps = array();
    if (preg_match_all('/(?<=[a-z,;] )[A-Z][a-z]{2,}/', ' ' . $core, $cm)) {
        foreach ($cm[0] as $w) $caps[strtolower($w)] = true;
    }
    $ev = kop_dd_event_parts($text);
    $words = kop_wbf_words($core);
    $d = array();
    foreach ($words as $w) {
        if ((strlen($w) >= 4 || preg_match('/\d/', $w)) && !in_array($w, $extra_stop, true)) $d[$w] = true;
    }
    preg_match_all('/\d+(?:\.\d+)?/', str_replace(',', '', $core), $nm);
    $nums = array_values(array_unique($nm[0]));
    sort($nums);
    return $extra + array('caps' => $caps, 'src' => $src, 'kind' => $kind, 'id' => $id, 'fid' => $fid, 'name' => $name, 'text' => $text, 'core' => $core,
        'ev' => $ev, 'words' => $words, 'dwords' => $d, 'nums' => $nums, 'year' => $year);
}
function dates_clash(array $a, array $b) {
    $x = $a['ev']['date'];
    $y = $b['ev']['date'];
    if ($x === '' || $y === '') return false;
    if (substr($x, 0, 4) !== substr($y, 0, 4)) return true;
    if (strlen($x) >= 7 && strlen($y) >= 7 && substr($x, 0, 7) !== substr($y, 0, 7)) return true;
    return strlen($x) === 10 && strlen($y) === 10 && $x !== $y;
}
/** Rule decision for two lines: 'same', or '' . Counts text matches rejected by the stricter checks. */
function rule_same(array $a, array $b) {
    global $TIGHT;
    $how = '';
    if (kop_dd_same_event($a['text'], $b['text'])) {
        $how = 'event';
    } elseif ($a['core'] !== '' && $b['core'] !== '' && (kop_wbf_text_same($a['core'], $b['core']) || kop_wbf_text_same($b['core'], $a['core']))) {
        if ($a['nums'] !== $b['nums'] || dates_clash($a, $b)) { $TIGHT++; return ''; }
        $how = 'text';
    }
    if ($how !== '') {
        // Joined, but one telling names someone or somewhere the other does not (Springville vs Provo campus): a risk to read.
        $da = array_diff_key($a['caps'], $b['caps']);
        $db = array_diff_key($b['caps'], $a['caps']);
        if ($da && $db) {
            $GLOBALS['NAMEDIFF']++;
            if (count($GLOBALS['NAMEDIFF_EX']) < 600) $GLOBALS['NAMEDIFF_EX'][] = array('a' => $a['text'], 'b' => $b['text'], 'fid' => $a['fid'], 'only_a' => implode(',', array_keys($da)), 'only_b' => implode(',', array_keys($db)), 'how' => $how);
        }
    }
    return $how;
}
function close_pair(array $a, array $b) {
    if (dates_clash($a, $b)) return false;
    $shared = count(array_intersect_key($a['dwords'], $b['dwords']));
    if ($shared < D_SHARED) return false;
    $union = count($a['dwords']) + count($b['dwords']) - $shared;
    return $union > 0 && $shared / $union >= D_JACCARD;
}
$TIGHT = 0;
$NAMEDIFF = 0;
$NAMEDIFF_EX = array();

/** Union-find over item indexes. */
class UF {
    public $p = array();
    function __construct($n) { $this->p = range(0, max(0, $n - 1)); }
    function find($x) { while ($this->p[$x] !== $x) { $this->p[$x] = $this->p[$this->p[$x]]; $x = $this->p[$x]; } return $x; }
    function union($a, $b) { $a = $this->find($a); $b = $this->find($b); if ($a !== $b) $this->p[$b] = $a; }
}

/** Candidate pairs (i<j) worth testing: they share >= 2 distinctive words, or are two dated lines of one kind in one year. */
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
        if ($it['ev']['date'] !== '' && $it['ev']['kind'] !== '') {
            $k = $it['ev']['kind'] . '|' . substr($it['ev']['date'], 0, 4);
            foreach ($dated[$k] ?? array() as $j) $pairs[$j . ',' . $i] = array($j, $i);
            if (count($dated[$k] ?? array()) < 400) $dated[$k][] = $i;
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

$ops4 = "'add_staff','add_list','set_if_empty','set_closed'";
$W = $pdo->query("SELECT id, pkey, facility_id, program, op, path, value, label, conflict, current_val, extra, evidence, status, applied_fid, reviewed_by
    FROM wpdl_kop_woodbury_facts WHERE status IN ('pending','held')" . ($ONLY ? " AND (facility_id = $ONLY OR applied_fid = $ONLY)" : '') . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$F = $pdo->query("SELECT id, pkey, topic_id, kind, facility_id, label, value, post_n, post_date, author FROM wpdl_kop_fornits_items
    WHERE status = 'pending'" . ($ONLY ? " AND facility_id = $ONLY" : ''))->fetchAll(PDO::FETCH_ASSOC);
$NF = $pdo->query("SELECT id, news_id, category, subject_name, facility_id, person, role, value, summary, event_date FROM news_facts
    WHERE status = 'pending' AND route = 'waiting'" . ($ONLY ? " AND facility_id = $ONLY" : ''))->fetchAll(PDO::FETCH_ASSOC);

$tot = array('woodbury' => 0, 'fornits' => 0, 'news' => count($NF));
$held_by = array();      // fid => [rows]
$pend_by = array();      // fid => [rows]
$noreq = array();        // program key => [rows] (facility_id = 0)
foreach ($W as $r) {
    if ($r['status'] === 'held') {
        $fid = (int) ($r['applied_fid'] ?: $r['facility_id']);
        if ($fid > 0) $held_by[$fid][] = $r;
        continue;
    }
    $tot['woodbury']++;
    if ((int) $r['facility_id'] > 0) $pend_by[(int) $r['facility_id']][] = $r;
    else $noreq[kop_wbf_org_key($r['program']) ?: 'program:' . $r['program']][] = $r;
}
$tot['fornits'] = count($F);
$tot['woodbury_held'] = array_sum(array_map('count', $held_by));
$f_by = array();
foreach ($F as $r) $f_by[(int) $r['facility_id']][] = $r;
$n_by = array();
foreach ($NF as $r) {
    if ((int) $r['facility_id'] > 0) $n_by[(int) $r['facility_id']][] = $r;
    else { $SKIP['news_no_record']++; }
}
$fids = array_values(array_unique(array_merge(array_keys($pend_by), array_keys($f_by), array_keys($n_by))));
sort($fids);
fwrite(STDERR, sprintf("loaded: woodbury pending %d, held %d, fornits %d, news %d, %d records (%.1fs)\n", $tot['woodbury'], $tot['woodbury_held'], $tot['fornits'], $tot['news'], count($fids), microtime(true) - $t0));

// News links by facility: normalized article urls.
$news_urls = array();
foreach ($pdo->query("SELECT l.facility_id, s.article_url, s.archive_url FROM news_facility_links l JOIN news_submissions s ON s.id = l.news_id") as $r) {
    foreach (kop_normalize_urls(array($r['article_url'], $r['archive_url'])) as $u) $news_urls[(int) $r['facility_id']][$u] = true;
}

/* ------------------------------------------------------------------------------------------------ per record */

$AI = array();          // per record: ai candidate summary
$GROUPS = array();      // for --facility
$comp_stats = array();  // fold stats: group sizes
$largest = array();
$left = array('woodbury' => array(), 'fornits' => array(), 'news' => array());   // counts of items still waiting after b+c per queue
$upper = array('woodbury' => 0, 'fornits' => 0, 'news' => 0);                    // cleared if every AI candidate matched

/** Run steps c and d on a list of waiting items for one record (or one program key). Returns nothing; fills $S and $EX. */
function fold_and_candidates($label_fid, $label_name, array $items, array $onhand, array $queue_totals, $has_doc) {
    global $comp_stats, $largest, $AI, $GROUPS, $ONLY, $upper;
    // Split by class.
    $cls = array();
    foreach ($items as $k => $it) $cls[$it['cls']][] = $k;
    $folded = array();      // item index => kept item index
    $comp_of = array();
    $reps_by_class = array();

    foreach ($cls as $c => $idxs) {
        $sub = array();
        foreach ($idxs as $k) $sub[] = $items[$k];
        $n = count($sub);
        $uf = new UF($n);
        $how = array();
        if ($c === 'staff') {
            $by_key = array();
            foreach ($sub as $i => $it) {
                $key = $it['pkey_person'];
                if ($key === '') continue;
                if (isset($by_key[$key])) { $uf->union($by_key[$key], $i); $how[$i] = 'person key'; }
                else $by_key[$key] = $i;
            }
            $keys = array_keys($by_key);
            if (count($keys) <= 300) {
                for ($a = 0; $a < count($keys); $a++) for ($b = $a + 1; $b < count($keys); $b++) {
                    $x = $sub[$by_key[$keys[$a]]];
                    $y = $sub[$by_key[$keys[$b]]];
                    if ($uf->find($by_key[$keys[$a]]) !== $uf->find($by_key[$keys[$b]]) && kop_dd_same_person($x['person'], $y['person'])) {
                        $uf->union($by_key[$keys[$a]], $by_key[$keys[$b]]);
                        $how[$by_key[$keys[$b]]] = 'kop_dd_same_person';
                    }
                }
            }
        } elseif ($c === 'link' || $c === 'fact') {
            $seen = array();
            foreach ($sub as $i => $it) {
                $key = $it['fkey'];
                if ($key === '') continue;
                if (isset($seen[$key])) { $uf->union($seen[$key], $i); $how[$i] = $c === 'link' ? 'same url' : 'fact key'; }
                else $seen[$key] = $i;
            }
        } elseif ($c === 'incident' || $c === 'notes') {
            foreach (candidate_pairs($sub) as $pr) {
                list($i, $j) = $pr;
                if ($uf->find($i) === $uf->find($j)) continue;
                $why = rule_same($sub[$i], $sub[$j]);
                if ($why !== '') { $uf->union($i, $j); $how[$j] = $why === 'event' ? 'kop_dd_same_event' : 'kop_wbf_text_same'; }
            }
        }
        // Components.
        $comps = array();
        foreach ($sub as $i => $it) $comps[$uf->find($i)][] = $i;
        $reps = array();
        foreach ($comps as $root => $members) {
            // Keep: the Woodbury item with the longest wording, else the longest wording overall.
            usort($members, function ($a, $b) use ($sub) {
                $wa = $sub[$a]['src'] === 'woodbury' ? 1 : 0;
                $wb = $sub[$b]['src'] === 'woodbury' ? 1 : 0;
                return $wb <=> $wa ?: strlen($sub[$b]['core'] ?: $sub[$b]['text']) <=> strlen($sub[$a]['core'] ?: $sub[$a]['text']) ?: $a <=> $b;
            });
            $keep = $members[0];
            $reps[] = $keep;
            if (count($members) > 1) {
                $srcs = array();
                foreach ($members as $m) $srcs[$sub[$m]['src']] = true;
                $diff_role = 0;
                if ($c === 'staff') {
                    $roles = array();
                    foreach ($members as $m) $roles[strtolower(trim($sub[$m]['role']))] = true;
                    $diff_role = count($roles) > 1 ? 1 : 0;
                }
                $g = array('fid' => $label_fid, 'name' => $label_name, 'class' => $c, 'size' => count($members), 'queues' => implode('+', array_keys($srcs)),
                    'diff_role' => $diff_role, 'keep' => $sub[$keep]['text'], 'members' => array());
                foreach ($members as $m) {
                    $g['members'][] = array('src' => $sub[$m]['src'], 'kind' => $sub[$m]['kind'], 'id' => $sub[$m]['id'], 'text' => $sub[$m]['text'], 'how' => $how[$m] ?? '');
                    if ($m !== $keep) {
                        $kind = $sub[$m]['kind'] . ($c === 'staff' ? ($diff_role ? ' (other role)' : ' (same role)') : '');
                        bump('c_fold', $sub[$m]['src'], $kind);
                        ex('c_fold', $sub[$m]['src'], $kind, array('fid' => $label_fid, 'name' => $label_name, 'wait' => cut($sub[$m]['text']), 'match' => cut($sub[$keep]['text']),
                            'id' => $sub[$m]['id'], 'how' => $how[$m] ?? ($how[$keep] ?? '')));
                    }
                }
                if (count($srcs) > 1) bump('c_cross_queue_groups', 'all', $c);
                bump('c_groups', 'all', $c);
                $comp_stats[$c][] = count($members);
                $largest[] = array('size' => count($members), 'fid' => $label_fid, 'name' => $label_name, 'class' => $c, 'keep' => cut($sub[$keep]['text'], 120));
                if ($ONLY) $GROUPS[] = $g;
            }
        }
        // Reps left after folding.
        foreach ($reps as $k) $reps_by_class[$c][] = $sub[$k];
    }

    // Step d: close pairs among the representatives, and between a representative and the lines already on hand.
    foreach (array('incident', 'notes') as $c) {
        $reps = $reps_by_class[$c] ?? array();
        if (!$reps) continue;
        $oh = array();
        foreach ($onhand as $o) if ($o['cls'] === $c) $oh[] = $o;
        $all = array_merge($reps, $oh);
        $nrep = count($reps);
        $uf = new UF(count($all) + 1);
        $sentinel = count($all);
        $in_pair = array();
        $pairs_found = array();
        foreach (candidate_pairs($all) as $pr) {
            list($i, $j) = $pr;
            if ($i >= $nrep && $j >= $nrep) continue;                    // two lines already on hand
            if (!close_pair($all[$i], $all[$j])) continue;
            if (rule_same($all[$i], $all[$j]) !== '') continue;            // the rules had it (cannot happen between reps)
            if ($j >= $nrep || $i >= $nrep) { $rep = min($i, $j); $other = max($i, $j); } else { $rep = $i; $other = $j; }
            $in_pair[$rep] = true;
            if ($other >= $nrep) { $uf->union($rep, $sentinel); } else { $in_pair[$other] = true; $uf->union($rep, $other); }
            $pairs_found[] = array($rep, $other);
        }
        if (!$pairs_found) continue;
        $lines = array_keys($in_pair);
        $hand_hit = array();
        foreach ($pairs_found as $pr) if ($pr[1] >= $nrep) $hand_hit[$pr[0]] = $pr[1];
        // What the AI call would carry: the record's on-hand lines for this class + the waiting lines in a pair.
        $chars = PROMPT_OVERHEAD_CHARS;
        foreach ($oh as $o) $chars += strlen($o['text']) + 6;
        foreach ($lines as $i) $chars += strlen($all[$i]['text']) + 6;
        $chars_trim = PROMPT_OVERHEAD_CHARS;
        $needed = array();
        foreach ($pairs_found as $pr) if ($pr[1] >= $nrep) $needed[$pr[1]] = true;
        foreach ($needed as $i => $_) $chars_trim += strlen($all[$i]['text']) + 6;
        foreach ($lines as $i) $chars_trim += strlen($all[$i]['text']) + 6;
        $rec = $label_fid > 0 ? 'f' . $label_fid : 'p:' . $label_name;
        $AI[$rec . '|' . $c] = array('fid' => $label_fid, 'name' => $label_name, 'class' => $c, 'lines' => count($lines), 'onhand' => count($oh),
            'chars' => $chars, 'chars_trim' => $chars_trim, 'pairs' => count($pairs_found));
        foreach ($lines as $i) {
            bump('d_ai_lines', $all[$i]['src'], $all[$i]['kind']);
        }
        // Upper bound: components with the on-hand sentinel clear every rep; other components keep one.
        $comps = array();
        foreach ($lines as $i) $comps[$uf->find($i)][] = $i;
        $sroot = $uf->find($sentinel);
        foreach ($comps as $root => $members) {
            if ($root === $sroot) {
                foreach ($members as $i) { bump('d_upper_clear', $all[$i]['src'], $all[$i]['kind']); $upper[$all[$i]['src']]++; }
            } elseif (count($members) > 1) {
                // keep one (the longest); the rest would fold
                usort($members, function ($a, $b) use ($all) { return strlen($all[$b]['text']) <=> strlen($all[$a]['text']); });
                for ($k = 1; $k < count($members); $k++) { bump('d_upper_clear', $all[$members[$k]]['src'], $all[$members[$k]]['kind']); $upper[$all[$members[$k]]['src']]++; }
            }
        }
        foreach ($pairs_found as $pr) {
            list($i, $j) = $pr;
            ex('d_ai', $all[$i]['src'], $all[$i]['kind'], array('fid' => $label_fid, 'name' => $label_name, 'wait' => cut($all[$i]['text']),
                'match' => cut($all[$j]['text']) . ($j >= $nrep ? '   [on hand]' : '   [also waiting]'), 'id' => $all[$i]['id'], 'how' => ''));
        }
        if ($ONLY) {
            $GROUPS[] = array('fid' => $label_fid, 'name' => $label_name, 'class' => $c . ' AI pairs', 'size' => count($pairs_found), 'queues' => '', 'diff_role' => 0, 'keep' => '',
                'members' => array_map(function ($pr) use ($all, $nrep) {
                    return array('src' => $all[$pr[0]]['src'], 'kind' => 'pair', 'id' => $all[$pr[0]]['id'], 'text' => $all[$pr[0]]['text'] . "\n        ~ " . $all[$pr[1]]['text'] . ($pr[1] >= $nrep ? '  [on hand]' : ''), 'how' => '');
                }, $pairs_found));
        }
    }

    // Left waiting after b+c.
    global $left;
    foreach ($reps_by_class as $c => $reps) foreach ($reps as $r) $left[$r['src']][$r['kind']] = ($left[$r['src']][$r['kind']] ?? 0) + 1;
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
    $lines_doc = doc_lines($doc, array('criticalIncidents.customIncidents', 'notes'));
    $lines_docH = doc_lines($docH, array('criticalIncidents.customIncidents', 'notes'));
    $mk_line_items = function (array $lines, $src) use ($fid, $name) {
        $o = array();
        foreach ($lines as $l) {
            $inc = $l['path'] === 'criticalIncidents.customIncidents';
            $o[] = make_item($src, 'record', '', $fid, $name, $l['text'], $inc, array('cls' => $inc ? 'incident' : 'notes'));
        }
        return $o;
    };
    $onhand_items = $mk_line_items($lines_docH, 'record');
    $onhand_doc_items = $mk_line_items($lines_doc, 'record');
    $match_line = function (array $it, array $pool) {
        foreach ($pool as $o) {
            if ($o['cls'] !== $it['cls']) continue;
            if (rule_same($it, $o) !== '') return $o;
        }
        return null;
    };

    $items = array();   // waiting items still alive after baseline + b

    /* ---- Woodbury ---- */
    foreach ($P as $r) {
        $k = $r['path'];
        if (!in_array($r['op'], $ops_ok, true)) { bump('other_ops', 'woodbury', $r['op']); continue; }
        if ((string) $r['reviewed_by'] !== '' || strpos((string) $r['extra'], '"edited_by"') !== false) { $SKIP['left_alone']++; bump('left_alone', 'woodbury', $k); continue; }
        $why = kop_wbf_on_record($doc, $r);
        if ($why !== '') {
            if (kop_wbf_conflict_parts($doc, $r)) { bump('b0_conflict', 'woodbury', $k); }
            else { bump('b0_baseline', 'woodbury', $k); ex('b0_baseline', 'woodbury', $k, array('fid' => $fid, 'name' => $name, 'wait' => cut(row_text($r) ?: $r['label']), 'match' => cut($why), 'id' => $r['pkey'], 'how' => '')); continue; }
        }
        $why = kop_wbf_on_record($docH, $r);
        if ($why !== '') {
            if (kop_wbf_conflict_parts($docH, $r)) { bump('b1_conflict', 'woodbury', $k); }
            else { bump('b1_held_covered', 'woodbury', $k); ex('b1_held_covered', 'woodbury', $k, array('fid' => $fid, 'name' => $name, 'wait' => cut(row_text($r) ?: $r['label']), 'match' => cut($why), 'id' => $r['pkey'], 'how' => '')); continue; }
        }
        // Waiting item alive: build the comparable item.
        $cls = path_class($k);
        $v = kop_wbf_row_value($r);
        if ($r['op'] === 'add_staff') {
            $pn = (string) ($v['name'] ?? '');
            $items[] = make_item('woodbury', $k, $r['pkey'], $fid, $name, $pn . ' - ' . ($v['role'] ?? ''), false,
                array('cls' => 'staff', 'person' => $pn, 'pkey_person' => kop_wbf_person_key($pn), 'role' => (string) ($v['role'] ?? '')));
        } elseif ($r['op'] === 'add_list' && $cls !== 'other') {
            $items[] = make_item('woodbury', $k, $r['pkey'], $fid, $name, row_text($r), $cls === 'incident', array('cls' => $cls));
        } else {
            $fk = kop_wbf_fact_key($r);
            if ($r['op'] === 'add_list' && $k === 'identification.pastNames') $fk = $fid . '|name|' . kop_dd_name_key(row_text($r));
            $items[] = make_item('woodbury', $k, $r['pkey'], $fid, $name, row_text($r) ?: $r['label'], false, array('cls' => 'fact', 'fkey' => $fk));
        }
    }

    /* ---- Fornits ---- */
    foreach ($FR as $r) {
        $kind = $r['kind'];
        $v = json_decode((string) $r['value'], true) ?: array();
        if ($kind === 'link') {
            $url = (string) ($v['url'] ?? '');
            $keys = kop_normalize_urls($url);
            $key = $keys ? $keys[0] : '';
            $have = array();
            foreach ((array) ($doc['resourceLinks'] ?? array()) as $l) if (is_array($l)) foreach (kop_normalize_urls($l['url'] ?? '') as $u) $have[$u] = true;
            if ($key !== '' && isset($have[$key])) { bump('b0_baseline', 'fornits', 'link'); ex('b0_baseline', 'fornits', 'link', array('fid' => $fid, 'name' => $name, 'wait' => cut($url), 'match' => 'in Materials and links', 'id' => $r['pkey'], 'how' => '')); continue; }
            if ($key !== '' && isset($news_urls[$fid][$key])) { bump('b1_held_covered', 'fornits', 'link (news link)'); ex('b1_held_covered', 'fornits', 'link (news link)', array('fid' => $fid, 'name' => $name, 'wait' => cut($url), 'match' => 'an article linked to this record', 'id' => $r['pkey'], 'how' => '')); continue; }
            $items[] = make_item('fornits', 'link', $r['pkey'], $fid, $name, $url, false, array('cls' => 'link', 'fkey' => $key));
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
            $items[] = make_item('fornits', 'staff', $r['pkey'], $fid, $name, $pn . ' - ' . $role, false,
                array('cls' => 'staff', 'person' => $pn, 'pkey_person' => $key, 'role' => $role));
        } elseif ($kind === 'incident') {
            $year = (string) ($v['year'] ?? '');
            $labels = array('death' => 'Death', 'abuse' => 'Abuse', 'sexual_abuse' => 'Sexual abuse', 'restraint' => 'Restraint', 'seclusion' => 'Seclusion',
                'neglect' => 'Neglect', 'runaway' => 'Runaway', 'injury' => 'Injury', 'lawsuit' => 'Lawsuit', 'investigation' => 'Investigation', 'other' => 'Incident');
            $when = $year !== '' ? $year : 'Reported ' . substr((string) $r['post_date'], 0, 4);
            $line = $when . ': ' . ($labels[$v['category'] ?? ''] ?? 'Incident') . ': ' . rtrim((string) ($v['summary'] ?? ''), '.') . '.';
            $row = array('op' => 'add_list', 'path' => 'criticalIncidents.customIncidents', 'label' => 'Incident', 'value' => wp_json_encode($line), 'extra' => '', 'evidence' => '[]', 'facility_id' => $fid, 'conflict' => '', 'current_val' => '');
            $it = make_item('fornits', 'incident', $r['pkey'], $fid, $name, $line, true, array('cls' => 'incident'));
            $hit = $match_line($it, $onhand_doc_items);
            if ($hit) { bump('b0_baseline', 'fornits', 'incident (rules, not the live check)'); ex('b0_baseline', 'fornits', 'incident (rules, not the live check)', array('fid' => $fid, 'name' => $name, 'wait' => cut($line), 'match' => cut($hit['text']), 'id' => $r['pkey'], 'how' => '')); continue; }
            $hit = $match_line($it, $onhand_items);
            if ($hit) { bump('b1_held_covered', 'fornits', 'incident', 1); ex('b1_held_covered', 'fornits', 'incident', array('fid' => $fid, 'name' => $name, 'wait' => cut($line), 'match' => cut($hit['text']), 'id' => $r['pkey'], 'how' => '')); continue; }
            $items[] = $it;
        } elseif ($kind === 'lead') {
            $line = 'Fornits lead (' . ($v['type'] ?? '') . '): ' . rtrim((string) ($v['summary'] ?? ''), '.') . '.';
            $it = make_item('fornits', 'lead', $r['pkey'], $fid, $name, $line, false, array('cls' => 'notes'));
            $hit = $match_line($it, $onhand_items);
            if ($hit) { bump('b1_held_covered', 'fornits', 'lead', 1); ex('b1_held_covered', 'fornits', 'lead', array('fid' => $fid, 'name' => $name, 'wait' => cut($line), 'match' => cut($hit['text']), 'id' => $r['pkey'], 'how' => '')); continue; }
            $items[] = $it;
        } elseif ($kind === 'testimony') {
            $id = 'fornits-' . (int) $r['topic_id'] . '-' . (int) $r['post_n'];
            $has = false;
            foreach ((array) ($doc['survivorTestimony'] ?? array()) as $t) if (is_array($t) && ($t['id'] ?? '') === $id) $has = true;
            if ($has) { bump('b0_baseline', 'fornits', 'testimony'); continue; }
            bump('not_measured', 'fornits', 'testimony');
            $left['fornits']['testimony'] = ($left['fornits']['testimony'] ?? 0) + 1;
        }
    }

    /* ---- News facts (record known) ---- */
    foreach ($NR as $r) {
        $cat = (string) $r['category'];
        $date = (string) $r['event_date'];
        $prefix = preg_match('/^(?:19|20)\d\d(?:-\d\d){0,2}$/', $date) ? $date . ': ' : '';
        $line = $prefix . ucfirst(str_replace('_', ' ', $cat)) . ': ' . rtrim((string) $r['summary'], '.') . '.';
        $inc = in_array($cat, INCIDENT_NEWS, true);
        $it = make_item('news', $cat, 'n' . $r['id'], $fid, $name, $line, $inc, array('cls' => $inc ? 'incident' : 'notes'));
        $hit = $match_line($it, $onhand_doc_items);
        if ($hit) { bump('b0_baseline', 'news', $cat); ex('b0_baseline', 'news', $cat, array('fid' => $fid, 'name' => $name, 'wait' => cut($line), 'match' => cut($hit['text']), 'id' => 'n' . $r['id'], 'how' => '')); continue; }
        $hit = $match_line($it, $onhand_items);
        if ($hit) { bump('b1_held_covered', 'news', $cat); ex('b1_held_covered', 'news', $cat, array('fid' => $fid, 'name' => $name, 'wait' => cut($line), 'match' => cut($hit['text']), 'id' => 'n' . $r['id'], 'how' => '')); continue; }
        $items[] = $it;
    }

    if ($items) fold_and_candidates($fid, $name, $items, $onhand_items, array(), true);
    if ((++$done % 250) === 0) fwrite(STDERR, sprintf("  %d/%d records (%.0fs)\n", $done, count($fids), microtime(true) - $t0));
}

/* ---- Woodbury items with no record: grouped by program key ---- */
if (!$ONLY) {
    foreach ($noreq as $pk => $rows) {
        $items = array();
        $pname = (string) $rows[0]['program'];
        foreach ($rows as $r) {
            if (!in_array($r['op'], $ops_ok, true)) { bump('other_ops', 'woodbury', $r['op']); continue; }
            $k = $r['path'];
            $cls = path_class($k);
            $v = kop_wbf_row_value($r);
            if ($r['op'] === 'add_staff') {
                $pn = (string) ($v['name'] ?? '');
                $items[] = make_item('woodbury', $k . ' (no record)', $r['pkey'], 0, $pname, $pn . ' - ' . ($v['role'] ?? ''), false,
                    array('cls' => 'staff', 'person' => $pn, 'pkey_person' => kop_wbf_person_key($pn), 'role' => (string) ($v['role'] ?? '')));
            } elseif ($r['op'] === 'add_list' && $cls !== 'other') {
                $items[] = make_item('woodbury', $k . ' (no record)', $r['pkey'], 0, $pname, row_text($r), $cls === 'incident', array('cls' => $cls));
            } else {
                $what = $r['op'] . '|' . $k . '|' . strtolower(trim(is_array($v) ? wp_json_encode($v) : (string) $v));
                $items[] = make_item('woodbury', $k . ' (no record)', $r['pkey'], 0, $pname, row_text($r) ?: $r['label'], false, array('cls' => 'fact', 'fkey' => $pk . '|' . $what));
            }
        }
        if ($items) fold_and_candidates(0, $pname, $items, array(), array(), false);
    }
}

/* ------------------------------------------------------------------------------------------------ report */

$run = microtime(true) - $t0;
$sumq = function ($bucket, $queue = null) use ($S) {
    $n = 0;
    foreach ($S[$bucket] ?? array() as $q => $kinds) if ($queue === null || $q === $queue) $n += array_sum($kinds);
    return $n;
};
$out = array('db' => $db_path, 'generated' => gmdate('c'), 'runtime_s' => round($run, 1), 'constants' => array('D_SHARED' => D_SHARED, 'D_JACCARD' => D_JACCARD), 'summary' => array(), 'counts' => $S,
    'skipped' => $SKIP, 'text_matches_rejected_by_number_or_date_check' => $TIGHT);

echo "\n=== Fact dedupe measurement (read only) ===  db={$db_path}  runtime " . round($run, 1) . "s" . ($ONLY ? "  facility=$ONLY" : '') . "\n";
echo "Waiting now: woodbury {$tot['woodbury']} (held, not on record yet: {$tot['woodbury_held']}), fornits {$tot['fornits']}, news facts {$tot['news']}\n";
echo "Skipped: " . json_encode($SKIP) . "   (left_alone = edited/sent back; news_no_record = no facility_id so not grouped)\n";
echo "Name check: $NAMEDIFF joined text pairs have a capitalized word each side lacks (kept joined; see the sample below).
";
echo "Stricter than the library: " . $TIGHT . " pairs kop_wbf_text_same() called the same were NOT joined because their numbers or dates differ.\n\n";

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
$print_bucket('0. Baseline: already on the record (what the hourly clear takes; Fornits by its own on-file rules)', 'b0_baseline');
$print_bucket('0b. On the record but a CONFLICT (stays waiting)', 'b0_conflict');
$print_bucket('b. Covered by HELD items / news links (new vs baseline)', 'b1_held_covered');
$print_bucket('b. ...would be covered but conflicts (stays, Conflicts tab)', 'b1_conflict');
$print_bucket('b. info: Fornits staff already listed under another role/name (not cleared)', 'b1_person_listed_other_role');
$print_bucket('c. FOLD among waiting items (items that go away, group size - 1)', 'c_fold');
echo "--- c. groups ---\n";
foreach ($S['c_groups']['all'] ?? array() as $c => $n) {
    $sizes = $comp_stats[$c] ?? array();
    rsort($sizes);
    echo sprintf("  %-9s groups %5d   cross-queue groups %5d   largest %s\n", $c, $n, $S['c_cross_queue_groups']['all'][$c] ?? 0, implode(',', array_slice($sizes, 0, 8)));
}
usort($largest, function ($a, $b) { return $b['size'] <=> $a['size']; });
echo "  largest groups:\n";
foreach (array_slice($largest, 0, 10) as $g) echo sprintf("    %4d  #%d %s [%s]  keep: %s\n", $g['size'], $g['fid'], cut($g['name'], 40), $g['class'], $g['keep']);
echo "\n";
$print_bucket('d. AI candidate lines (waiting lines that are in a close pair)', 'd_ai_lines');
$print_bucket('d. upper bound: would clear/fold if every AI candidate were a match', 'd_upper_clear');
$print_bucket('not measured (no rule for this kind)', 'not_measured');
$print_bucket('Woodbury items with an op outside the four known', 'other_ops');
$print_bucket('Edited / sent back (left alone by every step)', 'left_alone');

// AI estimate.
$ai_records = array();
$chars = $chars_trim = $ai_calls = 0;
foreach ($AI as $a) {
    $ai_records[($a['fid'] > 0 ? 'f' . $a['fid'] : 'p:' . $a['name'])] = true;
    $chars += $a['chars'];
    $chars_trim += $a['chars_trim'];
    $ai_calls++;
}
$ai_lines = $sumq('d_ai_lines');
echo "--- d. AI estimate (one call per record and line class; tokens = chars/4) ---\n";
echo sprintf("  records %d (with a record: %d; program names without one: %d), calls %d, waiting lines %d\n", count($ai_records),
    count(array_filter(array_keys($ai_records), function ($k) { return $k[0] === 'f'; })), count(array_filter(array_keys($ai_records), function ($k) { return $k[0] === 'p'; })), $ai_calls, $ai_lines);
echo sprintf("  prompt tokens, full (every on-hand incident/note line + waiting lines): ~%s;  trimmed (only on-hand lines that are in a pair): ~%s\n",
    number_format((int) ($chars * TOKENS_PER_CHAR)), number_format((int) ($chars_trim * TOKENS_PER_CHAR)));
$big = $AI;
uasort($big, function ($a, $b) { return $b['chars'] <=> $a['chars']; });
echo "  biggest single calls: ";
foreach (array_slice($big, 0, 5) as $a) echo sprintf("#%d %s %s ~%dk tok (%d waiting, %d on hand);  ", $a['fid'], cut($a['name'], 25), $a['class'], (int) ($a['chars'] * TOKENS_PER_CHAR / 1000), $a['lines'], $a['onhand']);
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
echo "  (news facts with no facility_id are counted in 'now' and never cleared here; Fornits leads/testimony/other kinds with no rule stay in 'after'.)\n";
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
                echo sprintf("  #%d %s%s\n     waiting: %s\n     matches: %s\n", $x['fid'], cut($x['name'], 50), $x['how'] ? '  [' . $x['how'] . ']' : '', $x['wait'], $x['match']);
            }
        }
        echo "\n";
    }
}
$out['examples'] = $exout;
$out['name_diff_joins'] = array('count' => $NAMEDIFF, 'sample' => $NAMEDIFF_EX);
if ($N_EX > 0 && $NAMEDIFF_EX) {
    echo "=== SAMPLE: joined by text but each side names something the other does not (possible false matches) ===
";
    foreach ($pick($NAMEDIFF_EX, $N_EX) as $x) echo sprintf("  #%d  only in 1st: %s | only in 2nd: %s
     1: %s
     2: %s
", $x['fid'], $x['only_a'], $x['only_b'], cut($x['a'], 200), cut($x['b'], 200));
    echo "
";
}

if ($ONLY) {
    echo "=== Groups on facility #$ONLY ===\n";
    foreach ($GROUPS as $g) {
        echo "\n[{$g['class']}] size {$g['size']}" . ($g['queues'] ? " queues {$g['queues']}" : '') . ($g['diff_role'] ? ' (roles differ)' : '') . "\n";
        foreach ($g['members'] as $m) echo sprintf("   %-8s %-34s %s%s\n", $m['src'], cut($m['kind'], 34), cut($m['text'], 260), $m['how'] ? '   <' . $m['how'] . '>' : '');
    }
    $out['groups'] = $GROUPS;
}
if (!empty($opt['json']) && $opt['json'] !== true) {
    file_put_contents($opt['json'], json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    echo "JSON written to {$opt['json']}\n";
}
echo "Runtime " . round(microtime(true) - $t0, 1) . "s, peak memory " . round(memory_get_peak_usage(true) / 1048576) . " MB\n";
