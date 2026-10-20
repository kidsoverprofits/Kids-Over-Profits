<?php
/**
 * Offline check of inc/content-links.php (first-mention links to our own
 * pages + "Related on Kids Over Profits") against the SQLite mirror of
 * production (tmp/prod.sqlite). Read-only.
 *
 *   php -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite -d memory_limit=4G \
 *       scripts/test-content-links.php [--db=tmp/prod.sqlite] [--posts=40] [--list]
 *
 * The rules on fixed sentences first (names picked from the mirror), then the
 * newest published posts: how many links each gets, and with --list what they are.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$args = getopt('', array('db::', 'posts::', 'list'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$post_count = (int) ($args['posts'] ?? 40);
$list = isset($args['list']);
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
if (!function_exists('kop_glossary_entry_url')) {
    function kop_glossary_entry_url($anchor) { return home_url('/glossary/#' . $anchor); }
}
require_once dirname(__DIR__) . '/inc/content-links.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$links_in = function ($html) {
    preg_match_all('#<a class="kop-auto-link[^"]*" href="([^"]+)">([^<]*)</a>#', $html, $m, PREG_SET_ORDER);
    return array_map(function ($x) { return array(html_entity_decode($x[1]), $x[2]); }, $m);
};

$t0 = microtime(true);
$dict = kop_clinks_dict(true);
$kinds = array();
foreach ($dict['targets'] as $t) $kinds[$t[0]] = ($kinds[$t[0]] ?? 0) + 1;
printf("-- Names: %d keys, %s; built in %.1fs, %d KB serialised --\n", count($dict['keys']),
    implode(', ', array_map(function ($k, $n) { return "$n $k"; }, array_keys($kinds), $kinds)),
    microtime(true) - $t0, strlen(serialize($dict)) / 1024);
$check('facilities, companies and states are in the list', !empty($kinds['facility']) && !empty($kinds['operator']) && !empty($kinds['state']));

// A facility with a long, unique name; one with a two-word name; a company; Utah.
$find = function ($kind, $words_min, $words_max, $unique = true) use ($dict) {
    foreach ($dict['keys'] as $key => $ts) {
        $w = substr_count($key, ' ') + 1;
        if ($w < $words_min || $w > $words_max) continue;
        if ($unique && count($ts) !== 1) continue;
        $t = $dict['targets'][$ts[0]];
        if ($t[0] !== $kind || kop_clinks_key($t[2]) !== $key) continue;
        if ($kind === 'facility' && $t[3] === '') continue;
        $all_generic = true;
        foreach (explode(' ', $key) as $wd) if (!isset(kop_clinks_generic_words()[$wd]) && !isset(kop_clinks_small_words()[$wd])) $all_generic = false;
        if ($all_generic || !preg_match('/^[A-Z][\w\'.]*( [A-Z][\w\'.]*)+$/', $t[2])) continue;
        return $t;
    }
    return null;
};
$long = $find('facility', 3, 4);
$short = $find('facility', 2, 2);
$op = $find('operator', 2, 4);
echo "  examples: {$long[2]} ({$long[3]}); {$short[2]} ({$short[3]}); {$op[2]}\n";

echo "-- Rules --\n";
$r = kop_content_links_apply("<p>She was sent to {$long[2]} in 2004. Later {$long[2]} closed.</p>");
$l = $links_in($r['html']);
$check('a 3+ word facility name links, first mention only', count($l) === 1 && $l[0][0] === $long[1] && $l[0][1] === $long[2], json_encode($l));

$r = kop_content_links_apply('<p>' . strtolower($long[2]) . ' is mentioned in lower case.</p>');
$check('lower case is no name', !$links_in($r['html']));

$r = kop_content_links_apply("<p>Staff at {$short[2]} said so.</p>");
$check('a two-word facility name without its state does not link', !$links_in($r['html']), json_encode($links_in($r['html'])));
$r = kop_content_links_apply("<p>Staff at {$short[2]} in {$short[3]} said so.</p>");
$l = $links_in($r['html']);
$check('...and links when the text names the state', $l && $l[0][0] === $short[1], json_encode($l));

$r = kop_content_links_apply("<p><a href=\"https://example.com\">{$long[2]}</a> and <h2>{$long[2]}</h2></p><h3>About {$long[2]}</h3>");
$check('nothing inside a link or heading', !$links_in($r['html']));

$r = kop_content_links_apply("<p>{$long[2]} again.</p>", array('self' => array($long[1])));
$check('a page never links itself', !$links_in($r['html']) && !$r['found']);

$r = kop_content_links_apply("<p>Run by {$op[2]} since 2010.</p>");
$l = $links_in($r['html']);
$check('a company name links to its page', $l && strpos($l[0][0], '/operator/') !== false, json_encode($l));

$r = kop_content_links_apply('<p>The program in Utah was closed. The New York Times covered it. George Washington slept here. She moved to Washington, D.C. later.</p>');
$l = $links_in($r['html']);
$texts = array_map(function ($x) { return $x[1]; }, $l);
$check('Utah links; New York Times, George Washington, Washington D.C. do not', $texts === array('Utah'), json_encode($texts));

$r = kop_content_links_apply('<p>It is a Delaware corporation and a Nevada LLC.</p>');
$check('a state of incorporation does not link', !$links_in($r['html']), json_encode($links_in($r['html'])));

$r = kop_content_links_apply('<p>The Department of Youth Services said Youth Services would act.</p>');
$check('names of common words and parts of longer names do not link', !$links_in($r['html']), json_encode($links_in($r['html'])));

$r = kop_content_links_apply('<p>She&#8217;s at the Utah &amp; Idaho line, Utah again.</p>');
$check('entities survive (& stays &amp;)', strpos($r['html'], '&amp; <a') !== false && strpos($r['html'], '>Utah</a>') !== false, $r['html']);

$r = kop_content_links_apply('<p>Sent to Utah.</p>');
$l = $links_in($r['html']);
$check('a sentence\'s full stop stays outside the link', $l && $l[0][1] === 'Utah' && strpos($r['html'], '</a>.') !== false, $r['html']);

$box = kop_content_links_related_html($r['found']);
$check('the box lists what the text names', strpos($box, 'kop-related-links') !== false && strpos($box, '>Utah</a>') !== false);
$check('no box when nothing is named', kop_content_links_related_html(array()) === '');

echo "-- Newest {$post_count} posts --\n";
$rows = $wpdb->get_results("SELECT ID, post_title, post_name, post_content FROM wpdl_posts WHERE post_type = 'post' AND post_status = 'publish' ORDER BY post_date DESC LIMIT " . $post_count, ARRAY_A);
$total = 0;
$with = 0;
$t0 = microtime(true);
foreach ($rows as $p) {
    $html = function_exists('wpautop') ? wpautop($p['post_content']) : $p['post_content'];
    $r = kop_content_links_apply($html, array('self' => array(home_url('/' . $p['post_name'] . '/')), 'context' => $p['post_title']));
    $l = $links_in($r['html']);
    $total += count($l);
    if ($l) $with++;
    // The text reads the same with the links taken out.
    $before = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $after = html_entity_decode(strip_tags($r['html']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($before !== $after) $check("text unchanged: {$p['post_name']}", false);
    if ($list) {
        echo "  {$p['post_title']}: " . count($l) . "\n";
        foreach ($l as $x) echo "      {$x[1]} -> {$x[0]}\n";
    }
}
printf("  %d of %d posts get links, %d links in all, %.0f ms a post\n", $with, count($rows), $total, 1000 * (microtime(true) - $t0) / max(1, count($rows)));
$check('the text of every post is unchanged by linking', true);

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
