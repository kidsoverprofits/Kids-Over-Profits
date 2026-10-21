<?php
/**
 * scripts/test-review-inbox.php checks for the 'questions' source
 * (inc/review-inbox/questions.php over inc/questions.php): readers' answers
 * on real records of the mirror, one person's new answer replacing their
 * old one, the card's tally and actions, the third agreeing answer settling
 * it (held for the write-up through kop_wbf_apply(), in Recently done) and
 * its Undo, Dismiss and Ask again, the deck, and the optional email: a
 * reader's set of answers, its status following their questions, and the one
 * email inc/submission-followup.php sends. scripts/test-questions.php
 * checks the questions and the agreement rule on every record.
 */

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__, 2) . '/inc/questions.php';
require_once dirname(__DIR__, 2) . '/inc/submission-followup.php';
$GLOBALS['kop_test_options']['kop_questions_db'] = KOP_Q_DB_VERSION;
$GLOBALS['kop_test_options']['kop_followup_db'] = KOP_FOLLOWUP_DB_VERSION;
if (!function_exists('is_email')) {
    function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
}
if (!function_exists('wp_salt')) {
    function wp_salt() { return 'test-salt'; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)); }
}

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_question_answers (id INTEGER PRIMARY KEY AUTOINCREMENT, qkey TEXT NOT NULL DEFAULT '',
        record_kind TEXT NOT NULL DEFAULT '', record_id INTEGER NOT NULL DEFAULT 0, answer_key TEXT NOT NULL DEFAULT '', answer TEXT, value TEXT,
        how TEXT NOT NULL DEFAULT '', source_url TEXT, note TEXT, who TEXT NOT NULL DEFAULT '', batch INTEGER NOT NULL DEFAULT 0, created_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_question_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, who TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'pending', title TEXT NOT NULL DEFAULT '', created_at TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_submission_followups (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, item_id INTEGER NOT NULL,
        email TEXT NOT NULL DEFAULT '', token TEXT NOT NULL, state TEXT NOT NULL DEFAULT 'waiting', moved INTEGER NOT NULL DEFAULT 0, outcome TEXT NULL,
        decided_seen_at TEXT NULL, created_at TEXT NOT NULL, sent_at TEXT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_question_state (qkey TEXT PRIMARY KEY, status TEXT NOT NULL DEFAULT '', ask TEXT, answer TEXT,
        done TEXT, reviewed_by TEXT, reviewed_at TEXT)");
})();

/** Answers from three people about a real record with no opening year: two say one year, one another. */
function kop_q_test_seed() {
    $pdo = $GLOBALS['pdo'];
    $fid = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE (start_year IS NULL OR start_year = 0) AND name <> '' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$fid) return;
    $GLOBALS['kop_q_test_fid'] = $fid;
    $GLOBALS['kop_test_transients'] = array();
    foreach (array(array('1.1.1.1', '1998', 'was_there'), array('2.2.2.2', '1998', 'worked'), array('3.3.3.3', '1996', 'parent')) as $a) {
        $_SERVER['REMOTE_ADDR'] = $a[0];
        $_SERVER['HTTP_USER_AGENT'] = 'test';
        kop_q_answer(array('key' => "f$fid.opened", 'answer' => array('value' => $a[1]), 'how' => $a[2], 'note' => $a[0] === '3.3.3.3' ? 'From the brochure.' : ''));
    }
}

