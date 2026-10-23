<?php
/**
 * scripts/test-review-inbox.php checks for the 'program-practices' source
 * (inc/review-inbox/program-practices.php over inc/program-practices.php), on
 * the pipeline's real export (kop-pipeline tmp/doc-read/practices.json, from
 * scripts/doc-text.py export) and the mirror's records: the import adds every
 * line once and a second import adds nothing, every line names a record that
 * exists and a known topic, Add puts exactly the ticked lines on the page
 * (with a "source" link to the page of the document) and leaves the rest off,
 * Undo puts them back to waiting, Leave all off changes no page, the checklist
 * boxes the lines show survive the facility store's save path and come off
 * again exactly, the pencil rewords or takes off a line, the merge tool moves
 * the rows, and every card says what its buttons do. Saving a record needs
 * MySQL and is not run: the tick test works on the document itself.
 */

if (!defined('KOP_DOC_READ_IMPORT_DIR')) {
    define('KOP_DOC_READ_IMPORT_DIR', getenv('KOP_DOC_READ_DIR') ?: dirname(__DIR__, 3) . '/kop-pipeline/tmp/doc-read');
}
require_once dirname(__DIR__, 2) . '/inc/program-practices.php';

$GLOBALS['kop_test_options']['kop_pp_db'] = KOP_PP_DB_VERSION;
$GLOBALS['pdo']->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_program_practices (id INTEGER PRIMARY KEY AUTOINCREMENT, ikey TEXT NOT NULL UNIQUE,
    facility_id INTEGER NOT NULL DEFAULT 0, doc_id INTEGER NOT NULL DEFAULT 0, answer_id INTEGER NOT NULL DEFAULT 0, doc_title TEXT NOT NULL DEFAULT '', doc_url TEXT NOT NULL DEFAULT '',
    page INTEGER NOT NULL DEFAULT 0, topic TEXT NOT NULL DEFAULT '', value TEXT NOT NULL, value_orig TEXT NOT NULL, quote TEXT NOT NULL, why TEXT NOT NULL DEFAULT '',
    year INTEGER NOT NULL DEFAULT 0, checklist TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'pending', batch TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL)");

