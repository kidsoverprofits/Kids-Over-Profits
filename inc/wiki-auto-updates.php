<?php
/**
 * Wiki updates made from record changes (docs/PLAN.md 3.12 step 6, DATA-FLOWS.md section 4 step 4).
 *
 * Every time a record changes, the r/troubledteens wiki entry linked to it gets a draft of what to add:
 *
 *   - kop_record_changed() (inc/record-events.php) fires 'kop_record_changed'; kop_wiki_auto_on_change() only
 *     notes the ids (facility, news, lawsuit, operator, wiki) in option kop_wiki_auto_queue. Nothing is read inline.
 *   - An hourly job (kop_wiki_auto_cron) turns them into the linked entries (wiki_submissions.facility_unique_name,
 *     Review inbox > Wiki links), re-runs kop_wiki_upd_gaps() for each and writes every new gap as the line the
 *     script drafts write (scripts/wiki-script-drafts.py in kop-pipeline: staff, names, operator, closure + header
 *     years, incidents, lawsuits, deaths, approved serious findings, news under In the Media, handbook rules), placed
 *     as the wiki editor's template places them. It also walks every linked entry in turn (kop_wiki_auto_sweep), so a
 *     write that skips the event (the memorial, an approved finding) still reaches its entry within a day or two.
 *   - "New" means not known yet: the first time the job sees an entry, the gaps it has then are its baseline (option
 *     kop_wiki_auto_known, sha1 keys per entry; the exported drafts in update-drafts.json already cover those). A
 *     decision adds the draft's keys (Approve, Set aside; a line emptied before approving is decided too), and its
 *     Undo takes them off again, so nothing is drafted twice and nothing decided comes back.
 *   - The draft waits at Review inbox > Wiki updates from record changes (inc/review-inbox/wiki-changes.php), in the
 *     same shape as an exported draft (ops applied by kop_wiki_drafts_build_draft()); Approve writes KOP's copy of the
 *     entry with exact Undo, then Ready for Reddit, On Reddit. A gap that leaves the record before it is reviewed
 *     (an article unlinked) leaves the draft too; one that arrives while it waits joins it.
 *
 * Options (none autoloaded): kop_wiki_auto_queue, kop_wiki_auto_known, kop_wiki_auto_drafts, kop_wiki_auto_state,
 * kop_wiki_auto_edits, kop_wiki_auto_old_<id>, kop_wiki_auto_sweep, kop_wiki_auto_last.
 * Test: scripts/test-wiki-auto-updates.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/wiki-update-drafts.php';

/** Record kinds whose change can change what a wiki entry is missing. */
function kop_wiki_auto_kinds() {
    return array('facility', 'news', 'lawsuit', 'operator', 'wiki');
}

/* ---- The listener ---------------------------------------------------------- */

if (function_exists('add_action')) add_action('kop_record_changed', 'kop_wiki_auto_on_change', 20, 3);

function kop_wiki_auto_on_change($kind, $ids = array(), $info = array()) {
    if (!in_array($kind, kop_wiki_auto_kinds(), true) || !$ids) return;
    $q = kop_wiki_auto_queue();
    foreach ((array) $ids as $id) {
        if ((int) $id > 0) $q[$kind][(int) $id] = time();
    }
    // A seed or a bulk import can touch thousands of rows: past the cap the sweep reaches them anyway.
    foreach ($q as $k => $list) if (count($list) > 5000) $q[$k] = array_slice($list, -5000, null, true);
    update_option('kop_wiki_auto_queue', $q, false);
}

function kop_wiki_auto_queue() {
    $q = get_option('kop_wiki_auto_queue', array());
    return is_array($q) ? $q : array();
}

/* ---- The hourly job ---------------------------------------------------------- */

if (function_exists('add_action')) {
    add_action('init', function () {
        if (!wp_next_scheduled('kop_wiki_auto_hourly')) wp_schedule_event(time() + 600, 'hourly', 'kop_wiki_auto_hourly');
    });
    add_action('kop_wiki_auto_hourly', 'kop_wiki_auto_cron');
    add_action('kop_wiki_auto_more', 'kop_wiki_auto_cron');
}

function kop_wiki_auto_cron() {
    if (get_transient('kop_wiki_auto_lock')) return;
    set_transient('kop_wiki_auto_lock', 1, 10 * MINUTE_IN_SECONDS);
    try {
        $res = kop_wiki_auto_run(kop_wiki_upd_pdo(), 45);
        update_option('kop_wiki_auto_last', $res + array('at' => gmdate('c')), false);
        // Baselines still to take (the first runs after deploy) or a long queue: go on in two minutes, not an hour,
        // so a change made meanwhile is not taken into a baseline.
        if (($res['left'] ?? 0) > 0 && function_exists('wp_schedule_single_event') && !wp_next_scheduled('kop_wiki_auto_more')) {
            wp_schedule_single_event(time() + 120, 'kop_wiki_auto_more');
        }
    } catch (Throwable $e) {
        update_option('kop_wiki_auto_last', array('at' => gmdate('c'), 'error' => $e->getMessage()), false);
        error_log('kop_wiki_auto_cron: ' . $e->getMessage());
    }
    delete_transient('kop_wiki_auto_lock');
}

/** Entries linked to facility records: entry id => facility id. */
function kop_wiki_auto_linked(array $entries, PDO $pdo) {
    $by_un = array();
    foreach ($pdo->query("SELECT id, unique_name FROM facilities_v2") as $r) {
        if ((string) $r['unique_name'] !== '' && !isset($by_un[$r['unique_name']])) $by_un[$r['unique_name']] = (int) $r['id'];
    }
    $out = array();
    foreach ($entries as $id => $e) {
        if (!in_array($e['kind'], array('program', 'other'), true)) continue;
        $un = trim((string) $e['facility_unique_name']);
        if ($un !== '' && isset($by_un[$un])) $out[(int) $id] = $by_un[$un];
    }
    return $out;
}

