<?php
/**
 * Review inbox source: company milestones (seeds/company-milestones.json, read
 * by inc/profile-timeline.php). Each card is one dated event in a parent
 * company's history: a buyout, a private equity owner, an investigation, a
 * settlement, a mass closure, with the quote each source gives. Approve puts
 * it on the "Year by year" timeline of the company's page (and of the pages
 * of every other company it names, and of the programs it lists); Leave it off
 * changes nothing. Everything on the card can be edited first (company,
 * kind, date, wording, the companies and programs it names, its sources).
 * Undo on either tab.
 *
 * "Add a milestone" (a tool over the list, folded) adds one by hand: from a
 * company's written history, or from its /operator/ page, whose admin-only
 * "Add a milestone" link opens this queue with the form open and the company
 * filled in (?type=company-milestones&tool=add&p_company=...). An added
 * milestone goes on the timelines at once, by the admin who added it; Undo
 * takes it off, Delete removes it (Undo of that in Recently done).
 *
 * Decisions: option kop_company_milestones_review {key: {decision, by, at}};
 * the reviewer's changes: option kop_company_milestones_edits {key: {field:
 * value}}; added milestones: option kop_company_milestones_added {key:
 * milestone} (kop_ptl_ms_added()). Keys are the seed's, or "added-...".
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
        'lookup'   => 'kop_rinbox_ms_lookup',
        'tools'    => array(array('id' => 'add', 'label' => 'Add a milestone', 'style' => 'approve', 'fold' => true,
            'help' => 'A dated event in a company\'s history, from its written history, a filing or the news: it goes on the "Year by year" timeline of the company\'s page at once, and of the companies and programs it names. Undo is on its card under On the timelines.',
            'params' => kop_rinbox_ms_params(array()))),
        'tool'     => 'kop_rinbox_ms_tool',
    );
});

/** The kinds a milestone can be: [kind => label]. */
function kop_rinbox_ms_kinds() {
    return array(
        'deal'          => 'Ownership: bought, sold, merged, a new owner',
        'founded'       => 'Founded',
        'rename'        => 'New name',
        'opened'        => 'Opened programs',
        'closure'       => 'Closed programs',
        'investigation' => 'Investigation',
        'lawsuit'       => 'Lawsuit',
        'settlement'    => 'Settlement',
        'scandal'       => 'In the news',
        'death'         => 'Death',
        'incident'      => 'Incident',
    );
}

/** Sources as the box shows them: one per line, "link | label | quote". */
function kop_rinbox_ms_sources_text(array $sources) {
    $lines = array();
    foreach ($sources as $s) {
        $lines[] = rtrim(implode(' | ', array((string) ($s['url'] ?? ''), (string) ($s['label'] ?? ''), (string) ($s['quote'] ?? ''))), ' |');
    }
    return implode("\n", $lines);
}

/** "link | label | quote" lines => [{label, url, quote}]; a line without a link is refused. */
function kop_rinbox_ms_parse_sources($text) {
    $out = array();
    foreach (preg_split('/\R/', (string) $text) as $n => $line) {
        $line = trim($line);
        if ($line === '') continue;
        $parts = array_map('trim', explode('|', $line, 3));
        $url = preg_match('#^https?://\S+$#i', $parts[0]) ? esc_url_raw($parts[0]) : '';
        if ($url === '') throw new RuntimeException('Source line ' . ($n + 1) . ' does not start with a link (https://...). Write each as: link | label | quote.');
        $label = (string) ($parts[1] ?? '');
        if ($label === '') $label = preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
        $out[] = array('label' => $label, 'url' => $url, 'quote' => (string) ($parts[2] ?? ''));
    }
    return $out;
}

