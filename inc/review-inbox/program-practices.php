<?php
/**
 * Review inbox source: rules and practices read from programs' own papers
 * (inc/program-practices.php). One card per program and topic (Levels and
 * phases, Rules, Punishments, Contact with family, ...), every line ticked and
 * linked to its page of our copy of the handbook, with the quote it rests on.
 * Add puts the ticked lines on the facility page's "Rules and practices"
 * section and ticks the record's boxes they show; the unticked lines are left
 * off. Leave all off changes nothing. Undo on the Added / Left off tabs.
 *
 * Keys: "<facility id>:<topic>".
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('program-practices', function () {
    if (!function_exists('kop_pp_rows')) return null;
    return array(
        'label'       => 'Rules from documents',
        'group'       => 'Suggestions to check',
        'help'        => 'What programs\' own handbooks, manuals, ground rules and enrollment papers in our library say about their levels, rules, punishments, contact with family, restraint and searches, one card per program and topic. Each line links to its page of the document. Add puts the ticked lines on the facility page under "Rules and practices"; the unticked ones are left off. Undo on the Added tab.',
        'views'       => array('waiting' => 'To review', 'added' => 'Added', 'left' => 'Left off'),
        'count'       => function () {
            return kop_rinbox_pp_view_counts()['waiting'];
        },
        'view_counts' => 'kop_rinbox_pp_view_counts',
        'list'        => 'kop_rinbox_pp_list',
        'get'         => function ($key) {
            list($fid, $topic) = array_pad(explode(':', (string) $key, 2), 2, '');
            return kop_rinbox_pp_item((int) $fid, $topic);
        },
        'act'         => 'kop_rinbox_pp_act',
    );
});

/** Cards: "<fid>:<topic>" => {waiting, added, left} line counts. */
function kop_rinbox_pp_cards() {
    if (function_exists('kop_pp_import')) kop_pp_import();
    if (!kop_pp_ready()) return array();
    global $wpdb;
    $rows = $wpdb->get_results('SELECT facility_id, topic, status, COUNT(*) AS n FROM ' . kop_pp_table() . ' GROUP BY facility_id, topic, status', ARRAY_A);
    $cards = array();
    foreach ((array) $rows as $r) {
        $key = (int) $r['facility_id'] . ':' . $r['topic'];
        if (!isset($cards[$key])) $cards[$key] = array('pending' => 0, 'added' => 0, 'left' => 0);
        $cards[$key][$r['status']] = (int) $r['n'];
    }
    return $cards;
}

/** A card waits while any of its lines waits; else Added if any line is on the page, else Left off. */
function kop_rinbox_pp_view(array $c) {
    if ($c['pending'] > 0) return 'waiting';
    return $c['added'] > 0 ? 'added' : 'left';
}

function kop_rinbox_pp_view_counts() {
    $counts = array('waiting' => 0, 'added' => 0, 'left' => 0);
    foreach (kop_rinbox_pp_cards() as $c) $counts[kop_rinbox_pp_view($c)]++;
    return $counts;
}

function kop_rinbox_pp_list(array $q) {
    $topics = kop_pp_topics();
    $order = array_flip(array_keys($topics));
    $keys = array();
    foreach (kop_rinbox_pp_cards() as $key => $c) {
        if (kop_rinbox_pp_view($c) !== $q['view']) continue;
        list($fid, $topic) = explode(':', $key, 2);
        $name = function_exists('kop_rinbox_facility') ? (string) (kop_rinbox_facility((int) $fid)['name'] ?? '') : '';
        if ($q['search'] !== '' && mb_stripos($name . ' ' . ($topics[$topic] ?? $topic), $q['search']) === false) continue;
        $keys[$key] = array($name, $order[$topic] ?? 99);
    }
    // A program's topics together, in the page's order.
    uksort($keys, function ($a, $b) use ($keys) {
        return strcasecmp($keys[$a][0], $keys[$b][0]) ?: $keys[$a][1] <=> $keys[$b][1];
    });
    $items = array();
    foreach (array_slice(array_keys($keys), (int) $q['offset'], (int) $q['limit']) as $key) {
        list($fid, $topic) = explode(':', $key, 2);
        $item = kop_rinbox_pp_item((int) $fid, $topic);
        if ($item) $items[] = $item;
    }
    return array('items' => $items, 'total' => count($keys));
}