/** The queued ids -> the entries they reach. */
function kop_wiki_auto_queued_entries(array $q, array $entries, array $linked, PDO $pdo) {
    $fids = array_map('intval', array_keys($q['facility'] ?? array()));
    $joins = array('news' => array('news_facility_links', 'news_id'), 'lawsuit' => array('lawsuit_facility_links', 'lawsuit_id'));
    foreach ($joins as $kind => $j) {
        $ids = array_map('intval', array_keys($q[$kind] ?? array()));
        foreach (array_chunk($ids, 500) as $chunk) {
            try {
                foreach ($pdo->query("SELECT DISTINCT facility_id FROM {$j[0]} WHERE {$j[1]} IN (" . implode(',', $chunk) . ')') as $r) $fids[] = (int) $r['facility_id'];
            } catch (Throwable $e) {
                // No link table here: the sweep reaches those entries.
            }
        }
    }
    $ops = array_map('intval', array_keys($q['operator'] ?? array()));
    if ($ops) {
        global $wpdb;
        $table = isset($wpdb) ? $wpdb->prefix . 'kop_operator_facilities' : '';
        foreach (array_chunk($ops, 500) as $chunk) {
            try {
                if ($table !== '') foreach ((array) $wpdb->get_col("SELECT DISTINCT facility_id FROM $table WHERE operator_id IN (" . implode(',', $chunk) . ')') as $f) $fids[] = (int) $f;
            } catch (Throwable $e) {
            }
        }
    }
    $fids = array_flip($fids);
    $out = array();
    foreach ($linked as $eid => $fid) if (isset($fids[$fid])) $out[$eid] = true;
    // A wiki row: its entry (the newest row of its page).
    $wids = $q['wiki'] ?? array();
    if ($wids) {
        foreach ($entries as $eid => $e) {
            if (!isset($linked[$eid])) continue;
            if (isset($wids[$eid])) $out[$eid] = true;
            foreach ((array) $e['older'] as $o) if (isset($wids[$o])) $out[$eid] = true;
        }
    }
    return array_keys($out);
}

/**
 * One run: the queued entries first, then the sweep, within $seconds.
 * -> {queued, swept, baselines, drafts, left (entries still waiting for this job)}.
 */
function kop_wiki_auto_run(PDO $pdo, $seconds = 45, $sweep_max = 30) {
    $t0 = microtime(true);
    $entries = kop_wiki_upd_entries($pdo);
    $linked = kop_wiki_auto_linked($entries, $pdo);
    $q = kop_wiki_auto_queue();
    update_option('kop_wiki_auto_queue', array(), false);
    $todo = kop_wiki_auto_queued_entries($q, $entries, $linked, $pdo);
    $known = kop_wiki_auto_known_all();
    // Entries with no baseline yet come right after the queue: until they have one, a change to them is not seen.
    $unseen = array_values(array_diff(array_keys($linked), array_keys($known), $todo));
    $res = array('queued' => count($todo), 'swept' => 0, 'baselines' => 0, 'drafts' => 0, 'left' => 0);
    $done = array();
    foreach (array_merge($todo, $unseen) as $eid) {
        if (microtime(true) - $t0 > $seconds) break;
        $r = kop_wiki_auto_refresh_safe($eid, $entries[$eid], $linked[$eid], $pdo, $res);
        $done[$eid] = true;
        if ($r === 'baseline') $res['baselines']++;
        if ($r === 'draft') $res['drafts']++;
    }
    // Queued entries not reached go back on the queue.
    $back = array();
    foreach ($todo as $eid) if (!isset($done[$eid])) $back[$eid] = time();
    if ($back) {
        $q2 = kop_wiki_auto_queue();
        $q2['wiki'] = ($q2['wiki'] ?? array()) + $back;
        update_option('kop_wiki_auto_queue', $q2, false);
    }
    // The sweep: every linked entry in turn, from where the last run stopped.
    $ids = array_keys($linked);
    sort($ids);
    $cursor = (int) get_option('kop_wiki_auto_sweep', 0);
    $n = 0;
    foreach (array_merge(array_filter($ids, function ($i) use ($cursor) { return $i > $cursor; }), array_filter($ids, function ($i) use ($cursor) { return $i <= $cursor; })) as $eid) {
        if ($n >= $sweep_max || microtime(true) - $t0 > $seconds) break;
        $cursor = $eid;
        if (isset($done[$eid])) continue;
        $r = kop_wiki_auto_refresh_safe($eid, $entries[$eid], $linked[$eid], $pdo, $res);
        $done[$eid] = true;
        $n++;
        if ($r === 'draft') $res['drafts']++;
        if ($r === 'baseline') $res['baselines']++;
    }
    update_option('kop_wiki_auto_sweep', $cursor, false);
    $res['swept'] = $n;
    $res['left'] = count($back) + count(array_diff(array_keys($linked), array_keys(kop_wiki_auto_known_all())));
    // Drafts of entries no longer linked go.
    $drafts = kop_wiki_auto_drafts();
    $gone = array_diff(array_keys($drafts), array_keys($linked));
    foreach ($gone as $eid) {
        $s = kop_wiki_auto_state()[$eid] ?? null;
        if (!$s || ($s['view'] ?? '') === 'review') kop_wiki_auto_save_draft($eid, null);
    }
    return $res;
}

