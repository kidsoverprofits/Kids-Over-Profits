<?php
/**
 * Review inbox source: the staff an approved wiki entry put on its facility
 * record (kop_wiki_merge_submission() in api/sync-wiki-facilities.php, run on
 * approval and by the 2026-10-13 backfill, api/backfill-wiki-staff.php). One
 * card per entry: the record, the wiki page, every person it added. The staff
 * are on the record already; looking it over is "Looks right", and "Take them
 * off" removes exactly those people (kop_wiki_staff_undo()). "Put them back"
 * on the Taken off tab adds them again.
 *
 * Read from option kop_wiki_staff_log (submission id => {record, program,
 * staff[], at}); looked over in kop_wiki_staff_checked (id => time); taken off
 * in kop_wiki_staff_undone (id => the log entry as it was).
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('wiki-staff', function () {
    $lib = get_stylesheet_directory() . '/api/sync-wiki-facilities.php';
    if (!file_exists($lib)) return null;
    require_once $lib;
    return array(
        'label'    => 'Wiki staff added',
        'group'    => 'Suggestions to check',
        'help'     => 'People an approved r/troubledteens wiki entry put on its facility record, each cited to the wiki page. They are on the page already. Looks right files the card; Take them off removes exactly the people this entry added (Put them back on the Taken off tab).',
        'views'    => array('added' => 'To look over', 'checked' => 'Looked over', 'undone' => 'Taken off'),
        'count'    => function () {
            return kop_rinbox_wstaff_view_counts()['added'];
        },
        'view_counts' => 'kop_rinbox_wstaff_view_counts',
        'list'     => 'kop_rinbox_wstaff_list',
        'get'      => function ($key) {
            $e = kop_rinbox_wstaff_entries();
            return isset($e[(int) $key]) ? kop_rinbox_wstaff_item((int) $key, $e[(int) $key]) : null;
        },
        'act'      => 'kop_rinbox_wstaff_act',
    );
});

function kop_rinbox_wstaff_opt($name) {
    $v = get_option($name, array());
    return is_array($v) ? $v : array();
}

/** id => entry + view, newest first. */
function kop_rinbox_wstaff_entries() {
    $checked = kop_rinbox_wstaff_opt('kop_wiki_staff_checked');
    $out = array();
    foreach (kop_wiki_staff_log() as $id => $u) {
        $u['view'] = isset($checked[(int) $id]) ? 'checked' : 'added';
        $out[(int) $id] = $u;
    }
    foreach (kop_rinbox_wstaff_opt('kop_wiki_staff_undone') as $id => $u) {
        if (isset($out[(int) $id])) continue;   // put back since
        $u['view'] = 'undone';
        $out[(int) $id] = $u;
    }
    krsort($out);
    return $out;
}

function kop_rinbox_wstaff_view_counts($q = array()) {
    $c = array('added' => 0, 'checked' => 0, 'undone' => 0);
    foreach (kop_rinbox_wstaff_entries() as $u) $c[$u['view']]++;
    return $c;
}

function kop_rinbox_wstaff_list(array $q) {
    $view = isset(kop_rinbox_wstaff_view_counts()[$q['view'] ?? '']) ? $q['view'] : 'added';
    $search = mb_strtolower(trim((string) ($q['search'] ?? '')));
    $rows = array();
    foreach (kop_rinbox_wstaff_entries() as $id => $u) {
        if ($u['view'] !== $view) continue;
        if ($search !== '') {
            $hay = mb_strtolower($u['record'] . ' ' . $u['program'] . ' ' . implode(' ', array_column((array) $u['staff'], 'name')));
            if (strpos($hay, $search) === false) continue;
        }
        $rows[$id] = $u;
    }
    $page = array_slice($rows, (int) $q['offset'], (int) $q['limit'], true);
    $items = array();
    foreach ($page as $id => $u) $items[] = kop_rinbox_wstaff_item($id, $u);
    return array('items' => $items, 'total' => count($rows));
}

/** The record's id, name and page from the unique name the log keeps. */
function kop_rinbox_wstaff_record($unique) {
    static $ids = array();
    if (!array_key_exists($unique, $ids)) {
        $st = kop_rinbox_pdo()->prepare('SELECT id FROM facilities_v2 WHERE unique_name = ?');
        $st->execute(array((string) $unique));
        $ids[$unique] = (int) $st->fetchColumn();
    }
    return $ids[$unique] > 0 ? kop_rinbox_facility($ids[$unique]) : null;
}

