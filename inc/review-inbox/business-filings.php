<?php
/**
 * Review inbox source: state business filings (inc/business-filings.php).
 * Each card is one registered company (Florida first) that may be one of our
 * programs or companies: its own name is a record's, it registered a record's
 * name as a trade name, its federal employer number is the EIN of the
 * program's nonprofit, or it sits at the program's street address. The card
 * shows its type, status, officers, registered agent and how each record
 * matched. Link ties it to the facility record picked, "Link to a company" to
 * a company; either page shows the filing at once. Not this program changes
 * nothing. Undo on the Linked tab takes off exactly the links made.
 *
 * Keys: "<ST>:<document number>". Tool: link every same-name, same-town match.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('business-filings', function () {
    if (!function_exists('kop_bizf_candidates')) return null;
    return array(
        'label'    => 'Business filings',
        'group'    => 'Suggestions to check',
        'help'     => 'Companies in a state\'s business register (Florida and Colorado so far) that may be one of our programs or companies: same name, a name they had before, a trade name they registered, the program\'s nonprofit EIN or the program\'s address. Link shows the filing (officers, registered agent, name changes, dissolution, trade names) on the facility or company page at once; Not this program changes nothing. Undo on the Linked tab.',
        'views'    => array('matched' => 'Same name and town', 'check' => 'Check the match', 'linked' => 'Linked', 'rejected' => 'Left off'),
        'count'    => function () {
            $c = kop_rinbox_bizf_view_counts();
            return $c['matched'] + $c['check'];
        },
        'view_counts' => 'kop_rinbox_bizf_view_counts',
        'tools'    => array(
            array('id' => 'link_matched', 'label' => 'Link every same-name, same-town match', 'style' => 'approve',
                'help' => 'Links each company on "Same name and town" to the record shown on its card. Each can be undone on the Linked tab.',
                'confirm' => 'Link every same-name, same-town company to its record? Each can be undone on the Linked tab.'),
        ),
        'tool'     => 'kop_rinbox_bizf_tool',
        'list'     => 'kop_rinbox_bizf_list',
        'get'      => function ($key) {
            $e = kop_bizf_candidates()[(string) $key] ?? null;
            return $e ? kop_rinbox_bizf_item((string) $key, $e) : null;
        },
        'act'      => 'kop_rinbox_bizf_act',
    );
});

function kop_rinbox_bizf_view(array $e, array $d) {
    $decision = (string) ($d['decision'] ?? '');
    if ($decision !== '') return $decision;
    return ($e['match'] ?? '') === 'matched' ? 'matched' : 'check';
}

function kop_rinbox_bizf_view_counts() {
    $decisions = kop_bizf_decisions();
    $counts = array('matched' => 0, 'check' => 0, 'linked' => 0, 'rejected' => 0);
    foreach (kop_bizf_candidates() as $key => $e) {
        $v = kop_rinbox_bizf_view($e, $decisions[$key] ?? array());
        if (isset($counts[$v])) $counts[$v]++;
    }
    return $counts;
}

function kop_rinbox_bizf_tool($id, array $params) {
    if ($id !== 'link_matched') throw new RuntimeException('Unknown tool.');
    $decisions = kop_bizf_decisions();
    $done = 0;
    $left = array();
    foreach (kop_bizf_candidates() as $key => $e) {
        if (kop_rinbox_bizf_view($e, $decisions[$key] ?? array()) !== 'matched') continue;
        $c = $e['candidates'][0] ?? null;
        try {
            if (!$c) throw new RuntimeException('no record');
            kop_bizf_apply($key, $c['kind'], (int) $c['id'], kop_rinbox_reviewer());
            $done++;
        } catch (RuntimeException $ex) {
            $left[] = (string) $e['name'];
        }
    }
    if (!$done && !$left) return array('message' => 'No same-name, same-town companies are waiting.');
    return array('message' => 'Linked ' . $done . ' compan' . ($done === 1 ? 'y' : 'ies') . '.'
        . ($left ? ' Left for you: ' . implode(', ', array_slice($left, 0, 8)) . (count($left) > 8 ? ' and ' . (count($left) - 8) . ' more' : '') . '.' : ''));
}

function kop_rinbox_bizf_list(array $q) {
    $decisions = kop_bizf_decisions();
    static $rank = array('its name' => 0, 'the EIN of the nonprofit found for the program' => 1, 'a name it had before' => 2, 'a trade name it registered' => 3, "the program's street address" => 4);
    $rows = array();
    foreach (kop_bizf_candidates() as $key => $e) {
        if (kop_rinbox_bizf_view($e, $decisions[$key] ?? array()) !== $q['view']) continue;
        if ($q['search'] !== '') {
            $hay = mb_strtolower($e['name'] . ' ' . $e['doc'] . ' ' . ($e['city'] ?? '') . ' ' . implode(' ', (array) ($e['officers'] ?? array())) . ' '
                . implode(' ', array_column((array) $e['candidates'], 'name')) . ' ' . implode(' ', array_column((array) $e['candidates'], 'trade_name')));
            if (mb_strpos($hay, mb_strtolower($q['search'])) === false) continue;
        }
        $rows[(string) $key] = $e;
    }
    // Strongest reason first, active companies before inactive ones, then by name.
    uksort($rows, function ($x, $y) use ($rows, $rank) {
        $a = $rows[$x];
        $b = $rows[$y];
        return ($rank[$a['candidates'][0]['how'] ?? ''] ?? 9) <=> ($rank[$b['candidates'][0]['how'] ?? ''] ?? 9)
            ?: (($a['status'] ?? '') === 'active' ? 0 : 1) <=> (($b['status'] ?? '') === 'active' ? 0 : 1)
            ?: strcasecmp((string) $a['name'], (string) $b['name']);
    });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $key => $e) $items[] = kop_rinbox_bizf_item((string) $key, $e);
    return array('items' => $items, 'total' => count($rows));
}

/** A candidate record as one line: name, place, status, and how it matched. */
function kop_rinbox_bizf_candidate_line(array $c) {
    $what = ($c['kind'] ?? '') === 'operator' ? 'company' : 'facility';
    $line = (string) ($c['name'] ?? '') . (!empty($c['place']) ? ' (' . $c['place'] . ')' : '');
    if (!kop_npf_record_exists($c['kind'] ?? 'facility', (int) $c['id'])) return $line . ', ' . $what . ' #' . (int) $c['id'] . ' (not on file any more)';
    // The record as it is named now, not as the build's copy of the mirror spelled it.
    $line = kop_npf_record_name($c['kind'] ?? 'facility', (int) $c['id']);
    $how = (string) ($c['how'] ?? '');
    $why = $how === 'a trade name it registered' ? 'it registered the trade name "' . ($c['trade_name'] ?? '') . '"'
        : ($how === 'a name it had before' ? 'it was once called "' . ($c['trade_name'] ?? '') . '"'
        : ($how === 'its name' ? 'same name'
        : ($how === "the program's street address" ? "its principal address is the program's street address"
        : 'its federal employer number is the EIN of the nonprofit found for this program')));
    return $line . (!empty($c['status']) ? ', ' . $c['status'] : '') . ', ' . $what . ' #' . (int) $c['id'] . ': ' . $why . (!empty($c['same_town']) ? ', same town' : '');
}