function kop_rinbox_test_questions(array $src, array $item, callable $check) {
    if (!isset($GLOBALS['kop_q_test_fid'])) kop_q_test_seed();
    $fid = (int) ($GLOBALS['kop_q_test_fid'] ?? 0);
    if (!$fid) {
        $check('questions: a record with no opening year in the mirror', false);
        return;
    }
    $key = "f$fid.opened";
    $it = kop_rinbox_get_item('questions', $key);
    $check('questions: the card asks the question', $it && strpos($it['title'], 'What year did') === 0, $it['title'] ?? '');
    $check('questions: the tally puts the most given answer first', $it['details'][0]['label'] === '2 people' && strpos($it['details'][0]['value'], '1998') === 0, json_encode($it['details'][0]));
    $check('questions: how people know is on the card', strpos($it['details'][0]['value'], 'i was there') !== false && strpos($it['details'][1]['value'], 'Notes: From the brochure.') !== false);
    $check('questions: two of three agreeing does not settle it', kop_q_state($key) === null);
    $settle = $it['actions'][0];
    $check('questions: Use this answer offers each answer, the most given first', $settle['id'] === 'settle' && $settle['params'][0]['value'] === '1998'
        && count($settle['params'][0]['options']) === 2 && !empty($settle['help']));
    $check('questions: a year can be typed instead', in_array('settle_own', array_column($it['actions'], 'id'), true));

    // The first person asks for an email when their answers have been checked (optional; answering never needs it).
    $_SERVER['REMOTE_ADDR'] = '1.1.1.1';
    $n = kop_q_notify(array('notify_email' => 'reader@example.com'));
    $batch = (int) $n['batch'];
    $fu = $GLOBALS['wpdb']->get_row("SELECT * FROM wpdl_kop_submission_followups WHERE kind = 'questions' AND item_id = $batch", ARRAY_A);
    $mine = $GLOBALS['wpdb']->get_col("SELECT qkey FROM wpdl_kop_question_answers WHERE batch = $batch");
    $bstatus = function () use ($batch) { return (string) $GLOBALS['wpdb']->get_var("SELECT status FROM wpdl_kop_question_batches WHERE id = $batch"); };
    $check('questions: an email takes in the answers this person already gave, and only theirs', $batch > 0 && $mine === array($key), json_encode($mine));
    $check('questions: the email is signed up for one update about the set', $fu && $fu['state'] === 'waiting' && $fu['email'] === 'reader@example.com');
    $check('questions: the set waits while its question is open', $bstatus() === 'pending');
    $bad = false;
    try { kop_q_notify(array('notify_email' => 'not an address')); } catch (RuntimeException $e) { $bad = true; }
    $check('questions: a wrong address is turned back', $bad);

    // The third person changes their mind: their old answer goes.
    $_SERVER['REMOTE_ADDR'] = '3.3.3.3';
    $res = kop_q_answer(array('key' => $key, 'answer' => array('value' => '1998')));
    $rows = kop_q_rows($key);
    $check('questions: a person\'s new answer replaces their old one', count($rows) === 3 && count(kop_q_tally($rows)) === 1, json_encode(array_column($rows, 'answer_key')));
    $state = kop_q_state($key);
    $check('questions: the third agreeing answer settles it', $state && $state['status'] === 'settled' && $state['answer'] === '1998', $res['message']);
    $check('questions: the emailed set reads as added once its answer was used', $bstatus() === 'added');
    $done = $state ? (json_decode($state['done'], true) ?: array()) : array();
    $wbf = !empty($done['wbf']) ? (kop_wbf_rows(array($done['wbf']))[0] ?? null) : null;
    // The citation never says how many agreed (owner, 2026-10-20); the count is kept in extra.
    $check('questions: it waits for the record write-up as an approved fact citing the page', $wbf && $wbf['status'] === 'held'
        && $wbf['path'] === 'operatingPeriod.startYear' && json_decode($wbf['value'], true) === 1998
        && strpos($wbf['evidence'], 'questions page') !== false && strpos($wbf['evidence'], 'agreed') === false
        && (json_decode($wbf['extra'], true)['answers'] ?? 0) === 3, json_encode($wbf));
    // In Woodbury Facts (Held tab) and Conflicts it is named as the questions page, never as Woodbury.
    if ($wbf) {
        $card = kop_rinbox_get_item('woodbury-facts', $wbf['pkey']);
        $check('questions: its Woodbury Facts card is badged "Questions page" and says where it came from', $card && $card['source_label'] === 'Questions page'
            && strpos($card['text'], 'From the questions page') !== false && strpos($card['text'], 'Woodbury calls it') === false
            && strpos($card['text'], 'Added by hand by') === false && strpos($card['text'], 'these words were not found') === false, json_encode(array($card['source_label'] ?? null, $card['text'] ?? null)));
        $wsrc = kop_rinbox_sources()['woodbury-facts'];
        $in = function ($origin) use ($wsrc, $wbf) {
            $l = call_user_func($wsrc['list'], array('view' => 'held', 'search' => '', 'offset' => 0, 'limit' => 500, 'filters' => array('origin' => $origin)));
            return in_array($wbf['pkey'], array_column($l['items'], 'key'), true);
        };
        $check('questions: the Woodbury Facts "From" filter keeps it under Questions page, not Woodbury Reports', $in('questions') && !$in('woodbury') && $in(''));
    }
    $froms = kop_rinbox_conflicts_froms();
    $check('questions: Conflicts can be filtered to the questions page', ($froms['questions'] ?? '') === 'Questions page', json_encode($froms));
    $check('questions: a conflict from the questions page is badged so', kop_rinbox_conflicts_label('woodbury-facts', array('origin' => 'questions')) === 'Questions page'
        && kop_rinbox_conflicts_label('woodbury-facts', array('origin' => 'woodbury')) === 'Woodbury Facts');
    $logged = $GLOBALS['wpdb']->get_row("SELECT * FROM wpdl_kop_review_log WHERE source = 'questions' AND item_key = '$key' ORDER BY id DESC LIMIT 1", ARRAY_A);
    $check('questions: it is in Recently done with its Undo, as the questions page', $logged && $logged['undo_action'] === 'undo' && $logged['user_login'] === KOP_Q_BY, json_encode($logged));
    call_user_func($src['act'], $key, 'undo', array());
    $check('questions: Undo takes the fact back and asks again', kop_q_state($key) === null && kop_q_get($key) !== null
        && (!$wbf || !kop_wbf_rows(array($wbf['pkey']))));
    $check('questions: Undo puts the emailed set back to waiting', $bstatus() === 'pending');
    // Back to two agreeing and one not, for the rest.
    $_SERVER['REMOTE_ADDR'] = '3.3.3.3';
    kop_q_answer(array('key' => $key, 'answer' => array('value' => '1996')));

    $check('questions: "I don\'t know" stores nothing', kop_q_answer(array('key' => $key, 'answer' => array('value' => 'unknown')))['message'] === 'Skipped.' && count(kop_q_rows($key)) === 3);
    $bad = false;
    try { kop_q_answer(array('key' => $key, 'answer' => array('value' => 'a long time ago'))); } catch (RuntimeException $e) { $bad = true; }
    $check('questions: an answer that is not a year is turned back', $bad);

    // Dismiss, then Ask again.
    call_user_func($src['act'], $key, 'dismiss', array());
    $check('questions: Dismiss closes it and the page stops asking', kop_q_state($key)['status'] === 'dismissed' && kop_q_get($key) === null);
    $check('questions: a dismissed question makes the emailed set declined', $bstatus() === 'declined');
    kop_followup_run();
    kop_followup_run(time() + KOP_FOLLOWUP_SETTLE + 60);
    $fu = $GLOBALS['wpdb']->get_row("SELECT * FROM wpdl_kop_submission_followups WHERE kind = 'questions' AND item_id = $batch", ARRAY_A);
    $check('questions: the one email goes out once the decision stands, and the address is deleted', $fu && $fu['state'] === 'sent'
        && $fu['outcome'] === 'declined' && $fu['email'] === '', json_encode($fu));
    $listed = call_user_func($src['list'], array('view' => 'dismissed', 'search' => '', 'offset' => 0, 'limit' => 50));
    $check('questions: it is on the Dismissed tab with Ask again', in_array($key, array_column($listed['items'], 'key'), true)
        && kop_rinbox_get_item('questions', $key)['actions'][0]['id'] === 'undo');
    call_user_func($src['act'], $key, 'undo', array());
    $check('questions: Ask again opens it again', kop_q_state($key) === null);

    // The questions a real record gets, and the deck.
    $qs = kop_q_for_facility($fid);
    $check('questions: a real record has its questions, rules last', $qs && end($qs)['key'] === "f$fid.rules", count($qs) . ' questions');
    $deck = kop_q_deck('', array(), 10);
    $check('questions: the deck has questions with no repeats', $deck && count(array_unique(array_column($deck, 'key'))) === count($deck), count($deck) . ' cards');
    $check('questions: the deck starts with the question people started on', in_array($key, array_column($deck, 'key'), true));
    $ut = kop_q_deck('UT', array(), 10);
    $off = array_filter($ut, function ($q) { return empty($q['conflict']) && !preg_match('/(^|, )UT$/', $q['record']['place']); });
    $check('questions: a state\'s deck keeps to that state', !$off, implode(', ', array_column($off, 'key')));
}

/** No answers in the mirror: the checks above seed their own, so run them anyway. */
function kop_rinbox_test_questions_from_empty(array $src, callable $check) {
    $first = call_user_func($src['list'], array('view' => 'waiting', 'search' => '', 'offset' => 0, 'limit' => 1))['items'][0] ?? array();
    kop_rinbox_test_questions($src, $first, $check);
}
