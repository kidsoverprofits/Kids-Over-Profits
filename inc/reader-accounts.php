<?php
/**
 * Readers' own words on the questions page (/questions/, inc/questions.php),
 * published as testimony (docs/plan 4.4 item 13; owner 2026-10-23, changed
 * 2026-11-11: no paraphrase, their words with a spelling and punctuation pass).
 *
 * Two kinds of answer carry free text about a facility: "What rules do you
 * remember at X?" (f<id>.rules) and the note under any other answer. Each is
 * read once: kop_ai_generate_alternating() gives back their whole text with
 * only spelling, capital letters and punctuation fixed, cut in order into
 * parts: "rules" (how the program ran for everyone: rules, levels,
 * punishments, limits) and "testimony" (everything else: what happened to
 * them or others, what they saw, how it felt; anything unsure), who they are, the
 * years they were there, the record's checklist boxes it shows, leads and the
 * names of people in it. The fixed text is checked word by word against
 * theirs (kop_ra_same_words()); a pass that changed more than that is thrown
 * away and their words are kept as written. Email addresses, phone numbers
 * and links are taken out before the AI sees it (kop_ra_redact()). Names of
 * staff stay, and the quote says what it says of them is the reader's
 * allegation (owner, 2026-11-11); every other name (their own, other
 * residents, family) is taken out of the words shown ("[name]").
 *
 * What a reading finds:
 *   parts      one row of {prefix}kop_program_practices per part, topic
 *              'account' (testimony) or 'account_rules' (owner 2026-11-12:
 *              testimony mixed into a rules answer is always moved out),
 *              answer_id = the answer's id (doc_id 0, doc_title =
 *              who they are, kop_ra_cite(); value = the words published;
 *              quote = their words as sent, contact details out; why =
 *              the staff it names, "; "-separated), waiting at
 *              Review inbox > What readers remember
 *              (inc/review-inbox/reader-accounts.php). Add quotes the
 *              ticked parts: testimony under the facility page's "Survivor
 *              testimony", rules under "Rules and practices" (both
 *              kop_ra_section_html()), and ticks the boxes they show; never
 *              reworded into prose
 *   leads      an offer to be contacted, a court case, a different program
 *              named: the card's Leads tab, admin-only, beside their whole answer
 *
 * The answer row keeps the reading: read_status ('' not read yet, 'read',
 * 'failed') and read_json {role, attended, cite, leads, names, staff, contact,
 * as_written, provider, read_at, error, lead_done}. A reader who answers the
 * same question again replaces their row (kop_q_answer()); the old row's
 * testimony nobody has decided goes with it (kop_ra_forget()).
 *
 * Read hourly, KOP_RA_BATCH answers a run (cron kop_ra_hourly), or at once
 * with the card's "Read now". Tested by scripts/test-review-inbox.php
 * --source=reader-accounts.
 */

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

if (!defined('KOP_RA_BATCH')) define('KOP_RA_BATCH', 8);

/** The kop_program_practices topics of a reader's testimony and of the rules in it (never one of kop_pp_topics()). */
const KOP_RA_TOPIC = 'account';
const KOP_RA_RULES_TOPIC = 'account_rules';

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

/** The words of a text, lower case, without punctuation: what a spelling pass may not change beyond a slip of the keys. */
function kop_ra_words($s) {
    $s = kop_ra_norm($s);
    $s = preg_replace("/(?<=\p{L})'(?=\p{L})/u", '', $s); // "don't" and "dont" are one word.
    return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $s), 'strlen'));
}

/**
 * Is $fixed their $text with only spelling, capitals and punctuation changed?
 * The words are walked side by side; where they part, the stretch on each side
 * up to where they agree again (at most four words) must read the same once
 * joined up, give or take a few letters ("alot" / "a lot", "coean" / "clean",
 * "aloud" / "allowed"). A word added or dropped outright fails it.
 */
