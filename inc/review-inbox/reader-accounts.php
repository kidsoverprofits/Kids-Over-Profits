<?php
/**
 * Review inbox source: what readers wrote on the questions page, read into
 * lines (inc/reader-accounts.php). One card per answer: the rules someone
 * remembers at a program, or the note under any other answer. The card shows
 * their whole answer (admins only, contact details and all), the lines read
 * from it, each ticked with the words it rests on, what is not about how the
 * program ran (never published) and any lead.
 *
 *   Add the ticked lines   puts them in the facility page's section "What
 *                          people who were there remember", cited by who they
 *                          are, and ticks the record's boxes they show
 *   Leave all off          nothing on the site changes
 *   Followed up            takes a lead off the Leads tab
 *   Read now / again       reads an answer the hourly run has not reached, or
 *                          one whose reading failed or looks wrong
 *   Undo                   the card's latest step
 *
 * Keys: "a<answer id>".
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('reader-accounts', function () {
    if (!function_exists('kop_ra_read') || !function_exists('kop_q_table')) return null;
    return array(
        'label'       => 'What readers remember',
        'group'       => 'Sent in by readers',
        'help'        => 'What readers wrote on the questions page (/questions/): the rules they remember at a program, and the notes they add under an answer. '
            . 'Each answer is read into lines in plain words, with the reader\'s own words under each. Add puts the ticked lines on the facility page under '
            . '"What people who were there remember", cited by who the reader is (a former resident, a parent...), never by name. Contact details and names '
            . 'are taken out first. What is not about how the program ran stays here, never on the site. An offer of contact, a court case or another '
            . 'program named waits on the Leads tab.',
        'views'       => array('waiting' => 'To review', 'leads' => 'Leads', 'added' => 'Added', 'left' => 'Left off', 'nothing' => 'Nothing to add'),
        'count'       => function () { return kop_rinbox_ra_view_counts()['waiting']; },
        'view_counts' => 'kop_rinbox_ra_view_counts',
        'list'        => 'kop_rinbox_ra_list',
        'get'         => function ($key) { return kop_rinbox_ra_item((int) substr((string) $key, 1)); },
        'act'         => 'kop_rinbox_ra_act',
    );
});

/** answer id => {row, pending, added, left, view, lead}. */
function kop_rinbox_ra_cards() {
    kop_q_ensure_tables();
    global $wpdb;
    $counts = array();
    if (kop_pp_ready() && kop_pp_has_accounts()) {
        foreach ((array) $wpdb->get_results('SELECT answer_id, status, COUNT(*) AS n FROM ' . kop_pp_table() . ' WHERE answer_id > 0 GROUP BY answer_id, status', ARRAY_A) as $r) {
            $counts[(int) $r['answer_id']][$r['status']] = (int) $r['n'];
        }
    }
    $cards = array();
    foreach ((array) $wpdb->get_results('SELECT * FROM ' . kop_q_table() . ' WHERE ' . kop_ra_where() . ' ORDER BY id DESC', ARRAY_A) as $row) {
        $c = $counts[(int) $row['id']] ?? array();
        $c += array('pending' => 0, 'added' => 0, 'left' => 0);
        $j = kop_ra_json($row);
        if ($row['read_status'] !== 'read' || $c['pending'] > 0) $view = 'waiting';
        elseif ($c['added'] > 0) $view = 'added';
        elseif ($c['left'] > 0) $view = 'left';
        else $view = 'nothing';
        $cards[(int) $row['id']] = $c + array('row' => $row, 'view' => $view, 'lead' => !empty($j['leads']) && empty($j['lead_done']));
    }
    return $cards;
}

function kop_rinbox_ra_in_view(array $c, $view) {
    return $view === 'leads' ? $c['lead'] : $c['view'] === $view;
}

function kop_rinbox_ra_view_counts() {
    $out = array('waiting' => 0, 'leads' => 0, 'added' => 0, 'left' => 0, 'nothing' => 0);
    foreach (kop_rinbox_ra_cards() as $c) {
        $out[$c['view']]++;
        if ($c['lead']) $out['leads']++;
    }
    return $out;
}

function kop_rinbox_ra_list(array $q) {
    $view = (string) ($q['view'] ?? 'waiting');
    $search = mb_strtolower(trim((string) ($q['search'] ?? '')));
    $items = array();
    foreach (kop_rinbox_ra_cards() as $id => $c) {
        if (!kop_rinbox_ra_in_view($c, $view)) continue;
        $it = kop_rinbox_ra_item($id, $c);
        if (!$it) continue;
        if ($search !== '' && mb_strpos(mb_strtolower($it['title'] . ' ' . $it['text']), $search) === false) continue;
        $items[] = $it;
    }
    return array('items' => array_slice($items, (int) $q['offset'], (int) $q['limit']), 'total' => count($items));
}

