<?php
/**
 * scripts/test-review-inbox.php checks for the 'reader-accounts' source
 * (inc/review-inbox/reader-accounts.php over inc/reader-accounts.php), with
 * answers shaped like the live site's first ones (contact details made up)
 * on a real record of the mirror and an AI stand-in: only facility answers
 * with free text are read; contact details never reach the AI; their whole
 * answer is quoted with only spelling and punctuation fixed, cut into its rules
 * and its testimony (an AI answering with one "words" still works), and a
 * pass that changed words is thrown away for their words as written; a name is
 * taken out of the words shown; who they are becomes the citation; a reply
 * that is not JSON gets one more try, a failure is marked and a "rate limit"
 * leaves it unread; the card's parts, leads and actions; Add quotes the rules
 * under Rules and practices and the testimony under Survivor testimony, never
 * on the documents' cards; Undo, Leave off, Followed up and its Undo, Read again; a
 * reader's new answer takes their old one's waiting testimony with it; the
 * old paraphrased lines go once and their answers are read again; a hedged
 * note keeps three agreeing answers from settling; remembered rules leave the
 * questions card.
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
    if (strpos($prompt, "<<<\nEARTH FIRE WATER AIR") !== false) {
        // The first reply is not JSON: the second try is.
        if (empty($GLOBALS['kop_ra_test_retried'])) { $GLOBALS['kop_ra_test_retried'] = 1; return 'Sure! Here are the words you asked for.'; }
        return '```json' . "\n" . json_encode(array('words' => 'Earth, Fire, Water, Air.', 'role' => 'unknown', 'attended' => '', 'leads' => array(), 'names' => array(),
            'checklist' => array('ttiPractices.hasLevelSystem', 'ttiPractices.hasNothing'))) . "\n```";
    }
    if (strpos($prompt, "<<<\nWe had to coean") !== false) {
        // A pass that rewrites: their words stand as written.
        return json_encode(array('words' => 'We were required to clean the staff bathrooms as punishment.', 'role' => 'unknown', 'attended' => '', 'leads' => array(), 'names' => array(), 'checklist' => array()));
    }
    return json_encode(array('role' => 'was_there', 'attended' => '2010 - 2012', 'checklist' => array(),
        'leads' => array(array('kind' => 'court', 'text' => 'Says they went to court with the program.')), 'names' => array(array('name' => 'Hanks', 'staff' => true), array('name' => 'Amy', 'staff' => false), 'Nobody Here'),
        'parts' => array(
            array('kind' => 'rules', 'words' => 'Well, if one girl got in trouble, we all got in trouble.'),
            array('kind' => 'testimony', 'words' => 'Amy too. Mr. Hanks made us scrub floors a lot.'),
            array('kind' => 'testimony', 'words' => 'If you have more questions, mail me at [email removed] or call [phone removed], see [link removed]. I went from 2010-2012.'))));
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
    $rules_text = 'Well if one girl got in trouble we all got in trouble Amy too. Mr Hanks made us scrub floors alot. If you have more questions mail me at '
        . 'jane.doe@example.com or call 555-123-4567, see www.example.com/me. I went from 2010-2012.';
    $a = $ins(array('qkey' => "f$fid.rules", 'record_id' => $fid, 'answer' => $rules_text, 'how' => 'was_there', 'who' => kop_q_who()));
    $b = $ins(array('qkey' => "f$fid.practice.hasLevelSystem", 'record_id' => $fid, 'answer' => 'Yes', 'note' => 'EARTH FIRE WATER AIR'));
    $w = $ins(array('qkey' => "f$fid.practice.hasIsolation", 'record_id' => $fid, 'answer' => 'Yes', 'note' => 'We had to coean staff bathrooms if got in trouble'));
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

    // The spelling pass may fix spelling and punctuation, nothing else.
    $same = array(
        array('It was alot One was we had to coean staff bathrooms', 'It was a lot. One was, we had to clean staff bathrooms.'),
        array('We were not aloud to contact family', 'We were not allowed to contact family.'),
        array("Couldn\u{2019}t talk to anyone", "Couldn't talk to anyone."),
        array('i had court with them.bc of all the stuff', 'I had court with them, bc of all the stuff.'),
    );
    $differ = array(
        array('Abusive.', 'It was abusive.'),
        array('i had court with them.bc of all the stuff', 'I had court with them because of all the stuff.'),
        array('They hit us', 'They did not hit us'),
        array('Staff yelled at us', 'Staff shouted at us'),
        array('They let us call', 'They never let us call'),
        array('we could go home', 'we couldnt go home'),
    );
    $ok = true;
    foreach ($same as $p) $ok = $ok && kop_ra_same_words($p[0], $p[1]);
    foreach ($differ as $p) $ok = $ok && !kop_ra_same_words($p[0], $p[1]);
    $check('reader-accounts: the word check lets spelling and punctuation through and stops a word added, dropped or changed', $ok);

    // Feelings about a rule, excuses for it and softeners are testimony, even inside a part the AI marked rules.
    $sifted = kop_ra_rules_sift(array(
        array('kind' => 'rules', 'words' => "There were 6 levels. Restraints were used only as a last resort. Level ones could not talk.\n- No physical contact.\n- No blankets in the common area, which was for our safety."),
        array('kind' => 'testimony', 'words' => 'I hated it.'),
    ));
    $check('reader-accounts: a softener or an excuse in a rules part moves to testimony, whole sentence; the rest stays rules, in order',
        $sifted === array(
            array('kind' => 'rules', 'words' => 'There were 6 levels.'),
            array('kind' => 'testimony', 'words' => 'Restraints were used only as a last resort.'),
            array('kind' => 'rules', 'words' => "Level ones could not talk.\n- No physical contact."),
            array('kind' => 'testimony', 'words' => "- No blankets in the common area, which was for our safety. I hated it."),
        ), json_encode($sifted));
    $check('reader-accounts: the prompt says feelings, excuses and "as a last resort" are testimony',
        strpos(kop_ra_prompt(array('ask' => 'q', 'answer' => '', 'name' => 'X', 'fid' => 1), 't'), 'only as a last resort') !== false);

    kop_ra_cron();
    $sent = implode("\n", $GLOBALS['kop_ra_test_prompts']);
    $check('reader-accounts: the AI never sees the contact details', strpos($sent, 'jane.doe') === false && strpos($sent, '555-123') === false && strpos($sent, 'www.example') === false);
    $check('reader-accounts: a reply that is not JSON gets one more try', !empty($GLOBALS['kop_ra_test_retried']) && kop_ra_answer($b)['read_status'] === 'read');

    $ra = kop_ra_answer($a);
    $ja = kop_ra_json($ra);
    $rows = kop_pp_rows(array('answer_id' => $a));
    $check('reader-accounts: an answer is cut into its rules and its testimony (one part each, in order), the spelling fixed', $ra['read_status'] === 'read'
        && count($rows) === 2 && $rows[0]['topic'] === 'account_rules' && $rows[0]['value'] === 'Well, if one girl got in trouble, we all got in trouble.'
        && $rows[1]['topic'] === 'account' && strpos($rows[1]['value'], '[name] too. Mr. Hanks made us scrub floors a lot. If you have') === 0
        && empty($ja['as_written']), json_encode($rows));
    $check('reader-accounts: staff stay named in the part that names them, a resident is taken out', strpos($rows[1]['value'], 'Amy') === false
        && $rows[1]['why'] === 'Hanks' && $rows[0]['why'] === '', json_encode($rows));
    $check('reader-accounts: only names in their words are kept, staff apart', $ja['names'] === array('Amy') && $ja['staff'] === array('Hanks'),
        json_encode(array($ja['names'], $ja['staff'])));
    $check('reader-accounts: who they are is the citation', $rows[0]['doc_title'] === "A former resident (2010\u{2013}2012), answering on Kids Over Profits' questions page, October 2026"
        && kop_ra_json(kop_ra_answer($b))['cite'] === "A reader, answering on Kids Over Profits' questions page, October 2026", $rows[0]['doc_title']);
    $check('reader-accounts: their contact details make a lead beside the AI\'s', array_column($ja['leads'], 'kind') === array('court', 'contact'), json_encode($ja['leads']));
    $check('reader-accounts: the testimony\'s year is the last year they were there', (int) $rows[0]['year'] === 2012);
    $lb = kop_pp_rows(array('answer_id' => $b));
    $check('reader-accounts: a note is read with its question; only boxes a reader may tick are kept', count($lb) === 1 && $lb[0]['value'] === 'Earth, Fire, Water, Air.'
        && $lb[0]['checklist'] === 'ttiPractices.hasLevelSystem', json_encode($lb));
    $lw = kop_pp_rows(array('answer_id' => $w));
    $check('reader-accounts: a pass that rewrote their words is thrown away; their words stand as written, as testimony', count($lw) === 1 && $lw[0]['topic'] === 'account'
        && $lw[0]['value'] === 'We had to coean staff bathrooms if got in trouble' && !empty(kop_ra_json(kop_ra_answer($w))['as_written'])
        && strpos(json_encode(kop_rinbox_ra_item($w)['details']), 'Kept as written') !== false, json_encode($lw));

    // The card.
    $card = kop_rinbox_ra_item($a);
    $check('reader-accounts: the card shows the whole answer to admins, their words ticked, and the leads',
        strpos($card['text'], 'jane.doe@example.com') !== false && count($card['checklist']) === 2 && $card['checklist'][0]['checked']
        && $card['checklist'][0]['label'] === $rows[0]['value'] && strpos($card['checklist'][0]['sub'], 'Rules and practices') === 0
        && strpos($card['checklist'][1]['sub'], 'Survivor testimony') === 0 && strpos(json_encode($card['details']), 'Court case') !== false, json_encode($card['checklist']));
    $check('reader-accounts: a waiting card offers Add, Leave off, Followed up and Read again, each with its help',
        array_column($card['actions'], 'id') === array('add', 'leave', 'lead_done', 'read') && !array_filter($card['actions'], function ($x) { return empty($x['help']); }),
        json_encode(array_column($card['actions'], 'id')));
    $keys = function ($view) { return array_column(kop_rinbox_ra_list(array('view' => $view, 'search' => '', 'offset' => 0, 'limit' => 200))['items'], 'key'); };
    $check('reader-accounts: the answers wait; the one with a lead is on the Leads tab', !array_diff(array("a$a", "a$b"), $keys('waiting')) && in_array("a$a", $keys('leads'), true)
        && !in_array("a$b", $keys('leads'), true));
    $check('reader-accounts: readers\' testimony never makes cards under Rules from documents', kop_rinbox_pp_item($fid, 'account') === null && kop_rinbox_pp_item($fid, 'levels') === null);

    // Add, the section, Undo.
    $msg = kop_rinbox_ra_act("a$a", 'add', array('picked' => array($card['checklist'][0]['keys'][0], $card['checklist'][1]['keys'][0])))['message'];
    $on = kop_ra_for_facility($fid);
    $ru = kop_ra_for_facility($fid, KOP_RA_RULES_TOPIC);
    $check('reader-accounts: Add quotes the rules under Rules and practices and the testimony under Survivor testimony, never as documents\' lines',
        count($on) === 1 && count($ru) === 1 && !kop_pp_for_facility($fid) && strpos($msg, '"Rules and practices" and "Survivor testimony"') !== false, $msg);
    $rh = kop_ra_rules_html($ru);
    $check('reader-accounts: the rules readers remember are quoted under their own heading', strpos($rh, 'What people who were there remember') !== false
        && strpos($rh, esc_html('Well, if one girl got in trouble, we all got in trouble.')) !== false && strpos($rh, 'Hanks') === false, $rh);
    $html = kop_ra_section_html($on);
    $check('reader-accounts: the page quotes their words and who they are, linking the questions page',
        strpos($html, '<figure class="kop-fp-testimony') !== false && strpos($html, esc_html('Mr. Hanks made us scrub floors a lot.')) !== false
        && strpos($html, "Names staff: Hanks. What it says about them is this reader's allegation") !== false
        && strpos($html, 'A former resident') !== false && strpos($html, '>questions page</a>') !== false && strpos($html, '@') === false, $html);
    $check('reader-accounts: the card moves to Added with Undo', kop_rinbox_ra_item($a)['status'] === 'added' && in_array('undo', array_column(kop_rinbox_ra_item($a)['actions'], 'id'), true));
    $msg = kop_rinbox_ra_act("a$a", 'undo', array())['message'];
    $check('reader-accounts: Undo puts it back to waiting and off the page', !kop_ra_for_facility($fid)
        && !kop_ra_for_facility($fid, KOP_RA_RULES_TOPIC) && count(kop_pp_rows(array('answer_id' => $a, 'status' => 'pending'))) === 2, $msg);
    kop_rinbox_ra_act("a$b", 'leave', array());
    $check('reader-accounts: Leave off changes no page', kop_rinbox_ra_item($b)['status'] === 'left' && !kop_ra_for_facility($fid));
    kop_rinbox_ra_act("a$b", 'undo', array());

    // A lead followed up, and its Undo.
    kop_rinbox_ra_act("a$a", 'lead_done', array());
    $check('reader-accounts: Followed up takes the lead off the Leads tab', !in_array("a$a", $keys('leads'), true));
    kop_rinbox_ra_act("a$a", 'undo', array());
    $check('reader-accounts: Undo opens the lead again and leaves the testimony waiting', in_array("a$a", $keys('leads'), true)
        && count(kop_pp_rows(array('answer_id' => $a, 'status' => 'pending'))) === 2);

    // Read again replaces the waiting testimony; a failure is marked; out of calls leaves it unread.
    kop_rinbox_ra_act("a$b", 'read', array());
    $check('reader-accounts: Read again replaces the waiting testimony', count(kop_pp_rows(array('answer_id' => $b))) === 1);
    $f = $ins(array('qkey' => "f$fid.practice.hasRestraint", 'record_id' => $fid, 'answer' => 'Yes', 'note' => 'FAIL ME'));
    $o = $ins(array('qkey' => "f$fid.practice.hasSeclusion", 'record_id' => $fid, 'answer' => 'Yes', 'note' => 'OUT OF CALLS'));
    kop_ra_cron();
    $check('reader-accounts: a failed reading is marked and offers Read again', kop_ra_answer($f)['read_status'] === 'failed'
        && in_array('Read again', array_column(kop_rinbox_ra_item($f)['actions'], 'label'), true));
    $check('reader-accounts: an AI out of calls leaves the answer for the next hour', kop_ra_answer($o)['read_status'] === '');

    // The old paraphrased lines go once, from cards and pages, and their answers are read again.
    global $wpdb;
    $wpdb->insert(kop_pp_table(), array('ikey' => sha1('old-line-' . $b), 'facility_id' => $fid, 'doc_id' => 0, 'answer_id' => $b, 'doc_title' => 'A reader', 'doc_url' => '',
        'page' => 0, 'topic' => 'levels', 'value' => 'The levels were named Earth, Fire, Water and Air.', 'value_orig' => '', 'quote' => 'Earth, Fire, Water, Air', 'why' => '',
        'year' => 0, 'checklist' => '', 'status' => 'added', 'batch' => '', 'created_at' => $at));
    delete_option('kop_ra_mode');
    kop_ra_migrate_to_testimony();
    $check('reader-accounts: the move to testimony takes the old lines off and reads their answers again', !kop_pp_rows(array('answer_id' => $b, 'topic' => 'levels'))
        && kop_ra_answer($b)['read_status'] === '' && get_option('kop_ra_mode') === 'testimony4');
    kop_ra_cron();
    // The second step (staff names): waiting testimony is read again, a decided one stays.
    kop_rinbox_ra_act("a$b", 'leave', array());
    update_option('kop_ra_mode', 'testimony3', false);
    kop_ra_migrate_to_testimony();
    $check('reader-accounts: a later step (rules apart) reads waiting testimony again and keeps what was decided',
        kop_ra_answer($a)['read_status'] === '' && !kop_pp_rows(array('answer_id' => $a)) && kop_ra_answer($b)['read_status'] === 'read'
        && count(kop_pp_rows(array('answer_id' => $b, 'status' => 'left'))) === 1 && get_option('kop_ra_mode') === 'testimony4');
    kop_ra_cron();

    // The reader answers again: their old answer's waiting testimony goes with it.
    $GLOBALS['kop_test_transients'] = array();
    kop_q_answer(array('key' => "f$fid.rules", 'answer' => array('value' => 'We had to ask to use the bathroom.'), 'how' => 'was_there'));
    $check('reader-accounts: a reader\'s new answer takes their old one\'s waiting testimony with it', !kop_ra_answer($a) && !kop_pp_rows(array('answer_id' => $a)));

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
