<?php
/**
 * KOP Tools > Research Tasks (inc/research-tasks.php): the seed imports once by key and hands over a later answer or
 * settled line once (never over the screen's own), every task from docs/RESEARCH-TASKS.md came in with a unique key,
 * assigning and settling with exact Undo, an added task, sending tasks to a volunteer as a research project (their
 * answer moves the task to Answered; settling marks it Used; Undo closes the project), the merge registry, and the cards.
 * Runs against a temporary copy of the tables in tmp/prod.sqlite (the setup is scripts/test-volunteer-projects.php's).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-research-tasks.php [--db=tmp/prod.sqlite] [--page=out.html]
 *   --page writes the screen as it stands at the end, to look at (css/admin-kit.css + css/research-tasks.css beside it).
 */
if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

// Sending a research link to the news queue reads every facility's names (kop_normalize_facility_mentions()).
ini_set('memory_limit', '1024M');

$args = getopt('', array('db::', 'list', 'page:'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

$db_path = sys_get_temp_dir() . '/kop-research-tasks-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
// The same tables as scripts/test-review-inbox.php: every source's test file loads with them.
$wanted = array(
    'facilities_v2', 'news_submissions', 'lawsuits', 'legislation', 'wiki_submissions', 'suggested_edits',
    'facility_closure_reports', 'news_closure_scans', 'lawsuit_facility_links', 'lawsuit_news_links', 'news_facility_candidates', 'news_facility_links', 'inspection_highlights',
    'inspection_facilities', 'indigenous_schools', 'indigenous_school_news', 'bug_reports', 'young_adult_programs',
    'wpdl_options', 'wpdl_kop_operators', 'wpdl_kop_operator_facilities', 'wpdl_kop_glossary_feedback',
    'wpdl_kop_woodbury_facts', 'wpdl_kop_woodbury_mentions', 'wpdl_kop_gdoc_links', 'wpdl_kop_fornits_items',
    'wpdl_kop_fornits_topics', 'wpdl_posts', 'wpdl_postmeta', 'wpdl_kop_review_tags', 'wpdl_fbv', 'wpdl_fbv_attachment_folder',
    // The weekly roundup's cards read these too.
    'inspection_highlight_summaries', 'news_featured',
);
foreach ($wanted as $t) {
    $sql = $copy->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$copy->exec('DETACH DATABASE src');
$copy = null;
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });

$GLOBALS['kop_test_wpdb_writes'] = true;
require __DIR__ . '/kop-test-harness.php';
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', function () { return gmdate('Y-m-d H:i:s'); }, 0);
$pdo->sqliteCreateFunction('NOW', function () { return gmdate('Y-m-d H:i:s'); }, 0);
if (!function_exists('kop_seed_pdo')) { function kop_seed_pdo() { return $GLOBALS['pdo']; } }
if (!function_exists('wp_get_current_user')) {
    function wp_get_current_user() { return new class { public $user_login = 'vol-test'; public $display_name = 'Vol Test'; public function exists() { return true; } }; }
}
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('wp_salt')) { function wp_salt() { return 'test-salt'; } }
if (!function_exists('get_current_user_id')) { function get_current_user_id() { return 1; } }
if (!function_exists('kop_facility_finder_label')) require_once dirname(__DIR__) . '/inc/facility-finder.php';
foreach (glob(__DIR__ . '/review-inbox-tests/*.php') ?: array() as $f) require_once $f;
require_once dirname(__DIR__) . '/inc/review-inbox.php';
$GLOBALS['kop_test_options']['kop_review_inbox_db'] = KOP_REVIEW_INBOX_DB_VERSION;
$GLOBALS['kop_test_options']['kop_volunteers_db'] = KOP_VOL_DB_VERSION;
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_tags (source TEXT NOT NULL, item_key TEXT NOT NULL, tag TEXT NOT NULL,
    created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, PRIMARY KEY (source, item_key, tag))");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_log (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL, item_key TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '', action TEXT NOT NULL, action_label TEXT NOT NULL DEFAULT '', style TEXT NOT NULL DEFAULT '', message TEXT,
    undo_action TEXT NOT NULL DEFAULT '', undo_params TEXT, user_login TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, undone_at TEXT, undone_by TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_review_holds (source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    assigned_to INTEGER NOT NULL DEFAULT 0, snooze_until TEXT, research INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, PRIMARY KEY (source, item_key))");
