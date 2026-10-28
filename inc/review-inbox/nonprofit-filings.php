<?php
/**
 * Review inbox source: nonprofit tax filings (inc/nonprofit-filings.php). Each
 * card is one nonprofit organization whose name is one of our records' names
 * (kop-pipeline scripts/nonprofit-990.py): who the IRS has it as, its filings
 * (years, latest revenue) and the records it may be, with how each matched.
 * Link ties it to the facility record picked, "Link to a company" to a
 * company (found, or a new one named in the finder: kop_record_field_param());
 * either page lists its yearly returns at once. Not this program changes
 * nothing. Undo on the Linked tab takes off exactly the links made, and a
 * company made from the card when nothing else uses it.
 *
 * Keys: the organization's EIN. Tool: link every matched organization that
 * has filings.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('nonprofit-filings', function () {
    if (!function_exists('kop_npf_candidates')) return null;
    return array(
        'label'    => 'Nonprofit tax filings',
        'group'    => 'Suggestions to check',
        'help'     => 'Nonprofits whose name is one of our programs\' or companies\' names, with their yearly IRS returns (Form 990: revenue, expenses, officers\' pay, assets). Link puts the returns on the facility or company page at once; Not this program changes nothing. Undo on the Linked tab.',
        'views'    => array('matched' => 'Same name and town', 'check' => 'Check the match', 'linked' => 'Linked', 'rejected' => 'Left off'),
        'count'    => function () {
            $c = kop_rinbox_npf_view_counts();
            return $c['matched'] + $c['check'];
        },
        'view_counts' => 'kop_rinbox_npf_view_counts',
        'tools'    => array(
            array('id' => 'link_matched', 'label' => 'Link every same-name, same-town match with filings', 'style' => 'approve',
                'help' => 'Links each organization on "Same name and town" that has returns on file to the record shown on its card. Each can be undone on the Linked tab.',
                'confirm' => 'Link every same-name, same-town organization with filings to its record? Each can be undone on the Linked tab.'),
        ),
        'tool'     => 'kop_rinbox_npf_tool',
        'list'     => 'kop_rinbox_npf_list',
        'get'      => function ($key) {
            $e = kop_npf_candidates()[(string) $key] ?? null;
            return $e ? kop_rinbox_npf_item((string) $key, $e) : null;
        },
        'act'      => 'kop_rinbox_npf_act',
    );
});

function kop_rinbox_npf_view(array $e, array $d) {
    $decision = (string) ($d['decision'] ?? '');
    if ($decision !== '') return $decision;
    return ($e['match'] ?? '') === 'matched' ? 'matched' : 'check';
}

function kop_rinbox_npf_view_counts() {
    $decisions = kop_npf_decisions();
    $counts = array('matched' => 0, 'check' => 0, 'linked' => 0, 'rejected' => 0);
    foreach (kop_npf_candidates() as $ein => $e) {
        $v = kop_rinbox_npf_view($e, $decisions[$ein] ?? array());
        if (isset($counts[$v])) $counts[$v]++;
    }
    return $counts;
}

/** The best candidate of a kind, or null. */
function kop_rinbox_npf_first(array $e, $kind) {
    foreach ((array) ($e['candidates'] ?? array()) as $c) {
        if (($c['kind'] ?? '') === $kind) return $c;
    }
    return null;
}

function kop_rinbox_npf_tool($id, array $params) {
    if ($id !== 'link_matched') throw new RuntimeException('Unknown tool.');
    $decisions = kop_npf_decisions();
    $done = 0;
    $left = array();
    foreach (kop_npf_candidates() as $ein => $e) {
        if (kop_rinbox_npf_view($e, $decisions[$ein] ?? array()) !== 'matched' || empty($e['filings'])) continue;
        $c = $e['candidates'][0] ?? null;
        try {
            if (!$c) throw new RuntimeException('no record');
            kop_npf_apply($ein, $c['kind'], (int) $c['id'], kop_rinbox_reviewer());
            $done++;
        } catch (RuntimeException $ex) {
            $left[] = (string) $e['name'];
        }
    }
    if (!$done && !$left) return array('message' => 'No same-name, same-town organizations with filings are waiting.');
    return array('message' => 'Linked ' . $done . ' organization' . ($done === 1 ? '' : 's') . '.'
        . ($left ? ' Left for you: ' . implode(', ', array_slice($left, 0, 8)) . (count($left) > 8 ? ' and ' . (count($left) - 8) . ' more' : '') . '.' : ''));
}

