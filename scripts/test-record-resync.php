<?php
/**
 * api/lib-record-resync.php: after a news article or lawsuit changes, its
 * facility links, case coverage and story groups follow it, whichever path
 * wrote it (docs/DATA-FLOWS.md). On an in-memory copy of the records and
 * facility tables from tmp/prod.sqlite:
 *
 *   - the one-time step keeps every link the sync could not give again
 *     (kop_resync_keep_underived()), so a resync of every row then takes
 *     no link off any page;
 *   - reject -> case links gone, Undo back to approved -> the same links again;
 *   - kop_records_deleted() leaves no link row pointing at a deleted row;
 *   - kop_news_fold_links() moves a dropped copy's ties to the kept one;
 *   - every write path named in DATA-FLOWS.md calls the helper.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-record-resync.php [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$root = dirname(__DIR__);
$args = getopt('', array('db::'));
$mirror = $args['db'] ?? ($root . '/tmp/prod.sqlite');

$fails = 0;
function check($ok, $label, $detail = '') {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fails++;
}

// ---- Every write path calls the helper (static) ------------------------------
$paths = array(
    'api/manage-submissions.php'          => array('kop_resync_after_status', 'kop_records_deleted', 'kop_resync_news', 'kop_resync_lawsuit'),
    'api/lib-publish-approved.php'        => array('kop_resync_lawsuit'),
    'inc/review-inbox/publish-approved.php' => array('kop_record_changed'),
    'inc/review-inbox/native.php'         => array('kop_rinbox_native_resync'),
    'inc/review-destinations.php'         => array('kop_rdest_resync', 'kop_records_deleted'),
    'inc/drive-docs.php'                  => array('kop_resync_after_status', 'kop_resync_news', 'kop_records_deleted'),
    'api/data-manager-extra.php'          => array('kop_resync_news', 'kop_resync_lawsuit', 'kop_record_changed'),
    'api/manage-duplicate-articles.php'   => array('kop_news_fold_links', 'kop_records_deleted'),
    'api/lib-news-archive.php'            => array('kop_resync_news'),
    'api/lib-news-post-import.php'        => array('kop_resync_news'),
    'api/lib-record-enrich.php'           => array('kop_resync_lawsuit'),
    'inc/lawsuit-updates.php'             => array('kop_resync_lawsuit'),
    'inc/admin.php'                       => array('kop_resync_lawsuit', 'kop_records_deleted'),
    'inc/facility-store.php'              => array("kop_record_changed('facility'"),
);
foreach ($paths as $file => $calls) {
    $src = (string) file_get_contents($root . '/' . $file);
    $missing = array_values(array_filter($calls, function ($c) use ($src) { return strpos($src, $c) === false; }));
    check(!$missing, "$file calls the resync helper", $missing ? 'missing ' . implode(', ', $missing) : '');
}

if (!file_exists($mirror)) {
    echo "SKIP the mirror checks: no $mirror (python ../kop-pipeline/scripts/sync-prod-sqlite.py)\n";
    exit($fails ? 1 : 0);
}

/** The scratch copy, with the MySQL words the syncs use turned into SQLite's. */
class KOP_Resync_Test_PDO extends PDO {
    private function fix($sql) {
        $sql = preg_replace('/^\s*INSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', (string) $sql);
        // The case-link writer's upsert (api/lawsuit-news-links.php), in SQLite's words.
        $sql = str_replace("ON DUPLICATE KEY UPDATE match_reason = IF(link_type = 'auto', VALUES(match_reason), match_reason)",
            "ON CONFLICT DO UPDATE SET match_reason = CASE WHEN link_type = 'auto' THEN excluded.match_reason ELSE match_reason END", $sql);
        return $sql;
    }
    public function prepare(string $query, array $options = array()): PDOStatement|false {
        return parent::prepare($this->fix($query), $options);
    }
    public function rawExec(string $statement) {
        return parent::exec($statement);
    }
    public function exec(string $statement): int|false {
        // The link tables exist already; their MySQL CREATE TABLE is not SQLite's.
        if (preg_match('/^\s*(CREATE TABLE IF NOT EXISTS|ALTER TABLE)/i', $statement)) return 0;
        return parent::exec($this->fix($statement));
    }
}

$pdo = new KOP_Resync_Test_PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $mirror) . "?mode=ro' AS prod");
$tables = array('news_submissions', 'lawsuits', 'legislation', 'news_facility_links', 'lawsuit_facility_links', 'lawsuit_news_links',
    'legislation_news_links', 'news_featured', 'news_story_arcs', 'journalists', 'journalist_articles', 'facilities_master', 'facilities_v2');
