<?php
/**
 * scripts/test-review-inbox.php checks for KOP Tools > Card Fields
 * (inc/review-inbox-fields.php) and filing an item on any kind of record
 * (inc/review-record-file.php):
 *
 * - a queue with 'record_kinds' gets its 'facility' pickers (fields, action
 *   params, moves) as the any-record finder; 'facility_only' ones and queues
 *   without 'record_kinds' keep the facility box;
 * - the fields and lines its cards have are remembered for the screen;
 * - the owner's choices hide a field (kept in the form, unseen), hide a line,
 *   and narrow the kinds; Undo puts the choices back;
 * - a link filed on a company goes into its "Materials and links" and Undo
 *   takes it off; a second filing of the same link is refused.
 *
 * Run with --source=card-fields or with no --source.
 */

require_once __DIR__ . '/_shared.php';

function kop_rinbox_test_card_fields(callable $check) {
    global $wpdb;
    if (!kop_rinbox_test_options_persist()) {
        $check('card-fields: options persist in this harness', false);
        return;
    }
    delete_option('kop_review_card_fields');
    delete_option('kop_review_card_fields_previous');
    delete_option('kop_review_card_fields_seen');

    $item = function () {
        return array('key' => 'a', 'title' => 'Card a',
            'fields' => array(
                array('name' => 'facility_id', 'label' => 'Facility', 'type' => 'facility', 'value' => '12'),
                array('name' => 'note', 'label' => 'Note', 'type' => 'text', 'value' => 'hello'),
            ),
            'details' => array(array('label' => 'Article', 'value' => 'x'), array('label' => 'Outlet', 'value' => 'y')),
            'actions' => array(array('id' => 'apply', 'label' => 'Apply', 'params' => array(
                array('name' => 'facility_id', 'label' => 'Facility', 'type' => 'facility', 'value' => ''),
                array('name' => 'home', 'label' => 'Home of', 'type' => 'facility', 'value' => '', 'facility_only' => true),
            ))),
            'moves' => array(array('id' => 'resource', 'label' => 'Move', 'params' => array(
                array('name' => 'facility_id', 'label' => 'Facility', 'type' => 'facility', 'value' => ''),
            ))));
    };
    $queue = function ($kinds) use ($item) {
        return function () use ($item, $kinds) {
            $spec = array('label' => 'Card fields test', 'views' => array('waiting' => 'Waiting'),
                'count' => function () { return 1; },
                'list' => function () use ($item) { return array('items' => array($item()), 'total' => 1); },
                'get' => function () use ($item) { return $item(); });
            if ($kinds !== null) $spec['record_kinds'] = $kinds;
            return $spec;
        };
    };
    kop_rinbox_register('zz-any', $queue(true));
    kop_rinbox_register('zz-some', $queue(array('facility', 'company', 'ya')));
    kop_rinbox_register('zz-prog', $queue(null));
    kop_rinbox_sources(true);
    $finish = function ($source) use ($item) { return kop_rinbox_finish_items($source, array($item()))[0]; };

    $c = $finish('zz-any');
    $all = array_keys(kop_rinbox_record_kinds());
    $check('card-fields: a queue taking any record gets the any-record finder in its fields, action params and moves',
        $c['fields'][0]['type'] === 'record' && $c['fields'][0]['kinds'] === $all
        && $c['actions'][0]['params'][0]['type'] === 'record' && $c['moves'][0]['params'][0]['type'] === 'record',
        json_encode(array($c['fields'][0], $c['moves'][0]['params'][0])));
    $check('card-fields: a facility_only param keeps the facility box', $c['actions'][0]['params'][1]['type'] === 'facility');
    $check('card-fields: a picker keeps its value (a program id)', (string) $c['fields'][0]['value'] === '12');
    $p = $finish('zz-prog');
    $check('card-fields: a queue without record_kinds keeps the facility box', $p['fields'][0]['type'] === 'facility' && $p['moves'][0]['params'][0]['type'] === 'facility');
    $s = $finish('zz-some');
    $check('card-fields: a queue taking some kinds offers those', $s['fields'][0]['kinds'] === array('facility', 'company', 'ya'), json_encode($s['fields'][0]['kinds']));

    $seen = get_option('kop_review_card_fields_seen');
    $check('card-fields: the fields and lines its cards have are remembered for the screen',
        isset($seen['zz-any']['fields']['note'], $seen['zz-any']['details']['Article']), json_encode($seen['zz-any'] ?? null));

    kop_rinbox_card_fields_save('zz-any', array('facility_id'), array('Outlet'), array('company', 'ya'));
    $c = $finish('zz-any');
    $check('card-fields: a hidden field stays in the form, marked unseen', !empty($c['fields'][1]['hidden_by_owner']) && empty($c['fields'][0]['hidden_by_owner']));
    $check('card-fields: a hidden line is left off the card', array_column($c['details'], 'label') === array('Outlet'), json_encode($c['details']));
    $check('card-fields: the picker offers only the kinds ticked', $c['fields'][0]['kinds'] === array('company', 'ya') && $c['moves'][0]['params'][0]['kinds'] === array('company', 'ya'));
    $check('card-fields: only differences are kept', get_option('kop_review_card_fields') === array('zz-any' => array('hide' => array('note'), 'hide_details' => array('Article'), 'kinds' => array('company', 'ya'))),
        json_encode(get_option('kop_review_card_fields')));
    kop_rinbox_card_fields_save('zz-any', array('facility_id', 'note'), array('Article', 'Outlet'), $all);
    $check('card-fields: ticking everything back keeps nothing', !isset(get_option('kop_review_card_fields')['zz-any']));

    // Filing on a company: "Materials and links", refused twice, exact Undo.
    $oid = (int) $wpdb->get_var('SELECT id FROM wpdl_kop_operators ORDER BY id LIMIT 1');
    if ($oid <= 0 || !function_exists('kop_gdl_company_load')) {
        $check('card-fields: a company to file on (mirror)', false);
        return;
    }
    kop_rinbox_test_with_wpdb_writes(function () use ($check, $oid) {
        $before = kop_gdl_company_load($oid);
        $url = 'https://example.org/card-fields-test-' . $oid;
        $done = kop_rinbox_file_on_record('company:' . $oid, array('what' => 'link', 'url' => $url, 'title' => 'A test page',
            'kind' => 'reference', 'source' => 'Card fields test'), 'tester');
        $after = kop_gdl_company_load($oid);
        $links = array_column((array) ($after['operator']['resourceLinks'] ?? array()), 'url');
        $check('card-fields: a link filed on a company is in its "Materials and links"', in_array($url, $links, true) && ($done['what'] ?? '') === 'link', json_encode($done));
        $twice = '';
        try {
            kop_rinbox_file_on_record('company:' . $oid, array('what' => 'link', 'url' => $url), 'tester');
        } catch (RuntimeException $e) {
            $twice = $e->getMessage();
        }
        $check('card-fields: the same link twice is refused', strpos($twice, 'already') !== false, $twice);
        kop_rinbox_unfile_from_record($done);
        $back = kop_gdl_company_load($oid);
        $check('card-fields: Undo takes it off again', (array) ($back['operator']['resourceLinks'] ?? array()) == (array) ($before['operator']['resourceLinks'] ?? array()));
        $refused = '';
        try {
            kop_rinbox_file_on_record('12', array('what' => 'link', 'url' => $url), 'tester');
        } catch (RuntimeException $e) {
            $refused = $e->getMessage();
        }
        $check('card-fields: a program pick is left to the queue', $refused !== '');
    });

    // A website of a consultant, transporter, marketer or trade organization: its websites list, twice refused, exact Undo.
    $pdo = kop_wbc_pdo();
    if (!function_exists('kop_resolve_table_name')) {
        // api/lib-suggested-edits.php asks MySQL (SHOW TABLES); the scratch copy is SQLite.
        function kop_resolve_table_name(PDO $pdo, $base, $prefix = '') {
            foreach (array_filter(array((string) $prefix . $base, $base)) as $t) {
                $q = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
                $q->execute(array($t));
                if ($q->fetchColumn()) return $t;
            }
            return (string) $prefix . $base;
        }
    }
    foreach (kop_rinbox_record_website_kinds() as $kind) {
        $table = kop_wbc_table_if_there($pdo, kop_wbc_record_tables()[$kind]['table']);
        $rid = $table !== '' ? (int) $pdo->query("SELECT id FROM `{$table}` ORDER BY id LIMIT 1")->fetchColumn() : 0;
        if ($rid <= 0) {
            echo "  (no {$kind} records in the mirror: its website path is not run here)\n";
            continue;
        }
        $json_of = function () use ($pdo, $table, $rid) {
            return (string) $pdo->query("SELECT json_data FROM `{$table}` WHERE id = {$rid}")->fetchColumn();
        };
        $before = $json_of();
        $url = 'https://example.org/website-test-' . $kind . '-' . $rid;
        $done = kop_rinbox_file_on_record($kind . ':' . $rid, array('what' => 'website', 'url' => $url, 'title' => 'Its site'), 'tester');
        $after = $json_of();
        $check("card-fields: a {$kind}'s website goes in its websites list", ($done['what'] ?? '') === 'website'
            && strpos($after, '"websites":[') !== false && strpos(str_replace('\\/', '/', $after), $url) !== false, json_encode($done));
        $twice = '';
        try {
            kop_rinbox_file_on_record($kind . ':' . $rid, array('what' => 'website', 'url' => $url), 'tester');
        } catch (RuntimeException $e) {
            $twice = $e->getMessage();
        }
        $check("card-fields: the same {$kind} website twice is refused", strpos($twice, 'already') !== false, $twice);
        kop_rinbox_unfile_from_record($done);
        $back = json_decode($json_of(), true);
        $orig = json_decode($before, true);
        $check("card-fields: Undo takes the {$kind} website off", strpos(str_replace('\\/', '/', $json_of()), $url) === false);
        $pdo->prepare("UPDATE `{$table}` SET json_data = ? WHERE id = ?")->execute(array($before, $rid));
        unset($back, $orig);
    }
    delete_option('kop_review_card_fields_seen');
}