function kop_rinbox_bizf_item($key, array $e) {
    $d = kop_bizf_decisions()[$key] ?? array();
    $view = kop_rinbox_bizf_view($e, $d);
    $open = in_array($view, array('matched', 'check'), true);
    $states = kop_bizf_states();
    $state_name = $states[$e['state']] ?? $e['state'];

    $details = array();
    $details[] = array('label' => $state_name . ' register', 'value' => implode(', ', array_filter(array(
        (string) ($e['type_label'] ?? ''), 'document ' . $e['doc'], (string) ($e['status_label'] ?? $e['status'] ?? ''),
        !empty($e['filed']) ? 'filed ' . kop_bizf_date($e['filed']) : '',
        !empty($e['last_report']) ? 'last annual report ' . (int) $e['last_report'] : '',
        !empty($e['city']) ? 'in ' . $e['city'] : '',
        !empty($e['fei']) ? 'EIN ' . substr($e['fei'], 0, 2) . '-' . substr($e['fei'], 2) : '',
    ))));
    if (!empty($e['officers'])) $details[] = array('label' => 'Officers', 'value' => implode('; ', (array) $e['officers']));
    if (!empty($e['agent'])) $details[] = array('label' => 'Registered agent', 'value' => (string) $e['agent']);
    if (!empty($e['trade_names'])) $details[] = array('label' => 'Trade names it registered', 'value' => (string) (int) $e['trade_names']);
    foreach ((array) ($e['flags'] ?? array()) as $f) $details[] = array('label' => 'Check', 'value' => (string) $f);
    if ($view === 'linked') {
        foreach ((array) ($d['links'] ?? array()) as $lid) {
            foreach (kop_bizf_links(array('id' => (int) $lid)) as $l) {
                $k = (int) $l['operator_id'] ? 'operator' : 'facility';
                $details[] = array('label' => 'Linked to', 'value' => kop_npf_record_name($k, $k === 'operator' ? $l['operator_id'] : $l['facility_id'])
                    . ', ' . $l['created_at'] . ' by ' . $l['created_by']);
            }
        }
    } elseif ($view === 'rejected') {
        $details[] = array('label' => 'Left off', 'value' => trim(($d['at'] ?? '') . ' by ' . ($d['by'] ?? '')));
    }
    if ($view !== 'linked') {
        foreach ((array) ($e['candidates'] ?? array()) as $i => $c) {
            $details[] = array('label' => $i === 0 ? 'Best match' : 'Also possible', 'value' => kop_rinbox_bizf_candidate_line($c));
        }
    }
    $ent = kop_bizf_entity($e['state'], $e['doc']);
    $links = array();
    if ($ent && !empty($ent['url'])) $links[] = array('label' => $state_name . "'s record of it", 'url' => (string) $ent['url']);

    $fac = $op = null;
    foreach ((array) ($e['candidates'] ?? array()) as $c) {
        if (!$fac && ($c['kind'] ?? '') === 'facility') $fac = $c;
        if (!$op && ($c['kind'] ?? '') === 'operator') $op = $c;
    }
    $actions = array();
    if ($open || $view === 'linked') {
        $actions[] = array('id' => 'link', 'label' => $view === 'linked' ? 'Also link another program' : 'Link to this program',
            'style' => $view === 'matched' && $fac ? 'approve' : 'neutral', 'ask' => $view === 'linked',
            'params' => array(array('name' => 'facility_id', 'label' => 'Record', 'type' => 'facility', 'value' => $view === 'linked' ? '' : ($fac ? (int) $fac['id'] : ''))),
            'help' => 'Shows this company\'s filing (officers, registered agent, name changes, dissolution, trade names) on the page of the record picked in "Record", at once. Undo on the Linked tab takes the link off.');
        $actions[] = array('id' => 'link_company', 'label' => 'Link to a company…', 'style' => $op && $view !== 'linked' ? 'approve' : 'neutral', 'ask' => true,
            'submit' => 'Link it to the company',
            'params' => array(kop_record_field_param('operator_id', 'Company', array('kinds' => array('company'), 'value' => $op ? kop_record_field_token('company', $op['id']) : ''))),
            'help' => 'Shows this company\'s filing on the company page picked, at once. A company not on file yet can be named in the finder: it is made when you click, citing this state filing. Undo on the Linked tab takes the link off (and the new company, if nothing else uses it).');
    }
    if ($open) {
        $actions[] = array('id' => 'reject', 'label' => 'Not this program', 'style' => 'reject',
            'help' => 'Nothing on the site changes; the card moves to Left off.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => $view === 'linked' ? 'Undo' : 'Back to review', 'style' => 'undo',
            'help' => $view === 'linked'
                ? 'Takes off every link made from this card, so the pages stop showing the filing; the card waits for review again.'
                : 'Nothing on the site changes; the card waits for review again.');
    }

    $labels = array('matched' => 'Same name and town', 'check' => 'Check the match', 'linked' => 'Linked', 'rejected' => 'Left off');
    return array(
        'key'          => $key,
        'title'        => (string) $e['name'],
        'subtitle'     => implode(' · ', array_filter(array(trim(implode(', ', array_filter(array((string) ($e['city'] ?? ''), $e['state'])))),
            (string) ($e['type_label'] ?? ''), (string) ($e['status'] ?? ''), 'document ' . $e['doc']))),
        'text'         => '',
        'details'      => $details,
        'links'        => $links,
        'fields'       => array(),
        'status'       => $view,
        'status_label' => $labels[$view] ?? $view,
        'created'      => (string) ($d['at'] ?? ''),
        'facility'     => $fac ? kop_rinbox_facility((int) $fac['id']) : null,
        'selected'     => $view === 'matched',
        'actions'      => $actions,
    );
}

