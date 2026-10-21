<?php
/**
 * Offline check of the first move of young adult programs out of the
 * facility data (kop_ya_maybe_migrate() in inc/young-adult-programs.php), and
 * of the second pass (kop_ya_maybe_link_and_move(): the moved programs'
 * folders, and the second copies of three of them moved in).
 *
 *   php scripts/test-young-adult-move.php [--db=tmp/prod.sqlite]
 *
 * The facility tables and wpdl_kop_woodbury_facts are copied from the
 * production mirror into an in-memory database, so the move really runs
 * (facility rows out, programs in, Woodbury items re-pointed) without
 * touching the mirror. Sync the mirror first so it has today's records.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$args = getopt('', array('db::'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('DAY_IN_SECONDS', 86400);
$GLOBALS['kop_test_options'] = array();

function get_stylesheet_directory() { return dirname(__DIR__); }
function home_url($p = '') { return 'https://kidsoverprofits.org' . $p; }
function admin_url($p = '') { return 'https://kidsoverprofits.org/wp-admin/' . $p; }
function get_option($k, $d = false) { return array_key_exists($k, $GLOBALS['kop_test_options']) ? $GLOBALS['kop_test_options'][$k] : $d; }
function update_option($k, $v, $a = null) { $GLOBALS['kop_test_options'][$k] = $v; return true; }
function add_action() {}
function add_filter() {}
function do_action() {}
function apply_filters($t, $v) { return $v; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
function sanitize_title($s) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $s)), '-'); }
function remove_accents($s) { return (string) $s; }
function current_time($t = 'mysql', $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function kop_seed_pdo() { return $GLOBALS['pdo']; }
function kop_v2_with_write_lock(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Just enough of $wpdb, over the same in-memory database. */
class KOP_YA_Test_Wpdb {
    public $prefix = 'wpdl_';
    public $last_error = '';
    private $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }
    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $pdo = $this->pdo;
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args, $pdo) {
            $v = $args[$i++];
            return $m[0] === '%d' ? (string) (int) $v : $pdo->quote((string) $v);
        }, $sql);
    }
    public function get_results($sql, $o = ARRAY_A) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    public function get_col($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    public function get_var($sql) { $v = $this->pdo->query($sql)->fetchColumn(); return $v === false ? null : $v; }
    public function query($sql) { return $this->pdo->exec(preg_replace('/^INSERT IGNORE /i', 'INSERT OR IGNORE ', $sql)); }
    public function update($table, array $data, array $where) {
        $set = implode(', ', array_map(function ($c) { return "$c = ?"; }, array_keys($data)));
        $w = implode(' AND ', array_map(function ($c) { return "$c = ?"; }, array_keys($where)));
        $s = $this->pdo->prepare("UPDATE $table SET $set WHERE $w");
        $s->execute(array_merge(array_values($data), array_values($where)));
        return $s->rowCount();
    }
}