$pdo->exec("CREATE TABLE wpdl_kop_volunteers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
    token_hash TEXT NOT NULL UNIQUE, created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, link_at TEXT NOT NULL, last_seen TEXT, revoked_at TEXT)");
$pdo->exec("CREATE TABLE wpdl_kop_volunteer_recs (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, source TEXT NOT NULL,
    item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', verdict TEXT NOT NULL, note TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
    outcome TEXT NOT NULL DEFAULT '', outcome_by TEXT NOT NULL DEFAULT '', outcome_at TEXT, UNIQUE (volunteer_id, source, item_key))");

$GLOBALS['kop_test_options']['kop_vol_projects_db'] = KOP_VOLP_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_projects (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, source TEXT NOT NULL, search TEXT NOT NULL DEFAULT '',
    question TEXT, yes_example TEXT, no_example TEXT, note TEXT, minutes INTEGER NOT NULL DEFAULT 20, volunteer_id INTEGER NOT NULL DEFAULT 0,
    status TEXT NOT NULL DEFAULT 'open', created_by TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, closed_at TEXT, closed_why TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE wpdl_kop_vol_project_items (project_id INTEGER NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT '', gone INTEGER NOT NULL DEFAULT 0, detail TEXT, PRIMARY KEY (project_id, item_key))");
$GLOBALS['kop_test_options']['kop_vol_messages_db'] = KOP_VOLM_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, project_id INTEGER NOT NULL DEFAULT 0,
    item_key TEXT NOT NULL DEFAULT '', item_title TEXT NOT NULL DEFAULT '', sender TEXT NOT NULL, author TEXT NOT NULL DEFAULT '', body TEXT NOT NULL,
    created_at TEXT NOT NULL, read_at TEXT)");
$GLOBALS['kop_test_options']['kop_vol_answers_db'] = KOP_VOLR_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_answers (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, item_key TEXT NOT NULL, volunteer_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '', answer TEXT, found INTEGER NOT NULL DEFAULT 1, links TEXT, status TEXT NOT NULL DEFAULT '', decided_by TEXT NOT NULL DEFAULT '',
    decided_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (project_id, item_key, volunteer_id))");
$GLOBALS['kop_test_options']['kop_vol_practice_db'] = KOP_VOLPR_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_practice (id INTEGER PRIMARY KEY AUTOINCREMENT, volunteer_id INTEGER NOT NULL, round_no INTEGER NOT NULL DEFAULT 1,
    source TEXT NOT NULL, item_key TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', answer TEXT NOT NULL, verdict TEXT NOT NULL DEFAULT '',
    position INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, answered_at TEXT, UNIQUE (volunteer_id, source, item_key))");
$GLOBALS['kop_test_options']['kop_vol_social_db'] = KOP_VSOC_DB_VERSION;
$pdo->exec("CREATE TABLE wpdl_kop_vol_social (id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, item_key TEXT NOT NULL, volunteer_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'taken', caption TEXT, image_url TEXT NOT NULL DEFAULT '', image_file TEXT NOT NULL DEFAULT '',
    attachment_id INTEGER NOT NULL DEFAULT 0, note TEXT, owner_note TEXT,
    decided_by TEXT NOT NULL DEFAULT '', decided_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE (project_id, item_key, volunteer_id))");
foreach (array('highlight-summaries', 'featured-reporting', 'news-images', 'federal-funding') as $lib) require_once dirname(__DIR__) . "/inc/$lib.php";

$pdo->exec("CREATE TABLE wpdl_kop_research_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, task_key TEXT NOT NULL UNIQUE, section TEXT NOT NULL DEFAULT '',
    title TEXT NOT NULL DEFAULT '', body TEXT, context TEXT, records TEXT, found TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'open',
    assignee TEXT NOT NULL DEFAULT '', volunteer_id INTEGER NOT NULL DEFAULT 0, project_id INTEGER NOT NULL DEFAULT 0, question TEXT, answer TEXT,
    answer_by TEXT NOT NULL DEFAULT '', answer_at TEXT, settled TEXT, settled_at TEXT, directions TEXT, companies TEXT, edited_at TEXT, seed_hash TEXT NOT NULL DEFAULT '', created_by TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL, updated_by TEXT NOT NULL DEFAULT '', updated_at TEXT NOT NULL)");
