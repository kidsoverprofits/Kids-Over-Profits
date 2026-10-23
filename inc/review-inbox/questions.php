<?php
/**
 * Review inbox source: readers' answers to the questions page (/questions/,
 * inc/questions.php). One card per question that has answers: what was
 * asked, each answer with how many people gave it, how they know, their
 * links and notes. A clear majority settles a question on its own (through
 * 'settle' here, as reviewer "questions page"); the rest wait here: staff
 * years always, anything people disagree on, and an answer whose note says
 * they are not sure (kop_q_hedged()). Remembered rules are not here: each
 * answer is read into lines on Review inbox > What readers remember
 * (inc/review-inbox/reader-accounts.php), and so is every note.
 *
 *   Use this answer   writes it to the record (facility facts are held for
 *                     the record's write-up; companies and name years at once)
 *   Write my own      the owner's value instead
 *   Dismiss           nothing changes and the question is no longer asked
 *   Undo              takes back what was written; the question is asked again
 *
 * Keys are the questions' own (f<id>.<what>, o<id>.<what>, x<hash>).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('questions', function () {
    if (!function_exists('kop_q_tally')) return null;
    return array(
        'label'       => 'Answers to our questions',
        'group'       => 'Sent in by readers',
        'help'        => 'What readers answered on the questions page (/questions/): when a program opened or closed, its town, ages, practices, staff, rules, '
            . 'company facts, and which source is right where sources disagree. When ' . KOP_Q_AGREE . ' different people give the same answer and nobody says otherwise, '
            . 'it goes through on its own and is listed under Settled with Undo. Staff years always wait here, and so does an answer whose note says they are not sure. '
            . 'Remembered rules and the notes are read into lines under What readers remember. '
            . 'Use this answer writes it to the record (a program\'s facts wait for its next write-up); Dismiss stops asking.',
        'views'       => array('waiting' => 'Waiting', 'settled' => 'Settled', 'dismissed' => 'Dismissed'),
        'count'       => function () { return kop_rinbox_q_view_counts()['waiting']; },
        'view_counts' => 'kop_rinbox_q_view_counts',
        'list'        => 'kop_rinbox_q_list',
        'get'         => 'kop_rinbox_q_item',
        'act'         => 'kop_rinbox_q_act',
    );
});

/** [qkey => {n, last, status}] for every question with answers. */
function kop_rinbox_q_keys() {
    kop_q_ensure_tables();
    global $wpdb;
    $out = array();
    foreach ((array) $wpdb->get_results('SELECT qkey, COUNT(DISTINCT who) AS n, MAX(created_at) AS last FROM ' . kop_q_table() . ' GROUP BY qkey', ARRAY_A) as $r) {
        // Remembered rules wait under What readers remember, one card per answer; one settled before that still shows here.
        if (function_exists('kop_ra_is_account_key') && kop_ra_is_account_key($r['qkey'])) continue;
        $out[$r['qkey']] = array('n' => (int) $r['n'], 'last' => (string) $r['last'], 'status' => 'waiting');
    }
    foreach ((array) $wpdb->get_results('SELECT qkey, status, reviewed_at FROM ' . kop_q_state_table(), ARRAY_A) as $r) {
        if (!isset($out[$r['qkey']])) $out[$r['qkey']] = array('n' => 0, 'last' => (string) $r['reviewed_at']);
        $out[$r['qkey']]['status'] = $r['status'] === 'settled' ? 'settled' : 'dismissed';
    }
    return $out;
}

function kop_rinbox_q_view_counts() {
    $c = array('waiting' => 0, 'settled' => 0, 'dismissed' => 0);
    foreach (kop_rinbox_q_keys() as $k) $c[$k['status']]++;
    return $c;
}

function kop_rinbox_q_list(array $q) {
    $keys = array_filter(kop_rinbox_q_keys(), function ($k) use ($q) { return $k['status'] === ($q['view'] ?? 'waiting'); });
    // Most answers first, then the newest.
    uasort($keys, function ($a, $b) { return $b['n'] <=> $a['n'] ?: strcmp($b['last'], $a['last']); });
    $items = array();
    $search = mb_strtolower(trim((string) ($q['search'] ?? '')));
    foreach (array_keys($keys) as $key) {
        $it = kop_rinbox_q_item((string) $key);
        if (!$it) continue;
        if ($search !== '' && mb_strpos(mb_strtolower($it['title'] . ' ' . $it['text']), $search) === false) continue;
        $items[] = $it;
    }
    return array('items' => array_slice($items, (int) $q['offset'], (int) $q['limit']), 'total' => count($items));
}