foreach ($pdo->query("SELECT name FROM prod.sqlite_master WHERE type = 'table' AND name LIKE 'wpdl_kop_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (preg_match('/^wpdl_kop_(operators|operator_facilities|operator_links|facility_locations|facility_identity|migration_state)$/', $t)) $tables[] = $t;
}
foreach ($tables as $t) {
    $sql = $pdo->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn();
    if (!$sql) continue;
    $pdo->rawExec($sql);   // not the shim's exec: this CREATE must run
    $pdo->query("INSERT INTO main.\"$t\" SELECT * FROM prod.\"$t\"");
}
$pdo->query('DETACH DATABASE prod');
// Columns newer than the mirror (api/news-story-groups.php adds them on the server).
foreach (array('story_group_id INTEGER', 'story_group_manual INTEGER DEFAULT 0', 'story_arc_id INTEGER') as $col) {
    try { $pdo->rawExec('ALTER TABLE news_submissions ADD COLUMN ' . $col); } catch (Throwable $e) { /* already there */ }
}

$GLOBALS['table_prefix'] = 'wpdl_';   // the v2 facility and operator tables (kop_v2_detect_prefix())
require $root . '/api/lib-record-resync.php';

$links = function ($table, $col, $id) use ($pdo) {
    $st = $pdo->prepare("SELECT * FROM $table WHERE $col = ? ORDER BY 1, 2");
    $st->execute(array($id));
    return $st->fetchAll();
};
$all_links = function () use ($pdo) {
    $out = array();
    foreach (array('news_facility_links' => 'news_id, facility_id', 'lawsuit_facility_links' => 'lawsuit_id, facility_id') as $t => $k) {
        foreach ($pdo->query("SELECT $k FROM $t")->fetchAll(PDO::FETCH_NUM) as $r) $out[$t . ':' . $r[0] . ':' . $r[1]] = true;
    }
    return $out;
};

// ---- Keep what the sync cannot give again ------------------------------------
$before = $all_links();
$kept = kop_resync_keep_underived($pdo, true);
echo 'retyped to related: ' . json_encode($kept) . "\n";
check(array_sum($kept) > 0, 'the one-time step retypes the links the sync could not give again');
check(!array_diff_key($before, $all_links()) && !array_diff_key($all_links(), $before), 'it adds and removes no link');

$live_news = $pdo->query("SELECT id FROM news_submissions WHERE status IN ('approved', 'published')")->fetchAll(PDO::FETCH_COLUMN);
$cases = $pdo->query('SELECT id FROM lawsuits')->fetchAll(PDO::FETCH_COLUMN);
$t0 = microtime(true);
$r1 = kop_resync_lawsuit($pdo, $cases, 'test');
$r2 = kop_resync_news($pdo, $live_news, 'test');
$secs = round(microtime(true) - $t0, 1);
$after = $all_links();
// A company's name used to land in news_facility_links under its operator id,
// where no page reads it; the sync now moves those to kop_operator_links.
$is_operator = array_flip(array_map('intval', $pdo->query('SELECT id FROM wpdl_kop_operators')->fetchAll(PDO::FETCH_COLUMN)));
$moved_off = array();
$lost = array();
foreach (array_keys(array_diff_key($before, $after)) as $k) {
    list($t, $rid, $fid) = explode(':', $k);
    if (isset($is_operator[(int) $fid])) $moved_off[] = array($t === 'news_facility_links' ? 'news' : 'lawsuit', (int) $rid, (int) $fid);
    else $lost[] = $k;
}
$on_op = $pdo->prepare('SELECT 1 FROM wpdl_kop_operator_links WHERE link_kind = ? AND link_id = ? AND operator_id = ?');
$not_moved = array_filter($moved_off, function ($m) use ($on_op) { $on_op->execute($m); return !$on_op->fetchColumn(); });
echo count($moved_off) . " operator-id links taken out of the facility link tables\n";
check(!$not_moved, "each of them is on its operator's page (kop_operator_links)", json_encode(array_slice(array_values($not_moved), 0, 3)));
$left = $pdo->query("SELECT COUNT(*) FROM news_facility_links l JOIN wpdl_kop_operators o ON o.id = l.facility_id JOIN news_submissions n ON n.id = l.news_id
    WHERE l.link_type = 'mentioned' AND n.status IN ('approved', 'published')")->fetchColumn();
check(!(int) $left, 'no live article keeps a mentioned link to an operator id', "$left left");
$gained = count(array_diff_key($after, $before));
check(!$lost, 'resyncing every case and live article afterwards takes no facility link off a page', implode(', ', array_slice($lost, 0, 5)));
echo count($cases) . ' cases, ' . count($live_news) . " live articles resynced in {$secs}s; $gained links added (mentions that resolve now)\n";
$failed = array_filter($r1 + $r2);
check(!$failed, 'no step failed', json_encode(array_slice($failed, 0, 3, true)));

// ---- Reject, then Undo -----------------------------------------------------------
$nid = (int) $pdo->query("SELECT l.news_id FROM lawsuit_news_links l JOIN news_submissions n ON n.id = l.news_id
    WHERE l.link_type = 'auto' AND n.status IN ('approved', 'published') LIMIT 1")->fetchColumn();
if ($nid) {
    $status = $pdo->query("SELECT status FROM news_submissions WHERE id = $nid")->fetchColumn();
    $cases_before = $links('lawsuit_news_links', 'news_id', $nid);
    $pdo->prepare("UPDATE news_submissions SET status = 'rejected' WHERE id = ?")->execute(array($nid));
    kop_resync_after_status($pdo, 'news', array($nid), 'rejected');
    $auto_left = array_filter($links('lawsuit_news_links', 'news_id', $nid), function ($l) { return $l['link_type'] === 'auto'; });
    check(!$auto_left, "a rejected article (#$nid) leaves the case cards");
    $pdo->prepare('UPDATE news_submissions SET status = ? WHERE id = ?')->execute(array($status, $nid));
    kop_resync_after_status($pdo, 'news', array($nid), $status);
    $strip = function ($rows) { return array_map(function ($r) { return array($r['lawsuit_id'], $r['link_type']); }, $rows); };
    check($strip($links('lawsuit_news_links', 'news_id', $nid)) == $strip($cases_before), 'Undo back to ' . $status . ' brings the same case links back');
} else {
    echo "SKIP reject/undo: no article with an automatic case link on the mirror\n";
}

// ---- Deletes leave no link rows ---------------------------------------------------
$lid = (int) $pdo->query('SELECT lawsuit_id FROM lawsuit_facility_links LIMIT 1')->fetchColumn();
$pdo->prepare('DELETE FROM lawsuits WHERE id = ?')->execute(array($lid));
kop_records_deleted($pdo, 'lawsuit', array($lid));
check(!$links('lawsuit_facility_links', 'lawsuit_id', $lid) && !$links('lawsuit_news_links', 'lawsuit_id', $lid), "a deleted case (#$lid) leaves no facility or news link");
$did = (int) $pdo->query('SELECT news_id FROM news_facility_links LIMIT 1')->fetchColumn();
$pdo->prepare('DELETE FROM news_submissions WHERE id = ?')->execute(array($did));
kop_records_deleted($pdo, 'news', array($did));
check(!$links('news_facility_links', 'news_id', $did) && !$links('lawsuit_news_links', 'news_id', $did), "a deleted article (#$did) leaves no link");

// ---- Duplicate articles: ties move to the kept copy -------------------------------
$pair = $pdo->query("SELECT a.id AS keep, b.id AS dropped FROM news_submissions a JOIN news_submissions b ON b.id > a.id
    WHERE a.status IN ('approved', 'published') AND b.status IN ('approved', 'published') LIMIT 1")->fetch();
if ($pair) {
    $keep = (int) $pair['keep'];
    $drop = (int) $pair['dropped'];
    $fid = (int) $pdo->query('SELECT id FROM facilities_v2 ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare("DELETE FROM news_facility_links WHERE news_id IN (?, ?) AND facility_id = ?")->execute(array($keep, $drop, $fid));
    $pdo->prepare("INSERT INTO news_facility_links (news_id, facility_id, link_type, created_by) VALUES (?, ?, 'primary', 'test')")->execute(array($drop, $fid));
    $pdo->prepare("UPDATE news_submissions SET status = 'deleted' WHERE id = ?")->execute(array($drop));
    kop_news_fold_links($pdo, $keep, array($drop));
    $moved = array_filter($links('news_facility_links', 'news_id', $keep), function ($l) use ($fid) { return (int) $l['facility_id'] === $fid && $l['link_type'] === 'primary'; });
    check((bool) $moved, "a hand-made facility link on a dropped copy (#$drop) moves to the kept one (#$keep)");
}

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