$pdo->exec("CREATE TABLE wpdl_kop_research_task_log (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, label TEXT NOT NULL DEFAULT '',
    before_rows TEXT, project_id INTEGER NOT NULL DEFAULT 0, user_login TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, undone_at TEXT)");
require_once dirname(__DIR__) . '/inc/plan-board.php';   // kop_pb_md(), the inline markdown the cards share
require_once dirname(__DIR__) . '/inc/facility-merge.php';
require_once dirname(__DIR__) . '/inc/research-tasks.php';
$GLOBALS['kop_test_options']['kop_rt_db'] = KOP_RT_DB_VERSION;
if (!function_exists('esc_textarea')) { function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); } }

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
};
$throws = function (callable $fn) {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage(); }
};
$row = function ($key) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM wpdl_kop_research_tasks WHERE task_key = %s', $key), ARRAY_A); };
$last_log = function () { global $wpdb; return (int) $wpdb->get_var('SELECT MAX(id) FROM wpdl_kop_research_task_log'); };

echo "The seed\n";
$seed_path = dirname(__DIR__) . '/seeds/research-tasks.json';
$seed = kop_rt_seed($seed_path);
$keys = array_column($seed['tasks'], 'key');
$check('the seed has the tasks from docs/RESEARCH-TASKS.md', count(array_filter($seed['tasks'], function ($t) { return ($t['from'] ?? '') === 'RESEARCH-TASKS.md'; })) >= 140, count($keys) . ' tasks');
$check('every key is unique', count($keys) === count(array_unique($keys)));
$bad = array_filter($seed['tasks'], function ($t) { return trim((string) ($t['title'] ?? '')) === '' || mb_strlen($t['title']) > 255 || !is_array($t['records'] ?? null); });
$check('every task has a title (255 at most) and a records list', !$bad, implode(', ', array_column($bad, 'key')));
$notq = array_filter($seed['tasks'], function ($t) { return empty($t['settled']) && substr(trim($t['title']), -1) !== '?'; });
$check('every open task is phrased as a question', !$notq, implode(' | ', array_column($notq, 'title')));
$long = array_filter($seed['tasks'], function ($t) { return empty($t['settled']) && str_word_count($t['title']) > 30; });
$check('every question is short (30 words at most)', !$long, implode(' | ', array_column($long, 'title')));
$inside = array_filter($seed['tasks'], function ($t) { return empty($t['settled']) && preg_match(kop_rt_inside_words(), $t['title']); });
$check('no open question points inside our files (rows, record numbers, "in scope")', !$inside, implode(' | ', array_column($inside, 'title')));
$nodir = array_filter($seed['tasks'], function ($t) { return !is_array($t['directions'] ?? null); });
$check('every task carries a where-to-look list', !$nodir);
$with = count(array_filter($seed['tasks'], function ($t) { return $t['directions'] || $t['records'] || $t['companies']; }));
$check('most tasks point somewhere we hold (records, companies or links)', $with >= 90, "$with of " . count($keys));
$badurl = array();
foreach ($seed['tasks'] as $t) foreach ($t['directions'] as $d) if (!preg_match('#^https?://\S+$#', $d['url']) || trim($d['label']) === '') $badurl[] = $t['key'];
$check('every direction has a label and a web address', !$badurl, implode(', ', array_unique($badurl)));
$ids = array();
foreach ($seed['tasks'] as $t) foreach ($t['records'] as $id) $ids[$id] = true;
$known = $ids ? array_map('intval', $wpdb->get_col('SELECT id FROM facilities_v2 WHERE id IN (' . implode(',', array_keys($ids)) . ')')) : array();
$missing = array_diff(array_keys($ids), $known);
// A record merged or deleted after its task came in is moved (or shown as gone) on the site; only say so here.
if ($missing) echo '  note record ids no longer in the mirror: ' . implode(', ', $missing) . "\n";
$check('the seed names records', count($known) > 40, count($known) . ' records');

