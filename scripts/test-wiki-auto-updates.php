<?php
/**
 * inc/wiki-auto-updates.php + inc/review-inbox/wiki-changes.php: a record change drafts an update for its linked
 * wiki entry. Against tmp/prod.sqlite, attached read-only under a scratch database that holds the tables written here
 * (wiki_submissions, facilities_v2); the mirror itself is never written.
 *
 *   - the site writes the same lines as kop-pipeline's scripts/wiki-script-drafts.py, on every script line of the
 *     drafts in tmp/wiki-updates/drafts/ (staff, incidents, lawsuits, deaths, names, operators, closures);
 *   - the first look at an entry is its baseline (no draft); a record change after it (a staff member added, an
 *     article linked) reaches the entry through kop_record_changed() and the hourly run, as one line each;
 *   - the card, edits, Approve (only the entry's own column), Pasted on Reddit, Undo (exact text back, the lines on
 *     To review again), Set aside (not offered again) and its Undo; a gap that leaves the record leaves the draft.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-wiki-auto-updates.php [--db=tmp/prod.sqlite] [--list]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'list'));
$mirror = realpath($args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite'));
$list = isset($args['list']);
if (!$mirror || !file_exists($mirror)) {
    fwrite(STDERR, "No mirror (run python ../kop-pipeline/scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
ini_set('memory_limit', '3G');

$db_path = sys_get_temp_dir() . '/kop-wiki-auto-' . getmypid() . '.sqlite';
@unlink($db_path);
$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
register_shutdown_function(function () use ($db_path) { restore_error_handler(); $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });
// Unqualified names find main's copies first, then the mirror's tables.
$pdo->exec('ATTACH DATABASE ' . $pdo->quote($mirror) . ' AS src');
foreach (array('wiki_submissions', 'facilities_v2', 'news_facility_links') as $t) {
    $sql = $pdo->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
    $pdo->exec(preg_replace('/^CREATE TABLE\s+("?)' . $t . '\1/i', 'CREATE TABLE main.' . $t, $sql));
    $pdo->exec("INSERT INTO main.$t SELECT * FROM src.$t");
}
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);

if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }
if (!function_exists('delete_option')) { function delete_option($name) { unset($GLOBALS['kop_test_options'][$name]); return true; } }
if (!function_exists('wp_die')) { function wp_die($m = '') { throw new RuntimeException((string) $m); } }
// The review inbox's own helpers, as the source calls them.
$GLOBALS['kop_test_sources'] = array();
function kop_rinbox_register($key, $fn) { $GLOBALS['kop_test_sources'][$key] = $fn; }
function kop_rinbox_reviewer() { return 'auto-test'; }
function kop_rinbox_facility($id) { return array('id' => (int) $id); }
require_once dirname(__DIR__) . '/inc/record-events.php';
require_once dirname(__DIR__) . '/inc/review-inbox/wiki-updates.php';   // kop_rinbox_wupd_op_label()
require_once dirname(__DIR__) . '/inc/review-inbox/wiki-changes.php';
$src = call_user_func($GLOBALS['kop_test_sources']['wiki-changes']);

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

echo "-- The lines are the script drafts' lines --\n";
$dir = dirname(__DIR__) . '/tmp/wiki-updates/drafts';
$exported = json_decode((string) @file_get_contents(dirname(__DIR__) . '/js/data/reddit-wiki/update-drafts.json'), true);
$same = 0;
$diff = array();
$by_kind = array();
foreach (glob($dir . '/*/ops.json') ?: array() as $f) {
    $eid = basename(dirname($f));
    $gaps = json_decode((string) @file_get_contents(dirname($f) . '/gaps.json'), true);
    $exp = $exported['entries'][$eid]['ops'] ?? null;
    if (!is_array($gaps) || !is_array($exp)) continue;
    $by_gid = array();
    foreach ((array) $gaps['gaps'] as $g) if (isset($g['gid'])) $by_gid[$g['gid']] = $g;
    $exp_text = array();
    foreach ($exp as $op) $exp_text[$op['id']] = $op;
    $program = kop_wiki_auto_clean_name($gaps['entry']['program_name'] ?? '');
    foreach ((array) json_decode((string) file_get_contents($f), true)['ops'] as $op) {
        // Lines the script wrote for one gap, as exported (corrected by hand, rewritten or grouped ones are not the script's).
        if (($op['by'] ?? '') !== 'script' || ($op['verdict'] ?? '') === 'dropped' || !empty($op['rewritten']) || !empty($op['final'])
            || count((array) ($op['gids'] ?? array())) !== 1 || !isset($exp_text[$op['id']])) continue;
        $g = $by_gid[$op['gids'][0]] ?? null;
        if (!$g) continue;
        $want = (string) ($exp_text[$op["id"]]["text"] ?? "");
        if ($want === "") continue;
        if (strpos($want, "\n") !== false) continue;
        switch ($g['kind']) {
            case 'staff': case 'staff_other':
                // The script names an earlier name of the record when the map puts the person there (STAFF_PLACES).
                if (strpos($want, ' ' . $program) === false) continue 2;
                $got = kop_wiki_auto_staff_line($g, $program);
                break;
            case 'incident': $got = kop_wiki_auto_incident_line($g); break;
            case 'lawsuit': $got = kop_wiki_auto_lawsuit_line($g); break;
            case 'death': $got = kop_wiki_auto_with_cite($g['text'], $g); break;
            default: continue 2;
        }
        $k = $g['kind'] === 'staff_other' ? 'staff' : $g['kind'];
        $by_kind[$k] = ($by_kind[$k] ?? 0) + 1;
        // The test host's address for our own files; a stray ")" the script leaves after some "was also" lists.
        $got = str_replace('https://example.test/', 'https://kidsoverprofits.org/', $got);
        // Since the drafts were exported, a person's other jobs on the map grew (Kris Archer): only the lists may differ.
        if ($got !== $want && strpos($got, ' was also ') !== false && strpos($got, strstr($want, ' was also ', true)) === 0
            && strpos(rtrim($got, '.'), rtrim(preg_replace('/ and ([^,]+)\.\)?$/', ', $1', $want), '.')) === 0) { $same++; continue; }
        if ($got === $want || $got . ')' === $want) $same++;
        else $diff[] = "$eid/{$op['id']} ($k)\n   site:   $got\n   script: $want";
    }
}
$total = $same + count($diff);
// One known difference: "Director of Business Development, Therapist" at one place, which the script splits in two.
$check('the site writes the script\'s line for the script lines of the exported drafts (99% or more)', $total > 200 && count($diff) <= $total / 100,
    "$same of $total the same; " . json_encode($by_kind) . ($diff ? "\n  " . implode("\n  ", array_slice($diff, 0, $list ? 200 : 8)) : ''));