function kop_ra_same_words($text, $fixed) {
    $a = kop_ra_words($text);
    $b = kop_ra_words($fixed);
    $na = count($a);
    $nb = count($b);
    if (!$na || !$nb) return $na === $nb;
    $neg = array('no' => 1, 'not' => 1, 'never' => 1, 'nothing' => 1, 'none' => 1, 'nobody' => 1, 'nor' => 1, 'cant' => 1, 'cannot' => 1, 'dont' => 1,
        'didnt' => 1, 'wasnt' => 1, 'werent' => 1, 'wont' => 1, 'couldnt' => 1, 'wouldnt' => 1, 'isnt' => 1, 'arent' => 1, 'havent' => 1, 'hadnt' => 1);
    $close = function (array $wx, array $wy) use ($neg) {
        // Only where they differ: words both share at either end are not a change.
        while ($wx && $wy && $wx[0] === $wy[0]) { array_shift($wx); array_shift($wy); }
        while ($wx && $wy && end($wx) === end($wy)) { array_pop($wx); array_pop($wy); }
        // A "no" or "not" never comes or goes.
        $nx = count(array_filter($wx, function ($w) use ($neg) { return isset($neg[$w]); }));
        $ny = count(array_filter($wy, function ($w) use ($neg) { return isset($neg[$w]); }));
        if ($nx !== $ny) return false;
        $x = implode('', $wx);
        $y = implode('', $wy);
        if ($x === $y) return true;
        if ($x === '' || $y === '') return false;
        $lx = mb_strlen($x);
        $ly = mb_strlen($y);
        $len = max($lx, $ly);
        // Of a like length ("abusive" is not "it was abusive", "bc" is not "because"), a few letters apart.
        if ($len > 40 || min($lx, $ly) / $len < 0.65) return false;
        return levenshtein($x, $y) <= max(2, (int) floor($len * 0.45));
    };
    $i = 0;
    $j = 0;
    $k = 4;
    while ($i < $na && $j < $nb) {
        if ($a[$i] === $b[$j]) { $i++; $j++; continue; }
        // Where do they agree again (two words in a row, or the end)? The shortest stretch first.
        $found = null;
        for ($s = 1; $s <= 2 * $k && !$found; $s++) {
            for ($x = 0; $x <= min($s, $k); $x++) {
                $y = $s - $x;
                if ($y > $k) continue;
                $ii = $i + $x;
                $jj = $j + $y;
                $end = $ii >= $na && $jj >= $nb;
                $agree = $ii < $na && $jj < $nb && $a[$ii] === $b[$jj]
                    && ($ii + 1 >= $na || $jj + 1 >= $nb || $a[$ii + 1] === $b[$jj + 1]);
                if (($end || $agree) && $close(array_slice($a, $i, $x), array_slice($b, $j, $y))) { $found = array($ii, $jj); break; }
            }
        }
        if (!$found) return false;
        list($i, $j) = $found;
    }
    return $close(array_slice($a, $i), array_slice($b, $j)) || ($na - $i === 0 && $nb - $j === 0);
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
    $checks = array();
    foreach (kop_pp_checklist_keys() as $sec => $keys) foreach ($keys as $k) $checks[] = $sec . '.' . $k;
    $asked = 'The question they were asked: "' . $ctx['ask'] . '"' . ($ctx['answer'] !== '' ? "\nTheir answer: \"" . $ctx['answer'] . "\"\nThe note they added is below." : '');
    return 'You prepare one answer that a reader of Kids Over Profits gave about a youth residential program, "' . $ctx['name'] . "\", to be published as their testimony, in their own words.\n"
        . $asked . "\n\nTheir words:\n<<<\n" . $text . "\n>>>\n\n"
        . "Answer with JSON only, in this shape:\n"
        . '{"parts": [{"kind": "", "words": ""}], "role": "", "attended": "", "checklist": [], "leads": [{"kind": "", "text": ""}], "names": [{"name": "", "staff": false}]}' . "\n\n"
        . "parts: their whole text, cut into parts in their order, so that the parts one after another are every word of it. In each part, fix ONLY "
        . "spelling, capital letters and punctuation (full stops between run-on sentences, apostrophes, commas, a capital at the start of a sentence). "
        . "Never add, drop, reorder, replace or explain a word; never expand an abbreviation or change their grammar, slang or swearing; a word you "
        . "cannot make out stays as it is. Keep their line breaks and list dashes. Leave \"[email removed]\", \"[link removed]\" and \"[phone removed]\" "
        . "as they are.\n"
        . "  kind: \"rules\" for a stretch that says how the program was run for everyone there: its rules, levels or phases and what each allowed, "
        . "the punishments it used, the daily schedule, limits on contact with family, what was allowed or not. \"testimony\" for everything else: "
        . "what happened to them or to someone, what they saw, how it felt, opinions, anything said about a named person. A sentence that does both, "
        . "or that you are not sure of, is testimony. A rules part only states what the rule or practice was. How they felt about it, what they "
        . "think of it, and any reason, excuse or defence for it (\"it was for our safety\", \"they had to\", \"to their credit\", \"it wasn't that bad\", "
        . "\"it was pretty chill\", \"it made sense at the time\") is testimony, and so is a sentence that softens or limits a rule "
        . "(\"only as a last resort\", \"only when needed\", \"rarely\", \"for the most part\"): the whole sentence goes, even in the middle of a list of "
        . "rules: cut it out of the rules into its own testimony part. Make each part as long as it can be: a new part only where the kind changes.\n"
        . "role: was_there if they say they were in the program, worked if they worked there, parent if their child was there, else unknown.\n"
        . "attended: the years they say they were there, like \"2010-2012\" or \"2007\", else \"\".\n"
        . 'checklist: those of these their words show the program did, else []: ' . implode(', ', $checks) . ".\n"
        . "leads: kind \"contact\" when they offer to be contacted, \"court\" when they mention a court case or lawsuit, \"other_program\" when the answer "
        . "is about a different program (name it in text), \"other\" for anything else a researcher should follow up; text says what it is.\n"
        . "names: every name of a person in their words, as written, each as {\"name\": \"\", \"staff\": false}. staff is true only when their "
        . "words show that person worked at the program (staff, counselor, therapist, teacher, director, owner, guard, \"Mr. X\" giving orders); "
        . "false for themselves, other residents, their family and anyone else.";
}

