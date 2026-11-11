<?php
/**
 * "Send to Claude" on every Review inbox card (owner, 2026-11-11: "all submissions should have a 'send to claude'
 * button in case the regular ai can't read it"; then: "sending to claude should have claude decide: what is this,
 * how should it be filed, and where").
 *
 * "Fill empty fields with AI" (Groq / Gemini through kop_rinbox_ai_fill_generic() or a queue's own 'ai_fill') reads
 * the item and the page its link points to on the server. When that page will not open there (a paywall, a site that
 * blocks the server, a scanned PDF, a Facebook post, a Drive file), or the owner wants it sorted for them, they click
 * Send to Claude on the card, optionally saying what to do. The item stays in its queue, with "Take back from Claude"
 * in place of the button. A Claude Code session (the /read-sent-to-claude skill,
 * .claude/skills/read-sent-to-claude/SKILL.md) reads the list, opens each link with its own browser and web tools,
 * decides what the item is, how it should be filed and where, and writes back:
 *
 *   GET  /wp-json/kop/v1/claude/review-items     the list: each item as its card has it (fields, details, links,
 *                                                the buttons it offers with their params, its "Move to" queues)
 *   POST /wp-json/kop/v1/claude/review-items     {source, key, outcome: "filed"|"filled"|"returned", note,
 *                                                fields?: {name: value}, steps?: [{action, params}]}
 *
 * Both need the header X-KOP-Research-Key (kop_msub_is_research(), inc/mobile-submit.php). 'fields' are saved through
 * the queue's own 'save' (only fields the card has, a select only one of its options). 'steps' (at most 5) are the
 * card's own buttons pressed in order through kop_rinbox_apply_action() as reviewer "Claude": a "Move to" (action
 * 'move', params.to one of its moves), a connect or file action, an approve; never an Undo. Each lands in Recently done
 * with its Undo, as anyone's click does. The five native types (news, lawsuits, bills, data, wiki) are approved only
 * on the page's own buttons, so there Claude fills, moves and connects and says in the note whether to approve.
 * "returned" saves and does nothing. The item leaves Claude's list and its card (wherever it is still a card) says what
 * Claude decided (option kop_rinbox_claude_done, the last 300). The list is option kop_rinbox_claude: "source|key" =>
 * {source, key, title, at, by, note}.
 *
 * It is a card add-on (kop_rinbox_addon()), on for every queue but the two that send to Claude their own way (Bug
 * reports: /fix-bugs; Write-ups: the write-ups list). The owner can switch it off per queue at KOP Tools > Card Add-ons.
 * Sending and taking back are logged in Recently done, each the other's Undo.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_RINBOX_CLAUDE_ACTIONS = array('send_to_claude', 'claude_take_back');

/** Queues with their own way to Claude. */
function kop_rinbox_claude_skip_sources() {
    return array('bug-reports', 'writeups');
}

function kop_rinbox_claude_id($source, $key) {
    return (string) $source . '|' . (string) $key;
}

/** [id => {source, key, title, at, by, note}], oldest first. */
function kop_rinbox_claude_queue() {
    $v = get_option('kop_rinbox_claude', array());
    return is_array($v) ? $v : array();
}

/** [id => {at, outcome, note, filled}]: what Claude last said about an item. */
function kop_rinbox_claude_done() {
    $v = get_option('kop_rinbox_claude_done', array());
    return is_array($v) ? $v : array();
}

function kop_rinbox_claude_note_clean($note, $max = 2000) {
    $note = function_exists('wp_strip_all_tags') ? wp_strip_all_tags((string) $note) : strip_tags((string) $note);
    return trim(mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $note), 0, $max));
}

