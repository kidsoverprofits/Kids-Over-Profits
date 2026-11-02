<?php
/**
 * scripts/test-review-inbox.php checks for the 'company-milestones' source
 * (inc/review-inbox/company-milestones.php over seeds/company-milestones.json
 * and inc/profile-timeline.php). Approve, wording edits, Undo and Leave it off
 * run on the harness's options.
 */

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__, 2) . '/inc/profile-timeline.php';

function kop_rinbox_test_company_milestones(array $src, array $item, callable $check) {
    $file = json_decode(file_get_contents(kop_ptl_ms_file()), true);
    $all = kop_ptl_ms_seed(true);
    $check('company-milestones: every milestone in the file is read', count($all) === count($file['milestones']), count($all) . ' of ' . count($file['milestones']));
    $bad = array();
    foreach ($all as $key => $m) {
        if (!(int) $m['year'] || empty($m['sources'])) $bad[] = $key;
        foreach ((array) $m['sources'] as $s) if (empty($s['url']) || trim((string) ($s['quote'] ?? '')) === '') $bad[] = $key;
        if (!isset(kop_ptl_kinds()[$m['kind'] === 'closure' ? 'closed' : $m['kind']])) $bad[] = $key . ' (' . $m['kind'] . ')';
    }
    $check('company-milestones: each has a year, a known kind and quoted sources', !$bad, implode(', ', array_slice(array_unique($bad), 0, 8)));
    $check('company-milestones: nothing is on a timeline before review', !kop_ptl_ms_approved());

    if (!kop_rinbox_test_options_persist()) {
        $check('company-milestones: options persist in this harness (skipping the actions)', false);
        return;
    }
    $key = $item['key'];
    $check('company-milestones: the card offers Approve first', $item['actions'][0]['id'] === 'approve' && $item['actions'][0]['style'] === 'approve');
    $check('company-milestones: the sources\' quotes are on the card', (bool) array_filter($item['details'], function ($d) { return strpos($d['label'], 'Source: ') === 0 && $d['value'][0] === '"'; }));

    call_user_func($src['act'], $key, 'approve', array());
    $check('company-milestones: Approve puts it on the timelines', isset(kop_ptl_ms_approved()[$key]));
    $e = kop_ptl_ms_event(kop_ptl_ms_get($key), 'main');
    $check('company-milestones: it reads as a timeline event with its sources', $e['title'] !== '' && $e['sources'] && $e['ms'] === $key, $e['title']);
    $other = kop_ptl_ms_event(kop_ptl_ms_get($key), 'other');
    $check('company-milestones: elsewhere it names the company', strpos($other['title'], kop_ptl_ms_get($key)['company']) === 0, $other['title']);

    call_user_func($src['save'], $key, array('title' => 'Edited line', 'detail' => 'Edited card.'));
    $check('company-milestones: the wording edit shows at once', kop_ptl_ms_approved()[$key]['title'] === 'Edited line' && kop_ptl_ms_approved()[$key]['detail'] === 'Edited card.');
    try {
        call_user_func($src['save'], $key, array('date' => 'last spring'));
        $check('company-milestones: a date it cannot read is refused', false);
    } catch (RuntimeException $ex) {
        $check('company-milestones: a date it cannot read is refused', true, $ex->getMessage());
    }
    try {
        call_user_func($src['act'], $key, 'approve', array());
        $check('company-milestones: a decided card cannot be approved twice', false);
    } catch (RuntimeException $ex) {
        $check('company-milestones: a decided card cannot be approved twice', true);
    }

    call_user_func($src['act'], $key, 'undo', array());
    $check('company-milestones: Undo takes it off and keeps the wording', !isset(kop_ptl_ms_approved()[$key]) && kop_ptl_ms_get($key)['title'] === 'Edited line');
    $seed_title = $all[$key]['title'];
    call_user_func($src['save'], $key, array('title' => $seed_title, 'detail' => (string) ($all[$key]['detail'] ?? '')));
    $check('company-milestones: wording set back to the file\'s is no edit', !isset(kop_ptl_ms_edits()[$key]));

    call_user_func($src['act'], $key, 'reject', array());
    $after = call_user_func($src['get'], $key);
    $check('company-milestones: Leave it off changes nothing on the site', !kop_ptl_ms_approved() && $after['status'] === 'rejected' && $after['actions'][0]['id'] === 'undo');
    call_user_func($src['act'], $key, 'undo', array());
    $check('company-milestones: Back to waiting', kop_rinbox_ms_view($key) === 'pending');

    // Every field can be edited, sources too.
    $kind_was = $all[$key]['kind'];
    call_user_func($src['save'], $key, array('kind' => 'settlement', 'sources' => "https://example.test/a | Example | \"the quote\"\nhttps://example.test/b"));
    $m = kop_ptl_ms_get($key);
    $check('company-milestones: kind and sources can be edited', $m['kind'] === 'settlement' && count($m['sources']) === 2
        && $m['sources'][1]['label'] === 'example.test' && $m['sources'][0]['quote'] === '"the quote"', json_encode($m['sources']));
    try {
        call_user_func($src['save'], $key, array('sources' => 'no link here'));
        $check('company-milestones: a source line without a link is refused', false);
    } catch (RuntimeException $ex) {
        $check('company-milestones: a source line without a link is refused', true, $ex->getMessage());
    }
    call_user_func($src['save'], $key, array('kind' => $kind_was, 'sources' => kop_rinbox_ms_sources_text($all[$key]['sources'])));
    $check('company-milestones: sources set back to the file\'s are no edit', !isset(kop_ptl_ms_edits()[$key]), json_encode(kop_ptl_ms_edits()[$key] ?? null));

    // Add a milestone by hand: on the timelines at once, Undo, Delete and its Undo.
    $check('company-milestones: the Add tool is folded and asks for company, date and sources',
        ($src['tools'][0]['id'] ?? '') === 'add' && !empty($src['tools'][0]['fold'])
        && !array_diff(array('company', 'kind', 'date', 'title', 'sources'), array_column($src['tools'][0]['params'], 'name')));
    $new = array('company' => 'Test Behavioral Holdings', 'kind' => 'deal', 'date' => '2019-06', 'title' => 'Bought by Example Capital',
        'detail' => 'A test.', 'also' => array('Example Capital'), 'programs' => array(), 'sources' => 'https://example.test/deal | Deal story | "bought"');
    try {
        call_user_func($src['tool'], 'add', array('company' => 'X', 'date' => '2019', 'title' => 'No source', 'kind' => 'deal', 'sources' => ''));
        $check('company-milestones: a milestone with no source is refused', false);
    } catch (RuntimeException $ex) {
        $check('company-milestones: a milestone with no source is refused', true, $ex->getMessage());
    }
    $res = call_user_func($src['tool'], 'add', $new);
    $added = array_keys(kop_ptl_ms_added());
    $akey = (string) end($added);
    $check('company-milestones: Add puts it on the timelines at once', $akey !== '' && isset(kop_ptl_ms_approved()[$akey])
        && kop_ptl_ms_approved()[$akey]['year'] === 2019 && kop_ptl_ms_approved()[$akey]['also'] === array('Example Capital'), $res['message']);
    $card = call_user_func($src['get'], $akey);
    $check('company-milestones: its card offers Undo and Delete', array_column($card['actions'], 'id') === array('undo', 'delete'), json_encode(array_column($card['actions'], 'id')));
    try {
        call_user_func($src['tool'], 'add', $new);
        $check('company-milestones: the same milestone twice is refused', false);
    } catch (RuntimeException $ex) {
        $check('company-milestones: the same milestone twice is refused', true, $ex->getMessage());
    }
    $del = call_user_func($src['act'], $akey, 'delete', array());
    $check('company-milestones: Delete takes it off and out of the queue', !isset(kop_ptl_ms_all()[$akey]) && !isset(kop_ptl_ms_approved()[$akey]) && !empty($del['undo']));
    call_user_func($src['act'], $akey, $del['undo']['action'], json_decode(json_encode($del['undo']['params']), true));
    $check('company-milestones: Undo of Delete brings it back on the timelines', isset(kop_ptl_ms_approved()[$akey]));
    try {
        call_user_func($src['act'], $key, 'delete', array());
        $check('company-milestones: a milestone from the file cannot be deleted', false);
    } catch (RuntimeException $ex) {
        $check('company-milestones: a milestone from the file cannot be deleted', true);
    }
    call_user_func($src['act'], $akey, 'delete', array());
}
