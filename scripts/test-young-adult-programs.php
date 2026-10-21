<?php
/**
 * Offline checks for young adult programs (inc/young-adult-programs.php) and
 * their tab on Woodbury Facts (inc/woodbury-facts.php).
 *
 *   php scripts/test-young-adult-programs.php [--db=tmp/prod.sqlite] [--facts=C:/tmp/kop-woodbury/pending/facts.json]
 *
 * The program table is made in an in-memory database; nothing is written to
 * the mirror. Every no-record proposal in facts.json is turned into a fact,
 * one program's whole card is added to a record and undone, and the result
 * must be the record exactly as it was. The public listing is rendered.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'facts::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$facts_path = $args['facts'] ?? 'C:/tmp/kop-woodbury/pending/facts.json';
if (!file_exists($db_path) || !file_exists($facts_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py) and $facts_path (scripts/woodbury-facts.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/facility-finder.php';
require_once dirname(__DIR__) . '/inc/young-adult-programs.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/woodbury-mentions.php';
require_once dirname(__DIR__) . '/inc/woodbury-create.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$ya = new PDO('sqlite::memory:');
$ya->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ya->exec("CREATE TABLE young_adult_programs (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_key TEXT NOT NULL UNIQUE,
    other_names TEXT, city TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', ages TEXT NOT NULL DEFAULT '',
    program_type TEXT NOT NULL DEFAULT '', run_by TEXT NOT NULL DEFAULT '', opened INTEGER, closed INTEGER, status TEXT NOT NULL DEFAULT 'Unknown',
    notes TEXT, links TEXT, facts TEXT, review TEXT NOT NULL DEFAULT 'approved', source TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, updated_at TEXT NOT NULL, document_folder_id INTEGER)");
function kop_seed_pdo() { return $GLOBALS['kop_test_ya_pdo']; }
$GLOBALS['kop_test_ya_pdo'] = $ya;
$strip = function ($row) { unset($row['updated_at']); return $row; };

echo "-- Records --\n";
$id = kop_ya_save($ya, array('name' => 'Echo Springs Transition Study Center', 'state' => 'Utah', 'city' => 'Moab', 'ages' => '18-24'), 0, 'tester');
$p = kop_ya_get($ya, $id);
$check('a program is saved', $p && $p['name'] === 'Echo Springs Transition Study Center' && $p['review'] === 'approved' && $p['facts'] === '[]');
$check('a US state is kept as its code', $p['state'] === 'UT', $p['state']);
$refused = false;
try {
    kop_ya_save($ya, array('name' => 'echo springs transition study center'), 0, 'tester');
} catch (RuntimeException $e) {
    $refused = true;
}
$check('the same name twice is refused', $refused);
kop_ya_save($ya, array_merge($p, array('other_names' => 'Echo Springs')), $id, 'tester');
$check('found by an other name', (int) (kop_ya_find_by_name($ya, 'Echo Springs')['id'] ?? 0) === $id);

echo "-- Facts and exact undo --\n";
$before = $strip(kop_ya_get($ya, $id));
$done = kop_ya_add_fact($ya, $id, array('key' => 'k1', 'group' => 'history', 'label' => 'Start year: 2004', 'cites' => array()),
    array('opened' => 2004, 'ages' => '17-26', 'other_names' => 'Echo Springs Study', 'run_by' => 'Cooper Partners'));
$p = kop_ya_get($ya, $id);
$check('an empty field is filled', (int) $p['opened'] === 2004 && $p['run_by'] === 'Cooper Partners');
$check('a field with a value is left alone', $p['ages'] === '18-24');
$check('an other name is added as a line', in_array('Echo Springs Study', kop_ya_lines($p['other_names']), true));
$refused = false;
try {
    kop_ya_add_fact($ya, $id, array('key' => 'k1', 'group' => 'history', 'label' => 'x', 'cites' => array()));
} catch (RuntimeException $e) {
    $refused = true;
}
$check('the same fact twice is refused', $refused);
kop_ya_remove_fact($ya, $done);
$check('undo gives back the record exactly', $strip(kop_ya_get($ya, $id)) == $before);

echo "-- Document folder --\n";
// The media library tables in memory, written for real; the mirror is not touched.
$fb = new PDO('sqlite::memory:');
$fb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fb->exec('CREATE TABLE wpdl_fbv (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, parent INTEGER, type INTEGER, created_by INTEGER, ord INTEGER)');
$fb->exec('CREATE TABLE wpdl_fbv_attachment_folder (folder_id INTEGER, attachment_id INTEGER)');
$mirror_wpdb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new wpdb($fb);
$GLOBALS['kop_test_wpdb_writes'] = true;
$folders = function () use ($fb) { return (int) $fb->query('SELECT COUNT(*) FROM wpdl_fbv')->fetchColumn(); };
$t = kop_wbc_existing_target('ya', $id);
$made = $fb->query('SELECT f.name, p.name AS parent, p.parent AS top FROM wpdl_fbv f JOIN wpdl_fbv p ON p.id = f.parent WHERE f.id = ' . (int) $t['folder'])->fetch(PDO::FETCH_ASSOC);
$check('filing under a program makes its folder, under "Young Adult Programs"', $t['kind'] === 'ya' && $made
    && $made['name'] === 'Echo Springs Transition Study Center' && $made['parent'] === KOP_YA_FOLDER_PARENT && (int) $made['top'] === 0, wp_json_encode($made));
$check('the folder is kept on the program', (int) kop_ya_get($ya, $id)['document_folder_id'] === $t['folder']);
$check('the target links the program\'s card', $t['url'] === kop_ya_url($id) && strpos($t['url'], '#kop-ya-program-' . $id) !== false, $t['url']);
$n = $folders();
$again = kop_wbc_existing_target('ya', $id);
$check('the next filing uses the same folder', $again['folder'] === $t['folder'] && $folders() === $n);
$ya->prepare('UPDATE young_adult_programs SET document_folder_id = 9999 WHERE id = ?')->execute(array($id));
$gone = kop_wbc_existing_target('ya', $id);
$check('a folder that is gone is replaced (by the one of its name)', $gone['folder'] === $t['folder'] && (int) kop_ya_get($ya, $id)['document_folder_id'] === $t['folder']);
$refused = '';
try {
    kop_wbc_existing_target('ya', 424242);
} catch (RuntimeException $e) {
    $refused = $e->getMessage();
}
$check('a program that is not there is refused', stripos($refused, 'not found') !== false, $refused);
$GLOBALS['wpdb'] = $mirror_wpdb;
$GLOBALS['kop_test_wpdb_writes'] = false;

echo "-- Every no-record proposal as a fact --\n";
$data = json_decode(file_get_contents($facts_path), true);
$by = array();
$bad = 0;
$examples = array();
foreach ($data['proposals'] as $pp) {
    if ((int) $pp['facility_id'] > 0 || $pp['group'] === 'consultant') {
        continue;
    }
    $r = array('pkey' => $pp['key'], 'grp' => $pp['group'], 'op' => $pp['op'], 'path' => $pp['path'], 'value' => json_encode($pp['value']),
        'label' => $pp['label'], 'evidence' => json_encode($pp['evidence']), 'status' => 'pending', 'extra' => '{}');
    list($fact, $fill) = kop_wbf_ya_fact($r);
    if ($fact['key'] === '' || trim($fact['label']) === '' || count($fact['cites']) > 3 || ($fact['cites'] && !preg_match('#^https?://#', $fact['cites'][0]['url']))) {
        $bad++;
        $examples[] = $pp['label'];
    }
    foreach ($fill as $col => $v) {
        if (!in_array($col, kop_ya_fillable(), true)) {
            $bad++;
            $examples[] = "fills $col";
        }
    }
    $by[$pp['program']][] = $r;
}
$check('every item becomes a fact with its sources', $bad === 0, $bad . ' bad' . ($examples ? ': ' . implode('; ', array_slice($examples, 0, 3)) : ''));
printf("  %d items for %d programs\n", array_sum(array_map('count', $by)), count($by));
$ya_flag = 0;
foreach ($data['proposals'] as $pp) {
    $ya_flag += !empty($pp['young_adult']) ? 1 : 0;
}
printf("  %d items the build marks as young adult\n", $ya_flag);

uasort($by, function ($a, $b) { return count($b) - count($a); });
$program = array_key_first($by);
$card = $by[$program];
$id2 = kop_ya_save($ya, array('name' => $program, 'state' => 'UT'), 0, 'tester');
$before = $strip(kop_ya_get($ya, $id2));
$dones = array();
$refused = 0;
foreach ($card as $r) {
    list($fact, $fill) = kop_wbf_ya_fact($r);
    try {
        $dones[] = kop_ya_add_fact($ya, $id2, $fact, $fill);
    } catch (RuntimeException $e) {
        $refused++;
    }
}
$check("a whole card goes on a program ($program, " . count($card) . ' items)', count(kop_ya_facts(kop_ya_get($ya, $id2))) === count($card) && !$refused);
foreach (array_reverse($dones) as $d) {
    kop_ya_remove_fact($ya, $d);
}
$check('and undoing every item gives back the program exactly', $strip(kop_ya_get($ya, $id2)) == $before);

echo "-- The page --\n";
$closed = array('pkey' => 'c1', 'grp' => 'history', 'op' => 'set_closed', 'path' => 'operatingPeriod', 'value' => json_encode(array('endYear' => 2011)),
    'label' => 'Closed in 2011 (sets status Closed, end year 2011)', 'evidence' => json_encode(array(array('label' => 'May 2011', 'number' => '', 'page' => 4,
        'url' => 'https://kidsoverprofits.org/wp-content/uploads/w.pdf#page=4', 'quote' => 'closed', 'found' => true))), 'status' => 'pending', 'extra' => '{}');
list($fact, $fill) = kop_wbf_ya_fact($closed);
kop_ya_add_fact($ya, $id, $fact, $fill);
$hidden = kop_ya_save($ya, array('name' => 'Hidden Program', 'review' => 'pending'), 0, 'tester');
// The folder shortcode (inc/features.php, not loaded here): a stand-in that names the folder it was asked for.
function kop_filebird_folder_shortcode($atts) {
    $GLOBALS['kop_test_ya_shortcodes'][] = $atts;
    return (int) $atts['folder_id'] === 78 ? '<p class="no-documents">No documents found in this folder.</p>'
        : '<div class="kop-document-folder" data-test-folder="' . (int) $atts['folder_id'] . '"><ul class="doc-list doc-layout-grid"><li class="doc-item">A document</li></ul></div>';
}
$ya->prepare('UPDATE young_adult_programs SET document_folder_id = 77 WHERE id = ?')->execute(array($id));
$empty = kop_ya_save($ya, array('name' => 'Empty Folder Program', 'state' => 'UT'), 0, 'tester');
$ya->prepare('UPDATE young_adult_programs SET document_folder_id = 78 WHERE id = ?')->execute(array($empty));
ob_start();
kop_ya_render_public();
$html = ob_get_clean();
$card = function ($pid) use ($html) {
    return preg_match('#<article class="kop-ya-program" id="kop-ya-program-' . (int) $pid . '".*?</article>#s', $html, $m) ? $m[0] : '';
};
$c = $card($id);
$check('a program\'s documents show on its card, under "More about this program"', strpos($c, 'data-test-folder="77"') !== false
    && strpos($c, 'More about this program') !== false && strpos($c, 'More about this program') < strpos($c, 'Documents on file')
    && strpos($c, 'Documents on file') < strpos($c, 'data-test-folder="77"'));
$asked = array_values(array_filter((array) ($GLOBALS['kop_test_ya_shortcodes'] ?? array()), function ($a) { return (int) $a['folder_id'] === 77; }))[0] ?? array();
$check('as a facility page lists them (same-name folders merged, the grid)', ($asked['merge'] ?? '') === 'name' && ($asked['layout'] ?? '') === 'grid');
$c = $card($empty);
$check('an empty folder shows nothing, not even the heading', $c !== '' && strpos($c, 'Documents on file') === false && strpos($c, 'More about this program') === false
    && strpos($c, 'no-documents') === false);
$c = $card($id2);
$check('a program with no folder shows no documents', $c === '' || strpos($c, 'Documents on file') === false);
kop_ya_delete($ya, $empty);
$check('a listed program is on the page', strpos($html, 'Echo Springs Transition Study Center') !== false);
$check('a hidden program is not', strpos($html, 'Hidden Program') === false);
$check('a closing fills status and year', strpos($html, '2004') === false && strpos($html, 'Closed 2011') !== false);
$check('a fact shows without the review screen wording', strpos($html, 'Closed in 2011') !== false && strpos($html, 'sets status') === false);
$check('a fact links its Woodbury page', strpos($html, 'href="https://kidsoverprofits.org/wp-content/uploads/w.pdf#page=4"') !== false
    && strpos($html, 'Woodbury Reports, May 2011, p. 4') !== false);
$check('programs are grouped by state', strpos($html, 'id="kop-ya-utah"') !== false || strpos($html, 'id="kop-ya-ut"') !== false);

echo "-- The review card --\n";
$panel = kop_wbf_ya_panel(array('program' => 'Echo Springs', 'program_as_written' => 'Echo Springs', 'place' => 'Moab, Utah', 'ya_why' => 'Ages: 18-24'));
$check('the matching program is picked already', (bool) preg_match('/<option value="' . $id . '" selected>/', $panel));
$check('a new one can be created, ages filled in', strpos($panel, 'data-act="ya_create"') !== false && strpos($panel, 'value="18-24"') !== false);
$check('the build says why, "described as" only when it is not ages or a name',
    strpos(kop_wbf_ya_panel(array('program' => 'X', 'program_as_written' => 'X', 'place' => '', 'ya_why' => 'transition program for young adults')), 'value="transition program for young adults"') !== false);
$check('a choice made by hand wins over the build', kop_wbf_ya_for('Nobody', true) === 1 && kop_wbf_ya_for('Nobody', false) === 0);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
