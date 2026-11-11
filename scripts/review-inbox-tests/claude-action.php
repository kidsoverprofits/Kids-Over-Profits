<?php
/**
 * scripts/test-review-inbox.php checks for "Send to Claude" on every card
 * (inc/review-inbox/_claude-action.php): offered on a made-up queue, not on
 * Bug reports or Write-ups; sending puts the card on Claude's list with the
 * note (logged with Take back as its Undo) and the card says it waits; the
 * list gives the card's fields; Claude's answer saves only the card's own
 * fields (a select only one of its options), takes it off the list and leaves
 * its note on the card; a second answer, a bad outcome or no note is refused.
 * Run with --source=claude or with no --source.
 */

require_once __DIR__ . '/_shared.php';

/** Called by scripts/test-review-inbox.php after the per-source checks. */
function kop_rinbox_test_claude_action(callable $check) {
    $GLOBALS['kop_rinbox_cl'] = array('title' => 'Paywalled story', 'outlet' => '', 'kind' => 'news', 'saved' => array(), 'filed' => '', 'acts' => array());
    kop_rinbox_register('zz-claude', function () {
        $get = function ($k) {
            if ($k !== 'a') return null;
            $d = $GLOBALS['kop_rinbox_cl'];
            return array('key' => 'a', 'title' => $d['title'], 'url' => 'https://example-news.test/paywalled',
                'fields' => array(
                    array('name' => 'title', 'label' => 'Title', 'type' => 'text', 'value' => $d['title']),
                    array('name' => 'outlet', 'label' => 'Outlet', 'type' => 'text', 'value' => $d['outlet']),
                    array('name' => 'kind', 'label' => 'Kind', 'type' => 'select', 'value' => $d['kind'], 'options' => array('news' => 'News', 'opinion' => 'Opinion')),
                ),
                'moves' => array(array('id' => 'lawsuit', 'label' => 'Lawsuits')),
                'actions' => $d['filed'] ? array(array('id' => 'unfile', 'label' => 'Undo', 'style' => 'undo'))
                    : array(array('id' => 'file_on', 'label' => 'File on a record', 'style' => 'neutral', 'params' => array(array('name' => 'record', 'label' => 'Record', 'type' => 'text', 'value' => ''))),
                        array('id' => 'approve', 'label' => 'Approve', 'style' => 'approve')));
        };
        return array('label' => 'Claude test', 'views' => array('waiting' => 'Waiting'),
            'count' => function () { return 1; },
            'list' => function () use ($get) { return array('items' => array($get('a')), 'total' => 1); },
            'get' => $get,
            'save' => function ($k, array $fields) {
                foreach ($fields as $n => $v) $GLOBALS['kop_rinbox_cl'][$n] = $v;
                $GLOBALS['kop_rinbox_cl']['saved'][] = $fields;
                return array('message' => 'Saved.');
            },
            'act' => function ($k, $action, array $params) {
                $GLOBALS['kop_rinbox_cl']['acts'][] = $action . '=' . json_encode($params) . '@' . kop_rinbox_reviewer();
                if ($action === 'file_on') { $GLOBALS['kop_rinbox_cl']['filed'] = (string) $params['record']; return array('message' => 'Filed on ' . $params['record'] . '.'); }
                if ($action === 'move') return array('message' => 'Moved to ' . $params['to'] . '.');
                throw new RuntimeException('unexpected ' . $action);
            });
    });
    kop_rinbox_sources(true);
    $card = function () { return kop_rinbox_get_item('zz-claude', 'a'); };
    $action = function (array $it, $id) {
        foreach ($it['actions'] as $a) if ($a['id'] === $id) return $a;
        return null;
    };

    $a = $action($card(), 'send_to_claude');
    $check('claude: every card has Send to Claude, a small form with an optional note', $a && !empty($a['ask']) && ($a['params'][0]['name'] ?? '') === 'claude_note', json_encode($a));
    $check('claude: not on Bug reports or Write-ups (they go to Claude their own way)',
        !kop_rinbox_addon_on('send-to-claude', 'bug-reports') && !kop_rinbox_addon_on('send-to-claude', 'writeups') && kop_rinbox_addon_on('send-to-claude', 'news'));

    {
        kop_rinbox_test_with_wpdb_writes(function () use ($check, $card, $action) {
            delete_option('kop_rinbox_claude');
            delete_option('kop_rinbox_claude_done');
            $req = new WP_REST_Request();
            foreach (array('source' => 'zz-claude', 'key' => 'a', 'action' => 'send_to_claude', 'params' => array('claude_note' => "Behind a paywall;\x07 try the archive")) as $k => $v) $req->set_param($k, $v);
            $res = kop_rinbox_rest_act($req)->get_data();
            $q = kop_rinbox_claude_queue();
            $row = $q['zz-claude|a'] ?? null;
            $check('claude: sending puts it on the list with the note (control characters dropped) and the title',
                $row && $row['note'] === 'Behind a paywall; try the archive' && $row['title'] === 'Paywalled story', json_encode($res) . ' ' . json_encode($q));
            $log = $GLOBALS['pdo']->query("SELECT undo_action FROM wpdl_kop_review_log WHERE source = 'zz-claude' AND action = 'send_to_claude' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $check('claude: logged in Recently done with Take back as its Undo', $log === 'claude_take_back', (string) $log);
            $it = $card();
            $check('claude: the card now says it waits, with Take back in place of Send',
                $action($it, 'claude_take_back') && !$action($it, 'send_to_claude') && strpos(implode(' ', $it['notes']), 'Waiting for Claude since') === 0
                && strpos(implode(' ', $it['notes']), 'try the archive') !== false, json_encode($it['notes']));

            $list = kop_rinbox_claude_list();
            $p = $list[0] ?? array();
            $check('claude: the list gives the card: queue, link, note and fields with their options',
                count($list) === 1 && $p['queue'] === 'Claude test' && $p['url'] === 'https://example-news.test/paywalled' && $p['can_save']
                && $p['note'] === 'Behind a paywall; try the archive' && count($p['fields']) === 3 && isset($p['fields'][2]['options']['opinion']), json_encode($p));

            $refused = function (callable $fn) { try { $fn(); return false; } catch (RuntimeException $e) { return true; } };
            $check('claude: an answer with no note or a made-up outcome is refused',
                $refused(function () { kop_rinbox_claude_finish('zz-claude', 'a', 'filled', '  '); })
                && $refused(function () { kop_rinbox_claude_finish('zz-claude', 'a', 'approved', 'note'); }));

            $out = kop_rinbox_claude_finish('zz-claude', 'a', 'filled', 'Read it through the Wayback copy.',
                array('outlet' => 'The Example Times', 'kind' => 'not-an-option', 'made_up' => 'x'));
            $d = $GLOBALS['kop_rinbox_cl'];
            $check('claude: the answer saves only the card\'s own fields, a select only one of its options',
                $d['outlet'] === 'The Example Times' && $d['kind'] === 'news' && $out['filled'] === array('Outlet')
                && $out['skipped'] === array('kind', 'made_up') && count($d['saved']) === 1, json_encode($out));
            $it = $card();
            $check('claude: it leaves the list and the card says what Claude did',
                !kop_rinbox_claude_queue() && $action($it, 'send_to_claude') && ($action($it, 'send_to_claude')['label'] ?? '') === 'Send to Claude again'
                && strpos(implode(' ', $it['notes']), 'filled Outlet: Read it through the Wayback copy.') !== false, json_encode($it['notes']));
            $check('claude: a second answer for it is refused', $refused(function () { kop_rinbox_claude_finish('zz-claude', 'a', 'returned', 'again'); }));

            // Deciding where it goes: the card's own buttons, as Claude; one it does not offer (or an Undo) is refused.
            kop_rinbox_claude_send('zz-claude', 'a', 'file it');
            $check('claude: the list gives the buttons it may press (not its own) and the "Move to" queues',
                in_array('file_on', array_column($p['actions'], 'id'), true) && !in_array('send_to_claude', array_column(kop_rinbox_claude_list()[0]['actions'], 'id'), true)
                && ($p['moves'][0]['id'] ?? '') === 'lawsuit', json_encode(array_column($p['actions'], 'id')));
            $check('claude: a step the card does not offer is refused and it stays on the list',
                $refused(function () { kop_rinbox_claude_finish('zz-claude', 'a', 'filed', 'x', array(), array(array('action' => 'delete_everything'))); })
                && $refused(function () { kop_rinbox_claude_finish('zz-claude', 'a', 'filed', 'x', array(), array(array('action' => 'move', 'params' => array('to' => 'nowhere')))); })
                && isset(kop_rinbox_claude_queue()['zz-claude|a']) && !$GLOBALS['kop_rinbox_cl']['acts']);
            $out = kop_rinbox_claude_finish('zz-claude', 'a', 'filed', 'A news story about record 12.', array(),
                array(array('action' => 'file_on', 'params' => array('record' => '12'))));
            $logged = $GLOBALS['pdo']->query("SELECT user_login FROM wpdl_kop_review_log WHERE source = 'zz-claude' AND action = 'file_on' ORDER BY id DESC LIMIT 1")->fetchColumn();
            $check("claude: its steps run as Claude through the card's own action, logged in Recently done",
                $GLOBALS['kop_rinbox_cl']['filed'] === '12' && strpos(end($GLOBALS['kop_rinbox_cl']['acts']), '@Claude') !== false && $logged === 'Claude'
                && $out['steps'] === array('File on a record: Filed on 12.') && empty($GLOBALS['kop_rinbox_reviewer_as']), json_encode($out) . ' ' . (string) $logged);
            $GLOBALS['kop_rinbox_cl']['filed'] = '';

            // Send again, then take back: off the list, Undo sends it again with the same note.
            kop_rinbox_claude_send('zz-claude', 'a', 'second try');
            $req = new WP_REST_Request();
            foreach (array('source' => 'zz-claude', 'key' => 'a', 'action' => 'claude_take_back', 'params' => array()) as $k => $v) $req->set_param($k, $v);
            kop_rinbox_rest_act($req);
            $undo = $GLOBALS['pdo']->query("SELECT undo_action, undo_params FROM wpdl_kop_review_log WHERE source = 'zz-claude' AND action = 'claude_take_back' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $check('claude: Take back takes it off the list; its Undo sends it again with the note',
                !kop_rinbox_claude_queue() && ($undo['undo_action'] ?? '') === 'send_to_claude' && strpos((string) ($undo['undo_params'] ?? ''), 'second try') !== false, json_encode($undo));
            delete_option('kop_rinbox_claude');
            delete_option('kop_rinbox_claude_done');
        });
    }
}
