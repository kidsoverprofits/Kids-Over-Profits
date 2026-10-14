<?php
/**
 * scripts/test-review-inbox.php checks for the card add-ons (kop_rinbox_addon()
 * in inc/review-inbox.php, KOP Tools > Card Add-ons in inc/review-inbox-addons.php)
 * and the "pick a record, or make a new one" field (inc/record-field.php):
 *
 * - "Home of a program" and "File under a young adult program" are add-ons;
 * - an add-on is on where its default, a queue's 'addons' or the owner says,
 *   never on a queue in its 'never'; its actions are answered even when off
 *   (an Undo still works); the screen keeps only what differs from the default;
 * - the field's values, a company picked, a new company made citing the card,
 *   Undo deleting it while untouched and keeping it once something links it;
 * - nonprofit tax filings' "Link to a company" makes a new company and its
 *   Undo takes both the link and the company off.
 *
 * Run with --source=addons or with no --source.
 */

require_once __DIR__ . '/_shared.php';

/** Called by scripts/test-review-inbox.php after the per-source checks. */
function kop_rinbox_test_card_addons(callable $check) {
    global $wpdb;
    $addons = kop_rinbox_addons();
    $check('addons: "Home of a program" and "File under a young adult program" are add-ons with a label, help and their actions',
        isset($addons['home-of-program'], $addons['ya-file']) && $addons['home-of-program']['help'] !== '' && $addons['ya-file']['help'] !== ''
        && in_array('undo_home_of_program', $addons['home-of-program']['actions'], true) && in_array('ya_unfile', $addons['ya-file']['actions'], true),
        implode(', ', array_keys($addons)));
    $check('addons: each stays off the queues it never goes on', !kop_rinbox_addon_on('home-of-program', 'facility-merge') && !kop_rinbox_addon_on('ya-file', 'woodbury-facts'));

    if (!kop_rinbox_test_options_persist()) {
        $check('addons: options persist in this harness (needed for the owner\'s choices)', false);
        return;
    }
    delete_option('kop_review_addons');

    // A made-up add-on, off by default, and two made-up queues: one lists it, one does not.
    $GLOBALS['kop_rinbox_addon_ran'] = array();
    kop_rinbox_addon('zz-addon', array(
        'label' => 'Test add-on', 'help' => 'Does a test thing.', 'actions' => array('zz_do', 'zz_undo'), 'default' => false,
        'never' => array('zz-never'),
        'card' => function ($source, array $item) { return array('id' => 'zz_do', 'label' => 'Test add-on', 'style' => 'neutral', 'help' => 'Does a test thing.'); },
        'act' => function ($source, $key, $action, array $params) {
            $GLOBALS['kop_rinbox_addon_ran'][] = $source . ':' . $key . ':' . $action;
            return array('message' => 'Add-on ran.', 'undo' => $action === 'zz_do' ? array('action' => 'zz_undo', 'params' => array()) : null);
        },
    ));
    $queue = function ($addons) {
        $get = function ($k) { return array('key' => $k, 'title' => 'Card ' . $k, 'actions' => array(array('id' => 'approve', 'label' => 'Approve', 'style' => 'approve'))); };
        return function () use ($get, $addons) {
            return array('label' => 'Add-on test', 'views' => array('waiting' => 'Waiting'), 'addons' => $addons,
                'count' => function () { return 1; },
                'list' => function () use ($get) { return array('items' => array($get('a')), 'total' => 1); },
                'get' => $get,
                'act' => function () { throw new RuntimeException('the queue\'s own act ran'); });
        };
    };
    kop_rinbox_register('zz-plain', $queue(array()));
    kop_rinbox_register('zz-lists', $queue(array('zz-addon')));
    kop_rinbox_register('zz-never', $queue(array('zz-addon')));
    kop_rinbox_sources(true);
    $has = function ($source) {
        $items = kop_rinbox_finish_items($source, array(array('key' => 'a', 'title' => 'Card a', 'actions' => array())));
        return in_array('zz_do', array_column($items[0]['actions'], 'id'), true);
    };
    $check('addons: an add-on off by default is not on a queue that does not list it', !$has('zz-plain'));
    $check('addons: a queue listing it in \'addons\' has it', $has('zz-lists'));
    $check('addons: a queue in its \'never\' does not, even when it lists it', !$has('zz-never') && !kop_rinbox_addon_allowed('zz-addon', 'zz-never'));

    kop_rinbox_test_with_wpdb_writes(function () use ($check, $has) {
        $req = new WP_REST_Request();
        foreach (array('source' => 'zz-plain', 'key' => 'a', 'action' => 'zz_do', 'params' => array()) as $k => $v) $req->set_param($k, $v);
        $res = kop_rinbox_rest_act($req)->get_data();
        $check('addons: its action is answered before the queue\'s own (and even where it is off, so an Undo still works)',
            ($res['message'] ?? '') === 'Add-on ran.' && $GLOBALS['kop_rinbox_addon_ran'] === array('zz-plain:a:zz_do'), json_encode($res));
    });

    // The owner's choices: on for zz-plain, off for zz-lists; only differences are kept.
    $n = kop_rinbox_addon_save(array('zz-addon' => array('zz-plain' => '1'), 'home-of-program' => array(), 'ya-file' => array()));
    $kept = get_option('kop_review_addons');
    $check('addons: the screen turns it on where ticked and off where unticked', $has('zz-plain') && !$has('zz-lists'));
    $check('addons: only choices that differ from the default are kept',
        ($kept['zz-addon'] ?? null) === array('zz-plain' => true, 'zz-lists' => false) && $n === count($kept, COUNT_RECURSIVE) - count($kept),
        json_encode(array('zz-addon' => $kept['zz-addon'] ?? null, 'n' => $n)));
    $check('addons: unticking "Home of a program" on every queue turns it off', !kop_rinbox_addon_on('home-of-program', 'nonprofit-filings')
        && kop_rinbox_addon_default('home-of-program', 'nonprofit-filings'));
    delete_option('kop_review_addons');
    $check('addons: "Put back the defaults" restores them', !$has('zz-plain') && $has('zz-lists') && kop_rinbox_addon_on('home-of-program', 'nonprofit-filings'));
    unset($GLOBALS['kop_rinbox_addons']['zz-addon'], $GLOBALS['kop_rinbox_builders']['zz-plain'], $GLOBALS['kop_rinbox_builders']['zz-lists'], $GLOBALS['kop_rinbox_builders']['zz-never']);
    kop_rinbox_sources(true);

    // ---- The record field --------------------------------------------------
    $p = kop_record_field_param('operator_id', 'Company', array('kinds' => array('operator'), 'value' => ''));
    $check('record field: the param asks the finder for companies and lets a new one be named',
        $p['type'] === 'record' && $p['kinds'] === array('company') && $p['create'] === array('company'), json_encode($p));
    $p2 = kop_record_field_param('x', 'X', array('kinds' => array('company', 'facility'), 'create' => false));
    $check('record field: create => false offers no new record', $p2['create'] === array() && $p2['kinds'] === array('company', 'facility'));
    $check('record field: values read as facility id, kind:id and new:kind:name',
        kop_record_field_parse('12') === array('facility', 12, '') && kop_record_field_parse('company:45') === array('company', 45, '')
        && kop_record_field_parse('new:company:  Acme   Youth ') === array('company', 0, 'Acme Youth') && kop_record_field_parse('junk')[0] === ''
        && kop_record_field_token('operator', 45) === 'company:45' && kop_record_field_token('facility', 9) === '9');

    $oid = (int) $wpdb->get_var('SELECT id FROM wpdl_kop_operators ORDER BY id LIMIT 1');
    $rec = kop_record_field_resolve('company:' . $oid, array('kinds' => array('company')));
    $check('record field: a company picked resolves to it, nothing made', $rec['id'] === $oid && !$rec['created'] && $rec['name'] !== '', json_encode($rec));
    $err = '';
    try { kop_record_field_resolve('12', array('kinds' => array('company'))); } catch (RuntimeException $e) { $err = $e->getMessage(); }
    $check('record field: a program where a company is asked for is refused', strpos($err, 'company') !== false, $err);
    $err = '';
    try { kop_record_field_resolve('new:company:Acme', array('kinds' => array('company'), 'create' => false)); } catch (RuntimeException $e) { $err = $e->getMessage(); }
    $check('record field: a new name where none may be made is refused', $err !== '', $err);

    // Making a company is kop_wbc_create() (inc/woodbury-create.php, the create Woodbury Reports and Wiki links use), whose
    // writer needs MySQL (INSERT IGNORE, SHOW COLUMNS). Here a company row is put in by hand and kept for Undo exactly as
    // kop_record_field_resolve() keeps one it made (kop_record_field_remember()); Undo's delete is the real writer's.
    $GLOBALS['pdo']->sqliteCreateFunction('GET_LOCK', function () { return 1; }, 2);
    $GLOBALS['pdo']->sqliteCreateFunction('RELEASE_LOCK', function () { return 1; }, 1);
    if (!function_exists('kop_v2_writes_active')) {
        function kop_v2_writes_active(PDO $pdo, $prefix, $refresh = false) { return true; }
    }
    require_once dirname(__DIR__, 2) . '/inc/facility-v2-writer.php';
    kop_rinbox_test_with_wpdb_writes(function () use ($check, $wpdb) {
        $name = 'Zz Record Field Test Company ' . getmypid();
        $count = function () use ($wpdb) { return (int) $wpdb->get_var('SELECT COUNT(*) FROM wpdl_kop_operators'); };
        $make = function ($n) use ($wpdb) {
            $wpdb->insert('wpdl_kop_operators', array('unique_name' => $n, 'name' => $n,
                'json_data' => json_encode(array('operator' => array('name' => $n, 'notes' => array('Added from a test card'))))));
            $id = (int) $wpdb->insert_id;
            kop_record_field_remember('company', $id, 0, 'inbox-test');
            return $id;
        };
        $before = $count();

        $id = $make($name);
        $check('record field: a company made here resolves by its token like any other', kop_record_field_resolve('company:' . $id)['name'] === $name);
        $check('record field: the picked label names it', kop_record_field_label('company:' . $id) === $name
            && kop_record_field_label('new:company:Acme') === 'New company: Acme');
        $msg = kop_record_field_unmake('company', $id);
        $check('record field: Undo deletes a company made here while nothing changed or linked it', $count() === $before && strpos($msg, 'deleted again') !== false, $msg);
        $check('record field: Undo of a record not made here says nothing and deletes nothing', kop_record_field_unmake('company', 1) === '' && $count() === $before);

        $id = $make($name . ' B');
        $fid = (int) $wpdb->get_var('SELECT id FROM facilities_v2 ORDER BY id LIMIT 1');
        $wpdb->insert('wpdl_kop_operator_facilities', array('operator_id' => $id, 'facility_id' => $fid));
        $msg = kop_record_field_unmake('company', $id);
        $check('record field: a company linked to a program since is kept by Undo', $count() === $before + 1 && strpos($msg, 'stays') !== false, $msg);
        $wpdb->query($wpdb->prepare('DELETE FROM wpdl_kop_operator_facilities WHERE operator_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM wpdl_kop_operators WHERE id = %d', $id));

        $id = $make($name . ' C');
        $wpdb->query($wpdb->prepare("UPDATE wpdl_kop_operators SET json_data = %s WHERE id = %d", json_encode(array('operator' => array('name' => $name . ' C', 'notes' => array('edited')))), $id));
        $msg = kop_record_field_unmake('company', $id);
        $check('record field: a company edited since is kept by Undo', $count() === $before + 1 && strpos($msg, 'edited since') !== false, $msg);
        $wpdb->query($wpdb->prepare('DELETE FROM wpdl_kop_operators WHERE id = %d', $id));

        // Nonprofit tax filings: "Link to a company" through the field, and Undo taking off the company the card made.
        if (!function_exists('kop_npf_candidates') || !kop_npf_candidates()) {
            kop_rinbox_test_skip('record field: nonprofit tax filings', 'no candidates file');
            return;
        }
        $ein = (string) array_keys(kop_npf_candidates())[0];
        $card = kop_rinbox_npf_item($ein, kop_npf_candidates()[$ein]);
        $lc = null;
        foreach ($card['actions'] as $a) if ($a['id'] === 'link_company') $lc = $a;
        $check('nonprofit-filings: "Link to a company" uses the find-or-make field', $lc && $lc['params'][0]['type'] === 'record'
            && $lc['params'][0]['kinds'] === array('company') && $lc['params'][0]['create'] === array('company'), json_encode($lc['params'] ?? null));
        $src = kop_rinbox_source('nonprofit-filings');
        $err = '';
        try { call_user_func($src['act'], $ein, 'link_company', array('operator_id' => '')); } catch (RuntimeException $e) { $err = $e->getMessage(); }
        $check('nonprofit-filings: no company picked is refused with a sentence', strpos($err, 'Pick a company') === 0, $err);

        $id = $make($name . ' D');
        $res = call_user_func($src['act'], $ein, 'link_company', array('operator_id' => 'company:' . $id));
        $check('nonprofit-filings: the company picked in the field gets the returns on its page', (bool) kop_npf_for_operator($id), $res['message']);
        $res = call_user_func($src['act'], $ein, 'undo', array());
        $check('nonprofit-filings: Undo takes off the link and the company the card made', !kop_npf_for_operator($id) && $count() === $before
            && strpos($res['message'], 'deleted again') !== false, $res['message']);

        $res = call_user_func($src['act'], $ein, 'link_company', array('operator_id' => 'company:1'));
        $res = call_user_func($src['act'], $ein, 'undo', array());
        $check('nonprofit-filings: Undo never deletes a company that was on file before', $count() === $before && kop_record_field_name('company', 1) !== '', $res['message']);
    });
}
