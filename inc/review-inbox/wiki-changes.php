<?php
/**
 * Review inbox source: wiki updates made from record changes (inc/wiki-auto-updates.php).
 *
 * One card per r/troubledteens entry whose linked record changed in a way the entry does not show yet: each line the
 * site wrote from the record, what changed on the record that the entry contradicts (never written in), "Show the
 * whole entry". "Edit details" changes or empties any line. Tabs as on Wiki updates: To review (Approve writes KOP's
 * copy of the entry, Set aside), Ready for Reddit (Copy, Edit it on Reddit, Pasted on Reddit), On Reddit, Set aside;
 * Undo on each.
 *
 * Keys: the wiki_submissions id of the entry's newest row.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__) . '/wiki-auto-updates.php';

kop_rinbox_register('wiki-changes', function () {
    return array(
        'label'    => 'Wiki updates from record changes',
        'group'    => 'Suggestions to check',
        'help'     => 'When a record linked to an r/troubledteens wiki entry changes (new staff, a closure, news, a lawsuit, a death, a finding), the site writes the lines the entry is now missing, each with its source. Approve writes them into KOP\'s copy of the entry; then copy it onto the Reddit page. Undo on every tab.',
        'views'    => array('review' => 'To review', 'ready' => 'Ready for Reddit', 'posted' => 'On Reddit', 'skip' => 'Set aside'),
        'count'    => function () { return kop_rinbox_wchg_view_counts()['review']; },
        'view_counts' => 'kop_rinbox_wchg_view_counts',
        'list'     => 'kop_rinbox_wchg_list',
        'get'      => function ($key) {
            $rows = kop_rinbox_wchg_rows();
            return isset($rows[(int) $key]) ? kop_rinbox_wchg_item($rows[(int) $key]) : null;
        },
        'act'      => 'kop_rinbox_wchg_act',
        'save'     => 'kop_rinbox_wchg_save',
        'ai_fill'  => function () {
            throw new RuntimeException('These lines are written from the record and cite its sources; edit a line by hand instead.');
        },
    );
});

/** Every waiting or decided update: id => draft + row + view. */
function kop_rinbox_wchg_rows($reset = false) {
    static $rows = null;
    if ($reset) {
        $rows = null;
        return array();
    }
    if ($rows !== null) return $rows;
    $rows = array();
    $drafts = kop_wiki_auto_drafts();
    if (!$drafts) return $rows;
    $pdo = kop_wiki_upd_pdo();
    $state = kop_wiki_auto_state();
    foreach ($drafts as $id => $d) {
        try {
            $row = kop_wiki_drafts_row($pdo, $id);
        } catch (RuntimeException $e) {
            continue;
        }
        $rows[(int) $id] = array('id' => (int) $id, 'draft' => $d, 'row' => $row, 'state' => $state[$id] ?? null,
            'view' => $state[$id]['view'] ?? 'review');
    }
    return $rows;
}

function kop_rinbox_wchg_view_counts() {
    $counts = array('review' => 0, 'ready' => 0, 'posted' => 0, 'skip' => 0);
    foreach (kop_rinbox_wchg_rows() as $r) $counts[$r['view']] = ($counts[$r['view']] ?? 0) + 1;
    return $counts;
}

function kop_rinbox_wchg_list(array $q) {
    $rows = array_filter(kop_rinbox_wchg_rows(), function ($r) use ($q) {
        if ($r['view'] !== $q['view']) return false;
        if ($q['search'] === '') return true;
        $hay = mb_strtolower($r['row']['program_name'] . ' ' . $r['row']['city_state'] . ' ' . ($r['draft']['record']['name'] ?? ''));
        return mb_strpos($hay, mb_strtolower($q['search'])) !== false;
    });
    // Newest change first.
    uasort($rows, function ($a, $b) { return strcmp((string) ($b['draft']['at'] ?? ''), (string) ($a['draft']['at'] ?? '')); });
    $items = array();
    foreach (array_slice($rows, (int) $q['offset'], (int) $q['limit'], true) as $r) $items[] = kop_rinbox_wchg_item($r);
    return array('items' => $items, 'total' => count($rows));
}

/** "2 staff, 1 news article" from the draft's kinds. */
function kop_rinbox_wchg_kinds(array $kinds) {
    $words = array('staff' => array('staff member', 'staff'), 'news' => array('news article', 'news articles'), 'lawsuit' => array('lawsuit', 'lawsuits'),
        'death' => array('death', 'deaths'), 'finding' => array('inspection finding', 'inspection findings'), 'incident' => array('incident', 'incidents'),
        'name' => array('name', 'names'), 'operator' => array('company', 'companies'), 'closure' => array('closure', 'closures'), 'rules' => array('handbook rule', 'handbook rules'));
    $out = array();
    foreach ($kinds as $k => $n) $out[] = $n . ' ' . ($words[$k][$n === 1 ? 0 : 1] ?? $k);
    return implode(', ', $out);
}