/** kop_wiki_auto_refresh(), one entry's bad data never stopping the run: its error is counted and logged. */
function kop_wiki_auto_refresh_safe($eid, array $entry, $fid, PDO $pdo, array &$res) {
    try {
        return kop_wiki_auto_refresh($eid, $entry, $fid, $pdo);
    } catch (Throwable $e) {
        $res['errors'][] = $eid . ': ' . $e->getMessage();
        error_log('kop_wiki_auto_refresh ' . $eid . ': ' . $e->getMessage());
        return 'error';
    }
}

/* ---- Stores ------------------------------------------------------------------ */

function kop_wiki_auto_known_all() {
    $k = get_option('kop_wiki_auto_known', array());
    return is_array($k) ? $k : array();
}

/** The keys an entry already has (baseline + decided), or null before its first look. */
function kop_wiki_auto_known($id) {
    $all = kop_wiki_auto_known_all();
    return isset($all[(int) $id]) ? (array) $all[(int) $id] : null;
}

function kop_wiki_auto_add_known($id, array $keys) {
    $all = kop_wiki_auto_known_all();
    $have = (array) ($all[(int) $id] ?? array());
    foreach ($keys as $k) $have[$k] = 1;
    $all[(int) $id] = $have;
    update_option('kop_wiki_auto_known', $all, false);
}

function kop_wiki_auto_drop_known($id, array $keys) {
    $all = kop_wiki_auto_known_all();
    if (!isset($all[(int) $id])) return;
    foreach ($keys as $k) unset($all[(int) $id][$k]);
    update_option('kop_wiki_auto_known', $all, false);
}

function kop_wiki_auto_drafts() {
    $d = get_option('kop_wiki_auto_drafts', array());
    return is_array($d) ? $d : array();
}

function kop_wiki_auto_draft($id) {
    return kop_wiki_auto_drafts()[(int) $id] ?? null;
}

function kop_wiki_auto_save_draft($id, $draft) {
    $all = kop_wiki_auto_drafts();
    if ($draft === null) unset($all[(int) $id]);
    else $all[(int) $id] = $draft;
    ksort($all);
    update_option('kop_wiki_auto_drafts', $all, false);
}

function kop_wiki_auto_state() {
    $s = get_option('kop_wiki_auto_state', array());
    return is_array($s) ? $s : array();
}

function kop_wiki_auto_set_state($id, $value) {
    $s = kop_wiki_auto_state();
    if ($value === null) unset($s[(int) $id]);
    else $s[(int) $id] = $value;
    update_option('kop_wiki_auto_state', $s, false);
}

function kop_wiki_auto_edits($id = null) {
    $e = get_option('kop_wiki_auto_edits', array());
    $e = is_array($e) ? $e : array();
    return $id === null ? $e : (array) ($e[(int) $id] ?? array());
}

function kop_wiki_auto_save_edits($id, array $edits) {
    $all = kop_wiki_auto_edits();
    if ($edits) $all[(int) $id] = $edits;
    else unset($all[(int) $id]);
    update_option('kop_wiki_auto_edits', $all, false);
}

/* ---- One entry --------------------------------------------------------------- */

/** A gap's identity, the same however the record words it later: an article by its link, a person by name. */
function kop_wiki_auto_gap_key(array $g) {
    $kind = (string) $g['kind'];
    $d = (array) ($g['detail'] ?? array());
    switch ($kind) {
        case 'news':
            $v = kop_wiki_upd_url_key((string) $g['source_url']);
            break;
        case 'staff':
            $v = kop_wiki_upd_person_key(preg_replace('/\s*\(.*$/s', '', (string) $g['text']));
            break;
        case 'lawsuit':
            $v = trim((string) ($d['case_number'] ?? '')) !== '' ? (string) $d['case_number'] : trim(kop_wiki_upd_words($g['text']));
            break;
        case 'name':
            $v = kop_wiki_upd_key((string) ($d['name'] ?? $g['text'])) . '|' . ($d['how'] ?? '');
            break;
        case 'operator':
            $v = kop_wiki_upd_key((string) ($d['operator'] ?? $g['text']));
            break;
        case 'closure':
            $v = (string) ($d['end_year'] ?? '') . '|' . (string) ($d['status'] ?? '');
            break;
        default:
            $v = trim(kop_wiki_upd_words($g['text'])) . '|' . (string) $g['date'];
    }
    return substr(sha1(($g['conflict'] ? 'c:' : '') . $kind . '|' . $v), 0, 16);
}

/**
 * Re-reads one entry against its record. -> 'baseline' (first look: what it lacks now is its baseline), 'draft' (a
 * draft is waiting), 'none' (nothing new), 'waiting' (an approved update waits to be pasted; new gaps wait for it).
 */
function kop_wiki_auto_refresh($eid, array $entry, $fid, PDO $pdo) {
    $eid = (int) $eid;
    $page = function_exists('kop_facility_page_data') ? kop_facility_page_data((int) $fid) : null;
    if (!$page) return 'none';
    $gaps = kop_wiki_upd_gaps($entry, $page, $pdo);
    $keyed = array();
    foreach ($gaps as $g) {
        if ($g['kind'] === 'profile') continue;   // the owner's whole write-up: the drafting workflow reads it, not a line
        $keyed[kop_wiki_auto_gap_key($g)] = $g;
    }
    $known = kop_wiki_auto_known($eid);
    if ($known === null) {
        kop_wiki_auto_add_known($eid, array_keys($keyed));
        return 'baseline';
    }
    $fresh = array_diff_key($keyed, $known);
    $state = kop_wiki_auto_state()[$eid] ?? null;
    $view = $state['view'] ?? 'review';
    if ($view === 'ready') return $fresh ? 'waiting' : 'none';
    if (!$fresh) {
        if ($view === 'review') kop_wiki_auto_save_draft($eid, null);
        return 'none';
    }
    if ($view !== 'review') {
        // The last update was pasted or set aside: this is a new one.
        kop_wiki_auto_set_state($eid, null);
        delete_option('kop_wiki_auto_old_' . $eid);
        kop_wiki_auto_save_edits($eid, array());
    }
    $md = kop_wiki_upd_markdown($entry);
    $old = kop_wiki_auto_draft($eid);
    $draft = kop_wiki_auto_make_draft($entry, $page, $fresh, $md);
    $draft['since'] = $old['since'] ?? gmdate('c');
    kop_wiki_auto_save_draft($eid, $draft);
    return 'draft';
}

