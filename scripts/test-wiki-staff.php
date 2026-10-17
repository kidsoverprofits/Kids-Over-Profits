<?php
/**
 * Wiki approval puts the entry's staff on its facility record
 * (api/sync-wiki-facilities.php kop_wiki_merge_submission(), called from
 * kop_resync_after_status('wiki', ...)), cited to the wiki page; a name the
 * record already lists stays as it is; taking the approval back takes them
 * off (kop_wiki_staff_undo()). On an in-memory copy of tmp/prod.sqlite.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-staff.php [--db=tmp/prod.sqlite] [--list]
 *
 * --list prints, for every approved entry with staff, how many would go on
 * its record (what approving all of them again would add).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$root = dirname(__DIR__);
$args = getopt('', array('db::', 'list'));
$mirror = $args['db'] ?? ($root . '/tmp/prod.sqlite');

$fails = 0;
function check($ok, $label, $detail = '') {
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
    if (!$ok) $fails++;
}

define('KOP_DB_CONFIG_LOADED', true);
// The citation's Reddit page comes from inc/wiki-updates.php (js/data/reddit-wiki/live-compare.json).
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');   // the file stops without it
if (!defined('ABSPATH')) define('ABSPATH', dirname(__DIR__) . '/');   // inc/wiki-updates.php loads only under WordPress
if (!function_exists('get_stylesheet_directory')) {
    function get_stylesheet_directory() { return dirname(__DIR__); }
}   // no api/config.php: the copy below is the database
require_once $root . '/inc/facility-store.php';
require_once $root . '/api/sync-wiki-facilities.php';

// ---- The mapping ------------------------------------------------------------------
$e = kop_wiki_staff_entries(array(
    array('name' => ' Jane  Doe ', 'role' => 'Clinical Director', 'bio' => 'is the...', 'previousRoles' => array(array('role' => 'Therapist', 'employer' => 'Provo Canyon School'), array('role' => '', 'employer' => 'Aspen')), 'isFormer' => true),
    array('name' => 'John Roe', 'role' => 'Former Owner', 'isFormer' => true),
    array('name' => '', 'role' => 'Therapist'),
), 'https://www.reddit.com/r/troubledteens/wiki/index/x');
check(count($e) === 2, 'entries with no name are left out');
// The parser's isFormer is its guess from the past tense, not a confirmation (owner, 2026-10-17): not read.
check($e[0]['name'] === 'Jane Doe' && $e[0]['role'] === 'Clinical Director', 'the parser\'s isFormer guess alone adds no "Former"', $e[0]['role']);
check($e[1]['role'] === 'Former Owner', '"Former" is not doubled');
check($e[0]['pastJobs'] === 'Therapist, Provo Canyon School; Aspen', 'previous roles become past jobs', $e[0]['pastJobs']);
check($e[0]['source'] === 'r/troubledteens wiki' && $e[0]['sourceUrl'] === 'https://www.reddit.com/r/troubledteens/wiki/index/x', 'each entry cites the wiki page');
check(!isset($e[0]['bio']), 'the write-up text stays on the wiki');
$f = kop_wiki_staff_entries(array(
    array('name' => 'A B', 'role' => 'reported to feel no remorse for the children whom CEDU abused ([source](https://medium.com/x))'),
    array('name' => 'C D', 'role' => 'co-owned/co-founded by Robert Crist, Larry Carter, and Jeff Smith and others'),
    array('name' => 'E F', 'role' => 'a Primary Therapist & the Transition and Alumni Support Coordinator'),
));
check($f[0]['role'] === '' && $f[1]['role'] === '', 'a sentence of the write-up is not printed as a role', $f[0]['role'] . ' / ' . $f[1]['role']);
check(count($f) === 3, '...the person still goes on the record');
$g = kop_wiki_staff_entries(array(
    array('name' => 'G H', 'role' => 'the Program Director', 'bio' => 'was the Program Director at Ashcreek Ranch Academy from February until July of 2015.'),
    array('name' => 'I J', 'role' => 'Executive Director', 'bio' => 'is the Co-Founder and current Executive Director of Ashcreek Ranch Academy.'),
    array('name' => 'K L', 'role' => 'Founder', 'bio' => '("Doc") was the Founder and Executive Director of Anneewakee.'),
    array('name' => 'M N', 'role' => 'Founders', 'bio' => 'were the Founders and Owners of Ashby Academy.'),
    array('name' => 'O P', 'role' => 'Therapist', 'bio' => 'is currently a Therapist at Eagle Ranch Academy.'),
));
check($g[0]['role'] === 'Former Program Director', '"was the Program Director ... until July of 2015": the write-up gives the end, so Former', $g[0]['role']);
$conf = kop_wiki_staff_entries(array(
    array('name' => 'U V', 'role' => 'Program Director', 'bio' => 'was formerly the Program Director of X.'),
    array('name' => 'W X', 'role' => 'Therapist', 'bio' => 'retired in 2019 after twenty years.'),
    array('name' => 'Y Z', 'role' => 'Director', 'bio' => 'was the Director of X from 2001 to 2008.'),
    array('name' => 'A C', 'role' => 'Director', 'bio' => 'worked as the Director of X.'),
    array('name' => 'B D', 'role' => 'Director', 'bio' => 'served as the Director of X.'),
));
check(array_column($conf, 'role') === array('Former Program Director', 'Former Therapist', 'Former Director', 'Director', 'Director'),
    'Former only when the write-up says it ("formerly", "retired", a year range), never for "worked as" / "served as" alone', implode(' / ', array_column($conf, 'role')));
// The parser's wording is not a title (kop_wiki_staff_title()).
$titles = array(
    array('current Clinical Director', '', 'Clinical Director'),
    array('current Chief Operating', '', 'Chief Operating Officer'),
    array('Co-Founder and current Clinical Director', '', 'Co-Founder and Clinical Director'),
    array('reported to have worked as the Program Director', '', 'Program Director'),
    array('cureently the is the Assistant Program Director', '', 'Assistant Program Director'),
    array('one', 'is one of the Founders and the Chief Clinical Officer of Embark Behavioral Health. Prior to this...', 'Founder, Chief Clinical Officer'),
    array('one', 'is one of the current Executive Directors of Residential Services of Youth Villages.', 'Executive Director'),
    array('one', 'He began his career as a youth pastor. Later he was one of the Executive Directors of X.', ''),
    array('married to Rob Dias', '', ''),
    array('son', 'is the son of Herman Fountain Sr., the founder.', ''),
    array('now working as an Educational Consultant', '', ''),
    array('same position', '', ''),
    array('presently known about her', '', ''),
    array('a drill sergeant', '', 'Drill sergeant'),
    array('Founder', '', 'Founder'),
);
foreach ($titles as $t) {
    $got = kop_wiki_staff_title($t[0], $t[1]);
    check($got === $t[2], 'title: "' . $t[0] . '" -> "' . $t[2] . '"', $got);
}
$x = kop_wiki_staff_entries(array(
    array('name' => 'Q R', 'role' => 'former CEO', 'bio' => 'is the former CEO.'),
    array('name' => 'S T', 'role' => 'one', 'bio' => 'is one of the Founders and former Executive Director of Carlbrook School.'),
));
check($x[0]['role'] === 'Former CEO' && $x[1]['role'] === 'Founder, former Executive Director', '"Former" once, and not before a role that already says which part is past', $x[0]['role'] . ' / ' . $x[1]['role']);
check($g[1]['role'] === 'Executive Director' && $g[4]['role'] === 'Therapist', '"is the ..." / "is currently ..." stay current', $g[1]['role'] . ' / ' . $g[4]['role']);
check($g[2]['role'] === 'Founder' && $g[3]['role'] === 'Founders', '"was the Founder", "were the Founders": past tense alone adds no "Former"', $g[2]['role'] . ' / ' . $g[3]['role']);
$h = kop_wiki_staff_entries(array(array('name' => 'I J', 'role' => 'Executive Director', 'bio' => 'is the Executive Director.')), '', true);
check($h[0]['role'] === 'Executive Director', "a closed program is no confirmation: its staff are not marked Former", $h[0]['role']);
check(kop_wiki_program_closed(array('status' => 'Closed')) && kop_wiki_program_closed(array('endYear' => 2019))
    && kop_wiki_program_closed(array(), '1998 - 2019') && kop_wiki_program_closed(array(), '1998–2019'), 'closed: status, end year, a year range');
check(!kop_wiki_program_closed(array('status' => 'Open'), '1998-present') && !kop_wiki_program_closed(array(), '1998'), 'open: "1998-present", a start year only');

if (!file_exists($mirror)) {
    echo "SKIP the mirror checks: no $mirror\n";
    exit($fails ? 1 : 0);
}

// ---- A copy of the records ----------------------------------------------------------
/** The scratch copy, with the MySQL the writer uses turned into SQLite's. */
class KOP_Wiki_Staff_Test_PDO extends PDO {
    private function fix($sql) {
        $sql = preg_replace('/^\s*SHOW TABLES LIKE (.+)$/i', "SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE $1 ESCAPE '\\'", (string) $sql);
        $sql = preg_replace('/^\s*INSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        // MySQL upserts (location memberships, company links) in SQLite's words.
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            $sql = str_ireplace('ON DUPLICATE KEY UPDATE', 'ON CONFLICT DO UPDATE SET', $sql);
            $sql = preg_replace('/\bVALUES\((\w+)\)/i', 'excluded.$1', $sql);
            $sql = preg_replace('/\bIF\(/i', 'IIF(', $sql);
        }
        return $sql;
    }
    public function prepare(string $query, array $options = array()): PDOStatement|false {
        return parent::prepare($this->fix($query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return $fetchMode === null ? parent::query($this->fix($query)) : parent::query($this->fix($query), $fetchMode, ...$fetchModeArgs);
    }
}
$pdo = new KOP_Wiki_Staff_Test_PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
$pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $mirror) . "?mode=ro' AS prod");
$tables = array('wiki_submissions', 'wiki_master', 'facilities_master', 'facilities_v2');
foreach ($pdo->query("SELECT name FROM prod.sqlite_master WHERE type = 'table' AND name LIKE 'wpdl_kop_%'")->fetchAll(PDO::FETCH_COLUMN) as $t) {
    if (preg_match('/^wpdl_kop_(operators|operator_facilities|facility_locations|facility_identity|migration_state|facility_addresses|addresses)$/', $t)) $tables[] = $t;
}
foreach ($tables as $t) {
    $sql = $pdo->query("SELECT sql FROM prod.sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn();
    if (!$sql) continue;
    $pdo->exec($sql);
    $pdo->exec("INSERT INTO main.\"$t\" SELECT * FROM prod.\"$t\"");
}
$pdo->exec('DETACH DATABASE prod');
// wiki_master (the approved copy per page) is not in the mirror: what the approval writes into it.
$pdo->exec('CREATE TABLE IF NOT EXISTS wiki_master (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, program_name TEXT, city_state TEXT, organization TEXT,
    program_type TEXT, years_active TEXT, json_data TEXT, markdown TEXT, facility_unique_name TEXT, last_updated_by TEXT, updated_at TEXT)');
$GLOBALS['table_prefix'] = 'wpdl_';   // the mirror's WordPress prefix
require_once $root . '/inc/facility-migration.php';
require_once $root . '/inc/facility-v2-writer.php';
$prefix = kop_v2_detect_prefix($pdo);
check($prefix !== null && kop_v2_writes_active($pdo, $prefix), 'the copy writes facilities_v2', var_export($prefix, true));

$staff_of = function ($unique) use ($pdo, $prefix) {
    $row = kop_v2_pdo_master_row_by_name($pdo, $prefix, $unique);
    $p = $row ? json_decode($row['json_data'], true) : array();
    $f = $p['data']['facility'] ?? ($p['data']['facilities'][0] ?? array());
    return (array) ($f['staff']['notableStaff'] ?? array());
};

$master = function (array $r) use ($pdo) {
    // The approval writes wiki_master first: the row the merge reads.
    $j = json_decode((string) $r['json_data'], true);
    $slug = !empty($j['slug']) ? $j['slug'] : strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $r['program_name'])));
    $pdo->prepare('DELETE FROM wiki_master WHERE slug = ?')->execute(array($slug));
    $pdo->prepare('INSERT INTO wiki_master (slug, program_name, json_data) VALUES (?, ?, ?)')->execute(array($slug, $r['program_name'], $r['json_data']));
};
$rows = $pdo->query("SELECT id, program_name, facility_unique_name, json_data FROM wiki_submissions
    WHERE status IN ('approved', 'published') AND json_data LIKE '%staffMembers%' ORDER BY id")->fetchAll();

// ---- One entry: approve, again, take back ---------------------------------------------
$pick = null;
foreach ($rows as $r) {
    $row = $r['facility_unique_name'] ? kop_v2_pdo_master_row_by_name($pdo, $prefix, $r['facility_unique_name']) : null;
    $p = $row ? json_decode($row['json_data'], true) : null;
    if (empty($p['__facility_ref'])) continue;
    // One whose staff are not all on the record yet (the live site's approvals already put most of them there).
    $f = $p['data']['facility'] ?? array();
    $on = array();
    foreach (array('administrator', 'notableStaff') as $l) foreach ((array) ($f['staff'][$l] ?? array()) as $e) $on[kop_wiki_staff_fold(is_array($e) ? ($e['name'] ?? '') : $e)] = true;
    foreach ((array) (json_decode((string) $r['json_data'], true)['staffMembers'] ?? array()) as $s) {
        if (is_array($s) && trim((string) ($s['name'] ?? '')) !== '' && !isset($on[kop_wiki_staff_fold($s['name'])])) { $pick = $r; break 2; }
    }
}
if ($pick) {
    $id = (int) $pick['id'];
    $unique = $pick['facility_unique_name'];
    $master($pick);
    $before = $staff_of($unique);
    $res = kop_wiki_merge_submission($pdo, $id);
    $after = $staff_of($unique);
    // What this approval added: the entries after it that were not there before (earlier wiki staff may be).
    $added = array_values(array_slice($after, count($before)));
    check(!empty($res['staff_added']) && count($after) === count($before) + $res['staff_added'], "approving #$id ({$pick['program_name']}) puts its staff on the record", json_encode($res));
    check($added && $added[0]['source'] === 'r/troubledteens wiki', 'each one cites the wiki', json_encode($added[0] ?? null));
    check($added && strpos((string) ($added[0]['sourceUrl'] ?? ''), 'https://www.reddit.com/r/troubledteens/wiki/') === 0, '...linked to its Reddit page', (string) ($added[0]['sourceUrl'] ?? ''));
    $fold = function ($l) { return array_map(function ($s) { return kop_wiki_staff_fold($s['name'] ?? ''); }, $l); };
    check(count(array_unique($fold($added))) === count($added) && !array_intersect($fold($added), $fold($before)), 'none of them was on the record already, none twice');
    $fid = (int) $pdo->query('SELECT id FROM facilities_v2 WHERE unique_name = ' . $pdo->quote($unique))->fetchColumn();
    $doc = kop_facility_load($fid, array('pdo' => $pdo, 'prefix' => $prefix));
    $errors = array_filter(kop_facility_validate($doc['doc'] ?? array()), function ($v) { return $v['severity'] === 'error'; });
    check($doc && !$errors, 'the record still validates', json_encode(array_slice(array_values($errors), 0, 2)));
    $again = kop_wiki_merge_submission($pdo, $id);
    check(count($staff_of($unique)) === count($after), 'approving it again adds nothing', json_encode($again));
    $removed = kop_wiki_staff_undo($pdo, $id);
    $back = $staff_of($unique);
    check($removed === (int) $res['staff_added'] && $back == $before, 'taking the approval back takes them off, the rest as it was', "$removed removed");
    check(kop_wiki_staff_undo($pdo, $id) === 0, 'a second undo does nothing');
} else {
    echo "SKIP approve/undo: no approved entry with staff linked to one facility\n";
}

// ---- Staff approved before the rule: the one-time "Former" pass, with the live log ----------
$pdo->exec("ATTACH DATABASE 'file:" . str_replace('\\', '/', $mirror) . "?mode=ro' AS prod");
$raw = $pdo->query("SELECT option_value FROM prod.wpdl_options WHERE option_name = 'kop_wiki_staff_log'")->fetchColumn();
$pdo->exec('DETACH DATABASE prod');
$live_log = $raw ? @unserialize($raw) : false;
if (is_array($live_log) && $live_log) {
    $GLOBALS['kop_wiki_staff_log'] = $live_log;
    $dry = kop_wiki_staff_former_pass($pdo, false);
    check(kop_wiki_staff_log() === $live_log, 'the dry run changes neither the log nor (below) the records');
    $changes = kop_wiki_staff_former_pass($pdo, true);
    check(count($changes) === count($dry) && count($changes) > 0, count($changes) . ' wiki staff roles rewritten', count($dry) . ' in the dry run');
    $by = array();
    foreach ($changes as $c) $by[$c['why']] = ($by[$c['why']] ?? 0) + 1;
    echo '  ' . json_encode($by) . "\n";
    $ok = true;
    $seen = array();
    foreach ($changes as $c) {
        $hit = false;
        foreach ($staff_of($c['record']) as $e) if (($e['name'] ?? '') === $c['name'] && ($e['role'] ?? '') === $c['to']) $hit = true;
        if (!$hit || preg_match('/^(?:Former )?(?:one|current|a|the|married|son|reported)\b/i', $c['to'])) { $ok = false; echo "  bad: {$c['name']}: {$c['to']}\n"; break; }
        if (isset($args['list'])) echo "  {$c['record']}: {$c['name']}: {$c['from']} -> {$c['to']} ({$c['why']})\n";
    }
    check($ok, 'each is on its record with its new role, none of the parser\'s wording left');
    check(count(kop_wiki_staff_former_pass($pdo, false)) === 0, 'running it again changes nothing');
    // Taking an approval back still finds its renamed entries (the log holds the new role).
    $sid = $changes[0]['submission'];
    $rec = $changes[0]['record'];
    $renamed = array_filter($changes, function ($c) use ($sid) { return $c['submission'] === $sid; });
    kop_wiki_staff_undo($pdo, $sid);
    $still = 0;
    foreach ($staff_of($rec) as $e) foreach ($renamed as $c) if (($e['name'] ?? '') === $c['name'] && ($e['role'] ?? '') === $c['to']) $still++;
    check($still === 0, "taking approval #$sid back still takes off the ones marked Former", count($renamed) . " marked, $still left");
    // The pass's own undo puts every other role back as it was.
    $back = kop_wiki_staff_former_pass($pdo, true, true);
    $want = array();
    foreach ($changes as $c) if ($c['submission'] !== $sid) $want[] = $c['record'] . '|' . $c['name'] . '|' . $c['from'];
    $got = array_map(function ($c) { return $c['record'] . '|' . $c['name'] . '|' . $c['to']; }, $back);
    sort($want);
    sort($got);
    check($got === $want, 'undo puts every role back as it was', count($back) . ' put back of ' . count($want));
    check(count(kop_wiki_staff_former_pass($pdo, false, true)) === 0, '...and leaves nothing to put back');
} else {
    echo "SKIP the Former pass: no kop_wiki_staff_log in the mirror\n";
}

// ---- Every approved entry: what approving them all again would put on the records ---------
$added = 0;
$entries = 0;
$why = array();
foreach ($rows as $r) {
    $master($r);
    $res = kop_wiki_merge_submission($pdo, (int) $r['id']);
    $n = (int) ($res['staff_added'] ?? 0);
    $added += $n;
    if ($n) $entries++;
    else $why[preg_replace("/'[^']*'/", "'..'", (string) $res['reason'])] = ($why[preg_replace("/'[^']*'/", "'..'", (string) $res['reason'])] ?? 0) + 1;
    if (isset($args['list']) && $n) echo "  #{$r['id']} {$r['program_name']}: $n\n";
}
echo count($rows) . " approved entries with staff: $entries would add $added staff to their records\n";
arsort($why);
foreach (array_slice($why, 0, 6, true) as $k => $v) echo "  $v nothing added: $k\n";

echo $fails ? "\n$fails FAILED\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