/** One AI call; a test stands in with $GLOBALS['kop_ra_ai']. */
function kop_ra_ai($prompt) {
    if (!empty($GLOBALS['kop_ra_ai']) && is_callable($GLOBALS['kop_ra_ai'])) return (string) call_user_func($GLOBALS['kop_ra_ai'], $prompt);
    require_once get_stylesheet_directory() . '/api/ai-providers.php';
    return kop_ai_generate_alternating($prompt, array('maxTokens' => 6000, 'temperature' => 0.1));
}

/**
 * The AI's answer, checked: {parts: [{kind, words, staff}], as_written, role,
 * attended, checklist, leads, names (taken out), staff (left in)}. Throws
 * when it is not the JSON asked for.
 */
function kop_ra_parse($raw, $text) {
    $s = trim(preg_replace('/^```\w*\s*|\s*```$/', '', trim((string) $raw)));
    $a = strpos($s, '{');
    $b = strrpos($s, '}');
    $j = $a !== false && $b > $a ? json_decode(substr($s, $a, $b - $a + 1), true) : null;
    if (!is_array($j) || (!isset($j['parts']) && !array_key_exists('words', $j))) throw new RuntimeException('The AI did not answer with the JSON asked for.');

    $names = array();
    $staff = array();
    foreach ((array) ($j['names'] ?? array()) as $n) {
        // Staff stay named; anyone else, or a name given without saying, comes out.
        $is_staff = is_array($n) && !empty($n['staff']) && $n['staff'] !== 'false';
        $n = trim((string) (is_array($n) ? ($n['name'] ?? '') : $n));
        if (mb_strlen($n) < 2 || mb_strlen($n) > 60 || mb_stripos($text, $n) === false) continue;
        if ($is_staff) $staff[] = $n;
        else $names[] = $n;
    }
    // A name both ways ("John" a resident, "Mr. John Smith" staff) comes out.
    $staff = array_values(array_unique(array_filter($staff, function ($n) use ($names) {
        foreach ($names as $m) if (mb_strtolower($m) === mb_strtolower($n)) return false;
        return true;
    })));
    // The longest first, so "Mr. John Smith" goes before "John".
    usort($names, function ($x, $y) { return mb_strlen($y) <=> mb_strlen($x); });
    $unname = function ($s) use ($names) {
        foreach ($names as $n) $s = preg_replace('/(?<![\p{L}])' . preg_quote($n, '/') . '(?![\p{L}])/iu', '[name]', $s);
        return $s;
    };
    $role = in_array($j['role'] ?? '', array('was_there', 'worked', 'parent'), true) ? $j['role'] : 'unknown';
    $attended = preg_match('/^(19[4-9]\d|20\d\d)(?:\s*[-\x{2013}]\s*(19[4-9]\d|20\d\d))?$/u', trim((string) ($j['attended'] ?? '')), $m)
        ? $m[1] . (!empty($m[2]) && $m[2] !== $m[1] ? '-' . $m[2] : '') : '';
    // Their words in parts, the spelling fixed. Parts that together are not every word of theirs, in order, are not theirs:
    // their words stand as written, all of it testimony.
    $raw_parts = isset($j['parts']) && is_array($j['parts']) ? $j['parts'] : array(array('kind' => 'testimony', 'words' => (string) ($j['words'] ?? '')));
    $parts = array();
    foreach ($raw_parts as $pt) {
        $w = trim(str_replace("\r\n", "\n", (string) (is_array($pt) ? ($pt['words'] ?? '') : $pt)));
        if ($w === '') continue;
        $kind = is_array($pt) && ($pt['kind'] ?? '') === 'rules' ? 'rules' : 'testimony';
        $n = count($parts);
        if ($n && $parts[$n - 1]['kind'] === $kind) $parts[$n - 1]['words'] .= (preg_match('/\n\s*$/', $parts[$n - 1]['words']) ? '' : ' ') . $w;
        else $parts[] = array('kind' => $kind, 'words' => $w);
    }
    $parts = kop_ra_rules_sift($parts);
    $as_written = !$parts || !kop_ra_same_words($text, implode(' ', array_column($parts, 'words')));
    if ($as_written) $parts = array(array('kind' => 'testimony', 'words' => trim(str_replace("\r\n", "\n", (string) $text))));
    foreach ($parts as &$pt) {
        $pt['words'] = $unname($pt['words']);
        $pt['staff'] = array_values(array_filter($staff, function ($n) use ($pt) { return mb_stripos($pt['words'], $n) !== false; }));
    }
    unset($pt);
    $leads = array();
    foreach ((array) ($j['leads'] ?? array()) as $l) {
        if (!is_array($l) || trim((string) ($l['text'] ?? '')) === '') continue;
        $kind = in_array($l['kind'] ?? '', array('contact', 'court', 'other_program'), true) ? $l['kind'] : 'other';
        $leads[] = array('kind' => $kind, 'text' => mb_substr(trim((string) $l['text']), 0, 300));
    }
    return array(
        'parts' => $parts, 'as_written' => $as_written, 'role' => $role, 'attended' => $attended,
        'checklist' => array_values(array_unique(array_filter(array_map('kop_pp_clean_check', (array) ($j['checklist'] ?? array()))))),
        'leads' => $leads, 'names' => $names, 'staff' => $staff,
    );
}