function kop_rinbox_ra_item($id, array $c = null) {
    if ($c === null) $c = kop_rinbox_ra_cards()[(int) $id] ?? null;
    if (!$c) return null;
    $row = $c['row'];
    $j = kop_ra_json($row);
    $ctx = kop_ra_context($row);
    $fac = kop_rinbox_facility($ctx['fid']);
    $rules = kop_ra_is_account_key($row['qkey']);
    $topics = kop_pp_topics();
    $labels = function_exists('kop_facility_checklist_labels') ? kop_facility_checklist_labels() : array();
    $hows = kop_q_hows();

    $text = $rules ? 'Their answer (only admins see this): "' . $row['answer'] . '"'
        : 'Asked: ' . $ctx['ask'] . ' They answered: ' . $row['answer'] . '. Their note (only admins see this): "' . $row['note'] . '"';
    $details = array();
    $details[] = array('label' => 'How they know', 'value' => $row['how'] !== '' ? ($hows[$row['how']] ?? $row['how']) : 'They did not say');
    if (!empty($j['cite'])) $details[] = array('label' => 'Cited on the page as', 'value' => $j['cite']);
    if ($row['read_status'] === '') $details[] = array('label' => 'Not read yet', 'value' => 'The hourly reading reaches it soon, or press Read now.');
    if ($row['read_status'] === 'failed') $details[] = array('label' => 'Reading failed', 'value' => (string) ($j['error'] ?? '') . ' Press Read again.');
    foreach ((array) ($j['leads'] ?? array()) as $l) {
        $details[] = array('label' => 'Lead: ' . (kop_ra_lead_kinds()[$l['kind']] ?? $l['kind']) . (!empty($j['lead_done']) ? ' (followed up)' : ''), 'value' => $l['text']);
    }
    foreach ((array) ($j['other'] ?? array()) as $o) $details[] = array('label' => 'Not about how it ran (never published)', 'value' => $o);
    if (!empty($j['names'])) $details[] = array('label' => 'Names taken out', 'value' => implode(', ', $j['names']));
    if (!empty($j['dropped'])) $details[] = array('label' => 'Lines dropped', 'value' => (int) $j['dropped'] . ' the AI wrote did not rest on their words.');

    $checklist = array();
    foreach (kop_pp_rows(array('answer_id' => (int) $id)) as $r) {
        $checks = array_filter(explode(',', (string) $r['checklist']));
        $sub = ($topics[$r['topic']] ?? $r['topic']) . ($checks ? '. Ticks: ' . implode(', ', array_map(function ($k) use ($labels) { return $labels[$k] ?? $k; }, $checks)) : '');
        if ($r['status'] === 'pending') {
            $checklist[] = array('keys' => array((string) $r['id']), 'label' => (string) $r['value'], 'url' => '', 'sub' => $sub,
                'note' => '"' . $r['quote'] . '"', 'checked' => true);
        } else {
            $details[] = array('label' => $r['status'] === 'added' ? 'On the page' : 'Left off', 'value' => $r['value'] . ' ("' . $r['quote'] . '"; ' . $sub . ')');
        }
    }

    $actions = array();
    $name = $fac ? $fac['name'] : 'the facility';
    if ($checklist) {
        $actions[] = array('id' => 'add', 'label' => 'Add the ticked lines', 'style' => 'approve',
            'help' => 'Puts the ticked lines on ' . $name . '\'s page under "What people who were there remember", each with the reader\'s words and who they are, '
                . 'and ticks the record\'s boxes they show; the unticked lines are left off. Undo on the Added tab.');
        $actions[] = array('id' => 'leave', 'label' => 'Leave all off', 'style' => 'reject', 'help' => 'Nothing on the site changes; the card moves to Left off.');
    }
    if ($c['lead']) {
        $actions[] = array('id' => 'lead_done', 'label' => 'Followed up', 'style' => 'neutral', 'help' => 'Takes the lead off the Leads tab. Nothing on the site changes.');
    }
    if (!$c['added']) {
        $actions[] = array('id' => 'read', 'label' => $row['read_status'] === '' ? 'Read now' : 'Read again', 'style' => 'neutral',
            'help' => 'Reads the answer with the AI now; lines waiting from an earlier reading are replaced. Nothing on the site changes.');
    }
    if (kop_ra_last_batch($id) || !empty($j['lead_done'])) {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => 'Puts back the card\'s latest step: lines added or left off wait again (and come off the page), or a lead followed up opens again.');
    }

    $view_labels = array('waiting' => 'To review', 'added' => 'Added', 'left' => 'Left off', 'nothing' => 'Nothing to add');
    $item = array(
        'key'          => 'a' . (int) $id,
        'title'        => $name . ': ' . ($rules ? 'rules they remember' : 'a note on "' . mb_substr($ctx['ask'], 0, 80) . '"'),
        'subtitle'     => implode(' · ', array_filter(array(
            kop_ra_who((string) $row['how'], (string) ($j['role'] ?? '')),
            count($checklist) ? count($checklist) . ' line' . (count($checklist) === 1 ? '' : 's') . ' to review' : '',
            $c['added'] ? $c['added'] . ' on the page' : '',
            $c['lead'] ? 'a lead' : '',
        ))),
        'url'          => kop_q_url($row['qkey'], array('kind' => 'facility', 'id' => $ctx['fid'])),
        'text'         => $text,
        'details'      => $details,
        'links'        => array(),
        'fields'       => array(),
        'facility'     => $fac,
        'status'       => $c['view'],
        'status_label' => $view_labels[$c['view']],
        'created'      => (string) $row['created_at'],
        'actions'      => $actions,
    );
    if ($checklist) {
        $item['checklist'] = $checklist;
        $item['checklist_noun'] = 'lines';
    }
    return $item;
}

function kop_rinbox_ra_act($key, $action, array $params) {
    $id = (int) substr((string) $key, 1);
    $by = kop_rinbox_reviewer();
    switch ($action) {
        case 'add':
            return array('message' => kop_ra_apply($id, (array) ($params['picked'] ?? array()), $by));
        case 'leave':
            return array('message' => kop_ra_leave($id, $by));
        case 'lead_done':
            return array('message' => kop_ra_lead_done($id, $by));
        case 'read':
            $r = kop_ra_reread($id);
            if ($r['error'] !== '') throw new RuntimeException('The reading failed: ' . $r['error']);
            return array('message' => $r['lines'] ? 'Read: ' . $r['lines'] . ' line' . ($r['lines'] === 1 ? '' : 's') . ' to review.' : 'Read: nothing in it to add to the page.');
        case 'undo':
            return array('message' => kop_ra_undo($id, $by));
    }
    throw new RuntimeException('Unknown action.');
}