function kop_rinbox_bizf_act($key, $action, array $params) {
    switch ($action) {
        case 'link':
            return array('message' => kop_bizf_apply($key, 'facility', (int) ($params['facility_id'] ?? 0), kop_rinbox_reviewer()));
        case 'link_company':
            $e = kop_bizf_candidates()[(string) $key] ?? array();
            $ent = $e ? kop_bizf_entity($e['state'], $e['doc']) : null;
            return array('message' => kop_record_field_link((string) ($params['operator_id'] ?? ''), array(
                'cite' => 'the ' . ($e['state'] ?? '') . ' business filing of "' . ($e['name'] ?? $key) . '" (document ' . ($e['doc'] ?? '') . ')',
                'url' => (string) ($ent['url'] ?? ''),
                'city' => (string) ($e['city'] ?? ''), 'state' => (string) ($e['state'] ?? ''), 'by' => kop_rinbox_reviewer(),
            ), function ($oid) use ($key) { return kop_bizf_apply($key, 'operator', $oid, kop_rinbox_reviewer()); }));
        case 'reject':
            return array('message' => kop_bizf_reject($key, kop_rinbox_reviewer()));
        case 'undo':
            $made = array();
            foreach ((array) (kop_bizf_decisions()[(string) $key]['links'] ?? array()) as $lid) {
                foreach (kop_bizf_links(array('id' => (int) $lid)) as $l) if ((int) $l['operator_id']) $made[] = (int) $l['operator_id'];
            }
            $msg = kop_bizf_undo($key);
            foreach ($made as $oid) $msg .= kop_record_field_unmake('company', $oid);
            return array('message' => $msg);
    }
    throw new RuntimeException('Unknown action.');
}
