<?php
/**
 * Plan Board, the part that works like a tracker of half-finished work (owner, 2026-11-27): every item has a status
 * (Active, Waiting on someone, Parked, Finished), a note on where it was left, steps the owner can tick, reword, move,
 * remove and add, a typed note per step, and one "Pick up here" panel showing a single next step.
 *
 * Nothing here is stored in the plan files by the screen. Each change is an answer row (inc/plan-board.php), laid over
 * the plan at once and folded into docs/plan/ by a later pass:
 *   "<item>@left"        kind left    the left-off note (over the item's "Left:" line)
 *   "<item>#<step>@note" kind note    what the owner typed for a step (over the "Note:" line under that Step)
 *   "<item>@steps"       kind steps   the owner's step list, JSON {list: [{id, label}], removed: [ids]}; a plan step in
 *                                     neither is new since the owner's change and is shown last
 *   "<item>@next"        kind next    "Work on this next": the newest one names the Pick up here item; never folded
 *   "new:<id>@new"       kind new     an item started on the board (body = its name); a pass writes it into a plan file
 *   "<item>"             kinds waiting and active join done (Finished) and later (Parked) as the owner's status
 * An item's status with no answer: "Status: waiting" -> waiting, "Focus: yes" -> active, every step done -> done,
 * otherwise parked when it is the owner's (has an Ask, steps or a left-off note), else none (Whole plan only).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The body of the Finished answer the screen saves itself when the last step is ticked. */
const KOP_PB_AUTO_DONE = 'Every step is done.';

function kop_pb_statuses() {
    return array('active' => 'Active', 'waiting' => 'Waiting on someone', 'parked' => 'Parked', 'done' => 'Finished');
}

/** The answer kind that sets a status by hand. */
function kop_pb_status_kinds() {
    return array('active' => 'active', 'waiting' => 'waiting', 'parked' => 'later', 'done' => 'done');
}

/** A row target as [item id, step id or '', suffix or '']: "x:a#s@note" -> [x:a, s, note]. */
function kop_pb_target_parts($row_item_id) {
    $id = (string) $row_item_id;
    $suffix = '';
    $at = strrpos($id, '@');
    if ($at !== false) {
        $suffix = substr($id, $at + 1);
        $id = substr($id, 0, $at);
    }
    $hash = strpos($id, '#');
    if ($hash !== false) return array(substr($id, 0, $hash), substr($id, $hash + 1), $suffix);
    return array($id, '', $suffix);
}

/** The items started on the board (open "@new" rows), shaped like plan items. */
function kop_pb_started(array $open) {
    $out = array();
    foreach ($open as $target => $r) {
        list($id, , $suffix) = kop_pb_target_parts($target);
        if ($suffix !== 'new' || $r['kind'] !== 'new') continue;
        $name = trim((string) $r['body']);
        $out[$id] = array('id' => $id, 'lead' => $name, 'text' => '', 'ask' => $name, 'hash' => '', 'date' => substr((string) $r['saved_at'], 0, 10),
            'why' => '', 'go' => array(), 'choices' => array(), 'time' => '', 'kind' => 'do', 'focus' => true, 'steps' => array(),
            'left' => '', 'status' => '', 'touched' => '', 'section' => 'new', 'group' => 'new', 'where' => 'Started on the board', 'started' => true);
    }
    return $out;
}

/** Everything a request reads: the plan, the passes, the rows split into open and folded, every item, the checks. */
function kop_pb_state() {
    $plan = kop_pb_plan();
    list($handled, $passes) = kop_pb_passes();
    $rows = kop_pb_rows();
    list($open, $folded) = kop_pb_split($rows, $handled);
    $items = kop_pb_items($plan) + kop_pb_started($open);
    return array('plan' => $plan, 'passes' => $passes, 'rows' => $rows, 'open' => $open, 'folded' => $folded, 'items' => $items,
        'checks' => kop_pb_check_results($items), 'touched' => kop_pb_touched($rows));
}

