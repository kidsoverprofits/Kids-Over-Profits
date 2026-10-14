<?php
/**
 * scripts/test-review-inbox.php checks for the 'wiki-staff' source
 * (inc/review-inbox/wiki-staff.php over api/sync-wiki-facilities.php). The
 * scratch copy gets the tables the facility writer needs, then one approved
 * wiki entry (#70, Embark Behavioral Health: four people, a record whose
 * location the merge leaves alone) is merged, as approving it does, so its
 * card waits in the queue.
 */

(function () {
    // Only when this queue is tested: the merge changes a record other queues read.
    if (!in_array($GLOBALS['only'] ?? '', array('', 'wiki-staff'), true)) return;
    $pdo = $GLOBALS['pdo'];
    $mirror = $GLOBALS['mirror'] ?? (dirname(__DIR__, 2) . '/tmp/prod.sqlite');
    $pdo->exec('ATTACH DATABASE ' . $pdo->quote($mirror) . ' AS wsrc');
    foreach (array('wpdl_kop_facility_locations', 'wpdl_kop_facility_identity', 'wpdl_kop_migration_state') as $t) {
        $have = $pdo->query("SELECT COUNT(*) FROM main.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
        $sql = $pdo->query("SELECT sql FROM wsrc.sqlite_master WHERE type = 'table' AND name = " . $pdo->quote($t))->fetchColumn();
        if ($have || !$sql) continue;
        $pdo->exec($sql);
        $pdo->exec("INSERT INTO main.`$t` SELECT * FROM wsrc.`$t`");
    }
    $pdo->exec('DETACH DATABASE wsrc');
    $pdo->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
    if (!defined('KOP_DB_CONFIG_LOADED')) define('KOP_DB_CONFIG_LOADED', true);   // the scratch copy is the database, not api/config.php's
    require_once dirname(__DIR__, 2) . '/inc/facility-migration.php';
    require_once dirname(__DIR__, 2) . '/api/sync-wiki-facilities.php';
    $GLOBALS['kop_test_options']['kop_wiki_staff_log'] = array();
    try {
        $GLOBALS['kop_rinbox_test_wstaff'] = kop_wiki_merge_submission($pdo, 70);
    } catch (Throwable $e) {
        $GLOBALS['kop_rinbox_test_wstaff'] = array('merged' => false, 'reason' => $e->getMessage());
    }
})();

function kop_rinbox_test_wiki_staff(array $src, array $item, callable $check) {
    $seed = $GLOBALS['kop_rinbox_test_wstaff'];
    $check('wiki-staff: approving the entry put its people on the record', !empty($seed['staff_added']), json_encode($seed));
    $check('wiki-staff: its card waits, one line per person, with the facility and wiki pages', $item['key'] === '70'
        && count($item['details']) === (int) $seed['staff_added'] && array_column($item['links'], 'label') === array('Facility page', 'Wiki page'),
        json_encode(array('key' => $item['key'], 'details' => count($item['details']), 'links' => array_column($item['links'], 'label'))));
    $check('wiki-staff: it offers Looks right and Take them off', array_column($item['actions'], 'id') === array('ok', 'remove'));

    call_user_func($src['act'], '70', 'ok', array());
    $c = call_user_func($src['view_counts'], array());
    $check('wiki-staff: Looks right files it under Looked over', $c['added'] === 0 && $c['checked'] === 1 && call_user_func($src['count']) === 0, json_encode($c));
    $res = call_user_func($src['act'], '70', 'remove', array());
    $again = kop_rinbox_get_item('wiki-staff', '70');
    $check('wiki-staff: Take them off removes them and the card moves to Taken off', $again['status'] === 'undone'
        && array_column($again['actions'], 'id') === array('readd') && strpos($res['message'], 'Took ' . (int) $seed['staff_added']) === 0, $res['message']);
    $res = call_user_func($src['act'], '70', 'readd', array());
    $back = kop_rinbox_get_item('wiki-staff', '70');
    $check('wiki-staff: Undo puts them back and the card waits again', $back['status'] === 'added' && strpos($res['message'], 'Put ' . (int) $seed['staff_added']) === 0, $res['message']);
    try {
        call_user_func($src['act'], '999999', 'ok', array());
        $check('wiki-staff: an entry that added no one is refused', false);
    } catch (RuntimeException $e) {
        $check('wiki-staff: an entry that added no one is refused', true, $e->getMessage());
    }
}
