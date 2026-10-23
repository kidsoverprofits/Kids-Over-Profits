<?php
/**
 * Readers' own words on the questions page (/questions/, inc/questions.php),
 * read into lines (docs/plan 4.4 item 13, owner 2026-10-23).
 *
 * Two kinds of answer carry free text about a facility: "What rules do you
 * remember at X?" (f<id>.rules) and the note under any other answer. Each is
 * read once, like a page of a handbook (inc/program-practices.php):
 * kop_ai_generate_alternating() splits it into lines, each with a topic of
 * kop_pp_topics(), our plain wording, the reader's words it rests on (spelling
 * fixed; checked against the answer, a line whose words are not in it is
 * dropped), a year and the checklist boxes it shows. Email addresses, phone
 * numbers and links are taken out before the AI sees it (kop_ra_redact());
 * the names it finds are taken out of the words and lines shown.
 *
 * What a reading finds:
 *   lines   rows of {prefix}kop_program_practices with answer_id = the
 *           answer's id (doc_id 0, doc_title = who they are, kop_ra_cite()),
 *           waiting at Review inbox > What readers remember
 *           (inc/review-inbox/reader-accounts.php), one card per answer. Add
 *           puts the ticked lines in the facility page's own section "What
 *           people who were there remember" (kop_ra_section_html()), never
 *           under Rules and practices (owner, 2026-10-23)
 *   other   what is not about how the program ran (opinions, feelings, what
 *           happened to them alone): on the card only, never published
 *   leads   an offer to be contacted, a court case, a different program
 *           named: the card's Leads tab, admin-only, beside their whole answer
 *
 * The answer row keeps the reading: read_status ('' not read yet, 'read',
 * 'failed') and read_json {role, attended, cite, other, leads, names,
 * contact, dropped, provider, read_at, error, lead_done}. A reader who
 * answers the same question again replaces their row (kop_q_answer()); the
 * old row's lines nobody has decided go with it (kop_ra_forget()).
 *
 * Read hourly, KOP_RA_BATCH answers a run (cron kop_ra_hourly), or at once
 * with the card's "Read now". Tested by scripts/test-review-inbox.php
 * --source=reader-accounts.
 */

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

if (!defined('KOP_RA_BATCH')) define('KOP_RA_BATCH', 8);

/** Is this a question whose answers wait here instead of on the questions card? */
function kop_ra_is_account_key($qkey) {
    return (bool) preg_match('/^f\d+\.rules$/', (string) $qkey);
}

/** The SQL that picks the answers read here. */
function kop_ra_where() {
    return "record_kind = 'facility' AND (qkey LIKE '%.rules' OR COALESCE(note, '') <> '')";
}

/** Answers not read yet, oldest first. */
function kop_ra_unread($limit = KOP_RA_BATCH) {
    kop_q_ensure_tables();
    global $wpdb;
    return (array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . kop_q_table() . ' WHERE ' . kop_ra_where()
        . " AND read_status = '' ORDER BY id LIMIT %d", (int) $limit), ARRAY_A);
}

function kop_ra_answer($id) {
    kop_q_ensure_tables();
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . kop_q_table() . ' WHERE id = %d', (int) $id), ARRAY_A);
    return is_array($r) ? $r : null;
}

function kop_ra_json(array $row) {
    $j = json_decode((string) ($row['read_json'] ?? ''), true);
    return is_array($j) ? $j : array();
}

function kop_ra_save_json($id, $status, array $json) {
    global $wpdb;
    $wpdb->update(kop_q_table(), array('read_status' => (string) $status, 'read_json' => wp_json_encode($json)), array('id' => (int) $id));
}

/** The text read: a rules answer's own words, else the note. */
function kop_ra_text(array $row) {
    return kop_ra_is_account_key($row['qkey']) ? (string) $row['answer'] : (string) $row['note'];
}

/**
 * Take out email addresses, links and phone numbers. Returns [text, kinds
 * found]: what the AI sees and the page could ever show.
 */
function kop_ra_redact($s) {
    $found = array();
    $swap = function ($kind, $with) use (&$found) {
        return function () use ($kind, $with, &$found) { $found[$kind] = true; return $with; };
    };
    $s = preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $swap('email', '[email removed]'), (string) $s);
    $s = preg_replace_callback('#\b(?:https?://|www\.)\S+#i', $swap('link', '[link removed]'), $s);
    $s = preg_replace_callback('/(?<![\d\-])(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}(?![\d\-])/', $swap('phone', '[phone removed]'), $s);
    return array($s, array_keys($found));
}

