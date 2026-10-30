<?php
/**
 * Categories related to the troubled teen industry: programs that are never
 * TTI facility records but work like them (Christian Rehabs for Adults, Young
 * Adult Programs). Owner decisions 2026-10-28 (docs/plan/4.3 item 20).
 *
 * Each record is a facility document of the same shape as facilities_v2
 * (inc/facility-store.php, $opts['related']), kept in its own table,
 * related_facilities, with ids above KOP_RELATED_FIRST_ID. So:
 *   - every TTI reader of facilities_v2 (directory, map, state lists, counts,
 *     open data, search) leaves them out by construction;
 *   - the shared link tables hold their ids with no clash: companies through
 *     {prefix}kop_operator_facilities, news through news_facility_links,
 *     cases through lawsuit_facility_links, nonprofit filings through
 *     {prefix}kop_nonprofit_links (facility_id);
 *   - their page is the facility page (kop_facility_page_data() reads the
 *     record's own table), at /related/<category>/<slug>/, labelled with its
 *     category; /related/<category>/ lists a category.
 * No kop_facility_locations rows are written (those put a program on the
 * TTI location pages).
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_RELATED_DB_VERSION', '1');
define('KOP_RELATED_REWRITE_VERSION', '1');

/**
 * slug => kind (its token in the record finder, "cra:<id>", inc/record-field.php),
 * label (the list's title), one (one record, lower case), intro (the index's
 * first paragraph: what the category is and why it is not TTI).
 */
function kop_related_categories() {
    return array(
        'christian-adult-rehabs' => array(
            'kind'  => 'cra',
            'label' => 'Christian Rehabs for Adults',
            'one'   => 'Christian rehab for adults',
            'intro' => 'Residential Christian addiction programs for adults, most of them Adult & Teen Challenge centers. They are not troubled teen programs: they take adults, not children. They are listed because the same corporations, staff and methods run the Teen Challenge programs for teenagers.',
        ),
        'young-adult-programs' => array(
            'kind'  => 'ya',
            'label' => 'Young Adult Programs',
            'one'   => 'young adult program',
            'intro' => 'Programs for people 18 and older. They are not troubled teen programs, but many are run by the same companies, take graduates of teen programs, or market themselves to the same parents.',
        ),
        'psychiatric-hospitals' => array(
            'kind'  => 'psych',
            'label' => 'Acute Psychiatric Inpatient',
            'one'   => 'psychiatric hospital or ward',
            'intro' => 'Psychiatric hospitals and inpatient psych wards that hold people for short stays in a crisis. They are not troubled teen programs, but companies that run teen residential treatment run many of them too, and teenagers are sent on from them to programs.',
        ),
        'nursing-homes' => array(
            'kind'  => 'nursing',
            'label' => 'Nursing Homes',
            'one'   => 'nursing home',
            'intro' => 'Long-term care homes for elderly and disabled adults. They are not troubled teen programs; they are listed where the same companies, owners or investors run both.',
        ),
        'disability-group-homes' => array(
            'kind'  => 'idd',
            'label' => 'I/DD Group Homes',
            'one'   => 'group home for adults with intellectual or developmental disabilities',
            'intro' => 'Group homes for adults with intellectual and developmental disabilities. They are not troubled teen programs; they are listed where the same companies, owners or staff run both.',
        ),
        'indian-boarding-schools' => array(
            'kind'  => 'ibs',
            'label' => 'Indian Boarding Schools',
            'one'   => 'Indian boarding school',
            'intro' => 'Federal and church-run boarding schools that Native children were sent to, many of them by force.',
            // Its records are still in indigenous_schools (inc/indigenous-schools.php): the list goes to that page until they move here.
            'page'  => '/indian-boarding-schools/',
        ),
        'foster-care' => array(
            'kind'  => 'foster',
            'label' => 'Foster Care',
            'one'   => 'foster care agency or home',
            'intro' => 'Foster homes and the agencies that place children in them. Foster care is not the troubled teen industry, but the same agencies often run both, and children in foster care are sent to its programs.',
        ),
    );
}

function kop_related_category($slug) {
    $all = kop_related_categories();
    return isset($all[$slug]) ? $all[$slug] : null;
}

/**
 * finder kind => category slug ('cra' => 'christian-adult-rehabs'). A
 * category whose records are still on a page of their own has no kind yet.
 */
function kop_related_kinds() {
    $out = array();
    foreach (kop_related_categories() as $slug => $c) {
        if (empty($c['page'])) $out[$c['kind']] = $slug;
    }
    return $out;
}

