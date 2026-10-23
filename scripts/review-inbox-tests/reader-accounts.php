<?php
/**
 * scripts/test-review-inbox.php checks for the 'reader-accounts' source
 * (inc/review-inbox/reader-accounts.php over inc/reader-accounts.php), with
 * answers shaped like the live site's first ones (contact details made up)
 * on a real record of the mirror and an AI stand-in: only facility answers
 * with free text are read; contact details never reach the AI; a line whose
 * words are not in the answer is dropped and a name is taken out of the words
 * shown; who they are becomes the citation; a reply that is not JSON gets
 * one more try, a failure is marked and a "rate limit" leaves it unread; the
 * card's lines, leads and actions; Add puts the lines in their own section
 * and never under Rules and practices or on the documents' cards; Undo,
 * Leave all off, Followed up and its Undo, Read again; a reader's new answer
 * takes their old one's waiting lines with it; a hedged note keeps three
 * agreeing answers from settling; remembered rules leave the questions card.
 */

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__, 2) . '/inc/questions.php';
require_once dirname(__DIR__, 2) . '/inc/program-practices.php';
require_once dirname(__DIR__, 2) . '/inc/reader-accounts.php';

/** The stand-in AI: canned readings keyed by words in the prompt. */
function kop_ra_test_ai($prompt) {
    $GLOBALS['kop_ra_test_prompts'][] = $prompt;
    if (strpos($prompt, 'FAIL ME') !== false) throw new Exception('Groq: HTTP 500 | Gemini: HTTP 500');
    if (strpos($prompt, 'OUT OF CALLS') !== false) throw new Exception('Gemini: rate limit reached for the day');
    if (strpos($prompt, "<<<
EARTH FIRE WATER AIR") !== false) {
        // The first reply is not JSON: the second try is.
        if (empty($GLOBALS['kop_ra_test_retried'])) { $GLOBALS['kop_ra_test_retried'] = 1; return 'Sure! Here are the lines you asked for.'; }
        return '```json' . "\n" . json_encode(array('role' => 'unknown', 'attended' => '', 'other' => array(), 'leads' => array(), 'names' => array(),
            'lines' => array(array('topic' => 'levels', 'line' => 'The levels were named Earth, Fire, Water and Air.', 'quote' => 'EARTH FIRE WATER AIR',
                'words' => 'Earth, Fire, Water, Air', 'year' => 0, 'checklist' => array('ttiPractices.hasLevelSystem', 'ttiPractices.hasNothing'))))) . "\n```";
    }
    return json_encode(array('role' => 'was_there', 'attended' => '2010 - 2012', 'other' => array('They found the staff mean.'),
        'leads' => array(array('kind' => 'court', 'text' => 'Says they went to court with the program.')), 'names' => array('Hanks', 'Nobody Here'),
        'lines' => array(
            array('topic' => 'consequences', 'line' => 'The whole group was punished when one girl got in trouble.', 'quote' => 'if one girl got in trouble we all got in trouble',
                'words' => 'If one girl got in trouble, we all got in trouble', 'year' => 0, 'checklist' => array()),
            array('topic' => 'consequences', 'line' => 'Residents scrubbed floors as a punishment.', 'quote' => 'Mr Hanks made us scrub floors',
                'words' => 'Mr. Hanks made us scrub floors', 'year' => 2011, 'checklist' => array()),
            array('topic' => 'rules', 'line' => 'Residents could not speak at meals.', 'quote' => 'no talking at meals', 'words' => 'No talking at meals', 'year' => 0),
            array('topic' => 'weather', 'line' => 'It rained a lot that year.', 'quote' => 'we all got in trouble', 'words' => 'we all got in trouble'),
        )));
}

function kop_rinbox_test_reader_accounts_from_empty(array $src, callable $check) {
    kop_rinbox_test_reader_accounts($src, array(), $check);
}

function kop_rinbox_test_reader_accounts(array $src, array $item, callable $check) {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    global $wpdb;
    $pdo = $GLOBALS['pdo'];
    if (!kop_rinbox_test_options_persist()) {
        $check('reader-accounts: options persist in this harness (needed for the decisions)', false);
        return;
    }
    // A record with no documents' lines, so its cards there stay empty.
    $fid = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE name <> '' AND id NOT IN (SELECT facility_id FROM wpdl_kop_program_practices) ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$fid) {
        $check('reader-accounts: a record to test with', false);
        return;
    }
    $GLOBALS['kop_ra_ai'] = 'kop_ra_test_ai';
    $GLOBALS['kop_ra_test_prompts'] = array();
    $at = '2026-10-22 05:51:22';
    $ins = function (array $r) use ($wpdb, $at) {
        $wpdb->insert(kop_q_table(), $r + array('record_kind' => 'facility', 'answer_key' => 'k', 'value' => '""', 'how' => '', 'source_url' => '', 'note' => '',
            'who' => substr(md5(json_encode($r)), 0, 24), 'batch' => 0, 'created_at' => $at, 'read_status' => '', 'read_json' => null));
        return (int) $wpdb->insert_id;
    };
    $_SERVER['REMOTE_ADDR'] = '9.9.9.9';
    $_SERVER['HTTP_USER_AGENT'] = 'reader-accounts test';
    $rules_text = 'Well if one girl got in trouble we all got in trouble. Mr Hanks made us scrub floors. If you have more questions mail me at '
        . 'jane.doe@example.com or call 555-123-4567, see www.example.com/me. I went from 2010-2012.';
    $a = $ins(array('qkey' => "f$fid.rules", 'record_id' => $fid, 'answer' => $rules_text, 'how' => 'was_there', 'who' => kop_q_who()));
    $b = $ins(array('qkey' => "f$fid.practice.hasLevelSystem", 'record_id' => $fid, 'answer' => 'Yes', 'note' => 'EARTH FIRE WATER AIR'));
    $plain = $ins(array('qkey' => "f$fid.gender", 'record_id' => $fid, 'answer' => 'Girls'));
    $company = $ins(array('qkey' => 'o1.hq', 'record_kind' => 'operator', 'record_id' => 1, 'answer' => 'Provo', 'note' => 'Their office moved twice.'));

    $unread = array_map('intval', array_column(kop_ra_unread(50), 'id'));
    $check('reader-accounts: remembered rules and notes on facility answers are read; a bare tap and a company note are not',
        in_array($a, $unread, true) && in_array($b, $unread, true) && !in_array($plain, $unread, true) && !in_array($company, $unread, true), json_encode($unread));
    $card = kop_rinbox_ra_item($a);
    $check('reader-accounts: an answer not read yet waits with Read now', $card && $card['status'] === 'waiting' && in_array('read', array_column($card['actions'], 'id'), true));

    list($red, $kinds) = kop_ra_redact($rules_text);
    $check('reader-accounts: email, phone and link are taken out; years are left alone',
        strpos($red, '@') === false && strpos($red, '555') === false && strpos($red, 'www.') === false && strpos($red, '2010-2012') !== false
        && $kinds === array('email', 'link', 'phone'), $red);
    $check('reader-accounts: year ranges and lists of years are not phone numbers', kop_ra_redact('2007-2008, 1990 2010 2012 and 1998-2003')[1] === array());

    kop_ra_cron();
    $sent = implode("\n", $GLOBALS['kop_ra_test_prompts']);
    $check('reader-accounts: the AI never sees the contact details', strpos($sent, 'jane.doe') === false && strpos($sent, '555-123') === false && strpos($sent, 'www.example') === false);
    $check('reader-accounts: a reply that is not JSON gets one more try', !empty($GLOBALS['kop_ra_test_retried']) && kop_ra_answer($b)['read_status'] === 'read');

    $ra = kop_ra_answer($a);
    $ja = kop_ra_json($ra);
    $lines = kop_pp_rows(array('answer_id' => $a));
    $check('reader-accounts: a line whose words are not in the answer, or with no known topic, is dropped', $ra['read_status'] === 'read' && count($lines) === 2 && (int) $ja['dropped'] === 2,
        count($lines) . ' lines, ' . ($ja['dropped'] ?? '?') . ' dropped');
    $public = implode(' | ', array_merge(array_column($lines, 'value'), array_column($lines, 'quote'), array_column($lines, 'doc_title')));
    $check('reader-accounts: a name is taken out of the words shown, the spelling fixed', strpos($public, 'Hanks') === false && strpos($public, 'Mr. [name] made us scrub floors') !== false
        && strpos($public, 'If one girl got in trouble, we all got in trouble') !== false, $public);
    $check('reader-accounts: only names in their words are kept', $ja['names'] === array('Hanks'), json_encode($ja['names']));
    $check('reader-accounts: who they are is the citation', $lines[0]['doc_title'] === "A former resident (2010\u{2013}2012), answering on Kids Over Profits' questions page, October 2026"
        && kop_ra_json(kop_ra_answer($b))['cite'] === "A reader, answering on Kids Over Profits' questions page, October 2026", $lines[0]['doc_title']);
    $check('reader-accounts: their contact details make a lead beside the AI\'s', array_column($ja['leads'], 'kind') === array('court', 'contact'), json_encode($ja['leads']));
    $check('reader-accounts: a line\'s year is theirs, else the last year they were there', array_map('intval', array_column($lines, 'year')) == array(2011, 2012)
        || array_map('intval', array_column($lines, 'year')) == array(2012, 2011), json_encode(array_column($lines, 'year')));
    $lb = kop_pp_rows(array('answer_id' => $b));
    $check('reader-accounts: a note is read with its question; only boxes a reader may tick are kept', count($lb) === 1 && $lb[0]['topic'] === 'levels'
        && $lb[0]['checklist'] === 'ttiPractices.hasLevelSystem', json_encode($lb));

    // The card.
    $card = kop_rinbox_ra_item($a);
    $check('reader-accounts: the card shows the whole answer to admins, lines ticked with their words, what is not published and the leads',
        strpos($card['text'], 'jane.doe@example.com') !== false && count($card['checklist']) === 2 && $card['checklist'][0]['checked']
        && strpos(json_encode($card['details']), 'never published') !== false && strpos(json_encode($card['details']), 'Court case') !== false, json_encode($card['details']));
    $check('reader-accounts: a waiting card offers Add, Leave all off, Followed up and Read again, each with its help',
        array_column($card['actions'], 'id') === array('add', 'leave', 'lead_done', 'read') && !array_filter($card['actions'], function ($x) { return empty($x['help']); }),
        json_encode(array_column($card['actions'], 'id')));
    $keys = function ($view) { return array_column(kop_rinbox_ra_list(array('view' => $view, 'search' => '', 'offset' => 0, 'limit' => 200))['items'], 'key'); };
    $check('reader-accounts: both answers wait; the one with a lead is on the Leads tab', !array_diff(array("a$a", "a$b"), $keys('waiting')) && in_array("a$a", $keys('leads'), true)
        && !in_array("a$b", $keys('leads'), true));
    $check('reader-accounts: readers\' lines never make cards under Rules from documents', kop_rinbox_pp_item($fid, 'consequences') === null && kop_rinbox_pp_item($fid, 'levels') === null);

    // Add, the section, Undo.
    $ids = array_map(function ($e) { return $e['keys'][0]; }, $card['checklist']);
    $msg = kop_rinbox_ra_act("a$a", 'add', array('picked' => array($ids[0])))['message'];
    $on = kop_pp_for_facility($fid, 'account');
    $check('reader-accounts: Add puts the ticked line in its own section and leaves the other off', count($on['consequences'] ?? array()) === 1
        && !kop_pp_for_facility($fid) && count(kop_pp_rows(array('answer_id' => $a, 'status' => 'left'))) === 1, $msg);
    $html = kop_ra_section_html($on);
    $check('reader-accounts: the section prints our line, their words and who they are, linking the questions page',
        strpos($html, esc_html($on['consequences'][0]['value'])) !== false && strpos($html, '<q class="kop-ra-words">') !== false
        && strpos($html, 'A former resident') !== false && strpos($html, '>questions page</a>') !== false && strpos($html, '@') === false, $html);
    $check('reader-accounts: the card moves to Added with Undo', kop_rinbox_ra_item($a)['status'] === 'added' && in_array('undo', array_column(kop_rinbox_ra_item($a)['actions'], 'id'), true));
    $msg = kop_rinbox_ra_act("a$a", 'undo', array())['message'];
    $check('reader-accounts: Undo puts both lines back to waiting and off the page', !kop_pp_for_facility($fid, 'account')
        && count(kop_pp_rows(array('answer_id' => $a, 'status' => 'pending'))) === 2, $msg);
    kop_rinbox_ra_act("a$b", 'leave', array());
    $check('reader-accounts: Leave all off changes no page', kop_rinbox_ra_item($b)['status'] === 'left' && !kop_pp_for_facility($fid, 'account'));
    kop_rinbox_ra_act("a$b", 'undo', array());

    // A lead followed up, and its Undo.
    kop_rinbox_ra_act("a$a", 'lead_done', array());
    $check('reader-accounts: Followed up takes the lead off the Leads tab', !in_array("a$a", $keys('leads'), true));
    kop_rinbox_ra_act("a$a", 'undo', array());
    $check('reader-accounts: Undo opens the lead again and leaves the lines waiting', in_array("a$a", $keys('leads'), true)
        && count(kop_pp_rows(array('answer_id' => $a, 'status' => 'pending'))) === 2);

    // Read again replaces the waiting lines; a failure is marked; out of calls leaves it unread.
    kop_rinbox_ra_act("a$b", 'read', array());
    $check('reader-accounts: Read again replaces the waiting lines', count(kop_pp_rows(array('answer_id' => $b))) === 1);
    $f = $ins(array('qkey' => "f$fid.practice.hasRestraint", 'record_id' => $fid, 'answer' => 'Yes', 'note' => 'FAIL ME'));
    $o = $ins(array('qkey' => "f$fid.practice.hasSeclusion", 'record_id' => $fid, 'answer' => 'Yes', 'note' => 'OUT OF CALLS'));
    kop_ra_cron();
    $check('reader-accounts: a failed reading is marked and offers Read again', kop_ra_answer($f)['read_status'] === 'failed'
        && in_array('Read again', array_column(kop_rinbox_ra_item($f)['actions'], 'label'), true));
    $check('reader-accounts: an AI out of calls leaves the answer for the next hour', kop_ra_answer($o)['read_status'] === '');

    // The reader answers again: their old answer's waiting lines go with it.
    $GLOBALS['kop_test_transients'] = array();
    kop_q_answer(array('key' => "f$fid.rules", 'answer' => array('value' => 'We had to ask to use the bathroom.'), 'how' => 'was_there'));
    $check('reader-accounts: a reader\'s new answer takes their old one\'s waiting lines with it', !kop_ra_answer($a) && !kop_pp_rows(array('answer_id' => $a)));

    // Hedged notes; remembered rules leave the questions card.
    $check('reader-accounts: a note that is not sure, or qualifies, is hedged; a plain one is not',
        kop_q_hedged('I Celina im not sure what year Norwalk opened') && kop_q_hedged('Not physically') && kop_q_hedged("I don\u{2019}t remember")
        && !kop_q_hedged('EARTH FIRE WATER AIR') && !kop_q_hedged('From the brochure.') && !kop_q_hedged('Pregnant young girls'));
    $check('reader-accounts: remembered rules are not on the questions card', function_exists('kop_rinbox_q_keys') && !isset(kop_rinbox_q_keys()["f$fid.rules"]));
    $hid = (int) $pdo->query("SELECT id FROM facilities_v2 WHERE (start_year IS NULL OR start_year = 0) AND name <> '' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if ($hid && kop_q_get("f$hid.opened")) {
        foreach (array('4.4.4.1' => '', '4.4.4.2' => '', '4.4.4.3' => 'I think it was 1990 but not sure') as $ip => $note) {
            $_SERVER['REMOTE_ADDR'] = $ip;
            kop_q_answer(array('key' => "f$hid.opened", 'answer' => array('value' => '1990'), 'how' => 'was_there', 'note' => $note));
        }
        $check('reader-accounts: three agreeing answers do not settle when a note is not sure', kop_q_state("f$hid.opened") === null
            && strpos(json_encode(kop_rinbox_q_item("f$hid.opened")['details'] ?? array()), 'Not settled on its own') !== false);
    }
    $vol = (string) file_get_contents(dirname(__DIR__, 2) . '/inc/review-volunteers.php');
    $check('reader-accounts: volunteers never open this queue', (bool) preg_match("/kop_vol_never_sources\(\).*?'reader-accounts'/s", $vol));
    unset($GLOBALS['kop_ra_ai']);
}