list($a, $b, $c) = kop_rt_import_seed($seed_path, true);
$check('importing adds every task once', $a === count($keys) && $b === 0 && $c === 0, "$a added");
$check('a second import adds nothing', kop_rt_import_seed($seed_path, true) === array(0, 0, 0, 0));
$seeded_settled = count(array_filter($seed['tasks'], function ($t) { return !empty($t['settled']); }));
$check('tasks the seed settles come in settled', kop_rt_counts()['done'] === $seeded_settled, "$seeded_settled settled in the seed");
$withrec = null;
foreach ($seed['tasks'] as $t) if ($t['records']) { $withrec = $row($t['key']); break; }
$check('a task comes in open, nobody\'s, with its records stored for the merge tool', $withrec['status'] === 'open' && $withrec['assignee'] === ''
    && strpos((string) $withrec['records'], '{"id":') === 1, (string) $withrec['records']);

// A session answers one task and settles another through the seed.
$tmp = sys_get_temp_dir() . '/kop-rt-seed-' . getmypid() . '.json';
$s2 = $seed;
$s2['tasks'][0]['answer'] = array('text' => 'Closed in 2021 (Iowa DHS notice).', 'by' => 'Claude session', 'at' => '2026-10-23');
$s2['tasks'][1]['settled'] = array('text' => 'Merged as a rename.', 'at' => '2026-10-23');
$s2['tasks'][] = array('key' => 'test:new-one', 'section' => 'Test', 'title' => 'A new question?', 'text' => '', 'records' => array(), 'found' => 'found today');
file_put_contents($tmp, json_encode($s2));
$res = kop_rt_import_seed($tmp, true);
$check('a new key, an answer and a settled line come over once each', $res === array(1, 1, 1, 0), json_encode($res));
$check('the answered task is Answered, from the session', $row($keys[0])['status'] === 'answered' && $row($keys[0])['answer_by'] === 'Claude session');
$check('the settled task is Settled with its line', $row($keys[1])['status'] === 'settled' && $row($keys[1])['settled'] === 'Merged as a rename.');
$check('the same file again changes nothing', kop_rt_import_seed($tmp, true) === array(0, 0, 0, 0));
$l = $last_log();
kop_rt_undo($l);
$check('a session\'s change has Undo too', $row($keys[1])['status'] === 'open');
kop_rt_undo($l - 1);
$check('the session\'s answer undone', $row($keys[0])['status'] === 'open' && trim((string) $row($keys[0])['answer']) === '');
// The owner answered by hand meanwhile: a seed answer arriving later never replaces it.
kop_rt_change(array((int) $row($keys[0])['id']), array('status' => 'answered', 'answer' => 'Owner knows.'), 'edit', 'test');
$s2['tasks'][0]['answer']['text'] = 'A second try.';
file_put_contents($tmp, json_encode($s2));
kop_rt_import_seed($tmp, true);
@unlink($tmp);
$check('a seed answer never replaces one already there', $row($keys[0])['answer'] === 'Owner knows.');
kop_rt_undo($last_log());

// A session rewords a task: it reaches a card nobody edited, never one the owner edited.
$k3 = $keys[3];
$s3 = $seed;
$s3['tasks'][3]['title'] = 'A reworded question?';
$s3['tasks'][3]['directions'] = array(array('label' => 'Our copy', 'url' => 'https://kidsoverprofits.org/wp-content/uploads/x.pdf', 'kind' => 'note'));
$s3['tasks'][3]['records'] = array();
file_put_contents($tmp, json_encode($s3));
$before = kop_rt_record_ids($row($k3));
$res = kop_rt_import_seed($tmp, true);
$check('a reworded entry reaches an unedited card', $res[3] === 1 && $row($k3)['title'] === 'A reworded question?' && kop_rt_directions($row($k3))[0]['label'] === 'Our copy', json_encode($res));
$check('rewording never takes a record away (the merge tool may have moved it)', kop_rt_record_ids($row($k3)) === $before);
kop_rt_undo($last_log());
$check('Undo puts the old wording back', $row($k3)['title'] === $seed['tasks'][3]['title']);
kop_rt_change(array((int) $row($k3)['id']), array('title' => 'The owner\'s own wording?'), 'edit', 'test');
$s3['tasks'][3]['title'] = 'A third wording?';
file_put_contents($tmp, json_encode($s3));
kop_rt_import_seed($tmp, true);
@unlink($tmp);
$check('an edited card keeps the owner\'s wording', $row($k3)['title'] === 'The owner\'s own wording?');