/** The owner's open step list for an item as {list, removed}, or null. */
function kop_pb_steps_row(array $it, array $open) {
    $r = $open[$it['id'] . '@steps'] ?? null;
    $d = $r ? json_decode((string) $r['body'], true) : null;
    if (!is_array($d) || !isset($d['list']) || !is_array($d['list'])) return null;
    return array('list' => $d['list'], 'removed' => array_map('strval', (array) ($d['removed'] ?? array())));
}

/**
 * The item's steps as the owner sees them: the plan's Step lines with the owner's open changes laid over (order,
 * wording, steps added and removed). Each: {id, label, url, note, answer, added?, reworded?}.
 */
function kop_pb_steps_of(array $it, array $open) {
    $plan = array();
    foreach ((array) ($it['steps'] ?? array()) as $st) {
        $plan[$st['id']] = $st + array('url' => '', 'note' => '', 'answer' => '');
    }
    $mine = kop_pb_steps_row($it, $open);
    if (!$mine) return array_values($plan);
    $out = array();
    $seen = array();
    foreach ($mine['list'] as $e) {
        $id = (string) ($e['id'] ?? '');
        $label = trim((string) ($e['label'] ?? ''));
        if ($id === '' || $label === '' || isset($seen[$id])) continue;
        $seen[$id] = true;
        $st = $plan[$id] ?? array('id' => $id, 'url' => '', 'note' => '', 'answer' => '', 'added' => true);
        if (isset($plan[$id]) && $label !== $plan[$id]['label']) $st['reworded'] = $plan[$id]['label'];
        $st['label'] = $label;
        $out[] = $st;
    }
    foreach ($plan as $id => $st) {
        if (!isset($seen[$id]) && !in_array((string) $id, $mine['removed'], true)) $out[] = $st;
    }
    return $out;
}

/**
 * One change to an item's step list. $op: reword (step, text), up (step), remove (step), add (text; $step = the id
 * to put it after, 'start', or '' for last), replace (step, $text = a list of labels: the step becomes those).
 * Returns the body to save for "<item>@steps" ('' when the list is the plan's again: withdraw only), or an error
 * array('error' => ...). Steps already done are left alone by reword and replace.
 */
function kop_pb_steps_change(array $it, array $open, array $folded, $op, $step, $text) {
    $steps = kop_pb_step_states($it, $open, $folded);
    $mine = kop_pb_steps_row($it, $open);
    $removed = $mine ? $mine['removed'] : array();
    $at = -1;
    foreach ($steps as $i => $st) if ($st['id'] === (string) $step) $at = $i;
    $fresh = function ($label) {
        return array('id' => 'n-' . substr(md5(uniqid('', true) . $label), 0, 8), 'label' => $label, 'url' => '', 'note' => '', 'answer' => '', 'state' => '');
    };
    $clean = function ($label) { return mb_substr(trim(preg_replace('/\s+/', ' ', (string) $label)), 0, 300); };
    if ($op === 'add') {
        $label = $clean($text);
        if ($label === '') return array('error' => 'Type the step first.');
        $pos = $step === 'start' ? 0 : ($at >= 0 ? $at + 1 : count($steps));
        array_splice($steps, $pos, 0, array($fresh($label)));
    } elseif ($at < 0) {
        return array('error' => 'That step is no longer on the plan. Reload the page.');
    } elseif ($op === 'reword') {
        $label = $clean($text);
        if ($label === '') return array('error' => 'A step needs some text. Remove it instead.');
        if ($steps[$at]['state'] !== '') return array('error' => 'That step is already done.');
        $steps[$at]['label'] = $label;
    } elseif ($op === 'up') {
        if ($at === 0) return array('error' => 'That step is already first.');
        $t = $steps[$at - 1];
        $steps[$at - 1] = $steps[$at];
        $steps[$at] = $t;
    } elseif ($op === 'remove' || $op === 'replace') {
        $new = array();
        if ($op === 'replace') {
            if ($steps[$at]['state'] !== '') return array('error' => 'That step is already done.');
            foreach ((array) $text as $label) if ($clean($label) !== '') $new[] = $fresh($clean($label));
            if (!$new) return array('error' => 'No steps to use.');
        }
        if (empty($steps[$at]['added'])) $removed[] = $steps[$at]['id'];
        array_splice($steps, $at, 1, $new);
    } else {
        return array('error' => 'Unknown change.');
    }
    $list = array();
    foreach ($steps as $st) $list[] = array('id' => $st['id'], 'label' => $st['label']);
    $plan = array();
    foreach ((array) $it['steps'] as $st) $plan[] = array('id' => $st['id'], 'label' => $st['label']);
    if ($list === $plan) return '';
    return wp_json_encode(array('list' => $list, 'removed' => array_values(array_unique($removed))));
}

