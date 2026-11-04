<?php
/**
 * Plan 4.3 items 26 + 27 and plan 3 item 38 (owner, 2026-11-04):
 *   - otherNames that are a company's own name come off two records: "Teen Challenge" on #100720 Teen Challenge
 *     Riverside Facility, "Embark Behavioral Health" on #100128 Embark Behavioral Health RTC. A record holding its
 *     operator's bare name pulls every story about the company onto that one site.
 *   - #100720 comes off approved news #11017 (Naomi Wood, Lakeland Girls Academy FL), put there by that other name.
 *   - Approved news with no record get the records their title/summary or article text names, from the dry run of
 *     kop_autoconn_submission_records() on the 2026-11-04 mirror, the wrong picks taken out by hand ("Michigan House",
 *     "Everyday Life", "Milestones", "Cunningham", "The Approach", Utah Prep from a sidebar, a Wyoming record in
 *     Michigan stories). Only rows whose facilities_mentioned is still empty are written.
 *
 * CLI only, on the server (ea-php82), from the theme directory:
 *   ea-php82 api/fix-news-text-links.php              dry run
 *   ea-php82 api/fix-news-text-links.php apply        write it (what was there kept in option kop_fix_news_text_links_log)
 *   ea-php82 api/fix-news-text-links.php undo apply   put it back (records only when nothing changed them since)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

const KOP_NTL_LOG = 'kop_fix_news_text_links_log';
const KOP_NTL_BY = 'news-text-links';

/** record id => other name that comes off. */
function kop_ntl_other_names() {
    return array(100720 => 'Teen Challenge', 100128 => 'Embark Behavioral Health');
}

/** news id => record ids it gets (approved, facilities_mentioned empty on the mirror). */
function kop_ntl_news_targets() {
    return array(
        196 => array(100247), 244 => array(14154), 359 => array(13245), 406 => array(11144), 407 => array(13639),
        408 => array(10371), 411 => array(10417), 412 => array(14130), 416 => array(11135, 11105, 11103, 11110),
        418 => array(11105, 11135, 11103, 11110), 420 => array(13641), 478 => array(10371),
        484 => array(14130, 14131, 12023), 488 => array(12050, 12539),
        493 => array(11135, 11104, 11201, 11205, 11155, 100553), 494 => array(11250, 100685), 495 => array(11470, 11476),
        1108 => array(10458), 10328 => array(100533, 13436), 10333 => array(11660), 10378 => array(9727),
        10411 => array(11476), 10639 => array(100669),
    );
}

/** news id => record id that comes off it. */
function kop_ntl_news_drop() {
    return array(11017 => 100720);
}