/** The form's fields with a milestone's values ($m empty: a new one). The card's editor and the Add tool both use them. */
function kop_rinbox_ms_params(array $m) {
    $kinds = kop_rinbox_ms_kinds();
    $kind = (string) ($m['kind'] ?? 'deal');
    if (!isset($kinds[$kind])) $kinds[$kind] = ucfirst($kind);
    $company = (string) ($m['company'] ?? '');
    return array(
        array('name' => 'company', 'label' => 'Company', 'type' => 'text', 'value' => $company, 'lookup' => 'company', 'placeholder' => 'Its name as on its page'),
        array('name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'value' => $kind, 'options' => $kinds),
        array('name' => 'date', 'label' => 'Date (2005, 2005-10 or 2005-10-01)', 'type' => 'text',
            'value' => !$m ? '' : (trim((string) ($m['date'] ?? '')) !== '' ? (string) $m['date'] : (string) $m['year'])),
        array('name' => 'title', 'label' => $company !== '' ? 'On ' . $company . '\'s timeline' : 'On the company\'s timeline (from its side: "Bought ...")', 'type' => 'text', 'value' => (string) ($m['title'] ?? '')),
        array('name' => 'sentence', 'label' => 'On other pages (a whole sentence; left empty, "' . ($company !== '' ? $company : 'Company') . ': " and the line above)', 'type' => 'text', 'value' => (string) ($m['sentence'] ?? ''), 'optional' => true),
        array('name' => 'detail', 'label' => 'More about it (the event\'s card)', 'type' => 'textarea', 'value' => (string) ($m['detail'] ?? ''), 'optional' => true),
        array('name' => 'also', 'label' => 'Also on the timeline of (other companies, one per line)', 'type' => 'list', 'value' => array_values((array) ($m['also'] ?? array())), 'optional' => true),
        array('name' => 'programs', 'label' => 'Programs it names (one per line)', 'type' => 'list', 'value' => array_values((array) ($m['programs'] ?? array())), 'optional' => true),
        array('name' => 'sources', 'label' => 'Sources, one per line: link | label | quote', 'type' => 'textarea', 'value' => kop_rinbox_ms_sources_text((array) ($m['sources'] ?? array()))),
    );
}

/** The fields sent, checked and tidied. Throws on what cannot be saved. */
function kop_rinbox_ms_clean(array $in) {
    $text = function ($v) { return trim(str_replace("\r\n", "\n", is_array($v) ? implode("\n", $v) : (string) $v)); };
    $out = array();
    foreach (kop_ptl_ms_edit_fields() as $f) {
        if (!array_key_exists($f, $in)) continue;
        $v = $in[$f];
        if ($f === 'also' || $f === 'programs') {
            $v = is_array($v) ? $v : preg_split('/\R/', (string) $v);
            $out[$f] = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $v)), 'strlen')));
            continue;
        }
        $v = $text($v);
        if ($f === 'company' && $v === '') throw new RuntimeException('Name the company.');
        if ($f === 'kind' && !isset(kop_ptl_kinds()[$v === 'closure' ? 'closed' : $v])) throw new RuntimeException('Pick a kind.');
        if ($f === 'date' && !preg_match('/^(1[89]|20)\d\d(-\d\d(-\d\d)?)?$/', $v)) throw new RuntimeException('Write the date as 2005, 2005-10 or 2005-10-01.');
        if ($f === 'title' && $v === '') throw new RuntimeException('The line on the timeline cannot be empty.');
        if ($f === 'sources') {
            $v = kop_rinbox_ms_parse_sources($v);
            if (!$v) throw new RuntimeException('Give at least one source: link | label | quote.');
        }
        $out[$f] = $v;
    }
    return $out;
}

/** Company names for the Company box: the companies with a page. */
function kop_rinbox_ms_lookup($name, $q) {
    if ($name !== 'company' || !function_exists('kop_operator_pages_index')) return array();
    $q = mb_strtolower($q);
    $starts = $rest = array();
    foreach ((array) (kop_operator_pages_index()['ids'] ?? array()) as $row) {
        $n = (string) ($row['display'] ?? $row['name'] ?? '');
        $pos = mb_strpos(mb_strtolower($n), $q);
        if ($pos === false) continue;
        if ($pos === 0) $starts[] = array('value' => $n); else $rest[] = array('value' => $n);
    }
    return array_slice(array_merge($starts, $rest), 0, 20);
}

function kop_rinbox_ms_tool($id, array $params) {
    if ($id !== 'add') throw new RuntimeException('Unknown tool.');
    return array('message' => kop_rinbox_ms_add($params));
}