/**
 * The item's status: active, waiting, parked, done; 'claude' when it is with Claude (answered, retired, sent for
 * research, sent to Claude, or checked off by the site); '' for a plan item that is not the owner's.
 * $steps: kop_pb_step_states() of it. $auto: the site's check found it done.
 */
function kop_pb_status_of(array $it, array $open, array $steps, $auto = false) {
    $r = $open[$it['id']] ?? null;
    $kind = $r ? (string) $r['kind'] : '';
    if (in_array($kind, array('answer', 'retire', 'research', 'claude'), true) || (!$r && $auto)) return 'claude';
    $by_kind = array_flip(kop_pb_status_kinds());
    if (isset($by_kind[$kind])) return $by_kind[$kind];
    if ($steps && !array_filter($steps, function ($s) { return $s['state'] === ''; })) return 'done';
    if (($it['status'] ?? '') === 'waiting') return 'waiting';
    if (!empty($it['focus'])) return 'active';
    $mine = (string) $it['ask'] !== '' || $steps || (string) ($it['left'] ?? '') !== '' || isset($open[$it['id'] . '@left']);
    return $mine ? 'parked' : '';
}

/** The status the item has with no answer of the owner's on the item itself. */
function kop_pb_base_status(array $it, array $open, array $steps) {
    unset($open[$it['id']]);
    return kop_pb_status_of($it, $open, $steps);
}

/** The left-off note: the owner's open one, else the plan's "Left:" line. */
function kop_pb_left_of(array $it, array $open) {
    $r = $open[$it['id'] . '@left'] ?? null;
    return $r ? (string) $r['body'] : (string) ($it['left'] ?? '');
}

/** item id => the time (UTC) of its newest answer row of any kind. $rows: every row that stands. */
function kop_pb_touched(array $rows) {
    $out = array();
    foreach ($rows as $r) {
        $id = kop_pb_target_parts($r['item_id'])[0];
        $t = (int) strtotime((string) $r['saved_at'] . ' UTC');
        if ($t > ($out[$id] ?? 0)) $out[$id] = $t;
    }
    return $out;
}

/** When the item was last touched: its newest answer, its "Touched:" line, else the newest date in its text. 0 = never. */
function kop_pb_touched_at(array $it, array $touched) {
    $day = (string) ($it['touched'] ?? '') !== '' ? (string) $it['touched'] : (string) ($it['date'] ?? '');
    $plan = $day !== '' ? (int) strtotime($day . ' 12:00:00 UTC') : 0;
    return max($plan, (int) ($touched[$it['id']] ?? 0));
}

function kop_pb_ago($ts) {
    if (!$ts) return 'not touched yet';
    $days = (int) round((strtotime(wp_date('Y-m-d')) - strtotime(wp_date('Y-m-d', $ts))) / DAY_IN_SECONDS);
    if ($days <= 0) return 'touched today';
    if ($days === 1) return 'touched yesterday';
    if ($days < 30) return 'touched ' . $days . ' days ago';
    return 'touched ' . wp_date('M j', $ts);
}