function kop_rinbox_npf_list(array $q) {
    $decisions = kop_npf_decisions();
    $rows = array();
    foreach (kop_npf_candidates() as $ein => $e) {
        if (kop_rinbox_npf_view($e, $decisions[$ein] ?? array()) !== $q['view']) continue;
        if ($q['search'] !== '') {
            $hay = mb_strtolower($e['name'] . ' ' . ($e['dba'] ?? '') . ' ' . $ein . ' ' . ($e['city'] ?? '') . ' ' . ($e['state'] ?? '') . ' '
                . implode(' ', array_column((array) $e['candidates'], 'name')));
            if (mb_strpos($hay, mb_strtolower($q['search'])) === false) continue;
        }
        $rows[(string) $ein] = $e;
    }
    // Organizations with returns first, the richest first: they say the most on a page.
    uksort($rows, function ($x, $y) use ($rows) {
        return (empty($rows[$y]['filings']) ? 0 : 1) <=> (empty($rows[$x]['filings']) ? 0 : 1)
            ?: (int) ($rows[$y]['latest_revenue'] ?? 0) <=> (int) ($rows[$x]['latest_revenue'] ?? 0)
            ?: strcasecmp((string) $rows[$x]['name'], (string) $rows[$y]['name']);
    });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $ein => $e) $items[] = kop_rinbox_npf_item((string) $ein, $e);
    return array('items' => $items, 'total' => count($rows));
}

/** A candidate record as one line: name, place, status, and how it matched. */
function kop_rinbox_npf_candidate_line(array $c) {
    $what = ($c['kind'] ?? '') === 'operator' ? 'company' : 'facility';
    $exists = kop_npf_record_exists($c['kind'] ?? 'facility', (int) $c['id']);
    $line = (string) ($c['name'] ?? '') . (!empty($c['place']) ? ' (' . $c['place'] . ')' : '');
    if (!$exists) return $line . ', ' . $what . ' #' . (int) $c['id'] . ' (not on file any more)';
    // The record as it is named now, not as the build's copy of the mirror spelled it.
    $line = kop_npf_record_name($c['kind'] ?? 'facility', (int) $c['id']);
    return $line . (!empty($c['status']) ? ', ' . $c['status'] : '') . ', ' . $what . ' #' . (int) $c['id']
        . ': same name as ' . (string) ($c['how'] ?? 'its name') . (!empty($c['same_town']) ? ', same town' : '');
}