function kop_rinbox_pp_item($fid, $topic) {
    $topics = kop_pp_topics();
    if (!isset($topics[$topic])) return null;
    $all = kop_pp_rows(array('facility_id' => (int) $fid, 'topic' => $topic));
    if (!$all) return null;
    $c = array('pending' => 0, 'added' => 0, 'left' => 0);
    foreach ($all as $r) $c[$r['status']] = ($c[$r['status']] ?? 0) + 1;
    $view = kop_rinbox_pp_view($c);
    $labels = function_exists('kop_facility_checklist_labels') ? kop_facility_checklist_labels() : array();
    $fac = function_exists('kop_rinbox_facility') ? kop_rinbox_facility((int) $fid) : null;
    $name = $fac ? (string) $fac['name'] : 'facility #' . (int) $fid;

    // Waiting: every waiting line to tick. Decided: the lines of each state, read only.
    $checklist = array();
    $details = array();
    $docs = array();
    foreach ($all as $r) {
        $docs[(int) $r['doc_id']] = true;
        $checks = array_filter(explode(',', (string) $r['checklist']));
        $sub = trim($r['doc_title'] . ((int) $r['page'] > 0 ? ', page ' . (int) $r['page'] : '') . ((int) $r['year'] > 0 ? ' (' . (int) $r['year'] . ')' : ''));
        if ($checks) $sub .= '. Ticks: ' . implode(', ', array_map(function ($k) use ($labels) { return $labels[$k] ?? $k; }, $checks));
        if ($view === 'waiting' && $r['status'] === 'pending') {
            $checklist[] = array('keys' => array((string) $r['id']), 'label' => (string) $r['value'], 'url' => kop_pp_page_url($r),
                'why' => (string) ($r['why'] ?? ''), 'sub' => $sub, 'note' => '"' . $r['quote'] . '"', 'checked' => true);
        } elseif ($r['status'] !== 'pending') {
            $details[] = array('label' => $r['status'] === 'added' ? 'On the page' : 'Left off',
                'value' => $r['value'] . ' (' . $sub . ')', 'url' => '');
        }
    }

    $actions = array();
    if ($view === 'waiting') {
        $actions[] = array('id' => 'add', 'label' => 'Add the ticked lines', 'style' => 'approve',
            'help' => 'Puts the ticked lines on ' . $name . '\'s facility page under "Rules and practices", at once, and ticks the record\'s boxes they show; the unticked lines are left off. Undo on the Added tab.');
        $actions[] = array('id' => 'leave', 'label' => 'Leave all off', 'style' => 'reject',
            'help' => 'Nothing on the site changes; the card moves to Left off.');
    }
    if (kop_pp_last_batch($fid, $topic)) {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => 'Puts back the last decision on this card: its lines wait for review again, come off the page, and the record\'s boxes it ticked are unticked.');
    }

    $view_labels = array('waiting' => 'To review', 'added' => 'Added', 'left' => 'Left off');
    $item = array(
        'key'          => (int) $fid . ':' . $topic,
        'title'        => $name . ': ' . $topics[$topic],
        'subtitle'     => implode(' · ', array_filter(array(
            $c['pending'] ? $c['pending'] . ' line' . ($c['pending'] === 1 ? '' : 's') . ' to review' : '',
            $c['added'] ? $c['added'] . ' on the page' : '',
            $c['left'] ? $c['left'] . ' left off' : '',
            count($docs) . ' document' . (count($docs) === 1 ? '' : 's'),
        ))),
        'text'         => '',
        'details'      => $details,
        'links'        => array(),
        'fields'       => array(),
        'status'       => $view,
        'status_label' => $view_labels[$view],
        'created'      => '',
        'facility'     => $fac,
        'actions'      => $actions,
    );
    if ($checklist) {
        $item['checklist'] = $checklist;
        $item['checklist_noun'] = 'lines';
    }
    return $item;
}

function kop_rinbox_pp_act($key, $action, array $params) {
    list($fid, $topic) = array_pad(explode(':', (string) $key, 2), 2, '');
    switch ($action) {
        case 'add':
            return array('message' => kop_pp_apply((int) $fid, $topic, (array) ($params['picked'] ?? array()), kop_rinbox_reviewer()));
        case 'leave':
            return array('message' => kop_pp_leave((int) $fid, $topic, kop_rinbox_reviewer()));
        case 'undo':
            return array('message' => kop_pp_undo((int) $fid, $topic));
    }
    throw new RuntimeException('Unknown action.');
}