function kop_rinbox_test_program_practices(array $src, array $item, callable $check) {
    global $wpdb;
    $pdo = $GLOBALS['pdo'];
    $table = kop_pp_table();
    $file = kop_pp_import_file();
    if (!is_readable($file)) {
        $check('program-practices: the pipeline export to test with (' . $file . ')', false);
        return;
    }
    $export = json_decode((string) file_get_contents($file), true);
    $n = count((array) ($export['items'] ?? array()));
    $count = function ($where = '') use ($wpdb, $table) { return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}" . ($where ? " WHERE {$where}" : '')); };
    $check('program-practices: the import holds every exported line once', $count() === $n, $count() . ' rows, ' . $n . ' in the file');
    $check('program-practices: a second import adds nothing', kop_pp_import(true) === 0 && $count() === $n);

    // Every line: a record that exists, a known topic, a link to its page of the document, the quote.
    $topics = kop_pp_topics();
    $bad = array();
    $exists = $pdo->prepare('SELECT COUNT(*) FROM facilities_v2 WHERE id = ?');
    foreach (kop_pp_rows(array()) as $r) {
        $exists->execute(array((int) $r['facility_id']));
        if (!$exists->fetchColumn()) $bad[] = $r['id'] . ': no record #' . $r['facility_id'];
        if (!isset($topics[$r['topic']])) $bad[] = $r['id'] . ': topic ' . $r['topic'];
        if (strpos(kop_pp_page_url($r), 'https://kidsoverprofits.org/wp-content/uploads/') !== 0) $bad[] = $r['id'] . ': link ' . $r['doc_url'];
        if (trim($r['quote']) === '') $bad[] = $r['id'] . ': no quote';
    }
    $check('program-practices: every line names a record, a topic, its page of our copy and its quote', !$bad, implode(' | ', array_slice($bad, 0, 5)));

    if (!kop_rinbox_test_options_persist()) {
        $check('program-practices: options persist in this harness (needed for the decisions)', false);
        return;
    }

    // A waiting card with several lines. Saving a record needs MySQL: this card's lines tick no boxes here.
    $card = null;
    foreach (kop_rinbox_pp_list(array('view' => 'waiting', 'search' => '', 'offset' => 0, 'limit' => 200))['items'] as $it) {
        if (count($it['checklist'] ?? array()) >= 4) { $card = $it; break; }
    }
    if (!$card) {
        $check('program-practices: a waiting card with 4+ lines to test with', false);
        return;
    }
    list($fid, $topic) = explode(':', $card['key'], 2);
    $wpdb->query($wpdb->prepare("UPDATE {$table} SET checklist = '' WHERE facility_id = %d AND topic = %s", (int) $fid, $topic));
    $check('program-practices: a card lists its lines ticked, each linked to its page with the quote',
        !array_filter($card['checklist'], function ($e) { return !$e['checked'] || strpos($e['url'], '#page=') === false || $e['note'] === ''; }));
    $check('program-practices: a waiting card offers Add the ticked lines and Leave all off, each with its help',
        array_column($card['actions'], 'id') === array('add', 'leave') && !array_filter($card['actions'], function ($a) { return empty($a['help']); }),
        json_encode(array_column($card['actions'], 'id')));

    $ids = array_map(function ($e) { return $e['keys'][0]; }, $card['checklist']);
    $picked = array_slice($ids, 0, 2);
    $msg = kop_rinbox_pp_act($card['key'], 'add', array('picked' => $picked))['message'];
    $added = kop_pp_for_facility((int) $fid)[$topic] ?? array();
    $check('program-practices: Add puts exactly the ticked lines on the page and leaves the rest off',
        array_map('intval', array_column($added, 'id')) == array_map('intval', $picked) || (count($added) === 2 && !array_diff(array_column($added, 'id'), $picked))
        && $count("facility_id = " . (int) $fid . " AND topic = '" . $topic . "' AND status = 'left'") === count($ids) - 2, $msg);
    $html = kop_pp_section_html(array($topic => $added));
    $check('program-practices: the section prints each line with a "source" link to its page',
        strpos($html, esc_html($added[0]['value'])) !== false && strpos($html, '>source</a>') !== false && strpos($html, '#page=') !== false
        && (!function_exists('kop_ie_attr') || kop_ie_attr('pp:1') === '' || strpos($html, 'data-kop-edit="pp:') !== false), substr($html, 0, 300));
    $after = kop_rinbox_pp_item((int) $fid, $topic);
    $check('program-practices: the card moves to Added, where Undo is offered', $after['status'] === 'added' && in_array('undo', array_column($after['actions'], 'id'), true));
    $msg = kop_rinbox_pp_act($card['key'], 'undo', array())['message'];
    $check('program-practices: Undo puts every line of the card back to waiting and off the page',
        $count("facility_id = " . (int) $fid . " AND topic = '" . $topic . "' AND status = 'pending'") === count($ids) && empty(kop_pp_for_facility((int) $fid)[$topic]), $msg);
    try {
        kop_rinbox_pp_act($card['key'], 'add', array('picked' => array()));
        $check('program-practices: Add with nothing ticked is refused', false);
    } catch (RuntimeException $e) {
        $check('program-practices: Add with nothing ticked is refused', $count("facility_id = " . (int) $fid . " AND topic = '" . $topic . "' AND status <> 'pending'") === 0, $e->getMessage());
    }
    kop_rinbox_pp_act($card['key'], 'leave', array());
    $check('program-practices: Leave all off changes no page', empty(kop_pp_for_facility((int) $fid)[$topic]) && kop_rinbox_pp_item((int) $fid, $topic)['status'] === 'left');
    kop_rinbox_pp_act($card['key'], 'undo', array());

    // The pencil: reword a line, refuse an empty one, take one off.
    kop_pp_apply((int) $fid, $topic, $picked, 'inbox-test');
    $line = (int) $picked[0];
    kop_pp_ie_save(array($line), array('value' => 'Reworded line.'));
    $check('program-practices: the pencil rewords a line, keeping what the reader wrote', kop_pp_rows(array('id' => $line))[0]['value'] === 'Reworded line.'
        && kop_pp_rows(array('id' => $line))[0]['value_orig'] !== 'Reworded line.');
    $refused = '';
    try { kop_pp_ie_save(array($line), array('value' => '  ')); } catch (RuntimeException $e) { $refused = $e->getMessage(); }
    $check('program-practices: the pencil refuses an empty line', $refused !== '');
    kop_pp_ie_save(array($line), array('value' => 'x', 'off' => true));
    $check('program-practices: the pencil takes a line off the page', kop_pp_rows(array('id' => $line))[0]['status'] === 'left'
        && count(kop_pp_for_facility((int) $fid)[$topic] ?? array()) === 1);
    kop_rinbox_pp_act($card['key'], 'undo', array());

    // Checklist boxes: every key a line shows survives the store's save path, and unticking gives the document back.
    $keys = array();
    foreach (kop_pp_rows(array()) as $r) foreach (array_filter(explode(',', (string) $r['checklist'])) as $k) $keys[$k] = true;
    $keys = array_keys($keys);
    $st = $pdo->prepare('SELECT json_data FROM facilities_v2 WHERE id = ?');
    $st->execute(array((int) kop_pp_rows(array())[0]['facility_id']));
    $doc = json_decode((string) $st->fetchColumn(), true);
    $orig = $doc;
    $on = kop_pp_doc_set($doc, $keys, true);
    $saved = kop_facility_normalize(kop_facility_to_legacy($doc));
    $lost = array_values(array_filter($on, function ($k) use ($saved) { return !kop_pp_doc_has($saved, $k); }));
    $check('program-practices: the boxes lines tick survive the facility store\'s save path', $keys && !$lost, 'lost: ' . implode(', ', $lost) . ' of ' . implode(', ', $keys));
    kop_pp_doc_set($doc, $on, false);
    $check('program-practices: unticking exactly what was ticked gives the document back', json_encode($doc) === json_encode($orig)
        || kop_facility_normalize(kop_facility_to_legacy($doc)) == kop_facility_normalize(kop_facility_to_legacy($orig)));
    $check('program-practices: only boxes a reader may tick are ticked', kop_pp_doc_set($doc, array('ttiPractices.hasNothing', 'staff.x'), true) === array());

    // The merge tool moves the rows of a merged-away record.
    $merge = (string) file_get_contents(dirname(__DIR__, 2) . '/inc/facility-merge.php');
    $check('program-practices: Merge Duplicates moves the lines (kop_fmerge_ref_tables)', strpos($merge, "'kop_program_practices', 'c' => 'facility_id'") !== false);

    // Every waiting card says what its buttons do.
    $missing = array();
    foreach (kop_rinbox_pp_list(array('view' => 'waiting', 'search' => '', 'offset' => 0, 'limit' => 50))['items'] as $it) {
        foreach ($it['actions'] as $a) if (empty($a['help'])) $missing[] = $it['key'] . ': ' . $a['id'];
    }
    $check('program-practices: every waiting card says what its buttons do', !$missing, implode(' | ', array_slice($missing, 0, 5)));
}