function kop_rinbox_wstaff_item($id, array $u) {
    $facility = kop_rinbox_wstaff_record($u['record']);
    $staff = (array) $u['staff'];
    $wiki = (string) ($staff[0]['sourceUrl'] ?? '');
    $details = array();
    foreach ($staff as $s) {
        $line = (string) ($s['role'] ?? '') !== '' ? $s['role'] : 'no role given';
        if ((string) ($s['pastJobs'] ?? '') !== '') $line .= '. Before: ' . $s['pastJobs'];
        $details[] = array('label' => (string) $s['name'], 'value' => $line);
    }
    $links = array();
    if ($facility && !empty($facility['url'])) $links[] = array('label' => 'Facility page', 'url' => $facility['url']);
    if ($wiki !== '') $links[] = array('label' => 'Wiki page', 'url' => $wiki);
    $n = count($staff);
    $people = $n . ($n === 1 ? ' person' : ' people');
    $actions = array();
    if ($u['view'] === 'added') {
        $actions[] = array('id' => 'ok', 'label' => 'Looks right', 'style' => 'approve',
            'help' => 'Files this card under Looked over. The people stay on the facility page.');
    }
    if ($u['view'] !== 'undone') {
        $actions[] = array('id' => 'remove', 'label' => 'Take them off', 'style' => 'reject',
            'help' => 'Removes the ' . $people . ' this wiki entry added from the facility record. Put them back on the Taken off tab.');
    }
    if ($u['view'] === 'checked') {
        $actions[] = array('id' => 'unok', 'label' => 'Undo: back to To look over', 'style' => 'undo',
            'help' => 'Moves the card back to To look over. Nothing on the record changes.');
    }
    if ($u['view'] === 'undone') {
        $actions[] = array('id' => 'readd', 'label' => 'Undo: put them back', 'style' => 'undo',
            'help' => 'Adds this wiki entry\'s people to the facility record again, as approving it does.');
    }
    $name = $facility ? $facility['name'] : (string) $u['record'];
    return array(
        'key'          => (string) $id,
        'title'        => $name,
        'subtitle'     => $people . ' from the wiki entry "' . $u['program'] . '"',
        'url'          => $wiki,
        'text'         => '',
        'created'      => (string) ($u['at'] ?? ''),
        'status'       => $u['view'],
        'status_label' => array('added' => 'On the facility page', 'checked' => 'Looked over', 'undone' => 'Taken off the record')[$u['view']],
        'facility'     => $facility,
        'details'      => $details,
        'links'        => $links,
        'actions'      => $actions,
    );
}

function kop_rinbox_wstaff_act($key, $action, array $params) {
    $id = (int) $key;
    $entries = kop_rinbox_wstaff_entries();
    if (!isset($entries[$id])) throw new RuntimeException('That wiki entry added no staff.');
    $u = $entries[$id];
    $checked = kop_rinbox_wstaff_opt('kop_wiki_staff_checked');
    $undone = kop_rinbox_wstaff_opt('kop_wiki_staff_undone');
    if ($action === 'ok' || $action === 'unok') {
        if ($u['view'] === 'undone') throw new RuntimeException('Those people were taken off; put them back first.');
        if ($action === 'ok') $checked[$id] = time();
        else unset($checked[$id]);
        update_option('kop_wiki_staff_checked', $checked, false);
        kop_rinbox_flush_counts();
        return array('message' => $action === 'ok' ? 'Filed under Looked over.' : 'Back on To look over.');
    }
    $pdo = kop_rinbox_pdo();
    if ($action === 'remove') {
        if ($u['view'] === 'undone') throw new RuntimeException('They were taken off already.');
        $entry = kop_wiki_staff_log()[$id];
        $n = kop_wiki_staff_undo($pdo, $id);
        $undone[$id] = $entry;
        unset($checked[$id]);
        update_option('kop_wiki_staff_undone', $undone, false);
        update_option('kop_wiki_staff_checked', $checked, false);
        kop_rinbox_flush_counts();
        return array('message' => 'Took ' . $n . ' off the facility record. Undo is on the Taken off tab.');
    }
    if ($action === 'readd') {
        if ($u['view'] !== 'undone') throw new RuntimeException('They are on the record already.');
        $st = $pdo->prepare('SELECT status FROM wiki_submissions WHERE id = ?');
        $st->execute(array($id));
        if (!in_array($st->fetchColumn(), array('approved', 'published'), true)) {
            throw new RuntimeException('That wiki entry is no longer approved, so its people stay off the record.');
        }
        $res = kop_wiki_merge_submission($pdo, $id);
        unset($undone[$id]);
        update_option('kop_wiki_staff_undone', $undone, false);
        kop_rinbox_flush_counts();
        $n = (int) ($res['staff_added'] ?? 0);
        return array('message' => $n ? 'Put ' . $n . ' back on the facility record.' : 'Nothing to put back: ' . $res['reason']);
    }
    throw new RuntimeException('Unknown action.');
}
