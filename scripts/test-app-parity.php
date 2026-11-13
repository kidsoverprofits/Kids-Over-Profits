<?php
/**
 * Website and app in step: every section the facility and company templates print is listed in docs/app-parity.json,
 * mapped to the mobile app's section or marked todo/skip with a reason, and every mapped app section exists in the app.
 *
 *   php scripts/test-app-parity.php            # the app checkout beside this one (../kids-over-profits-mobile)
 *   KOP_APP_DIR=/path/to/app php scripts/test-app-parity.php
 *   php scripts/test-app-parity.php --todo     # also list what the app still lacks
 *
 * Fails when a template prints a section the list does not name (a new website section: port it to the app, or list
 * it as todo/skip), or when a listed app id is missing from the app's section list. Runs in CI (.github/workflows/
 * app-parity.yml) on every push touching the templates, the app API or this list. No database needed.
 */

$root = dirname(__DIR__);
$show_todo = in_array('--todo', $argv ?? array(), true);
$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

$manifest = json_decode((string) @file_get_contents($root . '/docs/app-parity.json'), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "docs/app-parity.json is missing or not valid JSON\n");
    exit(2);
}

/** Section ids a template prints: its jump-list keys, $kop_*_id('x') and literal <section id="x">. */
function kop_parity_template_ids($file, $list_var) {
    $src = (string) file_get_contents($file);
    $ids = array();
    if (preg_match_all('/\$' . preg_quote($list_var, '/') . "\\['([a-z0-9-]+)'\\]/", $src, $m)) $ids = array_merge($ids, $m[1]);
    if (preg_match_all("/\\\$kop_fp_id\\('([a-z0-9-]+)'\\)/", $src, $m)) $ids = array_merge($ids, $m[1]);
    if (preg_match_all('/<section[^>]*\bid="([a-z0-9-]+)"/', $src, $m)) $ids = array_merge($ids, $m[1]);
    $ids = array_values(array_unique($ids));
    sort($ids);
    return $ids;
}

$pages = array(
    'facility' => array('template' => 'templates/facility-page.php', 'var' => 'kop_fp_sections', 'app' => 'src/components/facility/model.ts'),
    'operator' => array('template' => 'templates/operator-page.php', 'var' => 'kop_op_sections', 'app' => 'src/components/operator/model.ts'),
);

$app_dir = getenv('KOP_APP_DIR') ?: dirname($root) . '/kids-over-profits-mobile';
$have_app = is_dir($app_dir . '/src');
$todos = array();

foreach ($pages as $kind => $p) {
    $list = (array) ($manifest[$kind] ?? array());
    $ids = kop_parity_template_ids($root . '/' . $p['template'], $p['var']);
    $check("$kind: the template prints sections", count($ids) > 5, count($ids) . ' found');
    $unlisted = array_values(array_diff($ids, array_keys($list)));
    $check("$kind: every website section is in docs/app-parity.json", !$unlisted,
        $unlisted ? 'new on the website, not in the app list: ' . implode(', ', $unlisted) . ' (port it to the app, or list it as todo/skip)' : '');

    foreach ($list as $id => $row) {
        $ok = is_array($row) && (isset($row['app']) || isset($row['todo']) || isset($row['skip']));
        if (!$ok) $check("$kind: '$id' says app, todo or skip", false);
        if (isset($row['todo'])) $todos[] = "$kind $id: " . $row['todo'];
        if (!isset($row['helper']) && !in_array($id, $ids, true)) {
            $check("$kind: '$id' is still on the website", false, 'listed but the template no longer prints it: take it off the list');
        }
    }

    if (!$have_app) continue;
    $app_src = (string) @file_get_contents($app_dir . '/' . $p['app']);
    $missing = array();
    foreach ($list as $id => $row) {
        if (!isset($row['app'])) continue;
        if (strpos($app_src, "'" . $row['app'] . "'") === false) $missing[] = $id . ' -> ' . $row['app'];
    }
    $check("$kind: every mapped section is in the app's section list ({$p['app']})", !$missing, $missing ? implode(', ', $missing) : '');
}

if (!$have_app) echo "SKIP the app side: no checkout at $app_dir (clone kids-over-profits-mobile beside this repo, or set KOP_APP_DIR)\n";
echo count($todos) . " section(s) not in the app yet" . ($show_todo ? ':' : ' (--todo lists them)') . "\n";
if ($show_todo) foreach ($todos as $t) echo "  TODO $t\n";

echo $failures ? "\n$failures check(s) failed\n" : "\nAll checks passed\n";
exit($failures ? 1 : 0);
