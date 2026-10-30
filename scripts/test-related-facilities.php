<?php
/**
 * Offline checks for the categories related to the TTI
 * (inc/related-facilities.php): a record is saved in related_facilities with
 * an id above KOP_RELATED_FIRST_ID, linked to a company, a filing and an
 * article, and its page and its category's list are rendered; the TTI
 * readers (the facility page index, the company's Programs list) never see it.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-related-facilities.php [--db=tmp/prod.sqlite]
 *
 * Every table it writes is a TEMP copy over the mirror (SQLite reads temp
 * tables before main ones); tmp/prod.sqlite is never written.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
if (!function_exists('kop_seed_pdo')) {
    function kop_seed_pdo() { return $GLOBALS['pdo']; }
}
if (!function_exists('flush_rewrite_rules')) { function flush_rewrite_rules() {} }
if (!function_exists('add_rewrite_rule')) { function add_rewrite_rule() {} }
if (!function_exists('delete_option')) { function delete_option() { return true; } }
require_once dirname(__DIR__) . '/inc/related-facilities.php';
require_once dirname(__DIR__) . '/inc/record-field.php';
require_once dirname(__DIR__) . '/inc/nonprofit-filings.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// The store's identity lookup uses MySQL's JSON_UNQUOTE when it cannot see the generated columns.
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) { return $v; }, 1);

// Temp tables: the related table (SQLite generated columns stand in for MySQL's) and copies of the link tables it writes.
$GLOBALS['kop_test_wpdb_writes'] = true;
$pdo->exec("CREATE TEMP TABLE related_facilities (
    id INTEGER PRIMARY KEY, category TEXT NOT NULL DEFAULT '', review TEXT NOT NULL DEFAULT 'approved',
    unique_name TEXT NOT NULL UNIQUE, json_data TEXT NOT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    name TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.identification.name')) STORED,
    name_key TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.identification.nameKey')) STORED,
    state TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.location.state')) STORED,
    city TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.location.city')) STORED,
    country TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.location.country')) STORED,
    status TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.operatingPeriod.status')) STORED,
    facility_type TEXT GENERATED ALWAYS AS (json_extract(json_data, '$.facilityDetails.type')) STORED,
    start_year INTEGER GENERATED ALWAYS AS (json_extract(json_data, '$.operatingPeriod.startYear')) STORED,
    end_year INTEGER GENERATED ALWAYS AS (json_extract(json_data, '$.operatingPeriod.endYear')) STORED)");
foreach (array('wpdl_kop_operator_facilities', 'news_facility_links') as $t) {
    $pdo->exec("CREATE TEMP TABLE $t AS SELECT * FROM main.$t");
}
$pdo->exec('CREATE TEMP TABLE wpdl_kop_nonprofit_links (id INTEGER PRIMARY KEY AUTOINCREMENT, ein TEXT NOT NULL, facility_id INTEGER NOT NULL DEFAULT 0,
    operator_id INTEGER NOT NULL DEFAULT 0, created_by TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL)');

echo "-- Records --\n";
$check('seven categories; each but Indian boarding schools (still on their page) has its finder kind', count(kop_related_categories()) === 7
    && kop_related_kinds() === array('cra' => 'christian-adult-rehabs', 'ya' => 'young-adult-programs', 'psych' => 'psychiatric-hospitals',
        'nursing' => 'nursing-homes', 'idd' => 'disability-group-homes', 'foster' => 'foster-care'), wp_json_encode(kop_related_kinds()));
$check('the record finder and filing name each kind', kop_record_field_kind_words()['cra'] === 'Christian rehab for adults'
    && kop_record_field_kind_words()['nursing'] === 'nursing home');
$id = kop_related_save('christian-adult-rehabs', array(
    'identification'  => array('name' => 'Test Adult Teen Challenge Men\'s Center', 'currentOperator' => 'Teen Challenge', 'otherNames' => array('Test ATC Men')),
    'locationDetails' => array('city' => 'Springfield', 'state' => 'MO', 'country' => 'United States'),
    'operatingPeriod' => array('startYear' => 1995, 'status' => 'Open'),
    'facilityDetails' => array('type' => 'Christian Addiction Treatment Center'),
    'notes'           => array('Men 18 and older (source: https://example.org/atc?page=2)'),
));
$check('saved above the TTI ids', $id > KOP_RELATED_FIRST_ID && kop_facility_is_related_id($id), (string) $id);
$rec = kop_related_get($id);
$check('stored as a facility document in its category', $rec && $rec['category'] === 'christian-adult-rehabs' && $rec['review'] === 'approved'
    && $rec['doc']['location']['state'] === 'MO' && $rec['doc']['operatingPeriod']['startYear'] === 1995);
$check('a web address with a query is kept', $rec && strpos(json_encode($rec['doc']['notes']), 'atc?page=2') !== false);
$check('never in facilities_v2', !$wpdb->get_var("SELECT COUNT(*) FROM facilities_v2 WHERE id = $id"));
$check('no TTI location rows', !$wpdb->get_var("SELECT COUNT(*) FROM wpdl_kop_facility_locations WHERE facility_id = $id"));
// A save replaces the document, as for a facility: the stored one, sent again without its id, finds its record by name and place.
$again = kop_related_save('christian-adult-rehabs', array_merge($rec['doc'], array('facility_id' => null)));
$check('the same name and place is the same record', $again === $id, (string) $again);
$id2 = kop_related_save('christian-adult-rehabs', array('identification' => array('name' => 'Test Women\'s Home'),
    'locationDetails' => array('city' => 'Pueblo', 'state' => 'CO', 'country' => 'United States')));
$check('the next record takes the next id', $id2 === $id + 1, (string) $id2);
$pending = kop_related_save('christian-adult-rehabs', array('identification' => array('name' => 'Test Pending Rehab'),
    'locationDetails' => array('state' => 'TX', 'country' => 'United States')), 0, array('review' => 'pending'));
$check('a pending record has no page', kop_related_url($pending) === '' && kop_related_url($id) !== '');
$check('found by name and state', kop_related_find('christian-adult-rehabs', 'test adult teen challenge mens center', 'MO') === $id);
$hits = kop_related_search('christian-adult-rehabs', 'ATC Men');
$check('the finder search reads other names', $hits && $hits[0]['id'] === $id);
$check('the record field names it', kop_record_field_name('cra', $id) === 'Test Adult Teen Challenge Men\'s Center' && kop_record_field_name('cra', 1) === '');

echo "-- Links --\n";
$check('linked to Teen Challenge (company 22)', kop_related_link_operator($id, 22));
$wpdb->query("INSERT INTO news_facility_links (news_id, facility_id, link_type) SELECT id, $id, 'primary' FROM news_submissions WHERE status IN ('approved','published') ORDER BY id LIMIT 1");
$check('a filing can be linked to it', kop_npf_record_exists('facility', $id) && kop_npf_add_link('112510315', 'facility', $id, 'tester') > 0);
$check('the filing names the record', kop_npf_record_name('facility', $id) === 'Test Adult Teen Challenge Men\'s Center');

echo "-- Pages --\n";
$url = kop_related_url($id);
$check('its page is under /related/<category>/', strpos($url, '/related/christian-adult-rehabs/') !== false, $url);
$check('kop_facility_page_url() gives the same page', kop_facility_page_url($id) === $url);
$index = kop_facility_pages_index();
$check('not in the TTI page index', !isset($index['ids'][$id]));
$data = kop_facility_page_data($id);
$check('the facility page view model loads', is_array($data) && $data['url'] === $url && $data['related']['label'] === 'Christian Rehabs for Adults');
$check('its summary says it is not a troubled teen program', $data && strpos($data['summary'], 'not a troubled teen program') !== false, $data['summary'] ?? '');
$check('the operator is Teen Challenge', $data && $data['operator']['name'] === 'Teen Challenge');
$check('its linked article is listed', $data && count($data['news']) === 1);
$check('the company\'s TTI programs are its siblings', $data && count($data['siblings']) > 5);
$check('no TTI place hub', $data && $data['hub_url'] === '');
$GLOBALS['kop_facility_page'] = $data;
ob_start();
include dirname(__DIR__) . '/templates/facility-page.php';
$html = ob_get_clean();
unset($GLOBALS['kop_facility_page']);
$check('the page says its category and that it is not TTI', strpos($html, 'Christian Rehabs for Adults') !== false && strpos($html, 'Not a troubled teen program') !== false);
$check('no "Facility profile" eyebrow', strpos($html, 'Facility profile') === false);
$pending_data = kop_facility_page_data($pending);
$check('a pending record\'s page does not load', $pending_data === null);

$tti = kop_facility_page_data(11357);   // Ozarks Teen Challenge (MO), run by Teen Challenge
$names = $tti ? array_column($tti['siblings'], 'name') : array();
$check('a TTI sibling page lists it, labelled', in_array('Test Adult Teen Challenge Men\'s Center', $names, true)
    && strpos(implode(' ', array_column($tti['siblings'], 'place')), 'Christian Rehabs for Adults') !== false);

$op = kop_operator_page_data(22);
$op_ids = $op ? array_column($op['facilities'], 'id') : array();
$check('the company page lists it apart from its TTI programs', $op && !in_array($id, $op_ids, true)
    && in_array($id, array_column($op['related'], 'id'), true) && !in_array($pending, array_column($op['related'], 'id'), true));
$GLOBALS['kop_operator_page'] = $op;
ob_start();
include dirname(__DIR__) . '/templates/operator-page.php';
$ohtml = ob_get_clean();
unset($GLOBALS['kop_operator_page']);
$check('the company page prints Related programs', strpos($ohtml, 'id="related"') !== false && strpos($ohtml, 'Test Women') === false);

$GLOBALS['kop_related_list'] = array('category' => 'christian-adult-rehabs') + kop_related_category('christian-adult-rehabs');
ob_start();
include dirname(__DIR__) . '/templates/related-category.php';
$lhtml = ob_get_clean();
unset($GLOBALS['kop_related_list']);
$check('the category list has both approved records by state', strpos($lhtml, 'Missouri') !== false && strpos($lhtml, 'Colorado') !== false
    && strpos($lhtml, 'Test Pending Rehab') === false && strpos($lhtml, '2 on record.') !== false);
$check('the list names the company with its page', strpos($lhtml, 'Run by <a href=') !== false);

echo "-- Young adult programs moved in --\n";
require_once dirname(__DIR__) . '/inc/young-adult-programs.php';
$old = $pdo->query('SELECT * FROM main.young_adult_programs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$log = kop_ya_move_to_related($pdo);
$moved = kop_ya_all($pdo, null);
$want = array_map(function ($r) { return KOP_RELATED_FIRST_ID + (int) $r['id']; }, $old);
$have = array_column($moved, 'id');
sort($want);
sort($have);
$check('every program is a record at its old id + ' . KOP_RELATED_FIRST_ID, $have === $want, implode(' | ', array_slice($log, 0, 3)));
$same = 0;
$diff = array();
foreach ($old as $r) {
    $p = kop_ya_get($pdo, (int) $r['id']);
    $ok = $p && $p['name'] === $r['name'] && $p['other_names'] === (string) $r['other_names'] && $p['city'] === $r['city'] && $p['ages'] === $r['ages']
        && $p['program_type'] === $r['program_type'] && $p['run_by'] === $r['run_by'] && $p['status'] === $r['status'] && $p['notes'] === (string) $r['notes']
        && $p['links'] === (string) $r['links'] && $p['review'] === $r['review'] && (int) $p['opened'] === (int) $r['opened'] && (int) $p['closed'] === (int) $r['closed']
        && json_decode($p['facts'], true) == (json_decode((string) $r['facts'], true) ?: array()) && (int) $p['document_folder_id'] === (int) $r['document_folder_id'];
    if ($ok) $same++; else $diff[] = $r['id'] . ' ' . $r['name'];
}
$check('each reads back as its old row (old ids still find it)', $same === count($old), implode('; ', $diff));
$opi = kop_ya_find_by_name($pdo, 'OPI');
$orec = $opi ? kop_related_get($opi['id']) : null;
$check('its facts are on the document where a facility\'s go', $orec && count($orec['doc']['staff']['notableStaff']) >= 10 && $orec['doc']['notes']);
$data = $opi ? kop_facility_page_data((int) $opi['id']) : null;
$check('its page is the facility page, under Young Adult Programs', $data && $data['related']['label'] === 'Young Adult Programs' && count($data['staff']) > 0);
$check('a program run by a company on file is linked to it (Embark Behavioral Health)', (bool) preg_grep('/Linked to company \d+ \(Embark/', $log));
$check('the move runs once', count(preg_grep('/is record \d+ already/', kop_ya_move_to_related($pdo))) === count($old));

echo "-- Delete --\n";
kop_related_delete($id2);
$check('a deleted record and its links are gone', kop_related_get($id2) === null && !$wpdb->get_var("SELECT COUNT(*) FROM wpdl_kop_operator_facilities WHERE facility_id = $id2"));

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