/** Lower case, straight quotes, single spaces: for finding the reader's words in their answer. */
function kop_ra_norm($s) {
    $s = str_replace(array("\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}"), array("'", "'", '"', '"'), (string) $s);
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower($s)));
}

/** Who they are, from their "How do you know?" or, without one, what their words say. */
function kop_ra_who($how, $role) {
    $who = array('was_there' => 'A former resident', 'worked' => 'A former staff member', 'parent' => 'A parent of a former resident');
    if (isset($who[$how])) return $who[$how];
    return $who[$role] ?? 'A reader';
}

/** "A former resident (2010–2012), answering on Kids Over Profits' questions page, October 2026". */
function kop_ra_cite($how, $role, $attended, $created_at) {
    $years = $attended !== '' ? ' (' . str_replace('-', "\u{2013}", $attended) . ')' : '';
    $t = strtotime((string) $created_at . ' UTC');
    return kop_ra_who($how, $role) . $years . ', answering on Kids Over Profits\' questions page' . ($t ? ', ' . gmdate('F Y', $t) : '');
}

/** The question and the record an answer belongs to: {ask, answer, name, fid}. */
function kop_ra_context(array $row) {
    $fid = (int) $row['record_id'];
    if (function_exists('kop_facility_merge_resolve')) $fid = (int) kop_facility_merge_resolve($fid);
    $q = kop_q_get($row['qkey']);
    $state = $q ? null : kop_q_state($row['qkey']);
    $name = $q ? (string) $q['record']['name'] : '';
    if ($name === '' && function_exists('kop_rinbox_facility')) $name = preg_replace('/ \([^)]*\)$/', '', (string) kop_rinbox_facility($fid)['name']);
    return array(
        'ask'    => $q ? (string) $q['ask'] : ($state ? (string) $state['ask'] : ''),
        'answer' => kop_ra_is_account_key($row['qkey']) ? '' : (string) $row['answer'],
        'name'   => $name !== '' ? $name : 'the program',
        'fid'    => $fid,
    );
}

function kop_ra_prompt(array $ctx, $text) {
    $topics = array();
    foreach (kop_pp_topics() as $k => $label) $topics[] = $k . ' (' . $label . ')';
    $checks = array();
    foreach (kop_pp_checklist_keys() as $sec => $keys) foreach ($keys as $k) $checks[] = $sec . '.' . $k;
    $asked = 'The question they were asked: "' . $ctx['ask'] . '"' . ($ctx['answer'] !== '' ? "\nTheir answer: \"" . $ctx['answer'] . "\"\nThe note they added is below." : '');
    return 'You read one answer that a reader of Kids Over Profits gave about a youth residential program, "' . $ctx['name'] . "\".\n"
        . $asked . "\n\nTheir words:\n<<<\n" . $text . "\n>>>\n\n"
        . "Answer with JSON only, in this shape:\n"
        . '{"role": "", "attended": "", "lines": [{"topic": "", "line": "", "quote": "", "words": "", "year": 0, "checklist": []}], "other": [], "leads": [{"kind": "", "text": ""}], "names": []}' . "\n\n"
        . "lines: each separate thing they say about how the program ran: its rules, levels, punishments, contact with family, restraint, isolation, searches, "
        . "therapy, daily life, school, who it took, what parents were told. One line per separate thing.\n"
        . '  topic: one of ' . implode(', ', $topics) . ".\n"
        . "  line: what they say, as one plain sentence in the past tense, in your words, adding nothing they did not say, with no names of people "
        . "(\"Residents cleaned staff bathrooms as a punishment.\"). Use the question for what a short note means (a note \"EARTH FIRE WATER AIR\" under "
        . "\"Did it have a level system?\" is \"The levels were named Earth, Fire, Water and Air.\").\n"
        . "  quote: the words of theirs the line rests on, copied exactly, character for character.\n"
        . "  words: the same quote with only spelling, capital letters and punctuation fixed. Leave a word you cannot make out as it is.\n"
        . "  year: the year it happened if they say so, else 0.\n"
        . '  checklist: those of these the line shows, else []: ' . implode(', ', $checks) . ".\n"
        . "role: was_there if they say they were in the program, worked if they worked there, parent if their child was there, else unknown.\n"
        . "attended: the years they say they were there, like \"2010-2012\" or \"2007\", else \"\".\n"
        . "other: what they say that is not about how the program ran (opinions, feelings, what happened to them alone, like \"Abusive.\" or "
        . "\"I was bullied a lot\"), as short plain sentences.\n"
        . "leads: kind \"contact\" when they offer to be contacted, \"court\" when they mention a court case or lawsuit, \"other_program\" when the answer "
        . "is about a different program (name it in text), \"other\" for anything else a researcher should follow up; text says what it is.\n"
        . "names: every name of a person in their words (their own or anyone else's), as written.\n"
        . 'If they say nothing about how the program ran, lines is [].';
}

