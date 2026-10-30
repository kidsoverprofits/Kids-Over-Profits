<?php
/**
 * scripts/test-review-inbox.php checks for the 'woodbury-reports' source
 * (inc/review-inbox/woodbury-mentions.php over inc/woodbury-mentions.php), on
 * the mirror's real candidates. Filing (media import, FileBird folders, MySQL
 * locks) and Undo (deletes the imported attachment) are not run offline.
 */

require_once dirname(__DIR__, 2) . '/inc/woodbury-mentions.php';
require_once dirname(__DIR__, 2) . '/inc/woodbury-create.php';
$GLOBALS['kop_test_options']['kop_woodbury_mentions_db'] = KOP_WOODBURY_DB_VERSION;

if (!function_exists('esc_sql')) {
    function esc_sql($s) { return str_replace("'", "''", (string) $s); }
}

function kop_rinbox_test_woodbury_reports(array $src, array $item, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $t = 'wpdl_kop_woodbury_mentions';
    $pick = function ($sql) use ($pdo) { return (string) $pdo->query($sql)->fetchColumn(); };

    $key = $pick("SELECT ckey FROM $t WHERE status = 'pending' AND facility_id > 0 AND file <> '' ORDER BY id LIMIT 1");
    $before = kop_wb_get($key);
    $it = kop_rinbox_get_item('woodbury-reports', $key);
    $file = $it['actions'][0];
    $check('woodbury-reports: filing defaults to the best match', $file['id'] === 'file' && (int) ($file['params'][0]['value'][0]['id'] ?? 0) === (int) $before['facility_id']);
    $view = array_values(array_filter($it['links'], function ($l) { return $l['label'] === 'View pages'; }));
    $check('woodbury-reports: the cut pages open in the old viewer', $view && strpos($view[0]['url'], 'action=kop_wb_view') !== false && strpos($view[0]['url'], 'nonce=') !== false);

    // Edit: what it is, and the best match.
    call_user_func($src['save'], $key, array('kind' => 'mention'));
    $check('woodbury-reports: save recategorises', kop_wb_get($key)['kind'] === 'mention');
    $other = (int) $pdo->query('SELECT id FROM facilities_v2 WHERE id <> ' . (int) $before['facility_id'] . " AND name <> '' ORDER BY id LIMIT 1")->fetchColumn();
    call_user_func($src['save'], $key, array('facility_id' => (string) $other));
    $r = kop_wb_get($key);
    $check('woodbury-reports: save changes the best match and its name', (int) $r['facility_id'] === $other && $r['facility_name'] !== '' && $r['facility_name'] !== $before['facility_name'], $r['facility_name']);
    try {
        call_user_func($src['save'], $key, array('kind' => 'poem'));
        $check('woodbury-reports: an unknown kind is refused', false);
    } catch (RuntimeException $e) {
        $check('woodbury-reports: an unknown kind is refused', true, $e->getMessage());
    }

    // Skip and back.
    $m = call_user_func($src['act'], $key, 'skip', array());
    $check('woodbury-reports: skip', kop_wb_get($key)['status'] === 'skipped', $m['message']);
    $it = kop_rinbox_get_item('woodbury-reports', $key);
    $check('woodbury-reports: skipped pages offer Put back', kop_rinbox_test_own_actions($it) === array('reopen'));
    $m = call_user_func($src['act'], $key, 'reopen', array());
    $check('woodbury-reports: put back', kop_wb_get($key)['status'] === 'pending', $m['message']);
    try {
        call_user_func($src['act'], $key, 'undo', array());
        $check('woodbury-reports: undo on waiting pages is refused', false);
    } catch (RuntimeException $e) {
        $check('woodbury-reports: undo on waiting pages is refused', true, $e->getMessage());
    }
    $pdo->prepare("UPDATE $t SET kind = ?, facility_id = ?, facility_name = ?, facility_state = ?, reviewed_by = ?, reviewed_at = ? WHERE ckey = ?")
        ->execute(array($before['kind'], $before['facility_id'], $before['facility_name'], $before['facility_state'], $before['reviewed_by'], $before['reviewed_at'], $key));

    $none = $pick("SELECT ckey FROM $t WHERE status = 'pending' AND facility_id = 0 ORDER BY id LIMIT 1");
    if ($none !== '') {
        try {
            call_user_func($src['act'], $none, 'file', array('facility' => '', 'also' => ''));
            $check('woodbury-reports: filing with no facility is refused', false);
        } catch (RuntimeException $e) {
            $check('woodbury-reports: filing with no facility is refused', true, $e->getMessage());
        }
    }
    $filed = $pick("SELECT ckey FROM $t WHERE status = 'filed' ORDER BY id LIMIT 1");
    if ($filed !== '') {
        $it = kop_rinbox_get_item('woodbury-reports', $filed);
        $undo = array_values(array_filter($it['actions'], function ($a) { return $a['id'] === 'undo'; }))[0] ?? array();
        $check('woodbury-reports: filed pages offer Undo (with a warning), Also file under, and no edits', !empty($undo['confirm'])
            && in_array('add', array_column($it['actions'], 'id'), true)
            && !empty($it['fields'][0]['readonly']) && in_array('Filed under', array_column($it['details'], 'label'), true));
        try {
            call_user_func($src['act'], $filed, 'add', array('facilities' => array()));
            $check('woodbury-reports: "Also file under" with nothing picked is refused', false);
        } catch (RuntimeException $e) {
            $check('woodbury-reports: "Also file under" with nothing picked is refused', true, $e->getMessage());
        }
    }

    // What the old screen also did.
    $it = kop_rinbox_get_item('woodbury-reports', $key);
    $check('woodbury-reports: the cut pages show in the card', ($it['preview']['url'] ?? '') !== '' && strpos($it['preview']['url'], 'action=kop_wb_view') !== false);
    $ids = kop_rinbox_test_own_actions($it);
    $check('woodbury-reports: waiting pages can be filed, filed under another kind of record, created, or skipped',
        $ids === array('file', 'file_record', 'create', 'skip'), implode(',', $ids));
    $file = $it['actions'][0];
    $opt = array_filter($file['params'], function ($p) { return empty($p['optional']); });
    $company = array_values(array_filter($file['params'], function ($p) { return $p['name'] === 'company'; }))[0] ?? array();
    $check('woodbury-reports: File it can run on many cards (every box optional) and finds companies by name', !$opt && ($company['lookup'] ?? '') === 'company');
    // Any record with a document folder: "File under that record" is the any-record finder (no programs there; young
    // adult programs too, their folder made the first time); the Best match stays a program.
    $fr = array_values(array_filter($it['actions'], function ($a) { return $a['id'] === 'file_record'; }))[0] ?? array();
    $rec = array_values(array_filter((array) ($fr['params'] ?? array()), function ($p) { return $p['name'] === 'record'; }))[0] ?? array();
    $kinds = (array) ($rec['kinds'] ?? array());
    $check('woodbury-reports: "File under that record" picks any record with a document folder',
        ($rec['type'] ?? '') === 'record' && in_array('company', $kinds, true) && in_array('consultant', $kinds, true) && in_array('tradeorg', $kinds, true)
        && in_array('ya', $kinds, true) && !in_array('facility', $kinds, true), wp_json_encode($rec));
    $best = array_values(array_filter($it['fields'], function ($f) { return $f['name'] === 'facility_id'; }))[0] ?? array();
    $check('woodbury-reports: the Best match stays a program', ($best['type'] ?? '') === 'facility');
    $check('woodbury-reports: a young adult program can be picked under "File under that record"', kop_rinbox_wb_record('ya:5') === array('ya', 5)
        && kop_rinbox_wb_record('c4') === array('company', 4));
    $ya = array_values(array_filter($file['params'], function ($p) { return $p['name'] === 'ya'; }))[0] ?? array();
    $check('woodbury-reports: File it also takes a young adult program', ($ya['type'] ?? '') === 'record' && ($ya['kinds'] ?? null) === array('ya')
        && !empty($ya['optional']), wp_json_encode($ya));
    $check('woodbury-reports: the generic "File under a young adult program" is not on these cards (the pages go in its folder instead)',
        !in_array('ya_file', array_column($it['actions'], 'id'), true));
    // The ya:<id> token, everywhere File it and the old screen read tokens (inc/woodbury-mentions.php).
    $check('woodbury-reports: "ya:6" names young adult program 6', kop_wb_parse_target('ya:6') === array('ya', 6) && kop_wb_clean_token(' YA:6 ') === 'ya:6'
        && kop_wb_parse_target('ya:0') === null && kop_wb_clean_token('ya:x') === '' && kop_wb_target_token('ya', 6) === 'ya:6'
        && kop_wb_target_token('consultant', 6) === '');
    $place = kop_wb_place(6, 'Test Program', 0, false, 'ya');
    $check('woodbury-reports: a young adult program place links its page', $place['key'] === 'ya:6' && $place['page'] === kop_ya_url(6) && strpos($place['page'], '/related/young-adult-programs/') !== false,
        wp_json_encode($place));
    $consultant = kop_wb_place(0, 'A Consultant', 0, true, 'consultant');
    $check('woodbury-reports: a consultant place still has no page or key', $consultant['page'] === '' && $consultant['key'] === '');
    $also = kop_wb_also(array('also_facilities' => wp_json_encode(array(array('id' => 6, 'kind' => 'ya', 'name' => 'P', 'folder' => 1),
        array('id' => 7, 'kind' => 'odd', 'name' => 'Q', 'folder' => 2)))));
    $check('woodbury-reports: an extra young adult program stays one when read back', $also[0]['kind'] === 'ya' && $also[1]['kind'] === 'facility');
    // A best match moved to the young adult programs (kop_ya_moved_from_map()): its program is picked instead.
    $moved = function_exists('kop_ya_moved_from_map') ? kop_ya_moved_from_map() : array();
    $mkey = $moved ? $pick("SELECT ckey FROM $t WHERE status = 'pending' AND facility_id IN (" . implode(',', array_map('intval', array_keys($moved))) . ") ORDER BY id LIMIT 1") : '';
    if ($mkey === '') {
        kop_rinbox_test_skip('woodbury-reports: a best match moved to the young adult programs files on its program', 'no waiting row names a moved record');
    } else {
        $mr = kop_wb_get($mkey);
        $prog = $moved[(int) $mr['facility_id']];
        $mit = kop_rinbox_get_item('woodbury-reports', $mkey);
        $mfile = $mit['actions'][0];
        $mya = array_values(array_filter($mfile['params'], function ($p) { return $p['name'] === 'ya'; }))[0] ?? array();
        $check('woodbury-reports: a best match moved to the young adult programs files on its program (picked, not the gone record)',
            ($mya['value'] ?? '') === 'ya:' . $prog['id'] && $mfile['params'][0]['value'] === array()
            && strpos($mit['text'], 'now the young adult program ' . $prog['name']) !== false, wp_json_encode($mya));
        $mp = kop_wb_place((int) $mr['facility_id'], $mr['facility_name'], 0, true, 'facility');
        $check('woodbury-reports: pages filed under it before it moved link the program\'s page', $mp['page'] === kop_ya_url($prog['id']), $mp['page']);
    }
    $check('woodbury-reports: names say which are young adult programs', kop_rinbox_wb_names(array($place, kop_wb_place(4, 'Co', 0, false, 'company')))
        === 'Test Program (young adult program), Co (company)');
    $section = $pick("SELECT ckey FROM $t WHERE status = 'pending' AND kind = 'section' AND facility_id > 0 ORDER BY id LIMIT 1");
    $mention = $pick("SELECT ckey FROM $t WHERE status = 'pending' AND kind = 'mention' ORDER BY id LIMIT 1");
    $check('woodbury-reports: articles with a confident match start ticked, mentions do not',
        ($section === '' || kop_rinbox_get_item('woodbury-reports', $section)['selected'] === true)
        && ($mention === '' || kop_rinbox_get_item('woodbury-reports', $mention)['selected'] === false));
    $tokens = kop_rinbox_wb_tokens(array('facilities' => array('12', '0'), 'alt_5' => '1', 'alt_8' => '', 'co_7' => '1', 'company' => 'c9', 'also' => 'company:3 x', 'ya' => 'ya:6'));
    $check('woodbury-reports: ticked boxes and picked companies and programs become filing targets', $tokens === array('12', '5', 'c7', 'c9', 'c3', 'ya:6'), implode(',', $tokens));
    $counts = call_user_func($src['view_counts'], array('search' => ''));
    $waiting = (int) $pdo->query("SELECT COUNT(*) FROM $t WHERE status = 'pending'")->fetchColumn();
    $check('woodbury-reports: every tab has its count', $counts['pending'] === $waiting && count($counts) === 7, json_encode($counts));
    foreach (array(array('create', array('kind' => '', 'name' => 'X')), array('file_record', array('record' => 'nonsense')), array('untag', array('record' => '12'))) as $try) {
        try {
            call_user_func($src['act'], $key, $try[0], $try[1]);
            $check('woodbury-reports: ' . $try[0] . ' without a proper choice is refused', false);
        } catch (RuntimeException $e) {
            $check('woodbury-reports: ' . $try[0] . ' without a proper choice is refused', true, $e->getMessage());
        }
    }
    $hits = call_user_func($src['lookup'], 'company', 'Aspen');
    $check('woodbury-reports: companies are found by name for filing', $hits && preg_match('/^c\d+$/', $hits[0]['value']), $hits ? $hits[0]['label'] : 'none');
    $yname = $pick("SELECT name FROM young_adult_programs WHERE review = 'approved' ORDER BY id LIMIT 1");
    try {
        $hits = $yname === '' ? array() : array_values(array_filter(call_user_func($src['lookup'], 'record', $yname), function ($h) { return strpos($h['value'], 'ya:') === 0; }));
        $check('woodbury-reports: a young adult program is found by name for "File under that record"', (bool) $hits, $hits ? $hits[0]['label'] : 'none for ' . $yname);
    } catch (PDOException $e) {
        kop_rinbox_test_skip('woodbury-reports: a young adult program is found by name for "File under that record"', 'the master tables need MySQL (SHOW TABLES)');
    }
    try {
        $hits = call_user_func($src['lookup'], 'record', 'Aspen');
        $check('woodbury-reports: any non-facility record is found by name', $hits && preg_match('/^(company|consultant|provider|transporter|marketer|tradeorg):\d+$/', $hits[0]['value']), $hits ? $hits[0]['label'] : 'none');
    } catch (PDOException $e) {
        kop_rinbox_test_skip('woodbury-reports: any non-facility record is found by name', 'the master tables need MySQL (SHOW TABLES)');
    }
    $check('woodbury-reports: queue tools are "Load the scan again" and "Remove extra copies"', array_column($src['tools'], 'id') === array('resync', 'dedupe'));
    $m = call_user_func($src['tool'], 'resync', array());
    $check('woodbury-reports: "Load the scan again" answers', $m['message'] !== '', $m['message']);
}