/** The card's button: Send to Claude (with an optional note), or Take back while it waits. */
function kop_rinbox_claude_card($source, array $item) {
    if (in_array($source, kop_rinbox_claude_skip_sources(), true)) return null;
    $id = kop_rinbox_claude_id($source, $item['key'] ?? '');
    $q = kop_rinbox_claude_queue()[$id] ?? null;
    // Only a card still waiting for a decision: one offering an Undo (filed, approved, trashed...) or nothing at all
    // has been decided, unless it is on the list (so it can still be taken back).
    $own = (array) ($item['actions'] ?? array());
    $decided = !$own || array_filter($own, function ($a) { return ($a['style'] ?? '') === 'undo'; });
    if ($decided && !$q) return null;
    if ($q) {
        $by = (string) ($q['by'] ?? '');
        $note = (string) ($q['note'] ?? '');
        return array('id' => 'claude_take_back', 'label' => 'Take back from Claude', 'style' => 'neutral',
            'help' => 'Takes it off Claude\'s list; the card stays here.',
            'notes' => array('Waiting for Claude since ' . (string) $q['at'] . ' UTC' . ($by !== '' ? ' (sent by ' . $by . ')' : '')
                . ($note !== '' ? ': "' . $note . '"' : '') . '. The next Claude session that reads the list opens the link, decides what it is and where it goes, and files it with this card\'s buttons.'));
    }
    $done = kop_rinbox_claude_done()[$id] ?? null;
    $last = '';
    if ($done) {
        $last = 'Claude read it on ' . substr((string) $done['at'], 0, 10)
            . (!empty($done['filled']) ? ', filled ' . implode(', ', (array) $done['filled']) : '')
            . (!empty($done['steps']) ? ', did ' . implode('; ', (array) $done['steps']) : '')
            . ($done['outcome'] === 'returned' ? ' and left it to you' : '')
            . ((string) ($done['note'] ?? '') !== '' ? ': ' . (string) $done['note'] : '.');
    }
    return array('id' => 'send_to_claude', 'label' => $done ? 'Send to Claude again' : 'Send to Claude', 'submit' => 'Send to Claude', 'style' => 'neutral',
        'ask' => true, 'notes' => $last !== '' ? array($last) : array(),
        'help' => 'Puts this card on Claude\'s list, for when "Fill empty fields with AI" cannot read the link (a paywall, a blocked site, a scanned PDF, social media) or you want it sorted: a Claude session opens the link itself, decides what it is, how to file it and where, and does it with this card\'s own buttons (each in Recently done with its Undo), leaving a note here. Undo is in Recently done.',
        'params' => array(
            array('name' => 'claude_note', 'label' => 'What should Claude do? (optional)', 'type' => 'textarea', 'value' => '', 'optional' => true),
        ));
}

/** Put an item on Claude's list. */
function kop_rinbox_claude_send($source, $key, $note = '', $title = '') {
    $q = kop_rinbox_claude_queue();
    $id = kop_rinbox_claude_id($source, $key);
    if (!isset($q[$id]) && count($q) >= 300) throw new RuntimeException('Claude\'s list already holds 300 items. Ask a session to read them first.');
    $q[$id] = array('source' => (string) $source, 'key' => (string) $key, 'title' => mb_substr((string) $title, 0, 300),
        'at' => gmdate('Y-m-d H:i:s'), 'by' => function_exists('kop_rinbox_reviewer') ? kop_rinbox_reviewer() : '',
        'note' => kop_rinbox_claude_note_clean($note));
    update_option('kop_rinbox_claude', $q, false);
    return $q[$id];
}

/** Take it off the list; returns what it was, or null. */
function kop_rinbox_claude_cancel($source, $key) {
    $q = kop_rinbox_claude_queue();
    $id = kop_rinbox_claude_id($source, $key);
    if (!isset($q[$id])) return null;
    $was = $q[$id];
    unset($q[$id]);
    update_option('kop_rinbox_claude', $q, false);
    return $was;
}

/** The add-on's actions; null when $action is not one of them. */
function kop_rinbox_claude_act($source, $key, $action, array $params) {
    if (!in_array($action, KOP_RINBOX_CLAUDE_ACTIONS, true)) return null;
    if ($action === 'send_to_claude') {
        $title = '';
        try {
            $it = kop_rinbox_source($source)['get'] ?? null;
            $it = $it ? call_user_func($it, (string) $key) : null;
            $title = (string) ($it['title'] ?? '');
        } catch (Throwable $e) {
            // The list still works without the title.
        }
        $row = kop_rinbox_claude_send($source, $key, (string) ($params['claude_note'] ?? ''), $title);
        return array('message' => 'Sent to Claude. The next session that reads the list decides how to file it; the card stays here until then. Undo is in Recently done.',
            'undo' => array('action' => 'claude_take_back', 'params' => array()));
    }
    $was = kop_rinbox_claude_cancel($source, $key);
    return array('message' => $was ? 'Taken back from Claude.' : 'It was not on Claude\'s list (a session may have just read it).',
        'undo' => $was ? array('action' => 'send_to_claude', 'params' => array('claude_note' => (string) ($was['note'] ?? ''))) : null);
}