/** The plan's inline markdown as plain text (a row's name, a text box). */
function kop_pb_plain($s) {
    $t = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '$1', (string) $s);
    return trim(str_replace(array('**', '`'), '', $t));
}

/** A short stable key for an item's element ids. */
function kop_pb_key($item_id) {
    return substr(md5((string) $item_id), 0, 10);
}

/**
 * The item the Pick up here panel shows: an active item with a next step, the one the owner last pressed "Work on
 * this next" on first, then the most recently touched. An item with no steps counts as its own one step; an item
 * whose steps are all done never takes the panel. Returns its id or ''.
 */
function kop_pb_pick(array $s) {
    $best = '';
    $best_key = null;
    foreach ($s['items'] as $id => $it) {
        $steps = kop_pb_step_states($it, $s['open'], $s['folded']);
        $auto = !empty($s['checks'][$id]['done']) && empty($s['checks'][$id]['overridden']);
        if (kop_pb_status_of($it, $s['open'], $steps, $auto) !== 'active') continue;
        if ($steps && !array_filter($steps, function ($x) { return $x['state'] === ''; })) continue;
        $key = array((int) ($s['open'][$id . '@next']['id'] ?? 0), $steps ? 1 : 0, kop_pb_touched_at($it, $s['touched']));
        if ($best_key === null || $key > $best_key) {
            $best = $id;
            $best_key = $key;
        }
    }
    return $best;
}

/** How many items hold each status: {active, waiting, parked, done, claude}. */
function kop_pb_status_counts(array $s) {
    $n = array('active' => 0, 'waiting' => 0, 'parked' => 0, 'done' => 0, 'claude' => 0);
    foreach ($s['items'] as $id => $it) {
        $auto = !empty($s['checks'][$id]['done']) && empty($s['checks'][$id]['overridden']);
        $st = kop_pb_status_of($it, $s['open'], kop_pb_step_states($it, $s['open'], $s['folded']), $auto);
        if ($st !== '') $n[$st]++;
    }
    return $n;
}

/** The line under the page title: how much is active, and a nudge when it is more than five. */
function kop_pb_load_line($active) {
    return (int) $active . ((int) $active === 1 ? ' active item' : ' active items')
        . ($active > 5 ? '. That is a lot to hold at once. Park the ones you will not touch this week.' : '. One next step each.');
}

/**
 * The Pick up here panel: one item, where it was left, its one next step, a box for the answer to that step.
 * $s: kop_pb_state().
 */
