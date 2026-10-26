<?php
/**
 * Offline check of Map Questions (inc/network-questions.php).
 *
 *   php scripts/test-network-questions.php
 *
 * The build's questions file loads with stable ids and real options, the six
 * alias questions are there, answers save and undo through the endpoint (an
 * answer that is not an option is refused, 'skip' hides the question, admins
 * only), open counts follow, the review inbox source lists the same cards,
 * and the screen renders to tmp/network-questions/page.html. WordPress and
 * the answers table are stubbed in memory; no record is touched (the records
 * database is not reachable here, so an alias answer is refused with a plain
 * reason, which is also checked).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

define('ABSPATH', dirname(__DIR__) . '/');

// --- WordPress stubs --------------------------------------------------------

$GLOBALS['kop_options'] = array();
$GLOBALS['kop_can'] = true;
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function apply_filters($h, $v) { return $v; }
function trailingslashit($s) { return rtrim((string) $s, '/\\') . '/'; }
function get_stylesheet_directory() { return rtrim(ABSPATH, '/'); }
function get_stylesheet_directory_uri() { return 'https://kidsoverprofits.org/wp-content/themes/child'; }
function get_option($k, $d = false) { return $GLOBALS['kop_options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['kop_options'][$k] = $v; return true; }
function get_transient($k) { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function home_url($p = '/') { return 'https://kidsoverprofits.org' . $p; }
function admin_url($p = '') { return 'https://kidsoverprofits.org/wp-admin/' . $p; }
function current_user_can() { return $GLOBALS['kop_can']; }
function check_ajax_referer() { return true; }
function wp_create_nonce() { return 'nonce'; }
function wp_unslash($v) { return $v; }
function sanitize_title($t) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string) $t), '-')); }
function wp_get_current_user() { return (object) array('user_login' => 'owner'); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES); }
class KopJsonExit extends Exception { public $payload; public $ok; }
function wp_send_json_success($data) { $e = new KopJsonExit('json'); $e->ok = true; $e->payload = $data; throw $e; }
function wp_send_json_error($data, $code = 400) { $e = new KopJsonExit('json'); $e->ok = false; $e->payload = $data; throw $e; }
if (!defined('WEEK_IN_SECONDS')) define('WEEK_IN_SECONDS', 604800);
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

// The answers table, in memory (the functions are function_exists-guarded, so these win).
$GLOBALS['kop_answers'] = array();
function kop_network_questions_answers() { return $GLOBALS['kop_answers']; }
function kop_network_questions_put(array $row) { $GLOBALS['kop_answers'][$row['question_id']] = $row; }
function kop_network_questions_remove($id) { unset($GLOBALS['kop_answers'][$id]); }
// No records database here: alias answers must be refused with a reason, never half-applied.
function kop_network_questions_record_opts() { return null; }
// The review inbox registry, enough to read the source back.
$GLOBALS['kop_sources'] = array();
function kop_rinbox_register($key, $fn) { $GLOBALS['kop_sources'][$key] = $fn; }
function kop_rinbox_reviewer() { return 'owner'; }
function kop_rinbox_map_rebuild_tool($why = '') { return array('id' => 'map_rebuild', 'label' => 'Rebuild the map now'); }

require ABSPATH . 'inc/network-map.php';
require ABSPATH . 'inc/review-inbox/map-questions.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($detail !== '' ? " ($detail)" : '') . "\n";
}

// --- The questions ----------------------------------------------------------

$build = kop_network_questions_build();
check('the build\'s questions file loads', count($build) > 0, count($build) . ' questions');
$sections = kop_network_questions_sections();
$bad = array();
foreach ($build as $id => $q) {
    if (!isset($sections[$q['section']])) $bad[] = $id . ' (section ' . $q['section'] . ')';
    elseif (count($q['options']) < 2) $bad[] = $id . ' (options)';
    elseif ($q['question'] === '') $bad[] = $id . ' (no question)';
    elseif (strpos($id, $q['section'] . ':') !== 0) $bad[] = $id . ' (id not under its section)';
}
check('every question has a known section, two or more answers and words', !$bad, implode(', ', array_slice($bad, 0, 5)));
$graph = kop_network_map_graph();
$ids = array();
foreach ($graph['nodes'] as $n) $ids[$n['id']] = true;
$off = array();
foreach ($build as $q) if ($q['node'] !== '' && !isset($ids[$q['node']])) $off[] = $q['node'];
check('every "See it on the map" link names a map node', !$off, implode(', ', array_slice($off, 0, 5)));
$kinds = array_filter($build, function ($q) { return $q['section'] === 'kind'; });
$first = reset($kinds);
check('a kind question offers the investor kind', $first && in_array('investor', array_column($first['options'], 'value'), true));

$aliases = kop_network_questions_aliases();
check('six alias questions', count($aliases) === 6, count($aliases));
$a = reset($aliases);
check('an alias question asks in plain words', strpos($a['question'], 'past name of') !== false && count($a['options']) === 2, $a['question']);
check('alias questions come first in the list', array_keys(kop_network_questions_all())[0] === key($aliases));
$all = kop_network_questions_all();
check('open = everything before any answer', count(kop_network_questions_open()) === count($all));

// --- Saving -----------------------------------------------------------------

function kop_test_save(array $items) {
    $_POST = array('items' => json_encode($items), 'nonce' => 'nonce');
    try {
        kop_network_questions_ajax();
    } catch (KopJsonExit $e) {
        return array($e->ok, $e->payload);
    }
    return array(null, null);
}

$qid = key($kinds);
$qopt = $first['options'][1]['value'];
list($ok, $payload) = kop_test_save(array(array('id' => $qid, 'answer' => $qopt)));
check('an answer saves with its label', $ok && ($payload['saved'][$qid]['answer'] ?? '') === $qopt && $payload['saved'][$qid]['label'] !== '');
check('the stored row carries the subject the build needs', ($GLOBALS['kop_answers'][$qid]['subject']['name'] ?? '') === $first['name'] && $GLOBALS['kop_answers'][$qid]['section'] === 'kind');
check('an answered question leaves the open list', !isset(kop_network_questions_open()[$qid]));
list($ok, $payload) = kop_test_save(array(array('id' => $qid, 'answer' => 'not-an-answer')));
check('an answer that is not an option is refused', $ok && !empty($payload['saved'][$qid]['error']));
check('the refused answer did not replace the saved one', ($GLOBALS['kop_answers'][$qid]['answer'] ?? '') === $qopt);
$second = array_keys($kinds)[1];
list($ok, $payload) = kop_test_save(array(array('id' => $second, 'answer' => 'skip')));
check('"skip" is stored and hides the question', $ok && $payload['saved'][$second]['answer'] === 'skip' && !isset(kop_network_questions_open()[$second]));
list($ok, $payload) = kop_test_save(array(array('id' => 'kind:not-a-question', 'answer' => 'parent')));
check('a question not on the list is refused', $ok && !empty($payload['saved']['kind:not-a-question']['error']));
$aid = key($aliases);
list($ok, $payload) = kop_test_save(array(array('id' => $aid, 'answer' => 'past-name')));
check('an alias answer with no records database is refused with a reason, not stored',
    $ok && !empty($payload['saved'][$aid]['error']) && !isset($GLOBALS['kop_answers'][$aid]), $payload['saved'][$aid]['error'] ?? '');
list($ok, $payload) = kop_test_save(array(array('id' => $aid, 'answer' => 'skip')));
check('"not sure" on an alias stores without touching the record', $ok && ($GLOBALS['kop_answers'][$aid]['answer'] ?? '') === 'skip');
list($ok, $payload) = kop_test_save(array(array('id' => $qid, 'answer' => 'undo'), array('id' => $second, 'answer' => 'undo'), array('id' => $aid, 'answer' => 'undo')));
check('undo takes all three back', $ok && !$GLOBALS['kop_answers'] && count(kop_network_questions_open()) === count($all));
$GLOBALS['kop_can'] = false;
list($ok) = kop_test_save(array(array('id' => $qid, 'answer' => $qopt)));
check('only an administrator can answer', $ok === false);
$GLOBALS['kop_can'] = true;

// An answered question that the next build no longer asks still shows under Answered, for Undo.
kop_network_questions_put(array('question_id' => 'kind:gone-from-the-build', 'section' => 'kind', 'subject' => array('name' => 'Gone Academy'),
    'answer' => 'parent', 'answer_label' => 'A company', 'answered_by' => 'owner', 'answered_at' => '2026-10-25 00:00:00'));
$rows = kop_network_questions_rows();
$gone = array_values(array_filter($rows, function ($r) { return $r['id'] === 'kind:gone-from-the-build'; }));
check('a settled answer stays on the Answered tab', $gone && $gone[0]['answer'] === 'parent' && $gone[0]['name'] === 'Gone Academy');
kop_network_questions_remove('kind:gone-from-the-build');

// --- The review inbox source ------------------------------------------------

$src = $GLOBALS['kop_sources']['map-questions']();
check('the inbox source registers', is_array($src) && $src['label'] === 'Map questions');
check('the inbox count is the open count', ($src['count'])() === count(kop_network_questions_open()));
$listed = ($src['list'])(array('view' => 'alias', 'search' => '', 'offset' => 0, 'limit' => 10));
check('the alias tab lists the six cards', $listed['total'] === 6 && count($listed['items']) === 6);
$item = $listed['items'][0];
$actions = array_column($item['actions'], 'id');
check('a card offers one button per answer and "not sure"', in_array('answer:past-name', $actions, true) && in_array('answer:own-program', $actions, true) && in_array('answer:skip', $actions, true));
$res = ($src['act'])($aid, 'answer:skip', array());
check('acting from the inbox stores the answer', ($GLOBALS['kop_answers'][$aid]['answer'] ?? '') === 'skip' && strpos($res['message'], 'Saved') === 0);
$got = ($src['get'])($aid);
check('the answered card shows Undo', $got && $got['status'] === 'answered' && $got['actions'][0]['id'] === 'undo');
($src['act'])($aid, 'undo', array());
check('undo from the inbox', !isset($GLOBALS['kop_answers'][$aid]));
$counts = ($src['view_counts'])();
check('view counts cover every section', isset($counts['kind'], $counts['alias'], $counts['answered']) && $counts['alias'] === 6);

// --- The screen -------------------------------------------------------------

ob_start();
kop_network_questions_page();
$html = ob_get_clean();
@mkdir(ABSPATH . 'tmp/network-questions', 0777, true);
file_put_contents(ABSPATH . 'tmp/network-questions/page.html', $html);
check('the screen renders every question into its config', strpos($html, 'kop-nq__list') !== false && substr_count($html, '"question":') >= count($all));
check('the screen names the rebuild', strpos($html, 'kop-network-rebuild') !== false);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed.\n";
exit($failures ? 1 : 0);
