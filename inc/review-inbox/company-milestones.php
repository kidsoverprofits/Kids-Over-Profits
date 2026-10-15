<?php
/**
 * Review inbox source: company milestones (seeds/company-milestones.json, read
 * by inc/profile-timeline.php). Each card is one dated event in a parent
 * company's history: a buyout, a private equity owner, an investigation, a
 * settlement, a mass closure, with the quote each source gives. Approve puts
 * it on the "Year by year" timeline of the company's page (and of the pages
 * of every other company it names, and of the programs it lists); Leave it off
 * changes nothing. The wording can be edited first. Undo on either tab.
 *
 * Decisions: option kop_company_milestones_review {key: {decision, by, at}};
 * the reviewer's wording: option kop_company_milestones_edits {key: {title,
 * sentence, detail, date}}. Keys are the seed's.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('company-milestones', function () {
    if (!function_exists('kop_ptl_ms_all')) return null;
    return array(
        'label'    => 'Company milestones',
        'group'    => 'Suggestions to check',
        'help'     => 'Dated events in parent companies\' histories found by research: buyouts, private equity owners, investigations, settlements and closures, each with the quote its sources give. Approve puts it on the "Year by year" timeline of the company\'s page, and of the programs and other companies it names; Leave it off changes nothing. Undo on either tab.',
        'views'    => kop_rinbox_ms_views(),
        'count'    => function () {
            return kop_rinbox_ms_view_counts()['pending'];
        },
        'view_counts' => 'kop_rinbox_ms_view_counts',
        'list'     => 'kop_rinbox_ms_list',
        'get'      => function ($key) {
            $m = kop_ptl_ms_get((string) $key);
            return $m ? kop_rinbox_ms_item((string) $key, $m) : null;
        },
        'save'     => function ($key, array $fields) {
            return array('message' => kop_rinbox_ms_save((string) $key, $fields));
        },
        'act'      => 'kop_rinbox_ms_act',
    );
});

function kop_rinbox_ms_views() {
    return array('pending' => 'Waiting', 'approved' => 'On the timelines', 'rejected' => 'Left off');
}

function kop_rinbox_ms_view($key) {
    $d = (string) (kop_ptl_ms_decisions()[$key]['decision'] ?? '');
    return in_array($d, array('approved', 'rejected'), true) ? $d : 'pending';
}

function kop_rinbox_ms_view_counts() {
    $counts = array('pending' => 0, 'approved' => 0, 'rejected' => 0);
    foreach (array_keys(kop_ptl_ms_all()) as $key) $counts[kop_rinbox_ms_view((string) $key)]++;
    return $counts;
}

function kop_rinbox_ms_list(array $q) {
    $rows = array();
    foreach (array_keys(kop_ptl_ms_all()) as $key) {
        $key = (string) $key;
        if (kop_rinbox_ms_view($key) !== $q['view']) continue;
        $m = kop_ptl_ms_get($key);
        if (!$m) continue;
        if ($q['search'] !== '') {
            $hay = mb_strtolower(implode(' ', array_merge(array($m['company'], $m['title'], (string) ($m['detail'] ?? ''), (string) $m['year']),
                (array) ($m['also'] ?? array()), (array) ($m['programs'] ?? array()))));
            if (mb_strpos($hay, mb_strtolower($q['search'])) === false) continue;
        }
        $rows[$key] = $m;
    }
    uasort($rows, function ($a, $b) {
        return strcasecmp((string) $a['company'], (string) $b['company']) ?: ((int) $a['year'] <=> (int) $b['year']);
    });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $key => $m) $items[] = kop_rinbox_ms_item((string) $key, $m);
    return array('items' => $items, 'total' => count($rows));
}

/** A company name as a link to its page when it has one. */
function kop_rinbox_ms_company_link($name) {
    $url = function_exists('kop_operator_page_url_for_name') ? (string) kop_operator_page_url_for_name($name) : '';
    return $url !== '' ? array('label' => $name . ' (company page)', 'url' => $url) : null;
}

