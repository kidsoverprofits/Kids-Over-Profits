<?php
/**
 * scripts/test-review-inbox.php checks for the 'nonprofit-filings' source
 * (inc/review-inbox/nonprofit-filings.php over inc/nonprofit-filings.php), on
 * the pipeline's real files in js/data/nonprofit-filings/ and the mirror's
 * records: every organization is well formed and names records that exist,
 * Link puts the returns on the facility page, a second Link adds nothing,
 * "Link to a company" puts them on the company page, Undo takes off exactly
 * the links the card made (and nothing linked by another card), Not this
 * program changes nothing, the merge tool moves the links, and every card
 * says what its buttons do.
 */

require_once dirname(__DIR__, 2) . '/inc/nonprofit-filings.php';

$GLOBALS['pdo']->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_nonprofit_links (id INTEGER PRIMARY KEY AUTOINCREMENT, ein TEXT NOT NULL,
    facility_id INTEGER NOT NULL DEFAULT 0, operator_id INTEGER NOT NULL DEFAULT 0, created_by TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL)');

function kop_rinbox_test_nonprofit_filings(array $src, array $item, callable $check) {
    global $wpdb;
    $cands = kop_npf_candidates();
    $table = kop_npf_table();
    $count = function () use ($wpdb, $table) { return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); };

    // Every organization: a 9-digit EIN, a known match, records that exist, its file when it has returns.
    $bad = array();
    $views = array('matched' => 0, 'check' => 0);
    foreach ($cands as $ein => $e) {
        if (!preg_match('/^\d{9}$/', (string) $ein)) $bad[] = $ein . ': EIN';
        if (!in_array($e['match'] ?? '', array('matched', 'check'), true)) $bad[] = $ein . ': match ' . json_encode($e['match'] ?? null);
        // One a researcher sent in by EIN (kop-pipeline scripts/nonprofit-990-extra.json) may name no record yet.
        $sent_in = (bool) preg_grep('/^Sent in by /', (array) ($e['flags'] ?? array()));
        if (empty($e['candidates']) && !$sent_in) $bad[] = $ein . ': no records';
        foreach ((array) ($e['candidates'] ?? array()) as $c) {
            if (!in_array($c['kind'] ?? '', array('facility', 'operator'), true)) $bad[] = $ein . ': kind ' . json_encode($c['kind'] ?? null);
            elseif (!kop_npf_record_exists($c['kind'], (int) $c['id'])) $bad[] = $ein . ': no ' . $c['kind'] . ' #' . $c['id'];
        }
        if (($e['match'] ?? '') === 'matched' && count($e['candidates']) !== 1) $bad[] = $ein . ': matched to ' . count($e['candidates']) . ' records';
        if (!empty($e['filings'])) {
            $org = kop_npf_org($ein);
            if (!$org) {
                $bad[] = $ein . ': no orgs/' . $ein . '.json';
                continue;
            }
            $years = array_column($org['filings'], 'year');
            $sorted = $years;
            rsort($sorted);
            if ($years !== $sorted) $bad[] = $ein . ': returns not newest first';
            foreach ($org['filings'] as $f) {
                if (!empty($f['pdf']) && strpos((string) $f['pdf'], 'https://') !== 0) $bad[] = $ein . ': return link ' . $f['pdf'];
            }
        }
        $views[kop_rinbox_npf_view($e, array())]++;
    }
    $check('nonprofit-filings: every organization is well formed and names records that exist', !$bad,
        count($cands) . ' organizations, ' . json_encode($views) . ($bad ? '; ' . implode(' | ', array_slice($bad, 0, 6)) : ''));

    $check('nonprofit-filings: EIN and money read as on a return', kop_npf_ein_label('236437695') === '23-6437695'
        && kop_npf_money(5422125) === '$5,422,125' && kop_npf_money(-126) === '-$126' && kop_npf_money(null) === '');

    // A matched organization with returns, linked to its facility.
    $ein = '';
    foreach ($cands as $k => $e) {
        if ($e['match'] === 'matched' && !empty($e['filings']) && ($e['candidates'][0]['kind'] ?? '') === 'facility') { $ein = (string) $k; break; }
    }
    if ($ein === '') {
        $check('nonprofit-filings: a matched organization with returns to test with', false);
        return;
    }
    $fid = (int) $cands[$ein]['candidates'][0]['id'];
    if (!kop_rinbox_test_options_persist()) {
        $check('nonprofit-filings: options persist in this harness (needed for the decisions)', false);
        return;
    }
    $card = kop_rinbox_npf_item($ein, $cands[$ein]);
    $check('nonprofit-filings: a matched card offers Link with its record filled in, Link to a company, Link to a Christian rehab for adults, Not this program, each with its help',
        array_column($card['actions'], 'id') === array('link', 'link_company', 'link_related', 'reject') && (int) $card['actions'][0]['params'][0]['value'] === $fid
        && !array_filter($card['actions'], function ($a) { return empty($a['help']); }), json_encode(array_column($card['actions'], 'id')));

    // The checks below start from no links and no decision on the sample: earlier queues' checks in the same run
    // (or the live site) may have linked organizations in the scratch copy.
    $wpdb->query("DELETE FROM {$table}");
    $d = kop_npf_decisions();
    unset($d[$ein]);
    kop_npf_save_decisions($d);
    $before = $count();
    $msg = kop_npf_apply($ein, 'facility', $fid, 'inbox-test');
    $orgs = kop_npf_for_facility($fid);
    $mine = array_values(array_filter($orgs, function ($o) use ($ein) { return $o['ein'] === $ein; }));
    $check('nonprofit-filings: Link puts the returns on the facility page', $count() === $before + 1 && $mine && $mine[0]['rows'], $msg);
    $html = kop_npf_section_html($mine);
    $check('nonprofit-filings: the section prints the EIN, the years and a link to each return',
        strpos($html, kop_npf_ein_label($ein)) !== false && strpos($html, '<table class="kop-npf-table">') !== false
        && strpos($html, (string) $mine[0]['rows'][0]['year']) !== false && strpos($html, 'Form ' . $mine[0]['rows'][0]['form']) !== false);
    $again = kop_npf_apply($ein, 'facility', $fid, 'inbox-test');
    $check('nonprofit-filings: a second Link to the same record adds nothing', $count() === $before + 1 && strpos($again, 'already') !== false, $again);
    $check('nonprofit-filings: the card moves to Linked, where Undo is offered', kop_rinbox_npf_view($cands[$ein], kop_npf_decisions()[$ein] ?? array()) === 'linked'
        && in_array('undo', array_column(kop_rinbox_npf_item($ein, $cands[$ein])['actions'], 'id'), true));

    // The same organization on a company page too.
    $oid = (int) $wpdb->get_var('SELECT id FROM wpdl_kop_operators ORDER BY id LIMIT 1');
    kop_npf_apply($ein, 'operator', $oid, 'inbox-test');
    $check('nonprofit-filings: Link to a company puts the returns on the company page', (bool) kop_npf_for_operator($oid)
        && count((array) kop_npf_decisions()[$ein]['links']) === 2);

    // Undo takes off this card's links only, not a link another card made.
    $other = '';
    foreach ($cands as $k => $e) if ((string) $k !== $ein) { $other = (string) $k; break; }
    kop_npf_apply($other, 'facility', $fid, 'inbox-test');
    $msg = kop_npf_undo($ein);
    $left = $wpdb->get_col("SELECT ein FROM {$table}");
    $check('nonprofit-filings: Undo takes off exactly the links the card made', $left === array($other) && !isset(kop_npf_decisions()[$ein]), $msg . ' left: ' . json_encode($left));
    $check('nonprofit-filings: after Undo the page no longer lists it', !array_filter(kop_npf_for_facility($fid), function ($o) use ($ein) { return $o['ein'] === $ein; }));
    kop_npf_undo($other);

    // Not this program changes nothing; Back to review; a later Link still works.
    $before = $count();
    kop_npf_reject($ein, 'inbox-test');
    $check('nonprofit-filings: Not this program changes no page', $count() === $before
        && kop_rinbox_npf_view($cands[$ein], kop_npf_decisions()[$ein]) === 'rejected');
    kop_npf_apply($ein, 'facility', $fid, 'inbox-test');
    $check('nonprofit-filings: a left-off organization can still be linked', $count() === $before + 1
        && (kop_npf_decisions()[$ein]['decision'] ?? '') === 'linked');
    kop_npf_undo($ein);
    $refused = '';
    try { kop_npf_apply($ein, 'facility', 999999999, 'inbox-test'); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
    $check('nonprofit-filings: a record that is not on file is refused', $refused !== '' && $count() === $before, $refused);

    // The merge tool moves the links of a merged-away record.
    $merge = (string) file_get_contents(dirname(__DIR__, 2) . '/inc/facility-merge.php');
    $check('nonprofit-filings: Merge Duplicates moves the links (kop_fmerge_ref_tables)', strpos($merge, "'kop_nonprofit_links', 'c' => 'facility_id'") !== false);

    // Every waiting card says what its buttons do.
    $missing = array();
    foreach (array('matched', 'check') as $view) {
        $res = kop_rinbox_npf_list(array('view' => $view, 'search' => '', 'offset' => 0, 'limit' => 20));
        foreach ($res['items'] as $it) {
            foreach ($it['actions'] as $a) if (empty($a['help'])) $missing[] = $it['key'] . ': ' . $a['id'];
        }
    }
    $check('nonprofit-filings: every waiting card says what its buttons do', !$missing, implode(' | ', array_slice($missing, 0, 5)));
}
