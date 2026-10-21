<?php
/**
 * Investors (inc/investors.php) against the SQLite mirror of production: the
 * free-text entries read into name, role and years; the one-time import
 * (Sequel TSI's four investors, Paradigm's one); the Investors line of the
 * company page and of a Sequel program's facility page; add, edit, link, link
 * edit, take off and delete, each undone exactly; Undo refused once a later
 * change touched the row; the merge, record-to-company and company-ref lists.
 * The investor tables live in a temp database with tmp/prod.sqlite attached
 * beside it, so the mirror is never written.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite scripts/test-investors.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$prod = dirname(__DIR__) . '/tmp/prod.sqlite';
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

$db_path = sys_get_temp_dir() . '/kop-investors-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec('CREATE TABLE wpdl_kop_investors (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL DEFAULT \'\',
    kind TEXT NOT NULL DEFAULT \'\', website TEXT NOT NULL DEFAULT \'\', about TEXT NULL, sources TEXT NULL, created_by TEXT NOT NULL DEFAULT \'\',
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
$copy->exec('CREATE TABLE wpdl_kop_investor_links (id INTEGER PRIMARY KEY AUTOINCREMENT, investor_id INTEGER NOT NULL, operator_id INTEGER NOT NULL DEFAULT 0,
    facility_id INTEGER NOT NULL DEFAULT 0, role TEXT NOT NULL DEFAULT \'\', start_year INTEGER NULL, end_year INTEGER NULL, detail TEXT NOT NULL DEFAULT \'\',
    source_url TEXT NOT NULL DEFAULT \'\', created_by TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL)');
$copy->exec('CREATE TABLE wpdl_kop_investor_log (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, investor_id INTEGER NOT NULL DEFAULT 0,
    link_id INTEGER NOT NULL DEFAULT 0, before_json TEXT NULL, after_json TEXT NULL, note TEXT NOT NULL DEFAULT \'\', done_by TEXT NOT NULL DEFAULT \'\',
    done_at TEXT NOT NULL, undone_by TEXT NOT NULL DEFAULT \'\', undone_at TEXT NULL)');
$copy = null;

require __DIR__ . '/kop-test-harness.php';
$GLOBALS['kop_test_wpdb_writes'] = true;
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($prod) . ' AS prod');
$pdo->sqliteCreateFunction('JSON_UNQUOTE', function ($v) {
    if (!is_string($v)) return $v;
    $d = json_decode($v);
    return is_string($d) ? $d : $v;
}, 1);

require_once dirname(__DIR__) . '/inc/choice-lists.php';
if (file_exists(dirname(__DIR__) . '/inc/record-field.php')) require_once dirname(__DIR__) . '/inc/record-field.php';
require_once dirname(__DIR__) . '/inc/investors.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "\n       " . $detail) . "\n";
    if (!$ok) $failures++;
};
$count = function ($t) use ($pdo) { return (int) $pdo->query("SELECT COUNT(*) FROM main.wpdl_kop_$t")->fetchColumn(); };

// ---- Words ----------------------------------------------------------------
$check('name key: "Alaris Royalty Corp." == "Alaris Royalty"', kop_inv_name_key('Alaris Royalty Corp.') === kop_inv_name_key('Alaris Royalty'));
$check('name key: "&" is "and", case and spacing ignored', kop_inv_name_key('Smith & Co  Partners, L.P.') === kop_inv_name_key('smith and co partners'));
$cases = array(
    'Patriot Capital (growth financing, exited 2008)' => array('Patriot Capital', 'Lender or financing', null, 2008),
    'Levine Leichtman Capital Partners (minority investor, 2009-2013)' => array('Levine Leichtman Capital Partners', 'Minority investor', 2009, 2013),
    'Alaris Royalty Corp. (preferred units, 2013-2017)' => array('Alaris Royalty Corp.', 'Preferred equity or royalty', 2013, 2017),
    'Altamont Capital Partners (majority owner from 2017)' => array('Altamont Capital Partners', 'Majority owner', 2017, null),
    'Pine Tree Equity Partners (2019)' => array('Pine Tree Equity Partners', 'Investor', 2019, null),
    'Patriot Capital (prior to 2008)' => array('Patriot Capital', 'Investor', null, 2008),
    'Bain Capital' => array('Bain Capital', 'Investor', null, null),
);
foreach ($cases as $text => $want) {
    $p = kop_inv_parse_entry($text);
    $got = array($p['name'], $p['role'], $p['start_year'], $p['end_year']);
    $check('parse: ' . $text, $got === $want, json_encode($got));
}
$check('years label', kop_inv_years_label(2009, 2013) === '2009 to 2013' && kop_inv_years_label(2017, null) === 'from 2017'
    && kop_inv_years_label(null, 2008) === 'until 2008' && kop_inv_years_label(null, null) === '');
$check('choice lists registered', in_array('Private equity firm', kop_inv_kinds(), true) && in_array('Majority owner', kop_inv_roles(), true));

// ---- Import ---------------------------------------------------------------
$entries = kop_inv_unlinked_entries();
$by = array();
foreach ($entries as $e) $by[$e['kind'] . ':' . $e['id']][] = $e['text'];
$check('unlinked: Sequel TSI\'s four entries and Paradigm\'s one, no provenance copies', count($by['company:20'] ?? array()) === 4
    && count($by['company:33'] ?? array()) === 1 && !array_filter(array_keys($by), function ($k) { return strpos($k, 'facility:') === 0; }), json_encode($by));

kop_inv_maybe_import();
$check('import: once, at the first admin load', get_option('kop_inv_import') === KOP_INV_IMPORT_VERSION);
// One investor and one link per unlinked entry (company #500017 gained two since 2026-10: CIC Partners, Cressey & Company).
$n = count($entries);
$check("import: $n investors, $n links, " . (2 * $n) . ' log rows', $n >= 5 && $count('investors') === $n && $count('investor_links') === $n && $count('investor_log') === 2 * $n,
    $count('investors') . '/' . $count('investor_links') . '/' . $count('investor_log'));
$check('import: nothing left unlinked', kop_inv_unlinked_entries() === array());
$alt = kop_inv_find_by_name('Altamont Capital Partners');
$altl = $alt ? kop_inv_links(array('investor_id' => $alt['id'])) : array();
$check('import: Altamont is a private equity firm, majority owner of company 20 from 2017', $alt && $alt['kind'] === 'Private equity firm'
    && count($altl) === 1 && (int) $altl[0]['operator_id'] === 20 && $altl[0]['role'] === 'Majority owner' && (int) $altl[0]['start_year'] === 2017
    && $altl[0]['detail'] === 'majority owner from 2017');
$check('import: Alaris is royalty finance', ($a = kop_inv_find_by_name('Alaris Royalty')) && $a['kind'] === 'Royalty or specialty finance');
kop_inv_maybe_import();
$check('import: a second run adds nothing', $count('investors') === $n && $count('investor_links') === $n);

// ---- Pages ----------------------------------------------------------------
$free = array('Patriot Capital (growth financing, exited 2008)', 'Somebody Else LLC');
$items = kop_inv_fact_items('company', array(20), $free);
$check('company line: linked investors, the free-text copy of a linked one dropped, an unlinked one kept',
    in_array('Altamont Capital Partners (majority owner, from 2017)', $items, true)
    && in_array('Levine Leichtman Capital Partners (minority investor, 2009 to 2013)', $items, true)
    && in_array('Patriot Capital (lender or financing, until 2008)', $items, true)
    && in_array('Somebody Else LLC', $items, true) && !in_array($free[0], $items, true) && count($items) === 5, json_encode($items));
$items = kop_inv_fact_items('company', array(33));
$check('company line: Paradigm', $items === array('Pine Tree Equity Partners (2019)') || $items === array('Pine Tree Equity Partners (from 2019)'), json_encode($items));
$fid = (int) $pdo->query("SELECT facility_id FROM prod.wpdl_kop_operator_facilities WHERE operator_id = 20 AND relationship = 'current' ORDER BY facility_id LIMIT 1")->fetchColumn();
$items = kop_inv_fact_items('facility', array($fid));
$check('facility line: a Sequel program names Sequel\'s investors "of Sequel TSI"',
    in_array('Altamont Capital Partners (majority owner of Sequel TSI, from 2017)', $items, true) && count($items) === 4, json_encode($items));
if (function_exists('kop_operator_page_data')) {
    try {
        $page = kop_operator_page_data(20);
        $line = array();
        foreach ((array) ($page['facts'] ?? array()) as $f) if ($f['label'] === 'Investors') $line = $f['value'];
        $check('company page data: the Investors fact is the linked list', in_array('Altamont Capital Partners (majority owner, from 2017)', (array) $line, true), json_encode($line));
    } catch (Throwable $ex) {
        $check('company page data builds', false, $ex->getMessage());
    }
}

// ---- Search -----------------------------------------------------------------
$hits = kop_inv_search('altamont', 4);
$check('search: "altamont" finds Altamont, opening Sequel TSI\'s page', count($hits) === 1 && $hits[0]['title'] === 'Altamont Capital Partners'
    && $hits[0]['url'] === kop_inv_record_url('company', 20) && $hits[0]['url'] !== '' && $hits[0]['meta'] === 'Investor - majority owner of Sequel TSI', json_encode($hits));
$hits = kop_inv_search('Alaris Royalty Corporation', 4);
$check('search: another legal suffix still finds Alaris', count($hits) === 1 && $hits[0]['title'] === 'Alaris Royalty Corp.', json_encode($hits));
$hits = kop_inv_search('capital', 10);
$check('search: "capital" finds the three Capital investors, A to Z', array_column($hits, 'title') === array('Altamont Capital Partners', 'Levine Leichtman Capital Partners', 'Patriot Capital'), json_encode(array_column($hits, 'title')));
$check('search: one letter finds nothing', kop_inv_search('a', 4) === array());
$solo = kop_inv_add(array('name' => 'Unlinked Capital Test'), 'test');
$check('search: an investor with no stake on a page is left out', !array_filter(kop_inv_search('unlinked capital', 4), function ($h) { return $h['title'] === 'Unlinked Capital Test'; }));
kop_inv_undo((int) $pdo->query('SELECT MAX(id) FROM main.wpdl_kop_investor_log')->fetchColumn(), 'test');
$check('search: wired into the dropdown, the popup and the search page', strpos(file_get_contents(dirname(__DIR__) . '/inc/ajax-search-lite.php'), 'kop_inv_search(') !== false
    && strpos(file_get_contents(dirname(__DIR__) . '/inc/global-search.php'), 'kop_inv_search(') !== false && strpos(file_get_contents(dirname(__DIR__) . '/search.php'), 'kop_inv_search(') !== false);

// ---- Writes and exact Undo --------------------------------------------------
$last = function () use ($pdo) { return (int) $pdo->query('SELECT MAX(id) FROM main.wpdl_kop_investor_log')->fetchColumn(); };
$snap = function () use ($pdo) {
    $out = array();
    foreach (array('investors', 'investor_links') as $t) $out[$t] = $pdo->query("SELECT * FROM main.wpdl_kop_$t ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    return json_encode($out);
};
$start = $snap();

$id = kop_inv_add(array('name' => 'Test Equity Partners', 'kind' => 'Private equity firm', 'website' => 'test.example', 'sources' => "https://example.test/a\n"), 'test');
$t = kop_inv_get($id);
$check('add: website gets https, sources stored as JSON', $t['website'] === 'https://test.example' && json_decode($t['sources'], true) === array(array('url' => 'https://example.test/a', 'label' => '')));
$refused = '';
try { kop_inv_add(array('name' => 'test equity partners, llc'), 'test'); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
$check('add: the same name (another spelling) is refused', $refused !== '', $refused);
$add_log = $last();

kop_inv_edit($id, array('name' => 'Test Equity Partners', 'kind' => 'Lender', 'about' => 'A made-up firm.'), 'test');
$edit_log = $last();
$check('edit: saved', kop_inv_get($id)['kind'] === 'Lender' && kop_inv_get($id)['sources'] === '');

$lid = kop_inv_link_add($id, 'company:20', array('role' => 'Lender or financing', 'start_year' => 2015, 'end_year' => 2018, 'source_url' => 'https://example.test/s'), 'test');
$link_log = $last();
$check('link: Sequel TSI\'s page names it', in_array('Test Equity Partners (lender or financing, 2015 to 2018)', kop_inv_fact_items('company', array(20)), true));
$refused = '';
try { kop_inv_link_add($id, 'company:20', array('role' => 'Lender or financing', 'start_year' => 2015), 'test'); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
$check('link: the same stake twice is refused', $refused !== '');
$refused = '';
try { kop_inv_link_add($id, 'company:999999999', array(), 'test'); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
$check('link: a record not on file is refused', $refused !== '', $refused);
$refused = '';
try { kop_inv_link_add($id, (string) $fid, array('start_year' => 2020, 'end_year' => 2010), 'test'); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
$check('link: last year before the first is refused', $refused !== '');

$flid = kop_inv_link_add($id, (string) $fid, array('role' => 'Minority investor'), 'test');
$flink_log = $last();
$check('link to a program: its page names it', in_array('Test Equity Partners (minority investor)', kop_inv_fact_items('facility', array($fid)), true));

kop_inv_link_edit($lid, array('role' => 'Majority owner', 'start_year' => 2016, 'end_year' => '', 'detail' => 'bought out'), 'test');
$relink_log = $last();
$check('link edit: saved', kop_inv_link_get($lid)['role'] === 'Majority owner' && kop_inv_link_get($lid)['end_year'] === null);

kop_inv_link_remove($flid, 'test');
$unlink_log = $last();
$check('take off: gone from the program page', !in_array('Test Equity Partners (minority investor)', kop_inv_fact_items('facility', array($fid)), true));

$refused = '';
try { kop_inv_undo($add_log, 'test'); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
$check('undo add refused while edited and linked since', $refused !== '', $refused);
$refused = '';
try { kop_inv_undo($link_log, 'test'); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
$check('undo link refused while the stake was edited since', $refused !== '', $refused);

$mid = $snap();
kop_inv_delete($id, 'test');
$delete_log = $last();
$check('delete: investor and its link gone', !kop_inv_get($id) && !kop_inv_link_get($lid));
kop_inv_undo($delete_log, 'test');
$check('undo delete: everything back with the same ids', $snap() === $mid);
$refused = '';
try { kop_inv_undo($delete_log, 'test'); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
$check('undo twice is refused', $refused !== '');

kop_inv_undo($unlink_log, 'test');
$check('undo take off: the program link is back', (bool) kop_inv_link_get($flid));
kop_inv_undo($relink_log, 'test');
$check('undo link edit: the stake is as it was', kop_inv_link_get($lid)['role'] === 'Lender or financing' && (int) kop_inv_link_get($lid)['end_year'] === 2018);
kop_inv_undo($flink_log, 'test');
kop_inv_undo($link_log, 'test');
kop_inv_undo($edit_log, 'test');
kop_inv_undo($add_log, 'test');
$check('undo all in reverse: tables exactly as before the writes', $snap() === $start);

// ---- Lists other code reads ---------------------------------------------------
$src = function ($f) { return (string) file_get_contents(dirname(__DIR__) . '/' . $f); };
$check('Merge Duplicates moves program stakes (kop_fmerge_ref_tables)', strpos($src('inc/facility-merge.php'), "'kop_investor_links', 'c' => 'facility_id'") !== false);
$check('Record to company takes them off (kop_rtc_link_tables)', strpos($src('inc/record-to-company.php'), "'kop_investor_links', 'c' => 'facility_id'") !== false);
$check('a company with stakes is never deleted by an Undo (kop_record_field_company_refs)', strpos($src('inc/record-field.php'), "'kop_investor_links'      => 'operator_id'") !== false);
$check('functions.php loads it, KOP Tools lists it', strpos($src('functions.php'), "/inc/investors.php'") !== false && strpos($src('wp-plugins/kop-tools/kop-tools.php'), "'kop-investors'") !== false);

$pdo = $wpdb = $GLOBALS['wpdb'] = null;
try { unlink($db_path); } catch (Throwable $ex) { /* Windows may still hold it; it is in the temp dir. */ }
echo $failures ? "\n$failures failed.\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
