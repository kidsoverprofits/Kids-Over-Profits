<?php
/**
 * scripts/test-review-inbox.php checks for the 'business-filings' source
 * (inc/review-inbox/business-filings.php over inc/business-filings.php), on the
 * pipeline's real files in js/data/business-filings/<st>/ and the mirror's
 * records: every company is well formed, names records that exist and keeps no
 * person's street address; Link shows the filing on the facility page, a second
 * Link adds nothing, "Link to a company" shows it on the company page, Undo
 * takes off exactly the card's links, Not this program changes nothing, the
 * merge tool moves the links, and every card says what its buttons do.
 */

require_once dirname(__DIR__, 2) . '/inc/nonprofit-filings.php';
require_once dirname(__DIR__, 2) . '/inc/business-filings.php';

$GLOBALS['pdo']->exec('CREATE TABLE IF NOT EXISTS wpdl_kop_business_links (id INTEGER PRIMARY KEY AUTOINCREMENT, state TEXT NOT NULL, doc TEXT NOT NULL,
    facility_id INTEGER NOT NULL DEFAULT 0, operator_id INTEGER NOT NULL DEFAULT 0, created_by TEXT NOT NULL DEFAULT \'\', created_at TEXT NOT NULL)');

function kop_rinbox_test_business_filings(array $src, array $item, callable $check) {
    global $wpdb;
    $cands = kop_bizf_candidates();
    $table = kop_bizf_table();
    $count = function () use ($wpdb, $table) { return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); };

    // Every company: a key, a known match, records that exist, its file; people with no street address.
    $bad = array();
    $views = array('matched' => 0, 'check' => 0);
    $hows = array('its name', 'a name it had before', 'a trade name it registered', 'the EIN of the nonprofit found for the program', "the program's street address");
    foreach ($cands as $key => $e) {
        if (!kop_bizf_split_key($key)) $bad[] = $key . ': key';
        if (!in_array($e['match'] ?? '', array('matched', 'check'), true)) $bad[] = $key . ': match';
        if (empty($e['candidates'])) $bad[] = $key . ': no records';
        foreach ((array) ($e['candidates'] ?? array()) as $c) {
            if (!in_array($c['how'] ?? '', $hows, true)) $bad[] = $key . ': how ' . json_encode($c['how'] ?? null);
            if (!in_array($c['kind'] ?? '', array('facility', 'operator'), true)) $bad[] = $key . ': kind';
            elseif (!kop_npf_record_exists($c['kind'], (int) $c['id'])) $bad[] = $key . ': no ' . $c['kind'] . ' #' . $c['id'];
            if (in_array($c['how'] ?? '', array('a trade name it registered', 'a name it had before'), true) && trim((string) ($c['trade_name'] ?? '')) === '') $bad[] = $key . ': the name it matched on is missing';
        }
        $ent = kop_bizf_entity($e['state'], $e['doc']);
        if (!$ent) {
            $bad[] = $key . ': no entity file';
        } else {
            foreach (array_merge((array) $ent['officers'], $ent['agent'] ? array($ent['agent']) : array()) as $p) {
                if (($p['kind'] ?? '') === 'person' && (isset($p['street']) || isset($p['address']))) $bad[] = $key . ': a person\'s street address';
            }
        }
        $views[kop_rinbox_bizf_view($e, array())]++;
    }
    $check('business-filings: every company is well formed, names records that exist, keeps no person\'s street address', !$bad,
        count($cands) . ' companies, ' . json_encode($views) . ($bad ? '; ' . implode(' | ', array_slice($bad, 0, 6)) : ''));

    $check('business-filings: dates read as words', kop_bizf_date('2004-03-15') === 'March 15, 2004' && kop_bizf_date('2004-03-15', true) === 'March 2004' && kop_bizf_date('') === '');

    // A company with a facility candidate, linked to it.
    $key = '';
    foreach ($cands as $k => $e) {
        if (($e['candidates'][0]['kind'] ?? '') === 'facility' && kop_rinbox_bizf_view($e, array()) === 'matched') { $key = (string) $k; break; }
    }
    if ($key === '') foreach ($cands as $k => $e) if (($e['candidates'][0]['kind'] ?? '') === 'facility') { $key = (string) $k; break; }
    if ($key === '' || !kop_rinbox_test_options_persist()) {
        $check('business-filings: a company to test with, and options that persist', false);
        return;
    }
    $e = $cands[$key];
    $fid = (int) $e['candidates'][0]['id'];
    $card = kop_rinbox_bizf_item($key, $e);
    $check('business-filings: a waiting card offers Link with its record filled in, Link to a company, Not this program, each with its help',
        array_column($card['actions'], 'id') === array('link', 'link_company', 'reject') && (int) $card['actions'][0]['params'][0]['value'] === $fid
        && !array_filter($card['actions'], function ($a) { return empty($a['help']); }), json_encode(array_column($card['actions'], 'id')));

    $before = $count();
    $msg = kop_bizf_apply($key, 'facility', $fid, 'inbox-test');
    $mine = array_values(array_filter(kop_bizf_for_facility($fid), function ($x) use ($e) { return $x['doc'] === $e['doc']; }));
    $check('business-filings: Link shows the filing on the facility page', $count() === $before + 1 && $mine, $msg);
    $html = kop_bizf_section_html($mine);
    $check('business-filings: the section prints the name, the document number and the state\'s link',
        strpos($html, esc_html($mine[0]['name'])) !== false && strpos($html, $mine[0]['doc']) !== false && strpos($html, kop_bizf_states()[$mine[0]['state']] . "'s record of it") !== false);
    $again = kop_bizf_apply($key, 'facility', $fid, 'inbox-test');
    $check('business-filings: a second Link to the same record adds nothing', $count() === $before + 1 && strpos($again, 'already') !== false, $again);

    $oid = (int) $wpdb->get_var('SELECT id FROM wpdl_kop_operators ORDER BY id LIMIT 1');
    kop_bizf_apply($key, 'operator', $oid, 'inbox-test');
    $check('business-filings: Link to a company shows the filing on the company page', (bool) kop_bizf_for_operator($oid)
        && count((array) kop_bizf_decisions()[$key]['links']) === 2);

    $other = '';
    foreach ($cands as $k => $x) if ((string) $k !== $key) { $other = (string) $k; break; }
    kop_bizf_apply($other, 'facility', $fid, 'inbox-test');
    $msg = kop_bizf_undo($key);
    $left = $wpdb->get_col("SELECT state || ':' || doc FROM {$table}");
    $check('business-filings: Undo takes off exactly the links the card made', $left === array($other) && !isset(kop_bizf_decisions()[$key]), $msg . ' left: ' . json_encode($left));
    kop_bizf_undo($other);

    $before = $count();
    kop_bizf_reject($key, 'inbox-test');
    $check('business-filings: Not this program changes no page', $count() === $before && kop_rinbox_bizf_view($e, kop_bizf_decisions()[$key]) === 'rejected');
    kop_bizf_undo($key);
    $refused = '';
    try { kop_bizf_apply($key, 'facility', 999999999, 'inbox-test'); } catch (RuntimeException $ex) { $refused = $ex->getMessage(); }
    $check('business-filings: a record that is not on file is refused', $refused !== '' && $count() === $before, $refused);

    // A filing we hold a copy of (_kop_bizf_filing on the attachment) links that copy; the others stay words.
    $with_file = null;
    foreach ($cands as $k => $x) {
        $ent = kop_bizf_entity($x['state'], $x['doc']);
        foreach ((array) ($ent['events'] ?? array()) as $ev) if (!empty($ev['file'])) { $with_file = array($ent, $ev); break 2; }
    }
    if ($with_file) {
        list($ent, $ev) = $with_file;
        $att = 990000001;
        $wpdb->insert($wpdb->postmeta, array('post_id' => $att, 'meta_key' => '_wp_attached_file', 'meta_value' => 'kop-test/filing.pdf'));
        $wpdb->insert($wpdb->postmeta, array('post_id' => $att, 'meta_key' => '_kop_bizf_filing', 'meta_value' => $ent['state'] . ':' . $ent['doc'] . ':' . $ev['file'] . ':' . $ev['date']));
        $html = kop_bizf_section_html(array($ent));
        $links = substr_count($html, 'kop-test/filing.pdf');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $att));
        $check('business-filings: a filing we hold links our copy, the others stay words', $links === 1
            && kop_bizf_copies($ent['state'], $ent['doc']) === array('file' => array(), 'date' => array()), $ent['state'] . ':' . $ent['doc'] . ':' . $ev['file'] . ' links ' . $links);
    } else {
        $check('business-filings: the build gives events their filing id (rebuild a state with filings)', false);
    }

    // An event with no filing id (Florida) links the one document filed that day; two that day link neither.
    $ev = array('date' => '2000-11-29', 'filed' => '2000-11-29', 'what' => 'Reinstatement');
    $one = array('file' => array('A' => 'u1'), 'date' => array('2000-11-29' => array('u1')));
    $two = array('file' => array('A' => 'u1', 'B' => 'u2'), 'date' => array('2000-11-29' => array('u1', 'u2')));
    $check('business-filings: a Florida event links the one document filed that day, never a guess between two',
        kop_bizf_event_copy($ev, $one) === 'u1' && kop_bizf_event_copy($ev, $two) === ''
        && kop_bizf_event_copy($ev + array('file' => 'C'), $one) === '');

    $merge = (string) file_get_contents(dirname(__DIR__, 2) . '/inc/facility-merge.php');
    $check('business-filings: Merge Duplicates moves the links (kop_fmerge_ref_tables)', strpos($merge, "'kop_business_links', 'c' => 'facility_id'") !== false);

    $missing = array();
    foreach (array('matched', 'check') as $view) {
        foreach (kop_rinbox_bizf_list(array('view' => $view, 'search' => '', 'offset' => 0, 'limit' => 20))['items'] as $it) {
            foreach ($it['actions'] as $a) if (empty($a['help'])) $missing[] = $it['key'] . ': ' . $a['id'];
        }
    }
    $check('business-filings: every waiting card says what its buttons do', !$missing, implode(' | ', array_slice($missing, 0, 5)));
}