function kop_pb_pickup_html(array $s) {
    $id = kop_pb_pick($s);
    $head = '<p class="kop-pb-eyebrow">Pick up here</p>';
    if ($id === '') {
        return '<section class="kop-pb-pick" aria-label="Pick up here">' . $head
            . '<p>No active item with a next step. Open a parked one and set it to Active, or start one at the bottom of the list.</p></section>';
    }
    $it = kop_pb_with_edit($s['items'][$id], kop_pb_edit_of($id, $s['open']));
    $key = kop_pb_key($id);
    $steps = kop_pb_step_states($it, $s['open'], $s['folded']);
    $todo = array_values(array_filter($steps, function ($x) { return $x['state'] === ''; }));
    $name = (string) $it['ask'] !== '' ? kop_pb_plain($it['ask']) : (string) $it['lead'];
    $left = kop_pb_left_of($it, $s['open']);
    $out = '<section class="kop-pb-pick" aria-label="Pick up here" data-id="' . esc_attr($id) . '">' . $head
        . '<h2 class="kop-pb-pick-name">' . esc_html($name) . '</h2>'
        . '<p class="kop-pb-pick-meta">' . esc_html(($it['where'] ?? '') . ' · ' . kop_pb_ago(kop_pb_touched_at($it, $s['touched']))) . '</p>';
    $out .= $left !== ''
        ? '<p class="kop-pb-eyebrow">Where you left off</p><p class="kop-pb-pick-left">' . nl2br(esc_html($left)) . '</p>'
        : '<p class="kop-pb-pick-meta">No note about where you left off. Open the item and write one before you stop next time.</p>';
    if ($todo) {
        $next = $todo[0];
        $note_id = 'pb-pick-note-' . $key . '-' . $next['id'];
        $out .= '<p class="kop-pb-eyebrow">Next step · ' . (count($steps) - count($todo) + 1) . ' of ' . count($steps) . '</p>'
            . '<p class="kop-pb-nextline"><span class="kop-pb-mark">' . kop_pb_md($next['label']) . '</span>'
            . ($next['note'] !== '' ? ' <span class="kop-pb-step-note">' . kop_pb_md($next['note']) . '</span>' : '') . '</p>'
            . '<label class="kop-pb-eyebrow" for="' . esc_attr($note_id) . '">Your answer or notes for this step</label>'
            . '<textarea id="' . esc_attr($note_id) . '" class="kop-pb-auto kop-pb-grow" rows="2" maxlength="4000" data-auto="note" data-step="' . esc_attr($next['id']) . '"'
            . ' data-saved="' . esc_attr($next['answer']) . '" placeholder="Type what you decided, found or need to remember. It is saved with the step.">' . esc_textarea($next['answer']) . '</textarea>'
            . '<div class="kop-pb-buttons">' . ($next['url'] !== '' ? kop_pb_link_button('Open', $next['url'], 'secondary') : '')
            . kop_ak_button('Done, show the next one', 'primary', 'data-act="step" data-step="' . esc_attr($next['id']) . '"')
            . kop_ak_button('Open item', 'quiet', 'data-act="show-card"') . '</div>';
    } else {
        // No steps: the item is its own one step.
        $decide = $it['kind'] === 'decide';
        $out .= '<p class="kop-pb-eyebrow">' . ($decide ? 'Decide' : 'Next step') . '</p><p class="kop-pb-nextline"><span class="kop-pb-mark">' . kop_pb_md((string) $it['ask'] !== '' ? $it['ask'] : $it['lead']) . '</span></p>'
            . '<div class="kop-pb-buttons">';
        foreach ((array) $it['go'] as $g) $out .= kop_pb_link_button($g['label'], $g['url'], 'secondary');
        $out .= ($decide ? kop_ak_button('Open item to answer', 'primary', 'data-act="show-card"')
            : kop_ak_button('Done, show the next one', 'primary', 'data-act="done"') . kop_ak_button('Open item', 'quiet', 'data-act="show-card"')) . '</div>';
    }
    return $out . '<div class="kop-pb-msg" role="status" aria-live="polite"></div></section>';
}

/**
 * The item's steps as a list the owner works in: tick, reword (the text is a box), note, move up, remove; the next
 * step is marked; then a box to add one. $steps: kop_pb_step_states(). $locked: the item is with Claude, nothing
 * can be changed.
 */
