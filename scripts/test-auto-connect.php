<?php
/**
 * inc/auto-connect.php: which waiting import items connect themselves (a name
 * exactly one record's) and which stay for approval, on a scratch copy of
 * tmp/prod.sqlite. Checks every pick is an exact name of its record (read
 * again from facilities_v2, not through the queue's own matcher), links the
 * wiki picks, logs them as 'auto' with Undo, and never links an undone one
 * again. Woodbury filing and Fornits links need MySQL and the media library,
 * so only their picks are checked.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-auto-connect.php [--db=tmp/prod.sqlite] [--list]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$list = isset($args['list']);
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

$db_path = sys_get_temp_dir() . '/kop-auto-connect-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
foreach (array('facilities_v2', 'wiki_submissions', 'wpdl_options', 'wpdl_kop_operators', 'wpdl_kop_woodbury_mentions', 'wpdl_kop_fornits_items',
        'wpdl_kop_fornits_topics', 'wpdl_kop_state_list_rows', 'wpdl_kop_gdoc_links', 'referrers_master', 'wpdl_referrers_master', 'providers_master',
        'wpdl_providers_master', 'transporters_master', 'wpdl_transporters_master') as $t) {
    $sql = $copy->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$copy->exec('DETACH DATABASE src');
$copy = null;
register_shutdown_function(function () use ($db_path) { restore_error_handler(); $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
if (!function_exists('kop_seed_pdo')) {
    function kop_seed_pdo() { return $GLOBALS['pdo']; }
}
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new class { public $user_login = 'test'; public function exists() { return true; } }; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
}
if (!function_exists('kop_facility_finder_label')) {
    require_once dirname(__DIR__) . '/inc/facility-finder.php';
}
require_once dirname(__DIR__) . '/inc/review-inbox.php';
require_once dirname(__DIR__) . '/inc/woodbury-mentions.php';
require_once dirname(__DIR__) . '/inc/closure-reports.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/fornits.php';
require_once dirname(__DIR__) . '/inc/state-lists.php';
require_once dirname(__DIR__) . '/inc/wiki-updates.php';
require_once dirname(__DIR__) . '/inc/auto-connect.php';
$pdo = $GLOBALS['pdo'];
$GLOBALS['kop_test_options']['kop_review_inbox_db'] = KOP_REVIEW_INBOX_DB_VERSION;
$GLOBALS['kop_test_options']['kop_auto_connect_db'] = KOP_AUTO_CONNECT_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_log (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_key TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, action_label TEXT NOT NULL DEFAULT '', style TEXT NOT NULL DEFAULT '', message TEXT,
    undo_action TEXT NOT NULL DEFAULT '', undo_params TEXT, user_login TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, undone_at TEXT, undone_by TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_holds (source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    assigned_to INTEGER NOT NULL DEFAULT 0, snooze_until TEXT, research INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_auto_connect (source TEXT NOT NULL, item_key TEXT NOT NULL, target TEXT NOT NULL DEFAULT '',
    outcome TEXT NOT NULL DEFAULT 'done', note TEXT, created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$has = function ($t) use ($pdo) {
    return (bool) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
};

/* The independent check: every name of each record as a plain key. */
$key = 'kop_autoconn_key';   // scripts/woodbury-scan.py key(), which the queues' matches were made with
$names = array();   // facility id => [key => true]
$holders = array(); // key => [facility id => true]
foreach ($pdo->query('SELECT id, name, unique_name, json_data FROM facilities_v2') as $f) {
    $d = json_decode((string) $f['json_data'], true) ?: array();
    $ident = (array) ($d['identification'] ?? array());
    $all = array($f['name'], $f['unique_name'], $ident['name'] ?? '', $ident['currentName'] ?? '');
    foreach (array('otherNames', 'pastNames') as $k) {
        foreach ((array) ($ident[$k] ?? array()) as $n) $all[] = is_array($n) ? ($n['name'] ?? '') : $n;
    }
    foreach ($all as $n) {
        $k = $key($n);
        if ($k === '') continue;
        $names[(int) $f['id']][$k] = true;
        $holders[$k][(int) $f['id']] = true;
    }
}

