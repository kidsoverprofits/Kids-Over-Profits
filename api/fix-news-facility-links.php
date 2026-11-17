<?php
/**
 * Takes a facility off a news article it is not about, moves the link to the right record, or marks it as named
 * only in passing (listed apart, folded, inc/news-passing.php) (plan 4.3 item 31, 2026-11-17). Each change edits
 * both stores, so a later save or resync does not bring the link back:
 *   - the article's stored mentions (facilities_mentioned; the matching line of json_data's "facilities" too),
 *   - news_facility_links (the link sync prunes it; a link added by hand is deleted as well).
 * Everything it changed is kept in option kop_fix_news_links_log (one entry per apply), and "undo apply" puts the
 * last apply back exactly: the article rows (only where nothing changed them since) and their link rows.
 *
 * CLI only, on the server (ea-php82), from the theme directory:
 *   php api/fix-news-facility-links.php --remove=18:10371[,news:facility...]      dry run
 *   php api/fix-news-facility-links.php --repoint=412:10459>100098                 dry run (news:from>to)
 *   php api/fix-news-facility-links.php --passing=18:12506,18:10371               dry run (adds the mention if none)
 *   php api/fix-news-facility-links.php --file=~/kop-import/news-links.json        [{"news_id","facility_id","to_id"?,"passing"?,"why"}]
 *   ... apply                                                                       write it (needs --note="why")
 *   php api/fix-news-facility-links.php undo [apply]                                put the last apply back
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$args = array();
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/s', $a, $m)) $args[$m[1]] = $m[2];
    else $args[$a] = true;
}
$apply = !empty($args['apply']);
$undo  = !empty($args['undo']);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
require_once __DIR__ . '/lawsuit-facility-links.php';   // news-mentions.php + facility-aliases.php
if (!defined('ABSPATH') || !function_exists('kop_seed_pdo') || !function_exists('kop_sync_news_facility_links')) {
    fwrite(STDERR, "WordPress or the theme's mention code did not load\n");
    exit(2);
}
$pdo = kop_seed_pdo();
if (!$pdo) {
    fwrite(STDERR, "No records database connection\n");
    exit(2);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
const KOP_FNL_LOG = 'kop_fix_news_links_log';

echo ($apply ? 'APPLY' : 'DRY RUN (add apply to write)') . ($undo ? ' UNDO' : '') . "\n";

function kop_fnl_links(PDO $pdo, $nid) {
    $p = kop_news_links_has_passing($pdo) ? ', passing' : '';
    $st = $pdo->prepare("SELECT news_id, facility_id, link_type, created_at, created_by{$p} FROM news_facility_links WHERE news_id = ? ORDER BY facility_id");
    $st->execute(array((int) $nid));
    return $st->fetchAll();
}

function kop_fnl_purge() {
    delete_transient('kop_facility_pages_index');
    do_action('litespeed_purge_all');
}

if ($undo) {
    $log = get_option(KOP_FNL_LOG);
    $last = is_array($log) && $log ? $log[count($log) - 1] : null;
    if (!$last) { echo "nothing to undo\n"; exit(0); }
    echo "Last apply: {$last['at']} ({$last['note']})\n";
    $read = $pdo->prepare('SELECT facilities_mentioned, json_data FROM news_submissions WHERE id = ?');
    foreach ($last['rows'] as $nid => $old) {
        $read->execute(array((int) $nid));
        $now = $read->fetch();
        $read->closeCursor();
        if (!$now || $now['facilities_mentioned'] !== $old['after']) {
            echo "  #$nid changed since the apply: left as it is\n";
            continue;
        }
        echo "  #$nid: mentions and " . count($old['links']) . " link rows put back\n";
        if (!$apply) continue;
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE news_submissions SET facilities_mentioned = ?, json_data = ? WHERE id = ?')
            ->execute(array($old['facilities_mentioned'], $old['json_data'], (int) $nid));
        $pdo->prepare('DELETE FROM news_facility_links WHERE news_id = ?')->execute(array((int) $nid));
        $ins = $pdo->prepare('INSERT INTO news_facility_links (news_id, facility_id, link_type, created_at, created_by) VALUES (?, ?, ?, ?, ?)');
        $set = kop_news_links_has_passing($pdo) ? $pdo->prepare('UPDATE news_facility_links SET passing = ? WHERE news_id = ? AND facility_id = ?') : null;
        foreach ($old['links'] as $l) {
            $ins->execute(array((int) $l['news_id'], (int) $l['facility_id'], $l['link_type'], $l['created_at'], $l['created_by']));
            if ($set) $set->execute(array((int) ($l['passing'] ?? 0), (int) $l['news_id'], (int) $l['facility_id']));
        }
        $pdo->commit();
    }
    if ($apply) {
        array_pop($log);
        update_option(KOP_FNL_LOG, $log, false);
        kop_fnl_purge();
    }
    exit(0);
}

// The changes asked for: [news_id, facility_id, to_id|null, why, passing]
$changes = array();
foreach (array_filter(explode(',', (string) ($args['remove'] ?? ''))) as $p) {
    if (preg_match('/^(\d+):(\d+)$/', trim($p), $m)) $changes[] = array((int) $m[1], (int) $m[2], null, '', false);
}
foreach (array_filter(explode(',', (string) ($args['repoint'] ?? ''))) as $p) {
    if (preg_match('/^(\d+):(\d+)>(\d+)$/', trim($p), $m)) $changes[] = array((int) $m[1], (int) $m[2], (int) $m[3], '', false);
}
foreach (array_filter(explode(',', (string) ($args['passing'] ?? ''))) as $p) {
    if (preg_match('/^(\d+):(\d+)$/', trim($p), $m)) $changes[] = array((int) $m[1], (int) $m[2], null, '', true);
}
if (!empty($args['file'])) {
    $path = preg_replace('/^~/', (string) getenv('HOME'), (string) $args['file']);
    $list = json_decode((string) @file_get_contents($path), true);
    if (!is_array($list)) { fwrite(STDERR, "Cannot read $path\n"); exit(2); }
    foreach ($list as $c) {
        $changes[] = array((int) $c['news_id'], (int) $c['facility_id'], !empty($c['to_id']) ? (int) $c['to_id'] : null, (string) ($c['why'] ?? ''), !empty($c['passing']));
    }
}
if (!$changes) { fwrite(STDERR, "Nothing asked: --remove=news:facility, --repoint=news:from>to, --passing=news:facility or --file=...\n"); exit(2); }
if (array_filter($changes, function ($c) { return $c[4]; }) && !kop_news_links_has_passing($pdo)) {
    fwrite(STDERR, "news_facility_links has no passing column yet (inc/news-passing.php adds it on the next page load)\n");
    exit(2);
}
$note = (string) ($args['note'] ?? '');
if ($apply && $note === '') { fwrite(STDERR, "apply needs --note=\"why\"\n"); exit(2); }

$byNews = array();
foreach ($changes as $c) $byNews[$c[0]][] = $c;

$index = kop_build_facility_alias_index($pdo);
$follow = function ($id) { return function_exists('kop_facility_follow_moves') ? (int) kop_facility_follow_moves((int) $id) : (int) $id; };
$read = $pdo->prepare('SELECT id, article_title, status, facilities_mentioned, json_data FROM news_submissions WHERE id = ?');
$entry = array('at' => gmdate('c'), 'note' => $note, 'rows' => array());
$done = 0;

foreach ($byNews as $nid => $list) {
    $read->execute(array($nid));
    $row = $read->fetch();
    $read->closeCursor();
    if (!$row) { echo "#$nid: no such article\n"; continue; }
    $mentions = json_decode((string) $row['facilities_mentioned'], true);
    if (!is_array($mentions)) $mentions = array();
    $json = json_decode((string) $row['json_data'], true);
    $drop_names = array();
    $changed = false;
    echo "#$nid {$row['article_title']} ({$row['status']})\n";
    foreach ($list as $c) {
        list(, $fid, $to, $why, $passing) = $c;
        $hit = false;
        foreach ($mentions as $i => $m) {
            $name = is_array($m) ? trim((string) ($m['name'] ?? '')) : trim((string) $m);
            $mid = is_array($m) && !empty($m['facility_id']) ? $follow($m['facility_id'])
                : (($r = kop_resolve_mention_to_facility($name, $index)) !== null ? (int) $r : 0);
            if ($mid !== $fid) continue;
            $hit = true;
            if ($passing) {
                if (!empty($m['passing'])) { echo "  \"$name\" ($fid) already marked in passing\n"; continue; }
                $mentions[$i] = array('name' => $name, 'facility_id' => $fid, 'passing' => true);
                echo "  \"$name\" ($fid) marked in passing" . ($why !== '' ? " ($why)" : '') . "\n";
            } elseif ($to) {
                $mentions[$i] = array('name' => $name, 'facility_id' => $to);
                echo "  \"$name\": $fid -> $to" . ($why !== '' ? " ($why)" : '') . "\n";
            } else {
                unset($mentions[$i]);
                $drop_names[] = strtolower($name);
                echo "  \"$name\" ($fid) taken off" . ($why !== '' ? " ($why)" : '') . "\n";
            }
            $changed = true;
        }
        if (!$hit && $passing) {
            // No mention names it (taken off earlier, or a link made by hand): add one under the record's name.
            $fname = (string) ($index['names'][$fid] ?? '');
            if ($fname === '') { echo "  $fid is no record\n"; continue; }
            $mentions[] = array('name' => $fname, 'facility_id' => $fid, 'passing' => true);
            $changed = true;
            echo "  \"$fname\" ($fid) added, in passing" . ($why !== '' ? " ($why)" : '') . "\n";
        } elseif (!$hit) echo "  no stored mention is $fid; the link row alone " . ($to ? 'moves' : 'comes off') . "\n";
    }
    $mentions = array_values($mentions);
    if ($drop_names && is_array($json) && isset($json['facilities'])) {
        $keep = function ($n) use ($drop_names) {
            $s = is_array($n) ? (string) ($n['name'] ?? '') : (string) $n;
            return !in_array(strtolower(trim($s)), $drop_names, true);
        };
        $json['facilities'] = is_array($json['facilities'])
            ? array_values(array_filter($json['facilities'], $keep))
            : implode("\n", array_filter(preg_split('/\r?\n/', (string) $json['facilities']), $keep));
    }
    $after_mentions = json_encode($mentions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $after_json = is_array($json) && $drop_names ? json_encode($json) : $row['json_data'];
    $links_before = kop_fnl_links($pdo, $nid);
    if (!$apply) { $done++; continue; }

    $pdo->beginTransaction();
    try {
        if ($changed) {
            $pdo->prepare('UPDATE news_submissions SET facilities_mentioned = ?, json_data = ? WHERE id = ?')
                ->execute(array($after_mentions, $after_json, $nid));
        }
        kop_sync_news_facility_links($pdo, (int) $nid, kop_normalize_facility_mentions($after_mentions), 'link-check');
        foreach ($list as $c) {
            if ($c[4]) continue;
            // A link added by hand (primary/related) is not the sync's to prune; a re-pointed one goes on the right
            // record with its own type, also when no stored mention named it (a scan's link).
            if ($c[2]) {
                foreach ($links_before as $l) {
                    if ((int) $l['facility_id'] !== $c[1]) continue;
                    $verb = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
                    $pdo->prepare("$verb INTO news_facility_links (news_id, facility_id, link_type, created_by) VALUES (?, ?, ?, ?)")
                        ->execute(array($nid, $c[2], $l['link_type'], 'link-check'));
                }
            }
            $pdo->prepare('DELETE FROM news_facility_links WHERE news_id = ? AND facility_id = ?')->execute(array($nid, $c[1]));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  FAILED: {$e->getMessage()}\n";
        continue;
    }
    $read->execute(array($nid));
    $now = $read->fetch();
    $read->closeCursor();
    $entry['rows'][$nid] = array(
        'facilities_mentioned' => $row['facilities_mentioned'],
        'json_data' => $row['json_data'],
        'links' => $links_before,
        'after' => $now['facilities_mentioned'],
    );
    $ids = array_map(function ($l) { return (int) $l['facility_id'] . (!empty($l['passing']) ? ' (passing)' : ''); }, kop_fnl_links($pdo, $nid));
    echo '  links now: ' . ($ids ? implode(', ', $ids) : 'none') . "\n";
    $done++;
}

if ($apply && $entry['rows']) {
    $log = get_option(KOP_FNL_LOG);
    $log = is_array($log) ? $log : array();
    $log[] = $entry;
    update_option(KOP_FNL_LOG, $log, false);
    kop_fnl_purge();
    echo "Logged in option " . KOP_FNL_LOG . " (undo apply puts it back)\n";
}
echo "$done article(s) " . ($apply ? 'changed' : 'would change') . "\n";
