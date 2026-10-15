<?php
/**
 * Review inbox source: on-air statements and facts from the industry's own
 * podcasts (inc/podcast-statements.php). One card per item: the words as the
 * transcript has them, a link to the audio at that second, who the reader
 * thinks said it and why, the categories and stance. "Approve and link" opens
 * the words to correct, the person (a {prefix}kop_people id, suggested from
 * the speaker's name; a new name gets a new id) and the programs and companies
 * it is about (suggested by exact name); Reject drops it. Undo on the Approved
 * and Rejected tabs. Approved items show only to administrators.
 *
 * Keys: the row id.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('podcasts', function () {
    if (!function_exists('kop_pod_ready')) return null;
    return array(
        'label'       => 'Podcast statements',
        'group'       => 'Suggestions to check',
        'help'        => 'What industry professionals say on their own podcasts, read from machine transcripts: statements about restraint, transport, isolation, cutting kids off from parents and the rest, and facts about programs and people. Listen at the given second before approving: the transcript mishears names and does not say who is talking. Approve and link ties the item to a person and to the programs and companies it is about; it shows only to administrators, on the person\'s People screen and on those pages.',
        'views'       => array('waiting' => 'To review', 'approved' => 'Approved', 'rejected' => 'Rejected'),
        'count'       => function () {
            return kop_rinbox_pod_view_counts(array())['waiting'];
        },
        'view_counts' => 'kop_rinbox_pod_view_counts',
        'list'        => 'kop_rinbox_pod_list',
        'get'         => function ($key) {
            $r = kop_pod_row((int) $key);
            return $r ? kop_rinbox_pod_item($r) : null;
        },
        'act'         => 'kop_rinbox_pod_act',
        'lookup'      => 'kop_rinbox_pod_lookup',
        'filters'     => array(
            array('name' => 'kind', 'label' => 'Kind', 'options' => kop_pod_kinds()),
            array('name' => 'severity', 'label' => 'Severity', 'options' => array('serious' => 'Serious', 'notable' => 'Notable')),
            array('name' => 'stance', 'label' => 'Stance', 'options' => array('endorses' => 'Endorses', 'describes' => 'Describes',
                'admits' => 'Admits', 'downplays' => 'Downplays', 'dismisses' => 'Dismisses')),
        ),
    );
});

function kop_rinbox_pod_status($view) {
    return array('waiting' => 'pending', 'approved' => 'approved', 'rejected' => 'rejected')[$view] ?? 'pending';
}

/** WHERE for a page of the queue (search over the words, speaker and episode; kind and severity filters). */
function kop_rinbox_pod_where(array $q, $status) {
    global $wpdb;
    $sql = array($wpdb->prepare('status = %s', $status));
    $f = (array) ($q['filters'] ?? array());
    if (!empty($f['kind'])) $sql[] = $wpdb->prepare('kind = %s', (string) $f['kind']);
    foreach (array('severity', 'stance') as $k) {
        if (!empty($f[$k])) $sql[] = $wpdb->prepare('data LIKE %s', '%"' . $k . '":"' . $wpdb->esc_like((string) $f[$k]) . '"%');
    }
    $s = trim((string) ($q['search'] ?? ''));
    if ($s !== '') {
        $like = '%' . $wpdb->esc_like($s) . '%';
        $sql[] = $wpdb->prepare('(quote LIKE %s OR speaker LIKE %s OR data LIKE %s)', $like, $like, $like);
    }
    return implode(' AND ', $sql);
}

function kop_rinbox_pod_view_counts(array $q) {
    $out = array('waiting' => 0, 'approved' => 0, 'rejected' => 0);
    if (!kop_pod_ready()) return $out;
    kop_pod_import();
    global $wpdb;
    foreach ($out as $view => $_) {
        $out[$view] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_pod_table() . ' WHERE ' . kop_rinbox_pod_where($q, kop_rinbox_pod_status($view)));
    }
    return $out;
}

function kop_rinbox_pod_list(array $q) {
    if (!kop_pod_ready()) return array('items' => array(), 'total' => 0);
    kop_pod_import();
    global $wpdb;
    $where = kop_rinbox_pod_where($q, kop_rinbox_pod_status($q['view'] ?? 'waiting'));
    $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . kop_pod_table() . ' WHERE ' . $where);
    // An episode's items together, in the order they were said; decided items newest first.
    $order = ($q['view'] ?? 'waiting') === 'waiting' ? 'show_key, episode, seconds, id' : 'decided_at DESC, id DESC';
    $rows = $wpdb->get_results('SELECT * FROM ' . kop_pod_table() . ' WHERE ' . $where . ' ORDER BY ' . $order
        . ' LIMIT ' . max(1, (int) ($q['limit'] ?? 20)) . ' OFFSET ' . max(0, (int) ($q['offset'] ?? 0)), ARRAY_A);
    $items = array();
    foreach ((array) $rows as $r) $items[] = kop_rinbox_pod_item(kop_pod_hydrate($r));
    return array('items' => $items, 'total' => $total);
}