function kop_rinbox_ms_item($key, array $m) {
    $view = kop_rinbox_ms_view($key);
    $d = kop_ptl_ms_decisions()[$key] ?? array();
    $kinds = kop_ptl_kinds();
    $kind = (string) ($m['kind'] ?? 'deal');
    $kind_label = $kind === 'closure' ? 'Closed' : ($kinds[$kind][2] ?? ucfirst($kind));

    $details = array();
    $details[] = array('label' => 'When', 'value' => trim((string) ($m['date'] ?? '')) !== '' ? (string) $m['date'] : (string) $m['year']);
    if (!empty($m['also'])) $details[] = array('label' => 'Also on the timeline of', 'value' => implode(', ', (array) $m['also']));
    if (!empty($m['programs'])) $details[] = array('label' => 'Programs named', 'value' => implode(', ', (array) $m['programs']));
    foreach ((array) ($m['sources'] ?? array()) as $s) {
        $details[] = array('label' => 'Source: ' . ((string) ($s['label'] ?? '') ?: (string) ($s['url'] ?? '')),
            'value' => trim((string) ($s['quote'] ?? '')) !== '' ? '"' . trim((string) $s['quote']) . '"' : '(no quote)');
    }
    if (trim((string) ($m['note'] ?? '')) !== '') $details[] = array('label' => 'Check', 'value' => (string) $m['note']);
    if ($view !== 'pending') {
        $details[] = array('label' => $view === 'approved' ? 'Approved' : 'Left off', 'value' => trim(($d['at'] ?? '') . ' by ' . ($d['by'] ?? '')));
    }

    $links = array();
    foreach (array_merge(array($m['company']), (array) ($m['also'] ?? array())) as $c) {
        $l = kop_rinbox_ms_company_link((string) $c);
        if ($l) $links[] = $l;
    }
    foreach ((array) ($m['sources'] ?? array()) as $s) {
        if (!empty($s['url'])) $links[] = array('label' => (string) ($s['label'] ?? '') ?: (string) $s['url'], 'url' => (string) $s['url']);
    }

    $fields = array(
        array('name' => 'title', 'label' => 'On ' . $m['company'] . '\'s timeline', 'type' => 'text', 'value' => (string) $m['title']),
        array('name' => 'sentence', 'label' => 'On other pages (a whole sentence; left empty, "' . $m['company'] . ': " and the line above)', 'type' => 'text', 'value' => (string) ($m['sentence'] ?? '')),
        array('name' => 'detail', 'label' => 'More about it (the event\'s card)', 'type' => 'textarea', 'value' => (string) ($m['detail'] ?? '')),
        array('name' => 'date', 'label' => 'Date (2005, 2005-10 or 2005-10-01)', 'type' => 'text', 'value' => trim((string) ($m['date'] ?? '')) !== '' ? (string) $m['date'] : (string) $m['year']),
    );

    $actions = array();
    if ($view === 'pending') {
        $actions[] = array('id' => 'approve', 'label' => 'Approve: put it on the timelines', 'style' => 'approve',
            'help' => 'Shows this event, as worded above, on the "Year by year" timeline of ' . $m['company'] . '\'s page and of the pages it names, at once. Undo takes it off.');
        $actions[] = array('id' => 'reject', 'label' => 'Leave it off', 'style' => 'reject',
            'help' => 'Nothing on the site changes; the card moves to Left off.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => $view === 'approved' ? 'Undo' : 'Back to waiting', 'style' => 'undo',
            'help' => $view === 'approved'
                ? 'Takes the event off every timeline; the card waits for review again with its wording kept.'
                : 'Nothing on the site changes; the card waits for review again.');
    }

    return array(
        'key'          => $key,
        'title'        => (int) $m['year'] . ': ' . $m['title'],
        'subtitle'     => implode(' · ', array_filter(array((string) $m['company'], $kind_label, count((array) ($m['sources'] ?? array())) . ' source' . (count((array) ($m['sources'] ?? array())) === 1 ? '' : 's')))),
        'text'         => (string) ($m['detail'] ?? ''),
        'details'      => $details,
        'links'        => $links,
        'fields'       => $fields,
        'status'       => $view,
        'status_label' => kop_rinbox_ms_views()[$view],
        'created'      => (string) ($d['at'] ?? ''),
        'actions'      => $actions,
    );
}

/** Saves the reviewer's wording; a field set back to the seed's is dropped. */
function kop_rinbox_ms_save($key, array $fields) {
    $seed = kop_ptl_ms_all()[$key] ?? null;
    if (!$seed) throw new RuntimeException('That milestone is not in the file any more.');
    $edits = kop_ptl_ms_edits();
    $mine = (array) ($edits[$key] ?? array());
    foreach (array('title', 'sentence', 'detail', 'date') as $f) {
        if (!array_key_exists($f, $fields)) continue;
        $v = trim(str_replace("\r\n", "\n", (string) $fields[$f]));
        if ($f === 'title' && $v === '') throw new RuntimeException('The line on the timeline cannot be empty.');
        if ($f === 'date' && $v !== '' && !preg_match('/^(1[89]|20)\d\d(-\d\d(-\d\d)?)?$/', $v)) throw new RuntimeException('Write the date as 2005, 2005-10 or 2005-10-01.');
        $was = $f === 'date' ? (trim((string) ($seed['date'] ?? '')) !== '' ? (string) $seed['date'] : (string) $seed['year']) : trim((string) ($seed[$f] ?? ''));
        if ($v === $was) unset($mine[$f]); else $mine[$f] = $v;
    }
    if ($mine) $edits[$key] = $mine; else unset($edits[$key]);
    update_option('kop_company_milestones_edits', $edits, false);
    if (kop_rinbox_ms_view($key) === 'approved') kop_rinbox_ms_purge();
    return 'Saved the wording.';
}

function kop_rinbox_ms_purge() {
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
    if (function_exists('do_action')) do_action('litespeed_purge_all');
}

function kop_rinbox_ms_act($key, $action, array $params) {
    $key = (string) $key;
    $m = kop_ptl_ms_get($key);
    if (!$m) throw new RuntimeException('That milestone is not in the file any more.');
    $decisions = kop_ptl_ms_decisions();
    $view = kop_rinbox_ms_view($key);
    switch ($action) {
        case 'approve':
        case 'reject':
            if ($view !== 'pending') throw new RuntimeException('This card was already decided; reload the list.');
            $decisions[$key] = array('decision' => $action === 'approve' ? 'approved' : 'rejected', 'by' => kop_rinbox_reviewer(), 'at' => current_time('mysql'));
            update_option('kop_company_milestones_review', $decisions, false);
            kop_rinbox_ms_purge();
            return array('message' => $action === 'approve'
                ? 'On the timelines: ' . $m['company'] . ', ' . (int) $m['year'] . '.'
                : 'Left off.');
        case 'undo':
            if ($view === 'pending') return array('message' => 'This card is already waiting.');
            unset($decisions[$key]);
            update_option('kop_company_milestones_review', $decisions, false);
            kop_rinbox_ms_purge();
            return array('message' => $view === 'approved' ? 'Taken off the timelines; waiting again.' : 'Waiting again.');
    }
    throw new RuntimeException('Unknown action.');
}