function kop_rinbox_wchg_item(array $r) {
    $id = (int) $r['id'];
    $d = $r['draft'];
    $row = $r['row'];
    $view = $r['view'];
    $name = trim((string) $row['program_name']);
    $edits = kop_wiki_auto_edits($id);
    $rec = (array) ($d['record'] ?? array());
    $details = array();
    $fields = array();
    $text = '';
    $can_approve = false;

    if ($view === 'review') {
        $b = kop_wiki_drafts_build_draft($d, $row['markdown'], $edits);
        foreach ((array) ($d['ops'] ?? array()) as $op) {
            $label = kop_rinbox_wupd_op_label($op);
            $value = ($op['op'] ?? '') === 'set_header_years' ? (string) $op['years'] : (string) ($op['text'] ?? '');
            if (isset($edits[$op['id']])) $value = (string) $edits[$op['id']];
            $out = trim($value) === '';
            $details[] = array('label' => $out ? $label . ' (left out)' : $label, 'value' => $out ? '' : $value);
            if (!$out && trim((string) ($op['note'] ?? '')) !== '') $details[] = array('label' => 'From', 'value' => (string) $op['note']);
            $fields[] = array('name' => (string) $op['id'], 'label' => $label . ' (empty it to leave this out)',
                'type' => ($op['op'] ?? '') === 'set_header_years' ? 'text' : 'textarea', 'value' => $value);
        }
        foreach ((array) ($d['conflicts'] ?? array()) as $c) {
            $details[] = array('label' => !empty($c['unsourced']) ? 'New on the record, with no source to cite (not written in)' : 'KOP\'s record says otherwise',
                'value' => $c['text'] . (($c['source_label'] ?? '') !== '' ? ' (' . $c['source_label'] . ')' : ''), 'url' => (string) ($c['source_url'] ?? ''));
        }
        foreach ($b['errors'] as $err) $details[] = array('label' => 'Could not place', 'value' => $err);
        $n = $b['count'];
        $can_approve = $n > 0;
        $what = kop_rinbox_wchg_kinds((array) ($d['kinds'] ?? array()));
        $text = ($what !== '' ? 'New on the record since the entry was last updated: ' . $what . '. ' : '')
            . ($n ? $n . ' line' . ($n === 1 ? '' : 's') . ' written from the record, each with its source; check each, change or empty any line in "Edit details", then approve.'
                : 'Nothing to write in: the changes below contradict the entry or have no source. Set it aside once noted, or correct the record or the entry by hand.')
            . ($b['stale'] ? ' The entry was edited after this draft was made: the lines are placed by section heading, so check where they sit in "Show the whole entry".' : '');
    } elseif ($view === 'ready') {
        $text = 'Approved: KOP\'s copy of the entry has the update. Copy the whole entry, paste it over the page on Reddit (Edit it on Reddit), then click Pasted on Reddit. Changes to the record made since wait until then.';
    } elseif ($view === 'posted') {
        $text = 'Pasted on Reddit. The next change to the record makes a new update.';
    } else {
        $text = 'Set aside: the entry stays as it is. The next change to the record makes a new update.';
    }
    if (!empty($r['state']['by'])) $details[] = array('label' => $view === 'skip' ? 'Set aside by' : 'Approved by', 'value' => $r['state']['by'] . ', ' . substr((string) $r['state']['at'], 0, 10));

    $reddit = kop_wiki_drafts_reddit_url($row + array('page' => kop_wiki_upd_page_of($row)));
    $links = array();
    if ($reddit !== '') $links[] = array('label' => 'The entry on the Reddit wiki', 'url' => $reddit);
    if (!empty($rec['url'])) $links[] = array('label' => 'The record\'s page on KOP', 'url' => (string) $rec['url']);
    $links[] = array('label' => 'The whole entry with the changes, and Copy', 'url' => kop_wiki_drafts_view_url($id, 'changes'));
    $edit = $view === 'ready' ? kop_wiki_drafts_reddit_edit_url($reddit) : '';

    if ($view === 'review') {
        $actions = array();
        if ($can_approve) $actions[] = array('id' => 'approve', 'label' => 'Approve', 'style' => 'approve',
            'help' => 'Writes these lines (as edited) into KOP\'s copy of ' . $name . ' and moves it to Ready for Reddit. Undo puts back the text it had.');
        $actions[] = array('id' => 'skip', 'label' => 'Set aside', 'style' => 'reject',
            'help' => 'Leaves ' . $name . ' as it is; these changes are not offered again. Undo brings them back.');
    } elseif ($view === 'ready') {
        $actions = array(
            array('id' => 'posted', 'label' => 'Pasted on Reddit', 'style' => 'approve',
                'help' => 'Marks the update as pasted onto the Reddit page; it moves to On Reddit.'),
            array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
                'help' => 'Puts back the text ' . $name . ' had before it was approved; the update waits on To review again.'),
        );
    } else {
        $actions = array(array('id' => 'undo', 'label' => 'Undo', 'style' => 'undo',
            'help' => $view === 'posted' ? 'Moves it back to Ready for Reddit.' : $name . ' waits on To review again.'));
    }

    $item = array(
        'key'          => (string) $id,
        'title'        => $name,
        'subtitle'     => implode(' · ', array_filter(array(trim((string) $row['city_state'], ' -'), (string) $row['years_active'],
            !empty($d['since']) ? 'changed ' . substr((string) $d['at'], 0, 10) : ''))),
        'text'         => $text,
        'details'      => $details,
        'status'       => $view,
        'status_label' => array('review' => '', 'ready' => 'Ready for Reddit', 'posted' => 'On Reddit', 'skip' => 'Set aside')[$view] ?? '',
        'created'      => (string) ($r['state']['at'] ?? $d['at'] ?? $row['updated_at']),
        'facility'     => !empty($rec['id']) && function_exists('kop_rinbox_facility') ? kop_rinbox_facility((int) $rec['id']) : null,
        'preview'      => array('label' => 'Show the whole entry', 'url' => kop_wiki_drafts_view_url($id, 'changes')),
        'links'        => $links,
        'actions'      => $actions,
        'decide_first' => true,
    );
    if ($fields) $item['fields'] = $fields;
    if ($view === 'review' && count($details) > 6) $item['details_fold'] = 'Show the lines this update adds';
    if ($view === 'ready') {
        $item['copy'] = array('label' => 'Copy the whole entry', 'text' => (string) $row['markdown']);
        if ($edit !== '') $item['go'] = array('label' => 'Edit it on Reddit', 'url' => $edit);
    }
    return $item;
}