echo "\nQuestions and where to look\n";
$check('a task must be a question', strpos($throws(function () { kop_rt_add(array('title' => 'Norris Academy town')); }), '"?"') !== false);
$check('a question naming a sheet row is sent back', strpos($throws(function () { kop_rt_add(array('title' => 'Lee Underwood (memorial sheet row 93) died 1931 or 1932?')); }), 'only means something to us') !== false);
$dirs = kop_rt_parse_directions("TN licence list | https://example.org/a.pdf\nhttps://example.org/b");
$check('"what it is | address" lines read as directions', count($dirs) === 2 && $dirs[0]['label'] === 'TN licence list' && $dirs[1]['url'] === 'https://example.org/b');
$check('a line with no address is refused', $throws(function () { kop_rt_parse_directions('just words'); }) !== '');
$check('directions write back as lines', kop_rt_directions_text($dirs) === "TN licence list | https://example.org/a.pdf\nhttps://example.org/b | https://example.org/b");
$wd = null;
foreach ($seed['tasks'] as $t) if (count($t['directions']) >= 2 && empty($t['settled']) && $t['key'] !== $k3) { $wd = $row($t['key']); break; }
$look = kop_rt_where_to_look($wd, kop_rt_record_names(kop_rt_record_ids($wd)), array());
$check('a card lists where to look', count($look) >= 2 && strpos(kop_rt_card_html($wd, array(), array()), 'Where to look (what we already have)') !== false);
$vq = kop_rt_volunteer_question($wd);
$check('a volunteer gets the question and where to start, not our notes', strpos($vq, $wd['title']) === 0 && strpos($vq, 'Where to start') !== false
    && strpos($vq, $look[0]['url']) !== false && (trim((string) $wd['body']) === '' || strpos($vq, trim((string) $wd['body'])) === false), $vq);

echo "\nAssigning, settling, Undo\n";
$t2 = $row($keys[2]);
$l1 = kop_rt_change(array($t2['id']), array('assignee' => 'claude'), 'assign', 'test');
$check('given to Claude', $row($keys[2])['assignee'] === 'claude' && count(kop_rt_list('claude')) === 1);
$check('Claude\'s list reads as sessions get it', kop_rt_export(kop_rt_list('claude'))[0]['key'] === $keys[2]);
kop_rt_undo($l1);
$check('Undo puts it back with nobody', $row($keys[2])['assignee'] === '' && kop_rt_list('claude') === array());
$check('undoing twice is refused', $throws(function () use ($l1) { kop_rt_undo($l1); }) === 'Already undone.');
$counts = kop_rt_counts();
$check('the counts add up', $counts['all'] === count($keys) + 1 && $counts['open'] + $counts['done'] === $counts['all'] && $counts['unassigned'] + $counts['answered'] === $counts['open'], json_encode($counts));
$nid = kop_rt_add(array('title' => 'Which town was Norris Academy in?', 'body' => 'Norris or Andersonville', 'section' => 'Test', 'records' => array($known[0])));
$check('a task added on the screen', kop_rt_record_ids(kop_rt_get($nid)) === array($known[0]) && count(kop_rt_list('open', 'Test', 'Andersonville')) === 1);
kop_rt_undo($last_log());
$check('Undo takes an added task away', kop_rt_get($nid) === null);
$check('a task needs a title', $throws(function () { kop_rt_add(array('title' => ' ')); }) !== '');

echo "\nVolunteers\n";
list($sam_id, $sam_url) = kop_vol_create('Sam Example');
$sam = kop_vol_by_token(substr($sam_url, -48));
$pick = array();
foreach ($seed['tasks'] as $t) if (count($t['records']) === 1 && count($pick) < 2) $pick[] = (int) $row($t['key'])['id'];
$plain = (int) $row('test:new-one')['id'];
$pick[] = $plain;
list($vlog, $pid, $link) = kop_rt_assign_volunteer($pick, $sam_id, '', 'Thanks!');
$p = kop_volp_get($pid);
$items = kop_volp_item_rows($pid, true);
$check('one research project for Sam, one question per task', $p['source'] === 'research:custom' && (int) $p['volunteer_id'] === $sam_id && count($items) === 3
    && $items[0]['item_key'] === 'rt' . $pick[0], json_encode(array_column($items, 'item_key')));