echo "-- Role sentences --\n";
$cases = array(
    array('Executive Director (2011-2013)', '**Ann Roe** was the Executive Director of X from 2011 to 2013.'),
    array('Therapist', '**Ann Roe** was a therapist at X.'),
    array('Staff', '**Ann Roe** was a staff member at X.'),
    array('RN', '**Ann Roe** was an RN at X.'),
    array('Co-founder', '**Ann Roe** was a co-founder of X.'),
    array('Founded (1998)', '**Ann Roe** founded X in 1998.'),
    array('Clinical director, Former Clinical Director', '**Ann Roe** was the clinical director of X.'),
    array('', '**Ann Roe** worked at X.'),
    array('Therapist (2007-2021), left', '**Ann Roe** was a therapist at X from 2007 to 2021.'),
);
foreach ($cases as $c) {
    $got = kop_wiki_auto_staff_line(array('text' => 'Ann Roe', 'kop_source' => true, 'source_url' => '', 'detail' => array('role' => $c[0])), 'X');
    $check('role "' . $c[0] . '"', $got === $c[1], $got);
}
$got = kop_wiki_auto_staff_line(array('text' => 'Ann Roe', 'source_url' => 'https://example.org/a', 'source_label' => 'Woodbury Reports, May 2007, p. 20',
    'detail' => array('role' => 'Teacher', 'other_roles' => array('Therapist, Provo Canyon School'))), 'X');