$apply = in_array('apply', $argv, true);
$undo = in_array('undo', $argv, true);
set_time_limit(0);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_seed_pdo')) {
    fwrite(STDERR, "WordPress did not load\n");
    exit(2);
}
foreach (array('news-mentions.php', 'lib-record-resync.php') as $f) require_once __DIR__ . '/' . $f;
$pdo = kop_seed_pdo();
$prefix = $pdo ? kop_seed_v2_prefix($pdo) : null;
if (!$pdo || $prefix === null || !function_exists('kop_resync_news') || !function_exists('kop_facility_load')) {
    fwrite(STDERR, "No records database, facilities_v2, kop_resync_news or kop_facility_load\n");
    exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$opts = array('pdo' => $pdo, 'prefix' => $prefix);

function kop_ntl_normalize(array $doc, $id, $unique_name) {
    return function_exists('kop_facility_normalize')
        ? kop_facility_normalize($doc, array('facility_id' => (int) $id, 'unique_name' => (string) $unique_name))
        : $doc;
}

function kop_ntl_after(array $log) {
    delete_transient('kop_facility_pages_index');
    if (function_exists('kop_record_changed') && !empty($log['docs'])) kop_record_changed('facility', array_map('intval', array_keys($log['docs'])));
    do_action('litespeed_purge_all');
}

$read = $pdo->prepare('SELECT id, article_title, status, facilities_mentioned FROM news_submissions WHERE id = ?');
$links = $pdo->prepare('SELECT * FROM news_facility_links WHERE news_id = ?');
$names = $pdo->prepare('SELECT name FROM facilities_v2 WHERE id = ?');
$name_of = function ($fid) use ($names) {
    $names->execute(array((int) $fid));
    $n = $names->fetchColumn();
    $names->closeCursor();
    return $n === false ? null : (string) $n;
};

if ($undo) {
    $log = get_option(KOP_NTL_LOG, array());
    if (!$log) { echo "Nothing to undo.\n"; exit(0); }
    foreach ((array) ($log['docs'] ?? array()) as $id => $entry) {
        $now = kop_facility_load((int) $id, $opts);
        if (!$now || empty($entry['after']) || json_encode($now['doc']) !== $entry['after']) { echo "  #$id changed since the fix, skipped\n"; continue; }
        echo "  #$id: record put back\n";
        if ($apply) kop_facility_save(kop_ntl_normalize(json_decode($entry['before'], true), (int) $id, $now['unique_name']), $opts);
    }
    foreach ((array) ($log['news'] ?? array()) as $id => $entry) {
        echo "  news $id: mentions and links put back\n";
        if (!$apply) continue;
        $pdo->prepare('UPDATE news_submissions SET facilities_mentioned = ? WHERE id = ?')->execute(array($entry['mentions'], (int) $id));
        $pdo->prepare('DELETE FROM news_facility_links WHERE news_id = ?')->execute(array((int) $id));
        foreach ($entry['links'] as $l) {
            $cols = array_keys($l);
            $pdo->prepare('INSERT INTO news_facility_links (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($l));
        }
        kop_record_changed('news', array((int) $id));
    }
    if ($apply) {
        delete_option(KOP_NTL_LOG);
        kop_ntl_after($log);
    }
    echo $apply ? "Put back.\n" : "Dry run.\n";
    exit(0);
}

$log = get_option(KOP_NTL_LOG);
if (!empty($log['done'])) { echo "Already applied (option " . KOP_NTL_LOG . "). Run 'undo apply' first to redo it.\n"; exit(0); }
if (!is_array($log)) $log = array('at' => gmdate('c'), 'docs' => array(), 'news' => array());
$total = 0;

// ---- Other names ------------------------------------------------------------------
foreach (kop_ntl_other_names() as $id => $drop) {
    if (!empty($log['docs'][$id]['after'])) continue;
    $rec = kop_facility_load($id, $opts);
    if (!$rec) { echo "  #$id: not found, skipped\n"; continue; }
    $doc = $rec['doc'];
    $other = (array) ($doc['identification']['otherNames'] ?? array());
    $keep = array();
    foreach ($other as $n) {
        $s = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
        if (strcasecmp(trim($s), $drop) !== 0) $keep[] = $n;
    }
    if (count($keep) === count($other)) { echo "  #$id: \"$drop\" is not an other name (now: " . json_encode($other) . ")\n"; continue; }
    echo "  #$id " . ($rec['doc']['identification']['name'] ?? '') . ": other name \"$drop\" off, keeps " . json_encode($keep, JSON_UNESCAPED_UNICODE) . "\n";
    $total++;
    if (!$apply) continue;
    $doc['identification']['otherNames'] = $keep;
    $log['docs'][$id] = array('before' => json_encode($rec['doc']));
    update_option(KOP_NTL_LOG, $log, false);
    kop_facility_save(kop_ntl_normalize($doc, $id, $rec['unique_name']), $opts);
    $saved = kop_facility_load($id, $opts);
    $log['docs'][$id]['after'] = json_encode($saved['doc']);
    update_option(KOP_NTL_LOG, $log, false);
}

// ---- News -------------------------------------------------------------------------
$plan = array();
foreach (kop_ntl_news_drop() as $nid => $fid) $plan[$nid] = array('drop' => $fid);
foreach (kop_ntl_news_targets() as $nid => $fids) $plan[$nid] = array('add' => $fids);
$resync = array();
foreach ($plan as $nid => $what) {
    if (isset($log['news'][$nid])) continue;
    $read->execute(array($nid));
    $row = $read->fetch(PDO::FETCH_ASSOC);
    $read->closeCursor();
    if (!$row) { echo "  news $nid: gone, skipped\n"; continue; }
    $mentions = json_decode((string) $row['facilities_mentioned'], true);
    $mentions = is_array($mentions) ? $mentions : array();
    $title = mb_substr((string) $row['article_title'], 0, 70);
    if (isset($what['drop'])) {
        $new = array_values(array_filter($mentions, function ($m) use ($what) { return (int) ($m['facility_id'] ?? 0) !== $what['drop']; }));
        if (count($new) === count($mentions)) { echo "  news $nid: #{$what['drop']} not in its mentions, skipped\n"; continue; }
        echo "  news $nid \"$title\": #{$what['drop']} off\n";
    } else {
        if ($row['status'] !== 'approved' || $mentions) { echo "  news $nid: now {$row['status']} with " . count($mentions) . " mentions, skipped\n"; continue; }
        $new = array();
        foreach ($what['add'] as $fid) {
            $n = $name_of($fid);
            if ($n === null) { echo "  news $nid: record #$fid gone, left out\n"; continue; }
            $new[] = array('name' => $n, 'facility_id' => $fid);
        }
        if (!$new) continue;
        echo "  news $nid \"$title\": " . implode('; ', array_map(function ($m) { return '#' . $m['facility_id'] . ' ' . $m['name']; }, $new)) . "\n";
    }
    $total++;
    if (!$apply) continue;
    $links->execute(array($nid));
    $log['news'][$nid] = array('mentions' => $row['facilities_mentioned'], 'links' => $links->fetchAll(PDO::FETCH_ASSOC));
    $links->closeCursor();
    update_option(KOP_NTL_LOG, $log, false);
    $pdo->prepare('UPDATE news_submissions SET facilities_mentioned = ? WHERE id = ?')
        ->execute(array(wp_json_encode($new, JSON_UNESCAPED_UNICODE), $nid));
    $resync[] = $nid;
}
if ($apply && $resync) {
    foreach (kop_resync_news($pdo, $resync, KOP_NTL_BY) as $nid => $failed) {
        if ($failed) echo "  news $nid: resync steps failed: " . implode(', ', $failed) . "\n";
    }
}
if ($apply) {
    $log['done'] = true;
    update_option(KOP_NTL_LOG, $log, false);
    kop_ntl_after($log);
}
echo ($apply ? 'Applied: ' : 'Dry run: ') . "$total changes\n";