/* ---- A writable copy of the tables involved ----------------------------- */

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($mirror) . ' AS m');
foreach (array('facilities_v2', 'wpdl_kop_facility_locations', 'wpdl_kop_operator_facilities', 'wpdl_kop_facility_identity',
    'wpdl_kop_facility_addresses', 'wpdl_kop_woodbury_facts', 'news_facility_links', 'news_facility_candidates',
    'lawsuit_facility_links', 'facility_closure_reports', 'news_submissions', 'wpdl_fbv', 'wpdl_kop_folder_links') as $t) {
    $pdo->exec("CREATE TABLE $t AS SELECT * FROM m.$t");
}
// Once the move has run on the site the mirror no longer has the seven
// records; the checks then look at what the real move left (its seven
// programs and its log) instead of running it again.
$site_log = $pdo->query("SELECT option_value FROM m.wpdl_options WHERE option_name = 'kop_ya_migration_log'")->fetchColumn();
$site_log = $site_log ? @unserialize($site_log) : null;
$site_moved = is_array($site_log) && !empty($site_log['lines']);
$site_programs = $site_moved ? $pdo->query("SELECT * FROM m.young_adult_programs WHERE id <= 7 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) : array();
$pdo->exec('DETACH DATABASE m');
// Version 3 of the Woodbury Facts table (dbDelta adds these on the site; a
// mirror synced since has them already).
$wbf_cols = array_column($pdo->query("PRAGMA table_info(wpdl_kop_woodbury_facts)")->fetchAll(PDO::FETCH_ASSOC), 'name');
if (!in_array('ya', $wbf_cols, true)) {
    $pdo->exec("ALTER TABLE wpdl_kop_woodbury_facts ADD COLUMN ya INTEGER NOT NULL DEFAULT 0");
}
if (!in_array('ya_why', $wbf_cols, true)) {
    $pdo->exec("ALTER TABLE wpdl_kop_woodbury_facts ADD COLUMN ya_why TEXT");
}
update_option('kop_woodbury_facts_db', '3');
// SQLite form of the table kop_ya_install() makes in MySQL.
$pdo->exec("CREATE TABLE young_adult_programs (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL UNIQUE,
    other_names TEXT, city TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', ages TEXT NOT NULL DEFAULT '',
    program_type TEXT NOT NULL DEFAULT '', run_by TEXT NOT NULL DEFAULT '', opened INTEGER, closed INTEGER, status TEXT NOT NULL DEFAULT 'Unknown',
    notes TEXT, links TEXT, facts TEXT, review TEXT NOT NULL DEFAULT 'approved', source TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, document_folder_id INTEGER)");
// The folder links table exists (copied above); the MySQL install is not run here.
function kop_folder_links_install() {}
$GLOBALS['pdo'] = $pdo;
$GLOBALS['wpdb'] = new KOP_YA_Test_Wpdb($pdo);

require_once dirname(__DIR__) . '/inc/facility-store.php';
require_once dirname(__DIR__) . '/inc/facility-migration.php';
require_once dirname(__DIR__) . '/inc/young-adult-programs.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
update_option('kop_ya_db', KOP_YA_DB_VERSION);

$failed = 0;
function check($ok, $what) {
    global $failed;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . "\n";
    if (!$ok) {
        $failed++;
    }
}
function rows(PDO $pdo, $sql, array $p = array()) {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return (int) $s->fetchColumn();
}

/* ---- Before ----------------------------------------------------------------- */

$ids = array_keys(kop_ya_first_moves());
$in = implode(',', $ids);
if ($site_moved) {
    echo 'The move ran on the site ' . gmdate('Y-m-d', (int) $site_log['time']) . "; checking what it left\n";
    foreach ($site_programs as $row) {
        $cols = array_keys($row);
        $pdo->prepare('INSERT INTO young_adult_programs (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
            ->execute(array_values($row));
    }
    update_option('kop_ya_migration_log', $site_log);
    update_option('kop_ya_migrated', '1');
    $log = $site_log;
    check(count(preg_grep('/^Moved facility record/', $log['lines'])) === 7, 'all seven moved');
    foreach ($ids as $fid) {
        $left = rows($pdo, 'SELECT COUNT(*) FROM facilities_v2 WHERE id = ?', array($fid))
            + rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_facility_locations WHERE facility_id = ?', array($fid))
            + rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_facility_identity WHERE facility_id = ?', array($fid))
            + rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE facility_id = ?', array($fid));
        check($left === 0, "#$fid is out of the facility tables");
    }
    $progs = kop_ya_all($pdo, 'approved');
    check(count($progs) === 7, 'seven young adult programs, listed');
    $byname = array();
    foreach ($progs as $p) {
        $byname[$p['name_key']] = $p;
    }
    $fulshear = $byname[kop_ya_key('Fulshear Treatment to Transition – The Ranch')] ?? array('facts' => '[]', 'notes' => '');
    $f = array_values(array_filter(kop_ya_facts($fulshear), function ($x) { return strpos($x['label'], 'Operator: InnerChange') === 0; }));
    check($f && $f[0]['cites'][0]['label'] === 'July 2009' && (int) $f[0]['cites'][0]['page'] === 7, 'a cited note became a fact citing its page (Fulshear: Operator: InnerChange, July 2009 p. 7)');
    check(rows($pdo, "SELECT COUNT(*) FROM wpdl_kop_woodbury_facts WHERE applied_fid IN ($in) OR (status IN ('pending','gone') AND facility_id IN ($in))") === 0,
        'no Woodbury item still points at a moved record');
    update_option('kop_ya_migration_log', null);
    kop_ya_maybe_migrate();
    check(get_option('kop_ya_migration_log') === null, 'the move runs only once');
    ob_start();
    kop_ya_render_public();
    $html = ob_get_clean();
    check(substr_count($html, 'class="kop-ya-program"') === 7, 'seven programs on the page');
    check(strpos($html, 'Woodbury Reports, July 2009, p. 7') !== false, 'with their Woodbury sources');
    kop_ya_test_second_pass($pdo);
    echo $failed ? "\n$failed failed\n" : "\nAll checks passed\n";
    exit($failed ? 1 : 0);
}

/** kop_ya_maybe_link_and_move() on the copy: the folders, then the second copies moved in. */
function kop_ya_test_second_pass(PDO $pdo) {
    echo "Folders and second copies (kop_ya_maybe_link_and_move)\n";
    $twins = kop_ya_twin_moves();
    $there = array();
    foreach ($twins as $fid => $t) {
        $there[$fid] = rows($pdo, 'SELECT COUNT(*) FROM facilities_v2 WHERE id = ?', array($fid)) > 0;
    }
    if (!array_filter($there)) {
        echo "  (the mirror has none of the second copies: the pass ran on the site already)\n";
        return;
    }
    $programs = rows($pdo, 'SELECT COUNT(*) FROM young_adult_programs');
    $total = rows($pdo, 'SELECT COUNT(*) FROM facilities_v2');
    $article = $pdo->query("SELECT n.id, n.article_url, n.status FROM news_facility_links l JOIN news_submissions n ON n.id = l.news_id
        WHERE l.facility_id = 10612 AND n.status <> 'deleted' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    kop_ya_maybe_link_and_move();
    $log = get_option('kop_ya_twins_log');
    echo '       ' . implode("\n       ", (array) ($log['lines'] ?? array())) . "\n";
    check(get_option('kop_ya_twins_done') === '1', 'marked done');
    foreach (kop_ya_folder_links() as $yid => $link) {
        $live = rows($pdo, 'SELECT COUNT(*) FROM wpdl_fbv WHERE id = ?', array($link[1])) > 0;
        $p = kop_ya_get($pdo, $yid);
        check(!$live || (int) $p['document_folder_id'] === (int) $link[1], "{$link[0]} has its facility-era folder {$link[1]}");
    }
    foreach ($twins as $fid => $t) {
        if (!$there[$fid]) continue;
        check(rows($pdo, 'SELECT COUNT(*) FROM facilities_v2 WHERE id = ?', array($fid)) === 0
            && rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_facility_locations WHERE facility_id = ?', array($fid)) === 0
            && rows($pdo, 'SELECT COUNT(*) FROM news_facility_links WHERE facility_id = ?', array($fid)) === 0, "#$fid ($t[0]) is out of the facility tables");
        check((int) (kop_ya_moved_from_map()[$fid]['id'] ?? 0) === $t[2], "  and its Woodbury and Fornits items find program $t[2]");
    }
    check(rows($pdo, 'SELECT COUNT(*) FROM young_adult_programs') === $programs, 'no new program: each copy joined its program');
    check(rows($pdo, 'SELECT COUNT(*) FROM facilities_v2') === $total - count(array_filter($there)), 'every other facility is untouched');
    $fulshear = kop_ya_get($pdo, 4);
    check(in_array('Fulshear Treatment to Transition', kop_ya_lines($fulshear['other_names']), true), 'Fulshear: the copy\'s name is one of the program\'s other names');
    if ($article) {
        $urls = array_column(kop_ya_parse_links($fulshear['links']), 1);
        check(in_array(trim($article['article_url']), $urls, true), 'Fulshear: its article is a link under "More about this program"');
        check(rows($pdo, 'SELECT COUNT(*) FROM news_submissions WHERE id = ? AND status = ?', array($article['id'], $article['status'])) === 1, '  and the article itself is unchanged');
    }
    if ($there[10612] && rows($pdo, 'SELECT COUNT(*) FROM wpdl_fbv WHERE id = 4162') > 0) {
        check(rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_folder_links WHERE folder_a = 4162 AND folder_b = 4163') === 1, 'Fulshear: the copy\'s folder 4162 is linked to the program\'s 4163');
    }
    update_option('kop_ya_twins_log', null);
    kop_ya_maybe_link_and_move();
    check(get_option('kop_ya_twins_log') === null, 'the pass runs only once');
}
$docs = array();
foreach ($pdo->query("SELECT id, json_data FROM facilities_v2 WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $docs[(int) $r['id']] = json_decode($r['json_data'], true);
}
echo "Before\n";
check(count($docs) === 7, 'the mirror has all seven records');
foreach ($docs as $fid => $d) {
    check((int) ($d['facilityDetails']['ageRange']['min'] ?? 0) >= 17, "#$fid ages start at 17 or more in the mirror");
}
$total = rows($pdo, 'SELECT COUNT(*) FROM facilities_v2');
$applied = $pdo->query("SELECT pkey, applied_fid, op FROM wpdl_kop_woodbury_facts WHERE status = 'applied' AND applied_fid IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
$waiting = rows($pdo, "SELECT COUNT(*) FROM wpdl_kop_woodbury_facts WHERE status IN ('pending','gone') AND facility_id IN ($in)");
printf("       %d Woodbury items added to them, %d waiting\n", count($applied), $waiting);

/* ---- The move --------------------------------------------------------------- */

echo "The move\n";
kop_ya_maybe_migrate();
$log = get_option('kop_ya_migration_log');
echo '       ' . implode("\n       ", $log['lines']) . "\n";
check(get_option('kop_ya_migrated') === '1', 'marked done');
check(count(preg_grep('/^Moved facility record/', $log['lines'])) === 7, 'all seven moved');
foreach ($ids as $fid) {
    $left = rows($pdo, 'SELECT COUNT(*) FROM facilities_v2 WHERE id = ?', array($fid))
        + rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_facility_locations WHERE facility_id = ?', array($fid))
        + rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_facility_identity WHERE facility_id = ?', array($fid))
        + rows($pdo, 'SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE facility_id = ?', array($fid));
    check($left === 0, "#$fid is out of the facility tables");
}
check(rows($pdo, 'SELECT COUNT(*) FROM facilities_v2') === $total - 7, 'every other facility is untouched (' . ($total - 7) . ' left)');

echo "The programs\n";
$progs = kop_ya_all($pdo, 'approved');
check(count($progs) === 7, 'seven young adult programs, listed');
$byname = array();
foreach ($progs as $p) {
    $byname[$p['name_key']] = $p;
}
foreach ($docs as $fid => $d) {
    $p = $byname[kop_ya_key($d['identification']['name'])] ?? null;
    if (!$p) {
        check(false, "#$fid became a program");
        continue;
    }
    $ar = $d['facilityDetails']['ageRange'];
    $staff = count((array) ($d['staff']['administrator'] ?? array())) + count((array) ($d['staff']['notableStaff'] ?? array()));
    $facts = kop_ya_facts($p);
    $staff_facts = count(array_filter($facts, function ($f) { return $f['group'] === 'staff'; }));
    check($p['ages'] === $ar['min'] . '-' . $ar['max'] && $p['city'] === (string) ($d['location']['city'] ?? '') && $p['state'] === (string) ($d['location']['state'] ?? ''),
        $p['name'] . ': ages ' . $p['ages'] . ', ' . $p['city'] . ', ' . $p['state']);
    check($staff_facts === $staff, "  all $staff staff came over");
    foreach ((array) ($d['identification']['pastNames'] ?? array()) as $pn) {
        check(in_array($pn, kop_ya_lines($p['other_names']), true), "  past name \"$pn\" kept");
    }
    $cited = count(array_filter($facts, function ($f) { return !empty($f['cites']); }));
    printf("       %d facts (%d citing a Woodbury page), notes: %s\n", count($facts), $cited, $p['notes'] === '' ? '(none)' : str_replace("\n", ' / ', mb_substr($p['notes'], 0, 120)));
}
$fulshear = $byname[kop_ya_key('Fulshear Treatment to Transition – The Ranch')] ?? array('facts' => '[]', 'notes' => '');
$f = array_values(array_filter(kop_ya_facts($fulshear), function ($x) { return strpos($x['label'], 'Operator: InnerChange') === 0; }));
check($f && $f[0]['cites'][0]['label'] === 'July 2009' && (int) $f[0]['cites'][0]['page'] === 7, 'a cited note becomes a fact citing its page (Fulshear: Operator: InnerChange, July 2009 p. 7)');

echo "Woodbury Facts\n";
$moved = 0;
$keyed = 0;
foreach ($applied as $a) {
    $r = $pdo->query('SELECT * FROM wpdl_kop_woodbury_facts WHERE pkey = ' . $pdo->quote($a['pkey']))->fetch(PDO::FETCH_ASSOC);
    $done = json_decode((string) $r['applied'], true);
    $moved += ($r['status'] === 'applied' && (int) $r['applied_fid'] === 0 && ($done['filed'] ?? '') === 'young_adult') ? 1 : 0;
    if ($a['op'] === 'add_staff' && ($done['done']['key'] ?? '') === $a['pkey']) {
        $keyed++;
    }
}
$staff_items = count(array_filter($applied, function ($a) { return $a['op'] === 'add_staff'; }));
check($moved === count($applied), "every added item now points at its program ($moved of " . count($applied) . ')');
check($keyed === $staff_items, "every added person keeps its item's key, so Undo takes them off ($keyed of $staff_items)");
check(rows($pdo, "SELECT COUNT(*) FROM wpdl_kop_woodbury_facts WHERE status IN ('pending','gone') AND facility_id IN ($in)") === 0
    && rows($pdo, "SELECT COUNT(*) FROM wpdl_kop_woodbury_facts WHERE ya = 1 AND match_note LIKE 'Was on facility record%'") === $waiting,
    "the $waiting waiting items moved to the young adult tab");
$one = $pdo->query("SELECT * FROM wpdl_kop_woodbury_facts WHERE status = 'applied' AND op = 'add_staff' AND applied LIKE '%young_adult%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($one) {
    $done = json_decode($one['applied'], true);
    $before = count(kop_ya_facts(kop_ya_get($pdo, $done['id'])));
    kop_ya_remove_fact($pdo, $done['done']);
    check(count(kop_ya_facts(kop_ya_get($pdo, $done['id']))) === $before - 1, 'undoing a moved person takes them off the program');
}

echo "Once only, and the page\n";
update_option('kop_ya_migration_log', null);
kop_ya_maybe_migrate();
check(get_option('kop_ya_migration_log') === null, 'the move runs only once');
ob_start();
kop_ya_render_public();
$html = ob_get_clean();
check(substr_count($html, 'class="kop-ya-program"') === 7, 'seven programs on the page');
check(strpos($html, 'Woodbury Reports, July 2009, p. 7') !== false, 'with their Woodbury sources');

echo $failed ? "\n$failed failed\n" : "\nAll checks passed\n";
exit($failed ? 1 : 0);