$check('a citation is the word "source"; other jobs follow, linked to their wiki pages',
    strpos($got, '([source](https://example.org/a)).') !== false && strpos($got, 'Woodbury') === false && strpos($got, 'Roe was also a therapist at [Provo Canyon School](') !== false, $got);

echo "-- A record change drafts an update --\n";
$entries = kop_wiki_upd_entries($pdo);
$linked = kop_wiki_auto_linked($entries, $pdo);
$check('entries linked to facility records', count($linked) > 100, count($linked) . ' linked');
// An entry with a staff section and a record, the text the entry is read from not edited by a draft yet.
$eid = 0;
foreach ($linked as $id => $fid) {
    $md = kop_wiki_upd_markdown($entries[$id]);
    if (preg_match('/^#+.*(staff|founder)/im', $md) && preg_match('/^#+.*media/im', $md) && kop_facility_page_data($fid)) { $eid = $id; break; }
}
$fid = $linked[$eid] ?? 0;
$check('an entry to test with', $eid > 0, $eid ? $entries[$eid]['program_name'] . ' -> record ' . $fid : 'none');
if (!$eid) exit(1);

$res = kop_wiki_auto_run($pdo, 600, 5);
$check('the first run takes baselines and drafts nothing', kop_wiki_auto_known($eid) !== null && !kop_wiki_auto_drafts() && $res['baselines'] >= count($linked) - 5,
    json_encode($res));
$res = kop_wiki_auto_run($pdo, 600, 5);
$check('a second run with nothing changed drafts nothing', !kop_wiki_auto_drafts() && $res['left'] === 0, json_encode($res));