/** One AI call; a test stands in with $GLOBALS['kop_ra_ai']. */
function kop_ra_ai($prompt) {
    if (!empty($GLOBALS['kop_ra_ai']) && is_callable($GLOBALS['kop_ra_ai'])) return (string) call_user_func($GLOBALS['kop_ra_ai'], $prompt);
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    return kop_ai_generate_alternating($prompt, array('maxTokens' => 1800, 'temperature' => 0.1));
}

/**
 * The AI's answer, checked: {role, attended, lines, other, leads, names,
 * dropped}. Throws when it is not the JSON asked for.
 */
function kop_ra_parse($raw, $text) {
    $s = trim(preg_replace('/^```\w*\s*|\s*```$/', '', trim((string) $raw)));
    $a = strpos($s, '{');
    $b = strrpos($s, '}');
    $j = $a !== false && $b > $a ? json_decode(substr($s, $a, $b - $a + 1), true) : null;
    if (!is_array($j) || !isset($j['lines']) || !is_array($j['lines'])) throw new RuntimeException('The AI did not answer with the JSON asked for.');

    $hay = kop_ra_norm($text);
    $names = array();
    foreach ((array) ($j['names'] ?? array()) as $n) {
        $n = trim((string) $n);
        if (mb_strlen($n) >= 2 && mb_strlen($n) <= 60 && mb_stripos($text, $n) !== false) $names[] = $n;
    }
    // The longest first, so "Mr. John Smith" goes before "John".
    usort($names, function ($x, $y) { return mb_strlen($y) <=> mb_strlen($x); });
    $unname = function ($s) use ($names) {
        foreach ($names as $n) $s = preg_replace('/(?<![\p{L}])' . preg_quote($n, '/') . '(?![\p{L}])/iu', '[name]', $s);
        return $s;
    };
    $topics = kop_pp_topics();
    $role = in_array($j['role'] ?? '', array('was_there', 'worked', 'parent'), true) ? $j['role'] : 'unknown';
    $attended = preg_match('/^(19[4-9]\d|20\d\d)(?:\s*[-\x{2013}]\s*(19[4-9]\d|20\d\d))?$/u', trim((string) ($j['attended'] ?? '')), $m)
        ? $m[1] . (!empty($m[2]) && $m[2] !== $m[1] ? '-' . $m[2] : '') : '';
    $last = $attended !== '' ? (int) substr($attended, -4) : 0;
    $lines = array();
    $dropped = 0;
    foreach ($j['lines'] as $l) {
        if (!is_array($l)) { $dropped++; continue; }
        $topic = (string) ($l['topic'] ?? '');
        $line = trim((string) ($l['line'] ?? ''));
        $quote = trim((string) ($l['quote'] ?? ''), " \t\n\r\0\x0B\"'");
        // The words must be theirs: a quote not in the answer is the AI's own, and the line goes.
        if (!isset($topics[$topic]) || mb_strlen($line) < 8 || mb_strlen($line) > 300 || $quote === ''
            || mb_strpos($hay, rtrim(kop_ra_norm($quote), '.,;:!')) === false) {
            $dropped++;
            continue;
        }
        $words = trim((string) ($l['words'] ?? ''), " \t\n\r\0\x0B\"'");
        similar_text(kop_ra_norm($words), kop_ra_norm($quote), $pct);
        // Spelling fixed only: anything further from their words than that is not theirs.
        if ($words === '' || $pct < 70) $words = $quote;
        $year = (int) ($l['year'] ?? 0);
        if ($year < 1940 || $year > (int) gmdate('Y')) $year = $last;
        $lines[] = array(
            'topic' => $topic, 'line' => $unname($line), 'words' => $unname(mb_strtoupper(mb_substr($words, 0, 1)) . mb_substr($words, 1)),
            'quote' => $quote, 'year' => $year,
            'checklist' => array_values(array_unique(array_filter(array_map('kop_pp_clean_check', (array) ($l['checklist'] ?? array()))))),
        );
    }
    $other = array();
    foreach ((array) ($j['other'] ?? array()) as $o) if (trim((string) $o) !== '') $other[] = mb_substr(trim((string) $o), 0, 300);
    $leads = array();
    foreach ((array) ($j['leads'] ?? array()) as $l) {
        if (!is_array($l) || trim((string) ($l['text'] ?? '')) === '') continue;
        $kind = in_array($l['kind'] ?? '', array('contact', 'court', 'other_program'), true) ? $l['kind'] : 'other';
        $leads[] = array('kind' => $kind, 'text' => mb_substr(trim((string) $l['text']), 0, 300));
    }
    return array('role' => $role, 'attended' => $attended, 'lines' => $lines, 'other' => $other, 'leads' => $leads, 'names' => $names, 'dropped' => $dropped);
}

