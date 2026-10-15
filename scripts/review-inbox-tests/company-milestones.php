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
    $all = kop_ptl_ms_all(true);
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
}