/** A milestone added by hand: on the timelines at once, approved by whoever added it. Returns the message. */
function kop_rinbox_ms_add(array $params) {
    foreach (array('company', 'kind', 'date', 'title', 'sources') as $f) if (!array_key_exists($f, $params)) $params[$f] = '';
    $m = kop_rinbox_ms_clean($params) + array('sentence' => '', 'detail' => '', 'also' => array(), 'programs' => array());
    $m['year'] = kop_ptl_date($m['date'])[0];
    $same = mb_strtolower($m['title']);
    foreach (array_keys(kop_ptl_ms_all()) as $k) {
        $o = kop_ptl_ms_get((string) $k);
        if ($o && (int) $o['year'] === (int) $m['year'] && mb_strtolower(trim((string) $o['title'])) === $same
            && kop_ptl_company_key((string) $o['company']) === kop_ptl_company_key($m['company'])) {
            throw new RuntimeException('That milestone is in the queue already (' . kop_rinbox_ms_views()[kop_rinbox_ms_view((string) $k)] . ').');
        }
    }
    $key = 'added-' . (sanitize_title($m['company']) ?: 'company') . '-' . $m['year'] . '-' . substr(md5($m['title'] . microtime(true)), 0, 8);
    $added = (array) get_option('kop_company_milestones_added', array());
    $added[$key] = $m + array('added_by' => kop_rinbox_reviewer(), 'added_at' => current_time('mysql'));
    update_option('kop_company_milestones_added', $added, false);
    $decisions = kop_ptl_ms_decisions();
    $decisions[$key] = array('decision' => 'approved', 'by' => kop_rinbox_reviewer(), 'at' => current_time('mysql'));
    update_option('kop_company_milestones_review', $decisions, false);
    kop_rinbox_ms_purge();
    $url = function_exists('kop_operator_page_url_for_name') ? (string) kop_operator_page_url_for_name($m['company']) : '';
    return 'Added to ' . $m['company'] . '\'s timeline, ' . $m['year'] . '. Its card is under On the timelines, with Undo.'
        . ($url === '' ? ' No company page has that name, so it shows nowhere until one does: check the spelling.' : '');
}

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

    $fields = kop_rinbox_ms_params($m);
    if (!empty($m['added_by'])) $details[] = array('label' => 'Added by hand', 'value' => trim(($m['added_at'] ?? '') . ' by ' . $m['added_by']));

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
    if (isset(kop_ptl_ms_added()[$key])) {
        $actions[] = array('id' => 'delete', 'label' => 'Delete', 'style' => 'neutral',
            'confirm' => 'Delete this milestone you added? It comes off every timeline. Undo is in Recently done.',
            'help' => 'Removes this milestone, added by hand, from the queue and from every timeline. Undo is in Recently done.');
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

/** Saves the reviewer's changes; a field set back to what the milestone first said is dropped. */
function kop_rinbox_ms_save($key, array $fields) {
    $base = kop_ptl_ms_all()[$key] ?? null;
    if (!$base) throw new RuntimeException('That milestone is not in the queue any more.');
    $base['date'] = trim((string) ($base['date'] ?? '')) !== '' ? (string) $base['date'] : (string) $base['year'];
    $edits = kop_ptl_ms_edits();
    $mine = (array) ($edits[$key] ?? array());
    foreach (kop_rinbox_ms_clean($fields) as $f => $v) {
        $was = $base[$f] ?? (is_array($v) ? array() : '');
        $was = is_array($was) ? array_values($was) : trim((string) $was);
        if ($v === $was) unset($mine[$f]); else $mine[$f] = $v;
    }
    if ($mine) $edits[$key] = $mine; else unset($edits[$key]);
    update_option('kop_company_milestones_edits', $edits, false);
    if (kop_rinbox_ms_view($key) === 'approved') kop_rinbox_ms_purge();
    return 'Saved.';
}

function kop_rinbox_ms_purge() {
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
    if (function_exists('do_action')) do_action('litespeed_purge_all');
}

function kop_rinbox_ms_act($key, $action, array $params) {
    $key = (string) $key;
    if ($action === 'restore') return kop_rinbox_ms_restore($key, $params);
    $m = kop_ptl_ms_get($key);
    if (!$m) throw new RuntimeException('That milestone is not in the queue any more.');
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
        case 'delete':
            $added = (array) get_option('kop_company_milestones_added', array());
            if (!isset($added[$key])) throw new RuntimeException('Only a milestone added by hand can be deleted; leave this one off instead.');
            $edits = kop_ptl_ms_edits();
            $undo = array('milestone' => $added[$key], 'decision' => $decisions[$key] ?? null, 'edits' => $edits[$key] ?? null);
            unset($added[$key], $decisions[$key], $edits[$key]);
            update_option('kop_company_milestones_added', $added, false);
            update_option('kop_company_milestones_review', $decisions, false);
            update_option('kop_company_milestones_edits', $edits, false);
            kop_rinbox_ms_purge();
            return array('message' => 'Deleted' . ($view === 'approved' ? ' and taken off the timelines.' : '.'), 'undo' => array('action' => 'restore', 'params' => $undo));
    }
    throw new RuntimeException('Unknown action.');
}

/** Undo of Delete (Recently done): the milestone, its decision and its edits as they were. */
function kop_rinbox_ms_restore($key, array $params) {
    if (empty($params['milestone']) || !is_array($params['milestone'])) throw new RuntimeException('Nothing to restore.');
    $added = (array) get_option('kop_company_milestones_added', array());
    if (isset($added[$key])) return array('message' => 'It is back already.');
    $added[$key] = $params['milestone'];
    update_option('kop_company_milestones_added', $added, false);
    foreach (array('kop_company_milestones_review' => 'decision', 'kop_company_milestones_edits' => 'edits') as $opt => $p) {
        if (empty($params[$p]) || !is_array($params[$p])) continue;
        $all = (array) get_option($opt, array());
        $all[$key] = $params[$p];
        update_option($opt, $all, false);
    }
    kop_rinbox_ms_purge();
    return array('message' => 'Back in the queue' . (($params['decision']['decision'] ?? '') === 'approved' ? ' and on the timelines.' : '.'));
}
