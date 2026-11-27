<?php
/**
 * KOP Tools > Plan Board (inc/plan-board.php): the plan seed reads, every item has a unique id and a hash, every
 * owner card says why and how long and links where it can, the markdown renders, open vs folded-in answers, steps,
 * edits and retirements, the export, every card renders, and the tracker's part (inc/plan-board-threads.php): statuses,
 * the left-off note, the owner's step changes, step notes, Pick up here. Offline, WordPress stubbed.
 *
 *   php scripts/test-plan-board.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

$theme = dirname(__DIR__);
define('ABSPATH', sys_get_temp_dir() . '/');
define('DAY_IN_SECONDS', 86400);
function add_action() {}
function add_filter() {}
function get_stylesheet_directory() { return $GLOBALS['theme']; }
function get_option($k, $d = false) { return $d; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_date($f, $t = null) { return date($f, $t ?? time()); }
function wp_json_encode($v) { return json_encode($v); }
function wp_trim_words($s, $n) { $w = preg_split('/\s+/', trim($s)); return implode(' ', array_slice($w, 0, $n)) . (count($w) > $n ? '...' : ''); }
$GLOBALS['theme'] = $theme;

require $theme . '/inc/plan-board.php';

$fails = 0;
$check = function ($name, $ok, $detail = '') use (&$fails) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' -- ' . $detail) . "\n";
    if (!$ok) $fails++;
};

// The seed.
$plan = kop_pb_plan();
$items = kop_pb_items($plan);
$check('the seed has items', count($items) > 50, count($items) . ' items');
$n = 0;
foreach ($plan['sections'] as $s) foreach ($s['groups'] as $g) $n += count($g['items']);
$check('every item id is unique', $n === count($items), "$n items, " . count($items) . ' ids');
$bad = array_filter($items, function ($it) { return !preg_match('/^[0-9.a-z-]+:[a-z0-9-]+$/', $it['id']) || empty($it['hash']) || $it['lead'] === ''; });
$check('every item has an id, a lead and a hash', !$bad, implode(', ', array_keys($bad)));
$nolead = array_filter($items, function ($it) { return strpos($it['text'], '**') !== 0; });
$check('every item starts with a bold lead (its id)', !$nolead, implode(', ', array_slice(array_keys($nolead), 0, 5)));

// Needs you: every owner item (section 2) carries its one-line question; other sections may.
$noask = array_filter($items, function ($it) { return $it['section'] === '2' && $it['ask'] === ''; });
$check('every section 2 item has an "Ask:" question', !$noask, implode(', ', array_slice(array_keys($noask), 0, 5)));
$asks = array_filter($items, function ($it) { return $it['ask'] !== ''; });
$check('no "Ask:" text left inside an item', !array_filter($items, function ($it) { return preg_match('/(^|\s)Ask:\s/', $it['text']); }));
$long = array_filter($asks, function ($it) { return mb_strlen($it['ask']) > 140; });
$check('every question is one line (140 characters at most)', !$long, implode(', ', array_keys($long)));
// The owner's lines (scripts/plan-board/build.py): every section 2 card says why it matters and how long it takes,
// every link is a full address, choices make a decide card, and none of it leaks into the item's text.
$owner = array_filter($items, function ($it) { return $it['section'] === '2'; });
$nowhy = array_filter($owner, function ($it) { return $it['why'] === '' || $it['time'] === ''; });
$check('every section 2 card has a "Why:" and a "Time:" line', !$nowhy, implode(', ', array_slice(array_keys($nowhy), 0, 5)));
$badurl = array();
foreach ($items as $it) {
    foreach ($it['go'] as $g) if (!preg_match('#^https://#', $g['url'])) $badurl[] = $it['id'];
    foreach ($it['steps'] as $st) if ($st['url'] !== '' && !preg_match('#^https://#', $st['url'])) $badurl[] = $it['id'] . '#' . $st['id'];
}
$check('every Go and Step link is a full https address', !$badurl, implode(', ', array_slice($badurl, 0, 5)));
$check("no owner line left inside an item's text", !array_filter($items, function ($it) { return preg_match('/(^|\s)(Why|Go|Choices|Time|Kind|Focus|Step):\s/', $it['text']); }));
$check('choices make a decide card', !array_filter($items, function ($it) { return $it['choices'] && $it['kind'] !== 'decide'; }));
$nfocus = count(array_filter($items, function ($it) { return $it['focus']; }));
$check('Active has a few cards (3 to 12 with "Focus: yes")', $nfocus >= 3 && $nfocus <= 12, (string) $nfocus);
$withsteps = array_filter($items, function ($it) { return count($it['steps']) > 1; });
$check('step ids are unique within their item', !array_filter($withsteps, function ($it) { $ids = array_column($it['steps'], 'id'); return count($ids) !== count(array_unique($ids)); }));

$a = reset($asks);
$c = kop_pb_card_html($a, array(), array());
$check('a card with a question leads with it, folds the text under Notes for Claude and is marked as needing the owner',
    strpos($c, '<p class="kop-pb-ask">') !== false && strpos($c, '<details class="kop-pb-details"><summary>Notes for Claude</summary>') !== false && strpos($c, 'data-needs="1"') !== false
    && strpos($c, 'data-group="' . $a['group'] . '"') !== false, $c);
$check('every card has Edit and Retire', strpos($c, 'data-act="open-edit"') !== false && strpos($c, 'data-act="open-retire"') !== false);
$dec = current(array_filter($items, function ($it) { return $it['choices'] && !$it['steps']; }));
$c = kop_pb_card_html($dec, array(), array());
$check('a decide card has one button per choice and Other', substr_count($c, 'data-act="choice"') === count($dec['choices']) && strpos($c, 'Other...') !== false
    && strpos($c, 'data-choice="' . esc_attr($dec['choices'][0]) . '"') !== false, $dec['id']);
$go = current(array_filter($items, function ($it) { return $it['go'] && !$it['steps'] && $it['kind'] === 'do'; }));
if ($go) {
    $c = kop_pb_card_html($go, array(), array());
    $check('a do card opens its link and has Done', strpos($c, 'href="' . esc_attr($go['go'][0]['url']) . '"') !== false && strpos($c, 'data-act="done"') !== false);
}
// From the group itself, not one of its items: 2.4 is often empty ("Nothing waiting").
$label = 'missing';
foreach ($plan['sections'] as $s) foreach ($s['groups'] as $g) if ($g['key'] === '2.4') $label = kop_pb_group_label($s, $g);
$check('a group name reads "2.4 Decisions waiting"', $label === '2.4 Decisions waiting', $label);

// The seed is built from the plan files as they are (build.py's source_text(): name order, LF, joined by a blank
// line). docs/ is not deployed, so this only runs in a checkout.
$files = glob($theme . '/docs/plan/*.md');
if ($files) {
    usort($files, function ($a, $b) { return strcmp(basename($a), basename($b)); });
    $src = implode("\n\n", array_map(function ($f) { return str_replace(array("\r\n", "\r"), "\n", file_get_contents($f)); }, $files));
    $check('the seed is current with docs/plan/ (else: python scripts/plan-board/build.py)', ($plan['source'] ?? '') === sha1($src), ($plan['source'] ?? 'no source hash') . ' vs ' . sha1($src));
}

// Markdown.
$h = kop_pb_md('**Lead**: run `php a.php --x` at [KOP Tools](https://kidsoverprofits.org/wp-admin/) and see [the file](plan/x.md), also https://example.org/a.b.');
$check('bold', strpos($h, '<strong>Lead</strong>') !== false, $h);
$check('code is escaped and kept whole', strpos($h, '<code>php a.php --x</code>') !== false, $h);
$check('link', strpos($h, '<a href="https://kidsoverprofits.org/wp-admin/" target="_blank" rel="noopener">KOP Tools</a>') !== false, $h);
$check('relative link is a path, not a link', strpos($h, 'the file <span class="kop-pb-path">(plan/x.md)</span>') !== false, $h);
$check('bare address linked, full stop left out', strpos($h, '<a href="https://example.org/a.b"') !== false, $h);
$check('html is escaped', strpos(kop_pb_md('<script>x</script> `<b>`'), '<script>') === false);
$check('a link inside code stays code', kop_pb_md('`https://x.org/[a](b)`') === '<code>https://x.org/[a](b)</code>');

// Passes and the split.
$tmp = tempnam(sys_get_temp_dir(), 'pbp');
file_put_contents($tmp, json_encode(array('passes' => array(
    array('at' => '2026-10-20 10:00', 'entries' => array(array('answer' => 2, 'itemId' => 'x:a', 'kind' => 'answer', 'result' => 'old'))),
    array('at' => '2026-10-21 10:00', 'entries' => array(array('answer' => 2, 'itemId' => 'x:a', 'kind' => 'answer', 'result' => 'new'))),
))));
list($handled, $passes) = kop_pb_passes($tmp);
$check('passes sort newest first', $passes[0]['at'] === '2026-10-21 10:00');
$check('an answer folded twice keeps the newest pass', ($handled[2]['result'] ?? '') === 'new');
$check('no file, no passes', kop_pb_passes($tmp . '-none') === array(array(), array()));
unlink($tmp);

$first = array_values($items)[0];
$rows = array(
    array('id' => 1, 'item_id' => $first['id'], 'item_hash' => $first['hash'], 'item_lead' => $first['lead'], 'kind' => 'answer', 'body' => 'older', 'saved_at' => '2026-10-20 09:00:00'),
    array('id' => 2, 'item_id' => 'x:a', 'item_hash' => 'h', 'item_lead' => 'Gone', 'kind' => 'done', 'body' => '', 'saved_at' => '2026-10-20 09:00:00'),
    array('id' => 3, 'item_id' => $first['id'], 'item_hash' => 'stale', 'item_lead' => $first['lead'], 'kind' => 'later', 'body' => 'newer', 'saved_at' => '2026-10-20 10:00:00'),
);
list($open, $folded) = kop_pb_split($rows, $handled);
$check('the newest open answer stands for its item', ($open[$first['id']]['id'] ?? 0) === 3);
$check('a folded-in answer is not open', !isset($open['x:a']) && isset($folded['x:a']));
$exp = kop_pb_open_export($open, $items);
$check('export: one open answer, item reworded since', count($exp) === 1 && $exp[0]['itemChanged'] === true && $exp[0]['onPlan'] === true && $exp[0]['lead'] === $first['lead'], json_encode($exp));
$create = file_get_contents($theme . '/inc/plan-board.php');
$check('no column named after a MySQL 8 reserved word', !preg_match('/^\s+(lead|lag|rank|row_number|window|groups|key|order|rows)\s+(VARCHAR|TEXT|BIGINT|INT|DATETIME|SMALLINT)\b/mi', $create));

// Every card renders, with an answer, folded in, and plain.
$errs = array();
foreach ($items as $it) {
    try {
        $html = kop_pb_card_html($it, array(), array());
        if (strpos($html, 'data-act="answer"') === false || strpos($html, 'data-act="retire"') === false) $errs[] = $it['id'];
    } catch (Throwable $e) { $errs[] = $it['id'] . ': ' . $e->getMessage(); }
}
$check('every card renders with its answer box and Retire', !$errs, implode('; ', array_slice($errs, 0, 3)));
$c = kop_pb_card_html($first, $open, array());
$check('an open answer shows its text, Undo, and the reworded note', strpos($c, 'newer') !== false && strpos($c, 'data-act="withdraw"') !== false && strpos($c, 'reworded') !== false);
$c = kop_pb_card_html($first, array(), array($first['id'] => $rows[0] + array('pass' => array('at' => '2026-10-21', 'result' => '**moved** to 6'))));
$check('a folded-in answer shows the pass result and a fresh box', strpos($c, 'Folded in 2026-10-21') !== false && strpos($c, '<strong>moved</strong>') !== false && strpos($c, 'data-act="answer"') !== false);

// Steps, edits, retirements.
$check('targets: item, step, edit', kop_pb_target('2.3:a#b') === array('2.3:a', 'b', false) && kop_pb_target('2.3:a@edit') === array('2.3:a', '', true)
    && kop_pb_target('2.3:a') === array('2.3:a', '', false));
$check('kinds per target', kop_pb_kinds_for('x:a#s') === array('step', 'skip') && kop_pb_kinds_for('x:a@edit') === array('edit') && in_array('retire', kop_pb_kinds_for('x:a'), true));
$st = reset($withsteps);
$s0 = $st['steps'][0]; $s1 = $st['steps'][1];
$c = kop_pb_card_html($st, array(), array());
$check('a card with steps shows the first step as Next, with Done, next and Skip, and the progress', strpos($c, 'data-step="' . esc_attr($s0['id']) . '"') !== false
    && strpos($c, 'Done, next') !== false && strpos($c, '0 of ' . count($st['steps']) . ' done') !== false && strpos($c, 'All steps (' . count($st['steps']) . ')') !== false, $st['id']);
$srow = function ($key, $kind) use ($st) { return array('id' => 9, 'item_id' => $key, 'item_hash' => $st['hash'], 'item_lead' => $st['lead'], 'kind' => $kind, 'body' => '', 'saved_at' => '2026-11-10 10:00:00'); };
$sopen = array($st['id'] . '#' . $s0['id'] => $srow($st['id'] . '#' . $s0['id'], 'step'));
$c = kop_pb_card_html($st, $sopen, array());
$check('a done step moves Next on, counts, and can be undone', strpos($c, '<div class="kop-pb-next" data-step="' . esc_attr($s1['id']) . '"') !== false && strpos($c, '1 of ') !== false
    && preg_match('/class="kop-pb-tick"[^>]*data-step="' . preg_quote(esc_attr($s0['id']), '/') . '" checked aria-label/', $c) && strpos($c, 'data-waiting="1"') !== false, $c);
$all = array();
foreach ($st['steps'] as $x) $all[$st['id'] . '#' . $x['id']] = $srow($st['id'] . '#' . $x['id'], 'skip');
$check('an item whose steps are all done leaves Everything for you', strpos(kop_pb_card_html($st, $all, array()), 'data-needs=""') !== false);
$exp = kop_pb_open_export($sopen, $items);
$check('export: a step names its item and its text', count($exp) === 1 && $exp[0]['itemId'] === $st['id'] && $exp[0]['step'] === $s0['label'] && $exp[0]['kind'] === 'step' && $exp[0]['onPlan'], json_encode($exp));
$eopen = array($first['id'] . '@edit' => array('id' => 10, 'item_id' => $first['id'] . '@edit', 'item_hash' => $first['hash'], 'item_lead' => $first['lead'], 'kind' => 'edit',
    'body' => json_encode(array('ask' => 'My new headline', 'why' => 'Because', 'text' => '')), 'saved_at' => '2026-11-10 10:00:00'));
$c = kop_pb_card_html($first, $eopen, array());
$check('an edit shows at once, marked Edited, with Undo my edit', strpos($c, 'My new headline') !== false && strpos($c, 'Because') !== false && strpos($c, 'is-edit') !== false
    && strpos($c, 'data-act="unedit"') !== false && strpos($c, kop_pb_md($first['text'])) !== false);
$exp = kop_pb_open_export($eopen, $items);
$check('export: an edit carries the new wording', count($exp) === 1 && $exp[0]['kind'] === 'edit' && ($exp[0]['edit']['ask'] ?? '') === 'My new headline' && $exp[0]['itemId'] === $first['id'], json_encode($exp));
$ropen = array($first['id'] => array('id' => 11, 'item_id' => $first['id'], 'item_hash' => $first['hash'], 'item_lead' => $first['lead'], 'kind' => 'retire', 'body' => 'old news', 'saved_at' => '2026-11-10 10:00:00'));
$c = kop_pb_card_html($first, $ropen, array());
$check('a retired card leaves Everything for you, waits for Claude, and can be undone', strpos($c, 'data-state="retire"') !== false && strpos($c, 'data-needs=""') !== false
    && strpos($c, 'data-waiting="1"') !== false && strpos($c, 'data-act="withdraw"') !== false && strpos($c, 'data-act="open-retire"') === false, $c);
$check('every card not answered yet has Needs research and Send to Claude, each with its box', strpos(kop_pb_card_html($first, array(), array()), 'data-act="open-research"') !== false
    && strpos(kop_pb_card_html($first, array(), array()), 'data-form="claude"') !== false && strpos(kop_pb_card_html($first, array(), array()), 'data-act="research"') !== false);
$check('research and claude are kinds an item takes', in_array('research', kop_pb_kinds_for('x:a'), true) && in_array('claude', kop_pb_kinds_for('x:a'), true)
    && !in_array('research', kop_pb_kinds_for('x:a#s'), true));
foreach (array('research' => 'Needs research', 'claude' => 'Sent to Claude') as $hk => $hl) {
    $hopen = array($first['id'] => array('id' => 12, 'item_id' => $first['id'], 'item_hash' => $first['hash'], 'item_lead' => $first['lead'], 'kind' => $hk, 'body' => 'who runs it now', 'saved_at' => '2026-11-13 10:00:00'));
    $c = kop_pb_card_html($first, $hopen, array());
    $check("a card marked $hl leaves Everything for you, waits for Claude, shows the note and can be undone",
        strpos($c, 'data-state="' . $hk . '"') !== false && strpos($c, 'data-needs=""') !== false && strpos($c, 'data-waiting="1"') !== false
        && strpos($c, 'who runs it now') !== false && strpos($c, 'data-act="withdraw"') !== false && strpos($c, 'data-act="edit-answer"') === false
        && strpos($c, 'data-act="open-' . $hk . '"') === false && strpos($c, $hl) !== false, $c);
    $exp = kop_pb_open_export($hopen, $items);
    $check("export: $hl carries its kind and note", count($exp) === 1 && $exp[0]['kind'] === $hk && $exp[0]['text'] === 'who runs it now', json_encode($exp));
}
$q = current(array_filter($items, function ($it) { return $it['ask'] !== '' && !$it['steps']; }));
$c = kop_pb_card_html($q, array(), array(), array('done' => false, 'evidence' => '12 left in Map Years', 'at' => 1792800000));
$check('a check not done yet shows its count on the card', strpos($c, 'kop-pb-now') !== false && strpos($c, '12 left in Map Years') !== false && strpos($c, 'data-needs="1"') !== false);

// Checks (inc/plan-board-checks.php): every check names an item on the plan, a done check takes the item out of
// Needs you until the owner says Not done for this wording, and the export hands it to the next pass.
$gone = array_diff(array_keys(kop_pb_checks()), array_keys($items));
$check('every check names an item on the plan', !$gone, implode(', ', $gone));
$ids = array_keys($asks);
$fake = array($ids[0] => array('done' => true, 'evidence' => '0 left', 'at' => 1792800000), $ids[1] => array('done' => false, 'evidence' => '3 left', 'at' => 1792800000));
$auto = kop_pb_auto_done($items, $fake, array());
$check('a passing check is done, a failing one is not', isset($auto[$ids[0]]) && !isset($auto[$ids[1]]));
$check('Not done holds for the same wording', !kop_pb_auto_done($items, $fake, array($ids[0] => $items[$ids[0]]['hash'])));
$check('a reworded item is checked again', (bool) kop_pb_auto_done($items, $fake, array($ids[0] => 'oldhash')));
$c = kop_pb_card_html($items[$ids[0]], array(), array(), $auto[$ids[0]]);
$check('a checked-off card shows its evidence and Not done, and leaves Needs you', strpos($c, 'data-state="auto"') !== false && strpos($c, '0 left') !== false
    && strpos($c, 'data-act="notdone"') !== false && strpos($c, 'data-act="open"') === false && strpos($c, 'data-needs=""') !== false, $c);
$exp = kop_pb_open_export(array(), $items, $auto);
$check('the export lists a checked-off item as kind auto', count($exp) === 1 && $exp[0]['kind'] === 'auto' && $exp[0]['itemId'] === $ids[0] && $exp[0]['text'] === '0 left', json_encode($exp));
$check('an open answer wins over a check', kop_pb_open_export(array($ids[0] => $rows[0]), $items, $auto)[0]['kind'] !== 'auto' && count(kop_pb_open_export(array($ids[0] => $rows[0]), $items, $auto)) === 1);

// The tracker's part (inc/plan-board-threads.php).
$check('targets: the left-off note, the step list, a step note', kop_pb_target_parts('2.3:a@left') === array('2.3:a', '', 'left')
    && kop_pb_target_parts('2.3:a#b@note') === array('2.3:a', 'b', 'note') && kop_pb_target('2.3:a#b@note') === array('2.3:a', 'b', false));
$check('kinds per tracker target', kop_pb_kinds_for('x:a@left') === array('left') && kop_pb_kinds_for('x:a#s@note') === array('note') && kop_pb_kinds_for('x:a@note') === array()
    && kop_pb_kinds_for('x:a#s@left') === array() && kop_pb_kinds_for('x:a@steps') === array('steps') && in_array('waiting', kop_pb_kinds_for('x:a'), true));
$check('every kind fits the kind column (10 characters)', !array_filter(array_keys(kop_pb_kinds()), function ($k) { return strlen($k) > 10; }));
$check('the plan carries left-off notes and touched dates', (bool) array_filter($items, function ($it) { return $it['left'] !== '' && $it['touched'] !== ''; }));
$trow = function ($target, $kind, $body, $id = 20) use ($st) {
    return array('id' => $id, 'item_id' => $target, 'item_hash' => $st['hash'], 'item_lead' => $st['lead'], 'kind' => $kind, 'body' => $body, 'saved_at' => '2026-11-27 10:00:00');
};

// The owner's step list over the plan's.
$body = kop_pb_steps_change($st, array(), array(), 'reword', $s0['id'], 'Reworded first step');
$o = array($st['id'] . '@steps' => $trow($st['id'] . '@steps', 'steps', $body));
$eff = kop_pb_steps_of($st, $o);
$check('a reworded step keeps its id and remembers the plan wording', is_string($body) && $eff[0]['id'] === $s0['id'] && $eff[0]['label'] === 'Reworded first step' && $eff[0]['reworded'] === $s0['label']
    && count($eff) === count($st['steps']), json_encode($body));
$check('rewording back to the plan needs no row', kop_pb_steps_change($st, $o, array(), 'reword', $s0['id'], $s0['label']) === '');
$body = kop_pb_steps_change($st, $o, array(), 'add', '', 'A new step');
$o2 = array($st['id'] . '@steps' => $trow($st['id'] . '@steps', 'steps', $body));
$eff = kop_pb_steps_of($st, $o2);
$new_step = end($eff);
$check('an added step goes last with its own id', $new_step['label'] === 'A new step' && !empty($new_step['added']) && strpos($new_step['id'], 'n-') === 0 && count($eff) === count($st['steps']) + 1);
$body = kop_pb_steps_change($st, $o2, array(), 'up', $new_step['id'], '');
$eff = kop_pb_steps_of($st, array($st['id'] . '@steps' => $trow($st['id'] . '@steps', 'steps', $body)));
$check('move up', $eff[count($eff) - 2]['id'] === $new_step['id']);
$body = kop_pb_steps_change($st, $o2, array(), 'remove', $s0['id'], '');
$o3 = array($st['id'] . '@steps' => $trow($st['id'] . '@steps', 'steps', $body));
$check('a removed step is gone', !in_array($s0['id'], array_column(kop_pb_steps_of($st, $o3), 'id'), true));
$st2 = $st;
$st2['steps'][] = array('id' => 'written-later', 'label' => 'Written later', 'url' => '', 'note' => '', 'answer' => '');
$eff = kop_pb_steps_of($st2, $o3);
$check('a step a pass adds after the owner changed the list still shows, last', end($eff)['id'] === 'written-later' && !in_array($s0['id'], array_column($eff, 'id'), true));
$body = kop_pb_steps_change($st, $o2, array(), 'replace', $s1['id'], array('Smaller one', 'Smaller two', ''));
$eff = kop_pb_steps_of($st, array($st['id'] . '@steps' => $trow($st['id'] . '@steps', 'steps', $body)));
$check('a step replaced by smaller ones', in_array('Smaller one', array_column($eff, 'label'), true) && in_array('Smaller two', array_column($eff, 'label'), true)
    && !in_array($s1['id'], array_column($eff, 'id'), true) && count($eff) === count($st['steps']) + 2);
$check('a done step is not reworded', is_array(kop_pb_steps_change($st, $sopen, array(), 'reword', $s0['id'], 'x')));
$check('a step that is not there is refused', is_array(kop_pb_steps_change($st, array(), array(), 'up', 'nope', '')) && is_array(kop_pb_steps_change($st, array(), array(), 'add', '', '  ')));
$exp = kop_pb_open_export($o3 + array($st['id'] . '@next' => $trow($st['id'] . '@next', 'next', '', 21)), $items);
$check('export: the step list in the owner\'s order, the removed step named, no Up next row', count($exp) === 1 && $exp[0]['kind'] === 'steps'
    && in_array('A new step', array_column($exp[0]['steps'], 'label'), true) && $exp[0]['removed'] === array($s0['label']), json_encode($exp));

// Statuses.
$base = array('id' => 'x:a', 'ask' => 'Do it', 'left' => '', 'status' => '', 'focus' => false, 'steps' => array());
$xrow = function ($kind) { return array('x:a' => array('id' => 30, 'item_id' => 'x:a', 'kind' => $kind, 'body' => '')); };
$two = array(array('state' => 'step'), array('state' => 'skip'));
$check('status with no answer: parked, active with Focus, waiting with Status, none for an item that is not the owner\'s',
    kop_pb_status_of($base, array(), array()) === 'parked' && kop_pb_status_of(array('focus' => true) + $base, array(), array()) === 'active'
    && kop_pb_status_of(array('status' => 'waiting', 'focus' => true) + $base, array(), array()) === 'waiting' && kop_pb_status_of(array('ask' => '') + $base, array(), array()) === '');
$check('status by answer: Not now parks, Done finishes, waiting and active are the owner\'s own',
    kop_pb_status_of($base, $xrow('later'), array()) === 'parked' && kop_pb_status_of($base, $xrow('done'), array()) === 'done'
    && kop_pb_status_of($base, $xrow('waiting'), array()) === 'waiting' && kop_pb_status_of($base, $xrow('active'), array()) === 'active');
$check('an item handed to Claude or checked off is with Claude', kop_pb_status_of($base, $xrow('research'), array()) === 'claude' && kop_pb_status_of($base, $xrow('answer'), array()) === 'claude'
    && kop_pb_status_of($base, array(), array(), true) === 'claude' && kop_pb_status_of($base, $xrow('active'), array(), true) === 'active');
$check('every step done is Finished without a second click; Active set by hand is kept', kop_pb_status_of(array('focus' => true) + $base, array(), $two) === 'done'
    && kop_pb_status_of($base, $xrow('active'), $two) === 'active');
$check('the status under the owner\'s own', kop_pb_base_status(array('focus' => true) + $base, $xrow('later'), array()) === 'active');
$check('touched: never, today, days ago', kop_pb_ago(0) === 'not touched yet' && kop_pb_ago(time()) === 'touched today' && kop_pb_ago(time() - 3 * 86400) === 'touched 3 days ago');
$check('the newest answer of any kind touches the item', kop_pb_touched(array($trow('x:a#s@note', 'note', 'n'), array('item_id' => 'x:a', 'saved_at' => '2026-11-27 11:00:00')))['x:a'] === strtotime('2026-11-27 11:00:00 UTC'));
$check('over five active says so', strpos(kop_pb_load_line(6), 'That is a lot') !== false && strpos(kop_pb_load_line(5), 'That is a lot') === false && strpos(kop_pb_load_line(1), '1 active item.') === 0);

// The card: a row that opens, the left-off note, the steps as a list to work in.
$c = kop_pb_card_html($st, array(), array());
$check('a card is a closed row with its name, steps done, last touched and next step', strpos($c, '<button type="button" class="kop-pb-head" aria-expanded="false"') !== false
    && strpos($c, '0/' . count($st['steps']) . ' steps') !== false && strpos($c, 'touched ') !== false && strpos($c, 'Next: <b>') !== false && preg_match('/class="kop-pb-detail"[^>]* hidden>/', $c), $c);
$check('the card has the left-off box, a box per open step, Add note, the add-step box and the status', strpos($c, 'data-auto="left"') !== false
    && strpos($c, 'Write this before you stop') !== false && substr_count($c, 'data-auto="reword"') === count($st['steps']) && substr_count($c, 'data-act="show-note"') === count($st['steps'])
    && strpos($c, 'data-act="stepadd"') !== false && strpos($c, 'class="kop-pb-status"') !== false && substr_count($c, ' is-next"') === 1, $c);
$key = $st['id'] . '#' . $s0['id'];
$nopen = $sopen + array($key . '@note' => $trow($key . '@note', 'note', 'The answer was 12', 22), $st['id'] . '@left' => $trow($st['id'] . '@left', 'left', 'Stopped after the first one', 23));
$c = kop_pb_card_html($st, $nopen, array());
$check('a left-off note and a step note show at once; the note stays on a done step', strpos($c, 'Stopped after the first one') !== false && strpos($c, 'The answer was 12') !== false
    && strpos($c, 'data-saved="Stopped after the first one"') !== false && strpos($c, 'data-waiting="1"') !== false, $c);
$exp = kop_pb_open_export($nopen, $items);
$check('export: the left-off note and the step note with its step', count(array_filter($exp, function ($e) use ($s0) { return ($e['kind'] === 'left' && $e['text'] === 'Stopped after the first one')
    || ($e['kind'] === 'note' && $e['text'] === 'The answer was 12' && $e['step'] === $s0['label']); })) === 2, json_encode($exp));
$c = kop_pb_card_html($first, $ropen, array());
$check('a card with Claude cannot be changed', strpos($c, 'class="kop-pb-status"') === false && strpos($c, 'data-act="stepadd"') === false && strpos($c, 'readonly') !== false);
$wopen = array($first['id'] => array('id' => 13, 'item_id' => $first['id'], 'item_hash' => $first['hash'], 'item_lead' => $first['lead'], 'kind' => 'waiting', 'body' => '', 'saved_at' => '2026-11-27 10:00:00'));
$c = kop_pb_card_html($first, $wopen, array());
$check('Waiting on someone keeps the card\'s buttons', strpos($c, 'data-status="waiting"') !== false && strpos($c, 'data-act="open-research"') !== false && strpos($c, 'data-act="withdraw"') === false);

// Pick up here.
$focus = array_filter($items, function ($it) { return $it['focus'] && $it['steps']; });
$state = array('items' => $items, 'open' => array(), 'folded' => array(), 'checks' => array(), 'touched' => array());
$pick = kop_pb_pick($state);
$check('Pick up here takes an active item with a next step', isset($items[$pick]) && $items[$pick]['focus'] && $items[$pick]['steps'], $pick);
$other = current(array_filter($focus, function ($it) use ($pick) { return $it['id'] !== $pick; }));
if ($other) {
    $state['open'] = array($other['id'] . '@next' => $trow($other['id'] . '@next', 'next', '', 40));
    $check('"Work on this next" wins', kop_pb_pick($state) === $other['id']);
    foreach ($other['steps'] as $x) $state['open'][$other['id'] . '#' . $x['id']] = $trow($other['id'] . '#' . $x['id'], 'step', '', 41);
    $check('an item whose steps are all done never takes the panel', kop_pb_pick($state) !== $other['id']);
}
$state['open'] = array();
$h = kop_pb_pickup_html($state);
$check('the panel shows the name, the next step, the box for its answer and Done', strpos($h, 'Pick up here') !== false && strpos($h, 'kop-pb-nextline') !== false
    && strpos($h, 'data-auto="note"') !== false && strpos($h, 'Done, show the next one') !== false && strpos($h, 'data-id="' . esc_attr($pick) . '"') !== false, $h);
$check('a card says when it is up next', strpos(kop_pb_card_html($items[$pick], array(), array(), null, array('pick' => $pick)), 'is-pick') !== false
    && strpos(kop_pb_card_html($items[$pick], array(), array(), null, array('pick' => $pick)), 'data-act="next"') === false
    && strpos(kop_pb_card_html($items[$pick], array(), array(), null, array('pick' => 'x')), 'data-act="next"') !== false);
$state['items'] = array();
$check('nothing active: the panel says so', strpos(kop_pb_pickup_html($state), 'No active item') !== false);

// An item started on the board.
$sopen2 = array('new:ab12cd34@new' => array('id' => 50, 'item_id' => 'new:ab12cd34@new', 'item_hash' => '', 'item_lead' => 'A new thing', 'kind' => 'new', 'body' => 'A new thing', 'saved_at' => '2026-11-27 10:00:00'));
$started = kop_pb_started($sopen2);
$c = kop_pb_card_html($started['new:ab12cd34'], $sopen2, array());
$check('an item started on the board is a card, active, with no steps yet', isset($started['new:ab12cd34']) && strpos($c, 'data-status="active"') !== false && strpos($c, 'A new thing') !== false
    && strpos($c, 'No steps yet') !== false && strpos($c, 'Notes for Claude') === false, $c);
$exp = kop_pb_open_export($sopen2, $items + $started);
$check('export: a started item carries its name and is marked started', count($exp) === 1 && $exp[0]['kind'] === 'new' && $exp[0]['text'] === 'A new thing' && !empty($exp[0]['started']) && $exp[0]['onPlan'] === false, json_encode($exp));

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