/** finder kind => what one record is called in a sentence ("nursing home"), for the record finder and filing. */
function kop_related_kind_words() {
    $out = array();
    foreach (kop_related_kinds() as $kind => $slug) $out[$kind] = kop_related_category($slug)['one'];
    return $out;
}

function kop_related_base() {
    return 'related';
}

/**
 * The facility store's options for these records, on the records PDO unless
 * one is given (as the facility writers, kop_ie_facility_opts()).
 */
function kop_related_opts(array $more = array()) {
    $opts = array_merge(array('related' => true, 'skip_memberships' => true), $more);
    if (empty($opts['pdo']) && function_exists('kop_seed_pdo') && ($pdo = kop_seed_pdo()) instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $opts['pdo'] = $pdo;
        if (!isset($opts['prefix']) && isset($GLOBALS['wpdb'])) $opts['prefix'] = $GLOBALS['wpdb']->prefix;
    }
    return $opts;
}

// ---------------------------------------------------------------------------
// Table
// ---------------------------------------------------------------------------

/** related_facilities: facilities_v2's columns plus the category. */
function kop_related_install($force = false) {
    global $wpdb;
    if (!$force && get_option('kop_related_db') === KOP_RELATED_DB_VERSION) return;
    $wpdb->query("CREATE TABLE IF NOT EXISTS related_facilities (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        category VARCHAR(60) NOT NULL DEFAULT '',
        review VARCHAR(20) NOT NULL DEFAULT 'approved',
        unique_name VARCHAR(255) NOT NULL,
        json_data JSON NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        schema_version TINYINT GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.schema_version' RETURNING SIGNED)) STORED,
        name VARCHAR(255) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.identification.name'), 255)) STORED,
        name_key VARCHAR(255) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.identification.nameKey'), 255)) STORED,
        state CHAR(2) GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.location.state')) STORED,
        city VARCHAR(120) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.location.city'), 120)) STORED,
        country VARCHAR(80) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.location.country'), 80)) STORED,
        status VARCHAR(20) GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.operatingPeriod.status')) STORED,
        facility_type VARCHAR(120) GENERATED ALWAYS AS (LEFT(JSON_VALUE(json_data, '$.facilityDetails.type'), 120)) STORED,
        start_year SMALLINT GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.operatingPeriod.startYear' RETURNING SIGNED)) STORED,
        end_year SMALLINT GENERATED ALWAYS AS (JSON_VALUE(json_data, '$.operatingPeriod.endYear' RETURNING SIGNED)) STORED,
        UNIQUE KEY uq_unique_name (unique_name),
        KEY idx_category (category, review),
        KEY idx_name_key (name_key)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    update_option('kop_related_db', KOP_RELATED_DB_VERSION, false);
}
add_action('admin_init', 'kop_related_install');

/** Is the table there (made by kop_related_install() on the first admin page after deploy)? */
function kop_related_ready() {
    static $ready = null;
    if ($ready !== null) return $ready;
    $opts = kop_related_opts();
    try {
        kop_facility_db_rows('SELECT id FROM related_facilities LIMIT 1', array(), $opts);
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

// ---------------------------------------------------------------------------
// Records
// ---------------------------------------------------------------------------

/**
 * Write one record: $raw is any facility shape kop_facility_normalize() takes
 * ({identification, location, ...}). With $id the record is updated; without,
 * a record of the same name and place in the table is reused, else one is
 * made. $opts['review'] = 'pending' holds the record off the public pages
 * (a sent-in one, until it is approved); 'approved' lets it on. Returns the id.
 */
function kop_related_save($category, array $raw, $id = 0, array $opts = array()) {
    if (!kop_related_category($category)) throw new RuntimeException('Unknown category ' . $category . '.');
    $opts = kop_related_opts($opts);
    $doc = kop_facility_normalize($raw, $opts);
    if ($doc['identification']['name'] === '') throw new RuntimeException('The record needs a name.');
    if ((int) $id > 0) {
        if (!kop_facility_is_related_id($id)) throw new RuntimeException('#' . (int) $id . ' is not a related record.');
        $doc['facility_id'] = (int) $id;
    }
    $id = kop_facility_save($doc, $opts, $status);
    kop_facility_db_exec('UPDATE related_facilities SET category = ? WHERE id = ?', array($category, (int) $id), $opts);
    if (isset($opts['review'])) {
        kop_facility_db_exec('UPDATE related_facilities SET review = ? WHERE id = ?', array($opts['review'] === 'pending' ? 'pending' : 'approved', (int) $id), $opts);
    }
    kop_related_flush();
    return (int) $id;
}

/** {id, category, unique_name, doc} or null. */
function kop_related_get($id, array $opts = array()) {
    if (!kop_facility_is_related_id($id) || (empty($opts['pdo']) && !kop_related_ready())) return null;
    $opts = kop_related_opts($opts);
    $rows = kop_facility_db_rows('SELECT id, category, review, unique_name, json_data, created_at, updated_at FROM related_facilities WHERE id = ?', array((int) $id), $opts);
    if (!$rows) return null;
    return kop_related_rec($rows[0]);
}

/** A table row as {id, category, review, unique_name, doc, created_at, updated_at}, or null when its document does not read. */
function kop_related_rec(array $row) {
    $doc = json_decode((string) $row['json_data'], true);
    if (!is_array($doc)) return null;
    return array('id' => (int) $row['id'], 'category' => (string) $row['category'], 'review' => (string) ($row['review'] ?? 'approved'),
        'unique_name' => (string) ($row['unique_name'] ?? ''), 'doc' => $doc,
        'created_at' => (string) ($row['created_at'] ?? ''), 'updated_at' => (string) ($row['updated_at'] ?? ''));
}

/**
 * Write a record at a given id (a move from another table that keeps its
 * numbers, kop_ya_move_to_related()). Refuses an id that is taken.
 */
function kop_related_insert_at($category, array $raw, $id, $review = 'approved', array $opts = array()) {
    $opts = kop_related_opts($opts);
    $id = (int) $id;
    if (!kop_facility_is_related_id($id) || !kop_related_category($category)) throw new RuntimeException('Not a related record id or category.');
    if (kop_facility_db_rows('SELECT id FROM related_facilities WHERE id = ?', array($id), $opts)) throw new RuntimeException('Record #' . $id . ' is taken.');
    $doc = kop_facility_normalize($raw, $opts);
    $doc['facility_id'] = $id;
    $doc['identification']['nameKey'] = kop_facility_name_key($doc['identification']['name']);
    $unique = kop_facility_allocate_unique_name($doc, $opts);
    $doc['provenance']['uniqueName'] = $unique;
    kop_facility_db_exec('INSERT INTO related_facilities (id, category, review, unique_name, json_data) VALUES (?, ?, ?, ?, ?)',
        array($id, $category, $review === 'pending' ? 'pending' : 'approved', $unique, kop_facility_json_encode($doc)), $opts);
    kop_related_flush();
    return $id;
}

/**
 * Every record of a category ('' = all): rows {id, category, review, name,
 * city, state, country, status, start_year, end_year, json_data}. $review:
 * 'approved' (the default; what the public sees), 'pending', or null for both.
 */
function kop_related_all($category = '', array $opts = array(), $review = 'approved') {
    if (empty($opts['pdo']) && !kop_related_ready()) return array();
    $opts = kop_related_opts($opts);
    $sql = 'SELECT id, category, review, unique_name, name, city, state, country, status, start_year, end_year, json_data, created_at, updated_at FROM related_facilities';
    $where = array();
    $params = array();
    if ($category !== '') {
        $where[] = 'category = ?';
        $params[] = $category;
    }
    if ($review !== null) {
        $where[] = 'review = ?';
        $params[] = $review;
    }
    return kop_facility_db_rows($sql . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY name, id', $params, $opts);
}

/**
 * Records of a category ('' = all) whose name or other names hold $q (the
 * record finder): [{id, category, name, city, state, review}], names that
 * start with it first.
 */
function kop_related_search($category, $q, $limit = 8, array $opts = array()) {
    $q = trim((string) $q);
    if (mb_strlen($q) < 2) return array();
    $needle = mb_strtolower($q);
    $hits = array();
    foreach (kop_related_all($category, $opts, null) as $r) {
        $doc = json_decode((string) $r['json_data'], true);
        $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
        $names = array_merge(array((string) $r['name']), array_map('strval', array_merge((array) ($ident['otherNames'] ?? array()), (array) ($ident['pastNames'] ?? array()))));
        $rank = null;
        foreach ($names as $i => $n) {
            $p = mb_strpos(mb_strtolower($n), $needle);
            if ($p !== false) {
                $rank = ($i === 0 && $p === 0) ? 0 : 1;
                break;
            }
        }
        if ($rank === null) continue;
        $hits[] = array('rank' => $rank, 'id' => (int) $r['id'], 'category' => (string) $r['category'], 'name' => (string) $r['name'],
            'city' => (string) $r['city'], 'state' => (string) $r['state'], 'review' => (string) $r['review']);
    }
    usort($hits, static function ($a, $b) { return $a['rank'] - $b['rank'] ?: strcasecmp($a['name'], $b['name']); });
    return array_slice($hits, 0, $limit);
}

/** The record of this name (and state, when given) in a category, or 0. */
function kop_related_find($category, $name, $state = null, array $opts = array()) {
    $key = kop_facility_name_key($name);
    if ($key === '') return 0;
    $st = kop_facility_state_code($state);
    foreach (kop_related_all($category, $opts, null) as $r) {
        if (kop_facility_name_key($r['name']) !== $key) continue;
        if ($st !== null && $r['state'] !== null && $r['state'] !== '' && $r['state'] !== $st) continue;
        return (int) $r['id'];
    }
    return 0;
}

/** The company that runs it ('current') or ran it ('past'), as for a facility. */
function kop_related_link_operator($id, $operator_id, $relationship = 'current', array $opts = array()) {
    if (!kop_facility_is_related_id($id) || (int) $operator_id <= 0) return false;
    $opts = kop_related_opts($opts);
    $rel = in_array($relationship, array('current', 'past', 'other'), true) ? $relationship : 'current';
    $t = kop_facility_table('operator_facilities', $opts);
    kop_facility_db_exec("DELETE FROM {$t} WHERE operator_id = ? AND facility_id = ?", array((int) $operator_id, (int) $id), $opts);
    kop_facility_db_exec("INSERT INTO {$t} (operator_id, facility_id, relationship, sort_order) VALUES (?, ?, ?, 0)", array((int) $operator_id, (int) $id, $rel), $opts);
    kop_related_flush();
    return true;
}

/** Remove a record and its company links (its news, case and filing links go with it). */
function kop_related_delete($id, array $opts = array()) {
    if (!kop_facility_is_related_id($id)) return false;
    $opts = kop_related_opts($opts);
    kop_facility_db_exec('DELETE FROM related_facilities WHERE id = ?', array((int) $id), $opts);
    foreach (array(kop_facility_table('operator_facilities', $opts), 'news_facility_links', 'lawsuit_facility_links') as $t) {
        try { kop_facility_db_exec("DELETE FROM {$t} WHERE facility_id = ?", array((int) $id), $opts); } catch (Throwable $e) {}
    }
    kop_related_flush();
    return true;
}

// ---------------------------------------------------------------------------
// Slugs and URLs
// ---------------------------------------------------------------------------

function kop_related_flush() {
    unset($GLOBALS['kop_related_index_cache']);
    if (function_exists('delete_transient')) delete_transient('kop_related_index');
}

/** {ids: {id: {category, slug, name}}, slugs: {category: {slug: id}}}, from every approved record (a pending one has no page yet). */
function kop_related_index() {
    if (isset($GLOBALS['kop_related_index_cache'])) return $GLOBALS['kop_related_index_cache'];
    $index = array('ids' => array(), 'slugs' => array());
    foreach (kop_related_all() as $r) {
        $cat = (string) $r['category'];
        $base = function_exists('kop_facility_page_slug_candidate')
            ? kop_facility_page_slug_candidate((string) $r['name'], (string) $r['state'], (string) $r['country'])
            : sanitize_title((string) $r['name']);
        $slug = $base !== '' ? $base : 'record';
        if (isset($index['slugs'][$cat][$slug])) $slug .= '-' . (int) $r['id'];
        $index['slugs'][$cat][$slug] = (int) $r['id'];
        $index['ids'][(int) $r['id']] = array('category' => $cat, 'slug' => $slug, 'name' => (string) $r['name']);
    }
    $GLOBALS['kop_related_index_cache'] = $index;
    return $index;
}

function kop_related_category_url($category) {
    return home_url('/' . kop_related_base() . '/' . rawurlencode($category) . '/');
}

/** The record's page, or ''. */
function kop_related_url($id) {
    $index = kop_related_index();
    $e = $index['ids'][(int) $id] ?? null;
    return $e ? home_url('/' . kop_related_base() . '/' . rawurlencode($e['category']) . '/' . rawurlencode($e['slug']) . '/') : '';
}

/** What a facility page needs to say it is a related record: {category, label, one, url}, or null. */
function kop_related_page_info($id) {
    $index = kop_related_index();
    $e = $index['ids'][(int) $id] ?? null;
    $cat = $e ? kop_related_category($e['category']) : null;
    if (!$cat) return null;
    return array('category' => $e['category'], 'label' => $cat['label'], 'one' => $cat['one'], 'url' => kop_related_category_url($e['category']),
        'slug' => $e['slug']);
}

/** A company's related records, for its page: [{id, name, place, status, url, category, label}]. */
function kop_related_for_operators(array $operator_ids) {
    global $wpdb;
    $ids = array_filter(array_map('intval', $operator_ids));
    if (!$ids || !kop_related_ready()) return array();
    $rows = $wpdb->get_results(
        "SELECT DISTINCT r.id, r.category, r.name, r.city, r.state, r.country, r.status, ofc.relationship
           FROM {$wpdb->prefix}kop_operator_facilities ofc JOIN related_facilities r ON r.id = ofc.facility_id
          WHERE r.review = 'approved' AND ofc.operator_id IN (" . implode(',', $ids) . ')
          ORDER BY r.category, r.name, r.id',
        ARRAY_A
    );
    $out = array();
    foreach ((array) $rows as $r) {
        if (isset($out[(int) $r['id']])) continue;
        $cat = kop_related_category((string) $r['category']);
        $state = ($r['state'] && function_exists('kop_state_canonical_name')) ? (string) kop_state_canonical_name($r['state']) : (string) $r['state'];
        $out[(int) $r['id']] = array(
            'id'       => (int) $r['id'],
            'name'     => (string) $r['name'],
            'place'    => trim(($r['city'] ? $r['city'] . ', ' : '') . ($state !== '' ? $state : ($r['country'] !== 'United States' ? (string) $r['country'] : '')), ', '),
            'status'   => (string) $r['status'],
            'past'     => $r['relationship'] === 'past',
            'url'      => kop_related_url((int) $r['id']),
            'category' => (string) $r['category'],
            'label'    => $cat ? $cat['label'] : '',
        );
    }
    return array_values($out);
}

// ---------------------------------------------------------------------------
// Routing: /related/<category>/ and /related/<category>/<slug>/
// ---------------------------------------------------------------------------

function kop_related_register_rewrite() {
    $base = preg_quote(kop_related_base(), '#');
    add_rewrite_rule('^' . $base . '/([^/]+)/?$', 'index.php?kop_related_cat=$matches[1]', 'top');
    add_rewrite_rule('^' . $base . '/([^/]+)/([^/]+)/?$', 'index.php?kop_related_cat=$matches[1]&kop_related=$matches[2]', 'top');
    if (get_option('kop_related_rewrite') !== KOP_RELATED_REWRITE_VERSION) {
        flush_rewrite_rules(false);
        update_option('kop_related_rewrite', KOP_RELATED_REWRITE_VERSION);
    }
}
add_action('init', 'kop_related_register_rewrite', 30);

add_filter('query_vars', function ($vars) {
    $vars[] = 'kop_related_cat';
    $vars[] = 'kop_related';
    return $vars;
});

function kop_related_requested() {
    if (!function_exists('get_query_var')) return array('', '');
    return array(trim((string) get_query_var('kop_related_cat')), trim((string) get_query_var('kop_related')));
}

// No post query: the page reads related_facilities (as kop_facility_pages_pre_get_posts()).
add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query() || trim((string) $query->get('kop_related_cat')) === '') return;
    $query->is_home = false;
    $query->is_posts_page = false;
    $query->set('posts_per_page', 1);
    $query->set('no_found_rows', true);
});
add_filter('posts_pre_query', function ($posts, $query) {
    if (is_admin() || !$query->is_main_query() || trim((string) $query->get('kop_related_cat')) === '') return $posts;
    return array();
}, 10, 2);
add_filter('pre_handle_404', function ($preempt, $query) {
    return trim((string) $query->get('kop_related_cat')) !== '' ? true : $preempt;
}, 10, 2);

/**
 * A record's page loads the facility page's view model (so the facility
 * template, its styles and scripts follow, inc/facility-pages.php); a
 * category's list sets $GLOBALS['kop_related_list']. Anything else is a 404.
 */
function kop_related_route() {
    list($cat, $slug) = kop_related_requested();
    if ($cat === '') return;
    $category = kop_related_category($cat);
    if ($category && !empty($category['page'])) {   // records still on their own page (see kop_related_categories())
        wp_safe_redirect(home_url($category['page']), 302);
        exit;
    }
    if ($category && $slug === '') {
        $GLOBALS['kop_related_list'] = array('category' => $cat) + $category;
        status_header(200);
        return;
    }
    if ($category) {
        $index = kop_related_index();
        $id = (int) ($index['slugs'][$cat][strtolower($slug)] ?? 0);
        if ($id === 0 && preg_match('/-(\d+)$/', $slug, $m) && isset($index['ids'][(int) $m[1]])) $id = (int) $m[1];
        if ($id === 0 && ctype_digit($slug) && isset($index['ids'][(int) $slug])) $id = (int) $slug;
        if ($id > 0) {
            $e = $index['ids'][$id];
            if ($e['category'] !== $cat || $e['slug'] !== $slug) {
                wp_safe_redirect(kop_related_url($id), 301);
                exit;
            }
            $data = function_exists('kop_facility_page_data') ? kop_facility_page_data($id) : null;
            if ($data) {
                $GLOBALS['kop_facility_page'] = $data;
                status_header(200);
                return;
            }
        }
    }
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
}
add_action('template_redirect', 'kop_related_route', 0);

add_filter('template_include', function ($template) {
    if (empty($GLOBALS['kop_related_list'])) return $template;
    $own = get_stylesheet_directory() . '/templates/related-category.php';
    return file_exists($own) ? $own : $template;
}, 99);

add_filter('pre_get_document_title', function ($title) {
    return !empty($GLOBALS['kop_related_list']) ? $GLOBALS['kop_related_list']['label'] . ' - ' . get_bloginfo('name') : $title;
}, 20);

add_action('wp_enqueue_scripts', function () {
    if (empty($GLOBALS['kop_related_list'])) return;
    if (function_exists('kop_enqueue_shared_facility_ui')) kop_enqueue_shared_facility_ui();
    $css = get_stylesheet_directory() . '/css/facility-profile.css';
    if (file_exists($css)) {
        wp_enqueue_style('kop-facility-profile', get_stylesheet_directory_uri() . '/css/facility-profile.css', array('kop-colors', 'kop-components'), filemtime($css));
    }
    $own = get_stylesheet_directory() . '/css/related-category.css';
    if (file_exists($own)) {
        wp_enqueue_style('kop-related-category', get_stylesheet_directory_uri() . '/css/related-category.css', array('kop-colors'), filemtime($own));
    }
}, 20);

/**
 * A category's list, grouped by state (or country): [{place, items: [{id,
 * name, city, status, years, operator, operator_url, url}]}], places A-Z.
 */
function kop_related_list_groups($category) {
    $groups = array();
    $ids = array();
    $rows = kop_related_all($category);
    foreach ($rows as $r) $ids[] = (int) $r['id'];
    $ops = $ids && function_exists('kop_v2_operators_for_facilities') ? (array) kop_v2_operators_for_facilities($ids) : array();
    foreach ($rows as $r) {
        $doc = json_decode((string) $r['json_data'], true);
        $ident = is_array($doc['identification'] ?? null) ? $doc['identification'] : array();
        $state = ($r['state'] && function_exists('kop_state_canonical_name')) ? (string) kop_state_canonical_name($r['state']) : '';
        $place = $state !== '' && strlen($state) > 2 ? $state : ((string) $r['country'] !== '' ? (string) $r['country'] : 'Place not known');
        $op_row = $ops[(int) $r['id']] ?? null;
        $op_name = trim((string) ($ident['currentOperator'] ?? '')) ?: ($op_row ? (string) $op_row['name'] : '');
        $years = '';
        if ($r['start_year'] || $r['end_year']) $years = trim(($r['start_year'] ?: '?') . ($r['end_year'] ? '-' . $r['end_year'] : ''));
        $groups[$place][] = array(
            'id'           => (int) $r['id'],
            'name'         => (string) $r['name'],
            'city'         => (string) $r['city'],
            'status'       => (string) $r['status'],
            'years'        => $years,
            'operator'     => $op_name,
            'operator_url' => $op_row && function_exists('kop_operator_page_url') ? (string) kop_operator_page_url((int) $op_row['id']) : '',
            'url'          => kop_related_url((int) $r['id']),
        );
    }
    ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
    $out = array();
    foreach ($groups as $place => $items) $out[] = array('place' => $place, 'items' => $items);
    return $out;
}