function kop_rinbox_q_item($key) {
    $key = (string) $key;
    $state = kop_q_state($key);
    $question = $state ? null : kop_q_get($key);
    $rows = kop_q_rows($key);
    if (!$question && !$state && !$rows) return null;
    $tally = kop_q_tally($rows);
    $hows = kop_q_hows();
    $ask = $question ? $question['ask'] : ($state ? (string) $state['ask'] : '');
    $details = array();
    $links = array();
    $options = array();
    foreach ($tally as $t) {
        $how = array();
        foreach ($t['hows'] as $h => $n) $how[] = strtolower($hows[$h] ?? $h) . ($n > 1 ? ' (' . $n . ')' : '');
        $details[] = array('label' => $t['count'] . ' ' . ($t['count'] === 1 ? 'person' : 'people'),
            'value' => $t['label'] . ($how ? '. How they know: ' . implode(', ', $how) : '') . ($t['notes'] ? '. Notes: ' . implode(' / ', $t['notes']) : ''));
        foreach (array_unique($t['sources']) as $u) $links[] = array('label' => 'Link given for "' . mb_substr($t['label'], 0, 40) . '"', 'url' => $u);
        $options[$t['key']] = mb_substr($t['label'], 0, 120) . ' (' . $t['count'] . ')';
    }
    $fac = null;
    if (preg_match('/^f(\d+)\./', $key, $m)) $fac = kop_rinbox_facility((int) $m[1]);
    elseif ($question && $question['record']['kind'] === 'facility') $fac = kop_rinbox_facility((int) $question['record']['id']);

    $actions = array();
    $status = $state ? ($state['status'] === 'settled' ? 'settled' : 'dismissed') : 'waiting';
    if ($status === 'waiting') {
        $hedged = kop_q_hedged_rows($rows);
        if ($hedged) {
            $details[] = array('label' => 'Not settled on its own', 'value' => 'A note qualifies the answer: "'
                . implode('" / "', array_map(function ($r) { return mb_substr((string) $r['note'], 0, 200); }, $hedged)) . '"');
        }
        if (!$question) {
            $details[] = array('label' => 'No longer asked', 'value' => 'The record has an answer now, or the question was settled another way.');
        } elseif ($options) {
            $actions[] = array('id' => 'settle', 'label' => 'Use this answer', 'style' => 'approve',
                'params' => array(array('name' => 'answer', 'label' => 'Answer', 'type' => 'select', 'options' => $options, 'value' => (string) array_key_first($options))),
                'help' => 'Writes the answer you pick to ' . $question['record']['name'] . ', citing the questions page (a program\'s facts go on with its next write-up). Undo on the Settled tab.');
            if ($question['type'] !== 'swipe') {
                $actions[] = array('id' => 'settle_own', 'label' => 'Write my own…', 'style' => 'neutral', 'ask' => true, 'submit' => 'Write it',
                    'params' => array(array('name' => 'value', 'label' => 'The answer', 'type' => 'text', 'value' => '')),
                    'help' => 'Writes the answer you type instead (a year, a town, ages as "12 to 17"). Undo on the Settled tab.');
            }
        }
        $actions[] = array('id' => 'dismiss', 'label' => 'Dismiss', 'style' => 'reject',
            'help' => 'Nothing on the site changes and the page stops asking this question. Undo on the Dismissed tab.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => $status === 'settled' ? 'Undo' : 'Ask again', 'style' => $status === 'settled' ? 'undo' : 'neutral',
            'help' => $status === 'settled' ? 'Takes back what was written to the record and asks the question again.' : 'The page asks this question again.');
        $details[] = array('label' => $status === 'settled' ? 'Settled' : 'Dismissed',
            'value' => trim((string) $state['answer'] . ' (' . $state['reviewed_by'] . ', ' . $state['reviewed_at'] . ' UTC)'));
    }
    $total = array_sum(array_column($tally, 'count'));
    return array(
        'key'          => $key,
        'title'        => $ask !== '' ? $ask : $key,
        'subtitle'     => $total . ' ' . ($total === 1 ? 'answer' : 'answers') . ($tally ? ' · most say ' . mb_substr($tally[0]['label'], 0, 60) : '')
            . ($question && empty($question['auto']) ? ' · always waits for you' : ''),
        'url'          => kop_q_url($key, $question ?: ($fac ? array('kind' => 'facility', 'id' => (int) $fac['id']) : null)),
        'text'         => (string) ($question['sub'] ?? ''),
        'details'      => $details,
        'links'        => $links,
        'fields'       => array(),
        'facility'     => $fac,
        'status'       => $status,
        'status_label' => array('waiting' => 'Waiting', 'settled' => 'Settled', 'dismissed' => 'Dismissed')[$status],
        'created'      => $rows ? (string) end($rows)['created_at'] : '',
        'actions'      => $actions,
        'selected'     => false,
    );
}

function kop_rinbox_q_act($key, $action, array $params) {
    $by = kop_rinbox_reviewer();
    switch ($action) {
        case 'settle':
            return array('message' => kop_q_settle($key, (string) ($params['answer'] ?? ''), $by));
        case 'settle_own':
            $v = trim((string) ($params['value'] ?? ''));
            if ($v === '') throw new RuntimeException('Type the answer first.');
            return array('message' => kop_q_settle($key, '', $by, $v));
        case 'dismiss':
            $q = kop_q_get($key);
            return array('message' => kop_q_dismiss($key, $q ? $q['ask'] : $key, $by));
        case 'undo':
            $s = kop_q_state($key);
            $msg = kop_q_undo($key, $by);
            return array('message' => $s && $s['status'] === 'dismissed' ? 'The page asks this question again.' : $msg);
    }
    throw new RuntimeException('Unknown action.');
}
