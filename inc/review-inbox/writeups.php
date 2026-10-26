<?php
/**
 * Review inbox source: write-ups to approve (inc/record-writeups.php). One
 * card per facility record with approved facts held for it: the facts (each
 * with its words and source) and the draft About the AI wrote from them and
 * the page's current About. The reviewer edits the draft in the box, asks
 * for a new one, or approves: the held facts go onto the record and the text
 * becomes the page's About, in one save (kop_wu_publish()). Undo in Recently
 * done is kop_wu_undo(). A single fact is sent back from its own queue's
 * "Approved, held for the write-up" tab.
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('writeups', function () {
    if (!function_exists('kop_wu_publish') || !function_exists('kop_wbf_table')) return null;
    return array(
        'label'    => 'Write-ups to approve',
        'group'    => 'Imports to review',
        'help'     => 'Facts approved in Woodbury Facts and Fornits do not go on the public page by themselves: they wait here, one card per record, until they are written up well. '
            . 'Each card lists the approved facts with their words and sources, and a draft of the page\'s About section that takes them in (the AI writes one within the hour; "Write it with AI" asks now). '
            . 'Read the draft against the facts, fix it in the box (cite as ([source](link))), then approve: the facts go on the record and the text becomes the About, at once. Undo in Recently done takes it all back. '
            . 'To take one fact out, use Undo on it in its own queue ("Approved, held for the write-up").'
            . (function_exists('kop_wu_last_run_line') && ($last = kop_wu_last_run_line()) !== '' ? ' ' . $last : ''),
        'views'    => array('waiting' => 'Waiting', 'published' => 'Approved'),
        'view_counts' => function (array $q = array()) {
            return array('waiting' => count(kop_rinbox_wu_ids('waiting', '')), 'published' => count(kop_rinbox_wu_ids('published', '')));
        },
        'count'    => function () {
            kop_wu_ensure_tables();
            return count(kop_wu_held_counts());
        },
        'list'     => function (array $q) {
            $view = $q['view'] === 'published' ? 'published' : 'waiting';
            $ids = kop_rinbox_wu_ids($view, (string) $q['search']);
            $page = array_slice($ids, (int) $q['offset'], (int) $q['limit']);
            return array('items' => array_values(array_filter(array_map('kop_rinbox_wu_item', $page))), 'total' => count($ids));
        },
        'get'      => function ($key) {
            return kop_rinbox_wu_item((int) $key);
        },
        'act'      => 'kop_rinbox_wu_act',
        'save'     => function ($key, array $fields) {
            if (!array_key_exists('writeup', $fields)) return array('message' => 'Nothing to save.');
            $changed = kop_wu_save_draft((int) $key, (string) $fields['writeup'], kop_rinbox_reviewer());
            return array('message' => $changed ? 'Saved. Nothing is on the page until you approve it.' : 'Nothing changed.');
        },
        'no_ai'    => true,
        'tools'    => array(array('id' => 'write', 'label' => 'Write more drafts now', 'style' => 'neutral',
            'help' => 'Asks the AI for drafts for up to 3 more records whose facts have none yet (or got new facts since). This also happens by itself every hour.')),
        'tool'     => function ($id, array $params) {
            if ($id !== 'write') throw new RuntimeException('Unknown tool.');
            $c = kop_wu_run_batch(3);
            kop_rinbox_flush_counts();
            return array('message' => $c['written'] . ' draft' . ($c['written'] === 1 ? '' : 's') . ' written'
                . ($c['errors'] ? '; stopped on: ' . implode(' | ', array_slice($c['errors'], 0, 2)) : '')
                . ($c['left'] ? '; ' . $c['left'] . ' more still to write' : '') . '.');
        },
    );
});

/** The record ids of a view, searched by name: records with held facts (most first), or with an approved write-up (latest first). */
function kop_rinbox_wu_ids($view, $search) {
    global $wpdb;
    kop_wu_ensure_tables();
    if ($view === 'published') {
        $ids = array_map('intval', (array) $wpdb->get_col('SELECT facility_id FROM ' . kop_wu_table() . " WHERE published IS NOT NULL AND published <> '' ORDER BY updated_at DESC, id DESC"));
    } else {
        $ids = array_keys(kop_wu_held_counts());
        // A draft with no facts left (all sent back) still shows, so it can be approved or dropped.
        foreach ((array) $wpdb->get_col('SELECT facility_id FROM ' . kop_wu_table() . " WHERE draft IS NOT NULL AND draft <> ''") as $f) {
            if (!in_array((int) $f, $ids, true)) $ids[] = (int) $f;
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if ($search !== '' && $ids) {
        $in = implode(',', array_map('intval', $ids));
        $st = kop_rinbox_pdo()->prepare("SELECT id FROM facilities_v2 WHERE id IN ({$in}) AND name LIKE ?");
        $st->execute(array('%' . $search . '%'));
        $hit = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
        $ids = array_values(array_filter($ids, function ($id) use ($hit) { return isset($hit[$id]); }));
    }
    return $ids;
}

function kop_rinbox_wu_item($fid) {
    $fid = (int) $fid;
    $fac = kop_rinbox_facility($fid);
    if (!$fac) return null;
    $wu = kop_wu_get($fid);
    $held = kop_wu_held_rows($fid);
    $facts = array_map('kop_wu_fact', $held);
    $draft = (array) ($wu['draft']['paragraphs'] ?? array());
    $pub = (array) ($wu['published']['paragraphs'] ?? array());
    $waiting = $held || $draft;
    $new = $draft ? kop_wu_new_since_draft($fid, $wu['draft'], $held) : array();

    $lines = array();
    if ($facts) {
        $lines[] = 'Approved facts held for this page (' . count($facts) . '):';
        foreach ($facts as $f) {
            $lines[] = '- ' . $f['where'] . ': ' . $f['what'] . ($f['quote'] !== '' ? ' ("' . kop_rinbox_excerpt($f['quote'], 300) . '")' : '') . ' (' . $f['cite'] . ')';
        }
    }
    if ($draft) {
        $lines[] = '';
        $lines[] = 'Draft write-up' . (!empty($wu['draft']['edited_by']) ? ' (edited by ' . $wu['draft']['edited_by'] . ')' : '') . ':';
        foreach ($draft as $p) $lines[] = $p;
        if (!empty($wu['draft']['check'])) $lines[] = 'To check: ' . $wu['draft']['check'];
        if ($new) $lines[] = count($new) . ' fact' . (count($new) === 1 ? ' was' : 's were') . ' approved after this draft was written: "Write it with AI" takes ' . (count($new) === 1 ? 'it' : 'them') . ' in, or add ' . (count($new) === 1 ? 'it' : 'them') . ' yourself.';
    } elseif ($waiting) {
        $lines[] = '';
        $lines[] = 'No draft yet. "Write it with AI" asks for one now (the hourly run writes one too), or type it in the box.';
    }
    if (!$waiting && $pub) {
        $lines[] = 'On the page as its About since ' . ($wu['published']['at'] ?? '') . ' UTC (approved by ' . ($wu['published']['by'] ?? '') . '):';
        foreach ($pub as $p) $lines[] = $p;
    }

    $actions = array();
    if ($waiting) {
        if ($draft) $actions[] = array('id' => 'publish', 'label' => 'Approve: put it on the page', 'style' => 'approve', 'with_fields' => true,
            'help' => 'The ' . count($held) . ' held fact' . (count($held) === 1 ? '' : 's') . ' go onto the record of ' . $fac['name'] . ' (staff, incidents, names, closure...) and the write-up becomes its About section, in one save. Undo in Recently done takes it all back.');
        $actions[] = array('id' => 'write', 'label' => $draft ? 'Write it again with AI' : 'Write it with AI', 'style' => 'neutral',
            'help' => 'Asks the AI for a new draft from the page\'s About and the held facts. What is in the box now is replaced.');
        if ($draft && !$held) $actions[] = array('id' => 'drop', 'label' => 'Drop the draft', 'style' => 'reject',
            'help' => 'No facts are held for this page any more: the draft is thrown away and the page stays as it is.');
    }
    if (!empty($wu['published']['log']) && !$waiting) {
        $actions[] = array('id' => 'undo_publish', 'label' => 'Undo', 'style' => 'undo', 'params' => array(),
            'help' => 'Takes the facts this write-up put on the record off again (they are held again) and restores the About as it was.');
    }

    return array(
        'key'          => (string) $fid,
        'title'        => $fac['name'],
        'subtitle'     => implode(' · ', array_filter(array(
            count($held) . ' approved fact' . (count($held) === 1 ? '' : 's') . ' held',
            $draft ? 'Draft by ' . (!empty($wu['draft']['edited_by']) ? $wu['draft']['edited_by'] : ucfirst((string) ($wu['draft']['provider'] ?? '') ?: 'the AI')) : ($waiting ? 'No draft yet' : ''),
            $pub ? 'An approved write-up is on the page' : '',
        ))),
        'url'          => function_exists('kop_facility_pages_url_for_id') ? (string) kop_facility_pages_url_for_id($fid) : '',
        'text'         => implode("\n", $lines),
        'created'      => (string) ($wu['draft']['at'] ?? ($wu['published']['at'] ?? '')),
        'status'       => $waiting ? 'pending' : 'approved',
        'status_label' => $waiting ? ($draft ? 'Draft to approve' : 'Waiting for a draft') : 'On the page',
        'facility'     => $fac + array('id' => $fid),
        'fields'       => $waiting ? array(array('name' => 'writeup', 'type' => 'textarea', 'value' => implode("\n\n", $draft),
            'label' => 'Write-up (the About section; a blank line between paragraphs; cite each sentence as ([source](link)))')) : array(),
        'actions'      => $actions,
        'links'        => array_values(array_filter(array_map(function ($f) {
            return $f['url'] !== '' ? array('label' => $f['cite'], 'url' => $f['url']) : null;
        }, $facts))),
    );
}

function kop_rinbox_wu_act($key, $action, array $params) {
    $fid = (int) $key;
    $user = kop_rinbox_reviewer();
    if ($action === 'write') {
        try {
            kop_wu_write($fid, $user);
        } catch (RuntimeException $e) {
            throw new RuntimeException('No new draft: ' . $e->getMessage());
        }
        return array('message' => 'A new draft is in the box. Read it against the facts before approving.');
    }
    if ($action === 'publish') {
        // The text as the editor holds it now ('with_fields'), saved or not.
        $text = isset($params['fields']['writeup']) ? (string) $params['fields']['writeup'] : null;
        $res = kop_wu_publish($fid, $user, $text);
        kop_rinbox_flush_counts();
        return array('message' => $res['message'], 'undo' => array('action' => 'undo_publish', 'params' => array('log' => (int) $res['log'])));
    }
    if ($action === 'undo_publish') {
        $log = (int) ($params['log'] ?? 0);
        if ($log <= 0) $log = (int) (kop_wu_get($fid)['published']['log'] ?? 0);
        $msg = kop_wu_undo($log, $user);
        kop_rinbox_flush_counts();
        return array('message' => $msg);
    }
    if ($action === 'drop') {
        if (kop_wu_held_rows($fid)) throw new RuntimeException('Facts are still held for this page: approve the write-up, or send them back from their queues first.');
        $wu = kop_wu_get($fid);
        kop_wu_put($fid, array(), $wu['published']);
        kop_rinbox_flush_counts();
        return array('message' => 'Draft dropped. The page is as it was.');
    }
    throw new RuntimeException('Unknown action.');
}
