<?php
/**
 * The readers of the facility records (facilities_v2 and its side tables),
 * checked against the records themselves: the data form's search and
 * autocomplete, the state page's inspection placement and related-records
 * name map, the alias index for news and lawsuit linking, and the program
 * index's news and lawsuit attachers.
 *
 * Until 2026-11-02 this diffed the legacy facilities_master readers against
 * v2 (the phase 4 switch rehearsal). facilities_master was retired in phase 5
 * (docs/DATA-MODEL-MIGRATION.md), so each reader is now held to what the v2
 * tables say instead.
 *
 * WordPress is stubbed and $wpdb is backed by PDO, so the real SQL runs
 * against a throwaway MySQL 8 loaded with a copy of the production tables -
 * never against production. Read-only.
 *
 * Usage:
 *   php -d memory_limit=3G scripts/test-facility-v2-readers.php \
 *       --dsn "mysql:host=127.0.0.1;port=3399;dbname=kop;charset=utf8mb4" --user root [--password x] [--prefix wpdl_]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('dsn:', 'user:', 'password::', 'prefix::'));
if (empty($args['dsn']) || !isset($args['user'])) {
    fwrite(STDERR, "Usage: --dsn <pdo dsn> --user <user> [--password <pw>] [--prefix wpdl_]\n");
    exit(2);
}
if (stripos($args['dsn'], 'kidsover_production') !== false) {
    fwrite(STDERR, "Refusing to run against the production database.\n");
    exit(2);
}

define('KOP_SEARCH_LIMIT', 100);          // the endpoint's own maximum
define('KOP_AUTOCOMPLETE_LIMIT', 200);    // same

/** Search terms, and autocomplete category/query pairs. */
function kop_reader_search_terms() {
    return array('academy', 'ranch', 'provo', 'hope house', 'wilderness', 'Utah', 'canyon school', 'red rock', 'elevations');
}
function kop_reader_autocomplete_cases() {
    return array(
        'facility' => '', 'operator' => '', 'type' => '', 'status' => '', 'gender' => '',
        'membership' => '', 'accreditation' => '', 'licensing' => '',
        'facility|anderson' => 'anderson', 'facility|canyon' => 'canyon', 'human|smith' => 'smith',
        'location|utah' => 'utah', 'role|director' => 'director', 'investor|capital' => 'capital',
    );
}