/**
 * Words that make a sentence testimony however it is marked (owner, 2026-11-13): how they felt about a rule, an excuse or
 * defence for it, or a softener ("as a last resort"). Matched on whole words, lower case.
 */
function kop_ra_not_rules_words() {
    return array(
        'last resort', 'only when needed', 'only when necessary', 'only if needed', 'only if necessary', 'when absolutely necessary',
        'for the most part', 'rarely', 'to their credit', 'in fairness', 'to be fair', 'for our safety', 'for our own safety',
        'for our own good', 'for our protection', 'it made sense', 'made sense at the time', 'not that bad', "wasn't that bad",
        'wasnt that bad', "wasn't so bad", 'pretty chill', 'chill af', 'i think', 'i guess', 'i felt', 'i feel', 'i hated', 'i liked',
        'i loved', 'honestly', 'unfair', 'ridiculous', 'stupid', 'abusive', 'traumatizing', 'traumatising', 'traumatic', 'cruel', 'awful',
        'horrible', 'terrible', 'understandable', 'reasonable', 'necessary evil', 'good intentions', 'meant well',
    );
}

/**
 * The backstop: a sentence of a rules part carrying kop_ra_not_rules_words() moves to testimony, whole. The parts stay
 * consecutive and same-kind neighbours are joined, so their words in order are unchanged.
 */