$d = json_decode($items[0]['detail'], true);
$check('a task about one record carries it (Sam sees its facts)', (int) ($d['facility_id'] ?? 0) > 0 && $d['question'] !== '');
$check('the tasks show as Sam\'s', kop_rt_get($pick[0])['assignee'] === 'volunteer' && (int) kop_rt_get($pick[0])['project_id'] === $pid);
$seen = kop_volp_items($sam_id, $pid, 5);
$check('Sam\'s page opens the questions', count($seen['items']) >= 2 && $seen['items'][0]['source'] === 'research', json_encode(array_column($seen['items'], 'key')));
// Every piece of information is cited: each fact with the link that says it.
$check('a fact with no link is refused, by its number', strpos($throws(function () use ($sam, $pid, $plain) {
    kop_volr_answer($sam, $pid, 'rt' . $plain, '', array(), true, array(array('claim' => 'In Andersonville.', 'url' => 'https://example.org/licence'), array('claim' => 'Opened 1998.')));
}), 'Fact 2 has no link') !== false);
$check('a link with no fact is refused', strpos($throws(function () use ($sam, $pid, $plain) {
    kop_volr_answer($sam, $pid, 'rt' . $plain, '', array(), true, array(array('url' => 'https://example.org/licence')));
}), 'no fact') !== false);
$check('a found answer with no facts is refused', $throws(function () use ($sam, $pid, $plain) { kop_volr_answer($sam, $pid, 'rt' . $plain, 'Andersonville.', array(), true, array()); }) !== '');
$sent_notes = array();
$GLOBALS['kop_test_sent'] = &$sent_notes;
$ans = kop_volr_answer($sam, $pid, 'rt' . $plain, 'The 2010 directory still says Norris.', array(), true, array(
    array('claim' => 'Norris Academy is in Andersonville, TN.', 'url' => 'https://example.org/licence', 'kind' => 'website', 'quote' => 'Norris Academy, Andersonville TN 37705'),
    array('claim' => 'It was licensed in 2015.', 'url' => 'https://example.org/licence', 'kind' => 'website'),
));
$check('each fact is kept with its link and quote', count($ans['links']) === 2 && $ans['links'][0]['claim'] === 'Norris Academy is in Andersonville, TN.'
    && $ans['links'][0]['quote'] === 'Norris Academy, Andersonville TN 37705' && $ans['links'][1]['url'] === 'https://example.org/licence', json_encode($ans));
$check('one page backing two facts is sent to the inbox once', $ans['links'][0]['sent'] === $ans['links'][1]['sent']);
$n = kop_rt_sync_volunteer_answers();
$t = kop_rt_get($plain);
$check('the answer moves the task to Answered, each fact with its source', $n === 1 && $t['status'] === 'answered'
    && strpos($t['answer'], "- Norris Academy is in Andersonville, TN. ([source](https://example.org/licence))") === 0
    && strpos($t['answer'], 'It says: "Norris Academy, Andersonville TN 37705"') !== false && strpos($t['answer'], 'The 2010 directory still says Norris.') !== false
    && substr_count($t['answer'], 'https://example.org/licence') === 2 && $t['answer_by'] === 'Sam Example (volunteer)', $t['answer']);
$h = kop_rt_card_html($t, array(), array());
$check('the card shows each fact with a source link', substr_count($h, '>source</a>') === 2, $h);
$check('a session answer from the seed cites each fact the same way', kop_rt_seed_answer_text(array('claims' => array(
    array('claim' => 'Closed March 2009.', 'url' => 'https://example.org/n', 'quote' => 'shut its doors in March'), array('claim' => 'No link', 'url' => ''))))
    === "- Closed March 2009. ([source](https://example.org/n))\n  - It says: \"shut its doors in March\"");
