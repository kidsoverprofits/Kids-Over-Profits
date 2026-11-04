<?php
/**
 * Four programs called Lakeside Academy, one name (owner, 2026-11-04):
 *   #100098 Kalamazoo, MI  - Sequel (Lakeside for Children); Cornelius Frederick died there in 2020, closed 2020
 *   #100099 Buffalo, MN    - Teen Challenge only; nothing of Sequel or Michigan belongs on it
 *   #10459  Clearfield, UT - Youth Health Associates (Woodbury Reports, March 2010 and July 2011)
 *   #12592  Lakeside Academy of Kansas (Goddard) - Sequel's Kansas program
 * The bare name "Lakeside Academy" is #10459's unique name, so the link syncs filed the Michigan news, a
 * Michigan case and a Sequel report's links under the Utah record. This puts each item on its own program:
 *   - news: the Michigan / Sequel articles on #100098, the two Kansas ones on #12592 (links + the
 *     facilities_mentioned entry, so a re-save keeps them there)
 *   - lawsuits 28, 75, 76, 77, 205 (Goshay v. Lakeside for Children, W.D. Mich.) on #100098
 *   - #10459: the "Vivant Provider Report" / "Sequel/Vivant/YSI" links go to #100098, the r/troubledteens
 *     wiki "lakesidetc" (Teen Challenge) link to #100099, the Goshay note comes off; its document folder is
 *     "UTAH / Youth Health Associates - Lakeside Academy" (#4027)
 *   - #100098: the "(Woodbury Reports, July 2011, p. 7 ...)" citation comes off its notes (that page is about
 *     Youth Health Associates' Utah programs)
 *   - FileBird: "Woodbury Reports Mentions" (#8099, the Utah program's March 2010 Woodbury page) moves from
 *     "Sequel TSI / Lakeside Academy" to the Utah program's folder
 * Code side (same commit): names records in several states share resolve only with a place
 * (api/facility-aliases.php), and pages drop items that name another state (inc/facility-pages.php).
 *
 * CLI only, on the server (ea-php82), from the theme directory:
 *   php api/fix-lakeside-academy-split.php              dry run: what would change
 *   php api/fix-lakeside-academy-split.php apply        write it (everything as it was kept in option kop_fix_lakeside_split_log)
 *   php api/fix-lakeside-academy-split.php undo apply   put it all back, records only when nothing changed them since
 *   php api/fix-lakeside-academy-split.php --selftest   the rewrite rules on made-up rows
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

const KOP_LKS_MI = 100098;
const KOP_LKS_MN = 100099;
const KOP_LKS_UT = 10459;
const KOP_LKS_KS = 12592;
const KOP_LKS_UT_FOLDER = 4027;      // UTAH / Youth Health Associates - Lakeside Academy
const KOP_LKS_SEQUEL_FOLDER = 240;   // Sequel TSI / Lakeside Academy
const KOP_LKS_WOODBURY_FOLDER = 8099;
const KOP_LKS_LOG = 'kop_fix_lakeside_split_log';

/** news id => facility it is about. Read on the mirror 2026-11-04: every article the Utah record held is the Michigan school's. */
function kop_lks_news_targets() {
    $mi = array(606, 762, 949, 1235, 1244, 1401, 10331, 10337, 10415, 10416, 10487, 10780, 11028,
                641, 718, 726, 945, 947, 987, 988);
    for ($i = 5419; $i <= 5478; $i++) $mi[] = $i;   // the SCIAD NET import's Michigan articles (deleted status, kept consistent)
    $out = array_fill_keys($mi, KOP_LKS_MI);
    $out[607] = KOP_LKS_KS;     // staff arrest, Kansas
    $out[10718] = KOP_LKS_KS;   // Wichita: Successful Dreams takes over the Kansas program
    return $out;
}

/** lawsuit id => facility. All are Goshay v. Lakeside for Children (W.D. Mich.) or its news. */
function kop_lks_lawsuit_targets() {
    return array_fill_keys(array(28, 75, 76, 77, 205), KOP_LKS_MI);
}

function kop_lks_is_lakeside($name) {
    $n = strtolower(trim(preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $name)));
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $n)) === 'lakeside academy';
}