function kop_ra_rules_sift(array $parts) {
    $pat = '/(?<![\p{L}\'])(?:' . implode('|', array_map(function ($w) { return preg_quote($w, '/'); }, kop_ra_not_rules_words())) . ')(?![\p{L}])/iu';
    $out = array();
    $push = function ($kind, $w) use (&$out) {
        $w = trim($w, " \t");
        if (trim($w) === '') return;
        $n = count($out);
        if ($n && $out[$n - 1]['kind'] === $kind) $out[$n - 1]['words'] .= (preg_match('/\n\s*$/', $out[$n - 1]['words']) ? '' : ' ') . $w;
        else $out[] = array('kind' => $kind, 'words' => $w);
    };
    foreach ($parts as $pt) {
        $w = str_replace(array("\u{2019}", "\u{2018}"), "'", $pt['words']);
        if ($pt['kind'] !== 'rules' || !preg_match($pat, $w)) { $push($pt['kind'], $pt['words']); continue; }
        foreach (preg_split('/(?<=[.!?])[ \t]+|(?<=\n)/u', $pt['words']) as $sentence) {
            $push(preg_match($pat, str_replace(array("\u{2019}", "\u{2018}"), "'", $sentence)) ? 'testimony' : 'rules', $sentence);
        }
    }
    foreach ($out as &$o) $o['words'] = trim($o['words']);
    unset($o);
    return $out;
}

/** What a lead's kind is called on the card. */
function kop_ra_lead_kinds() {
    return array('contact' => 'Offers to be contacted', 'court' => 'Court case', 'other_program' => 'About a different program', 'other' => 'To follow up');
}

/**
 * Read one answer row: its testimony waits on its card, the rest is kept on
 * the row. A form problem gets one more try with the reason. Throws on an AI
 * that is out of calls ("rate limit"), leaving the row unread.
 */