// The change: a new staff member on the record, saved as a writer would, and the event it fires.
$doc = json_decode($pdo->query('SELECT json_data FROM facilities_v2 WHERE id = ' . (int) $fid)->fetchColumn(), true);
$doc['staff']['notableStaff'][] = array('name' => 'Quentin Testperson', 'role' => 'Therapist (2015-2018)', 'source' => 'Example Article', 'sourceUrl' => 'https://www.sltrib.com/news/2019/quentin-article');
$pdo->prepare('UPDATE main.facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(json_encode($doc), $fid));
kop_record_changed('facility', array($fid));
// kop_record_changed() only bumps options under the harness; the listener is what add_action would call.
kop_wiki_auto_on_change('facility', array($fid));
$check('the event queues the record', isset(kop_wiki_auto_queue()['facility'][$fid]));
$res = kop_wiki_auto_run($pdo, 600, 0);
$d = kop_wiki_auto_draft($eid);
$check('the next run drafts the entry it reaches', $d && $res['queued'] >= 1 && $res['drafts'] >= 1 && !kop_wiki_auto_queue(), json_encode($res));
$staff = array_values(array_filter((array) ($d['ops'] ?? array()), function ($o) { return strpos((string) ($o['text'] ?? ''), 'Quentin Testperson') !== false; }));
$program = kop_wiki_auto_clean_name($entries[$eid]['program_name']);
$check('one line for the new staff member, in the editor\'s form, in the entry\'s staff section',
    count($d['ops']) === 1 && count($staff) === 1 && $staff[0]['op'] === 'append_to_section'
    && $staff[0]['text'] === '**Quentin Testperson** was a therapist at ' . $program . ' from 2015 to 2018 ([source](https://www.sltrib.com/news/2019/quentin-article)).',
    json_encode($d['ops'] ?? null, JSON_UNESCAPED_SLASHES));

$item = call_user_func($src['get'], (string) $eid);
$check('the card lists the line, Approve and Set aside, and the whole entry', $item && array_column($item['actions'], 'id') === array('approve', 'skip')
    && in_array($staff[0]['id'], array_column($item['fields'], 'name'), true) && strpos($item['preview']['url'], 'from=changes') !== false
    && strpos($item['text'], '1 staff member') !== false, $item['text'] ?? '');
$listed = call_user_func($src['list'], array('view' => 'review', 'search' => '', 'offset' => 0, 'limit' => 10));
$check('it is on To review and counted', $listed['total'] === 1 && call_user_func($src['count']) === 1);

// Edit the line.
call_user_func($src['save'], (string) $eid, array($staff[0]['id'] => str_replace('a therapist', 'a family therapist', $staff[0]['text'])));
$b = kop_wiki_auto_build($eid, kop_wiki_drafts_row($pdo, $eid)['markdown']);
$check('an edited line goes in as edited', strpos($b['md'], 'a family therapist at') !== false && !$b['problems'] && $b['count'] === 1);

$row = function ($id) use ($pdo) {
    $st = $pdo->prepare('SELECT original_markdown, generated_markdown, updated_at FROM wiki_submissions WHERE id = ?');
    $st->execute(array($id));
    return $st->fetch(PDO::FETCH_ASSOC);
};
$before = $row($eid);
$col = kop_wiki_drafts_row($pdo, $eid)['column'];
$other = $col === 'original_markdown' ? 'generated_markdown' : 'original_markdown';
$res = call_user_func($src['act'], (string) $eid, 'approve', array());
$after = $row($eid);
$check('Approve writes the entry\'s own column only, every earlier line kept', $after[$col] === $b['md'] && $after[$other] === $before[$other]
    && !kop_wiki_drafts_check($before[$col], $after[$col], false)[0], $res['message']);
$check('it moves to Ready for Reddit with Copy', (call_user_func($src['get'], (string) $eid)['copy']['text'] ?? '') === $after[$col]);
$res = kop_wiki_auto_run($pdo, 600, 50);
$check('the approved change is not drafted again', (kop_wiki_auto_state()[$eid]['view'] ?? '') === 'ready' && kop_wiki_auto_draft($eid)['keys'] === $d['keys']);

call_user_func($src['act'], (string) $eid, 'posted', array());
$check('Pasted on Reddit moves it to On Reddit', (kop_wiki_auto_state()[$eid]['view'] ?? '') === 'posted');
call_user_func($src['act'], (string) $eid, 'undo', array());
call_user_func($src['act'], (string) $eid, 'undo', array());
$back = $row($eid);
$check('Undo twice: the exact text and updated_at back, the line on To review again', $back[$col] === $before[$col] && $back['updated_at'] === $before['updated_at']
    && (kop_wiki_auto_state()[$eid] ?? null) === null);
kop_wiki_auto_run($pdo, 600, 50);
$check('the next run drafts it again', count(kop_wiki_auto_draft($eid)['ops'] ?? array()) === 1);

// A second change joins the waiting draft; taking it off the record takes it out of the draft.
$doc['staff']['notableStaff'][] = array('name' => 'Rhoda Testperson', 'role' => 'Executive Director', 'source' => 'Example', 'sourceUrl' => 'https://www.sltrib.com/news/2020/rhoda');
$pdo->prepare('UPDATE main.facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(json_encode($doc), $fid));
kop_wiki_auto_on_change('facility', array($fid));
kop_wiki_auto_run($pdo, 600, 0);
$texts = array_column(kop_wiki_auto_draft($eid)['ops'], 'text');
$check('a change made while it waits joins the draft, the reviewer\'s edit kept', count($texts) === 2 && in_array('**Rhoda Testperson** was the Executive Director of ' . $program . ' ([source](https://www.sltrib.com/news/2020/rhoda)).', $texts, true)
    && isset(kop_wiki_auto_edits($eid)[$staff[0]['id']]), implode(' | ', $texts));
array_pop($doc['staff']['notableStaff']);
$pdo->prepare('UPDATE main.facilities_v2 SET json_data = ? WHERE id = ?')->execute(array(json_encode($doc), $fid));
kop_wiki_auto_on_change('facility', array($fid));
kop_wiki_auto_run($pdo, 600, 0);
$check('a fact taken off the record leaves the draft', count(kop_wiki_auto_draft($eid)['ops']) === 1);

call_user_func($src['act'], (string) $eid, 'skip', array());
kop_wiki_auto_run($pdo, 600, 50);
$check('Set aside: on its tab, and not offered again', (kop_wiki_auto_state()[$eid]['view'] ?? '') === 'skip' && call_user_func($src['count']) === 0);
call_user_func($src['act'], (string) $eid, 'undo', array());
kop_wiki_auto_run($pdo, 600, 50);
$check('its Undo puts it back on To review', call_user_func($src['count']) === 1 && (kop_wiki_auto_state()[$eid] ?? null) === null);

// A news article linked to the record: one In the Media line.
$news = $pdo->query("SELECT id, article_title AS title, article_url FROM news_submissions WHERE status IN ('approved','published') AND article_url LIKE 'http%' AND article_title <> ''
    AND id NOT IN (SELECT news_id FROM news_facility_links WHERE facility_id = " . (int) $fid . ') ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ($news) {
    $cols = $pdo->query('PRAGMA main.table_info(news_facility_links)')->fetchAll(PDO::FETCH_ASSOC);
    $vals = array();
    foreach ($cols as $c) {
        $n = $c['name'];
        $vals[$n] = $n === 'news_id' ? (int) $news['id'] : ($n === 'facility_id' ? $fid : ($n === 'link_type' || $n === 'type' ? 'about' : ($c['notnull'] && $c['dflt_value'] === null && !$c['pk'] ? '' : null)));
        if ($c['pk'] && $n === 'id') unset($vals[$n]);
    }
    $pdo->prepare('INSERT INTO main.news_facility_links (' . implode(',', array_keys($vals)) . ') VALUES (' . implode(',', array_fill(0, count($vals), '?')) . ')')->execute(array_values($vals));
    kop_wiki_auto_on_change('news', array((int) $news['id']));
    kop_wiki_auto_run($pdo, 600, 0);
    $media = array_values(array_filter(kop_wiki_auto_draft($eid)['ops'], function ($o) use ($news) { return strpos((string) $o['text'], (string) $news['article_url']) !== false || strpos((string) $o['text'], kop_wiki_auto_fix_url($news['article_url'])) !== false; }));
    $check('a news article linked to the record: one In the Media line, reached through the news event', count($media) === 1
        && preg_match('/media|news/i', (string) ($media[0]['section'] ?? $media[0]['heading'] ?? '')), json_encode($media[0] ?? null, JSON_UNESCAPED_SLASHES));
    $b = kop_wiki_auto_build($eid, kop_wiki_drafts_row($pdo, $eid)['markdown']);
    $check('the draft with both lines applies cleanly', !$b['problems'] && !$b['errors'] && $b['count'] === 2, implode('; ', $b['errors']));
}

echo "-- Every linked entry drafts and applies --\n";
// A made-up change on every record at once would be a large test; instead each entry's whole gap list (what a record
// holding all its facts as new would give) is written and applied: nothing may fail to place or lose a line.
$bad = array();
$n = 0;
foreach ($linked as $id => $f) {
    $page = kop_facility_page_data($f);
    if (!$page) continue;
    $gaps = array();
    foreach (kop_wiki_upd_gaps($entries[$id], $page, $pdo) as $g) if ($g['kind'] !== 'profile') $gaps[kop_wiki_auto_gap_key($g)] = $g;
    if (!$gaps) continue;
    $md = kop_wiki_upd_markdown($entries[$id]);
    $draft = kop_wiki_auto_make_draft($entries[$id], $page, $gaps, $md);
    $b = kop_wiki_drafts_build_draft($draft, $md, array());
    $n++;
    if ($b['problems'] || $b['errors']) $bad[] = $id . ' ' . $entries[$id]['program_name'] . ': ' . implode('; ', array_merge($b['problems'], $b['errors']));
    if ($list) printf("%5d %-40s %3d ops %s\n", $id, mb_substr($entries[$id]['program_name'], 0, 40), count($draft['ops']), json_encode($draft['kinds']));
}
$check('every linked entry\'s gaps are written and placed with no line lost', $n > 100 && !$bad, $n . ' entries; ' . implode(' | ', array_slice($bad, 0, 8)));

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
