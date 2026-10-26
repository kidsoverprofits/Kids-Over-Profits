<?php
/**
 * scripts/test-review-inbox.php checks for the 'writeups' source
 * (inc/review-inbox/writeups.php over inc/record-writeups.php), on the
 * mirror's real Woodbury Facts items and the made-up Fornits thread
 * (scripts/review-inbox-tests/fornits.php): approving a fact holds it and
 * leaves the record as it was; the record's write-up is drafted (a made-up
 * AI), edited and approved, which writes the facts on and makes the text
 * the page's About; Undo takes all of it back. Everything runs on the
 * scratch copy.
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-review-inbox.php --source=writeups
 */

require_once dirname(__DIR__, 2) . '/inc/closure-reports.php';
require_once dirname(__DIR__, 2) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__, 2) . '/inc/profile-notes.php';
$GLOBALS['kop_test_options']['kop_woodbury_facts_db'] = KOP_WOODBURY_FACTS_DB_VERSION;
$GLOBALS['kop_test_options']['kop_closure_reports_db'] = KOP_CLOSURE_REPORTS_DB_VERSION;
$GLOBALS['kop_test_options']['kop_record_writeups_db'] = KOP_RECORD_WRITEUPS_DB_VERSION;

(function () {
    $pdo = $GLOBALS['pdo'];
    $pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_record_writeups (id INTEGER PRIMARY KEY AUTOINCREMENT, facility_id INTEGER NOT NULL DEFAULT 0,
        draft TEXT NULL, published TEXT NULL, updated_at TEXT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS wpdl_kop_record_writeup_log (id INTEGER PRIMARY KEY AUTOINCREMENT, facility_id INTEGER NOT NULL DEFAULT 0,
        before_row TEXT NULL, items TEXT NULL, done_by TEXT NULL, done_at TEXT NULL, undone_at TEXT NULL)");
    $t = 'wpdl_kop_woodbury_facts';
    if ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = '$t'")->fetchColumn()) {
        $cols = array_column($pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('ya', $cols, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN ya INTEGER NOT NULL DEFAULT 0");
        if (!in_array('ya_why', $cols, true)) $pdo->exec("ALTER TABLE $t ADD COLUMN ya_why TEXT NULL");
    }
})();

/** A staff list as JSON with its keys sorted and empty lists dropped: a save reorders keys and adds empty groups. */
function kop_rinbox_test_writeups_canon($v) {
    $f = function ($x) use (&$f) {
        if (!is_array($x)) return $x;
        $out = array();
        foreach ($x as $k => $y) {
            $y = $f($y);
            if ($y === array() || $y === null) continue;
            $out[$k] = $y;
        }
        if (array_keys($out) !== range(0, count($out) - 1)) ksort($out);
        return $out;
    };
    return json_encode($f($v));
}

function kop_rinbox_test_writeups_units(callable $check) {
    $urls = array(1 => 'https://a.test/1', 2 => 'https://b.test/2', 3 => '');
    $check('writeups: markers become source links before the full stop, a number with no link is dropped',
        kop_wu_cite_markers('It opened in 1998. [1][2] It closed. [3]', $urls) === 'It opened in 1998 ([source](https://a.test/1); [source](https://b.test/2)). It closed.',
        kop_wu_cite_markers('It opened in 1998. [1][2] It closed. [3]', $urls));
    $s = kop_wu_paragraph_sentences('It opened in 1998 ([source](https://a.test/1); [source](https://b.test/2)). It closed ([source](https://c.test/3)). Then nothing.');
    $check('writeups: a cited paragraph reads as the About\'s sentences, each with its sources',
        count($s) === 3 && $s[0]['text'] === 'It opened in 1998.' && count($s[0]['sources']) === 2 && $s[1]['sources'][0]['url'] === 'https://c.test/3'
        && $s[2]['text'] === 'Then nothing.' && !$s[2]['sources'], json_encode($s));
    $check('writeups: paragraphs from text split on blank lines', kop_wu_paragraphs_from_text("One\nline.\n\n\nTwo.") === array('One line.', 'Two.'));
}

function kop_rinbox_test_writeups(array $src, $first, callable $check) {
    kop_rinbox_test_writeups_from_empty($src, $check);
}

function kop_rinbox_test_writeups_from_empty(array $src, callable $check) {
    $pdo = $GLOBALS['pdo'];
    $t = 'wpdl_kop_woodbury_facts';
    kop_rinbox_test_writeups_units($check);
    $check('writeups: approving holds facts (the switch is on)', kop_fact_hold_on());

    // A record with two waiting staff items the record does not hold, no conflict.
    $opts = kop_wbf_opts();
    $pick = null;
    foreach ($pdo->query("SELECT facility_id, COUNT(*) AS n FROM $t WHERE status = 'pending' AND op = 'add_staff' AND facility_id > 0
            AND (conflict IS NULL OR conflict = '') GROUP BY facility_id HAVING n >= 2 ORDER BY facility_id LIMIT 60")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $stored = kop_facility_load((int) $c['facility_id'], $opts);
        if (!$stored) continue;
        $rows = array();
        foreach ($pdo->query("SELECT * FROM $t WHERE status = 'pending' AND op = 'add_staff' AND facility_id = " . (int) $c['facility_id'] . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $trial = $stored['doc'];
            try { kop_wbf_doc_apply($trial, $r); $rows[] = $r; } catch (Throwable $e) { continue; }
            if (count($rows) === 2) break;
        }
        if (count($rows) === 2) { $pick = array((int) $c['facility_id'], $rows); break; }
    }
    if (!$pick) {
        $check('writeups: a record with two new staff items exists in the mirror', false);
        return;
    }
    list($fid, $rows) = $pick;
    $doc0 = kop_facility_load($fid, $opts)['doc'];
    $json0 = kop_rinbox_test_writeups_canon($doc0['staff'] ?? null);
    $wbf = kop_rinbox_source('woodbury-facts');

    // Approve: held, the record unchanged.
    $m = call_user_func($wbf['act'], $rows[0]['pkey'], 'apply', array());
    $r0 = kop_wbf_rows(array($rows[0]['pkey']))[0];
    $check('writeups: Add on a Woodbury item holds it for the write-up', $r0['status'] === 'held' && (int) $r0['applied_fid'] === $fid && strpos($m['message'], 'held') !== false, $m['message']);
    $check('writeups: ...and leaves the record as it was', kop_rinbox_test_writeups_canon(kop_facility_load($fid, $opts)['doc']['staff'] ?? null) === $json0);
    call_user_func($wbf['act'], $rows[1]['pkey'], 'apply', array());
    $held = kop_wu_held_rows($fid);
    $check('writeups: the record\'s held facts are listed', count($held) === 2, count($held) . ' held');
    $will = json_encode(kop_wu_doc_with_held($doc0, $fid)['staff'] ?? null);
    $names = array_map(function ($r) { return (string) (kop_wbf_row_value($r)['name'] ?? ''); }, $rows);
    $check('writeups: the record as it will be has both people', strpos($will, trim(json_encode($names[0]), '"')) !== false
        && strpos($will, trim(json_encode($names[1]), '"')) !== false, implode(', ', $names));
    // The same item approved again is refused (it is no longer waiting); the held tab and its card offer Undo.
    $it = kop_rinbox_get_item('woodbury-facts', $rows[0]['pkey']);
    $check('writeups: a held item\'s card says so and offers Undo', $it['status'] === 'held' && kop_rinbox_test_own_actions($it) === array('undo'), $it['status_label']);
    $tab = call_user_func($wbf['list'], array('view' => 'held', 'search' => '', 'offset' => 0, 'limit' => 50, 'filters' => array()));
    $check('writeups: the "Approved, held" tab lists them', $tab['total'] >= 2);
    $check('writeups: the held facts are for admins only on the page', kop_wu_admin_html($fid) === '' && strpos(kop_wu_admin_html($fid, true), 'Waiting for the write-up') !== false
        && strpos(kop_wu_admin_html($fid, true), htmlspecialchars($names[0], ENT_QUOTES)) !== false);

    // The queue lists the record; no draft yet, so no Approve.
    $ids = kop_rinbox_wu_ids('waiting', '');
    $check('writeups: the record waits in the queue', in_array($fid, $ids, true), count($ids) . ' records');
    // "One record" gathers the write-up card with the record's other waiting cards, write-up first.
    // Every queue's waiting view is read: about 160 MB at its peak on the mirror (the server allows 512 MB).
    @ini_set('memory_limit', '512M');
    $rec = kop_rinbox_record_items('facility', $fid, 120);
    $check('writeups: "One record" lists the record\'s write-up first', ($rec['groups'][0]['source'] ?? '') === 'writeups'
        && (string) ($rec['groups'][0]['items'][0]['key'] ?? '') === (string) $fid && ($rec['groups'][0]['items'][0]['source'] ?? '') === 'writeups',
        json_encode(array_map(function ($g) { return $g['source'] . ':' . count($g['items']); }, $rec['groups'])) . ' skipped: ' . implode(', ', $rec['skipped']));
    $card = kop_rinbox_get_item('writeups', (string) $fid);
    $check('writeups: with no draft the card offers "Write it with AI" and "Send to Claude"', array_values(array_diff(array_column($card['actions'], 'id'), KOP_RINBOX_HOMES_ACTIONS)) === array('write', 'claude'), implode(',', array_column($card['actions'], 'id')));
    try {
        kop_wu_publish($fid, 'tester');
        $check('writeups: nothing to approve without a draft', false);
    } catch (RuntimeException $e) {
        $check('writeups: nothing to approve without a draft', strpos($e->getMessage(), 'no write-up') !== false, $e->getMessage());
    }

    // Send to Claude: the hourly run leaves it alone; the session reads the prompt and sends back the JSON.
    call_user_func($src['act'], (string) $fid, 'claude', array());
    $card = kop_rinbox_get_item('writeups', (string) $fid);
    $check('writeups: a record sent to Claude says so and can be taken back', strpos($card['subtitle'], 'Sent to Claude') !== false
        && in_array('unclaude', array_column($card['actions'], 'id'), true), $card['subtitle']);
    $asked = array();
    $GLOBALS['kop_wu_ai'] = function ($p) use (&$asked) { $asked[] = $p; throw new RuntimeException('stub'); };
    kop_wu_run_batch(500);
    $check('writeups: the hourly run skips a record sent to Claude', !array_filter($asked, function ($p) use ($names) { return strpos($p, $names[0]) !== false; }));
    $listed = array_values(array_filter(kop_wu_claude_list(), function ($r) use ($fid) { return $r['facility_id'] === $fid; }));
    $check('writeups: Claude\'s list carries the record\'s prompt', $listed && strpos($listed[0]['prompt'], 'New facts approved for this page') !== false && $listed[0]['held'] >= 2);
    preg_match_all('/^\[(\d+)\] Staff: /m', $listed[0]['prompt'] ?? '', $nums);
    kop_wu_claude_save($fid, array('paragraphs' => array('Two people worked there. [' . implode('][', $nums[1]) . ']'), 'check' => ''));
    $wu = kop_wu_get($fid);
    $check('writeups: Claude\'s answer is the cited draft, by Claude, off the list', ($wu['draft']['provider'] ?? '') === 'claude'
        && substr_count($wu['draft']['paragraphs'][0] ?? '', '[source](') >= 1 && !isset(kop_wu_claude_queue()[$fid]), json_encode($wu['draft']));
    try {
        kop_wu_claude_save($fid, '{"paragraphs":["x"]}');
        $check('writeups: an answer for a record not on the list is refused', false);
    } catch (RuntimeException $e) {
        $check('writeups: an answer for a record not on the list is refused', strpos($e->getMessage(), 'not sent to Claude') !== false);
    }
    $wu0 = kop_wu_get($fid);
    kop_wu_put($fid, array(), $wu0['published']);
    update_option('kop_wu_failed', array(), false);

    // A record changed straight away (no held facts) waits for a write-up too; the write-up's own save does not count.
    $held_ids = kop_wu_held_counts();
    $other = 0;
    foreach ($GLOBALS['pdo']->query('SELECT id FROM facilities_v2 ORDER BY id LIMIT 400')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (!isset($held_ids[(int) $id]) && (int) $id !== $fid) { $other = (int) $id; break; }
    }
    update_option('kop_wu_changed', array(), false);
    kop_wu_on_change('facility', array($other), array('source' => 'writeup'));
    $check('writeups: the write-up\'s own save is not a change', !isset(kop_wu_changed()[$other]));
    kop_wu_on_change('facility', array($other), array());
    $check('writeups: a direct change puts a record with no held facts in the queue', isset(kop_wu_changed()[$other]) && in_array($other, kop_rinbox_wu_ids('waiting', ''), true));
    $card = kop_rinbox_get_item('writeups', (string) $other);
    $check('writeups: its card says the record changed and offers a draft', strpos($card['text'], 'The record changed on') !== false
        && in_array('write', array_column($card['actions'], 'id'), true), $card['subtitle']);
    $seen = '';
    $GLOBALS['kop_wu_ai'] = function ($p) use (&$seen) { $seen = $p; return '{"paragraphs":["It is a program. [1]"],"check":""}'; };
    kop_wu_write($other);
    $check('writeups: the draft of a changed record is written from what the record says now', strpos($seen, 'What the record says now') !== false
        && strpos($seen, 'None: the record itself changed.') !== false, mb_substr($seen, -400));
    $wu = kop_wu_get($other);
    $check('writeups: a draft written after the change is not stale', !kop_wu_changed_since_draft($other, $wu['draft']));
    kop_wu_publish($other, 'tester');
    $check('writeups: approving takes the record off the changed list', !isset(kop_wu_changed()[$other]) && !in_array($other, kop_rinbox_wu_ids('waiting', ''), true));
    $log = (int) (kop_wu_get($other)['published']['log'] ?? 0);
    if ($log) kop_wu_undo($log, 'tester');
    update_option('kop_wu_changed', array(), false);

    // A made-up AI: one sentence per new fact, citing its number.
    $seen = '';
    $GLOBALS['kop_wu_ai'] = function ($prompt) use (&$seen) {
        $seen = $prompt;
        preg_match_all('/^\[(\d+)\] Staff: [^:]*: ([^:\n(]+)/m', $prompt, $m);
        $paras = array();
        foreach ($m[1] as $i => $n) $paras[] = trim($m[2][$i]) . ' worked at the program. [' . $n . ']';
        return '```json' . "\n" . json_encode(array('paragraphs' => array(implode(' ', $paras)), 'check' => 'Check the roles.')) . "\n```";
    };
    $m = call_user_func($src['act'], (string) $fid, 'write', array());
    $wu = kop_wu_get($fid);
    $check('writeups: the prompt carries the facts with their quotes and the rules', strpos($seen, 'New facts approved for this page') !== false && strpos($seen, 'Restraint is restraint') !== false
        && substr_count($seen, ' Staff: ') >= 2);
    $check('writeups: "Write it with AI" stores a cited draft', count($wu['draft']['paragraphs'] ?? array()) === 1 && strpos($wu['draft']['paragraphs'][0], '([source](') !== false
        && $wu['draft']['check'] === 'Check the roles.' && count($wu['draft']['held']) === 2, json_encode($wu['draft']['paragraphs'] ?? null));
    $card = kop_rinbox_get_item('writeups', (string) $fid);
    $publish = array_column($card['actions'], null, 'id')['publish'] ?? array();
    $check('writeups: the card now offers Approve, sending the editor\'s text with it', !empty($publish['with_fields']) && $card['fields'][0]['name'] === 'writeup'
        && strpos($card['text'], 'Draft write-up') !== false);
    $check('writeups: still nothing on the record before approval', kop_rinbox_test_writeups_canon(kop_facility_load($fid, $opts)['doc']['staff'] ?? null) === $json0);

    // Approve with an edit made in the box (not saved first).
    $edited = 'Edited by the reviewer ([source](https://example.test/edit)).' . "\n\n" . $wu['draft']['paragraphs'][0];
    $m = call_user_func($src['act'], (string) $fid, 'publish', array('fields' => array('writeup' => $edited)));
    $doc1 = kop_facility_load($fid, $opts)['doc'];
    $wu = kop_wu_get($fid);
    $after = array_map(function ($r) { return kop_wbf_rows(array($r['pkey']))[0]; }, $rows);
    $check('writeups: Approve writes both facts onto the record', strpos(json_encode($doc1['staff'] ?? null), trim(json_encode($names[0]), '"')) !== false
        && strpos(json_encode($doc1['staff'] ?? null), trim(json_encode($names[1]), '"')) !== false, $m['message']);
    $check('writeups: ...files them as added, tied to the approval', $after[0]['status'] === 'applied' && (int) (json_decode($after[0]['applied'], true)['writeup'] ?? 0) === (int) $wu['published']['log']);
    $check('writeups: ...and the edited text is the published About', ($wu['published']['paragraphs'][0] ?? '') === 'Edited by the reviewer ([source](https://example.test/edit)).' && !$wu['draft']
        && $wu['published']['covered']);
    $check('writeups: the approval says how to undo it', !empty($m['undo']['action']) && $m['undo']['action'] === 'undo_publish' && (int) $m['undo']['params']['log'] === (int) $wu['published']['log']);
    $check('writeups: nothing is held for the record any more', !kop_wu_held_rows($fid) && !in_array($fid, kop_rinbox_wu_ids('waiting', ''), true)
        && in_array($fid, kop_rinbox_wu_ids('published', ''), true));

    // The page's About: the write-up, then a note added since.
    $notes = kop_wu_doc_notes($doc1);
    $build = function (array $lines) { return kop_pnotes_build($lines); };
    $about = kop_wu_page_about($fid, $notes, $build($notes), $build);
    $check('writeups: the About is the write-up, cited', !empty($about['writeup']) && $about['paragraphs'][0][0]['text'] === 'Edited by the reviewer.'
        && $about['paragraphs'][0][0]['sources'][0]['url'] === 'https://example.test/edit' && count($about['paragraphs']) === 2, json_encode($about['paragraphs'][0] ?? null));
    $later = array_merge($notes, array('License: Licensed by the state in 2001 (Example Report, 2001: https://example.test/license)'));
    $about2 = kop_wu_page_about($fid, $later, $build($later), $build);
    $check('writeups: a note added after the write-up still shows under it', count($about2['paragraphs']) === 3, json_encode(end($about2['paragraphs'])));
    $check('writeups: a record with no write-up keeps the notes-built About', kop_wu_page_about(-5, $notes, array('paragraphs' => array('x')), $build) === array('paragraphs' => array('x')));

    // Undo from Recently done: the facts come off, held again; the About is as it was.
    $msg = call_user_func($src['act'], (string) $fid, $m['undo']['action'], $m['undo']['params'])['message'];
    $doc2 = kop_facility_load($fid, $opts)['doc'];
    $wu = kop_wu_get($fid);
    $j2 = kop_rinbox_test_writeups_canon($doc2['staff'] ?? null);
    $at = strspn($j2 ^ $json0, " ");
    $check('writeups: Undo takes the facts off the record', $j2 === $json0, $msg . ' | before: ' . substr($json0, max(0, $at - 80), 260) . ' | after: ' . substr($j2, max(0, $at - 80), 260));
    $check('writeups: ...holds them again, with the draft back and nothing published', count(kop_wu_held_rows($fid)) === 2 && !$wu['published']
        && ($wu['draft']['paragraphs'] ?? null) === kop_wu_paragraphs_from_text($edited));
    try {
        kop_wu_undo((int) $m['undo']['params']['log'], 'tester');
        $check('writeups: an approval is undone once', false);
    } catch (RuntimeException $e) {
        $check('writeups: an approval is undone once', strpos($e->getMessage(), 'already') !== false, $e->getMessage());
    }

    // A held fact sent back from its own queue leaves the write-up; with none left the draft can be dropped.
    foreach ($rows as $r) call_user_func($wbf['act'], $r['pkey'], 'undo', array());
    $check('writeups: Undo on a held item puts it back to waiting, the record untouched', kop_wbf_rows(array($rows[0]['pkey']))[0]['status'] === 'pending'
        && kop_rinbox_test_writeups_canon(kop_facility_load($fid, $opts)['doc']['staff'] ?? null) === $json0 && !kop_wu_held_rows($fid));
    $card = kop_rinbox_get_item('writeups', (string) $fid);
    $check('writeups: a draft with no facts left can be dropped', in_array('drop', array_column($card['actions'], 'id'), true));
    call_user_func($src['act'], (string) $fid, 'drop', array());
    $check('writeups: ...and leaves the queue', !in_array($fid, kop_rinbox_wu_ids('waiting', ''), true));

    // Conflicts settled with "Use this value" are held too.
    $live = null;
    foreach ($pdo->query("SELECT * FROM $t WHERE status = 'pending' AND op = 'set_if_empty' AND facility_id > 0 ORDER BY id LIMIT 4000")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $f = kop_facility_load((int) $r['facility_id'], $opts);
        if ($f && kop_wbf_conflict($f['doc'], $r) !== '') { $live = $r; break; }
    }
    if ($live) {
        $before = json_encode(kop_facility_load((int) $live['facility_id'], $opts)['doc']);
        $msg = kop_wbf_resolve($live, 'use', '', 'tester');
        $r = kop_wbf_rows(array($live['pkey']))[0];
        $check('writeups: "Use this value" on a conflict is held, the record unchanged', $r['status'] === 'held' && json_encode(kop_facility_load((int) $live['facility_id'], $opts)['doc']) === $before, $msg);
        $doc = kop_facility_load((int) $live['facility_id'], $opts)['doc'];
        $h = array('src' => 'wbf', 'row' => $r);
        kop_wu_apply_row($doc, $h);
        $check('writeups: ...and approving the write-up writes the value over the record\'s', kop_wbf_get($doc, $live['path']) == kop_wbf_row_value($live), json_encode(kop_wbf_get($doc, $live['path'])));
        call_user_func($wbf['act'], $live['pkey'], 'undo', array());
    }

    // Fornits: a staff item is held; a discussion link still goes on at once.
    if (function_exists('kop_fornits_items_table')) {
        $fi = kop_fornits_items_table();
        $staff = $pdo->query("SELECT * FROM $fi WHERE kind = 'staff' AND status = 'pending' AND facility_id > 0 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($staff) {
            $ffid = (int) $staff['facility_id'];
            $sj = json_encode(kop_facility_load($ffid, $opts)['doc']['staff'] ?? null);
            $res = kop_fornits_apply(array($staff), array(), 0, 'tester')[$staff['pkey']] ?? array();
            $row = $pdo->query("SELECT status, applied_fid FROM $fi WHERE pkey = " . $pdo->quote($staff['pkey']))->fetch(PDO::FETCH_ASSOC);
            $check('writeups: a Fornits staff item is held, the record unchanged', !empty($res['held']) && $row['status'] === 'held' && (int) $row['applied_fid'] === $ffid
                && json_encode(kop_facility_load($ffid, $opts)['doc']['staff'] ?? null) === $sj, json_encode($res));
            $facts = array_map('kop_wu_fact', kop_wu_held_rows($ffid));
            $check('writeups: ...and joins that record\'s write-up with its post as the source', $facts && strpos(end($facts)['url'], 'fornits') !== false, json_encode(end($facts)));
            kop_fornits_undo(array(array_merge($staff, array('status' => 'held'))), 'tester');
            $check('writeups: Fornits Undo on a held item puts it back', $pdo->query("SELECT status FROM $fi WHERE pkey = " . $pdo->quote($staff['pkey']))->fetchColumn() === 'pending');
        }
        $check('writeups: a discussion link is not a fact to hold', !kop_fornits_is_fact(array('kind' => 'link')) && kop_fornits_is_fact(array('kind' => 'lead'), 'closed')
            && !kop_fornits_is_fact(array('kind' => 'lead'), 'news'));
    }
    unset($GLOBALS['kop_wu_ai']);
}