function kop_rinbox_pod_item(array $r) {
    $d = $r['d'];
    $kinds = kop_pod_kinds();
    $cats = kop_pod_categories();
    $join = function ($v) { return implode(', ', array_filter(array_map('strval', (array) $v))); };
    $time = kop_pod_time($r['seconds']);
    $audio = kop_pod_audio_url($r);

    // Who it is about: the person the reader named, and the records it names (or the reviewer's links once decided).
    $pending = $r['status'] === 'pending';
    $person = $pending ? kop_pod_person_for($r['speaker']) : $r['person_id'];
    $tokens = $pending ? kop_pod_suggest_records($d) : kop_pod_tokens($r['records']);

    if ($r['kind'] === 'statement') {
        $title = ($r['speaker'] !== '' ? $r['speaker'] : 'Speaker unknown') . ': ' . (string) ($d['why'] ?? '');
    } elseif ($r['kind'] === 'person') {
        $title = trim(($d['person'] ?? '') . ', ' . trim(($d['role'] ?? '') . ' ' . ($d['event'] ?? '')) . ' at ' . ($d['program'] ?? ''), ' ,');
    } elseif ($r['kind'] === 'program') {
        $title = trim(($d['program'] ?? '') . ': ' . trim(($d['field'] ?? '') . ' ' . ($d['value'] ?? '')), ' :');
    } else {
        $title = trim(($d['program'] ?? '') . ': ' . ($d['summary'] ?? ''), ' :');
    }

    $details = array();
    $add = function ($label, $value) use (&$details) {
        $value = trim((string) $value);
        if ($value !== '') $details[] = array('label' => $label, 'value' => $value);
    };
    $add('Kind', $kinds[$r['kind']] ?? $r['kind']);
    if ($r['kind'] === 'statement') {
        $add('Who said it', trim($r['speaker'] . (!empty($d['speaker_role']) ? ' (' . $d['speaker_role'] . ')' : '')));
        $add('How sure', trim(($d['attribution'] ?? '') . ': ' . ($d['speaker_basis'] ?? ''), ': '));
        $add('Passing on someone else\'s words', $d['relayed'] ?? '');
        $add('Works or worked at', $join(array_map(function ($a) {
            return is_array($a) ? trim(($a['program'] ?? '') . (!empty($a['role']) ? ' (' . $a['role'] . ')' : '')) : (string) $a;
        }, (array) ($d['affiliation'] ?? array()))));
        $add('Programs named', $join($d['programs'] ?? array()));
        $add('About', $join(array_map(function ($c) use ($cats) { return $cats[$c] ?? $c; }, (array) ($d['category'] ?? array()))));
        $add('Stance', $d['stance'] ?? '');
        $add('Severity', $d['severity'] ?? '');
        $add('Context', $d['context'] ?? '');
    } else {
        foreach (array('person' => 'Person', 'role' => 'Role', 'event' => 'Event', 'from_program' => 'Came from', 'from_role' => 'Role there',
            'program' => 'Program', 'place' => 'Place', 'date' => 'When', 'field' => 'What', 'value' => 'Says', 'summary' => 'Summary', 'note' => 'Note') as $k => $label) {
            $add($label, is_array($d[$k] ?? '') ? $join($d[$k]) : ($d[$k] ?? ''));
        }
    }
    if ($pending) {
        $add('Suggested person', $person ? kop_pod_person_value($r['speaker'], $person) : ($r['speaker'] !== '' ? 'No id yet: approving makes one for "' . $r['speaker'] . '"' : ''));
    } elseif ($r['status'] === 'approved') {
        $add('Linked person', $r['person_id'] ? kop_pod_person_value($r['speaker'], $r['person_id']) : 'none');
        $add('Linked records', $join(array_column(kop_pod_token_chips($tokens), 'name')) ?: 'none');
    }
    if ($r['quote'] !== $r['quote_orig']) $add('Transcript had', $r['quote_orig']);

    $links = array();
    if ($audio !== '') $links[] = array('label' => 'Listen at ' . $time, 'url' => $audio);
    if (!empty($d['episode_link'])) $links[] = array('label' => 'Episode page', 'url' => (string) $d['episode_link']);
    if ($r['person_id'] && function_exists('kop_people_admin_url')) $links[] = array('label' => 'Person screen', 'url' => kop_people_admin_url(array('person' => $r['person_id'])));

    $actions = array();
    if ($pending) {
        $actions[] = array('id' => 'approve', 'label' => 'Approve and link', 'style' => 'approve', 'ask' => true, 'submit' => 'Approve',
            'help' => 'Opens the words, the person and the programs and companies to check, then approves the item with those links. It shows only to administrators: on the person\'s People screen and in an "On air" section of each linked page. Undo on the Approved tab.',
            'params' => array(
                array('name' => 'quote', 'label' => 'Words (fix what the transcript misheard)', 'type' => 'textarea', 'value' => $r['quote']),
                array('name' => 'person', 'label' => 'Who said it (pick a person, or type a full name for a new id; empty: no one)', 'type' => 'text',
                    'lookup' => 'person', 'placeholder' => 'Name or #id', 'value' => $person ? kop_pod_person_value($r['speaker'], $person) : $r['speaker'], 'optional' => true),
                array('name' => 'records', 'label' => 'Programs and companies it is about', 'type' => 'facilities', 'kinds' => array('facility', 'company'),
                    'value' => kop_pod_token_chips($tokens), 'optional' => true),
            ));
        $actions[] = array('id' => 'reject', 'label' => 'Reject', 'style' => 'reject',
            'help' => 'Leaves it out: not worth keeping, misheard past fixing, or not an industry professional. Nothing on the site changes.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => 'Puts the item back to waiting with its words and links as they were; a person id made by approving it goes again if nothing else names them.');
    }

    $labels = array('pending' => 'To review', 'approved' => 'Approved', 'rejected' => 'Rejected');
    return array(
        'key'          => (string) $r['id'],
        'title'        => mb_strimwidth($title, 0, 200, '...'),
        'subtitle'     => trim((string) ($d['episode_title'] ?? $r['episode']) . (!empty($d['episode_date']) ? ' (' . $d['episode_date'] . ')' : '') . ', at ' . $time),
        'text'         => $r['quote'],
        'details'      => $details,
        'links'        => $links,
        'fields'       => array(),
        'status'       => $r['status'] === 'pending' ? 'waiting' : $r['status'],
        'status_label' => $labels[$r['status']] ?? $r['status'],
        'created'      => (string) $r['created_at'],
        'actions'      => $actions,
        'conflict'     => $r['kind'] === 'statement' && ($d['attribution'] ?? '') === 'unclear' ? 'Who said this is unclear: listen before linking a person.' : '',
    );
}

function kop_rinbox_pod_act($key, $action, array $params) {
    switch ($action) {
        case 'approve':
            return array('message' => kop_pod_approve((int) $key, $params, kop_rinbox_reviewer()));
        case 'reject':
            return array('message' => kop_pod_reject((int) $key, kop_rinbox_reviewer()));
        case 'undo':
            return array('message' => kop_pod_undo((int) $key));
    }
    throw new RuntimeException('Unknown action.');
}

/** People in the industry pool whose name or other names hold $q: "Name #id". */
function kop_rinbox_pod_lookup($name, $q) {
    if ($name !== 'person' || !function_exists('kop_people_table')) return array();
    global $wpdb;
    $q = trim(preg_replace('/\s*#\d+\s*$/', '', (string) $q));
    if (mb_strlen($q) < 2) return array();
    $like = '%' . $wpdb->esc_like($q) . '%';
    $t = kop_people_table('people');
    $pool = kop_people_has_pool() ? " AND pool = 'industry'" : '';
    $rows = $wpdb->get_results($wpdb->prepare("SELECT id, name, aliases FROM {$t} WHERE merged_into IS NULL{$pool} AND (name LIKE %s OR aliases LIKE %s) ORDER BY name LIMIT 20", $like, $like), ARRAY_A);
    $out = array();
    foreach ((array) $rows as $p) {
        $also = function_exists('kop_people_alias_list') ? implode(', ', kop_people_alias_list($p['aliases'])) : '';
        $out[] = array('value' => $p['name'] . ' #' . (int) $p['id'], 'label' => $p['name'] . ' #' . (int) $p['id'] . ($also !== '' ? ' (also ' . $also . ')' : ''));
    }
    return $out;
}