/** facilities_mentioned JSON with every "Lakeside Academy" entry pinned to $fid. [json, changed]. */
function kop_lks_pin_mentions($json, $fid) {
    $list = json_decode((string) $json, true);
    if (!is_array($list)) return array($json, false);
    $changed = false;
    foreach ($list as $i => $m) {
        $name = is_array($m) ? (string) ($m['name'] ?? '') : (string) $m;
        if (!kop_lks_is_lakeside($name)) continue;
        $now = is_array($m) ? (int) ($m['facility_id'] ?? 0) : 0;
        if ($now === $fid) continue;
        $list[$i] = array_merge(is_array($m) ? $m : array(), array('name' => $name, 'facility_id' => $fid));
        $changed = true;
    }
    return array($changed ? json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $json, $changed);
}

/** The three documents after the move: [ut, mi, mn, changes]. */
function kop_lks_fix_docs(array $ut, array $mi, array $mn) {
    $changes = array();
    $urls = function (array $doc) {
        $out = array();
        foreach ((array) ($doc['resourceLinks'] ?? array()) as $l) if (is_array($l)) $out[(string) ($l['url'] ?? '')] = true;
        return $out;
    };
    $mi_urls = $urls($mi);
    $mn_urls = $urls($mn);
    $keep = array();
    foreach ((array) ($ut['resourceLinks'] ?? array()) as $l) {
        $src = is_array($l) ? (string) ($l['source'] ?? '') : '';
        $url = is_array($l) ? (string) ($l['url'] ?? '') : '';
        if (stripos($src, 'Vivant Provider Report') !== false || stripos($src, 'Sequel/Vivant') !== false) {
            if (!isset($mi_urls[$url])) { $mi['resourceLinks'][] = $l; $mi_urls[$url] = true; }
            $changes[] = 'link to #' . KOP_LKS_MI . ': ' . $url;
            continue;
        }
        if (stripos($url, '/wiki/index/lakesidetc') !== false) {
            if (!isset($mn_urls[$url])) { $mn['resourceLinks'][] = $l; $mn_urls[$url] = true; }
            $changes[] = 'link to #' . KOP_LKS_MN . ': ' . $url;
            continue;
        }
        $keep[] = $l;
    }
    if (isset($ut['resourceLinks'])) $ut['resourceLinks'] = $keep;

    $notes = array();
    foreach ((array) ($ut['operatingPeriod']['notes'] ?? array()) as $n) {
        if (is_string($n) && (stripos($n, 'Goshay') !== false || stripos($n, 'W.D. Mich') !== false)) {
            $changes[] = 'note off #' . KOP_LKS_UT . ': ' . $n;
            continue;
        }
        $notes[] = $n;
    }
    if (isset($ut['operatingPeriod']['notes'])) $ut['operatingPeriod']['notes'] = $notes;
    if ((int) ($ut['documentFolderId'] ?? 0) !== KOP_LKS_UT_FOLDER) {
        $ut['documentFolderId'] = KOP_LKS_UT_FOLDER;
        $changes[] = 'document folder of #' . KOP_LKS_UT . ': #' . KOP_LKS_UT_FOLDER;
    }

    $cite = '/\s*\(Woodbury Reports, July 2011, p\. 7 \(on the record\), 2011-07: \S*woodbury-0711\.pdf#page=7\)/';
    foreach ((array) ($mi['operatingPeriod']['notes'] ?? array()) as $i => $n) {
        if (!is_string($n)) continue;
        $clean = preg_replace($cite, '', $n);
        if ($clean !== $n) {
            $mi['operatingPeriod']['notes'][$i] = $clean;
            $changes[] = 'Woodbury July 2011 citation off a #' . KOP_LKS_MI . ' note';
        }
    }
    return array($ut, $mi, $mn, $changes);
}

