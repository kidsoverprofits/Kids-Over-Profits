<?php
/**
 * KOP Tools > Plan Board (inc/plan-board.php): the plan seed reads, every item has a unique id and a hash, every
 * owner card says why and how long and links where it can, the markdown renders, open vs folded-in answers, steps,
 * edits and retirements, the export, and every card renders. Offline, WordPress stubbed.
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
$check('This week has a few cards (3 to 12)', $nfocus >= 3 && $nfocus <= 12, (string) $nfocus);
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
$check('a group name reads "2.4 Decisions waiting"', ($items['2.4:wiki-updates-for-entries-with-no-exported-draft']['where'] ?? '') === '2.4 Decisions waiting',
    $items['2.4:wiki-updates-for-entries-with-no-exported-draft']['where'] ?? 'missing');

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
    && strpos($c, 'data-act="unstep"') !== false && strpos($c, 'data-waiting="1"') !== false, $c);
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

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