/** The draft for an entry's new gaps, in the shape of an exported draft (update-drafts.json). */
function kop_wiki_auto_make_draft(array $entry, array $page, array $fresh, $md) {
    $conflicts = array();
    $writable = array();
    foreach ($fresh as $k => $g) {
        if ($g['conflict']) $conflicts[] = array('text' => $g['text'], 'source_label' => (string) $g['source_label'], 'source_url' => (string) $g['source_url'], 'key' => $k);
        elseif (!empty($g['needs_source']) && !in_array($g['kind'], array('staff', 'name', 'operator'), true)) $conflicts[] = array('text' => $g['text'], 'source_label' => '', 'source_url' => '', 'key' => $k, 'unsourced' => true);
        else $writable[$k] = $g;
    }
    $ops = kop_wiki_auto_ops($entry, $page, $writable, $md);
    $what = array();
    foreach ($fresh as $g) $what[$g['kind']] = ($what[$g['kind']] ?? 0) + 1;
    return array(
        'program'   => (string) $entry['program_name'],
        'record'    => array('id' => (int) $page['id'], 'name' => (string) $page['name'], 'status' => (string) ($page['status'] ?? ''), 'url' => (string) ($page['url'] ?? '')),
        'base_sha1' => sha1(rtrim(str_replace("\r\n", "\n", (string) $md), "\n")),
        'ops'       => $ops,
        'tense'     => array(),
        'fixes'     => array(),
        'conflicts' => $conflicts,
        'keys'      => array_keys($fresh),
        'kinds'     => $what,
        'at'        => gmdate('c'),
    );
}

/** The draft as it would be approved now (kop_wiki_drafts_build_draft() over the stored draft and the reviewer's edits). */
function kop_wiki_auto_build($id, $current_md, $edits = null) {
    $d = kop_wiki_auto_draft($id);
    if (!$d) throw new RuntimeException('There is no update from record changes for this entry.');
    return kop_wiki_drafts_build_draft($d, $current_md, $edits === null ? kop_wiki_auto_edits($id) : $edits);
}

/* ---- Approve, undo ------------------------------------------------------------ */

function kop_wiki_auto_approve(PDO $pdo, $id, $by) {
    $id = (int) $id;
    $d = kop_wiki_auto_draft($id);
    if (!$d) throw new RuntimeException('That update is not here any more.');
    $row = kop_wiki_drafts_row($pdo, $id);
    $b = kop_wiki_auto_build($id, $row['markdown']);
    if ($b['problems']) throw new RuntimeException('Not approved: ' . implode('; ', $b['problems']) . '.');
    if (!$b['count']) throw new RuntimeException('Nothing is left to add: every line was left out. Set it aside instead.');
    update_option('kop_wiki_auto_old_' . $id, array('column' => $row['column'], 'text' => $row['markdown'], 'updated_at' => $row['updated_at']), false);
    $pdo->prepare('UPDATE wiki_submissions SET ' . $row['column'] . ' = ?, updated_at = ? WHERE id = ?')->execute(array($b['md'], gmdate('Y-m-d H:i:s'), $id));
    kop_wiki_auto_add_known($id, (array) $d['keys']);
    kop_wiki_auto_set_state($id, array('view' => 'ready', 'by' => (string) $by, 'at' => gmdate('c'), 'sha1' => sha1($b['md']),
        'lines' => count($b['added']), 'keys' => (array) $d['keys']));
    return $b;
}

function kop_wiki_auto_skip($id, $by) {
    $d = kop_wiki_auto_draft($id);
    if (!$d) throw new RuntimeException('That update is not here any more.');
    kop_wiki_auto_add_known($id, (array) $d['keys']);
    kop_wiki_auto_set_state($id, array('view' => 'skip', 'by' => (string) $by, 'at' => gmdate('c'), 'keys' => (array) $d['keys']));
}

/** Puts back the text the entry had (if nobody changed it since) and the draft waits on To review again. */
function kop_wiki_auto_undo(PDO $pdo, $id) {
    $id = (int) $id;
    $state = kop_wiki_auto_state()[$id] ?? null;
    if (!$state) return;
    if (($state['view'] ?? '') === 'ready' || ($state['view'] ?? '') === 'posted') {
        $old = get_option('kop_wiki_auto_old_' . $id, null);
        if (is_array($old)) {
            $row = kop_wiki_drafts_row($pdo, $id);
            if (sha1((string) $row[$old['column']]) !== (string) $state['sha1']) {
                throw new RuntimeException('The entry was edited after it was approved, so Undo would lose that edit. Change it in the wiki editor instead.');
            }
            $pdo->prepare('UPDATE wiki_submissions SET ' . $old['column'] . ' = ?, updated_at = ? WHERE id = ?')->execute(array($old['text'], $old['updated_at'], $id));
            delete_option('kop_wiki_auto_old_' . $id);
        }
    }
    kop_wiki_auto_drop_known($id, (array) ($state['keys'] ?? array()));
    kop_wiki_auto_set_state($id, null);
}

/* ---- Writing the lines (scripts/wiki-script-drafts.py) -------------------------- */