kop_rt_change(array($plain), array('status' => 'settled', 'settled' => 'Record fixed.'), 'settle', 'test');
kop_rt_mark_volunteer_used($t);
$check('settling marks Sam\'s answer Used', $wpdb->get_var("SELECT status FROM wpdl_kop_vol_answers WHERE item_key = 'rt$plain'") === 'used');
kop_rt_undo($vlog);
$check('Undo of the send closes the project and frees the tasks', kop_volp_get($pid)['status'] === 'closed' && kop_rt_get($pick[0])['assignee'] === '' && (int) kop_rt_get($pick[0])['project_id'] === 0);
$check('sending nothing is refused', $throws(function () use ($sam_id) { kop_rt_assign_volunteer(array(), $sam_id); }) !== '');

echo "\nVolunteers pick their own\n";
list($kim_id, $kim_url) = kop_vol_create('Kim Example');
$kim = kop_vol_by_token(substr($kim_url, -48));
$pick = kop_rt_pickable();
$owner_one = (int) $pick[0]['id'];
kop_rt_change(array($owner_one), array('assignee' => 'owner'), 'assign', 'test');
$claude_one = (int) $pick[1]['id'];
kop_rt_change(array($claude_one), array('assignee' => 'claude'), 'assign', 'test');
$pick = kop_rt_pickable();
$pids = array_column($pick, 'id');
$check('the list offers open questions given to nobody', count($pick) === kop_rt_counts()['pickable'] && count($pick) > 50, count($pick) . ' offered');
$check('...never one given to the owner or to Claude', !in_array($owner_one, $pids, true) && !in_array($claude_one, $pids, true));
$memorial = (int) $wpdb->get_var("SELECT id FROM wpdl_kop_research_tasks WHERE section = 'In Loving Memory' AND status = 'open' AND assignee = '' LIMIT 1");
$check('...never an In Loving Memory one (they check the memorial sheet)', $memorial > 0 && !in_array('In Loving Memory', array_column($pick, 'section'), true)
    && kop_rt_counts()['pickable'] < kop_rt_counts()['unassigned']);
$check('...and taking one anyway is refused', strpos($throws(function () use ($kim, $memorial) { kop_rt_volunteer_take($kim, array($memorial)); }), 'Someone else') === 0
    && kop_rt_get($memorial)['assignee'] === '');
$check('...as is an admin sending one to a volunteer', strpos($throws(function () use ($kim_id, $memorial) { kop_rt_assign_volunteer(array($memorial), $kim_id); }), 'memorial sheet') !== false
    && kop_rt_get($memorial)['assignee'] === '');
$check('...each with its question, topic and how many places to start', $pick[0]['title'] !== '' && isset($pick[0]['section'], $pick[0]['start']));
$six = array_slice($pids, 0, 6);
$check('taking more than the hold is refused, saying how many fit', strpos($throws(function () use ($kim, $six) { kop_rt_volunteer_take($kim, $six); }), 'room for 5') !== false);
list($kp, $kn) = kop_rt_volunteer_take($kim, array_slice($pids, 0, 3));
$kproj = kop_volp_get($kp);
$check('Kim takes 3: one research project of hers, one question each', $kn === 3 && (int) $kproj['volunteer_id'] === $kim_id && count(kop_volp_item_rows($kp)) === 3
    && kop_rt_get($pids[0])['assignee'] === 'volunteer' && (int) kop_rt_get($pids[0])['volunteer_id'] === $kim_id, json_encode($kproj));
$check('...they leave the list for everyone else', !array_intersect(array_slice($pids, 0, 3), array_column(kop_rt_pickable(), 'id')));
$check('...and the log says who picked them', strpos((string) $wpdb->get_var('SELECT label FROM wpdl_kop_research_task_log ORDER BY id DESC LIMIT 1'), 'Kim Example picked 3 questions') === 0);
$check('Kim\'s page opens them', count(kop_volp_items($kim_id, $kp, 5)['items']) === 3);
$check('a question someone else took is refused', strpos($throws(function () use ($sam, $pids) { kop_rt_volunteer_take($sam, array($pids[0])); }), 'Someone else has just taken') === 0);
$check('Kim has room for 2 more, not 3', strpos($throws(function () use ($kim, $pids) { kop_rt_volunteer_take($kim, array_slice($pids, 3, 3)); }), 'room for 2') !== false);
kop_volp_leave($kim, $kp);
kop_rt_free_released();
$check('handing the batch back puts the questions back on the list', in_array($pids[0], array_column(kop_rt_pickable(), 'id'), true)
    && kop_rt_get($pids[0])['assignee'] === '' && (int) kop_rt_get($pids[0])['project_id'] === 0 && kop_rt_held_by($kim_id) === 0);