/** What a lead's kind is called on the card. */
function kop_ra_lead_kinds() {
    return array('contact' => 'Offers to be contacted', 'court' => 'Court case', 'other_program' => 'About a different program', 'other' => 'To follow up');
}

/**
 * Read one answer row: its lines wait on its card, the rest is kept on the
 * row. A form problem gets one more try with the reason. Throws on an AI
 * that is out of calls ("rate limit"), leaving the row unread.
 */
function kop_ra_read(array $row) {
    $id = (int) $row['id'];
    list($text, $contact) = kop_ra_redact(kop_ra_text($row));
    $ctx = kop_ra_context($row);
    $prompt = kop_ra_prompt($ctx, $text);
    try {
        try {
            $got = kop_ra_parse(kop_ra_ai($prompt), $text);
        } catch (RuntimeException $e) {
            $got = kop_ra_parse(kop_ra_ai($prompt . "\n\nYour last answer was refused: " . $e->getMessage() . ' Answer with the JSON only.'), $text);
        }
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'rate limit') !== false) throw $e;
        kop_ra_save_json($id, 'failed', array('error' => mb_substr($e->getMessage(), 0, 300), 'read_at' => gmdate('Y-m-d H:i:s')));
        return array('lines' => 0, 'error' => $e->getMessage());
    }
    if ($contact && !array_filter($got['leads'], function ($l) { return $l['kind'] === 'contact'; })) {
        $what = array_map(function ($k) { return array('email' => 'an email address', 'phone' => 'a phone number', 'link' => 'a link')[$k]; }, $contact);
        $last = array_pop($what);
        $got['leads'][] = array('kind' => 'contact', 'text' => 'Left ' . ($what ? implode(', ', $what) . ' and ' : '') . $last . ' in the answer.');
    }
    $cite = kop_ra_cite((string) $row['how'], $got['role'], $got['attended'], (string) $row['created_at']);
    global $wpdb;
    $added = 0;
    foreach ($got['lines'] as $i => $l) {
        $wpdb->insert(kop_pp_table(), array(
            'ikey' => sha1('answer|' . $id . '|' . $i . '|' . $l['quote']), 'facility_id' => $ctx['fid'], 'doc_id' => 0, 'answer_id' => $id,
            'doc_title' => mb_substr($cite, 0, 255), 'doc_url' => '', 'page' => 0, 'topic' => $l['topic'], 'value' => $l['line'], 'value_orig' => $l['line'],
            'quote' => $l['words'], 'why' => '', 'year' => $l['year'], 'checklist' => implode(',', $l['checklist']),
            'status' => 'pending', 'batch' => '', 'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        if ($wpdb->insert_id) $added++;
    }
    kop_ra_save_json($id, 'read', array('role' => $got['role'], 'attended' => $got['attended'], 'cite' => $cite, 'other' => $got['other'],
        'leads' => $got['leads'], 'names' => $got['names'], 'contact' => $contact, 'dropped' => $got['dropped'],
        'provider' => (string) ($GLOBALS['kop_ai_last_provider'] ?? ''), 'read_at' => gmdate('Y-m-d H:i:s')));
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
    return array('lines' => $added, 'error' => '');
}

/** Read it again: its undecided lines go and a fresh reading replaces them. */
function kop_ra_reread($id) {
    $row = kop_ra_answer($id);
    if (!$row) throw new RuntimeException('That answer is gone.');
    if (kop_pp_rows(array('answer_id' => (int) $id, 'status' => 'added'))) throw new RuntimeException('Some of its lines are on the page: Undo first.');
    kop_ra_forget(array((int) $id));
    return kop_ra_read($row);
}

/** The waiting lines of these answers go (the answer was replaced, or is read again). */
function kop_ra_forget(array $ids) {
    if (!$ids || !kop_pp_ready() || !kop_pp_has_accounts()) return;
    global $wpdb;
    foreach ($ids as $id) $wpdb->delete(kop_pp_table(), array('answer_id' => (int) $id, 'status' => 'pending'));
}

/* ---- Decisions ------------------------------------------------------------ */

function kop_ra_last_batch($id) {
    $found = null;
    foreach (kop_pp_log() as $bid => $b) if ((int) ($b['answer_id'] ?? 0) === (int) $id) $found = $bid;
    return $found;
}

function kop_ra_apply($id, array $picked, $by) {
    $rows = kop_pp_rows(array('answer_id' => (int) $id, 'status' => 'pending'));
    $fid = $rows ? (int) $rows[0]['facility_id'] : 0;
    $msg = kop_pp_decide($fid, $rows, $picked, $by, array('topic' => '', 'answer_id' => (int) $id));
    return str_replace('to the facility page', 'to the facility page under "What people who were there remember"', $msg);
}

function kop_ra_leave($id, $by) {
    $rows = kop_pp_rows(array('answer_id' => (int) $id, 'status' => 'pending'));
    return kop_pp_leave_rows($rows ? (int) $rows[0]['facility_id'] : 0, $rows, $by, array('topic' => '', 'answer_id' => (int) $id));
}

function kop_ra_lead_done($id, $by, $done = true) {
    $row = kop_ra_answer($id);
    if (!$row) throw new RuntimeException('That answer is gone.');
    $j = kop_ra_json($row);
    if (empty($j['leads'])) throw new RuntimeException('This answer has no lead.');
    if ($done) {
        $j['lead_done'] = array('by' => (string) $by, 'at' => gmdate('Y-m-d H:i:s'));
    } else {
        unset($j['lead_done']);
    }
    kop_ra_save_json($id, (string) $row['read_status'], $j);
    return $done ? 'Marked followed up. It is off the Leads tab; Undo puts it back.' : 'The lead is open again on the Leads tab.';
}

/** Undo the card's latest step: a lead marked followed up, else the last Add or Leave. */
function kop_ra_undo($id, $by) {
    $row = kop_ra_answer($id);
    $j = $row ? kop_ra_json($row) : array();
    $batch = kop_ra_last_batch($id);
    $batch_at = $batch ? (string) (kop_pp_log()[$batch]['at'] ?? '') : '';
    if (!empty($j['lead_done']) && strcmp((string) $j['lead_done']['at'], $batch_at) >= 0) return kop_ra_lead_done($id, $by, false);
    if (!$batch) throw new RuntimeException('Nothing to undo.');
    return kop_pp_undo_batch($batch);
}

/* ---- The facility page ---------------------------------------------------- */

/** The section "What people who were there remember": one list per topic, each line with their words and who they are. */
function kop_ra_section_html(array $groups) {
    $topics = kop_pp_topics();
    $qurl = function_exists('kop_q_url') ? kop_q_url() : home_url('/questions/');
    ob_start();
    foreach ($groups as $t => $rows) : ?>
        <h3 class="kop-fp-subhead"><?php echo esc_html($topics[$t] ?? $t); ?></h3>
        <ul class="kop-pp-lines kop-ra-lines">
            <?php foreach ($rows as $r) :
                $cite = esc_html((string) $r['doc_title']);
                $cite = preg_replace('/questions page/', '<a href="' . esc_url($qurl) . '">questions page</a>', $cite, 1);
                ?>
                <li<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('pp:' . (int) $r['id'], 'this line') : ''; ?>>
                    <?php echo esc_html((string) $r['value']); ?>
                    <?php if (trim((string) $r['quote']) !== '') : ?><q class="kop-ra-words"><?php echo esc_html((string) $r['quote']); ?></q><?php endif; ?>
                    <span class="kop-ra-cite"><?php echo $cite; // Escaped above. ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endforeach;
    return ob_get_clean();
}

/* ---- Hourly reading ------------------------------------------------------- */

add_action('init', function () {
    if (!wp_next_scheduled('kop_ra_hourly')) wp_schedule_event(time() + 600, 'hourly', 'kop_ra_hourly');
});
add_action('kop_ra_hourly', 'kop_ra_cron');

function kop_ra_cron() {
    if (!function_exists('kop_pp_ready') || !kop_pp_ready() || !kop_pp_has_accounts()) return;
    foreach (kop_ra_unread() as $row) {
        try {
            kop_ra_read($row);
        } catch (Throwable $e) {
            error_log('kop_ra_cron: ' . $e->getMessage());
            break; // Out of AI calls: the rest wait for the next hour.
        }
    }
}