/** The program sections of the wiki editor's template, in its order (scripts/wiki-drafts.py TEMPLATE). */
function kop_wiki_auto_template() {
    return array(
        'History and Background Information' => function ($k) { return strpos($k, 'history') !== false || strpos($k, 'background') !== false; },
        'Founders and Notable Staff' => function ($k) { return strpos($k, 'staff') !== false || strpos($k, 'founder') !== false || strpos($k, 'employee') !== false; },
        'Program Structure' => function ($k) { return strpos($k, 'structure') !== false || strpos($k, 'level system') !== false || strpos($k, 'phase') !== false; },
        'Rules and Punishments' => function ($k) { return strpos($k, 'rule') !== false || strpos($k, 'punishment') !== false; },
        'Abuse/Neglect Allegations and Lawsuits' => function ($k) { return strpos($k, 'abuse') !== false || strpos($k, 'lawsuit') !== false || strpos($k, 'allegation') !== false; },
        'In the Media' => function ($k) { return in_array($k, array('in the media', 'news articles', 'media coverage', 'news'), true); },
        'Survivor Testimonies' => function ($k) { return strpos($k, 'testimon') !== false; },
        'Related Media' => function ($k) { return strpos($k, 'related media') !== false; },
    );
}

/** "The Bethesda Home for Girls**()" -> "The Bethesda Home for Girls". */
function kop_wiki_auto_clean_name($name) {
    return trim(preg_replace('/\s+/', ' ', preg_replace('/\(\s*\)|\*+/', '', (string) $name)));
}

/** [Name](its r/troubledteens wiki page) when the wiki has a page of that title (not the entry's own), else the name. */
function kop_wiki_auto_wiki_link($name, $own = '') {
    static $keys = null;
    if ($keys === null) {
        $keys = array();
        $f = get_stylesheet_directory() . '/js/data/reddit-wiki/page-urls.json';
        $j = is_readable($f) ? json_decode((string) file_get_contents($f), true) : null;
        foreach ((array) ($j['titles'] ?? array()) as $t => $u) $keys[trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($t)))] = $u;
        $wwasp = 'https://www.reddit.com/r/troubledteens/wiki/index/wwasp';
        $keys += array('wwasp' => $wwasp, 'wwasps' => $wwasp, 'world wide association of specialty programs and schools' => $wwasp);
    }
    $norm = function ($s) { return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string) $s))); };
    $k = $norm($name);
    $short = $norm(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $name));
    $abbr = preg_match('/\(([^)]+)\)\s*$/', (string) $name, $m) ? $norm($m[1]) : '';
    $url = $keys[$k] ?? $keys[preg_replace('/^the /', '', $k)] ?? $keys[$short] ?? ($abbr !== '' ? ($keys[$abbr] ?? null) : null);
    if (!$url || $k === $norm($own)) return (string) $name;
    return '[' . $name . '](' . $url . ')';
}

/** Sources stored as site paths put back where they live; a HEAL page from 2023 on goes to HEAL's own capture. */
function kop_wiki_auto_fix_url($u) {
    $u = preg_replace('#^https?://(www\.)?kidsoverprofits\.org/(r|u|user)/#', 'https://www.reddit.com/$2/', (string) $u);
    $u = preg_replace('#^https?://(www\.)?kidsoverprofits\.org/web/#', 'https://web.archive.org/web/', $u);
    if (preg_match('#^https://web\.archive\.org/web/(\d+)\w*/(https?://(www\.)?heal-online\.org\S*)#', $u, $m) && $m[1] >= '2023') {
        $u = (string) (kop_wiki_drafts_heal_archive()[kop_wiki_drafts_heal_key($m[2])] ?? $u);
    }
    return $u;
}

/** The citation a line ends with: the linked word "source" (a wiki page keeps its title), '' for KOP's own record. */
function kop_wiki_auto_cite(array $g) {
    if (!empty($g['kop_source'])) return '';
    $url = trim((string) $g['source_url']);
    if ($url !== '') {
        $url = kop_wiki_auto_fix_url($url);
        if (preg_match('#^https?://(www\.|old\.)?reddit\.com/r/troubledteens/wiki/#i', $url)) return ' ([' . ($g['source_label'] ?: 'r/troubledteens wiki') . '](' . $url . '))';
        return ' ([source](' . $url . '))';
    }
    $ct = trim((string) ($g['detail']['cite_text'] ?? ''));
    if ($ct === '') return '';
    $link = function_exists('kop_facility_pages_cite_url') ? (string) kop_facility_pages_cite_url($ct) : '';
    return $link !== '' ? ' ([source](' . kop_wiki_auto_fix_url($link) . '))' : ' (' . $ct . ')';
}

function kop_wiki_auto_sentence($text) {
    $t = trim(preg_replace('/\s+/', ' ', (string) $text));
    return $t === '' || preg_match('/[.!?]["”)]?$/u', $t) ? $t : $t . '.';
}

/** The sentence with its citation before the final stop. */
function kop_wiki_auto_with_cite($text, array $g) {
    $t = kop_wiki_auto_sentence($text);
    $c = kop_wiki_auto_cite($g);
    return $c !== '' && $t !== '' ? mb_substr($t, 0, -1) . $c . mb_substr($t, -1) : $t;
}

function kop_wiki_auto_long_date($d) {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $d, $m) ? date('F', mktime(0, 0, 0, (int) $m[2], 1)) . ' ' . (int) $m[3] . ', ' . $m[1] : (string) $d;
}

function kop_wiki_auto_mdy($d) {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $d, $m) ? (int) $m[2] . '/' . (int) $m[3] . '/' . $m[1] : (string) $d;
}