if (in_array('--selftest', $argv, true)) {
    $fails = 0;
    $t = function ($name, $ok) use (&$fails) { echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) $fails++; };
    list($j, $c) = kop_lks_pin_mentions('["Lakeside Academy","Lakeside for Children"]', KOP_LKS_MI);
    $t('a bare name is pinned, others left', $c && json_decode($j, true) === array(array('name' => 'Lakeside Academy', 'facility_id' => KOP_LKS_MI), 'Lakeside for Children'));
    list($j, $c) = kop_lks_pin_mentions('[{"name":"Lakeside Academy (Kalamazoo, Michigan)","facility_id":null}]', KOP_LKS_MI);
    $t('a placed name in object form is pinned', $c && json_decode($j, true)[0]['facility_id'] === KOP_LKS_MI);
    list($j2, $c2) = kop_lks_pin_mentions($j, KOP_LKS_MI);
    $t('pinning twice changes nothing', !$c2 && $j2 === $j);
    $t('Lakeside Academy of Kansas is not the bare name', !kop_lks_is_lakeside('Lakeside Academy of Kansas'));
    $ut = array('resourceLinks' => array(
            array('url' => 'https://archive.ph/a', 'source' => 'Google Doc: Vivant Provider Report - 2024(CM)'),
            array('url' => 'https://reddit.com/r/troubledteens/wiki/index/lakesidetc', 'source' => 'Google Doc: Teen Challenge - Israel'),
            array('url' => 'https://ut.example', 'source' => 'other')),
        'operatingPeriod' => array('notes' => array('Ages 13-17 (Woodbury)', 'Corrected (data audit): Goshay v. Lakeside for Children, W.D. Mich. 1:21-cv-848')));
    $mi = array('resourceLinks' => array(array('url' => 'https://archive.ph/a')), 'operatingPeriod' => array('notes' => array(
        'Closed 2020 (NYT). (Woodbury Reports, July 2011, p. 7 (on the record), 2011-07: https://kidsoverprofits.org/wp-content/uploads/2024/12/woodbury-0711.pdf#page=7)')));
    $mn = array('resourceLinks' => array());
    list($u, $m, $n, $ch) = kop_lks_fix_docs($ut, $mi, $mn);
    $t('Utah keeps only its own link', array_column($u['resourceLinks'], 'url') === array('https://ut.example'));
    $t('the Sequel link is not doubled on Michigan', count($m['resourceLinks']) === 1);
    $t('the Teen Challenge wiki link goes to Minnesota', count($n['resourceLinks']) === 1);
    $t('the Goshay note leaves Utah', $u['operatingPeriod']['notes'] === array('Ages 13-17 (Woodbury)'));
    $t('the Woodbury citation leaves Michigan', $m['operatingPeriod']['notes'] === array('Closed 2020 (NYT).'));
    list($u2, $m2, $n2, $ch2) = kop_lks_fix_docs($u, $m, $n);
    $t('running twice changes nothing more', $ch2 === array() && $u2 === $u && $m2 === $m && $n2 === $n);
    exit($fails ? 1 : 0);
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
$pdo = kop_seed_pdo();
$prefix = $pdo ? kop_seed_v2_prefix($pdo) : null;
if (!$pdo || $prefix === null) {
    fwrite(STDERR, "No records database connection, or facilities_v2 is not written\n");
    exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$opts = array('pdo' => $pdo, 'prefix' => $prefix);
$fbv = $prefix . 'fbv';

if ($undo) {
    $log = get_option(KOP_LKS_LOG, array());
    if (!$log) { echo "Nothing to undo.\n"; exit(0); }
    foreach ((array) ($log['docs'] ?? array()) as $id => $entry) {
        $now = kop_facility_load((int) $id, $opts);
        if (!$now || json_encode($now['doc']) !== $entry['after']) { echo "  #$id changed since the fix, skipped (put it back by hand)\n"; continue; }
        echo "  #$id: record put back\n";
        if ($apply) kop_facility_save(json_decode($entry['before'], true), $opts);
    }
    foreach (array('news' => array('news_submissions', 'news_facility_links', 'news_id'), 'lawsuit' => array('lawsuits', 'lawsuit_facility_links', 'lawsuit_id')) as $kind => $t) {
        foreach ((array) ($log[$kind] ?? array()) as $id => $entry) {
            echo "  $kind $id: mentions and links put back\n";
            if (!$apply) continue;
            $pdo->prepare("UPDATE {$t[0]} SET facilities_mentioned = ? WHERE id = ?")->execute(array($entry['mentions'], (int) $id));
            $pdo->prepare("DELETE FROM {$t[1]} WHERE {$t[2]} = ?")->execute(array((int) $id));
            foreach ($entry['links'] as $l) {
                $cols = array_keys($l);
                $pdo->prepare("INSERT INTO {$t[1]} (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                    ->execute(array_values($l));
            }
        }
    }
    if (isset($log['folder_parent'])) {
        echo "  folder #" . KOP_LKS_WOODBURY_FOLDER . ": back under #{$log['folder_parent']}\n";
        if ($apply) $pdo->prepare("UPDATE `$fbv` SET parent = ? WHERE id = ?")->execute(array((int) $log['folder_parent'], KOP_LKS_WOODBURY_FOLDER));
    }
    if ($apply) {
        delete_option(KOP_LKS_LOG);
        kop_lks_after($log);
    }
    echo $apply ? "Put back.\n" : "Dry run.\n";
    exit(0);
}

/** Fresh pages, search and open data after a change. */
function kop_lks_after(array $log) {
    if (function_exists('kop_record_changed')) {
        if (!empty($log['news'])) kop_record_changed('news', array_map('intval', array_keys($log['news'])));
        if (!empty($log['lawsuit'])) kop_record_changed('lawsuit', array_map('intval', array_keys($log['lawsuit'])));
        kop_record_changed('facility', array(KOP_LKS_MI, KOP_LKS_MN, KOP_LKS_UT, KOP_LKS_KS));
    }
    delete_transient('kop_facility_pages_index');
    do_action('litespeed_purge_all');
}

if (get_option(KOP_LKS_LOG)) {
    echo "Already applied (option " . KOP_LKS_LOG . " is set). Run 'undo apply' first to redo it.\n";
    exit(0);
}
$log = array('at' => gmdate('c'), 'docs' => array(), 'news' => array(), 'lawsuit' => array());
$total = 0;

// ---- News and lawsuits ----------------------------------------------------------
foreach (array('news' => array('news_submissions', 'news_facility_links', 'news_id', kop_lks_news_targets()),
               'lawsuit' => array('lawsuits', 'lawsuit_facility_links', 'lawsuit_id', kop_lks_lawsuit_targets())) as $kind => $t) {
    list($table, $links, $col, $targets) = $t;
    $title = $kind === 'news' ? 'article_title' : 'case_name';
    $read = $pdo->prepare("SELECT id, $title AS title, facilities_mentioned FROM $table WHERE id = ?");
    $link_rows = $pdo->prepare("SELECT * FROM $links WHERE $col = ?");
    foreach ($targets as $id => $fid) {
        $read->execute(array($id));
        $row = $read->fetch(PDO::FETCH_ASSOC);
        $read->closeCursor();
        if (!$row) { echo "  $kind $id: gone, skipped\n"; continue; }
        $link_rows->execute(array($id));
        $before_links = $link_rows->fetchAll(PDO::FETCH_ASSOC);
        $have = array();
        foreach ($before_links as $l) $have[(int) $l['facility_id']] = $l;
        list($mentions, $pinned) = kop_lks_pin_mentions($row['facilities_mentioned'], $fid);
        $wrong = array_intersect_key($have, array_flip(array_diff(array(KOP_LKS_UT, KOP_LKS_MN, KOP_LKS_MI, KOP_LKS_KS), array($fid))));
        $missing = !isset($have[$fid]);
        if (!$pinned && !$wrong && !$missing) continue;
        $what = array();
        if ($wrong) $what[] = 'off #' . implode(', #', array_keys($wrong));
        if ($missing) $what[] = 'on #' . $fid;
        if ($pinned) $what[] = 'mention pinned';
        echo "  $kind $id \"" . mb_substr((string) $row['title'], 0, 70) . '": ' . implode('; ', $what) . "\n";
        $total++;
        if (!$apply) continue;
        $log[$kind][$id] = array('mentions' => $row['facilities_mentioned'], 'links' => $before_links);
        update_option(KOP_LKS_LOG, $log, false);
        if ($pinned) $pdo->prepare("UPDATE $table SET facilities_mentioned = ? WHERE id = ?")->execute(array($mentions, $id));
        $type = 'mentioned';
        foreach ($wrong as $wfid => $l) {
            if ($l['link_type'] !== 'mentioned') $type = $l['link_type'];
            $pdo->prepare("DELETE FROM $links WHERE $col = ? AND facility_id = ?")->execute(array($id, $wfid));
        }
        if ($missing) {
            $pdo->prepare("INSERT INTO $links ($col, facility_id, link_type, created_by) VALUES (?, ?, ?, ?)")
                ->execute(array($id, $fid, $type, 'lakeside-split'));
        }
    }
}

// ---- The three records ------------------------------------------------------------
$recs = array();
foreach (array(KOP_LKS_UT, KOP_LKS_MI, KOP_LKS_MN) as $id) {
    $recs[$id] = kop_facility_load($id, $opts);
    if (!$recs[$id]) { fwrite(STDERR, "Record #$id not found; records left as they are\n"); $recs = array(); break; }
}
if ($recs) {
    list($ut, $mi, $mn, $changes) = kop_lks_fix_docs($recs[KOP_LKS_UT]['doc'], $recs[KOP_LKS_MI]['doc'], $recs[KOP_LKS_MN]['doc']);
    foreach ($changes as $c) { echo "  record: $c\n"; $total++; }
    if ($apply && $changes) {
        foreach (array(KOP_LKS_UT => $ut, KOP_LKS_MI => $mi, KOP_LKS_MN => $mn) as $id => $doc) {
            if ($doc === $recs[$id]['doc']) continue;
            $log['docs'][$id] = array('before' => json_encode($recs[$id]['doc']));
            update_option(KOP_LKS_LOG, $log, false);
            kop_facility_save($doc, $opts);
            $saved = kop_facility_load($id, $opts);
            $log['docs'][$id]['after'] = json_encode($saved['doc']);
            update_option(KOP_LKS_LOG, $log, false);
        }
    }
}

// ---- Woodbury folder ----------------------------------------------------------------
$f = $pdo->prepare("SELECT id, name, parent FROM `$fbv` WHERE id IN (?, ?)");
$f->execute(array(KOP_LKS_WOODBURY_FOLDER, KOP_LKS_UT_FOLDER));
$folders = array();
foreach ($f->fetchAll(PDO::FETCH_ASSOC) as $r) $folders[(int) $r['id']] = $r;
$wb = $folders[KOP_LKS_WOODBURY_FOLDER] ?? null;
$utf = $folders[KOP_LKS_UT_FOLDER] ?? null;
if ($wb && $utf && (int) $wb['parent'] === KOP_LKS_SEQUEL_FOLDER && stripos((string) $utf['name'], 'Lakeside Academy') !== false) {
    echo "  folder \"{$wb['name']}\" #" . KOP_LKS_WOODBURY_FOLDER . ": from Sequel TSI / Lakeside Academy to \"{$utf['name']}\"\n";
    $total++;
    if ($apply) {
        $log['folder_parent'] = (int) $wb['parent'];
        update_option(KOP_LKS_LOG, $log, false);
        $pdo->prepare("UPDATE `$fbv` SET parent = ? WHERE id = ?")->execute(array(KOP_LKS_UT_FOLDER, KOP_LKS_WOODBURY_FOLDER));
    }
} elseif ($wb && (int) $wb['parent'] !== KOP_LKS_UT_FOLDER) {
    echo "  folder #" . KOP_LKS_WOODBURY_FOLDER . " is not where it was expected (parent #{$wb['parent']}); left for a person\n";
}

// Anything else still on the Utah record that the list above does not cover.
$q = $pdo->prepare('SELECT n.id, n.article_title FROM news_facility_links l JOIN news_submissions n ON n.id = l.news_id WHERE l.facility_id = ?');
$q->execute(array(KOP_LKS_UT));
$known = kop_lks_news_targets();
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (!isset($known[(int) $r['id']])) echo "  check by hand: news {$r['id']} \"" . mb_substr((string) $r['article_title'], 0, 70) . "\" is on #" . KOP_LKS_UT . "\n";
}

if ($apply && $total) kop_lks_after($log);
echo ($apply ? 'Applied: ' : 'Dry run: ') . "$total changes\n";