function kop_rinbox_npf_item($ein, array $e) {
    $d = kop_npf_decisions()[$ein] ?? array();
    $view = kop_rinbox_npf_view($e, $d);
    $org = kop_npf_org($ein);
    $open = in_array($view, array('matched', 'check'), true);

    $details = array();
    if (!empty($e['dba'])) $details[] = array('label' => 'Second name on file with the IRS', 'value' => (string) $e['dba']);
    $kind = kop_npf_ntee_label($e['ntee'] ?? '');
    $details[] = array('label' => 'IRS listing', 'value' => trim(implode(', ', array_filter(array(
        'EIN ' . kop_npf_ein_label($ein),
        trim(implode(', ', array_filter(array((string) ($e['address'] ?? ''), (string) ($e['city'] ?? ''), (string) ($e['state'] ?? ''))))),
        $kind, !empty($e['ruling']) ? 'tax-exempt since ' . substr((string) $e['ruling'], 0, 4) : '',
    )))));
    $details[] = array('label' => 'Returns on file', 'value' => !empty($e['filings'])
        ? $e['filings'] . ' (' . $e['years'] . ')' . (isset($e['latest_revenue']) && $e['latest_revenue'] !== null ? '; revenue ' . kop_npf_money($e['latest_revenue']) . ' in ' . $e['latest_year'] : '')
        : 'None on ProPublica: the page would show only its name and EIN.');
    foreach ((array) ($e['flags'] ?? array()) as $f) $details[] = array('label' => 'Check', 'value' => (string) $f);
    if ($view === 'linked') {
        foreach ((array) ($d['links'] ?? array()) as $lid) {
            foreach (kop_npf_links(array('id' => (int) $lid)) as $l) {
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
            $details[] = array('label' => $i === 0 ? 'Best match' : 'Also possible', 'value' => kop_rinbox_npf_candidate_line($c));
        }
    }

    $links = array(array('label' => 'ProPublica Nonprofit Explorer', 'url' => sprintf('https://projects.propublica.org/nonprofits/organizations/%s', $ein)));
    foreach (array_slice((array) ($org['filings'] ?? array()), 0, 1) as $f) {
        if (!empty($f['pdf'])) $links[] = array('label' => 'Latest return (' . $f['year'] . ', Form ' . $f['form'] . ')', 'url' => (string) $f['pdf']);
    }

    $fac = kop_rinbox_npf_first($e, 'facility');
    $op = kop_rinbox_npf_first($e, 'operator');
    $link_help = 'Lists this organization\'s yearly returns on the page of the record picked in "Record", at once. Undo on the Linked tab takes the link off.';
    $actions = array();
    if ($open || $view === 'linked') {
        $actions[] = array('id' => 'link', 'label' => $view === 'linked' ? 'Also link another program' : 'Link to this program',
            'style' => $view === 'matched' && $fac ? 'approve' : 'neutral', 'ask' => $view === 'linked',
            'params' => array(array('name' => 'facility_id', 'label' => 'Record', 'type' => 'facility', 'value' => $view === 'linked' ? '' : ($fac ? (int) $fac['id'] : ''))),
            'help' => $link_help);
        $actions[] = array('id' => 'link_company', 'label' => 'Link to a company…', 'style' => $op && $view !== 'linked' ? 'approve' : 'neutral', 'ask' => true,
            'submit' => 'Link it to the company',
            'params' => array(kop_record_field_param('operator_id', 'Company', array('kinds' => array('company'), 'value' => $op ? kop_record_field_token('company', $op['id']) : ''))),
            'help' => 'Lists this organization\'s yearly returns on the company\'s page, at once. A company not on file yet can be named in the finder: it is made when you click, citing this IRS listing. Undo on the Linked tab takes the link off (and the new company, if nothing else uses it).');
        // An organization that runs only adult programs (an Adult & Teen Challenge center): a record of
        // Christian Rehabs for Adults, never a TTI facility (inc/related-facilities.php).
        if (function_exists('kop_related_kinds')) {
            $actions[] = array('id' => 'link_related', 'label' => 'Link to a Christian rehab for adults…', 'style' => 'neutral', 'ask' => true,
                'submit' => 'Link it to the rehab',
                'params' => array(kop_record_field_param('related_id', 'Christian rehab for adults', array('kinds' => array('cra')))),
                'help' => 'For an organization that runs adult programs only. Lists its yearly returns on the rehab\'s page (listed under Christian Rehabs for Adults, not as a troubled teen program), at once. A rehab not on file yet can be named in the finder: it is made when you click, citing this IRS listing. Undo on the Linked tab takes the link off; a rehab made here stays.');
        }
    }
    if ($open) {
        $actions[] = array('id' => 'reject', 'label' => 'Not this program', 'style' => 'reject',
            'help' => 'Nothing on the site changes; the card moves to Left off.');
    } else {
        $actions[] = array('id' => 'undo', 'label' => $view === 'linked' ? 'Undo' : 'Back to review', 'style' => 'undo',
            'help' => $view === 'linked'
                ? 'Takes off every link made from this card, so the pages stop listing the returns; the card waits for review again.'
                : 'Nothing on the site changes; the card waits for review again.');
    }

    $labels = array('matched' => 'Same name and town', 'check' => 'Check the match', 'linked' => 'Linked', 'rejected' => 'Left off');
    return array(
        'key'          => $ein,
        'title'        => (string) $e['name'],
        'subtitle'     => implode(' · ', array_filter(array(
            trim(implode(', ', array_filter(array((string) ($e['city'] ?? ''), (string) ($e['state'] ?? ''))))),
            'EIN ' . kop_npf_ein_label($ein),
            !empty($e['filings']) ? $e['filings'] . ' returns, ' . $e['years'] : 'no returns on file',
        ))),
        'text'         => '',
        'details'      => $details,
        'links'        => $links,
        'fields'       => array(),
        'status'       => $view,
        'status_label' => $labels[$view] ?? $view,
        'created'      => (string) ($d['at'] ?? ''),
        'facility'     => $fac ? kop_rinbox_facility((int) $fac['id']) : null,
        'selected'     => $view === 'matched' && !empty($e['filings']),
        'actions'      => $actions,
    );
}

function kop_rinbox_npf_act($key, $action, array $params) {
    switch ($action) {
        case 'link':
            return array('message' => kop_npf_apply($key, 'facility', (int) ($params['facility_id'] ?? 0), kop_rinbox_reviewer()));
        case 'link_company':
            $e = kop_npf_candidates()[(string) $key] ?? array();
            return array('message' => kop_record_field_link((string) ($params['operator_id'] ?? ''), array(
                'cite' => 'the IRS listing of "' . ($e['name'] ?? $key) . '" (EIN ' . kop_npf_ein_label($key) . ')',
                'url' => 'https://projects.propublica.org/nonprofits/organizations/' . $key,
                'city' => (string) ($e['city'] ?? ''), 'state' => (string) ($e['state'] ?? ''), 'by' => kop_rinbox_reviewer(),
            ), function ($oid) use ($key) { return kop_npf_apply($key, 'operator', $oid, kop_rinbox_reviewer()); }));
        case 'link_related':
            $e = kop_npf_candidates()[(string) $key] ?? array();
            return array('message' => kop_record_field_link((string) ($params['related_id'] ?? ''), array(
                'kinds' => array('cra'),
                'cite' => 'the IRS listing of "' . ($e['name'] ?? $key) . '" (EIN ' . kop_npf_ein_label($key) . ')',
                'url' => 'https://projects.propublica.org/nonprofits/organizations/' . $key,
                'city' => (string) ($e['city'] ?? ''), 'state' => (string) ($e['state'] ?? ''), 'by' => kop_rinbox_reviewer(),
            ), function ($rid) use ($key) { return kop_npf_apply($key, 'facility', $rid, kop_rinbox_reviewer()); }));
        case 'reject':
            return array('message' => kop_npf_reject($key, kop_rinbox_reviewer()));
        case 'undo':
            $made = kop_rinbox_npf_linked_companies($key);
            $msg = kop_npf_undo($key);
            foreach ($made as $oid) $msg .= kop_record_field_unmake('company', $oid);
            return array('message' => $msg);
    }
    throw new RuntimeException('Unknown action.');
}

/** The companies this card linked (its decision's links), read before Undo takes them off. */
function kop_rinbox_npf_linked_companies($ein) {
    $out = array();
    foreach ((array) (kop_npf_decisions()[(string) $ein]['links'] ?? array()) as $lid) {
        foreach (kop_npf_links(array('id' => (int) $lid)) as $l) if ((int) $l['operator_id']) $out[] = (int) $l['operator_id'];
    }
    return $out;
}

