<?php
/**
 * Review inbox source: Record cleanup (inc/record-cleanup.php). One card per facility record holding copies
 * that approvals and merges left: one person under two spellings, roles carrying their source, a name listed
 * twice or as the record's own, one event told twice. Each change is a line to tick; "Tidy the ticked" writes
 * them, "Leave as it is" keeps the record and files the lines away. Undo on the Tidied tab puts the whole
 * record back while nobody has changed it since.
 *
 * Keys: the facility id.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('record-cleanup', function () {
    if (!function_exists('kop_rc_cards')) return null;
    return array(
        'label'       => 'Record cleanup',
        'group'       => 'Suggestions to check',
        'help'        => 'Copies that earlier approvals and merges left on facility records: one person listed under two spellings, roles that carry their source and repeat titles, a name listed twice or as the record\'s own name, one event told by two sources. Each change is a line; tick the ones to make and press "Tidy the ticked". Undo on the Tidied tab puts the record back as it was.',
        'views'       => array('waiting' => 'To tidy', 'tidied' => 'Tidied', 'left' => 'Left as it is'),
        'count'       => function () {
            return count(kop_rc_cards());
        },
        'view_counts' => function () {
            return array('waiting' => count(kop_rc_cards()), 'tidied' => count(kop_rinbox_rc_tidied()), 'left' => count(kop_rc_left()));
        },
        'list'        => 'kop_rinbox_rc_list',
        'get'         => function ($key) {
            return kop_rinbox_rc_item((int) $key);
        },
        'act'         => 'kop_rinbox_rc_act',
    );
});

/** Records tidied here whose last tidy can still be undone: [fid => log entry]. */
function kop_rinbox_rc_tidied() {
    $out = array();
    foreach (kop_rc_log() as $e) {
        $fid = (int) $e['fid'];
        if (isset($out[$fid]) || !kop_rc_undo_stack($fid)) continue;
        $out[$fid] = $e;
    }
    return $out;
}

function kop_rinbox_rc_list(array $q) {
    if ($q['view'] === 'tidied') {
        $fids = array_keys(kop_rinbox_rc_tidied());
    } elseif ($q['view'] === 'left') {
        $fids = array_map('intval', array_keys(kop_rc_left()));
    } else {
        $cards = kop_rc_cards();
        // The records with the most to tidy first.
        uasort($cards, function ($a, $b) { return count($b['steps']) <=> count($a['steps']) ?: strcasecmp($a['name'], $b['name']); });
        $fids = array_keys($cards);
    }
    if ($q['search'] !== '') {
        $fids = array_values(array_filter($fids, function ($fid) use ($q) {
            $f = kop_rinbox_facility((int) $fid);
            return $f && mb_stripos($f['name'], $q['search']) !== false;
        }));
    }
    $items = array();
    foreach (array_slice($fids, (int) $q['offset'], (int) $q['limit']) as $fid) {
        $item = kop_rinbox_rc_item((int) $fid);
        if ($item) $items[] = $item;
    }
    return array('items' => $items, 'total' => count($fids));
}

function kop_rinbox_rc_item($fid) {
    $fid = (int) $fid;
    $fac = kop_rinbox_facility($fid);
    $name = $fac ? $fac['name'] : 'facility #' . $fid;
    $cards = kop_rc_cards();
    $card = $cards[$fid] ?? null;
    $tidied = kop_rinbox_rc_tidied()[$fid] ?? null;
    $left = kop_rc_left()[$fid] ?? null;
    if (!$card && !$tidied && !$left) return null;
    $kind_words = array('staff' => 'Same person', 'roles' => 'Roles', 'name' => 'Names', 'incident' => 'Same event');

    $view = $card ? 'waiting' : ($tidied ? 'tidied' : 'left');
    $item = array(
        'key'          => (string) $fid,
        'title'        => $name,
        'subtitle'     => '',
        'text'         => '',
        'details'      => array(),
        'links'        => $fac && $fac['url'] !== '' ? array(array('label' => 'The facility page', 'url' => $fac['url'])) : array(),
        'fields'       => array(),
        'status'       => $view,
        'status_label' => array('waiting' => 'To tidy', 'tidied' => 'Tidied', 'left' => 'Left as it is')[$view],
        'created'      => '',
        'facility'     => $fac,
        'actions'      => array(),
    );
    if ($card) {
        $counts = array();
        foreach ($card['steps'] as $s) $counts[$kind_words[$s['kind']] ?? $s['kind']] = ($counts[$kind_words[$s['kind']] ?? $s['kind']] ?? 0) + 1;
        $item['subtitle'] = implode(' · ', array_map(function ($k, $n) { return $k . ': ' . $n; }, array_keys($counts), $counts));
        $item['checklist'] = array_map(function ($s) use ($kind_words) {
            return array('keys' => array($s['key']), 'label' => $s['text'], 'sub' => $kind_words[$s['kind']] ?? $s['kind'], 'checked' => true);
        }, $card['steps']);
        $item['checklist_noun'] = 'changes';
        $item['actions'][] = array('id' => 'tidy', 'label' => 'Tidy the ticked', 'style' => 'approve',
            'help' => 'Makes the ticked changes to ' . $name . ' in one save, live at once; the unticked lines stay on this card. Undo on the Tidied tab puts the record back.');
        $item['actions'][] = array('id' => 'leave', 'label' => 'Leave as it is', 'style' => 'reject',
            'help' => 'The record stays as it is and these lines do not come back.');
    }
    if ($tidied) {
        foreach ((array) $tidied['lines'] as $l) $item['details'][] = array('label' => 'Tidied', 'value' => $l);
        if (!$card) $item['subtitle'] = 'Tidied by ' . $tidied['by'] . ' on ' . substr((string) $tidied['at'], 0, 10);
        $item['actions'][] = array('id' => 'undo', 'label' => 'Undo the tidy', 'style' => 'undo',
            'help' => 'Puts the record back exactly as it was before its last tidy here (refused if it changed since).');
    }
    if ($left && !$card) {
        $item['subtitle'] = count($left) . ' line' . (count($left) === 1 ? '' : 's') . ' left as they are';
        $item['actions'][] = array('id' => 'unleave', 'label' => 'Back to the queue', 'style' => 'undo',
            'help' => 'The lines left off wait for review again.');
    }
    return $item;
}

function kop_rinbox_rc_act($key, $action, array $params) {
    $fid = (int) $key;
    switch ($action) {
        case 'tidy':
            return array('message' => kop_rc_apply($fid, (array) ($params['picked'] ?? array()), kop_rinbox_reviewer()));
        case 'leave':
            return array('message' => kop_rc_leave($fid, array()));
        case 'undo':
            return array('message' => kop_rc_undo($fid, kop_rinbox_reviewer()));
        case 'unleave':
            return array('message' => kop_rc_unleave($fid));
    }
    throw new RuntimeException('Unknown action.');
}