function kop_rinbox_wchg_save($key, array $fields) {
    $r = kop_rinbox_wchg_rows()[(int) $key] ?? null;
    if (!$r) throw new RuntimeException('That wiki update is not here any more.');
    if ($r['view'] !== 'review') throw new RuntimeException('Undo it first: only an update on To review can be changed.');
    $edits = kop_wiki_auto_edits($r['id']);
    foreach ((array) ($r['draft']['ops'] ?? array()) as $op) {
        if (!array_key_exists($op['id'], $fields)) continue;
        $orig = ($op['op'] ?? '') === 'set_header_years' ? (string) $op['years'] : (string) ($op['text'] ?? '');
        $v = str_replace("\r\n", "\n", (string) $fields[$op['id']]);
        if (trim($v) === trim($orig)) unset($edits[$op['id']]);
        else $edits[$op['id']] = $v;
    }
    $b = kop_wiki_drafts_build_draft($r['draft'], $r['row']['markdown'], $edits);
    if ($b['problems']) throw new RuntimeException('Not saved: ' . implode('; ', $b['problems']) . '.');
    kop_wiki_auto_save_edits($r['id'], $edits);
    kop_rinbox_wchg_rows(true);
    return array('message' => 'Saved.');
}

function kop_rinbox_wchg_act($key, $action, array $params) {
    $r = kop_rinbox_wchg_rows()[(int) $key] ?? null;
    if (!$r) throw new RuntimeException('That wiki update is not here any more.');
    $id = (int) $r['id'];
    $name = trim((string) $r['row']['program_name']);
    $pdo = kop_wiki_upd_pdo();
    if ($action === 'undo') {
        if ($r['view'] === 'posted') {
            $s = $r['state'];
            $s['view'] = 'ready';
            kop_wiki_auto_set_state($id, $s);
            $msg = 'Undone. ' . $name . ' is back on Ready for Reddit.';
        } else {
            kop_wiki_auto_undo($pdo, $id);
            $msg = 'Undone. ' . $name . ($r['view'] === 'ready' ? ' has its old text back and' : '') . ' waits on To review.';
        }
        kop_rinbox_wchg_rows(true);
        return array('message' => $msg);
    }
    if ($action === 'posted') {
        if ($r['view'] !== 'ready') throw new RuntimeException('Approve it first.');
        $s = $r['state'];
        $s['view'] = 'posted';
        $s['posted_at'] = gmdate('c');
        kop_wiki_auto_set_state($id, $s);
        // Changes made while it waited to be pasted become the next update at the next run.
        kop_wiki_auto_on_change('wiki', array($id));
        kop_rinbox_wchg_rows(true);
        return array('message' => 'Marked as pasted on Reddit. ' . $name . ' is on the On Reddit tab.');
    }
    if ($r['view'] !== 'review') throw new RuntimeException('Undo this entry first.');
    if ($action === 'skip') {
        kop_wiki_auto_skip($id, kop_rinbox_reviewer());
        kop_rinbox_wchg_rows(true);
        return array('message' => 'Set aside. ' . $name . ' stays as it is; Undo is on the Set aside tab.');
    }
    if ($action === 'approve') {
        $b = kop_wiki_auto_approve($pdo, $id, kop_rinbox_reviewer());
        kop_rinbox_wchg_rows(true);
        return array('message' => 'Approved. ' . $name . ' has ' . $b['count'] . ' new line' . ($b['count'] === 1 ? '' : 's') . '; copy it onto Reddit from Ready for Reddit.');
    }
    throw new RuntimeException('Unknown action.');
}
