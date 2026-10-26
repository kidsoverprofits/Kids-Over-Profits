<?php
/**
 * Review inbox > "One record": every waiting card about one facility, or one
 * company and its facilities, from every queue, gathered on one page (owner,
 * 2026-10-26). The cards are the queues' own, so Approve, Reject and the rest
 * work as they do in each queue.
 *
 * Each queue's waiting view is searched by the record's name and kept where
 * the card names the record (its 'facility' is one of the record's ids), or,
 * on a card that names no facility, where its title or text has the name. A
 * queue that fails or is slow is skipped and named, never fatal. The write-up
 * (held facts and draft) comes first.
 *
 * REST: GET kop/v1/review-inbox/record?kind=facility|operator&id=N (admins).
 * The page: Review inbox, special list "_record" (js/review-inbox.js renderRecord()).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** The record as the view searches for it: {kind, id, name, url, fids, names}, or null. */
function kop_rinbox_record_ref($kind, $id) {
    global $wpdb;
    $id = (int) $id;
    if ($id <= 0) return null;
    if ($kind === 'operator') {
        $row = $wpdb->get_row($wpdb->prepare("SELECT id, name FROM `{$wpdb->prefix}kop_operators` WHERE id = %d", $id), ARRAY_A);
        if (!$row) return null;
        $name = function_exists('kop_dired_name') ? (string) kop_dired_name($row) : (string) $row['name'];
        $fids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT facility_id FROM `{$wpdb->prefix}kop_operator_facilities` WHERE operator_id = %d", $id)));
        $names = array($name);
        foreach (array_slice($fids, 0, 40) as $f) {
            $fac = kop_rinbox_facility($f);
            if ($fac) $names[] = preg_replace('/\s*\([^)]*\)$/', '', (string) $fac['name']);
        }
        return array('kind' => 'operator', 'id' => $id, 'name' => $name, 'fids' => $fids, 'names' => array_values(array_unique(array_filter($names))),
            'url' => function_exists('kop_dired_company_url') ? (string) kop_dired_company_url($id) : '');
    }
    $fac = kop_rinbox_facility($id);
    if (!$fac) return null;
    return array('kind' => 'facility', 'id' => $id, 'name' => (string) $fac['name'], 'fids' => array($id),
        'names' => array(preg_replace('/\s*\([^)]*\)$/', '', (string) $fac['name'])),
        'url' => function_exists('kop_facility_pages_url_for_id') ? (string) kop_facility_pages_url_for_id($id) : '');
}

/** Bytes the gathering may use before the remaining queues are skipped: 70% of the memory limit (0 = no limit). */
function kop_rinbox_record_memory_cap() {
    if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');
    $l = trim((string) ini_get('memory_limit'));
    if ($l === '' || $l === '-1') return 0;
    $n = (float) $l;
    $u = strtolower(substr($l, -1));
    $n *= $u === 'g' ? 1073741824 : ($u === 'm' ? 1048576 : ($u === 'k' ? 1024 : 1));
    return (int) ($n * 0.7);
}

/** Is this card about the record? */
function kop_rinbox_record_match(array $item, array $ref) {
    $fid = (int) ($item['facility']['id'] ?? 0);
    if ($fid > 0) return in_array($fid, $ref['fids'], true);
    $hay = mb_strtolower((string) ($item['title'] ?? '') . ' ' . (string) ($item['subtitle'] ?? '') . ' ' . (string) ($item['text'] ?? ''));
    foreach ($ref['names'] as $n) {
        if (mb_strlen($n) >= 4 && mb_strpos($hay, mb_strtolower($n)) !== false) return true;
    }
    return false;
}

/**
 * Every waiting card about the record: {record, groups: [{source, label, items}], skipped: [labels]}.
 * $budget: seconds to spend before the remaining queues are skipped.
 */
function kop_rinbox_record_items($kind, $id, $budget = 40) {
    $ref = kop_rinbox_record_ref($kind, $id);
    if (!$ref) throw new RuntimeException('That record was not found.');
    $start = microtime(true);
    $cap = kop_rinbox_record_memory_cap();
    $sources = kop_rinbox_sources();
    // The write-up first: the held facts and the draft are what the page will say.
    $order = array_keys($sources);
    usort($order, function ($a, $b) { return ($b === 'writeups') <=> ($a === 'writeups'); });
    $groups = array();
    $skipped = array();
    foreach ($order as $key) {
        $src = $sources[$key];
        if (microtime(true) - $start > $budget || ($cap && memory_get_usage() > $cap)) { $skipped[] = $src['label']; continue; }
        $views = array_keys((array) $src['views']);
        if (!$views || empty($src['list'])) continue;
        $found = array();
        foreach (array_slice($ref['names'], 0, 12) as $name) {
            $q = array('view' => $views[0], 'search' => $name, 'offset' => 0, 'limit' => 50, 'origin' => '',
                'filters' => kop_rinbox_chosen_filters($src, function () { return null; }));
            try {
                $res = kop_rinbox_list_unheld($src, $key, $q);
            } catch (Throwable $e) {
                $skipped[] = $src['label'];
                continue 2;
            }
            foreach ((array) ($res['items'] ?? array()) as $it) {
                if (is_array($it) && isset($it['key']) && kop_rinbox_record_match($it, $ref)) $found[(string) $it['key']] = $it;
            }
            unset($res);
        }
        if (!$found) continue;
        $items = kop_rinbox_finish_items($key, array_values($found));
        foreach ($items as &$it) {
            // The card posts its actions to its own queue (as on Your inbox).
            $it['source'] = $key;
            $it['source_label'] = $src['label'];
            $it['native'] = !empty($src['native']);
        }
        unset($it);
        $groups[] = array('source' => $key, 'label' => $src['label'], 'items' => $items);
    }
    unset($ref['names']);
    return array('record' => $ref, 'groups' => $groups, 'skipped' => array_values(array_unique($skipped)));
}

add_action('rest_api_init', function () {
    register_rest_route('kop/v1', '/review-inbox/record-find', array(
        'methods' => 'GET', 'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $req) {
            return kop_rinbox_rest(function () use ($req) {
                return array('results' => function_exists('kop_mobile_suggest') ? kop_mobile_suggest((string) $req->get_param('q'), array('facility', 'operator'), 10) : array());
            });
        },
    ));
    register_rest_route('kop/v1', '/review-inbox/record', array(
        'methods' => 'GET', 'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback' => function (WP_REST_Request $req) {
            return kop_rinbox_rest(function () use ($req) {
                if (function_exists('set_time_limit')) @set_time_limit(120);
                return kop_rinbox_record_items($req->get_param('kind') === 'operator' ? 'operator' : 'facility', (int) $req->get_param('id'));
            });
        },
    ));
});
