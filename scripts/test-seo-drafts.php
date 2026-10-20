<?php
/**
 * KOP Tools > SEO Descriptions (inc/seo-drafts.php): which posts and pages are listed, the drafted
 * description and keyphrase for each kind of page, the hand-written seeds, approve and exact Undo, and
 * the screen. Runs against a scratch copy of the tables it needs from tmp/prod.sqlite; the mirror itself
 * is never written.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-seo-drafts.php [--db=tmp/prod.sqlite] [--list]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");

$args = getopt('', array('db::', 'list'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$list = isset($args['list']);
if (!file_exists($mirror)) {
    fwrite(STDERR, "No mirror at $mirror (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

// The tables the drafts read and write, copied into a scratch file (inspection_reports without its text).
$db_path = sys_get_temp_dir() . '/kop-seo-drafts-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$copy->exec('ATTACH DATABASE ' . $copy->quote($mirror) . ' AS src');
foreach (array('wpdl_posts', 'wpdl_postmeta', 'facilities_v2', 'inspection_facilities') as $t) {
    $sql = $copy->query("SELECT sql FROM src.sqlite_master WHERE type = 'table' AND name = " . $copy->quote($t))->fetchColumn();
    if (!$sql) continue;
    $copy->exec($sql);
    $copy->exec("INSERT INTO main.`$t` SELECT * FROM src.`$t`");
}
$copy->exec('CREATE TABLE inspection_reports AS SELECT id, facility_id FROM src.inspection_reports');
$copy->exec('CREATE TABLE news_submissions AS SELECT id, article_title, article_url, json_data, status FROM src.news_submissions');
$copy->exec('CREATE TABLE news_facility_links AS SELECT * FROM src.news_facility_links');
$copy->exec('DETACH DATABASE src');
$copy = null;
register_shutdown_function(function () use ($db_path) { $GLOBALS['pdo'] = null; $GLOBALS['wpdb'] = null; gc_collect_cycles(); @unlink($db_path); });

$GLOBALS['kop_test_wpdb_writes'] = true;
if (!defined('KOP_DB_CONFIG_LOADED')) define('KOP_DB_CONFIG_LOADED', true);
require __DIR__ . '/kop-test-harness.php';

// What the screen needs beyond the harness. Post meta writes go to the scratch copy.
function update_post_meta($id, $key, $value) {
    global $wpdb;
    $n = $wpdb->update('wpdl_postmeta', array('meta_value' => $value), array('post_id' => (int) $id, 'meta_key' => $key));
    if (!$n) $wpdb->insert('wpdl_postmeta', array('post_id' => (int) $id, 'meta_key' => $key, 'meta_value' => $value));
    return true;
}
function delete_post_meta($id, $key) { global $wpdb; return (bool) $wpdb->delete('wpdl_postmeta', array('post_id' => (int) $id, 'meta_key' => $key)); }
function wp_slash($v) { return $v; }
function wp_unslash($v) { return $v; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="nonce">'; }
function wp_date($f, $t) { return date($f, (int) $t); }
function get_current_user_id() { return 1; }
function wp_generate_password($n = 12) { return substr(bin2hex(random_bytes(8)), 0, $n); }
function wp_die($m) { throw new RuntimeException((string) $m); }
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

require_once dirname(__DIR__) . '/inc/template-layout.php';   // kop_template_layout_noindex()
require_once dirname(__DIR__) . '/inc/seo.php';
require_once dirname(__DIR__) . '/inc/admin-kit.php';
require_once dirname(__DIR__) . '/inc/seo-drafts.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$post = function ($id) use ($wpdb) {
    return $wpdb->get_row($wpdb->prepare('SELECT ID, post_type, post_name, post_title, post_excerpt, post_content FROM wpdl_posts WHERE ID = %d', $id));
};

echo "-- Seeds --\n";
$seed = kop_seo_drafts_seed();
$bad = array();
foreach ($seed as $id => $s) {
    $p = $post((int) $id);
    $len = mb_strlen((string) $s['desc']);
    if (!$p) { $bad[] = "$id gone"; continue; }
    if (kop_seo_drafts_title($p) !== html_entity_decode((string) $s['title'], ENT_QUOTES, 'UTF-8')) $bad[] = "$id is now \"" . kop_seo_drafts_title($p) . '"';
    if ($len < 70 || $len > KOP_SEO_DESC_MAX) $bad[] = "$id [$len]";
}
$check('every written description names its page and fits (70 to 158)', !$bad, implode('; ', array_slice($bad, 0, 6)));

echo "-- Which pages --\n";
$todo = kop_seo_drafts_candidates(false);
$by = array();
$no_draft = $long = array();
$drafts = array();
foreach ($todo as $c) {
    $k = kop_seo_drafts_kind($c[0]);
    $by[$k] = ($by[$k] ?? 0) + 1;
    list($d, $how) = $c[1] !== '' ? array($c[1], 'stored') : kop_seo_drafts_description($c[0]);
    list($kw, $kw_how, $waits) = $c[2] !== '' ? array($c[2], 'stored', false) : kop_seo_drafts_keyphrase($c[0]);
    if ($d === '') $no_draft[] = $c[0]->ID . ' ' . kop_seo_drafts_title($c[0]);
    if (mb_strlen($d) > KOP_SEO_DESC_MAX) $long[] = $c[0]->ID;
    if ($d !== '') $drafts[$d][] = (int) $c[0]->ID;
    if ($list) echo '  ' . $c[0]->ID . " [$k] " . kop_seo_drafts_title($c[0]) . "\n      $d\n      kw: " . ($kw !== '' ? $kw : '(' . $kw_how . ')') . "\n";
}
ksort($by);
echo '  ' . count($todo) . ' listed: ' . implode(', ', array_map(function ($k, $n) { return "$k $n"; }, array_keys($by), $by)) . "\n";
$check('posts and pages missing a Yoast field are listed', count($todo) > 50, (string) count($todo));
$noindex = (int) $wpdb->get_var("SELECT COUNT(*) FROM wpdl_postmeta WHERE meta_key = '_yoast_wpseo_meta-robots-noindex' AND meta_value = '1' AND post_id IN (" . implode(',', array_map(function ($c) { return (int) $c[0]->ID; }, $todo) ?: array(0)) . ')');
$check('no page kept out of search is listed', $noindex === 0, (string) $noindex);
$tools = array_filter($todo, function ($c) { return kop_seo_noindex_page($c[0], kop_seo_drafts_template($c[0])); });
$check('no tool page is listed', !$tools, implode(',', array_map(function ($c) { return $c[0]->ID; }, $tools)));
$ex = kop_seo_sitemap_exclude_tools(array(5));
$check("the tool pages leave Yoast's sitemap, other exclusions kept", in_array(5, $ex, true) && in_array(6053, $ex, true) && in_array(9366, $ex, true) && !in_array(2251, $ex, true), implode(',', $ex));
$check('every listed page has a drafted description', !$no_draft, implode(' | ', array_slice($no_draft, 0, 8)));
$check('no draft runs past 158', !$long, implode(',', $long));
// Two posts of one article (the Walrus piece, posted twice with small edits) get the same draft; each card names the other.
$dups = array_filter($drafts, function ($ids) { return count($ids) > 1; });
$unnamed = array();
foreach ($dups as $d => $ids) {
    $twins = array_map($post, array_slice($ids, 1));
    $html = kop_seo_drafts_card(array($post($ids[0]), '', ''), false, $twins);
    if (strpos($html, 'Same draft as') === false || strpos($html, esc_html(kop_seo_drafts_title($twins[0]))) === false) $unnamed[] = implode('+', $ids);
}
echo '  same draft: ' . ($dups ? implode(' | ', array_map(function ($d, $ids) { return implode('+', $ids) . ': ' . mb_substr($d, 0, 50); }, array_keys($dups), $dups)) : 'none') . "\n";
$check('pages with the same draft name each other on their cards', !$unnamed && count($dups) <= 2, implode(', ', $unnamed));

echo "-- Rules --\n";
$p = $post(1093);
if ($p) {
    $d = kop_seo_drafts_description($p)[0];
    $check('a lawsuit post: who sued whom, where and when', $d === 'Anonymous survivors sued Vista RTC in Utah on March 23, 2022.', $d);
    $check('its keyphrase is the case name', kop_seo_drafts_keyphrase($p)[0] === 'Anonymous v. Vista RTC', kop_seo_drafts_keyphrase($p)[0]);
}
$p = $post(1097);
if ($p) {
    $d = kop_seo_drafts_description($p)[0];
    $check('"et al." reads as "and others", the complaint date counts', strpos($d, 'Triangle Cross Ranch and others') !== false && strpos($d, 'February 12, 2021') !== false, $d);
    $check('the keyphrase drops "et al."', kop_seo_drafts_keyphrase($p)[0] === 'Sherman, et al. v. Trinity Teen Solutions', kop_seo_drafts_keyphrase($p)[0]);
}
$p = $post(2301);
if ($p) {
    $d = kop_seo_drafts_description($p)[0];
    $check('a news post opens with its facts, not its byline or "Summary:"', strpos($d, 'The parents of 17-year-old') === 0, $d);
    $check('a news post is a news post', kop_seo_drafts_kind($p) === 'news');
}
$p = $post(1856);
if ($p) $check('a spotlight on a person: the name and the program', kop_seo_drafts_keyphrase($p)[0] === 'John Santa Montana Academy', kop_seo_drafts_keyphrase($p)[0]);
$wy = $wpdb->get_row("SELECT p.ID, p.post_type, p.post_name, p.post_title, p.post_excerpt, p.post_content FROM wpdl_posts p JOIN wpdl_postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_page_template' AND m.meta_value = 'templates/page-state.php' WHERE p.post_status = 'publish' AND p.post_title = 'Wyoming'");
if ($wy) {
    $d = kop_seo_drafts_description($wy)[0];
    $check('a state hub names how many programs it lists', (bool) preg_match('/^\d[\d,]* troubled teen programs on record in Wyoming, /', $d), $d);
    $check('its keyphrase waits on the owner\'s wording', kop_seo_drafts_keyphrase($wy)[0] === '' && kop_seo_drafts_keyphrase($wy)[2] === true);
    $GLOBALS['kop_test_options']['kop_seo_kw_wording'] = array('facility' => '{name} abuse', 'state' => 'troubled teen programs {name}');
    $check('once set, the wording fills it', kop_seo_drafts_keyphrase($wy)[0] === 'troubled teen programs Wyoming', kop_seo_drafts_keyphrase($wy)[0]);
    unset($GLOBALS['kop_test_options']['kop_seo_kw_wording']);
}
// News posts: the program comes from news_facility_links once the facility wording is set.
$GLOBALS['kop_test_options']['kop_seo_kw_wording'] = array('facility' => '{name} abuse', 'state' => '');
$p = $post(820);
if ($p) $check('a news post: its one linked program, in the facility wording', kop_seo_drafts_keyphrase($p)[0] === 'Provo Canyon School abuse', kop_seo_drafts_keyphrase($p)[0]);
$p = $post(2309);
if ($p) {
    $k = kop_seo_drafts_keyphrase($p);
    $check("the post's own link beats discovery's guesses; the card names the others", $k[0] === 'Youth Dynamics abuse' && strpos($k[1], 'Yellowstone Boys and Girls Ranch') !== false, $k[0] . ' / ' . $k[1]);
}
$p = $post(2315);
if ($p) {
    $k = kop_seo_drafts_keyphrase($p);
    $check('several programs of one kind, none in the title: empty, the card names them', $k[0] === '' && strpos($k[1], 'Discovery Ranch') !== false, $k[1]);
}
$p = $post(470);
if ($p) $check('a news post the import skipped is found by its article link', kop_seo_drafts_keyphrase($p)[0] === 'Robert Land Academy abuse', kop_seo_drafts_keyphrase($p)[0]);
if ($list) {
    foreach (kop_seo_drafts_candidates(false) as $c) if (kop_seo_drafts_kind($c[0]) === 'news') { $k = kop_seo_drafts_keyphrase($c[0]); echo '  news ' . $c[0]->ID . ': ' . ($k[0] !== '' ? $k[0] : '(' . $k[1] . ')') . "\n"; }
}
unset($GLOBALS['kop_test_options']['kop_seo_kw_wording']);
$ut = $wpdb->get_row("SELECT ID, post_type, post_name, post_title, post_excerpt, post_content FROM wpdl_posts WHERE post_name = 'ut-reports' AND post_status = 'publish'");
if ($ut) {
    $d = kop_seo_drafts_description($ut)[0];
    $check('a state report page: its number of reports and the agency', (bool) preg_match('/^\d[\d,]* Utah DHHS inspection reports on youth residential programs in Utah/', $d), $d);
}
$check('two state hubs no longer read the same live', kop_seo_state_description('Utah') !== str_replace('Utah', 'Nevada', kop_seo_state_description('Nevada')));

echo "-- Approve and Undo --\n";
$id = 1093;
$GLOBALS['kop_test_options'] = array_diff_key($GLOBALS['kop_test_options'], array('kop_seo_drafts_log' => 1, 'kop_seo_drafts_skipped' => 1));
$was = array((string) get_post_meta($id, '_yoast_wpseo_metadesc', true), (string) get_post_meta($id, '_yoast_wpseo_focuskw', true));
$e = kop_seo_drafts_approve($id, "  Anonymous survivors sued <b>Vista RTC</b>\n in Utah. ", 'Vista RTC lawsuit');
$check('approving stores both fields, tags and spaces taken out', get_post_meta($id, '_yoast_wpseo_metadesc', true) === 'Anonymous survivors sued Vista RTC in Utah.'
    && get_post_meta($id, '_yoast_wpseo_focuskw', true) === 'Vista RTC lawsuit');
$check('the approval is logged with the fields as they were', $e && $e['before'] === array('metadesc' => $was[0], 'focuskw' => $was[1]) && kop_seo_drafts_log()[0]['token'] === $e['token']);
$check('a page with both fields set leaves the list', !in_array($id, array_map(function ($c) { return (int) $c[0]->ID; }, kop_seo_drafts_candidates(false)), true));
$check('approving the same words again changes nothing', kop_seo_drafts_approve($id, 'Anonymous survivors sued Vista RTC in Utah.', '') === null);
$check('Undo puts both fields back', kop_seo_drafts_undo($e['token']) === true
    && (string) get_post_meta($id, '_yoast_wpseo_metadesc', true) === $was[0] && (string) get_post_meta($id, '_yoast_wpseo_focuskw', true) === $was[1]);
$check('an undone approval cannot be undone twice', kop_seo_drafts_undo($e['token']) === false);
$e = kop_seo_drafts_approve($id, 'First words for the test, long enough to be a description of this lawsuit post.', '');
update_post_meta($id, '_yoast_wpseo_metadesc', 'Changed in the editor afterwards.');
$check('Undo is refused once the field changed since', kop_seo_drafts_undo($e['token']) === false && get_post_meta($id, '_yoast_wpseo_metadesc', true) === 'Changed in the editor afterwards.');
delete_post_meta($id, '_yoast_wpseo_metadesc');
$GLOBALS['kop_test_options']['kop_seo_drafts_skipped'] = array(2301 => time());
$check('a skipped page moves to the Skipped tab', !in_array(2301, array_map(function ($c) { return (int) $c[0]->ID; }, kop_seo_drafts_candidates(false)), true)
    && in_array(2301, array_map(function ($c) { return (int) $c[0]->ID; }, kop_seo_drafts_candidates(true)), true));

echo "-- Screen --\n";
foreach (array('' => 'To review', 'skipped' => 'Skipped', 'done' => 'Done') as $tab => $label) {
    $_GET = array('page' => 'kop-seo-drafts', 'tab' => $tab);
    ob_start();
    try { kop_seo_drafts_screen(); $html = ob_get_clean(); } catch (Throwable $t) { ob_end_clean(); $html = ''; echo '  ' . $t->getMessage() . "\n"; }
    $check("the $label tab draws", strpos($html, 'kop-seod') !== false && substr_count($html, '<form') === substr_count($html, '</form>'), (string) strlen($html));
    if ($tab === '') {
        $check('every card button submits the form', strpos($html, 'name="one"') !== false && !preg_match('/type="button"[^>]*name="(one|skip_one|undo)"/', $html));
        $check('each card has its description and keyphrase boxes', substr_count($html, '<textarea') === min(40, count(kop_seo_drafts_candidates(false))) && strpos($html, '[kw]') !== false);
        $check('no stored token or bare id is shown as a name', !preg_match('/>(facility|consultant|operator):\d+</', $html));
    }
    if ($tab === 'done') $check('Done lists the approval with its Undo', strpos($html, 'name="undo"') !== false);
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