/** What a session gets for one item: the card as the owner sees it, never a sender's email. */
function kop_rinbox_claude_payload(array $row) {
    $source = (string) $row['source'];
    $out = array('source' => $source, 'key' => (string) $row['key'], 'queue' => '', 'title' => (string) ($row['title'] ?? ''),
        'sent_at' => (string) $row['at'], 'sent_by' => (string) ($row['by'] ?? ''), 'note' => (string) ($row['note'] ?? ''),
        'can_save' => false, 'gone' => false);
    try {
        $src = kop_rinbox_source($source);
        $out['queue'] = (string) ($src['label'] ?? $source);
        $out['can_save'] = !empty($src['save']);
        $it = kop_rinbox_get_item($source, (string) $row['key']);
    } catch (Throwable $e) {
        $out['gone'] = true;
        $out['error'] = $e->getMessage();
        return $out;
    }
    $fields = array();
    foreach ((array) $it['fields'] as $f) {
        $fields[] = array_intersect_key((array) $f, array_flip(array('name', 'label', 'type', 'value', 'options', 'readonly')));
    }
    return $out + array(
        'item_title' => (string) $it['title'], 'subtitle' => (string) $it['subtitle'], 'url' => (string) $it['url'],
        'text' => (string) $it['text'], 'status' => (string) $it['status_label'],
        'facility' => $it['facility'], 'details' => array_values((array) $it['details']), 'links' => array_values((array) $it['links']),
        'preview' => $it['preview'], 'fields' => $fields,
        // The buttons Claude may press to file it (never the Undo ones, never its own).
        'actions' => array_values(array_filter((array) $it['actions'], function ($a) {
            return ($a['style'] ?? '') !== 'undo' && !in_array((string) $a['id'], KOP_RINBOX_CLAUDE_ACTIONS, true);
        })),
        'moves' => array_values((array) $it['moves']),
    );
}

/** Claude's list, oldest first, as payloads. */
function kop_rinbox_claude_list() {
    $out = array();
    foreach (kop_rinbox_claude_queue() as $row) $out[] = kop_rinbox_claude_payload((array) $row);
    return $out;
}

/**
 * A session's answer for one item: saves the fields it filled (only the card's own editable fields; a select only one
 * of its options; a list as a list), takes the item off the list and keeps the note for the card.
 *
 * @return array {source, key, outcome, filled: [labels], skipped: [names]}
 */
