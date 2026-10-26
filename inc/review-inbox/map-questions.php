<?php
/**
 * Review inbox source: the network map build's judgement calls and six
 * curated aliases (KOP Tools > Map Questions, inc/network-questions.php).
 * One action per answer; the decision is stored through
 * kop_network_questions_decide(), so the screen and the inbox agree. A build
 * answer reaches the map at the next hourly rebuild; an alias answer changes
 * the record at once. Undo on the Answered tab.
 *
 * Keys: the question id (section and names, stable across board exports).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('map-questions', function () {
    if (!function_exists('kop_network_questions_decide')) return null;
    $sections = kop_network_questions_sections();
    $views = array();
    foreach ($sections as $key => $s) $views[$key] = $s[0];
    $views['answered'] = 'Answered';
    return array(
        'label'    => 'Map questions',
        'group'    => 'Suggestions to check',
        'help'     => 'Decisions the network map build cannot make on its own (what a name is, who bought whom, which record, which of two closed names came first) and six curated aliases that may be another era of their record. Press the answer; a build answer is on the map after the next hourly rebuild, an alias answer changes the record at once. Undo on the Answered tab.',
        'views'    => $views,
        'tool_url' => admin_url('admin.php?page=kop-network-questions'),
        'count'    => function () {
            $n = 0;
            foreach (kop_network_questions_open() as $q) $n++;
            return $n;
        },
        'view_counts' => 'kop_rinbox_mq_view_counts',
        'tools'    => array_values(array_filter(array(
            function_exists('kop_rinbox_map_rebuild_tool') ? kop_rinbox_map_rebuild_tool('Build answers reach the map at the next rebuild; press this to start one now.') : null,
        ))),
        'tool'     => function ($id, array $params) {
            if ($id === 'map_rebuild') return kop_rinbox_map_rebuild_run();
            throw new RuntimeException('Unknown tool.');
        },
        'list'     => 'kop_rinbox_mq_list',
        'get'      => function ($key) {
            foreach (kop_network_questions_rows() as $r) {
                if ($r['id'] === (string) $key) return kop_rinbox_mq_item($r);
            }
            return null;
        },
        'act'      => 'kop_rinbox_mq_act',
    );
});

function kop_rinbox_mq_view(array $r) {
    return $r['answer'] !== '' ? 'answered' : $r['section'];
}

function kop_rinbox_mq_view_counts() {
    $counts = array();
    foreach (array_keys(kop_network_questions_sections()) as $s) $counts[$s] = 0;
    $counts['answered'] = 0;
    foreach (kop_network_questions_rows() as $r) {
        $v = kop_rinbox_mq_view($r);
        if (isset($counts[$v])) $counts[$v]++;
    }
    return $counts;
}

function kop_rinbox_mq_list(array $q) {
    $rows = array();
    foreach (kop_network_questions_rows() as $r) {
        if (kop_rinbox_mq_view($r) !== $q['view']) continue;
        if ($q['search'] !== '') {
            $hay = mb_strtolower($r['name'] . ' ' . $r['question'] . ' ' . implode(' ', $r['evidence']));
            if (mb_strpos($hay, mb_strtolower($q['search'])) === false) continue;
        }
        $rows[] = $r;
    }
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit']) as $r) $items[] = kop_rinbox_mq_item($r);
    return array('items' => $items, 'total' => count($rows));
}

function kop_rinbox_mq_item(array $r) {
    $sections = kop_network_questions_sections();
    $view = kop_rinbox_mq_view($r);
    $details = array();
    foreach ($r['evidence'] as $i => $e) $details[] = array('label' => $i === 0 ? 'Evidence' : '', 'value' => $e);
    if ($r['answer'] !== '') $details[] = array('label' => 'Answered', 'value' => $r['label'] . ($r['by'] !== '' ? ' (' . $r['by'] . ')' : ''));
    $links = array();
    foreach ($r['links'] as $l) $links[] = array('label' => $l['label'], 'url' => $l['url']);
    if ($view === 'answered') {
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => $r['section'] === 'alias' ? 'Puts the record back as it was; the question waits again.'
                : 'Takes the answer back; the build guesses again at the next rebuild and the question returns.'));
    } else {
        $actions = array();
        foreach ($r['options'] as $i => $o) {
            $actions[] = array('id' => 'answer:' . $o['value'], 'label' => $o['label'], 'style' => $i === 0 ? 'approve' : 'neutral',
                'help' => $r['section'] === 'alias' ? ($o['value'] === 'past-name' ? 'Adds the name to the record\'s past names.' : 'Takes the name off the record\'s match aliases; it waits for a record of its own.')
                    : 'The map draws it this way from the next hourly rebuild.');
        }
        $actions[] = array('id' => 'answer:skip', 'label' => $r['section'] === 'alias' ? 'Not sure, leave it' : 'Leave it as the build guessed', 'style' => 'neutral',
            'help' => 'Nothing changes; the question leaves the list.');
    }
    return array(
        'key'          => $r['id'],
        'title'        => $r['question'],
        'subtitle'     => ($sections[$r['section']][0] ?? $r['section']) . ($r['name'] !== '' ? ' · ' . $r['name'] : ''),
        'text'         => $sections[$r['section']][1] ?? '',
        'details'      => $details,
        'status'       => $view,
        'status_label' => $view === 'answered' ? 'Answered: ' . $r['label'] : 'To answer',
        'created'      => $r['at'],
        'links'        => $links,
        'actions'      => $actions,
    );
}

function kop_rinbox_mq_act($key, $action, array $params) {
    if ($action === 'undo') $answer = 'undo';
    elseif (strpos($action, 'answer:') === 0) $answer = substr($action, 7);
    else throw new RuntimeException('Unknown action.');
    $out = kop_network_questions_decide(array(array('id' => (string) $key, 'answer' => $answer)), kop_rinbox_reviewer());
    $r = $out[(string) $key] ?? null;
    if (!$r) throw new RuntimeException('Not saved.');
    if (!empty($r['error'])) throw new RuntimeException($r['error']);
    if ($answer === 'undo') return array('message' => 'Undone. The question waits again.');
    return array('message' => 'Saved: ' . $r['label'] . '.');
}