$pdo = new PDO($args['dsn'], $args['user'], $args['password'] ?? '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$prefix = $args['prefix'] ?? 'wpdl_';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

define('ABSPATH', dirname(__DIR__) . '/');
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
define('OBJECT', 'OBJECT');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
preg_match('/dbname=([^;]+)/', $args['dsn'], $m);
define('DB_NAME', $m[1] ?? 'kop');

/** Minimal $wpdb over PDO: the readers' SQL runs for real. */
class wpdb {
    public $prefix;
    public $last_error = '';
    private $pdo;
    public function __construct(PDO $pdo, $prefix) {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }
    public function prepare($sql, ...$a) {
        if (count($a) === 1 && is_array($a[0])) $a = $a[0];
        $sql = str_replace('%%', '%', $sql);
        $out = '';
        $i = 0;
        for ($p = 0; $p < strlen($sql); $p++) {
            if ($sql[$p] === '%' && isset($sql[$p + 1]) && strpos('sdfF', $sql[$p + 1]) !== false) {
                $v = $a[$i++] ?? '';
                $out .= in_array($sql[$p + 1], array('d'), true) ? (string)(int)$v
                    : (in_array($sql[$p + 1], array('f', 'F'), true) ? (string)(float)$v : $this->pdo->quote((string)$v));
                $p++;
                continue;
            }
            $out .= $sql[$p];
        }
        return $out;
    }
    private function run($sql) {
        return $this->pdo->query($sql);
    }
    public function get_results($sql, $type = 'OBJECT') {
        $rows = $this->run($sql)->fetchAll(PDO::FETCH_ASSOC);
        if ($type === ARRAY_A) return $rows;
        return array_map(function ($r) { return (object)$r; }, $rows);
    }
    public function get_row($sql, $type = 'OBJECT') {
        $rows = $this->get_results($sql, $type);
        return $rows ? $rows[0] : null;
    }
    public function get_var($sql) {
        $v = $this->run($sql)->fetchColumn();
        return $v === false ? null : $v;
    }
    public function get_col($sql) {
        return $this->run($sql)->fetchAll(PDO::FETCH_COLUMN);
    }
    public function query($sql) {
        return $this->run($sql)->rowCount();
    }
    public function esc_like($t) { return addcslashes((string)$t, '_%\\'); }
}
$wpdb = new wpdb($pdo, $prefix);
$GLOBALS['wpdb'] = $wpdb;

// WordPress stubs: enough for the reader paths under test.
function add_action() {}
function add_filter() {}
function apply_filters($tag, $value) { return $value; }
function do_action() {}
function register_rest_route() {}
function rest_ensure_response($v) { return $v; }
function rest_url($path = '') { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
function home_url($path = '') { return 'https://example.test/' . ltrim($path, '/'); }
function admin_url($path = '') { return home_url('wp-admin/' . $path); }
function site_url($path = '') { return home_url($path); }
function get_stylesheet_directory() { return dirname(__DIR__); }
function get_stylesheet_directory_uri() { return 'https://example.test/theme'; }
function trailingslashit($s) { return rtrim((string)$s, '/\\') . '/'; }
function untrailingslashit($s) { return rtrim((string)$s, '/\\'); }
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : $s; }
function sanitize_title($s) { return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$s)); }
function esc_url_raw($s) { return $s; }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return $s; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wp_list_pluck($list, $field) {
    $out = array();
    foreach ((array)$list as $row) {
        $row = (array)$row;
        if (isset($row[$field])) $out[] = $row[$field];
    }
    return $out;
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function current_time($type = 'mysql') { return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s'); }
function absint($v) { return abs((int)$v); }
function wp_cache_get() { return false; }
function wp_cache_set() { return true; }
function get_transient() { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function get_option($name, $default = false) {
    // The model switches are the only options these readers consult. Every
    // area reads v2, as on production since phase 5.
    if ($name === 'kop_data_model') return 'v2';
    if ($name === 'kop_data_model_areas') return array_keys(kop_v2_areas());
    return $default;
}
function update_option() { return true; }
function get_page_by_path() { return null; }
function get_permalink() { return ''; }
function get_posts() { return array(); }
function get_the_title() { return ''; }
function get_post_meta() { return ''; }
function has_post_thumbnail() { return false; }
function get_the_post_thumbnail_url() { return ''; }
function wp_next_scheduled() { return true; }
function wp_schedule_event() {}
function wp_schedule_single_event() {}
function spawn_cron() {}
function register_shutdown_function_stub() {}
function wp_verify_nonce() { return true; }
function wp_create_nonce() { return 'nonce'; }
function current_user_can() { return false; }
function is_user_logged_in() { return false; }
function get_bloginfo() { return 'KOP'; }
class WP_Error {
    public $code;
    public $message;
    public $data;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const EDITABLE = 'POST'; const DELETABLE = 'DELETE'; }
class WP_REST_Request {
    private $params;
    public function __construct(array $params = array()) { $this->params = $params; }
    public function get_param($k) { return $this->params[$k] ?? null; }
    public function get_json_params() { return $this->params; }
    public function get_params() { return $this->params; }
}

require_once dirname(__DIR__) . '/inc/facility-store.php';
require_once dirname(__DIR__) . '/inc/facility-v2-readers.php';
require_once dirname(__DIR__) . '/inc/database.php';
require_once dirname(__DIR__) . '/inc/utilities.php';
require_once dirname(__DIR__) . '/inc/rest-api.php';
require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';
require_once dirname(__DIR__) . '/api/facility-aliases.php';

$lower = function ($s) { return mb_strtolower(trim((string)$s)); };
$facilities = $pdo->query("SELECT id, unique_name, name, state FROM facilities_v2 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$facility_ids = array_flip(array_map('intval', array_column($facilities, 'id')));
$operator_ids = array_flip(array_map('intval', $pdo->query("SELECT id FROM `{$prefix}kop_operators`")->fetchAll(PDO::FETCH_COLUMN)));
check('the copy has facility records', count($facilities) > 0, count($facilities) . ' facilities, ' . count($operator_ids) . ' operators');
check('the copy has the write switch on, as production does', kop_v2_writes_active($pdo, $prefix, true));

/** Facilities whose own name or row name contains $q, as lowercase names. */
$named = function ($q) use ($facilities, $lower) {
    $out = array();
    $q = $lower($q);
    foreach ($facilities as $f) {
        foreach (array('name', 'unique_name') as $k) {
            if ($q !== '' && mb_strpos($lower($f[$k]), $q) !== false) $out[(int)$f['id']] = array($lower($f['name']), $lower($f['unique_name']));
        }
    }
    return $out;
};

echo "\n-- Data form search (kop/v1/search) --\n";
foreach (kop_reader_search_terms() as $term) {
    $response = kop_search_database_rest_callback(new WP_REST_Request(array('keyword' => $term, 'limit' => KOP_SEARCH_LIMIT)));
    $found = array();
    $found_ids = array();
    foreach ((array)($response['results'] ?? array()) as $r) {
        $found[$lower($r['name'] ?? '')] = true;
        // A facility of a matched operator is listed inside that result, not again on its own.
        foreach ((array)($r['data']['facilities'] ?? array()) as $nested) {
            if (!empty($nested['facility_id'])) $found_ids[(int)$nested['facility_id']] = true;
        }
    }
    $want = $named($term);
    // Results are sorted by name and cut at the limit, so a capped search is
    // checked up to the last name it reached.
    $capped = count($found) >= KOP_SEARCH_LIMIT;
    $edge = $capped ? array_key_last($found) : null;
    $missing = array();
    $compared = 0;
    foreach ($want as $id => $names) {
        // A facility's own result is named by its row name (unique_name).
        if ($capped && strnatcasecmp($names[1], $edge) > 0) continue;
        $compared++;
        if (!isset($found[$names[0]]) && !isset($found[$names[1]]) && !isset($found_ids[$id])) $missing[] = "$id {$names[1]}";
    }
    check("search \"$term\": finds every facility named so", count($missing) === 0,
        count($found) . ' results, ' . count($want) . ' named so' . ($capped ? ", capped: compared $compared up to \"$edge\"" : '')
        . ($missing ? '; missing: ' . implode(' | ', array_slice($missing, 0, 5)) : ''));
}

echo "\n-- Autocomplete (kop/v1/autocomplete) --\n";
foreach (kop_reader_autocomplete_cases() as $key => $query) {
    $category = strtok($key, '|');
    $response = kop_autocomplete_rest_callback(new WP_REST_Request(array(
        'category' => $category, 'q' => $query, 'limit' => KOP_AUTOCOMPLETE_LIMIT,
    )));
    $values = array_map($lower, (array)($response['values'] ?? array()));
    $capped = count($values) >= KOP_AUTOCOMPLETE_LIMIT;
    if ($category === 'facility' && $query !== '' && !$capped) {
        $offered = array_flip($values);
        $missing = array();
        foreach ($named($query) as $id => $names) {
            if (!isset($offered[$names[0]]) && !isset($offered[$names[1]])) $missing[] = "$id {$names[1]}";
        }
        check("autocomplete $key: offers every facility named so", count($missing) === 0,
            count($values) . ' values' . ($missing ? '; missing: ' . implode(' | ', array_slice($missing, 0, 5)) : ''));
        continue;
    }
    check("autocomplete $key: offers values", count($values) > 0, count($values) . ($capped ? ' (capped)' : ''));
}

echo "\n-- State pages --\n";
// Which US state page an inspection row lands on: the name pin, decided from
// the facilities' own states (kop_master_facility_state_map()).
$state_map = kop_master_facility_state_map();
$unmapped = array();
foreach ($facilities as $f) {
    $st = strtoupper(trim((string)$f['state']));
    if ($st === '') continue;
    $key = kop_normalize_facility_name((string)$f['name']);
    if ($key !== '' && !in_array($st, $state_map[$key] ?? array(), true)) $unmapped[] = "{$f['id']} {$f['name']} ($st)";
}
check('inspection placement map has every facility in its own state', count($unmapped) === 0,
    count($state_map) . ' names' . ($unmapped ? '; missing: ' . implode(' | ', array_slice($unmapped, 0, 6)) : ''));

$pins = $wrong_pins = 0;
$wrong = array();
foreach ($pdo->query("SELECT DISTINCT facility_name FROM inspection_facilities")->fetchAll(PDO::FETCH_COLUMN) as $name) {
    $key = kop_normalize_facility_name((string)$name);
    if ($key === '') continue;
    $pin = kop_master_state_pin($key);
    if ($pin === '') continue;
    $pins++;
    // A pin found through a stripped state suffix has no entry of its own.
    if (isset($state_map[$key]) && $state_map[$key] !== array($pin)) {
        $wrong_pins++;
        $wrong[] = "$name -> $pin (" . implode('/', $state_map[$key]) . ')';
    }
}
check('an inspection name is pinned only to the one state its facilities are in', $wrong_pins === 0,
    "$pins names pinned" . ($wrong ? '; wrong: ' . implode(' | ', array_slice($wrong, 0, 5)) : ''));

$name_ids = kop_state_master_name_id_map();
$unresolved = $dangling = array();
foreach ($facilities as $f) {
    if (!isset($name_ids[$lower($f['unique_name'])])) $unresolved[] = "{$f['id']} {$f['unique_name']}";
}
foreach ($name_ids as $name => $id) {
    if (!isset($facility_ids[$id]) && !isset($operator_ids[$id])) $dangling[] = "$name ($id)";
}
check('related-records name map resolves every facility by its row name', count($unresolved) === 0,
    count($name_ids) . ' names' . ($unresolved ? '; unresolved: ' . implode(' | ', array_slice($unresolved, 0, 6)) : ''));
check('related-records name map points only at existing records', count($dangling) === 0,
    $dangling ? implode(' | ', array_slice($dangling, 0, 6)) : '');

echo "\n-- News and lawsuit linking --\n";
$index = kop_build_facility_alias_index($pdo);
$ambiguous = $index['ambiguous'];
$unresolved = $dangling = array();
foreach ($facilities as $f) {
    $key = kop_normalize_name_key((string)$f['unique_name']);
    if ($key !== '' && !isset($index['exact'][$key]) && !isset($ambiguous[$key])) $unresolved[] = "{$f['id']} {$f['unique_name']}";
}
foreach ($index['exact'] as $key => $id) {
    if (!isset($facility_ids[(int)$id]) && !isset($operator_ids[(int)$id])) $dangling[] = "$key ($id)";
}
check('alias index resolves every facility by its row name', count($unresolved) === 0,
    count($index['exact']) . ' names, ' . count($ambiguous) . ' ambiguous' . ($unresolved ? '; unresolved: ' . implode(' | ', array_slice($unresolved, 0, 6)) : ''));
check('alias index points only at existing records', count($dangling) === 0, $dangling ? implode(' | ', array_slice($dangling, 0, 6)) : '');

$linked_ids = array_values(array_unique(array_map('intval', array_merge(
    (array)$pdo->query("SELECT DISTINCT facility_id FROM news_facility_links")->fetchAll(PDO::FETCH_COLUMN),
    (array)$pdo->query("SELECT DISTINCT facility_id FROM lawsuit_facility_links")->fetchAll(PDO::FETCH_COLUMN)
))));
$linked_names = kop_v2_pdo_names_by_id($pdo, $prefix, $linked_ids);
$missing = array_values(array_diff($linked_ids, array_map('intval', array_keys($linked_names))));
check('every linked news and lawsuit id resolves to a name', count($missing) === 0,
    count($linked_ids) . ' linked ids' . ($missing ? '; missing: ' . implode(', ', array_slice($missing, 0, 8)) : ''));

echo "\n-- Program index links --\n";
$projects = kop_v2_get_facilities_projects();
$projects = isset($projects['projects']) ? $projects['projects'] : $projects;
kop_attach_linked_news_to_projects($wpdb, $projects);
kop_attach_linked_lawsuits_to_projects($wpdb, $projects);
check('program index lists projects', count($projects) > 0, count($projects) . ' projects');
$sql = array(
    'news'     => array('linked_news', "SELECT l.facility_id, l.news_id FROM news_facility_links l JOIN news_submissions n ON n.id = l.news_id WHERE n.status IN ('approved','published')"),
    'lawsuits' => array('linked_lawsuits', "SELECT lf.facility_id, lf.lawsuit_id FROM lawsuit_facility_links lf JOIN lawsuits l ON l.id = lf.lawsuit_id WHERE l.publication_status = 'published'"),
);
foreach ($sql as $kind => $spec) {
    list($field, $query) = $spec;
    $by_facility = array();
    foreach ($pdo->query($query)->fetchAll(PDO::FETCH_NUM) as $row) $by_facility[(int)$row[0]][(int)$row[1]] = true;
    $want = $got = array();
    foreach ($projects as $key => $project) {
        foreach ((array)($project['data']['facilities'] ?? array()) as $nested) {
            if (!is_array($nested) || empty($nested['facility_id'])) continue;
            $fid = (int)$nested['facility_id'];
            foreach (array_keys($by_facility[$fid] ?? array()) as $item) $want[] = "$key #$fid/$item";
            foreach ((array)($nested[$field] ?? array()) as $item) $got[] = "$key #$fid/" . (int)($item['id'] ?? 0);
        }
    }
    $lost = array_values(array_diff($want, $got));
    $extra = array_values(array_diff($got, $want));
    check("$kind: every published link shows on its program", count($lost) === 0,
        count(array_unique($want)) . ' links on listed facilities' . ($lost ? '; not shown: ' . implode(' | ', array_slice($lost, 0, 5)) : ''));
    check("$kind: nothing shown that is not a published link", count($extra) === 0, $extra ? implode(' | ', array_slice($extra, 0, 5)) : '');
}

echo $failures === 0 ? "\nAll checks passed.\n" : "\n{$failures} check(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
