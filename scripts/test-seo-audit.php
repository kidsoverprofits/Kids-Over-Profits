<?php
/**
 * KOP Tools > SEO Audit (inc/seo-audit.php): reading the crawl's audit.json,
 * flag labels, the filters, the counts and the screen. Offline, temp dir; with
 * --audit=<path> it also renders the screen over a real audit.json.
 *
 *   php -d extension=mbstring scripts/test-seo-audit.php [--audit=../kop-pipeline/tmp/seo-audit/audit.json]
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
error_reporting(E_ALL);
set_error_handler(function ($no, $str, $file, $line) { throw new ErrorException($str, 0, $no, $file, $line); });

$args = getopt('', array('audit::'));
$fails = 0;
$check = function ($name, $ok, $detail = '') use (&$fails) {
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ' -- ' . $detail) . "\n";
    if (!$ok) $fails++;
};

// WordPress stubs only as far as inc/seo-audit.php and the admin kit go.
define('ABSPATH', __DIR__ . '/');
function add_action() {}
function add_filter() {}
function apply_filters($t, $v) { return $v; }
function get_stylesheet_directory() { return dirname(__DIR__); }
function current_user_can() { return true; }
function wp_die($m) { throw new RuntimeException($m); }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_unslash($s) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function selected($a, $b, $echo = true) { return (string) $a === (string) $b ? ' selected="selected"' : ''; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg($k, $v, $u) { return $u . (strpos($u, '?') === false ? '?' : '&') . $k . '=' . rawurlencode($v); }
function wp_date($f, $t) { return date($f, $t); }

require_once dirname(__DIR__) . '/inc/admin-kit.php';
require_once dirname(__DIR__) . '/inc/seo-audit.php';

$tmp = sys_get_temp_dir() . '/kop-seo-audit-test-' . getmypid();
@mkdir($tmp, 0755, true);
$GLOBALS['kop_seo_audit_path'] = $tmp . '/audit.json';

$render = function (array $get) {
    $_GET = $get;
    ob_start();
    kop_seo_audit_page();
    return ob_get_clean();
};

$check('no audit yet: load gives null', kop_seo_audit_load() === null);
$check('no audit yet: the screen says so', strpos($render(array()), 'No audit has been copied') !== false);
file_put_contents($tmp . '/audit.json', '{not json');
$check('a broken file loads as null', kop_seo_audit_load() === null);

$rows = array(
    array('url' => 'https://kidsoverprofits.org/facility/acme-ranch/', 'kind' => 'facility', 'status' => 200, 'post_id' => null,
        'title' => 'Acme Ranch (Utah) | Kids Over Profits', 'desc' => 'Acme Ranch is a program in Utah.', 'canonical' => 'https://kidsoverprofits.org/facility/acme-ranch/',
        'breadcrumb' => array(array('name' => 'Home', 'item' => 'https://kidsoverprofits.org/')), 'stored' => null,
        'flags' => array('sparse_facility', 'breadcrumb_shallow', 'short_desc')),
    array('url' => 'https://kidsoverprofits.org/facility/acme-ranch-2/', 'kind' => 'facility', 'status' => 200,
        'title' => 'Acme Ranch (Idaho) | Kids Over Profits', 'desc' => 'Acme Ranch is a program in Utah.', 'breadcrumb' => array(),
        'flags' => array('sparse_facility', 'dup_desc', 'no_breadcrumb'), 'dup_desc_with' => array('https://kidsoverprofits.org/facility/acme-ranch/')),
    array('url' => 'https://kidsoverprofits.org/some-post/', 'kind' => 'post', 'status' => 200, 'post_id' => 42,
        'title' => 'Some post <b>bold</b>', 'desc' => 'A news story about a program & its owner.',
        'breadcrumb' => array(array('name' => 'Home'), array('name' => 'News'), array('name' => 'Some post')),
        'stored' => array('metadesc' => '', 'focuskw' => ''), 'flags' => array('no_stored_desc', 'no_focuskw', 'http_404')),
    array('url' => 'https://kidsoverprofits.org/about/', 'kind' => 'page', 'status' => 200, 'post_id' => 7, 'title' => 'About', 'desc' => 'Who we are.',
        'stored' => array('metadesc' => 'Who we are.', 'focuskw' => 'about kop'), 'flags' => array()),
);
file_put_contents($tmp . '/audit.json', json_encode(array('generated' => '2026-10-20T12:00:00Z', 'mirror' => '2026-10-20T08:00:00Z', 'rows' => $rows)));
$audit = kop_seo_audit_load();
$check('the audit loads', is_array($audit) && count($audit['rows']) === 4);

$check('a known flag has its label', kop_seo_audit_flag('sparse_facility')[0] === 'Sparse facility');
$check('an HTTP code reads as HTTP 404', kop_seo_audit_flag('http_404')[0] === 'HTTP 404');
$check('an unknown code shows as itself', kop_seo_audit_flag('new_thing')[0] === 'new_thing');

$check('filter by kind', count(kop_seo_audit_filter($rows, 'facility')) === 2);
$check('filter by flag', count(kop_seo_audit_filter($rows, '', 'dup_desc')) === 1);
$check('filter "none" keeps the clean pages', count(kop_seo_audit_filter($rows, '', 'none')) === 1);
$check('search reads the description', count(kop_seo_audit_filter($rows, '', '', 'OWNER')) === 1);
$check('kind and flag together', count(kop_seo_audit_filter($rows, 'post', 'sparse_facility')) === 0);

$counts = kop_seo_audit_flag_counts($rows);
$check('counts per flag', ($counts['sparse_facility'] ?? 0) === 2 && ($counts['no_focuskw'] ?? 0) === 1, json_encode($counts));
$check('known flags first, others after', array_key_last($counts) === 'http_404', json_encode(array_keys($counts)));

$html = $render(array());
$check('the screen shows every page', substr_count($html, 'class="kop-seoa-card"') === 4);
$check('the status line counts flagged pages', strpos($html, '4 pages, 3 with something flagged') !== false);
$check('the problem list links each flag', strpos($html, 'flag=sparse_facility') !== false && strpos($html, 'Sparse facility <span class="kop-ak-count">2</span>') !== false);
$check('company tab named "Company" when present; kinds as tabs', strpos($html, '>Facility <span class="kop-ak-count">2</span>') !== false);
$check('text is escaped', strpos($html, 'Some post &lt;b&gt;bold&lt;/b&gt;') !== false && strpos($html, '<b>bold</b>') === false);
$check('a post links to its editor', strpos($html, 'post.php?post=42&amp;action=edit') !== false);
$check('empty Yoast field shows "none"', strpos($html, '<dt>Yoast field</dt><dd><em class="kop-seoa-none">none</em>') !== false);
$check('the trail reads Home > News > Some post', strpos($html, 'Home &gt; News &gt; Some post') !== false);
$check('the duplicate is linked', strpos($html, 'Same description as: <a href="https://kidsoverprofits.org/facility/acme-ranch/"') !== false);
$check('the canonical line only when it is elsewhere', strpos($html, '<dt>Canonical</dt>') === false);

$html = $render(array('flag' => 'dup_desc'));
$check('flag filter narrows the cards', substr_count($html, 'class="kop-seoa-card"') === 1);
$html = $render(array('kind' => 'post', 'flag' => 'sparse_facility'));
$check('nothing matching says so', strpos($html, 'No page matches.') !== false);

$many = array();
for ($i = 0; $i < 120; $i++) $many[] = array('url' => "https://kidsoverprofits.org/facility/f$i/", 'kind' => 'facility', 'title' => "F$i", 'desc' => '', 'flags' => array('no_desc'));
file_put_contents($tmp . '/audit.json', json_encode(array('generated' => '2026-10-20T12:00:00Z', 'rows' => $many)));
$html = $render(array('paged' => '3'));
$check('50 a page, with a pager', substr_count($html, 'class="kop-seoa-card"') === 20 && strpos($html, 'kop-ak-pager') !== false);

if (!empty($args['audit'])) {
    $GLOBALS['kop_seo_audit_path'] = $args['audit'];
    $real = kop_seo_audit_load();
    $check('the real audit loads', is_array($real), $args['audit']);
    if ($real) {
        $t = microtime(true);
        $html = $render(array());
        $check('the real audit renders', substr_count($html, 'class="kop-seoa-card"') === min(50, count($real['rows'])));
        echo '     ' . count($real['rows']) . ' rows, ' . round(microtime(true) - $t, 2) . "s to render\n";
        foreach (kop_seo_audit_flag_counts($real['rows']) as $code => $n) {
            if (!isset(kop_seo_audit_flags()[$code]) && !preg_match('/^http_\d{3}$/', $code)) echo "     unlabelled flag: $code ($n)\n";
        }
    }
}

array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);
echo $fails ? "\n$fails failed\n" : "\nAll passed\n";
exit($fails ? 1 : 0);
