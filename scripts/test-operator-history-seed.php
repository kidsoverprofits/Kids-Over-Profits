<?php
/**
 * The operator history seed (kop_operator_history_apply_seed() in
 * inc/operator-history.php) on a throwaway copy of the two company tables
 * from tmp/prod.sqlite: fills empty records as drafts, replaces a seeded
 * draft nobody edited (also one from an older seed, by previous_hashes),
 * and never touches an edited or published history.
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite \
 *       scripts/test-operator-history-seed.php
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$prod = dirname(__DIR__) . '/tmp/prod.sqlite';
if (!file_exists($prod)) {
    fwrite(STDERR, "No mirror at $prod (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}
$db_path = sys_get_temp_dir() . '/kop-operator-seed-' . getmypid() . '.sqlite';
@unlink($db_path);
$copy = new PDO('sqlite:' . $db_path);
$copy->exec("ATTACH DATABASE " . $copy->quote($prod) . " AS prod");
foreach (array('wpdl_kop_operators', 'wpdl_kop_operator_facilities') as $t) {
    $copy->exec("CREATE TABLE $t AS SELECT * FROM prod.$t");
}
$copy->exec('DETACH DATABASE prod');
$copy = null;

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/operator-history.php';

// The harness $wpdb reads only; the seed writes one row at a time.
$GLOBALS['wpdb'] = $wpdb = new class($pdo) extends wpdb {
    private $db;
    public function __construct(PDO $pdo) { parent::__construct($pdo); $this->db = $pdo; }
    public function update($table, $data, $where) {
        $set = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($data)));
        $cond = implode(' AND ', array_map(function ($k) { return "$k = ?"; }, array_keys($where)));
        $st = $this->db->prepare("UPDATE $table SET $set WHERE $cond");
        $st->execute(array_merge(array_values($data), array_values($where)));
        return $st->rowCount();
    }
};

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};
$op = function ($name) use ($wpdb) {
    $id = (int) kop_operator_pages_index(true)['names'][kop_facility_pages_name_key($name)];
    return array($id, json_decode((string) $wpdb->get_var("SELECT json_data FROM wpdl_kop_operators WHERE id = $id"), true));
};
$put = function ($id, array $json) use ($wpdb) {
    $wpdb->update('wpdl_kop_operators', array('json_data' => json_encode($json)), array('id' => $id));
};
$write_seed = function (array $companies) {
    $path = sys_get_temp_dir() . '/kop-operator-seed-' . getmypid() . '.json';
    file_put_contents($path, json_encode(array('companies' => $companies)));
    return $path;
};

$seed = json_decode((string) file_get_contents(dirname(__DIR__) . '/seeds/operator-histories.json'), true);
$check('the seed has companies', !empty($seed['companies']));
$by_name = array();
foreach ($seed['companies'] as $c) $by_name[$c['name']] = $c;
$names = array_slice(array_keys($by_name), 0, 4);
list($a, $b, $c, $d) = $names;

// Any history the live mirror already holds is cleared, so the run starts empty.
foreach ($names as $n) {
    list($id, $json) = $op($n);
    unset($json['operator']['history'], $json['operator']['historyStatus'], $json['operator']['historySeedHash'], $json['operator']['historySources'], $json['operator']['historyReviewNotes']);
    $put($id, $json);
}

// 1. Empty records fill as drafts.
$report = kop_operator_history_apply_seed($write_seed(array_map(function ($n) use ($by_name) { return $by_name[$n]; }, $names)));
$check('empty records are filled', count(array_filter($report, function ($r) { return $r === 'filled'; })) === 4, json_encode($report));
list($id_a, $ja) = $op($a);
$check('a filled history is a draft with its notes and fingerprint',
    $ja['operator']['historyStatus'] === 'draft'
    && $ja['operator']['historySeedHash'] === kop_operator_history_hash($by_name[$a]['history'])
    && $ja['operator']['historyReviewNotes'] === trim((string) $by_name[$a]['reviewer_notes']));
$check('a draft is hidden from readers and shown to admins',
    kop_operator_history_written($ja['operator'], false) === null && kop_operator_history_written($ja['operator'], true) !== null);

// 2. The same seed again changes nothing.
$report = kop_operator_history_apply_seed($write_seed(array($by_name[$a])));
$check('the same seed again leaves the draft', ($report[$a] ?? '') === 'same', json_encode($report));

// 3. A newer seed replaces an untouched draft.
$newer = $by_name[$a];
$newer['history'][0] .= ' A quoted finding.';
$report = kop_operator_history_apply_seed($write_seed(array($newer)));
list(, $ja) = $op($a);
$check('a newer seed replaces an untouched draft', ($report[$a] ?? '') === 'replaced' && end($ja['operator']['history']) !== false && $ja['operator']['history'][0] === $newer['history'][0], json_encode($report));

// 4. A draft from an older seed (no fingerprint) is replaced through previous_hashes.
list($id_b, $jb) = $op($b);
unset($jb['operator']['historySeedHash']);
$put($id_b, $jb);
$newer_b = $by_name[$b];
$newer_b['history'][] = 'One more paragraph.';
$report = kop_operator_history_apply_seed($write_seed(array($newer_b)));
$check('without previous_hashes an unfingerprinted draft is kept', ($report[$b] ?? '') === 'kept', json_encode($report));
$newer_b['previous_hashes'] = array(kop_operator_history_hash($by_name[$b]['history']));
$report = kop_operator_history_apply_seed($write_seed(array($newer_b)));
list(, $jb) = $op($b);
$check('an older seed\'s draft is replaced through previous_hashes', ($report[$b] ?? '') === 'replaced' && count($jb['operator']['history']) === count($newer_b['history']), json_encode($report));

// 5. A draft a person edited stays.
list($id_c, $jc) = $op($c);
$jc['operator']['history'][0] = 'Edited by the owner.';
$put($id_c, $jc);
$newer_c = $by_name[$c];
$newer_c['history'][0] .= ' Changed.';
$newer_c['previous_hashes'] = array(kop_operator_history_hash($by_name[$c]['history']));
$report = kop_operator_history_apply_seed($write_seed(array($newer_c)));
list(, $jc) = $op($c);
$check('an edited draft is kept', ($report[$c] ?? '') === 'kept' && $jc['operator']['history'][0] === 'Edited by the owner.', json_encode($report));

// 6. A published history stays, even unedited.
list($id_d, $jd) = $op($d);
$jd['operator']['historyStatus'] = 'published';
$put($id_d, $jd);
$newer_d = $by_name[$d];
$newer_d['history'][0] .= ' Changed.';
$report = kop_operator_history_apply_seed($write_seed(array($newer_d)));
list(, $jd) = $op($d);
$check('a published history is kept', ($report[$d] ?? '') === 'kept' && $jd['operator']['historyStatus'] === 'published', json_encode($report));

// The Sequel rewrite (seed v11) replaces the draft on production: v7 with the four pull quotes added on the page.
$sequel = $by_name['Sequel TSI'] ?? null;
$report = $sequel ? kop_operator_history_apply_seed($write_seed(array($sequel))) : array();
list(, $js) = $sequel ? $op('Sequel TSI') : array(null, array());
$check('the Sequel rewrite replaces the draft with the pull quotes added on the page', ($report['Sequel TSI'] ?? '') === 'replaced'
    && ($js['operator']['history'] ?? null) === $sequel['history'] && ($js['operator']['historyStatus'] ?? '') === 'draft', json_encode($report));
$tables = array_values(array_filter((array) ($sequel['history'] ?? array()), 'kop_operator_history_table_parse'));
$check('the Sequel history has its four tables', count($tables) === 4, (string) count($tables));
foreach ($tables as $t) {
    $p = kop_operator_history_table_parse($t);
    $bad = array_filter($p['rows'], function ($r) use ($p) { return count($r) !== count($p['head']); });
    $check('every row of "' . $p['caption'] . '" has a cell per column', !$bad, json_encode(array_values($bad)));
}

// History tables: "| a | b |" lines, an optional "Table:" caption, a separator line skipped.
$t = kop_operator_history_table_parse("Table: Who paid\n| Year | What |\n|---|---|\n| 2017 | [Alaris](https://example.org/a) <b>x</b> |");
$check('a table paragraph is parsed', $t && $t['caption'] === 'Who paid' && $t['head'] === array('Year', 'What') && count($t['rows']) === 1, json_encode($t));
$check('prose and pull quotes are not tables', kop_operator_history_table_parse('A | B') === null && kop_operator_history_table_parse("> | quoted |") === null
    && kop_operator_history_table_parse("| only a head |") === null);
$html = kop_operator_history_table_html($t);
$check('a table is escaped with its links kept', strpos($html, '<caption>Who paid</caption>') !== false && strpos($html, '&lt;b&gt;') !== false
    && strpos($html, '<a href="https://example.org/a"') !== false && strpos($html, '<th scope="row">2017</th>') !== false, $html);
$check('a table reads as plain lines in the app', kop_operator_history_plain("Table: Who paid\n| Year | What |\n|---|---|\n| 2017 | Alaris |") === "Who paid\nYear: 2017; What: Alaris");
$check('the paragraphs draw a table where its paragraph is', strpos(kop_operator_history_paragraphs_html(array('Before.', "| A | B |\n| 1 | 2 |"), 'o', 1), '<table class="kop-op-history-table">') !== false);

// Windows keeps the file open until the process ends.
register_shutdown_function(function () use ($db_path) {
    try { @unlink($db_path); } catch (Throwable $e) {}
});
echo $failures ? "\n$failures failure(s).\n" : "\nAll checks passed.\n";
exit($failures ? 1 : 0);