function kop_ra_read(array $row) {
    $id = (int) $row['id'];
    list($text, $contact) = kop_ra_redact(kop_ra_text($row));
    $ctx = kop_ra_context($row);
    $prompt = kop_ra_prompt($ctx, $text);
    $old = kop_ra_json($row);
    try {
        try {
            $got = kop_ra_parse(kop_ra_ai($prompt), $text);
        } catch (RuntimeException $e) {
            $got = kop_ra_parse(kop_ra_ai($prompt . "\n\nYour last answer was refused: " . $e->getMessage() . ' Answer with the JSON only.'), $text);
        }
    } catch (Throwable $e) {
        if (stripos($e->getMessage(), 'rate limit') !== false) throw $e;
        kop_ra_save_json($id, 'failed', array('error' => mb_substr($e->getMessage(), 0, 300), 'read_at' => gmdate('Y-m-d H:i:s'))
            + array_intersect_key($old, array('lead_done' => 1)));
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
    foreach ($got['parts'] as $i => $pt) {
        if (trim(preg_replace('/\[(?:email|link|phone) removed\]/', '', $pt['words'])) === '') continue;
        $wpdb->insert(kop_pp_table(), array(
            'ikey' => sha1('answer|' . $id . '|' . $i . '|' . $pt['words']), 'facility_id' => $ctx['fid'], 'doc_id' => 0, 'answer_id' => $id,
            'doc_title' => mb_substr($cite, 0, 255), 'doc_url' => '', 'page' => $i + 1,
            'topic' => $pt['kind'] === 'rules' ? KOP_RA_RULES_TOPIC : KOP_RA_TOPIC, 'value' => $pt['words'], 'value_orig' => $pt['words'],
            'quote' => $text, 'why' => mb_substr(implode('; ', $pt['staff']), 0, 500), 'year' => $got['attended'] !== '' ? (int) substr($got['attended'], -4) : 0,
            'checklist' => implode(',', $got['checklist']), 'status' => 'pending', 'batch' => '', 'created_at' => gmdate('Y-m-d H:i:s'),
        ));
        if ($wpdb->insert_id) $added++;
    }
    kop_ra_save_json($id, 'read', array('role' => $got['role'], 'attended' => $got['attended'], 'cite' => $cite,
        'leads' => $got['leads'], 'names' => $got['names'], 'staff' => $got['staff'], 'contact' => $contact, 'as_written' => $got['as_written'],
        'provider' => (string) ($GLOBALS['kop_ai_last_provider'] ?? ''), 'read_at' => gmdate('Y-m-d H:i:s'))
        + array_intersect_key($old, array('lead_done' => 1)));
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
    return array('lines' => $added, 'error' => '');
}

/** Read it again: its undecided testimony goes and a fresh reading replaces it. */
function kop_ra_reread($id) {
    $row = kop_ra_answer($id);
    if (!$row) throw new RuntimeException('That answer is gone.');
    if (kop_pp_rows(array('answer_id' => (int) $id, 'status' => 'added'))) throw new RuntimeException('Their words are on the page: Undo first.');
    kop_ra_forget(array((int) $id), true);
    return kop_ra_read($row);
}

/** The waiting testimony of these answers goes (the answer was replaced, or is read again; $all: left off too). */
function kop_ra_forget(array $ids, $all = false) {
    if (!$ids || !kop_pp_ready() || !kop_pp_has_accounts()) return;
    global $wpdb;
    foreach ($ids as $id) {
        $wpdb->delete(kop_pp_table(), array('answer_id' => (int) $id, 'status' => 'pending'));
        if ($all) $wpdb->delete(kop_pp_table(), array('answer_id' => (int) $id, 'status' => 'left'));
    }
}

/**
 * Once (2026-11-11): the lines read from readers' answers before, our plain
 * wording of their words, go, from the cards and from the pages; every answer
 * is read again into testimony and waits for review. Boxes those lines ticked
 * on records stay ticked. Then again for each change since (testimony2: staff
 * names left in; testimony3, 2026-11-12: rules apart from testimony;
 * testimony4, 2026-11-13: feelings about and excuses for rules are testimony):
 * testimony still waiting is read again; what was added or left off stays.
 */
function kop_ra_migrate_to_testimony() {
    if (!kop_pp_ready() || !kop_pp_has_accounts()) return;
    $mode = get_option('kop_ra_mode');
    if ($mode === 'testimony4') return;
    global $wpdb;
    if ($mode === 'testimony' || $mode === 'testimony2' || $mode === 'testimony3') {
        kop_q_ensure_tables();
        $wpdb->query('DELETE FROM ' . kop_pp_table() . " WHERE answer_id > 0 AND status = 'pending'");
        $wpdb->query('UPDATE ' . kop_q_table() . " SET read_status = '' WHERE " . kop_ra_where() . " AND read_status <> ''"
            . ' AND id NOT IN (SELECT answer_id FROM ' . kop_pp_table() . ' WHERE answer_id > 0)');
        update_option('kop_ra_mode', 'testimony4', false);
        return;
    }
    $fids = array_map('intval', (array) $wpdb->get_col('SELECT DISTINCT facility_id FROM ' . kop_pp_table() . " WHERE answer_id > 0 AND status = 'added'"));
    $wpdb->query('DELETE FROM ' . kop_pp_table() . " WHERE answer_id > 0 AND topic NOT IN ('" . KOP_RA_TOPIC . "', '" . KOP_RA_RULES_TOPIC . "')");
    $log = kop_pp_log();
    foreach ($log as $bid => $b) if (!empty($b['answer_id'])) unset($log[$bid]);
    kop_pp_save_log($log);
    kop_q_ensure_tables();
    $wpdb->query('UPDATE ' . kop_q_table() . " SET read_status = '' WHERE " . kop_ra_where() . " AND read_status <> ''");
    foreach ($fids as $fid) kop_pp_purge($fid);
    if ($fids && function_exists('kop_record_changed')) kop_record_changed('facility', $fids);
    update_option('kop_ra_mode', 'testimony4', false);
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
    $where = array();
    $picked = array_flip(array_map('intval', $picked));
    foreach ($rows as $r) if (isset($picked[(int) $r['id']])) $where[$r['topic'] === KOP_RA_RULES_TOPIC ? 'rules' : 'testimony'] = true;
    $under = implode(' and ', array_map(function ($k) { return $k === 'rules' ? '"Rules and practices"' : '"Survivor testimony"'; }, array_keys($where)));
    return preg_replace('/^Added \d+ lines? to the facility page(, left (\d+) off)?/', 'Added their words to the facility page under ' . $under . '${1}', $msg);
}

function kop_ra_leave($id, $by) {
    $rows = kop_pp_rows(array('answer_id' => (int) $id, 'status' => 'pending'));
    $msg = kop_pp_leave_rows($rows ? (int) $rows[0]['facility_id'] : 0, $rows, $by, array('topic' => '', 'answer_id' => (int) $id));
    return preg_replace('/^Left \d+ lines? off/', 'Left their words off', $msg);
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

/** A record's readers' testimony ($topic KOP_RA_TOPIC) or remembered rules (KOP_RA_RULES_TOPIC) on its page, in answer and part order. */
function kop_ra_for_facility($fid, $topic = KOP_RA_TOPIC) {
    $rows = kop_pp_rows(array('facility_id' => (int) $fid, 'status' => 'added', 'kind' => 'account', 'topic' => $topic));
    usort($rows, function ($x, $y) { return array((int) $x['answer_id'], (int) $x['page']) <=> array((int) $y['answer_id'], (int) $y['page']); });
    return $rows;
}

/** The rules readers remember, under "Rules and practices": their words quoted, never reworded. */
function kop_ra_rules_html(array $rows) {
    if (!$rows) return '';
    return '<h3 class="kop-fp-subhead">What people who were there remember</h3>'
        . '<p class="kop-fp-count">In their own words, from our questions page; only the spelling and punctuation are fixed.</p>'
        . kop_ra_section_html($rows);
}

/** Their words as quotes in the "Survivor testimony" section, each with who they are and, when it names staff, that it is their allegation. */
function kop_ra_section_html(array $rows) {
    $qurl = function_exists('kop_q_url') ? kop_q_url() : home_url('/questions/');
    ob_start();
    foreach ($rows as $r) :
        $cite = esc_html((string) $r['doc_title']);
        $cite = preg_replace('/questions page/', '<a href="' . esc_url($qurl) . '">questions page</a>', $cite, 1);
        ?>
        <figure class="kop-fp-testimony kop-ra-testimony"<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('pp:' . (int) $r['id'], 'these words') : ''; ?>>
            <blockquote><?php foreach (preg_split('/\R\s*\R/', trim((string) $r['value'])) as $para) : ?><p><?php echo nl2br(esc_html(trim($para))); ?></p><?php endforeach; ?></blockquote>
            <figcaption><?php echo $cite; // Escaped above. ?>
                <?php $staff = array_filter(array_map('trim', explode(';', (string) ($r['why'] ?? '')))); if ($staff) : ?>
                <span class="kop-ra-allegation">Names staff: <?php echo esc_html(implode(', ', $staff)); ?>. What it says about them is this reader's allegation, not a finding; Kids Over Profits has not verified it.</span>
                <?php endif; ?></figcaption>
        </figure>
    <?php endforeach;
    return ob_get_clean();
}

/* ---- Hourly reading ------------------------------------------------------- */

add_action('init', function () {
    if (!wp_next_scheduled('kop_ra_hourly')) wp_schedule_event(time() + 600, 'hourly', 'kop_ra_hourly');
});
add_action('kop_ra_hourly', 'kop_ra_cron');
add_action('admin_init', function () {
    if (function_exists('kop_pp_ready') && kop_pp_ready() && kop_pp_has_accounts()) kop_ra_migrate_to_testimony();
}, 20);

function kop_ra_cron() {
    if (!function_exists('kop_pp_ready') || !kop_pp_ready() || !kop_pp_has_accounts()) return;
    kop_ra_migrate_to_testimony();
    foreach (kop_ra_unread() as $row) {
        try {
            kop_ra_read($row);
        } catch (Throwable $e) {
            error_log('kop_ra_cron: ' . $e->getMessage());
            break; // Out of AI calls: the rest wait for the next hour.
        }
    }
}