/** "the" for a title one person holds, "an RN", "a therapist". Same as scripts/wiki-drafts.py role_article(). */
function kop_wiki_auto_role_article($role) {
    if (preg_match('/^(?!(assistant|associate|deputy|vice|co-?|former )\b)[^,]*\b(director|ceo|coo|cfo|cmo|cto|president|founder|owner|headmaster|headmistress|head of|principal|superintendent|administrator|chair(man|woman|person)?|chief|dean)\b/i', $role)) return 'the';
    if (preg_match('/^[A-Z]{2}/', $role)) return strpos('AEFHILMNORSX', $role[0]) !== false ? 'an' : 'a';
    return preg_match('/^([aeiou]|hono|hour)/i', $role) && !preg_match('/^(uni|use|eu|one)/i', $role) ? 'an' : 'a';
}

/** "the Executive Director of X", "a therapist at X", "a co-founder of X". */
function kop_wiki_auto_role_phrase($role, $place, $also = false) {
    $role = trim((string) $role);
    if (preg_match('/^(the|a|an)\s/i', $role)) return $role . (stripos($role, 'the ') === 0 ? ' of ' : ' at ') . $place;
    // A role written in sentence case ("Clinical director") reads as words; titles in title case stay as written.
    // The other jobs after "was also" keep their case (scripts/wiki-drafts.py also_roles()).
    if (!$also && preg_match('/^[A-Z][a-z]+(?: (?:[a-z][\w&\/-]*|&))+$/', $role)) $role = mb_strtolower(mb_substr($role, 0, 1)) . mb_substr($role, 1);
    if (kop_wiki_auto_role_article($role) === 'the') return 'the ' . $role . ' of ' . $place;
    if (preg_match('/^staff$/i', $role)) $role = 'staff member';
    elseif (preg_match('/^[A-Za-z][a-z-]+(?: and [A-Za-z][a-z-]+)*$/', $role)) $role = mb_strtolower($role);
    $prep = preg_match('/found|owner|trustee|board|partner|investor|shareholder/i', $role) ? 'of' : 'at';
    return kop_wiki_auto_role_article($role) . ' ' . $role . ' ' . $prep . ' ' . $place;
}

/** "Admissions Director, Admissions" -> "Admissions Director"; notes in brackets, "Former", NATSAP seats and ", left" go. */
function kop_wiki_auto_clean_role($role) {
    $role = preg_replace('/,\s*left\s*$/i', '', trim((string) $role));
    $role = preg_replace('/\bPrinciple\b/', 'Principal', preg_replace('/\s*\([^)]*\)/', '', $role));
    $parts = array();
    foreach (explode(',', $role) as $p) {
        $p = trim(preg_replace('/^former\s+/i', '', trim($p)));
        if ($p !== '' && stripos($p, 'natsap') === false) $parts[] = $p;
    }
    $words = function ($p) {
        preg_match_all('/[a-z]+/', str_replace('admission ', 'admissions ', strtolower($p)), $m);
        return array_values(array_diff(array_unique($m[0]), array('of', 'and', 'the')));
    };
    $keep = array();
    foreach ($parts as $i => $p) {
        $drop = false;
        foreach ($parts as $j => $q) {
            if ($i === $j) continue;
            $wp = $words($p);
            $wq = $words($q);
            $same = !array_diff($wp, $wq) && !array_diff($wq, $wp);
            // "CEO" beside "Chief Executive Officer": an abbreviation of another part goes.
            preg_match_all('/[A-Za-z]+/', $q, $qw);
            $initials = strtolower(implode('', array_map(function ($w) { return $w[0]; }, array_diff($qw[0], array('of', 'and', 'the')))));
            if (($same && $j < $i) || (!$same && $wp && !array_diff($wp, $wq)) || (strlen($p) <= 5 && strtolower($p) === $initials)) { $drop = true; break; }
        }
        if (!$drop) $keep[] = $p;
    }
    return $keep ? implode(', ', $keep) : $role;
}

/** One staff paragraph: always "was" (KOP's lists do not say whether someone still holds the job). */
function kop_wiki_auto_staff_line(array $g, $program) {
    $d = (array) ($g['detail'] ?? array());
    $name = trim(preg_replace('/\s*\(.*$/s', '', (string) $g['text']));
    $raw = (string) ($d['role'] ?? '');
    $span = preg_match('/\((\d{4})\s*[-–]\s*(\d{4})/u', $raw, $m) && $m[1] !== $m[2] ? ' from ' . $m[1] . ' to ' . $m[2] : '';
    $year = preg_match('/\((\d{4})/', $raw, $m) ? $m[1] : '';
    $role = kop_wiki_auto_clean_role(preg_replace('/\s*\((\d{4}).*$/s', '', $raw));
    if ($role === '') {
        $s = '**' . $name . '** worked at ' . $program . $span;
    } elseif (preg_match('/^(helped found|co-?founded|founded)$/i', $role)) {
        $s = '**' . $name . '** ' . mb_strtolower(mb_substr($role, 0, 1)) . mb_substr($role, 1) . ' ' . $program . ($year !== '' ? ' in ' . $year : '');
    } else {
        $s = '**' . $name . '** was ' . kop_wiki_auto_role_phrase($role, $program) . $span;
    }
    $s = preg_replace('/\.\.$/', '.', $s . kop_wiki_auto_cite($g) . '.');   // "Heritage Schools Inc."
    $others = array();
    foreach ((array) ($d['other_roles'] ?? array()) as $r) {
        $at = strrpos((string) $r, ', ');
        if ($at === false) continue;
        // "Training Manager, Education Director" at one place: "the training manager and education director of X".
        $role2 = preg_replace('/,\s+/', ' and ', trim(preg_replace('/,\s*left\s*$/i', '', substr($r, 0, $at))));
        $role2 = trim(preg_replace('/\s*\(\d{4}[^)]*\)/', '', $role2));
        $place = trim(preg_replace('/\s*\(\d{4}.*$/s', '', substr($r, $at + 2)));
        if ($role2 === '' || $place === '') continue;
        $others[] = kop_wiki_auto_role_phrase($role2, kop_wiki_auto_wiki_link($place, $program), true);
    }
    if ($others) {
        $parts = explode(' ', $name);
        $last = count($others) > 1 ? implode(', ', array_slice($others, 0, -1)) . ' and ' . end($others) : $others[0];
        $s .= ' ' . end($parts) . ' was also ' . $last . '.';
    }
    return $s;
}