function kop_rinbox_claude_finish($source, $key, $outcome, $note, $fields = array(), $steps = array()) {
    $source = (string) $source;
    $key = (string) $key;
    $id = kop_rinbox_claude_id($source, $key);
    if (!isset(kop_rinbox_claude_queue()[$id])) throw new RuntimeException('That item is not on Claude\'s list (taken back, or already answered).');
    if (!in_array($outcome, array('filed', 'filled', 'returned'), true)) throw new RuntimeException('outcome must be "filed", "filled" or "returned".');
    if ($outcome === 'returned') { $fields = array(); $steps = array(); }
    $note = kop_rinbox_claude_note_clean($note, 3000);
    if ($note === '') throw new RuntimeException('A note is required: what Claude read and found, or why it could not.');

    $filled = array();
    $skipped = array();
    if (is_array($fields) && $fields) {
        $src = kop_rinbox_source($source);
        if (empty($src['save'])) throw new RuntimeException('This queue has no fields to fill; send outcome "returned" with the note.');
        $item = kop_rinbox_get_item($source, $key);
        $byName = array();
        foreach ((array) $item['fields'] as $f) {
            if (empty($f['readonly'])) $byName[(string) $f['name']] = $f;
        }
        $save = array();
        foreach ($fields as $name => $v) {
            $name = (string) $name;
            $f = $byName[$name] ?? null;
            if (!$f) { $skipped[] = $name; continue; }
            $type = $f['type'] ?? 'text';
            if ($type === 'select') {
                $v = (string) $v;
                if (empty($f['options']) || !array_key_exists($v, (array) $f['options'])) { $skipped[] = $name; continue; }
            } elseif ($type === 'list' || $type === 'facilities') {
                $v = array_values(array_filter(array_map(function ($x) { return trim((string) $x); }, is_array($v) ? $v : preg_split('/[\n;]+/', (string) $v)), 'strlen'));
            } elseif ($type === 'checkbox') {
                $v = !empty($v) ? 1 : 0;
            } else {
                $v = is_array($v) ? implode('; ', array_map('strval', $v)) : trim((string) $v);
            }
            $save[$name] = $v;
            $filled[] = (string) $f['label'];
        }
        if ($save) call_user_func($src['save'], $key, $save);
    }

    // Filing it: the card's own buttons, in order, as Claude (each in Recently done with its Undo).
    $ran = array();
    if (is_array($steps) && $steps) {
        if (count($steps) > 5) throw new RuntimeException('At most 5 steps for one item.');
        $GLOBALS['kop_rinbox_reviewer_as'] = 'Claude';
        try {
            $at = $key;
            foreach (array_values($steps) as $i => $step) {
                $act = function_exists('kop_rinbox_action_id') ? kop_rinbox_action_id((string) ($step['action'] ?? '')) : (string) ($step['action'] ?? '');
                $params = is_array($step['params'] ?? null) ? $step['params'] : array();
                $item = kop_rinbox_get_item($source, $at);
                $ok = false;
                $label = $act;
                if ($act === 'move') {
                    foreach ((array) $item['moves'] as $m) {
                        if ((string) ($m['id'] ?? '') === (string) ($params['to'] ?? '')) { $ok = true; $label = 'Move to ' . (string) $m['label']; }
                    }
                } else {
                    foreach ((array) $item['actions'] as $a) {
                        if ((string) $a['id'] === $act && ($a['style'] ?? '') !== 'undo' && !in_array($act, KOP_RINBOX_CLAUDE_ACTIONS, true)) { $ok = true; $label = (string) $a['label']; }
                    }
                }
                if (!$ok) throw new RuntimeException('Step ' . ($i + 1) . ' ("' . $act . '") is not a button this card offers now' . ($ran ? '; steps before it ran: ' . implode(', ', $ran) : '') . '.');
                $res = kop_rinbox_apply_action($source, $at, $act, $params);
                $ran[] = $label . ': ' . (string) ($res['message'] ?? 'done');
                if (empty($res['item'])) break; // it left the queue (approved, moved, filed)
                $at = (string) ($res['key'] ?? $at);
            }
        } finally {
            unset($GLOBALS['kop_rinbox_reviewer_as']);
        }
    }

    kop_rinbox_claude_cancel($source, $key);
    $done = kop_rinbox_claude_done();
    unset($done[$id]);
    $done[$id] = array('at' => gmdate('Y-m-d H:i:s'), 'outcome' => $outcome, 'note' => $note, 'filled' => $filled, 'steps' => $ran);
    if (count($done) > 300) $done = array_slice($done, -300, null, true);
    update_option('kop_rinbox_claude_done', $done, false);
    if (function_exists('kop_rinbox_flush_counts')) kop_rinbox_flush_counts();
    return array('source' => $source, 'key' => $key, 'outcome' => $outcome, 'filled' => $filled, 'skipped' => $skipped, 'steps' => $ran);
}

kop_rinbox_addon('send-to-claude', array(
    'label'   => 'Send to Claude',
    'help'    => 'On every card: put it on Claude\'s list. A Claude session opens the link itself (for when "Fill empty fields with AI" cannot), decides what it is, how to file it and where, does that with the card\'s own buttons (Undo in Recently done) and leaves a note on the card.',
    'actions' => KOP_RINBOX_CLAUDE_ACTIONS,
    'never'   => 'kop_rinbox_claude_skip_sources',
    'card'    => 'kop_rinbox_claude_card',
    'act'     => 'kop_rinbox_claude_act',
));

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/claude/review-items', array(
        array(
            'methods'             => 'GET',
            'permission_callback' => function (WP_REST_Request $req) { return function_exists('kop_msub_is_research') && kop_msub_is_research($req); },
            'callback'            => function () {
                $resp = new WP_REST_Response(array('success' => true, 'items' => kop_rinbox_claude_list()));
                $resp->header('Cache-Control', 'no-store');
                return $resp;
            },
        ),
        array(
            'methods'             => 'POST',
            'permission_callback' => function (WP_REST_Request $req) { return function_exists('kop_msub_is_research') && kop_msub_is_research($req); },
            'callback'            => function (WP_REST_Request $req) {
                try {
                    $fields = $req->get_param('fields');
                    $out = kop_rinbox_claude_finish((string) $req->get_param('source'), (string) $req->get_param('key'),
                        (string) $req->get_param('outcome'), (string) $req->get_param('note'), is_array($fields) ? wp_unslash($fields) : array(),
                        is_array($req->get_param('steps')) ? wp_unslash($req->get_param('steps')) : array());
                } catch (Throwable $e) {
                    return new WP_Error('kop_rinbox_claude', $e->getMessage(), array('status' => 400));
                }
                return array('success' => true) + $out;
            },
        ),
    ));
});
