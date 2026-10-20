<?php
/**
 * KOP Tools > Plan Board (inc/plan-board.php): the plan seed reads, every item has a unique id and a hash, the
 * markdown renders, open vs folded-in answers, the export, and every card renders. Offline, WordPress stubbed.
 *
 *   php scripts/test-plan-board.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

$theme = dirname(__DIR__);
define('ABSPATH', sys_get_temp_dir() . '/');
function add_action() {}
function add_filter() {}
function get_stylesheet_directory() { return $GLOBALS['theme']; }
function get_option($k, $d = false) { return $d; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function wp_date($f, $t = null) { return date($f, $t ?? time()); }
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
    array('id' => 1, 'item_id' => $first['id'], 'item_hash' => $first['hash'], 'lead' => $first['lead'], 'kind' => 'answer', 'body' => 'older', 'saved_at' => '2026-10-20 09:00:00'),
    array('id' => 2, 'item_id' => 'x:a', 'item_hash' => 'h', 'lead' => 'Gone', 'kind' => 'done', 'body' => '', 'saved_at' => '2026-10-20 09:00:00'),
    array('id' => 3, 'item_id' => $first['id'], 'item_hash' => 'stale', 'lead' => $first['lead'], 'kind' => 'later', 'body' => 'newer', 'saved_at' => '2026-10-20 10:00:00'),
);
list($open, $folded) = kop_pb_split($rows, $handled);
$check('the newest open answer stands for its item', ($open[$first['id']]['id'] ?? 0) === 3);
$check('a folded-in answer is not open', !isset($open['x:a']) && isset($folded['x:a']));
$exp = kop_pb_open_export($open, $items);
$check('export: one open answer, item reworded since', count($exp) === 1 && $exp[0]['itemChanged'] === true && $exp[0]['onPlan'] === true, json_encode($exp));

// Every card renders, with an answer, folded in, and plain.
$errs = array();
foreach ($items as $it) {
    try {
        $html = kop_pb_card_html($it, null, null);
        if (strpos($html, 'data-act="answer"') === false) $errs[] = $it['id'];
    } catch (Throwable $e) { $errs[] = $it['id'] . ': ' . $e->getMessage(); }
}
$check('every card renders with its answer box', !$errs, implode('; ', array_slice($errs, 0, 3)));
$c = kop_pb_card_html($first, $open[$first['id']], null);
$check('an open answer shows its text, Change and Withdraw, and the reworded note', strpos($c, 'newer') !== false && strpos($c, 'data-act="withdraw"') !== false && strpos($c, 'reworded') !== false);
$c = kop_pb_card_html($first, null, $rows[0] + array('pass' => array('at' => '2026-10-21', 'result' => '**moved** to 6')));
$check('a folded-in answer shows the pass result and a fresh box', strpos($c, 'Folded in 2026-10-21') !== false && strpos($c, '<strong>moved</strong>') !== false && strpos($c, 'data-act="answer"') !== false);

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