/** One of the record's incidents as its own paragraph: the record's words, dated when they are not. */
function kop_wiki_auto_incident_line(array $g) {
    $d = (array) ($g['detail'] ?? array());
    $what = trim((string) ($d['what'] ?? '') !== '' ? $d['what'] : (explode(': ', (string) $g['text'], 2)[1] ?? $g['text']));
    $when = trim((string) ($d['when'] ?? ''));
    $year = preg_match('/\d{4}/', $when, $m) ? $m[0] : '';
    if ($when !== '' && !($year !== '' && strpos($what, $year) !== false) && stripos($what, $when) === false) {
        $lead = stripos($when, 'reported') === 0 ? preg_replace('/^Reported /', 'Reported in ', $when)
            : (preg_match('/\b\d{1,2},? \d{4}$/', $when) ? 'On ' : 'In ') . $when;
        $first = strtolower(strtok($what, ' '));
        if (in_array($first, array('a', 'an', 'the', 'survivors', 'survivor', 'staff', 'residents', 'students', 'boys', 'girls',
            'parents', 'former', 'two', 'three', 'several', 'one', 'another', 'his', 'her', 'their'), true)) {
            $what = mb_strtolower(mb_substr($what, 0, 1)) . mb_substr($what, 1);
        }
        $what = $lead . ', ' . $what;
    }
    return kop_wiki_auto_with_cite($what, $g);
}

/** A case: its summary as the sentence, the case named after it (the editor's buildLawsuitSentence()). */
function kop_wiki_auto_lawsuit_line(array $g) {
    $d = (array) ($g['detail'] ?? array());
    $case = trim((string) $g['text']);
    $bits = array_filter(array(trim((string) ($d['court'] ?? '')), trim((string) ($d['case_number'] ?? '')) !== '' ? 'No. ' . trim($d['case_number']) : ''));
    $named = '*' . $case . '*' . ($bits ? ' (' . implode(', ', $bits) . ')' : '');
    $summary = kop_wiki_auto_sentence((string) ($d['summary'] ?? ''));
    $year = trim((string) $g['date']);
    $text = $summary !== '' ? $summary . ' ' . $named . '.' : ($year !== '' ? 'In ' . $year . ', a lawsuit was filed: ' . $named . '.' : 'A lawsuit was filed: ' . $named . '.');
    $outcome = trim((string) ($d['outcome'] ?? ''));
    if ($outcome !== '' && stripos($text, $outcome) === false) $text .= ' Outcome: ' . kop_wiki_auto_sentence($outcome);
    return kop_wiki_auto_with_cite($text, $g);
}

/** The state's own words, quoted: "In a report dated May 3, 2021, Utah inspectors found: "...". */
function kop_wiki_auto_finding_line(array $g) {
    $d = (array) ($g['detail'] ?? array());
    $state = preg_match('/^(.+?) licensing inspection report/', (string) $g['source_label'], $m) ? trim($m[1]) : '';
    $ex = trim(preg_replace('/\s+/', ' ', (string) ($d['excerpt'] ?? '') !== '' ? $d['excerpt'] : (explode(': ', (string) $g['text'], 2)[1] ?? $g['text'])));
    $ex = str_replace('"', "'", rtrim($ex, '.'));
    $when = trim((string) $g['date']) !== '' ? 'In a report dated ' . kop_wiki_auto_long_date($g['date']) . ', ' : '';
    $who = $state !== '' ? $state . ' inspectors' : 'state inspectors';
    return $when . ($when === '' ? ucfirst($who) : $who) . ' found: "' . $ex . '."' . kop_wiki_auto_cite($g);
}