function kop_pb_steplist_html(array $it, array $steps, $locked = false) {
    $key = kop_pb_key($it['id']);
    $kinds = kop_pb_kinds();
    $next_id = '';
    foreach ($steps as $st) if ($st['state'] === '') { $next_id = $st['id']; break; }
    $list = '';
    foreach ($steps as $i => $st) {
        $sid = esc_attr($st['id']);
        $done = $st['state'] !== '';
        $plain = kop_pb_plain($st['label']);
        // A done step folded into the plan by a pass can no longer be unticked here.
        $tick = '<input type="checkbox" class="kop-pb-tick" id="pb-chk-' . $key . '-' . $sid . '" data-step="' . $sid . '"' . ($done ? ' checked' : '')
            . ($locked || ($done && !$st['open']) ? ' disabled' : '') . ' aria-label="' . esc_attr('Done: ' . $plain) . '">';
        if ($done || $locked) {
            $text = '<span class="kop-pb-step-text">' . ($st['url'] !== '' ? '<a href="' . esc_attr($st['url']) . '" target="_blank" rel="noopener">' . kop_pb_md($st['label']) . '</a>' : kop_pb_md($st['label']))
                . ($done ? ' <span class="kop-pb-step-state">(' . esc_html($st['state'] === 'skip' ? 'skipped' : 'done') . ')</span>' : '') . '</span>';
        } else {
            $text = '<textarea id="pb-st-' . $key . '-' . $sid . '" class="kop-pb-auto kop-pb-grow kop-pb-step-box" rows="1" maxlength="300" data-auto="reword" data-step="' . $sid . '"'
                . ' data-saved="' . esc_attr($st['label']) . '" aria-label="Step text">' . esc_textarea($st['label']) . '</textarea>';
        }
        $acts = '';
        if (!$locked) {
            if ($st['url'] !== '' && !$done) $acts .= '<a class="button kop-ak-btn kop-ak-btn--quiet" href="' . esc_attr($st['url']) . '" target="_blank" rel="noopener">Open <span aria-hidden="true">&#8599;</span></a>';
            if ($st['answer'] === '') $acts .= kop_ak_button('Add note', 'quiet', 'data-act="show-note" data-step="' . $sid . '"');
            if (!$done && $i > 0) $acts .= kop_ak_button('↑', 'quiet', 'data-act="stepup" data-step="' . $sid . '" title="Move up" aria-label="' . esc_attr('Move up: ' . $plain) . '"');
            if (!$done) $acts .= kop_ak_button('×', 'quiet', 'data-act="stepdel" data-step="' . $sid . '" title="Remove" aria-label="' . esc_attr('Remove step: ' . $plain) . '"');
        }
        $note = '<div class="kop-pb-stepnote"' . ($st['answer'] === '' ? ' hidden' : '') . '>'
            . ($st['note'] !== '' ? '<span class="kop-pb-step-note">' . kop_pb_md($st['note']) . '</span>' : '')
            . '<textarea id="pb-note-' . $key . '-' . $sid . '" class="kop-pb-auto kop-pb-grow" rows="1" maxlength="4000" data-auto="note" data-step="' . $sid . '" data-saved="' . esc_attr($st['answer']) . '"'
            . ($locked ? ' readonly' : '') . ' aria-label="' . esc_attr('Answer or notes for: ' . $plain) . '" placeholder="Your answer or notes for this step">' . esc_textarea($st['answer']) . '</textarea></div>';
        $list .= '<li class="is-' . esc_attr($done ? $st['state'] : 'todo') . ($st['id'] === $next_id ? ' is-next' : '') . '" data-step="' . $sid . '">' . $tick . $text
            . ($done ? ' <span class="screen-reader-text">(' . esc_html($kinds[$st['state']] ?? '') . ')</span>' : '')
            . '<span class="kop-pb-step-acts">' . $acts . '</span>' . $note . '</li>';
    }
    $out = '<div class="kop-pb-allsteps"><p class="kop-pb-eyebrow">All steps (' . count($steps) . ')</p>'
        . ($steps ? '<ul>' . $list . '</ul>' : '<p class="kop-pb-hint">No steps yet.</p>');
    if (!$locked) {
        $add = 'pb-add-' . $key;
        $out .= '<div class="kop-pb-addstep"><label class="screen-reader-text" for="' . $add . '">New step</label>'
            . '<input type="text" id="' . $add . '" class="kop-pb-add-text" data-draft="1" maxlength="300" placeholder="Add a small step, something under 20 minutes">'
            . kop_ak_button('Add step', 'secondary', 'data-act="stepadd"') . '</div>';
    }
    return $out . '</div>';
}