list($kp2) = kop_rt_volunteer_take($kim, array($pids[4]));
kop_volr_answer($kim, $kp2, 'rt' . $pids[4], '', array(), true, array(array('claim' => 'It opened in 2002.', 'url' => 'https://example.org/opened')));
kop_volp_leave($kim, $kp2);
kop_rt_sync_volunteer_answers();
kop_rt_free_released();
$check('an answered question stays answered after the batch closes', kop_rt_get($pids[4])['status'] === 'answered' && kop_rt_get($pids[4])['assignee'] === 'volunteer');

echo "\nMerges and cards\n";
$reg = array_values(array_filter(kop_rt_merge_tables(kop_fmerge_json_tables('wpdl_'), 'wpdl_'), function ($x) { return $x['t'] === 'wpdl_kop_research_tasks'; }));
$check('the merge tool moves the ids a task names', count($reg) === 1 && $reg[0]['c'] === 'records' && $reg[0]['key'] === 'id', json_encode($reg));
$changed = false;
$sw = kop_fmerge_json_swap(json_decode(kop_rt_records_json(array(5, 7)), true), 'id', 7, 9, $changed);
$check('a swapped record id reads back', $changed && kop_rt_record_ids(array('records' => json_encode($sw))) === array(5, 9));
$all = kop_rt_list('all');
$names = kop_rt_record_names(array_merge(array(), ...array_map('kop_rt_record_ids', $all)));
$bad = 0;
foreach ($all as $t) {
    $h = kop_rt_card_html($t, $names, array($sam_id => 'Sam Example'));
    if (strpos($h, '<h3 class="kop-rt-title">') === false || substr_count($h, '<details') !== substr_count($h, '</details>')
        || substr_count($h, '<article') !== 1 || substr_count($h, '</article>') !== 1) $bad++;
}
$check('every card renders whole', $bad === 0, "$bad broken of " . count($all));
$h = kop_rt_md("Lead **bold** and [a link](https://example.org)\n\n- one\n  - sub\n- two");
$check('the details read as paragraphs and lists', strpos($h, '<p>Lead <strong>bold</strong>') !== false && substr_count($h, '<li') === 3 && strpos($h, 'class="is-sub"') !== false, $h);

if (!empty($args['page'])) {
    if (!function_exists('wp_nonce_field')) { function wp_nonce_field() { return '<input type="hidden" name="_wpnonce" value="x">'; } }
    if (!function_exists('selected')) { function selected($a, $b, $echo = true) { return (string) $a === (string) $b ? ' selected' : ''; } }
    if (!function_exists('sanitize_key')) { function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); } }
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = array('view' => 'open');
    kop_rt_change(array($pick[1]), array('assignee' => 'owner'), 'assign', 'Assigned task #' . $pick[1]);
    kop_rt_change(array((int) $row($keys[5])['id']), array('status' => 'answered', 'answer' => "Closed in 2021.\n- DHS notice: https://example.org/n", 'answer_by' => 'Claude session'),
        'seed', 'From a session: answered');
    ob_start();
    kop_rt_page_body();
    $theme = str_replace('\\', '/', dirname(__DIR__));
    file_put_contents($args['page'], '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<link rel="stylesheet" href="file:///' . $theme . '/css/colors.css"><link rel="stylesheet" href="file:///' . $theme . '/css/admin-kit.css">'
        . '<link rel="stylesheet" href="file:///' . $theme . '/css/research-tasks.css"><style>body{background:#f0f0f1;font:13px/1.4 "Segoe UI",sans-serif;margin:0;padding:10px 16px;color:#1d2327}'
        . '.screen-reader-text{position:absolute;left:-9999px}.button{display:inline-block;padding:2px 10px;border:1px solid #2271b1;border-radius:3px;background:#f6f7f7;color:#2271b1;font-size:13px;min-height:30px}</style>'
        . ob_get_clean());
    echo "\nWrote the screen to {$args['page']}\n";
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