/* ---- Woodbury Reports pages ---- */
echo "-- Woodbury Reports --\n";
if ($has('wpdl_kop_woodbury_mentions')) {
    // The site's hourly pass has filed every page it could; put the last 20 it filed back to waiting.
    $pdo->exec("UPDATE wpdl_kop_woodbury_mentions SET status = 'pending', reviewed_by = '', reviewed_at = NULL
        WHERE id IN (SELECT id FROM wpdl_kop_woodbury_mentions WHERE status = 'filed' AND reviewed_by = 'auto' ORDER BY reviewed_at DESC LIMIT 20)");
    $picks = kop_autoconn_woodbury_rows(100000);
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_woodbury_mentions WHERE status = 'pending'")->fetchColumn();
    $check('woodbury: some waiting pages connect themselves, the rest wait', $picks && count($picks) < $waiting, count($picks) . ' of ' . $waiting);
    $bad = array();
    foreach ($picks as $r) {
        $fid = (int) $r['facility_id'];
        $found = $r['kind'] === 'section' ? array($r['header']) : explode(', ', (string) $r['matched_name']);
        $exact = false;
        foreach ($found as $n) {
            $k = $key($n);
            if (isset($names[$fid][$k]) && count($holders[$k] ?? array()) === 1 && kop_autoconn_distinctive($n)) $exact = true;
        }
        if (!$exact) $bad[] = '#' . $r['id'] . ' ' . ($r['header'] ?: $r['matched_name']) . ' -> ' . $r['facility_name'];
        if ($list) echo '  ' . $r['kind'] . ': "' . ($r['kind'] === 'section' ? $r['header'] : $r['matched_name']) . '" -> ' . $r['facility_name'] . ' (' . $r['facility_state'] . ")\n";
    }
    $check('woodbury: every pick is an exact, distinctive name of its record and of no other', !$bad, count($bad) . ' not: ' . implode('; ', array_slice($bad, 0, 5)));
    $kinds = array_count_values(array_column($picks, 'kind'));
    $check('woodbury: close names and pages with other possible records never connect themselves',
        !isset($kinds['fuzzy']) && !array_filter($picks, function ($r) { return trim((string) $r['alternatives'], '[] ') !== ''; }), json_encode($kinds));
} else {
    echo "  (no Woodbury table in the mirror)\n";
}

/* ---- Wiki links ---- */
echo "-- Wiki links --\n";
$check('wiki: exactly one same-name record is a pick', kop_autoconn_wiki_exact(array(
    array('unique_name' => 'a', 'reason' => 'same name, same state', 'score' => 100),
    array('unique_name' => 'b', 'reason' => 'every word of the name, same state', 'score' => 65))) !== null);
$check('wiki: two records of the name wait', kop_autoconn_wiki_exact(array(
    array('unique_name' => 'a', 'reason' => 'same name, same state', 'score' => 100),
    array('unique_name' => 'b', 'reason' => 'a past name of the record, same state', 'score' => 80))) === null);
$check('wiki: a record whose name only begins with the entry\'s waits', kop_autoconn_wiki_exact(array(
    array('unique_name' => 'a', 'reason' => 'the record\'s name begins with the entry\'s name, same town', 'score' => 105))) === null);
$check('wiki: a record listing the name as its current name waits', kop_autoconn_wiki_exact(array(
    array('unique_name' => 'a', 'reason' => 'the record\'s current name, same state', 'score' => 90))) === null);

$wpicks = kop_autoconn_wiki_picks($pdo);
$entries = kop_wiki_upd_entries($pdo);
$unlinked = count(array_filter($entries, function ($e) { return $e['kind'] !== 'list' && trim((string) $e['facility_unique_name']) === ''; }));
echo '  ' . count($wpicks) . ' of ' . $unlinked . " unlinked entries have one exact record\n";
$check('wiki: a generic name ("The Academy") never links itself', !array_filter($wpicks, function ($p) { return !array_filter(kop_wiki_upd_entry_names($p[0]), 'kop_autoconn_distinctive'); }));
if (!$wpicks) {
    // None in the mirror: give one unlinked program entry (on this scratch copy) the exact name of a record in its states.
    foreach ($entries as $eid => $e) {
        if ($e['kind'] !== 'program' || trim((string) $e['facility_unique_name']) !== '') continue;
        $states = kop_wiki_upd_states($e);
        foreach ($pdo->query('SELECT id, name, state FROM facilities_v2 ORDER BY id') as $f) {
            if (($states && !in_array($f['state'], $states, true)) || !kop_wiki_upd_tokens($f['name']) || count($holders[$key($f['name'])] ?? array()) !== 1) continue;
            $pdo->prepare('UPDATE wiki_submissions SET program_name = ? WHERE id = ?')->execute(array($f['name'], $eid));
            break;
        }
        $wpicks = kop_autoconn_wiki_picks($pdo);
        if ($wpicks) break;
        $pdo->prepare('UPDATE wiki_submissions SET program_name = ? WHERE id = ?')->execute(array($e['program_name'], $eid));
    }
    echo '  (made-up: ' . implode(', ', array_map(function ($p) { return '"' . $p[0]['program_name'] . '"'; }, $wpicks)) . ")\n";
}
$bad = array();
foreach ($wpicks as $id => list($e, $c)) {
    $ek = array_map($key, kop_wiki_upd_entry_names($e));
    $fid = (int) ($c['id'] ?? 0);
    if ($fid > 0 && !array_intersect($ek, array_keys($names[$fid] ?? array()))) $bad[] = $e['program_name'] . ' -> ' . $c['name'];
    if ($list) echo '  "' . $e['program_name'] . '" -> ' . $c['name'] . ' (' . $c['reason'] . ")\n";
}
$check('wiki: every pick shares an exact name with its record', !$bad, implode('; ', array_slice($bad, 0, 5)));

$GLOBALS['kop_test_options']['kop_wiki_link_log'] = array();
$n = kop_autoconn_wiki(microtime(true) + 600);
$check('wiki: the run links every pick', $n === count($wpicks), $n . ' linked');
$id = (int) array_key_first($wpicks);
if ($id) {
    $st = $pdo->prepare('SELECT facility_unique_name, facility_link_status FROM wiki_submissions WHERE id = ?');
    $st->execute(array($id));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $check('wiki: a link is saved as suggested, to the exact record', $row['facility_unique_name'] === $wpicks[$id][1]['unique_name'] && $row['facility_link_status'] === 'suggested');
    $log = $pdo->query("SELECT * FROM wpdl_kop_review_log WHERE source = 'wiki-links' AND item_key = '" . $id . "'")->fetch(PDO::FETCH_ASSOC);
    $check('wiki: Recently done shows it by auto, with Undo', $log && $log['user_login'] === 'auto' && $log['undo_action'] === 'undo', $log['message'] ?? '');
    kop_wiki_upd_unlink($pdo, $id);
    kop_autoconn_wiki(microtime(true) + 600);
    $st->execute(array($id));
    $check('wiki: an entry a person undid is not linked again', trim((string) $st->fetchColumn()) === trim((string) $entries[$id]['facility_unique_name']));
}

/* ---- Fornits ---- */
echo "-- Fornits --\n";
if ($has('wpdl_kop_fornits_items')) {
    $fpicks = kop_autoconn_fornits_rows(100000);
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_fornits_items WHERE kind = 'link' AND status = 'pending'")->fetchColumn();
    $check('fornits: some discussion links connect themselves, the rest wait', $fpicks && count($fpicks) < $waiting, count($fpicks) . ' of ' . $waiting);
    $check('fornits: only names read in the posts, never a board or title alone, never a name other records share',
        !array_filter($fpicks, function ($r) {
            return trim((string) $r['also'], '[] ') !== '' || !preg_match('/opening post|named in \d+ posts/', $r['how']);
        }));
} else {
    echo "  (no Fornits table in the mirror)\n";
}

/* ---- State Lists ---- */
echo "-- State Lists --\n";
if ($has('wpdl_kop_state_list_rows')) {
    $spicks = kop_autoconn_state_list_picks($pdo);
    $bad = array();
    foreach ($spicks as $rid => $fid) {
        $r = kop_sl_row($pdo, $rid);
        $k = $key($r['name']);
        if (!isset($names[$fid][$k])) $bad[] = $r['name'];
    }
    $check('state lists: every pick is the record\'s exact name', !$bad, count($spicks) . ' picks; ' . implode('; ', array_slice($bad, 0, 5)));
} else {
    echo "  (no State Lists table in the mirror)\n";
}

/* ---- Names made of common words ---- */
echo "-- Distinctive names --
";
foreach (array('Lake House Academy', 'West Ridge Academy', 'Spring Creek Lodge Academy', 'Mountain Valley Treatment Center', 'Aspen Ranch') as $n) {
    $check("one program's name: $n", kop_autoconn_distinctive($n));
}
foreach (array('Wilderness Treatment Center', 'Youth Care', 'Ranch School', 'Family Life Center', "Children's Village", 'Lake Academy') as $n) {
    $check("generic words wait: $n", !kop_autoconn_distinctive($n));
}

/* ---- Drive Docs ---- */
echo "-- Drive Docs --
";
if ($has('wpdl_kop_gdoc_links')) {
    if (!function_exists('kop_gdl_table')) require_once dirname(__DIR__) . '/inc/drive-docs.php';
    if (!function_exists('kop_facility_state_code')) require_once dirname(__DIR__) . '/inc/facility-store.php';
    $prog = function ($doc, $src = 'sciad') { return kop_autoconn_drive_program(array('source' => $src, 'source_doc' => $doc)); };
    $check('drive docs: a SCIAD collection names its program and state', $prog('SCIAD NET: Utah / Diamond Ranch Academy / News') === array('Diamond Ranch Academy', 'UT'));
    $check('drive docs: a company collection drops "HQ"', $prog('SCIAD NET: Tennessee / Acadia Healthcare HQ / News') === array('Acadia Healthcare', 'TN'));
    $check('drive docs: general collections name no program', $prog('SCIAD NET: General Non-Program Specific / News') === null && $prog('SCIAD NET: Not in a collection') === null);
    $check('drive docs: a wiki page names its program without its years', $prog('r/troubledteens wiki: page "Synanon (1958-1991)" (as of 2026-01-07)', 'wiki') === array('Synanon', ''));
    // The site has tied every link it could; untie 50 of its exact-name ties so there is something to tie.
    $pdo->exec("UPDATE wpdl_kop_gdoc_links SET facility_id = 0, facility_how = ''
        WHERE id IN (SELECT id FROM wpdl_kop_gdoc_links WHERE status = 'pending' AND facility_how = 'SCIAD collection name (exact)' ORDER BY id LIMIT 50)");
    // Rite of Passage's two company records were merged on the site: a second one with the same name, for the shared-name check.
    $pdo->exec('CREATE TEMP TABLE rop AS SELECT * FROM wpdl_kop_operators WHERE id = (SELECT MIN(id) FROM wpdl_kop_operators WHERE name = \'Rite of Passage, Inc.\')');
    $pdo->exec('UPDATE rop SET id = (SELECT MAX(id) + 1 FROM wpdl_kop_operators)');
    $pdo->exec('INSERT INTO wpdl_kop_operators SELECT * FROM rop');
    $before = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_gdoc_links WHERE status = 'pending' AND facility_id = 0")->fetchColumn();
    $tied = kop_autoconn_drive_rematch();
    $check('drive docs: links with no record are tied to the record their collection or page names exactly', $tied > 0 && $tied < $before, "$tied of $before");
    $bad = array();
    foreach ($pdo->query("SELECT source, source_doc, facility_id FROM wpdl_kop_gdoc_links WHERE facility_how IN ('SCIAD collection name (exact)', 'wiki page title (exact)') AND status = 'pending' GROUP BY source_doc") as $r) {
        $p = kop_autoconn_drive_program($r);
        if (!$p) continue;
        $hit = false;
        foreach (array_merge(array($p[0]), explode('/', $p[0])) as $part) $hit = $hit || isset($names[(int) $r['facility_id']][$key(trim($part))]);
        if (!$hit) $bad[] = $r['source_doc'];
    }
    $check('drive docs: every tie is an exact name of its record', !$bad, implode('; ', array_slice($bad, 0, 5)));
    if ($list) {
        foreach ($pdo->query("SELECT source_doc, facility_id, COUNT(*) n FROM wpdl_kop_gdoc_links WHERE status = 'pending' AND facility_how IN ('SCIAD collection name (exact)', 'wiki page title (exact)') GROUP BY source_doc, facility_id ORDER BY n DESC") as $r) {
            $nm = $pdo->query('SELECT name FROM facilities_v2 WHERE id = ' . (int) $r['facility_id'])->fetchColumn();
            echo "  {$r['n']}  {$r['source_doc']} -> $nm
";
        }
    }
    // Company-only links go on the company's page when its name is exactly one company record.
    $check('drive docs: a company name finds its one record', kop_gdl_company_id('Straight Inc.') > 0 && kop_gdl_company_id('Straight, Inc') === kop_gdl_company_id('Straight Inc.'));
    $check('drive docs: a name two company records share finds none', kop_gdl_company_id('Rite of Passage, Inc.') === 0);
    $acadia = kop_autoconn_drive_program(array('source' => 'sciad', 'source_doc' => 'SCIAD NET: Tennessee / Acadia Healthcare HQ / News'));
    $check('drive docs: a collection named for a company ("Acadia Healthcare HQ") finds the company', $acadia && kop_gdl_company_id($acadia[0]) > 0);
    $oid = kop_gdl_company_id('Straight Inc.');
    $crows = $pdo->query("SELECT * FROM wpdl_kop_gdoc_links WHERE status = 'pending' AND facility_id = 0 AND operator_name <> '' AND kind IN ('reference', 'other', 'archive', 'social') ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
    if ($oid && $crows) {
        $before_json = $pdo->query('SELECT json_data FROM wpdl_kop_operators WHERE id = ' . $oid)->fetchColumn();
        $res = kop_gdl_apply_company($crows, $oid, 'auto');
        $after = json_decode($pdo->query('SELECT json_data FROM wpdl_kop_operators WHERE id = ' . $oid)->fetchColumn(), true);
        $urls = array_column((array) ($after['operator']['resourceLinks'] ?? array()), 'url');
        $check('drive docs: Add to a company puts the links in its "Materials and links"', count(array_filter($res, function ($x) { return !empty($x['ok']); })) === count($crows)
            && !array_diff(array_column($crows, 'url'), $urls), count($urls) . ' links on the company');
        $page = function_exists('kop_facility_pages_resource_links') ? kop_facility_pages_resource_links($after['operator']['resourceLinks']) : null;
        $check('drive docs: the company page groups them as a facility page does', $page === null || count($page) > 0);
        $again = kop_gdl_rows(array($crows[0]['pkey']));
        $again[0]['status'] = 'pending';
        $dup = kop_gdl_apply_company($again, $oid, 'auto');
        $check('drive docs: a link already on the company page is not added twice', empty($dup[$crows[0]['pkey']]['ok']));
        $pdo->exec("UPDATE wpdl_kop_gdoc_links SET status = 'applied', applied = " . $pdo->quote(json_encode(array('target' => 'company', 'place' => 'resource', 'operator_id' => $oid, 'url' => $crows[0]['url']))) . ' WHERE pkey = ' . $pdo->quote($crows[0]['pkey']));
        $undo = kop_gdl_undo(kop_gdl_rows(array_column($crows, 'pkey')), 'test');
        $restored = $pdo->query('SELECT json_data FROM wpdl_kop_operators WHERE id = ' . $oid)->fetchColumn();
        $left = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_gdoc_links WHERE status = 'pending' AND pkey IN (" . implode(',', array_map(array($pdo, 'quote'), array_column($crows, 'pkey'))) . ')')->fetchColumn();
        $check('drive docs: Undo takes them off the company page and back to waiting',
            !array_intersect(array_column($crows, 'url'), array_column((array) (json_decode($restored, true)['operator']['resourceLinks'] ?? array()), 'url')) && $left === count($crows));
        $pdo->prepare('UPDATE wpdl_kop_operators SET json_data = ? WHERE id = ?')->execute(array($before_json, $oid));
    }
    // News is only ever added by a person (owner, 2026-10-20).
    $check('drive docs: no pass sends news links by itself', !function_exists('kop_autoconn_drive_news')
        && kop_autoconn_drive_is_news(array('kind' => 'news')) && !kop_autoconn_drive_is_news(array('kind' => 'reference')));
    $dpicks = $pdo->query('SELECT * FROM wpdl_kop_gdoc_links WHERE ' . kop_autoconn_drive_where())->fetchAll(PDO::FETCH_ASSOC);
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_gdoc_links WHERE status = 'pending'")->fetchColumn();
    $check('drive docs: exact-name links add themselves, the rest wait', $dpicks && count($dpicks) < $waiting, count($dpicks) . ' of ' . $waiting);
    $check('drive docs: never a close name, a website-only match, another record named, or a link a person touched',
        !array_filter($dpicks, function ($r) {
            return stripos($r['facility_how'], 'close') !== false || in_array($r['facility_how'], array('', 'website on record'), true)
                || trim((string) $r['also_named'], '[] ') !== '' || (string) $r['reviewed_by'] !== '';
        }));
} else {
    echo "  (no Drive Docs table in the mirror)
";
}

/* ---- Nonprofit tax filings and business filings ---- */
echo "-- Nonprofit and business filings --\n";
require_once dirname(__DIR__) . '/inc/nonprofit-filings.php';
require_once dirname(__DIR__) . '/inc/business-filings.php';
foreach (array('nonprofit-filings' => kop_npf_candidates(), 'business-filings' => kop_bizf_candidates()) as $src => $entries) {
    if (!$entries) { echo "  ($src: no candidates file)\n"; continue; }
    $matched = array_filter($entries, function ($e) { return ($e['match'] ?? '') === 'matched'; });
    // A card someone already decided is never touched.
    $decided = array_slice(array_keys($matched), 0, 1);
    $decisions = $decided ? array($decided[0] => array('decision' => 'rejected')) : array();
    $picks = kop_autoconn_filing_picks($src, $entries, $decisions);
    $check("$src: every \"Same name and town\" card links itself, \"Check the match\" waits",
        count($picks) === count($matched) - count($decided) && count($picks) < count($entries), count($picks) . ' of ' . count($entries));
    $check("$src: a decided card is left alone", !$decided || !isset($picks[$decided[0]]));
    $applied = array();
    $n = kop_autoconn_filings($src, microtime(true) + 60, $entries, $decisions, function ($k, $kind, $id, $by) use (&$applied) {
        $applied[$k] = array($kind, $id, $by);
    });
    $check("$src: linked to the card's one record, by 'auto'", $n === count($picks) && !array_filter($applied, function ($a) { return $a[2] !== 'auto' || $a[1] <= 0; }));
    $logged = (int) $pdo->query("SELECT COUNT(*) FROM wpdl_kop_review_log WHERE action = 'auto' AND source = " . $pdo->quote($src))->fetchColumn();
    $check("$src: each shows in Recently done with Undo", $logged === $n, "$logged logged");
    $check("$src: one undone is never linked again", !kop_autoconn_filing_picks($src, $entries, $decisions));
}

echo $failures ? "\n$failures failed\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