/** Every new gap -> its ops, placed in the entry's own sections; a section the entry lacks is added as the template does. */
function kop_wiki_auto_ops(array $entry, array $page, array $gaps, $md) {
    $program = kop_wiki_auto_clean_name($entry['program_name']);
    list(, $w_end) = kop_wiki_upd_years($entry, $md);
    $closed = strcasecmp((string) ($page['status'] ?? ''), 'Closed') === 0 || $w_end !== 'present';
    $lines = kop_wiki_drafts_lines($md);
    $secs = kop_wiki_drafts_sections($lines);
    $template = kop_wiki_auto_template();
    $present = array();
    $levels = array();
    foreach ($template as $canon => $is) {
        foreach ($secs as $s) {
            if ($s[0] === 0) continue;   // the page title
            if ($is($s[2])) {
                $present[$canon] = trim(preg_replace('/[#*]+/', '', $lines[$s[0]]));
                $levels[$canon] = preg_match('/^\s*(#+)/', $lines[$s[0]], $m) ? $m[1] : '##';
                break;
            }
        }
    }
    $sep = '---';
    foreach (array_slice($lines, 0, kop_wiki_drafts_footer($lines)) as $l) if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $l)) { $sep = trim($l); break; }
    $ops = array();
    $add = function ($canon, $text, array $g, $key, array $extra = array()) use (&$ops, &$present, &$levels, $template, $sep) {
        $op = array('id' => 'a' . $key, 'by' => 'site', 'text' => $text, 'verdict' => 'ok',
            'note' => 'Written from KOP\'s record (' . $g['kind'] . ($g['date'] !== '' ? ', ' . $g['date'] : '') . ').') + $extra;
        if (empty($g['kop_source']) && kop_wiki_auto_cite($g) === '') $op['note'] .= ' The record names no outside source: it stands on KOP\'s own record.';
        if (isset($present[$canon])) {
            $op += array('op' => 'append_to_section', 'section' => $present[$canon]);
        } else {
            // After the nearest earlier template section the entry has (or this draft added), at its level.
            $anchor = '';
            $level = '##';
            foreach ($template as $c => $is) {
                if ($c === $canon) break;
                if (isset($present[$c])) { $anchor = $present[$c]; $level = $levels[$c]; }
            }
            $op += array('op' => 'add_section', 'after_section' => $anchor, 'heading' => $level . ' **' . $canon . '**', 'separator' => $sep);
            $present[$canon] = $canon;
            $levels[$canon] = $level;
        }
        $ops[] = $op;
    };
    $order = array('name' => 0, 'operator' => 1, 'closure' => 2, 'staff' => 3, 'rules' => 4, 'incident' => 5, 'lawsuit' => 5, 'death' => 5, 'finding' => 5, 'news' => 6);
    uksort($gaps, function ($a, $b) use ($gaps, $order) {
        $x = $gaps[$a];
        $y = $gaps[$b];
        return array(($order[$x['kind']] ?? 9), (string) $x['date'], $a) <=> array(($order[$y['kind']] ?? 9), (string) $y['date'], $b);
    });
    // News: one "In the Media" line each, as a list when the section is one.
    $media_list = true;
    foreach ($secs as $s) {
        if (!$template['In the Media']($s[2])) continue;
        $body = array();
        for ($k = $s[0] + 1; $k < kop_wiki_drafts_body_end($lines, $s[0], $s[1]); $k++) {
            if (trim($lines[$k]) !== '' && !preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $lines[$k]) && !kop_wiki_drafts_is_placeholder($lines[$k])) $body[] = $lines[$k];
        }
        $media_list = !$body || count(preg_grep('/^\s*[-*] /', $body)) === count($body);
        break;
    }
    foreach ($gaps as $key => $g) {
        $d = (array) ($g['detail'] ?? array());
        switch ($g['kind']) {
            case 'staff':
                $add('Founders and Notable Staff', kop_wiki_auto_staff_line($g, $program), $g, $key);
                break;
            case 'incident':
                $add('Abuse/Neglect Allegations and Lawsuits', kop_wiki_auto_incident_line($g), $g, $key);
                break;
            case 'lawsuit':
                $add('Abuse/Neglect Allegations and Lawsuits', kop_wiki_auto_lawsuit_line($g), $g, $key);
                break;
            case 'death':
                $add('Abuse/Neglect Allegations and Lawsuits', kop_wiki_auto_with_cite($g['text'], $g), $g, $key);
                break;
            case 'finding':
                $add('Abuse/Neglect Allegations and Lawsuits', kop_wiki_auto_finding_line($g), $g, $key);
                break;
            case 'rules':
                $add(($d['section'] ?? '') === 'Program Structure' ? 'Program Structure' : 'Rules and Punishments', kop_wiki_auto_with_cite($g['text'], $g), $g, $key);
                break;
            case 'name':
                $nm = (string) ($d['name'] ?? '');
                $how = (string) ($d['how'] ?? '');
                $s = $how === 'later' ? $program . ' later operated as ' . kop_wiki_auto_wiki_link($nm, $program)
                    : ($how === 'current' ? $program . ' now operates as ' . kop_wiki_auto_wiki_link($nm, $program)
                    : ($how === 'aka' || $how === 'also' ? $program . ' has also been known as ' . $nm : $program . ' was formerly called ' . $nm));
                $add('History and Background Information', kop_wiki_auto_with_cite($s, $g), $g, $key);
                break;
            case 'operator':
                $op = (string) ($d['operator'] ?? '');
                if (!preg_match('/[a-z]/', $op) || preg_match('/^\W*(unknown|relocated|closed|n\/?a|none|tbd|\?)\W*$/i', $op)) break;
                $add('History and Background Information', kop_wiki_auto_with_cite($program . ' ' . ($closed ? 'was' : 'is') . ' operated by ' . kop_wiki_auto_wiki_link($op, $program), $g), $g, $key);
                break;
            case 'closure':
                $end = (string) ($d['end_year'] ?? '');
                $num = (bool) preg_match('/^\d{4}$/', $end);
                $add('History and Background Information', kop_wiki_auto_with_cite($program . ($num ? ' closed in ' . $end : ' has closed'), $g), $g, $key);
                $start = trim(explode('-', (string) ($entry['years_active'] ?? ''))[0]);
                // Only a header with years (or a bold name to put them after), as kop_wiki_drafts_apply() sets them.
                $head = (string) ($lines[0] ?? '');
                $has_years = preg_match('/\*\*\s*\([^)]*\)/', $head) || (strpos($head, '(') === false && preg_match('/^#+\s*\*\*.*?\*\*/', $head));
                if ($num && preg_match('/^\d{4}$/', $start) && $has_years && strpos($head, '(' . $start . '-' . $end . ')') === false) {
                    $ops[] = array('id' => 'a' . $key . 'y', 'by' => 'site', 'op' => 'set_header_years', 'years' => $start . '-' . $end, 'verdict' => 'ok',
                        'note' => 'Closing year from the record.');
                }
                break;
            case 'news':
                $line = '[' . trim((string) $g['text']) . '](' . kop_wiki_auto_fix_url($g['source_url']) . ') (' . ($g['source_label'] !== '' ? $g['source_label'] : 'news')
                    . (trim((string) $g['date']) !== '' ? ', ' . kop_wiki_auto_mdy($g['date']) : '') . ')';
                $add('In the Media', ($media_list ? '- ' : '') . $line, $g, $key);
                break;
        }
    }
    return $ops;
}
